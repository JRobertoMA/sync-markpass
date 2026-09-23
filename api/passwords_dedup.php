<?php

declare(strict_types=1);

require_once __DIR__ . '/_vault_bootstrap.php';
require_once __DIR__ . '/../src/sync/PasswordMerger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    vault_json(['error' => 'Method not allowed'], 405);
}

$vault = vault_require_unlocked();

$source = $_GET['source'] ?? null;
if ($source !== null && !in_array($source, ['chrome', 'firefox'], true)) {
    $source = null;
}

try {
    $merger = new PasswordMerger($vault);
    $groups = $merger->getDuplicateGroups($source);
    vault_json([
        'groups'      => $groups,
        'total'       => count($groups),
        'total_rows'  => array_sum(array_map(fn($g) => count($g['rows']), $groups)),
    ]);
} catch (Exception $e) {
    vault_json(['error' => $e->getMessage()], 500);
}
