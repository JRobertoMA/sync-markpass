<?php

declare(strict_types=1);

require_once __DIR__ . '/_vault_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET /api/vault.php?status ────────────────────────────────────────────────
if ($method === 'GET') {
    $vault = new Vault();
    vault_json([
        'exists'       => $vault->exists(),
        'unlocked'     => $vault->exists() && $vault->isUnlocked(),
        'locked_until' => $vault->exists() ? $vault->lockedUntil() : null,
        'idle_seconds' => Vault::IDLE_SECONDS,
    ]);
}

if ($method !== 'POST') {
    vault_json(['error' => 'Method not allowed'], 405);
}

vault_require_csrf();

$body   = vault_body();
$action = $body['action'] ?? '';
$master = (string) ($body['master'] ?? '');
$vault  = new Vault();

try {
    switch ($action) {
        case 'create':
            $vault->create($master);
            vault_json(['success' => true]);

        case 'unlock':
            if ($master === '') {
                vault_json(['error' => 'Introduce la contraseña maestra.'], 422);
            }
            if (!$vault->unlock($master)) {
                vault_json(['error' => 'Contraseña maestra incorrecta.', 'locked_until' => $vault->lockedUntil()], 401);
            }
            vault_json(['success' => true]);

        case 'lock':
            $vault->lock();
            vault_json(['success' => true]);

        case 'destroy':
            $vault->destroy($master);
            vault_json(['success' => true]);

        default:
            vault_json(['error' => 'Acción no válida'], 400);
    }
} catch (RuntimeException $e) {
    vault_json(['error' => $e->getMessage(), 'locked_until' => $vault->exists() ? $vault->lockedUntil() : null], 422);
} catch (Exception $e) {
    vault_json(['error' => $e->getMessage()], 500);
}
