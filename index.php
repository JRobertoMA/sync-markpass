<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sync Bookmarks</title>
    <link rel="stylesheet" href="assets/style.css?v=13">
</head>
<body>

<header>
    <span class="logo">🔖</span>
    <h1>Sync Bookmarks</h1>
    <nav class="header-nav">
        <a href="passwords.php">🔐 Contraseñas</a>
    </nav>
</header>

<div class="container">

    <!-- Stats bar -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="number" id="stat-total">—</div>
            <div class="label">Total marcadores</div>
        </div>
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
            <div class="label">En ambos</div>
        </div>
    </div>

    <!-- Import -->
    <div class="section">
        <h2>Importar marcadores</h2>
        <div class="import-grid">

            <div class="drop-zone chrome" id="drop-chrome" role="button" tabindex="0" aria-label="Importar marcadores de Google Chrome">
                <input type="file" id="file-chrome" accept=".html,.htm">
                <div class="icon">🌐</div>
                <div class="name">Google Chrome</div>
                <div class="hint">Arrastra o haz clic · Formato .html</div>
                <div class="file-info" id="info-chrome"></div>
            </div>

            <div class="drop-zone firefox" id="drop-firefox" role="button" tabindex="0" aria-label="Importar marcadores de Mozilla Firefox">
                <input type="file" id="file-firefox" accept=".html,.htm,.json">
                <div class="icon">🦊</div>
                <div class="name">Mozilla Firefox</div>
                <div class="hint">Arrastra o haz clic · Formato .html o .json</div>
                <div class="file-info" id="info-firefox"></div>
            </div>

        </div>

        <label class="import-option">
            <input type="checkbox" id="preserve-categorized">
            <span>Preservar carpetas ya categorizadas al importar</span>
        </label>
    </div>

    <!-- Diff table -->
    <div class="section">
        <h2>Comparación de marcadores</h2>

        <input type="text" class="search-bar" id="search-input" placeholder="Buscar por título o URL…">

        <div class="tabs" role="tablist" aria-label="Filtrar marcadores">
            <button class="tab-btn active" data-tab="all" role="tab" aria-selected="false">
                Todos
                <span class="tab-badge">0</span>
            </button>
            <button class="tab-btn" data-tab="only_chrome" role="tab" aria-selected="false">
                Solo Chrome
                <span class="tab-badge">0</span>
            </button>
            <button class="tab-btn firefox" data-tab="only_firefox" role="tab" aria-selected="false">
                Solo Firefox
                <span class="tab-badge">0</span>
            </button>
            <button class="tab-btn both" data-tab="in_both" role="tab" aria-selected="false">
                En ambos
                <span class="tab-badge">0</span>
            </button>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Carpeta</th>
                        <th>Fuente</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody id="bm-tbody">
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <div class="icon">📂</div>
                                <p>Importa los marcadores de ambos navegadores para ver la comparación</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="pagination"></div>
    </div>

    <!-- Link check -->
    <div class="section">
        <h2>Comprobar enlaces</h2>
        <p class="section-desc">
            Pide cada URL para ver si sigue viva. Un <strong>403</strong> o un tiempo de espera agotado suelen
            significar que el sitio bloquea programas, no que el enlace esté roto: eso queda como «dudoso» y se
            revisa a mano. Solo se propone borrar lo que está claramente muerto.
        </p>

        <div class="stats-bar link-stats">
            <div class="stat-card both">
                <div class="number" id="link-alive">—</div>
                <div class="label">Vivos</div>
                <div class="stat-note" id="link-alive-note"></div>
            </div>
            <div class="stat-card firefox">
                <div class="number" id="link-dubious">—</div>
                <div class="label">Dudosos</div>
            </div>
            <div class="stat-card conflict">
                <div class="number" id="link-dead">—</div>
                <div class="label">Muertos</div>
            </div>
            <div class="stat-card">
                <div class="number" id="link-unchecked">—</div>
                <div class="label">Sin comprobar</div>
            </div>
        </div>

        <p class="section-desc" id="link-last-check"></p>

        <div class="link-actions">
            <button class="btn-primary" id="btn-check-stale">Comprobar los que tocan</button>
            <button class="btn-secondary" id="btn-check-all">Comprobar todos</button>
            <button class="btn-secondary" id="btn-check-failed">Reintentar los fallidos</button>
        </div>

        <div class="job-estimate" id="link-estimate" style="display:none">
            <div class="job-estimate-text" id="link-estimate-text"></div>
            <div class="job-estimate-actions">
                <button class="btn-secondary btn-sm" id="link-estimate-cancel">Cancelar</button>
                <button class="btn-primary btn-sm" id="link-estimate-go">Empezar</button>
            </div>
        </div>

        <div class="job-progress" id="link-progress" style="display:none" role="status" aria-live="polite">
            <div class="job-progress-head">
                <span class="jp-label">Procesando…</span>
                <span class="job-progress-time jp-time"></span>
            </div>
            <div class="job-bar" role="progressbar"><div class="job-bar-fill jp-bar"></div></div>
            <div class="job-progress-foot">
                <span class="jp-detail"></span>
                <span class="job-progress-actions">
                    <button class="btn-sm jp-cancel">Cancelar</button>
                    <button class="btn-sm jp-dismiss" style="display:none">Descartar</button>
                </span>
            </div>
        </div>

        <div class="tabs" role="tablist" aria-label="Filtrar enlaces">
            <button class="tab-btn conflict active" data-link-tab="dead" role="tab" aria-selected="true">
                Muertos <span class="tab-badge">0</span>
            </button>
            <button class="tab-btn firefox" data-link-tab="dubious" role="tab" aria-selected="false">
                Dudosos <span class="tab-badge">0</span>
            </button>
            <button class="tab-btn" data-link-tab="redirect" role="tab" aria-selected="false">
                Redirigen <span class="tab-badge">0</span>
            </button>
            <button class="tab-btn both" data-link-tab="manual" role="tab" aria-selected="false">
                Verificados a mano <span class="tab-badge">0</span>
            </button>
        </div>

        <div class="link-bulk" id="link-bulk" style="display:none">
            <label class="import-option" style="margin:0">
                <input type="checkbox" id="link-select-all">
                <span>Seleccionar todo lo de esta página</span>
            </label>
            <span class="link-bulk-count" id="link-bulk-count"></span>
            <button class="btn-sm" id="btn-bulk-alive">✓ Marcar como vivos</button>
            <button class="btn-sm danger" id="btn-bulk-delete">Mover a la papelera</button>
            <button class="btn-sm" id="btn-bulk-unmark" style="display:none">Quitar marca</button>
            <button class="btn-sm" id="btn-bulk-adopt" style="display:none">Adoptar destino</button>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:28px"></th>
                        <th>Enlace</th>
                        <th>Resultado</th>
                        <th>Comprobado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="link-tbody">
                    <tr><td colspan="5">
                        <div class="empty-state">
                            <div class="icon">🔗</div>
                            <p>Todavía no has comprobado los enlaces</p>
                        </div>
                    </td></tr>
                </tbody>
            </table>
        </div>

        <div id="link-pagination"></div>

        <div class="api-key-notice" style="margin-top:16px">
            ⚠️ Borrar aquí <strong>no borra el marcador del navegador</strong>: esta base es una copia.
            Para limpiarlo de verdad, borra aquí, exporta desde «Exportar marcadores» y reimporta ese
            archivo en Chrome o Firefox.
        </div>
    </div>

    <!-- Categorize -->
    <div class="section">
        <h2>Categorizar con IA</h2>
        <p class="section-desc">Asigna carpetas automáticamente usando heurística de dominios y Claude AI.</p>

        <div class="categorize-controls">
            <div class="control-group">
                <label class="control-label">Motor</label>
                <div class="radio-group">
                    <label class="radio-opt">
                        <input type="radio" name="engine" value="both" checked>
                        <span>Heurística + Claude <span class="badge-ai">recomendado</span></span>
                    </label>
                    <label class="radio-opt">
                        <input type="radio" name="engine" value="heuristic">
                        <span>Solo Heurística <span class="badge-free">gratis</span></span>
                    </label>
                    <label class="radio-opt">
                        <input type="radio" name="engine" value="claude">
                        <span>Solo Claude AI</span>
                    </label>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label">Aplicar a</label>
                <select id="cat-source" class="select-input">
                    <option value="all">Todos los marcadores</option>
                    <option value="chrome">Solo Chrome</option>
                    <option value="firefox">Solo Firefox</option>
                </select>
            </div>

            <div class="control-group">
                <label class="control-label">Máximo</label>
                <select id="cat-max" class="select-input">
                    <option value="0">Todos los pendientes</option>
                    <option value="300" selected>300 marcadores</option>
                    <option value="500">500 marcadores</option>
                    <option value="1000">1000 marcadores</option>
                </select>
            </div>

            <div id="api-key-notice" class="api-key-notice" style="display:none">
                Para usar Claude AI necesitas configurar <code>ANTHROPIC_API_KEY</code>
                en el archivo <code>.env</code> del proyecto (ver <code>.env.example</code>).
            </div>

            <button class="btn-primary" id="btn-categorize">
                Analizar y sugerir carpetas
            </button>
        </div>

        <!-- Estimación previa -->
        <div class="job-estimate" id="cat-estimate" style="display:none">
            <div class="job-estimate-text" id="cat-estimate-text"></div>
            <div class="job-estimate-actions">
                <button class="btn-secondary btn-sm" id="cat-estimate-cancel">Cancelar</button>
                <button class="btn-primary btn-sm" id="cat-estimate-go">Empezar</button>
            </div>
        </div>

        <!-- Progreso del job en segundo plano -->
        <div class="job-progress" id="cat-progress" style="display:none" role="status" aria-live="polite">
            <div class="job-progress-head">
                <span class="jp-label">Procesando…</span>
                <span class="job-progress-time jp-time"></span>
            </div>
            <div class="job-bar" role="progressbar"><div class="job-bar-fill jp-bar"></div></div>
            <div class="job-progress-foot">
                <span class="jp-detail"></span>
                <span class="job-progress-actions">
                    <button class="btn-sm jp-cancel">Cancelar</button>
                    <button class="btn-primary btn-sm jp-open" style="display:none">Ver sugerencias</button>
                    <button class="btn-sm jp-dismiss" style="display:none">Descartar</button>
                </span>
            </div>
        </div>
    </div>

    <!-- Export -->
    <div class="section">
        <h2>Exportar marcadores</h2>
        <p class="section-desc">Elige el formato que importa tu navegador y qué marcadores incluir.</p>

        <div class="export-builder">
            <div class="control-group">
                <label class="control-label" for="export-format">Formato</label>
                <select id="export-format" class="select-input">
                    <option value="chrome-html">Chrome · HTML</option>
                    <option value="firefox-html">Firefox · HTML</option>
                    <option value="firefox-json">Firefox · JSON</option>
                    <option value="csv">CSV (hoja de cálculo)</option>
                    <option value="mariadb-sql">MariaDB · SQL</option>
                </select>
            </div>

            <div class="control-group">
                <label class="control-label" for="export-source">Incluir</label>
                <select id="export-source" class="select-input">
                    <option value="merged">Combinado (sin duplicados)</option>
                    <option value="chrome">Solo los de Chrome</option>
                    <option value="firefox">Solo los de Firefox</option>
                </select>
            </div>

            <button class="btn-primary" id="btn-export">Descargar</button>
        </div>

        <p class="section-desc" id="export-hint" style="margin-top:12px"></p>

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <h3 class="sub-heading">Copia de seguridad</h3>
        <p class="section-desc">
            La base SQLite completa: marcadores, carpetas, historial y la bóveda de contraseñas cifrada.
        </p>
        <button class="export-btn" id="btn-backup">
            <span class="icon">💾</span>
            Descargar copia SQLite
        </button>
    </div>

    <!-- Maintenance -->
    <div class="section">
        <h2>Mantenimiento</h2>
        <p style="color:var(--text-muted);margin-bottom:1rem;font-size:.9rem">
            Fusiona marcadores con la misma URL que aparecen tanto en Chrome como en Firefox.
            Los categorizados tienen prioridad y no se pierden.
        </p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
            <button class="btn-primary" id="btn-deduplicate">
                Eliminar duplicados entre Chrome y Firefox
            </button>
            <span id="dedup-result" style="font-size:.9rem;color:var(--text-muted)"></span>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <p style="color:var(--text-muted);margin-bottom:1rem;font-size:.9rem">
            Analiza qué carpeta fue asignada a cada dominio e identifica inconsistencias.
            Permite reasignar en bulk todos los marcadores de un dominio a una sola carpeta.
        </p>
        <button class="btn-primary" id="btn-analyze">
            Analizar y homologar dominios
        </button>

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <h3 class="sub-heading">Papelera <span class="trash-count" id="trash-count">0</span></h3>
        <p class="section-desc">
            Los marcadores borrados se guardan aquí <span id="trash-keep-days">30</span> días por si el
            comprobador se equivocó. Al restaurarlos vuelven con su carpeta y su estado.
        </p>
        <div id="trash-list" class="history-list">
            <div class="history-empty">La papelera está vacía.</div>
        </div>
        <div class="link-actions" id="trash-actions" style="display:none;margin-top:12px">
            <button class="btn-danger" id="btn-trash-empty">Vaciar papelera</button>
        </div>

        <h3 class="sub-heading">Últimos cambios aplicados</h3>
        <p class="section-desc">
            Cada categorización u homologación guarda las carpetas anteriores. Solo se puede deshacer
            el cambio más reciente; se conservan los últimos 20 o 30 días.
        </p>
        <div id="history-list" class="history-list">
            <div class="history-empty">Todavía no has aplicado ningún cambio masivo.</div>
        </div>
    </div>

