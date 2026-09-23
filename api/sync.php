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
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $diff   = $merger->getDiff($search !== '' ? $search : null);

    // Return counts + paginated data
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;
    $tab     = $_GET['tab'] ?? 'only_chrome'; // only_chrome | only_firefox | in_both

    $allowed = ['all', 'only_chrome', 'only_firefox', 'in_both'];
    if (!in_array($tab, $allowed, true)) {
        $tab = 'all';
    }

    if ($tab === 'all') {
        $rows = array_merge($diff['only_chrome'], $diff['only_firefox'], $diff['in_both']);
    } else {
        $rows = $diff[$tab];
    }

    $total  = count($rows);
    $offset = ($page - 1) * $perPage;
    $items  = array_slice($rows, $offset, $perPage);

    $allCount = count($diff['only_chrome']) + count($diff['only_firefox']) + count($diff['in_both']);

    echo json_encode([
        'tab'          => $tab,
        'page'         => $page,
        'per_page'     => $perPage,
        'total'        => $total,
        'items'        => $items,
        'counts'       => [
            'all'           => $allCount,
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
