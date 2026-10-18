<?php

require_once __DIR__ . '/../src/sync/Merger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $merger = new Merger();
    $stats  = $merger->getStats();
    $diff   = $merger->getDiff();

    echo json_encode([
        'stats'  => $stats,
        'counts' => [
            'only_chrome'   => count($diff['only_chrome']),
            'only_firefox'  => count($diff['only_firefox']),
            'in_both'       => count($diff['in_both']),
            'total_chrome'  => $diff['total_chrome'],
            'total_firefox' => $diff['total_firefox'],
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
