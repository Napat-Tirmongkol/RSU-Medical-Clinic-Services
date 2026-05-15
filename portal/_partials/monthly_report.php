<?php
/**
 * portal/_partials/monthly_report.php
 * รายงานการดำเนินงานประจำเดือน
 *
 * Auth: access_monthly_report (กรอก/แก้) หรือ access_director_view (อนุมัติ) หรือ superadmin
 * AJAX: portal/ajax_monthly_report.php
 */
declare(strict_types=1);

if (!isset($pdo)) $pdo = db();

$mrCsrfToken = function_exists('get_csrf_token') ? get_csrf_token() : ($_SESSION['csrf_token'] ?? '');
$mrIsSuper    = ($_SESSION['admin_role'] ?? '') === 'superadmin';
$mrIsDirector = $mrIsSuper || !empty($_SESSION['access_director_view']);
$mrCanEdit    = $mrIsSuper || !empty($_SESSION['access_monthly_report']);

$mrUserDept = isset($_SESSION['department_id']) ? (int)$_SESSION['department_id'] : 0;

$thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$nowYear  = (int)date('Y');
$nowMonth = (int)date('n');
?>

<style>
#section-monthly_report .mr-card { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:18px; transition:all 0.2s; }
#section-monthly_report .mr-card:hover { border-color:#fbbf24; box-shadow:0 8px 20px rgba(245,158,11,0.08); }
#section-monthly_report .mr-status-draft     { background:#fef3c7; color:#92400e; }
#section-monthly_report .mr-status-submitted { background:#dbeafe; color:#1e3a8a; }
#section-monthly_report .mr-status-approved  { background:#d1fae5; color:#065f46; }
#section-monthly_report .mr-pager-btn { width:36px; height:36px; border-radius:10px; border:1px solid #e2e8f0; background:white; font-weight:700; color:#64748b; font-size:13px; transition:all 0.15s; }
#section-monthly_report .mr-pager-btn:hover:not(:disabled) { background:#fef3c7; border-color:#f59e0b; color:#b45309; }
#section-monthly_report .mr-pager-btn:disabled { opacity:0.4; cursor:not-allowed; }
#section-monthly_report .mr-pager-btn.mr-active { background:#f59e0b; border-color:#f59e0b; color:white; }
#section-monthly_report .mr-empty { padding:60px 20px; text-align:center; }

/* Editor table */
#mrEditModal { z-index: 200; }
#mrEditBox   { max-height: 92vh; }
#mrEditModal .mr-edit-table { width:100%; border-collapse:collapse; font-size:13px; }
#mrEditModal .mr-edit-table th,
#mrEditModal .mr-edit-table td { border:1px solid #e2e8f0; padding:8px; vertical-align:top; }
#mrEditModal .mr-edit-table th { background:#f8fafc; font-weight:900; color:#475569; text-align:left; font-size:12px; }
#mrEditModal .mr-edit-table textarea { width:100%; border:none; resize:vertical; min-height:60px; font-size:13px; line-height:1.6; background:transparent; padding:4px; }
#mrEditModal .mr-edit-table textarea:focus { outline: 2px solid #fbbf24; outline-offset:-1px; background:#fffbeb; }
#mrEditModal .mr-cat-row td { background:#fef3c7; color:#92400e; font-weight:900; font-size:13px; padding:6px 10px; }
#mrEditModal .mr-del-btn { color:#dc2626; cursor:pointer; }
#mrEditModal .mr-edit-flex { display:flex; flex-direction:column; min-height:0; }
#mrEditModal .mr-edit-scroll { overflow-y:auto; min-height:0; flex:1; }

/* ── Bold & Colorful — tilt-aware lift ── */
#section-monthly_report .mr-card { isolation: isolate; position: relative; }
#section-monthly_report .mr-card:hover:not(.fx-tilt) { transform: translateY(-3px); }
#section-monthly_report .mr-card.fx-tilt:hover { --lift: -3px; }
#section-monthly_report .mr-card .w-14.h-14 { transition: transform .25s cubic-bezier(.16,1,.3,1); }
#section-monthly_report .mr-card:hover .w-14.h-14 { transform: scale(1.08) rotate(-4deg); }

