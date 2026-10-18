/* =====================================================
   Sync Bookmarks — Contraseñas (passwords.php)
   Misma estructura que app.js pero con bóveda: nada se
   pide al servidor hasta que está desbloqueada, y cualquier
   401 devuelve a la pantalla de bloqueo.
   ===================================================== */

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

const state = {
    tab: 'all',
    page: 1,
    search: '',
    unlocked: false,
    dedupGroups: [],
    dedupKeep: null,     // selección del modal de duplicados, por si expira la sesión
    expiryTimer: null,
    countdownTimer: null,
};

/* ---------- Helpers ---------- */
function toast(msg, type = 'success') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className = '', 3500);
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function fmtDate(ts) {
    if (!ts) return '—';
    return new Date(ts * 1000).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
}

/** fetch con CSRF; si la bóveda se cerró (401) vuelve al gate y lanza. */
async function api(url, opts = {}) {
    opts.headers = Object.assign({ 'X-CSRF-Token': CSRF }, opts.headers || {});
    if (opts.json !== undefined) {
        opts.method  = opts.method || 'POST';
        opts.body    = JSON.stringify(opts.json);
        opts.headers['Content-Type'] = 'application/json';
        delete opts.json;
    }
    const res  = await fetch(url, opts);
    const data = await res.json().catch(() => ({ error: 'Respuesta no válida del servidor' }));
    if (res.status === 401 && data.error === 'locked') {
        saveDedupSelection();
        showGate('unlock');
        toast('La bóveda se ha bloqueado. Vuelve a introducir la contraseña maestra.', 'error');
        throw new Error('locked');
    }
    return { ok: res.ok, status: res.status, data };
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

/* ---------- Confirmación reutilizable ---------- */
function confirmAction({ title = '¿Continuar?', body = '', okLabel = 'Continuar', variant = 'primary' }) {
    return new Promise(resolve => {
        const modal = document.getElementById('confirm-modal');
        const ok    = document.getElementById('confirm-ok');

        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-body').textContent  = body;
        ok.textContent = okLabel;
        ok.className   = variant === 'danger' ? 'btn-danger' : 'btn-primary';

        const release = trapFocus(modal);
        const close = result => {
            modal.style.display = 'none';
            release();
            modal.querySelectorAll('[data-confirm-close]').forEach(el => el.removeEventListener('click', onCancel));
            ok.removeEventListener('click', onOk);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        };
        const onOk     = () => close(true);
        const onCancel = () => close(false);
        const onKey    = e => { if (e.key === 'Escape') { e.stopPropagation(); close(false); } };

        modal.querySelectorAll('[data-confirm-close]').forEach(el => el.addEventListener('click', onCancel));
        ok.addEventListener('click', onOk);
        document.addEventListener('keydown', onKey);

        modal.style.display = 'flex';
    });
}

/* ---------- Indicador de carga sobre la tabla ---------- */
function setTableLoading(on) {
    const wrap = document.querySelector('.table-wrap');
    if (!wrap) return;
    let box = wrap.querySelector('.table-loading');
    if (on) {
        if (!box) {
            box = document.createElement('div');
            box.className = 'table-loading';
            box.innerHTML = '<div class="spinner"></div>';
            wrap.appendChild(box);
        }
    } else if (box) {
        box.remove();
    }
}

/* ---------- Gate (crear / desbloquear) ---------- */
function showGate(mode) {
    state.unlocked = false;
    clearTimeout(state.expiryTimer);
    clearInterval(state.countdownTimer);
    state.releaseExpiryFocus?.(); state.releaseExpiryFocus = null;
    document.getElementById('expiry-modal').style.display = 'none';
    document.getElementById('vault-app').style.display  = 'none';
    document.getElementById('vault-gate').style.display = '';
    document.getElementById('btn-lock').style.display   = 'none';
    document.getElementById('gate-loading').style.display = 'none';
    document.getElementById('gate-create').style.display  = mode === 'create' ? '' : 'none';
    document.getElementById('gate-unlock').style.display  = mode === 'unlock' ? '' : 'none';
    if (mode === 'unlock') {
        const inp = document.getElementById('unlock-master');
        inp.value = '';
        setTimeout(() => inp.focus(), 50);
    }
}

async function showApp() {
    state.unlocked = true;
    document.getElementById('vault-gate').style.display = 'none';
    document.getElementById('vault-app').style.display  = '';
    document.getElementById('btn-lock').style.display   = '';
    scheduleExpiryWarning();
    await loadTable();
    loadExportCounts();
    restoreDedupSelection();
}

/* La bóveda se cierra sola tras IDLE_SECONDS; avisamos 2 min antes para no
   perder lo que esté a medias (p. ej. la selección del modal de duplicados). */
const EXPIRY_WARNING_MS = 2 * 60 * 1000;

function scheduleExpiryWarning() {
    clearTimeout(state.expiryTimer);
    const idleMs = (state.idleSeconds ?? 900) * 1000;
    state.expiryTimer = setTimeout(showExpiryWarning, Math.max(5000, idleMs - EXPIRY_WARNING_MS));
}

function showExpiryWarning() {
    if (!state.unlocked) return;
    const modal = document.getElementById('expiry-modal');
    const out   = document.getElementById('expiry-countdown');
    let left    = Math.floor(EXPIRY_WARNING_MS / 1000);

    const tick = () => {
        out.textContent = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
        if (left-- <= 0) {
            clearInterval(state.countdownTimer);
            modal.style.display = 'none';
            showGate('unlock');
            toast('La bóveda se bloqueó por inactividad.', 'warn');
        }
    };
    tick();
    clearInterval(state.countdownTimer);
    state.countdownTimer = setInterval(tick, 1000);
    modal.style.display = 'flex';
    state.releaseExpiryFocus = trapFocus(modal);
}

function hideExpiryWarning() {
    clearInterval(state.countdownTimer);
    state.releaseExpiryFocus?.(); state.releaseExpiryFocus = null;
    document.getElementById('expiry-modal').style.display = 'none';
}

function setupExpiry() {
    document.getElementById('expiry-stay').addEventListener('click', async () => {
        hideExpiryWarning();
        // Cualquier petición refresca vault_last en el servidor
        const { data } = await api('api/vault.php?status', { method: 'GET' });
        if (!data.unlocked) return showGate('unlock');
        scheduleExpiryWarning();
        toast('Sesión renovada');
    });
    document.getElementById('expiry-lock').addEventListener('click', async () => {
        hideExpiryWarning();
        await api('api/vault.php', { json: { action: 'lock' } });
        showGate('unlock');
    });
}

async function checkVault() {
    try {
        const { data } = await api('api/vault.php?status', { method: 'GET' });
        state.idleSeconds = data.idle_seconds || 900;
        document.getElementById('idle-min').textContent = Math.round(state.idleSeconds / 60);
        if (!data.exists)        return showGate('create');
        if (!data.unlocked)      return showGate('unlock');
        await showApp();
    } catch (e) {
        if (e.message !== 'locked') toast('No se pudo comprobar la bóveda', 'error');
    }
}

function setupGate() {
    document.getElementById('gate-create').addEventListener('submit', async e => {
        e.preventDefault();
        const a = document.getElementById('create-master').value;
        const b = document.getElementById('create-master2').value;
        if (a !== b) return toast('Las contraseñas no coinciden', 'error');
        const { ok, data } = await api('api/vault.php', { json: { action: 'create', master: a } });
        if (!ok) return toast(data.error || 'No se pudo crear la bóveda', 'error');
        toast('Bóveda creada');
        await showApp();
    });

    document.getElementById('gate-unlock').addEventListener('submit', async e => {
        e.preventDefault();
        const err = document.getElementById('unlock-error');
        err.textContent = '';
        const master = document.getElementById('unlock-master').value;
        const { ok, data } = await api('api/vault.php', { json: { action: 'unlock', master } });
        if (!ok) {
            err.textContent = data.error || 'Contraseña incorrecta';
            document.getElementById('unlock-master').select();
            return;
        }
        await showApp();
    });

    document.getElementById('btn-lock').addEventListener('click', async () => {
        await api('api/vault.php', { json: { action: 'lock' } });
        showGate('unlock');
        toast('Bóveda bloqueada');
    });
}

/* ---------- Stats ---------- */
function updateStats(counts) {
    document.getElementById('stat-only-chrome').textContent  = counts.only_chrome  ?? '—';
    document.getElementById('stat-only-firefox').textContent = counts.only_firefox ?? '—';
    document.getElementById('stat-both').textContent         = counts.in_both      ?? '—';
    document.getElementById('stat-conflicts').textContent    = counts.conflicts    ?? '—';

    const map = {
        all: counts.all, only_chrome: counts.only_chrome, only_firefox: counts.only_firefox,
        in_both: counts.in_both, conflicts: counts.conflicts,
    };
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const badge = btn.querySelector('.tab-badge');
        if (badge && map[btn.dataset.tab] !== undefined) badge.textContent = map[btn.dataset.tab];
    });
}

