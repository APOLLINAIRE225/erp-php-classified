/**
 * ════════════════════════════════════════════════════════
 * ADMIN ATTENDANCE VIEWER — Scripts JS
 * ESPERANCE H2O · Fichier 2/3
 * ════════════════════════════════════════════════════════
 * ✅ Navigation onglets (page + card)
 * ✅ Recherche live
 * ✅ Vue grille / liste
 * ✅ Auto-refresh configurable
 * ✅ Charts (présence, horaires, retards)
 * ✅ Distance GPS Haversine
 * ✅ Fullscreen selfie modal
 * ✅ Toast notifications
 * ✅ Export CSV
 * ✅ Horloge live
 * ✅ Raccourcis clavier
 */

/* ═══════════════════════════════════
   🕐 HORLOGE
═══════════════════════════════════ */
function tickClock() {
    const n = new Date();
    const el = document.getElementById('clk');
    const ed = document.getElementById('clkd');
    if (el) el.textContent = n.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    if (ed) ed.textContent = n.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tickClock();
setInterval(tickClock, 1000);

/* ═══════════════════════════════════
   🔔 TOAST
═══════════════════════════════════ */
function toast(msg, type = 'info', sub = '') {
    const colors  = {success:'var(--neon)', error:'var(--red)', info:'var(--cyan)', warn:'var(--gold)'};
    const icons   = {success:'fa-check-circle', error:'fa-times-circle', info:'fa-info-circle', warn:'fa-exclamation-triangle'};
    const stack   = document.getElementById('toast-stack');
    if (!stack) return;
    const t = document.createElement('div');
    t.className = 'toast';
    t.innerHTML = `
        <div class="toast-ico" style="background:${colors[type]}22;color:${colors[type]}">
            <i class="fas ${icons[type]}"></i>
        </div>
        <div class="toast-txt">
            <strong style="color:${colors[type]}">${msg}</strong>
            ${sub ? `<span>${sub}</span>` : ''}
        </div>`;
    stack.appendChild(t);
    setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 350); }, 4200);
}

/* ═══════════════════════════════════
   📑 PAGE TABS (Jour / Semaine / Stats / Absents / Export)
═══════════════════════════════════ */
function switchPageTab(name) {
    document.querySelectorAll('.page-tn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelector(`.page-tn[data-tab="${name}"]`)?.classList.add('active');
    document.getElementById(`panel-${name}`)?.classList.add('active');
    // Lazy init charts when switching to stats
    if (name === 'stats') initCharts();
    if (name === 'absents') renderAbsents();
    if (name === 'week') renderWeekTable();
}

/* ═══════════════════════════════════
   📇 CARD TABS (Arrivée / Départ / Comparer)
═══════════════════════════════════ */
function switchCardTab(attId, section) {
    const card = document.querySelector(`[data-att="${attId}"]`);
    if (!card) return;
    card.querySelectorAll('.ct').forEach(b => b.classList.remove('active'));
    card.querySelectorAll('.c-panel').forEach(p => p.classList.remove('active'));
    card.querySelector(`.ct[data-panel="${section}"]`)?.classList.add('active');
    card.querySelector(`.c-panel[data-panel="${section}"]`)?.classList.add('active');
}

/* ═══════════════════════════════════
   🔍 RECHERCHE LIVE
═══════════════════════════════════ */
function liveSearch() {
    const q = (document.getElementById('live-search')?.value || '').toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.att-card').forEach(card => {
        const name = card.dataset.name || '';
        const code = card.dataset.code || '';
        const cat  = card.dataset.cat  || '';
        const show = !q || name.includes(q) || code.includes(q) || cat.includes(q);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const info = document.getElementById('search-count');
    if (info) info.textContent = q ? `${visible} résultat(s)` : '';
}

/* ═══════════════════════════════════
   🔲 VUE GRILLE / LISTE
═══════════════════════════════════ */
let listMode = false;
function toggleView() {
    listMode = !listMode;
    const grid = document.getElementById('att-grid');
    const btn  = document.getElementById('view-btn');
    if (!grid) return;
    if (listMode) {
        grid.classList.add('list-mode');
        document.querySelectorAll('.att-card').forEach(c => c.classList.add('list-mode'));
        if (btn) btn.innerHTML = '<i class="fas fa-th"></i> Grille';
    } else {
        grid.classList.remove('list-mode');
        document.querySelectorAll('.att-card').forEach(c => c.classList.remove('list-mode'));
        if (btn) btn.innerHTML = '<i class="fas fa-list"></i> Liste';
    }
}

/* ═══════════════════════════════════
   📍 DISTANCE GPS (Haversine)
═══════════════════════════════════ */
function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371000; // mètres
    const toRad = d => d * Math.PI / 180;
    const dLat  = toRad(lat2 - lat1);
    const dLon  = toRad(lon2 - lon1);
    const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon/2)**2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return Math.round(R * c);
}

