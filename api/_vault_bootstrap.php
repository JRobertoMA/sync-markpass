<?php

declare(strict_types=1);

/**
 * Arranque común de los endpoints de contraseñas: sesión de la bóveda, cabeceras JSON
 * y helpers para exigir bóveda desbloqueada y token CSRF.
 */

require_once __DIR__ . '/../src/vault/Vault.php';

Vault::startSession();

header('Cache-Control: no-store');

/** Emite JSON y termina. */
function vault_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Cuerpo JSON de la petición (o $_POST si no es JSON). */
function vault_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/** Devuelve la bóveda desbloqueada o responde 401 {"error":"locked"}. */
function vault_require_unlocked(): Vault {
    $vault = new Vault();
    if (!$vault->isUnlocked()) {
        vault_json(['error' => 'locked', 'message' => 'La bóveda está bloqueada.'], 401);
    }
    return $vault;
}

/** Exige cabecera X-CSRF-Token válida en peticiones que modifican estado. */
function vault_require_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!Vault::checkCsrf($token)) {
        vault_json(['error' => 'csrf', 'message' => 'Token CSRF inválido. Recarga la página.'], 403);
    }
}
