<?php
/**
 * portal/_partials/payroll_employees.php
 * Payroll — employee profile management UI
 *
 * Backend: portal/ajax_payroll.php (entity=employee, lookup)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/payroll_helper.php';

$_pdo = db();
pr_ensure_schema($_pdo);

$peCsrf = get_csrf_token();
?>

<style>
    #section-payroll_employees .pe-card {
        background:#fff; border-radius:16px; border:1px solid #e2e8f0;
        box-shadow:0 2px 8px rgba(15,23,42,.04); padding:18px 20px;
    }
    body[data-theme='dark'] #section-payroll_employees .pe-card { background:#1e293b; border-color:#334155; }

    #section-payroll_employees .pe-input,
    #peEmpModal .pe-input {
        width:100%; padding:9px 12px; border-radius:10px;
        border:1.5px solid #e2e8f0; background:#f8fafc;
        font-size:14px; font-weight:500; outline:none;
    }
    #section-payroll_employees .pe-input:focus,
    #peEmpModal .pe-input:focus {
        background:#fff; border-color:#2e9e63;
        box-shadow:0 0 0 3px rgba(46,158,99,.12);
    }
    body[data-theme='dark'] #section-payroll_employees .pe-input,
    body[data-theme='dark'] #peEmpModal .pe-input {
        background:#0f172a; border-color:#334155; color:#e2e8f0;
    }

    #section-payroll_employees .pe-table { width:100%; border-collapse:collapse; font-size:14px; }
    #section-payroll_employees .pe-table th {
        background:#f8fafc; padding:11px 14px; text-align:left; font-weight:800;
        color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.04em;
        border-bottom:2px solid #e2e8f0;
    }
    #section-payroll_employees .pe-table td {
        padding:12px 14px; border-bottom:1px solid #f1f5f9;
    }
    body[data-theme='dark'] #section-payroll_employees .pe-table th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
    body[data-theme='dark'] #section-payroll_employees .pe-table td { border-color:#334155; color:#e2e8f0; }

    #section-payroll_employees .pe-icon-btn {
        width:32px; height:32px; border-radius:8px;
        display:inline-flex; align-items:center; justify-content:center;
        border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer;
    }
    #section-payroll_employees .pe-icon-btn:hover { transform:translateY(-1px); box-shadow:0 4px 10px rgba(15,23,42,.08); }
    #section-payroll_employees .pe-icon-btn.is-edit:hover   { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    #section-payroll_employees .pe-icon-btn.is-toggle:hover { background:#f59e0b; color:#fff; border-color:#f59e0b; }

    #section-payroll_employees .pe-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
    #section-payroll_employees .pe-empty i { font-size:48px; margin-bottom:12px; opacity:.5; display:block; }

    /* Modal */
    #peEmpModal {
        position:fixed; inset:0; background:rgba(15,23,42,.55) !important;
        backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
        z-index:9000 !important; display:none; align-items:center; justify-content:center; padding:16px;
    }
    #peEmpModal.is-open { display:flex; }
    #peEmpModal .pe-modal-box {
        background:#fff; border-radius:18px; width:100%; max-width:760px;
        max-height:92vh; overflow-y:auto; box-shadow:0 24px 64px rgba(15,23,42,.3);
    }
    body[data-theme='dark'] #peEmpModal .pe-modal-box { background:#1e293b; color:#e2e8f0; }

    /* Typeahead */
    .pe-typeahead { position:relative; }
    .pe-typeahead-list {
        position:absolute; top:100%; left:0; right:0; background:#fff;
        border:1.5px solid #e2e8f0; border-radius:10px; margin-top:4px;
        max-height:280px; overflow-y:auto; z-index:100; display:none;
        box-shadow:0 12px 28px rgba(15,23,42,.12);
    }
    body[data-theme='dark'] .pe-typeahead-list { background:#1e293b; border-color:#334155; }
    .pe-typeahead-list.is-open { display:block; }
    .pe-typeahead-item { padding:9px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:13px; }
    body[data-theme='dark'] .pe-typeahead-item { border-color:#334155; }
    .pe-typeahead-item:hover { background:#f1f5f9; }
    body[data-theme='dark'] .pe-typeahead-item:hover { background:#0f172a; }
    .pe-typeahead-item .ta-meta { font-size:11px; color:#94a3b8; }

    /* Section dividers in modal */
    .pe-section-title {
        font-size:11px; font-weight:800; color:#475569;
        text-transform:uppercase; letter-spacing:.05em;
        margin-top:12px; margin-bottom:6px;
        border-left:3px solid #2e9e63; padding-left:10px;
    }

    #section-payroll_employees .pe-pill {
        display:inline-block; padding:2px 8px; border-radius:999px;
        font-size:11px; font-weight:700;
    }
    #section-payroll_employees .pe-pill-active   { background:#dcfce7; color:#15803d; }
    #section-payroll_employees .pe-pill-inactive { background:#f1f5f9; color:#64748b; }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#2e9e63,#3bba7a);color:#fff">
                <i class="fa-solid fa-users-gear"></i>
            </span>
            ตั้งค่าพนักงาน (Payroll)
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            โปรไฟล์ payroll ของพนักงาน · เงินเดือน · ประกันสังคม · ภาษี · บัญชีธนาคาร
        </p>
    </div>
    <button type="button" class="ds-btn ds-btn-primary" onclick="peOpenModal()">
        <i class="fa-solid fa-plus mr-1"></i> เพิ่มพนักงาน
    </button>
</div>

<!-- Filter bar -->
<div class="pe-card mb-5 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[220px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค้นหา</label>
        <input id="peSearch" type="text" class="pe-input" placeholder="ชื่อ / รหัสพนักงาน / เลขผู้เสียภาษี">
    </div>
    <div class="min-w-[140px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">สถานะ</label>
        <select id="peFilterActive" class="pe-input">
            <option value="1">เปิดใช้งาน</option>
            <option value="0">ปิดใช้งาน</option>
            <option value="-1">ทั้งหมด</option>
        </select>
    </div>
</div>

<!-- Table -->
<div class="pe-card">
    <div id="peTableWrap">
        <div class="pe-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>
    </div>
    <div id="pePagerWrap"></div>
</div>

<!-- Employee Modal -->
<div id="peEmpModal">
    <div class="pe-modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-700 sticky top-0 bg-white dark:bg-slate-800 z-10">
            <h3 id="peModalTitle" class="text-lg font-black">เพิ่มพนักงาน</h3>
            <button type="button" onclick="peCloseModal()"
                    class="w-8 h-8 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center">
                <i class="fa-solid fa-xmark text-slate-500"></i>
            </button>
        </div>
        <form id="peEmpForm" class="px-6 py-5 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($peCsrf) ?>">
            <input type="hidden" name="action" value="employee:save">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="staff_id" id="peStaffId" value="">

            <!-- Section: identity -->
            <div class="pe-section-title">ข้อมูลพนักงาน</div>
            <div class="pe-typeahead" id="peStaffPickerWrap">
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                    พนักงาน <span class="text-rose-500">*</span>
                </label>
                <input type="text" id="peStaffSearch" class="pe-input" autocomplete="off"
                       placeholder="พิมพ์ชื่อพนักงาน (จาก sys_staff)...">
                <div id="peStaffList" class="pe-typeahead-list"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">รหัสพนักงาน</label>
                    <input name="employee_no" type="text" class="pe-input" maxlength="40" placeholder="(ถ้ามี)">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ประเภทการจ้าง</label>
                    <select name="employment_type" class="pe-input">
                        <?php foreach (PR_EMPLOYMENT_TYPES as $k => $v): ?>
                        <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">วันที่เริ่มงาน</label>
                    <input name="hire_date" type="date" class="pe-input">
                </div>
            </div>

            <!-- Section: salary -->
            <div class="pe-section-title">เงินเดือน &amp; รายได้ประจำ</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        เงินเดือนพื้นฐาน <span class="text-rose-500">*</span>
                    </label>
                    <input name="base_salary" type="number" min="0" step="0.01" class="pe-input" required>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค่าครองชีพ/ตำแหน่ง</label>
                    <input name="monthly_allowance" type="number" min="0" step="0.01" value="0" class="pe-input">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">อัตรา OT (ต่อ ชม.)</label>
                    <input name="ot_rate" type="number" min="0" step="0.01" value="0" class="pe-input">
                </div>
            </div>

            <!-- Section: SSO / PF -->
            <div class="pe-section-title">ประกันสังคม &amp; กองทุนสำรองเลี้ยงชีพ</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input name="is_in_sso" type="checkbox" value="1" checked class="w-4 h-4 rounded text-emerald-600">
                    <span class="text-sm font-medium">อยู่ในประกันสังคม</span>
                </label>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">เลขประกันสังคม</label>
                    <input name="sso_no" type="text" class="pe-input" maxlength="20">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">เลขผู้เสียภาษี</label>
                    <input name="tax_id" type="text" class="pe-input" maxlength="20">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input name="is_in_pf" type="checkbox" value="1" class="w-4 h-4 rounded text-emerald-600">
                    <span class="text-sm font-medium">อยู่ใน Provident Fund</span>
                </label>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">อัตรา PF ลูกจ้าง (%)</label>
                    <input name="pf_rate_pct" type="number" min="0" max="100" step="0.01" value="0" class="pe-input">
                </div>
            </div>

            <!-- Section: tax allowances -->
            <div class="pe-section-title">ค่าลดหย่อนภาษี (รายปี)</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค่าลดหย่อนส่วนตัว</label>
                    <input name="personal_allowance" type="number" min="0" step="0.01" value="60000" class="pe-input">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค่าลดหย่อนคู่สมรส</label>
                    <input name="spouse_allowance" type="number" min="0" step="0.01" value="0" class="pe-input">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">จำนวนบุตร</label>
                    <input name="children_count" type="number" min="0" step="1" value="0" class="pe-input">
                </div>
            </div>

            <!-- Section: bank -->
            <div class="pe-section-title">บัญชีรับเงินเดือน</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ธนาคาร</label>
                    <input name="bank_name" type="text" class="pe-input" maxlength="80">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">เลขบัญชี</label>
                    <input name="bank_account" type="text" class="pe-input" maxlength="40">
                </div>
            </div>

            <!-- Active toggle -->
            <div class="border-t border-slate-100 dark:border-slate-700 pt-3 flex items-center gap-3">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input name="is_active" type="checkbox" value="1" checked class="w-4 h-4 rounded text-emerald-600">
                    <span class="text-sm font-medium">เปิดใช้งาน (จะถูกรวมในงวดที่สร้างใหม่)</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-700">
                <button type="button" onclick="peCloseModal()" class="ds-btn ds-btn-ghost">ยกเลิก</button>
                <button type="submit" class="ds-btn ds-btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';
    const AJAX_URL = 'ajax_payroll.php';
    const CSRF     = <?= json_encode($peCsrf) ?>;
    const ETYPES   = <?= json_encode(PR_EMPLOYMENT_TYPES, JSON_UNESCAPED_UNICODE) ?>;
    let pePage = 1, peSearchDebounce = null;

    function thBaht(n) { return Number(n||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function escapeHtml(s) {
        return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function loadEmployees() {
        const params = new URLSearchParams({
            action: 'employee:list',
            q:      document.getElementById('peSearch').value || '',
            active: document.getElementById('peFilterActive').value || '1',
            page:   pePage, per_page: 20,
        });
        fetch(AJAX_URL + '?' + params.toString())
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    document.getElementById('peTableWrap').innerHTML =
                        '<div class="pe-empty"><i class="fa-solid fa-circle-exclamation"></i>' +
                        escapeHtml(d.message || 'โหลดไม่สำเร็จ') + '</div>';
                    return;
                }
                renderTable(d);
                renderPager(d);
            });
    }

    function renderTable(d) {
        if (!d.rows || d.rows.length === 0) {
            document.getElementById('peTableWrap').innerHTML =
                '<div class="pe-empty"><i class="fa-regular fa-folder-open"></i>' +
                'ยังไม่มีโปรไฟล์พนักงาน<br>' +
                '<small>กด "เพิ่มพนักงาน" เพื่อสร้าง</small></div>';
            return;
        }
        let html = '<div style="overflow-x:auto"><table class="pe-table"><thead><tr>' +
            '<th>ชื่อ-นามสกุล</th>' +
            '<th>รหัส</th>' +
            '<th>ประเภท</th>' +
            '<th style="text-align:right">เงินเดือน</th>' +
            '<th style="text-align:center">SSO</th>' +
            '<th style="text-align:center">PF</th>' +
            '<th style="text-align:center">สถานะ</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';
        d.rows.forEach(r => {
            html += '<tr>' +
                '<td><div class="font-semibold">' + escapeHtml(r.full_name||'—') + '</div>' +
                    (r.official_title || r.job_title ? '<div class="text-xs text-slate-500">' + escapeHtml(r.official_title || r.job_title) + '</div>' : '') +
                '</td>' +
                '<td><code class="text-xs">' + escapeHtml(r.employee_no || '—') + '</code></td>' +
                '<td class="text-sm">' + (ETYPES[r.employment_type] || r.employment_type) + '</td>' +
                '<td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums">' + thBaht(r.base_salary) + '</td>' +
                '<td style="text-align:center">' + (Number(r.is_in_sso)===1 ? '<i class="fa-solid fa-check text-emerald-600"></i>' : '—') + '</td>' +
                '<td style="text-align:center">' + (Number(r.is_in_pf)===1 ? (r.pf_rate_pct + '%') : '—') + '</td>' +
                '<td style="text-align:center"><span class="pe-pill pe-pill-' + (Number(r.is_active)===1?'active':'inactive') + '">' +
                    (Number(r.is_active)===1?'เปิด':'ปิด') + '</span></td>' +
                '<td style="text-align:center">' +
                    '<button class="pe-icon-btn is-edit" onclick="peOpenModal(' + r.id + ')" title="แก้ไข"><i class="fa-solid fa-pen-to-square text-xs"></i></button> ' +
                    '<button class="pe-icon-btn is-toggle" onclick="peToggle(' + r.id + ')" title="สลับสถานะ"><i class="fa-solid fa-' + (Number(r.is_active)===1?'toggle-on':'toggle-off') + ' text-xs"></i></button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('peTableWrap').innerHTML = html;
    }

    function renderPager(d) {
        if (!d.total) { document.getElementById('pePagerWrap').innerHTML = ''; return; }
        const cur = d.page, pages = d.pages;
        const btn = (l, t, o) => {
            o = o||{};
            return '<button class="ds-btn ds-btn-ghost text-xs' + (o.active?' bg-emerald-100':'') + '"' +
                (o.disabled?' disabled':'') + ' onclick="window.peGoToPage(' + t + ')" style="min-width:32px;height:30px;padding:0 8px">' + l + '</button>';
        };
        let html = '<div class="flex justify-between items-center pt-3 text-sm text-slate-500">' +
            '<div>หน้า ' + cur + ' / ' + pages + ' · รวม ' + d.total.toLocaleString() + ' รายการ</div>' +
            '<div class="flex gap-1">' +
            btn('«', 1, {disabled:cur===1}) + btn('‹', Math.max(1,cur-1), {disabled:cur===1});
        const start = Math.max(1, cur-2), end = Math.min(pages, cur+2);
        for (let i=start; i<=end; i++) html += btn(i, i, {active:i===cur});
        html += btn('›', Math.min(pages,cur+1), {disabled:cur===pages}) +
                btn('»', pages, {disabled:cur===pages}) + '</div></div>';
        document.getElementById('pePagerWrap').innerHTML = html;
    }

    window.peGoToPage = function(n) { pePage = n; loadEmployees(); };

    // ── Typeahead staff picker ───────────────────────────────────
    let staffDeb = null;
    document.getElementById('peStaffSearch').addEventListener('input', function() {
        const q = this.value.trim();
        document.getElementById('peStaffId').value = '';
        clearTimeout(staffDeb);
        if (q === '') {
            document.getElementById('peStaffList').classList.remove('is-open');
            return;
        }
        staffDeb = setTimeout(() => {
            fetch(AJAX_URL + '?action=lookup:staff&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(d => {
                    const list = document.getElementById('peStaffList');
                    if (!d.ok || !d.rows || d.rows.length === 0) {
                        list.innerHTML = '<div class="pe-typeahead-item" style="color:#94a3b8">ไม่พบ (หรือทำโปรไฟล์ payroll แล้ว)</div>';
                        list.classList.add('is-open');
                        return;
                    }
                    list.innerHTML = d.rows.map(r =>
                        '<div class="pe-typeahead-item" data-id="' + r.id + '" data-name="' + escapeHtml(r.full_name||'') + '">' +
                        '<div>' + escapeHtml(r.full_name||'—') + '</div>' +
                        (r.official_title || r.job_title ? '<div class="ta-meta">' + escapeHtml(r.official_title || r.job_title) + '</div>' : '') +
                        '</div>'
                    ).join('');
                    list.classList.add('is-open');
                    list.querySelectorAll('[data-id]').forEach(el => {
                        el.addEventListener('click', () => {
                            document.getElementById('peStaffId').value = el.dataset.id;
                            document.getElementById('peStaffSearch').value = el.dataset.name;
                            list.classList.remove('is-open');
                        });
                    });
                });
        }, 250);
    });
    document.addEventListener('click', (e) => {
        if (!document.getElementById('peStaffPickerWrap').contains(e.target)) {
            document.getElementById('peStaffList').classList.remove('is-open');
        }
    });

    // ── Modal ────────────────────────────────────────────────────
    function teleportModal() {
        const m = document.getElementById('peEmpModal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    window.peOpenModal = function(id) {
        teleportModal();
        const form = document.getElementById('peEmpForm');
        form.reset();
        form.querySelector('[name="id"]').value = '';
        document.getElementById('peStaffId').value = '';
        document.getElementById('peStaffSearch').value = '';
        document.getElementById('peModalTitle').textContent = 'เพิ่มพนักงาน';
        document.getElementById('peStaffPickerWrap').style.display = '';

        if (id) {
            fetch(AJAX_URL + '?action=employee:get&id=' + id)
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) {
                        Swal.fire({icon:'error', title:'ไม่พบ', text:d.message||''});
                        return;
                    }
                    const r = d.row;
                    document.getElementById('peModalTitle').textContent = 'แก้ไข · ' + (r.full_name||'');
                    form.querySelector('[name="id"]').value = r.id;
                    document.getElementById('peStaffId').value = r.staff_id;
                    document.getElementById('peStaffSearch').value = r.full_name || '';
                    // Hide staff picker on edit (can't change which staff)
                    document.getElementById('peStaffPickerWrap').style.display = 'none';

                    ['employee_no','employment_type','base_salary','monthly_allowance','ot_rate',
                     'bank_name','bank_account','tax_id','sso_no','pf_rate_pct',
                     'personal_allowance','spouse_allowance','children_count',
                     'hire_date','terminate_date'].forEach(f => {
                        const el = form.querySelector('[name="' + f + '"]');
                        if (el) el.value = r[f] || (el.type === 'number' ? 0 : '');
                    });
                    form.querySelector('[name="is_in_sso"]').checked = Number(r.is_in_sso) === 1;
                    form.querySelector('[name="is_in_pf"]').checked  = Number(r.is_in_pf)  === 1;
                    form.querySelector('[name="is_active"]').checked = Number(r.is_active) === 1;
                });
        }
        document.getElementById('peEmpModal').classList.add('is-open');
    };

    window.peCloseModal = function() {
        document.getElementById('peEmpModal').classList.remove('is-open');
    };
    document.getElementById('peEmpModal').addEventListener('click', function(e) {
        if (e.target === this) peCloseModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && document.getElementById('peEmpModal').classList.contains('is-open')) peCloseModal();
    });

    document.getElementById('peEmpForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        ['is_in_sso','is_in_pf','is_active'].forEach(k => {
            if (!fd.has(k)) fd.set(k, '0');
        });
        // For new records, staff_id must be set via picker
        if (!fd.get('id') && !fd.get('staff_id')) {
            Swal.fire({icon:'warning', title:'ยังไม่ได้เลือกพนักงาน'});
            return;
        }
        fetch(AJAX_URL, {method:'POST', body:fd})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    peCloseModal();
                    Swal.fire({icon:'success', title:d.message||'บันทึกแล้ว', timer:1200, showConfirmButton:false});
                    loadEmployees();
                } else {
                    Swal.fire({icon:'error', title:'บันทึกไม่ได้', text:d.message||''});
                }
            });
    });

    window.peToggle = function(id) {
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'employee:toggle');
        fd.append('id', id);
        fetch(AJAX_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
            if (d.ok) loadEmployees();
            else Swal.fire({icon:'error', title:d.message||''});
        });
    };

    document.getElementById('peSearch').addEventListener('input', () => {
        clearTimeout(peSearchDebounce);
        peSearchDebounce = setTimeout(() => { pePage = 1; loadEmployees(); }, 350);
    });
    document.getElementById('peFilterActive').addEventListener('change', () => { pePage = 1; loadEmployees(); });

    loadEmployees();
})();
</script>