function renderDistances() {
    document.querySelectorAll('[data-dist-target]').forEach(el => {
        const lat1 = parseFloat(el.dataset.lat1 || 0);
        const lon1 = parseFloat(el.dataset.lon1 || 0);
        const lat2 = parseFloat(el.dataset.lat2 || 0);
        const lon2 = parseFloat(el.dataset.lon2 || 0);
        if (lat1 && lon1 && lat2 && lon2) {
            const d = haversine(lat1, lon1, lat2, lon2);
            const label = d < 50 ? '✅ Même zone' : d < 500 ? '⚠️ Légèrement éloigné' : '🔴 Position différente';
            el.innerHTML = `<i class="fas fa-ruler-horizontal"></i> ${d}m entre arrivée et départ — ${label}`;
        }
    });
}

/* ═══════════════════════════════════
   🖼️ FULLSCREEN SELFIE
═══════════════════════════════════ */
function openFullscreen(modalId) {
    const m = document.getElementById(modalId);
    if (m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeFullscreen(modalId) {
    const m = document.getElementById(modalId);
    if (m) { m.classList.remove('show'); document.body.style.overflow = ''; }
}

/* ═══════════════════════════════════
   🔄 AUTO-REFRESH
═══════════════════════════════════ */
let arTimer     = null;
let arActive    = false;
let arCountdown = 60;

function toggleAutoRefresh() {
    arActive = !arActive;
    const btn = document.getElementById('ar-btn');
    if (!btn) return;
    if (arActive) {
        btn.classList.add('on');
        arCountdown = 60;
        arTimer = setInterval(() => {
            arCountdown--;
            const el = document.getElementById('ar-count');
            if (el) el.textContent = `${arCountdown}s`;
            if (arCountdown <= 0) { window.location.reload(); }
        }, 1000);
        toast('Auto-refresh activé', 'info', 'Rafraîchissement dans 60s');
    } else {
        btn.classList.remove('on');
        clearInterval(arTimer);
        const el = document.getElementById('ar-count');
        if (el) el.textContent = '';
        toast('Auto-refresh désactivé', 'warn');
    }
}

/* ═══════════════════════════════════
   📊 CHARTS
═══════════════════════════════════ */
let chartsInit = false;
let chartInstances = {};

function initCharts() {
    if (chartsInit) return;
    chartsInit = true;

    // Récupérer données depuis data-attributes injectés par PHP
    const statsEl = document.getElementById('stats-data');
    if (!statsEl) return;
    const data = JSON.parse(statsEl.textContent || '{}');

    // ── Donut présence
    const ctx1 = document.getElementById('chart-presence')?.getContext('2d');
    if (ctx1) {
        chartInstances['presence'] = new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: ['À l\'heure', 'En retard', 'Absents'],
                datasets: [{
                    data: [data.ontime || 0, data.late || 0, data.absent || 0],
                    backgroundColor: ['rgba(50,190,143,0.82)', 'rgba(255,53,83,0.82)', 'rgba(90,128,112,0.5)'],
                    borderColor: '#0d1e2c', borderWidth: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#b8d8cc', font: { size: 11 }, padding: 12 } }
                }
            }
        });
    }

    // ── Bar heures d'arrivée
    const ctx2 = document.getElementById('chart-horaires')?.getContext('2d');
    if (ctx2 && data.hours_dist) {
        chartInstances['horaires'] = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: Object.keys(data.hours_dist),
                datasets: [{
                    label: 'Arrivées',
                    data: Object.values(data.hours_dist),
                    backgroundColor: 'rgba(50,190,143,0.72)',
                    borderRadius: 7, borderSkipped: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a8070', font: { size: 10 } } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a8070' }, beginAtZero: true }
                }
            }
        });
    }

    // ── Line heures sup
    const ctx3 = document.getElementById('chart-ot')?.getContext('2d');
    if (ctx3 && data.ot_by_emp) {
        chartInstances['ot'] = new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: data.ot_by_emp.map(e => e.name),
                datasets: [{
                    label: 'H.Sup (h)',
                    data: data.ot_by_emp.map(e => e.hours),
                    backgroundColor: 'rgba(255,208,96,0.72)',
                    borderRadius: 7, borderSkipped: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a8070', font: { size: 10 }, maxRotation: 30 } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a8070' }, beginAtZero: true }
                }
            }
        });
    }

    // ── Doughnut departures
    const ctx4 = document.getElementById('chart-depart')?.getContext('2d');
    if (ctx4) {
        chartInstances['depart'] = new Chart(ctx4, {
            type: 'doughnut',
            data: {
                labels: ['Partis', 'Encore présents'],
                datasets: [{
                    data: [data.departed || 0, Math.max(0, (data.ontime + data.late) - (data.departed || 0))],
                    backgroundColor: ['rgba(255,208,96,0.82)', 'rgba(6,182,212,0.5)'],
                    borderColor: '#0d1e2c', borderWidth: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#b8d8cc', font: { size: 11 }, padding: 12 } }
                }
            }
        });
    }
}