/* ── DARK MODE ──────────────────────────────────────────────── */
body[data-theme='dark'] #section-monthly_report .mr-card {
    background: #0f172a; border-color: #1e293b;
    box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35);
}
body[data-theme='dark'] #section-monthly_report .mr-card:hover { border-color: #f59e0b; box-shadow: 0 18px 36px -18px rgba(245,158,11,.30); }
body[data-theme='dark'] #section-monthly_report .mr-status-draft     { background: rgba(245,158,11,.18); color:#fbbf24; }
body[data-theme='dark'] #section-monthly_report .mr-status-submitted { background: rgba(59,130,246,.18); color:#93c5fd; }
body[data-theme='dark'] #section-monthly_report .mr-status-approved  { background: rgba(16,185,129,.18); color:#6ee7b7; }
body[data-theme='dark'] #section-monthly_report .mr-pager-btn { background:#0f172a; border-color:#1e293b; color:#cbd5e1; }
body[data-theme='dark'] #section-monthly_report .mr-pager-btn:hover:not(:disabled) { background:rgba(245,158,11,.16); border-color:#f59e0b; color:#fbbf24; }
body[data-theme='dark'] #section-monthly_report .mr-pager-btn.mr-active { background:#f59e0b; border-color:#f59e0b; color:#0f172a; }
body[data-theme='dark'] #section-monthly_report .mr-empty { color:#64748b; }

/* header gradient + filter bar dark variants */
body[data-theme='dark'] #section-monthly_report .bg-gradient-to-br.from-amber-50 {
    background: linear-gradient(135deg, rgba(245,158,11,.10), #0f172a 55%, rgba(244,63,94,.10));
    border-color: rgba(245,158,11,.30) !important;
}
body[data-theme='dark'] #section-monthly_report .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-monthly_report .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-monthly_report .bg-amber-50 { background: rgba(245,158,11,.16) !important; }
body[data-theme='dark'] #section-monthly_report .bg-amber-100 { background: rgba(245,158,11,.22) !important; }
body[data-theme='dark'] #section-monthly_report .text-amber-700 { color:#fbbf24 !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-800 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-600 { color:#cbd5e1 !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-500 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-400 { color:#64748b !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-300 { color:#475569 !important; }
body[data-theme='dark'] #section-monthly_report .text-slate-200 { color:#334155 !important; }
body[data-theme='dark'] #section-monthly_report .border-slate-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-monthly_report .border-amber-100 { border-color: rgba(245,158,11,.30) !important; }
body[data-theme='dark'] #section-monthly_report .border-amber-200 { border-color: rgba(245,158,11,.30) !important; }
body[data-theme='dark'] #section-monthly_report .border-rose-200  { border-color: rgba(244,63,94,.30) !important; }

/* edit modal */
body[data-theme='dark'] #mrEditBox { background:#0f172a; }
body[data-theme='dark'] #mrEditModal .bg-slate-50 { background:#1e293b !important; }
body[data-theme='dark'] #mrEditModal .text-slate-900 { color:#f1f5f9 !important; }
body[data-theme='dark'] #mrEditModal .border-slate-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #mrEditModal .mr-edit-table th,
body[data-theme='dark'] #mrEditModal .mr-edit-table td { border-color:#1e293b; }
body[data-theme='dark'] #mrEditModal .mr-edit-table th { background:#1e293b; color:#cbd5e1; }
body[data-theme='dark'] #mrEditModal .mr-edit-table textarea { color:#e2e8f0; }
body[data-theme='dark'] #mrEditModal .mr-edit-table textarea:focus { background: rgba(245,158,11,.10); }
body[data-theme='dark'] #mrEditModal .mr-cat-row td { background: rgba(245,158,11,.16); color:#fbbf24; }

@media (prefers-reduced-motion: reduce) {
    #section-monthly_report .mr-card,
    #section-monthly_report .mr-card .w-14.h-14 { transition: none !important; transform: none !important; }
}

/* Print stylesheet */
@media print {
    body * { visibility: hidden !important; }
    #mrPrintArea, #mrPrintArea * { visibility: visible !important; }
    #mrPrintArea { position: absolute; top:0; left:0; width:100%; padding:24px; }
    #mrPrintArea table { border-collapse: collapse; width:100%; }
    #mrPrintArea table, #mrPrintArea th, #mrPrintArea td { border: 1px solid #1e293b !important; }
    #mrPrintArea th, #mrPrintArea td { padding:8px; vertical-align:top; }
    #mrPrintArea .mr-print-cat { background:#f1f5f9 !important; font-weight:bold; }
    .no-print { display:none !important; }
}
</style>

