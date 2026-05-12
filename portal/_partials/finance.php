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
.fin-table { width:100%; border-collapse:collapse; font-size:13px; }
.fin-table th { background:#f8fafc; padding:9px 10px; text-align:left; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; border-bottom:1px solid #e2e8f0; }
.fin-table td { padding:10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.fin-table tbody tr:hover { background:#fafbfc; }
.fin-cat-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fin-amt-income { color:#059669; font-weight:800; }
.fin-amt-expense { color:#dc2626; font-weight:800; }
.fin-pagi { display:flex; gap:4px; align-items:center; justify-content:center; padding:14px 0; }
.fin-pagi button { padding:6px 10px; border:1px solid #cbd5e1; background:#fff; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; min-width:34px; }
.fin-pagi button:hover:not(:disabled) { background:#f0faf4; border-color:#2e9e63; color:#2e9e63; }
.fin-pagi button:disabled { opacity:0.4; cursor:not-allowed; }
.fin-pagi button.active { background:#2e9e63; color:#fff; border-color:#2e9e63; }
.fin-empty { text-align:center; padding:48px 16px; color:#94a3b8; font-size:13px; }
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
        <div class="flex items-center gap-2">
            <button id="finBtnCategories" class="btn-solid bg-amber-500 text-white hover:bg-amber-600 text-sm">
                <i class="fa-solid fa-tags"></i> จัดการหมวดหมู่
            </button>
            <button id="finBtnAdd" class="btn-solid bg-[#2e9e63] text-white hover:bg-[#27845a] text-sm">
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
        <div class="fin-filter-bar">
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
                        <th style="width:110px">วันที่</th>
                        <th style="width:90px">ประเภท</th>
                        <th>หมวด</th>
                        <th>รายละเอียด</th>
                        <th style="width:120px;text-align:right">จำนวนเงิน</th>
                        <th style="width:120px">อ้างอิง</th>
                        <th style="width:80px;text-align:center">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="finTbody"></tbody>
            </table>
        </div>
        <div id="finPagi" class="fin-pagi"></div>
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

    // ── Defaults: this month ──
    function setDefaultDates() {
        const now = new Date();
        const first = new Date(now.getFullYear(), now.getMonth(), 1);
        const last  = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        const toIso = (d) => d.toISOString().slice(0, 10);
        document.getElementById('finFrom').value = toIso(first);
        document.getElementById('finTo').value   = toIso(last);
    }
    setDefaultDates();

    // ── Load & render ──
    async function load(page = 1) {
        currentPage = page;
        const params = new URLSearchParams({
            action: 'list',
            from: document.getElementById('finFrom').value,
            to:   document.getElementById('finTo').value,
            kind: document.getElementById('finKind').value || '',
            category_id: document.getElementById('finCategoryFilter').value || '0',
            page: String(page),
        });
        const r = await fetch(AJAX + '?' + params.toString(), { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: j.message || '' }); return; }

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
            tbody.innerHTML = `<tr><td colspan="7" class="fin-empty">
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
                return `<tr>
                    <td class="text-slate-600">${fmtDate(row.txn_date)}</td>
                    <td>${kindBadge}</td>
                    <td>${catChip}</td>
                    <td class="text-slate-700">${escapeHtml(row.description || '')}${row.payment_method ? ` <span class="text-[10px] text-slate-400">· ${escapeHtml(row.payment_method)}</span>` : ''}</td>
                    <td class="${amtClass} text-right">${amtPrefix}${fmt(row.amount)}</td>
                    <td class="text-xs text-slate-500 font-mono">${escapeHtml(row.reference || '-')}</td>
                    <td class="text-center">
                        <button onclick='finEditRow(${JSON.stringify(row).replace(/'/g, "&#39;")})' class="text-[#2e9e63] hover:text-[#27845a] mr-2" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                        <button onclick="finDeleteRow(${row.id})" class="text-rose-500 hover:text-rose-700" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        }

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

    // ── Bind ──
    document.getElementById('finBtnApply').onclick = () => load(1);
    document.getElementById('finBtnReset').onclick = () => { setDefaultDates(); document.getElementById('finKind').value = ''; document.getElementById('finCategoryFilter').value = '0'; load(1); };
    document.getElementById('finBtnAdd').onclick = () => openTxnModal(null);
    document.getElementById('finBtnCategories').onclick = openCategoriesModal;

    load(1);
})();
</script>