/* ═══════════════════════════════════
   👻 ABSENTS (render depuis data)
═══════════════════════════════════ */
function renderAbsents() {
    const container = document.getElementById('absents-list');
    if (!container) return;
    const el = document.getElementById('absent-data');
    if (!el) return;
    const absents = JSON.parse(el.textContent || '[]');
    if (!absents.length) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-check-double"></i><h3>Tous les employés ont pointé !</h3><p>Aucune absence aujourd\'hui</p></div>';
        return;
    }
    container.innerHTML = absents.map((emp, i) => `
        <div class="absent-card" style="animation-delay:${i*0.04}s">
            <div class="absent-ico"><i class="fas fa-user-slash"></i></div>
            <div>
                <div class="absent-name">${esc(emp.full_name)}</div>
                <div class="absent-meta">${esc(emp.employee_code)} · ${esc(emp.position_title)} · ${esc(emp.category_name)}</div>
            </div>
        </div>`).join('');
}

/* ═══════════════════════════════════
   📅 SEMAINE
═══════════════════════════════════ */
function renderWeekTable() {
    const el = document.getElementById('week-data');
    const tbody = document.getElementById('week-tbody');
    if (!el || !tbody) return;
    const rows = JSON.parse(el.textContent || '[]');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="fas fa-calendar"></i><h3>Aucune donnée cette semaine</h3></div></td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(r => {
        const statusCl = r.status === 'retard' ? 'bdg-r' : 'bdg-n';
        const statusTx = r.status === 'retard' ? 'Retard' : 'À l\'heure';
        const worked   = r.hours_worked ? `${r.hours_worked}h` : '—';
        const pen      = r.penalty_amount > 0 ? `<span class="bdg bdg-r">-${fmtNum(r.penalty_amount)} F</span>` : '—';
        const ot       = r.overtime_hours > 0 ? `<span class="bdg bdg-g">+${r.overtime_hours}h</span>` : '—';
        return `<tr>
            <td><strong>${fmtDate(r.work_date)}</strong></td>
            <td><strong>${esc(r.full_name)}</strong></td>
            <td style="font-family:var(--fh);font-size:15px;font-weight:900;color:var(--cyan)">${r.check_in ? r.check_in.substring(0,5) : '—'}</td>
            <td style="font-family:var(--fh);font-size:15px;font-weight:900;color:var(--gold)">${r.check_out ? r.check_out.substring(0,5) : '—'}</td>
            <td style="font-family:var(--fh);font-weight:900;color:var(--text)">${worked}</td>
            <td><span class="bdg ${statusCl}">${statusTx}</span></td>
            <td>${pen}</td>
            <td>${ot}</td>
        </tr>`;
    }).join('');
}

