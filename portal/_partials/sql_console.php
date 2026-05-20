<?php
// portal/_partials/sql_console.php — Read-only SQL console (superadmin)
// Loaded by portal/index.php. The server-side endpoint guards everything
// regardless of what this UI sends, but we surface the constraints clearly
// so the operator isn't surprised by rejections.
?>
<style>
.sc-page { padding: 4px 4px 80px; }
.sc-h1 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.sc-sub { font-size: 12px; color: #64748b; margin-bottom: 12px; }

.sc-warn { background: #fef3c7; border: 1.5px solid #fde68a; color: #92400e; padding: 12px 14px; border-radius: 10px; font-size: 12px; line-height: 1.6; margin-bottom: 16px; }
.sc-warn .h { font-weight: 900; color: #b45309; display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.sc-warn code { background: rgba(120,53,15,0.1); padding: 1px 5px; border-radius: 3px; font-size: 11px; }

.sc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; margin-bottom: 14px; }

#sc-input { width: 100%; min-height: 140px; font-family: ui-monospace, Menlo, Monaco, monospace; font-size: 13px; line-height: 1.55; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; background: #0f172a; color: #e2e8f0; resize: vertical; }
#sc-input:focus { outline: none; border-color: #ea580c; box-shadow: 0 0 0 3px rgba(234,88,12,0.15); }

.sc-actions { display: flex; gap: 8px; margin-top: 10px; align-items: center; flex-wrap: wrap; }
.sc-btn { padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; border: 1px solid transparent; transition: filter 0.15s; display: inline-flex; align-items: center; gap: 6px; }
.sc-btn:hover { filter: brightness(1.05); }
.sc-btn-run { background: #ea580c; color: #fff; }
.sc-btn-run:disabled { opacity: 0.5; cursor: not-allowed; }
.sc-btn-ghost { background: #fff; border-color: #cbd5e1; color: #475569; }
.sc-meta { margin-left: auto; font-size: 11px; font-weight: 700; color: #64748b; }
.sc-meta b { color: #0f172a; }

.sc-result-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.sc-result-head .sc-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 9999px; font-size: 11px; font-weight: 800; }
.sc-result-head .sc-pill-ok    { background: #dcfce7; color: #15803d; }
.sc-result-head .sc-pill-warn  { background: #fef3c7; color: #b45309; }
.sc-result-head .sc-pill-err   { background: #fee2e2; color: #b91c1c; }
.sc-result-head .sc-pill-info  { background: #dbeafe; color: #1e40af; }

.sc-pii-warn { background: #fff7ed; border: 1.5px solid #fdba74; color: #9a3412; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }

.sc-table-wrap { overflow: auto; border: 1px solid #e2e8f0; border-radius: 10px; max-height: 60vh; background: #fff; }
.sc-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.sc-table th { position: sticky; top: 0; background: #f1f5f9; padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 900; color: #475569; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; white-space: nowrap; }
.sc-table th .col-type { font-size: 9px; color: #94a3b8; text-transform: none; display: block; font-weight: 700; }
.sc-table td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; font-family: ui-monospace, Menlo, monospace; vertical-align: top; white-space: pre-wrap; word-break: break-word; max-width: 320px; }
.sc-table tbody tr:hover { background: #fafbfc; }
.sc-table td.null { color: #cbd5e1; font-style: italic; }

.sc-history { font-size: 12px; }
.sc-history h4 { font-size: 11px; font-weight: 900; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 8px; }
.sc-history-item { padding: 8px 10px; background: #f8fafc; border-radius: 6px; margin-bottom: 4px; cursor: pointer; transition: background 0.12s; }
.sc-history-item:hover { background: #e2e8f0; }
.sc-history-item .when { font-size: 10px; color: #94a3b8; font-weight: 700; }
.sc-history-item .q { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: #0f172a; word-break: break-all; margin-top: 2px; }
.sc-history-item .stats { font-size: 10px; color: #475569; margin-top: 2px; }

.sc-snippet-bar { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.sc-snippet { font-size: 11px; padding: 4px 9px; border-radius: 6px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; cursor: pointer; font-weight: 700; transition: filter 0.12s; }
.sc-snippet:hover { filter: brightness(1.05); }

body[data-theme='dark'] .sc-card,
body[data-theme='dark'] .sc-table-wrap { background: #1e293b; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .sc-warn { background: rgba(146,64,14,0.18); border-color: rgba(180,83,9,0.4); color: #fde68a; }
body[data-theme='dark'] .sc-pii-warn { background: rgba(154,52,18,0.2); color: #fdba74; border-color: rgba(253,186,116,0.4); }
body[data-theme='dark'] .sc-table th { background: #0f172a; color: #cbd5e1; border-color: #334155; }
body[data-theme='dark'] .sc-table td { border-color: #334155; }
body[data-theme='dark'] .sc-table tbody tr:hover { background: #0f172a; }
body[data-theme='dark'] .sc-history-item { background: #0f172a; }
body[data-theme='dark'] .sc-history-item:hover { background: #334155; }
body[data-theme='dark'] .sc-history-item .q { color: #f1f5f9; }
</style>

<div class="sc-page">
    <h1 class="sc-h1"><i class="fa-solid fa-terminal" style="color:#ea580c"></i> SQL Console <span style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;background:#fed7aa;color:#9a3412;padding:3px 9px;border-radius:6px">read-only</span></h1>
    <p class="sc-sub">รัน SELECT / SHOW / DESCRIBE / EXPLAIN เพื่อ diagnostic — query log ทุกครั้งไป sys_activity_logs · ใช้ได้เฉพาะ superadmin</p>

    <div class="sc-warn">
        <div class="h"><i class="fa-solid fa-shield-halved"></i> ข้อจำกัด / Safeguards</div>
        <ul style="margin:0;padding-left:18px">
            <li>ห้าม <code>INSERT</code> / <code>UPDATE</code> / <code>DELETE</code> / <code>DROP</code> / <code>ALTER</code> / <code>CREATE</code> / <code>SET</code> / <code>CALL</code> และอื่นๆ ที่เป็น write</li>
            <li>อนุญาตเฉพาะคำสั่งเดียวต่อครั้ง (ห้าม <code>;</code> ในกลาง query)</li>
            <li>ระบบเพิ่ม <code>LIMIT 100</code> ให้อัตโนมัติถ้าไม่ใส่ · cap แข็งที่ 500 แถวต่อ result</li>
            <li>Rate limit 30 queries / 60 วินาที · query ทุกครั้งบันทึก audit (รวมที่ถูก reject)</li>
            <li>ผลลัพธ์อาจมี PII — โปรดอย่า export โดยไม่จำเป็น</li>
        </ul>
    </div>

    <div style="display:grid;grid-template-columns:2.2fr 1fr;gap:14px">
        <div>
            <div class="sc-card">
                <div class="sc-snippet-bar" id="sc-snippets">
                    <!-- pre-canned snippets injected by JS so admins can start fast -->
                </div>
                <textarea id="sc-input" placeholder="SELECT * FROM sys_users WHERE id = 42&#10;-- หรือ -- SHOW COLUMNS FROM camp_bookings"
                          spellcheck="false" autocomplete="off"></textarea>
                <div class="sc-actions">
                    <button type="button" class="sc-btn sc-btn-run" id="sc-run"><i class="fa-solid fa-play"></i> Run</button>
                    <button type="button" class="sc-btn sc-btn-ghost" onclick="scClear()"><i class="fa-solid fa-eraser"></i> ล้าง</button>
                    <span class="sc-meta" id="sc-meta">Ctrl/Cmd + Enter เพื่อรัน</span>
                </div>
            </div>

            <div class="sc-card" id="sc-result-card" style="display:none">
                <div class="sc-result-head">
                    <div id="sc-result-pills"></div>
                    <button type="button" class="sc-btn sc-btn-ghost" onclick="scCopyCsv()" id="sc-copy-csv" style="font-size:10px;padding:5px 10px"><i class="fa-solid fa-copy"></i> Copy CSV</button>
                </div>
                <div id="sc-pii-warn" class="sc-pii-warn" style="display:none">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>ผลลัพธ์มีคอลัมน์ที่อาจเป็น PII — โปรดระมัดระวังการ copy / export</span>
                </div>
                <div class="sc-table-wrap" id="sc-table-wrap"></div>
            </div>
        </div>

        <div class="sc-card">
            <div class="sc-history">
                <h4><i class="fa-solid fa-clock-rotate-left"></i> ประวัติ session นี้</h4>
                <div id="sc-history-list">
                    <div style="font-size:11px;color:#94a3b8">— ยังไม่มี —</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const AJAX = 'ajax_sql_console.php';
    const PII_PATTERNS = /citizen[_-]?id|phone|email|line[_-]?user[_-]?id|password|consent[_-]?ip|consent[_-]?user[_-]?agent|signature|stored[_-]?(name|path)|cert|hash/i;

    // Pre-canned snippets — common diagnostic queries the operator might
    // run. Click → loads into textarea, doesn't auto-execute (still need
    // to review and press Run).
    const SNIPPETS = [
        { label: 'Vaccine: type distribution', sql: "SELECT type, COUNT(*) AS n FROM camp_list GROUP BY type ORDER BY n DESC" },
        { label: 'Vaccine: booking status', sql: "SELECT b.status, COUNT(*) AS n FROM camp_bookings b JOIN camp_list c ON b.campaign_id = c.id WHERE c.type='vaccine' GROUP BY b.status" },
        { label: 'Vaccine: missing records', sql: "SELECT COUNT(*) AS missing FROM camp_bookings b JOIN camp_list c ON b.campaign_id = c.id LEFT JOIN user_vaccination_records v ON v.campaign_booking_id=b.id WHERE c.type='vaccine' AND b.status='completed' AND v.id IS NULL" },
        { label: 'PDPA: mismatch', sql: "SELECT id, full_name, consent_general_accepted_at, consent_sensitive_accepted_at FROM sys_users WHERE (consent_general_accepted_at IS NULL) <> (consent_sensitive_accepted_at IS NULL) LIMIT 20" },
        { label: 'Recent activity', sql: "SELECT action, description, created_at FROM sys_activity_logs ORDER BY created_at DESC LIMIT 30" },
        { label: 'Tables in DB', sql: "SHOW TABLES" },
    ];

    let history = [];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderSnippets() {
        const bar = document.getElementById('sc-snippets');
        bar.innerHTML = SNIPPETS.map((s, i) =>
            `<button type="button" class="sc-snippet" data-idx="${i}" title="${esc(s.sql)}">${esc(s.label)}</button>`
        ).join('');
        bar.querySelectorAll('.sc-snippet').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('sc-input').value = SNIPPETS[+btn.dataset.idx].sql;
                document.getElementById('sc-input').focus();
            });
        });
    }

    function renderResultPills(json) {
        const el = document.getElementById('sc-result-pills');
        const ms = json.duration_ms ?? 0;
        const rows = json.row_count ?? 0;
        const trunc = json.truncated;
        el.innerHTML = `
            <span class="sc-pill sc-pill-ok"><i class="fa-solid fa-circle-check"></i> สำเร็จ</span>
            <span class="sc-pill sc-pill-info">${rows.toLocaleString('th-TH')} แถว${trunc ? ' (cap 500)' : ''}</span>
            <span class="sc-pill sc-pill-info">${ms.toLocaleString('th-TH')} ms</span>
            ${trunc ? '<span class="sc-pill sc-pill-warn"><i class="fa-solid fa-scissors"></i> truncated</span>' : ''}
        `;
    }

    function renderError(message) {
        document.getElementById('sc-result-card').style.display = '';
        document.getElementById('sc-result-pills').innerHTML =
            `<span class="sc-pill sc-pill-err"><i class="fa-solid fa-circle-xmark"></i> ${esc(message)}</span>`;
        document.getElementById('sc-pii-warn').style.display = 'none';
        document.getElementById('sc-table-wrap').innerHTML = '';
    }

    function renderTable(json) {
        const wrap = document.getElementById('sc-table-wrap');
        if (!json.columns?.length || !json.rows?.length) {
            wrap.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:12px">— ไม่มีผลลัพธ์ —</div>';
            return;
        }
        const cols = json.columns;
        // PII warning if any column name matches the heuristic
        const hasPii = cols.some(c => PII_PATTERNS.test(c.name));
        document.getElementById('sc-pii-warn').style.display = hasPii ? 'flex' : 'none';

        const head = cols.map(c =>
            `<th>${esc(c.name)}<span class="col-type">${esc(c.type || '—').toLowerCase()}</span></th>`
        ).join('');
        const body = json.rows.map(r =>
            '<tr>' + cols.map(c => {
                const v = r[c.name];
                if (v === null || v === undefined) return '<td class="null">NULL</td>';
                const s = String(v);
                // truncate single cells > 500 chars so the table doesn't break
                const display = s.length > 500 ? s.substring(0, 500) + '…' : s;
                return `<td>${esc(display)}</td>`;
            }).join('') + '</tr>'
        ).join('');
        wrap.innerHTML = `<table class="sc-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    }

    async function scRun() {
        const sql = document.getElementById('sc-input').value.trim();
        if (!sql) return;
        const btn = document.getElementById('sc-run');
        const meta = document.getElementById('sc-meta');
        btn.disabled = true;
        meta.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังรัน…';
        document.getElementById('sc-result-card').style.display = '';

        try {
            const fd = new FormData();
            fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
            fd.append('sql', sql);
            const res = await fetch(AJAX + '?action=run', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) {
                renderError(json.message || 'รันไม่สำเร็จ');
                addHistory(sql, { ok: false, message: json.message });
                meta.textContent = 'รันไม่สำเร็จ';
                return;
            }
            renderResultPills(json);
            renderTable(json);
            addHistory(sql, { ok: true, rows: json.row_count, ms: json.duration_ms });
            meta.innerHTML = `<b>${json.row_count.toLocaleString('th-TH')}</b> แถว · <b>${json.duration_ms}</b> ms · เพิ่ม <code>LIMIT</code> ให้อัตโนมัติ`;
            // Save result for CSV copy
            window._scLastResult = json;
        } catch (err) {
            renderError(err.message);
            meta.textContent = 'รันไม่สำเร็จ';
        } finally {
            btn.disabled = false;
        }
    }

    function addHistory(sql, result) {
        history.unshift({ sql, result, at: new Date() });
        if (history.length > 20) history.length = 20;
        const list = document.getElementById('sc-history-list');
        if (!history.length) {
            list.innerHTML = '<div style="font-size:11px;color:#94a3b8">— ยังไม่มี —</div>';
            return;
        }
        list.innerHTML = history.map((h, i) => {
            const stats = h.result.ok
                ? `${h.result.rows.toLocaleString('th-TH')} แถว · ${h.result.ms} ms`
                : `❌ ${esc(h.result.message || '')}`;
            return `<div class="sc-history-item" data-idx="${i}">
                <div class="when">${h.at.toLocaleTimeString('th-TH')}</div>
                <div class="q">${esc(h.sql.length > 100 ? h.sql.substring(0, 100) + '…' : h.sql)}</div>
                <div class="stats">${stats}</div>
            </div>`;
        }).join('');
        list.querySelectorAll('.sc-history-item').forEach(it => {
            it.addEventListener('click', () => {
                document.getElementById('sc-input').value = history[+it.dataset.idx].sql;
                document.getElementById('sc-input').focus();
            });
        });
    }

    function scClear() {
        document.getElementById('sc-input').value = '';
        document.getElementById('sc-result-card').style.display = 'none';
        document.getElementById('sc-meta').textContent = 'Ctrl/Cmd + Enter เพื่อรัน';
        document.getElementById('sc-input').focus();
    }

    function scCopyCsv() {
        const json = window._scLastResult;
        if (!json || !json.columns?.length) return;
        const esc = s => {
            const str = String(s == null ? '' : s);
            return /[",\n]/.test(str) ? '"' + str.replace(/"/g, '""') + '"' : str;
        };
        const lines = [json.columns.map(c => esc(c.name)).join(',')];
        for (const r of json.rows) {
            lines.push(json.columns.map(c => esc(r[c.name])).join(','));
        }
        navigator.clipboard.writeText(lines.join('\n')).then(() => {
            Swal.fire({ icon:'success', title:'คัดลอกแล้ว', text:`${json.rows.length} แถว → clipboard`, timer: 1200, showConfirmButton: false });
        }).catch(() => {
            Swal.fire({ icon:'error', title:'คัดลอกไม่สำเร็จ', text:'browser ไม่อนุญาต' });
        });
    }

    // Ctrl/Cmd + Enter to run
    document.getElementById('sc-input').addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            scRun();
        }
    });
    document.getElementById('sc-run').addEventListener('click', scRun);

    window.scClear = scClear;
    window.scCopyCsv = scCopyCsv;

    renderSnippets();
})();
</script>
