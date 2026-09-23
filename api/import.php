<?php

require_once __DIR__ . '/../src/parser/HtmlParser.php';
require_once __DIR__ . '/../src/parser/FirefoxJsonParser.php';
require_once __DIR__ . '/../src/sync/Merger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$source = $_POST['source'] ?? '';
if (!in_array($source, ['chrome', 'firefox'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid source. Must be "chrome" or "firefox"']);
    exit;
}

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error code: ' . $file['error']]);
    exit;
}

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$tmpPath  = $file['tmp_name'];

try {
    $bookmarks = [];

    if ($ext === 'json') {
        if ($source !== 'firefox') {
            http_response_code(400);
            echo json_encode(['error' => 'JSON format is only supported for Firefox']);
            exit;
        }
        $parser    = new FirefoxJsonParser();
        $tree      = $parser->parseFile($tmpPath);
        $bookmarks = $tree->flattenBookmarks();

    } elseif ($ext === 'html' || $ext === 'htm') {
        $parser    = new HtmlParser($source);
        $tree      = $parser->parseFile($tmpPath);
        $bookmarks = $tree->flattenBookmarks();

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported file type. Use .html or .json']);
        exit;
    }

    $preserveCategorized = filter_var($_POST['preserve_categorized'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $merger   = new Merger();
    $merger->importFolders($tree->flattenFolders(), $source);
    $counters = $merger->importBookmarks($bookmarks, $source, $preserveCategorized);
    $stats    = $merger->getStats();

    echo json_encode([
        'success'   => true,
        'source'    => $source,
        'in_file'   => count($bookmarks),
        'added'     => $counters['added'],
        'updated'   => $counters['updated'],
        'unchanged' => $counters['unchanged'],
        'stats'     => $stats,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
