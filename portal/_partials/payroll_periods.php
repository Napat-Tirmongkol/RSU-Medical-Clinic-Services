<?php
/**
 * portal/_partials/payroll_periods.php
 * Payroll — periods + entries UI
 *
 * Backend: portal/ajax_payroll.php (entity=period, entry)
 *   - period:list / get / create / approve / unapprove / pay / cancel / delete
 *   - entry:update
 *   - report:pnd1_csv / sso_csv / bank_csv
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/payroll_helper.php';

$_pdo = db();
pr_ensure_schema($_pdo);

$ppCsrf = get_csrf_token();
?>

<style>
    #section-payroll_periods .pp-card {
        background:#fff; border-radius:16px; border:1px solid #e2e8f0;
        box-shadow:0 2px 8px rgba(15,23,42,.04); padding:18px 20px;
    }
    body[data-theme='dark'] #section-payroll_periods .pp-card { background:#1e293b; border-color:#334155; }

    #section-payroll_periods .pp-input,
    #ppModal .pp-input {
        padding:9px 12px; border-radius:10px;
        border:1.5px solid #e2e8f0; background:#f8fafc;
        font-size:14px; font-weight:500; outline:none;
    }
    body[data-theme='dark'] #section-payroll_periods .pp-input,
    body[data-theme='dark'] #ppModal .pp-input {
        background:#0f172a; border-color:#334155; color:#e2e8f0;
    }

    #section-payroll_periods .pp-status {
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700;
    }
    #section-payroll_periods .pp-status-draft     { background:#f1f5f9; color:#64748b; }
    #section-payroll_periods .pp-status-approved  { background:#dbeafe; color:#1d4ed8; }
    #section-payroll_periods .pp-status-paid      { background:#dcfce7; color:#15803d; }
    #section-payroll_periods .pp-status-cancelled { background:#fee2e2; color:#b91c1c; text-decoration:line-through; }

    #section-payroll_periods .pp-table { width:100%; border-collapse:collapse; font-size:14px; }
    #section-payroll_periods .pp-table th {
        background:#f8fafc; padding:11px 14px; text-align:left; font-weight:800;
        color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.04em;
        border-bottom:2px solid #e2e8f0;
    }
    #section-payroll_periods .pp-table td { padding:12px 14px; border-bottom:1px solid #f1f5f9; }
    body[data-theme='dark'] #section-payroll_periods .pp-table th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
    body[data-theme='dark'] #section-payroll_periods .pp-table td { border-color:#334155; color:#e2e8f0; }

    #section-payroll_periods .pp-icon-btn {
        width:30px; height:30px; border-radius:8px;
        display:inline-flex; align-items:center; justify-content:center;
        border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer;
    }
    #section-payroll_periods .pp-icon-btn:hover {
        transform:translateY(-1px); box-shadow:0 4px 10px rgba(15,23,42,.08);
    }
    #section-payroll_periods .pp-icon-btn.is-view:hover    { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    #section-payroll_periods .pp-icon-btn.is-approve:hover { background:#16a34a; color:#fff; border-color:#16a34a; }
    #section-payroll_periods .pp-icon-btn.is-pay:hover     { background:#0d9488; color:#fff; border-color:#0d9488; }
    #section-payroll_periods .pp-icon-btn.is-cancel:hover  { background:#f59e0b; color:#fff; border-color:#f59e0b; }
    #section-payroll_periods .pp-icon-btn.is-delete:hover  { background:#dc2626; color:#fff; border-color:#dc2626; }

    /* Modal */
    #ppModal {
        position:fixed; inset:0; background:rgba(15,23,42,.55) !important;
        backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
        z-index:9000 !important; display:none; align-items:center; justify-content:center; padding:16px;
    }
    #ppModal.is-open { display:flex; }
    #ppModal .pp-modal-box {
        background:#fff; border-radius:18px; width:100%; max-width:1200px;
        max-height:94vh; overflow-y:auto; box-shadow:0 24px 64px rgba(15,23,42,.3);
    }
    body[data-theme='dark'] #ppModal .pp-modal-box { background:#1e293b; color:#e2e8f0; }

    .pp-modal-head {
        background:linear-gradient(135deg, #2e9e63 0%, #3bba7a 100%);
        color:#fff; padding:18px 22px;
        border-radius:12px 12px 0 0;
        position:sticky; top:0; z-index:2;
    }

    /* Entries table */
    .pp-entries { width:100%; border-collapse:collapse; font-size:12px; }
    .pp-entries th {
        background:#f8fafc; padding:8px 8px; font-size:10px;
        text-transform:uppercase; letter-spacing:.03em; color:#64748b;
        font-weight:800; border-bottom:1px solid #e2e8f0; text-align:right;
        position:sticky; top:0; z-index:1;
    }
    .pp-entries th:nth-child(1), .pp-entries th:nth-child(2) { text-align:left; }
    .pp-entries td {
        padding:5px 8px; border-bottom:1px solid #f1f5f9;
        font-variant-numeric:tabular-nums; text-align:right;
    }
    .pp-entries td:nth-child(1), .pp-entries td:nth-child(2) { text-align:left; }
    body[data-theme='dark'] .pp-entries th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
    body[data-theme='dark'] .pp-entries td { border-color:#334155; color:#e2e8f0; }
    .pp-entries .editable {
        background:#fffbeb; border:1px solid #fde68a; border-radius:6px;
        padding:3px 6px; font-size:12px; width:75px;
        text-align:right; font-variant-numeric:tabular-nums;
    }
    body[data-theme='dark'] .pp-entries .editable {
        background:#451a03; border-color:#92400e; color:#fef3c7;
    }
    .pp-entries .net-col { font-weight:800; color:#15803d; }
    body[data-theme='dark'] .pp-entries .net-col { color:#34d399; }
    .pp-entries .tax-col { color:#b91c1c; }
    body[data-theme='dark'] .pp-entries .tax-col { color:#fca5a5; }

    .pp-totals-bar {
        background:linear-gradient(135deg,#ecfdf5,#dcfce7);
        border:1.5px solid #bbf7d0;
        border-radius:12px;
        padding:14px 18px;
        display:grid;
        grid-template-columns:repeat(3, 1fr);
        gap:16px;
    }
    body[data-theme='dark'] .pp-totals-bar {
        background:#064e3b; border-color:#047857;
    }
    .pp-totals-bar .label {
        font-size:11px; font-weight:700; color:#475569;
        text-transform:uppercase; letter-spacing:.05em;
    }
    body[data-theme='dark'] .pp-totals-bar .label { color:#a7f3d0; }
    .pp-totals-bar .value {
        font-size:20px; font-weight:900;
        font-variant-numeric:tabular-nums;
        margin-top:2px;
    }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#2e9e63,#3bba7a);color:#fff">
                <i class="fa-solid fa-calendar-days"></i>
            </span>
            งวดเงินเดือน
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            สร้าง · แก้ไข · อนุมัติ · จ่ายเงินเดือนรายเดือน · ส่งออก ภงด.1 / ประกันสังคม / Bank
        </p>
    </div>
    <button type="button" class="ds-btn ds-btn-primary" onclick="ppOpenCreate()">
        <i class="fa-solid fa-plus mr-1"></i> สร้างงวดใหม่
    </button>
</div>

<div class="pp-card">
    <div id="ppListWrap">
        <div style="padding:60px;text-align:center;color:#94a3b8">
            <i class="fa-solid fa-spinner fa-spin text-3xl"></i>
        </div>
    </div>
</div>

<!-- Period detail modal -->
<div id="ppModal">
    <div class="pp-modal-box">
        <div id="ppModalBody"></div>
    </div>
</div>

<script>
(function () {
    'use strict';
    const AJAX_URL = 'ajax_payroll.php';
    const CSRF     = <?= json_encode($ppCsrf) ?>;
    const STATUS_LABELS = {
        draft:'ฉบับร่าง', approved:'อนุมัติแล้ว', paid:'จ่ายแล้ว', cancelled:'ยกเลิก',
    };
    const TH_MONTHS = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

    function thBaht(n) { return Number(n||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function escapeHtml(s) {
        return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function thYM(ym) {
        if (!ym) return '—';
        const [y, m] = ym.split('-');
        return TH_MONTHS[Number(m)-1] + ' ' + (Number(y) + 543);
    }
    function thDate(d) {
        if (!d) return '—';
        const p = String(d).split(' ')[0].split('-');
        if (p.length !== 3) return d;
        return Number(p[2]) + ' ' + TH_MONTHS[Number(p[1])-1] + ' ' + (Number(p[0])+543);
    }

    // ── List ─────────────────────────────────────────────────────
    function loadList() {
        fetch(AJAX_URL + '?action=period:list')
            .then(r => r.json())
            .then(d => {
                if (!d.ok || !d.rows) {
                    document.getElementById('ppListWrap').innerHTML =
                        '<div style="padding:60px;text-align:center;color:#94a3b8">' +
                        '<i class="fa-solid fa-circle-exclamation text-3xl"></i><br>' + escapeHtml(d.message||'โหลดไม่สำเร็จ') + '</div>';
                    return;
                }
                renderList(d.rows);
            });
    }

    function renderList(rows) {
        if (rows.length === 0) {
            document.getElementById('ppListWrap').innerHTML =
                '<div style="padding:60px;text-align:center;color:#94a3b8">' +
                '<i class="fa-regular fa-folder-open text-3xl"></i><br>ยังไม่มีงวด · กด "สร้างงวดใหม่"</div>';
            return;
        }
        let html = '<div style="overflow-x:auto"><table class="pp-table"><thead><tr>' +
            '<th>งวด</th>' +
            '<th>จำนวนคน</th>' +
            '<th style="text-align:right">ยอดรวม (Gross)</th>' +
            '<th style="text-align:right">หัก ณ ที่จ่าย+SSO+...</th>' +
            '<th style="text-align:right">ยอดสุทธิ (Net)</th>' +
            '<th style="text-align:center">สถานะ</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';
        rows.forEach(r => {
            const isDraft = r.status === 'draft';
            const isApproved = r.status === 'approved';
            const isPaid = r.status === 'paid';
            const isCancelled = r.status === 'cancelled';

            html += '<tr>' +
                '<td><div class="font-bold">' + thYM(r.period_ym) + '</div>' +
                    (r.pay_date ? '<div class="text-xs text-slate-500">จ่าย ' + thDate(r.pay_date) + '</div>' : '') +
                '</td>' +
                '<td>' + (r.entry_count || 0) + ' คน</td>' +
                '<td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums">' + thBaht(r.total_gross) + '</td>' +
                '<td style="text-align:right;color:#b91c1c;font-variant-numeric:tabular-nums">' + thBaht(r.total_deductions) + '</td>' +
                '<td style="text-align:right;font-weight:800;color:#15803d;font-variant-numeric:tabular-nums">' + thBaht(r.total_net) + '</td>' +
                '<td style="text-align:center"><span class="pp-status pp-status-' + r.status + '">' + STATUS_LABELS[r.status] + '</span></td>' +
                '<td style="text-align:center;white-space:nowrap">' +
                    '<button class="pp-icon-btn is-view" onclick="ppOpenDetail(' + r.id + ')" title="ดู/แก้ไข"><i class="fa-solid fa-eye text-xs"></i></button> ' +
                    (isDraft
                        ? '<button class="pp-icon-btn is-approve" onclick="ppApprove(' + r.id + ')" title="อนุมัติ"><i class="fa-solid fa-circle-check text-xs"></i></button> '
                        : '') +
                    (isApproved
                        ? '<button class="pp-icon-btn is-pay" onclick="ppPay(' + r.id + ',\'' + thYM(r.period_ym) + '\')" title="จ่ายเงิน + Cash Book"><i class="fa-solid fa-money-bill-wave text-xs"></i></button> '
                        : '') +
                    ((isDraft || isApproved)
                        ? '<button class="pp-icon-btn is-cancel" onclick="ppCancel(' + r.id + ',\'' + thYM(r.period_ym) + '\')" title="ยกเลิกงวด"><i class="fa-solid fa-ban text-xs"></i></button> '
                        : '') +
                    ((isDraft || isCancelled)
                        ? '<button class="pp-icon-btn is-delete" onclick="ppDelete(' + r.id + ',\'' + thYM(r.period_ym) + '\')" title="ลบ"><i class="fa-solid fa-trash-can text-xs"></i></button>'
                        : '') +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('ppListWrap').innerHTML = html;
    }

    // ── Create period ────────────────────────────────────────────
    window.ppOpenCreate = async function() {
        const today = new Date();
        const defaultYm = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0');
        // Default pay date = last day of period month
        const lastDay = new Date(today.getFullYear(), today.getMonth()+1, 0).toISOString().slice(0,10);

        const { isConfirmed, value } = await Swal.fire({
            title: 'สร้างงวดเงินเดือนใหม่',
            html:
                '<div style="text-align:left;font-size:14px;line-height:1.6">' +
                'ระบบจะสร้างรายการให้พนักงานที่เปิดใช้งานทุกคนอัตโนมัติ (เงินเดือนพื้นฐาน + ค่าครองชีพ + คำนวณ ภงด.1 + SSO)' +
                '<label style="display:block;margin-top:10px;font-size:12px;font-weight:700;color:#475569">งวด (YYYY-MM)</label>' +
                '<input id="swYm" type="month" value="' + defaultYm + '" class="swal2-input" style="margin:0;width:100%">' +
                '<label style="display:block;margin-top:10px;font-size:12px;font-weight:700;color:#475569">วันที่จ่าย (ตั้งภายหลังได้)</label>' +
                '<input id="swPayDate" type="date" value="' + lastDay + '" class="swal2-input" style="margin:0;width:100%">' +
                '</div>',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-plus mr-1"></i> สร้าง',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2e9e63',
            preConfirm: () => ({
                ym: document.getElementById('swYm').value,
                pay_date: document.getElementById('swPayDate').value,
            }),
        });
        if (!isConfirmed) return;

        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'period:create');
        fd.append('period_ym', value.ym);
        fd.append('pay_date',  value.pay_date);

        fetch(AJAX_URL, {method:'POST', body:fd})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({icon:'success', title:d.message, timer:1600, showConfirmButton:false});
                    loadList();
                    setTimeout(() => ppOpenDetail(d.period_id), 800);
                } else {
                    Swal.fire({icon:'error', title:'สร้างไม่ได้', text:d.message||''});
                }
            });
    };

    // ── Detail modal ─────────────────────────────────────────────
    function teleportModal() {
        const m = document.getElementById('ppModal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }
    window.ppCloseModal = function() { document.getElementById('ppModal').classList.remove('is-open'); };
    document.getElementById('ppModal').addEventListener('click', function(e) {
        if (e.target === this) ppCloseModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && document.getElementById('ppModal').classList.contains('is-open')) ppCloseModal();
    });

    window.ppOpenDetail = function(id) {
        teleportModal();
        const body = document.getElementById('ppModalBody');
        body.innerHTML = '<div style="padding:60px;text-align:center;color:#94a3b8">' +
            '<i class="fa-solid fa-spinner fa-spin text-3xl"></i></div>';
        document.getElementById('ppModal').classList.add('is-open');

        fetch(AJAX_URL + '?action=period:get&id=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.ok || !d.row) {
                    body.innerHTML = '<div style="padding:60px;text-align:center;color:#dc2626">' +
                        '<i class="fa-solid fa-circle-exclamation text-3xl"></i><br>' + escapeHtml(d.message||'') + '</div>';
                    return;
                }
                renderDetail(d.row);
            });
    };

    function renderDetail(p) {
        const isDraft  = p.status === 'draft';
        const entries  = p.entries || [];
        const headerHtml =
            '<div class="pp-modal-head">' +
                '<div class="flex justify-between items-start gap-3">' +
                    '<div>' +
                        '<div class="text-xs font-bold uppercase tracking-wider opacity-80">งวด</div>' +
                        '<div class="text-2xl font-black">' + thYM(p.period_ym) + '</div>' +
                        '<div class="text-xs opacity-90 mt-1">' +
                            (p.pay_date ? 'จ่าย ' + thDate(p.pay_date) + ' · ' : '') +
                            'สถานะ <b>' + STATUS_LABELS[p.status] + '</b> · ' +
                            entries.length + ' คน' +
                        '</div>' +
                    '</div>' +
                    '<button onclick="ppCloseModal()"' +
                        ' style="background:rgba(255,255,255,.2);color:#fff;width:32px;height:32px;border-radius:8px;border:none;cursor:pointer">' +
                        '<i class="fa-solid fa-xmark"></i></button>' +
                '</div>' +
            '</div>';

        // Totals bar
        const totalsHtml =
            '<div class="pp-totals-bar" style="margin:20px 22px">' +
                '<div><div class="label">รวมรายได้ (Gross)</div><div class="value text-slate-800 dark:text-slate-100">' + thBaht(p.total_gross) + '</div></div>' +
                '<div><div class="label">หัก ณ ที่จ่าย + SSO + ...</div><div class="value text-rose-600">' + thBaht(p.total_deductions) + '</div></div>' +
                '<div><div class="label">ยอดสุทธิ (Net)</div><div class="value text-emerald-600">' + thBaht(p.total_net) + '</div></div>' +
            '</div>';

        // Entries table
        let rowsHtml = '';
        if (entries.length === 0) {
            rowsHtml = '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:30px">ไม่มีรายการ — สร้างพนักงานก่อนค่อยสร้างงวด</td></tr>';
        } else {
            entries.forEach(e => {
                rowsHtml +=
                    '<tr data-entry-id="' + e.id + '">' +
                        '<td><div class="font-semibold">' + escapeHtml(e.full_name||'—') + '</div>' +
                            (e.position_title ? '<div class="text-xs text-slate-500">' + escapeHtml(e.position_title) + '</div>' : '') +
                        '</td>' +
                        '<td>' + escapeHtml(e.employee_no || '—') + '</td>' +
                        '<td>' + thBaht(e.base_salary) + '</td>' +
                        '<td>' + thBaht(e.allowance) + '</td>' +
                        '<td>' + (isDraft
                            ? '<input class="editable" type="number" min="0" step="0.5" value="' + Number(e.ot_hours) + '" data-field="ot_hours">'
                            : Number(e.ot_hours)) +
                        '</td>' +
                        '<td>' + (isDraft
                            ? '<input class="editable" type="number" min="0" step="0.01" value="' + Number(e.bonus) + '" data-field="bonus">'
                            : thBaht(e.bonus)) +
                        '</td>' +
                        '<td>' + (isDraft
                            ? '<input class="editable" type="number" min="0" step="0.01" value="' + Number(e.other_deductions) + '" data-field="other_deductions">'
                            : thBaht(e.other_deductions)) +
                        '</td>' +
                        '<td>' + thBaht(e.gross_total) + '</td>' +
                        '<td class="tax-col">' + thBaht(e.tax_amount) + '</td>' +
                        '<td>' + thBaht(e.sso_employee) + '</td>' +
                        '<td class="net-col">' + thBaht(e.net_amount) + '</td>' +
                        (isDraft
                            ? '<td><button class="ds-btn ds-btn-ghost text-xs" style="padding:4px 8px" onclick="ppSaveRow(' + e.id + ')" title="บันทึก row"><i class="fa-solid fa-floppy-disk"></i></button></td>'
                            : '<td><a href="payroll_payslip.php?entry_id=' + e.id + '" target="_blank" class="ds-btn ds-btn-ghost text-xs" style="padding:4px 8px;text-decoration:none" title="ดู/พิมพ์ Payslip"><i class="fa-solid fa-receipt"></i></a></td>') +
                    '</tr>';
            });
        }

        const tableHtml =
            '<div style="margin:0 22px 16px;overflow-x:auto;max-height:50vh;overflow-y:auto">' +
            '<table class="pp-entries"><thead><tr>' +
                '<th>ชื่อ</th>' +
                '<th>รหัส</th>' +
                '<th>เงินเดือน</th>' +
                '<th>ค่าครองชีพ</th>' +
                '<th>OT (ชม.)</th>' +
                '<th>โบนัส</th>' +
                '<th>หักอื่นๆ</th>' +
                '<th>Gross</th>' +
                '<th>ภงด.1</th>' +
                '<th>SSO</th>' +
                '<th>Net</th>' +
                (isDraft ? '<th></th>' : '<th></th>') +
            '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div>';

        // Action buttons
        let actionsHtml = '<div style="padding:0 22px 22px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">' +
            '<div class="flex gap-2 flex-wrap">' +
                '<button onclick="ppExport(' + p.id + ',\'pnd1_csv\')" class="ds-btn ds-btn-ghost" style="background:#dbeafe;color:#1d4ed8;border:1.5px solid #bfdbfe">' +
                    '<i class="fa-solid fa-file-csv mr-1"></i> ภงด.1 CSV' +
                '</button>' +
                '<button onclick="ppExport(' + p.id + ',\'sso_csv\')" class="ds-btn ds-btn-ghost" style="background:#fef3c7;color:#92400e;border:1.5px solid #fde68a">' +
                    '<i class="fa-solid fa-file-csv mr-1"></i> ประกันสังคม CSV' +
                '</button>' +
                '<button onclick="ppExport(' + p.id + ',\'bank_csv\')" class="ds-btn ds-btn-ghost" style="background:#dcfce7;color:#15803d;border:1.5px solid #bbf7d0">' +
                    '<i class="fa-solid fa-file-csv mr-1"></i> โอนเงิน Bank CSV' +
                '</button>' +
            '</div>' +
            '<div class="flex gap-2 flex-wrap">' +
                '<button onclick="ppCloseModal()" class="ds-btn ds-btn-ghost">ปิด</button>' +
                (isDraft
                    ? '<button onclick="ppApprove(' + p.id + ')" class="ds-btn ds-btn-primary"><i class="fa-solid fa-circle-check mr-1"></i> อนุมัติงวด</button>'
                    : '') +
                (p.status === 'approved'
                    ? '<button onclick="ppUnapprove(' + p.id + ')" class="ds-btn ds-btn-ghost" style="background:#fef3c7;color:#92400e;border:1.5px solid #fde68a"><i class="fa-solid fa-rotate-left mr-1"></i> กลับเป็นฉบับร่าง</button>' +
                      '<button onclick="ppPay(' + p.id + ',\'' + thYM(p.period_ym) + '\')" class="ds-btn ds-btn-primary" style="background:linear-gradient(135deg,#0d9488,#14b8a6)"><i class="fa-solid fa-money-bill-wave mr-1"></i> จ่ายเงิน + เข้า Cash Book</button>'
                    : '') +
            '</div>' +
            '</div>';

        document.getElementById('ppModalBody').innerHTML = headerHtml + totalsHtml + tableHtml + actionsHtml;
    }

    // ── Save one entry row ───────────────────────────────────────
    window.ppSaveRow = function(entryId) {
        const tr = document.querySelector('#ppModal tr[data-entry-id="' + entryId + '"]');
        if (!tr) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'entry:update');
        fd.append('id', entryId);
        tr.querySelectorAll('input[data-field]').forEach(inp => {
            fd.append(inp.dataset.field, inp.value);
        });
        fetch(AJAX_URL, {method:'POST', body:fd})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({icon:'success', title:'บันทึก', timer:800, showConfirmButton:false});
                    // Refresh whole detail to get updated totals
                    const idMatch = document.querySelector('#ppModalBody').innerHTML.match(/period:get&id=(\d+)/);
                    const tds = tr.querySelectorAll('td');
                    // Update calc cells inline (simpler than re-rendering)
                    const c = d.calc;
                    tds[7].textContent  = thBaht(c.gross_total);  // Gross
                    tds[8].textContent  = thBaht(c.tax_amount);   // Tax
                    tds[9].textContent  = thBaht(c.sso_employee); // SSO
                    tds[10].textContent = thBaht(c.net_amount);   // Net
                    // Refresh modal to get totals
                    setTimeout(() => {
                        const periodMatch = window._ppCurrentPeriodId;
                        if (periodMatch) ppOpenDetail(periodMatch);
                    }, 600);
                } else {
                    Swal.fire({icon:'error', title:'บันทึกไม่ได้', text:d.message||''});
                }
            });
    };

    // Track which period the modal is showing — used by ppSaveRow refresh
    const origOpen = window.ppOpenDetail;
    window.ppOpenDetail = function(id) {
        window._ppCurrentPeriodId = id;
        origOpen(id);
    };

    // ── Workflow actions ─────────────────────────────────────────
    function postAction(action, id, extra, confirmOpts) {
        const doIt = () => {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', action);
            fd.append('id', id);
            if (extra) for (const [k, v] of Object.entries(extra)) fd.append(k, v);
            return fetch(AJAX_URL, {method:'POST', body:fd}).then(r => r.json());
        };
        if (confirmOpts) {
            return Swal.fire(confirmOpts).then(({isConfirmed, value}) => {
                if (!isConfirmed) return null;
                if (typeof confirmOpts.preConfirm === 'function' && value) {
                    Object.assign(extra || (extra = {}), value);
                }
                return doIt();
            });
        }
        return doIt();
    }

    window.ppApprove = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon:'question', title:'อนุมัติงวดนี้?',
            text:'หลังอนุมัติจะแก้ไขรายการไม่ได้ พร้อมกดต่อเพื่อจ่ายเงิน',
            showCancelButton:true, confirmButtonText:'อนุมัติ', cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#16a34a',
        });
        if (!isConfirmed) return;
        const d = await postAction('period:approve', id);
        if (d.ok) {
            Swal.fire({icon:'success', title:d.message, timer:1400, showConfirmButton:false});
            loadList();
            if (document.getElementById('ppModal').classList.contains('is-open')) ppOpenDetail(id);
        } else {
            Swal.fire({icon:'error', title:'อนุมัติไม่ได้', text:d.message});
        }
    };

    window.ppUnapprove = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon:'warning', title:'กลับเป็นฉบับร่าง?',
            text:'งวดจะแก้ไขได้อีกครั้ง · ใช้เมื่อเจอข้อผิดพลาดก่อนจ่ายเงิน',
            showCancelButton:true, confirmButtonText:'ยกเลิกอนุมัติ', cancelButtonText:'ปิด',
            confirmButtonColor:'#f59e0b',
        });
        if (!isConfirmed) return;
        const d = await postAction('period:unapprove', id);
        if (d.ok) {
            Swal.fire({icon:'success', title:d.message, timer:1200, showConfirmButton:false});
            loadList();
            if (document.getElementById('ppModal').classList.contains('is-open')) ppOpenDetail(id);
        } else {
            Swal.fire({icon:'error', title:'ทำไม่ได้', text:d.message});
        }
    };

    window.ppPay = async function(id, ymLabel) {
        const today = new Date().toISOString().slice(0,10);
        const { isConfirmed, value } = await Swal.fire({
            icon:'question',
            title:'จ่ายเงินงวด ' + ymLabel + '?',
            html:'<div style="text-align:left;font-size:14px;line-height:1.6">' +
                'จะ post รายจ่ายเข้า Cash Book อัตโนมัติ (หมวด "เงินเดือน/ค่าจ้าง")' +
                '<label style="display:block;margin-top:10px;font-size:12px;font-weight:700;color:#475569">วันที่จ่ายจริง</label>' +
                '<input id="swPayDate" type="date" value="' + today + '" class="swal2-input" style="margin:0;width:100%">' +
                '</div>',
            showCancelButton:true, confirmButtonText:'จ่ายเงิน', cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#0d9488',
            preConfirm: () => ({ pay_date: document.getElementById('swPayDate').value }),
        });
        if (!isConfirmed) return;
        const d = await postAction('period:pay', id, { pay_date: value.pay_date });
        if (d.ok) {
            Swal.fire({icon:'success', title:d.message, timer:1800, showConfirmButton:false});
            loadList();
            if (document.getElementById('ppModal').classList.contains('is-open')) ppCloseModal();
        } else {
            Swal.fire({icon:'error', title:'จ่ายไม่ได้', text:d.message});
        }
    };

    window.ppCancel = async function(id, ym) {
        const { isConfirmed } = await Swal.fire({
            icon:'warning', title:'ยกเลิกงวด ' + ym + '?',
            text:'งวดจะถูก mark เป็น "ยกเลิก" — สามารถสร้างใหม่ได้ (ลบงวดก่อน หรือต่างเดือน)',
            showCancelButton:true, confirmButtonText:'ยกเลิก', cancelButtonText:'ปิด',
            confirmButtonColor:'#f59e0b',
        });
        if (!isConfirmed) return;
        const d = await postAction('period:cancel', id);
        if (d.ok) { Swal.fire({icon:'success', title:d.message, timer:1200, showConfirmButton:false}); loadList(); }
        else Swal.fire({icon:'error', title:d.message});
    };

    window.ppDelete = async function(id, ym) {
        const { isConfirmed } = await Swal.fire({
            icon:'warning', title:'ลบงวด ' + ym + '?',
            text:'ลบได้เฉพาะ draft หรือ cancelled · ข้อมูลรายการของพนักงานจะถูกลบทั้งหมด',
            showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#dc2626',
        });
        if (!isConfirmed) return;
        const d = await postAction('period:delete', id);
        if (d.ok) { Swal.fire({icon:'success', title:d.message, timer:1200, showConfirmButton:false}); loadList(); }
        else Swal.fire({icon:'error', title:d.message});
    };

    window.ppExport = function(periodId, kind) {
        window.location.href = AJAX_URL + '?action=report:' + kind + '&period_id=' + periodId;
    };

    // ── Initial ──────────────────────────────────────────────────
    loadList();
})();
</script>
