<?php

declare(strict_types=1);

/**
 * Parser de los CSV de contraseñas que exportan Chrome y Firefox.
 *
 * Estructura verificada con exports reales:
 *   Chrome : name,url,username,password[,note]           (sin comillas, LF, sin BOM)
 *   Firefox: "url","username","password","httpRealm","formActionOrigin","guid",
 *            "timeCreated","timeLastUsed","timePasswordChanged"   (todo entrecomillado, CRLF)
 *
 * Chrome incluye credenciales de apps Android con url `android://<hash>@<paquete>/`;
 * se conservan con is_android=1 (Firefox no las importa).
 */
class PasswordCsvParser {

    private const CHROME_HEADER  = ['name', 'url', 'username', 'password'];   // + 'note' opcional
    private const FIREFOX_HEADER = ['url', 'username', 'password', 'httprealm', 'formactionorigin', 'guid',
                                    'timecreated', 'timelastused', 'timepasswordchanged'];

    /** Esquemas de URL que cuentan como credencial; el resto se omite (chrome://, about:, moz-extension://…). */
    private const ALLOWED_SCHEMES = ['http', 'https', 'android'];

    /** Formato detectado tras parse(): 'chrome' | 'firefox' */
    private string $format = '';
    private int    $skipped = 0;

    public function getFormat(): string { return $this->format; }
    public function getSkipped(): int   { return $this->skipped; }

    /**
     * @return array[] filas normalizadas (ver normalize())
     */
    public function parseFile(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Fichero no encontrado: $filePath");
        }
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el fichero.');
        }

        // BOM opcional
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('El CSV está vacío.');
        }
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

        $this->format = $this->detectFormat($header);
        $index        = array_flip($header);

        $rows          = [];
        $this->skipped = 0;

        while (($r = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($r === [null]) continue; // línea en blanco
            $get = fn(string $col) => isset($index[$col], $r[$index[$col]]) ? trim((string) $r[$index[$col]]) : '';

            $row = $this->format === 'chrome'
                ? $this->normalize($get('url'), $get('name'), $get('username'), $get('password'), $get('note'), '', '', '', null, null, null)
                : $this->normalize(
                    $get('url'), '', $get('username'), $get('password'), '',
                    $get('httprealm'), $get('formactionorigin'), $get('guid'),
                    $this->msToSeconds($get('timecreated')),
                    $this->msToSeconds($get('timelastused')),
                    $this->msToSeconds($get('timepasswordchanged'))
                );

            if ($row === null) {
                $this->skipped++;
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function detectFormat(array $header): string {
        $isChrome = array_slice($header, 0, 4) === self::CHROME_HEADER
            && (count($header) === 4 || (count($header) === 5 && $header[4] === 'note'));
        if ($isChrome) {
            return 'chrome';
        }
        if ($header === self::FIREFOX_HEADER) {
            return 'firefox';
        }
        throw new RuntimeException(
            'Cabecera no reconocida. Se esperaba el CSV exportado por Chrome (name,url,username,password,note) '
            . 'o por Firefox (url,username,password,httpRealm,…).'
        );
    }

    /**
     * Devuelve la fila normalizada o null si no es utilizable (sin url o sin contraseña).
     */
    private function normalize(
        string $url, string $name, string $username, string $password, string $note,
        string $httpRealm, string $formActionOrigin, string $guid,
        ?int $timeCreated, ?int $timeLastUsed, ?int $timePasswordChanged
    ): ?array {
        if ($url === '' || $password === '') {
            return null;
        }

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }

        // Solo credenciales web o de apps Android. Firefox exporta también entradas internas
        // (chrome://firefoxaccounts = clave de Sync, moz-extension://…) que no son logins de sitios
        // y no deben sincronizarse ni importarse en ningún navegador.
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        $isAndroid = $scheme === 'android';
        if ($isAndroid) {
            // android://<hash>@<paquete>/ → el "host" es el nombre del paquete
            $origin = $url;
        } else {
            $host   = preg_replace('/^www\./', '', $host);
            $origin = $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return [
            'origin'                => $origin,
            'host'                  => $host,
            'url'                   => $url,
            'name'                  => $name !== '' ? $name : $host,
            'username'              => $username,
            'password'              => $password,
            'note'                  => $note,
            'http_realm'            => $httpRealm,
            'form_action_origin'    => $formActionOrigin,
            'guid'                  => $guid,
            'time_created'          => $timeCreated,
            'time_last_used'        => $timeLastUsed,
            'time_password_changed' => $timePasswordChanged,
            'is_android'            => $isAndroid ? 1 : 0,
        ];
    }

    private function msToSeconds(string $value): ?int {
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        return intdiv((int) $value, 1000);
    }
}