<div class="space-y-5 p-5 sm:p-7" id="mrRoot">

    <!-- ── Header ─────────────────────────────────────────────────── -->
    <div class="bg-gradient-to-br from-amber-50 via-white to-rose-50 rounded-3xl border border-amber-100 shadow-sm p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-base font-black text-slate-800">📊 รายงานการดำเนินงานประจำเดือน</h2>
                <p class="text-xs font-bold text-slate-500 mt-1 leading-relaxed">
                    <?php if ($mrIsDirector): ?>
                        คุณคือ <span class="text-emerald-600">ผู้อำนวยการ</span> — ดูทุกฝ่าย + อนุมัติได้
                    <?php elseif ($mrCanEdit): ?>
                        กรอกข้อมูลผลการดำเนินงานประจำเดือนของฝ่ายตัวเอง · บันทึกอัตโนมัติทุกครั้ง · ส่งให้ ผอ. ตรวจสอบ
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($mrCanEdit): ?>
            <button onclick="mrCreateNew()" class="h-11 px-5 bg-amber-500 hover:bg-amber-600 text-white font-black rounded-2xl shadow-lg shadow-amber-200 active:scale-95 transition-all flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> สร้างรายงานเดือนนี้
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Toolbar / Filter ───────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm px-5 py-4 flex flex-wrap items-center gap-3">
        <select id="mrFilterYear" class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none">
            <option value="">ทุกปี</option>
            <?php for ($y = $nowYear; $y >= $nowYear - 3; $y--): ?>
                <option value="<?= $y ?>"><?= $y + 543 ?></option>
            <?php endfor; ?>
        </select>
        <select id="mrFilterMonth" class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none">
            <option value="">ทุกเดือน</option>
            <?php foreach ($thaiMonths as $i => $m): ?>
                <option value="<?= $i + 1 ?>"><?= $m ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($mrIsDirector): ?>
        <select id="mrFilterDept" class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none">
            <option value="">ทุกฝ่าย</option>
        </select>
        <?php endif; ?>
        <button onclick="mrLoadList(1)" class="h-10 px-4 bg-amber-500 hover:bg-amber-600 text-white font-black rounded-xl text-sm active:scale-95 transition-all flex items-center gap-2">
            <i class="fa-solid fa-rotate"></i> รีเฟรช
        </button>
        <span id="mrTotalLabel" class="ml-auto text-xs font-black text-slate-500"></span>
    </div>

    <!-- ── List ───────────────────────────────────────────────────── -->
    <div id="mrListWrap" class="space-y-3">
        <div class="mr-empty bg-white border border-slate-200 rounded-2xl">
            <i class="fa-solid fa-spinner fa-spin text-3xl text-slate-300 mb-3"></i>
            <p class="text-sm font-bold text-slate-400">กำลังโหลด...</p>
        </div>
    </div>

    <!-- Pagination -->
    <div id="mrPager" class="flex justify-center items-center gap-1.5 flex-wrap"></div>
</div>

