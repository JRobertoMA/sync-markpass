<?php

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath = __DIR__ . '/../data/bookmarks.sqlite';

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . self::$dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function migrate(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS folders (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT    NOT NULL,
                path     TEXT    NOT NULL UNIQUE,
                parent_path TEXT,
                source   TEXT    NOT NULL CHECK(source IN ('chrome','firefox','both'))
            );

            CREATE TABLE IF NOT EXISTS bookmarks (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                url            TEXT    NOT NULL,
                title          TEXT    NOT NULL DEFAULT '',
                folder_path    TEXT    NOT NULL DEFAULT '/',
                add_date       INTEGER,
                last_modified  INTEGER,
                icon           TEXT,
                source         TEXT    NOT NULL CHECK(source IN ('chrome','firefox','both')),
                is_categorized INTEGER NOT NULL DEFAULT 0,
                UNIQUE(url, source)
            );

            CREATE INDEX IF NOT EXISTS idx_bookmarks_url ON bookmarks(url);
            CREATE INDEX IF NOT EXISTS idx_bookmarks_source ON bookmarks(source);

            -- Bóveda de contraseñas: una sola fila con el salt de Argon2id y un verificador
            -- cifrado con la clave derivada. La clave maestra nunca se guarda.
            CREATE TABLE IF NOT EXISTS vault (
                id              INTEGER PRIMARY KEY CHECK(id = 1),
                salt            BLOB    NOT NULL,
                verifier        BLOB    NOT NULL,
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until    INTEGER,
                created_at      INTEGER NOT NULL
            );

            -- Credenciales importadas de los CSV de Chrome/Firefox. usuario, contraseña y nota van
            -- cifrados (secretbox, nonce por campo); *_hash son hashes con clave para poder comparar
            -- filas en SQL sin descifrar nada.
            CREATE TABLE IF NOT EXISTS passwords (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                source                TEXT    NOT NULL CHECK(source IN ('chrome','firefox')),
                origin                TEXT    NOT NULL,
                host                  TEXT    NOT NULL,
                url                   TEXT    NOT NULL,
                name                  TEXT    NOT NULL DEFAULT '',
                username_enc          BLOB    NOT NULL,
                password_enc          BLOB    NOT NULL,
                note_enc              BLOB,
                username_hash         TEXT    NOT NULL,
                password_hash         TEXT    NOT NULL,
                http_realm            TEXT    NOT NULL DEFAULT '',
                form_action_origin    TEXT    NOT NULL DEFAULT '',
                guid                  TEXT    NOT NULL DEFAULT '',
                time_created          INTEGER,
                time_last_used        INTEGER,
                time_password_changed INTEGER,
                is_android            INTEGER NOT NULL DEFAULT 0,
                is_preferred          INTEGER NOT NULL DEFAULT 0,
                UNIQUE(source, origin, username_hash, http_realm)
            );

            CREATE INDEX IF NOT EXISTS idx_passwords_host_user ON passwords(host, username_hash);
            CREATE INDEX IF NOT EXISTS idx_passwords_source ON passwords(source);

            -- Historial de cambios masivos de carpeta, para poder deshacerlos.
            -- Un folder_batch por acción (categorizar / homologar) y una fila de
            -- folder_history por marcador con su estado anterior.
            -- `kind` no lleva CHECK a propósito: cada tipo nuevo de cambio reversible
            -- (categorize, homologate, adopt_redirect…) obligaría a recrear la tabla.
            -- Se valida en PHP, en FolderHistory::KINDS.
            CREATE TABLE IF NOT EXISTS folder_batch (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at INTEGER NOT NULL,
                kind       TEXT    NOT NULL,
                summary    TEXT    NOT NULL DEFAULT '',
                affected   INTEGER NOT NULL DEFAULT 0,
                undone_at  INTEGER
            );

            CREATE TABLE IF NOT EXISTS folder_history (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id           INTEGER NOT NULL REFERENCES folder_batch(id) ON DELETE CASCADE,
                bookmark_id        INTEGER NOT NULL,
                old_folder_path    TEXT    NOT NULL,
                old_is_categorized INTEGER NOT NULL DEFAULT 0,
                old_url            TEXT
            );

            CREATE INDEX IF NOT EXISTS idx_folder_history_batch ON folder_history(batch_id);

            -- Papelera: marcadores borrados, con la fila entera en JSON para poder
            -- restaurarlos aunque el esquema de bookmarks cambie más adelante.
            CREATE TABLE IF NOT EXISTS trash (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                deleted_at INTEGER NOT NULL,
                reason     TEXT    NOT NULL DEFAULT '',
                url        TEXT    NOT NULL,
                title      TEXT    NOT NULL DEFAULT '',
                payload    TEXT    NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_trash_deleted ON trash(deleted_at);
        ");

        // Migration for existing databases: add is_categorized if missing
        $cols = array_column($db->query('PRAGMA table_info(bookmarks)')->fetchAll(), 'name');
        if (!in_array('is_categorized', $cols, true)) {
            $db->exec('ALTER TABLE bookmarks ADD COLUMN is_categorized INTEGER NOT NULL DEFAULT 0');
        }

        // Estado de comprobación de enlaces (añadido después; la tabla ya existía)
        $linkCols = [
            'link_status'       => 'TEXT',
            'link_code'         => 'INTEGER',
            'link_error'        => 'TEXT',
            'link_redirect_url' => 'TEXT',
            'link_checked_at'   => 'INTEGER',
            'link_fail_count'   => 'INTEGER NOT NULL DEFAULT 0',
        ];
        foreach ($linkCols as $name => $type) {
            if (!in_array($name, $cols, true)) {
                $db->exec("ALTER TABLE bookmarks ADD COLUMN $name $type");
            }
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bookmarks_link_status ON bookmarks(link_status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bookmarks_link_checked ON bookmarks(link_checked_at)');

        // Marca manual de «vivo». link_status es el estado que ve la app; link_auto_status
        // guarda siempre lo que dio la última comprobación automática, para poder avisar
        // si un enlace marcado a mano pasa a estar muerto de verdad.
        if (!in_array('link_manual_at', $cols, true)) {
            $db->exec('ALTER TABLE bookmarks ADD COLUMN link_manual_at INTEGER');
        }
        if (!in_array('link_auto_status', $cols, true)) {
            $db->exec('ALTER TABLE bookmarks ADD COLUMN link_auto_status TEXT');
            // Hasta ahora el estado automático y el efectivo eran lo mismo
            $db->exec('UPDATE bookmarks SET link_auto_status = link_status WHERE link_status IS NOT NULL');
        }

        // El historial también guarda la url, para poder deshacer la adopción de redirecciones
        $hCols = array_column($db->query('PRAGMA table_info(folder_history)')->fetchAll(), 'name');
        if (!in_array('old_url', $hCols, true)) {
            $db->exec('ALTER TABLE folder_history ADD COLUMN old_url TEXT');
        }

        self::dropFolderBatchKindCheck($db);
    }

    /**
     * Las bases creadas antes tienen CHECK(kind IN ('categorize','homologate')) en folder_batch,
     * que impide registrar tipos nuevos de cambio reversible. SQLite no permite alterar un CHECK:
     * hay que recrear la tabla.
     */
    private static function dropFolderBatchKindCheck(PDO $db): void {
        $sql = $db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='folder_batch'"
        )->fetchColumn();

        if (!$sql || !str_contains((string) $sql, 'CHECK(kind IN')) {
            return;
        }

        // Las FK deben quedar desactivadas durante el DROP; el PRAGMA no funciona dentro de una transacción.
        $db->exec('PRAGMA foreign_keys = OFF');
        try {
            $db->beginTransaction();
            $db->exec("
                CREATE TABLE folder_batch_new (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    created_at INTEGER NOT NULL,
                    kind       TEXT    NOT NULL,
                    summary    TEXT    NOT NULL DEFAULT '',
                    affected   INTEGER NOT NULL DEFAULT 0,
                    undone_at  INTEGER
                )
            ");
            $db->exec('INSERT INTO folder_batch_new (id, created_at, kind, summary, affected, undone_at)
                       SELECT id, created_at, kind, summary, affected, undone_at FROM folder_batch');
            $db->exec('DROP TABLE folder_batch');
            $db->exec('ALTER TABLE folder_batch_new RENAME TO folder_batch');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } finally {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }

    public static function reset(): void {
        self::$instance = null;
        if (file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
    }
}
