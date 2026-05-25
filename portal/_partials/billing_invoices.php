<?php
/**
 * portal/_partials/billing_invoices.php
 * Patient Billing — Invoice list + payment recording
 *
 * Backend: portal/ajax_billing.php (entity=invoice, payment)
 * Auto-syncs payments → Cash Book via finance_sync_helper
 *   (source_module='billing_payment')
 *
 * URL params (optional):
 *   ?focus_invoice=N  → auto-open invoice detail modal on load
 *   ?focus_encounter=N → filter list to that encounter
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/patient_billing_helper.php';

$_pdo = db();
pb_ensure_schema($_pdo);

$biCsrf       = get_csrf_token();
$focusInvoice = (int)($_GET['focus_invoice']   ?? 0);
$focusEnc     = (int)($_GET['focus_encounter'] ?? 0);
?>

<style>
    #section-billing_invoices .bi-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
        padding: 18px 20px;
    }
    body[data-theme='dark'] #section-billing_invoices .bi-card {
        background: #1e293b; border-color: #334155;
    }

    #section-billing_invoices .bi-input,
    #biInvoiceModal .bi-input {
        width: 100%; padding: 9px 12px; border-radius: 10px;
        border: 1.5px solid #e2e8f0; background: #f8fafc;
        font-size: 14px; font-weight: 500; outline: none;
        transition: all .15s ease;
    }
    #section-billing_invoices .bi-input:focus,
    #biInvoiceModal .bi-input:focus {
        background: #fff; border-color: #2e9e63;
        box-shadow: 0 0 0 3px rgba(46,158,99,.12);
    }
    body[data-theme='dark'] #section-billing_invoices .bi-input,
    body[data-theme='dark'] #biInvoiceModal .bi-input {
        background: #0f172a; border-color: #334155; color: #e2e8f0;
    }

    #section-billing_invoices .bi-status {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
    }
    #section-billing_invoices .bi-status-draft          { background:#f1f5f9; color:#64748b; }
    #section-billing_invoices .bi-status-issued         { background:#dbeafe; color:#1d4ed8; }
    #section-billing_invoices .bi-status-partially_paid { background:#fef3c7; color:#92400e; }
    #section-billing_invoices .bi-status-paid           { background:#dcfce7; color:#15803d; }
    #section-billing_invoices .bi-status-overdue        { background:#fee2e2; color:#b91c1c; }
    #section-billing_invoices .bi-status-void           { background:#f1f5f9; color:#94a3b8; text-decoration: line-through; }

    #section-billing_invoices .bi-table {
        width: 100%; border-collapse: collapse; font-size: 14px;
    }
    #section-billing_invoices .bi-table th {
        background: #f8fafc; padding: 11px 14px; text-align: left;
        font-weight: 800; color: #475569; font-size: 11px;
        text-transform: uppercase; letter-spacing: 0.04em;
        border-bottom: 2px solid #e2e8f0;
    }
    #section-billing_invoices .bi-table td {
        padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
    }
    body[data-theme='dark'] #section-billing_invoices .bi-table th {
        background: #0f172a; color: #cbd5e1; border-color: #334155;
    }
    body[data-theme='dark'] #section-billing_invoices .bi-table td {
        border-color: #334155; color: #e2e8f0;
    }

    #section-billing_invoices .bi-icon-btn {
        width: 30px; height: 30px; border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 1.5px solid #e2e8f0; background: #fff; color: #64748b;
        cursor: pointer; transition: all .15s ease;
    }
    #section-billing_invoices .bi-icon-btn:hover {
        transform: translateY(-1px); box-shadow: 0 4px 10px rgba(15,23,42,.08);
    }
    #section-billing_invoices .bi-icon-btn.is-view:hover    { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    #section-billing_invoices .bi-icon-btn.is-pay:hover     { background:#16a34a; color:#fff; border-color:#16a34a; }
    #section-billing_invoices .bi-icon-btn.is-void:hover    { background:#dc2626; color:#fff; border-color:#dc2626; }

    #section-billing_invoices .bi-empty {
        text-align: center; padding: 60px 20px; color: #94a3b8;
    }
    #section-billing_invoices .bi-empty i {
        font-size: 48px; margin-bottom: 12px; opacity: 0.5; display: block;
    }

    #section-billing_invoices .bi-pager {
        display: flex; justify-content: space-between; align-items: center;
        gap: 8px; padding: 14px 0 4px;
        font-size: 13px; color: #64748b;
    }
    #section-billing_invoices .bi-pager-btns { display: flex; gap: 4px; }
    #section-billing_invoices .bi-pager-btn {
        min-width: 32px; height: 32px; padding: 0 8px;
        border-radius: 8px; border: 1px solid #e2e8f0; background: #fff;
        font-weight: 700; color: #475569; cursor: pointer;
    }
    #section-billing_invoices .bi-pager-btn:hover:not(:disabled) { background: #f1f5f9; }
    #section-billing_invoices .bi-pager-btn.is-active {
        background: #2e9e63; color: #fff; border-color: #2e9e63;
    }
    #section-billing_invoices .bi-pager-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    /* ── Modal — Portal-Escape pattern ──────────────────────────── */
    #biInvoiceModal {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.55) !important;
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        z-index: 9000 !important;
        display: none; align-items: center; justify-content: center;
        padding: 16px;
    }
    #biInvoiceModal.is-open { display: flex; }
    #biInvoiceModal .bi-modal-box {
        background: #fff; border-radius: 18px;
        width: 100%; max-width: 820px; max-height: 92vh;
        overflow-y: auto;
        box-shadow: 0 24px 64px rgba(15,23,42,.3);
    }
    body[data-theme='dark'] #biInvoiceModal .bi-modal-box {
        background: #1e293b; color: #e2e8f0;
    }

    /* Receipt-style header inside modal */
    .bi-receipt-head {
        background: linear-gradient(135deg, #2e9e63 0%, #3bba7a 100%);
        color: #fff; padding: 18px 22px;
        border-radius: 12px 12px 0 0;
        position: sticky; top: 0; z-index: 2;
    }
    .bi-receipt-num {
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 14px; font-weight: 700;
        background: rgba(255,255,255,.2);
        padding: 4px 10px; border-radius: 6px;
        display: inline-block;
    }

    .bi-items-table {
        width: 100%; border-collapse: collapse; font-size: 13px;
    }
    .bi-items-table th {
        background: #f8fafc; padding: 8px 10px;
        font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;
        color: #64748b; font-weight: 800;
        border-bottom: 1px solid #e2e8f0; text-align: left;
    }
    .bi-items-table td {
        padding: 7px 10px; border-bottom: 1px solid #f1f5f9;
        vertical-align: top;
    }
    body[data-theme='dark'] .bi-items-table th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
    body[data-theme='dark'] .bi-items-table td { border-color:#334155; }

    .bi-pay-row {
        padding: 10px 12px;
        background: #f8fafc;
        border-left: 3px solid #16a34a;
        border-radius: 6px;
        margin-bottom: 6px;
        font-size: 13px;
    }
    body[data-theme='dark'] .bi-pay-row { background: #0f172a; }

    /* Print rules — when user clicks print, hide chrome and show clean invoice */
    @media print {
        body * { visibility: hidden; }
        .bi-printable, .bi-printable * { visibility: visible; }
        .bi-printable { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print, #section-billing_invoices .bi-card:not(.bi-printable),
        #ec-tour-fab, .portal-header, .portal-sidebar { display: none !important; }
    }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#2e9e63,#3bba7a);color:#fff">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </span>
            ใบแจ้งหนี้ &amp; รับชำระ
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            รายการใบแจ้งหนี้จาก encounter ที่ปิดแล้ว · รับชำระจะ sync เข้า Cash Book อัตโนมัติ
        </p>
    </div>
    <a href="billing_encounters.php" class="ds-btn ds-btn-ghost">
        <i class="fa-solid fa-notes-medical mr-1"></i> ไปสร้าง encounter
    </a>
</div>

<!-- Filter bar -->
<div class="bi-card mb-5 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[220px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค้นหา</label>
        <input id="biSearch" type="text" class="bi-input" placeholder="INV-..., ชื่อผู้ป่วย, รหัส">
    </div>
    <div class="min-w-[140px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">สถานะ</label>
        <select id="biFilterStatus" class="bi-input">
            <option value="">— ทุกสถานะ —</option>
            <option value="issued">ออกแล้ว (รอชำระ)</option>
            <option value="partially_paid">ชำระบางส่วน</option>
            <option value="paid">ชำระครบ</option>
            <option value="overdue">เลยกำหนด</option>
            <option value="void">ยกเลิก</option>
        </select>
    </div>
    <div class="min-w-[140px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ผู้ชำระ</label>
        <select id="biFilterPayer" class="bi-input">
            <option value="">— ทุกประเภท —</option>
            <option value="patient">ผู้ป่วยจ่ายเอง</option>
            <option value="insurance">ประกัน</option>
            <option value="gold_card">บัตรทอง</option>
            <option value="other">อื่นๆ</option>
        </select>
    </div>
    <div class="min-w-[150px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ตั้งแต่</label>
        <input id="biDateFrom" type="date" class="bi-input">
    </div>
    <div class="min-w-[150px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ถึง</label>
        <input id="biDateTo" type="date" class="bi-input">
    </div>
    <button type="button" class="ds-btn ds-btn-ghost" onclick="biResetFilters()">
        <i class="fa-solid fa-rotate-left mr-1"></i> ล้าง
    </button>
</div>

<!-- List -->
<div class="bi-card">
    <div id="biTableWrap">
        <div class="bi-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>
    </div>
    <div id="biPagerWrap"></div>
</div>

<!-- Invoice Detail Modal -->
<div id="biInvoiceModal">
    <div class="bi-modal-box bi-printable">
        <!-- Modal content rendered by JS -->
        <div id="biModalBody"></div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const AJAX_URL = 'ajax_billing.php';
    const CSRF     = <?= json_encode($biCsrf) ?>;
    const FOCUS_INVOICE   = <?= (int)$focusInvoice ?>;
    const FOCUS_ENCOUNTER = <?= (int)$focusEnc ?>;

    const STATUS_LABELS = {
        draft: 'ฉบับร่าง', issued: 'ออกแล้ว', partially_paid: 'ชำระบางส่วน',
        paid: 'ชำระครบ', overdue: 'เลยกำหนด', void: 'ยกเลิก',
    };
    const PAYER_LABELS = {
        patient: 'ผู้ป่วยจ่ายเอง', insurance: 'ประกัน',
        gold_card: 'บัตรทอง', other: 'อื่นๆ',
    };
    const METHOD_LABELS = {
        cash: 'เงินสด', transfer: 'โอน', card: 'บัตร', cheque: 'เช็ค', other: 'อื่นๆ',
    };

    let biPage = 1;
    let biSearchDebounce = null;

    function thBaht(n) {
        return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function thDate(d) {
        if (!d) return '—';
        const m = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const p = String(d).split(' ')[0].split('-');
        if (p.length !== 3) return d;
        return Number(p[2]) + ' ' + m[Number(p[1]) - 1] + ' ' + (Number(p[0]) + 543);
    }
    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function loadInvoices() {
        const params = new URLSearchParams({
            action:     'invoice:list',
            q:          document.getElementById('biSearch').value || '',
            status:     document.getElementById('biFilterStatus').value || '',
            payer_type: document.getElementById('biFilterPayer').value || '',
            date_from:  document.getElementById('biDateFrom').value || '',
            date_to:    document.getElementById('biDateTo').value || '',
            page:       biPage,
            per_page:   20,
        });
        fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    document.getElementById('biTableWrap').innerHTML =
                        '<div class="bi-empty"><i class="fa-solid fa-circle-exclamation"></i>' +
                        escapeHtml(d.message || 'โหลดไม่สำเร็จ') + '</div>';
                    return;
                }
                renderTable(d);
                renderPager(d);
            })
            .catch(() => {
                document.getElementById('biTableWrap').innerHTML =
                    '<div class="bi-empty"><i class="fa-solid fa-wifi"></i> เชื่อมต่อไม่ได้</div>';
            });
    }

    function renderTable(d) {
        if (!d.rows || d.rows.length === 0) {
            document.getElementById('biTableWrap').innerHTML =
                '<div class="bi-empty"><i class="fa-regular fa-folder-open"></i>' +
                'ยังไม่มีใบแจ้งหนี้<br>' +
                '<small>ออกใบแจ้งหนี้จาก encounter ที่สถานะ "ปิดแล้ว"</small></div>';
            return;
        }

        let html = '<div style="overflow-x:auto"><table class="bi-table"><thead><tr>' +
            '<th>เลขที่</th>' +
            '<th>วันที่ออก</th>' +
            '<th>ผู้ป่วย</th>' +
            '<th>ผู้ชำระ</th>' +
            '<th style="text-align:right">ยอด</th>' +
            '<th style="text-align:right">ชำระแล้ว</th>' +
            '<th style="text-align:right">คงค้าง</th>' +
            '<th style="text-align:center">สถานะ</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';

        d.rows.forEach(r => {
            const balance = parseFloat(r.balance) || 0;
            const isPayable = ['issued', 'partially_paid', 'overdue'].includes(r.status) && balance > 0.005;

            html += '<tr>' +
                '<td><code style="background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;color:#0f172a">' +
                    escapeHtml(r.invoice_no) + '</code></td>' +
                '<td>' + thDate(r.issue_date) + '</td>' +
                '<td>' +
                    '<div class="font-semibold">' + escapeHtml(r.patient_name || '—') + '</div>' +
                    (r.patient_code ? '<div class="text-xs text-slate-500">' + escapeHtml(r.patient_code) + '</div>' : '') +
                '</td>' +
                '<td class="text-sm">' + (PAYER_LABELS[r.payer_type] || r.payer_type) + '</td>' +
                '<td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums">' + thBaht(r.total) + '</td>' +
                '<td style="text-align:right;color:#15803d;font-weight:700;font-variant-numeric:tabular-nums">' + thBaht(r.paid_amount) + '</td>' +
                '<td style="text-align:right;font-weight:800;font-variant-numeric:tabular-nums;color:' +
                    (balance > 0.005 ? '#b91c1c' : '#94a3b8') + '">' + thBaht(balance) + '</td>' +
                '<td style="text-align:center"><span class="bi-status bi-status-' + r.status + '">' +
                    (STATUS_LABELS[r.status] || r.status) + '</span></td>' +
                '<td style="text-align:center;white-space:nowrap">' +
                    '<button class="bi-icon-btn is-view" onclick="biOpenModal(' + r.id + ')" title="ดู / รับชำระ"><i class="fa-solid fa-eye text-xs"></i></button> ' +
                    (isPayable
                        ? '<button class="bi-icon-btn is-pay" onclick="biOpenModal(' + r.id + ',true)" title="รับชำระ"><i class="fa-solid fa-cash-register text-xs"></i></button> '
                        : '') +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('biTableWrap').innerHTML = html;
    }

    function renderPager(d) {
        if (!d.total || d.total === 0) {
            document.getElementById('biPagerWrap').innerHTML = '';
            return;
        }
        const cur = d.page, pages = d.pages, total = d.total;
        const btn = (label, target, opts) => {
            opts = opts || {};
            const cls = opts.active ? ' is-active' : '';
            const dis = opts.disabled ? ' disabled' : '';
            return '<button class="bi-pager-btn' + cls + '"' + dis +
                ' onclick="window.biGoToPage(' + target + ')">' + label + '</button>';
        };
        let html = '<div class="bi-pager">' +
            '<div>หน้า ' + cur + ' / ' + pages + ' · รวม ' + total.toLocaleString() + ' รายการ</div>' +
            '<div class="bi-pager-btns">' +
            btn('«', 1, { disabled: cur === 1 }) +
            btn('‹', Math.max(1, cur - 1), { disabled: cur === 1 });
        const start = Math.max(1, cur - 2);
        const end   = Math.min(pages, cur + 2);
        for (let i = start; i <= end; i++) html += btn(i, i, { active: i === cur });
        html += btn('›', Math.min(pages, cur + 1), { disabled: cur === pages }) +
                btn('»', pages, { disabled: cur === pages }) +
                '</div></div>';
        document.getElementById('biPagerWrap').innerHTML = html;
    }

    window.biGoToPage = function (n) { biPage = n; loadInvoices(); };
    window.biResetFilters = function () {
        document.getElementById('biSearch').value = '';
        document.getElementById('biFilterStatus').value = '';
        document.getElementById('biFilterPayer').value = '';
        document.getElementById('biDateFrom').value = '';
        document.getElementById('biDateTo').value = '';
        biPage = 1;
        loadInvoices();
    };

    // ── Detail modal ─────────────────────────────────────────────
    function teleportModal() {
        const m = document.getElementById('biInvoiceModal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    window.biOpenModal = function (id, focusPay) {
        teleportModal();
        const body = document.getElementById('biModalBody');
        body.innerHTML = '<div style="padding:60px;text-align:center;color:#94a3b8">' +
            '<i class="fa-solid fa-spinner fa-spin text-3xl"></i><br>กำลังโหลด...</div>';
        document.getElementById('biInvoiceModal').classList.add('is-open');

        fetch(AJAX_URL + '?action=invoice:get&id=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.ok || !d.row) {
                    body.innerHTML = '<div style="padding:60px;text-align:center;color:#dc2626">' +
                        '<i class="fa-solid fa-circle-exclamation text-3xl"></i><br>' +
                        escapeHtml(d.message || 'ไม่พบใบแจ้งหนี้') + '</div>';
                    return;
                }
                renderInvoiceDetail(d.row, focusPay);
            });
    };

    window.biCloseModal = function () {
        document.getElementById('biInvoiceModal').classList.remove('is-open');
    };

    document.getElementById('biInvoiceModal').addEventListener('click', function (e) {
        if (e.target === this) biCloseModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' &&
            document.getElementById('biInvoiceModal').classList.contains('is-open')) {
            biCloseModal();
        }
    });

    function renderInvoiceDetail(r, focusPay) {
        const balance = parseFloat(r.total) - parseFloat(r.paid_amount);
        const isPayable = ['issued','partially_paid','overdue'].includes(r.status) && balance > 0.005;
        const isVoidable = ['issued','partially_paid','overdue'].includes(r.status) && (r.payments || []).length === 0;

        const itemsHtml = (r.items || []).length === 0
            ? '<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px">ไม่มีรายการบริการ</td></tr>'
            : r.items.map(it => '<tr>' +
                '<td><code style="font-size:11px;color:#64748b">' + escapeHtml(it.service_code || '') + '</code> ' +
                    escapeHtml(it.service_name || '') +
                    (it.note ? '<br><span class="text-xs text-slate-500">' + escapeHtml(it.note) + '</span>' : '') +
                '</td>' +
                '<td style="text-align:right">' + Number(it.quantity).toLocaleString() + '</td>' +
                '<td style="text-align:right;font-variant-numeric:tabular-nums">' + thBaht(it.unit_price) + '</td>' +
                '<td style="text-align:right;font-variant-numeric:tabular-nums;color:#dc2626">' +
                    (parseFloat(it.discount) > 0 ? '−' + thBaht(it.discount) : '—') + '</td>' +
                '<td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums">' + thBaht(it.line_total) + '</td>' +
                '</tr>').join('');

        const paysHtml = (r.payments || []).length === 0
            ? '<p style="color:#94a3b8;font-style:italic;font-size:13px;text-align:center;padding:12px">ยังไม่มีการชำระเงิน</p>'
            : (r.payments || []).map(p => '<div class="bi-pay-row">' +
                '<div class="flex justify-between items-start">' +
                    '<div>' +
                        '<div class="font-bold text-slate-800 dark:text-slate-100">' +
                            thBaht(p.amount) + ' ฿ ' +
                            '<span class="text-xs font-medium text-slate-500 ml-1">' +
                                (METHOD_LABELS[p.method] || p.method) + '</span>' +
                        '</div>' +
                        '<div class="text-xs text-slate-500 mt-0.5">' +
                            'รับชำระ ' + thDate(p.payment_date) +
                            (p.reference ? ' · เลขอ้างอิง ' + escapeHtml(p.reference) : '') +
                        '</div>' +
                        (p.note ? '<div class="text-xs text-slate-500 mt-0.5">' + escapeHtml(p.note) + '</div>' : '') +
                    '</div>' +
                    '<div class="text-xs text-slate-400 font-mono">#' + p.id +
                        (p.finance_txn_id ? '<br>CB#' + p.finance_txn_id : '') +
                    '</div>' +
                '</div>' +
            '</div>').join('');

        const html =
            '<div class="bi-receipt-head">' +
                '<div class="flex justify-between items-start gap-3">' +
                    '<div>' +
                        '<div class="bi-receipt-num">' + escapeHtml(r.invoice_no) + '</div>' +
                        '<div class="text-xl font-black mt-2">' + escapeHtml(r.patient_name || '—') + '</div>' +
                        '<div class="text-sm opacity-90">' +
                            (r.patient_code ? 'รหัส ' + escapeHtml(r.patient_code) : '') +
                            (r.patient_phone ? ' · 📞 ' + escapeHtml(r.patient_phone) : '') +
                        '</div>' +
                    '</div>' +
                    '<button onclick="biCloseModal()" class="no-print"' +
                        ' style="background:rgba(255,255,255,.2);color:#fff;width:32px;height:32px;border-radius:8px;border:none;cursor:pointer">' +
                        '<i class="fa-solid fa-xmark"></i></button>' +
                '</div>' +
            '</div>' +

            '<div style="padding:20px 22px">' +
                '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mb-4">' +
                    '<div><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">วันที่ออก</div>' +
                        '<div class="font-semibold">' + thDate(r.issue_date) + '</div></div>' +
                    '<div><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">ครบกำหนด</div>' +
                        '<div class="font-semibold">' + thDate(r.due_date) + '</div></div>' +
                    '<div><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">ผู้ชำระ</div>' +
                        '<div class="font-semibold">' + (PAYER_LABELS[r.payer_type] || r.payer_type) + '</div></div>' +
                    '<div><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">สถานะ</div>' +
                        '<div><span class="bi-status bi-status-' + r.status + '">' +
                            (STATUS_LABELS[r.status] || r.status) + '</span></div></div>' +
                '</div>' +

                (r.encounter_no
                    ? '<div class="text-xs text-slate-500 mb-3">' +
                        '<i class="fa-solid fa-notes-medical mr-1"></i> จาก encounter <code>' +
                        escapeHtml(r.encounter_no) + '</code> · เข้ารับบริการ ' + thDate(r.visit_date) +
                        (r.provider_name ? ' · ผู้ให้บริการ ' + escapeHtml(r.provider_name) : '') +
                      '</div>'
                    : '') +

                // Line items
                '<table class="bi-items-table mb-3">' +
                    '<thead><tr>' +
                        '<th style="width:50%">บริการ</th>' +
                        '<th style="text-align:right;width:60px">จำนวน</th>' +
                        '<th style="text-align:right">ราคา/หน่วย</th>' +
                        '<th style="text-align:right;width:90px">ส่วนลด</th>' +
                        '<th style="text-align:right;width:110px">รวม</th>' +
                    '</tr></thead>' +
                    '<tbody>' + itemsHtml + '</tbody>' +
                '</table>' +

                // Totals box
                '<div class="bg-slate-50 dark:bg-slate-900/50 rounded-xl p-4 text-sm mb-4">' +
                    '<div class="flex justify-between mb-1"><span class="text-slate-500">รวมก่อนส่วนลด</span><span class="font-bold tabular-nums">' + thBaht(r.subtotal) + '</span></div>' +
                    (parseFloat(r.discount) > 0 ? '<div class="flex justify-between mb-1 text-rose-600"><span>− ส่วนลด</span><span class="font-bold tabular-nums">' + thBaht(r.discount) + '</span></div>' : '') +
                    (parseFloat(r.tax) > 0 ? '<div class="flex justify-between mb-1 text-amber-600"><span>+ ภาษี</span><span class="font-bold tabular-nums">' + thBaht(r.tax) + '</span></div>' : '') +
                    '<div class="flex justify-between pt-2 border-t border-slate-200 dark:border-slate-700 text-base"><span class="font-black">ยอดสุทธิ</span><span class="font-black tabular-nums">' + thBaht(r.total) + '</span></div>' +
                    '<div class="flex justify-between mt-1 text-emerald-600 text-sm"><span class="font-semibold">ชำระแล้ว</span><span class="font-bold tabular-nums">' + thBaht(r.paid_amount) + '</span></div>' +
                    '<div class="flex justify-between mt-0.5 ' + (balance > 0.005 ? 'text-rose-600' : 'text-slate-400') + ' text-sm"><span class="font-semibold">คงค้าง</span><span class="font-bold tabular-nums">' + thBaht(balance) + '</span></div>' +
                '</div>' +

                // Payments
                '<div class="mb-4">' +
                    '<div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">ประวัติการชำระเงิน</div>' +
                    paysHtml +
                '</div>' +

                // Payment form (if applicable)
                (isPayable
                    ? '<div class="border-t border-slate-100 dark:border-slate-700 pt-4 no-print">' +
                        '<div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">' +
                            '<i class="fa-solid fa-cash-register mr-1"></i> รับชำระเงิน' +
                        '</div>' +
                        '<form id="biPayForm" class="space-y-3">' +
                            '<input type="hidden" name="csrf_token" value="' + escapeHtml(CSRF) + '">' +
                            '<input type="hidden" name="action" value="payment:create">' +
                            '<input type="hidden" name="invoice_id" value="' + r.id + '">' +
                            '<div class="grid grid-cols-2 md:grid-cols-4 gap-3">' +
                                '<div><label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">จำนวนเงิน <span class="text-rose-500">*</span></label>' +
                                    '<input name="amount" type="number" step="0.01" min="0.01" max="' + balance.toFixed(2) + '" value="' + balance.toFixed(2) + '" class="bi-input" required></div>' +
                                '<div><label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">วิธีชำระ</label>' +
                                    '<select name="method" class="bi-input">' +
                                        Object.entries(METHOD_LABELS).map(([k,v]) => '<option value="' + k + '">' + v + '</option>').join('') +
                                    '</select></div>' +
                                '<div><label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">วันที่</label>' +
                                    '<input name="payment_date" type="date" value="' + new Date().toISOString().slice(0,10) + '" class="bi-input"></div>' +
                                '<div><label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">เลขอ้างอิง</label>' +
                                    '<input name="reference" type="text" class="bi-input" placeholder="(ถ้ามี)" maxlength="100"></div>' +
                            '</div>' +
                            '<div><label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">หมายเหตุ</label>' +
                                '<input name="note" type="text" class="bi-input" maxlength="500" placeholder="(ถ้ามี)"></div>' +
                            '<div class="flex justify-end gap-2">' +
                                '<button type="submit" class="ds-btn ds-btn-primary">' +
                                    '<i class="fa-solid fa-check mr-1"></i> บันทึก · เข้า Cash Book อัตโนมัติ' +
                                '</button>' +
                            '</div>' +
                        '</form>' +
                      '</div>'
                    : '') +

                // Actions
                '<div class="flex justify-between gap-2 pt-3 border-t border-slate-100 dark:border-slate-700 mt-3 no-print">' +
                    '<button onclick="biCloseModal()" class="ds-btn ds-btn-ghost">ปิด</button>' +
                    '<div class="flex gap-2 flex-wrap">' +
                        '<button onclick="window.print()" class="ds-btn ds-btn-ghost"' +
                            ' style="background:#f1f5f9;color:#475569;border:1.5px solid #cbd5e1">' +
                            '<i class="fa-solid fa-print mr-1"></i> พิมพ์' +
                        '</button>' +
                        (isVoidable
                            ? '<button onclick="biVoid(' + r.id + ',\'' + escapeHtml(r.invoice_no) + '\')" class="ds-btn ds-btn-ghost"' +
                                ' style="background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca">' +
                                '<i class="fa-solid fa-ban mr-1"></i> ยกเลิกใบ' +
                              '</button>'
                            : '') +
                    '</div>' +
                '</div>' +
            '</div>';

        document.getElementById('biModalBody').innerHTML = html;

        // Wire payment form submit
        const payForm = document.getElementById('biPayForm');
        if (payForm) {
            payForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const fd = new FormData(this);
                const btn = this.querySelector('button[type="submit"]');
                btn.disabled = true;
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> กำลังบันทึก...';

                fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(rsp => rsp.json())
                    .then(d => {
                        if (d.ok) {
                            Swal.fire({
                                icon: 'success', title: d.message || 'รับชำระแล้ว',
                                timer: 1600, showConfirmButton: false,
                            });
                            // Reload modal to show updated payments
                            biOpenModal(r.id);
                            loadInvoices();
                        } else {
                            Swal.fire({ icon: 'error', title: 'บันทึกไม่ได้', text: d.message || '' });
                            btn.disabled = false;
                            btn.innerHTML = orig;
                        }
                    })
                    .catch(() => {
                        Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่ได้' });
                        btn.disabled = false;
                        btn.innerHTML = orig;
                    });
            });

            // Auto-scroll to payment form if focusPay flag set
            if (focusPay) {
                setTimeout(() => {
                    payForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    payForm.querySelector('[name="amount"]').focus();
                }, 200);
            }
        }
    }

    window.biVoid = async function (id, no) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning',
            title: 'ยกเลิก ' + no + '?',
            text: 'ใบแจ้งหนี้จะเป็นสถานะ "ยกเลิก" — ทำเฉพาะใบที่ยังไม่มีการชำระเงินใดๆ',
            showCancelButton: true,
            confirmButtonText: 'ยกเลิกใบนี้',
            cancelButtonText: 'ปิด',
            confirmButtonColor: '#dc2626',
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'invoice:void');
        fd.append('id', id);
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({ icon: 'success', title: d.message,
                                timer: 1300, showConfirmButton: false });
                    biCloseModal();
                    loadInvoices();
                } else {
                    Swal.fire({ icon: 'error', title: 'ยกเลิกไม่ได้', text: d.message || '' });
                }
            });
    };

    // ── Wire filters ─────────────────────────────────────────────
    document.getElementById('biSearch').addEventListener('input', () => {
        clearTimeout(biSearchDebounce);
        biSearchDebounce = setTimeout(() => { biPage = 1; loadInvoices(); }, 350);
    });
    ['biFilterStatus','biFilterPayer','biDateFrom','biDateTo'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            biPage = 1; loadInvoices();
        });
    });

    // ── Initial load + focus param handling ──────────────────────
    loadInvoices();
    if (FOCUS_INVOICE > 0) {
        setTimeout(() => biOpenModal(FOCUS_INVOICE), 200);
    }
})();
</script>
