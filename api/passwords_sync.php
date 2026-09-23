<?php

declare(strict_types=1);

require_once __DIR__ . '/_vault_bootstrap.php';
require_once __DIR__ . '/../src/sync/PasswordMerger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    vault_json(['error' => 'Method not allowed'], 405);
}

$vault = vault_require_unlocked();

try {
    $merger = new PasswordMerger($vault);
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $diff   = $merger->getDiff($search !== '' ? $search : null);

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $tab     = $_GET['tab'] ?? 'all';

    $allowed = ['all', 'only_chrome', 'only_firefox', 'in_both', 'conflicts'];
    if (!in_array($tab, $allowed, true)) {
        $tab = 'all';
    }

    // «all» no incluye los conflictos: tienen otra forma de fila (dos credenciales
    // enfrentadas) y se muestran en su propia pestaña. El front avisa de los que
    // quedan fuera de la vista actual con `conflicts_outside`.
    $rows = $tab === 'all'
        ? array_merge($diff['only_chrome'], $diff['only_firefox'], $diff['in_both'])
        : $diff[$tab];

    $total  = count($rows);
    $offset = ($page - 1) * $perPage;

    vault_json([
        'tab'      => $tab,
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'items'    => array_slice($rows, $offset, $perPage),
        'conflicts_outside' => $tab === 'conflicts' ? 0 : count($diff['conflicts']),
        'counts'   => [
            'all'           => count($diff['only_chrome']) + count($diff['only_firefox']) + count($diff['in_both']),
            'only_chrome'   => count($diff['only_chrome']),
            'only_firefox'  => count($diff['only_firefox']),
            'in_both'       => count($diff['in_both']),
            'conflicts'     => count($diff['conflicts']),
            'total_chrome'  => $diff['total_chrome'],
            'total_firefox' => $diff['total_firefox'],
        ],
    ]);
} catch (Exception $e) {
    vault_json(['error' => $e->getMessage()], 500);
}
