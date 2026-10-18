<?php

require_once __DIR__ . '/../model/Bookmark.php';
require_once __DIR__ . '/../model/BookmarkTree.php';

class FirefoxJsonParser {
    // Mozilla stores dates in microseconds; convert to seconds
    private const DATE_DIVISOR = 1_000_000;

    // Special root GUIDs to skip or handle as roots
    private const SKIP_ROOTS = ['placesRoot', 'tagsFolder', 'unfiledBookmarksFolder', 'mobileFolder'];
    private const TOOLBAR_ROOT = 'toolbarFolder';
    private const MENU_ROOT    = 'bookmarksMenuFolder';

    public function parseFile(string $filePath): BookmarkTree {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }
        $json = file_get_contents($filePath);
        return $this->parse($json);
    }

    public function parse(string $json): BookmarkTree {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        $tree = new BookmarkTree();

        // The root node contains children: menu, toolbar, unfiled, mobile
        foreach ($data['children'] ?? [] as $child) {
            $root = $child['root'] ?? '';

            if (in_array($root, self::SKIP_ROOTS, true)) {
                continue;
            }

            $folderName = match($root) {
                self::TOOLBAR_ROOT => 'Barra de marcadores',
                self::MENU_ROOT    => 'Menú de marcadores',
                default            => $child['title'] ?? 'Sin título',
            };

            $folder = new BookmarkFolder($folderName, '/' . $folderName, 'firefox');
            $this->processNode($child, $folder, '/' . $folderName);
            $tree->addRoot($folder);
        }

        return $tree;
    }

    private function processNode(array $node, BookmarkFolder $parentFolder, string $currentPath): void {
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['typeCode'] ?? 0;

            if ($type === 2) {
                // Folder
                $name = $child['title'] ?? 'Sin título';
                if ($name === '') {
                    $name = 'Sin título';
                }
                $path      = $this->buildPath($currentPath, $name);
                $subFolder = new BookmarkFolder($name, $path, 'firefox');
                $parentFolder->addSubfolder($subFolder);
                $this->processNode($child, $subFolder, $path);

            } elseif ($type === 1) {
                // Bookmark
                $url = $child['uri'] ?? '';

                // Skip special Firefox URIs
                if (str_starts_with($url, 'place:') || str_starts_with($url, 'about:')) {
                    continue;
                }

                $title        = $child['title'] ?? '';
                $addDate      = isset($child['dateAdded'])      ? (int)($child['dateAdded']      / self::DATE_DIVISOR) : null;
                $lastModified = isset($child['lastModified'])   ? (int)($child['lastModified']   / self::DATE_DIVISOR) : null;
                $icon         = $child['iconuri'] ?? null;

                $bookmark = new Bookmark(
                    url:          $url,
                    title:        $title,
                    folderPath:   $currentPath,
                    addDate:      $addDate,
                    lastModified: $lastModified,
                    icon:         $icon,
                    source:       'firefox'
                );

                $parentFolder->addBookmark($bookmark);
            }
        }
    }

    private function buildPath(string $parentPath, string $name): string {
        $parentPath = rtrim($parentPath, '/');
        $safeName   = str_replace(['/', '\\'], '-', $name);
        return $parentPath . '/' . $safeName;
    }
}
