<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

/**
 * Bóveda de contraseñas cifrada con contraseña maestra (libsodium).
 *
 * - La clave maestra se deriva con Argon2id (sodium_crypto_pwhash) a partir de la
 *   contraseña y un salt aleatorio guardado en la tabla `vault`. Nunca se escribe en disco.
 * - De la clave maestra se derivan dos subclaves (sodium_crypto_kdf): una para cifrar
 *   campos con secretbox y otra para hashear usuario/contraseña con generichash con clave,
 *   de forma que se puedan comparar filas en SQL sin descifrar.
 * - Tras desbloquear, la clave maestra vive en la sesión PHP (base64) y se cierra sola
 *   pasados IDLE_SECONDS sin actividad.
 */
class Vault {
    public const SESSION_NAME  = 'sbvault';
    public const IDLE_SECONDS  = 900;   // 15 min
    public const MAX_ATTEMPTS  = 5;
    public const LOCK_SECONDS  = 300;   // 5 min tras MAX_ATTEMPTS fallos
    public const MIN_MASTER_LEN = 12;

    private const KDF_CONTEXT  = 'sbvault!';
    private const KDF_ID_ENC   = 1;
    private const KDF_ID_MAC   = 2;
    private const VERIFIER_TEXT = 'sync-bookmarks-vault-ok';

    private PDO     $db;
    private ?string $encKey = null;
    private ?string $macKey = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ── Sesión ────────────────────────────────────────────────────────────────

