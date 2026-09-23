<?php

class MariaDbExporter {

    /**
     * @param array[] $bookmarks  All rows from the bookmarks table (including id)
     * @param array[] $folders    All rows from the folders table (including id)
     * @return string  MariaDB-compatible SQL dump (exact copy of SQLite schema)
     */
    public function export(array $bookmarks, array $folders = []): string {
        $date = date('Y-m-d H:i:s');

        $sql  = "-- Sync Bookmarks export\n";
        $sql .= "-- Generated: {$date}\n";
        $sql .= "-- Bookmarks: " . count($bookmarks) . " | Folders: " . count($folders) . "\n\n";

        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        // ── folders ──────────────────────────────────────────────────────────

        $sql .= "DROP TABLE IF EXISTS `folders`;\n";
        $sql .= "CREATE TABLE `folders` (\n";
        $sql .= "  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,\n";
        $sql .= "  `name`        TEXT          NOT NULL,\n";
        // utf8mb4_bin matches SQLite's case-sensitive TEXT UNIQUE behavior for paths
        $sql .= "  `path`        VARCHAR(500)  COLLATE utf8mb4_bin NOT NULL,\n";
        $sql .= "  `parent_path` VARCHAR(500)  COLLATE utf8mb4_bin DEFAULT NULL,\n";
        $sql .= "  `source`      ENUM('chrome','firefox','both') NOT NULL,\n";
        $sql .= "  PRIMARY KEY (`id`),\n";
        $sql .= "  UNIQUE KEY `uq_path` (`path`)\n";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";

        if (!empty($folders)) {
            foreach (array_chunk($folders, 100) as $chunk) {
                $sql .= "INSERT IGNORE INTO `folders` (`id`, `name`, `path`, `parent_path`, `source`) VALUES\n";
                $rows = [];
                foreach ($chunk as $f) {
                    $rows[] = sprintf(
                        "  (%d, %s, %s, %s, %s)",
                        (int)$f['id'],
                        $this->quote($f['name']        ?? ''),
                        $this->quote($f['path']        ?? ''),
                        isset($f['parent_path']) && $f['parent_path'] !== '' ? $this->quote($f['parent_path']) : 'NULL',
                        $this->quote($f['source']      ?? 'chrome')
                    );
                }
                $sql .= implode(",\n", $rows) . ";\n\n";
            }
        }

        // ── bookmarks ────────────────────────────────────────────────────────

        $sql .= "DROP TABLE IF EXISTS `bookmarks`;\n";
        $sql .= "CREATE TABLE `bookmarks` (\n";
        $sql .= "  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,\n";
        $sql .= "  `url`            TEXT          NOT NULL,\n";
        $sql .= "  `title`          TEXT          NOT NULL DEFAULT '',\n";
        $sql .= "  `folder_path`    VARCHAR(500)  NOT NULL DEFAULT '/',\n";
        $sql .= "  `add_date`       BIGINT        DEFAULT NULL,\n";
        $sql .= "  `last_modified`  BIGINT        DEFAULT NULL,\n";
        $sql .= "  `icon`           MEDIUMTEXT    DEFAULT NULL,\n";
        $sql .= "  `source`         ENUM('chrome','firefox','both') NOT NULL,\n";
        $sql .= "  `is_categorized` TINYINT(1)    NOT NULL DEFAULT 0,\n";
        $sql .= "  PRIMARY KEY (`id`),\n";
        $sql .= "  UNIQUE KEY `uq_url_source` (url(500), `source`),\n";
        $sql .= "  KEY `idx_url` (url(500)),\n";
        $sql .= "  KEY `idx_source` (`source`)\n";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";

        if (!empty($bookmarks)) {
            foreach (array_chunk($bookmarks, 100) as $chunk) {
                $sql .= "INSERT INTO `bookmarks` (`id`, `url`, `title`, `folder_path`, `add_date`, `last_modified`, `icon`, `source`, `is_categorized`) VALUES\n";
                $rows = [];
                foreach ($chunk as $bm) {
                    $rows[] = sprintf(
                        "  (%d, %s, %s, %s, %s, %s, %s, %s, %d)",
                        (int)$bm['id'],
                        $this->quote($bm['url']         ?? ''),
                        $this->quote($bm['title']       ?? ''),
                        $this->quote($bm['folder_path'] ?? '/'),
                        isset($bm['add_date'])      && $bm['add_date']      !== '' ? (int)$bm['add_date']      : 'NULL',
                        isset($bm['last_modified']) && $bm['last_modified'] !== '' ? (int)$bm['last_modified'] : 'NULL',
                        isset($bm['icon'])          && $bm['icon']          !== '' ? $this->quote($bm['icon']) : 'NULL',
                        $this->quote($bm['source']  ?? 'chrome'),
                        (int)($bm['is_categorized'] ?? 0)
                    );
                }
                $sql .= implode(",\n", $rows) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        return $sql;
    }

    private function quote(string $value): string {
        return "'" . str_replace(
            ["\\", "'", "\n", "\r", "\0"],
            ["\\\\", "\\'", "\\n", "\\r", "\\0"],
            $value
        ) . "'";
    }
}
