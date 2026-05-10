<?php
/**
 * portal/_partials/gold_card.php — Gold Card Management
 *
 * จัดการสมาชิกบัตรทอง + เอกสารแนบหลายไฟล์/คน + workflow สถานะ
 *
 * โหลดผ่าน portal/index.php?section=gold_card
 * AJAX: portal/ajax_gold_card.php (entity:action pattern)
 */
declare(strict_types=1);

$pdo = db();
$csrfToken = get_csrf_token();

$stats = ['total'=>0,'approved'=>0,'pending'=>0,'rejected'=>0,'expiring'=>0,'staff'=>0,'student'=>0];
try {
    $r = $pdo->query("
        SELECT
            COUNT(*)                                                          AS total,
            SUM(status IN ('approved','active'))                              AS approved,
            SUM(status IN ('pending','submitted'))                            AS pending,
            SUM(status = 'rejected')                                          AS rejected,
            SUM(status = 'active' AND coverage_end BETWEEN CURDATE()
                AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))                     AS expiring,
            SUM(member_type = 'บุคลากร')                                       AS staff,
            SUM(member_type = 'นักศึกษา')                                      AS student
        FROM gold_card_members
    ")->fetch(PDO::FETCH_ASSOC);
    if ($r) $stats = array_map('intval', $r);
} catch (PDOException $e) { /* table not migrated yet */ }
?>

<style>
    #section-gold_card .gc-stat-card {
        background:#fff; border:1.5px solid #f1f5f9; border-radius:20px;
        padding:18px 22px; display:flex; align-items:center; gap:14px;
        box-shadow:0 1px 3px rgba(0,0,0,.04); transition:all .15s;
    }
    #section-gold_card .gc-stat-card:hover { transform: translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,.06); }
    #section-gold_card .gc-icon-tile {
        width:44px; height:44px; border-radius:14px;
        display:flex; align-items:center; justify-content:center;
        font-size:18px; flex-shrink:0;
    }
    #section-gold_card .gc-badge {
        padding:3px 10px; border-radius:99px; font-size:10px;
        font-weight:900; letter-spacing:.05em; display:inline-block;
    }
    #section-gold_card .gc-badge-pending  { background:#fef9c3; color:#a16207; border:1px solid #fde68a; }
    #section-gold_card .gc-badge-submitted{ background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; }
    #section-gold_card .gc-badge-approved { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
    #section-gold_card .gc-badge-active   { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
    #section-gold_card .gc-badge-rejected { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
    #section-gold_card .gc-badge-expired  { background:#f3f4f6; color:#6b7280; border:1px solid #e5e7eb; }
    #section-gold_card .gc-pager-btn {
        min-width:34px; height:34px; padding:0 10px;
        border-radius:9px; border:1.5px solid #e2e8f0; background:#fff;
        color:#475569; font-weight:800; font-size:12px; cursor:pointer;
        display:inline-flex; align-items:center; justify-content:center;
        transition:all .15s;
    }
    #section-gold_card .gc-pager-btn:hover:not(:disabled) { background:#f1f5f9; }
    #section-gold_card .gc-pager-btn:disabled { opacity:.4; cursor:not-allowed; }
    #section-gold_card .gc-pager-btn.gc-active { background:#f59e0b; color:#fff; border-color:#f59e0b; }
    #section-gold_card .gc-modal { z-index:200; }
    #section-gold_card .gc-modal-box { max-height: 90vh; }
    #section-gold_card .gc-tab {
        padding:8px 16px; border-radius:10px; font-size:12px; font-weight:800;
        color:#64748b; cursor:pointer; transition:all .15s; background:transparent; border:none;
    }
    #section-gold_card .gc-tab:hover { background:#f1f5f9; color:#1e293b; }
    #section-gold_card .gc-tab.gc-active-tab { background:#f59e0b; color:#fff; }
    #section-gold_card .gc-doc-card {
        background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;
        padding:14px; display:flex; gap:12px; align-items:center;
    }
    #section-gold_card .gc-doc-icon {
        width:42px; height:42px; border-radius:10px;
        display:flex; align-items:center; justify-content:center;
        font-size:18px; flex-shrink:0;
    }
    #section-gold_card .gc-dropzone {
        border:2.5px dashed #fcd34d; background:#fffbeb;
        border-radius:18px; padding:30px; text-align:center;
        cursor:pointer; transition:all .15s; color:#92400e;
    }
    #section-gold_card .gc-dropzone:hover, #section-gold_card .gc-dropzone.gc-drag-over {
        border-color:#f59e0b; background:#fef3c7; transform:scale(1.01);
    }
