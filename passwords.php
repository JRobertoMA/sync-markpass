<?php
require_once __DIR__ . '/src/vault/Vault.php';
Vault::startSession();
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(Vault::csrfToken(), ENT_QUOTES) ?>">
    <title>Sync Bookmarks · Contraseñas</title>
    <link rel="stylesheet" href="assets/style.css?v=13">
</head>
<body>

<header>
    <span class="logo">🔐</span>
    <h1>Sync Bookmarks · Contraseñas</h1>
    <nav class="header-nav">
        <a href="index.php">🔖 Marcadores</a>
        <button class="btn-sm" id="btn-lock" style="display:none">Bloquear bóveda</button>
    </nav>
</header>

<!-- ===== Pantalla de bloqueo ===== -->
<div class="container" id="vault-gate">
    <div class="section gate-box">
        <div class="gate-icon">🔐</div>

        <div id="gate-loading">Comprobando bóveda…</div>

        <form id="gate-create" style="display:none" autocomplete="off">
            <h2>Crear contraseña maestra</h2>
            <p class="section-desc">
                Las contraseñas importadas se guardan cifradas con una clave derivada de esta contraseña maestra.
                <strong>No hay forma de recuperarla</strong>: si la olvidas, habrá que destruir la bóveda y volver a importar.
            </p>
            <input type="password" class="search-bar" id="create-master" placeholder="Contraseña maestra (mín. 12 caracteres)" minlength="12" required>
            <input type="password" class="search-bar" id="create-master2" placeholder="Repite la contraseña maestra" minlength="12" required>
            <button class="btn-primary" type="submit">Crear bóveda</button>
        </form>

        <form id="gate-unlock" style="display:none" autocomplete="off">
            <h2>Desbloquear bóveda</h2>
            <p class="section-desc">Se bloquea sola tras <span id="idle-min">15</span> min sin actividad.</p>
            <input type="password" class="search-bar" id="unlock-master" placeholder="Contraseña maestra" required autofocus>
            <button class="btn-primary" type="submit">Desbloquear</button>
            <p class="gate-error" id="unlock-error"></p>
        </form>
    </div>
</div>

