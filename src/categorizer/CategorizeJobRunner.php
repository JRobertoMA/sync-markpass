<?php

declare(strict_types=1);

require_once __DIR__ . '/CategorizeRunner.php';
require_once __DIR__ . '/../Jobs.php';

/**
 * Ejecuta un job de categorización. Extraído de cli/worker.php cuando el worker pasó a
 * atender más de un tipo de job; la lógica es la misma.
 *
 * El job guarda las sugerencias en `results`; aplicarlas sigue siendo cosa del usuario
 * desde el modal.
 */
class CategorizeJobRunner {
    private Jobs $jobs;
    /** @var callable */
    private $log;

    public function __construct(Jobs $jobs, ?callable $log = null) {
        $this->jobs = $jobs;
        $this->log  = $log ?? static function (string $m): void {};
    }

    /** @param array $job job en estado 'running'; devuelve el job actualizado */
    public function run(array $job): array {
        $runner  = new CategorizeRunner();
        $pending = $runner->pending($job['source'], (int) $job['max']);

        if (!$pending) {
            $job['status']  = 'done';
            $job['warning'] = 'No hay marcadores sin categorizar.';
            return $job;
        }

        $job['total'] = count($pending);
        $this->jobs->save($job);

        $engine      = $job['engine'];
        $suggestions = [];

        // ── 1. Heurística: instantánea, sobre todos los pendientes ───────────
        if ($engine === 'heuristic' || $engine === 'both') {
            $suggestions    = $runner->runHeuristic($pending);
            $job['done']    = count($suggestions);
            $job['batches'] = 0;
            $this->jobs->save($job);
        }

        // ── 2. Claude: por lotes, con progreso y cancelación ────────────────
        if ($engine === 'claude' || $engine === 'both') {
            require_once __DIR__ . '/ClaudeApiCategorizer.php';

            $toRefine = $engine === 'both' ? $runner->needsRefining($suggestions) : $pending;

            if ($toRefine) {
                $job['total']        = count($toRefine);
                $job['done']         = 0;
                $job['batches']      = (int) ceil(count($toRefine) / CATEGORIZE_BATCH_SIZE);
                $job['batches_done'] = 0;
                $this->jobs->save($job);

                $jobs = $this->jobs;
                try {
                    $claude  = new ClaudeApiCategorizer();
                    $refined = $claude->categorize($toRefine, function (array $batchResults, int $i, int $total) use (&$job, $jobs) {
                        $job['batches_done'] = $i;
                        $job['batches']      = $total;
                        $job['done']        += count($batchResults);
                        $jobs->save($job);

                        // Cancelación: la API escribe cancel=true en el fichero del job
                        $fresh = $jobs->get($job['id']);
                        if ($fresh === null || !empty($fresh['cancel'])) {
                            $job['cancel'] = true;
                            return false;
                        }
                        return true;
                    });

                    $suggestions = $engine === 'both'
                        ? $runner->mergeRefined($suggestions, $refined)
                        : $refined;

                } catch (Throwable $e) {
                    // Sin API key o fallo de red: con 'both' nos quedamos con la heurística
                    if ($engine === 'both') {
                        $job['warning'] = 'Claude no disponible, se usó solo heurística: ' . $e->getMessage();
                        ($this->log)("job_warn {$job['id']} " . $e->getMessage());
                    } else {
                        throw $e;
                    }
                }
            }
        }

        $changed        = $runner->changedOnly($suggestions);
        $job['results'] = $changed;
        $job['changed'] = count($changed);
        $job['status']  = !empty($job['cancel']) ? 'cancelled' : 'done';

        return $job;
    }
}