<!-- ── Edit Modal ──────────────────────────────────────────────────── -->
<div id="mrEditModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 backdrop-blur-sm">
    <div id="mrEditBox" class="bg-white rounded-3xl shadow-2xl w-full max-w-5xl mx-4 mr-edit-flex">
        <div class="flex items-center justify-between p-5 border-b border-slate-200 shrink-0">
            <div>
                <h3 id="mrEditTitle" class="text-lg font-black text-slate-900">รายงาน</h3>
                <p id="mrEditSub" class="text-xs font-bold text-slate-500 mt-1"></p>
            </div>
            <div class="flex items-center gap-2">
                <span id="mrEditStatus" class="px-3 py-1 rounded-full text-xs font-black"></span>
                <button onclick="mrCloseEdit()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center transition-colors">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <div class="px-5 py-4 border-b border-slate-200 shrink-0 bg-slate-50">
            <label class="block text-xs font-black text-slate-600 mb-1">รายละเอียดการประชุม</label>
            <input id="mrMeetingInfo" type="text"
                placeholder="เช่น ครั้งที่ 1/2569 วันที่ 06/01/69 เวลา 09.30 น. ณ ห้องประชุมออนไลน์"
                class="w-full h-10 px-3 bg-white border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none"
                onblur="mrSaveMeta()">
        </div>

        <div class="mr-edit-scroll p-5">
            <div class="overflow-x-auto">
                <table class="mr-edit-table">
                    <thead>
                        <tr>
                            <th style="width:160px">โครงการ/กิจกรรม</th>
                            <th style="width:200px">รายละเอียดการปฏิบัติงาน</th>
                            <th>ผลการดำเนินงาน (ผลลัพธ์ที่ได้)</th>
                            <th style="width:160px">ข้อเสนอแนะ</th>
                            <th style="width:36px" class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody id="mrItemsBody"></tbody>
                </table>
            </div>
            <div class="mt-3">
                <button onclick="mrAddItem()" class="px-4 h-9 bg-slate-100 hover:bg-slate-200 text-slate-700 font-black rounded-xl text-xs transition-colors">
                    <i class="fa-solid fa-plus mr-1"></i> เพิ่มกิจกรรมเอง
                </button>
            </div>
        </div>

        <div class="p-5 border-t border-slate-200 flex items-center gap-3 shrink-0">
            <button onclick="mrPrint()" class="h-11 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-black rounded-xl text-sm transition-colors flex items-center gap-2">
                <i class="fa-solid fa-print"></i> พิมพ์
            </button>
            <div class="flex-1"></div>
            <button id="mrSubmitBtn" onclick="mrSubmitReport()" class="hidden h-11 px-5 bg-blue-500 hover:bg-blue-600 text-white font-black rounded-xl text-sm shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                <i class="fa-solid fa-paper-plane"></i> ส่งให้ ผอ. ตรวจสอบ
            </button>
            <button id="mrApproveBtn" onclick="mrApproveReport()" class="hidden h-11 px-5 bg-emerald-500 hover:bg-emerald-600 text-white font-black rounded-xl text-sm shadow-lg shadow-emerald-200 transition-all flex items-center gap-2">
                <i class="fa-solid fa-check"></i> อนุมัติ
            </button>
            <button id="mrRevertBtn" onclick="mrRevertReport()" class="hidden h-11 px-4 bg-amber-50 border border-amber-200 text-amber-700 hover:bg-amber-100 font-black rounded-xl text-sm transition-colors flex items-center gap-2">
                <i class="fa-solid fa-rotate-left"></i> ส่งกลับแก้ไข
            </button>
        </div>
    </div>
</div>

<!-- ── Hidden Print Area ───────────────────────────────────────────── -->
<div id="mrPrintArea" style="display:none"></div>

