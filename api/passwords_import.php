<?php

declare(strict_types=1);

require_once __DIR__ . '/_vault_bootstrap.php';
require_once __DIR__ . '/../src/parser/PasswordCsvParser.php';
require_once __DIR__ . '/../src/sync/PasswordMerger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vault_json(['error' => 'Method not allowed'], 405);
}

$vault = vault_require_unlocked();
vault_require_csrf();

$source = $_POST['source'] ?? '';
if (!in_array($source, ['chrome', 'firefox'], true)) {
    vault_json(['error' => 'Origen no válido. Debe ser "chrome" o "firefox".'], 400);
}

if (empty($_FILES['file'])) {
    vault_json(['error' => 'No se subió ningún fichero.'], 400);
}
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    vault_json(['error' => 'Error de subida (código ' . $file['error'] . ').'], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv' && $ext !== 'txt') {
    vault_json(['error' => 'Formato no soportado. Sube el CSV exportado por el navegador.'], 400);
}

try {
    $parser = new PasswordCsvParser();
    $rows   = $parser->parseFile($file['tmp_name']);

    if ($parser->getFormat() !== $source) {
        $detected = $parser->getFormat() === 'chrome' ? 'Chrome' : 'Firefox';
        vault_json(['error' => "El fichero tiene el formato de {$detected}, no del navegador seleccionado."], 400);
    }

    $merger   = new PasswordMerger($vault);
    $counters = $merger->import($rows, $source);

    vault_json([
        'success'   => true,
        'source'    => $source,
        'in_file'   => count($rows),
        'skipped'   => $parser->getSkipped(),
        'added'     => $counters['added'],
        'updated'   => $counters['updated'],
        'unchanged' => $counters['unchanged'],
        'stats'     => $merger->getStats(),
    ]);
} catch (RuntimeException $e) {
    vault_json(['error' => $e->getMessage()], 400);
} catch (Exception $e) {
    vault_json(['error' => $e->getMessage()], 500);
} finally {
    // El CSV está en texto plano: no dejar rastro en el tmp de PHP
    if (isset($file['tmp_name']) && is_file($file['tmp_name'])) {
        @unlink($file['tmp_name']);
    }
}
