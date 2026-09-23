/* =====================================================
   Bookmark Sync — Frontend
   ===================================================== */

const state = {
    tab: 'all',
    page: 1,
    search: '',
    counts: { all: 0, only_chrome: 0, only_firefox: 0, in_both: 0, total_chrome: 0, total_firefox: 0 },
    linkTab: 'dead',    // pestaña de «Comprobar enlaces»
    linkPage: 1,
    linkScope: 'stale', // ámbito elegido en la estimación pendiente de confirmar
    job: null,          // job en curso (categorización o comprobación de enlaces)
    jobTimer: null,
    jobStart: null,
};

/* ---------- Toast ---------- */
function toast(msg, type = 'success', action = null, ms = 3500) {
    const el = document.getElementById('toast');
    el.textContent = '';
    el.appendChild(document.createTextNode(msg));

    if (action) {
        const btn = document.createElement('button');
        btn.className = 'toast-undo';
        btn.textContent = action.label;
        btn.addEventListener('click', () => {
            el.className = '';
            clearTimeout(el._t);
            action.onClick();
        });
        el.appendChild(btn);
    }

    el.className = 'show ' + type;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className = '', ms);
}


/* ---------- Accesibilidad: foco en modales ---------- */
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea, [tabindex]:not([tabindex="-1"])';

/**
 * Atrapa el Tab dentro del modal y devuelve el foco al elemento previo al cerrarlo.
 * Devuelve una función para soltar el foco.
 */
function trapFocus(modal) {
    const previous = document.activeElement;

    const onKey = e => {
        if (e.key !== 'Tab') return;
        const items = [...modal.querySelectorAll(FOCUSABLE)].filter(el => el.offsetParent !== null);
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first)      { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };

    modal.addEventListener('keydown', onKey);
    setTimeout(() => modal.querySelector(FOCUSABLE)?.focus(), 30);

    return () => {
        modal.removeEventListener('keydown', onKey);
        if (previous && document.contains(previous)) previous.focus();
    };
}

/** Marca aria-selected en el grupo de pestañas. */
function syncTabAria() {
    document.querySelectorAll('.tab-btn').forEach(b =>
        b.setAttribute('aria-selected', b.classList.contains('active') ? 'true' : 'false'));
}

/** Las drop-zone son div[role=button]: Enter y Espacio deben abrir el selector de archivo. */
function enableDropZoneKeyboard() {
    document.querySelectorAll('.drop-zone').forEach(zone => {
        zone.addEventListener('keydown', e => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            zone.querySelector('input[type="file"]')?.click();
        });
    });
}

/* ---------- Confirmación (sustituye a confirm() nativo) ---------- */
function confirmAction({ title = '¿Continuar?', body = '', okLabel = 'Continuar', variant = 'primary' }) {
    return new Promise(resolve => {
        const modal = document.getElementById('confirm-modal');
        const ok    = document.getElementById('confirm-ok');

        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-body').textContent  = body;
        ok.textContent = okLabel;
        ok.className   = variant === 'danger' ? 'btn-danger' : 'btn-primary';

        const release = trapFocus(modal);
        const close = (result) => {
            modal.style.display = 'none';
            release();
            modal.querySelectorAll('[data-confirm-close]').forEach(el => el.removeEventListener('click', onCancel));
            ok.removeEventListener('click', onOk);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        };
        const onOk     = () => close(true);
        const onCancel = () => close(false);
        const onKey    = e => { if (e.key === 'Escape') close(false); };

        modal.querySelectorAll('[data-confirm-close]').forEach(el => el.addEventListener('click', onCancel));
        ok.addEventListener('click', onOk);
        document.addEventListener('keydown', onKey);

        modal.style.display = 'flex';
    });
}

/* ---------- Stats ---------- */
async function loadStats() {
    try {
        const res  = await fetch('api/stats.php');
        const data = await res.json();

        if (data.error) return;

        state.counts = data.counts;

        const total = (data.counts.only_chrome  || 0)
                    + (data.counts.only_firefox || 0)
                    + (data.counts.in_both      || 0);
        document.getElementById('stat-total').textContent        = total;
        document.getElementById('stat-only-chrome').textContent  = data.counts.only_chrome;
        document.getElementById('stat-only-firefox').textContent = data.counts.only_firefox;
        document.getElementById('stat-both').textContent         = data.counts.in_both;

        updateTabBadges(data.counts);
    } catch (_) {}
}

function updateTabBadges(counts) {
    state.updateExportHint?.();
    const allCount = (counts.all !== undefined)
        ? counts.all
        : (counts.only_chrome || 0) + (counts.only_firefox || 0) + (counts.in_both || 0);
    const map = {
        'all':          allCount,
        'only_chrome':  counts.only_chrome,
        'only_firefox': counts.only_firefox,
        'in_both':      counts.in_both,
    };
    document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
        const tab   = btn.dataset.tab;
        const badge = btn.querySelector('.tab-badge');
        if (badge && map[tab] !== undefined) {
            badge.textContent = map[tab];
        }
    });
}

/* ---------- Table ---------- */
async function loadTable() {
    const tbody = document.getElementById('bm-tbody');
    tbody.innerHTML = `<tr><td colspan="4" class="loading"><span class="spinner"></span> Cargando...</td></tr>`;

    const params = new URLSearchParams({
        tab:  state.tab,
        page: state.page,
    });
    if (state.search) params.set('search', state.search);

    try {
        const res  = await fetch('api/sync.php?' + params);
        const data = await res.json();

        if (data.error) {
            tbody.innerHTML = `<tr><td colspan="4" class="loading">${data.error}</td></tr>`;
            return;
        }

        state.counts = data.counts;
        updateTabBadges(data.counts);
        renderTable(data.items, data.total, data.page, data.per_page);

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" class="loading">Error al cargar datos</td></tr>`;
    }
}

