<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/sync/Merger.php';
require_once __DIR__ . '/../src/categorizer/HeuristicCategorizer.php';
require_once __DIR__ . '/../src/categorizer/CategorizeRunner.php';
require_once __DIR__ . '/../src/sync/FolderHistory.php';
require_once __DIR__ . '/../src/Jobs.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// ── POST /api/categorize.php ─────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? 'suggest';

    // ── action: estimate ─────────────────────────────────────────────────────
    // Cuántos marcadores hay pendientes y cuánto costará procesarlos. No llama a la API.
    if ($action === 'estimate') {
        $source = $body['source'] ?? 'all';
        $engine = $body['engine'] ?? 'both';
        $max    = (int) ($body['max'] ?? 0);

        $runner = new CategorizeRunner();
        echo json_encode($runner->estimate($engine, $source, $max));
        exit;
    }

    // ── action: suggest ──────────────────────────────────────────────────────
    // Ya no procesa dentro de la petición: encola un job y lo ejecuta cli/worker.php.
    // Con mpm_prefork, una tanda de Claude bloqueaba un worker de Apache varios minutos.
    if ($action === 'suggest') {
        $source = $body['source'] ?? 'all';   // chrome | firefox | all
        $engine = $body['engine'] ?? 'both';  // heuristic | claude | both
        $max    = (int) ($body['max'] ?? 0);  // 0 = todos los pendientes

        if (!in_array($engine, ['heuristic', 'claude', 'both'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Motor no válido']);
            exit;
        }
        if (!in_array($source, ['all', 'chrome', 'firefox'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Origen no válido']);
            exit;
        }

        $jobs = new Jobs();
        if ($active = $jobs->blocking()) {
            echo json_encode(['error' => 'Ya hay una categorización en marcha.', 'job_id' => $active['id']]);
            exit;
        }

        $runner  = new CategorizeRunner();
        $pending = count($runner->pending($source, $max));

        if ($pending === 0) {
            echo json_encode(['error' => 'No hay marcadores sin categorizar']);
            exit;
        }

        $job     = $jobs->create('categorize', [
            'engine'     => $engine,
            'source'     => $source,
            'max'        => $max,
            'batch_size' => CATEGORIZE_BATCH_SIZE,
            'batches'    => (int) ceil($pending / max(1, CATEGORIZE_BATCH_SIZE)),
            'changed'    => 0,
        ], $pending);
        $spawned = $jobs->spawnWorker();

        echo json_encode([
            'job_id'  => $job['id'],
            'job'     => Jobs::summary($job),
            'spawned' => $spawned,
        ]);
        exit;
    }

    // ── action: apply ────────────────────────────────────────────────────────
    if ($action === 'apply') {
        $changes = $body['changes'] ?? [];

        if (empty($changes)) {
            echo json_encode(['error' => 'No se enviaron cambios']);
            exit;
        }

        $db      = Database::getInstance();
        $history = new FolderHistory($db);

        // Resolver los ids afectados por cada cambio para poder guardar su estado previo
        $findBySource = $db->prepare('SELECT id FROM bookmarks WHERE url = ? AND source = ?');
        $findAny      = $db->prepare('SELECT id FROM bookmarks WHERE url = ?');

        $updateOne = $db->prepare('UPDATE bookmarks SET folder_path = ?, is_categorized = 1 WHERE id = ?');

        $updated = 0;
        $db->beginTransaction();
        try {
            $batchId = $history->start('categorize');

            foreach ($changes as $change) {
                $url    = $change['url']    ?? '';
                $folder = $change['folder'] ?? '';
                $source = $change['source'] ?? '';

                if (!$url || !$folder) continue;

                if ($source) {
                    $findBySource->execute([$url, $source]);
                    $ids = $findBySource->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $findAny->execute([$url]);
                    $ids = $findAny->fetchAll(PDO::FETCH_COLUMN);
                }
                if (!$ids) continue;

                $history->snapshot($batchId, $ids);
                foreach ($ids as $id) {
                    $updateOne->execute([$folder, (int) $id]);
                }
                $updated++;
            }

            $history->finish($batchId, "$updated marcadores · categorización");
            $db->commit();
            $history->prune();
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }

        echo json_encode([
            'success'  => true,
            'updated'  => $updated,
            'batch_id' => $updated > 0 ? $batchId : null,
        ]);
        exit;
    }

    // ── action: apply_domain ─────────────────────────────────────────────────
    if ($action === 'apply_domain') {
        $changes = $body['changes'] ?? []; // [{domain: string, folder: string}, ...]

        if (empty($changes)) {
            echo json_encode(['error' => 'No se enviaron cambios']);
            exit;
        }

        $db      = Database::getInstance();
        $history = new FolderHistory($db);

        // Build domain → folder map (strip www. for consistent matching)
        $domainMap = [];
        foreach ($changes as $c) {
            $domain = strtolower(preg_replace('/^www\./', '', $c['domain'] ?? ''));
            $folder = $c['folder'] ?? '';
            if ($domain && $folder) {
                $domainMap[$domain] = $folder;
            }
        }

        // Fetch all bookmark URLs and match by extracted hostname
        $allRows = $db->query("SELECT id, url FROM bookmarks")->fetchAll();
        $stmt    = $db->prepare("UPDATE bookmarks SET folder_path = :folder, is_categorized = 1 WHERE id = :id");

        // Agrupar por carpeta destino para poder guardar el estado previo en bloque
        $targets = [];
        foreach ($allRows as $row) {
            $parsed = parse_url($row['url'] ?? '');
            $host   = strtolower(preg_replace('/^www\./', '', $parsed['host'] ?? ''));
            if (isset($domainMap[$host])) {
                $targets[] = ['id' => (int) $row['id'], 'folder' => $domainMap[$host]];
            }
        }

        $updated = 0;
        $db->beginTransaction();
        try {
            $batchId = $history->start('homologate');
            $history->snapshot($batchId, array_column($targets, 'id'));

            foreach ($targets as $t) {
                $stmt->execute([':folder' => $t['folder'], ':id' => $t['id']]);
                $updated++;
            }

            $nDomains = count($domainMap);
            $history->finish($batchId, "$updated marcadores · $nDomains dominios homologados");
            $db->commit();
            $history->prune();
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }

        echo json_encode([
            'success'  => true,
            'updated'  => $updated,
            'batch_id' => $updated > 0 ? $batchId : null,
        ]);
        exit;
    }

    // ── action: undo ─────────────────────────────────────────────────────────
    // Restaura las carpetas del lote indicado. Solo el más reciente sin deshacer.
    if ($action === 'undo') {
        $batchId = (int) ($body['batch_id'] ?? 0);
        if ($batchId <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Falta el lote a deshacer']);
            exit;
        }

        try {
            $history  = new FolderHistory();
            $restored = $history->undo($batchId);
            echo json_encode([
                'success'  => true,
                'restored' => $restored,
                'history'  => $history->recent(),
            ]);
        } catch (RuntimeException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida']);
    exit;
}

// ── GET /api/categorize.php?history ──────────────────────────────────────────
// Últimos cambios masivos aplicados, para la lista con botón Deshacer.
if ($method === 'GET' && isset($_GET['history'])) {
    $history = new FolderHistory();
    echo json_encode(['batches' => $history->recent()]);
    exit;
}

// ── GET /api/categorize.php?check ────────────────────────────────────────────
// Checks whether Claude API key is configured
if ($method === 'GET' && isset($_GET['check'])) {
    require_once __DIR__ . '/../config.php';
    echo json_encode([
        'claude_configured' => !empty(ANTHROPIC_API_KEY),
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
