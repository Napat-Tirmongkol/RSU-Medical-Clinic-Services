<?php
// portal/_partials/finance.php — Cash book (Phase 1)
// โหลดผ่าน portal/index.php — มี get_csrf_token() + SweetAlert2 พร้อมใช้
?>
<style>
.fin-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; }
.fin-kpi { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:12px; }
.fin-kpi .ic { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.fin-kpi .num { font-size:20px; font-weight:900; color:#0f172a; }
.fin-kpi .lbl { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
.fin-filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:end; }
.fin-filter-bar label { font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:3px; }
.fin-filter-bar input, .fin-filter-bar select { font-size:13px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; }
.fin-filter-bar input[type="search"] { min-width: 220px; }
.fin-table { width:100%; border-collapse:collapse; font-size:13px; }
.fin-table th { background:#f8fafc; padding:9px 10px; text-align:left; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; border-bottom:1px solid #e2e8f0; }
.fin-table td { padding:10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.fin-table tbody tr:hover { background:#fafbfc; }
.fin-table tbody tr.is-selected { background:#f0faf4; }
.fin-cat-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fin-amt-income { color:#059669; font-weight:800; }
.fin-amt-expense { color:#dc2626; font-weight:800; }
.fin-pagi { display:flex; gap:4px; align-items:center; justify-content:center; padding:14px 0; }
.fin-pagi button { padding:6px 10px; border:1px solid #cbd5e1; background:#fff; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; min-width:34px; }
.fin-pagi button:hover:not(:disabled) { background:#f0faf4; border-color:#2e9e63; color:#2e9e63; }
.fin-pagi button:disabled { opacity:0.4; cursor:not-allowed; }
.fin-pagi button.active { background:#2e9e63; color:#fff; border-color:#2e9e63; }
.fin-empty { text-align:center; padding:48px 16px; color:#94a3b8; font-size:13px; }

/* Quick-date chips */
.fin-quick-dates { display:flex; flex-wrap:wrap; gap:6px; padding:4px 0 0; }
.fin-chip {
    padding:5px 12px; border-radius:99px; border:1.5px solid #e2e8f0;
    background:#fff; color:#475569; font-size:12px; font-weight:700;
    cursor:pointer; transition:all .15s; white-space:nowrap;
}
.fin-chip:hover { border-color:#2e9e63; color:#2e9e63; background:#f0faf4; }
.fin-chip.is-active {
    background:linear-gradient(135deg,#2e9e63,#3bba7a); color:#fff;
    border-color:transparent; box-shadow:0 4px 10px -3px rgba(46,158,99,.45);
}

/* Bulk selection bottom bar (sticky, slides up when items selected) */
.fin-bulk-bar {
    position:sticky; bottom:12px;
    margin:14px auto 0; max-width:680px;
    background:linear-gradient(135deg,#0f172a 0%,#14532d 100%);
    color:#fff; border-radius:14px;
    padding:12px 16px;
    display:flex; align-items:center; gap:14px;
    box-shadow:0 20px 40px -10px rgba(15,23,42,.45), inset 0 1px 0 rgba(255,255,255,.10);
    z-index:10;
    transform:translateY(120%); opacity:0; pointer-events:none;
    transition:transform .25s cubic-bezier(.16,1,.3,1), opacity .25s;
}
.fin-bulk-bar.is-visible { transform:translateY(0); opacity:1; pointer-events:auto; }
.fin-bulk-count {
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:800;
}
.fin-bulk-count b { font-size:18px; color:#fbbf24; }
.fin-bulk-sum {
    font-size:12px; color:rgba(255,255,255,.75);
    padding-left:14px; border-left:1px solid rgba(255,255,255,.20);
    font-variant-numeric: tabular-nums;
}
.fin-bulk-bar .spacer { flex:1; }
.fin-bulk-bar button {
    padding:8px 14px; border-radius:10px; font-size:12px; font-weight:800;
    border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    transition: background .15s, transform .15s;
}
.fin-bulk-bar .btn-cancel { background:rgba(255,255,255,.10); color:#fff; border:1px solid rgba(255,255,255,.22); }
.fin-bulk-bar .btn-cancel:hover { background:rgba(255,255,255,.18); }
.fin-bulk-bar .btn-del { background:linear-gradient(135deg,#f43f5e,#dc2626); color:#fff; box-shadow:0 6px 14px -3px rgba(244,63,94,.5); }
.fin-bulk-bar .btn-del:hover { transform:translateY(-1px); }

/* Checkbox column */
.fin-chk { width:34px; text-align:center; }
.fin-chk input[type="checkbox"] {
    width:15px; height:15px; cursor:pointer; accent-color:#2e9e63;
}

/* Duplicate hint inside the modal */
.fin-dup-hint {
    margin-top:8px;
    background:#fffbeb; border:1px solid #fde68a; color:#92400e;
    border-radius:10px; padding:8px 12px;
    font-size:12px; line-height:1.55;
}
.fin-dup-hint b { color:#78350f; }
.fin-dup-hint ul { margin:6px 0 0; padding-left:18px; }
.fin-dup-hint li { margin:2px 0; }

/* Dark mode for the bulk bar inner text (already gradient-dark) — leave as is */
body[data-theme='dark'] .fin-chip { background:#1e293b; color:#cbd5e1; border-color:#334155; }
body[data-theme='dark'] .fin-chip:hover { background:rgba(46,158,99,.15); color:#6ee7b7; border-color:rgba(46,158,99,.30); }
body[data-theme='dark'] .fin-dup-hint { background:rgba(245,158,11,.10); color:#fbbf24; border-color:rgba(245,158,11,.30); }
</style>

<div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-money-bill-trend-up text-emerald-600"></i>
                ระบบการเงิน — Cash Book
            </h2>
            <p class="text-xs text-slate-500 mt-1">บันทึกรายรับ-รายจ่ายของคลินิก ดูสรุปตามช่วงเวลา + หมวดหมู่</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button id="finBtnExport" class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" title="ดาวน์โหลด CSV ตามตัวกรองที่เลือก">
                <i class="fa-solid fa-file-csv"></i> CSV
            </button>
            <button id="finBtnCategories" class="btn-solid bg-amber-500 text-white hover:bg-amber-600 text-sm">
                <i class="fa-solid fa-tags"></i> จัดการหมวดหมู่
            </button>
            <button id="finBtnAdd" class="btn-solid bg-brand-500 text-white hover:bg-brand-600 text-sm">
                <i class="fa-solid fa-plus"></i> เพิ่มรายการ
            </button>
        </div>
    </div>

    <!-- KPI Summary -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="fin-kpi" style="background:#f0fdf4">
            <div class="ic" style="background:#dcfce7;color:#15803d"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div><div class="num" id="finKpiIncome">฿0</div><div class="lbl">รายได้</div></div>
        </div>
        <div class="fin-kpi" style="background:#fef2f2">
            <div class="ic" style="background:#fee2e2;color:#b91c1c"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div><div class="num" id="finKpiExpense">฿0</div><div class="lbl">รายจ่าย</div></div>
        </div>
        <div class="fin-kpi" style="background:#eff6ff">
            <div class="ic" style="background:#dbeafe;color:#1e40af"><i class="fa-solid fa-scale-balanced"></i></div>
            <div><div class="num" id="finKpiNet">฿0</div><div class="lbl">สุทธิ</div></div>
        </div>
        <div class="fin-kpi" style="background:#fafafa">
            <div class="ic" style="background:#e2e8f0;color:#475569"><i class="fa-solid fa-list-check"></i></div>
            <div><div class="num" id="finKpiCount">0</div><div class="lbl">จำนวนรายการ</div></div>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="fin-card">
        <!-- Quick-date chips -->
        <div class="fin-quick-dates" id="finQuickDates" style="margin-bottom:12px">
            <button class="fin-chip" data-range="today">วันนี้</button>
            <button class="fin-chip is-active" data-range="this_month">เดือนนี้</button>
            <button class="fin-chip" data-range="last_month">เดือนก่อน</button>
            <button class="fin-chip" data-range="last_3">3 เดือนล่าสุด</button>
            <button class="fin-chip" data-range="ytd">ตั้งแต่ต้นปี (YTD)</button>
            <button class="fin-chip" data-range="last_year">ปีที่แล้ว</button>
            <button class="fin-chip" data-range="all">ทั้งหมด</button>
        </div>

        <div class="fin-filter-bar">
            <div style="flex:1;min-width:220px">
                <label>ค้นหา</label>
                <input type="search" id="finSearch" placeholder="รายละเอียด / เลขที่ใบเสร็จ / อ้างอิง..." style="width:100%">
            </div>
            <div><label>จาก</label><input type="date" id="finFrom"></div>
            <div><label>ถึง</label><input type="date" id="finTo"></div>
            <div>
                <label>ประเภท</label>
                <select id="finKind">
                    <option value="">ทั้งหมด</option>
                    <option value="income">รายได้</option>
                    <option value="expense">รายจ่าย</option>
                </select>
            </div>
            <div>
                <label>หมวด</label>
                <select id="finCategoryFilter"><option value="0">ทั้งหมด</option></select>
            </div>
            <button id="finBtnApply" class="btn-solid bg-slate-700 text-white hover:bg-slate-800 text-sm">
                <i class="fa-solid fa-magnifying-glass"></i> กรอง
            </button>
            <button id="finBtnReset" class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm">
                <i class="fa-solid fa-rotate"></i> รีเซ็ต
            </button>
        </div>
    </div>

    <!-- Transactions table -->
    <div class="fin-card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="font-bold text-slate-700 text-sm">รายการรายรับ-รายจ่าย</div>
            <div id="finPageInfo" class="text-xs text-slate-500"></div>
        </div>
        <div class="overflow-x-auto">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th class="fin-chk"><input type="checkbox" id="finChkAll" title="เลือก/ยกเลิกทั้งหน้า"></th>
                        <th style="width:110px">วันที่</th>
                        <th style="width:90px">ประเภท</th>
                        <th>หมวด</th>
                        <th>รายละเอียด</th>
                        <th style="width:120px;text-align:right">จำนวนเงิน</th>
                        <th style="width:140px">เลขที่ / อ้างอิง</th>
                        <th style="width:110px;text-align:center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="finTbody"></tbody>
            </table>
        </div>
        <div id="finPagi" class="fin-pagi"></div>
    </div>

    <!-- Bulk action bar (sticky bottom, shows when items are selected) -->
    <div id="finBulkBar" class="fin-bulk-bar" role="region" aria-label="การจัดการรายการที่เลือก">
        <span class="fin-bulk-count"><i class="fa-solid fa-square-check"></i> เลือก <b id="finBulkCount">0</b> รายการ</span>
        <span class="fin-bulk-sum" id="finBulkSum">รวม ฿0</span>
        <span class="spacer"></span>
        <button class="btn-cancel" id="finBulkCancel"><i class="fa-solid fa-xmark"></i> ยกเลิก</button>
        <button class="btn-del" id="finBulkDelete"><i class="fa-solid fa-trash"></i> ลบที่เลือก</button>
    </div>
</div>

<script>
(function () {
    const CSRF = '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>';
    const AJAX = 'ajax_finance.php';
    const fmt = (n) => '฿' + Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const fmtDate = (d) => { if (!d) return '-'; const x = new Date(d); return x.toLocaleDateString('th-TH', { year: '2-digit', month: '2-digit', day: '2-digit' }); };

    let cachedCategories = [];
    let currentPage = 1;
    let selectedIds = new Set();   // ids of rows currently bulk-selected
    let lastRows = [];             // cache of last page render — used to compute selected sum

    // ── Defaults: this month ──
    function setDefaultDates() {
        const now = new Date();
        const first = new Date(now.getFullYear(), now.getMonth(), 1);
        const last  = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        // ห้ามใช้ toISOString() เพราะเป็น UTC — ที่ GMT+7 จะเลื่อน 1 วันก่อนหน้า
        // (วันที่ใน UI เป็น local date เสมอ)
        const toIso = (d) => {
            const y  = d.getFullYear();
            const m  = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${dd}`;
        };
        document.getElementById('finFrom').value = toIso(first);
        document.getElementById('finTo').value   = toIso(last);
    }
    setDefaultDates();

    // ── Load & render ──
    function currentFilterParams() {
        return {
            from: document.getElementById('finFrom').value,
            to:   document.getElementById('finTo').value,
            kind: document.getElementById('finKind').value || '',
            category_id: document.getElementById('finCategoryFilter').value || '0',
            q:    document.getElementById('finSearch').value.trim(),
        };
    }
    async function load(page = 1) {
        currentPage = page;
        const f = currentFilterParams();
        const params = new URLSearchParams({ action: 'list', page: String(page), ...f });
        const r = await fetch(AJAX + '?' + params.toString(), { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: j.message || '' }); return; }
        lastRows = j.rows || [];

        // KPI
        document.getElementById('finKpiIncome').textContent  = fmt(j.summary.income);
        document.getElementById('finKpiExpense').textContent = fmt(j.summary.expense);
        document.getElementById('finKpiNet').textContent     = fmt(j.summary.net);
        document.getElementById('finKpiCount').textContent   = j.summary.count;

        // Categories cache + filter dropdown
        cachedCategories = j.categories || [];
        const currentSel = document.getElementById('finCategoryFilter').value;
        const catSel = document.getElementById('finCategoryFilter');
        catSel.innerHTML = '<option value="0">ทั้งหมด</option>' + cachedCategories.map(c =>
            `<option value="${c.id}">${c.kind === 'income' ? '⬆️' : '⬇️'} ${escapeHtml(c.name)}</option>`).join('');
        catSel.value = currentSel;

        // Table
        const tbody = document.getElementById('finTbody');
        if (!j.rows || j.rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="fin-empty">
                <i class="fa-solid fa-folder-open text-3xl text-slate-300 mb-2 block"></i>
                ไม่พบรายการในช่วงที่เลือก</td></tr>`;
        } else {
            tbody.innerHTML = j.rows.map(row => {
                const kindBadge = row.kind === 'income'
                    ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">รายได้</span>'
                    : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">รายจ่าย</span>';
                const catChip = row.category_id
                    ? `<span class="fin-cat-chip" style="background:${row.category_color || '#e2e8f0'}20;color:${row.category_color || '#475569'};border:1px solid ${row.category_color || '#cbd5e1'}40">
                          <i class="fa-solid ${row.category_icon || 'fa-circle'}"></i> ${escapeHtml(row.category_name || '')}
                       </span>`
                    : '<span class="text-slate-400 text-xs">-</span>';
                const amtClass = row.kind === 'income' ? 'fin-amt-income' : 'fin-amt-expense';
                const amtPrefix = row.kind === 'income' ? '+' : '-';
                const refDisplay = row.receipt_no
                    ? `<span class="font-mono text-[11px] text-[#2e9e63] font-bold">${escapeHtml(row.receipt_no)}</span>` + (row.reference ? `<br><span class="text-[10px] text-slate-400 font-mono">${escapeHtml(row.reference)}</span>` : '')
                    : (row.reference ? `<span class="font-mono text-xs text-slate-500">${escapeHtml(row.reference)}</span>` : '<span class="text-slate-300 text-xs">-</span>');
                const isSel = selectedIds.has(row.id);
                return `<tr data-id="${row.id}" data-amount="${row.amount}" data-kind="${row.kind}" class="${isSel ? 'is-selected' : ''}">
                    <td class="fin-chk"><input type="checkbox" class="fin-row-chk" data-id="${row.id}" ${isSel ? 'checked' : ''}></td>
                    <td class="text-slate-600">${fmtDate(row.txn_date)}</td>
                    <td>${kindBadge}</td>
                    <td>${catChip}</td>
                    <td class="text-slate-700">${escapeHtml(row.description || '')}${row.payment_method ? ` <span class="text-[10px] text-slate-400">· ${escapeHtml(row.payment_method)}</span>` : ''}</td>
                    <td class="${amtClass} text-right">${amtPrefix}${fmt(row.amount)}</td>
                    <td>${refDisplay}</td>
                    <td class="text-center whitespace-nowrap">
                        <a href="finance_receipt.php?id=${row.id}" target="_blank" class="text-slate-500 hover:text-[#2e9e63] mr-2" title="พิมพ์ใบเสร็จ"><i class="fa-solid fa-print"></i></a>
                        <button onclick='finEditRow(${JSON.stringify(row).replace(/'/g, "&#39;")})' class="text-[#2e9e63] hover:text-[#27845a] mr-2" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                        <button onclick="finDeleteRow(${row.id})" class="text-rose-500 hover:text-rose-700" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');

            // Bind row checkboxes
            tbody.querySelectorAll('.fin-row-chk').forEach(cb => {
                cb.addEventListener('change', (e) => toggleRowSelection(parseInt(e.target.dataset.id, 10), e.target.checked));
            });
            syncSelectAllState();
        }
        updateBulkBar();

        // Pagination (window ±2 + first/prev/next/last)
        const totalPages = Math.max(1, Math.ceil(j.total / j.per_page));
        document.getElementById('finPageInfo').textContent = `หน้า ${j.page} / ${totalPages} · รวม ${j.total} รายการ`;
        const pagi = document.getElementById('finPagi');
        if (totalPages <= 1) { pagi.innerHTML = ''; }
        else {
            const btns = [];
            btns.push(`<button ${j.page === 1 ? 'disabled' : ''} onclick="finLoad(1)" title="หน้าแรก">«</button>`);
            btns.push(`<button ${j.page === 1 ? 'disabled' : ''} onclick="finLoad(${j.page - 1})" title="ก่อนหน้า">‹</button>`);
            for (let p = Math.max(1, j.page - 2); p <= Math.min(totalPages, j.page + 2); p++) {
                btns.push(`<button class="${p === j.page ? 'active' : ''}" onclick="finLoad(${p})">${p}</button>`);
            }
            btns.push(`<button ${j.page === totalPages ? 'disabled' : ''} onclick="finLoad(${j.page + 1})" title="ถัดไป">›</button>`);
            btns.push(`<button ${j.page === totalPages ? 'disabled' : ''} onclick="finLoad(${totalPages})" title="สุดท้าย">»</button>`);
            pagi.innerHTML = btns.join('');
        }
    }
    window.finLoad = load;

    function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

    // ── Add/Edit modal ──
    async function openTxnModal(row) {
        const isEdit = !!row;
        const cats = cachedCategories;
        const kind = row?.kind || 'income';

        const buildCatOptions = (selKind, selId) => cats.filter(c => c.kind === selKind)
            .map(c => `<option value="${c.id}" ${String(c.id) === String(selId) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`)
            .join('');

        const { value: formData } = await Swal.fire({
            title: isEdit ? 'แก้ไขรายการ' : 'เพิ่มรายการ',
            width: 560,
            html: `<div class="text-left space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600 mb-1 block">วันที่ *</label>
                        <input type="date" id="ftxDate" class="swal2-input" style="margin:0;width:100%" value="${row?.txn_date || new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-600 mb-1 block">ประเภท *</label>
                        <select id="ftxKind" class="swal2-input" style="margin:0;width:100%" onchange="document.getElementById('ftxCat').innerHTML = window._finBuildCatOpts(this.value, '')">
                            <option value="income" ${kind === 'income' ? 'selected' : ''}>รายได้</option>
                            <option value="expense" ${kind === 'expense' ? 'selected' : ''}>รายจ่าย</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600 mb-1 block">หมวด</label>
                        <select id="ftxCat" class="swal2-input" style="margin:0;width:100%">${buildCatOptions(kind, row?.category_id)}</select>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-600 mb-1 block">จำนวนเงิน (บาท) *</label>
                        <input type="number" id="ftxAmount" class="swal2-input" style="margin:0;width:100%" step="0.01" min="0" value="${row?.amount || ''}" placeholder="0.00">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600 mb-1 block">รายละเอียด</label>
                    <input type="text" id="ftxDesc" class="swal2-input" style="margin:0;width:100%" value="${escapeHtml(row?.description || '')}" placeholder="เช่น ค่ารักษาผู้ป่วยเลขที่ 1234">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600 mb-1 block">เลขอ้างอิง</label>
                        <input type="text" id="ftxRef" class="swal2-input" style="margin:0;width:100%" value="${escapeHtml(row?.reference || '')}" placeholder="เช่น INV-2025-001">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-600 mb-1 block">วิธีชำระ</label>
                        <select id="ftxPay" class="swal2-input" style="margin:0;width:100%">
                            <option value="">- ไม่ระบุ -</option>
                            <option value="เงินสด" ${row?.payment_method === 'เงินสด' ? 'selected' : ''}>เงินสด</option>
                            <option value="โอน" ${row?.payment_method === 'โอน' ? 'selected' : ''}>โอน</option>
                            <option value="บัตรเครดิต" ${row?.payment_method === 'บัตรเครดิต' ? 'selected' : ''}>บัตรเครดิต</option>
                            <option value="QR/PromptPay" ${row?.payment_method === 'QR/PromptPay' ? 'selected' : ''}>QR/PromptPay</option>
                            <option value="เช็ค" ${row?.payment_method === 'เช็ค' ? 'selected' : ''}>เช็ค</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600 mb-1 block">หมายเหตุ</label>
                    <textarea id="ftxNote" class="swal2-textarea" style="margin:0;width:100%;min-height:60px">${escapeHtml(row?.note || '')}</textarea>
                </div>
            </div>`,
            showCancelButton: true,
            confirmButtonText: isEdit ? 'บันทึก' : 'เพิ่ม',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2e9e63',
            didOpen: () => {
                window._finBuildCatOpts = (kind, selId) => buildCatOptions(kind, selId);

                // Duplicate-detection probe on the "อ้างอิง" field —
                // debounced check after the user pauses typing.
                const refInput = document.getElementById('ftxRef');
                if (refInput) {
                    let dupTimer = null;
                    const showHint = (rows) => {
                        const slot = document.getElementById('ftxDupSlot');
                        if (!slot) return;
                        if (!rows || rows.length === 0) { slot.innerHTML = ''; return; }
                        slot.innerHTML = `<div class="fin-dup-hint">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <b>อาจซ้ำ</b> — พบ ${rows.length} รายการที่มีเลขอ้างอิงเดียวกัน:
                            <ul>${rows.map(d => `<li>#${d.id} · ${d.txn_date} · ${d.kind === 'income' ? 'รายได้' : 'รายจ่าย'} · ฿${Number(d.amount).toLocaleString('th-TH', {minimumFractionDigits:2})} <span style="color:#a16207">— ${escapeHtml(d.description || '-')}</span></li>`).join('')}</ul>
                            <span style="color:#78350f;font-size:11px">บันทึกได้ตามปกติถ้าตั้งใจซ้ำ</span>
                        </div>`;
                    };
                    refInput.insertAdjacentHTML('afterend', '<div id="ftxDupSlot"></div>');
                    refInput.addEventListener('input', () => {
                        clearTimeout(dupTimer);
                        const v = refInput.value.trim();
                        if (!v) { document.getElementById('ftxDupSlot').innerHTML = ''; return; }
                        dupTimer = setTimeout(async () => {
                            const fd = new FormData();
                            fd.append('csrf_token', CSRF);
                            fd.append('action', 'txn:check_duplicate');
                            fd.append('reference', v);
                            fd.append('kind', document.getElementById('ftxKind').value);
                            if (isEdit) fd.append('exclude_id', String(row.id));
                            try {
                                const res = await fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
                                const j = await res.json();
                                if (j.ok) showHint(j.duplicates);
                            } catch (e) { /* silent */ }
                        }, 450);
                    });
                    // Run once on open if there's a pre-filled reference (edit mode)
                    if (refInput.value.trim()) refInput.dispatchEvent(new Event('input'));
                }
            },
            preConfirm: () => {
                const v = {
                    txn_date: document.getElementById('ftxDate').value,
                    kind: document.getElementById('ftxKind').value,
                    category_id: document.getElementById('ftxCat').value,
                    amount: document.getElementById('ftxAmount').value,
                    description: document.getElementById('ftxDesc').value,
                    reference: document.getElementById('ftxRef').value,
                    payment_method: document.getElementById('ftxPay').value,
                    note: document.getElementById('ftxNote').value,
                };
                if (!v.txn_date) { Swal.showValidationMessage('กรุณาระบุวันที่'); return false; }
                if (!v.amount || parseFloat(v.amount) <= 0) { Swal.showValidationMessage('จำนวนเงินต้อง > 0'); return false; }
                return v;
            }
        });

        if (!formData) return;

        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', isEdit ? 'txn:update' : 'txn:create');
        if (isEdit) fd.append('id', String(row.id));
        Object.entries(formData).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: j.message || '' }); return; }
        Swal.fire({ icon: 'success', title: isEdit ? 'อัปเดตแล้ว' : 'เพิ่มแล้ว', timer: 1200, showConfirmButton: false, toast: true, position: 'top-end' });
        load(currentPage);
    }
    window.finEditRow = openTxnModal;

    // ── Delete row ──
    window.finDeleteRow = async function (id) {
        const r = await Swal.fire({
            title: 'ลบรายการนี้?', text: 'การลบไม่สามารถยกเลิกได้',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626',
        });
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'txn:delete');
        fd.append('id', String(id));
        const res = await fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: j.message || '' }); return; }
        Swal.fire({ icon: 'success', title: 'ลบแล้ว', timer: 1200, showConfirmButton: false, toast: true, position: 'top-end' });
        load(currentPage);
    };

    // ── Categories modal ──
    async function openCategoriesModal() {
        const incomeRows = cachedCategories.filter(c => c.kind === 'income');
        const expenseRows = cachedCategories.filter(c => c.kind === 'expense');
        const buildRows = (rows) => rows.length === 0
            ? '<tr><td colspan="4" class="text-center text-slate-400 py-3 text-xs">ยังไม่มีหมวด</td></tr>'
            : rows.map(c => `<tr>
                <td><span class="fin-cat-chip" style="background:${c.color}20;color:${c.color};border:1px solid ${c.color}40"><i class="fa-solid ${c.icon || 'fa-circle'}"></i> ${escapeHtml(c.name)}</span></td>
                <td class="text-center"><input type="color" value="${c.color}" disabled style="width:28px;height:20px;border:1px solid #e2e8f0;border-radius:4px"></td>
                <td class="text-center text-xs text-slate-500">${c.sort_order ?? 0}</td>
                <td class="text-right">
                    <button onclick='finEditCat(${JSON.stringify(c).replace(/'/g, "&#39;")})' class="text-xs text-[#2e9e63] hover:text-[#27845a] mr-2">แก้</button>
                    <button onclick="finDeleteCat(${c.id})" class="text-xs text-rose-500 hover:text-rose-700">ลบ</button>
                </td>
              </tr>`).join('');

        Swal.fire({
            title: 'จัดการหมวดหมู่',
            width: 760,
            html: `<div class="text-left space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="border border-slate-200 rounded-lg overflow-hidden">
                        <div class="bg-emerald-50 text-emerald-800 font-bold text-sm px-3 py-2 border-b border-emerald-200">⬆️ รายได้ (${incomeRows.length})</div>
                        <table class="fin-table"><tbody>${buildRows(incomeRows)}</tbody></table>
                    </div>
                    <div class="border border-slate-200 rounded-lg overflow-hidden">
                        <div class="bg-rose-50 text-rose-800 font-bold text-sm px-3 py-2 border-b border-rose-200">⬇️ รายจ่าย (${expenseRows.length})</div>
                        <table class="fin-table"><tbody>${buildRows(expenseRows)}</tbody></table>
                    </div>
                </div>
                <button onclick="finEditCat(null)" class="btn-solid bg-[#2e9e63] text-white hover:bg-[#27845a] text-sm">
                    <i class="fa-solid fa-plus"></i> เพิ่มหมวดใหม่
                </button>
            </div>`,
            showCloseButton: true,
            showConfirmButton: false,
        });
    }

    window.finEditCat = async function (cat) {
        const isEdit = !!cat;
        const { value } = await Swal.fire({
            title: isEdit ? 'แก้ไขหมวด' : 'เพิ่มหมวด',
            width: 460,
            html: `<div class="text-left space-y-3">
                <div><label class="text-xs font-bold text-slate-600 mb-1 block">ชื่อหมวด *</label>
                    <input type="text" id="fcName" class="swal2-input" style="margin:0;width:100%" value="${escapeHtml(cat?.name || '')}" placeholder="เช่น ค่าจัดซื้อ">
                </div>
                <div><label class="text-xs font-bold text-slate-600 mb-1 block">ประเภท *</label>
                    <select id="fcKind" class="swal2-input" style="margin:0;width:100%" ${isEdit ? 'disabled' : ''}>
                        <option value="income" ${cat?.kind === 'income' ? 'selected' : ''}>รายได้</option>
                        <option value="expense" ${cat?.kind === 'expense' ? 'selected' : ''}>รายจ่าย</option>
                    </select>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><label class="text-xs font-bold text-slate-600 mb-1 block">ไอคอน FA</label>
                        <input type="text" id="fcIcon" class="swal2-input" style="margin:0;width:100%" value="${escapeHtml(cat?.icon || 'fa-circle')}" placeholder="fa-circle">
                    </div>
                    <div><label class="text-xs font-bold text-slate-600 mb-1 block">สี</label>
                        <input type="color" id="fcColor" value="${cat?.color || '#64748b'}" style="width:100%;height:36px;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer">
                    </div>
                    <div><label class="text-xs font-bold text-slate-600 mb-1 block">ลำดับ</label>
                        <input type="number" id="fcOrder" class="swal2-input" style="margin:0;width:100%" value="${cat?.sort_order ?? 0}">
                    </div>
                </div>
                <p class="text-[11px] text-slate-500">ไอคอน FA: ลองดูที่ <a href="https://fontawesome.com/search?ic=free" target="_blank" class="text-[#2e9e63] underline">fontawesome.com</a> ใส่เฉพาะชื่อขึ้นต้น "fa-"</p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#2e9e63',
            preConfirm: () => {
                const name = document.getElementById('fcName').value.trim();
                if (!name) { Swal.showValidationMessage('กรุณากรอกชื่อหมวด'); return false; }
                return {
                    name,
                    kind: document.getElementById('fcKind').value,
                    icon: document.getElementById('fcIcon').value.trim() || 'fa-circle',
                    color: document.getElementById('fcColor').value,
                    sort_order: document.getElementById('fcOrder').value || '0',
                };
            }
        });
        if (!value) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', isEdit ? 'category:update' : 'category:create');
        if (isEdit) fd.append('id', String(cat.id));
        Object.entries(value).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: j.message || '' }); return; }
        await load(currentPage);
        openCategoriesModal();
    };

    window.finDeleteCat = async function (id) {
        const r = await Swal.fire({
            title: 'ลบหมวดนี้?', text: 'หมวดที่มีรายการอ้างอิงจะลบไม่ได้',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626',
        });
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'category:delete');
        fd.append('id', String(id));
        const res = await fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: j.message || '' }); return; }
        await load(currentPage);
        openCategoriesModal();
    };

    // ── Quick-date chips ────────────────────────────────────
    function setDateRange(range) {
        const now = new Date();
        const toIso = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        let from, to;
        switch (range) {
            case 'today':
                from = to = new Date(now);
                break;
            case 'this_month':
                from = new Date(now.getFullYear(), now.getMonth(), 1);
                to   = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'last_month':
                from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                to   = new Date(now.getFullYear(), now.getMonth(), 0);
                break;
            case 'last_3':
                from = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                to   = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'ytd':
                from = new Date(now.getFullYear(), 0, 1);
                to   = new Date(now);
                break;
            case 'last_year':
                from = new Date(now.getFullYear() - 1, 0, 1);
                to   = new Date(now.getFullYear() - 1, 11, 31);
                break;
            case 'all':
                from = new Date(2000, 0, 1);
                to   = new Date(now.getFullYear() + 1, 11, 31);
                break;
        }
        document.getElementById('finFrom').value = toIso(from);
        document.getElementById('finTo').value   = toIso(to);
        document.querySelectorAll('#finQuickDates .fin-chip').forEach(c => c.classList.toggle('is-active', c.dataset.range === range));
    }
    document.querySelectorAll('#finQuickDates .fin-chip').forEach(c => {
        c.addEventListener('click', () => { setDateRange(c.dataset.range); load(1); });
    });
    // When user manually edits a date, unselect chips
    ['finFrom', 'finTo'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            document.querySelectorAll('#finQuickDates .fin-chip.is-active').forEach(c => c.classList.remove('is-active'));
        });
    });

    // ── Search box (debounced) ─────────────────────────────
    let searchTimer = null;
    document.getElementById('finSearch').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => load(1), 350);
    });

    // ── CSV export ─────────────────────────────────────────
    document.getElementById('finBtnExport').onclick = () => {
        const f = currentFilterParams();
        const params = new URLSearchParams(f);
        // Open in same tab so the browser triggers the download dialog from
        // the Content-Disposition header; portal session cookie travels along.
        window.location.href = 'finance_export.php?' + params.toString();
    };

    // ── Bulk selection ─────────────────────────────────────
    function toggleRowSelection(id, checked) {
        if (checked) selectedIds.add(id); else selectedIds.delete(id);
        const tr = document.querySelector(`#finTbody tr[data-id="${id}"]`);
        if (tr) tr.classList.toggle('is-selected', checked);
        syncSelectAllState();
        updateBulkBar();
    }
    function syncSelectAllState() {
        const allChk = document.getElementById('finChkAll');
        const rows = lastRows.map(r => r.id);
        if (rows.length === 0) { allChk.checked = false; allChk.indeterminate = false; return; }
        const selectedOnPage = rows.filter(id => selectedIds.has(id)).length;
        allChk.checked = selectedOnPage === rows.length;
        allChk.indeterminate = selectedOnPage > 0 && selectedOnPage < rows.length;
    }
    document.getElementById('finChkAll').addEventListener('change', (e) => {
        const checked = e.target.checked;
        lastRows.forEach(r => {
            if (checked) selectedIds.add(r.id); else selectedIds.delete(r.id);
        });
        // re-paint row chk + class without full reload
        document.querySelectorAll('#finTbody .fin-row-chk').forEach(cb => { cb.checked = checked; });
        document.querySelectorAll('#finTbody tr[data-id]').forEach(tr => tr.classList.toggle('is-selected', checked));
        updateBulkBar();
    });
    function updateBulkBar() {
        const bar = document.getElementById('finBulkBar');
        const count = selectedIds.size;
        document.getElementById('finBulkCount').textContent = count;
        // Compute sum across all currently-visible rows that are selected
        // (we only know amount/kind of rows currently in lastRows — Phase A scope)
        let sum = 0;
        lastRows.forEach(r => {
            if (selectedIds.has(r.id)) {
                sum += (r.kind === 'income' ? 1 : -1) * Number(r.amount || 0);
            }
        });
        const sumStr = (sum >= 0 ? '+' : '') + fmt(Math.abs(sum));
        document.getElementById('finBulkSum').textContent = 'รวม ' + sumStr;
        bar.classList.toggle('is-visible', count > 0);
    }
    document.getElementById('finBulkCancel').onclick = () => {
        selectedIds.clear();
        document.querySelectorAll('#finTbody .fin-row-chk').forEach(cb => { cb.checked = false; });
        document.querySelectorAll('#finTbody tr.is-selected').forEach(tr => tr.classList.remove('is-selected'));
        syncSelectAllState();
        updateBulkBar();
    };
    document.getElementById('finBulkDelete').onclick = async () => {
        if (selectedIds.size === 0) return;
        const r = await Swal.fire({
            icon: 'warning',
            title: `ลบ ${selectedIds.size} รายการที่เลือก?`,
            text: 'การลบไม่สามารถยกเลิกได้',
            showCancelButton: true,
            confirmButtonText: 'ลบทั้งหมด', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'txn:bulk_delete');
        [...selectedIds].forEach(id => fd.append('ids[]', String(id)));
        const res = await fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: j.message || '' }); return; }
        Swal.fire({ icon: 'success', title: `ลบ ${j.deleted} รายการแล้ว`, timer: 1400, showConfirmButton: false, toast: true, position: 'top-end' });
        selectedIds.clear();
        load(currentPage);
    };

    // ── Bind ──
    document.getElementById('finBtnApply').onclick = () => load(1);
    document.getElementById('finBtnReset').onclick = () => {
        setDefaultDates(); setDateRange('this_month');
        document.getElementById('finKind').value = '';
        document.getElementById('finCategoryFilter').value = '0';
        document.getElementById('finSearch').value = '';
        selectedIds.clear();
        load(1);
    };
    document.getElementById('finBtnAdd').onclick = () => openTxnModal(null);
    document.getElementById('finBtnCategories').onclick = openCategoriesModal;

    load(1);
})();
</script>
