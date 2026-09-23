<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../sync/Merger.php';
require_once __DIR__ . '/HeuristicCategorizer.php';

/**
 * Orquesta la categorización de marcadores. La usa cli/worker.php (en segundo plano)
 * y api/categorize.php para la estimación previa.
 *
 * El pipeline es el de siempre: heurística sobre todos los pendientes y, con engine=both,
 * Claude refina solo los de confianza < 0.7 o carpeta /Sin categorizar.
 * La novedad es que informa del progreso lote a lote y se puede cancelar.
 */
class CategorizeRunner {
    public const REFINE_CONFIDENCE = 0.7;
    public const UNCATEGORIZED     = '/Sin categorizar';

    private Merger $merger;

    public function __construct(?Merger $merger = null) {
        $this->merger = $merger ?? new Merger();
    }

    /**
     * Marcadores pendientes para este origen, recortados al máximo pedido (0 = todos).
     *
     * @return array[]
     */
    public function pending(string $source, int $max = 0): array {
        $src     = $source === 'all' ? null : $source;
        $pending = $this->merger->getUncategorized($src);
        return $max > 0 ? array_slice($pending, 0, $max) : $pending;
    }

    /**
     * Estimación que se muestra antes de lanzar el job: cuántos marcadores, cuántas
     * llamadas a la API y cuánto tardará aproximadamente.
     */
    public function estimate(string $engine, string $source, int $max = 0): array {
        $pending = count($this->pending($source, $max));

        // La heurística es instantánea (medido: <1 ms por cada 400 marcadores).
        if ($engine === 'heuristic') {
            return ['pending' => $pending, 'batches' => 0, 'est_seconds' => 0, 'engine' => $engine];
        }

        // Con 'both' solo va a Claude lo que la heurística no resuelve con confianza.
        // No se puede saber sin ejecutarla, así que se ejecuta: es gratis y rápida.
        $toRefine = $pending;
        if ($engine === 'both' && $pending > 0) {
            $suggestions = $this->runHeuristic($this->pending($source, $max));
            $toRefine    = count($this->needsRefining($suggestions));
        }

        $batches = (int) ceil($toRefine / max(1, CATEGORIZE_BATCH_SIZE));

        return [
            'pending'     => $pending,
            'to_refine'   => $toRefine,
            'batches'     => $batches,
            'est_seconds' => $batches * CATEGORIZE_SECONDS_PER_BATCH,
            'engine'      => $engine,
        ];
    }

    /** Heurística sobre todos los marcadores dados, con las reglas aprendidas del usuario. */
    public function runHeuristic(array $bookmarks): array {
        $heuristic = new HeuristicCategorizer($this->merger->getLearnedDomainRules());
        return $heuristic->categorize($bookmarks);
    }

    /** Sugerencias que Claude debería revisar: poca confianza o sin categorizar. */
    public function needsRefining(array $suggestions): array {
        return array_values(array_filter($suggestions, fn($s) =>
            ($s['confidence'] < self::REFINE_CONFIDENCE) || ($s['suggested_folder'] === self::UNCATEGORIZED)
        ));
    }

    /** Fusiona por URL los resultados de Claude sobre las sugerencias heurísticas. */
    public function mergeRefined(array $suggestions, array $refined): array {
        $byUrl = array_column($refined, null, 'url');
        foreach ($suggestions as &$s) {
            if (isset($byUrl[$s['url']])) {
                $r = $byUrl[$s['url']];
                $s['suggested_folder'] = $r['suggested_folder'];
                $s['confidence']       = $r['confidence'];
                $s['engine']           = 'claude';
            }
        }
        unset($s);
        return $suggestions;
    }

    /** Solo las sugerencias que cambian algo: carpeta distinta de la actual y no vacía. */
    public function changedOnly(array $suggestions): array {
        return array_values(array_filter($suggestions, fn($s) =>
            ($s['suggested_folder'] ?? '') !== ($s['folder_path'] ?? '')
            && !empty($s['suggested_folder'])
        ));
    }
}