/* ---------- Tabla ---------- */
async function loadTable() {
    if (!state.unlocked) return;
    const params = new URLSearchParams({ tab: state.tab, page: state.page, search: state.search });
    setTableLoading(true);
    try {
        const { ok, data } = await api('api/passwords_sync.php?' + params, { method: 'GET' });
        if (!ok) return toast(data.error || 'Error al cargar', 'error');
        updateStats(data.counts);
        renderConflictsNote(data.conflicts_outside ?? 0);
        state.tab === 'conflicts'
            ? renderConflicts(data.items, data.total, data.page, data.per_page)
            : renderTable(data.items, data.total, data.page, data.per_page);
    } catch (e) {
        if (e.message !== 'locked') toast('Error de conexión', 'error');
    } finally {
        setTableLoading(false);
    }
}

/* Los conflictos no salen en las demás pestañas: avisamos para que no pasen desapercibidos */
function renderConflictsNote(n) {
    const note = document.getElementById('conflicts-note');
    if (!n) { note.style.display = 'none'; return; }
    note.innerHTML = `⚠️ Hay <strong>${n}</strong> credencial${n === 1 ? '' : 'es'} en conflicto que no se `
        + `muestra${n === 1 ? '' : 'n'} en esta pestaña. <button type="button" id="go-conflicts">Ver conflictos</button>`;
    note.style.display = 'flex';
    document.getElementById('go-conflicts').addEventListener('click', () => {
        document.querySelector('.tab-btn[data-tab="conflicts"]')?.click();
    });
}

