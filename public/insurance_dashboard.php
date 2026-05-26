<?php
/**
 * public/insurance_dashboard.php
 *
 * Public dashboard — เปิดให้บุคคลภายนอกดูได้โดยไม่ต้อง login
 * รองรับ multi-workbook: /public/insurance_dashboard.php?wb=<slug>
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pageTitle = (defined('SITE_NAME') ? SITE_NAME : 'RSU Medical Clinic') . ' — Insurance Dashboard';

// อ่าน workbook slug จาก URL (ถ้ามี) — ไว้ส่งให้ JS เริ่ม fetch
$wbSlug = isset($_GET['wb']) ? trim((string)$_GET['wb']) : '';
$wbSlug = preg_replace('/[^a-z0-9_\-]/', '', strtolower($wbSlug));

// API base URL
$apiUrl = '../api/dashboard_public.php';
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    body { font-family: 'Prompt', sans-serif; background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%); min-height: 100vh; }
    .ip-card {
        background: #fff; border: 1.5px solid #e2e8f0; border-radius: 1.5rem;
        padding: 1.5rem; transition: all .2s; position: relative; overflow: hidden;
    }
    .ip-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(15,23,42,.08); }
    .ip-kpi-value { font-size: 2.75rem; font-weight: 900; color: #0f172a; line-height: 1; letter-spacing: -.02em; }
    .ip-kpi-label { font-size: .7rem; font-weight: 900; color: #64748b; text-transform: uppercase; letter-spacing: .12em; }
    .ip-kpi-icon {
        width: 50px; height: 50px; border-radius: 1rem;
        display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
    }
    .ip-bg-blue    { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
    .ip-bg-emerald { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
    .ip-bg-amber   { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #b45309; }
    .ip-bg-rose    { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #b91c1c; }
    .ip-bg-purple  { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #6d28d9; }
    .ip-bg-cyan    { background: linear-gradient(135deg, #cffafe, #a5f3fc); color: #0e7490; }
    .ip-bg-indigo  { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #4338ca; }
    .ip-bg-slate   { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); color: #475569; }
    @keyframes ip-fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); }}
    .ip-card { animation: ip-fade-in .4s ease-out backwards; }
    .ip-skeleton { background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: ip-skel 1.5s infinite; }
    @keyframes ip-skel { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; }}

    .ip-filter {
        display: inline-flex; align-items: center; gap: 6px;
        background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
        padding: 4px 6px 4px 14px;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
        transition: border-color .15s, box-shadow .15s, transform .15s;
    }
    .ip-filter:hover { border-color: #cbd5e1; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,23,42,.06); }
    .ip-filter:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,.12); }
    .ip-filter-label { font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; }
    .ip-filter-select {
        border: none; outline: none; background: transparent;
        font-size: 13px; font-weight: 900; color: #0f172a;
        padding: 6px 26px 6px 8px; cursor: pointer; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b' stroke-width='3'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 6px center; background-size: 12px;
        font-family: inherit;
    }
    .ip-filter-select:hover { color: #1d4ed8; }
    .ip-filter-clear {
        height: 28px; padding: 0 12px;
        border: 1.5px solid #e2e8f0; background: #fff; color: #64748b;
        border-radius: 9px; font-size: 11px; font-weight: 900; cursor: pointer;
        display: inline-flex; align-items: center; gap: 5px;
        transition: all .15s;
    }
    .ip-filter-clear:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

    .ip-wb-tab {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 16px; border-radius: 12px;
        font-size: 13px; font-weight: 800; color: #64748b;
        text-decoration: none; white-space: nowrap;
        background: #fff; border: 1.5px solid #e2e8f0;
        transition: all .2s;
    }
    .ip-wb-tab:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(15,23,42,.08); }
    .ip-wb-tab.ip-wb-active {
        color: #fff; border-color: transparent;
        background: linear-gradient(135deg, var(--wb-color, #3b82f6), color-mix(in srgb, var(--wb-color, #3b82f6) 75%, white));
        box-shadow: 0 8px 18px -4px color-mix(in srgb, var(--wb-color, #3b82f6) 50%, transparent);
    }
</style>
</head>
<body>

<div class="max-w-7xl mx-auto px-4 md:px-8 py-8">

    <!-- Header -->
    <div class="text-center mb-6">
        <div class="inline-flex items-center gap-3 mb-3">
            <div id="ipHeaderIcon" class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-cyan-500 text-white flex items-center justify-center text-2xl shadow-lg shadow-blue-200">
                <i class="fa-solid fa-shield-heart"></i>
            </div>
            <div class="text-left">
                <h1 id="ipHeaderTitle" class="text-2xl md:text-3xl font-black text-slate-800">Insurance Dashboard</h1>
                <p id="ipHeaderSubtitle" class="text-xs md:text-sm text-slate-500 font-bold">ภาพรวมสิทธิ์ประกันสุขภาพและบัตรทอง · เปิดเผยต่อสาธารณะ</p>
            </div>
        </div>
        <div class="inline-flex items-center gap-2">
            <div class="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-full px-4 py-1.5 text-xs font-bold text-slate-500 shadow-sm">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                อัปเดตล่าสุด: <span id="ipUpdatedAt">กำลังโหลด...</span>
            </div>
            <button onclick="loadDashboard(true)" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 border border-slate-200 rounded-full px-3 py-1.5 text-xs font-bold text-slate-600 shadow-sm transition-all" title="รีเฟรชข้อมูล">
                <i class="fa-solid fa-arrows-rotate"></i> รีเฟรช
            </button>
        </div>
    </div>

    <!-- Workbook Tabs (rendered after fetch) -->
    <div id="ipWorkbookTabs" class="flex flex-wrap justify-center items-center gap-2 mb-5"></div>

    <!-- Filter Bar (Year / Month) -->
    <div class="flex flex-wrap justify-center items-center gap-3 mb-6">
        <div class="ip-filter">
            <span class="ip-filter-label"><i class="fa-solid fa-calendar"></i> ปี</span>
            <select id="ipFilterYear" class="ip-filter-select" onchange="onFilterChange()">
                <option value="">ทุกปี</option>
            </select>
        </div>
        <div class="ip-filter">
            <span class="ip-filter-label"><i class="fa-solid fa-calendar-day"></i> เดือน</span>
            <select id="ipFilterMonth" class="ip-filter-select" onchange="onFilterChange()">
                <option value="">ทุกเดือน</option>
                <option value="1">มกราคม</option>
                <option value="2">กุมภาพันธ์</option>
                <option value="3">มีนาคม</option>
                <option value="4">เมษายน</option>
                <option value="5">พฤษภาคม</option>
                <option value="6">มิถุนายน</option>
                <option value="7">กรกฎาคม</option>
                <option value="8">สิงหาคม</option>
                <option value="9">กันยายน</option>
                <option value="10">ตุลาคม</option>
                <option value="11">พฤศจิกายน</option>
                <option value="12">ธันวาคม</option>
            </select>
        </div>
        <button id="ipFilterClear" class="ip-filter-clear hidden" onclick="clearFilters()">
            <i class="fa-solid fa-xmark"></i> ล้างตัวกรอง
        </button>
    </div>

    <!-- Loading state -->
    <div id="ipLoading" class="grid grid-cols-12 gap-5">
        <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="col-span-12 md:col-span-6 xl:col-span-3 ip-card">
                <div class="flex items-start gap-4">
                    <div class="ip-skeleton w-12 h-12 rounded-2xl"></div>
                    <div class="flex-1">
                        <div class="ip-skeleton h-3 w-24 rounded mb-2"></div>
                        <div class="ip-skeleton h-8 w-20 rounded"></div>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

    <!-- Widgets Grid (rendered after fetch) -->
    <div id="ipGrid" class="grid grid-cols-12 gap-5 hidden"></div>

    <!-- Error state -->
    <div id="ipError" class="hidden text-center py-20 text-rose-500 font-bold">
        <i class="fa-solid fa-circle-exclamation text-4xl mb-3"></i>
        <p class="text-base">ไม่สามารถโหลดข้อมูลได้</p>
        <button onclick="loadDashboard()" class="mt-4 px-5 h-10 bg-rose-500 text-white rounded-lg text-sm font-black hover:bg-rose-600">ลองใหม่</button>
    </div>

    <!-- Footer -->
    <footer class="text-center mt-12 text-xs text-slate-400 font-bold">
        <p>Powered by <span class="text-slate-600"><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'RSU Medical Clinic') ?></span></p>
        <p class="mt-1">ข้อมูลที่แสดงเป็นภาพรวมเชิงสถิติเท่านั้น · ไม่มีข้อมูลส่วนบุคคล (PDPA Compliant)</p>
    </footer>
</div>

<script>
const COLOR_HEX = {
    blue:'#3b82f6', emerald:'#10b981', amber:'#f59e0b', rose:'#f43f5e',
    purple:'#a855f7', cyan:'#06b6d4', indigo:'#6366f1', slate:'#64748b'
};
const COLOR_PALETTE = ['#3b82f6','#10b981','#f59e0b','#a855f7','#ef4444','#06b6d4','#6366f1','#94a3b8'];
const SIZE_CLASS = {
    sm: 'col-span-12 md:col-span-6 xl:col-span-3',
    md: 'col-span-12 md:col-span-6 xl:col-span-4',
    lg: 'col-span-12 md:col-span-6 xl:col-span-6',
    xl: 'col-span-12',
};

let availableYearsLoaded = false;

// อ่าน wb จาก URL ปัจจุบัน
function getWBSlug() {
    const url = new URL(location.href);
    return url.searchParams.get('wb') || '';
}

function getFilterParams() {
    const y = document.getElementById('ipFilterYear').value;
    const m = document.getElementById('ipFilterMonth').value;
    const params = new URLSearchParams();
    if (y) params.set('year', y);
    if (m) params.set('month', m);
    const wb = getWBSlug();
    if (wb) params.set('wb', wb);
    return params.toString();
}

const COLOR_HEX_FULL = {
    blue:'#3b82f6', emerald:'#10b981', amber:'#f59e0b', rose:'#f43f5e',
    purple:'#a855f7', cyan:'#06b6d4', indigo:'#6366f1', slate:'#64748b'
};

// solo mode: ถ้า URL มี ?solo=1 ซ่อน tab strip → แชร์ workbook เดี่ยวโดย
// ไม่เปิดเผย workbook อื่นที่เป็น public
const IS_SOLO = (new URLSearchParams(location.search).get('solo') === '1');

function renderWorkbookTabs(workbooks, activeSlug) {
    const wrap = document.getElementById('ipWorkbookTabs');
    if (IS_SOLO || !workbooks || workbooks.length <= 1) { wrap.innerHTML = ''; return; }
    let html = '';
    workbooks.forEach(wb => {
        const isActive = wb.slug === activeSlug;
        const color = COLOR_HEX_FULL[wb.color] || COLOR_HEX_FULL.blue;
        html += `<a href="?wb=${encodeURIComponent(wb.slug)}"
                    class="ip-wb-tab ${isActive ? 'ip-wb-active' : ''}"
                    style="--wb-color:${color}">
                    <i class="fa-solid ${escapeAttr(wb.icon || 'fa-chart-pie')}"></i>
                    <span>${escapeHtml(wb.name)}</span>
                 </a>`;
    });
    wrap.innerHTML = html;
}

function applyWorkbookHeader(wb) {
    if (!wb) return;
    const color = COLOR_HEX_FULL[wb.color] || COLOR_HEX_FULL.blue;
    document.getElementById('ipHeaderTitle').textContent = wb.name || 'Insurance Dashboard';
    if (wb.description) {
        document.getElementById('ipHeaderSubtitle').textContent = wb.description;
    }
    const iconEl = document.getElementById('ipHeaderIcon');
    iconEl.style.background = `linear-gradient(135deg, ${color}, ${color}cc)`;
    iconEl.innerHTML = `<i class="fa-solid ${escapeAttr(wb.icon || 'fa-chart-pie')}"></i>`;
    document.title = wb.name + ' — Insurance Dashboard';
}

function onFilterChange() {
    const hasFilter = document.getElementById('ipFilterYear').value ||
                      document.getElementById('ipFilterMonth').value;
    document.getElementById('ipFilterClear').classList.toggle('hidden', !hasFilter);
    loadDashboard();
}

function clearFilters() {
    document.getElementById('ipFilterYear').value = '';
    document.getElementById('ipFilterMonth').value = '';
    onFilterChange();
}

function populateYearDropdown(years) {
    if (availableYearsLoaded) return;
    const sel = document.getElementById('ipFilterYear');
    const current = sel.value;
    sel.innerHTML = '<option value="">ทุกปี</option>';
    (years || []).forEach(y => {
        const beYear = y + 543;
        const opt = document.createElement('option');
        opt.value = String(y);
        opt.textContent = String(beYear); // แสดง ปี พ.ศ.
        sel.appendChild(opt);
    });
    sel.value = current;
    availableYearsLoaded = true;
}

function loadDashboard(forceFresh) {
    document.getElementById('ipLoading').classList.remove('hidden');
    document.getElementById('ipGrid').classList.add('hidden');
    document.getElementById('ipError').classList.add('hidden');

    const qs = getFilterParams();
    let url = '<?= $apiUrl ?>' + (qs ? '?' + qs : '');
    // Cache buster เมื่อกดรีเฟรชเอง (เพื่อข้ามทั้ง browser cache + ETag)
    if (forceFresh) url += (url.includes('?') ? '&' : '?') + '_t=' + Date.now();

    fetch(url, {
        credentials: 'omit',
        // ใช้ no-cache → revalidate กับ server ทุกครั้ง (ETag ดูแล 304)
        cache: forceFresh ? 'reload' : 'no-cache',
    })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) throw new Error(d.message || 'fetch failed');
            populateYearDropdown(d.available_years || []);
            renderGrid(d);
        })
        .catch(err => {
            console.error(err);
            document.getElementById('ipLoading').classList.add('hidden');
            document.getElementById('ipError').classList.remove('hidden');
        });
}

function renderGrid(d) {
    document.getElementById('ipUpdatedAt').textContent = formatThaiDateTime(d.generated_at);

    // Workbook tabs + header
    if (d.workbook) applyWorkbookHeader(d.workbook);
    if (d.public_workbooks) renderWorkbookTabs(d.public_workbooks, d.workbook ? d.workbook.slug : '');

    const grid = document.getElementById('ipGrid');
    grid.innerHTML = '';

    if (!d.widgets || !d.widgets.length) {
        grid.innerHTML = `<div class="col-span-12 ip-card text-center py-16 text-slate-400 font-bold">
            <i class="fa-solid fa-chart-pie text-5xl mb-3 opacity-40"></i>
            <p>ยังไม่มี widget ใน dashboard</p>
        </div>`;
    } else {
        d.widgets.forEach((w, i) => {
            const card = document.createElement('div');
            card.className = (SIZE_CLASS[w.size] || SIZE_CLASS.md) + ' ip-card';
            card.style.animationDelay = (i * 0.05) + 's';
            card.innerHTML = renderWidgetHTML(w);
            grid.appendChild(card);
        });

        // Render charts (after DOM insertion)
        d.widgets.forEach(w => {
            if (w.type === 'kpi') return;
            const canvas = document.getElementById('ipChart_' + w.id);
            if (canvas) renderChart(canvas, w);
        });
    }

    document.getElementById('ipLoading').classList.add('hidden');
    grid.classList.remove('hidden');
}

function renderWidgetHTML(w) {
    if (w.type === 'kpi') {
        const v = (w.data && typeof w.data.value !== 'undefined') ? w.data.value : '—';
        const formatted = (typeof v === 'number') ? v.toLocaleString() : v;
        return `
            <div class="flex items-start gap-4">
                <div class="ip-kpi-icon ip-bg-${escapeAttr(w.color)}">
                    <i class="fa-solid fa-chart-simple"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="ip-kpi-label mb-1 truncate">${escapeHtml(w.title)}</p>
                    <p class="ip-kpi-value">${formatted}</p>
                    ${w.subtitle ? `<p class="text-xs text-slate-400 font-bold mt-2">${escapeHtml(w.subtitle)}</p>` : ''}
                </div>
            </div>
        `;
    }
    return `
        <div class="mb-3">
            <h3 class="text-sm font-black text-slate-800">${escapeHtml(w.title)}</h3>
            ${w.subtitle ? `<p class="text-xs text-slate-400 font-bold mt-0.5">${escapeHtml(w.subtitle)}</p>` : ''}
        </div>
        <div style="height:260px"><canvas id="ipChart_${w.id}"></canvas></div>
    `;
}

function renderChart(canvas, w) {
    const c = COLOR_HEX[w.color] || COLOR_HEX.blue;
    const cAlpha = c + '20';
    const data = w.data || {};

    if (['line', 'area'].includes(w.type) && data.shape === 'timeseries') {
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: (data.series || []).map((s, i) => ({
                    label: s.name, data: s.data,
                    borderColor: i === 0 ? c : COLOR_PALETTE[(i+1) % COLOR_PALETTE.length],
                    backgroundColor: w.type === 'area' ? (i === 0 ? cAlpha : COLOR_PALETTE[(i+1) % COLOR_PALETTE.length] + '20') : 'transparent',
                    tension: 0.3, fill: w.type === 'area', borderWidth: 2.5, pointRadius: 4, pointHoverRadius: 6,
                }))
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { weight: 700, family: 'Prompt' }, boxWidth: 12 }}}}
        });
    } else if (w.type === 'bar' && data.shape === 'breakdown') {
        new Chart(canvas, {
            type: 'bar',
            data: { labels: data.labels || [], datasets: [{ data: data.values || [], backgroundColor: c, borderRadius: 6 }] },
            options: {
                indexAxis: (data.labels || []).length > 5 ? 'y' : 'x',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { titleFont: { family: 'Prompt' }, bodyFont: { family: 'Prompt' }}},
                scales: { x: { ticks: { font: { family: 'Prompt' }}}, y: { ticks: { font: { family: 'Prompt' }}}}
            }
        });
    } else if (w.type === 'bar' && data.shape === 'timeseries') {
        new Chart(canvas, {
            type: 'bar',
            data: { labels: data.labels || [], datasets: (data.series || []).map((s, i) => ({ label: s.name, data: s.data, backgroundColor: i === 0 ? c : COLOR_PALETTE[(i+1) % COLOR_PALETTE.length], borderRadius: 4 })) },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { weight: 700, family: 'Prompt' }}}}}
        });
    } else if (['donut', 'pie'].includes(w.type) && data.shape === 'breakdown') {
        new Chart(canvas, {
            type: w.type === 'donut' ? 'doughnut' : 'pie',
            data: { labels: data.labels || [], datasets: [{ data: data.values || [], backgroundColor: COLOR_PALETTE, borderWidth: 3, borderColor: '#fff' }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: w.type === 'donut' ? '60%' : 0,
                plugins: { legend: { position: 'bottom', labels: { font: { weight: 700, family: 'Prompt' }, boxWidth: 12, padding: 10 }}}
            }
        });
    } else {
        const ctx = canvas.getContext('2d');
        ctx.font = '14px Prompt'; ctx.fillStyle = '#94a3b8'; ctx.textAlign = 'center';
        ctx.fillText('ไม่มีข้อมูลที่จะแสดง', canvas.width / 2, canvas.height / 2);
    }
}

function formatThaiDateTime(iso) {
    try {
        const d = new Date(iso);
        const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const dd = d.getDate();
        const mm = months[d.getMonth()];
        const yy = d.getFullYear() + 543;
        const hh = String(d.getHours()).padStart(2, '0');
        const mn = String(d.getMinutes()).padStart(2, '0');
        return `${dd} ${mm} ${yy} · ${hh}:${mn} น.`;
    } catch { return iso; }
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) { return String(s ?? '').replace(/[^a-z0-9_-]/gi, ''); }

loadDashboard();
// Auto-refresh ทุก 1 นาที (เพื่อให้ admin override เด้งมาเร็ว)
setInterval(() => loadDashboard(false), 60 * 1000);
</script>

</body>
</html>