</style>

<div id="section-gold_card-content" class="px-5 md:px-8 py-8 space-y-7">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-800 flex items-center gap-3">
                <span class="text-3xl">🪪</span> บัตรทอง (Universal Coverage)
            </h1>
            <p class="text-sm text-slate-500 font-bold mt-1">จัดการผู้มีสิทธิ์บัตรทอง · เอกสารสมัคร · สถานะอนุมัติ</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="gcOpenMemberModal(null)" class="h-11 px-5 bg-amber-500 hover:bg-amber-600 text-white font-black rounded-2xl shadow-lg shadow-amber-200 active:scale-95 transition-all flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> เพิ่มสมาชิก
            </button>
        </div>
    </div>

    <!-- ── KPI Cards (5 ใบ) ───────────────────────────────────────────── -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
        <div class="gc-stat-card">
            <div class="gc-icon-tile bg-amber-50 text-amber-500"><i class="fa-solid fa-id-card"></i></div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">ทั้งหมด</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['total']) ?></p>
            </div>
        </div>
        <div class="gc-stat-card">
            <div class="gc-icon-tile bg-emerald-50 text-emerald-500"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">อนุมัติแล้ว</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['approved']) ?></p>
            </div>
        </div>
        <div class="gc-stat-card">
            <div class="gc-icon-tile bg-blue-50 text-blue-500"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">รอ/ส่งแล้ว</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['pending']) ?></p>
            </div>
        </div>
        <div class="gc-stat-card">
            <div class="gc-icon-tile bg-rose-50 text-rose-500"><i class="fa-solid fa-circle-xmark"></i></div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">ไม่ผ่าน</p>
                <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($stats['rejected']) ?></p>
            </div>
        </div>
        <div class="gc-stat-card <?= $stats['expiring'] > 0 ? 'border-amber-300 bg-amber-50/50' : '' ?>">
            <div class="gc-icon-tile <?= $stats['expiring'] > 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-50 text-slate-300' ?>"><i class="fa-solid fa-clock"></i></div>
            <div>
                <p class="text-[10px] font-black <?= $stats['expiring'] > 0 ? 'text-amber-600' : 'text-slate-400' ?> uppercase tracking-widest leading-none mb-1">ใกล้หมด ≤30วัน</p>
                <p class="text-2xl font-black leading-none <?= $stats['expiring'] > 0 ? 'text-amber-700' : 'text-slate-800' ?>"><?= number_format($stats['expiring']) ?></p>
            </div>
        </div>
    </div>

    <!-- ── Charts row (Trend + Hospital Bar) ──────────────────────────── -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-black text-slate-800">แนวโน้มการลงทะเบียน</h3>
                    <p class="text-xs text-slate-400 font-bold mt-0.5">12 เดือนล่าสุด</p>
                </div>
                <i class="fa-solid fa-chart-line text-amber-400"></i>
            </div>
            <div style="height:240px"><canvas id="gcTrendChart"></canvas></div>
        </div>
        <div class="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-black text-slate-800">Top รพ.หลัก</h3>
                    <p class="text-xs text-slate-400 font-bold mt-0.5">10 อันดับแรก</p>
                </div>
                <i class="fa-solid fa-hospital text-amber-400"></i>
            </div>
            <div style="height:240px"><canvas id="gcHospChart"></canvas></div>
        </div>
    </div>

    <!-- ── Search/Filter + Table ──────────────────────────────────────── -->
    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 flex flex-wrap items-center gap-3">
            <h3 class="text-base font-black text-slate-800 flex-1 min-w-[150px]">รายชื่อผู้มีสิทธิ์</h3>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                <input type="text" id="gcSearch" placeholder="ค้นหาชื่อ/เลขบัตร/เบอร์..."
                    class="h-10 pl-9 pr-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none w-60">
            </div>
            <select id="gcFilterType" onchange="gcLoadMembers(1)"
                class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none">
                <option value="">ทุกประเภท</option>
                <option value="บุคลากร">บุคลากร</option>
                <option value="นักศึกษา">นักศึกษา</option>
                <option value="บุคคลทั่วไป">บุคคลทั่วไป</option>
            </select>
            <select id="gcFilterStatus" onchange="gcLoadMembers(1)"
                class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none">
                <option value="">ทุกสถานะ</option>
                <option value="pending">รอเอกสาร</option>
                <option value="submitted">ส่งแล้ว</option>
                <option value="approved">อนุมัติ</option>
                <option value="active">ใช้งาน</option>
                <option value="rejected">ไม่ผ่าน</option>
                <option value="expired">หมดอายุ</option>
            </select>
        </div>

        <div id="gcTableWrap" class="min-h-[200px]"></div>
        <div id="gcPagerWrap" class="px-6 py-4 flex items-center justify-between border-t border-slate-100 hidden flex-wrap gap-3">
            <div id="gcPagerInfo" class="text-xs font-bold text-slate-500"></div>
            <div id="gcPager" class="flex items-center gap-1.5"></div>
        </div>
    </div>
