<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

/**
 * Papelera de marcadores borrados.
 *
 * Borrar aquí es reversible: la fila entera se guarda en JSON (`payload`), no en columnas
 * espejo, para que restaurar siga funcionando aunque el esquema de `bookmarks` cambie.
 *
 * Ojo: esto NO borra el marcador del navegador. La base es un espejo; para limpiar
 * Chrome/Firefox de verdad hay que exportar desde la app y reimportar allí.
 */
class Trash {
    /** Días que se conservan los borrados antes de purgarse solos. */
    public const KEEP_DAYS = 30;

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Mueve marcadores a la papelera. Devuelve cuántos se movieron.
     *
     * @param int[] $ids
     */
    public function moveMany(array $ids, string $reason = ''): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) {
            return 0;
        }

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->prepare("SELECT * FROM bookmarks WHERE id IN ($in)");
        $rows->execute($ids);
        $rows = $rows->fetchAll();

        if (!$rows) {
            return 0;
        }

        $insert = $this->db->prepare(
            'INSERT INTO trash (deleted_at, reason, url, title, payload)
             VALUES (:at, :reason, :url, :title, :payload)'
        );
        $delete = $this->db->prepare('DELETE FROM bookmarks WHERE id = ?');

        $now   = time();
        $moved = 0;

        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $insert->execute([
                    ':at'      => $now,
                    ':reason'  => $reason,
                    ':url'     => $row['url'] ?? '',
                    ':title'   => $row['title'] ?? '',
                    ':payload' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $delete->execute([(int) $row['id']]);
                $moved++;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $moved;
    }

    /**
     * Devuelve marcadores de la papelera a `bookmarks`.
     *
     * El id original puede estar ocupado por otra fila (p. ej. tras reimportar), así que
     * se reinserta sin `id` y se deja que SQLite asigne uno nuevo. Si la misma
     * (url, source) ya existe, se descarta la restauración de esa fila: el marcador ya volvió.
     *
     * @param int[] $ids ids de la tabla trash
     * @return array{restored:int, skipped:int}
     */
    public function restore(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) {
            return ['restored' => 0, 'skipped' => 0];
        }

        $in    = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->prepare("SELECT * FROM trash WHERE id IN ($in)");
        $stmt->execute($ids);
        $items = $stmt->fetchAll();

        $exists = $this->db->prepare('SELECT 1 FROM bookmarks WHERE url = ? AND source = ?');
        $drop   = $this->db->prepare('DELETE FROM trash WHERE id = ?');

        $restored = $skipped = 0;

        $this->db->beginTransaction();
        try {
            foreach ($items as $item) {
                $row = json_decode((string) $item['payload'], true);
                if (!is_array($row) || empty($row['url'])) {
                    $skipped++;
                    continue;
                }

                $exists->execute([$row['url'], $row['source'] ?? 'chrome']);
                if ($exists->fetchColumn()) {
                    $drop->execute([(int) $item['id']]);   // ya está de vuelta: se limpia la papelera
                    $skipped++;
                    continue;
                }

                unset($row['id']);
                $cols   = array_keys($row);
                $place  = implode(',', array_map(fn($c) => ":$c", $cols));
                $insert = $this->db->prepare(
                    'INSERT INTO bookmarks (' . implode(',', $cols) . ") VALUES ($place)"
                );
                $insert->execute(array_combine(array_map(fn($c) => ":$c", $cols), array_values($row)));

                $drop->execute([(int) $item['id']]);
                $restored++;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /** Listado paginado, más reciente primero. */
    public function list(int $page = 1, int $perPage = 50): array {
        $total  = (int) $this->db->query('SELECT COUNT(*) FROM trash')->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = $this->db->prepare(
            'SELECT id, deleted_at, reason, url, title FROM trash ORDER BY deleted_at DESC, id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$perPage, $offset]);

        return [
            'items'    => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** Borra definitivamente los ids indicados (o todo si $ids está vacío y $all es true). */
    public function purge(array $ids = [], bool $all = false): int {
        if ($all) {
            $n = (int) $this->db->query('SELECT COUNT(*) FROM trash')->fetchColumn();
            $this->db->exec('DELETE FROM trash');
            return $n;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) {
            return 0;
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM trash WHERE id IN ($in)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /** Purga automática de lo que lleva más de KEEP_DAYS días. */
    public function pruneOld(): int {
        $stmt = $this->db->prepare('DELETE FROM trash WHERE deleted_at < ?');
        $stmt->execute([time() - self::KEEP_DAYS * 86400]);
        return $stmt->rowCount();
    }

    public function count(): int {
        return (int) $this->db->query('SELECT COUNT(*) FROM trash')->fetchColumn();
    }
}
