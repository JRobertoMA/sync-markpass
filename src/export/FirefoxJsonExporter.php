<?php

class FirefoxJsonExporter {
    private const DATE_MULTIPLIER = 1_000_000; // Firefox uses microseconds

    /**
     * @param array[] $bookmarks  Flat array of bookmark rows
     */
    public function export(array $bookmarks): string {
        $now = time() * self::DATE_MULTIPLIER;

        $menuChildren    = [];
        $toolbarChildren = [];
        $otherChildren   = [];

        foreach ($bookmarks as $bm) {
            $path  = trim($bm['folder_path'] ?? '/', '/');
            $parts = $path === '' ? [] : explode('/', $path);

            $node = $this->makeBookmarkNode($bm, $now);

            if (count($parts) === 0) {
                $otherChildren[] = $node;
            } else {
                // Route to toolbar vs menu based on first path segment
                $first = strtolower($parts[0]);
                if (str_contains($first, 'barra') || str_contains($first, 'toolbar') || str_contains($first, 'favoritos')) {
                    $toolbarChildren = $this->insertIntoPath($toolbarChildren, array_slice($parts, 1), $node, $now);
                } else {
                    $menuChildren = $this->insertIntoPath($menuChildren, array_slice($parts, 1), $node, $now);
                }
            }
        }

        $root = [
            'guid'         => 'root________',
            'title'        => '',
            'index'        => 0,
            'dateAdded'    => $now,
            'lastModified' => $now,
            'id'           => 1,
            'typeCode'     => 2,
            'type'         => 'text/x-moz-place-container',
            'root'         => 'placesRoot',
            'children'     => [
                [
                    'guid'         => 'menu________',
                    'title'        => 'menu',
                    'index'        => 0,
                    'dateAdded'    => $now,
                    'lastModified' => $now,
                    'id'           => 2,
                    'typeCode'     => 2,
                    'type'         => 'text/x-moz-place-container',
                    'root'         => 'bookmarksMenuFolder',
                    'children'     => $menuChildren,
                ],
                [
                    'guid'         => 'toolbar_____',
                    'title'        => 'toolbar',
                    'index'        => 1,
                    'dateAdded'    => $now,
                    'lastModified' => $now,
                    'id'           => 3,
                    'typeCode'     => 2,
                    'type'         => 'text/x-moz-place-container',
                    'root'         => 'toolbarFolder',
                    'children'     => $toolbarChildren,
                ],
            ],
        ];

        return json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function makeBookmarkNode(array $bm, int $now): array {
        $addDate      = isset($bm['add_date'])      ? (int)$bm['add_date']      * self::DATE_MULTIPLIER : $now;
        $lastModified = isset($bm['last_modified']) ? (int)$bm['last_modified'] * self::DATE_MULTIPLIER : $now;

        $node = [
            'guid'         => $this->generateGuid(),
            'title'        => $bm['title'] ?? '',
            'index'        => 0,
            'dateAdded'    => $addDate,
            'lastModified' => $lastModified,
            'id'           => rand(100, 99999),
            'typeCode'     => 1,
            'type'         => 'text/x-moz-place',
            'uri'          => $bm['url'] ?? '',
        ];

        if (!empty($bm['icon'])) {
            $node['iconuri'] = $bm['icon'];
        }

        return $node;
    }

    private function makeFolderNode(string $name, int $now): array {
        return [
            'guid'         => $this->generateGuid(),
            'title'        => $name,
            'index'        => 0,
            'dateAdded'    => $now,
            'lastModified' => $now,
            'id'           => rand(100, 99999),
            'typeCode'     => 2,
            'type'         => 'text/x-moz-place-container',
            'children'     => [],
        ];
    }

    /**
     * Inserts a node into a nested children array following the given path parts.
     * Creates intermediate folders as needed.
     */
    private function insertIntoPath(array $children, array $parts, array $node, int $now): array {
        if (empty($parts)) {
            $children[] = $node;
            return $children;
        }

        $folderName = $parts[0];
        $rest       = array_slice($parts, 1);

        // Find existing folder
        foreach ($children as &$child) {
            if (($child['typeCode'] ?? 0) === 2 && $child['title'] === $folderName) {
                $child['children'] = $this->insertIntoPath($child['children'], $rest, $node, $now);
                return $children;
            }
        }
        unset($child);

        // Create new folder
        $folder             = $this->makeFolderNode($folderName, $now);
        $folder['children'] = $this->insertIntoPath([], $rest, $node, $now);
        $children[]         = $folder;

        return $children;
    }

    private function generateGuid(): string {
        $bytes = random_bytes(9);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