function emptyRow(msg) {
    return `<tr><td colspan="6"><div class="empty-state"><div class="icon">🔐</div><p>${escHtml(msg)}</p></div></td></tr>`;
}

function siteCell(r) {
    const android = r.is_android ? ' <span class="pw-android">app Android</span>' : '';
    return `<div class="pw-site">
        <span class="pw-name">${escHtml(r.name)}${android}</span>
        <span class="pw-origin">${escHtml(r.origin)}${r.http_realm ? ' · ' + escHtml(r.http_realm) : ''}</span>
    </div>`;
}

function passwordCell(id, hasNote = false) {
    return `<div class="pw-cell">
        <span class="pw-mask" data-id="${id}">••••••••</span>
        <button class="btn-sm" data-reveal="${id}" title="Mostrar durante 10 s" aria-label="Mostrar contraseña">👁</button>
        <button class="btn-sm" data-copy="${id}" title="Copiar al portapapeles" aria-label="Copiar contraseña">📋</button>
        ${hasNote ? '<span class="pw-note" data-note="' + id + '" hidden></span>' : ''}
    </div>`;
}

function sourceCell(src) {
    const label = { chrome: 'Chrome', firefox: 'Firefox', both: 'Ambos' }[src] || src;
    return `<span class="source-dot ${src}"></span> ${label}`;
}

