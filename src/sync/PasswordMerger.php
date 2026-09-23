<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../vault/Vault.php';

/**
 * Import, comparación, deduplicación y selección de contraseñas entre Chrome y Firefox.
 * Necesita una Vault desbloqueada: cifra al importar y descifra solo lo que se muestra o exporta.
 *
 * Clave de comparación entre navegadores: (host sin www., username_hash).
 * Dos filas con la misma clave y distinto password_hash son un "conflicto".
 */
class PasswordMerger {
    private PDO   $db;
    private Vault $vault;

    public function __construct(Vault $vault) {
        $this->db    = Database::getInstance();
        $this->vault = $vault;
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * Upsert por (source, origin, username_hash, http_realm). Nunca borra lo que ya no está en el CSV.
     * Firefox: se actualiza si time_password_changed es más reciente.
     * Chrome (sin fechas): se actualiza si cambió la contraseña.
     *
     * @param array[] $rows filas de PasswordCsvParser
     * @return array{added:int, updated:int, unchanged:int}
     */
    public function import(array $rows, string $source): array {
        $existing = $this->db->prepare(
            'SELECT id, password_hash, time_password_changed FROM passwords
             WHERE source = :source AND origin = :origin AND username_hash = :uh AND http_realm = :realm'
        );
        $insert = $this->db->prepare(
            'INSERT INTO passwords (source, origin, host, url, name, username_enc, password_enc, note_enc,
                                    username_hash, password_hash, http_realm, form_action_origin, guid,
                                    time_created, time_last_used, time_password_changed, is_android)
             VALUES (:source, :origin, :host, :url, :name, :ue, :pe, :ne, :uh, :ph, :realm, :fao, :guid,
                     :tc, :tlu, :tpc, :android)'
        );
        $update = $this->db->prepare(
            'UPDATE passwords SET url = :url, name = :name, password_enc = :pe, note_enc = :ne, password_hash = :ph,
                    form_action_origin = :fao, guid = :guid, time_created = :tc, time_last_used = :tlu,
                    time_password_changed = :tpc, is_preferred = 0
             WHERE id = :id'
        );

        $counters = ['added' => 0, 'updated' => 0, 'unchanged' => 0];

        $this->db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $uh = $this->vault->hash($r['username']);
                $ph = $this->vault->hash($r['password']);

                $existing->execute([':source' => $source, ':origin' => $r['origin'], ':uh' => $uh, ':realm' => $r['http_realm']]);
                $cur = $existing->fetch();

                $common = [
                    ':url'  => $r['url'],
                    ':name' => $r['name'],
                    ':fao'  => $r['form_action_origin'],
                    ':guid' => $r['guid'],
                    ':tc'   => $r['time_created'],
                    ':tlu'  => $r['time_last_used'],
                    ':tpc'  => $r['time_password_changed'],
                ];

                if (!$cur) {
                    $stmt = $insert;
                    $stmt->bindValue(':source',  $source);
                    $stmt->bindValue(':origin',  $r['origin']);
                    $stmt->bindValue(':host',    $r['host']);
                    $stmt->bindValue(':ue',      $this->vault->encrypt($r['username']), PDO::PARAM_LOB);
                    $stmt->bindValue(':uh',      $uh);
                    $stmt->bindValue(':realm',   $r['http_realm']);
                    $stmt->bindValue(':android', $r['is_android'], PDO::PARAM_INT);
                    $this->bindCommon($stmt, $common, $r, $ph);
                    $stmt->execute();
                    $counters['added']++;
                    continue;
                }

                $incomingNewer = $r['time_password_changed'] !== null
                    ? (int) $r['time_password_changed'] > (int) ($cur['time_password_changed'] ?? 0)
                    : $ph !== $cur['password_hash'];

                if (!$incomingNewer) {
                    $counters['unchanged']++;
                    continue;
                }

                $stmt = $update;
                $stmt->bindValue(':id', (int) $cur['id'], PDO::PARAM_INT);
                $this->bindCommon($stmt, $common, $r, $ph);
                $stmt->execute();
                $counters['updated']++;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $counters;
    }

    private function bindCommon(PDOStatement $stmt, array $common, array $r, string $ph): void {
        foreach ($common as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $stmt->bindValue(':pe', $this->vault->encrypt($r['password']), PDO::PARAM_LOB);
        $stmt->bindValue(':ne', $r['note'] !== '' ? $this->vault->encrypt($r['note']) : null, $r['note'] !== '' ? PDO::PARAM_LOB : PDO::PARAM_NULL);
        $stmt->bindValue(':ph', $ph);
    }

    // ── Diff ──────────────────────────────────────────────────────────────────

    /**
     * Agrupa por (host, username_hash) y clasifica.
     *
     * @return array{only_chrome: array[], only_firefox: array[], in_both: array[], conflicts: array[],
     *               total_chrome:int, total_firefox:int}
     */
    public function getDiff(?string $search = null): array {
        $rows   = $this->fetchRows($search);
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['host'] . '|' . $r['username_hash']][$r['source']][] = $r;
        }

