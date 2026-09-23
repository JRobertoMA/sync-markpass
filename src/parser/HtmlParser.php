<?php

require_once __DIR__ . '/../model/Bookmark.php';
require_once __DIR__ . '/../model/BookmarkTree.php';

/**
 * Parser para el formato Netscape Bookmark (Chrome y Firefox HTML export).
 *
 * Se usa un tokenizador por regex en lugar de DOMDocument porque el formato
 * Netscape no es HTML válido (<DL><p>, <DT> sin cerrar, etc.) y DOMDocument
 * reordena los nodos de forma imprevisible, perdiendo marcadores.
 */
class HtmlParser {
    private string $source;

    public function __construct(string $source) {
        $this->source = $source;
    }

    public function parseFile(string $filePath): BookmarkTree {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }
        return $this->parse(file_get_contents($filePath));
    }

    public function parse(string $html): BookmarkTree {
        $tree = new BookmarkTree();
        $root = new BookmarkFolder('root', '/', $this->source);
        $tree->addRoot($root);

        // Stack de [BookmarkFolder, string path]
        $folderStack = [$root];
        $pathStack   = ['/'];
        $pendingName = null;  // nombre de carpeta que espera su <DL>
        $depth       = 0;     // profundidad de anidamiento <DL>

        /*
         * Tokenizamos en orden de documento. Capturamos:
         *   1. <DL...>              — abre nivel de carpeta
         *   2. </DL>               — cierra nivel de carpeta
         *   3. <H3 attrs>name</H3> — declara nombre para el próximo <DL>
         *   4. <A attrs>title</A>  — un marcador
         *
         * El atributo ICON puede tener decenas de miles de chars (base64),
         * por eso capturamos los atributos por separado.
         */
        $pattern = '/
            (<DL[^>]*>)                        (?# 1: DL apertura )
          | (<\/DL[^>]*>)                       (?# 2: DL cierre )
          | <H3 ([^>]*) > (.*?) <\/H3>          (?# 3: carpeta H3; attrs=3, nombre=4 )
          | <A  \s ([^>]*?) \s*> (.*?) <\/A>    (?# 4: marcador A; attrs=5, título=6 )
        /isx';

        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {

            // ── <DL> apertura ────────────────────────────────────────────────
            if (!empty($m[1])) {
                $depth++;

                if ($depth === 1) {
                    // El primer DL es la raíz, ya está configurada
                    continue;
                }

                $name        = $pendingName ?? 'Sin nombre';
                $pendingName = null;

                /** @var BookmarkFolder $parent */
                $parent     = end($folderStack);
                $parentPath = end($pathStack);

                $safeName   = str_replace(['/', '\\'], '-', $name);
                $folderPath = rtrim($parentPath, '/') . '/' . $safeName;

                $folder = new BookmarkFolder($name, $folderPath, $this->source);
                $parent->addSubfolder($folder);

                $folderStack[] = $folder;
                $pathStack[]   = $folderPath;

            // ── </DL> cierre ─────────────────────────────────────────────────
            } elseif (!empty($m[2])) {
                $depth--;
                $pendingName = null;

                if (count($folderStack) > 1) {
                    array_pop($folderStack);
                    array_pop($pathStack);
                }

            // ── <H3> nombre de carpeta ───────────────────────────────────────
            } elseif (isset($m[4]) && $m[4] !== '') {
                $pendingName = trim(html_entity_decode(
                    strip_tags($m[4]),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ));

            // ── <A> marcador ─────────────────────────────────────────────────
            } elseif (isset($m[5])) {
                $attrs = $m[5];
                $title = trim(html_entity_decode(
                    strip_tags($m[6] ?? ''),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ));

                // HREF
                if (!preg_match('/\bHREF\s*=\s*"([^"]*)"/i', $attrs, $hrefM)) {
                    continue;
                }
                $url = html_entity_decode($hrefM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Saltar URIs especiales de Firefox
                if (str_starts_with($url, 'place:') || str_starts_with($url, 'about:')) {
                    continue;
                }

                // ADD_DATE
                $addDate = null;
                if (preg_match('/\bADD_DATE\s*=\s*"(\d+)"/i', $attrs, $dateM)) {
                    $addDate = (int)$dateM[1];
                }

                // LAST_MODIFIED
                $lastModified = null;
                if (preg_match('/\bLAST_MODIFIED\s*=\s*"(\d+)"/i', $attrs, $lmM)) {
                    $lastModified = (int)$lmM[1];
                }

                // ICON — la URI de datos puede ser muy larga
                $icon = null;
                if (preg_match('/\bICON\s*=\s*"(data:[^"]*)"/i', $attrs, $iconM)) {
                    $icon = $iconM[1];
                }

                /** @var BookmarkFolder $currentFolder */
                $currentFolder = end($folderStack);
                $currentPath   = end($pathStack);

                $bookmark = new Bookmark(
                    url:          $url,
                    title:        $title ?: $url,
                    folderPath:   $currentPath,
                    addDate:      $addDate,
                    lastModified: $lastModified,
                    icon:         $icon,
                    source:       $this->source
                );

                $currentFolder->addBookmark($bookmark);
            }
        }

        return $tree;
    }
}