/* ═══════════════════════════════════
   📤 EXPORT CSV
═══════════════════════════════════ */
function exportCSV() {
    const el = document.getElementById('export-data');
    if (!el) return;
    const rows = JSON.parse(el.textContent || '[]');
    if (!rows.length) { toast('Aucune donnée à exporter','warn'); return; }

    const headers = ['Employé','Code','Poste','Catégorie','Arrivée','Départ','H.Travaillées','Statut','Retard(min)','Pénalité(FCFA)','H.Sup','Montant Sup','GPS Arrivée','GPS Départ'];
    const lines = ['\xEF\xBB\xBF' + headers.join(';')];

    rows.forEach(r => {
        const gpsIn  = r.latitude && r.longitude ? `${r.latitude},${r.longitude}` : '';
        const gpsOut = r.checkout_latitude && r.checkout_longitude ? `${r.checkout_latitude},${r.checkout_longitude}` : '';
        lines.push([
            r.full_name, r.employee_code, r.position_title, r.category_name,
            r.check_in || '', r.check_out || '', r.hours_worked || '',
            r.status || '', r.minutes_late || 0, r.penalty_amount || 0,
            r.overtime_hours || 0, r.overtime_amount || 0, gpsIn, gpsOut
        ].map(v => `"${String(v).replace(/"/g,'""')}"`).join(';'));
    });

    const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = `pointages_${document.getElementById('filter-date')?.value || 'export'}.csv`;
    a.click(); URL.revokeObjectURL(url);
    toast('Export CSV téléchargé !', 'success', `${rows.length} ligne(s)`);
}

/* ═══════════════════════════════════
   🖨️ PRINT CARD
═══════════════════════════════════ */
function printCard(attId) {
    const card = document.querySelector(`[data-att="${attId}"]`);
    if (!card) return;
    const w = window.open('', '_blank');
    w.document.write(`<html><head><title>Pointage</title>
    <style>body{font-family:Arial;padding:20px;font-size:14px;}
    h2{color:#32be8f;} table{width:100%;border-collapse:collapse;}
    td,th{border:1px solid #ddd;padding:8px;}</style></head><body>`);
    w.document.write(`<h2>Pointage — ESPERANCE H2O</h2>`);
    w.document.write(card.outerHTML);
    w.document.write(`</body></html>`);
    w.document.close(); w.print();
}

/* ═══════════════════════════════════
   ⌨️ RACCOURCIS CLAVIER
═══════════════════════════════════ */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-fs.show').forEach(m => {
            m.classList.remove('show'); document.body.style.overflow = '';
        });
    }
    if (e.ctrlKey && e.key === 'k') { e.preventDefault(); document.getElementById('live-search')?.focus(); }
    if (e.ctrlKey && e.key === 'e') { e.preventDefault(); exportCSV(); }
    if (e.ctrlKey && e.key === 'r' && !e.shiftKey) { /* laisser reload normal */ }
});

/* ═══════════════════════════════════
   🛠 UTILS
═══════════════════════════════════ */
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtNum(v) { return new Intl.NumberFormat('fr-FR').format(v||0); }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('fr-FR',{weekday:'short',day:'numeric',month:'short'}) : '—'; }

/* ═══════════════════════════════════
   🚀 INIT
═══════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    // Distances GPS
    renderDistances();
    // Default panel
    switchPageTab('today');
    // Toast bienvenue
    const cnt = parseInt(document.getElementById('count-total')?.textContent || '0');
    if (cnt > 0) toast(`${cnt} pointage(s) chargé(s)`, 'info');
    // Hint raccourcis
    setTimeout(() => toast('⌨️ Ctrl+K = Recherche · Ctrl+E = Export CSV','info','Raccourcis disponibles'), 1800);
    console.log('📸 Visionneuse Pointages NEON 3.0 — ESPERANCE H2O');
});
