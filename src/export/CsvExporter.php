<?php

class CsvExporter {

    /**
     * @param array[] $bookmarks  Rows from DB (url, title, folder_path, source, add_date, is_categorized)
     * @return string  UTF-8 CSV content with BOM for Excel compatibility
     */
    public function export(array $bookmarks): string {
        $handle = fopen('php://temp', 'r+');

        // BOM so Excel opens UTF-8 correctly
        fwrite($handle, "\xEF\xBB\xBF");

        // Header row
        fputcsv($handle, ['title', 'url', 'folder_path', 'source', 'add_date', 'is_categorized']);

        foreach ($bookmarks as $bm) {
            $addDate = ($bm['add_date'] ?? 0) > 0
                ? date('Y-m-d H:i:s', (int)$bm['add_date'])
                : '';

            fputcsv($handle, [
                $bm['title']          ?? '',
                $bm['url']            ?? '',
                $bm['folder_path']    ?? '',
                $bm['source']         ?? '',
                $addDate,
                $bm['is_categorized'] ?? 0,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