function renderTable(items, total, page, perPage) {
    const tbody = document.getElementById('pw-tbody');
    if (!items.length) {
        tbody.innerHTML = emptyRow(state.search ? 'Sin resultados para la búsqueda' : 'No hay contraseñas en esta pestaña');
        renderPagination(0, 1, perPage);
        return;
    }
    tbody.innerHTML = items.map(r => `
        <tr>
            <td data-label="Sitio">${siteCell(r)}</td>
            <td data-label="Usuario" class="pw-user">${escHtml(r.username) || '<span class="muted">(sin usuario)</span>'}</td>
            <td data-label="Contraseña">${passwordCell(r.id, r.has_note)}</td>
            <td data-label="Fuente">${sourceCell(r.source)}</td>
            <td data-label="Cambiada">${fmtDate(r.time_password_changed)}</td>
            <td><button class="btn-sm danger" data-delete="${r.id}"
                        data-host="${escHtml(r.host)}" data-user="${escHtml(r.username)}"
                        title="Borrar esta credencial" aria-label="Borrar credencial">🗑</button></td>
        </tr>`).join('');
    renderPagination(total, page, perPage);
}

function renderConflicts(items, total, page, perPage) {
    const tbody = document.getElementById('pw-tbody');
    if (!items.length) {
        tbody.innerHTML = emptyRow('Sin conflictos: donde coinciden sitio y usuario, la contraseña es la misma');
        renderPagination(0, 1, perPage);
        return;
    }
    tbody.innerHTML = items.map(c => {
        const sub = (r, isWinner) => `
            <tr class="conflict-sub ${isWinner ? 'winner' : ''}">
                <td data-label="Fuente">${sourceCell(r.source)}<div class="pw-origin">${escHtml(r.origin)}</div></td>
                <td></td>
                <td data-label="Contraseña">${passwordCell(r.id, r.has_note)}</td>
                <td><button class="btn-sm ${isWinner && c.resolved ? 'active' : ''}" data-prefer="${r.id}">
                    ${isWinner ? (c.resolved ? 'Elegida' : 'Ganaría por defecto') : 'Usar esta'}
                </button></td>
                <td data-label="Cambiada">${fmtDate(r.time_password_changed)}</td>
                <td><button class="btn-sm danger" data-delete="${r.id}"
                            data-host="${escHtml(c.host)}" data-user="${escHtml(c.username)}"
                            title="Borrar esta credencial" aria-label="Borrar credencial">🗑</button></td>
            </tr>`;
        return `
            <tr class="conflict-head">
                <td colspan="6">${escHtml(c.host)} <span class="pw-user">${escHtml(c.username)}</span></td>
            </tr>
            ${sub(c.chrome,  c.winner === 'chrome')}
            ${sub(c.firefox, c.winner === 'firefox')}`;
    }).join('');
    renderPagination(total, page, perPage);
}

function renderPagination(total, page, perPage) {
    const pages = Math.ceil(total / perPage);
    const el    = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = `
        <button class="page-btn" id="btn-prev" ${page <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span class="page-info">Página ${page} de ${pages} (${total} total)</span>
        <button class="page-btn" id="btn-next" ${page >= pages ? 'disabled' : ''}>Siguiente →</button>`;
    document.getElementById('btn-prev')?.addEventListener('click', () => { state.page--; loadTable(); });
    document.getElementById('btn-next')?.addEventListener('click', () => { state.page++; loadTable(); });
}

