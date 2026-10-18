<?php

declare(strict_types=1);

require_once __DIR__ . '/_vault_bootstrap.php';
require_once __DIR__ . '/../src/sync/PasswordMerger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vault_json(['error' => 'Method not allowed'], 405);
}

$vault = vault_require_unlocked();
vault_require_csrf();

$body   = vault_body();
$action = $body['action'] ?? '';
$merger = new PasswordMerger($vault);

try {
    switch ($action) {
        // ── reveal: devuelve usuario/contraseña/nota descifrados de una fila ──
        case 'reveal':
            $id  = (int) ($body['id'] ?? 0);
            $row = $id > 0 ? $merger->reveal($id) : null;
            if ($row === null) {
                vault_json(['error' => 'No existe.'], 404);
            }
            vault_json($row);

        // ── prefer: marca la fila ganadora de un conflicto ──
        case 'prefer':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0 || !$merger->resolveConflict($id)) {
                vault_json(['error' => 'No existe.'], 404);
            }
            vault_json(['success' => true, 'stats' => $merger->getStats()]);

        // ── delete: borra filas (deduplicación) ──
        case 'delete':
            $ids = $body['ids'] ?? [];
            if (!is_array($ids) || $ids === []) {
                vault_json(['error' => 'No se indicaron filas.'], 422);
            }
            $deleted = $merger->deleteRows($ids);
            vault_json(['success' => true, 'deleted' => $deleted, 'stats' => $merger->getStats()]);

        default:
            vault_json(['error' => 'Acción no válida'], 400);
    }
} catch (Exception $e) {
    vault_json(['error' => $e->getMessage()], 500);
}
