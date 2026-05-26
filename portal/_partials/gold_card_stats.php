<?php
/**
 * portal/_partials/gold_card_stats.php
 * สถิติบัตรทอง — UI partial · 1 row/เดือน (ยอดสมาชิกรวม ณ สิ้นเดือน)
 *
 * AJAX endpoint: portal/ajax_gold_card_stats.php
 * Template: portal/gold_card_stats_template.php
 * Import: portal/gold_card_stats_import.php
 */
declare(strict_types=1);
$csrf = $_SESSION['csrf_token'] ?? '';
$currentYearBE = (int)date('Y') + 543;
$currentMonth  = (int)date('n');
?>

<style>
/* ─── Gold Card Stats — styles ──────────────────────────────── */
.gcs-shell { max-width: 1400px; margin: 0 auto; }
.gcs-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; }

/* Header */
.gcs-head { display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.gcs-head .ic {
    width:44px; height:44px; border-radius:12px;
    background:linear-gradient(135deg, #fef3c7, #fde68a);
    color:#b45309; border:1.5px solid #fcd34d;
    display:flex; align-items:center; justify-content:center; font-size:18px;
}
.gcs-head h2 { margin:0; font-size:20px; font-weight:900; color:#0f172a; letter-spacing:-0.01em; }
.gcs-head p  { margin:0; font-size:12px; color:#64748b; font-weight:600; }

/* Filter bar */
.gcs-filter {
    display:flex; gap:12px; align-items:end; flex-wrap:wrap;
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:14px 18px; margin-bottom:14px;
}
.gcs-filter label { display:block; font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.gcs-filter input[type=number] {
    padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:10px;
    font-family:inherit; font-size:13px; color:#0f172a; background:#fff;
    width:110px; font-variant-numeric: tabular-nums;
    transition: all .15s ease;
}
.gcs-filter input[type=number]:focus { outline:none; border-color:#d97706; box-shadow:0 0 0 4px rgba(217,119,6,.12); }

/* KPI cards */
.gcs-kpis {
    display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap:12px; margin-bottom:14px;
}
.gcs-kpi {
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:14px 16px; display:flex; align-items:center; gap:12px;
    position:relative; overflow:hidden;
}
.gcs-kpi::before {
    content:''; position:absolute; top:0; left:0; bottom:0; width:4px;
    background:var(--kpi-color, #94a3b8); border-radius:14px 0 0 14px;
}
.gcs-kpi .kpi-ic {
    width:40px; height:40px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; flex-shrink:0;
    background:var(--kpi-bg, #f1f5f9); color:var(--kpi-color, #475569);
}
.gcs-kpi .kpi-num { font-size:22px; font-weight:900; color:#0f172a; line-height:1.1; font-variant-numeric: tabular-nums; }
.gcs-kpi .kpi-num .unit { font-size:11px; font-weight:700; color:#94a3b8; margin-left:3px; }
.gcs-kpi .kpi-lbl { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
.gcs-kpi .kpi-sub { font-size:10.5px; font-weight:600; color:#94a3b8; margin-top:2px; }
.gcs-kpi[data-tone="latest"] { --kpi-color:#d97706; --kpi-bg:#fef3c7; }
.gcs-kpi[data-tone="peak"]   { --kpi-color:#16a34a; --kpi-bg:#dcfce7; }
.gcs-kpi[data-tone="low"]    { --kpi-color:#dc2626; --kpi-bg:#fee2e2; }
.gcs-kpi[data-tone="rows"]   { --kpi-color:#6366f1; --kpi-bg:#e0e7ff; }

/* Chart wrap */
.gcs-chart-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:14px; }
.gcs-chart-title { font-size:13px; font-weight:800; color:#0f172a; margin-bottom:10px; display:flex; align-items:center; gap:8px; }
.gcs-chart-title i { color:#d97706; }
.gcs-chart-box { position: relative; width: 100%; height: 300px; }
.gcs-chart-box canvas { max-height: 100%; }

/* Table */
.gcs-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.gcs-table-bar {
    display:flex; gap:10px; align-items:center; flex-wrap:wrap;
    padding:14px 18px; border-bottom:1px solid #f1f5f9; background:#f8fafc;
}
.gcs-table-bar h3 { margin:0; font-size:13px; font-weight:800; color:#0f172a; flex:1; }
.gcs-btn {
    padding:8px 14px; border-radius:10px; font-size:12.5px; font-weight:800;
    border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    transition: all .15s ease; font-family:inherit; text-decoration:none;
}
.gcs-btn:hover { text-decoration:none; }
.gcs-btn:active { transform: scale(.97); }
.gcs-btn-primary {
    background:linear-gradient(135deg, #d97706, #b45309); color:#fff;
    box-shadow:0 4px 12px rgba(217,119,6,.35);
}
.gcs-btn-primary:hover { box-shadow:0 8px 20px rgba(217,119,6,.45); }
.gcs-btn-ghost { background:#fff; border:1.5px solid #e2e8f0; color:#475569; }
.gcs-btn-ghost:hover { border-color:#cbd5e1; }

table.gcs-table { width:100%; border-collapse:collapse; }
.gcs-table th {
    background:#f8fafc; color:#475569; font-size:11px; font-weight:800;
    text-transform:uppercase; letter-spacing:.05em;
    padding:10px 14px; text-align:left; border-bottom:1px solid #e2e8f0;
}
.gcs-table td {
    padding:10px 14px; font-size:13px; color:#0f172a;
    border-bottom:1px solid #f1f5f9; vertical-align:middle;
}
.gcs-table tr:last-child td { border-bottom:none; }
.gcs-table tr:hover td { background:#fef9c3; }
.gcs-table input[type=number], .gcs-table input[type=text], .gcs-table select {
    padding:6px 10px; border:1.5px solid transparent; border-radius:8px;
    font-family:inherit; font-size:13px; color:#0f172a; background:transparent;
    width:100%; min-width:60px;
    transition: all .15s ease;
}
.gcs-table input:hover, .gcs-table select:hover { background:#fff; border-color:#e2e8f0; }
.gcs-table input:focus, .gcs-table select:focus { outline:none; background:#fff; border-color:#d97706; box-shadow:0 0 0 3px rgba(217,119,6,.1); }
.gcs-table .col-num { text-align:right; }
.gcs-table .col-num input { text-align:right; font-variant-numeric: tabular-nums; font-weight:700; }
.gcs-table .col-actions { text-align:right; white-space:nowrap; }
.gcs-table .col-actions button {
    width:32px; height:32px; border-radius:8px; border:1.5px solid transparent;
    background:transparent; cursor:pointer; display:inline-flex;
    align-items:center; justify-content:center;
    transition:all .15s ease;
}
.gcs-table .col-actions button:hover { background:#fee2e2; color:#dc2626; border-color:#fecaca; }

.gcs-empty { padding:40px 20px; text-align:center; color:#94a3b8; font-size:13px; font-weight:600; }
.gcs-empty i { display:block; font-size:36px; margin-bottom:10px; color:#cbd5e1; }

.gcs-pager {
    display:flex; gap:6px; justify-content:flex-end; align-items:center;
    padding:12px 18px; border-top:1px solid #f1f5f9; font-size:12px; color:#64748b;
}
.gcs-pager .info { margin-right:auto; font-weight:600; }
.gcs-pager button {
    min-width:32px; height:32px; border-radius:8px;
    background:#fff; border:1.5px solid #e2e8f0; cursor:pointer;
    font-size:12px; font-weight:700; color:#475569;
    transition: all .15s ease;
}
.gcs-pager button:hover:not(:disabled) { border-color:#d97706; color:#d97706; }
.gcs-pager button:disabled { opacity:.4; cursor:not-allowed; }
.gcs-pager button.is-current { background:#d97706; color:#fff; border-color:#d97706; }

@keyframes gcsRowFlash {
    0%   { background-color:#fef3c7; box-shadow: inset 3px 0 0 #d97706; }
    100% { background-color:transparent; box-shadow: inset 3px 0 0 transparent; }
}
.gcs-row-flash td { animation: gcsRowFlash 1.2s ease-out both; }
@media (prefers-reduced-motion: reduce) {
    .gcs-row-flash td { animation:none; background-color:#fef3c7; }
}

/* Dark mode */
body[data-theme='dark'] .gcs-card,
body[data-theme='dark'] .gcs-filter,
body[data-theme='dark'] .gcs-kpi,
body[data-theme='dark'] .gcs-chart-wrap,
body[data-theme='dark'] .gcs-table-wrap { background:#0f172a; border-color:#1e293b; }
body[data-theme='dark'] .gcs-table-bar { background:#0b1220; border-color:#1e293b; }
body[data-theme='dark'] .gcs-table th  { background:#0b1220; color:#cbd5e1; border-color:#1e293b; }
body[data-theme='dark'] .gcs-table td  { color:#e2e8f0; border-color:#1e293b; }
body[data-theme='dark'] .gcs-table tr:hover td { background:rgba(217,119,6,.1); }
body[data-theme='dark'] .gcs-kpi .kpi-num { color:#f1f5f9; }
body[data-theme='dark'] .gcs-table input:hover, body[data-theme='dark'] .gcs-table select:hover { background:#1e293b; border-color:#334155; }
body[data-theme='dark'] .gcs-table input:focus, body[data-theme='dark'] .gcs-table select:focus { background:#1e293b; }
body[data-theme='dark'] .gcs-pager button { background:#1e293b; border-color:#334155; color:#cbd5e1; }
</style>

<div class="gcs-shell">

    <div class="gcs-head">
        <div class="ic"><i class="fa-solid fa-shield-heart"></i></div>
        <div style="flex:1; min-width:0;">
            <h2>สถิติบัตรทอง — รายเดือน</h2>
            <p>ยอดสมาชิกรวม ณ สิ้นเดือน (snapshot) · ไม่กระทบหน้า "บัตรทอง" ที่จัดการใบสมัครรายคน</p>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="gcs-filter">
        <div>
            <label>ปี พ.ศ. ตั้งแต่</label>
            <input type="number" id="gcs-from-year" min="2500" max="2700" placeholder="2563" onchange="gcsLoad()">
        </div>
        <div>
            <label>ถึง</label>
            <input type="number" id="gcs-to-year" min="2500" max="2700" placeholder="<?= $currentYearBE ?>" onchange="gcsLoad()">
        </div>
        <div style="flex:1;"></div>
        <div>
            <button type="button" class="gcs-btn gcs-btn-ghost" onclick="gcsLoad()" title="โหลดข้อมูลใหม่">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="gcs-kpis">
        <div class="gcs-kpi" data-tone="latest">
            <div class="kpi-ic"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="kpi-lbl">ยอดล่าสุด</div>
                <div class="kpi-num"><span id="gcs-kpi-latest">0</span> <span class="unit">คน</span></div>
                <div class="kpi-sub" id="gcs-kpi-latest-sub">—</div>
            </div>
        </div>
        <div class="gcs-kpi" data-tone="peak">
            <div class="kpi-ic"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div>
                <div class="kpi-lbl">สูงสุด</div>
                <div class="kpi-num"><span id="gcs-kpi-peak">0</span> <span class="unit">คน</span></div>
                <div class="kpi-sub" id="gcs-kpi-peak-sub">—</div>
            </div>
        </div>
        <div class="gcs-kpi" data-tone="low">
            <div class="kpi-ic"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div>
                <div class="kpi-lbl">ต่ำสุด</div>
                <div class="kpi-num"><span id="gcs-kpi-low">0</span> <span class="unit">คน</span></div>
                <div class="kpi-sub" id="gcs-kpi-low-sub">—</div>
            </div>
        </div>
        <div class="gcs-kpi" data-tone="rows">
            <div class="kpi-ic"><i class="fa-solid fa-table-list"></i></div>
            <div>
                <div class="kpi-lbl">รวมเดือนที่บันทึก</div>
                <div class="kpi-num"><span id="gcs-kpi-rows">0</span> <span class="unit">เดือน</span></div>
                <div class="kpi-sub">ในช่วงปีที่เลือก</div>
            </div>
        </div>
    </div>

    <!-- Trend chart -->
    <div class="gcs-chart-wrap">
        <div class="gcs-chart-title"><i class="fa-solid fa-chart-line"></i> แนวโน้มยอดสมาชิก · เทียบรายปี (year-over-year)</div>
        <div class="gcs-chart-box"><canvas id="gcsChart"></canvas></div>
    </div>

    <!-- Data table -->
    <div class="gcs-table-wrap">
        <div class="gcs-table-bar">
            <h3>ตารางสถิติ <span id="gcs-count-badge" style="background:#fef3c7;color:#b45309;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:800;margin-left:6px;">0</span></h3>
            <a href="gold_card_stats_template.php" target="_blank" rel="noopener"
               download="gold_card_stats_template.xlsx"
               class="gcs-btn gcs-btn-ghost" title="ดาวน์โหลด Excel เปล่า">
                <i class="fa-solid fa-file-arrow-down"></i> Template
            </a>
            <button type="button" class="gcs-btn gcs-btn-ghost" onclick="document.getElementById('gcs-import-file').click()" title="นำเข้าจาก Excel">
                <i class="fa-solid fa-file-import"></i> Import
            </button>
            <input type="file" id="gcs-import-file" accept=".xlsx,.xls,.csv" style="display:none" onchange="gcsImportExcel(event)">
            <button class="gcs-btn gcs-btn-primary" onclick="gcsAddRow()">
                <i class="fa-solid fa-plus"></i> เพิ่มแถว
            </button>
        </div>
        <div style="overflow-x:auto;">
            <table class="gcs-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:100px;">ปี พ.ศ.</th>
                        <th style="width:150px;">เดือน</th>
                        <th class="col-num" style="width:140px;">จำนวน (คน)</th>
                        <th>หมายเหตุ</th>
                        <th class="col-actions" style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody id="gcs-tbody"></tbody>
            </table>
        </div>
        <div class="gcs-pager" id="gcs-pager">
            <span class="info" id="gcs-pager-info">หน้า 1 / 1 · รวม 0 รายการ</span>
        </div>
    </div>
</div>

<script>
(() => {
    const CSRF = <?= json_encode($csrf) ?>;
    const ENDPOINT = 'ajax_gold_card_stats.php';
    const PAGE_SIZE = 20;
    const THAI_MONTHS = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const CURRENT_YEAR_BE = <?= $currentYearBE ?>;
    const CURRENT_MONTH   = <?= $currentMonth ?>;

    const state = {
        rows: [],
        page: 1,
        summary: {},
        yearly: [],
    };
    let chart = null;

    const $ = (id) => document.getElementById(id);

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

    async function gcsLoad() {
        try {
            const params = {};
            const fy = $('gcs-from-year').value, ty = $('gcs-to-year').value;
            if (fy) params.from_year = fy;
            if (ty) params.to_year = ty;
            const [list, yearly] = await Promise.all([
                api('list', params),
                api('analytics:yearly', params),
            ]);
            // newest-first sort (UI only)
            state.rows = (list.data || []).slice().sort((a,b) => {
                if (+a.year_be !== +b.year_be) return +b.year_be - +a.year_be;
                return +b.month - +a.month;
            });
            state.summary = list.summary;
            state.yearly  = yearly.data || [];
            renderKpi();
            renderTable();
            renderChart(state.yearly);
        } catch (e) {
            Swal.fire({ icon:'error', title:'โหลดข้อมูลล้มเหลว', text: e.message || String(e) });
        }
    }

    function renderKpi() {
        const s = state.summary || {};
        $('gcs-kpi-latest').textContent     = (s.latest_value || 0).toLocaleString('en-US');
        $('gcs-kpi-latest-sub').textContent = s.latest_label ? `เดือน ${s.latest_label}` : '—';
        $('gcs-kpi-peak').textContent       = (s.peak || 0).toLocaleString('en-US');
        $('gcs-kpi-peak-sub').textContent   = s.peak_label ? `เดือน ${s.peak_label}` : '—';
        $('gcs-kpi-low').textContent        = (s.low || 0).toLocaleString('en-US');
        $('gcs-kpi-low-sub').textContent    = s.low_label ? `เดือน ${s.low_label}` : '—';
        $('gcs-kpi-rows').textContent       = (s.row_count || 0).toLocaleString('en-US');
        $('gcs-count-badge').textContent    = (state.rows.length || 0).toLocaleString('en-US');
    }

    function renderTable() {
        const tbody = $('gcs-tbody');
        const total = state.rows.length;
        const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (state.page > totalPages) state.page = totalPages;
        const startIdx = (state.page - 1) * PAGE_SIZE;
        const slice = state.rows.slice(startIdx, startIdx + PAGE_SIZE);

        if (!slice.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="gcs-empty">
                <i class="fa-solid fa-shield-heart"></i>
                ยังไม่มีบันทึก — กด "เพิ่มแถว" หรือ Import จาก Excel เพื่อเริ่ม
            </td></tr>`;
        } else {
            tbody.innerHTML = slice.map((r, i) => {
                const idx = startIdx + i + 1;
                const monthOpts = Array.from({length:12}, (_, k) =>
                    `<option value="${k+1}" ${(+r.month === k+1) ? 'selected' : ''}>${THAI_MONTHS[k+1]}</option>`).join('');
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                return `
                <tr data-id="${r.id}">
                    <td>${idx}</td>
                    <td><input type="number" min="2500" max="2700" value="${+r.year_be}" data-field="year_be" onchange="gcsRowEdit(${r.id}, this)"></td>
                    <td><select data-field="month" onchange="gcsRowEdit(${r.id}, this)">${monthOpts}</select></td>
                    <td class="col-num"><input type="number" min="0" value="${+r.member_count || 0}" data-field="member_count" onchange="gcsRowEdit(${r.id}, this)" onfocus="this.select()"></td>
                    <td><input type="text" value="${esc(r.note)}" placeholder="—" data-field="note" onchange="gcsRowEdit(${r.id}, this)"></td>
                    <td class="col-actions">
                        <button type="button" onclick="gcsRowDelete(${r.id})" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        }
        renderPager(total, totalPages);
    }

    function renderPager(total, totalPages) {
        const p = $('gcs-pager');
        const cur = state.page;
        const win = 2;
        let html = `<span class="info">หน้า ${cur} / ${totalPages} · รวม ${total.toLocaleString('en-US')} รายการ</span>`;
        html += `<button onclick="gcsGoPage(1)"        ${cur<=1?'disabled':''} title="หน้าแรก">«</button>`;
        html += `<button onclick="gcsGoPage(${cur-1})" ${cur<=1?'disabled':''}>‹</button>`;
        const start = Math.max(1, cur - win), end = Math.min(totalPages, cur + win);
        for (let i = start; i <= end; i++) {
            html += `<button onclick="gcsGoPage(${i})" class="${i===cur?'is-current':''}">${i}</button>`;
        }
        html += `<button onclick="gcsGoPage(${cur+1})" ${cur>=totalPages?'disabled':''}>›</button>`;
        html += `<button onclick="gcsGoPage(${totalPages})" ${cur>=totalPages?'disabled':''} title="หน้าสุดท้าย">»</button>`;
        p.innerHTML = html;
    }
    window.gcsGoPage = (n) => { state.page = n; renderTable(); window.scrollTo({ top: $('gcs-tbody').offsetTop - 100, behavior:'smooth' }); };

    function renderChart(yearly) {
        const ctx = $('gcsChart').getContext('2d');
        // Group by year → series per year, 12 month points
        const byYear = {};
        yearly.forEach(r => {
            const y = +r.year_be;
            if (!byYear[y]) byYear[y] = Array(12).fill(null);
            byYear[y][+r.month - 1] = +r.member_count;
        });
        const years = Object.keys(byYear).map(Number).sort((a,b) => a-b);
        const palette = ['#d97706','#16a34a','#0891b2','#8b5cf6','#dc2626','#ec4899','#0ea5e9','#10b981','#f59e0b','#6366f1'];
        const datasets = years.map((y, i) => ({
            label: 'พ.ศ. ' + y,
            data: byYear[y],
            borderColor: palette[i % palette.length],
            backgroundColor: palette[i % palette.length] + '20',
            tension: 0.25,
            spanGaps: true,
            pointRadius: 3,
            pointHoverRadius: 5,
            borderWidth: 2,
        }));
        if (chart) chart.destroy();
        const isDark = document.body.getAttribute('data-theme') === 'dark';
        chart = new Chart(ctx, {
            type: 'line',
            data: { labels: THAI_MONTHS.slice(1).map(m => m.substring(0,3)), datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: isDark ? '#e2e8f0' : '#334155', font: { weight: 700 } } },
                    tooltip: {
                        backgroundColor:'#0f172a', titleColor:'#fff', bodyColor:'#cbd5e1',
                        callbacks: { label: ctx => `${ctx.dataset.label}: ${(+ctx.parsed.y).toLocaleString('en-US')} คน` },
                    },
                },
                scales: {
                    x: { ticks: { color: isDark ? '#cbd5e1' : '#64748b' }, grid: { display:false } },
                    y: { beginAtZero: false,
                         ticks: { color: isDark ? '#cbd5e1' : '#64748b', callback: v => v.toLocaleString('en-US') },
                         grid:  { color: isDark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)' } },
                },
            },
        });
    }

    new MutationObserver(muts => {
        for (const m of muts) {
            if (m.attributeName === 'data-theme') { renderChart(state.yearly); break; }
        }
    }).observe(document.body, { attributes:true, attributeFilter:['data-theme'] });

    /* ───── CRUD ───── */
    window.gcsAddRow = async function() {
        try {
            const r = await api('monthly:create', {
                year_be: CURRENT_YEAR_BE, month: CURRENT_MONTH, member_count: 0, note: '',
            }, 'POST');
            state.page = 1;
            await gcsLoad();
            requestAnimationFrame(() => {
                const target = document.querySelector(`#gcs-tbody tr[data-id="${r.id}"]`)
                    || document.querySelector('#gcs-tbody tr[data-id]');
                if (!target) return;
                target.scrollIntoView({ behavior:'smooth', block:'center' });
                target.classList.add('gcs-row-flash');
                setTimeout(() => target.classList.remove('gcs-row-flash'), 1200);
                const numInput = target.querySelector('input[type="number"][data-field="member_count"]');
                if (numInput) { numInput.focus(); numInput.select(); }
            });
            if (r.duplicate) {
                Swal.fire({ toast:true, position:'top-end', icon:'info', title: r.message, timer: 2800, showConfirmButton:false });
            }
        } catch (e) {
            Swal.fire({ icon:'error', title:'เพิ่มแถวล้มเหลว', text: e.message || String(e) });
        }
    };

    window.gcsRowEdit = async function(id, input) {
        const row = state.rows.find(r => +r.id === +id);
        if (!row) return;
        const field = input.dataset.field;
        let value = input.value;
        if (field === 'member_count' || field === 'year_be' || field === 'month') {
            value = Math.max(0, +value || 0);
        }
        try {
            await api('monthly:update', {
                id,
                year_be:      field === 'year_be'      ? value : row.year_be,
                month:        field === 'month'        ? value : row.month,
                member_count: field === 'member_count' ? value : row.member_count,
                note:         field === 'note'         ? value : row.note,
            }, 'POST');
            row[field] = value;
            await gcsLoad();
        } catch (e) {
            Swal.fire({ icon:'error', title:'บันทึกล้มเหลว', text: e.message || String(e) });
            input.value = row[field];
        }
    };

    window.gcsRowDelete = async function(id) {
        const row = state.rows.find(r => +r.id === +id);
        const lbl = row ? `${THAI_MONTHS[+row.month]} ${row.year_be}` : '';
        const r = await Swal.fire({
            icon:'warning', title:'ลบรายการนี้?',
            html: lbl ? `<b>${lbl}</b> · ${row.member_count.toLocaleString('en-US')} คน` : '',
            showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#dc2626',
        });
        if (!r.isConfirmed) return;
        try { await api('monthly:delete', { id }, 'POST'); await gcsLoad(); }
        catch (e) { Swal.fire({ icon:'error', title:'ลบล้มเหลว', text: e.message || String(e) }); }
    };

    /* ───── Excel Import ───── */
    window.gcsImportExcel = async function(e) {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        e.target.value = '';
        if (file.size > 5 * 1024 * 1024) {
            Swal.fire({ icon:'warning', title:'ไฟล์ใหญ่เกินไป', text:'รองรับสูงสุด 5 MB' });
            return;
        }
        const c = await Swal.fire({
            icon: 'question', title: 'นำเข้าไฟล์นี้?',
            html: `
                <div style="text-align:left;font-size:13px;color:#475569;line-height:1.6">
                    ไฟล์: <b>${file.name.replace(/[<>&]/g,'')}</b>
                    <div style="margin-top:10px;padding:8px 10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:11.5px;color:#78350f">
                        <i class="fa-solid fa-circle-info"></i>
                        เดือนที่มีอยู่แล้วในระบบ <b>จะถูกอัปเดตทับ</b> (ไม่เพิ่มเป็นแถวใหม่)
                    </div>
                </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-file-import"></i> นำเข้า',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#d97706',
        });
        if (!c.isConfirmed) return;
        Swal.fire({ title:'กำลังนำเข้า...', didOpen: () => Swal.showLoading(), allowOutsideClick:false });
        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('file', file);
            const r = await fetch('gold_card_stats_import.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json().catch(() => ({ ok:false, message:'invalid json' }));
            if (!j.ok) throw new Error(j.message || 'import failed');
            Swal.fire({
                icon: 'success', title: 'นำเข้าสำเร็จ',
                html: `<div style="text-align:left;font-size:13px;line-height:1.8">
                    เพิ่มใหม่ <b style="color:#16a34a">${j.inserted}</b> ·
                    อัปเดต <b style="color:#0891b2">${j.updated}</b> ·
                    ข้าม <b style="color:#94a3b8">${j.skipped}</b> แถว
                </div>`,
            });
            await gcsLoad();
        } catch (err) {
            Swal.fire({ icon:'error', title:'นำเข้าล้มเหลว', text: err.message || String(err) });
        }
    };

    /* Init */
    window.gcsLoad = gcsLoad;
    gcsLoad();
})();
</script>
