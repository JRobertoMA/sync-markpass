<?php

declare(strict_types=1);

/**
 * Comprobación de enlaces.
 *   GET  ?summary                       → conteo por estado y fecha del último chequeo
 *   GET  ?status=dead|dubious|redirect&page=  → listado paginado (50)
 *   GET  ?estimate&scope=stale|all|failed     → cuántos se comprobarían y cuánto tardaría
 *   POST action=check   {scope}         → encola el job y lanza el worker
 *   POST action=recheck {ids[]}         → recomprueba unos pocos en el acto (≤ 20)
 *   POST action=delete  {ids[]}         → mueve a la papelera
 *   POST action=adopt_redirect {ids[]}  → adopta la URL de destino (reversible)
 *   POST action=mark_alive {ids[]}      → verificado vivo a mano (las comprobaciones no lo pisan)
 *   POST action=unmark     {ids[]}      → quita la marca y vuelve al resultado automático
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/link/LinkChecker.php';
require_once __DIR__ . '/../src/link/LinkCheckJobRunner.php';
require_once __DIR__ . '/../src/link/LinkStore.php';
require_once __DIR__ . '/../src/sync/Trash.php';
require_once __DIR__ . '/../src/sync/FolderHistory.php';

header('Content-Type: application/json; charset=utf-8');

/** Segundos por enlace, medidos con 10 en paralelo sobre marcadores reales. */
const LINK_SECONDS_PER_ITEM = 0.15;

$db     = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

