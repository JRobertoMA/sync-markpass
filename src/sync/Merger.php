<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../model/Bookmark.php';

class Merger {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Imports bookmarks from a source without erasing existing data.
     *
     * - New URLs are inserted.
     * - Existing URLs are updated ONLY if the incoming last_modified is newer.
     * - Bookmarks removed from the browser file are kept in the DB.
     * - When $preserveCategorized is true, already-categorized bookmarks keep
     *   their current folder_path even if the browser file has a newer last_modified.
     *
     * Returns ['added' => int, 'updated' => int, 'unchanged' => int].
     *
     * @param Bookmark[] $bookmarks
     * @return array{added:int, updated:int, unchanged:int}
     */
    public function importBookmarks(array $bookmarks, string $source, bool $preserveCategorized = false): array {
        if ($preserveCategorized) {
            // Preserve folder_path and is_categorized for already-categorized bookmarks.
            $sql = "
                INSERT INTO bookmarks (url, title, folder_path, add_date, last_modified, icon, source)
                VALUES (:url, :title, :folder_path, :add_date, :last_modified, :icon, :source)
                ON CONFLICT(url, source) DO UPDATE SET
                    title          = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                         THEN excluded.title         ELSE bookmarks.title         END,
                    folder_path    = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                              AND bookmarks.is_categorized = 0
                                         THEN excluded.folder_path   ELSE bookmarks.folder_path   END,
                    icon           = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                         THEN excluded.icon          ELSE bookmarks.icon          END,
                    is_categorized = bookmarks.is_categorized,
                    last_modified  = MAX(COALESCE(excluded.last_modified, 0),
                                        COALESCE(bookmarks.last_modified, 0))
            ";
        } else {
            // Default: browser wins when last_modified is newer; reset is_categorized so the
            // bookmark is re-examined by the categorizer after its folder_path changes.
            $sql = "
                INSERT INTO bookmarks (url, title, folder_path, add_date, last_modified, icon, source)
                VALUES (:url, :title, :folder_path, :add_date, :last_modified, :icon, :source)
                ON CONFLICT(url, source) DO UPDATE SET
                    title          = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                         THEN excluded.title         ELSE bookmarks.title         END,
                    folder_path    = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                         THEN excluded.folder_path   ELSE bookmarks.folder_path   END,
                    icon           = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                         THEN excluded.icon          ELSE bookmarks.icon          END,
                    is_categorized = CASE WHEN excluded.last_modified > bookmarks.last_modified
                                         THEN 0
                                         ELSE bookmarks.is_categorized END,
                    last_modified  = MAX(COALESCE(excluded.last_modified, 0),
                                        COALESCE(bookmarks.last_modified, 0))
            ";
        }

        // SQLite upsert (requires SQLite 3.24+, available since PHP 7.x bundled SQLite)
        $stmt = $this->db->prepare($sql);

        $counters = ['added' => 0, 'updated' => 0, 'unchanged' => 0];

        // Pre-load existing URLs for this source to detect added vs updated
        $existing = $this->db->prepare("SELECT url, last_modified FROM bookmarks WHERE source = ?");
        $existing->execute([$source]);
        $existingMap = [];
        foreach ($existing->fetchAll() as $row) {
            $existingMap[$row['url']] = (int)($row['last_modified'] ?? 0);
        }

        $this->db->beginTransaction();
        foreach ($bookmarks as $b) {
            $stmt->execute([
                ':url'           => $b->url,
                ':title'         => $b->title,
                ':folder_path'   => $b->folderPath,
                ':add_date'      => $b->addDate,
                ':last_modified' => $b->lastModified,
                ':icon'          => $b->icon,
                ':source'        => $source,
            ]);

            if (!isset($existingMap[$b->url])) {
                $counters['added']++;
            } elseif ($b->lastModified > $existingMap[$b->url]) {
                $counters['updated']++;
            } else {
                $counters['unchanged']++;
            }
        }
        $this->db->commit();

        return $counters;
    }

