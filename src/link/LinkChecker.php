<?php

declare(strict_types=1);

/**
 * Comprueba si las URLs siguen vivas, en paralelo con curl_multi.
 *
 * Reglas que vienen de medir contra los marcadores reales:
 *  - Un 403 casi siempre es Cloudflare bloqueando bots, no un enlace roto: eso es «dudoso»,
 *    nunca «muerto». Solo se propone borrar lo que está claramente muerto (DNS/404/410).
 *  - Sin User-Agent de navegador, bastantes sitios contestan 403 aunque estén vivos.
 *  - Muchos servidores no implementan HEAD: si falla, se reintenta con GET pidiendo
 *    solo los primeros 2 KB.
 *  - Nunca más de MAX_PER_HOST conexiones al mismo host a la vez.
 */
class LinkChecker {
    public const ALIVE      = 'alive';
    public const DUBIOUS    = 'dubious';
    public const DEAD       = 'dead';
    public const UNSUPPORTED = 'unsupported';      // chrome://, chrome-extension://…
    public const LOCAL      = 'unchecked_local';   // localhost / IP privada

    public const CONCURRENCY  = 10;
    public const MAX_PER_HOST = 2;

    private const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
                     . 'Chrome/140.0.0.0 Safari/537.36';

    private const CONNECT_TIMEOUT = 6;
    private const TOTAL_TIMEOUT   = 15;
    private const MAX_REDIRS      = 5;

    private int $concurrency;

    public function __construct(int $concurrency = self::CONCURRENCY) {
        $this->concurrency = max(1, min(32, $concurrency));
    }

    /**
     * Clasifica una URL sin llegar a pedirla, cuando ya se sabe que no tiene sentido.
     * Devuelve null si hay que comprobarla de verdad.
     */
    public static function preClassify(string $url): ?string {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::UNSUPPORTED;
        }
        return self::isLocalHost((string) parse_url($url, PHP_URL_HOST)) ? self::LOCAL : null;
    }

    /**
     * localhost e IPs privadas: desde dentro del contenedor «localhost» es el propio
     * contenedor, así que comprobarlas daría un falso muerto.
     */
    public static function isLocalHost(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.lan')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        return false;
    }

    /**
     * Traduce el resultado de curl a uno de los tres estados.
     *
     * @param int $errno código de curl (0 si hubo respuesta)
     */
    public static function classify(int $httpCode, int $errno): string {
        if ($errno !== 0) {
            return match ($errno) {
                CURLE_COULDNT_RESOLVE_HOST => self::DEAD,   // el dominio ya no existe
                CURLE_COULDNT_CONNECT      => self::DEAD,   // nadie escucha en ese puerto
                default                    => self::DUBIOUS, // timeout, SSL, exceso de redirecciones…
            };
        }

        if ($httpCode >= 200 && $httpCode < 400) {
            return self::ALIVE;
        }
        if ($httpCode === 404 || $httpCode === 410) {
            return self::DEAD;
        }
        // 401/403/429/5xx y cualquier otro: el sitio responde, así que no está muerto
        return self::DUBIOUS;
    }

    /**
     * Comprueba un lote de marcadores.
     *
     * @param array[]  $items    filas con al menos ['id' => int, 'url' => string]
     * @param callable|null $onResult fn(array $result): ?bool — por cada URL terminada;
     *                                 devolver false aborta el resto del lote (cancelación).
     * @return array[] resultados: id, url, status, code, error, redirect_url
     */
    public function checkBatch(array $items, ?callable $onResult = null): array {
        $queue   = array_values($items);
        $results = [];
        $active  = [];                       // handle id => contexto
        $perHost = [];                       // host => nº de conexiones en curso
        $stopped = false;

        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->concurrency);

        $emit = function (array $res) use (&$results, $onResult, &$stopped): void {
            $results[] = $res;
            if ($onResult !== null && $onResult($res) === false) {
                $stopped = true;
            }
        };

        // Los que no hay que pedir se resuelven sin tocar la red
        foreach ($queue as $i => $item) {
            $pre = self::preClassify((string) $item['url']);
            if ($pre !== null) {
                $emit([
                    'id' => (int) $item['id'], 'url' => $item['url'], 'status' => $pre,
                    'code' => 0, 'error' => null, 'redirect_url' => null,
                ]);
                unset($queue[$i]);
            }
        }
        $queue = array_values($queue);

        $fill = function () use (&$queue, &$active, &$perHost, $multi): void {
            foreach ($queue as $i => $item) {
                if (count($active) >= $this->concurrency) {
                    return;
                }
                $host = strtolower((string) parse_url((string) $item['url'], PHP_URL_HOST));
                if (($perHost[$host] ?? 0) >= self::MAX_PER_HOST) {
                    continue;   // ese host está ocupado; se prueba con el siguiente de la cola
                }

                $ch = $this->makeHandle((string) $item['url'], 'HEAD');
                curl_multi_add_handle($multi, $ch);
                $active[(int) $ch] = ['item' => $item, 'handle' => $ch, 'host' => $host, 'method' => 'HEAD'];
                $perHost[$host] = ($perHost[$host] ?? 0) + 1;
                unset($queue[$i]);
            }
            $queue = array_values($queue);
        };

        $fill();

        while ($active) {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running) {
                curl_multi_select($multi, 1.0);
            }

            while ($done = curl_multi_info_read($multi)) {
                $ch  = $done['handle'];
                $key = (int) $ch;
                $ctx = $active[$key] ?? null;
                if (!$ctx) {
                    curl_multi_remove_handle($multi, $ch);
                    curl_close($ch);
                    continue;
                }

                $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $errno = (int) $done['result'];
                $eff   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                unset($active[$key]);
                $perHost[$ctx['host']] = max(0, ($perHost[$ctx['host']] ?? 1) - 1);

                // HEAD que no sirve: se reintenta con GET parcial antes de juzgar
                $retry = $ctx['method'] === 'HEAD'
                    && ($errno !== 0 || $code === 403 || $code === 405 || $code === 501 || $code >= 500);

                if ($retry && !$stopped) {
                    $ch2 = $this->makeHandle((string) $ctx['item']['url'], 'GET');
                    curl_multi_add_handle($multi, $ch2);
                    $active[(int) $ch2] = $ctx + ['handle' => $ch2];
                    $active[(int) $ch2]['method'] = 'GET';
                    $perHost[$ctx['host']] = ($perHost[$ctx['host']] ?? 0) + 1;
                    continue;
                }

                $url    = (string) $ctx['item']['url'];
                $status = self::classify($code, $errno);
                $emit([
                    'id'           => (int) $ctx['item']['id'],
                    'url'          => $url,
                    'status'       => $status,
                    'code'         => $code,
                    'error'        => $errno !== 0 ? curl_strerror($errno) : null,
                    'redirect_url' => ($eff !== '' && rtrim($eff, '/') !== rtrim($url, '/')) ? $eff : null,
                ]);
            }

            if ($stopped) {
                break;
            }
            $fill();
        }

        // Cancelado a mitad: se cierra lo que quedaba en vuelo
        foreach ($active as $ctx) {
            curl_multi_remove_handle($multi, $ctx['handle']);
            curl_close($ctx['handle']);
        }
        curl_multi_close($multi);

        return $results;
    }

    private function makeHandle(string $url, string $method) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
        ]);
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_RANGE, '0-2048');   // solo el principio: no descargar el sitio entero
        }
        return $ch;
    }
}