        $onlyChrome = $onlyFirefox = $inBoth = $conflicts = [];
        $totalChrome = $totalFirefox = 0;

        foreach ($groups as $key => $bySource) {
            $chrome  = $bySource['chrome']  ?? [];
            $firefox = $bySource['firefox'] ?? [];
            $totalChrome  += count($chrome);
            $totalFirefox += count($firefox);

            if (!$firefox) {
                foreach ($chrome as $r) $onlyChrome[] = $this->publicRow($r);
                continue;
            }
            if (!$chrome) {
                foreach ($firefox as $r) $onlyFirefox[] = $this->publicRow($r);
                continue;
            }

            $c = $this->representative($chrome);
            $f = $this->representative($firefox);
            $samePassword = array_intersect(array_column($chrome, 'password_hash'), array_column($firefox, 'password_hash')) !== [];

            if ($samePassword) {
                $row = $this->publicRow($this->pickWinner($c, $f));
                $row['source']     = 'both';
                $row['chrome_id']  = (int) $c['id'];
                $row['firefox_id'] = (int) $f['id'];
                $inBoth[] = $row;
            } else {
                $winner = $this->pickWinner($c, $f);
                $conflicts[] = [
                    'key'      => $key,
                    'host'     => $c['host'],
                    'username' => $this->vault->decrypt($c['username_enc']) ?? '',
                    'chrome'   => $this->publicRow($c),
                    'firefox'  => $this->publicRow($f),
                    'winner'   => $winner['source'],
                    'resolved' => (int) $c['is_preferred'] === 1 || (int) $f['is_preferred'] === 1,
                ];
            }
        }

