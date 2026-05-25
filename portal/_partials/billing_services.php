<?php
/**
 * portal/_partials/billing_services.php
 * Patient Billing — Service Catalog admin UI
 *
 * Backend: portal/ajax_billing.php (entity=service)
 *
 * Loaded by portal/billing_services.php after role gate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/patient_billing_helper.php';

$_pdo = db();
pb_ensure_schema($_pdo);

$pbServiceCsrf = get_csrf_token();
?>

<style>
    /* ── Service catalog styles (scoped to this section) ─────────── */
    #section-billing_services .bs-card {
        background: #fff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
        padding: 18px 20px;
    }
    body[data-theme='dark'] #section-billing_services .bs-card {
        background: #1e293b;
        border-color: #334155;
    }

    #section-billing_services .bs-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
    }
    #section-billing_services .bs-pill-consultation { background:#dbeafe; color:#1e40af; }
    #section-billing_services .bs-pill-treatment    { background:#dcfce7; color:#166534; }
    #section-billing_services .bs-pill-procedure    { background:#fce7f3; color:#9d174d; }
    #section-billing_services .bs-pill-lab          { background:#e0e7ff; color:#3730a3; }
    #section-billing_services .bs-pill-vaccination  { background:#ccfbf1; color:#0f766e; }
    #section-billing_services .bs-pill-consumable   { background:#fef3c7; color:#92400e; }
    #section-billing_services .bs-pill-other        { background:#f1f5f9; color:#475569; }

    #section-billing_services .bs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    #section-billing_services .bs-table th {
        background: #f8fafc;
        padding: 12px 14px;
        text-align: left;
        font-weight: 800;
        color: #475569;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #e2e8f0;
    }
    #section-billing_services .bs-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    body[data-theme='dark'] #section-billing_services .bs-table th {
        background: #0f172a;
        color: #cbd5e1;
        border-color: #334155;
    }
    body[data-theme='dark'] #section-billing_services .bs-table td {
        border-color: #334155;
        color: #e2e8f0;
    }
    #section-billing_services .bs-table tr:hover td {
        background: #f8fafc;
    }
    body[data-theme='dark'] #section-billing_services .bs-table tr:hover td {
        background: #0f172a;
    }

    #section-billing_services .bs-icon-btn {
        width: 32px; height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1.5px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        cursor: pointer;
        transition: all .15s ease;
    }
    #section-billing_services .bs-icon-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(15,23,42,.08);
    }
    #section-billing_services .bs-icon-btn.is-edit:hover    { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    #section-billing_services .bs-icon-btn.is-toggle:hover  { background:#f59e0b; color:#fff; border-color:#f59e0b; }
    #section-billing_services .bs-icon-btn.is-delete:hover  { background:#ef4444; color:#fff; border-color:#ef4444; }

    #section-billing_services .bs-pager {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 14px 0 4px;
        font-size: 13px;
        color: #64748b;
    }
    #section-billing_services .bs-pager-btns {
        display: flex;
        gap: 4px;
    }
    #section-billing_services .bs-pager-btn {
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        font-weight: 700;
        color: #475569;
        cursor: pointer;
    }
    #section-billing_services .bs-pager-btn:hover:not(:disabled) {
        background: #f1f5f9;
    }
    #section-billing_services .bs-pager-btn.is-active {
        background: #2e9e63;
        color: #fff;
        border-color: #2e9e63;
    }
    #section-billing_services .bs-pager-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    /* Modal — Portal-Escape pattern */
    #bsServiceModal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55) !important;
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        z-index: 9000 !important;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    #bsServiceModal.is-open { display: flex; }
    #bsServiceModal .bs-modal-box {
        background: #fff;
        border-radius: 18px;
        width: 100%;
        max-width: 560px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 24px 64px rgba(15,23,42,.3);
    }
    body[data-theme='dark'] #bsServiceModal .bs-modal-box {
        background: #1e293b;
        color: #e2e8f0;
    }

    #section-billing_services .bs-input,
    #bsServiceModal .bs-input {
        width: 100%;
        padding: 10px 14px;
        border-radius: 10px;
        border: 1.5px solid #e2e8f0;
        background: #f8fafc;
        font-size: 14px;
        font-weight: 500;
        outline: none;
        transition: all .15s ease;
    }
    #section-billing_services .bs-input:focus,
    #bsServiceModal .bs-input:focus {
        background: #fff;
        border-color: #2e9e63;
        box-shadow: 0 0 0 4px rgba(46,158,99,.12);
    }
    body[data-theme='dark'] #section-billing_services .bs-input,
    body[data-theme='dark'] #bsServiceModal .bs-input {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    #section-billing_services .bs-empty {
        text-align: center;
        padding: 60px 20px;
        color: #94a3b8;
    }
    #section-billing_services .bs-empty i {
        font-size: 48px;
        margin-bottom: 12px;
        opacity: 0.5;
        display: block;
    }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#2e9e63,#3bba7a);color:#fff">
                <i class="fa-solid fa-list-check"></i>
            </span>
            บริการคลินิก
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            ตั้งค่ารายการบริการที่ใช้สร้างใบแจ้งหนี้ผู้ป่วย — กำหนดรหัส ราคา หน่วยนับ
        </p>
    </div>
    <button type="button" class="ds-btn ds-btn-primary" onclick="bsOpenModal()">
        <i class="fa-solid fa-plus mr-1"></i> เพิ่มบริการ
    </button>
