<?php

require_once __DIR__ . '/../src/sync/Merger.php';
require_once __DIR__ . '/../src/export/ChromeHtmlExporter.php';
require_once __DIR__ . '/../src/export/FirefoxHtmlExporter.php';
require_once __DIR__ . '/../src/export/FirefoxJsonExporter.php';
require_once __DIR__ . '/../src/export/CsvExporter.php';
require_once __DIR__ . '/../src/export/MariaDbExporter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$format = $_GET['format'] ?? '';
$source = $_GET['source'] ?? 'merged'; // chrome | firefox | merged

$validFormats = ['chrome-html', 'firefox-html', 'firefox-json', 'csv', 'mariadb-sql', 'sqlite-db'];
if (!in_array($format, $validFormats, true)) {
    http_response_code(400);
    echo "Invalid format. Use: " . implode(', ', $validFormats);
    exit;
}

try {
    $merger = new Merger();

    if ($source === 'merged') {
        $bookmarks = array_map(fn($b) => $b->toArray(), $merger->getMerged());
    } elseif (in_array($source, ['chrome', 'firefox'], true)) {
        // Incluye los deduplicados: también están en ese navegador
        $bookmarks = $merger->getAllForBrowser($source);
    } else {
        $bookmarks = $merger->getAll();
    }

    $date = date('Y-m-d');

    switch ($format) {
        case 'chrome-html':
            $exporter = new ChromeHtmlExporter();
            $content  = $exporter->export($bookmarks);
            $filename = "bookmarks-chrome-{$date}.html";
            header('Content-Type: text/html; charset=utf-8');
            break;

        case 'firefox-html':
            $exporter = new FirefoxHtmlExporter();
            $content  = $exporter->export($bookmarks);
            $filename = "bookmarks-firefox-{$date}.html";
            header('Content-Type: text/html; charset=utf-8');
            break;

        case 'firefox-json':
            $exporter = new FirefoxJsonExporter();
            $content  = $exporter->export($bookmarks);
            $filename = "bookmarks-firefox-{$date}.json";
            header('Content-Type: application/json; charset=utf-8');
            break;

        case 'csv':
            $exporter = new CsvExporter();
            $content  = $exporter->export($bookmarks);
            $filename = "bookmarks-{$date}.csv";
            header('Content-Type: text/csv; charset=utf-8');
            break;

        case 'mariadb-sql':
            require_once __DIR__ . '/../src/Database.php';
            $db      = Database::getInstance();
            $allBm   = $db->query("SELECT * FROM bookmarks ORDER BY id")->fetchAll();
            $allFolders = $db->query("SELECT * FROM folders ORDER BY id")->fetchAll();
            $exporter = new MariaDbExporter();
            $content  = $exporter->export($allBm, $allFolders);
            $filename = "bookmarks-{$date}.sql";
            header('Content-Type: application/sql; charset=utf-8');
            break;

        case 'sqlite-db':
            $dbPath = __DIR__ . '/../data/bookmarks.sqlite';
            if (!file_exists($dbPath)) {
                http_response_code(404);
                echo 'Database file not found';
                exit;
            }
            $filename = "bookmarks-backup-{$date}.sqlite";
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            header('Content-Length: ' . filesize($dbPath));
            readfile($dbPath);
            exit;
    }

    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Content-Length: ' . strlen($content));
    echo $content;

} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
