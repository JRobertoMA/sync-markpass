<?php

declare(strict_types=1);

require_once __DIR__ . '/_vault_bootstrap.php';
require_once __DIR__ . '/../src/sync/PasswordMerger.php';
require_once __DIR__ . '/../src/export/PasswordCsvExporter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    vault_json(['error' => 'Method not allowed'], 405);
}

$vault = vault_require_unlocked();

// ── GET ?counts=1 ────────────────────────────────────────────────────────────
// Cuántas filas saldrían en cada combinación, para poder etiquetar los botones
// de exportar y deshabilitar los que no descargarían nada.
if (isset($_GET['counts'])) {
    try {
        $merger = new PasswordMerger($vault);
        $counts = [];
        foreach ([['merged', 'chrome'], ['merged', 'firefox'],
                  ['missing_in_chrome', 'chrome'], ['missing_in_firefox', 'firefox']] as [$scope, $target]) {
            $counts["{$target}-csv:{$scope}"] = count($merger->getExportRows($scope, $target));
        }
        vault_json(['counts' => $counts]);
    } catch (Exception $e) {
        vault_json(['error' => $e->getMessage()], 500);
    }
}

$format = $_GET['format'] ?? '';
$scope  = $_GET['scope']  ?? 'merged';

$formats = ['chrome-csv' => 'chrome', 'firefox-csv' => 'firefox'];
$scopes  = ['merged', 'missing_in_chrome', 'missing_in_firefox'];

if (!isset($formats[$format])) {
    vault_json(['error' => 'Formato no válido. Usa: ' . implode(', ', array_keys($formats))], 400);
}
if (!in_array($scope, $scopes, true)) {
    vault_json(['error' => 'Ámbito no válido. Usa: ' . implode(', ', $scopes)], 400);
}

try {
    $target   = $formats[$format];
    $merger   = new PasswordMerger($vault);
    $rows     = $merger->getExportRows($scope, $target);
    $exporter = new PasswordCsvExporter();
    $content  = $exporter->export($rows, $target);

    $filename = "passwords-{$target}-{$scope}-" . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Content-Length: ' . strlen($content));
    header('X-Rows: ' . count($rows));
    echo $content;
} catch (Exception $e) {
    vault_json(['error' => $e->getMessage()], 500);
}
