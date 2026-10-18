<?php

class FirefoxHtmlExporter {
    /**
     * @param array[] $bookmarks  Flat array of bookmark rows (from DB or Merger::getMerged)
     */
    public function export(array $bookmarks): string {
        $tree = $this->buildTree($bookmarks);

        $lines = [];
        $lines[] = '<!DOCTYPE NETSCAPE-Bookmark-file-1>';
        $lines[] = '<!-- This is an automatically generated file.';
        $lines[] = '     It will be read and overwritten.';
        $lines[] = '     DO NOT EDIT! -->';
        $lines[] = '<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">';
        $lines[] = '<meta http-equiv="Content-Security-Policy"';
        $lines[] = '      content="default-src \'self\'; script-src \'none\'; img-src data: *; object-src \'none\'"></meta>';
        $lines[] = '<TITLE>Bookmarks</TITLE>';
        $lines[] = '<H1>Menú Marcadores</H1>';
        $lines[] = '';
        $lines[] = '<DL><p>';

        $this->renderFolder($tree, $lines, 1);

        $lines[] = '</DL>';

        return implode("\n", $lines);
    }

    private function buildTree(array $bookmarks): array {
        $root = ['__bookmarks' => [], '__folders' => []];

        foreach ($bookmarks as $bm) {
            $path  = trim($bm['folder_path'] ?? '/', '/');
            $parts = $path === '' ? [] : explode('/', $path);

            $node = &$root;
            foreach ($parts as $part) {
                if (!isset($node['__folders'][$part])) {
                    $node['__folders'][$part] = ['__bookmarks' => [], '__folders' => []];
                }
                $node = &$node['__folders'][$part];
            }
            $node['__bookmarks'][] = $bm;
        }

        return $root;
    }

    private function renderFolder(array $node, array &$lines, int $depth): void {
        $indent = str_repeat('    ', $depth);
        $ts     = time();

        foreach ($node['__folders'] as $name => $subNode) {
            $lines[] = "{$indent}<DT><H3 ADD_DATE=\"{$ts}\" LAST_MODIFIED=\"{$ts}\">" . htmlspecialchars($name, ENT_XML1) . "</H3>";
            $lines[] = "{$indent}<DL><p>";
            $this->renderFolder($subNode, $lines, $depth + 1);
            $lines[] = "{$indent}</DL><p>";
        }

        foreach ($node['__bookmarks'] as $bm) {
            $url          = htmlspecialchars($bm['url']   ?? '', ENT_XML1);
            $title        = htmlspecialchars($bm['title'] ?? '', ENT_XML1);
            $addDate      = $bm['add_date']      ?? $ts;
            $lastModified = $bm['last_modified'] ?? $ts;

            $iconAttr    = '';
            $iconUriAttr = '';
            if (!empty($bm['icon'])) {
                $iconAttr    = ' ICON="' . $bm['icon'] . '"';
                $iconUriAttr = ' ICON_URI="' . htmlspecialchars($bm['icon'], ENT_XML1) . '"';
            }

            $lines[] = "{$indent}<DT><A HREF=\"{$url}\" ADD_DATE=\"{$addDate}\" LAST_MODIFIED=\"{$lastModified}\"{$iconUriAttr}{$iconAttr}>{$title}</A>";
        }
    }
}