</div><!-- /container -->

<div id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

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

<!-- Categorization Modal -->
<div id="cat-modal" class="modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-backdrop" id="cat-backdrop"></div>
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modal-title">Sugerencias de categorización</h3>
            <button class="modal-close" id="modal-close" aria-label="Cerrar">✕</button>
        </div>

        <div class="modal-toolbar">
            <span id="modal-summary"></span>
            <div class="modal-actions">
                <button class="btn-sm" id="btn-select-all">Seleccionar todo</button>
                <button class="btn-sm" id="btn-deselect-all">Deseleccionar</button>
            </div>
        </div>

        <div class="modal-filter">
            <input type="text" class="search-bar" id="modal-search" placeholder="Filtrar sugerencias…" style="margin:0">
            <select id="modal-engine-filter" class="select-input" style="width:auto;min-width:140px">
                <option value="">Todos los motores</option>
                <option value="heuristic">Heurística</option>
                <option value="claude">Claude AI</option>
            </select>
        </div>

        <div class="modal-body" id="modal-body">
            <!-- Rows injected by JS -->
        </div>

        <div class="modal-footer">
            <button class="btn-secondary" id="btn-cancel-modal">Cancelar</button>
            <button class="btn-primary" id="btn-apply-changes">
                Aplicar cambios seleccionados
            </button>
        </div>
    </div>
