<?php
/**
 * portal/_partials/vitals_bp.php
 * Blood pressure logbook UI
 *
 * Backend: portal/ajax_vitals.php (entity=bp, lookup)
 *
 * Loaded by portal/vitals_bp.php after role gate.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/vitals_helper.php';

$_pdo = db();
vitals_bp_ensure_schema($_pdo);

$vbpCsrf = get_csrf_token();
?>

<style>
    #section-vitals_bp .vbp-card {
        background:#fff; border-radius:16px; border:1px solid #e2e8f0;
        box-shadow:0 2px 8px rgba(15,23,42,.04); padding:18px 20px;
    }
    body[data-theme='dark'] #section-vitals_bp .vbp-card {
        background:#1e293b; border-color:#334155;
    }

    #section-vitals_bp .vbp-input,
    #vbpEntryModal .vbp-input,
    #vbpPatientModal .vbp-input {
        padding:9px 12px; border-radius:10px;
        border:1.5px solid #e2e8f0; background:#f8fafc;
        font-size:14px; font-weight:500; outline:none;
    }
    #section-vitals_bp .vbp-input:focus,
    #vbpEntryModal .vbp-input:focus {
        background:#fff; border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220,38,38,.12);
    }
    body[data-theme='dark'] #section-vitals_bp .vbp-input,
    body[data-theme='dark'] #vbpEntryModal .vbp-input,
    body[data-theme='dark'] #vbpPatientModal .vbp-input {
        background:#0f172a; border-color:#334155; color:#e2e8f0;
    }

    #section-vitals_bp .vbp-cls {
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 10px; border-radius:999px;
        font-size:11px; font-weight:700;
    }
    #section-vitals_bp .vbp-cls-normal   { background:#dcfce7; color:#15803d; }
    #section-vitals_bp .vbp-cls-elevated { background:#fef9c3; color:#a16207; }
    #section-vitals_bp .vbp-cls-stage1   { background:#fed7aa; color:#c2410c; }
    #section-vitals_bp .vbp-cls-stage2   { background:#fee2e2; color:#b91c1c; }
    #section-vitals_bp .vbp-cls-crisis   { background:#7f1d1d; color:#fff;
                                            box-shadow:0 0 0 2px rgba(127,29,29,.3); }

    #section-vitals_bp .vbp-bp-value {
        font-size:18px; font-weight:900; font-variant-numeric:tabular-nums;
        color:#0f172a; letter-spacing:-0.01em;
    }
    body[data-theme='dark'] #section-vitals_bp .vbp-bp-value { color:#f1f5f9; }
    #section-vitals_bp .vbp-bp-unit {
        font-size:11px; font-weight:600; color:#94a3b8; margin-left:4px;
    }

    #section-vitals_bp .vbp-table {
        width:100%; border-collapse:collapse; font-size:14px;
    }
    #section-vitals_bp .vbp-table th {
        background:#f8fafc; padding:11px 14px; text-align:left; font-weight:800;
        color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.04em;
        border-bottom:2px solid #e2e8f0;
    }
    #section-vitals_bp .vbp-table td { padding:12px 14px; border-bottom:1px solid #f1f5f9; }
    body[data-theme='dark'] #section-vitals_bp .vbp-table th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
    body[data-theme='dark'] #section-vitals_bp .vbp-table td { border-color:#334155; color:#e2e8f0; }
    #section-vitals_bp .vbp-table tr:hover td { background:#f8fafc; cursor:pointer; }
    body[data-theme='dark'] #section-vitals_bp .vbp-table tr:hover td { background:#0f172a; }

    #section-vitals_bp .vbp-icon-btn {
        width:30px; height:30px; border-radius:8px;
        display:inline-flex; align-items:center; justify-content:center;
        border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer;
    }
    #section-vitals_bp .vbp-icon-btn:hover {
        transform:translateY(-1px); box-shadow:0 4px 10px rgba(15,23,42,.08);
    }
    #section-vitals_bp .vbp-icon-btn.is-view:hover    { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    #section-vitals_bp .vbp-icon-btn.is-edit:hover    { background:#f59e0b; color:#fff; border-color:#f59e0b; }
    #section-vitals_bp .vbp-icon-btn.is-delete:hover  { background:#dc2626; color:#fff; border-color:#dc2626; }

    #section-vitals_bp .vbp-empty {
        text-align:center; padding:60px 20px; color:#94a3b8;
    }
    #section-vitals_bp .vbp-empty i {
        font-size:48px; margin-bottom:12px; opacity:.5; display:block;
    }

    /* Modal — Portal-Escape pattern */
    #vbpEntryModal, #vbpPatientModal {
        position:fixed; inset:0; background:rgba(15,23,42,.55) !important;
        backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
        z-index:9000 !important; display:none;
        align-items:center; justify-content:center; padding:16px;
    }
    #vbpEntryModal.is-open, #vbpPatientModal.is-open { display:flex; }
    #vbpEntryModal .vbp-modal-box {
        background:#fff; border-radius:18px; width:100%; max-width:600px;
        max-height:94vh; overflow-y:auto; box-shadow:0 24px 64px rgba(15,23,42,.3);
    }
    #vbpPatientModal .vbp-modal-box {
        background:#fff; border-radius:18px; width:100%; max-width:880px;
        max-height:94vh; overflow-y:auto; box-shadow:0 24px 64px rgba(15,23,42,.3);
    }
    body[data-theme='dark'] #vbpEntryModal .vbp-modal-box,
    body[data-theme='dark'] #vbpPatientModal .vbp-modal-box {
        background:#1e293b; color:#e2e8f0;
    }

    /* BP entry visual indicator — live classification preview */
    #vbpClsPreview {
        padding:14px 18px; border-radius:12px; text-align:center;
        font-weight:800; transition:all .2s ease;
        background:#f1f5f9; color:#64748b;
    }
    #vbpClsPreview.vbp-cls-normal   { background:#dcfce7; color:#15803d; }
    #vbpClsPreview.vbp-cls-elevated { background:#fef9c3; color:#a16207; }
    #vbpClsPreview.vbp-cls-stage1   { background:#fed7aa; color:#c2410c; }
    #vbpClsPreview.vbp-cls-stage2   { background:#fee2e2; color:#b91c1c; }
    #vbpClsPreview.vbp-cls-crisis   { background:#7f1d1d; color:#fff; }

    /* Typeahead */
    .vbp-typeahead { position:relative; }
    .vbp-typeahead-list {
        position:absolute; top:100%; left:0; right:0; background:#fff;
        border:1.5px solid #e2e8f0; border-radius:10px; margin-top:4px;
        max-height:240px; overflow-y:auto; z-index:100; display:none;
        box-shadow:0 12px 28px rgba(15,23,42,.12);
    }
    body[data-theme='dark'] .vbp-typeahead-list { background:#1e293b; border-color:#334155; }
    .vbp-typeahead-list.is-open { display:block; }
    .vbp-typeahead-item { padding:9px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:13px; }
    body[data-theme='dark'] .vbp-typeahead-item { border-color:#334155; }
    .vbp-typeahead-item:hover { background:#f1f5f9; }
    body[data-theme='dark'] .vbp-typeahead-item:hover { background:#0f172a; }
    .vbp-typeahead-item .ta-meta { font-size:11px; color:#94a3b8; }

    /* Patient summary cards */
    .vbp-stat-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding:14px 16px;
    }
    body[data-theme='dark'] .vbp-stat-card { background:#0f172a; border-color:#334155; }
    .vbp-stat-card .label {
        font-size:11px; font-weight:700; color:#64748b;
        text-transform:uppercase; letter-spacing:.04em;
    }
    .vbp-stat-card .value {
        font-size:22px; font-weight:900; color:#0f172a; margin-top:4px;
        font-variant-numeric:tabular-nums;
    }
    body[data-theme='dark'] .vbp-stat-card .value { color:#f1f5f9; }
    .vbp-stat-card .sub { font-size:11px; color:#94a3b8; margin-top:2px; }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#dc2626,#f87171);color:#fff">
                <i class="fa-solid fa-heart-pulse"></i>
            </span>
            สมุดความดันโลหิต
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            บันทึก SBP/DBP/ชีพจรของผู้ป่วย · ดู trend รายบุคคล · ระบบประเมินระดับตามมาตรฐาน AHA 2017
        </p>
    </div>
    <button type="button" class="ds-btn ds-btn-primary" onclick="vbpOpenEntry()">
        <i class="fa-solid fa-plus mr-1"></i> บันทึกครั้งใหม่
    </button>
</div>

<!-- Filter bar -->
<div class="vbp-card mb-5 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[220px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ค้นหาผู้ป่วย</label>
        <input id="vbpSearch" type="text" class="vbp-input" style="width:100%" placeholder="ชื่อ / รหัส นศ. / เบอร์โทร">
    </div>
    <div class="min-w-[150px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ระดับความดัน</label>
        <select id="vbpFilterCls" class="vbp-input" style="width:100%">
            <option value="">— ทุกระดับ —</option>
            <?php foreach (VITALS_BP_CLASSIFICATIONS as $k => $v): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="min-w-[150px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ตั้งแต่</label>
        <input id="vbpDateFrom" type="date" class="vbp-input" style="width:100%">
    </div>
    <div class="min-w-[150px]">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ถึง</label>
        <input id="vbpDateTo" type="date" class="vbp-input" style="width:100%">
    </div>
    <button type="button" class="ds-btn ds-btn-ghost" onclick="vbpResetFilters()">
        <i class="fa-solid fa-rotate-left mr-1"></i> ล้าง
    </button>
</div>

<!-- List -->
<div class="vbp-card">
    <div id="vbpTableWrap">
        <div class="vbp-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>
    </div>
    <div id="vbpPagerWrap"></div>
</div>

<!-- BP Entry Modal -->
<div id="vbpEntryModal">
    <div class="vbp-modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-700 sticky top-0 bg-white dark:bg-slate-800 z-10">
            <h3 id="vbpEntryTitle" class="text-lg font-black">บันทึกความดันใหม่</h3>
            <button type="button" onclick="vbpCloseEntry()"
                    class="w-8 h-8 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center">
                <i class="fa-solid fa-xmark text-slate-500"></i>
            </button>
        </div>
        <form id="vbpEntryForm" class="px-6 py-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($vbpCsrf) ?>">
            <input type="hidden" name="action" value="bp:save">
            <input type="hidden" name="id" value="">

            <!-- Patient picker -->
            <div class="vbp-typeahead">
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                    ผู้ป่วย <span class="text-rose-500">*</span>
                </label>
                <input type="hidden" name="patient_id" id="vbpPatientId" value="">
                <input type="text" id="vbpPatientSearch" class="vbp-input" style="width:100%" autocomplete="off"
                       placeholder="พิมพ์ชื่อ / รหัส นศ. / เบอร์โทร...">
                <div id="vbpPatientList" class="vbp-typeahead-list"></div>
            </div>

            <!-- BP values -->
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        SBP (Systolic) <span class="text-rose-500">*</span>
                    </label>
                    <input name="systolic" id="vbpSys" type="number" min="60" max="260" class="vbp-input"
                           style="width:100%;font-weight:800;font-size:18px;text-align:center" required
                           placeholder="120" inputmode="numeric">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        DBP (Diastolic) <span class="text-rose-500">*</span>
                    </label>
                    <input name="diastolic" id="vbpDia" type="number" min="30" max="180" class="vbp-input"
                           style="width:100%;font-weight:800;font-size:18px;text-align:center" required
                           placeholder="80" inputmode="numeric">
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        ชีพจร (bpm)
                    </label>
                    <input name="pulse_rate" id="vbpPulse" type="number" min="30" max="220" class="vbp-input"
                           style="width:100%;font-weight:800;font-size:18px;text-align:center"
                           placeholder="72" inputmode="numeric">
                </div>
            </div>

            <!-- Live classification preview -->
            <div id="vbpClsPreview">— กรอก SBP/DBP เพื่อประเมินระดับ —</div>

            <!-- When / how / arm -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">
                        วัน-เวลา <span class="text-rose-500">*</span>
                    </label>
                    <input name="measured_at" id="vbpMeasuredAt" type="datetime-local" class="vbp-input"
                           style="width:100%" required>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">ท่า</label>
                    <select name="position" class="vbp-input" style="width:100%">
                        <?php foreach (VITALS_POSITIONS as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $k === 'sitting' ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">แขนที่วัด</label>
                    <select name="arm" class="vbp-input" style="width:100%">
                        <option value="">— ไม่ระบุ —</option>
                        <?php foreach (VITALS_ARMS as $k => $v): ?>
                        <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 block">หมายเหตุ</label>
                <textarea name="notes" class="vbp-input" rows="2" maxlength="500" style="width:100%"
                          placeholder="เช่น หลังออกกำลังกาย, หลังรับประทานยาลดความดัน, ฯลฯ"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-700">
                <button type="button" onclick="vbpCloseEntry()" class="ds-btn ds-btn-ghost">ยกเลิก</button>
                <button type="submit" class="ds-btn ds-btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Patient Drill-down Modal -->
<div id="vbpPatientModal">
    <div class="vbp-modal-box">
        <div id="vbpPatientBody"></div>
    </div>
</div>

<script>
(function () {
    'use strict';
    const AJAX_URL = 'ajax_vitals.php';
    const CSRF = <?= json_encode($vbpCsrf) ?>;
    const CLS_LABELS = <?= json_encode(VITALS_BP_CLASSIFICATIONS, JSON_UNESCAPED_UNICODE) ?>;
    const POS_LABELS = <?= json_encode(VITALS_POSITIONS, JSON_UNESCAPED_UNICODE) ?>;
    const ARM_LABELS = <?= json_encode(VITALS_ARMS, JSON_UNESCAPED_UNICODE) ?>;

    let vbpPage = 1;
    let vbpSearchDebounce = null;

    function escapeHtml(s) {
        return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function thDate(d) {
        if (!d) return '—';
        const m = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const p = String(d).split(' ')[0].split('-');
        if (p.length !== 3) return d;
        return Number(p[2]) + ' ' + m[Number(p[1])-1] + ' ' + (Number(p[0])+543);
    }
    function thDateTime(s) {
        if (!s) return '—';
        const parts = String(s).split(' ');
        const dateStr = thDate(parts[0]);
        const time = parts[1] ? parts[1].slice(0, 5) : '';
        return dateStr + (time ? ' ' + time : '');
    }
    function nowLocalForInput() {
        const d = new Date();
        const tz = d.getTimezoneOffset() * 60000;
        return new Date(d - tz).toISOString().slice(0, 16);
    }
    // Classify in JS — must match vitals_bp_classify() server-side
    function classifyBp(sbp, dbp) {
        if (!sbp || !dbp) return null;
        if (sbp >= 180 || dbp >= 120) return 'crisis';
        if (sbp >= 140 || dbp >= 90)  return 'stage2';
        if (sbp >= 130 || dbp >= 80)  return 'stage1';
        if (sbp >= 120)               return 'elevated';
        return 'normal';
    }

    // ── List ──────────────────────────────────────────────────────
    function loadList() {
        const params = new URLSearchParams({
            action: 'bp:list',
            q:               document.getElementById('vbpSearch').value || '',
            classification:  document.getElementById('vbpFilterCls').value || '',
            date_from:       document.getElementById('vbpDateFrom').value || '',
            date_to:         document.getElementById('vbpDateTo').value || '',
            page:            vbpPage,
            per_page:        20,
        });
        fetch(AJAX_URL + '?' + params.toString(), { credentials:'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    document.getElementById('vbpTableWrap').innerHTML =
                        '<div class="vbp-empty"><i class="fa-solid fa-circle-exclamation"></i>' +
                        escapeHtml(d.message || 'โหลดไม่สำเร็จ') + '</div>';
                    return;
                }
                renderTable(d);
                renderPager(d);
            });
    }

    function renderTable(d) {
        if (!d.rows || d.rows.length === 0) {
            document.getElementById('vbpTableWrap').innerHTML =
                '<div class="vbp-empty"><i class="fa-regular fa-folder-open"></i>' +
                'ยังไม่มีบันทึก<br><small>กด "บันทึกครั้งใหม่" เพื่อเริ่ม</small></div>';
            return;
        }
        let html = '<div style="overflow-x:auto"><table class="vbp-table"><thead><tr>' +
            '<th>ผู้ป่วย</th>' +
            '<th style="text-align:center">SBP / DBP</th>' +
            '<th style="text-align:center">ชีพจร</th>' +
            '<th>วัน-เวลาที่วัด</th>' +
            '<th style="text-align:center">ระดับ</th>' +
            '<th>โดย</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';
        d.rows.forEach(r => {
            html += '<tr>' +
                '<td onclick="vbpOpenPatient(' + r.patient_id + ')">' +
                    '<div class="font-semibold text-slate-800 dark:text-slate-100">' + escapeHtml(r.patient_name || '—') + '</div>' +
                    (r.patient_code ? '<div class="text-xs text-slate-500">' + escapeHtml(r.patient_code) + '</div>' : '') +
                '</td>' +
                '<td style="text-align:center">' +
                    '<span class="vbp-bp-value">' + r.systolic + '/' + r.diastolic + '</span>' +
                    '<span class="vbp-bp-unit">mmHg</span>' +
                '</td>' +
                '<td style="text-align:center;font-weight:700;font-variant-numeric:tabular-nums">' +
                    (r.pulse_rate ? r.pulse_rate + ' <span class="text-xs text-slate-400">bpm</span>' : '—') +
                '</td>' +
                '<td class="text-sm">' + thDateTime(r.measured_at) + '</td>' +
                '<td style="text-align:center"><span class="vbp-cls vbp-cls-' + r.classification + '">' +
                    (CLS_LABELS[r.classification] || r.classification) + '</span></td>' +
                '<td class="text-xs text-slate-500">' +
                    (r.source === 'self'
                        ? '<span style="display:inline-block;padding:2px 7px;border-radius:8px;background:#dcfce7;color:#15803d;font-weight:800;font-size:10px;margin-right:4px">✋ ผู้ป่วยจดเอง</span>'
                        : '') +
                    escapeHtml(r.recorded_by_name || '—') +
                '</td>' +
                '<td style="text-align:center;white-space:nowrap" onclick="event.stopPropagation()">' +
                    '<button class="vbp-icon-btn is-view" onclick="vbpOpenPatient(' + r.patient_id + ')" title="ดู trend ผู้ป่วย"><i class="fa-solid fa-chart-line text-xs"></i></button> ' +
                    '<button class="vbp-icon-btn is-edit" onclick="vbpOpenEntry(' + r.id + ')" title="แก้ไข"><i class="fa-solid fa-pen-to-square text-xs"></i></button> ' +
                    '<button class="vbp-icon-btn is-delete" onclick="vbpDelete(' + r.id + ',\'' + escapeHtml(r.patient_name||'') + '\')" title="ลบ"><i class="fa-solid fa-trash-can text-xs"></i></button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('vbpTableWrap').innerHTML = html;
    }

    function renderPager(d) {
        if (!d.total) { document.getElementById('vbpPagerWrap').innerHTML = ''; return; }
        const cur = d.page, pages = d.pages;
        const btn = (l, t, o) => {
            o = o||{};
            return '<button class="ds-btn ds-btn-ghost text-xs' + (o.active?' bg-rose-100':'') + '"' +
                (o.disabled?' disabled':'') + ' onclick="window.vbpGoTo(' + t + ')" style="min-width:32px;height:30px;padding:0 8px">' + l + '</button>';
        };
        let html = '<div class="flex justify-between items-center pt-3 text-sm text-slate-500">' +
            '<div>หน้า ' + cur + ' / ' + pages + ' · รวม ' + d.total.toLocaleString() + ' รายการ</div>' +
            '<div class="flex gap-1">' +
            btn('«', 1, {disabled:cur===1}) + btn('‹', Math.max(1,cur-1), {disabled:cur===1});
        const start = Math.max(1, cur-2), end = Math.min(pages, cur+2);
        for (let i=start; i<=end; i++) html += btn(i, i, {active:i===cur});
        html += btn('›', Math.min(pages,cur+1), {disabled:cur===pages}) +
                btn('»', pages, {disabled:cur===pages}) + '</div></div>';
        document.getElementById('vbpPagerWrap').innerHTML = html;
    }

    window.vbpGoTo = function(n) { vbpPage = n; loadList(); };
    window.vbpResetFilters = function() {
        document.getElementById('vbpSearch').value = '';
        document.getElementById('vbpFilterCls').value = '';
        document.getElementById('vbpDateFrom').value = '';
        document.getElementById('vbpDateTo').value = '';
        vbpPage = 1;
        loadList();
    };

    // ── Entry modal ───────────────────────────────────────────────
    function teleportModal(id) {
        const m = document.getElementById(id);
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    function updateClsPreview() {
        const sbp = parseInt(document.getElementById('vbpSys').value, 10);
        const dbp = parseInt(document.getElementById('vbpDia').value, 10);
        const el = document.getElementById('vbpClsPreview');
        el.className = '';
        if (sbp && dbp && sbp > dbp) {
            const cls = classifyBp(sbp, dbp);
            el.classList.add('vbp-cls-' + cls);
            el.innerHTML = '<i class="fa-solid fa-heart-pulse mr-1"></i> ' +
                sbp + '/' + dbp + ' mmHg · <b>' + (CLS_LABELS[cls] || cls) + '</b>';
        } else {
            el.textContent = '— กรอก SBP/DBP เพื่อประเมินระดับ —';
        }
    }

    window.vbpOpenEntry = function(id) {
        teleportModal('vbpEntryModal');
        const form = document.getElementById('vbpEntryForm');
        form.reset();
        form.querySelector('[name="id"]').value = '';
        document.getElementById('vbpPatientId').value = '';
        document.getElementById('vbpPatientSearch').value = '';
        document.getElementById('vbpMeasuredAt').value = nowLocalForInput();
        document.getElementById('vbpEntryTitle').textContent = 'บันทึกความดันใหม่';
        updateClsPreview();

        if (id) {
            fetch(AJAX_URL + '?action=bp:get&id=' + id)
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) { Swal.fire({icon:'error', title:'ไม่พบ', text:d.message||''}); return; }
                    const r = d.row;
                    document.getElementById('vbpEntryTitle').textContent = 'แก้ไขบันทึก';
                    form.querySelector('[name="id"]').value = r.id;
                    document.getElementById('vbpPatientId').value = r.patient_id;
                    document.getElementById('vbpPatientSearch').value =
                        (r.patient_name || '') + (r.patient_code ? ' · ' + r.patient_code : '');
                    document.getElementById('vbpSys').value   = r.systolic;
                    document.getElementById('vbpDia').value   = r.diastolic;
                    document.getElementById('vbpPulse').value = r.pulse_rate || '';
                    // datetime-local needs "YYYY-MM-DDTHH:MM"
                    if (r.measured_at) {
                        document.getElementById('vbpMeasuredAt').value =
                            r.measured_at.replace(' ', 'T').slice(0, 16);
                    }
                    if (r.position) form.querySelector('[name="position"]').value = r.position;
                    if (r.arm)      form.querySelector('[name="arm"]').value = r.arm;
                    if (r.notes)    form.querySelector('[name="notes"]').value = r.notes;
                    updateClsPreview();
                });
        }
        document.getElementById('vbpEntryModal').classList.add('is-open');
        setTimeout(() => document.getElementById('vbpPatientSearch').focus(), 100);
    };

    window.vbpCloseEntry = function() {
        document.getElementById('vbpEntryModal').classList.remove('is-open');
    };

    document.getElementById('vbpEntryModal').addEventListener('click', function(e) {
        if (e.target === this) vbpCloseEntry();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (document.getElementById('vbpEntryModal').classList.contains('is-open')) vbpCloseEntry();
            if (document.getElementById('vbpPatientModal').classList.contains('is-open')) vbpClosePatient();
        }
    });

    // Live classification on input
    ['vbpSys', 'vbpDia'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateClsPreview);
    });

    // Patient typeahead
    let patSearchDeb = null;
    const patWrap = document.querySelector('#vbpEntryForm .vbp-typeahead');
    document.getElementById('vbpPatientSearch').addEventListener('input', function() {
        const q = this.value.trim();
        document.getElementById('vbpPatientId').value = '';
        clearTimeout(patSearchDeb);
        if (q === '') {
            document.getElementById('vbpPatientList').classList.remove('is-open');
            return;
        }
        patSearchDeb = setTimeout(() => {
            fetch(AJAX_URL + '?action=lookup:patient&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(d => {
                    const list = document.getElementById('vbpPatientList');
                    if (!d.ok || !d.rows || d.rows.length === 0) {
                        list.innerHTML = '<div class="vbp-typeahead-item" style="color:#94a3b8">ไม่พบ</div>';
                        list.classList.add('is-open');
                        return;
                    }
                    list.innerHTML = d.rows.map(r => {
                        const meta = [
                            r.student_personnel_id ? 'รหัส ' + r.student_personnel_id : '',
                            r.phone_number ? '📞 ' + r.phone_number : ''
                        ].filter(Boolean).join(' · ');
                        return '<div class="vbp-typeahead-item" data-id="' + r.id +
                            '" data-name="' + escapeHtml(r.full_name||'') +
                            '" data-code="' + escapeHtml(r.student_personnel_id||'') + '">' +
                            '<div>' + escapeHtml(r.full_name||'—') + '</div>' +
                            (meta ? '<div class="ta-meta">' + escapeHtml(meta) + '</div>' : '') +
                            '</div>';
                    }).join('');
                    list.classList.add('is-open');
                    list.querySelectorAll('[data-id]').forEach(el => {
                        el.addEventListener('click', () => {
                            document.getElementById('vbpPatientId').value = el.dataset.id;
                            document.getElementById('vbpPatientSearch').value =
                                el.dataset.name + (el.dataset.code ? ' · ' + el.dataset.code : '');
                            list.classList.remove('is-open');
                            document.getElementById('vbpSys').focus();
                        });
                    });
                });
        }, 250);
    });
    document.addEventListener('click', e => {
        if (patWrap && !patWrap.contains(e.target)) {
            document.getElementById('vbpPatientList').classList.remove('is-open');
        }
    });

    // Save
    document.getElementById('vbpEntryForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!document.getElementById('vbpPatientId').value) {
            Swal.fire({icon:'warning', title:'ยังไม่ได้เลือกผู้ป่วย', text:'พิมพ์ชื่อหรือรหัส แล้วเลือกจากรายการ'});
            return;
        }
        const fd = new FormData(this);
        fetch(AJAX_URL, {method:'POST', body:fd, credentials:'same-origin'})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    vbpCloseEntry();
                    Swal.fire({icon:'success', title:d.message||'บันทึกแล้ว', timer:1200, showConfirmButton:false});
                    loadList();
                } else {
                    let detail = '';
                    if (d.errors) {
                        detail = '<ul style="text-align:left;margin-top:10px;font-size:13px">' +
                            Object.entries(d.errors).map(([k,v]) =>
                                '<li><b>' + escapeHtml(k) + '</b>: ' + escapeHtml(v) + '</li>').join('') + '</ul>';
                    }
                    Swal.fire({icon:'error', title:'บันทึกไม่ได้', html:escapeHtml(d.message||'') + detail});
                }
            });
    });

    window.vbpDelete = async function(id, name) {
        const {isConfirmed} = await Swal.fire({
            icon:'warning', title:'ลบบันทึก?',
            text:'ของ ' + name + ' · ลบแล้วกู้คืนไม่ได้',
            showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#dc2626',
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'bp:delete');
        fd.append('id', id);
        fetch(AJAX_URL, {method:'POST', body:fd})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({icon:'success', title:d.message, timer:1100, showConfirmButton:false});
                    loadList();
                } else {
                    Swal.fire({icon:'error', title:d.message||''});
                }
            });
    };

    // ── Patient drill-down modal with trend chart ─────────────────
    let chartInstance = null;

    window.vbpOpenPatient = function(patientId) {
        teleportModal('vbpPatientModal');
        const body = document.getElementById('vbpPatientBody');
        body.innerHTML = '<div style="padding:60px;text-align:center;color:#94a3b8">' +
            '<i class="fa-solid fa-spinner fa-spin text-3xl"></i></div>';
        document.getElementById('vbpPatientModal').classList.add('is-open');

        fetch(AJAX_URL + '?action=bp:summary&patient_id=' + patientId)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    body.innerHTML = '<div style="padding:60px;text-align:center;color:#dc2626">' +
                        '<i class="fa-solid fa-circle-exclamation text-3xl"></i><br>' +
                        escapeHtml(d.message||'') + '</div>';
                    return;
                }
                renderPatientDetail(d);
            });
    };

    window.vbpClosePatient = function() {
        document.getElementById('vbpPatientModal').classList.remove('is-open');
        if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    };

    document.getElementById('vbpPatientModal').addEventListener('click', function(e) {
        if (e.target === this) vbpClosePatient();
    });

    function renderPatientDetail(d) {
        const p = d.patient, latest = d.latest, stats = d.stats_30d || {}, trend = d.trend_points || [];
        const latestCls = latest ? latest.classification : null;
        const latestColor = latest
            ? {normal:'#15803d', elevated:'#a16207', stage1:'#c2410c', stage2:'#b91c1c', crisis:'#7f1d1d'}[latestCls]
            : '#94a3b8';

        const headerHtml =
            '<div style="background:linear-gradient(135deg,#dc2626,#f87171);color:#fff;padding:18px 22px;display:flex;justify-content:space-between;align-items:start;border-radius:18px 18px 0 0">' +
                '<div style="min-width:0">' +
                    '<div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.9">' +
                        '<i class="fa-solid fa-heart-pulse mr-1"></i> BP TRACKING' +
                    '</div>' +
                    '<div style="font-size:22px;font-weight:900;margin-top:4px">' + escapeHtml(p.full_name||'—') + '</div>' +
                    '<div style="font-size:12px;opacity:.9;margin-top:2px">' +
                        (p.student_personnel_id ? 'รหัส ' + escapeHtml(p.student_personnel_id) + ' · ' : '') +
                        (p.phone_number ? '📞 ' + escapeHtml(p.phone_number) : '') +
                    '</div>' +
                '</div>' +
                '<button onclick="vbpClosePatient()" style="background:rgba(255,255,255,.2);color:#fff;width:32px;height:32px;border-radius:8px;border:none;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>';

        // Stats
        const statsHtml =
            '<div style="padding:18px 22px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px">' +
                '<div class="vbp-stat-card">' +
                    '<div class="label">วัดล่าสุด</div>' +
                    (latest
                        ? '<div class="value" style="color:' + latestColor + '">' + latest.systolic + '/' + latest.diastolic + '</div>' +
                          '<div class="sub">' + thDateTime(latest.measured_at) + '</div>'
                        : '<div class="value">—</div><div class="sub">ยังไม่มีบันทึก</div>') +
                '</div>' +
                '<div class="vbp-stat-card">' +
                    '<div class="label">เฉลี่ย 30 วัน</div>' +
                    '<div class="value">' + (stats.count > 0 ? (stats.avg_systolic + '/' + stats.avg_diastolic) : '—') + '</div>' +
                    '<div class="sub">' + (stats.count > 0 ? 'จาก ' + stats.count + ' ครั้ง' : 'ไม่มีข้อมูล') + '</div>' +
                '</div>' +
                '<div class="vbp-stat-card">' +
                    '<div class="label">SBP สูงสุด</div>' +
                    '<div class="value">' + (stats.max_systolic || '—') + '</div>' +
                    '<div class="sub">ในรอบ 30 วัน</div>' +
                '</div>' +
                '<div class="vbp-stat-card" style="border-color:' + (stats.high_count > 0 ? '#fecaca' : '#e2e8f0') + '">' +
                    '<div class="label">ครั้งที่สูง (≥140/90)</div>' +
                    '<div class="value" style="color:' + (stats.high_count > 0 ? '#b91c1c' : '#0f172a') + '">' +
                        (stats.high_count || 0) + '</div>' +
                    '<div class="sub">ในรอบ 30 วัน</div>' +
                '</div>' +
            '</div>';

        // Chart container
        const chartHtml =
            '<div style="padding:0 22px 18px">' +
                '<div style="font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">' +
                    '<i class="fa-solid fa-chart-line"></i> Trend (60 ครั้งล่าสุด)' +
                '</div>' +
                (trend.length === 0
                    ? '<div class="vbp-empty"><i class="fa-regular fa-folder-open"></i>ยังไม่มีบันทึก</div>'
                    : '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;height:280px"><canvas id="vbpTrendChart"></canvas></div>') +
            '</div>';

        // Footer with close
        const footerHtml =
            '<div style="padding:14px 22px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:8px">' +
                '<button onclick="vbpClosePatient()" class="ds-btn ds-btn-ghost">ปิด</button>' +
                '<button onclick="vbpClosePatient();vbpOpenEntryForPatient(' + p.id + ',\'' + escapeHtml(p.full_name||'').replace(/'/g,"\\'") + '\',\'' + escapeHtml(p.student_personnel_id||'').replace(/'/g,"\\'") + '\')" class="ds-btn ds-btn-primary"><i class="fa-solid fa-plus mr-1"></i> บันทึกใหม่</button>' +
            '</div>';

        document.getElementById('vbpPatientBody').innerHTML =
            headerHtml + statsHtml + chartHtml + footerHtml;

        if (trend.length > 0) {
            setTimeout(() => renderTrendChart(trend), 50);
        }
    }

    window.vbpOpenEntryForPatient = function(pid, name, code) {
        setTimeout(() => {
            vbpOpenEntry();
            document.getElementById('vbpPatientId').value = pid;
            document.getElementById('vbpPatientSearch').value = name + (code ? ' · ' + code : '');
            document.getElementById('vbpSys').focus();
        }, 250);
    };

    function renderTrendChart(points) {
        const ctx = document.getElementById('vbpTrendChart');
        if (!ctx || typeof Chart === 'undefined') return;
        if (chartInstance) chartInstance.destroy();

        const labels = points.map(p => p.measured_at.slice(5, 16).replace(' ', '\n'));
        const sbp    = points.map(p => Number(p.systolic));
        const dbp    = points.map(p => Number(p.diastolic));

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'SBP', data: sbp, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.1)',
                      tension: 0.25, fill: false, pointRadius: 3 },
                    { label: 'DBP', data: dbp, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.1)',
                      tension: 0.25, fill: false, pointRadius: 3 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12, weight: 700 } } },
                    tooltip: {
                        callbacks: {
                            title: items => items[0].label.replace('\n', ' '),
                        }
                    },
                    annotation: false,
                },
                scales: {
                    x: { ticks: { font: { size: 10 }, maxRotation: 0 } },
                    y: { beginAtZero: false, suggestedMin: 50, suggestedMax: 180,
                         ticks: { font: { size: 11 } },
                         title: { display: true, text: 'mmHg', font: { size: 11, weight: 700 } } },
                }
            }
        });
    }

    // ── Wire filters ──────────────────────────────────────────────
    document.getElementById('vbpSearch').addEventListener('input', () => {
        clearTimeout(vbpSearchDebounce);
        vbpSearchDebounce = setTimeout(() => { vbpPage = 1; loadList(); }, 350);
    });
    ['vbpFilterCls', 'vbpDateFrom', 'vbpDateTo'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            vbpPage = 1; loadList();
        });
    });

    loadList();
})();
</script>
