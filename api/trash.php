<?php

declare(strict_types=1);

/**
 * Papelera de marcadores borrados.
 *   GET  ?page=1                  → listado paginado
 *   POST action=restore {ids[]}   → devuelve a bookmarks
 *   POST action=purge   {ids[]}   → borra definitivamente esos
 *   POST action=empty             → vacía la papelera entera
 */

require_once __DIR__ . '/../src/sync/Trash.php';

header('Content-Type: application/json; charset=utf-8');

$trash = new Trash();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Purga silenciosa de lo que ya caducó
    $trash->pruneOld();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    echo json_encode($trash->list($page) + ['keep_days' => Trash::KEEP_DAYS]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$ids    = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($i) => $i > 0));

try {
    switch ($action) {
        case 'restore':
            if (!$ids) {
                http_response_code(422);
                echo json_encode(['error' => 'No se indicó qué restaurar']);
                exit;
            }
            $r = $trash->restore($ids);
            echo json_encode(['success' => true] + $r + ['remaining' => $trash->count()]);
            exit;

        case 'purge':
            if (!$ids) {
                http_response_code(422);
                echo json_encode(['error' => 'No se indicó qué borrar']);
                exit;
            }
            echo json_encode(['success' => true, 'purged' => $trash->purge($ids), 'remaining' => $trash->count()]);
            exit;

        case 'empty':
            echo json_encode(['success' => true, 'purged' => $trash->purge([], true), 'remaining' => 0]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