    /**
     * Arranca la sesión de la bóveda con cookie HttpOnly + SameSite=Strict limitada al proyecto.
     */
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => ($base === '' ? '/' : $base . '/'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string {
        return $_SESSION['csrf'] ?? '';
    }

    public static function checkCsrf(?string $token): bool {
        $expected = $_SESSION['csrf'] ?? '';
        return $expected !== '' && $token !== null && hash_equals($expected, $token);
    }

    // ── Estado ────────────────────────────────────────────────────────────────

    public function exists(): bool {
        return (bool) $this->db->query('SELECT 1 FROM vault WHERE id = 1')->fetchColumn();
    }

    /**
     * Intenta restaurar la clave desde la sesión. Devuelve false si no hay clave o si
     * la bóveda lleva demasiado tiempo inactiva (en ese caso la bloquea).
     */
    public function isUnlocked(): bool {
        if ($this->encKey !== null) {
            return true;
        }
        $key  = $_SESSION['vault_key']  ?? null;
        $last = (int) ($_SESSION['vault_last'] ?? 0);
        if (!$key) {
            return false;
        }
        if (time() - $last > self::IDLE_SECONDS) {
            $this->lock();
            return false;
        }
        $master = base64_decode($key, true);
        if ($master === false || strlen($master) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            $this->lock();
            return false;
        }
        $this->deriveSubkeys($master);
        sodium_memzero($master);
        $_SESSION['vault_last'] = time();
        return true;
    }

    public function lockedUntil(): ?int {
        $row = $this->db->query('SELECT locked_until FROM vault WHERE id = 1')->fetch();
        if (!$row || !$row['locked_until']) {
            return null;
        }
        return (int) $row['locked_until'] > time() ? (int) $row['locked_until'] : null;
    }

    // ── Crear / desbloquear / bloquear / destruir ────────────────────────────

    /**
     * Crea la bóveda. Lanza si ya existe o si la contraseña es demasiado corta.
     */
    public function create(string $master): void {
        if ($this->exists()) {
            throw new RuntimeException('La bóveda ya existe.');
        }
        if (mb_strlen($master) < self::MIN_MASTER_LEN) {
            throw new RuntimeException('La contraseña maestra debe tener al menos ' . self::MIN_MASTER_LEN . ' caracteres.');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key  = $this->deriveMaster($master, $salt);
        $this->deriveSubkeys($key);

        $verifier = $this->encrypt(self::VERIFIER_TEXT);

        $stmt = $this->db->prepare('INSERT INTO vault (id, salt, verifier, created_at) VALUES (1, :salt, :verifier, :now)');
        $stmt->bindValue(':salt', $salt, PDO::PARAM_LOB);
        $stmt->bindValue(':verifier', $verifier, PDO::PARAM_LOB);
        $stmt->bindValue(':now', time(), PDO::PARAM_INT);
        $stmt->execute();

        $this->storeInSession($key);
        sodium_memzero($key);
    }

    /**
     * Desbloquea con la contraseña maestra. Devuelve false si es incorrecta.
     * Lanza RuntimeException si la bóveda no existe o está bloqueada por intentos fallidos.
     */
    public function unlock(string $master): bool {
        $row = $this->db->query('SELECT salt, verifier, failed_attempts, locked_until FROM vault WHERE id = 1')->fetch();
        if (!$row) {
            throw new RuntimeException('La bóveda no existe.');
        }
        if ($row['locked_until'] && (int) $row['locked_until'] > time()) {
            $secs = (int) $row['locked_until'] - time();
            throw new RuntimeException("Demasiados intentos fallidos. Espera {$secs} s.");
        }

        $key = $this->deriveMaster($master, (string) $row['salt']);
        $this->deriveSubkeys($key);

        if ($this->decrypt((string) $row['verifier']) !== self::VERIFIER_TEXT) {
            $this->encKey = $this->macKey = null;
            sodium_memzero($key);
            $this->registerFailure((int) $row['failed_attempts']);
            return false;
        }

        $this->db->exec('UPDATE vault SET failed_attempts = 0, locked_until = NULL WHERE id = 1');
        $this->storeInSession($key);
        sodium_memzero($key);
        return true;
    }

    public function lock(): void {
        $this->encKey = $this->macKey = null;
        unset($_SESSION['vault_key'], $_SESSION['vault_last']);
    }

    /**
     * Borra todas las contraseñas y la bóveda. Exige la contraseña maestra.
     */
    public function destroy(string $master): void {
        if (!$this->unlock($master)) {
            throw new RuntimeException('Contraseña maestra incorrecta.');
        }
        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM passwords');
        $this->db->exec('DELETE FROM vault');
        $this->db->commit();
        $this->lock();
    }

    // ── Cifrado ──────────────────────────────────────────────────────────────

    /** nonce(24) || ciphertext */
    public function encrypt(string $plain): string {
        $this->assertUnlocked();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->encKey);
    }

    /** Devuelve null si el ciphertext no abre con la clave actual. */
    public function decrypt(?string $blob): ?string {
        $this->assertUnlocked();
        if ($blob === null || strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce  = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->encKey);
        return $plain === false ? null : $plain;
    }

    /** Hash con clave (hex) para comparar valores sin descifrarlos. */
    public function hash(string $value): string {
        $this->assertUnlocked();
        return bin2hex(sodium_crypto_generichash($value, $this->macKey));
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private function deriveMaster(string $master, string $salt): string {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_KDF_KEYBYTES,
            $master,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    private function deriveSubkeys(string $master): void {
        $this->encKey = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KDF_ID_ENC, self::KDF_CONTEXT, $master);
        $this->macKey = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_GENERICHASH_KEYBYTES, self::KDF_ID_MAC, self::KDF_CONTEXT, $master);
    }

    private function storeInSession(string $master): void {
        // Solo si hay sesión: la clase también se usa desde CLI y scripts de prueba
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['vault_key']  = base64_encode($master);
        $_SESSION['vault_last'] = time();
    }

    private function registerFailure(int $previous): void {
        $attempts = $previous + 1;
        $until    = $attempts >= self::MAX_ATTEMPTS ? time() + self::LOCK_SECONDS : null;
        if ($until !== null) {
            $attempts = 0; // el contador arranca de cero cuando expire el bloqueo
        }
        $stmt = $this->db->prepare('UPDATE vault SET failed_attempts = :a, locked_until = :u WHERE id = 1');
        $stmt->execute([':a' => $attempts, ':u' => $until]);
    }

    private function assertUnlocked(): void {
        if ($this->encKey === null || $this->macKey === null) {
            throw new RuntimeException('La bóveda está bloqueada.');
        }
    }
}