</div>

<!-- Filter bar -->
<div class="bs-card mb-5 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[200px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค้นหา</label>
        <input id="bsSearch" type="text" class="bs-input" placeholder="รหัสหรือชื่อบริการ...">
    </div>
    <div class="min-w-[160px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">หมวดหมู่</label>
        <select id="bsFilterCategory" class="bs-input">
            <option value="">— ทุกหมวด —</option>
            <?php foreach (PB_SERVICE_CATEGORIES as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="min-w-[140px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">สถานะ</label>
        <select id="bsFilterActive" class="bs-input">
            <option value="1">ใช้งานอยู่</option>
            <option value="0">ปิดใช้งาน</option>
            <option value="-1">ทั้งหมด</option>
        </select>
    </div>
    <button type="button" class="ds-btn ds-btn-ghost" onclick="bsResetFilters()">
        <i class="fa-solid fa-rotate-left mr-1"></i> ล้าง
    </button>
</div>

<!-- Table -->
<div class="bs-card">
    <div id="bsTableWrap">
        <!-- AJAX content here -->
        <div class="bs-empty">
            <i class="fa-solid fa-spinner fa-spin"></i>
            กำลังโหลดบริการ...
        </div>
    </div>
    <div id="bsPagerWrap"></div>
</div>

<!-- Service modal (teleported to body on open — Portal-Escape) -->
<div id="bsServiceModal">
    <div class="bs-modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-700">
            <h3 id="bsModalTitle" class="text-lg font-black">เพิ่มบริการ</h3>
            <button type="button" onclick="bsCloseModal()"
                    class="w-8 h-8 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center">
                <i class="fa-solid fa-xmark text-slate-500"></i>
            </button>
        </div>
        <form id="bsServiceForm" class="px-6 py-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($pbServiceCsrf) ?>">
            <input type="hidden" name="action" value="service:create">
            <input type="hidden" name="id" value="">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        รหัสบริการ <span class="text-rose-500">*</span>
                    </label>
                    <input name="code" type="text" class="bs-input" placeholder="เช่น CONS-GP" required maxlength="40">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        หมวดหมู่ <span class="text-rose-500">*</span>
                    </label>
                    <select name="category" class="bs-input" required>
                        <?php foreach (PB_SERVICE_CATEGORIES as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                    ชื่อบริการ <span class="text-rose-500">*</span>
                </label>
                <input name="name" type="text" class="bs-input" placeholder="เช่น ตรวจรักษาทั่วไป" required maxlength="200">
            </div>

            <div>
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">คำอธิบาย</label>
                <textarea name="description" class="bs-input" rows="2" maxlength="500" placeholder="(ถ้ามี)"></textarea>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        ราคา (บาท) <span class="text-rose-500">*</span>
                    </label>
                    <input name="unit_price" type="number" min="0" step="0.01" class="bs-input" value="0" required>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        หน่วย <span class="text-rose-500">*</span>
                    </label>
                    <input name="unit_label" type="text" class="bs-input" value="ครั้ง" required maxlength="40">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ลำดับ</label>
                    <input name="sort_order" type="number" class="bs-input" value="0">
                </div>
            </div>

            <div class="flex flex-wrap gap-4 pt-1">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input name="is_active" type="checkbox" value="1" checked
                           class="w-4 h-4 rounded text-emerald-600">
                    <span class="text-sm font-medium">เปิดใช้งาน</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input name="is_taxable" type="checkbox" value="1"
                           class="w-4 h-4 rounded text-emerald-600">
                    <span class="text-sm font-medium">คิด VAT</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-700">
                <button type="button" onclick="bsCloseModal()" class="ds-btn ds-btn-ghost">ยกเลิก</button>
                <button type="submit" id="bsSubmitBtn" class="ds-btn ds-btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const AJAX_URL = 'ajax_billing.php';
    const CATEGORIES = <?= json_encode(PB_SERVICE_CATEGORIES, JSON_UNESCAPED_UNICODE) ?>;

    let bsCurrentPage = 1;
    let bsSearchDebounce = null;

    function thBaht(n) {
        return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function loadServices() {
        const params = new URLSearchParams({
            action:   'service:list',
            q:        document.getElementById('bsSearch').value || '',
            category: document.getElementById('bsFilterCategory').value || '',
            active:   document.getElementById('bsFilterActive').value || '1',
            page:     bsCurrentPage,
            per_page: 20,
        });

        fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    document.getElementById('bsTableWrap').innerHTML =
                        '<div class="bs-empty"><i class="fa-solid fa-circle-exclamation"></i>' +
                        escapeHtml(d.message || 'โหลดข้อมูลไม่สำเร็จ') + '</div>';
                    return;
                }
                renderTable(d);
                renderPager(d);
            })
            .catch(() => {
                document.getElementById('bsTableWrap').innerHTML =
                    '<div class="bs-empty"><i class="fa-solid fa-wifi"></i> เชื่อมต่อไม่ได้</div>';
            });
    }

    function renderTable(d) {
        if (!d.rows || d.rows.length === 0) {
            document.getElementById('bsTableWrap').innerHTML =
                '<div class="bs-empty"><i class="fa-regular fa-folder-open"></i>' +
                'ยังไม่มีบริการที่ตรงกับเงื่อนไข<br>' +
                '<small>ลองล้างตัวกรอง หรือกด "เพิ่มบริการ" ที่มุมขวาบน</small></div>';
            return;
        }

        let html = '<div style="overflow-x:auto"><table class="bs-table"><thead><tr>' +
            '<th>รหัส</th>' +
            '<th>ชื่อบริการ</th>' +
            '<th>หมวด</th>' +
            '<th style="text-align:right">ราคา/หน่วย</th>' +
            '<th style="text-align:center">สถานะ</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';

        d.rows.forEach(r => {
            const catLabel = CATEGORIES[r.category] || r.category;
            const catCls   = 'bs-pill bs-pill-' + r.category;
            const activeBadge = Number(r.is_active) === 1
                ? '<span class="bs-pill" style="background:#dcfce7;color:#15803d">เปิดใช้งาน</span>'
                : '<span class="bs-pill" style="background:#f1f5f9;color:#64748b">ปิดอยู่</span>';
            html += '<tr>' +
                '<td><code style="background:#f1f5f9;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:600;color:#475569">' + escapeHtml(r.code) + '</code></td>' +
                '<td><div class="font-semibold text-slate-800 dark:text-slate-100">' + escapeHtml(r.name) + '</div>' +
                  (r.description ? '<div class="text-xs text-slate-500 mt-0.5">' + escapeHtml(r.description) + '</div>' : '') +
                '</td>' +
                '<td><span class="' + catCls + '">' + escapeHtml(catLabel) + '</span></td>' +
                '<td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums">' +
                    thBaht(r.unit_price) + '<span class="text-xs text-slate-400"> /' + escapeHtml(r.unit_label) + '</span></td>' +
                '<td style="text-align:center">' + activeBadge + '</td>' +
                '<td style="text-align:center;white-space:nowrap">' +
                    '<button class="bs-icon-btn is-edit" onclick="bsOpenModal(' + Number(r.id) + ')" title="แก้ไข">' +
                        '<i class="fa-solid fa-pen-to-square text-xs"></i></button> ' +
                    '<button class="bs-icon-btn is-toggle" onclick="bsToggle(' + Number(r.id) + ')" title="' +
                        (Number(r.is_active) === 1 ? 'ปิดใช้งาน' : 'เปิดใช้งาน') + '">' +
                        '<i class="fa-solid fa-' + (Number(r.is_active) === 1 ? 'toggle-on' : 'toggle-off') + ' text-xs"></i></button> ' +
                    '<button class="bs-icon-btn is-delete" onclick="bsDelete(' + Number(r.id) + ', \'' +
                        escapeHtml(r.code).replace(/'/g, "\\'") + '\')" title="ลบ">' +
                        '<i class="fa-solid fa-trash-can text-xs"></i></button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('bsTableWrap').innerHTML = html;
    }

    function renderPager(d) {
        if (!d.total || d.total === 0) {
            document.getElementById('bsPagerWrap').innerHTML = '';
            return;
        }
        const cur = d.page, pages = d.pages, total = d.total;
        let html = '<div class="bs-pager">' +
            '<div>หน้า ' + cur + ' / ' + pages + ' · รวม ' + total.toLocaleString() + ' รายการ</div>' +
            '<div class="bs-pager-btns">';

        const btn = (label, target, opts) => {
            opts = opts || {};
            const cls = (opts.active ? ' is-active' : '');
            const dis = opts.disabled ? ' disabled' : '';
            return '<button class="bs-pager-btn' + cls + '"' + dis +
                ' onclick="window.bsGoToPage(' + target + ')">' + label + '</button>';
        };

        html += btn('«', 1, { disabled: cur === 1 });
        html += btn('‹', Math.max(1, cur - 1), { disabled: cur === 1 });

        const start = Math.max(1, cur - 2);
        const end   = Math.min(pages, cur + 2);
        for (let i = start; i <= end; i++) html += btn(i, i, { active: i === cur });

        html += btn('›', Math.min(pages, cur + 1), { disabled: cur === pages });
        html += btn('»', pages, { disabled: cur === pages });
        html += '</div></div>';
        document.getElementById('bsPagerWrap').innerHTML = html;
    }

    window.bsGoToPage = function (n) {
        bsCurrentPage = n;
        loadServices();
    };

    window.bsResetFilters = function () {
        document.getElementById('bsSearch').value = '';
        document.getElementById('bsFilterCategory').value = '';
        document.getElementById('bsFilterActive').value = '1';
        bsCurrentPage = 1;
        loadServices();
    };

    function teleportModal() {
        const m = document.getElementById('bsServiceModal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    window.bsOpenModal = function (id) {
        const m = teleportModal();
        const form = document.getElementById('bsServiceForm');
        form.reset();
        form.querySelector('[name="id"]').value = '';
        form.querySelector('[name="action"]').value = 'service:create';
        document.getElementById('bsModalTitle').textContent = 'เพิ่มบริการ';

        if (id) {
            const params = new URLSearchParams({ action: 'service:get', id: id });
            fetch(AJAX_URL + '?' + params.toString())
                .then(r => r.json())
                .then(d => {
                    if (!d.ok || !d.row) {
                        Swal.fire({ icon: 'error', title: 'ไม่พบบริการ', text: d.message || '' });
                        return;
                    }
                    const r = d.row;
                    document.getElementById('bsModalTitle').textContent = 'แก้ไขบริการ · ' + r.code;
                    form.querySelector('[name="action"]').value = 'service:update';
                    form.querySelector('[name="id"]').value          = r.id;
                    form.querySelector('[name="code"]').value        = r.code;
                    form.querySelector('[name="name"]').value        = r.name;
                    form.querySelector('[name="category"]').value    = r.category;
                    form.querySelector('[name="description"]').value = r.description || '';
                    form.querySelector('[name="unit_price"]').value  = r.unit_price;
                    form.querySelector('[name="unit_label"]').value  = r.unit_label;
                    form.querySelector('[name="sort_order"]').value  = r.sort_order;
                    form.querySelector('[name="is_active"]').checked  = Number(r.is_active) === 1;
                    form.querySelector('[name="is_taxable"]').checked = Number(r.is_taxable) === 1;
                });
        }

        m.classList.add('is-open');
    };

    window.bsCloseModal = function () {
        document.getElementById('bsServiceModal').classList.remove('is-open');
    };

    // Backdrop click to close
    document.getElementById('bsServiceModal').addEventListener('click', function (e) {
        if (e.target === this) bsCloseModal();
    });

    // Form submit
    document.getElementById('bsServiceForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        // Checkbox unchecked → not in FormData. Normalize.
        if (!fd.has('is_active'))  fd.set('is_active',  '0');
        if (!fd.has('is_taxable')) fd.set('is_taxable', '0');

        const btn = document.getElementById('bsSubmitBtn');
        btn.disabled = true;
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> กำลังบันทึก...';

        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    bsCloseModal();
                    Swal.fire({
                        icon: 'success', title: d.message || 'บันทึกแล้ว',
                        timer: 1400, showConfirmButton: false,
                    });
                    loadServices();
                } else {
                    const errMsg = d.message || 'บันทึกไม่สำเร็จ';
                    const errDetail = d.errors
                        ? '<ul style="text-align:left;margin-top:10px;font-size:13px">' +
                          Object.entries(d.errors).map(([k, v]) =>
                              '<li><b>' + escapeHtml(k) + '</b>: ' + escapeHtml(v) + '</li>').join('') +
                          '</ul>'
                        : '';
                    Swal.fire({
                        icon: 'error', title: 'บันทึกไม่ได้',
                        html: escapeHtml(errMsg) + errDetail,
                    });
                }
            })
            .catch(() => {
                Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่ได้' });
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            });
    });

    window.bsToggle = function (id) {
        const fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars($pbServiceCsrf) ?>');
        fd.append('action', 'service:toggle');
        fd.append('id', id);

        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    loadServices();
                } else {
                    Swal.fire({ icon: 'error', title: d.message || 'สลับสถานะไม่ได้' });
                }
            });
    };

    window.bsDelete = async function (id, code) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning',
            title: 'ลบบริการ?',
            text: 'รหัส ' + code + ' จะถูกลบอย่างถาวร · กดปิดใช้งานแทนได้ถ้าต้องการเก็บไว้เป็นประวัติ',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText:  'ยกเลิก',
            confirmButtonColor: '#ef4444',
        });
        if (!isConfirmed) return;

        const fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars($pbServiceCsrf) ?>');
        fd.append('action', 'service:delete');
        fd.append('id', id);
        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({ icon: 'success', title: d.message || 'ลบแล้ว',
                                timer: 1200, showConfirmButton: false });
                    loadServices();
                } else {
                    Swal.fire({ icon: 'error', title: 'ลบไม่ได้', text: d.message || '' });
                }
            });
    };

    // Search debounce (350ms)
    document.getElementById('bsSearch').addEventListener('input', () => {
        clearTimeout(bsSearchDebounce);
        bsSearchDebounce = setTimeout(() => {
            bsCurrentPage = 1;
            loadServices();
        }, 350);
    });

    // Filter changes — reload immediately
    ['bsFilterCategory', 'bsFilterActive'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            bsCurrentPage = 1;
            loadServices();
        });
    });

    // ESC closes modal
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' &&
            document.getElementById('bsServiceModal').classList.contains('is-open')) {
            bsCloseModal();
        }
    });

    // Initial load
    loadServices();
})();
</script>