/* Revelar / copiar: compartido por la tabla principal y el modal de duplicados */
async function handleRevealCopy(btn) {
    const id   = btn.dataset.reveal || btn.dataset.copy;
    const cell = btn.closest('.pw-cell');

    // Segundo clic en 👁 mientras está visible: ocultar ya
    if (btn.dataset.reveal && cell.querySelector('.pw-mask').classList.contains('revealed')) {
        hidePassword(cell);
        return;
    }

    const { ok, data } = await api('api/passwords_manage.php', { json: { action: 'reveal', id: Number(id) } });
    if (!ok) return toast(data.error || 'No se pudo descifrar', 'error');

    if (btn.dataset.copy) {
        try {
            await navigator.clipboard.writeText(data.password);
            toast('Contraseña copiada (bórrala del portapapeles cuando la uses)');
        } catch { toast('El navegador no permitió copiar', 'error'); }
        return;
    }

    const mask = cell.querySelector('.pw-mask');
    mask.textContent = data.password;
    mask.classList.add('revealed');

    const note = cell.querySelector('.pw-note');
    if (note && data.note) {
        note.textContent = '📝 ' + data.note;
        note.hidden = false;
    }

    // Cuenta atrás visible en el propio botón
    let left = REVEAL_SECONDS;
    const eye = cell.querySelector('[data-reveal]');
    eye.classList.add('pw-countdown');
    eye.textContent = left;
    clearInterval(cell._t);
    cell._t = setInterval(() => {
        if (--left <= 0) return hidePassword(cell);
        eye.textContent = left;
    }, 1000);
}

const REVEAL_SECONDS = 10;

function hidePassword(cell) {
    clearInterval(cell._t);
    const mask = cell.querySelector('.pw-mask');
    mask.textContent = '••••••••';
    mask.classList.remove('revealed');

    const eye = cell.querySelector('[data-reveal]');
    eye.classList.remove('pw-countdown');
    eye.textContent = '👁';

    const note = cell.querySelector('.pw-note');
    if (note) { note.hidden = true; note.textContent = ''; }
}

/* Acciones por delegación en la tabla principal: revelar, copiar, preferir */
function setupTableActions() {
    document.getElementById('pw-tbody').addEventListener('click', async e => {
        const btn = e.target.closest('button');
        if (!btn) return;

        if (btn.dataset.reveal || btn.dataset.copy) return handleRevealCopy(btn);

        if (btn.dataset.prefer) {
            const { ok, data } = await api('api/passwords_manage.php', { json: { action: 'prefer', id: Number(btn.dataset.prefer) } });
            if (!ok) return toast(data.error || 'No se pudo guardar', 'error');
            toast('Ganador guardado');
            await loadTable();
            return;
        }

        if (btn.dataset.delete) return deleteCredential(btn);
    });
}

async function deleteCredential(btn) {
    const who = btn.dataset.user ? `${btn.dataset.host} · ${btn.dataset.user}` : btn.dataset.host;
    const go  = await confirmAction({
        title:   'Borrar credencial',
        body:    `Se borrará «${who}» de la bóveda. No afecta a lo que tengas guardado en el navegador, `
               + 'pero aquí no se puede deshacer.',
        okLabel: 'Borrar',
        variant: 'danger',
    });
    if (!go) return;

    const { ok, data } = await api('api/passwords_manage.php', { json: { action: 'delete', ids: [Number(btn.dataset.delete)] } });
    if (!ok) return toast(data.error || 'No se pudo borrar', 'error');
    toast('✓ Credencial borrada');
    await loadTable();
    loadExportCounts();
}

/* ---------- Conteos de exportación ---------- */
async function loadExportCounts() {
    if (!state.unlocked) return;
    try {
        const { ok, data } = await api('api/passwords_export.php?counts=1', { method: 'GET' });
        if (!ok) return;
        document.querySelectorAll('[data-count]').forEach(el => {
            const n = data.counts?.[el.dataset.count];
            if (n === undefined) return;
            el.textContent = n;
            el.closest('.export-btn')?.classList.toggle('is-empty', n === 0);
        });
    } catch (e) { /* accesorio */ }
}