</div>

<!-- Domain Homologation Modal -->
<div id="homolog-modal" class="modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="homolog-title">
    <div class="modal-backdrop" id="homolog-backdrop"></div>
    <div class="modal-box" style="max-width:900px">
        <div class="modal-header">
            <h3 id="homolog-title">Homologar dominios</h3>
            <button class="modal-close" id="homolog-close" aria-label="Cerrar">✕</button>
        </div>

        <div class="modal-toolbar">
            <span id="homolog-summary"></span>
            <div class="modal-actions">
                <button class="btn-sm" id="homolog-select-inconsistent">Seleccionar inconsistentes</button>
                <button class="btn-sm" id="homolog-deselect-all">Deseleccionar</button>
            </div>
        </div>

        <div class="modal-filter">
            <input type="text" class="search-bar" id="homolog-search" placeholder="Filtrar por dominio…" style="margin:0">
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--text-muted);white-space:nowrap">
                <input type="checkbox" id="homolog-only-inconsistent"> Solo inconsistentes
            </label>
        </div>

        <div class="modal-body" id="homolog-body">
            <!-- Rows injected by JS -->
        </div>

        <div class="modal-footer">
            <button class="btn-secondary" id="homolog-cancel">Cancelar</button>
            <button class="btn-primary" id="homolog-apply">
                Aplicar seleccionados
            </button>
        </div>
    </div>
</div>

<script src="assets/app.js?v=13"></script>
</body>
</html>