function links_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    if (isset($_GET['summary'])) {
        $counts = ['alive' => 0, 'dubious' => 0, 'dead' => 0, 'unsupported' => 0,
                   'unchecked_local' => 0, 'unchecked' => 0];

        foreach ($db->query('SELECT link_status, COUNT(*) c FROM bookmarks GROUP BY link_status') as $row) {
            $key = $row['link_status'] ?? 'unchecked';
            $counts[$key] = (int) $row['c'];
        }

        $redirects = (int) $db->query(
            'SELECT COUNT(*) FROM bookmarks WHERE link_redirect_url IS NOT NULL'
        )->fetchColumn();

        $manual = $db->query(
            "SELECT COUNT(*) AS n,
                    SUM(CASE WHEN link_auto_status = 'dead' THEN 1 ELSE 0 END) AS stale
             FROM bookmarks WHERE link_manual_at IS NOT NULL"
        )->fetch();

        links_out([
            'counts'       => $counts,
            'redirects'    => $redirects,
            'manual'       => (int) ($manual['n'] ?? 0),
            'manual_stale' => (int) ($manual['stale'] ?? 0),
            'total'        => (int) $db->query('SELECT COUNT(*) FROM bookmarks')->fetchColumn(),
            'last_check'   => $db->query('SELECT MAX(link_checked_at) FROM bookmarks')->fetchColumn() ?: null,
            'trash_count'  => (new Trash($db))->count(),
            'stale_days'   => LinkCheckJobRunner::STALE_DAYS,
        ]);
    }

    if (isset($_GET['estimate'])) {
        $scope = $_GET['scope'] ?? 'stale';
        $n     = count(LinkCheckJobRunner::selectFor($db, $scope));
        links_out([
            'scope'       => $scope,
            'pending'     => $n,
            'concurrency' => LinkChecker::CONCURRENCY,
            'est_seconds' => (int) ceil($n * LINK_SECONDS_PER_ITEM),
        ]);
    }

    // Listado
    $status  = $_GET['status'] ?? 'dead';
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $offset  = ($page - 1) * $perPage;

    $order = 'link_fail_count DESC, id';

    if ($status === 'redirect') {
        $where  = 'link_redirect_url IS NOT NULL';
        $params = [];
    } elseif ($status === 'manual') {
        $where  = 'link_manual_at IS NOT NULL';
        $params = [];
        // Primero los que llevan aviso (la comprobación automática dice que está muerto)
        $order  = "(link_auto_status = 'dead') DESC, link_manual_at DESC, id";
    } else {
        if (!in_array($status, [LinkChecker::DEAD, LinkChecker::DUBIOUS, LinkChecker::ALIVE,
                                LinkChecker::UNSUPPORTED, LinkChecker::LOCAL], true)) {
            links_out(['error' => 'Estado no válido'], 400);
        }
        $where  = 'link_status = ?';
        $params = [$status];
    }

    $total = (int) (function () use ($db, $where, $params) {
        $s = $db->prepare("SELECT COUNT(*) FROM bookmarks WHERE $where");
        $s->execute($params);
        return $s->fetchColumn();
    })();

    $stmt = $db->prepare(
        "SELECT id, url, title, folder_path, source, link_status, link_code, link_error,
                link_redirect_url, link_checked_at, link_fail_count,
                link_manual_at, link_auto_status
         FROM bookmarks WHERE $where
         ORDER BY $order LIMIT ? OFFSET ?"
    );
    $stmt->execute([...$params, $perPage, $offset]);

    links_out([
        'status'   => $status,
        'items'    => $stmt->fetchAll(),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
}

if ($method !== 'POST') {
    links_out(['error' => 'Method not allowed'], 405);
}

// ── POST ─────────────────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$ids    = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($i) => $i > 0));

try {
    switch ($action) {

        // ── Encolar la comprobación en segundo plano ──────────────────────────
        case 'check':
            $scope = $body['scope'] ?? 'stale';
            if (!in_array($scope, ['stale', 'all', 'failed'], true)) {
                links_out(['error' => 'Ámbito no válido'], 400);
            }

            $jobs = new Jobs();
            if ($active = $jobs->blocking()) {
                links_out(['error' => 'Ya hay una comprobación en marcha.', 'job_id' => $active['id']]);
            }

            $pending = count(LinkCheckJobRunner::selectFor($db, $scope));
            if ($pending === 0) {
                links_out(['error' => 'No hay enlaces que comprobar en este ámbito.']);
            }

            $job     = $jobs->create('linkcheck', [
                'scope'       => $scope,
                'concurrency' => LinkChecker::CONCURRENCY,
                'alive'       => 0,
                'dubious'     => 0,
                'dead'        => 0,
                'skipped'     => 0,
            ], $pending);
            $spawned = $jobs->spawnWorker();

            links_out(['job_id' => $job['id'], 'job' => Jobs::summary($job), 'spawned' => $spawned]);

        // ── Recomprobar unos pocos al vuelo, sin job ──────────────────────────
        case 'recheck':
            if (!$ids) {
                links_out(['error' => 'No se indicaron enlaces'], 422);
            }
            if (count($ids) > 20) {
                links_out(['error' => 'Máximo 20 de golpe; para más, usa la comprobación completa.'], 422);
            }

            $items = LinkCheckJobRunner::selectFor($db, 'ids', $ids);
            if (!$items) {
                links_out(['error' => 'No existen esos enlaces'], 404);
            }

            $results = (new LinkChecker())->checkBatch($items);
            $store   = new LinkStore($db);
            $db->beginTransaction();
            foreach ($results as $r) {
                $store->save($r);
            }
            $db->commit();

            // Avisar al front de cuáles tienen marca manual, que se mantiene aunque falle
            $in     = implode(',', array_fill(0, count($ids), '?'));
            $marked = $db->prepare("SELECT id FROM bookmarks WHERE id IN ($in) AND link_manual_at IS NOT NULL");
            $marked->execute($ids);
            $marked = array_flip(array_map('intval', $marked->fetchAll(PDO::FETCH_COLUMN)));
            foreach ($results as &$r) {
                $r['manual'] = isset($marked[(int) $r['id']]);
            }
            unset($r);

            links_out(['success' => true, 'results' => $results]);

        // ── Marca manual de «vivo» ────────────────────────────────────────────
        case 'mark_alive':
            if (!$ids) {
                links_out(['error' => 'No se indicaron enlaces'], 422);
            }
            links_out(['success' => true, 'marked' => (new LinkStore($db))->markAlive($ids), 'ids' => $ids]);

        case 'unmark':
            if (!$ids) {
                links_out(['error' => 'No se indicaron enlaces'], 422);
            }
            links_out(['success' => true, 'unmarked' => (new LinkStore($db))->unmark($ids)]);

        // ── Mover a la papelera ───────────────────────────────────────────────
        case 'delete':
            if (!$ids) {
                links_out(['error' => 'No se indicaron enlaces'], 422);
            }
            $reason = (string) ($body['reason'] ?? 'enlace roto');
            $moved  = (new Trash($db))->moveMany($ids, $reason);
            links_out(['success' => true, 'deleted' => $moved]);

        // ── Adoptar la URL de destino de una redirección (reversible) ─────────
        case 'adopt_redirect':
            if (!$ids) {
                links_out(['error' => 'No se indicaron enlaces'], 422);
            }

            $in   = implode(',', array_fill(0, count($ids), '?'));
            $rows = $db->prepare(
                "SELECT id, url, link_redirect_url FROM bookmarks
                 WHERE id IN ($in) AND link_redirect_url IS NOT NULL AND link_redirect_url != ''"
            );
            $rows->execute($ids);
            $rows = $rows->fetchAll();

            if (!$rows) {
                links_out(['error' => 'Ninguno de esos enlaces tiene una redirección guardada'], 422);
            }

            $history = new FolderHistory($db);
            $update  = $db->prepare(
                'UPDATE bookmarks SET url = :url, link_redirect_url = NULL, link_checked_at = :at WHERE id = :id'
            );
            $exists  = $db->prepare('SELECT 1 FROM bookmarks WHERE url = ? AND source = (SELECT source FROM bookmarks WHERE id = ?) AND id != ?');

            $updated = $skipped = 0;
            $db->beginTransaction();
            try {
                $batchId = $history->start('adopt_redirect');
                $history->snapshot($batchId, array_column($rows, 'id'));

                foreach ($rows as $row) {
                    // Si ya existe otro marcador con la URL de destino, adoptarla rompería
                    // UNIQUE(url, source): se deja como está y se cuenta aparte.
                    $exists->execute([$row['link_redirect_url'], (int) $row['id'], (int) $row['id']]);
                    if ($exists->fetchColumn()) {
                        $skipped++;
                        continue;
                    }
                    $update->execute([
                        ':url' => $row['link_redirect_url'],
                        ':at'  => time(),
                        ':id'  => (int) $row['id'],
                    ]);
                    $updated++;
                }

                $history->finish($batchId, "$updated enlaces · destino de redirección adoptado");
                $db->commit();
                $history->prune();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            links_out([
                'success'  => true,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'batch_id' => $updated > 0 ? $batchId : null,
            ]);

        default:
            links_out(['error' => 'Acción no válida'], 400);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    links_out(['error' => $e->getMessage()], 500);
}