    /**
     * Returns the diff between Chrome and Firefox bookmarks.
     *
     * Returns an array with keys:
     *   - 'only_chrome'   : bookmarks only in Chrome
     *   - 'only_firefox'  : bookmarks only in Firefox
     *   - 'in_both'       : bookmarks present in both (same URL)
     *   - 'total_chrome'  : total Chrome bookmarks
     *   - 'total_firefox' : total Firefox bookmarks
     */
    public function getDiff(?string $search = null): array {
        $chrome  = $this->getBySource('chrome',  $search);
        $firefox = $this->getBySource('firefox', $search);
        $both    = $this->getBySource('both',    $search);

        $chromeUrls  = array_column($chrome,  null, 'url');
        $firefoxUrls = array_column($firefox, null, 'url');

        $onlyChrome  = [];
        $onlyFirefox = [];
        $inBoth      = [];

        // Cross-browser duplicates not yet merged (shouldn't exist after dedup, kept for safety)
        foreach ($chromeUrls as $url => $row) {
            if (isset($firefoxUrls[$url])) {
                $inBoth[] = $this->mergeRows($row, $firefoxUrls[$url]);
            } else {
                $onlyChrome[] = $row;
            }
        }

        foreach ($firefoxUrls as $url => $row) {
            if (!isset($chromeUrls[$url])) {
                $onlyFirefox[] = $row;
            }
        }

        // Records already merged (source='both') go directly into in_both
        foreach ($both as $row) {
            $row['chrome_folder']  = $row['folder_path'];
            $row['firefox_folder'] = $row['folder_path'];
            $inBoth[] = $row;
        }

        return [
            'only_chrome'    => $onlyChrome,
            'only_firefox'   => $onlyFirefox,
            'in_both'        => $inBoth,
            'total_chrome'   => count($chrome) + count($both),
            'total_firefox'  => count($firefox) + count($both),
        ];
    }

    /**
     * Merges Chrome and Firefox bookmarks into a unified list.
     * For duplicates (same URL), the one with the most recent last_modified wins for metadata.
     *
     * @return Bookmark[]
     */
    public function getMerged(): array {
        $diff = $this->getDiff();
        $result = [];

        foreach ($diff['only_chrome'] as $row) {
            $result[] = Bookmark::fromArray($row);
        }

        foreach ($diff['only_firefox'] as $row) {
            $result[] = Bookmark::fromArray($row);
        }

        foreach ($diff['in_both'] as $row) {
            $b         = Bookmark::fromArray($row);
            $b->source = 'both';
            $result[]  = $b;
        }

        return $result;
    }

