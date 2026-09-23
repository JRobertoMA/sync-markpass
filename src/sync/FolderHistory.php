<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

/**
 * Historial de cambios masivos de carpeta, para poder deshacerlos.
 *
 * Uso: el endpoint abre su transacción, llama a snapshot() con los ids que va a tocar
 * ANTES de actualizarlos, actualiza, y cierra con finish(). Todo dentro de la misma
 * transacción que ya orquesta el controlador.
 *
 * Solo se puede deshacer el lote más reciente no deshecho: deshacer en desorden
 * restauraría estados intermedios que ya no tienen sentido.
 */
class FolderHistory {
    /** Lotes que se conservan; al crear uno nuevo se podan los más antiguos. */
    public const KEEP_BATCHES = 20;
    public const KEEP_DAYS    = 30;

    /** Tipos válidos de lote. La columna no lleva CHECK (ver Database::migrate), se valida aquí. */
    public const KINDS = ['categorize', 'homologate', 'adopt_redirect'];

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getInstance();
    }

    /** Abre un lote y devuelve su id. */
    public function start(string $kind, string $summary = ''): int {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Tipo de lote no válido: $kind");
        }
        $stmt = $this->db->prepare(
            'INSERT INTO folder_batch (created_at, kind, summary) VALUES (:now, :kind, :summary)'
        );
        $stmt->execute([':now' => time(), ':kind' => $kind, ':summary' => $summary]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Guarda el estado actual de los marcadores indicados antes de modificarlos.
     * Ignora ids que ya estén en el lote (no se duplica el estado original).
     *
     * @param int[] $bookmarkIds
     */
    public function snapshot(int $batchId, array $bookmarkIds): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookmarkIds), fn($i) => $i > 0)));
        if (!$ids) {
            return 0;
        }

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "INSERT INTO folder_history (batch_id, bookmark_id, old_folder_path, old_is_categorized, old_url)
             SELECT ?, b.id, b.folder_path, b.is_categorized, b.url
             FROM bookmarks b
             WHERE b.id IN ($in)
               AND b.id NOT IN (SELECT bookmark_id FROM folder_history WHERE batch_id = ?)"
        );
        $stmt->execute([$batchId, ...$ids, $batchId]);
        return $stmt->rowCount();
    }

    /** Cierra el lote con el número real de filas afectadas; lo borra si no cambió nada. */
    public function finish(int $batchId, string $summary = ''): void {
        $affected = (int) $this->db->query(
            "SELECT COUNT(*) FROM folder_history WHERE batch_id = $batchId"
        )->fetchColumn();

        if ($affected === 0) {
            $this->db->prepare('DELETE FROM folder_batch WHERE id = ?')->execute([$batchId]);
            return;
        }

        $sql    = 'UPDATE folder_batch SET affected = :n' . ($summary !== '' ? ', summary = :s' : '') . ' WHERE id = :id';
        $params = [':n' => $affected, ':id' => $batchId];
        if ($summary !== '') {
            $params[':s'] = $summary;
        }
        $this->db->prepare($sql)->execute($params);
    }

    /** Últimos lotes, más reciente primero. */
    public function recent(int $limit = 10): array {
        $stmt = $this->db->prepare(
            'SELECT id, created_at, kind, summary, affected, undone_at
             FROM folder_batch ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll();

        $undoableId = $this->undoableId();
        foreach ($rows as &$r) {
            $r['id']        = (int) $r['id'];
            $r['affected']  = (int) $r['affected'];
            $r['undone']    = $r['undone_at'] !== null;
            $r['can_undo']  = $r['id'] === $undoableId;
        }
        return $rows;
    }

    /** Id del único lote que se puede deshacer ahora mismo (el más reciente sin deshacer). */
    public function undoableId(): ?int {
        $id = $this->db->query(
            'SELECT id FROM folder_batch WHERE undone_at IS NULL ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Restaura las carpetas de un lote. Devuelve el número de marcadores restaurados.
     *
     * @throws RuntimeException si el lote no existe, ya se deshizo o no es el más reciente.
     */
    public function undo(int $batchId): int {
        $stmt = $this->db->prepare('SELECT id, undone_at, kind, summary FROM folder_batch WHERE id = ?');
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();

        if (!$batch) {
            throw new RuntimeException('Ese cambio ya no está en el historial.');
        }
        if ($batch['undone_at'] !== null) {
            throw new RuntimeException('Ese cambio ya se deshizo.');
        }
        if ($this->undoableId() !== $batchId) {
            throw new RuntimeException('Solo se puede deshacer el cambio más reciente.');
        }

        $this->db->beginTransaction();
        try {
            $restored = $this->db->prepare(
                'UPDATE bookmarks
                 SET folder_path = (SELECT h.old_folder_path FROM folder_history h
                                    WHERE h.batch_id = :b AND h.bookmark_id = bookmarks.id),
                     is_categorized = (SELECT h.old_is_categorized FROM folder_history h
                                       WHERE h.batch_id = :b2 AND h.bookmark_id = bookmarks.id),
                     -- old_url es NULL en los lotes anteriores a esta columna: COALESCE deja la url actual
                     url = COALESCE((SELECT h.old_url FROM folder_history h
                                     WHERE h.batch_id = :b4 AND h.bookmark_id = bookmarks.id), url)
                 WHERE id IN (SELECT bookmark_id FROM folder_history WHERE batch_id = :b3)'
            );
            $restored->execute([':b' => $batchId, ':b2' => $batchId, ':b3' => $batchId, ':b4' => $batchId]);
            $n = $restored->rowCount();

            $this->db->prepare('UPDATE folder_batch SET undone_at = ? WHERE id = ?')
                     ->execute([time(), $batchId]);
            $this->db->commit();
            return $n;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Poda lotes viejos para que el .sqlite no crezca sin control. */
    public function prune(): void {
        $cutoff = time() - self::KEEP_DAYS * 86400;
        $this->db->prepare('DELETE FROM folder_history WHERE batch_id IN
                            (SELECT id FROM folder_batch WHERE created_at < ?)')->execute([$cutoff]);
        $this->db->prepare('DELETE FROM folder_batch WHERE created_at < ?')->execute([$cutoff]);

        $keepFrom = $this->db->prepare(
            'SELECT id FROM folder_batch ORDER BY id DESC LIMIT 1 OFFSET ?'
        );
        $keepFrom->execute([self::KEEP_BATCHES]);
        $oldest = $keepFrom->fetchColumn();
        if ($oldest !== false) {
            $this->db->prepare('DELETE FROM folder_history WHERE batch_id <= ?')->execute([$oldest]);
            $this->db->prepare('DELETE FROM folder_batch WHERE id <= ?')->execute([$oldest]);
        }
    }
}