function renderTable(items, total, page, perPage) {
    const tbody = document.getElementById('bm-tbody');

    if (items.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="4">
                <div class="empty-state">
                    <div class="icon">🔍</div>
                    <p>No hay marcadores en esta categoría</p>
                </div>
            </td></tr>`;
    } else {
        tbody.innerHTML = items.map(b => rowHtml(b)).join('');
    }

    renderPagination(total, page, perPage);
}

function rowHtml(b) {
    const favicon = b.icon
        ? `<img src="${escHtml(b.icon)}" class="favicon" loading="lazy" onerror="this.style.display='none'">`
        : `<span class="favicon" style="display:inline-block;width:16px"></span>`;

    const title    = escHtml(b.title || b.url || '(sin título)');
    const url      = escHtml(b.url || '');
    const folder   = escHtml(b.folder_path || '/');
    const source   = b.source || 'chrome';
    const date     = b.add_date ? new Date(b.add_date * 1000).toLocaleDateString() : '—';

    return `<tr>
        <td data-label="Título">
            <div class="bm-title">
                ${favicon}
                <a href="${url}" target="_blank" rel="noopener" title="${url}">${title}</a>
            </div>
        </td>
        <td data-label="Carpeta"><span class="folder-badge" title="${folder}">${folder}</span></td>
        <td data-label="Fuente"><span class="source-dot ${source}"></span>${source}</td>
        <td data-label="Fecha" style="color:var(--text-muted)">${date}</td>
    </tr>`;
}

function renderPagination(total, page, perPage) {
    const pages = Math.ceil(total / perPage);
    const el    = document.getElementById('pagination');

    if (pages <= 1) {
        el.innerHTML = '';
        return;
    }

    el.innerHTML = `
        <button class="page-btn" id="btn-prev" ${page <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span class="page-info">Página ${page} de ${pages} (${total} total)</span>
        <button class="page-btn" id="btn-next" ${page >= pages ? 'disabled' : ''}>Siguiente →</button>
    `;

    document.getElementById('btn-prev')?.addEventListener('click', () => { state.page--; loadTable(); });
    document.getElementById('btn-next')?.addEventListener('click', () => { state.page++; loadTable(); });
}

/* ---------- Tabs ---------- */
function setupTabs() {
    // Solo las pestañas de la comparación: las de «Comprobar enlaces» usan data-link-tab
    document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            state.tab  = btn.dataset.tab;
            state.page = 1;
            document.querySelectorAll('.tab-btn[data-tab]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            syncTabAria();
            loadTable();
        });
    });
}

/* ---------- Search ---------- */
function setupSearch() {
    const input = document.getElementById('search-input');
    if (!input) return;
    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            state.search = input.value;
            state.page   = 1;
            loadTable();
        }, 300);
    });
}

/* ---------- Import / Drop zones ---------- */
function setupDropZones() {
    ['chrome', 'firefox'].forEach(source => {
        const zone  = document.getElementById(`drop-${source}`);
        const input = document.getElementById(`file-${source}`);
        const info  = zone.querySelector('.file-info');

        if (!zone || !input) return;

        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            const file = e.dataTransfer.files[0];
            if (file) uploadFile(file, source, info);
        });

        input.addEventListener('change', () => {
            if (input.files[0]) uploadFile(input.files[0], source, info);
        });
    });
}

async function uploadFile(file, source, infoEl) {
    const form = new FormData();
    form.append('file',   file);
    form.append('source', source);
    form.append('preserve_categorized', document.getElementById('preserve-categorized')?.checked ? '1' : '0');

    if (infoEl) {
        infoEl.textContent = `Importando "${file.name}"…`;
        infoEl.style.display = 'block';
    }

    try {
        const res  = await fetch('api/import.php', { method: 'POST', body: form });
        const data = await res.json();

        if (data.error) {
            toast('Error: ' + data.error, 'error');
            if (infoEl) infoEl.textContent = 'Error en la importación';
            return;
        }

        const parts = [];
        if (data.added)     parts.push(`${data.added} nuevos`);
        if (data.updated)   parts.push(`${data.updated} actualizados`);
        if (data.unchanged) parts.push(`${data.unchanged} sin cambios`);
        const summary = parts.join(' · ') || 'sin cambios';

        toast(`✓ ${source}: ${summary}`, 'success');
        if (infoEl) infoEl.textContent = `✓ ${data.in_file} en archivo · ${summary}`;

        await loadStats();
        await loadTable();

    } catch (e) {
        toast(errorMsg(e), 'error');
        if (infoEl) infoEl.textContent = 'Error de conexión';
    }
}

/* ---------- Export buttons ---------- */
function setupExportButtons() {
    const fmt  = document.getElementById('export-format');
    const src  = document.getElementById('export-source');
    const hint = document.getElementById('export-hint');

    // Algunas combinaciones no tienen sentido: el SQL de MariaDB siempre vuelca todo.
    const updateHint = () => {
        const counts = state.counts;
        const n = {
            merged:  (counts.only_chrome || 0) + (counts.only_firefox || 0) + (counts.in_both || 0),
            chrome:  counts.total_chrome  || 0,
            firefox: counts.total_firefox || 0,
        }[src.value] ?? 0;

        if (fmt.value === 'mariadb-sql') {
            src.disabled = true;
            hint.textContent = 'El volcado SQL incluye siempre todos los marcadores y carpetas.';
        } else {
            src.disabled = false;
            hint.textContent = `Se descargarán ${n} marcadores.`;
        }
    };

    fmt.addEventListener('change', updateHint);
    src.addEventListener('change', updateHint);
    updateHint();
    state.updateExportHint = updateHint;   // loadStats lo refresca al tener los conteos

    document.getElementById('btn-export').addEventListener('click', () => {
        const source = fmt.value === 'mariadb-sql' ? 'merged' : src.value;
        window.location = `api/export.php?format=${fmt.value}&source=${source}`;
    });

    document.getElementById('btn-backup').addEventListener('click', () => {
        window.location = 'api/export.php?format=sqlite-db&source=merged';
    });
}

async function checkApiKey() {
    try {
        const res  = await fetch('api/categorize.php?check');
        const data = await res.json();
        const notice = document.getElementById('api-key-notice');
        if (notice) notice.style.display = data.claude_configured ? 'none' : 'block';
    } catch (_) {}
}

function setupCategorize() {
    const btn = document.getElementById('btn-categorize');
    if (!btn) return;

    const estimateBox = document.getElementById('cat-estimate');

    // Paso 1: pedir la estimación y enseñarla antes de gastar nada
    btn.addEventListener('click', async () => {
        const opts = readCatOptions();
        btn.disabled = true;
        btn.textContent = 'Calculando…';

        try {
            const res  = await fetch('api/categorize.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({ action: 'estimate', ...opts }),
            });
            const data = await res.json();

            if (data.error)      return toast('Error: ' + data.error, 'error');
            if (!data.pending)   return toast('No hay marcadores sin categorizar.', 'info');

            showEstimate(data, opts);
        } catch (e) {
            toast('Error de conexión: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Analizar y sugerir carpetas';
        }
    });

    document.getElementById('cat-estimate-cancel').addEventListener('click', () => {
        estimateBox.style.display = 'none';
    });

    document.getElementById('cat-estimate-go').addEventListener('click', async () => {
        estimateBox.style.display = 'none';
        await startCategorizeJob(readCatOptions());
    });

    setupJobProgressButtons();
}

/** Borra lo que quedó del trabajo anterior para no mezclarlo con el nuevo. */
function resetJobProgress() {
    clearTimeout(state.hideJobTimer);
    clearTimeout(state.jobTimer);
    state.job        = null;
    state.jobStart   = null;
    state.jobTicks   = 0;
    state.cancelling = false;
    document.querySelectorAll('.job-progress').forEach(box => {
        box.style.display = 'none';
        box.querySelector('.jp-label').textContent   = 'Procesando…';
        box.querySelector('.jp-detail').textContent  = '';
        box.querySelector('.jp-time').textContent    = '';
        box.querySelector('.jp-bar').style.width     = '';
        box.querySelector('.jp-bar').className       = 'jp-bar job-bar-fill';
    });
}

/**
 * Retira el bloque de progreso unos segundos después de terminar y borra el job:
 * los resultados ya están en la tabla, el recuadro solo estorba.
 */
function hideJobProgressSoon(boxId, ms = 6000) {
    clearTimeout(state.hideJobTimer);
    state.hideJobTimer = setTimeout(() => {
        const box = document.getElementById(boxId);
        if (box) box.style.display = 'none';
        fetch('api/jobs.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'clear' }),
        }).catch(() => {});
    }, ms);
}

/** Los dos bloques de progreso (categorizar y enlaces) comparten manejadores. */
function setupJobProgressButtons() {
    document.querySelectorAll('.job-progress').forEach(box => {
        box.querySelector('.jp-cancel')?.addEventListener('click', async (e) => {
            if (!state.job?.id) return;
            const btn = e.currentTarget;

            // El worker tarda hasta un punto de control en enterarse: decirlo ya
            btn.disabled = true;
            btn.textContent = 'Cancelando…';
            box.querySelector('.jp-label').textContent = 'Cancelando…';
            state.cancelling = true;

            try {
                await fetch('api/jobs.php', {
                    method:  'POST',
                    headers: {'Content-Type': 'application/json'},
                    body:    JSON.stringify({ action: 'cancel', id: state.job.id }),
                });
                toast('Cancelando… se guardará todo lo comprobado.', 'info');
            } catch (err) {
                toast(errorMsg(err), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Cancelar';
                pollJob(true);   // por si el seguimiento se había detenido
            }
        });

        box.querySelector('.jp-open')?.addEventListener('click', () => openJobResults());

        box.querySelector('.jp-dismiss')?.addEventListener('click', () => {
            box.style.display = 'none';
            fetch('api/jobs.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({ action: 'clear' }),
            });
        });
    });
}

function readCatOptions() {
    return {
        engine: document.querySelector('input[name="engine"]:checked')?.value ?? 'both',
        source: document.getElementById('cat-source')?.value ?? 'all',
        max:    parseInt(document.getElementById('cat-max')?.value ?? '0', 10),
    };
}

function showEstimate(data, opts) {
    const box = document.getElementById('cat-estimate');
    const txt = document.getElementById('cat-estimate-text');

    if (opts.engine === 'heuristic') {
        txt.innerHTML = `<strong>${data.pending}</strong> marcadores pendientes · heurística local, sin coste y casi instantánea.`;
    } else {
        const mins = data.est_seconds >= 60
            ? `~${Math.ceil(data.est_seconds / 60)} min`
            : `~${data.est_seconds} s`;
        const refine = data.to_refine !== undefined && opts.engine === 'both'
            ? ` · la heurística resuelve ${data.pending - data.to_refine} y Claude revisa <strong>${data.to_refine}</strong>`
            : '';
        txt.innerHTML = `<strong>${data.pending}</strong> marcadores pendientes${refine}`
            + `<br><strong>${data.batches}</strong> llamadas a la API · duración estimada <strong>${mins}</strong>.`
            + ` Puedes cerrar la pestaña: seguirá en segundo plano.`;
    }
    box.style.display = 'flex';
}

async function startCategorizeJob(opts) {
    resetJobProgress();
    try {
        const res  = await fetch('api/categorize.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'suggest', ...opts }),
        });
        const data = await res.json();

        if (data.error && data.job_id) {
            toast('Ya había un trabajo en marcha; te muestro ese.', 'info');
            state.jobStart = null;
            return pollJob(true);
        }
        if (data.error) {
            toast('Error: ' + data.error, 'error');
            return;
        }
        state.job      = data.job;
        state.jobStart = Date.now();
        try { renderJobProgress(data.job); } catch (err) { console.error(err); }
        pollJob(true);
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

/** Distingue un fallo de red de un error del propio código, que antes salía como «error de conexión». */
function errorMsg(e) {
    return (e instanceof TypeError && /fetch|network/i.test(e.message))
        ? 'Error de conexión con el servidor'
        : 'Error: ' + (e?.message || e);
}

/* Polling: 1,5 s con job activo; para al terminar */
async function pollJob(immediate = false) {
    clearTimeout(state.jobTimer);
    if (!immediate) { /* llamada programada */ }

    try {
        const res  = await fetch('api/jobs.php');
        const data = await res.json();
        const job  = data.job;

        if (!job) {
            document.querySelectorAll('.job-progress').forEach(b => b.style.display = 'none');
            return;
        }

        const activo = job.status === 'queued' || job.status === 'running';

        // Al entrar en la página, un trabajo terminado hace rato no interesa:
        // sus resultados ya están en las tablas y el recuadro solo confunde.
        if (!activo && state.job === null && Date.now() - Date.parse(job.updated_at) > 120000) {
            document.querySelectorAll('.job-progress').forEach(b => b.style.display = 'none');
            return;
        }

        state.job = job;
        if (!state.jobStart) state.jobStart = Date.parse(job.created_at) || Date.now();
        try { renderJobProgress(job); } catch (err) { console.error(err); }

        if (job.status === 'queued' || job.status === 'running') {
            // Los totales de la sección se refrescan cada pocos ticks, no en cada uno
            if ((job.type ?? 'categorize') === 'linkcheck' && (state.jobTicks = (state.jobTicks ?? 0) + 1) % 4 === 0) {
                loadLinkSummary();
            }
            state.jobTimer = setTimeout(() => pollJob(), 1500);
            return;
        }
        state.jobTicks   = 0;
        state.cancelling = false;

        // Terminado
        if ((job.type ?? 'categorize') === 'linkcheck') {
            await Promise.all([loadLinkSummary(), loadLinkTable()]);
            hideJobProgressSoon('link-progress');
            if (job.status === 'done') {
                toast(`✓ ${job.done} enlaces comprobados · ${job.dead ?? 0} muertos`, 'success');
            } else if (job.status === 'cancelled') {
                toast(`Comprobación cancelada · ${job.done} enlaces revisados quedan guardados`, 'info');
            } else if (job.status === 'error') {
                toast('Error al comprobar: ' + (job.error ?? ''), 'error');
            }
            return;
        }

        if (job.status === 'done' && job.changed > 0) {
            await openJobResults();
        } else if (job.status === 'done') {
            toast('Sin cambios de carpeta que sugerir.', 'info');
        } else if (job.status === 'error') {
            toast('Error en la categorización: ' + (job.error ?? ''), 'error');
        } else if (job.status === 'cancelled') {
            toast('Categorización cancelada.', 'info');
            if (job.changed > 0) await openJobResults();
        }
        if (job.warning) toast('⚠ ' + job.warning, 'warn');

    } catch (e) {
        state.jobTimer = setTimeout(() => pollJob(), 5000);
    }
}

function renderJobProgress(job) {
    const esLink = (job.type ?? 'categorize') === 'linkcheck';
    const active = job.status === 'queued' || job.status === 'running';

    // Cada tipo de job pinta en su propia sección; la otra se oculta
    const box   = document.getElementById(esLink ? 'link-progress' : 'cat-progress');
    const otra  = document.getElementById(esLink ? 'cat-progress' : 'link-progress');
    if (otra) otra.style.display = 'none';
    if (!box) return;

    const bar    = box.querySelector('.jp-bar');
    const gauge  = box.querySelector('.job-bar');
    const label  = box.querySelector('.jp-label');
    const detail = box.querySelector('.jp-detail');
    const time   = box.querySelector('.jp-time');

    box.style.display = '';

    const pct = job.total > 0 ? Math.min(100, Math.round(job.done / job.total * 100)) : 0;

    // Sin avance medible: en cola todavía no hay nada, y la heurística no tiene lotes.
    // En esos casos no se fija el ancho para que mande la animación del CSS.
    const enCola        = job.status === 'queued';
    const indeterminado = active && !esLink && job.batches === 0;
    // OJO: hay que mantener 'jp-bar', que es por donde se localiza el elemento
    bar.className   = 'jp-bar job-bar-fill' + (indeterminado ? ' indeterminate' : ' ' + job.status);
    bar.style.width = (indeterminado || enCola) ? '' : pct + '%';

    if (indeterminado || enCola) {
        gauge.removeAttribute('aria-valuenow');
    } else {
        gauge.setAttribute('aria-valuenow', String(pct));
        gauge.setAttribute('aria-valuemin', '0');
        gauge.setAttribute('aria-valuemax', '100');
    }

    const labels = esLink ? {
        queued:    'En cola…',
        running:   `Comprobando enlaces… ${pct}%`,
        done:      '✓ Comprobación terminada',
        cancelled: 'Comprobación cancelada',
        error:     'Error',
    } : {
        queued:    'En cola…',
        running:   'Procesando marcadores…',
        done:      '✓ Análisis terminado',
        cancelled: 'Cancelado',
        error:     'Error',
    };
    label.textContent = (state.cancelling && active) ? 'Cancelando…' : (labels[job.status] ?? job.status);

    const parts = [];
    if (esLink) {
        if (job.total > 0) parts.push(`${job.done}/${job.total} enlaces`);
        parts.push(`${job.alive ?? 0} vivos · ${job.dubious ?? 0} dudosos · ${job.dead ?? 0} muertos`);
    } else {
        if (job.batches > 0) parts.push(`lote ${job.batches_done}/${job.batches}`);
        if (job.total > 0)   parts.push(`${job.done}/${job.total} marcadores`);
        if (!active)         parts.push(`${job.changed} cambios sugeridos`);
    }
    detail.textContent = parts.join(' · ');

    if (state.jobStart) {
        const secs = Math.round((Date.now() - state.jobStart) / 1000);
        let txt = secs >= 60 ? `${Math.floor(secs / 60)} min ${secs % 60} s` : `${secs} s`;
        // Con el avance conocido se puede estimar lo que falta
        if (active && job.done > 20 && job.total > job.done) {
            const restan = Math.round((secs / job.done) * (job.total - job.done));
            txt += restan >= 60 ? ` · faltan ~${Math.ceil(restan / 60)} min` : ` · faltan ~${restan} s`;
        }
        time.textContent = txt;
    }

    box.querySelector('.jp-cancel').style.display  = active ? '' : 'none';
    box.querySelector('.jp-dismiss').style.display = active ? 'none' : '';
    const open = box.querySelector('.jp-open');
    if (open) open.style.display = !active && !esLink && job.changed > 0 ? '' : 'none';
}

async function openJobResults() {
    if (!state.job?.id) return;
    try {
        const res  = await fetch(`api/jobs.php?id=${encodeURIComponent(state.job.id)}&results=1`);
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');
        if (!data.suggestions?.length) return toast('Sin sugerencias que revisar.', 'info');

        allSuggestions = data.suggestions;
        openCatModal({ changed: data.changed, total: data.total });
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

let releaseCatFocus = null;

function openCatModal(data) {
    const modal = document.getElementById('cat-modal');
    modal.style.display = 'flex';
    releaseCatFocus = trapFocus(modal);
    document.getElementById('modal-title').textContent = 'Sugerencias de categorización';
    document.getElementById('modal-summary').textContent =
        `${data.changed} cambios sugeridos de ${data.total} marcadores analizados`;

    renderSuggestions(allSuggestions);
    setupModalControls();
}

function renderSuggestions(suggestions) {
    const body    = document.getElementById('modal-body');
    const q       = (document.getElementById('modal-search')?.value ?? '').toLowerCase();
    const engine  = document.getElementById('modal-engine-filter')?.value ?? '';

    body.innerHTML = '';

    suggestions.forEach((s, i) => {
        const title    = s.title || s.url || '(sin título)';
        const oldDir   = s.folder_path   || '/';
        const newDir   = s.suggested_folder || '/';
        const eng      = s.engine || 'heuristic';

        // Filter
        const matchesQ = !q || title.toLowerCase().includes(q) || (s.url||'').toLowerCase().includes(q)
                            || newDir.toLowerCase().includes(q);
        const matchesE = !engine || eng === engine;

        const hidden = (!matchesQ || !matchesE) ? 'hidden' : '';

        const div = document.createElement('div');
        div.className = `sug-row ${hidden}`;
        div.dataset.index = i;

        div.innerHTML = `
            <input type="checkbox" class="sug-check" checked data-index="${i}">
            <div class="sug-info">
                <div class="sug-title">
                    <a href="${escHtml(s.url||'')}" target="_blank" rel="noopener" title="${escHtml(s.url||'')}">
                        ${escHtml(title)}
                    </a>
                </div>
                <div class="sug-folders">
                    <span class="sug-old">${escHtml(oldDir)}</span>
                    <span class="sug-arrow">→</span>
                    <span class="sug-new ${eng}">${escHtml(newDir)}</span>
                </div>
            </div>
            <div class="sug-engine">
                <span class="engine-badge ${eng}">${eng === 'claude' ? 'Claude AI' : 'Heurística'}</span>
            </div>
        `;

        body.appendChild(div);
    });
}

function setupModalControls() {
    // Close
    document.getElementById('modal-close').onclick     = closeCatModal;
    document.getElementById('btn-cancel-modal').onclick = closeCatModal;
    document.getElementById('cat-backdrop').onclick     = closeCatModal;

    // Select / deselect all
    document.getElementById('btn-select-all').onclick   = () => setAllChecked(true);
    document.getElementById('btn-deselect-all').onclick  = () => setAllChecked(false);

    // Search/filter
    const searchInput = document.getElementById('modal-search');
    const engineFilter = document.getElementById('modal-engine-filter');
    searchInput.value  = '';
    engineFilter.value = '';

    searchInput.oninput  = () => renderSuggestions(allSuggestions);
    engineFilter.onchange = () => renderSuggestions(allSuggestions);

    // Apply
    document.getElementById('btn-apply-changes').onclick = applyChanges;
}

function setAllChecked(val) {
    document.querySelectorAll('.sug-check').forEach(cb => {
        const row = cb.closest('.sug-row');
        if (!row.classList.contains('hidden')) cb.checked = val;
    });
}

function closeCatModal() {
    releaseCatFocus?.(); releaseCatFocus = null;
    document.getElementById('cat-modal').style.display = 'none';
}

async function applyChanges() {
    const checked = [...document.querySelectorAll('.sug-check:checked')];
    if (!checked.length) {
        toast('No hay cambios seleccionados', 'error');
        return;
    }

    const changes = checked.map(cb => {
        const i = parseInt(cb.dataset.index);
        const s = allSuggestions[i];
        return { url: s.url, folder: s.suggested_folder, source: s.source };
    });

    const btn      = document.getElementById('btn-apply-changes');
    btn.disabled   = true;
    btn.textContent = 'Aplicando…';

    try {
        const res  = await fetch('api/categorize.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'apply', changes }),
        });
        const data = await res.json();

        if (data.error) {
            toast('Error: ' + data.error, 'error');
            return;
        }

        toast(`✓ ${data.updated} marcadores actualizados`, 'success',
            data.batch_id ? { label: 'Deshacer', onClick: () => undoBatch(data.batch_id) } : null,
            data.batch_id ? 10000 : 3500);
        closeCatModal();
        await loadStats();
        await loadTable();
        await loadHistory();

    } catch (e) {
        toast(errorMsg(e), 'error');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Aplicar cambios seleccionados';
    }
}

/* =====================================================
   Domain Homologation
   ===================================================== */

let homologData    = [];
let homologFolders = [];

let releaseHomologFocus = null;

function openHomologModal() {
    const modal = document.getElementById('homolog-modal');
    modal.style.display = 'flex';
    releaseHomologFocus = trapFocus(modal);
}
function closeHomologModal() {
    releaseHomologFocus?.(); releaseHomologFocus = null;
    document.getElementById('homolog-modal').style.display = 'none';
}

function setupHomolog() {
    const btn = document.getElementById('btn-analyze');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        homologPage = 1;
        document.getElementById('homolog-search').value = '';
        document.getElementById('homolog-only-inconsistent').checked = false;
        await loadHomolog(true);
    });

    // Close
    document.getElementById('homolog-close').addEventListener('click', closeHomologModal);
    document.getElementById('homolog-cancel').addEventListener('click', closeHomologModal);
    document.getElementById('homolog-backdrop').addEventListener('click', closeHomologModal);

    // Toolbar
    document.getElementById('homolog-select-inconsistent').addEventListener('click', () => {
        document.querySelectorAll('.hom-check').forEach(cb => {
            const row = cb.closest('.hom-row');
            if (row && row.dataset.inconsistent === '1') cb.checked = true;
        });
    });
    document.getElementById('homolog-deselect-all').addEventListener('click', () => {
        document.querySelectorAll('.hom-check').forEach(cb => cb.checked = false);
    });

    document.getElementById('homolog-apply').addEventListener('click', applyHomologChanges);

    // Filtros: ahora los resuelve el servidor, así que recargan la página 1
    const modal = document.getElementById('homolog-modal');
    let timer;
    modal.addEventListener('input', e => {
        if (e.target.id !== 'homolog-search') return;
        clearTimeout(timer);
        timer = setTimeout(() => { homologPage = 1; loadHomolog(); }, 300);
    });
    modal.addEventListener('change', e => {
        if (e.target.id !== 'homolog-only-inconsistent') return;
        homologPage = 1;
        loadHomolog();
    });
}

let homologPage = 1;

/**
 * Carga una página de dominios. El filtrado va en el servidor: la lista completa
 * eran cientos de KB y el modal solo muestra 100 filas.
 */
async function loadHomolog(openAfter = false) {
    const btn = document.getElementById('btn-analyze');
    const q         = document.getElementById('homolog-search').value.trim();
    const onlyIncon = document.getElementById('homolog-only-inconsistent').checked;

    if (openAfter) { btn.disabled = true; btn.textContent = 'Analizando…'; }

    const params = new URLSearchParams({ page: homologPage, per_page: 100 });
    if (q)         params.set('q', q);
    if (onlyIncon) params.set('only_inconsistent', '1');

    try {
        const res  = await fetch('api/analyze.php?' + params);
        const data = await res.json();
        if (data.error) { toast('Error: ' + data.error, 'error'); return; }

        homologData = data.domains;
        // La lista de carpetas solo viene en la primera página
        if (data.folders) homologFolders = data.folders;

        const shownFrom = data.filtered ? (data.page - 1) * data.per_page + 1 : 0;
        const shownTo   = Math.min(data.page * data.per_page, data.filtered);
        document.getElementById('homolog-summary').textContent =
            `${data.total_domains} dominios · ${data.inconsistent_count} inconsistentes`
            + (data.filtered !== data.total_domains ? ` · ${data.filtered} filtrados` : '')
            + (data.filtered ? ` · mostrando ${shownFrom}-${shownTo}` : '');

        renderHomologRows(homologData, homologFolders);
        renderHomologPager(data);
        if (openAfter) openHomologModal();
    } catch (e) {
        toast(errorMsg(e), 'error');
    } finally {
        if (openAfter) { btn.disabled = false; btn.textContent = 'Analizar y homologar dominios'; }
    }
}

function renderHomologPager(data) {
    const pages = Math.ceil(data.filtered / data.per_page);
    let pager   = document.getElementById('homolog-pager');

    if (!pager) {
        pager = document.createElement('div');
        pager.id = 'homolog-pager';
        pager.className = 'pagination';
        document.getElementById('homolog-body').after(pager);
    }

    if (pages <= 1) { pager.innerHTML = ''; return; }

    pager.innerHTML = `
        <button class="page-btn" id="hom-prev" ${data.page <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span class="page-info">Página ${data.page} de ${pages}</span>
        <button class="page-btn" id="hom-next" ${data.page >= pages ? 'disabled' : ''}>Siguiente →</button>`;

    document.getElementById('hom-prev')?.addEventListener('click', () => { homologPage--; loadHomolog(); });
    document.getElementById('hom-next')?.addEventListener('click', () => { homologPage++; loadHomolog(); });
}

function renderHomologRows(domains, folders) {
    const body = document.getElementById('homolog-body');

    if (!domains.length) {
        body.innerHTML = `<div style="padding:2rem;text-align:center;color:var(--text-muted)">No hay dominios</div>`;
        return;
    }

    body.innerHTML = domains.map((d, i) => {
        const folderBadges = Object.entries(d.folders)
            .slice(0, 3)
            .map(([f, c]) => `<span class="folder-badge" title="${escHtml(f)}">${escHtml(f)} <em style="opacity:.6">(${c})</em></span>`)
            .join(' ');
        const more = Object.keys(d.folders).length > 3
            ? `<span style="color:var(--text-muted);font-size:.8rem">+${Object.keys(d.folders).length - 3} más</span>`
            : '';

        // Select with all known folders; dominant one pre-selected
        const selectOpts = folders.map(f => {
            const sel = f === d.dominant_folder ? ' selected' : '';
            return `<option value="${escHtml(f)}"${sel}>${escHtml(f)}</option>`;
        }).join('');

        return `
        <div class="hom-row${d.is_consistent ? '' : ' hom-inconsistent'}"
             data-domain="${escHtml(d.domain)}"
             data-inconsistent="${d.is_consistent ? '0' : '1'}"
             data-index="${i}">
            <label class="hom-check-wrap">
                <input type="checkbox" class="hom-check" data-index="${i}">
            </label>
            <div class="hom-info">
                <div class="hom-domain">
                    <strong>${escHtml(d.domain)}</strong>
                    <span class="tab-badge" style="margin-left:.4rem">${d.total}</span>
                    ${!d.is_consistent ? '<span class="hom-badge-warn">inconsistente</span>' : ''}
                </div>
                <div class="hom-folders">${folderBadges}${more}</div>
            </div>
            <div class="hom-action">
                <select class="hom-folder-select select-input" data-index="${i}"
                        style="width:100%;font-size:.8rem;padding:5px 8px">
                    ${selectOpts}
                </select>
            </div>
        </div>`;
    }).join('');
}

async function applyHomologChanges() {
    const checked = [...document.querySelectorAll('.hom-check:checked')];
    if (!checked.length) { toast('No hay dominios seleccionados', 'error'); return; }

    const changes = [];
    for (const cb of checked) {
        const i      = parseInt(cb.dataset.index);
        const select = document.querySelector(`.hom-folder-select[data-index="${i}"]`);
        const folder = select ? select.value.trim() : '';
        if (!folder) continue;
        changes.push({ domain: homologData[i].domain, folder });
    }

    if (!changes.length) {
        toast('Selecciona una carpeta destino para los dominios marcados', 'error');
        return;
    }

    const btn = document.getElementById('homolog-apply');
    btn.disabled    = true;
    btn.textContent = 'Aplicando…';

    try {
        const res  = await fetch('api/categorize.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'apply_domain', changes }),
        });
        const data = await res.json();
        if (data.error) { toast('Error: ' + data.error, 'error'); return; }

        toast(`${data.updated} marcadores actualizados`, 'success',
            data.batch_id ? { label: 'Deshacer', onClick: () => undoBatch(data.batch_id) } : null,
            data.batch_id ? 10000 : 3500);
        closeHomologModal();
        loadStats();
        loadTable();
        loadHistory();
    } catch (e) {
        toast(errorMsg(e), 'error');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Aplicar seleccionados';
    }
}

/* =====================================================
   Historial de cambios masivos
   ===================================================== */

async function loadHistory() {
    try {
        const res  = await fetch('api/categorize.php?history');
        const data = await res.json();
        renderHistory(data.batches ?? []);
    } catch (e) { /* el historial es accesorio: no molestar si falla */ }
}

function renderHistory(batches) {
    const box = document.getElementById('history-list');
    if (!box) return;

    if (!batches.length) {
        box.innerHTML = '<div class="history-empty">Todavía no has aplicado ningún cambio masivo.</div>';
        return;
    }

    const kindLabel = { categorize: 'Categorización', homologate: 'Homologación' };

    box.innerHTML = batches.map(b => `
        <div class="history-item ${b.undone ? 'undone' : ''}">
            <span class="history-kind ${b.kind}">${kindLabel[b.kind] ?? b.kind}</span>
            <span class="history-summary">${escHtml(b.summary)}</span>
            <span class="history-date">${fmtBatchDate(b.created_at)}</span>
            ${b.undone
                ? '<span class="history-undone-tag">deshecho</span>'
                : (b.can_undo ? `<button class="btn-sm" data-undo="${b.id}">Deshacer</button>` : '')}
        </div>`).join('');

    box.querySelectorAll('[data-undo]').forEach(btn =>
        btn.addEventListener('click', () => undoBatch(parseInt(btn.dataset.undo, 10))));
}

function fmtBatchDate(ts) {
    if (!ts) return '';
    return new Date(ts * 1000).toLocaleString('es-MX',
        { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

async function undoBatch(batchId) {
    const go = await confirmAction({
        title:   'Deshacer cambios',
        body:    'Las carpetas de los marcadores de ese lote volverán a como estaban antes de aplicarlo.',
        okLabel: 'Deshacer',
    });
    if (!go) return;

    try {
        const res  = await fetch('api/categorize.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'undo', batch_id: batchId }),
        });
        const data = await res.json();

        if (data.error) return toast('No se pudo deshacer: ' + data.error, 'error');

        toast(`✓ ${data.restored} marcadores restaurados`, 'success');
        renderHistory(data.history ?? []);
        await loadStats();
        await loadTable();
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}


/* =====================================================
   Comprobar enlaces
   ===================================================== */

async function loadLinkSummary() {
    try {
        const res  = await fetch('api/links.php?summary');
        const data = await res.json();
        if (data.error) return;

        const c = data.counts;
        document.getElementById('link-alive').textContent     = c.alive;
        document.getElementById('link-dubious').textContent   = c.dubious;
        document.getElementById('link-dead').textContent      = c.dead;
        document.getElementById('link-unchecked').textContent = c.unchecked;

        const badges = { dead: c.dead, dubious: c.dubious, redirect: data.redirects, manual: data.manual };

        const note = document.getElementById('link-alive-note');
        if (note) {
            note.textContent = '';
            if (data.manual) {
                note.appendChild(document.createTextNode(`incluye ${data.manual} verificados a mano`));
            }
            if (data.manual_stale) {
                const w = document.createElement('span');
                w.classList.add('warn');
                w.textContent = ` · ${data.manual_stale} para revisar`;
                note.appendChild(w);
            }
        }
        document.querySelectorAll('.tab-btn[data-link-tab]').forEach(btn => {
            const b = btn.querySelector('.tab-badge');
            if (b) b.textContent = badges[btn.dataset.linkTab] ?? 0;
        });

        const skipped = (c.unsupported || 0) + (c.unchecked_local || 0);
        const parts = [];
        parts.push(data.last_check
            ? `Última comprobación: ${fmtBatchDate(data.last_check)}`
            : 'Todavía no has comprobado ningún enlace');
        if (skipped) parts.push(`${skipped} no se pueden comprobar (chrome:// o de tu red local)`);
        parts.push(`se revisan de nuevo a los ${data.stale_days} días`);
        document.getElementById('link-last-check').textContent = parts.join(' · ');

        state.trashCount = data.trash_count;
    } catch (e) { /* accesorio */ }
}

function setupLinkCheck() {
    const estimateBox = document.getElementById('link-estimate');

    const ask = async (scope) => {
        state.linkScope = scope;
        try {
            const res  = await fetch(`api/links.php?estimate&scope=${scope}`);
            const data = await res.json();
            if (data.error)    return toast('Error: ' + data.error, 'error');
            if (!data.pending) return toast('No hay enlaces que comprobar en ese grupo.', 'info');

            const mins = data.est_seconds >= 60
                ? `~${Math.ceil(data.est_seconds / 60)} min`
                : `~${data.est_seconds} s`;
            const nombre = { stale: 'sin revisar o caducados', all: 'en total', failed: 'que fallaron antes' }[scope];

            document.getElementById('link-estimate-text').innerHTML =
                `<strong>${data.pending}</strong> enlaces ${nombre} · ${data.concurrency} peticiones a la vez · `
                + `duración estimada <strong>${mins}</strong>.<br>`
                + `Se piden desde este equipo; puedes cerrar la pestaña, sigue en segundo plano.`;
            estimateBox.style.display = 'flex';
        } catch (e) {
            toast(errorMsg(e), 'error');
        }
    };

    document.getElementById('btn-check-stale').addEventListener('click',  () => ask('stale'));
    document.getElementById('btn-check-all').addEventListener('click',    () => ask('all'));
    document.getElementById('btn-check-failed').addEventListener('click', () => ask('failed'));

    document.getElementById('link-estimate-cancel').addEventListener('click', () => {
        estimateBox.style.display = 'none';
    });

    document.getElementById('link-estimate-go').addEventListener('click', async () => {
        estimateBox.style.display = 'none';
        resetJobProgress();          // que no se vea ni un instante el trabajo anterior
        try {
            const res  = await fetch('api/links.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({ action: 'check', scope: state.linkScope }),
            });
            const data = await res.json();

            // Ya había uno en marcha: en vez de un error opaco, seguimos ese
            if (data.error && data.job_id) {
                toast('Ya había una comprobación en marcha; te muestro esa.', 'info');
                state.jobStart = null;
                return pollJob(true);
            }
            if (data.error) return toast('Error: ' + data.error, 'error');

            state.job      = data.job;
            state.jobStart = Date.now();
            // El polling arranca pase lo que pase: si el pintado fallara, el trabajo
            // ya está en marcha en el servidor y hay que seguirlo igualmente.
            try { renderJobProgress(data.job); } catch (err) { console.error(err); }
            pollJob(true);
        } catch (e) {
            toast(errorMsg(e), 'error');
        }
    });

    // Pestañas de enlaces
    document.querySelectorAll('.tab-btn[data-link-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            state.linkTab  = btn.dataset.linkTab;
            state.linkPage = 1;
            document.querySelectorAll('.tab-btn[data-link-tab]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            syncTabAria();
            loadLinkTable();
        });
    });

    // Selección múltiple
    document.getElementById('link-select-all').addEventListener('change', e => {
        document.querySelectorAll('#link-tbody .link-check').forEach(cb => cb.checked = e.target.checked);
        updateLinkSelection();
    });
    document.getElementById('link-tbody').addEventListener('change', e => {
        if (e.target.classList.contains('link-check')) updateLinkSelection();
    });

    document.getElementById('btn-bulk-delete').addEventListener('click', () => deleteLinks(selectedLinkIds()));
    document.getElementById('btn-bulk-adopt').addEventListener('click',  () => adoptRedirects(selectedLinkIds()));
    document.getElementById('btn-bulk-alive').addEventListener('click',  () => markLinksAlive(selectedLinkIds()));
    document.getElementById('btn-bulk-unmark').addEventListener('click', () => unmarkLinks(selectedLinkIds()));

    // Acciones por fila
    document.getElementById('link-tbody').addEventListener('click', async e => {
        const btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.linkAlive)   return markLinksAlive([parseInt(btn.dataset.linkAlive, 10)]);
        if (btn.dataset.linkUnmark)  return unmarkLinks([parseInt(btn.dataset.linkUnmark, 10)]);
        if (btn.dataset.linkDelete)  return deleteLinks([parseInt(btn.dataset.linkDelete, 10)]);
        if (btn.dataset.linkAdopt)   return adoptRedirects([parseInt(btn.dataset.linkAdopt, 10)]);
        if (btn.dataset.linkRecheck) return recheckLinks([parseInt(btn.dataset.linkRecheck, 10)], btn);
    });
}