    private function getBySource(string $source, ?string $search = null): array {
        if ($search !== null && $search !== '') {
            $like = '%' . $search . '%';
            $stmt = $this->db->prepare("SELECT * FROM bookmarks WHERE source = ? AND (title LIKE ? OR url LIKE ?) ORDER BY folder_path, title");
            $stmt->execute([$source, $like, $like]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM bookmarks WHERE source = ? ORDER BY folder_path, title");
            $stmt->execute([$source]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Merge two DB rows for the same URL (one chrome, one firefox).
     * Picks the most recent metadata; marks source as 'both'.
     */
    private function mergeRows(array $chrome, array $firefox): array {
        $chromeModified  = $chrome['last_modified']  ?? 0;
        $firefoxModified = $firefox['last_modified'] ?? 0;

        $winner = ($firefoxModified > $chromeModified) ? $firefox : $chrome;
        $winner['source'] = 'both';
        $winner['chrome_folder']  = $chrome['folder_path'];
        $winner['firefox_folder'] = $firefox['folder_path'];
        return $winner;
    }

    /**
     * Upserts folders extracted from a BookmarkTree into the folders table.
     * If the same path already exists for the other source, marks it as 'both'.
     *
     * @param array{folder: BookmarkFolder, parent_path: string|null}[] $folderEntries
     */
    public function importFolders(array $folderEntries, string $source): void {
        $stmt = $this->db->prepare("
            INSERT INTO folders (name, path, parent_path, source)
            VALUES (:name, :path, :parent_path, :source)
            ON CONFLICT(path) DO UPDATE SET
                name        = excluded.name,
                parent_path = excluded.parent_path,
                source      = CASE
                                  WHEN folders.source != excluded.source THEN 'both'
                                  ELSE folders.source
                              END
        ");

        $this->db->beginTransaction();
        foreach ($folderEntries as $entry) {
            $stmt->execute([
                ':name'        => $entry['folder']->name,
                ':path'        => $entry['folder']->path,
                ':parent_path' => $entry['parent_path'],
                ':source'      => $source,
            ]);
        }
        $this->db->commit();
    }

    /**
     * Removes duplicate bookmarks (same URL in both chrome and firefox),
     * keeping the one with is_categorized=1 as priority.
     * Ties broken by most recent last_modified; winner's source is set to 'both'.
     *
     * @return array{removed: int}
     */
    public function deduplicateBookmarks(): array {
        // LEFT JOIN with existing 'both' records so we can factor in their
        // categorization state and delete them before the UPDATE (otherwise
        // the UNIQUE(url, source) constraint fires when re-deduplicating
        // after a second import).
        $pairs = $this->db->query("
            SELECT c.id AS chrome_id, f.id AS firefox_id,
                   c.is_categorized AS chrome_cat, f.is_categorized AS firefox_cat,
                   COALESCE(c.last_modified, 0) AS chrome_mod,
                   COALESCE(f.last_modified, 0) AS firefox_mod,
                   c.folder_path AS chrome_folder, f.folder_path AS firefox_folder,
                   b.id          AS both_id,
                   COALESCE(b.is_categorized, 0) AS both_cat,
                   COALESCE(b.folder_path, '')   AS both_folder
            FROM bookmarks c
            JOIN bookmarks f ON c.url = f.url
            LEFT JOIN bookmarks b ON b.url = c.url AND b.source = 'both'
            WHERE c.source = 'chrome' AND f.source = 'firefox'
        ")->fetchAll();

        if (empty($pairs)) {
            return ['removed' => 0];
        }

        $update          = $this->db->prepare("
            UPDATE bookmarks
            SET source = 'both', is_categorized = :is_cat, folder_path = :folder_path
            WHERE id = :id
        ");
        $delete          = $this->db->prepare("DELETE FROM bookmarks WHERE id = :id");
        $deleteExisting  = $this->db->prepare("DELETE FROM bookmarks WHERE id = :id");

        $removed = 0;
        $this->db->beginTransaction();
        foreach ($pairs as $p) {
            $chromeCat  = (int)$p['chrome_cat'];
            $firefoxCat = (int)$p['firefox_cat'];
            $bothCat    = (int)$p['both_cat'];

            // Factor in any pre-existing 'both' record's categorization state
            $effectiveChromeCat  = max($chromeCat,  $bothCat);
            $effectiveFirefoxCat = max($firefoxCat, $bothCat);

            if ($effectiveChromeCat > $effectiveFirefoxCat) {
                $winnerId     = $p['chrome_id'];
                $loserId      = $p['firefox_id'];
                $winnerFolder = ($bothCat > $chromeCat && $p['both_folder'] !== '')
                    ? $p['both_folder'] : $p['chrome_folder'];
            } elseif ($effectiveFirefoxCat > $effectiveChromeCat) {
                $winnerId     = $p['firefox_id'];
                $loserId      = $p['chrome_id'];
                $winnerFolder = ($bothCat > $firefoxCat && $p['both_folder'] !== '')
                    ? $p['both_folder'] : $p['firefox_folder'];
            } elseif ((int)$p['firefox_mod'] >= (int)$p['chrome_mod']) {
                $winnerId     = $p['firefox_id'];
                $loserId      = $p['chrome_id'];
                $winnerFolder = ($p['both_folder'] !== '') ? $p['both_folder'] : $p['firefox_folder'];
            } else {
                $winnerId     = $p['chrome_id'];
                $loserId      = $p['firefox_id'];
                $winnerFolder = ($p['both_folder'] !== '') ? $p['both_folder'] : $p['chrome_folder'];
            }

            // Remove any existing 'both' record to avoid UNIQUE(url, source) conflict
            if ($p['both_id']) {
                $deleteExisting->execute([':id' => $p['both_id']]);
            }

            $update->execute([
                ':is_cat'      => max($chromeCat, $firefoxCat, $bothCat),
                ':folder_path' => $winnerFolder,
                ':id'          => $winnerId,
            ]);
            $delete->execute([':id' => $loserId]);
            $removed++;
        }
        $this->db->commit();

        return ['removed' => $removed];
    }

    /**
     * Returns summary stats about stored bookmarks.
     */
    public function getStats(): array {
        $stmt = $this->db->query("
            SELECT source, COUNT(*) as total
            FROM bookmarks
            GROUP BY source
        ");
        $rows = $stmt->fetchAll();
        $stats = ['chrome' => 0, 'firefox' => 0, 'both' => 0];
        foreach ($rows as $row) {
            $stats[$row['source']] = (int)$row['total'];
        }
        return $stats;
    }

    /**
     * Returns all bookmarks for a given source, or all if source is null.
     *
     * @return array[]
     */
    public function getAll(?string $source = null): array {
        if ($source !== null) {
            $stmt = $this->db->prepare("SELECT * FROM bookmarks WHERE source = ? ORDER BY folder_path, title");
            $stmt->execute([$source]);
        } else {
            $stmt = $this->db->query("SELECT * FROM bookmarks ORDER BY source, folder_path, title");
        }
        return $stmt->fetchAll();
    }

    /**
     * Todos los marcadores presentes en un navegador: los exclusivos de esa fuente
     * MÁS los deduplicados (source='both'), que también están en ese navegador.
     *
     * getAll('chrome') devuelve solo source='chrome', que tras deduplicar son
     * casi ninguno; para exportar «lo que tengo en Chrome» hay que incluir 'both'.
     *
     * @return array[]
     */
    public function getAllForBrowser(string $source): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM bookmarks WHERE source IN (?, 'both') ORDER BY folder_path, title"
        );
        $stmt->execute([$source]);
        return $stmt->fetchAll();
    }

    /**
     * Returns a domain → dominant folder_path map built from bookmarks
     * that have already been categorized (is_categorized = 1).
     * Used by HeuristicCategorizer to learn from previous Claude results.
     *
     * @return array<string,string>
     */
    public function getLearnedDomainRules(): array {
        $rows = $this->db->query(
            "SELECT url, folder_path FROM bookmarks WHERE is_categorized = 1"
        )->fetchAll();

        $domainFolders = [];
        foreach ($rows as $row) {
            $host = strtolower(parse_url($row['url'] ?? '', PHP_URL_HOST) ?? '');
            if (!$host) continue;
            $domain = preg_replace('/^www\./', '', $host);
            $folder = $row['folder_path'] ?? '/';
            $domainFolders[$domain][$folder] = ($domainFolders[$domain][$folder] ?? 0) + 1;
        }

        $rules = [];
        foreach ($domainFolders as $domain => $folders) {
            arsort($folders);
            $rules[$domain] = (string) array_key_first($folders);
        }

        return $rules;
    }

    /**
     * Returns bookmarks that have not been categorized yet (is_categorized = 0).
     *
     * @return array[]
     */
    public function getUncategorized(?string $source = null): array {
        if ($source !== null) {
            $stmt = $this->db->prepare("SELECT * FROM bookmarks WHERE source = ? AND is_categorized = 0 ORDER BY folder_path, title");
            $stmt->execute([$source]);
        } else {
            $stmt = $this->db->query("SELECT * FROM bookmarks WHERE is_categorized = 0 ORDER BY source, folder_path, title");
        }
        return $stmt->fetchAll();
    }
}
