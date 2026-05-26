<?php
/**
 * portal/_partials/accident_log.php
 * บันทึกอุบัติเหตุรายวัน — UI partial
 *
 * โหลดจาก portal/accident_log.php (มี $hasAccidentLog gate)
 * AJAX endpoint: portal/ajax_accident_log.php
 */
declare(strict_types=1);
$csrf = $_SESSION['csrf_token'] ?? '';
?>

<style>
/* ─── Accident Log — styles ────────────────────────────────── */
.al-shell { max-width: 1400px; margin: 0 auto; }
.al-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; }

/* Header */
.al-head { display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.al-head .ic {
    width:44px; height:44px; border-radius:12px;
    background:linear-gradient(135deg, #fef2f2, #fee2e2);
    color:#dc2626; border:1.5px solid #fecaca;
    display:flex; align-items:center; justify-content:center; font-size:18px;
}
.al-head h2 { margin:0; font-size:20px; font-weight:900; color:#0f172a; letter-spacing:-0.01em; }
.al-head p  { margin:0; font-size:12px; color:#64748b; font-weight:600; }

/* Filter bar */
.al-filter {
    display:flex; gap:12px; align-items:end; flex-wrap:wrap;
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:14px 18px; margin-bottom:14px;
}
.al-filter label { display:block; font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.al-filter input[type=date] {
    padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:10px;
    font-family:inherit; font-size:13px; color:#0f172a; background:#fff;
    transition: all .15s ease;
}
.al-filter input[type=date]:focus { outline:none; border-color:#dc2626; box-shadow:0 0 0 4px rgba(220,38,38,.12); }

/* Quick chips */
.al-chips { display:flex; gap:6px; flex-wrap:wrap; }
.al-chip {
    padding:6px 12px; border-radius:999px;
    background:#fff; border:1.5px solid #e2e8f0;
    font-size:11.5px; font-weight:700; color:#475569;
    cursor:pointer; transition:all .15s ease;
}
.al-chip:hover { border-color:#fca5a5; color:#dc2626; }
.al-chip.is-active {
    background:linear-gradient(135deg, #dc2626, #b91c1c);
    color:#fff; border-color:#dc2626;
    box-shadow:0 4px 12px rgba(220,38,38,.35);
}

/* KPI cards */
.al-kpis {
    display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap:12px; margin-bottom:14px;
}
.al-kpi {
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:14px 16px; display:flex; align-items:center; gap:12px;
    position:relative; overflow:hidden;
}
.al-kpi::before {
    content:''; position:absolute; top:0; left:0; bottom:0; width:4px;
    background:var(--kpi-color, #94a3b8); border-radius:14px 0 0 14px;
}
.al-kpi .kpi-ic {
    width:40px; height:40px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; flex-shrink:0;
    background:var(--kpi-bg, #f1f5f9); color:var(--kpi-color, #475569);
}
.al-kpi .kpi-num { font-size:22px; font-weight:900; color:#0f172a; line-height:1.1; }
.al-kpi .kpi-num .unit { font-size:11px; font-weight:700; color:#94a3b8; margin-left:3px; }
.al-kpi .kpi-lbl { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
.al-kpi .kpi-sub { font-size:10.5px; font-weight:600; color:#94a3b8; margin-top:2px; }
.al-kpi[data-tone="total"]    { --kpi-color:#dc2626; --kpi-bg:#fee2e2; }
.al-kpi[data-tone="avg"]      { --kpi-color:#f59e0b; --kpi-bg:#fef3c7; }
.al-kpi[data-tone="peak"]     { --kpi-color:#8b5cf6; --kpi-bg:#ede9fe; }
.al-kpi[data-tone="days"]     { --kpi-color:#0891b2; --kpi-bg:#cffafe; }

/* Chart wrap */
.al-chart-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:14px; }
.al-chart-title { font-size:13px; font-weight:800; color:#0f172a; margin-bottom:10px; display:flex; align-items:center; gap:8px; }
.al-chart-title i { color:#dc2626; }
#alChart { width:100%; height:260px; }

/* Table */
.al-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.al-table-bar {
    display:flex; gap:10px; align-items:center; flex-wrap:wrap;
    padding:14px 18px; border-bottom:1px solid #f1f5f9; background:#f8fafc;
}
.al-table-bar h3 { margin:0; font-size:13px; font-weight:800; color:#0f172a; flex:1; }
.al-btn {
    padding:8px 14px; border-radius:10px; font-size:12.5px; font-weight:800;
    border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    transition: all .15s ease; font-family:inherit;
}
.al-btn:active { transform: scale(.97); }
.al-btn-primary {
    background:linear-gradient(135deg, #dc2626, #b91c1c); color:#fff;
    box-shadow:0 4px 12px rgba(220,38,38,.35);
}
.al-btn-primary:hover { box-shadow:0 8px 20px rgba(220,38,38,.45); }
.al-btn-ghost { background:#fff; border:1.5px solid #e2e8f0; color:#475569; }
.al-btn-ghost:hover { border-color:#cbd5e1; }
.al-btn-danger { background:#fee2e2; color:#b91c1c; border:1.5px solid #fecaca; }
.al-btn-danger:hover { background:#fecaca; }

table.al-table { width:100%; border-collapse:collapse; }
.al-table th {
    background:#f8fafc; color:#475569; font-size:11px; font-weight:800;
    text-transform:uppercase; letter-spacing:.05em;
    padding:10px 14px; text-align:left; border-bottom:1px solid #e2e8f0;
}
.al-table td {
    padding:10px 14px; font-size:13px; color:#0f172a;
    border-bottom:1px solid #f1f5f9; vertical-align:middle;
}
.al-table tr:last-child td { border-bottom:none; }
.al-table tr:hover td { background:#fef9c3; }
.al-table input[type=date], .al-table input[type=number], .al-table input[type=text] {
    padding:6px 10px; border:1.5px solid transparent; border-radius:8px;
    font-family:inherit; font-size:13px; color:#0f172a; background:transparent;
    width:100%; min-width:90px;
    transition: all .15s ease;
}
.al-table input:hover { background:#fff; border-color:#e2e8f0; }
.al-table input:focus { outline:none; background:#fff; border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.1); }
.al-table .col-num { text-align:right; }
.al-table .col-num input { text-align:right; font-variant-numeric: tabular-nums; font-weight:700; }
.al-table .col-actions { text-align:right; white-space:nowrap; }
.al-table .col-actions button {
    width:32px; height:32px; border-radius:8px; border:1.5px solid transparent;
    background:transparent; cursor:pointer; display:inline-flex;
    align-items:center; justify-content:center;
    transition:all .15s ease;
}
.al-table .col-actions button:hover { background:#fee2e2; color:#dc2626; border-color:#fecaca; }

/* Empty / loading */
.al-empty { padding:40px 20px; text-align:center; color:#94a3b8; font-size:13px; font-weight:600; }
.al-empty i { display:block; font-size:36px; margin-bottom:10px; color:#cbd5e1; }

/* Pagination */
.al-pager {
    display:flex; gap:6px; justify-content:flex-end; align-items:center;
    padding:12px 18px; border-top:1px solid #f1f5f9; font-size:12px; color:#64748b;
}
.al-pager .info { margin-right:auto; font-weight:600; }
.al-pager button {
    min-width:32px; height:32px; border-radius:8px;
    background:#fff; border:1.5px solid #e2e8f0; cursor:pointer;
    font-size:12px; font-weight:700; color:#475569;
    transition: all .15s ease;
}
.al-pager button:hover:not(:disabled) { border-color:#dc2626; color:#dc2626; }
.al-pager button:disabled { opacity:.4; cursor:not-allowed; }
.al-pager button.is-current { background:#dc2626; color:#fff; border-color:#dc2626; }

/* Row flash */
@keyframes alRowFlash {
    0%   { background-color:#fef9c3; box-shadow: inset 3px 0 0 #f59e0b; }
    100% { background-color:transparent; box-shadow: inset 3px 0 0 transparent; }
}
.al-row-flash td { animation: alRowFlash 1.2s ease-out both; }
@media (prefers-reduced-motion: reduce) {
    .al-row-flash td { animation:none; background-color:#fef9c3; }
}

/* Dark mode */
body[data-theme='dark'] .al-card,
body[data-theme='dark'] .al-filter,
body[data-theme='dark'] .al-kpi,
body[data-theme='dark'] .al-chart-wrap,
body[data-theme='dark'] .al-table-wrap { background:#0f172a; border-color:#1e293b; }
body[data-theme='dark'] .al-table-bar { background:#0b1220; border-color:#1e293b; }
body[data-theme='dark'] .al-table th  { background:#0b1220; color:#cbd5e1; border-color:#1e293b; }
body[data-theme='dark'] .al-table td  { color:#e2e8f0; border-color:#1e293b; }
body[data-theme='dark'] .al-table tr:hover td { background:rgba(220,38,38,.1); }
body[data-theme='dark'] .al-kpi .kpi-num { color:#f1f5f9; }
body[data-theme='dark'] .al-table input:hover { background:#1e293b; border-color:#334155; }
body[data-theme='dark'] .al-table input:focus { background:#1e293b; }
body[data-theme='dark'] .al-pager button { background:#1e293b; border-color:#334155; color:#cbd5e1; }
</style>

<div class="al-shell">

    <!-- Header -->
    <div class="al-head">
        <div class="ic"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div style="flex:1; min-width:0;">
            <h2>บันทึกอุบัติเหตุรายวัน</h2>
            <p>เก็บสถิติอุบัติเหตุ / เหตุการณ์ไม่พึงประสงค์ของคลินิก · 1 แถวต่อวัน</p>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="al-filter">
        <div>
            <label>ตั้งแต่</label>
            <input type="date" id="al-from" onchange="alLoad()">
        </div>
        <div>
            <label>ถึง</label>
            <input type="date" id="al-to" onchange="alLoad()">
        </div>
        <div style="flex:1;">
            <label>ช่วงด่วน</label>
            <div class="al-chips" id="al-chips">
                <button type="button" class="al-chip" data-range="today">วันนี้</button>
                <button type="button" class="al-chip" data-range="month">เดือนนี้</button>
                <button type="button" class="al-chip" data-range="3month">3 เดือน</button>
                <button type="button" class="al-chip is-active" data-range="year">ปีนี้</button>
                <button type="button" class="al-chip" data-range="all">ทั้งหมด</button>
            </div>
        </div>
        <div>
            <button type="button" class="al-btn al-btn-ghost" onclick="alLoad()" title="โหลดข้อมูลใหม่">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="al-kpis">
        <div class="al-kpi" data-tone="total">
            <div class="kpi-ic"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="kpi-lbl">รวมในช่วง</div>
                <div class="kpi-num"><span id="al-kpi-total">0</span> <span class="unit">ครั้ง</span></div>
                <div class="kpi-sub" id="al-kpi-total-sub">—</div>
            </div>
        </div>
        <div class="al-kpi" data-tone="avg">
            <div class="kpi-ic"><i class="fa-solid fa-chart-simple"></i></div>
            <div>
                <div class="kpi-lbl">เฉลี่ย/วันที่บันทึก</div>
                <div class="kpi-num"><span id="al-kpi-avg">0</span> <span class="unit">ครั้ง</span></div>
                <div class="kpi-sub">ค่าเฉลี่ยจากวันที่มีรายการ</div>
            </div>
        </div>
        <div class="al-kpi" data-tone="peak">
            <div class="kpi-ic"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div>
                <div class="kpi-lbl">สูงสุด</div>
                <div class="kpi-num"><span id="al-kpi-peak">0</span> <span class="unit">ครั้ง</span></div>
                <div class="kpi-sub" id="al-kpi-peak-date">—</div>
            </div>
        </div>
        <div class="al-kpi" data-tone="days">
            <div class="kpi-ic"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <div class="kpi-lbl">วันที่มีรายการ</div>
                <div class="kpi-num"><span id="al-kpi-days">0</span> <span class="unit">วัน</span></div>
                <div class="kpi-sub">จำนวนวันที่มีอุบัติเหตุ</div>
            </div>
        </div>
    </div>

    <!-- Monthly chart -->
    <div class="al-chart-wrap">
        <div class="al-chart-title"><i class="fa-solid fa-chart-column"></i> สถิติรายเดือนในช่วงที่เลือก</div>
        <canvas id="alChart"></canvas>
    </div>

    <!-- Data table -->
    <div class="al-table-wrap">
        <div class="al-table-bar">
            <h3>ตารางบันทึก <span id="al-count-badge" style="background:#fee2e2;color:#b91c1c;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:800;margin-left:6px;">0</span></h3>
            <button class="al-btn al-btn-primary" onclick="alAddRow()">
                <i class="fa-solid fa-plus"></i> เพิ่มแถว
            </button>
        </div>
        <div style="overflow-x:auto;">
            <table class="al-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:150px;">วันที่</th>
                        <th style="width:80px;">วัน</th>
                        <th class="col-num" style="width:120px;">จำนวน (ครั้ง)</th>
                        <th>หมายเหตุ</th>
                        <th class="col-actions" style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody id="al-tbody"></tbody>
            </table>
        </div>
        <div class="al-pager" id="al-pager">
            <span class="info" id="al-pager-info">หน้า 1 / 1 · รวม 0 รายการ</span>
        </div>
    </div>
</div>

<script>
(() => {
    const CSRF = <?= json_encode($csrf) ?>;
    const ENDPOINT = 'ajax_accident_log.php';
    const PAGE_SIZE = 20;
    const THAI_DAYS = ['อา','จ','อ','พ','พฤ','ศ','ส'];
    const THAI_MONTHS_SHORT = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

    const state = {
        rows: [],
        page: 1,
        summary: { day_count:0, total:0, peak:0, peak_date:null, avg_per_day:0 },
    };
    let chart = null;

    const $ = (id) => document.getElementById(id);
    const toISODate = (d) => {
        const y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
        return `${y}-${m}-${dd}`;
    };
    const fmtThaiDate = (iso) => {
        if (!iso) return '—';
        const d = new Date(iso); if (isNaN(+d)) return iso;
        return `${d.getDate()} ${THAI_MONTHS_SHORT[d.getMonth()]} ${d.getFullYear()+543}`;
    };

    async function api(action, params = {}, method = 'GET') {
        let url = ENDPOINT + '?action=' + encodeURIComponent(action);
        const opts = { credentials:'same-origin' };
        if (method === 'POST') {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            for (const [k,v] of Object.entries(params)) fd.append(k, v ?? '');
            opts.method = 'POST'; opts.body = fd;
        } else {
            const qs = new URLSearchParams(params).toString();
            if (qs) url += '&' + qs;
        }
        const r = await fetch(url, opts);
        const json = await r.json().catch(() => ({ ok:false, message:'invalid json' }));
        if (!json.ok) throw new Error(json.message || 'request failed');
        return json;
    }

    function applyRangeChip(range) {
        document.querySelectorAll('.al-chip').forEach(c => c.classList.toggle('is-active', c.dataset.range === range));
        const now = new Date();
        let from, to;
        if (range === 'today') {
            from = to = toISODate(now);
        } else if (range === 'month') {
            from = toISODate(new Date(now.getFullYear(), now.getMonth(), 1));
            to   = toISODate(now);
        } else if (range === '3month') {
            from = toISODate(new Date(now.getFullYear(), now.getMonth()-2, 1));
            to   = toISODate(now);
        } else if (range === 'year') {
            from = toISODate(new Date(now.getFullYear(), 0, 1));
            to   = toISODate(now);
        } else { // all
            from = ''; to = '';
        }
        $('al-from').value = from;
        $('al-to').value = to;
    }

    async function alLoad() {
        try {
            const params = {};
            const from = $('al-from').value, to = $('al-to').value;
            if (from) params.from = from;
            if (to)   params.to = to;
            const [list, monthly] = await Promise.all([
                api('list', params),
                api('analytics:monthly', params),
            ]);
            // newest-first sort (UI only — backend stays ASC for reports)
            state.rows = (list.data || []).slice().sort((a,b) => {
                if (a.entry_date !== b.entry_date) return a.entry_date < b.entry_date ? 1 : -1;
                return (+b.id || 0) - (+a.id || 0);
            });
            state.summary = list.summary;
            renderKpi();
            renderTable();
            renderChart(monthly.data || []);
        } catch (e) {
            Swal.fire({ icon:'error', title:'โหลดข้อมูลล้มเหลว', text: e.message || String(e) });
        }
    }

    function renderKpi() {
        const s = state.summary;
        $('al-kpi-total').textContent     = (s.total || 0).toLocaleString('en-US');
        $('al-kpi-total-sub').textContent = s.day_count > 0 ? `จาก ${s.day_count} วันที่บันทึก` : '—';
        $('al-kpi-avg').textContent       = (+s.avg_per_day || 0).toFixed(1);
        $('al-kpi-peak').textContent      = (s.peak || 0).toLocaleString('en-US');
        $('al-kpi-peak-date').textContent = s.peak_date ? `เมื่อ ${fmtThaiDate(s.peak_date)}` : '—';
        $('al-kpi-days').textContent      = (s.day_count || 0).toLocaleString('en-US');
        $('al-count-badge').textContent   = (state.rows.length || 0).toLocaleString('en-US');
    }

    function renderTable() {
        const tbody = $('al-tbody');
        const total = state.rows.length;
        const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (state.page > totalPages) state.page = totalPages;
        const startIdx = (state.page - 1) * PAGE_SIZE;
        const slice = state.rows.slice(startIdx, startIdx + PAGE_SIZE);

        if (!slice.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="al-empty">
                <i class="fa-solid fa-shield-heart"></i>
                ยังไม่มีบันทึก — กด "เพิ่มแถว" เพื่อเริ่ม
            </td></tr>`;
        } else {
            tbody.innerHTML = slice.map((r, i) => {
                const idx = startIdx + i + 1;
                const dow = THAI_DAYS[new Date(r.entry_date).getDay()];
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                return `
                <tr data-id="${r.id}">
                    <td>${idx}</td>
                    <td><input type="date" value="${esc(r.entry_date)}" data-field="entry_date" onchange="alRowEdit(${r.id}, this)"></td>
                    <td><span style="font-weight:700;color:#64748b">${dow}.</span></td>
                    <td class="col-num"><input type="number" min="0" value="${+r.accident_count || 0}" data-field="accident_count" onchange="alRowEdit(${r.id}, this)" onfocus="this.select()"></td>
                    <td><input type="text" value="${esc(r.note)}" placeholder="—" data-field="note" onchange="alRowEdit(${r.id}, this)"></td>
                    <td class="col-actions">
                        <button type="button" onclick="alRowDelete(${r.id})" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        }

        renderPager(total, totalPages);
    }

    function renderPager(total, totalPages) {
        const p = $('al-pager');
        const cur = state.page;
        const win = 2; // window ±2
        let html = `<span class="info">หน้า ${cur} / ${totalPages} · รวม ${total.toLocaleString('en-US')} รายการ</span>`;
        html += `<button onclick="alGoPage(1)"        ${cur<=1?'disabled':''} title="หน้าแรก">«</button>`;
        html += `<button onclick="alGoPage(${cur-1})" ${cur<=1?'disabled':''}>‹</button>`;
        const start = Math.max(1, cur - win), end = Math.min(totalPages, cur + win);
        for (let i = start; i <= end; i++) {
            html += `<button onclick="alGoPage(${i})" class="${i===cur?'is-current':''}">${i}</button>`;
        }
        html += `<button onclick="alGoPage(${cur+1})" ${cur>=totalPages?'disabled':''}>›</button>`;
        html += `<button onclick="alGoPage(${totalPages})" ${cur>=totalPages?'disabled':''} title="หน้าสุดท้าย">»</button>`;
        p.innerHTML = html;
    }
    window.alGoPage = (n) => { state.page = n; renderTable(); window.scrollTo({ top: $('al-tbody').offsetTop - 100, behavior:'smooth' }); };

    function renderChart(monthly) {
        const ctx = $('alChart').getContext('2d');
        const labels = monthly.map(m => {
            const [y, mo] = m.ym.split('-');
            return `${THAI_MONTHS_SHORT[+mo-1]} ${(+y + 543) % 100}`;
        });
        const data = monthly.map(m => +m.total || 0);
        if (chart) chart.destroy();
        const isDark = document.body.getAttribute('data-theme') === 'dark';
        chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'จำนวน (ครั้ง)',
                    data,
                    backgroundColor: 'rgba(220,38,38,.7)',
                    borderColor: '#dc2626',
                    borderWidth: 1,
                    borderRadius: 6,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor:'#0f172a', titleColor:'#fff', bodyColor:'#cbd5e1' },
                },
                scales: {
                    x: { ticks: { color: isDark ? '#cbd5e1' : '#64748b' }, grid: { display:false } },
                    y: {
                        beginAtZero: true,
                        ticks: { color: isDark ? '#cbd5e1' : '#64748b', precision:0 },
                        grid: { color: isDark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)' },
                    },
                },
            },
        });
    }

    // Re-render chart on theme switch
    new MutationObserver(muts => {
        for (const m of muts) {
            if (m.attributeName === 'data-theme') {
                // Re-fetch (small cost) so chart picks up new theme
                alLoad();
                break;
            }
        }
    }).observe(document.body, { attributes:true, attributeFilter:['data-theme'] });

    /* ───── CRUD ───── */
    window.alAddRow = async function() {
        const today = toISODate(new Date());
        try {
            const r = await api('daily:create', { entry_date: today, accident_count: 0, note: '' }, 'POST');
            if (r.duplicate) {
                // ถ้ามีอยู่แล้ว → highlight แถวเดิมแทน insert
                state.page = 1;
                await alLoad();
                const existing = document.querySelector(`#al-tbody tr[data-id]`);
                if (existing) {
                    existing.scrollIntoView({ behavior:'smooth', block:'center' });
                    existing.classList.add('al-row-flash');
                    setTimeout(() => existing.classList.remove('al-row-flash'), 1200);
                }
                Swal.fire({ toast:true, position:'top-end', icon:'info', title: r.message, timer: 2500, showConfirmButton:false });
            } else {
                state.page = 1;
                await alLoad();
                requestAnimationFrame(() => {
                    const firstRow = document.querySelector('#al-tbody tr[data-id]');
                    if (!firstRow) return;
                    firstRow.scrollIntoView({ behavior:'smooth', block:'center' });
                    firstRow.classList.add('al-row-flash');
                    setTimeout(() => firstRow.classList.remove('al-row-flash'), 1200);
                    const numInput = firstRow.querySelector('input[type="number"]');
                    if (numInput) { numInput.focus(); numInput.select(); }
                });
            }
        } catch (e) {
            Swal.fire({ icon:'error', title:'เพิ่มแถวล้มเหลว', text: e.message || String(e) });
        }
    };

    window.alRowEdit = async function(id, input) {
        const row = state.rows.find(r => +r.id === +id);
        if (!row) return;
        const field = input.dataset.field;
        let value = input.value;
        if (field === 'accident_count') value = Math.max(0, +value || 0);
        try {
            await api('daily:update', {
                id,
                entry_date:     field === 'entry_date'     ? value : row.entry_date,
                accident_count: field === 'accident_count' ? value : row.accident_count,
                note:           field === 'note'           ? value : row.note,
            }, 'POST');
            // Optimistic local update + refresh summary
            row[field] = value;
            await alLoad();
        } catch (e) {
            Swal.fire({ icon:'error', title:'บันทึกล้มเหลว', text: e.message || String(e) });
            // Revert input to old value
            input.value = row[field];
        }
    };

    window.alRowDelete = async function(id) {
        const row = state.rows.find(r => +r.id === +id);
        const dateLabel = row ? fmtThaiDate(row.entry_date) : '';
        const r = await Swal.fire({
            icon:'warning', title:'ลบรายการนี้?',
            html: dateLabel ? `วันที่ <b>${dateLabel}</b> · จำนวน <b>${row.accident_count}</b> ครั้ง` : '',
            showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#dc2626',
        });
        if (!r.isConfirmed) return;
        try {
            await api('daily:delete', { id }, 'POST');
            await alLoad();
        } catch (e) {
            Swal.fire({ icon:'error', title:'ลบล้มเหลว', text: e.message || String(e) });
        }
    };

    /* ───── Init ───── */
    window.alLoad = alLoad;
    document.querySelectorAll('.al-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            applyRangeChip(chip.dataset.range);
            alLoad();
        });
    });
    // Default: ปีนี้
    applyRangeChip('year');
    alLoad();
})();
</script>