        return [
            'only_chrome'   => $onlyChrome,
            'only_firefox'  => $onlyFirefox,
            'in_both'       => $inBoth,
            'conflicts'     => $conflicts,
            'total_chrome'  => $totalChrome,
            'total_firefox' => $totalFirefox,
        ];
    }

    public function getStats(): array {
        $diff = $this->getDiff();
        return [
            'only_chrome'   => count($diff['only_chrome']),
            'only_firefox'  => count($diff['only_firefox']),
            'in_both'       => count($diff['in_both']),
            'conflicts'     => count($diff['conflicts']),
            'total_chrome'  => $diff['total_chrome'],
            'total_firefox' => $diff['total_firefox'],
        ];
    }

    // ── Duplicados dentro del mismo navegador ─────────────────────────────────

    /**
     * Grupos con más de una fila para (source, host, username_hash): http vs https, con/sin www, puerto…
     *
     * @return array[] [{source, host, username, same_password, rows: [...]}]
     */
    public function getDuplicateGroups(?string $source = null): array {
        $rows   = $this->fetchRows(null, $source);
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['source'] . '|' . $r['host'] . '|' . $r['username_hash']][] = $r;
        }

        $result = [];
        foreach ($groups as $g) {
            if (count($g) < 2) continue;
            // sugerido por defecto: https, sin www, más reciente
            usort($g, fn($a, $b) => $this->dedupScore($b) <=> $this->dedupScore($a));
            $result[] = [
                'source'        => $g[0]['source'],
                'host'          => $g[0]['host'],
                'username'      => $this->vault->decrypt($g[0]['username_enc']) ?? '',
                'same_password' => count(array_unique(array_column($g, 'password_hash'))) === 1,
                'rows'          => array_map(fn($r) => $this->publicRow($r), $g),
            ];
        }
        usort($result, fn($a, $b) => [$a['source'], $a['host']] <=> [$b['source'], $b['host']]);
        return $result;
    }

    private function dedupScore(array $r): int {
        $score = 0;
        if (str_starts_with($r['origin'], 'https://')) $score += 100;
        if (!str_contains($r['url'], '://www.'))       $score += 10;
        $score += min(9, (int) (((int) ($r['time_password_changed'] ?? 0)) / 100_000_000)); // más reciente gana empates
        return $score;
    }

    // ── Acciones puntuales ────────────────────────────────────────────────────

    public function reveal(int $id): ?array {
        $stmt = $this->db->prepare('SELECT username_enc, password_enc, note_enc FROM passwords WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) return null;
        return [
            'username' => $this->vault->decrypt($r['username_enc']) ?? '',
            'password' => $this->vault->decrypt($r['password_enc']) ?? '',
            'note'     => $r['note_enc'] !== null ? ($this->vault->decrypt($r['note_enc']) ?? '') : '',
        ];
    }

    /** Marca el ganador de un conflicto. $winnerId debe pertenecer al grupo (host, username_hash). */
    public function resolveConflict(int $winnerId): bool {
        $stmt = $this->db->prepare('SELECT host, username_hash FROM passwords WHERE id = ?');
        $stmt->execute([$winnerId]);
        $w = $stmt->fetch();
        if (!$w) return false;

        $this->db->beginTransaction();
        $this->db->prepare('UPDATE passwords SET is_preferred = 0 WHERE host = ? AND username_hash = ?')
                 ->execute([$w['host'], $w['username_hash']]);
        $this->db->prepare('UPDATE passwords SET is_preferred = 1 WHERE id = ?')->execute([$winnerId]);
        $this->db->commit();
        return true;
    }

    /** @param int[] $ids */
    public function deleteRows(array $ids): int {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) return 0;
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM passwords WHERE id IN ($in)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    // ── Export ────────────────────────────────────────────────────────────────

    /**
     * Filas descifradas listas para el exportador.
     *
     * @param string $scope  merged | missing_in_chrome | missing_in_firefox
     * @param string $target chrome | firefox  (firefox excluye is_android)
     */
    public function getExportRows(string $scope, string $target): array {
        $rows   = $this->fetchRows();
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['host'] . '|' . $r['username_hash']][$r['source']][] = $r;
        }

        $out = [];
        foreach ($groups as $bySource) {
            $chrome  = $bySource['chrome']  ?? [];
            $firefox = $bySource['firefox'] ?? [];

            $c = $chrome  ? $this->representative($chrome)  : null;
            $f = $firefox ? $this->representative($firefox) : null;

            switch ($scope) {
                case 'missing_in_chrome':
                    if (!$f) continue 2;
                    if ($c && $this->samePassword($chrome, $firefox)) continue 2;
                    $pick = $c ? $this->pickWinner($c, $f) : $f;
                    if ($pick['source'] === 'chrome') continue 2; // conflicto resuelto a favor de Chrome: nada que llevar
                    break;
                case 'missing_in_firefox':
                    if (!$c) continue 2;
                    if ($f && $this->samePassword($chrome, $firefox)) continue 2;
                    $pick = $f ? $this->pickWinner($c, $f) : $c;
                    if ($pick['source'] === 'firefox') continue 2;
                    break;
                default: // merged
                    $pick = ($c && $f) ? $this->pickWinner($c, $f) : ($c ?? $f);
            }

            if ($target === 'firefox' && (int) $pick['is_android'] === 1) continue;

            $out[] = [
                'origin'                => $pick['origin'],
                'url'                   => $pick['url'],
                'name'                  => $pick['name'],
                'username'              => $this->vault->decrypt($pick['username_enc']) ?? '',
                'password'              => $this->vault->decrypt($pick['password_enc']) ?? '',
                'note'                  => $pick['note_enc'] !== null ? ($this->vault->decrypt($pick['note_enc']) ?? '') : '',
                'http_realm'            => $pick['http_realm'],
                'form_action_origin'    => $pick['form_action_origin'],
                'time_created'          => $pick['time_created'],
                'time_last_used'        => $pick['time_last_used'],
                'time_password_changed' => $pick['time_password_changed'],
                'is_android'            => (int) $pick['is_android'],
            ];
        }

        usort($out, fn($a, $b) => [$a['origin'], $a['username']] <=> [$b['origin'], $b['username']]);
        return $out;
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function fetchRows(?string $search = null, ?string $source = null): array {
        $sql    = 'SELECT * FROM passwords WHERE 1=1';
        $params = [];
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $params[] = $source;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (host LIKE ? OR name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY host, source, origin';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Fila visible en listados: sin ciphertext ni hashes; usuario descifrado, contraseña nunca. */
    private function publicRow(array $r): array {
        return [
            'id'                    => (int) $r['id'],
            'source'                => $r['source'],
            'host'                  => $r['host'],
            'origin'                => $r['origin'],
            'url'                   => $r['url'],
            'name'                  => $r['name'],
            'username'              => $this->vault->decrypt($r['username_enc']) ?? '',
            'has_note'              => $r['note_enc'] !== null,
            'http_realm'            => $r['http_realm'],
            'time_password_changed' => $r['time_password_changed'] !== null ? (int) $r['time_password_changed'] : null,
            'is_android'            => (int) $r['is_android'],
            'is_preferred'          => (int) $r['is_preferred'],
        ];
    }

    /** Entre varias filas del mismo navegador y misma clave, la preferida o la más reciente. */
    private function representative(array $rows): array {
        usort($rows, fn($a, $b) =>
            [(int) $b['is_preferred'], (int) ($b['time_password_changed'] ?? 0), $this->dedupScore($b)]
            <=> [(int) $a['is_preferred'], (int) ($a['time_password_changed'] ?? 0), $this->dedupScore($a)]
        );
        return $rows[0];
    }

    /**
     * Ganador entre la fila de Chrome y la de Firefox: la marcada como preferida; si no,
     * la de time_password_changed más reciente. Chrome no exporta fechas → por defecto gana Firefox.
     */
    private function pickWinner(array $c, array $f): array {
        if ((int) $c['is_preferred'] === 1) return $c;
        if ((int) $f['is_preferred'] === 1) return $f;
        return (int) ($c['time_password_changed'] ?? 0) > (int) ($f['time_password_changed'] ?? 0) ? $c : $f;
    }

    private function samePassword(array $chrome, array $firefox): bool {
        return array_intersect(array_column($chrome, 'password_hash'), array_column($firefox, 'password_hash')) !== [];
    }
}