/* ---------- Tabs / búsqueda ---------- */
function setupTabs() {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            state.tab  = btn.dataset.tab;
            state.page = 1;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            syncTabAria();
            loadTable();
        });
    });
}

function setupSearch() {
    const input = document.getElementById('search-input');
    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => { state.search = input.value; state.page = 1; loadTable(); }, 300);
    });
}

/* ---------- Import ---------- */
function setupDropZones() {
    ['chrome', 'firefox'].forEach(source => {
        const zone  = document.getElementById(`drop-${source}`);
        const input = document.getElementById(`file-${source}`);
        const info  = zone.querySelector('.file-info');

        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (e.dataTransfer.files[0]) uploadFile(e.dataTransfer.files[0], source, info);
        });
        input.addEventListener('change', () => {
            if (input.files[0]) uploadFile(input.files[0], source, info);
            input.value = '';
        });
    });
}

async function uploadFile(file, source, infoEl) {
    const form = new FormData();
    form.append('file', file);
    form.append('source', source);
    infoEl.textContent = `Importando "${file.name}"…`;
    infoEl.style.display = 'block';

    try {
        const { ok, data } = await api('api/passwords_import.php', { method: 'POST', body: form });
        if (!ok || data.error) {
            toast('Error: ' + (data.error || 'importación fallida'), 'error');
            infoEl.textContent = 'Error en la importación';
            return;
        }
        const parts = [];
        if (data.added)     parts.push(`${data.added} nuevas`);
        if (data.updated)   parts.push(`${data.updated} actualizadas`);
        if (data.unchanged) parts.push(`${data.unchanged} sin cambios`);
        if (data.skipped)   parts.push(`${data.skipped} omitidas`);
        const summary = parts.join(' · ') || 'sin cambios';
        toast(`✓ ${source}: ${summary}`);
        infoEl.textContent = `✓ ${data.in_file} en archivo · ${summary}`;
        await loadTable();
        loadExportCounts();
    } catch (e) {
        if (e.message !== 'locked') { toast('Error de conexión', 'error'); infoEl.textContent = 'Error de conexión'; }
    }
}

/* ---------- Export ---------- */
function setupExport() {
    // Comprobar que la bóveda sigue abierta antes de navegar; si no, la descarga sería un JSON de error.
    document.querySelectorAll('[data-export]').forEach(a => {
        a.addEventListener('click', async e => {
            e.preventDefault();
            if (a.classList.contains('is-empty')) {
                return toast('No hay nada que exportar en esa combinación.', 'info');
            }
            try {
                const { data } = await api('api/vault.php?status', { method: 'GET' });
                if (!data.unlocked) return showGate('unlock');
                scheduleExpiryWarning();
                window.location = a.href;
            } catch { /* ya gestionado */ }
        });
    });
}

/* ---------- Dedup ---------- */
const modalReleases = {};

function openModal(id) {
    const modal = document.getElementById(id);
    modal.style.display = 'flex';
    modalReleases[id] = trapFocus(modal);
}
function closeModal(id) {
    modalReleases[id]?.(); delete modalReleases[id];
    document.getElementById(id).style.display = 'none';
}

function setupModals() {
    document.querySelectorAll('[data-close]').forEach(el =>
        el.addEventListener('click', () => closeModal(el.dataset.close)));
}

