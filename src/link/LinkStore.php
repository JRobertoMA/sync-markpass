<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

/**
 * Único sitio que escribe el estado de los enlaces.
 *
 * Dos columnas distintas, a propósito:
 *  - link_status      → el estado que ve la app (contadores, pestañas, ámbitos).
 *  - link_auto_status → lo que dio la última comprobación automática, siempre.
 *
 * Sin marca manual son iguales. Con marca manual (link_manual_at no nulo), link_status
 * se queda en 'alive' pase lo que pase, y link_auto_status permite avisar si la
 * comprobación automática pasa a dar un muerto claro.
 *
 * Antes el UPDATE estaba duplicado en el runner del job y en `recheck`; tenerlo aquí
 * evita que uno de los dos caminos se olvide de respetar la marca.
 */
class LinkStore {
    private PDO $db;
    private ?PDOStatement $save = null;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getInstance();
    }

    /** Guarda el resultado de una comprobación automática (fila de LinkChecker::checkBatch). */
    public function save(array $r): void {
        $this->save ??= $this->db->prepare(
            "UPDATE bookmarks
             SET link_auto_status  = :status,
                 link_status       = CASE WHEN link_manual_at IS NOT NULL THEN 'alive' ELSE :status2 END,
                 link_code         = :code,
                 link_error        = :error,
                 link_redirect_url = :redirect,
                 link_checked_at   = :at,
                 link_fail_count   = CASE WHEN :status3 = 'alive' THEN 0 ELSE link_fail_count + 1 END
             WHERE id = :id"
        );
        $this->save->execute([
            ':status'   => $r['status'],
            ':status2'  => $r['status'],
            ':status3'  => $r['status'],
            ':code'     => $r['code'],
            ':error'    => $r['error'],
            ':redirect' => $r['redirect_url'],
            ':at'       => time(),
            ':id'       => $r['id'],
        ]);
    }

    /**
     * Marca como vivos a mano. Devuelve cuántos se marcaron.
     *
     * @param int[] $ids
     */
    public function markAlive(array $ids): int {
        [$in, $ids] = $this->in($ids);
        if (!$ids) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "UPDATE bookmarks SET link_manual_at = ?, link_status = 'alive' WHERE id IN ($in)"
        );
        $stmt->execute([time(), ...$ids]);
        return $stmt->rowCount();
    }

    /**
     * Quita la marca manual y vuelve al último resultado automático.
     *
     * @param int[] $ids
     */
    public function unmark(array $ids): int {
        [$in, $ids] = $this->in($ids);
        if (!$ids) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "UPDATE bookmarks
             SET link_manual_at = NULL,
                 link_status    = COALESCE(link_auto_status, link_status)
             WHERE id IN ($in) AND link_manual_at IS NOT NULL"
        );
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /** @return array{0:string, 1:int[]} placeholders y ids limpios */
    private function in(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        return [implode(',', array_fill(0, count($ids), '?')), $ids];
    }
}