</div>

<!-- ════════════ MEMBER MODAL (Add / Edit / Detail) ════════════ -->
<div id="gcMemberModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 backdrop-blur-sm gc-modal">
    <div class="bg-white rounded-[2rem] w-full max-w-3xl mx-4 shadow-2xl flex flex-col gc-modal-box">
        <div class="px-7 pt-6 pb-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 id="gcMemberModalTitle" class="text-lg font-black text-slate-900">เพิ่มสมาชิกบัตรทอง</h3>
            <button onclick="gcCloseMemberModal()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Tabs (เฉพาะตอน edit) -->
        <div id="gcTabs" class="px-7 pt-3 flex gap-2 border-b border-slate-100 hidden shrink-0">
            <button class="gc-tab gc-active-tab" data-tab="info" onclick="gcSwitchTab('info')">📋 ข้อมูล</button>
            <button class="gc-tab" data-tab="docs" onclick="gcSwitchTab('docs')">📎 เอกสาร <span id="gcDocCountBadge" class="ml-1 px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full text-[10px] font-black"></span></button>
            <button class="gc-tab" data-tab="hist" onclick="gcSwitchTab('hist')">🕘 ประวัติ</button>
        </div>

        <div class="overflow-y-auto flex-1 px-7 py-5">
            <input type="hidden" id="gcMemberId" value="0">

            <!-- Tab: Info -->
            <div id="gcTabInfo" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">เลขบัตรประชาชน <span class="text-rose-500">*</span></label>
                        <input type="text" id="gcCitizenId" maxlength="13" placeholder="13 หลัก"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                        <input type="text" id="gcFullName" placeholder="ชื่อ นามสกุล"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">ประเภทผู้สมัคร</label>
                        <select id="gcMemberType"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                            <option value="บุคคลทั่วไป">บุคคลทั่วไป</option>
                            <option value="บุคลากร">บุคลากร</option>
                            <option value="นักศึกษา">นักศึกษา</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">เบอร์โทร</label>
                        <input type="text" id="gcPhone" placeholder="0XXXXXXXXX"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">รพ.หลัก</label>
                        <input type="text" id="gcHospMain" placeholder="ชื่อโรงพยาบาลหลัก"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">รพ.รอง (ถ้ามี)</label>
                        <input type="text" id="gcHospSub" placeholder="ชื่อโรงพยาบาลรอง"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">วันสมัคร</label>
                        <input type="date" id="gcApplyDate"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">สถานะ</label>
                        <select id="gcStatus"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                            <option value="pending">รอเอกสาร</option>
                            <option value="submitted">ส่งแล้ว</option>
                            <option value="approved">อนุมัติ</option>
                            <option value="active">ใช้งาน</option>
                            <option value="rejected">ไม่ผ่าน</option>
                            <option value="expired">หมดอายุ</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">วันเริ่มคุ้มครอง</label>
                        <input type="date" id="gcCovStart"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">วันสิ้นสุดคุ้มครอง</label>
                        <input type="date" id="gcCovEnd"
                            class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">หมายเหตุ</label>
                        <textarea id="gcRemarks" rows="2"
                            class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-amber-500/10 outline-none bg-slate-50"></textarea>
                    </div>
                </div>
            </div>

            <!-- Tab: Documents -->
            <div id="gcTabDocs" class="space-y-4 hidden">
                <div class="gc-dropzone" id="gcDropzone" onclick="document.getElementById('gcDocFile').click()">
                    <i class="fa-solid fa-cloud-arrow-up text-3xl mb-2"></i>
                    <p class="text-sm font-black">ลากไฟล์มาวาง / คลิกเพื่อเลือก</p>
                    <p class="text-xs font-bold mt-1 text-amber-600">PDF / JPG / PNG / DOC · ≤20MB</p>
                </div>
                <input type="file" id="gcDocFile" class="hidden" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" onchange="gcUploadDoc(this.files[0])">
                <div class="flex items-center gap-3">
                    <label class="text-xs font-black text-slate-500 uppercase tracking-widest">ประเภท:</label>
                    <select id="gcDocType" class="h-9 px-3 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold outline-none">
                        <option value="id_copy">สำเนาบัตรประชาชน</option>
                        <option value="house_reg">สำเนาทะเบียนบ้าน</option>
                        <option value="application">ใบสมัคร</option>
                        <option value="photo">รูปถ่าย</option>
                        <option value="medical">ใบรับรองแพทย์</option>
                        <option value="other">อื่นๆ</option>
                    </select>
                </div>
                <div id="gcDocList" class="space-y-2"></div>
            </div>

            <!-- Tab: History -->
            <div id="gcTabHist" class="hidden">
                <div id="gcHistList" class="space-y-2"></div>
            </div>

            <div id="gcMemberError" class="hidden mt-4 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-4 py-3"></div>
        </div>

        <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex gap-3 shrink-0">
            <button onclick="gcCloseMemberModal()" class="flex-1 h-11 bg-white border border-slate-200 text-slate-600 font-black rounded-xl text-sm hover:bg-slate-100 transition-colors">ยกเลิก</button>
            <button id="gcDeleteBtn" onclick="gcDeleteMember()" class="hidden h-11 px-4 bg-rose-50 border border-rose-200 text-rose-600 font-black rounded-xl text-sm hover:bg-rose-100 transition-colors">
                <i class="fa-solid fa-trash"></i> ลบ
            </button>
            <button onclick="gcSaveMember()" class="flex-2 h-11 px-6 bg-amber-500 hover:bg-amber-600 text-white font-black rounded-xl text-sm shadow-lg shadow-amber-200 transition-all flex items-center justify-center gap-2" style="flex:2">
                <i class="fa-solid fa-floppy-disk"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    const CSRF = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
    const ENDPOINT = 'ajax_gold_card.php';
    const PAGE_SIZE = 20;
    let currentPage = 1;

    // ── helpers ──────────────────────────────────────────────────────
    function gcPost(entity, action, extra = {}, isFormData = false) {
        let body;
        if (isFormData) {
            body = extra;
            body.append('entity', entity);
            body.append('action', action);
            body.append('csrf_token', CSRF);
        } else {
            body = new FormData();
            body.append('entity', entity);
            body.append('action', action);
            body.append('csrf_token', CSRF);
            for (const [k, v] of Object.entries(extra)) body.append(k, v);
        }
        return fetch(ENDPOINT, { method: 'POST', body, credentials: 'same-origin' })
            .then(r => r.json());
    }

    const STATUS_LABELS = {
        pending: 'รอเอกสาร', submitted: 'ส่งแล้ว', approved: 'อนุมัติ',
        active: 'ใช้งาน', rejected: 'ไม่ผ่าน', expired: 'หมดอายุ'
    };
    const DOC_TYPE_LABELS = {
        id_copy: 'สำเนาบัตรประชาชน', house_reg: 'สำเนาทะเบียนบ้าน',
        application: 'ใบสมัคร', photo: 'รูปถ่าย', medical: 'ใบรับรองแพทย์', other: 'อื่นๆ'
    };

    function statusBadge(s) {
        return `<span class="gc-badge gc-badge-${s}">${STATUS_LABELS[s] || s}</span>`;
    }

    // ── load members table ──────────────────────────────────────────
    window.gcLoadMembers = function(page) {
        currentPage = page || 1;
        const search = document.getElementById('gcSearch').value.trim();
        const type   = document.getElementById('gcFilterType').value;
        const status = document.getElementById('gcFilterStatus').value;

        gcPost('member', 'list', { page: currentPage, page_size: PAGE_SIZE, search, type, status })
            .then(r => {
                if (r.status !== 'ok') {
                    document.getElementById('gcTableWrap').innerHTML = `<div class="p-12 text-center text-rose-500 font-bold">${r.message || 'โหลดไม่สำเร็จ'}</div>`;
                    return;
                }
                renderTable(r.rows, r.total, r.page, r.pages);
            });
    };

    function renderTable(rows, total, page, pages) {
        const wrap = document.getElementById('gcTableWrap');
        if (!rows.length) {
            wrap.innerHTML = `<div class="p-16 text-center text-slate-400 font-bold">
                <i class="fa-solid fa-folder-open text-4xl mb-3 opacity-40"></i>
                <p class="text-sm">ยังไม่มีรายชื่อ</p>
                <p class="text-xs mt-1 text-slate-300">คลิก "เพิ่มสมาชิก" ด้านบนเพื่อเริ่มต้น</p>
            </div>`;
            document.getElementById('gcPagerWrap').classList.add('hidden');
            return;
        }
        let html = `<div class="overflow-x-auto"><table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-200 text-[10px] font-black text-slate-500 uppercase tracking-widest">
                    <th class="px-5 py-3 text-left">ชื่อ-นามสกุล</th>
                    <th class="px-3 py-3 text-left">เลขบัตร ปชช.</th>
                    <th class="px-3 py-3 text-left">ประเภท</th>
                    <th class="px-3 py-3 text-left">รพ.หลัก</th>
                    <th class="px-3 py-3 text-center">สถานะ</th>
                    <th class="px-3 py-3 text-center">เอกสาร</th>
                    <th class="px-5 py-3 text-right">จัดการ</th>
                </tr>
            </thead><tbody>`;
        rows.forEach(r => {
            const cid = r.citizen_id || '';
            const cidMask = cid.length === 13 ? `${cid.substr(0,1)}-${cid.substr(1,4)}-${cid.substr(5,5)}-${cid.substr(10,2)}-${cid.substr(12,1)}` : cid;
            html += `<tr class="border-b border-slate-100 hover:bg-amber-50/30 transition-colors">
                <td class="px-5 py-3 font-black text-slate-800">${escapeHtml(r.full_name)}</td>
                <td class="px-3 py-3 font-mono text-xs text-slate-500">${escapeHtml(cidMask)}</td>
                <td class="px-3 py-3 text-slate-600 text-xs font-bold">${escapeHtml(r.member_type || '-')}</td>
                <td class="px-3 py-3 text-slate-600 text-xs font-bold">${escapeHtml(r.hospital_main || '-')}</td>
                <td class="px-3 py-3 text-center">${statusBadge(r.status)}</td>
                <td class="px-3 py-3 text-center">
                    ${r.doc_count > 0 ? `<span class="inline-flex items-center gap-1 text-amber-600 font-black text-xs"><i class="fa-solid fa-paperclip"></i> ${r.doc_count}</span>` : '<span class="text-slate-300 text-xs">—</span>'}
                </td>
                <td class="px-5 py-3 text-right">
                    <button onclick="gcOpenMemberModal(${r.id})" class="px-3 py-1.5 bg-amber-50 text-amber-600 hover:bg-amber-100 rounded-lg text-xs font-black transition-colors">
                        <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
        renderPager(page, pages, total);
    }

    function renderPager(page, pages, total) {
        const wrap = document.getElementById('gcPagerWrap');
        const info = document.getElementById('gcPagerInfo');
        const pager = document.getElementById('gcPager');
        if (pages <= 1) { wrap.classList.add('hidden'); return; }
        wrap.classList.remove('hidden');
        wrap.classList.add('flex');

        info.textContent = `หน้า ${page} / ${pages} · รวม ${total.toLocaleString()} รายการ`;

        let html = `<button class="gc-pager-btn" onclick="gcLoadMembers(1)" ${page<=1?'disabled':''}>«</button>
                    <button class="gc-pager-btn" onclick="gcLoadMembers(${page-1})" ${page<=1?'disabled':''}>‹</button>`;
        const start = Math.max(1, page - 2);
        const end   = Math.min(pages, page + 2);
        if (start > 1) html += `<span class="text-slate-300 px-1">…</span>`;
        for (let i = start; i <= end; i++) {
            html += `<button class="gc-pager-btn ${i===page?'gc-active':''}" onclick="gcLoadMembers(${i})">${i}</button>`;
        }
        if (end < pages) html += `<span class="text-slate-300 px-1">…</span>`;
        html += `<button class="gc-pager-btn" onclick="gcLoadMembers(${page+1})" ${page>=pages?'disabled':''}>›</button>
                 <button class="gc-pager-btn" onclick="gcLoadMembers(${pages})" ${page>=pages?'disabled':''}>»</button>`;
        pager.innerHTML = html;
    }

    // ── modal ────────────────────────────────────────────────────────
    window.gcOpenMemberModal = function(id) {
        const modal = document.getElementById('gcMemberModal');
        document.getElementById('gcMemberId').value = id || 0;
        document.getElementById('gcMemberError').classList.add('hidden');
        gcSwitchTab('info');

        if (id) {
            document.getElementById('gcMemberModalTitle').textContent = 'แก้ไขสมาชิก';
            document.getElementById('gcDeleteBtn').classList.remove('hidden');
            document.getElementById('gcTabs').classList.remove('hidden');
            document.getElementById('gcTabs').classList.add('flex');
            gcPost('member', 'get', { id }).then(r => {
                if (r.status !== 'ok') { Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message}); return; }
                const m = r.member;
                document.getElementById('gcCitizenId').value = m.citizen_id || '';
                document.getElementById('gcFullName').value  = m.full_name || '';
                document.getElementById('gcMemberType').value = m.member_type || 'บุคคลทั่วไป';
                document.getElementById('gcPhone').value     = m.phone || '';
                document.getElementById('gcHospMain').value  = m.hospital_main || '';
                document.getElementById('gcHospSub').value   = m.hospital_sub || '';
                document.getElementById('gcApplyDate').value = m.application_date || '';
                document.getElementById('gcStatus').value    = m.status || 'pending';
                document.getElementById('gcCovStart').value  = m.coverage_start || '';
                document.getElementById('gcCovEnd').value    = m.coverage_end || '';
                document.getElementById('gcRemarks').value   = m.remarks || '';
                renderDocs(r.documents || []);
                renderHist(r.history || []);
            });
        } else {
            document.getElementById('gcMemberModalTitle').textContent = 'เพิ่มสมาชิกบัตรทอง';
            document.getElementById('gcDeleteBtn').classList.add('hidden');
            document.getElementById('gcTabs').classList.add('hidden');
            document.getElementById('gcTabs').classList.remove('flex');
            ['gcCitizenId','gcFullName','gcPhone','gcHospMain','gcHospSub','gcApplyDate','gcCovStart','gcCovEnd','gcRemarks']
                .forEach(k => document.getElementById(k).value = '');
            document.getElementById('gcMemberType').value = 'บุคคลทั่วไป';
            document.getElementById('gcStatus').value = 'pending';
        }
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    window.gcCloseMemberModal = function() {
        const modal = document.getElementById('gcMemberModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    window.gcSwitchTab = function(tab) {
        document.querySelectorAll('#gcTabs .gc-tab').forEach(b => b.classList.toggle('gc-active-tab', b.dataset.tab === tab));
        document.getElementById('gcTabInfo').classList.toggle('hidden', tab !== 'info');
        document.getElementById('gcTabDocs').classList.toggle('hidden', tab !== 'docs');
        document.getElementById('gcTabHist').classList.toggle('hidden', tab !== 'hist');
    };

    function renderDocs(docs) {
        const list = document.getElementById('gcDocList');
        document.getElementById('gcDocCountBadge').textContent = docs.length;
        if (!docs.length) {
            list.innerHTML = `<div class="text-center text-slate-400 font-bold py-6 text-sm">ยังไม่มีเอกสารแนบ</div>`;
            return;
        }
        list.innerHTML = docs.map(d => `
            <div class="gc-doc-card">
                <div class="gc-doc-icon bg-amber-50 text-amber-500"><i class="fa-solid fa-file-${d.mime_type && d.mime_type.startsWith('image/') ? 'image' : 'lines'}"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="font-black text-slate-800 text-sm truncate">${escapeHtml(d.file_name)}</p>
                    <p class="text-xs text-slate-500 font-bold mt-0.5">${DOC_TYPE_LABELS[d.doc_type] || d.doc_type} · ${formatBytes(d.file_size)} · ${d.uploaded_at}</p>
                </div>
                <a href="ajax_gold_card.php?action=download_legacy" onclick="event.preventDefault(); gcDownloadDoc(${d.id})" class="px-3 h-9 inline-flex items-center gap-1 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg text-xs font-black">
                    <i class="fa-solid fa-eye"></i> ดู
                </a>
                <button onclick="gcDeleteDoc(${d.id})" class="px-3 h-9 inline-flex items-center bg-rose-50 text-rose-600 hover:bg-rose-100 rounded-lg text-xs font-black">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        `).join('');
    }

    function renderHist(hist) {
        const list = document.getElementById('gcHistList');
        if (!hist.length) {
            list.innerHTML = `<div class="text-center text-slate-400 font-bold py-6 text-sm">ยังไม่มีประวัติ</div>`;
            return;
        }
        const ACTION_LABELS = { created: '🆕 สร้างใหม่', updated: '✏️ แก้ไขข้อมูล', status_changed: '🔄 เปลี่ยนสถานะ', doc_added: '📎 เพิ่มเอกสาร', doc_removed: '🗑️ ลบเอกสาร', deleted: '❌ ลบสมาชิก' };
        list.innerHTML = hist.map(h => `
            <div class="bg-slate-50 border border-slate-100 rounded-xl px-4 py-2.5 flex items-center justify-between text-xs">
                <span class="font-black text-slate-700">${ACTION_LABELS[h.action] || h.action}</span>
                <span class="text-slate-500 font-bold">${h.changed_at}</span>
            </div>
        `).join('');
    }

    window.gcSaveMember = function() {
        const id = document.getElementById('gcMemberId').value;
        const data = {
            id,
            citizen_id: document.getElementById('gcCitizenId').value.trim(),
            full_name:  document.getElementById('gcFullName').value.trim(),
            member_type: document.getElementById('gcMemberType').value,
            phone:      document.getElementById('gcPhone').value.trim(),
            hospital_main: document.getElementById('gcHospMain').value.trim(),
            hospital_sub:  document.getElementById('gcHospSub').value.trim(),
            application_date: document.getElementById('gcApplyDate').value,
            coverage_start: document.getElementById('gcCovStart').value,
            coverage_end:   document.getElementById('gcCovEnd').value,
            status: document.getElementById('gcStatus').value,
            remarks: document.getElementById('gcRemarks').value.trim(),
        };
        gcPost('member', 'save', data).then(r => {
            const err = document.getElementById('gcMemberError');
            if (r.status === 'ok') {
                err.classList.add('hidden');
                Swal.fire({ icon: 'success', title: 'สำเร็จ', text: r.message, timer: 1500, showConfirmButton: false });
                gcCloseMemberModal();
                gcLoadMembers(currentPage);
                location.reload(); // refresh KPIs
            } else {
                err.textContent = r.message || 'บันทึกไม่สำเร็จ';
                err.classList.remove('hidden');
            }
        });
    };

    window.gcDeleteMember = async function() {
        const id = document.getElementById('gcMemberId').value;
        if (!id || id == 0) return;
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ยืนยันการลบ?', text: 'จะลบสมาชิกและเอกสารทั้งหมดออกจากระบบ',
            showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626', reverseButtons: true
        });
        if (!isConfirmed) return;
        gcPost('member', 'delete', { id }).then(r => {
            if (r.status === 'ok') {
                Swal.fire({ icon: 'success', title: 'ลบแล้ว', timer: 1500, showConfirmButton: false });
                gcCloseMemberModal();
                location.reload();
            } else Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: r.message });
        });
    };

    window.gcUploadDoc = function(file) {
        if (!file) return;
        const memberId = document.getElementById('gcMemberId').value;
        if (!memberId || memberId == 0) {
            Swal.fire({ icon: 'info', title: 'กรุณาบันทึกข้อมูลก่อน', text: 'ต้องบันทึกข้อมูลสมาชิกก่อน จึงจะอัปโหลดเอกสารได้' });
            return;
        }
        const fd = new FormData();
        fd.append('member_id', memberId);
        fd.append('doc_type', document.getElementById('gcDocType').value);
        fd.append('file', file);
        gcPost('document', 'upload', fd, true).then(r => {
            if (r.status === 'ok') {
                Swal.fire({ icon: 'success', title: 'อัปโหลดแล้ว', timer: 1200, showConfirmButton: false });
                gcPost('member', 'get', { id: memberId }).then(r2 => {
                    if (r2.status === 'ok') renderDocs(r2.documents || []);
                });
            } else Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: r.message });
        });
        document.getElementById('gcDocFile').value = '';
    };

    window.gcDeleteDoc = async function(docId) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบเอกสารนี้?', showCancelButton: true,
            confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626', reverseButtons: true
        });
        if (!isConfirmed) return;
        gcPost('document', 'delete', { id: docId }).then(r => {
            if (r.status === 'ok') {
                Swal.fire({ icon: 'success', title: 'ลบแล้ว', timer: 1200, showConfirmButton: false });
                const memberId = document.getElementById('gcMemberId').value;
                gcPost('member', 'get', { id: memberId }).then(r2 => {
                    if (r2.status === 'ok') renderDocs(r2.documents || []);
                });
            } else Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: r.message });
        });
    };

    window.gcDownloadDoc = function(docId) {
        const fd = new FormData();
        fd.append('entity', 'document');
        fd.append('action', 'download');
        fd.append('id', docId);
        fd.append('csrf_token', CSRF);
        fetch(ENDPOINT, { method: 'POST', body: fd })
            .then(r => r.blob())
            .then(blob => {
                const url = URL.createObjectURL(blob);
                window.open(url, '_blank');
                setTimeout(() => URL.revokeObjectURL(url), 60000);
            });
    };

    // Drag & drop
    const dz = document.getElementById('gcDropzone');
    if (dz) {
        ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('gc-drag-over'); }));
        ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('gc-drag-over'); }));
        dz.addEventListener('drop', e => {
            const f = e.dataTransfer.files[0];
            if (f) gcUploadDoc(f);
        });
    }

    // Search debounce
    let searchTimer;
    document.getElementById('gcSearch').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => gcLoadMembers(1), 350);
    });

    // ── Charts ──────────────────────────────────────────────────────
    function loadCharts() {
        if (typeof Chart === 'undefined') { setTimeout(loadCharts, 200); return; }

        // Trend (use public API via direct fetch — no auth needed since we're admin)
        fetch('../api/dashboard_public.php', { credentials: 'omit' })
            .then(r => r.json())
            .then(d => {
                const trendW = (d.widgets || []).find(w => w.data_source === 'gold_trend_12m');
                if (trendW) {
                    new Chart(document.getElementById('gcTrendChart'), {
                        type: 'line',
                        data: {
                            labels: trendW.data.labels,
                            datasets: [{
                                label: 'จำนวนผู้ลงทะเบียน', data: trendW.data.series[0].data,
                                borderColor: '#f59e0b', backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                tension: 0.3, fill: true, borderWidth: 2.5, pointRadius: 4,
                            }],
                        },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                    });
                }
                const hospW = (d.widgets || []).find(w => w.data_source === 'gold_by_hospital');
                if (hospW) {
                    new Chart(document.getElementById('gcHospChart'), {
                        type: 'bar',
                        data: {
                            labels: hospW.data.labels,
                            datasets: [{
                                label: 'จำนวน', data: hospW.data.values,
                                backgroundColor: '#fbbf24', borderRadius: 6,
                            }]
                        },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                    });
                }
            });
    }

    // ── Utils ───────────────────────────────────────────────────────
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function formatBytes(b) {
        if (!b) return '0 B';
        const u = ['B','KB','MB','GB'];
        let i = 0; let n = b;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return n.toFixed(1) + ' ' + u[i];
    }

    // ── init ────────────────────────────────────────────────────────
    gcLoadMembers(1);
    loadCharts();
})();
</script>
