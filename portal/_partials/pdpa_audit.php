<?php
// portal/_partials/pdpa_audit.php — PDPA Consent Audit (read-only)
// Loaded by portal/index.php — portal_CSRF + SweetAlert2 available
?>
<style>
.pa-page { padding: 4px 4px 80px; }
.pa-h1 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0 0 4px; }
.pa-sub { font-size: 12px; color: #64748b; margin-bottom: 20px; }

.pa-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
.pa-kpi { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; transition: transform 0.18s, box-shadow 0.18s; }
.pa-kpi:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -10px rgba(15,23,42,0.18); }
.pa-kpi .ic { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.pa-kpi .num { font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1.1; }
.pa-kpi .lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.pa-kpi[data-tone="total"]   { background: #f8fafc; }
.pa-kpi[data-tone="total"] .ic { background: #e2e8f0; color: #475569; }
.pa-kpi[data-tone="full"]    { background: #f0fdf4; }
.pa-kpi[data-tone="full"] .ic { background: #dcfce7; color: #15803d; }
.pa-kpi[data-tone="partial"] { background: #fef3c7; }
.pa-kpi[data-tone="partial"] .ic { background: #fde68a; color: #b45309; }
.pa-kpi[data-tone="legacy"]  { background: #fef2f2; }
.pa-kpi[data-tone="legacy"] .ic { background: #fee2e2; color: #b91c1c; }
.pa-kpi[data-tone="general-only"] { background: #eff6ff; }
.pa-kpi[data-tone="general-only"] .ic { background: #dbeafe; color: #1e40af; }

.pa-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; margin-bottom: 16px; }
.pa-card-title { font-size: 13px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.pa-card-title i { color: #2e9e63; }

.pa-version-bar { display: flex; align-items: center; gap: 4px; height: 28px; border-radius: 8px; overflow: hidden; background: #f1f5f9; }
.pa-version-seg { height: 100%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 900; color: #fff; transition: filter 0.18s; min-width: 28px; }
.pa-version-seg:hover { filter: brightness(1.1); }
.pa-version-legend { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; font-size: 11px; font-weight: 700; color: #475569; }
.pa-version-legend .dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; margin-right: 4px; vertical-align: middle; }

.pa-filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; }
.pa-filter-bar label { font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px; }
.pa-filter-bar input, .pa-filter-bar select { font-size: 13px; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
.pa-filter-bar input[type="search"] { min-width: 240px; }
.pa-filter-bar .btn-x { padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; border: 1px solid transparent; transition: filter 0.15s; }
.pa-filter-bar .btn-x:hover { filter: brightness(1.05); }
.pa-filter-bar .btn-x.primary { background: #2e9e63; color: #fff; }
.pa-filter-bar .btn-x.ghost { background: #fff; border-color: #cbd5e1; color: #475569; }
.pa-filter-bar .btn-x.export { background: #f1f5f9; border-color: #cbd5e1; color: #1e40af; }

.pa-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
.pa-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1000px; }
.pa-table th { background: #f8fafc; padding: 9px 10px; text-align: left; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.pa-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.pa-table tbody tr:hover { background: #fafbfc; cursor: pointer; }
.pa-table .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace; font-size: 11px; color: #475569; }

.pa-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 9999px; font-size: 11px; font-weight: 800; }
.pa-pill-ok    { background: #dcfce7; color: #15803d; }
.pa-pill-warn  { background: #fde68a; color: #b45309; }
.pa-pill-err   { background: #fee2e2; color: #b91c1c; }
.pa-pill-muted { background: #e2e8f0; color: #475569; }
.pa-pill-info  { background: #dbeafe; color: #1e40af; }

.pa-pager { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; font-size: 12px; color: #475569; flex-wrap: wrap; gap: 8px; }
.pa-pager .pg-btns { display: flex; gap: 4px; }
.pa-pager .pg-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; font-weight: 800; font-size: 12px; cursor: pointer; transition: background 0.15s; }
.pa-pager .pg-btn:hover:not(:disabled) { background: #f1f5f9; }
.pa-pager .pg-btn.active { background: #2e9e63; border-color: #2e9e63; color: #fff; }
.pa-pager .pg-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* Detail modal — portal-escape pattern (teleport to body) */
#pa-detail-modal { display: none; z-index: 9000 !important; background: rgba(15,23,42,0.55) !important; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); position: fixed; inset: 0; align-items: center; justify-content: center; padding: 20px; }
#pa-detail-modal.is-open { display: flex; }
#pa-detail-box { background: #fff; border-radius: 18px; width: 100%; max-width: 720px; max-height: 90vh; overflow-y: auto; padding: 24px; }
#pa-detail-box h3 { font-size: 18px; font-weight: 900; color: #0f172a; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
#pa-detail-box .pa-detail-section { margin-top: 16px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
#pa-detail-box .pa-detail-section h4 { font-size: 12px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px; }
#pa-detail-box dl { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; font-size: 13px; }
#pa-detail-box dt { color: #64748b; font-weight: 700; }
#pa-detail-box dd { margin: 0; color: #0f172a; word-break: break-all; }
#pa-detail-box .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace; font-size: 11px; color: #1e40af; }

body[data-theme='dark'] .pa-kpi,
body[data-theme='dark'] .pa-card,
body[data-theme='dark'] .pa-table-wrap,
body[data-theme='dark'] #pa-detail-box { background: #1e293b; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .pa-kpi .num, body[data-theme='dark'] .pa-h1, body[data-theme='dark'] #pa-detail-box dd, body[data-theme='dark'] .pa-card-title { color: #f1f5f9; }
body[data-theme='dark'] .pa-table th { background: #0f172a; color: #cbd5e1; border-color: #334155; }
body[data-theme='dark'] .pa-table td { border-color: #334155; }
body[data-theme='dark'] .pa-table tbody tr:hover { background: #0f172a; }
body[data-theme='dark'] .pa-filter-bar input, body[data-theme='dark'] .pa-filter-bar select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
</style>

<div class="pa-page">
    <h1 class="pa-h1"><i class="fa-solid fa-shield-check" style="color:#2e9e63"></i> PDPA Consent Audit</h1>
    <p class="pa-sub">ตรวจสอบและส่งออกบันทึกความยินยอม PDPA ของผู้ใช้ — ใช้ในการตรวจประเมิน ISO 27701 / ตอบ DSAR / ส่งหลักฐานต่อ PDPC</p>

    <!-- KPI tiles -->
    <div class="pa-kpis" id="pa-kpis">
        <div class="pa-kpi" data-tone="total" data-stat="total"><div class="ic"><i class="fa-solid fa-users"></i></div><div><div class="num">–</div><div class="lbl">ผู้ใช้ทั้งหมด</div></div></div>
        <div class="pa-kpi" data-tone="full" data-stat="full"><div class="ic"><i class="fa-solid fa-circle-check"></i></div><div><div class="num">–</div><div class="lbl">ยินยอมครบ (24+26)</div></div></div>
        <div class="pa-kpi" data-tone="general-only" data-stat="general_only"><div class="ic"><i class="fa-solid fa-minus-circle"></i></div><div><div class="num">–</div><div class="lbl">ทั่วไปอย่างเดียว</div></div></div>
        <div class="pa-kpi" data-tone="partial" data-stat="partial"><div class="ic"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num">–</div><div class="lbl">ไม่สมมาตร (ตรวจ)</div></div></div>
        <div class="pa-kpi" data-tone="legacy" data-stat="legacy"><div class="ic"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="num">–</div><div class="lbl">ยัง/Legacy ไม่มี v2</div></div></div>
    </div>

    <!-- Version distribution -->
    <div class="pa-card">
        <div class="pa-card-title"><i class="fa-solid fa-code-branch"></i> การกระจายตัวของเวอร์ชันนโยบาย</div>
        <div class="pa-version-bar" id="pa-version-bar"></div>
        <div class="pa-version-legend" id="pa-version-legend"></div>
    </div>

    <!-- Filters + table -->
    <div class="pa-card">
        <div class="pa-filter-bar">
            <div>
                <label>ค้นหา (ชื่อ / เลขบัตร / LINE ID / IP)</label>
                <input type="search" id="pa-q" placeholder="พิมพ์เพื่อค้นหา…">
            </div>
            <div>
                <label>เวอร์ชัน</label>
                <select id="pa-version"><option value="">— ทั้งหมด —</option></select>
            </div>
            <div>
                <label>สถานะ</label>
                <select id="pa-status">
                    <option value="">— ทั้งหมด —</option>
                    <option value="full">ยินยอมครบ</option>
                    <option value="general_only">ทั่วไปอย่างเดียว</option>
                    <option value="partial">ไม่สมมาตร</option>
                    <option value="legacy">Legacy (ไม่มี v2)</option>
                </select>
            </div>
            <button type="button" class="btn-x primary" onclick="paLoadList(1)"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
            <button type="button" class="btn-x ghost"   onclick="paReset()"><i class="fa-solid fa-rotate-left"></i> ล้าง</button>
            <button type="button" class="btn-x export"  onclick="paExport()"><i class="fa-solid fa-file-csv"></i> ส่งออก CSV</button>
        </div>

        <div class="pa-table-wrap">
            <table class="pa-table">
                <thead><tr>
                    <th>ID</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>เลขบัตร</th>
                    <th>ยินยอมทั่วไป</th>
                    <th>ยินยอมอ่อนไหว</th>
                    <th>เวอร์ชัน</th>
                    <th>IP</th>
                    <th>UA</th>
                </tr></thead>
                <tbody id="pa-tbody">
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">กำลังโหลด…</td></tr>
                </tbody>
            </table>
            <div class="pa-pager" id="pa-pager"></div>
        </div>
    </div>
</div>

<!-- Detail modal (will be teleported to body on first open) -->
<div id="pa-detail-modal" onclick="if(event.target===this) paCloseDetail()">
    <div id="pa-detail-box">
        <h3><i class="fa-solid fa-user-shield" style="color:#2e9e63"></i> รายละเอียดความยินยอม</h3>
        <p class="pa-sub" id="pa-detail-name">—</p>
        <div id="pa-detail-content"></div>
        <div style="display:flex; justify-content:flex-end; margin-top:18px">
            <button type="button" class="btn-x ghost" onclick="paCloseDetail()"><i class="fa-solid fa-xmark"></i> ปิด</button>
        </div>
    </div>
</div>

<script>
(function() {
    const AJAX = 'ajax_pdpa_audit.php';
    let paCurrent = { page: 1, perPage: 20, totalPages: 1 };

    // Soft pastel palette for version segments — deterministic so the same
    // version always gets the same colour across reloads
    const PA_PALETTE = ['#2e9e63', '#3b82f6', '#a855f7', '#f59e0b', '#ec4899', '#14b8a6', '#64748b'];
    function paColorFor(idx) { return PA_PALETTE[idx % PA_PALETTE.length]; }

    function paFmtDate(s) {
        if (!s) return '<span style="color:#cbd5e1">—</span>';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        return d.toLocaleString('th-TH', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
    }
    function paEscape(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function paLoadStats() {
        try {
            const res = await fetch(AJAX + '?action=stats', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message || 'load failed');

            for (const k of ['total','full','partial','legacy','general_only']) {
                const el = document.querySelector(`.pa-kpi[data-stat="${k}"] .num`);
                if (el) el.textContent = (json.stats[k] ?? 0).toLocaleString('th-TH');
            }

            // Version distribution bar
            const bar = document.getElementById('pa-version-bar');
            const legend = document.getElementById('pa-version-legend');
            const versions = json.versions || [];
            const sel = document.getElementById('pa-version');
            // Reset version dropdown options (keep first "all")
            sel.innerHTML = '<option value="">— ทั้งหมด —</option>';
            if (!versions.length) {
                bar.innerHTML = '<div style="font-size:11px;color:#94a3b8;padding:6px 10px">ยังไม่มีข้อมูล consent v2</div>';
                legend.innerHTML = '';
                return;
            }
            const totalN = versions.reduce((a,v) => a + (+v.n||0), 0) || 1;
            bar.innerHTML = '';
            legend.innerHTML = '';
            versions.forEach((v, i) => {
                const pct = ((+v.n||0) / totalN) * 100;
                const color = paColorFor(i);
                const seg = document.createElement('div');
                seg.className = 'pa-version-seg';
                seg.style.flex = pct;
                seg.style.background = color;
                seg.textContent = pct >= 8 ? `${pct.toFixed(0)}%` : '';
                seg.title = `${v.v}: ${v.n} คน (${pct.toFixed(1)}%)`;
                bar.appendChild(seg);

                const li = document.createElement('span');
                li.innerHTML = `<span class="dot" style="background:${color}"></span>${paEscape(v.v)} (${v.n.toLocaleString('th-TH')})`;
                legend.appendChild(li);

                const opt = document.createElement('option');
                opt.value = v.v; opt.textContent = v.v;
                sel.appendChild(opt);
            });
        } catch (err) {
            console.error('[pdpa_audit] stats error', err);
        }
    }

    async function paLoadList(page) {
        page = page || 1;
        paCurrent.page = page;
        const q = document.getElementById('pa-q').value.trim();
        const version = document.getElementById('pa-version').value;
        const status = document.getElementById('pa-status').value;
        const params = new URLSearchParams({ action:'list', page, per_page: paCurrent.perPage, q, version, status });

        const tbody = document.getElementById('pa-tbody');
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</td></tr>';

        try {
            const res = await fetch(AJAX + '?' + params, { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message || 'load failed');

            paCurrent.totalPages = json.page_count;
            if (!json.rows.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">ไม่พบข้อมูลตามเงื่อนไข</td></tr>';
            } else {
                tbody.innerHTML = json.rows.map(r => {
                    const gen = r.consent_general_accepted_at
                        ? `<span class="pa-pill pa-pill-ok"><i class="fa-solid fa-check"></i> ${paFmtDate(r.consent_general_accepted_at)}</span>`
                        : `<span class="pa-pill pa-pill-muted">—</span>`;
                    const sen = r.consent_sensitive_accepted_at
                        ? `<span class="pa-pill pa-pill-ok"><i class="fa-solid fa-check"></i> ${paFmtDate(r.consent_sensitive_accepted_at)}</span>`
                        : `<span class="pa-pill pa-pill-err"><i class="fa-solid fa-xmark"></i> ไม่ยินยอม</span>`;
                    const ver = r.consent_general_version
                        ? `<span class="pa-pill pa-pill-info mono">${paEscape(r.consent_general_version)}</span>`
                        : `<span class="pa-pill pa-pill-muted">—</span>`;
                    return `<tr onclick="paShowDetail(${r.id})">
                        <td class="mono">#${r.id}</td>
                        <td><b>${paEscape(r.full_name || '—')}</b><br><span class="mono" style="font-size:10px">${paEscape(r.line_user_id || '')}</span></td>
                        <td class="mono">${paEscape(r.citizen_id_masked || '—')}</td>
                        <td>${gen}</td>
                        <td>${sen}</td>
                        <td>${ver}</td>
                        <td class="mono">${paEscape(r.consent_ip || '—')}</td>
                        <td class="mono" title="${paEscape(r.consent_user_agent_short || '')}">${paEscape(r.consent_user_agent_short || '—')}</td>
                    </tr>`;
                }).join('');
            }

            // Pagination controls
            const pager = document.getElementById('pa-pager');
            const p = json.page, pc = json.page_count;
            const win = 2;
            const start = Math.max(1, p - win), end = Math.min(pc, p + win);
            let btns = '';
            btns += `<button class="pg-btn" ${p===1?'disabled':''} onclick="paLoadList(1)">«</button>`;
            btns += `<button class="pg-btn" ${p===1?'disabled':''} onclick="paLoadList(${p-1})">‹</button>`;
            for (let i = start; i <= end; i++) {
                btns += `<button class="pg-btn ${i===p?'active':''}" onclick="paLoadList(${i})">${i}</button>`;
            }
            btns += `<button class="pg-btn" ${p===pc?'disabled':''} onclick="paLoadList(${p+1})">›</button>`;
            btns += `<button class="pg-btn" ${p===pc?'disabled':''} onclick="paLoadList(${pc})">»</button>`;
            pager.innerHTML = `
                <div>หน้า ${p} / ${pc} · รวม ${json.total.toLocaleString('th-TH')} รายการ</div>
                <div class="pg-btns">${btns}</div>`;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:30px;color:#b91c1c">ERROR: ${paEscape(err.message)}</td></tr>`;
        }
    }

    function paReset() {
        document.getElementById('pa-q').value = '';
        document.getElementById('pa-version').value = '';
        document.getElementById('pa-status').value = '';
        paLoadList(1);
    }

    function paExport() {
        const q = document.getElementById('pa-q').value.trim();
        const version = document.getElementById('pa-version').value;
        const status = document.getElementById('pa-status').value;
        const params = new URLSearchParams({ action:'export', q, version, status });
        window.location.href = AJAX + '?' + params;
    }

    function paTeleportModal() {
        const m = document.getElementById('pa-detail-modal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    async function paShowDetail(id) {
        const m = paTeleportModal();
        const content = document.getElementById('pa-detail-content');
        const nameEl = document.getElementById('pa-detail-name');
        content.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</div>';
        nameEl.textContent = '—';
        m.classList.add('is-open');

        try {
            const res = await fetch(AJAX + '?action=detail&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message || 'load failed');
            const r = json.row;
            nameEl.textContent = `${r.full_name || '—'} (#${r.id})`;

            const genVerify = r.consent_general_version ? (r.general_hash_verifies
                ? '<span class="pa-pill pa-pill-ok"><i class="fa-solid fa-shield-check"></i> Hash ตรง</span>'
                : '<span class="pa-pill pa-pill-err"><i class="fa-solid fa-shield-halved"></i> Hash ไม่ตรง — ตรวจสอบทันที</span>')
                : '<span class="pa-pill pa-pill-muted">ยังไม่ยินยอม</span>';
            const senVerify = r.consent_sensitive_version ? (r.sensitive_hash_verifies
                ? '<span class="pa-pill pa-pill-ok"><i class="fa-solid fa-shield-check"></i> Hash ตรง</span>'
                : '<span class="pa-pill pa-pill-err"><i class="fa-solid fa-shield-halved"></i> Hash ไม่ตรง — ตรวจสอบทันที</span>')
                : '<span class="pa-pill pa-pill-muted">ยังไม่ยินยอม</span>';

            content.innerHTML = `
                <div class="pa-detail-section">
                    <h4>ข้อมูลผู้ใช้</h4>
                    <dl>
                        <dt>เลขบัตรประชาชน</dt><dd class="mono">${paEscape(r.citizen_id || '—')}</dd>
                        <dt>LINE User ID</dt><dd class="mono">${paEscape(r.line_user_id || '—')}</dd>
                        <dt>สถานะ</dt><dd>${paEscape(r.status || '—')}</dd>
                        <dt>อีเมล</dt><dd>${paEscape(r.email || '—')}</dd>
                        <dt>เบอร์โทร</dt><dd>${paEscape(r.phone_number || '—')}</dd>
                    </dl>
                </div>
                <div class="pa-detail-section">
                    <h4>ความยินยอมข้อมูลทั่วไป (มาตรา 24) ${genVerify}</h4>
                    <dl>
                        <dt>ยอมรับเมื่อ</dt><dd>${paFmtDate(r.consent_general_accepted_at)}</dd>
                        <dt>เวอร์ชัน</dt><dd class="mono">${paEscape(r.consent_general_version || '—')}</dd>
                        <dt>Hash (SHA-256)</dt><dd class="mono">${paEscape(r.consent_general_text_hash || '—')}</dd>
                    </dl>
                </div>
                <div class="pa-detail-section">
                    <h4>ความยินยอมข้อมูลอ่อนไหว (มาตรา 26) ${senVerify}</h4>
                    <dl>
                        <dt>ยอมรับเมื่อ</dt><dd>${paFmtDate(r.consent_sensitive_accepted_at)}</dd>
                        <dt>เวอร์ชัน</dt><dd class="mono">${paEscape(r.consent_sensitive_version || '—')}</dd>
                        <dt>Hash (SHA-256)</dt><dd class="mono">${paEscape(r.consent_sensitive_text_hash || '—')}</dd>
                    </dl>
                </div>
                <div class="pa-detail-section">
                    <h4>หลักฐานการยินยอม (Forensic evidence)</h4>
                    <dl>
                        <dt>IP Address</dt><dd class="mono">${paEscape(r.consent_ip || '—')}</dd>
                        <dt>User-Agent</dt><dd class="mono" style="font-size:11px">${paEscape(r.consent_user_agent || '—')}</dd>
                    </dl>
                </div>`;
        } catch (err) {
            content.innerHTML = `<div style="text-align:center;padding:40px;color:#b91c1c">ERROR: ${paEscape(err.message)}</div>`;
        }
    }
    function paCloseDetail() {
        document.getElementById('pa-detail-modal').classList.remove('is-open');
    }

    // Debounced search-as-you-type — keep server quiet while user is still typing
    let paQTimer;
    document.getElementById('pa-q').addEventListener('input', () => {
        clearTimeout(paQTimer);
        paQTimer = setTimeout(() => paLoadList(1), 350);
    });
    document.getElementById('pa-version').addEventListener('change', () => paLoadList(1));
    document.getElementById('pa-status').addEventListener('change', () => paLoadList(1));

    // Expose for inline handlers
    window.paLoadList = paLoadList;
    window.paReset = paReset;
    window.paExport = paExport;
    window.paShowDetail = paShowDetail;
    window.paCloseDetail = paCloseDetail;

    // Boot
    paLoadStats();
    paLoadList(1);
})();
</script>
