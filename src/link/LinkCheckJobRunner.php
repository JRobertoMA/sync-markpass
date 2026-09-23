<?php

declare(strict_types=1);

require_once __DIR__ . '/LinkChecker.php';
require_once __DIR__ . '/LinkStore.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Jobs.php';

/**
 * Ejecuta un job de comprobación de enlaces: pide las URLs en paralelo y va guardando
 * el estado de cada marcador según llegan las respuestas.
 *
 * Guarda a disco cada SAVE_EVERY resultados, no en cada uno: con miles de enlaces,
 * escribir el JSON del job en cada respuesta sería el cuello de botella. Ese mismo
 * punto es donde se detecta la cancelación.
 */
class LinkCheckJobRunner {
    /**
     * Cada cuántos resultados se hace commit, se guarda el progreso y se mira si han cancelado.
     * Es también la latencia de la cancelación: con 25 tardaba ~5 s en reaccionar.
     */
    private const SAVE_EVERY = 10;

    /** Días tras los cuales un enlace ya comprobado vuelve a tocar (scope 'stale') */
    public const STALE_DAYS = 30;

    private Jobs $jobs;
    private PDO  $db;
    /** @var callable */
    private $log;

    public function __construct(Jobs $jobs, ?callable $log = null) {
        $this->jobs = $jobs;
        $this->db   = Database::getInstance();
        $this->log  = $log ?? static function (string $m): void {};
    }

    /**
     * Marcadores que entran en un ámbito dado.
     *
     * @param string $scope all | stale | failed
     * @param int[]  $ids   solo estos ids (scope 'ids')
     */
    public static function selectFor(PDO $db, string $scope, array $ids = []): array {
        $stale = time() - self::STALE_DAYS * 86400;

        switch ($scope) {
            case 'all':
                $sql = 'SELECT id, url FROM bookmarks ORDER BY id';
                $params = [];
                break;

            case 'failed':
                // También los marcados vivos a mano cuya comprobación automática dio muerto:
                // son justo los que llevan el aviso y conviene confirmar.
                $sql = "SELECT id, url FROM bookmarks
                        WHERE link_status IN ('dead','dubious')
                           OR (link_manual_at IS NOT NULL AND link_auto_status = 'dead')
                        ORDER BY link_fail_count DESC, id";
                $params = [];
                break;

            case 'ids':
                $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
                if (!$ids) return [];
                $in     = implode(',', array_fill(0, count($ids), '?'));
                $sql    = "SELECT id, url FROM bookmarks WHERE id IN ($in) ORDER BY id";
                $params = $ids;
                break;

            case 'stale':
            default:
                // Nunca comprobados, o comprobados hace más de STALE_DAYS.
                // Los no comprobables (chrome://, locales) no se vuelven a mirar.
                $sql = "SELECT id, url FROM bookmarks
                        WHERE (link_checked_at IS NULL OR link_checked_at < ?)
                          AND (link_status IS NULL OR link_status NOT IN ('unsupported','unchecked_local'))
                        ORDER BY link_checked_at IS NOT NULL, id";
                $params = [$stale];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array $job job en estado 'running'; devuelve el job actualizado */
    public function run(array $job): array {
        $scope = $job['scope'] ?? 'stale';
        $items = self::selectFor($this->db, $scope, $job['ids'] ?? []);

        if (!$items) {
            $job['status']  = 'done';
            $job['warning'] = 'No hay enlaces que comprobar en este ámbito.';
            return $job;
        }

        $job['total']   = count($items);
        $job['done']    = 0;
        $job['alive']   = 0;
        $job['dubious'] = 0;
        $job['dead']    = 0;
        $job['skipped'] = 0;
        $this->jobs->save($job);

        // Único sitio que escribe resultados: respeta las marcas manuales
        $store = new LinkStore($this->db);

        $jobs    = $this->jobs;
        $db      = $this->db;
        $pending = 0;
        $db->beginTransaction();

        $checker = new LinkChecker((int) ($job['concurrency'] ?? LinkChecker::CONCURRENCY));

        $checker->checkBatch($items, function (array $r) use (&$job, &$pending, $jobs, $db, $store): ?bool {
            $store->save($r);

            $job['done']++;
            match ($r['status']) {
                LinkChecker::ALIVE   => $job['alive']++,
                LinkChecker::DEAD    => $job['dead']++,
                LinkChecker::DUBIOUS => $job['dubious']++,
                default              => $job['skipped']++,
            };

            // Persistir por tandas: commit + guardar progreso + mirar si han cancelado
            if (++$pending >= self::SAVE_EVERY) {
                $db->commit();
                $jobs->save($job);
                $pending = 0;

                $fresh = $jobs->get($job['id']);
                if ($fresh === null || !empty($fresh['cancel'])) {
                    $job['cancel'] = true;
                    $db->beginTransaction();   // el bucle exterior cierra esta
                    return false;
                }
                $db->beginTransaction();
            }
            return true;
        });

        if ($db->inTransaction()) {
            $db->commit();
        }

        $job['status'] = !empty($job['cancel']) ? 'cancelled' : 'done';
        ($this->log)("linkcheck {$job['id']} done={$job['done']} alive={$job['alive']} "
                   . "dubious={$job['dubious']} dead={$job['dead']}");

        return $job;
    }
}
