<?php
/**
 * portal/_partials/insurance_sync.php — Insurance Sync Hub
 */
declare(strict_types=1);

$pdo = db();
$csrfToken = get_csrf_token();

// KPI stats + last sync — loaded server-side to avoid FOUC
$stats = ['total_active' => 0, 'total_inactive' => 0, 'staff' => 0, 'student' => 0, 'manual_override' => 0, 'total' => 0];
$lastSync = null;
try {
    $r = $pdo->query("
        SELECT
            SUM(insurance_status = 'Active')    AS total_active,
            SUM(insurance_status = 'Inactive')  AS total_inactive,
            SUM(member_status = 'บุคลากร')       AS staff,
            SUM(member_status = 'นักศึกษา')      AS student,
            SUM(manually_overridden = 1)        AS manual_override,
            COUNT(*)                            AS total
        FROM insurance_members
    ")->fetch(PDO::FETCH_ASSOC);
    if ($r) $stats = array_map('intval', $r);

    $lastSync = $pdo->query("
        SELECT sync_id, MIN(changed_at) AS sync_time,
               SUM(change_type = 'inserted')   AS cnt_new,
               SUM(change_type = 'inactivated') AS cnt_removed,
               COUNT(*) AS cnt_total
        FROM insurance_member_history
        GROUP BY sync_id ORDER BY sync_id DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) { /* table may not exist yet */ }
?>

<style>
    .ins-upload-area { border: 2.5px dashed #bfdbfe; background: #eff6ff; border-radius: 24px; cursor: pointer; transition: all .2s; }
    .ins-upload-area:hover, .ins-upload-area.drag-over { border-color: #0052CC; background: #dbeafe; transform: scale(1.01); }
    .ins-badge { padding: 3px 10px; border-radius: 99px; font-size: 10px; font-weight: 900; letter-spacing: .05em; display: inline-block; }
    .badge-active   { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
    .badge-inactive { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .badge-staff    { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .badge-student  { background: #fef9c3; color: #ca8a04; border: 1px solid #fde68a; }
    .ins-stat-card  { background:#fff; border:1px solid #f1f5f9; border-radius:20px; padding:20px 24px; display:flex; align-items:center; gap:16px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
</style>

<div class="px-5 md:px-8 py-8 space-y-8">

    <!-- ── Header ── -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="sec-title" style="margin-bottom:4px">🛡️ Insurance Sync Hub</div>
            <p style="font-size:13px;color:#64748b">จัดการข้อมูลสิทธิ์ประกันสุขภาพของบุคลากรและนักศึกษา</p>
        </div>
        <!-- Visibility Toggle -->
        <div class="flex items-center gap-3 bg-white px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="flex flex-col text-right">
                <span class="text-[9px] font-black text-gray-400 uppercase tracking-widest leading-none mb-1">แสดงการ์ดให้ User</span>
                <span id="insVisibilityLabel" class="text-[10px] font-bold leading-none <?= defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE ? 'text-blue-600' : 'text-gray-400' ?>">
                    <?= defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE ? 'เปิดใช้งาน' : 'ปิดอยู่' ?>
                </span>
            </div>
            <label class="toggle">
                <input type="checkbox" id="insToggleVisibility"
                    <?= defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE ? 'checked' : '' ?>
                    onchange="updateInsVisibility(this)">
                <div class="toggle-track"></div>
                <div class="toggle-thumb"></div>
            </label>
        </div>
    </div>

    <!-- ── KPI Stat Cards ── -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="ins-stat-card">
            <div class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-500 flex items-center justify-center text-lg shrink-0">
                <i class="fa-solid fa-shield-check"></i>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">มีสิทธิ์ (Active)</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['total_active']) ?></p>
            </div>
        </div>
        <div class="ins-stat-card">
            <div class="w-11 h-11 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center text-lg shrink-0">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">บุคลากร</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['staff']) ?></p>
            </div>
        </div>
        <div class="ins-stat-card">
            <div class="w-11 h-11 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center text-lg shrink-0">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">นักศึกษา</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['student']) ?></p>
            </div>
        </div>
        <div class="ins-stat-card">
            <div class="w-11 h-11 rounded-2xl bg-rose-50 text-rose-400 flex items-center justify-center text-lg shrink-0">
                <i class="fa-solid fa-pen-to-square"></i>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">Manual Override</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['manual_override']) ?></p>
            </div>
        </div>
    </div>

    <!-- ── Upload + History (2-col) ── -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Upload (5/12) -->
        <div class="lg:col-span-5 bg-white rounded-[2rem] border border-slate-200 shadow-sm p-8 flex flex-col gap-6">
            <h2 class="text-base font-black text-slate-800">อัปโหลดไฟล์รายชื่อผู้มีสิทธิ์</h2>

            <!-- Step indicator -->
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <div id="stepDot1" class="w-7 h-7 rounded-full bg-[#0052CC] text-white text-[11px] font-black flex items-center justify-center">1</div>
                    <span class="text-xs font-black text-slate-600">เลือกไฟล์</span>
                </div>
                <div class="flex-1 h-px bg-slate-200"></div>
                <div class="flex items-center gap-2">
                    <div id="stepDot2" class="w-7 h-7 rounded-full bg-slate-100 text-slate-400 text-[11px] font-black flex items-center justify-center transition-all">2</div>
                    <span id="stepLabel2" class="text-xs font-black text-slate-400 transition-all">อัปโหลด</span>
                </div>
            </div>

            <!-- Column hint (collapsible) -->
            <details class="group">
                <summary class="cursor-pointer list-none flex items-center gap-2 text-xs font-black text-blue-600 select-none">
                    <i class="fa-solid fa-circle-info"></i> ดูรูปแบบคอลัมในไฟล์
                    <i class="fa-solid fa-chevron-down text-[9px] group-open:rotate-180 transition-transform ml-auto"></i>
                </summary>
                <div class="mt-3 bg-blue-50 border border-blue-100 rounded-2xl p-4 text-xs font-bold text-blue-700 space-y-1">
                    <div class="font-black text-blue-800 mb-2">คอลัมในไฟล์ CSV / Excel</div>
                    <div>• <code class="bg-blue-100 px-1 rounded">member_id</code> — รหัสสมาชิก <span class="text-rose-500 font-black">(บังคับ)</span></div>
                    <div>• <code class="bg-blue-100 px-1 rounded">member_status</code> — <code>บุคลากร</code> หรือ <code>นักศึกษา</code></div>
                    <div>• <code class="bg-blue-100 px-1 rounded">full_name</code>, <code class="bg-blue-100 px-1 rounded">citizen_id</code>, <code class="bg-blue-100 px-1 rounded">coverage_start</code>, <code class="bg-blue-100 px-1 rounded">coverage_end</code> ฯลฯ</div>
                </div>
            </details>

            <!-- Drop zone -->
            <div class="ins-upload-area flex flex-col items-center justify-center py-10 px-6" id="insUploadArea"
                 onclick="document.getElementById('insFileInput').click()"
                 ondragover="event.preventDefault();this.classList.add('drag-over')"
                 ondragleave="this.classList.remove('drag-over')"
                 ondrop="handleInsDrop(event)">
                <div class="w-14 h-14 bg-white rounded-3xl shadow-lg flex items-center justify-center text-blue-600 text-xl mb-3 border border-blue-50">
                    <i class="fa-solid fa-file-shield"></i>
                </div>
                <p class="text-sm font-black text-slate-700" id="insFileLabel">คลิกหรือลากไฟล์มาวางที่นี่</p>
                <p class="text-[11px] text-slate-400 mt-1">.csv, .xlsx, .xls</p>
            </div>
            <input type="file" id="insFileInput" accept=".csv,.xlsx,.xls" class="hidden" onchange="onInsFileSelect(this)">

            <!-- Last sync summary -->
            <?php if ($lastSync): ?>
            <div class="bg-slate-50 border border-slate-100 rounded-2xl px-4 py-3 flex items-center gap-3">
                <i class="fa-solid fa-clock-rotate-left text-slate-300 text-sm shrink-0"></i>
                <div class="text-xs text-slate-500 font-bold">
                    Sync ล่าสุด <span class="text-slate-700">#<?= $lastSync['sync_id'] ?></span>
                    <span class="mx-1.5 text-slate-300">·</span>
                    <?= htmlspecialchars($lastSync['sync_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <span class="mx-1.5 text-slate-300">·</span>
                    <span class="text-emerald-600">+<?= (int)$lastSync['cnt_new'] ?></span>
                    <span class="mx-1 text-slate-300">/</span>
                    <span class="text-rose-500">-<?= (int)$lastSync['cnt_removed'] ?></span>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-slate-50 border border-slate-100 rounded-2xl px-4 py-3 flex items-center gap-3">
                <i class="fa-solid fa-clock-rotate-left text-slate-200 text-sm shrink-0"></i>
                <p class="text-xs text-slate-400 font-bold">ยังไม่มีประวัติการ sync</p>
            </div>
            <?php endif; ?>

            <button id="insBtnUpload" onclick="doInsUpload()" disabled
                class="w-full h-14 bg-[#0052CC] text-white font-black rounded-2xl shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center justify-center gap-3 disabled:opacity-30 disabled:cursor-not-allowed">
                <i class="fa-solid fa-cloud-arrow-up"></i> อัปโหลดและอัปเดตข้อมูล
            </button>

            <div id="insUploadResult" class="hidden"></div>
        </div>

        <!-- History (7/12) -->
        <div class="lg:col-span-7 bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
            <div class="px-8 py-6 border-b border-slate-100 flex flex-wrap items-center gap-3 shrink-0">
                <div class="flex-1">
                    <h2 class="text-base font-black text-slate-800">ประวัติการ Sync</h2>
                    <p class="text-xs text-slate-400 font-bold mt-1">สรุปผลแต่ละครั้งที่อัปโหลดไฟล์</p>
                </div>
                <button onclick="loadInsHistory(1)" class="h-9 px-4 bg-slate-50 border border-slate-200 text-slate-500 rounded-xl font-black text-xs active:scale-95 transition-all flex items-center gap-2">
                    <i class="fa-solid fa-rotate"></i> รีเฟรช
                </button>
            </div>
            <div id="insHistoryResult" class="flex-1 min-h-[200px]"></div>
            <div id="insHistoryPager" class="px-8 py-4 flex justify-center border-t border-slate-100 hidden shrink-0"></div>
        </div>
    </div>

    <!-- ── Member List (full width) ── -->
    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-8 py-6 border-b border-slate-100 flex flex-wrap items-center gap-3">
            <h2 class="text-base font-black text-slate-800 flex-1">รายชื่อผู้ประกัน</h2>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                <input type="text" id="insMemberSearch" placeholder="ค้นหารหัส / ชื่อ / เลขบัตร..."
                    class="h-10 pl-9 pr-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none w-60">
            </div>
            <select id="insFilterType" onchange="loadInsMembers(1)" class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none">
                <option value="">ทุกประเภท</option>
                <option value="บุคลากร">บุคลากร</option>
                <option value="นักศึกษา">นักศึกษา</option>
            </select>
            <select id="insFilterStatus" onchange="loadInsMembers(1)" class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none">
                <option value="">ทุกสถานะ</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
            <button onclick="openInsMemberModal(null)" class="h-10 px-5 bg-slate-100 text-slate-600 rounded-xl font-black text-sm active:scale-95 transition-all flex items-center gap-2 hover:bg-slate-200">
                <i class="fa-solid fa-plus text-xs"></i> เพิ่มสมาชิก
            </button>
        </div>

        <div id="insMembersResult" class="min-h-[200px]"></div>
        <div id="insMembersPager" class="px-8 py-4 flex justify-center border-t border-slate-100 hidden"></div>
    </div>
</div>

<!-- ── Member Form Modal ─────────────────────────────────────────────────── -->
<div id="insMemberModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] w-full max-w-lg mx-4 shadow-2xl flex flex-col max-h-[90vh]">
        <div class="px-8 pt-7 pb-5 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 id="insMemberModalTitle" class="text-base font-black text-slate-900">เพิ่มสมาชิก</h3>
            <button onclick="closeInsMemberModal()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 px-8 py-6 space-y-4">
            <input type="hidden" id="imIsEdit" value="0">

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">รหัสสมาชิก <span class="text-rose-500">*</span></label>
                    <input type="text" id="imMemberId" placeholder="เช่น 6512345"
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
                <div class="col-span-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">ชื่อ-นามสกุล</label>
                    <input type="text" id="imFullName" placeholder="ชื่อ นามสกุล"
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">ประเภท</label>
                    <select id="imMemberStatus" class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                        <option value="">— ไม่ระบุ —</option>
                        <option value="บุคลากร">บุคลากร</option>
                        <option value="นักศึกษา">นักศึกษา</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">สถานะสิทธิ์</label>
                    <select id="imInsuranceStatus" class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                        <option value="Active">Active — มีสิทธิ์</option>
                        <option value="Inactive">Inactive — ไม่มีสิทธิ์</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">เลขบัตรประชาชน</label>
                    <input type="text" id="imCitizenId" maxlength="13" placeholder="13 หลัก"
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">เลขกรมธรรม์</label>
                    <input type="text" id="imPolicyNumber" placeholder="Policy No."
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">วันเริ่มคุ้มครอง</label>
                    <input type="date" id="imCoverageStart"
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">วันสิ้นสุดคุ้มครอง</label>
                    <input type="date" id="imCoverageEnd"
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
                <div class="col-span-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">หมายเหตุ</label>
                    <input type="text" id="imRemarks" placeholder="หมายเหตุ (ถ้ามี)"
                        class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                </div>
            </div>

            <div id="imError" class="hidden text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-4 py-3"></div>
        </div>
        <div class="px-8 pb-7 pt-4 border-t border-slate-100 flex gap-3 shrink-0">
            <button id="imBtnSave" onclick="saveInsMember()"
                class="flex-1 h-12 bg-[#0052CC] text-white font-black rounded-xl shadow-lg shadow-blue-200 active:scale-95 transition-all text-sm">
                บันทึก
            </button>
            <button onclick="closeInsMemberModal()" class="px-6 h-12 bg-slate-50 text-slate-500 font-black rounded-xl text-sm active:scale-95 transition-all">
                ยกเลิก
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
    const CSRF = '<?= $csrfToken ?>';
    let selectedFile = null;
    let searchDebounce = null;

    // ── Step indicator ──────────────────────────────────────────────────────────
    function setStep(step) {
        const dot2  = document.getElementById('stepDot2');
        const lbl2  = document.getElementById('stepLabel2');
        if (step >= 2) {
            dot2.className  = 'w-7 h-7 rounded-full bg-[#0052CC] text-white text-[11px] font-black flex items-center justify-center transition-all';
            lbl2.className  = 'text-xs font-black text-slate-700 transition-all';
        } else {
            dot2.className  = 'w-7 h-7 rounded-full bg-slate-100 text-slate-400 text-[11px] font-black flex items-center justify-center transition-all';
            lbl2.className  = 'text-xs font-black text-slate-400 transition-all';
        }
    }

    // ── File helpers ────────────────────────────────────────────────────────────
    async function excelToCSV(file) {
        if (!/\.(xlsx|xls)$/i.test(file.name)) return file;
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const wb  = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: true });
                    const csv = XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]], { blankrows: false });
                    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
                    resolve(new File([blob], file.name.replace(/\.(xlsx|xls)$/i, '.csv'), { type: 'text/csv' }));
                } catch (err) { reject(err); }
            };
            reader.readAsArrayBuffer(file);
        });
    }

    function setFile(file) {
        selectedFile = file;
        document.getElementById('insFileLabel').textContent = file.name;
        document.getElementById('insUploadArea').classList.add('border-blue-500', 'bg-blue-50');
        document.getElementById('insBtnUpload').disabled = false;
        setStep(2);
    }

    window.onInsFileSelect = (input) => { if (input.files[0]) setFile(input.files[0]); };
    window.handleInsDrop   = (e) => {
        e.preventDefault();
        document.getElementById('insUploadArea').classList.remove('drag-over');
        if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    };

    // ── Upload ──────────────────────────────────────────────────────────────────
    window.doInsUpload = async function () {
        if (!selectedFile) return;
        const btn       = document.getElementById('insBtnUpload');
        const resultDiv = document.getElementById('insUploadResult');
        btn.disabled    = true;
        btn.innerHTML   = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังประมวลผล...';
        resultDiv.classList.add('hidden');

        try {
            const file = await excelToCSV(selectedFile);
            const fd   = new FormData();
            fd.append('action', 'upload');
            fd.append('csrf_token', CSRF);
            fd.append('insurance_file', file);

            const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();

            resultDiv.classList.remove('hidden');
            if (data.status === 'ok') {
                resultDiv.innerHTML = `
                    <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-5 flex items-start gap-4">
                        <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 shrink-0 text-lg">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div>
                            <div class="font-black text-emerald-800 text-sm mb-1.5">อัปเดตสำเร็จ</div>
                            <div class="flex flex-wrap gap-2 text-xs font-bold">
                                <span class="px-2.5 py-1 bg-white border border-emerald-100 rounded-lg text-slate-600">ทั้งหมด <strong>${data.total_csv}</strong> รายการ</span>
                                <span class="px-2.5 py-1 bg-emerald-100 rounded-lg text-emerald-700">+${data.total_new} ใหม่</span>
                                <span class="px-2.5 py-1 bg-blue-100 rounded-lg text-blue-700">~${data.total_updated} อัปเดต</span>
                                <span class="px-2.5 py-1 bg-rose-100 rounded-lg text-rose-600">-${data.total_inactivated} ระงับ</span>
                                ${data.total_protected ? `<span class="px-2.5 py-1 bg-amber-100 rounded-lg text-amber-700">${data.total_protected} ป้องกัน</span>` : ''}
                            </div>
                        </div>
                    </div>`;
                selectedFile = null;
                document.getElementById('insFileInput').value    = '';
                document.getElementById('insFileLabel').textContent = 'คลิกหรือลากไฟล์มาวางที่นี่';
                document.getElementById('insUploadArea').classList.remove('border-blue-500', 'bg-blue-50');
                setStep(1);
                loadInsMembers(1);
                loadInsHistory(1);
            } else {
                resultDiv.innerHTML = `<div class="bg-rose-50 border border-rose-100 rounded-2xl p-5 text-sm font-bold text-rose-700">${data.message}</div>`;
            }
        } catch (err) {
            resultDiv.classList.remove('hidden');
            resultDiv.innerHTML = `<div class="bg-rose-50 border border-rose-100 rounded-2xl p-5 text-sm font-bold text-rose-700">เกิดข้อผิดพลาด: ${err.message}</div>`;
        } finally {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up mr-2"></i>อัปโหลดและอัปเดตข้อมูล';
        }
    };

    // ── Member List ─────────────────────────────────────────────────────────────
    window.loadInsMembers = async function (page) {
        const container = document.getElementById('insMembersResult');
        const pager     = document.getElementById('insMembersPager');
        container.innerHTML = '<div class="flex items-center justify-center py-16"><i class="fa-solid fa-spinner fa-spin text-3xl text-blue-200"></i></div>';
        pager.classList.add('hidden');

        const fd = new FormData();
        fd.append('action', 'list_members');
        fd.append('csrf_token', CSRF);
        fd.append('page', page);
        fd.append('search', document.getElementById('insMemberSearch').value);
        fd.append('filter_type', document.getElementById('insFilterType').value);
        fd.append('filter_status', document.getElementById('insFilterStatus').value);

        try {
            const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status !== 'ok') throw new Error(data.message);

            if (!data.members.length) {
                container.innerHTML = '<div class="text-center py-16 text-slate-400 font-bold">ไม่พบข้อมูล</div>';
                return;
            }

            const typeBadge = (s) => {
                if (s === 'บุคลากร') return '<span class="ins-badge badge-staff">บุคลากร</span>';
                if (s === 'นักศึกษา') return '<span class="ins-badge badge-student">นักศึกษา</span>';
                return s ? `<span class="ins-badge" style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0">${s}</span>` : '—';
            };
            const dateRange = (s, e) => (s || e) ? [s, e].filter(Boolean).join(' – ') : '—';

            container.innerHTML = `
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead><tr class="bg-slate-50/80 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="text-left px-6 py-4">รหัสสมาชิก</th>
                            <th class="text-left px-6 py-4">ชื่อ-นามสกุล</th>
                            <th class="text-center px-6 py-4">ประเภท</th>
                            <th class="text-center px-6 py-4">สิทธิ์</th>
                            <th class="text-center px-6 py-4">ระยะเวลาคุ้มครอง</th>
                            <th class="w-14"></th>
                        </tr></thead>
                        <tbody>${data.members.map(m => `
                            <tr class="hover:bg-slate-50 border-b border-slate-100">
                                <td class="px-6 py-4 font-mono text-xs font-black text-slate-400">${m.member_id}</td>
                                <td class="px-6 py-4 text-sm font-bold text-slate-800">
                                    <div>${m.full_name || '—'}</div>
                                    ${Number(m.manually_overridden || 0) === 1 ? '<div class="mt-1 text-[9px] font-black uppercase tracking-widest text-amber-600">Manual override</div>' : ''}
                                </td>
                                <td class="px-6 py-4 text-center">${typeBadge(m.member_status)}</td>
                                <td class="px-6 py-4 text-center">
                                    <span class="ins-badge badge-${m.insurance_status === 'Active' ? 'active' : 'inactive'}">${m.insurance_status}</span>
                                </td>
                                <td class="px-6 py-4 text-center text-xs text-slate-500 font-bold">${dateRange(m.coverage_start, m.coverage_end)}</td>
                                <td class="px-6 py-4 text-right">
                                    <button onclick='openInsMemberModal(${JSON.stringify(m)})'
                                        class="h-8 px-3 bg-slate-100 hover:bg-blue-50 hover:text-blue-600 text-slate-500 rounded-lg text-xs font-black transition-colors">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                </td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                </div>`;

            const totalPages = Math.ceil(data.total / data.per_page);
            if (totalPages > 1 || data.total > 0) {
                let ph = `<div class="flex items-center gap-2 flex-wrap justify-center">`;
                ph += `<span class="text-xs font-bold text-slate-400 mr-2">หน้า ${page} / ${totalPages} · รวม ${Number(data.total).toLocaleString()} รายการ</span>`;
                if (page > 1)          ph += `<button onclick="loadInsMembers(1)" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">«</button>`;
                if (page > 1)          ph += `<button onclick="loadInsMembers(${page-1})" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">‹</button>`;
                for (let i = Math.max(1, page-2); i <= Math.min(totalPages, page+2); i++) {
                    ph += `<button onclick="loadInsMembers(${i})" class="w-9 h-9 rounded-xl font-black text-xs ${i === page ? 'bg-[#0052CC] text-white shadow-lg shadow-blue-200' : 'bg-white border border-slate-200 text-slate-400 hover:bg-slate-50'}">${i}</button>`;
                }
                if (page < totalPages) ph += `<button onclick="loadInsMembers(${page+1})" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">›</button>`;
                if (page < totalPages) ph += `<button onclick="loadInsMembers(${totalPages})" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">»</button>`;
                ph += '</div>';
                pager.innerHTML = ph;
                pager.classList.remove('hidden');
            }
        } catch (err) {
            container.innerHTML = `<p class="text-rose-500 font-bold p-8">Error: ${err.message}</p>`;
        }
    };

    // Debounce search — real-time after 350ms
    document.getElementById('insMemberSearch').addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => loadInsMembers(1), 350);
    });

    // ── History ─────────────────────────────────────────────────────────────────
    window.loadInsHistory = async function(page = 1) {
        const container = document.getElementById('insHistoryResult');
        const pager     = document.getElementById('insHistoryPager');
        container.innerHTML = '<div class="p-8 text-center text-slate-400 font-bold">กำลังโหลดประวัติ...</div>';
        pager.classList.add('hidden');

        const fd = new FormData();
        fd.append('action', 'list_history');
        fd.append('csrf_token', CSRF);
        fd.append('page', page);

        try {
            const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status !== 'ok') throw new Error(data.message);

            if (!data.history.length) {
                container.innerHTML = '<div class="text-center py-12 text-slate-400 font-bold">ยังไม่มีประวัติการอัปเดต</div>';
                return;
            }

            const fmt = (v) => Number(v || 0);

            container.innerHTML = `
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead><tr class="bg-slate-50/80 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="text-left px-6 py-4">Sync</th>
                            <th class="text-left px-6 py-4">เวลา</th>
                            <th class="text-center px-6 py-4">เพิ่มใหม่</th>
                            <th class="text-center px-6 py-4">ระงับสิทธิ์</th>
                            <th class="text-center px-6 py-4">อัปเดต</th>
                            <th class="text-center px-6 py-4">ป้องกัน</th>
                            <th class="text-center px-6 py-4">รวม</th>
                        </tr></thead>
                        <tbody>${data.history.map(h => `
                            <tr class="hover:bg-slate-50/60 border-b border-slate-100">
                                <td class="px-6 py-5">
                                    <span class="text-xs font-black text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg">#${h.sync_id}</span>
                                </td>
                                <td class="px-6 py-5 text-xs font-bold text-slate-500 whitespace-nowrap">${h.sync_time || '-'}</td>
                                <td class="px-6 py-5 text-center">
                                    ${fmt(h.cnt_new) > 0 ? `<span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-sm font-black bg-emerald-50 text-emerald-600 border border-emerald-100">+${fmt(h.cnt_new)} คน</span>` : `<span class="text-slate-200 font-black text-sm">—</span>`}
                                </td>
                                <td class="px-6 py-5 text-center">
                                    ${fmt(h.cnt_removed) > 0 ? `<span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-sm font-black bg-rose-50 text-rose-500 border border-rose-100">-${fmt(h.cnt_removed)} คน</span>` : `<span class="text-slate-200 font-black text-sm">—</span>`}
                                </td>
                                <td class="px-6 py-5 text-center">
                                    ${fmt(h.cnt_updated) > 0 ? `<span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-sm font-black bg-blue-50 text-blue-600 border border-blue-100">${fmt(h.cnt_updated)} รายการ</span>` : `<span class="text-slate-200 font-black text-sm">—</span>`}
                                </td>
                                <td class="px-6 py-5 text-center">
                                    ${fmt(h.cnt_protected) > 0 ? `<span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-sm font-black bg-amber-50 text-amber-600 border border-amber-100">${fmt(h.cnt_protected)} รายการ</span>` : `<span class="text-slate-200 font-black text-sm">—</span>`}
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <span class="text-sm font-black text-slate-600">${fmt(h.cnt_total).toLocaleString()}</span>
                                </td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                </div>`;

            const totalPages = Math.ceil(data.total / data.per_page);
            if (totalPages > 1) {
                let ph = `<div class="flex items-center gap-2 flex-wrap justify-center">`;
                ph += `<span class="text-xs font-bold text-slate-400 mr-2">หน้า ${page} / ${totalPages} · รวม ${data.total} ครั้ง</span>`;
                if (page > 1)          ph += `<button onclick="loadInsHistory(1)" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">«</button>`;
                if (page > 1)          ph += `<button onclick="loadInsHistory(${page-1})" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">‹</button>`;
                for (let i = Math.max(1, page-2); i <= Math.min(totalPages, page+2); i++) {
                    ph += `<button onclick="loadInsHistory(${i})" class="w-9 h-9 rounded-xl font-black text-xs ${i === page ? 'bg-[#0052CC] text-white shadow-lg shadow-blue-200' : 'bg-white border border-slate-200 text-slate-400 hover:bg-slate-50'}">${i}</button>`;
                }
                if (page < totalPages) ph += `<button onclick="loadInsHistory(${page+1})" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">›</button>`;
                if (page < totalPages) ph += `<button onclick="loadInsHistory(${totalPages})" class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 hover:bg-slate-50 font-black text-xs">»</button>`;
                ph += '</div>';
                pager.innerHTML = ph;
                pager.classList.remove('hidden');
            }
        } catch (err) {
            container.innerHTML = `<p class="text-rose-500 font-bold p-8">Error: ${err.message}</p>`;
        }
    };

    // ── Member Modal ─────────────────────────────────────────────────────────────
    window.openInsMemberModal = function(member) {
        const isEdit = member !== null;
        document.getElementById('insMemberModalTitle').textContent         = isEdit ? 'แก้ไขข้อมูลสมาชิก' : 'เพิ่มสมาชิก';
        document.getElementById('imIsEdit').value                          = isEdit ? '1' : '0';
        document.getElementById('imMemberId').value                        = member?.member_id        ?? '';
        document.getElementById('imMemberId').readOnly                     = isEdit;
        document.getElementById('imMemberId').classList.toggle('opacity-50', isEdit);
        document.getElementById('imFullName').value                        = member?.full_name        ?? '';
        document.getElementById('imMemberStatus').value                    = member?.member_status    ?? '';
        document.getElementById('imInsuranceStatus').value                 = member?.insurance_status ?? 'Active';
        document.getElementById('imCitizenId').value                       = member?.citizen_id       ?? '';
        document.getElementById('imPolicyNumber').value                    = member?.policy_number    ?? '';
        document.getElementById('imCoverageStart').value                   = member?.coverage_start   ?? '';
        document.getElementById('imCoverageEnd').value                     = member?.coverage_end     ?? '';
        document.getElementById('imRemarks').value                         = member?.remarks          ?? '';
        document.getElementById('imError').classList.add('hidden');
        document.getElementById('insMemberModal').classList.replace('hidden', 'flex');
    };

    window.closeInsMemberModal = () => document.getElementById('insMemberModal').classList.replace('flex', 'hidden');

    window.saveInsMember = async function() {
        const btn    = document.getElementById('imBtnSave');
        const errDiv = document.getElementById('imError');
        const mid    = document.getElementById('imMemberId').value.trim();
        if (!mid) { errDiv.textContent = 'กรุณาระบุรหัสสมาชิก'; errDiv.classList.remove('hidden'); return; }

        btn.disabled = true; btn.textContent = 'กำลังบันทึก...';
        errDiv.classList.add('hidden');

        const fd = new FormData();
        fd.append('action',           'save_member');
        fd.append('csrf_token',       CSRF);
        fd.append('member_id',        mid);
        fd.append('is_edit',          document.getElementById('imIsEdit').value);
        fd.append('full_name',        document.getElementById('imFullName').value.trim());
        fd.append('member_status',    document.getElementById('imMemberStatus').value);
        fd.append('insurance_status', document.getElementById('imInsuranceStatus').value);
        fd.append('citizen_id',       document.getElementById('imCitizenId').value.trim());
        fd.append('policy_number',    document.getElementById('imPolicyNumber').value.trim());
        fd.append('coverage_start',   document.getElementById('imCoverageStart').value);
        fd.append('coverage_end',     document.getElementById('imCoverageEnd').value);
        fd.append('remarks',          document.getElementById('imRemarks').value.trim());

        try {
            const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'ok') {
                closeInsMemberModal();
                loadInsMembers(1);
            } else {
                errDiv.textContent = data.message;
                errDiv.classList.remove('hidden');
            }
        } catch (err) {
            errDiv.textContent = 'เกิดข้อผิดพลาด: ' + err.message;
            errDiv.classList.remove('hidden');
        } finally {
            btn.disabled = false; btn.textContent = 'บันทึก';
        }
    };

    window.updateInsVisibility = function(cb) {
        const fd = new FormData();
        fd.append('action', 'set_visibility');
        fd.append('csrf_token', CSRF);
        fd.append('active', cb.checked ? '1' : '0');
        fetch('ajax_insurance_sync.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                const lbl = document.getElementById('insVisibilityLabel');
                if (data.status === 'ok') {
                    lbl.textContent = cb.checked ? 'เปิดใช้งาน' : 'ปิดอยู่';
                    lbl.className   = `text-[10px] font-bold leading-none ${cb.checked ? 'text-blue-600' : 'text-gray-400'}`;
                } else { cb.checked = !cb.checked; alert(data.message); }
            });
    };

    loadInsHistory(1);
    loadInsMembers(1);
})();
</script>
