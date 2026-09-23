<?php

require_once __DIR__ . '/../src/sync/Merger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $merger = new Merger();
    $result = $merger->deduplicateBookmarks();
    $stats  = $merger->getStats();

    echo json_encode([
        'success' => true,
        'removed' => $result['removed'],
        'stats'   => $stats,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