function selectedLinkIds() {
    return [...document.querySelectorAll('#link-tbody .link-check:checked')]
        .map(cb => parseInt(cb.value, 10));
}

function updateLinkSelection() {
    const n = selectedLinkIds().length;
    document.getElementById('link-bulk-count').textContent = n ? `${n} seleccionados` : '';
    ['btn-bulk-delete', 'btn-bulk-adopt', 'btn-bulk-alive', 'btn-bulk-unmark']
        .forEach(id => document.getElementById(id).disabled = n === 0);
}

async function loadLinkTable() {
    const tbody = document.getElementById('link-tbody');
    try {
        const res  = await fetch(`api/links.php?status=${state.linkTab}&page=${state.linkPage}`);
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        const isRedirect = state.linkTab === 'redirect';
        const isManual   = state.linkTab === 'manual';
        const isFailed   = !isRedirect && !isManual;   // Muertos o Dudosos
        document.getElementById('link-bulk').style.display = data.items.length ? 'flex' : 'none';
        document.getElementById('btn-bulk-adopt').style.display  = isRedirect ? '' : 'none';
        document.getElementById('btn-bulk-delete').style.display = isFailed   ? '' : 'none';
        document.getElementById('btn-bulk-alive').style.display  = isFailed   ? '' : 'none';
        document.getElementById('btn-bulk-unmark').style.display = isManual   ? '' : 'none';
        document.getElementById('link-select-all').checked = false;

        if (!data.items.length) {
            const vacio = {
                dead:     'Ningún enlace muerto. 🎉',
                dubious:  'Ningún enlace dudoso.',
                redirect: 'Ningún enlace redirige a otra dirección.',
                manual:   'No has marcado ningún enlace como vivo. Usa ✓ en Muertos o Dudosos tras comprobarlo tú.',
            }[state.linkTab];
            tbody.innerHTML = `<tr><td colspan="5"><div class="empty-state">
                <div class="icon">🔗</div><p>${vacio}</p></div></td></tr>`;
            renderLinkPagination(0, 1, data.per_page);
            updateLinkSelection();
            return;
        }

        tbody.innerHTML = data.items.map(r => linkRowHtml(r, isRedirect, isManual)).join('');
        renderLinkPagination(data.total, data.page, data.per_page);
        updateLinkSelection();
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

/** Texto corto del resultado automático: el error de curl o el código HTTP. */
function linkResultText(r) {
    return r.link_error ? r.link_error : `HTTP ${r.link_code}`;
}

function linkRowHtml(r, isRedirect, isManual = false) {
    const title = escHtml(r.title || r.url);
    const url   = escHtml(r.url);

    let resultado;
    if (isRedirect) {
        resultado = `<span class="link-redirect">→ ${escHtml(r.link_redirect_url || '')}</span>`;
    } else if (isManual) {
        // Verificado a mano: la marca manda; debajo, lo que dice la comprobación automática
        const stale = r.link_auto_status === 'dead';
        const auto  = r.link_auto_status === 'alive'
            ? 'la comprobación automática también lo da por vivo'
            : `${stale ? '⚠ ' : ''}la comprobación automática ahora da: ${escHtml(linkResultText(r))}`;
        resultado = `<span class="link-badge manual">✓ verificado ${fmtBatchDate(r.link_manual_at)}</span>
                     <span class="link-auto${stale ? ' stale' : ''}">${auto}</span>`;
    } else {
        resultado = `<span class="link-badge ${r.link_status}">${escHtml(linkResultText(r))}</span>`;
        if (r.link_fail_count > 1) {
            resultado += ` <span class="link-fails">${r.link_fail_count} intentos</span>`;
        }
    }

    let acciones;
    if (isRedirect) {
        acciones = `<button class="btn-sm" data-link-adopt="${r.id}" title="Usar la dirección de destino">Adoptar</button>`;
    } else if (isManual) {
        acciones = `<button class="btn-sm" data-link-recheck="${r.id}" title="Volver a comprobar" aria-label="Volver a comprobar">↻</button>
                    <button class="btn-sm" data-link-unmark="${r.id}" title="Quitar la marca de vivo">Quitar marca</button>`;
    } else {
        acciones = `<button class="btn-sm" data-link-alive="${r.id}" title="Lo he comprobado y funciona" aria-label="Marcar como vivo">✓</button>
                    <button class="btn-sm" data-link-recheck="${r.id}" title="Volver a comprobar" aria-label="Volver a comprobar">↻</button>
                    <button class="btn-sm danger" data-link-delete="${r.id}" title="Mover a la papelera" aria-label="Mover a la papelera">🗑</button>`;
    }

    return `<tr>
        <td><input type="checkbox" class="link-check" value="${r.id}" aria-label="Seleccionar ${title}"></td>
        <td data-label="Enlace">
            <div class="bm-title">
                <a href="${url}" target="_blank" rel="noopener noreferrer" title="${url}">${title}</a>
            </div>
            <span class="pw-origin">${url}</span>
            <span class="folder-badge">${escHtml(r.folder_path || '/')}</span>
        </td>
        <td data-label="Resultado">${resultado}</td>
        <td data-label="Comprobado" style="color:var(--text-muted)">${r.link_checked_at ? fmtBatchDate(r.link_checked_at) : '—'}</td>
        <td>${acciones}</td>
    </tr>`;
}

function renderLinkPagination(total, page, perPage) {
    const pages = Math.ceil(total / perPage);
    const el    = document.getElementById('link-pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = `
        <button class="page-btn" id="link-prev" ${page <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span class="page-info">Página ${page} de ${pages} (${total} total)</span>
        <button class="page-btn" id="link-next" ${page >= pages ? 'disabled' : ''}>Siguiente →</button>`;
    document.getElementById('link-prev')?.addEventListener('click', () => { state.linkPage--; loadLinkTable(); });
    document.getElementById('link-next')?.addEventListener('click', () => { state.linkPage++; loadLinkTable(); });
}

async function recheckLinks(ids, btn) {
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    try {
        const res  = await fetch('api/links.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'recheck', ids }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        const r = data.results?.[0];
        const nombre = { alive: 'vivo', dubious: 'dudoso', dead: 'muerto' }[r?.status] ?? r?.status;
        const detalle = r?.code ? ` (HTTP ${r.code})` : (r?.error ? ` (${r.error})` : '');
        if (r?.manual && r.status !== 'alive') {
            toast(`La comprobación automática sigue dando ${nombre}${detalle}; tu marca de vivo se mantiene.`,
                  r.status === 'dead' ? 'warn' : 'info', null, 6000);
        } else {
            toast(`Comprobado: ${nombre}${detalle}`, r?.status === 'alive' ? 'success' : 'info');
        }
        await loadLinkSummary();
        await loadLinkTable();
    } catch (e) {
        toast(errorMsg(e), 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '↻'; }
    }
}

/** Verificado vivo a mano: las comprobaciones siguen, pero no pisan la marca. */
async function markLinksAlive(ids) {
    if (!ids.length) return;
    try {
        const res  = await fetch('api/links.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'mark_alive', ids }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        toast(`✓ ${data.marked} marcado${data.marked === 1 ? '' : 's'} como vivo${data.marked === 1 ? '' : 's'}`,
              'success', { label: 'Deshacer', onClick: () => unmarkLinks(ids, true) }, 10000);
        await Promise.all([loadLinkSummary(), loadLinkTable()]);
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

async function unmarkLinks(ids, silent = false) {
    if (!ids.length) return;
    try {
        const res  = await fetch('api/links.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'unmark', ids }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        toast(silent ? 'Marca deshecha' : `Marca quitada de ${data.unmarked}: vuelven a su resultado automático`, 'info');
        await Promise.all([loadLinkSummary(), loadLinkTable()]);
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

async function deleteLinks(ids) {
    if (!ids.length) return;
    const go = await confirmAction({
        title:   ids.length === 1 ? 'Mover a la papelera' : `Mover ${ids.length} a la papelera`,
        body:    `${ids.length === 1 ? 'Este marcador se moverá' : 'Estos marcadores se moverán'} a la papelera, `
               + 'donde podrás restaurarlos durante 30 días. Recuerda que esto no los borra de tu navegador.',
        okLabel: 'Mover a la papelera',
        variant: 'danger',
    });
    if (!go) return;

    try {
        const res  = await fetch('api/links.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'delete', ids, reason: 'enlace roto' }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        toast(`✓ ${data.deleted} en la papelera`, 'success');
        await Promise.all([loadLinkSummary(), loadLinkTable(), loadTrash(), loadStats()]);
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

async function adoptRedirects(ids) {
    if (!ids.length) return;
    const go = await confirmAction({
        title:   'Adoptar destino',
        body:    `Se cambiará la dirección de ${ids.length} marcador${ids.length === 1 ? '' : 'es'} por aquella `
               + 'a la que redirigen. Puedes deshacerlo desde «Últimos cambios aplicados».',
        okLabel: 'Adoptar',
    });
    if (!go) return;

    try {
        const res  = await fetch('api/links.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'adopt_redirect', ids }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        const extra = data.skipped ? ` · ${data.skipped} omitidos (ya existía ese destino)` : '';
        toast(`✓ ${data.updated} direcciones actualizadas${extra}`, 'success',
            data.batch_id ? { label: 'Deshacer', onClick: () => undoBatch(data.batch_id) } : null,
            data.batch_id ? 10000 : 3500);
        await Promise.all([loadLinkSummary(), loadLinkTable(), loadHistory(), loadTable()]);
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

/* ---------- Papelera ---------- */

async function loadTrash() {
    try {
        const res  = await fetch('api/trash.php');
        const data = await res.json();
        const box  = document.getElementById('trash-list');
        if (!box) return;

        document.getElementById('trash-count').textContent    = data.total;
        document.getElementById('trash-keep-days').textContent = data.keep_days;
        document.getElementById('trash-actions').style.display = data.total ? '' : 'none';

        if (!data.total) {
            box.innerHTML = '<div class="history-empty">La papelera está vacía.</div>';
            return;
        }

        box.innerHTML = data.items.map(i => `
            <div class="history-item">
                <span class="history-kind">${escHtml(i.reason || 'borrado')}</span>
                <span class="history-summary">
                    ${escHtml(i.title || i.url)}
                    <span class="pw-origin">${escHtml(i.url)}</span>
                </span>
                <span class="history-date">${fmtBatchDate(i.deleted_at)}</span>
                <button class="btn-sm" data-restore="${i.id}">Restaurar</button>
            </div>`).join('');

        box.querySelectorAll('[data-restore]').forEach(btn =>
            btn.addEventListener('click', () => restoreFromTrash([parseInt(btn.dataset.restore, 10)])));
    } catch (e) { /* accesorio */ }
}

async function restoreFromTrash(ids) {
    try {
        const res  = await fetch('api/trash.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'restore', ids }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');

        const extra = data.skipped ? ` · ${data.skipped} ya estaban` : '';
        toast(`✓ ${data.restored} restaurados${extra}`, 'success');
        await Promise.all([loadTrash(), loadLinkSummary(), loadLinkTable(), loadStats(), loadTable()]);
    } catch (e) {
        toast(errorMsg(e), 'error');
    }
}

function setupTrash() {
    document.getElementById('btn-trash-empty')?.addEventListener('click', async () => {
        const go = await confirmAction({
            title:   'Vaciar papelera',
            body:    'Los marcadores de la papelera se borrarán definitivamente. Esto no se puede deshacer.',
            okLabel: 'Vaciar',
            variant: 'danger',
        });
        if (!go) return;

        const res  = await fetch('api/trash.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({ action: 'empty' }),
        });
        const data = await res.json();
        if (data.error) return toast('Error: ' + data.error, 'error');
        toast(`Papelera vaciada (${data.purged})`, 'success');
        loadTrash();
    });
}

/* ---------- Deduplication ---------- */
function setupDeduplicate() {
    const btn    = document.getElementById('btn-deduplicate');
    const result = document.getElementById('dedup-result');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const go = await confirmAction({
            title:   'Eliminar duplicados',
            body:    'Se fusionarán los marcadores con la misma URL que están en Chrome y en Firefox. '
                   + 'Los ya categorizados tienen prioridad. Esta acción no se puede deshacer.',
            okLabel: 'Eliminar duplicados',
            variant: 'danger',
        });
        if (!go) return;

        btn.disabled    = true;
        btn.textContent = 'Procesando…';
        result.textContent = '';

        try {
            const res  = await fetch('api/deduplicate.php', { method: 'POST' });
            const data = await res.json();

            if (data.error) {
                toast('Error: ' + data.error, 'error');
                return;
            }

            result.textContent = data.removed > 0
                ? `${data.removed} duplicado(s) eliminado(s).`
                : 'No se encontraron duplicados.';
            toast(result.textContent, data.removed > 0 ? 'success' : 'info');
            loadStats();
            loadTable();
        } catch (e) {
            toast(errorMsg(e), 'error');
        } finally {
            btn.disabled    = false;
            btn.textContent = 'Eliminar duplicados entre Chrome y Firefox';
        }
    });
}

/* ---------- ESC closes any open modal ---------- */
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const catModal    = document.getElementById('cat-modal');
    const homologModal = document.getElementById('homolog-modal');
    if (catModal?.style.display !== 'none' && catModal?.style.display !== '') closeCatModal();
    else if (homologModal?.style.display !== 'none' && homologModal?.style.display !== '') closeHomologModal();
});

/* ---------- Init ---------- */
document.addEventListener('DOMContentLoaded', () => {
    setupTabs();
    syncTabAria();
    enableDropZoneKeyboard();
    setupSearch();
    setupDropZones();
    setupExportButtons();
    setupCategorize();
    setupDeduplicate();
    setupHomolog();
    setupLinkCheck();
    setupTrash();
    checkApiKey();
    pollJob(true);   // retoma un job en marcha tras recargar
    loadHistory();
    loadLinkSummary();
    loadLinkTable();
    loadTrash();
    loadStats();
    loadTable();
});

/* ---------- Utils ---------- */
function escHtml(str) {
    return String(str)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;');
}
