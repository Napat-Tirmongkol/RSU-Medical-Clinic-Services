<?php
/**
 * portal/_partials/billing_encounters.php
 * Patient Billing — Encounter (visit) admin UI
 *
 * Backend: portal/ajax_billing.php (entity=encounter, lookup)
 *
 * Loaded by portal/billing_encounters.php after role gate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/patient_billing_helper.php';

$_pdo = db();
pb_ensure_schema($_pdo);

// Preload active services for the line-item picker (catalog is small)
$activeServices = $_pdo->query("
    SELECT id, code, name, category, unit_price, unit_label
    FROM sys_billing_services
    WHERE is_active = 1
    ORDER BY category ASC, sort_order ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$beCsrf = get_csrf_token();
?>

<style>
    /* Scoped encounter styles */
    #section-billing_encounters .be-card {
        background: #fff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
        padding: 18px 20px;
    }
    body[data-theme='dark'] #section-billing_encounters .be-card {
        background: #1e293b;
        border-color: #334155;
    }

    #section-billing_encounters .be-input,
    #beEncModal .be-input {
        width: 100%;
        padding: 9px 12px;
        border-radius: 10px;
        border: 1.5px solid #e2e8f0;
        background: #f8fafc;
        font-size: 14px;
        font-weight: 500;
        outline: none;
        transition: all .15s ease;
    }
    #section-billing_encounters .be-input:focus,
    #beEncModal .be-input:focus {
        background: #fff;
        border-color: #2e9e63;
        box-shadow: 0 0 0 3px rgba(46,158,99,.12);
    }
    body[data-theme='dark'] #section-billing_encounters .be-input,
    body[data-theme='dark'] #beEncModal .be-input {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    #section-billing_encounters .be-status {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
    }
    #section-billing_encounters .be-status-draft     { background:#f1f5f9; color:#64748b; }
    #section-billing_encounters .be-status-finalized { background:#dcfce7; color:#15803d; }
    #section-billing_encounters .be-status-invoiced  { background:#dbeafe; color:#1d4ed8; }
    #section-billing_encounters .be-status-cancelled { background:#fee2e2; color:#b91c1c; }

    #section-billing_encounters .be-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    #section-billing_encounters .be-table th {
        background: #f8fafc;
        padding: 11px 14px;
        text-align: left;
        font-weight: 800;
        color: #475569;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #e2e8f0;
    }
    #section-billing_encounters .be-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
    }
    body[data-theme='dark'] #section-billing_encounters .be-table th {
        background: #0f172a;
        color: #cbd5e1;
        border-color: #334155;
    }
    body[data-theme='dark'] #section-billing_encounters .be-table td {
        border-color: #334155;
        color: #e2e8f0;
    }

    #section-billing_encounters .be-icon-btn {
        width: 30px; height: 30px;
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
    #section-billing_encounters .be-icon-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(15,23,42,.08);
    }
    #section-billing_encounters .be-icon-btn.is-edit:hover   { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    #section-billing_encounters .be-icon-btn.is-final:hover  { background:#16a34a; color:#fff; border-color:#16a34a; }
    #section-billing_encounters .be-icon-btn.is-cancel:hover { background:#f59e0b; color:#fff; border-color:#f59e0b; }
    #section-billing_encounters .be-icon-btn.is-delete:hover { background:#ef4444; color:#fff; border-color:#ef4444; }

    #section-billing_encounters .be-empty {
        text-align: center;
        padding: 60px 20px;
        color: #94a3b8;
    }
    #section-billing_encounters .be-empty i {
        font-size: 48px;
        margin-bottom: 12px;
        opacity: 0.5;
        display: block;
    }

    #section-billing_encounters .be-pager {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 14px 0 4px;
        font-size: 13px;
        color: #64748b;
    }
    #section-billing_encounters .be-pager-btns { display: flex; gap: 4px; }
    #section-billing_encounters .be-pager-btn {
        min-width: 32px; height: 32px; padding: 0 8px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff; font-weight: 700; color: #475569;
        cursor: pointer;
    }
    #section-billing_encounters .be-pager-btn:hover:not(:disabled) { background: #f1f5f9; }
    #section-billing_encounters .be-pager-btn.is-active { background: #2e9e63; color: #fff; border-color: #2e9e63; }
    #section-billing_encounters .be-pager-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    /* ── Modal — Portal-Escape pattern ──────────────────────────── */
    #beEncModal {
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
    #beEncModal.is-open { display: flex; }
    #beEncModal .be-modal-box {
        background: #fff;
        border-radius: 18px;
        width: 100%;
        max-width: 960px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 24px 64px rgba(15,23,42,.3);
    }
    body[data-theme='dark'] #beEncModal .be-modal-box {
        background: #1e293b;
        color: #e2e8f0;
    }

    /* Typeahead dropdown */
    .be-typeahead {
        position: relative;
    }
    .be-typeahead-list {
        position: absolute;
        top: 100%; left: 0; right: 0;
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        margin-top: 4px;
        max-height: 280px;
        overflow-y: auto;
        z-index: 100;
        box-shadow: 0 12px 28px rgba(15,23,42,.12);
        display: none;
    }
    body[data-theme='dark'] .be-typeahead-list {
        background: #1e293b;
        border-color: #334155;
    }
    .be-typeahead-list.is-open { display: block; }
    .be-typeahead-item {
        padding: 9px 14px;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    body[data-theme='dark'] .be-typeahead-item { border-color: #334155; }
    .be-typeahead-item:hover, .be-typeahead-item.is-hover { background: #f1f5f9; }
    body[data-theme='dark'] .be-typeahead-item:hover { background: #0f172a; }
    .be-typeahead-item .ta-meta { font-size: 11px; color: #94a3b8; }

    /* Line items */
    #beItemsTable {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    #beItemsTable th {
        background: #f8fafc;
        padding: 8px 10px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
        font-weight: 800;
        border-bottom: 1px solid #e2e8f0;
        text-align: left;
    }
    #beItemsTable td {
        padding: 6px 10px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    body[data-theme='dark'] #beItemsTable th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
    body[data-theme='dark'] #beItemsTable td { border-color:#334155; }
    #beItemsTable .item-qty, #beItemsTable .item-price, #beItemsTable .item-disc {
        width: 100%;
        padding: 6px 10px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        font-size: 13px;
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    body[data-theme='dark'] #beItemsTable .item-qty,
    body[data-theme='dark'] #beItemsTable .item-price,
    body[data-theme='dark'] #beItemsTable .item-disc {
        background: #0f172a; border-color: #334155; color: #e2e8f0;
    }
    #beItemsTable .item-select {
        width: 100%;
        padding: 6px 10px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        font-size: 13px;
    }
    body[data-theme='dark'] #beItemsTable .item-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    #beItemsTable .item-line-total {
        text-align: right;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    #beItemsTable .item-remove {
        width: 28px; height: 28px;
        border-radius: 6px;
        background: #fee2e2; color: #dc2626; border: none;
        cursor: pointer;
    }
    #beItemsTable .item-remove:hover { background: #dc2626; color: #fff; }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#2e9e63,#3bba7a);color:#fff">
                <i class="fa-solid fa-notes-medical"></i>
            </span>
            บันทึกการเข้ารับบริการ
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            สร้าง encounter เมื่อผู้ป่วยมาใช้บริการ → เลือกบริการที่ใช้ → ปิดงานเพื่อออกใบแจ้งหนี้
        </p>
    </div>
    <button type="button" class="ds-btn ds-btn-primary" onclick="beOpenModal()">
        <i class="fa-solid fa-plus mr-1"></i> Encounter ใหม่
    </button>
</div>

<!-- Filter bar -->
<div class="be-card mb-5 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[220px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค้นหา</label>
        <input id="beSearch" type="text" class="be-input" placeholder="ENC-..., ชื่อผู้ป่วย, รหัส นศ./บุคลากร">
    </div>
    <div class="min-w-[150px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">สถานะ</label>
        <select id="beFilterStatus" class="be-input">
            <option value="">— ทุกสถานะ —</option>
            <option value="draft">ฉบับร่าง</option>
            <option value="finalized">ปิดแล้ว (รอออกใบ)</option>
            <option value="invoiced">ออกใบแล้ว</option>
            <option value="cancelled">ยกเลิก</option>
        </select>
    </div>
    <div class="min-w-[160px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ตั้งแต่</label>
        <input id="beDateFrom" type="date" class="be-input">
    </div>
    <div class="min-w-[160px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ถึง</label>
        <input id="beDateTo" type="date" class="be-input">
    </div>
    <button type="button" class="ds-btn ds-btn-ghost" onclick="beResetFilters()">
        <i class="fa-solid fa-rotate-left mr-1"></i> ล้าง
    </button>
</div>

<!-- List -->
<div class="be-card">
    <div id="beTableWrap">
        <div class="be-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>
    </div>
    <div id="bePagerWrap"></div>
</div>

<!-- Encounter Modal -->
<div id="beEncModal">
    <div class="be-modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-700 sticky top-0 bg-white dark:bg-slate-800 z-10">
            <div>
                <h3 id="beModalTitle" class="text-lg font-black">Encounter ใหม่</h3>
                <p id="beModalSubtitle" class="text-xs text-slate-500 mt-0.5"></p>
            </div>
            <button type="button" onclick="beCloseModal()"
                    class="w-8 h-8 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center">
                <i class="fa-solid fa-xmark text-slate-500"></i>
            </button>
        </div>
        <form id="beEncForm" class="px-6 py-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($beCsrf) ?>">
            <input type="hidden" name="action" value="encounter:save">
            <input type="hidden" name="id" value="">

            <!-- Patient -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="be-typeahead">
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        ผู้ป่วย <span class="text-rose-500">*</span>
                    </label>
                    <input type="hidden" name="patient_id" id="bePatientId" value="">
                    <input type="text" id="bePatientSearch" class="be-input" autocomplete="off"
                           placeholder="พิมพ์ชื่อ / รหัส นศ. / เบอร์โทร / เลขบัตร...">
                    <div id="bePatientList" class="be-typeahead-list"></div>
                </div>

                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        วันที่เข้ารับบริการ <span class="text-rose-500">*</span>
                    </label>
                    <input type="date" name="visit_date" id="beVisitDate" class="be-input" required>
                </div>
            </div>

            <!-- Provider + diagnosis -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="be-typeahead">
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        ผู้ให้บริการ (แพทย์/พยาบาล)
                    </label>
                    <input type="hidden" name="provider_id" id="beProviderId" value="">
                    <input type="text" id="beProviderSearch" class="be-input" autocomplete="off"
                           placeholder="พิมพ์ชื่อ...">
                    <div id="beProviderList" class="be-typeahead-list"></div>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        การวินิจฉัย / อาการ
                    </label>
                    <input type="text" name="diagnosis" id="beDiagnosis" class="be-input" maxlength="500"
                           placeholder="เช่น ปวดหัว, ไข้หวัด">
                </div>
            </div>

            <!-- Line items -->
            <div>
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2 block">
                    บริการที่ใช้ <span class="text-rose-500">*</span>
                </label>
                <table id="beItemsTable">
                    <thead>
                        <tr>
                            <th style="width:35%">บริการ</th>
                            <th style="width:90px;text-align:right">จำนวน</th>
                            <th style="width:120px;text-align:right">ราคา/หน่วย</th>
                            <th style="width:110px;text-align:right">ส่วนลด</th>
                            <th style="width:130px;text-align:right">รวม</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="beItemsBody"></tbody>
                </table>
                <button type="button" id="beAddItemBtn"
                        class="mt-2 px-4 py-2 rounded-lg border-2 border-dashed border-emerald-300 text-emerald-700 text-sm font-bold hover:bg-emerald-50">
                    <i class="fa-solid fa-plus mr-1"></i> เพิ่มรายการบริการ
                </button>
            </div>

            <!-- Notes -->
            <div>
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">หมายเหตุ</label>
                <textarea name="notes" id="beNotes" class="be-input" rows="2" placeholder="(ถ้ามี)"></textarea>
            </div>

            <!-- Totals -->
            <div class="border-t border-slate-100 dark:border-slate-700 pt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ส่วนลดรวม</label>
                        <input type="number" name="discount" id="beDiscount" step="0.01" min="0" value="0" class="be-input">
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ภาษี (VAT)</label>
                        <input type="number" name="tax" id="beTax" step="0.01" min="0" value="0" class="be-input">
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-900/50 rounded-xl p-4 text-sm">
                    <div class="flex justify-between mb-1">
                        <span class="text-slate-500">ยอดรวมก่อนส่วนลด</span>
                        <span id="beSubtotalDisplay" class="font-bold tabular-nums">0.00</span>
                    </div>
                    <div class="flex justify-between mb-1 text-rose-600">
                        <span>− ส่วนลด</span>
                        <span id="beDiscountDisplay" class="font-bold tabular-nums">0.00</span>
                    </div>
                    <div class="flex justify-between mb-2 text-amber-600">
                        <span>+ ภาษี</span>
                        <span id="beTaxDisplay" class="font-bold tabular-nums">0.00</span>
                    </div>
                    <div class="flex justify-between pt-2 border-t border-slate-200 dark:border-slate-700 text-base">
                        <span class="font-black">ยอดสุทธิ</span>
                        <span id="beTotalDisplay" class="font-black tabular-nums text-emerald-600">0.00</span>
                    </div>
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex justify-between gap-2 pt-3 border-t border-slate-100 dark:border-slate-700 flex-wrap">
                <button type="button" onclick="beCloseModal()" class="ds-btn ds-btn-ghost">
                    <i class="fa-solid fa-xmark mr-1"></i> ปิด
                </button>
                <div class="flex gap-2 flex-wrap">
                    <button type="button" id="beSaveDraftBtn" class="ds-btn ds-btn-ghost"
                            style="background:#f1f5f9;color:#475569;border:1.5px solid #cbd5e1">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึกฉบับร่าง
                    </button>
                    <button type="button" id="beFinalizeBtn" class="ds-btn ds-btn-primary">
                        <i class="fa-solid fa-circle-check mr-1"></i> ปิดงาน · พร้อมออกใบแจ้งหนี้
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const AJAX_URL = 'ajax_billing.php';
    const CSRF     = <?= json_encode($beCsrf) ?>;
    const SERVICES = <?= json_encode($activeServices, JSON_UNESCAPED_UNICODE) ?>;
    const SERVICE_BY_ID = Object.fromEntries(SERVICES.map(s => [String(s.id), s]));
    const CATEGORIES = <?= json_encode(PB_SERVICE_CATEGORIES, JSON_UNESCAPED_UNICODE) ?>;

    let bePage = 1;
    let beSearchDebounce = null;

    function thBaht(n) {
        return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function thDate(d) {
        if (!d) return '—';
        const m = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const p = String(d).split('-');
        if (p.length !== 3) return d;
        return Number(p[2]) + ' ' + m[Number(p[1]) - 1] + ' ' + (Number(p[0]) + 543);
    }
    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
    function todayIso() {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' +
               String(d.getDate()).padStart(2,'0');
    }

    // ── List ─────────────────────────────────────────────────────
    function loadEncounters() {
        const params = new URLSearchParams({
            action:    'encounter:list',
            q:         document.getElementById('beSearch').value || '',
            status:    document.getElementById('beFilterStatus').value || '',
            date_from: document.getElementById('beDateFrom').value || '',
            date_to:   document.getElementById('beDateTo').value || '',
            page:      bePage,
            per_page:  20,
        });
        fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    document.getElementById('beTableWrap').innerHTML =
                        '<div class="be-empty"><i class="fa-solid fa-circle-exclamation"></i>' +
                        escapeHtml(d.message || 'โหลดไม่สำเร็จ') + '</div>';
                    return;
                }
                renderTable(d);
                renderPager(d);
            })
            .catch(() => {
                document.getElementById('beTableWrap').innerHTML =
                    '<div class="be-empty"><i class="fa-solid fa-wifi"></i> เชื่อมต่อไม่ได้</div>';
            });
    }

    function renderTable(d) {
        if (!d.rows || d.rows.length === 0) {
            document.getElementById('beTableWrap').innerHTML =
                '<div class="be-empty"><i class="fa-regular fa-folder-open"></i>' +
                'ยังไม่มี encounter ที่ตรงกับเงื่อนไข<br>' +
                '<small>กด "Encounter ใหม่" ที่มุมขวาบนเพื่อสร้าง</small></div>';
            return;
        }
        const statusLabels = {
            draft:'ฉบับร่าง', finalized:'ปิดแล้ว', invoiced:'ออกใบแล้ว', cancelled:'ยกเลิก'
        };

        let html = '<div style="overflow-x:auto"><table class="be-table"><thead><tr>' +
            '<th>เลขที่</th>' +
            '<th>วันที่</th>' +
            '<th>ผู้ป่วย</th>' +
            '<th>ผู้ให้บริการ</th>' +
            '<th style="text-align:right">ยอดสุทธิ</th>' +
            '<th style="text-align:center">สถานะ</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';

        d.rows.forEach(r => {
            const isDraft       = r.status === 'draft';
            const isFinalized   = r.status === 'finalized';
            const isFinalizable = isDraft;
            const isInvoiceable = isFinalized;  // gen invoice from finalized only
            const isCancellable = ['draft','finalized'].includes(r.status);
            const isDeletable   = isDraft;

            html += '<tr>' +
                '<td><code style="background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;color:#0f172a">' +
                    escapeHtml(r.encounter_no) + '</code></td>' +
                '<td>' + thDate(r.visit_date) + '</td>' +
                '<td>' +
                    '<div class="font-semibold">' + escapeHtml(r.patient_name || '—') + '</div>' +
                    (r.patient_code ? '<div class="text-xs text-slate-500">' + escapeHtml(r.patient_code) + '</div>' : '') +
                '</td>' +
                '<td class="text-sm">' + escapeHtml(r.provider_name || '—') + '</td>' +
                '<td style="text-align:right;font-weight:800;font-variant-numeric:tabular-nums">' +
                    thBaht(r.total) + ' ฿</td>' +
                '<td style="text-align:center"><span class="be-status be-status-' + r.status + '">' +
                    (statusLabels[r.status] || r.status) + '</span></td>' +
                '<td style="text-align:center;white-space:nowrap">' +
                    (isDraft
                        ? '<button class="be-icon-btn is-edit" onclick="beOpenModal(' + r.id + ')" title="แก้ไข"><i class="fa-solid fa-pen-to-square text-xs"></i></button> '
                        : '<button class="be-icon-btn is-edit" onclick="beOpenModal(' + r.id + ',true)" title="ดู"><i class="fa-solid fa-eye text-xs"></i></button> ') +
                    (isFinalizable
                        ? '<button class="be-icon-btn is-final" onclick="beFinalize(' + r.id + ')" title="ปิดงาน"><i class="fa-solid fa-circle-check text-xs"></i></button> '
                        : '') +
                    (isInvoiceable
                        ? '<button class="be-icon-btn is-final" style="background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe" onclick="beGenInvoice(' + r.id + ',\'' + escapeHtml(r.encounter_no) + '\',' + Number(r.total) + ')" title="ออกใบแจ้งหนี้"><i class="fa-solid fa-file-invoice-dollar text-xs"></i></button> '
                        : '') +
                    (r.status === 'invoiced'
                        ? '<a href="billing_invoices.php?focus_encounter=' + r.id + '" class="be-icon-btn is-edit" style="background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe;text-decoration:none" title="ไปดูใบแจ้งหนี้"><i class="fa-solid fa-file-invoice text-xs"></i></a> '
                        : '') +
                    (isCancellable
                        ? '<button class="be-icon-btn is-cancel" onclick="beCancel(' + r.id + ',\'' + escapeHtml(r.encounter_no) + '\')" title="ยกเลิก"><i class="fa-solid fa-ban text-xs"></i></button> '
                        : '') +
                    (isDeletable
                        ? '<button class="be-icon-btn is-delete" onclick="beDelete(' + r.id + ',\'' + escapeHtml(r.encounter_no) + '\')" title="ลบ"><i class="fa-solid fa-trash-can text-xs"></i></button>'
                        : '') +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('beTableWrap').innerHTML = html;
    }

    function renderPager(d) {
        if (!d.total || d.total === 0) {
            document.getElementById('bePagerWrap').innerHTML = '';
            return;
        }
        const cur = d.page, pages = d.pages, total = d.total;
        const btn = (label, target, opts) => {
            opts = opts || {};
            const cls = opts.active ? ' is-active' : '';
            const dis = opts.disabled ? ' disabled' : '';
            return '<button class="be-pager-btn' + cls + '"' + dis +
                ' onclick="window.beGoToPage(' + target + ')">' + label + '</button>';
        };
        let html = '<div class="be-pager">' +
            '<div>หน้า ' + cur + ' / ' + pages + ' · รวม ' + total.toLocaleString() + ' รายการ</div>' +
            '<div class="be-pager-btns">' +
            btn('«', 1, { disabled: cur === 1 }) +
            btn('‹', Math.max(1, cur - 1), { disabled: cur === 1 });
        const start = Math.max(1, cur - 2);
        const end   = Math.min(pages, cur + 2);
        for (let i = start; i <= end; i++) html += btn(i, i, { active: i === cur });
        html += btn('›', Math.min(pages, cur + 1), { disabled: cur === pages }) +
                btn('»', pages, { disabled: cur === pages }) +
                '</div></div>';
        document.getElementById('bePagerWrap').innerHTML = html;
    }

    window.beGoToPage = function (n) { bePage = n; loadEncounters(); };
    window.beResetFilters = function () {
        document.getElementById('beSearch').value = '';
        document.getElementById('beFilterStatus').value = '';
        document.getElementById('beDateFrom').value = '';
        document.getElementById('beDateTo').value = '';
        bePage = 1;
        loadEncounters();
    };

    // ── Typeahead helpers ────────────────────────────────────────
    function setupTypeahead(searchEl, listEl, hiddenEl, endpoint, renderItem, onSelect) {
        let debounce = null;
        searchEl.addEventListener('input', () => {
            const q = searchEl.value.trim();
            hiddenEl.value = '';  // clear selection if user types again
            clearTimeout(debounce);
            if (q === '') {
                listEl.classList.remove('is-open');
                return;
            }
            debounce = setTimeout(() => {
                fetch(AJAX_URL + '?action=' + endpoint + '&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(d => {
                        if (!d.ok || !d.rows || d.rows.length === 0) {
                            listEl.innerHTML = '<div class="be-typeahead-item" style="color:#94a3b8">ไม่พบผลลัพธ์</div>';
                            listEl.classList.add('is-open');
                            return;
                        }
                        listEl.innerHTML = d.rows.map(renderItem).join('');
                        listEl.classList.add('is-open');
                        listEl.querySelectorAll('[data-id]').forEach(el => {
                            el.addEventListener('click', () => {
                                onSelect(el.dataset);
                                listEl.classList.remove('is-open');
                            });
                        });
                    });
            }, 250);
        });
        // Click outside closes
        document.addEventListener('click', (e) => {
            if (!searchEl.contains(e.target) && !listEl.contains(e.target)) {
                listEl.classList.remove('is-open');
            }
        });
    }

    // ── Modal ────────────────────────────────────────────────────
    function teleportModal() {
        const m = document.getElementById('beEncModal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    function resetForm() {
        const f = document.getElementById('beEncForm');
        f.reset();
        f.querySelector('[name="id"]').value = '';
        f.querySelector('[name="action"]').value = 'encounter:save';
        document.getElementById('bePatientId').value = '';
        document.getElementById('beProviderId').value = '';
        document.getElementById('bePatientSearch').value = '';
        document.getElementById('beProviderSearch').value = '';
        document.getElementById('beVisitDate').value = todayIso();
        document.getElementById('beItemsBody').innerHTML = '';
        document.getElementById('beDiscount').value = 0;
        document.getElementById('beTax').value = 0;
        // Add one empty row for convenience
        addItemRow();
        recalcTotals();
        setFormEditable(true);
    }

    function setFormEditable(editable) {
        const f = document.getElementById('beEncForm');
        f.querySelectorAll('input, select, textarea, button').forEach(el => {
            if (el.type === 'button' && (el.id === 'beAddItemBtn' || el.id === 'beSaveDraftBtn' || el.id === 'beFinalizeBtn')) {
                el.style.display = editable ? '' : 'none';
            } else if (el.type !== 'hidden' && el.closest && !el.closest('.modal-header-area')) {
                el.disabled = !editable;
            }
        });
        // Items: also disable remove buttons
        document.querySelectorAll('#beItemsBody .item-remove').forEach(b => b.disabled = !editable);
    }

    window.beOpenModal = function (id, readonly) {
        teleportModal();
        resetForm();

        if (id) {
            // Load existing
            fetch(AJAX_URL + '?action=encounter:get&id=' + id)
                .then(r => r.json())
                .then(d => {
                    if (!d.ok || !d.row) {
                        Swal.fire({ icon:'error', title:'ไม่พบ encounter', text: d.message || '' });
                        return;
                    }
                    const r = d.row;
                    const f = document.getElementById('beEncForm');
                    f.querySelector('[name="id"]').value = r.id;
                    document.getElementById('beModalTitle').textContent =
                        (r.status === 'draft' ? 'แก้ไข Encounter · ' : 'ดู Encounter · ') + r.encounter_no;
                    document.getElementById('beModalSubtitle').textContent =
                        'สถานะ: ' + ({draft:'ฉบับร่าง',finalized:'ปิดแล้ว',invoiced:'ออกใบแจ้งหนี้แล้ว',cancelled:'ยกเลิก'}[r.status] || r.status);

                    document.getElementById('bePatientId').value = r.patient_id;
                    document.getElementById('bePatientSearch').value =
                        (r.patient_name || '') + (r.patient_code ? ' · ' + r.patient_code : '');
                    document.getElementById('beProviderId').value = r.provider_id || '';
                    document.getElementById('beProviderSearch').value = r.provider_name || '';
                    document.getElementById('beVisitDate').value = r.visit_date;
                    document.getElementById('beDiagnosis').value = r.diagnosis || '';
                    document.getElementById('beNotes').value = r.notes || '';
                    document.getElementById('beDiscount').value = r.discount || 0;
                    document.getElementById('beTax').value = r.tax || 0;

                    // Rebuild items
                    document.getElementById('beItemsBody').innerHTML = '';
                    (r.items || []).forEach(it => addItemRow(it));
                    if ((r.items || []).length === 0) addItemRow();
                    recalcTotals();

                    if (readonly || r.status !== 'draft') setFormEditable(false);
                });
        } else {
            document.getElementById('beModalTitle').textContent = 'Encounter ใหม่';
            document.getElementById('beModalSubtitle').textContent = 'จะถูกบันทึกเป็นฉบับร่าง สามารถแก้ไขก่อนปิดงาน';
        }

        document.getElementById('beEncModal').classList.add('is-open');
    };

    window.beCloseModal = function () {
        document.getElementById('beEncModal').classList.remove('is-open');
    };

    document.getElementById('beEncModal').addEventListener('click', function (e) {
        if (e.target === this) beCloseModal();
    });

    // ── Line items ───────────────────────────────────────────────
    function addItemRow(data) {
        data = data || {};
        const tbody = document.getElementById('beItemsBody');
        const tr = document.createElement('tr');
        tr.dataset.serviceId = data.service_id || '';

        // Service select with optgroup by category
        const opts = ['<option value="">— เลือกบริการ —</option>'];
        const grouped = {};
        SERVICES.forEach(s => {
            if (!grouped[s.category]) grouped[s.category] = [];
            grouped[s.category].push(s);
        });
        Object.entries(grouped).forEach(([cat, arr]) => {
            opts.push('<optgroup label="' + (CATEGORIES[cat] || cat) + '">');
            arr.forEach(s => {
                const sel = (String(s.id) === String(data.service_id || '')) ? ' selected' : '';
                opts.push('<option value="' + s.id + '"' + sel + '>' +
                    escapeHtml(s.code) + ' — ' + escapeHtml(s.name) + ' (' + thBaht(s.unit_price) + ' /' + escapeHtml(s.unit_label) + ')' +
                    '</option>');
            });
            opts.push('</optgroup>');
        });

        tr.innerHTML =
            '<td><select class="item-select item-service">' + opts.join('') + '</select></td>' +
            '<td><input type="number" class="item-qty" step="0.01" min="0.01" value="' +
                (data.quantity || 1) + '"></td>' +
            '<td><input type="number" class="item-price" step="0.01" min="0" value="' +
                (data.unit_price || 0) + '"></td>' +
            '<td><input type="number" class="item-disc" step="0.01" min="0" value="' +
                (data.discount || 0) + '"></td>' +
            '<td class="item-line-total">0.00</td>' +
            '<td style="text-align:center"><button type="button" class="item-remove" title="ลบ"><i class="fa-solid fa-xmark"></i></button></td>';

        // Bind events
        const sel = tr.querySelector('.item-service');
        sel.addEventListener('change', () => {
            const svc = SERVICE_BY_ID[sel.value];
            if (svc) {
                tr.dataset.serviceId = svc.id;
                tr.querySelector('.item-price').value = svc.unit_price;
            }
            recalcLine(tr);
        });
        tr.querySelectorAll('.item-qty, .item-price, .item-disc').forEach(inp => {
            inp.addEventListener('input', () => recalcLine(tr));
        });
        tr.querySelector('.item-remove').addEventListener('click', () => {
            tr.remove();
            recalcTotals();
        });

        tbody.appendChild(tr);
        recalcLine(tr);
    }

    function recalcLine(tr) {
        const qty   = parseFloat(tr.querySelector('.item-qty').value)   || 0;
        const price = parseFloat(tr.querySelector('.item-price').value) || 0;
        const disc  = parseFloat(tr.querySelector('.item-disc').value)  || 0;
        const total = Math.max(0, (qty * price) - disc);
        tr.querySelector('.item-line-total').textContent = thBaht(total);
        tr.dataset.lineTotal = total;
        recalcTotals();
    }

    function recalcTotals() {
        let subtotal = 0;
        document.querySelectorAll('#beItemsBody tr').forEach(tr => {
            subtotal += parseFloat(tr.dataset.lineTotal || 0);
        });
        const discount = parseFloat(document.getElementById('beDiscount').value) || 0;
        const tax      = parseFloat(document.getElementById('beTax').value)      || 0;
        const total = Math.max(0, subtotal - discount) + tax;

        document.getElementById('beSubtotalDisplay').textContent = thBaht(subtotal);
        document.getElementById('beDiscountDisplay').textContent = thBaht(discount);
        document.getElementById('beTaxDisplay').textContent      = thBaht(tax);
        document.getElementById('beTotalDisplay').textContent    = thBaht(total);
    }

    document.getElementById('beAddItemBtn').addEventListener('click', () => addItemRow());
    document.getElementById('beDiscount').addEventListener('input', recalcTotals);
    document.getElementById('beTax').addEventListener('input', recalcTotals);

    // ── Save ─────────────────────────────────────────────────────
    function collectItems() {
        const items = [];
        document.querySelectorAll('#beItemsBody tr').forEach(tr => {
            const sid = tr.querySelector('.item-service').value;
            if (!sid) return;
            items.push({
                service_id: Number(sid),
                quantity:   parseFloat(tr.querySelector('.item-qty').value)   || 1,
                unit_price: parseFloat(tr.querySelector('.item-price').value) || 0,
                discount:   parseFloat(tr.querySelector('.item-disc').value)  || 0,
            });
        });
        return items;
    }

    function saveEncounter(then) {
        const patientId = document.getElementById('bePatientId').value;
        if (!patientId) {
            Swal.fire({ icon:'warning', title:'ยังไม่ได้เลือกผู้ป่วย',
                        text:'พิมพ์ชื่อ/รหัส แล้วเลือกจากรายการที่ขึ้นมา' });
            return;
        }
        const items = collectItems();
        if (items.length === 0) {
            Swal.fire({ icon:'warning', title:'ยังไม่ได้เพิ่มบริการ',
                        text:'กด "เพิ่มรายการบริการ" และเลือกอย่างน้อย 1 รายการ' });
            return;
        }

        const fd = new FormData(document.getElementById('beEncForm'));
        fd.set('items', JSON.stringify(items));

        fetch(AJAX_URL, { method:'POST', body: fd, credentials:'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    if (then) then(d.id);
                    else {
                        Swal.fire({ icon:'success', title:'บันทึกแล้ว',
                                    timer: 1200, showConfirmButton: false });
                        beCloseModal();
                        loadEncounters();
                    }
                } else {
                    Swal.fire({ icon:'error', title:'บันทึกไม่ได้', text: d.message || '' });
                }
            });
    }

    document.getElementById('beSaveDraftBtn').addEventListener('click', () => saveEncounter());

    document.getElementById('beFinalizeBtn').addEventListener('click', async () => {
        const { isConfirmed } = await Swal.fire({
            icon: 'question',
            title: 'ปิดงาน encounter นี้?',
            text: 'หลังปิดงานจะแก้ไขไม่ได้ พร้อมออกใบแจ้งหนี้ในขั้นถัดไป',
            showCancelButton: true,
            confirmButtonText: 'ปิดงาน',
            cancelButtonText:  'ยกเลิก',
            confirmButtonColor: '#16a34a',
        });
        if (!isConfirmed) return;

        // First save, then finalize
        saveEncounter((id) => {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'encounter:finalize');
            fd.append('id', id);
            fetch(AJAX_URL, { method:'POST', body: fd, credentials:'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        Swal.fire({ icon:'success', title:'ปิดงานแล้ว · พร้อมออกใบแจ้งหนี้',
                                    timer: 1500, showConfirmButton: false });
                        beCloseModal();
                        loadEncounters();
                    } else {
                        Swal.fire({ icon:'error', title:'ปิดงานไม่ได้', text: d.message || '' });
                    }
                });
        });
    });

    // ── Other actions ────────────────────────────────────────────
    window.beFinalize = async function (id) {
        const { isConfirmed } = await Swal.fire({
            icon:'question', title:'ปิดงาน encounter?', text:'หลังปิดจะแก้ไขไม่ได้',
            showCancelButton:true, confirmButtonText:'ปิดงาน', cancelButtonText:'ยกเลิก',
            confirmButtonColor: '#16a34a',
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'encounter:finalize');
        fd.append('id', id);
        fetch(AJAX_URL, { method:'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({ icon:'success', title: d.message || 'ปิดงานแล้ว',
                                timer: 1300, showConfirmButton: false });
                    loadEncounters();
                } else {
                    Swal.fire({ icon:'error', title:'ปิดงานไม่ได้', text: d.message || '' });
                }
            });
    };

    window.beCancel = async function (id, no) {
        const { isConfirmed } = await Swal.fire({
            icon:'warning', title:'ยกเลิก ' + no + '?',
            text:'รายการจะมีสถานะ "ยกเลิก" — ดูประวัติได้ แต่ออกใบไม่ได้',
            showCancelButton:true, confirmButtonText:'ยกเลิก encounter', cancelButtonText:'ปิด',
            confirmButtonColor: '#f59e0b',
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'encounter:cancel');
        fd.append('id', id);
        fetch(AJAX_URL, { method:'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) loadEncounters();
                else Swal.fire({ icon:'error', title:'ยกเลิกไม่ได้', text: d.message || '' });
            });
    };

    window.beGenInvoice = async function (id, no, total) {
        const todayPlus30 = new Date();
        todayPlus30.setDate(todayPlus30.getDate() + 30);
        const dueDefault = todayPlus30.toISOString().slice(0, 10);

        const { isConfirmed, value } = await Swal.fire({
            title: 'ออกใบแจ้งหนี้จาก ' + no,
            html:
                '<div style="text-align:left;font-size:14px;line-height:1.6">' +
                '<p>ยอด <b>' + thBaht(total) + ' ฿</b> · กำหนดประเภทผู้ชำระและวันครบกำหนด</p>' +
                '<label style="display:block;margin-top:10px;font-size:12px;font-weight:700;color:#475569">ผู้ชำระเงิน</label>' +
                '<select id="swPayerType" class="swal2-select" style="width:100%">' +
                '<option value="patient">ผู้ป่วยจ่ายเอง</option>' +
                '<option value="insurance">ประกัน</option>' +
                '<option value="gold_card">บัตรทอง</option>' +
                '<option value="other">อื่นๆ</option>' +
                '</select>' +
                '<label style="display:block;margin-top:10px;font-size:12px;font-weight:700;color:#475569">ครบกำหนด</label>' +
                '<input id="swDueDate" type="date" value="' + dueDefault + '" class="swal2-input" style="margin:0;width:100%">' +
                '</div>',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-file-invoice-dollar mr-1"></i> ออกใบแจ้งหนี้',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#1d4ed8',
            preConfirm: () => ({
                payer_type: document.getElementById('swPayerType').value,
                due_date:   document.getElementById('swDueDate').value,
            }),
        });
        if (!isConfirmed) return;

        const fd = new FormData();
        fd.append('csrf_token',   CSRF);
        fd.append('action',       'invoice:create_from_encounter');
        fd.append('encounter_id', id);
        fd.append('payer_type',   value.payer_type);
        fd.append('due_date',     value.due_date);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({
                        icon: 'success',
                        title: d.message,
                        html: '<a href="billing_invoices.php?focus_invoice=' + d.invoice_id +
                              '" class="text-blue-600 font-bold">→ ไปดูใบแจ้งหนี้</a>',
                        confirmButtonText: 'ปิด',
                    });
                    loadEncounters();
                } else {
                    Swal.fire({ icon: 'error', title: 'ออกใบแจ้งหนี้ไม่ได้', text: d.message || '' });
                }
            });
    };

    window.beDelete = async function (id, no) {
        const { isConfirmed } = await Swal.fire({
            icon:'warning', title:'ลบฉบับร่าง ' + no + '?',
            text:'ฉบับร่างจะถูกลบถาวร · ลบได้เฉพาะสถานะ draft เท่านั้น',
            showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
            confirmButtonColor: '#ef4444',
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'encounter:delete');
        fd.append('id', id);
        fetch(AJAX_URL, { method:'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) loadEncounters();
                else Swal.fire({ icon:'error', title:'ลบไม่ได้', text: d.message || '' });
            });
    };

    // ── Typeaheads ───────────────────────────────────────────────
    setupTypeahead(
        document.getElementById('bePatientSearch'),
        document.getElementById('bePatientList'),
        document.getElementById('bePatientId'),
        'lookup:patient',
        (r) => {
            const meta = [
                r.student_personnel_id ? 'รหัส ' + escapeHtml(r.student_personnel_id) : null,
                r.phone_number         ? '📞 ' + escapeHtml(r.phone_number)            : null,
                r.status               ? escapeHtml(r.status)                          : null,
            ].filter(Boolean).join(' · ');
            return '<div class="be-typeahead-item" data-id="' + r.id + '" data-name="' +
                escapeHtml(r.full_name || '') + '" data-code="' + escapeHtml(r.student_personnel_id || '') + '">' +
                '<div>' + escapeHtml(r.full_name || '(ไม่มีชื่อ)') + '</div>' +
                (meta ? '<div class="ta-meta">' + meta + '</div>' : '') +
                '</div>';
        },
        (data) => {
            document.getElementById('bePatientId').value = data.id;
            document.getElementById('bePatientSearch').value =
                data.name + (data.code ? ' · ' + data.code : '');
        }
    );

    setupTypeahead(
        document.getElementById('beProviderSearch'),
        document.getElementById('beProviderList'),
        document.getElementById('beProviderId'),
        'lookup:provider',
        (r) => '<div class="be-typeahead-item" data-id="' + r.id +
            '" data-name="' + escapeHtml(r.full_name || '') + '">' +
            '<div>' + escapeHtml(r.full_name || '—') + '</div>' +
            (r.title ? '<div class="ta-meta">' + escapeHtml(r.title) + '</div>' : '') +
            '</div>',
        (data) => {
            document.getElementById('beProviderId').value = data.id;
            document.getElementById('beProviderSearch').value = data.name;
        }
    );

    // ── Wire filters ─────────────────────────────────────────────
    document.getElementById('beSearch').addEventListener('input', () => {
        clearTimeout(beSearchDebounce);
        beSearchDebounce = setTimeout(() => { bePage = 1; loadEncounters(); }, 350);
    });
    ['beFilterStatus', 'beDateFrom', 'beDateTo'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            bePage = 1; loadEncounters();
        });
    });

    // ESC closes modal
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' &&
            document.getElementById('beEncModal').classList.contains('is-open')) {
            beCloseModal();
        }
    });

    // Initial load
    loadEncounters();
})();
</script>
