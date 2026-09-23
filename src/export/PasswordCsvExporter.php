<?php

declare(strict_types=1);

/**
 * Genera el CSV de contraseñas con las columnas exactas que importa cada navegador.
 * Sin BOM: ni Chrome ni Firefox lo necesitan.
 */
class PasswordCsvExporter {

    /**
     * @param array[] $rows filas descifradas de PasswordMerger::getExportRows()
     * @param string  $target chrome | firefox
     */
    public function export(array $rows, string $target): string {
        $handle = fopen('php://temp', 'r+');

        if ($target === 'firefox') {
            fputcsv($handle, ['url', 'username', 'password', 'httpRealm', 'formActionOrigin', 'guid',
                              'timeCreated', 'timeLastUsed', 'timePasswordChanged'], ',', '"', '');
            foreach ($rows as $r) {
                if ($r['is_android']) continue;
                fputcsv($handle, [
                    $r['origin'],
                    $r['username'],
                    $r['password'],
                    $r['http_realm'],
                    $r['form_action_origin'] !== '' ? $r['form_action_origin'] : $r['origin'],
                    '', // guid vacío: Firefox genera uno nuevo
                    $this->ms($r['time_created']),
                    $this->ms($r['time_last_used']),
                    $this->ms($r['time_password_changed']),
                ], ',', '"', '');
            }
        } else {
            fputcsv($handle, ['name', 'url', 'username', 'password', 'note'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r['name'],
                    $r['url'],
                    $r['username'],
                    $r['password'],
                    $r['note'],
                ], ',', '"', '');
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    private function ms(?int $seconds): string {
        return $seconds === null ? '' : (string) ($seconds * 1000);
    }
}