<script>
(function(){
    const CSRF       = '<?= htmlspecialchars($mrCsrfToken, ENT_QUOTES) ?>';
    const ENDPOINT   = 'ajax_monthly_report.php';
    const PAGE_SIZE  = 20;
    const IS_DIRECTOR = <?= $mrIsDirector ? 'true' : 'false' ?>;
    const CAN_EDIT   = <?= $mrCanEdit ? 'true' : 'false' ?>;
    const THAI_MONTHS = <?= json_encode($thaiMonths, JSON_UNESCAPED_UNICODE) ?>;
    let currentPage  = 1;
    let currentReportId = null;

    function post(entity, action, extra = {}) {
        const body = new FormData();
        body.append('entity', entity);
        body.append('action', action);
        body.append('csrf_token', CSRF);
        for (const [k, v] of Object.entries(extra)) body.append(k, v);
        return fetch(ENDPOINT, { method:'POST', body, credentials:'same-origin' }).then(r => r.json());
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // ── Init: Load departments for filter (director only) ────────
    if (IS_DIRECTOR) {
        post('department', 'list').then(res => {
            if (res.status === 'ok') {
                const sel = document.getElementById('mrFilterDept');
                if (sel) {
                    res.departments.filter(d => d.active == 1).forEach(d => {
                        const opt = document.createElement('option');
                        opt.value = d.id; opt.textContent = d.name;
                        sel.appendChild(opt);
                    });
                }
            }
        });
    }

    // ── Reports list ────────────────────────────────────────────
    window.mrLoadList = async function(page) {
        currentPage = page || 1;
        const year  = document.getElementById('mrFilterYear').value;
        const month = document.getElementById('mrFilterMonth').value;
        const deptEl = document.getElementById('mrFilterDept');
        const dept  = deptEl ? deptEl.value : '';

        const wrap = document.getElementById('mrListWrap');
        wrap.innerHTML = '<div class="mr-empty bg-white border border-slate-200 rounded-2xl"><i class="fa-solid fa-spinner fa-spin text-3xl text-slate-300 mb-3"></i><p class="text-sm font-bold text-slate-400">กำลังโหลด...</p></div>';

        const res = await post('report', 'list', {
            page: currentPage, page_size: PAGE_SIZE,
            year: year || 0, month: month || 0, department_id: dept || 0,
        });
        if (res.status !== 'ok') {
            wrap.innerHTML = `<div class="mr-empty bg-white border border-rose-200 rounded-2xl"><i class="fa-solid fa-circle-exclamation text-3xl text-rose-300 mb-3"></i><p class="text-sm font-bold text-rose-500">${escapeHtml(res.message || 'โหลดไม่สำเร็จ')}</p></div>`;
            return;
        }

        const total = res.total || 0;
        document.getElementById('mrTotalLabel').textContent = total > 0 ? `หน้า ${res.page} / ${res.pages} · รวม ${total} รายงาน` : '';

        if (!res.reports.length) {
            wrap.innerHTML = '<div class="mr-empty bg-white border border-slate-200 rounded-2xl"><i class="fa-solid fa-folder-open text-4xl text-slate-200 mb-3"></i><p class="text-sm font-bold text-slate-400">ยังไม่มีรายงาน</p><p class="text-xs text-slate-300 mt-1">กดปุ่ม "สร้างรายงานเดือนนี้" เพื่อเริ่ม</p></div>';
            renderPager(0);
            return;
        }

        wrap.innerHTML = res.reports.map(r => {
            const statusCls = `mr-status-${r.status}`;
            const statusLabel = { draft:'ฉบับร่าง', submitted:'ส่งแล้ว · รอ ผอ.', approved:'อนุมัติแล้ว' }[r.status] || r.status;
            return `
            <div class="mr-card fx-tilt fx-tilt-light flex flex-wrap items-center justify-between gap-4" data-tilt="3">
                <div class="flex items-center gap-4 flex-1">
                    <div class="w-14 h-14 bg-amber-100 rounded-2xl flex flex-col items-center justify-center shrink-0">
                        <div class="text-[10px] font-black text-amber-700 uppercase">${THAI_MONTHS[r.report_month - 1]}</div>
                        <div class="text-base font-black text-amber-700">${(r.report_year - 0) + 543}</div>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-800">${escapeHtml(r.department_name)}</h3>
                        <p class="text-xs font-bold text-slate-500 mt-0.5">${r.item_count} กิจกรรม</p>
                    </div>
                </div>
                <span class="${statusCls} px-3 py-1 rounded-full text-xs font-black">${statusLabel}</span>
                <button onclick="mrOpenEdit(${r.id})" class="h-10 px-4 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-black rounded-xl text-xs flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square"></i> ${r.status === 'approved' ? 'ดู' : 'แก้ไข'}
                </button>
            </div>`;
        }).join('');

        if (window.RsuFx && typeof RsuFx.refresh === 'function') RsuFx.refresh(wrap);
        renderPager(res.pages || 1);
    };

    function renderPager(pages) {
        const el = document.getElementById('mrPager');
        if (pages <= 1) { el.innerHTML = ''; return; }
        const page = currentPage;
        let html = `<button class="mr-pager-btn" onclick="mrLoadList(1)" ${page<=1?'disabled':''}>«</button>
                    <button class="mr-pager-btn" onclick="mrLoadList(${page-1})" ${page<=1?'disabled':''}>‹</button>`;
        const start = Math.max(1, page - 2);
        const end   = Math.min(pages, page + 2);
        for (let i = start; i <= end; i++) html += `<button class="mr-pager-btn ${i===page?'mr-active':''}" onclick="mrLoadList(${i})">${i}</button>`;
        html += `<button class="mr-pager-btn" onclick="mrLoadList(${page+1})" ${page>=pages?'disabled':''}>›</button>
                 <button class="mr-pager-btn" onclick="mrLoadList(${pages})" ${page>=pages?'disabled':''}>»</button>`;
        el.innerHTML = html;
    }

    // ── Create new report ───────────────────────────────────────
    window.mrCreateNew = async function() {
        if (!CAN_EDIT) return Swal.fire({icon:'info', title:'ไม่มีสิทธิ์สร้าง', text:'ต้องมี access_monthly_report'});
        const deptRes = await post('staff', 'departments_for_self');
        if (deptRes.status !== 'ok') return Swal.fire({icon:'error', title:'โหลดฝ่ายไม่สำเร็จ', text: deptRes.message || ''});

        const monthOpts = THAI_MONTHS.map((m, i) => `<option value="${i+1}">${m}</option>`).join('');
        const now = new Date();
        const yearOpts = [];
        for (let y = now.getFullYear(); y >= now.getFullYear() - 3; y--) yearOpts.push(`<option value="${y}">${y + 543}</option>`);

        let deptHtml;
        if (deptRes.fixed) {
            const d = deptRes.departments[0];
            deptHtml = `<input type="hidden" id="swDept" value="${d.id}">
                        <p class="mb-2 text-sm"><b>ฝ่าย:</b> ${escapeHtml(d.name)}</p>`;
        } else {
            deptHtml = `<label class="block text-sm font-bold mb-1 text-left">ฝ่าย</label>
                        <select id="swDept" class="swal2-select" style="width:100%">${deptRes.departments.map(d=>`<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('')}</select>`;
        }

        const result = await Swal.fire({
            title: 'สร้างรายงานประจำเดือน',
            html: `<div class="text-left space-y-3">
                ${deptHtml}
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-sm font-bold mb-1">ปี</label>
                        <select id="swYear" class="swal2-select" style="width:100%">${yearOpts.join('')}</select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">เดือน</label>
                        <select id="swMonth" class="swal2-select" style="width:100%">${monthOpts}</select>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">รายการกิจกรรมจะถูกสร้างจาก template ของฝ่ายอัตโนมัติ</p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '✚ สร้าง',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f59e0b',
            didOpen: () => {
                document.getElementById('swMonth').value = now.getMonth() + 1;
                document.getElementById('swYear').value  = now.getFullYear();
            },
            preConfirm: () => ({
                department_id: document.getElementById('swDept').value,
                year:  document.getElementById('swYear').value,
                month: document.getElementById('swMonth').value,
            })
        });
        if (!result.isConfirmed) return;

        const res = await post('report', 'create_from_template', result.value);
        if (res.status !== 'ok') return Swal.fire({icon:'error', title:'สร้างไม่สำเร็จ', text: res.message || ''});

        if (res.created) Swal.fire({icon:'success', title:'สร้างรายงานเรียบร้อย', timer:1200, showConfirmButton:false});
        mrOpenEdit(res.id);
        mrLoadList(1);
    };

    // ── Open edit modal ────────────────────────────────────────
    window.mrOpenEdit = async function(id) {
        currentReportId = id;
        const res = await post('report', 'get', { id });
        if (res.status !== 'ok') return Swal.fire({icon:'error', title:'โหลดรายงานไม่สำเร็จ', text: res.message || ''});

        const r  = res.report;
        const items = res.items || [];
        document.getElementById('mrEditTitle').textContent = `รายงาน — ${r.department_name}`;
        document.getElementById('mrEditSub').textContent   = `ประจำเดือน ${THAI_MONTHS[r.report_month - 1]} ${(parseInt(r.report_year)+543)}`;
        document.getElementById('mrMeetingInfo').value     = r.meeting_info || '';

        const status = r.status;
        const statusEl = document.getElementById('mrEditStatus');
        const map = { draft:['mr-status-draft','ฉบับร่าง'], submitted:['mr-status-submitted','ส่งแล้ว'], approved:['mr-status-approved','อนุมัติแล้ว'] };
        const [cls, label] = map[status] || ['mr-status-draft', status];
        statusEl.className = cls + ' px-3 py-1 rounded-full text-xs font-black';
        statusEl.textContent = label;

        // Lock fields if approved (เว้น director)
        const locked = (status === 'approved') && !IS_DIRECTOR;
        document.getElementById('mrMeetingInfo').disabled = locked;

        // Render items
        const body = document.getElementById('mrItemsBody');
        let lastCat = null;
        body.innerHTML = items.map(it => {
            let catRow = '';
            if (it.category && it.category !== lastCat) {
                catRow = `<tr class="mr-cat-row"><td colspan="5">${escapeHtml(it.category)}</td></tr>`;
                lastCat = it.category;
            }
            return catRow + `
            <tr data-item-id="${it.id}">
                <td><textarea data-field="activity"  ${locked?'disabled':''} oninput="mrItemDirty(${it.id})">${escapeHtml(it.activity || '')}</textarea></td>
                <td><textarea data-field="detail"    ${locked?'disabled':''} oninput="mrItemDirty(${it.id})">${escapeHtml(it.detail || '')}</textarea></td>
                <td><textarea data-field="result"    ${locked?'disabled':''} oninput="mrItemDirty(${it.id})">${escapeHtml(it.result || '')}</textarea></td>
                <td><textarea data-field="suggestion" ${locked?'disabled':''} oninput="mrItemDirty(${it.id})">${escapeHtml(it.suggestion || '')}</textarea></td>
                <td class="text-center no-print">
                    ${locked ? '' : `<button onclick="mrDeleteItem(${it.id})" class="mr-del-btn" title="ลบ"><i class="fa-solid fa-trash text-xs"></i></button>`}
                </td>
            </tr>`;
        }).join('');

        // Toggle action buttons
        document.getElementById('mrSubmitBtn').classList.toggle('hidden',  locked || status !== 'draft');
        document.getElementById('mrApproveBtn').classList.toggle('hidden', !IS_DIRECTOR || status === 'approved');
        document.getElementById('mrRevertBtn').classList.toggle('hidden',  !IS_DIRECTOR || status !== 'approved');

        const modal = document.getElementById('mrEditModal');
        modal.classList.remove('hidden'); modal.classList.add('flex');
    };

    window.mrCloseEdit = function() {
        const m = document.getElementById('mrEditModal');
        m.classList.add('hidden'); m.classList.remove('flex');
        currentReportId = null;
        mrLoadList(currentPage);
    };

    // Save meta (meeting info)
    window.mrSaveMeta = async function() {
        if (!currentReportId) return;
        const v = document.getElementById('mrMeetingInfo').value;
        await post('report', 'save_meta', { id: currentReportId, meeting_info: v });
    };

    // Save item (debounced per item via blur)
    const dirtyTimers = {};
    window.mrItemDirty = function(itemId) {
        clearTimeout(dirtyTimers[itemId]);
        dirtyTimers[itemId] = setTimeout(() => mrSaveItem(itemId), 800);
    };

    async function mrSaveItem(itemId) {
        const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
        if (!row) return;
        const get = field => row.querySelector(`textarea[data-field="${field}"]`).value;
        const res = await post('item', 'save', {
            id: itemId, report_id: currentReportId,
            activity:   get('activity'),
            detail:     get('detail'),
            result:     get('result'),
            suggestion: get('suggestion'),
        });
        // visual feedback
        if (res.status === 'ok') {
            row.style.transition = 'background 0.3s';
            row.style.background = '#d1fae5';
            setTimeout(() => row.style.background = '', 400);
        }
    }

    window.mrAddItem = async function() {
        const { value: activity } = await Swal.fire({
            title: 'เพิ่มกิจกรรม', input: 'text',
            inputLabel: 'ชื่อกิจกรรม', inputPlaceholder: 'เช่น กิจกรรมพิเศษ...',
            showCancelButton: true, confirmButtonText: 'เพิ่ม', cancelButtonText: 'ยกเลิก',
            inputValidator: v => !v || !v.trim() ? 'กรุณาระบุชื่อกิจกรรม' : undefined,
        });
        if (!activity) return;
        const res = await post('item', 'save', { report_id: currentReportId, activity });
        if (res.status === 'ok') mrOpenEdit(currentReportId);
        else Swal.fire({icon:'error', title:'เพิ่มไม่สำเร็จ', text: res.message || ''});
    };

    window.mrDeleteItem = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon:'warning', title:'ลบกิจกรรมนี้?', text:'ไม่สามารถกู้คืนได้',
            showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#dc2626',
        });
        if (!isConfirmed) return;
        const res = await post('item', 'delete', { id });
        if (res.status === 'ok') mrOpenEdit(currentReportId);
        else Swal.fire({icon:'error', title:'ลบไม่สำเร็จ', text: res.message || ''});
    };

    // ── Workflow buttons ────────────────────────────────────────
    window.mrSubmitReport = async function() {
        const { isConfirmed } = await Swal.fire({
            icon:'question', title:'ส่งรายงานให้ ผอ.?',
            text:'หลังส่งแล้ว จะแก้ไขเองไม่ได้ (ผอ. ส่งกลับมาให้แก้ได้)',
            showCancelButton:true, confirmButtonText:'ส่ง', cancelButtonText:'ยกเลิก', confirmButtonColor:'#3b82f6',
        });
        if (!isConfirmed) return;
        const res = await post('report', 'submit', { id: currentReportId });
        if (res.status !== 'ok') return Swal.fire({icon:'error', title:'ส่งไม่สำเร็จ', text: res.message || ''});
        Swal.fire({icon:'success', title:'ส่งเรียบร้อย', timer:1200, showConfirmButton:false});
        mrOpenEdit(currentReportId);
    };

    window.mrApproveReport = async function() {
        const { isConfirmed, value } = await Swal.fire({
            icon:'success', title:'อนุมัติรายงาน?',
            input:'textarea', inputLabel:'ความเห็น (optional)', inputPlaceholder:'เช่น เนื้อหาครบถ้วน',
            showCancelButton:true, confirmButtonText:'อนุมัติ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#10b981',
        });
        if (!isConfirmed) return;
        const res = await post('report', 'approve', { id: currentReportId, note: value || '' });
        if (res.status !== 'ok') return Swal.fire({icon:'error', title:'ไม่สำเร็จ', text: res.message || ''});
        Swal.fire({icon:'success', title:'อนุมัติเรียบร้อย', timer:1200, showConfirmButton:false});
        mrOpenEdit(currentReportId);
    };

    window.mrRevertReport = async function() {
        const { isConfirmed, value } = await Swal.fire({
            icon:'warning', title:'ส่งกลับให้ฝ่ายแก้ไข?',
            input:'textarea', inputLabel:'เหตุผล (optional)',
            showCancelButton:true, confirmButtonText:'ส่งกลับ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#f59e0b',
        });
        if (!isConfirmed) return;
        const res = await post('report', 'revert', { id: currentReportId, note: value || '' });
        if (res.status !== 'ok') return Swal.fire({icon:'error', title:'ไม่สำเร็จ', text: res.message || ''});
        Swal.fire({icon:'success', title:'ส่งกลับเรียบร้อย', timer:1200, showConfirmButton:false});
        mrOpenEdit(currentReportId);
    };

    // ── Print ────────────────────────────────────────────────────
    window.mrPrint = async function() {
        if (!currentReportId) return;
        const res = await post('report', 'get', { id: currentReportId });
        if (res.status !== 'ok') return;
        const r = res.report; const items = res.items || [];

        let lastCat = null; let rowsHtml = '';
        items.forEach(it => {
            if (it.category && it.category !== lastCat) {
                rowsHtml += `<tr class="mr-print-cat"><td colspan="4"><b>${escapeHtml(it.category)}</b></td></tr>`;
                lastCat = it.category;
            }
            rowsHtml += `<tr>
                <td>${escapeHtml(it.activity || '')}</td>
                <td>${escapeHtml(it.detail || '').replace(/\n/g, '<br>')}</td>
                <td>${escapeHtml(it.result || '').replace(/\n/g, '<br>')}</td>
                <td>${escapeHtml(it.suggestion || '').replace(/\n/g, '<br>')}</td>
            </tr>`;
        });

        const area = document.getElementById('mrPrintArea');
        area.innerHTML = `
            <div style="text-align:center; margin-bottom:18px">
                <h2 style="font-size:18px; font-weight:bold">รายงานการดำเนินงาน ${escapeHtml(r.department_name)}</h2>
                <p style="font-size:14px">ประจำเดือน ${THAI_MONTHS[r.report_month - 1]} ${(parseInt(r.report_year)+543)} ${r.meeting_info ? '· ' + escapeHtml(r.meeting_info) : ''}</p>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width:18%">โครงการ/กิจกรรม</th>
                        <th style="width:22%">รายละเอียดการปฏิบัติงาน</th>
                        <th style="width:42%">ผลการดำเนินงาน (ผลลัพธ์ที่ได้)</th>
                        <th style="width:18%">ข้อเสนอแนะ</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>`;
        area.style.display = 'block';
        window.print();
        setTimeout(() => area.style.display = 'none', 500);
    };

    // ── Init ─────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => mrLoadList(1));
    if (document.readyState !== 'loading') mrLoadList(1);

    // Re-load when section becomes visible
    const sec = document.getElementById('section-monthly_report');
    if (sec) {
        new MutationObserver(muts => {
            for (const m of muts) {
                if (m.attributeName === 'style' && sec.style.display !== 'none') {
                    mrLoadList(currentPage);
                }
            }
        }).observe(sec, { attributes: true });
    }
})();
</script>