<!-- ===== App (solo visible con la bóveda abierta) ===== -->
<div class="container" id="vault-app" style="display:none">

    <div class="stats-bar">
        <div class="stat-card chrome">
            <div class="number" id="stat-only-chrome">—</div>
            <div class="label">Solo en Chrome</div>
        </div>
        <div class="stat-card firefox">
            <div class="number" id="stat-only-firefox">—</div>
            <div class="label">Solo en Firefox</div>
        </div>
        <div class="stat-card both">
            <div class="number" id="stat-both">—</div>
            <div class="label">En ambos (iguales)</div>
        </div>
        <div class="stat-card conflict">
            <div class="number" id="stat-conflicts">—</div>
            <div class="label">Conflictos</div>
        </div>
    </div>

    <!-- Import -->
    <div class="section">
        <h2>Importar contraseñas</h2>
        <p class="section-desc">
            Chrome: <code>chrome://password-manager/settings</code> → Exportar contraseñas.
            Firefox: <code>about:logins</code> → ⋯ → Exportar inicios de sesión.
        </p>
        <div class="import-grid">
            <div class="drop-zone chrome" id="drop-chrome" role="button" tabindex="0" aria-label="Importar contraseñas de Google Chrome">
                <input type="file" id="file-chrome" accept=".csv">
                <div class="icon">🌐</div>
                <div class="name">Google Chrome</div>
                <div class="hint">Arrastra o haz clic · Formato .csv</div>
                <div class="file-info" id="info-chrome" role="status"></div>
            </div>
            <div class="drop-zone firefox" id="drop-firefox" role="button" tabindex="0" aria-label="Importar contraseñas de Mozilla Firefox">
                <input type="file" id="file-firefox" accept=".csv">
                <div class="icon">🦊</div>
                <div class="name">Mozilla Firefox</div>
                <div class="hint">Arrastra o haz clic · Formato .csv</div>
                <div class="file-info" id="info-firefox" role="status"></div>
            </div>
        </div>
        <div class="api-key-notice">
            ⚠️ El CSV que exporta el navegador está en <strong>texto plano</strong>. Bórralo de tu disco cuando
            termines de importarlo. Aquí solo se guarda cifrado.
        </div>
    </div>

    <!-- Diff -->
    <div class="section">
        <h2>Comparación de contraseñas</h2>
        <p class="section-desc">
            Se comparan por sitio + usuario. La búsqueda solo ve el sitio y el nombre: usuario y contraseña
            están cifrados. En los conflictos, si no eliges ganador, se usa la fecha de cambio de contraseña
            (Chrome no la exporta, así que por defecto gana Firefox).
        </p>

        <input type="text" class="search-bar" id="search-input" placeholder="Buscar por sitio o nombre…">

        <div class="tabs" role="tablist" aria-label="Filtrar contraseñas">
            <button class="tab-btn active" data-tab="all" role="tab" aria-selected="false">Sin conflicto <span class="tab-badge">0</span></button>
            <button class="tab-btn" data-tab="only_chrome" role="tab" aria-selected="false">Solo Chrome <span class="tab-badge">0</span></button>
            <button class="tab-btn firefox" data-tab="only_firefox" role="tab" aria-selected="false">Solo Firefox <span class="tab-badge">0</span></button>
            <button class="tab-btn both" data-tab="in_both" role="tab" aria-selected="false">En ambos <span class="tab-badge">0</span></button>
            <button class="tab-btn conflict" data-tab="conflicts" role="tab" aria-selected="false">Conflictos <span class="tab-badge">0</span></button>
        </div>

        <div class="tab-note" id="conflicts-note" style="display:none" role="status"></div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sitio</th>
                        <th>Usuario</th>
                        <th>Contraseña</th>
                        <th>Fuente</th>
                        <th>Cambiada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="pw-tbody">
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <div class="icon">🔐</div>
                            <p>Importa las contraseñas de ambos navegadores para ver la comparación</p>
                        </div>
                    </td></tr>
                </tbody>
            </table>
        </div>

        <div id="pagination"></div>
    </div>

    <!-- Export -->
    <div class="section">
        <h2>Exportar</h2>
        <p class="section-desc">
            Los CSV llevan las columnas exactas que importa cada navegador. Las credenciales de apps Android
            (<code>android://</code>) solo van al CSV de Chrome.
        </p>
        <div class="export-grid">
            <a class="export-btn" href="api/passwords_export.php?format=chrome-csv&scope=missing_in_chrome" data-export>
                <span class="icon">🌐</span> Lo que falta en Chrome <span class="export-count" data-count="chrome-csv:missing_in_chrome"></span>
            </a>
            <a class="export-btn" href="api/passwords_export.php?format=firefox-csv&scope=missing_in_firefox" data-export>
                <span class="icon">🦊</span> Lo que falta en Firefox <span class="export-count" data-count="firefox-csv:missing_in_firefox"></span>
            </a>
            <a class="export-btn" href="api/passwords_export.php?format=chrome-csv&scope=merged" data-export>
                <span class="icon">📦</span> Todo → CSV Chrome <span class="export-count" data-count="chrome-csv:merged"></span>
            </a>
            <a class="export-btn" href="api/passwords_export.php?format=firefox-csv&scope=merged" data-export>
                <span class="icon">📦</span> Todo → CSV Firefox <span class="export-count" data-count="firefox-csv:merged"></span>
            </a>
        </div>
        <p class="section-desc" style="margin-top:12px">
            Importar en Chrome: <code>chrome://password-manager/settings</code> → Importar.
            En Firefox: <code>about:logins</code> → ⋯ → Importar desde un archivo.
        </p>
    </div>

    <!-- Maintenance -->
    <div class="section">
        <h2>Mantenimiento</h2>
        <p class="section-desc">
            Busca entradas repetidas dentro del mismo navegador (http/https, con y sin www, puerto) para el mismo
            sitio y usuario, y elige cuál conservar.
        </p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
            <button class="btn-primary" id="btn-dedup">Buscar duplicados</button>
            <span id="dedup-result" style="font-size:.9rem;color:var(--text-muted)"></span>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <p class="section-desc">
            <strong>Zona de peligro.</strong> Destruir la bóveda borra todas las contraseñas importadas y la
            contraseña maestra. Los marcadores no se tocan.
        </p>
        <button class="btn-danger" id="btn-destroy">Destruir bóveda</button>
    </div>

</div><!-- /vault-app -->

<div id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

<!-- Dedup modal -->
<div id="dedup-modal" class="modal" style="display:none" role="dialog" aria-modal="true" aria-label="Duplicados dentro del mismo navegador">
    <div class="modal-backdrop" data-close="dedup-modal"></div>
    <div class="modal-box">
        <div class="modal-header">
            <h3>Duplicados dentro del mismo navegador</h3>
            <button class="modal-close" data-close="dedup-modal" aria-label="Cerrar">✕</button>
        </div>
        <div class="modal-toolbar">
            <span id="dedup-summary"></span>
            <span class="dedup-legend">
                En cada grupo, la fila marcada se <strong>conserva</strong>; las tachadas se borran.
                Por defecto se sugiere la más segura (https, sin www) y más reciente.
            </span>
        </div>
        <div class="modal-body" id="dedup-body"></div>
        <div class="modal-footer">
            <span class="dedup-legend" id="dedup-fate-summary" style="margin-right:auto"></span>
            <button class="btn-secondary" data-close="dedup-modal">Cancelar</button>
            <button class="btn-primary" id="dedup-apply">Borrar duplicados</button>
        </div>
    </div>
</div>

<!-- Modal de confirmación reutilizable -->
<div id="confirm-modal" class="modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
    <div class="modal-backdrop" data-confirm-close></div>
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header">
            <h3 id="confirm-title">¿Continuar?</h3>
            <button class="modal-close" data-confirm-close aria-label="Cerrar">✕</button>
        </div>
        <div class="modal-body" style="padding:20px 24px">
            <p id="confirm-body" class="section-desc" style="margin:0"></p>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" data-confirm-close>Cancelar</button>
            <button class="btn-primary" id="confirm-ok">Continuar</button>
        </div>
    </div>
</div>

<!-- Aviso de sesión a punto de expirar -->
<div id="expiry-modal" class="modal" style="display:none" role="dialog" aria-modal="true" aria-label="La bóveda va a bloquearse">
    <div class="modal-backdrop"></div>
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <h3>La bóveda va a bloquearse</h3>
        </div>
        <div class="modal-body" style="padding:20px 24px">
            <p class="section-desc" style="margin:0">
                Por inactividad se cerrará en <strong id="expiry-countdown">2:00</strong>.
                Si estás a mitad de algo, sigue aquí para no perder la selección.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" id="expiry-lock">Bloquear ya</button>
            <button class="btn-primary" id="expiry-stay">Seguir aquí</button>
        </div>
    </div>
</div>

<!-- Destroy modal -->
<div id="destroy-modal" class="modal" style="display:none" role="dialog" aria-modal="true" aria-label="Destruir bóveda">
    <div class="modal-backdrop" data-close="destroy-modal"></div>
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <h3>Destruir bóveda</h3>
            <button class="modal-close" data-close="destroy-modal" aria-label="Cerrar">✕</button>
        </div>
        <form id="destroy-form" autocomplete="off">
            <div class="modal-body" style="padding:20px 24px">
                <p class="section-desc" style="margin-top:0">
                    Se borrarán <strong>todas</strong> las contraseñas importadas. Escribe la contraseña maestra para confirmar.
                </p>
                <input type="password" class="search-bar" id="destroy-master" placeholder="Contraseña maestra" required style="margin:0">
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" type="button" data-close="destroy-modal">Cancelar</button>
                <button class="btn-danger" type="submit">Destruir</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/passwords.js?v=13"></script>
</body>
</html>
