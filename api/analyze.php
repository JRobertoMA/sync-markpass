<?php

require_once __DIR__ . '/../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $db   = Database::getInstance();
    $rows = $db->query("SELECT url, folder_path FROM bookmarks ORDER BY url")->fetchAll();

    $domains    = [];
    $folderFreq = [];

    foreach ($rows as $row) {
        $url    = $row['url'] ?? '';
        $folder = $row['folder_path'] ?: '/';

        $parsed = parse_url($url);
        $host   = strtolower($parsed['host'] ?? '');
        if (!$host) continue;

        // Strip www. for grouping so www.github.com and github.com are the same
        $domain = preg_replace('/^www\./', '', $host);

        if (!isset($domains[$domain])) {
            $domains[$domain] = [
                'domain'      => $domain,
                'total'       => 0,
                'folders'     => [],
                'example_url' => $url,
            ];
        }

        $domains[$domain]['total']++;
        $domains[$domain]['folders'][$folder] = ($domains[$domain]['folders'][$folder] ?? 0) + 1;

        $folderFreq[$folder] = ($folderFreq[$folder] ?? 0) + 1;
    }

    // Post-process each domain
    foreach ($domains as &$d) {
        arsort($d['folders']); // most-used folder first
        $d['dominant_folder'] = array_key_first($d['folders']);
        $d['is_consistent']   = count($d['folders']) === 1;
    }
    unset($d);

    // Sort: inconsistent first, then by total desc
    usort($domains, function ($a, $b) {
        if ($a['is_consistent'] !== $b['is_consistent']) {
            return $a['is_consistent'] ? 1 : -1;
        }
        return $b['total'] - $a['total'];
    });

    arsort($folderFreq);

    $domains = array_values($domains);
    $totalDomains      = count($domains);
    $inconsistentCount = count(array_filter($domains, fn($d) => !$d['is_consistent']));

    // Filtrado y paginación en servidor: la lista completa son cientos de KB y el
    // modal solo muestra 100 filas cada vez.
    if (!empty($_GET['only_inconsistent'])) {
        $domains = array_values(array_filter($domains, fn($d) => !$d['is_consistent']));
    }

    if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
        $needle  = mb_strtolower($q);
        $domains = array_values(array_filter(
            $domains,
            fn($d) => str_contains($d['domain'], $needle) || str_contains(mb_strtolower($d['dominant_folder']), $needle)
        ));
    }

    $filtered = count($domains);
    $perPage  = min(500, max(10, (int) ($_GET['per_page'] ?? 100)));
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $offset   = ($page - 1) * $perPage;

    echo json_encode([
        'domains'           => array_slice($domains, $offset, $perPage),
        'page'              => $page,
        'per_page'          => $perPage,
        'filtered'          => $filtered,
        'total_domains'     => $totalDomains,
        'inconsistent_count'=> $inconsistentCount,
        // La lista de carpetas (para el select del modal) solo se manda en la primera
        // página: son cientos de entradas y no cambian al paginar.
        'folders'           => $page === 1 ? array_keys($folderFreq) : null,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