function setupDedup() {
    document.getElementById('btn-dedup').addEventListener('click', async () => {
        const btn = document.getElementById('btn-dedup');
        btn.disabled = true;
        try {
            const { ok, data } = await api('api/passwords_dedup.php', { method: 'GET' });
            if (!ok) return toast(data.error || 'Error', 'error');
            state.dedupGroups = data.groups;
            document.getElementById('dedup-result').textContent =
                data.total ? `${data.total} grupos · ${data.total_rows} filas` : 'Sin duplicados';
            if (!data.total) return toast('No hay duplicados dentro de un mismo navegador');
            renderDedup(data.groups);
            openModal('dedup-modal');
        } catch (e) {
            if (e.message !== 'locked') toast('Error de conexión', 'error');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('dedup-apply').addEventListener('click', async () => {
        const ids = [];
        document.querySelectorAll('#dedup-body .dedup-group').forEach(g => {
            const keep = g.querySelector('input[type=radio]:checked')?.value;
            g.querySelectorAll('input[type=radio]').forEach(r => { if (r.value !== keep) ids.push(Number(r.value)); });
        });
        if (!ids.length) return closeModal('dedup-modal');
        const { ok, data } = await api('api/passwords_manage.php', { json: { action: 'delete', ids } });
        if (!ok) return toast(data.error || 'No se pudo borrar', 'error');
        toast(`✓ ${data.deleted} duplicados borrados`);
        closeModal('dedup-modal');
        state.dedupKeep = null;
        document.getElementById('dedup-result').textContent = `${data.deleted} borrados`;
        await loadTable();
        loadExportCounts();
    });
}

function dedupTags(r) {
    const tags = [];
    const https = r.origin.startsWith('https://');
    tags.push(`<span class="dedup-tag ${https ? 'good' : 'warn'}">${https ? 'https' : 'http'}</span>`);
    if (r.url.includes('://www.')) tags.push('<span class="dedup-tag">www</span>');
    const port = r.origin.match(/:(\d+)$/);
    if (port) tags.push(`<span class="dedup-tag">puerto ${port[1]}</span>`);
    if (r.http_realm) tags.push(`<span class="dedup-tag">realm: ${escHtml(r.http_realm)}</span>`);
    if (r.has_note) tags.push('<span class="dedup-tag">con nota</span>');
    if (r.is_android) tags.push('<span class="dedup-tag">app Android</span>');
    return `<div class="dedup-tags">${tags.join('')}</div>`;
}

/* Si la bóveda se cierra a mitad de la revisión de duplicados, guardamos lo elegido
   para reabrir el modal igual tras desbloquear. */
function saveDedupSelection() {
    const modal = document.getElementById('dedup-modal');
    if (!modal || modal.style.display !== 'flex' || !state.dedupGroups.length) return;

    const keep = {};
    document.querySelectorAll('#dedup-body .dedup-group').forEach(g => {
        const sel = g.querySelector('input[type=radio]:checked');
        if (sel) keep[g.dataset.group] = sel.value;
    });
    state.dedupKeep = { groups: state.dedupGroups, keep };
    closeModal('dedup-modal');
}

function restoreDedupSelection() {
    const saved = state.dedupKeep;
    if (!saved) return;
    state.dedupKeep = null;

    state.dedupGroups = saved.groups;
    renderDedup(saved.groups);
    Object.entries(saved.keep).forEach(([gi, id]) => {
        const radio = document.querySelector(`#dedup-body .dedup-group[data-group="${gi}"] input[value="${id}"]`);
        if (radio) radio.checked = true;
    });
    updateDedupFate();
    openModal('dedup-modal');
    toast('Recuperada tu selección de duplicados', 'info');
}

function renderDedup(groups) {
    document.getElementById('dedup-summary').textContent =
        `${groups.length} grupo${groups.length === 1 ? '' : 's'}`;

    document.getElementById('dedup-body').innerHTML = groups.map((g, gi) => `
        <div class="dedup-group" data-group="${gi}">
            <div class="dedup-group-head">
                ${sourceCell(g.source)}
                <strong>${escHtml(g.host)}</strong>
                <span class="pw-user">${escHtml(g.username) || '(sin usuario)'}</span>
                ${g.same_password
                    ? '<span class="dedup-badge-ok">misma contraseña</span>'
                    : '<span class="hom-badge-warn">contraseñas distintas — revisa con 👁</span>'}
                <span class="count">${g.rows.length} entradas</span>
            </div>
            <table class="dedup-table">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>URL guardada</th>
                        <th>Detalles</th>
                        <th>Cambiada</th>
                        ${g.same_password ? '' : '<th>Contraseña</th>'}
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    ${g.rows.map((r, ri) => `
                        <tr class="${ri === 0 ? 'keep' : ''}" data-id="${r.id}">
                            <td><input type="radio" name="dedup-${gi}" value="${r.id}" ${ri === 0 ? 'checked' : ''}></td>
                            <td><span class="dedup-url">${escHtml(r.url)}</span></td>
                            <td>${dedupTags(r)}</td>
                            <td>${fmtDate(r.time_password_changed)}</td>
                            ${g.same_password ? '' : `<td>${passwordCell(r.id)}</td>`}
                            <td><span class="dedup-fate ${ri === 0 ? 'keep' : 'remove'}">${ri === 0 ? '✓ conservar' : '✕ borrar'}</span></td>
                        </tr>`).join('')}
                </tbody>
            </table>
        </div>`).join('');

    updateDedupFate();
}

/** Actualiza clases/etiquetas de cada fila según el radio marcado y el resumen del pie. */
function updateDedupFate() {
    let remove = 0;
    document.querySelectorAll('#dedup-body .dedup-group').forEach(g => {
        const keep = g.querySelector('input[type=radio]:checked')?.value;
        g.querySelectorAll('tbody tr').forEach(tr => {
            const isKeep = tr.dataset.id === keep;
            tr.classList.toggle('keep', isKeep);
            const fate = tr.querySelector('.dedup-fate');
            fate.className   = 'dedup-fate ' + (isKeep ? 'keep' : 'remove');
            fate.textContent = isKeep ? '✓ conservar' : '✕ borrar';
            if (!isKeep) remove++;
        });
    });
    document.getElementById('dedup-fate-summary').textContent =
        remove ? `Se borrarán ${remove} entrada${remove === 1 ? '' : 's'}` : 'Nada que borrar';
    document.getElementById('dedup-apply').disabled = remove === 0;
}

function setupDedupTable() {
    const body = document.getElementById('dedup-body');
    // Clic en cualquier parte de la fila (salvo botones) marca su radio
    body.addEventListener('click', e => {
        const btn = e.target.closest('button');
        if (btn && (btn.dataset.reveal || btn.dataset.copy)) return handleRevealCopy(btn);
        const tr = e.target.closest('tbody tr');
        if (!tr) return;
        const radio = tr.querySelector('input[type=radio]');
        if (radio && !radio.checked) { radio.checked = true; updateDedupFate(); }
    });
    body.addEventListener('change', e => {
        if (e.target.matches('input[type=radio]')) updateDedupFate();
    });
}

/* ---------- Destruir bóveda ---------- */
function setupDestroy() {
    document.getElementById('btn-destroy').addEventListener('click', () => {
        document.getElementById('destroy-master').value = '';
        openModal('destroy-modal');
    });
    document.getElementById('destroy-form').addEventListener('submit', async e => {
        e.preventDefault();
        const master = document.getElementById('destroy-master').value;
        const { ok, data } = await api('api/vault.php', { json: { action: 'destroy', master } });
        if (!ok) return toast(data.error || 'No se pudo destruir', 'error');
        closeModal('destroy-modal');
        toast('Bóveda destruida');
        showGate('create');
    });
}

/* ESC cierra el modal abierto (el de confirmación se gestiona solo) */
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('confirm-modal').style.display === 'flex') return;
    ['dedup-modal', 'destroy-modal'].forEach(id => {
        if (document.getElementById(id).style.display === 'flex') closeModal(id);
    });
});

/* ---------- Init ---------- */
document.addEventListener('DOMContentLoaded', () => {
    setupExpiry();
    setupGate();
    setupTabs();
    syncTabAria();
    enableDropZoneKeyboard();
    setupSearch();
    setupDropZones();
    setupTableActions();
    setupExport();
    setupModals();
    setupDedup();
    setupDedupTable();
    setupDestroy();
    checkVault();
});
