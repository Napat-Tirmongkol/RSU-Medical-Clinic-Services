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

require_once __DIR__ . '/../../includes/kpi_override_helper.php';

$pdo = db();
$csrfToken = get_csrf_token();
$canEditKPI = ($_SESSION['admin_role'] ?? '') === 'superadmin' || !empty($_SESSION['access_dashboard_admin']);

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

// Apply KPI overrides + read status (badges)
$gcAuto = $stats;
$stats['total']    = kpi_with_override($pdo, 'gold_total',         $stats['total']);
$stats['approved'] = kpi_with_override($pdo, 'gold_approved',      $stats['approved']);
$stats['pending']  = kpi_with_override($pdo, 'gold_pending_docs',  $stats['pending']);
$stats['rejected'] = kpi_with_override($pdo, 'gold_rejected',      $stats['rejected']);
$stats['expiring'] = kpi_with_override($pdo, 'gold_expiring_30d',  $stats['expiring']);
$gcOver = kpi_override_status($pdo);
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

    /* Folder browser view */
    #section-gold_card .gc-folder-tile {
        background: linear-gradient(135deg, #fffbeb, #fef3c7);
        border: 1.5px solid #fde68a;
        border-radius: 16px;
        padding: 18px 16px;
        display: flex; flex-direction: column; align-items: center;
        cursor: pointer; transition: all .2s cubic-bezier(.34,1.56,.64,1);
        position: relative; overflow: hidden;
        min-height: 120px;
    }
    #section-gold_card .gc-folder-tile:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 24px -8px rgba(245,158,11,.4);
        border-color: #f59e0b;
    }
    #section-gold_card .gc-folder-icon {
        font-size: 38px; color: #f59e0b; line-height: 1; margin-bottom: 8px;
        filter: drop-shadow(0 4px 6px rgba(245,158,11,.25));
    }
    #section-gold_card .gc-folder-name {
        font-size: 13px; font-weight: 900; color: #78350f;
        line-height: 1.2; text-align: center; margin-bottom: 4px;
    }
    #section-gold_card .gc-folder-count {
        font-size: 11px; font-weight: 800; color: #b45309;
        background: rgba(255,255,255,.8); padding: 2px 9px; border-radius: 99px;
    }
    #section-gold_card .gc-folder-bar {
        position: absolute; bottom: 0; left: 0; right: 0;
        height: 4px; background: rgba(245,158,11,.15);
    }
    #section-gold_card .gc-folder-bar-fill {
        height: 100%; background: linear-gradient(90deg, #10b981, #34d399);
        transition: width .6s ease;
    }
    #section-gold_card .gc-member-tile {
        background: #fff; border: 1.5px solid #e2e8f0;
        border-radius: 14px; padding: 14px;
        cursor: pointer; transition: all .15s;
        display: flex; gap: 12px; align-items: flex-start;
    }
    #section-gold_card .gc-member-tile:hover {
        border-color: #f59e0b; transform: translateY(-2px);
        box-shadow: 0 8px 20px -6px rgba(0,0,0,.1);
    }
    #section-gold_card .gc-file-icon {
        width: 44px; height: 44px; border-radius: 10px;
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        color: #1d4ed8; display: flex; align-items: center; justify-content: center;
        font-size: 18px; flex-shrink: 0;
    }
    #section-gold_card .gc-breadcrumb {
        display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        font-size: 12px; font-weight: 800; color: #64748b;
    }
    #section-gold_card .gc-breadcrumb a {
        color: #b45309; cursor: pointer; transition: color .15s;
    }
    #section-gold_card .gc-breadcrumb a:hover { color: #78350f; text-decoration: underline; }
    #section-gold_card .gc-view-tab {
        padding: 8px 14px; border-radius: 10px; font-size: 12px; font-weight: 900;
        color: #64748b; cursor: pointer; transition: all .15s;
        background: transparent; border: none;
        display: flex; align-items: center; gap: 6px;
    }
    #section-gold_card .gc-view-tab:hover { background: #f1f5f9; color: #0f172a; }
    #section-gold_card .gc-view-tab.gc-view-active {
        background: #f59e0b; color: #fff;
        box-shadow: 0 4px 10px -2px rgba(245,158,11,.4);
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

    <!-- ── KPI Cards (5 ใบ — overrideable) ─────────────────────────────── -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
        <div class="gc-stat-card km-card" data-kpi-key="gold_total" data-kpi-label="บัตรทอง — ทั้งหมด">
            <div class="gc-icon-tile bg-amber-50 text-amber-500"><i class="fa-solid fa-id-card"></i></div>
            <div class="km-body">
                <p class="km-label text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">ทั้งหมด</p>
                <p class="km-value text-2xl font-black text-slate-800 leading-none" data-value="<?= (int)$stats['total'] ?>"><?= number_format($stats['total']) ?></p>
            </div>
            <?php if (!empty($gcOver['gold_total'])): ?><span class="km-override-badge">OVERRIDE</span><?php endif; ?>
        </div>
        <div class="gc-stat-card km-card" data-kpi-key="gold_approved" data-kpi-label="บัตรทอง — อนุมัติ">
            <div class="gc-icon-tile bg-emerald-50 text-emerald-500"><i class="fa-solid fa-circle-check"></i></div>
            <div class="km-body">
                <p class="km-label text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">อนุมัติแล้ว</p>
                <p class="km-value text-2xl font-black text-slate-800 leading-none" data-value="<?= (int)$stats['approved'] ?>"><?= number_format($stats['approved']) ?></p>
            </div>
            <?php if (!empty($gcOver['gold_approved'])): ?><span class="km-override-badge">OVERRIDE</span><?php endif; ?>
        </div>
        <div class="gc-stat-card km-card" data-kpi-key="gold_pending_docs" data-kpi-label="บัตรทอง — รอเอกสาร">
            <div class="gc-icon-tile bg-blue-50 text-blue-500"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="km-body">
                <p class="km-label text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">รอ/ส่งแล้ว</p>
                <p class="km-value text-2xl font-black text-slate-800 leading-none" data-value="<?= (int)$stats['pending'] ?>"><?= number_format($stats['pending']) ?></p>
            </div>
            <?php if (!empty($gcOver['gold_pending_docs'])): ?><span class="km-override-badge">OVERRIDE</span><?php endif; ?>
        </div>
        <div class="gc-stat-card km-card" data-kpi-key="gold_rejected" data-kpi-label="บัตรทอง — ไม่ผ่าน">
            <div class="gc-icon-tile bg-rose-50 text-rose-500"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="km-body">
                <p class="km-label text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">ไม่ผ่าน</p>
                <p class="km-value text-2xl font-black text-slate-800 leading-none" data-value="<?= (int)$stats['rejected'] ?>"><?= number_format($stats['rejected']) ?></p>
            </div>
            <?php if (!empty($gcOver['gold_rejected'])): ?><span class="km-override-badge">OVERRIDE</span><?php endif; ?>
        </div>
        <div class="gc-stat-card km-card <?= $stats['expiring'] > 0 ? 'border-amber-300 bg-amber-50/50' : '' ?>" data-kpi-key="gold_expiring_30d" data-kpi-label="บัตรทอง — ใกล้หมด ≤30 วัน">
            <div class="gc-icon-tile <?= $stats['expiring'] > 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-50 text-slate-300' ?>"><i class="fa-solid fa-clock"></i></div>
            <div class="km-body">
                <p class="km-label text-[10px] font-black <?= $stats['expiring'] > 0 ? 'text-amber-600' : 'text-slate-400' ?> uppercase tracking-widest leading-none mb-1">ใกล้หมด ≤30วัน</p>
                <p class="km-value text-2xl font-black leading-none <?= $stats['expiring'] > 0 ? 'text-amber-700' : 'text-slate-800' ?>" data-value="<?= (int)$stats['expiring'] ?>"><?= number_format($stats['expiring']) ?></p>
            </div>
            <?php if (!empty($gcOver['gold_expiring_30d'])): ?><span class="km-override-badge">OVERRIDE</span><?php endif; ?>
        </div>
    </div>

    <!-- ── Bulk Import Card ───────────────────────────────────────────── -->
    <div class="bg-gradient-to-br from-amber-50 via-white to-orange-50 border border-amber-200 rounded-[2rem] p-6 shadow-sm">
        <div class="flex flex-wrap items-start gap-5">
            <div class="w-14 h-14 rounded-2xl bg-white shadow-sm flex items-center justify-center text-amber-500 text-2xl shrink-0">
                <i class="fa-solid fa-folder-arrow-up"></i>
            </div>
            <div class="flex-1 min-w-[250px]">
                <h3 class="text-base font-black text-slate-800">📁 นำเข้าจากไฟล์ (Bulk Import)</h3>
                <p class="text-xs font-bold text-slate-500 mt-1 leading-relaxed">
                    อัปโหลดไฟล์เอกสารบัตรทองหลายๆ ไฟล์พร้อมกัน · ระบบจะอ่านชื่อจากไฟล์แล้วจับคู่กับนักศึกษาในระบบ
                    <br><span class="text-amber-600">→ ถ้าชื่อตรงกัน นักศึกษาจะได้สถานะ Active บัตรทองในโปรไฟล์อัตโนมัติ</span>
                </p>
            </div>
            <button onclick="gcOpenBulkModal()" class="h-12 px-6 bg-amber-500 hover:bg-amber-600 text-white font-black rounded-2xl shadow-lg shadow-amber-200 active:scale-95 transition-all flex items-center gap-2 text-sm">
                <i class="fa-solid fa-cloud-arrow-up"></i> เริ่มนำเข้า
            </button>
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

    <!-- ── View Toggle + List/Folder Panel ────────────────────────────── -->
    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 flex flex-wrap items-center gap-3">
            <h3 class="text-base font-black text-slate-800 flex-1 min-w-[150px]">รายชื่อผู้มีสิทธิ์</h3>

            <!-- View tabs -->
            <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl p-1 gap-1">
                <button id="gcViewTabTable" type="button" class="gc-view-tab gc-view-active" onclick="gcSwitchView('table')">
                    <i class="fa-solid fa-table-list"></i> ตาราง
                </button>
                <button id="gcViewTabFolder" type="button" class="gc-view-tab" onclick="gcSwitchView('folder')">
                    <i class="fa-solid fa-folder-tree"></i> โฟลเดอร์
                </button>
            </div>

            <div id="gcTableFilters" class="flex items-center gap-2 flex-wrap">
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
        </div>

        <!-- Table view -->
        <div id="gcTableView">
            <div id="gcTableWrap" class="min-h-[200px]"></div>
            <div id="gcPagerWrap" class="px-6 py-4 flex items-center justify-between border-t border-slate-100 hidden flex-wrap gap-3">
                <div id="gcPagerInfo" class="text-xs font-bold text-slate-500"></div>
                <div id="gcPager" class="flex items-center gap-1.5"></div>
            </div>
        </div>

        <!-- Folder view -->
        <div id="gcFolderView" class="hidden p-6">
            <div id="gcFolderBreadcrumb" class="gc-breadcrumb mb-5">
                <i class="fa-solid fa-folder-open text-amber-500"></i>
                <a onclick="gcFolderHome()">บัตรทอง</a>
            </div>
            <div id="gcFolderContent" class="min-h-[200px]"></div>
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

<!-- ════════════ BULK IMPORT MODAL ════════════ -->
<div id="gcBulkModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 backdrop-blur-sm gc-modal">
    <div class="bg-white rounded-[2rem] w-full max-w-5xl mx-4 shadow-2xl flex flex-col gc-modal-box">
        <div class="px-7 pt-6 pb-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 class="text-lg font-black text-slate-900"><i class="fa-solid fa-folder-arrow-up text-amber-500 mr-2"></i> นำเข้าจากไฟล์ (Bulk Import)</h3>
            <button onclick="gcCloseBulkModal()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="overflow-y-auto flex-1 px-7 py-5 space-y-5">
            <!-- Step 1: Select files -->
            <div id="gcBulkStep1" class="space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-800 font-bold leading-relaxed space-y-2">
                    <p><i class="fa-solid fa-circle-info mr-1"></i> <b>วิธีตั้งชื่อไฟล์:</b> ใช้ชื่อนักศึกษา เช่น <code class="bg-white px-2 py-0.5 rounded">นายสมชาย ใจดี.pdf</code> (ตัดเลขนำหน้าออกอัตโนมัติ)</p>
                    <p><i class="fa-solid fa-folder-tree mr-1 text-amber-600"></i> <b>โหมดโฟลเดอร์:</b> ชื่อโฟลเดอร์ <code class="bg-white px-2 py-0.5 rounded">5พค68</code> หรือ <code class="bg-white px-2 py-0.5 rounded">1มค 68</code> = เดือน พ.ค. 2568 → บันทึกเป็น <b>วันลงทะเบียน</b></p>
                </div>

                <!-- Mode Toggle -->
                <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-2xl p-1.5 w-fit">
                    <button type="button" id="gcModeFiles" onclick="gcSwitchMode('files')" class="gc-mode-btn h-9 px-4 rounded-xl text-xs font-black flex items-center gap-2 transition-all bg-white text-slate-800 shadow-sm">
                        <i class="fa-solid fa-file-circle-plus"></i> เลือกไฟล์
                    </button>
                    <button type="button" id="gcModeFolder" onclick="gcSwitchMode('folder')" class="gc-mode-btn h-9 px-4 rounded-xl text-xs font-black flex items-center gap-2 transition-all text-slate-500">
                        <i class="fa-solid fa-folder-tree"></i> เลือกโฟลเดอร์ (รวมเดือน)
                    </button>
                </div>

                <label class="block">
                    <div class="gc-dropzone" id="gcBulkDropzone">
                        <i class="fa-solid fa-cloud-arrow-up text-4xl mb-2"></i>
                        <p id="gcDropzoneText" class="text-base font-black">ลากไฟล์ <span class="text-amber-700">หรือลากทั้งโฟลเดอร์</span> มาวาง / คลิกเพื่อเลือก</p>
                        <p class="text-xs font-bold mt-1 text-amber-600">รองรับโฟลเดอร์ที่มี subfolder รายเดือน · PDF / JPG / PNG / DOC · ≤20MB ต่อไฟล์</p>
                    </div>
                    <input type="file" id="gcBulkFiles" multiple accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" class="hidden">
                </label>

                <div id="gcBulkFileList" class="hidden space-y-1 text-xs text-slate-600 font-bold max-h-32 overflow-y-auto bg-slate-50 rounded-xl p-3"></div>

                <div class="flex justify-end">
                    <button id="gcBulkScanBtn" disabled onclick="gcBulkScan()" class="h-11 px-6 bg-blue-500 hover:bg-blue-600 text-white font-black rounded-xl text-sm shadow-lg disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> สแกนและจับคู่
                    </button>
                </div>
            </div>

            <!-- Step 2: Match results -->
            <div id="gcBulkStep2" class="hidden space-y-4">
                <div id="gcBulkSummary" class="grid grid-cols-2 md:grid-cols-4 gap-3"></div>

                <!-- Quick Actions -->
                <div class="flex flex-wrap items-center gap-2 px-1">
                    <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest mr-1">เลือก:</span>
                    <button type="button" onclick="gcBulkSelectAll('all')" class="h-8 px-3 bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-black rounded-lg flex items-center gap-1.5 transition-all active:scale-95">
                        <i class="fa-solid fa-check-double"></i> ทั้งหมด
                    </button>
                    <button type="button" onclick="gcBulkSelectAll('matched')" class="h-8 px-3 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-[11px] font-black rounded-lg flex items-center gap-1.5 transition-all">
                        <i class="fa-solid fa-circle-check"></i> เฉพาะที่จับคู่ได้
                    </button>
                    <button type="button" onclick="gcBulkSelectAll('with_user')" class="h-8 px-3 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-[11px] font-black rounded-lg flex items-center gap-1.5 transition-all">
                        <i class="fa-solid fa-user-check"></i> ที่มีนักศึกษาแล้ว
                    </button>
                    <button type="button" onclick="gcBulkSelectAll('none')" class="h-8 px-3 bg-slate-50 hover:bg-slate-100 text-slate-600 border border-slate-200 text-[11px] font-black rounded-lg flex items-center gap-1.5 transition-all ml-auto">
                        <i class="fa-solid fa-square"></i> ยกเลิก
                    </button>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                <th class="px-3 py-2 text-left">ไฟล์</th>
                                <th class="px-3 py-2 text-center">เดือน</th>
                                <th class="px-3 py-2 text-left">ชื่อที่ดึง</th>
                                <th class="px-3 py-2 text-center">สถานะ</th>
                                <th class="px-3 py-2 text-left">นักศึกษา (sys_users)</th>
                                <th class="px-3 py-2 text-center">
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="checkbox" id="gcBulkMasterCheck" class="w-4 h-4 accent-amber-500" onchange="gcBulkToggleMaster(this)">
                                        <span>นำเข้า</span>
                                    </label>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="gcBulkResultRows"></tbody>
                    </table>
                </div>

                <div class="flex justify-between items-center gap-3">
                    <button onclick="gcBulkBackToStep1()" class="h-11 px-5 bg-white border border-slate-200 text-slate-600 font-black rounded-xl text-sm hover:bg-slate-50">
                        <i class="fa-solid fa-arrow-left mr-1"></i> ย้อนกลับ
                    </button>
                    <button id="gcBulkCommitBtn" onclick="gcBulkCommit()" class="h-11 px-6 bg-amber-500 hover:bg-amber-600 text-white font-black rounded-xl text-sm shadow-lg shadow-amber-200 flex items-center gap-2">
                        <i class="fa-solid fa-check-double"></i> ยืนยันสร้าง <span id="gcBulkCommitCount" class="bg-white/20 px-2 py-0.5 rounded text-[10px]">0</span> รายการ
                    </button>
                </div>
            </div>

            <!-- Step 3: Result -->
            <div id="gcBulkStep3" class="hidden space-y-4 text-center py-8">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-3xl">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h4 class="text-lg font-black text-slate-800">นำเข้าเสร็จสิ้น</h4>
                <div id="gcBulkResultText" class="text-sm text-slate-600 font-bold"></div>
                <button onclick="gcCloseBulkModal(); location.reload();" class="h-11 px-6 bg-blue-500 text-white font-black rounded-xl text-sm">เสร็จสิ้น</button>
            </div>
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

    // ── View toggle (Table / Folder) ────────────────────────────────
    let currentView = 'table';
    let folderState = { tree: null, openYear: null, openMonth: null };

    window.gcSwitchView = function(mode) {
        currentView = mode;
        document.getElementById('gcViewTabTable').classList.toggle('gc-view-active', mode === 'table');
        document.getElementById('gcViewTabFolder').classList.toggle('gc-view-active', mode === 'folder');
        document.getElementById('gcTableView').classList.toggle('hidden', mode !== 'table');
        document.getElementById('gcFolderView').classList.toggle('hidden', mode !== 'folder');
        document.getElementById('gcTableFilters').classList.toggle('hidden', mode !== 'table');

        if (mode === 'folder') gcFolderHome();
    };

    window.gcFolderHome = function() {
        folderState.openYear = null;
        folderState.openMonth = null;
        gcRenderFolderBreadcrumb();
        gcRenderFolderRoot();
    };

    function gcRenderFolderBreadcrumb() {
        const bc = document.getElementById('gcFolderBreadcrumb');
        let html = `<i class="fa-solid fa-folder-open text-amber-500"></i>
                    <a onclick="gcFolderHome()">บัตรทอง</a>`;
        if (folderState.openYear !== null && folderState.openMonth !== null) {
            const tile = (folderState.tree || []).find(f =>
                f.year === folderState.openYear && f.month === folderState.openMonth);
            if (tile) {
                html += ` <span class="text-slate-300">/</span>
                          <span class="text-slate-700">${escapeHtml(tile.label)}</span>`;
            }
        }
        bc.innerHTML = html;
    }

    function gcRenderFolderRoot() {
        const wrap = document.getElementById('gcFolderContent');
        wrap.innerHTML = `<div class="text-center text-slate-400 font-bold py-8">
            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
            <p class="text-sm">กำลังโหลดโฟลเดอร์...</p>
        </div>`;

        gcPost('folder', 'tree', {}).then(r => {
            if (r.status !== 'ok') {
                wrap.innerHTML = `<div class="text-rose-500 font-bold py-8 text-center">${r.message || 'โหลดไม่สำเร็จ'}</div>`;
                return;
            }
            folderState.tree = r.folders;
            const folders = r.folders || [];
            const noDate = r.no_date_count || 0;

            if (folders.length === 0 && noDate === 0) {
                wrap.innerHTML = `<div class="text-center text-slate-400 font-bold py-12">
                    <i class="fa-solid fa-folder-open text-4xl mb-3 opacity-40"></i>
                    <p>ยังไม่มีโฟลเดอร์</p>
                    <p class="text-xs mt-2 text-slate-300">โฟลเดอร์จะถูกสร้างอัตโนมัติเมื่อมีสมาชิกที่ระบุวันสมัคร</p>
                </div>`;
                return;
            }

            // Group folders by year
            const byYear = {};
            folders.forEach(f => { (byYear[f.year] = byYear[f.year] || []).push(f); });
            const years = Object.keys(byYear).sort((a, b) => b - a);

            let html = '';
            years.forEach(y => {
                const beYear = parseInt(y, 10) + 543;
                const yearFolders = byYear[y];
                const totalInYear = yearFolders.reduce((s, f) => s + f.count, 0);
                html += `<div class="mb-7">
                    <div class="flex items-center gap-2 mb-3 text-xs font-black text-slate-500 uppercase tracking-widest">
                        <i class="fa-solid fa-calendar-days text-amber-500"></i>
                        พ.ศ. ${beYear}
                        <span class="text-slate-300">·</span>
                        <span class="text-amber-700 normal-case">${totalInYear.toLocaleString()} ราย</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6 gap-3">`;
                yearFolders.forEach(f => {
                    const ratio = f.count > 0 ? (f.approved / f.count * 100) : 0;
                    html += `
                        <div class="gc-folder-tile" onclick="gcOpenFolder(${f.year}, ${f.month})">
                            <i class="fa-solid fa-folder gc-folder-icon"></i>
                            <div class="gc-folder-name">${escapeHtml(f.label)}</div>
                            <span class="gc-folder-count">${f.count.toLocaleString()} ราย</span>
                            <div class="gc-folder-bar"><div class="gc-folder-bar-fill" style="width:${ratio}%"></div></div>
                        </div>`;
                });
                html += `</div></div>`;
            });

            // No-date folder (if any)
            if (noDate > 0) {
                html += `<div class="mb-3">
                    <div class="flex items-center gap-2 mb-3 text-xs font-black text-slate-500 uppercase tracking-widest">
                        <i class="fa-solid fa-circle-question text-slate-400"></i> ไม่ระบุวันสมัคร
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6 gap-3">
                        <div class="gc-folder-tile" style="background:linear-gradient(135deg,#f1f5f9,#e2e8f0);border-color:#cbd5e1" onclick="gcOpenFolder(null, null)">
                            <i class="fa-solid fa-folder-minus gc-folder-icon" style="color:#64748b"></i>
                            <div class="gc-folder-name" style="color:#334155">ไม่ระบุเดือน</div>
                            <span class="gc-folder-count" style="background:rgba(255,255,255,.9);color:#475569">${noDate.toLocaleString()} ราย</span>
                        </div>
                    </div>
                </div>`;
            }

            wrap.innerHTML = html;
        });
    }

    window.gcOpenFolder = function(year, month) {
        folderState.openYear = year;
        folderState.openMonth = month;
        gcRenderFolderBreadcrumb();

        const wrap = document.getElementById('gcFolderContent');
        wrap.innerHTML = `<div class="text-center text-slate-400 font-bold py-8">
            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
            <p class="text-sm">กำลังโหลดสมาชิก...</p>
        </div>`;

        const params = { page: 1, page_size: 100 };
        if (year !== null && month !== null) {
            params.year = year;
            params.month = month;
        } else {
            params.no_date = 1;
        }

        gcPost('member', 'list', params).then(r => {
            if (r.status !== 'ok') {
                wrap.innerHTML = `<div class="text-rose-500 font-bold py-8 text-center">${r.message || 'โหลดไม่สำเร็จ'}</div>`;
                return;
            }
            const rows = r.rows || [];
            if (rows.length === 0) {
                wrap.innerHTML = `<div class="text-center text-slate-400 font-bold py-12">
                    <i class="fa-solid fa-folder-open text-4xl mb-3 opacity-40"></i>
                    <p>โฟลเดอร์ว่าง</p>
                </div>`;
                return;
            }

            let html = `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">`;
            rows.forEach(r => {
                const cidLast4 = (r.citizen_id || '').slice(-4);
                html += `
                    <div class="gc-member-tile" onclick="gcOpenMemberModal(${r.id})">
                        <div class="gc-file-icon"><i class="fa-solid fa-file-lines"></i></div>
                        <div class="flex-1 min-w-0">
                            <div class="font-black text-slate-800 text-sm truncate">${escapeHtml(r.full_name || '(ไม่มีชื่อ)')}</div>
                            <div class="flex items-center gap-2 mt-1 flex-wrap">
                                ${statusBadge(r.status)}
                                ${r.doc_count > 0 ? `<span class="text-[11px] font-black text-amber-600"><i class="fa-solid fa-paperclip"></i> ${r.doc_count}</span>` : ''}
                            </div>
                            <div class="text-[10px] text-slate-400 font-bold mt-1 truncate">
                                ${cidLast4 ? `xxxx-${cidLast4}` : ''}
                                ${r.hospital_main ? `· ${escapeHtml(r.hospital_main)}` : ''}
                            </div>
                        </div>
                    </div>`;
            });
            html += `</div>
                <div class="text-center text-xs text-slate-400 font-bold mt-5">
                    รวม ${r.total} รายการ${r.total > 100 ? ' · แสดง 100 แรก' : ''}
                </div>`;
            wrap.innerHTML = html;
        });
    };

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

    // ── BULK IMPORT ─────────────────────────────────────────────────
    let bulkFiles = null;       // FileList from input
    let bulkScanReport = null;  // server response
    let bulkMode = 'files';     // 'files' | 'folder'

    window.gcSwitchMode = function(mode) {
        bulkMode = mode;
        const filesBtn  = document.getElementById('gcModeFiles');
        const folderBtn = document.getElementById('gcModeFolder');
        const input     = document.getElementById('gcBulkFiles');
        const dzText    = document.getElementById('gcDropzoneText');

        if (mode === 'folder') {
            folderBtn.classList.add('bg-white','text-slate-800','shadow-sm');
            folderBtn.classList.remove('text-slate-500');
            filesBtn.classList.remove('bg-white','text-slate-800','shadow-sm');
            filesBtn.classList.add('text-slate-500');
            input.setAttribute('webkitdirectory', '');
            input.setAttribute('directory', '');
            input.removeAttribute('accept');
            dzText.textContent = 'คลิกเพื่อเลือกโฟลเดอร์ (รวมโฟลเดอร์ย่อยอัตโนมัติ)';
        } else {
            filesBtn.classList.add('bg-white','text-slate-800','shadow-sm');
            filesBtn.classList.remove('text-slate-500');
            folderBtn.classList.remove('bg-white','text-slate-800','shadow-sm');
            folderBtn.classList.add('text-slate-500');
            input.removeAttribute('webkitdirectory');
            input.removeAttribute('directory');
            input.setAttribute('accept', '.pdf,.png,.jpg,.jpeg,.doc,.docx');
            dzText.textContent = 'ลากไฟล์มาวาง / คลิกเพื่อเลือก';
        }
        // reset selection
        input.value = '';
        bulkFiles = null;
        document.getElementById('gcBulkScanBtn').disabled = true;
        document.getElementById('gcBulkFileList').classList.add('hidden');
    };

    window.gcOpenBulkModal = function() {
        const modal = document.getElementById('gcBulkModal');
        modal.classList.remove('hidden'); modal.classList.add('flex');
        gcBulkBackToStep1();
        gcSwitchMode('files');
    };
    window.gcCloseBulkModal = function() {
        const modal = document.getElementById('gcBulkModal');
        modal.classList.add('hidden'); modal.classList.remove('flex');
    };
    window.gcBulkBackToStep1 = function() {
        document.getElementById('gcBulkStep1').classList.remove('hidden');
        document.getElementById('gcBulkStep2').classList.add('hidden');
        document.getElementById('gcBulkStep3').classList.add('hidden');
    };

    document.getElementById('gcBulkDropzone').addEventListener('click', () => document.getElementById('gcBulkFiles').click());
    ['dragenter','dragover'].forEach(ev => document.getElementById('gcBulkDropzone').addEventListener(ev, e => { e.preventDefault(); document.getElementById('gcBulkDropzone').classList.add('gc-drag-over'); }));
    ['dragleave','drop'].forEach(ev => document.getElementById('gcBulkDropzone').addEventListener(ev, e => { e.preventDefault(); document.getElementById('gcBulkDropzone').classList.remove('gc-drag-over'); }));
    document.getElementById('gcBulkDropzone').addEventListener('drop', async e => {
        e.preventDefault();
        const items = e.dataTransfer.items;
        if (!items || items.length === 0) return;

        // ถ้า browser รองรับ webkitGetAsEntry → traverse ได้ทั้งโฟลเดอร์
        const hasEntryAPI = items[0].webkitGetAsEntry !== undefined;
        if (hasEntryAPI) {
            Swal.fire({ title: 'กำลังสแกนโฟลเดอร์...', html: 'รอสักครู่', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            try {
                const collected = [];
                const promises = [];
                for (const it of items) {
                    const entry = it.webkitGetAsEntry();
                    if (!entry) continue;
                    if (entry.isFile) {
                        promises.push(new Promise(resolve => entry.file(f => {
                            try { Object.defineProperty(f, 'webkitRelativePath', { value: entry.name, configurable: true }); } catch (_){}
                            collected.push(f); resolve();
                        }, () => resolve())));
                    } else if (entry.isDirectory) {
                        promises.push(readDirEntry(entry, entry.name + '/').then(arr => collected.push(...arr)));
                        // ถ้า drop โฟลเดอร์ → switch UI ไป folder mode (ให้ตรงกับสิ่งที่ผู้ใช้คาดหวัง)
                        if (bulkMode === 'files') gcSwitchMode('folder');
                    }
                }
                await Promise.all(promises);
                Swal.close();
                if (collected.length === 0) {
                    Swal.fire({ icon: 'info', title: 'ไม่พบไฟล์ในโฟลเดอร์' });
                    return;
                }
                applyDroppedFiles(collected);
            } catch (err) {
                Swal.close();
                console.error(err);
                Swal.fire({ icon: 'error', title: 'อ่านโฟลเดอร์ไม่สำเร็จ', text: String(err) });
            }
        } else {
            // Fallback: ใช้เฉพาะ files (browser เก่า ไม่มี webkitGetAsEntry)
            const dt = new DataTransfer();
            for (const f of e.dataTransfer.files) dt.items.add(f);
            document.getElementById('gcBulkFiles').files = dt.files;
            bulkOnFilesSelected();
        }
    });

    // อ่าน FileSystemDirectoryEntry ทั้งหมดแบบ recursive (handle batch ของ readEntries)
    function readDirEntry(dirEntry, basePath) {
        return new Promise((resolve, reject) => {
            const reader = dirEntry.createReader();
            const all = [];
            const readBatch = () => {
                reader.readEntries(async batch => {
                    if (batch.length === 0) {
                        // อ่าน entries ทั้งหมดของ folder นี้แล้ว → recurse
                        const sub = [];
                        for (const entry of all) {
                            const path = basePath + entry.name;
                            if (entry.isFile) {
                                await new Promise(r => entry.file(f => {
                                    try { Object.defineProperty(f, 'webkitRelativePath', { value: path, configurable: true }); } catch (_){}
                                    sub.push(f); r();
                                }, () => r()));
                            } else if (entry.isDirectory) {
                                const inner = await readDirEntry(entry, path + '/');
                                sub.push(...inner);
                            }
                        }
                        resolve(sub);
                    } else {
                        all.push(...batch);
                        readBatch(); // readEntries อาจคืนทีละ batch — ต้องเรียกซ้ำจน batch ว่าง
                    }
                }, reject);
            };
            readBatch();
        });
    }

    function applyDroppedFiles(files) {
        // ใส่ลง bulkFiles + อัปเดต UI (ข้าม input.files เพราะ DataTransfer ไม่ทำงานกับ folder-traversed File)
        const allowExt = /\.(pdf|png|jpe?g|docx?)$/i;
        bulkFiles = files.filter(f => allowExt.test(f.name));
        if (bulkFiles.length === 0) {
            Swal.fire({ icon: 'warning', title: 'ไม่พบไฟล์ที่รองรับ', text: 'รองรับ PDF / JPG / PNG / DOC / DOCX' });
            return;
        }

        const list = document.getElementById('gcBulkFileList');
        const byFolder = {};
        bulkFiles.forEach(f => {
            const path = f.webkitRelativePath || '';
            const parts = path.split('/').filter(Boolean);
            const folder = parts.length > 1 ? parts.slice(0, -1).join('/') : '(root)';
            (byFolder[folder] = byFolder[folder] || []).push(f);
        });
        const folderCount = Object.keys(byFolder).length;
        const skipped = files.length - bulkFiles.length;

        let html = `<div class="font-black text-slate-700 mb-1">📎 ${bulkFiles.length} ไฟล์`;
        if (folderCount > 1) html += ` ใน ${folderCount} โฟลเดอร์`;
        if (skipped > 0) html += ` <span class="text-rose-500">(ข้าม ${skipped} ไฟล์ที่ไม่รองรับ)</span>`;
        html += `</div>`;
        Object.entries(byFolder).slice(0, 20).forEach(([folder, arr]) => {
            html += `<div class="text-amber-700"><i class="fa-solid fa-folder mr-1"></i> ${escapeHtml(folder)} <span class="text-slate-400">(${arr.length} ไฟล์)</span></div>`;
        });
        if (folderCount > 20) html += `<div class="text-slate-400">... และอีก ${folderCount - 20} โฟลเดอร์</div>`;
        list.innerHTML = html;
        list.classList.remove('hidden');
        document.getElementById('gcBulkScanBtn').disabled = false;
    }

    document.getElementById('gcBulkFiles').addEventListener('change', bulkOnFilesSelected);

    function bulkOnFilesSelected() {
        const input = document.getElementById('gcBulkFiles');
        if (!input.files || input.files.length === 0) return;
        // กรองเฉพาะไฟล์ที่ extension ถูกต้อง (folder mode อาจมี junk)
        const allowExt = /\.(pdf|png|jpe?g|docx?)$/i;
        bulkFiles = Array.from(input.files).filter(f => allowExt.test(f.name));
        if (bulkFiles.length === 0) {
            Swal.fire({ icon: 'warning', title: 'ไม่พบไฟล์ที่รองรับ', text: 'รองรับ PDF / JPG / PNG / DOC / DOCX' });
            return;
        }

        const list = document.getElementById('gcBulkFileList');
        // จัดกลุ่มตามโฟลเดอร์ (ถ้ามี webkitRelativePath)
        const byFolder = {};
        bulkFiles.forEach(f => {
            const path = f.webkitRelativePath || '';
            const parts = path.split('/').filter(Boolean);
            const folder = parts.length > 1 ? parts.slice(0, -1).join('/') : '(root)';
            (byFolder[folder] = byFolder[folder] || []).push(f);
        });
        const folderCount = Object.keys(byFolder).length;
        const skipped = input.files.length - bulkFiles.length;

        let html = `<div class="font-black text-slate-700 mb-1">📎 ${bulkFiles.length} ไฟล์`;
        if (folderCount > 1) html += ` ใน ${folderCount} โฟลเดอร์`;
        if (skipped > 0) html += ` <span class="text-rose-500">(ข้าม ${skipped} ไฟล์ที่ไม่รองรับ)</span>`;
        html += `</div>`;
        Object.entries(byFolder).slice(0, 20).forEach(([folder, files]) => {
            html += `<div class="text-amber-700"><i class="fa-solid fa-folder mr-1"></i> ${escapeHtml(folder)} <span class="text-slate-400">(${files.length} ไฟล์)</span></div>`;
        });
        if (folderCount > 20) html += `<div class="text-slate-400">... และอีก ${folderCount - 20} โฟลเดอร์</div>`;
        list.innerHTML = html;
        list.classList.remove('hidden');
        document.getElementById('gcBulkScanBtn').disabled = false;
    }

    window.gcBulkScan = function() {
        if (!bulkFiles || bulkFiles.length === 0) return;
        const fd = new FormData();
        bulkFiles.forEach(f => {
            fd.append('files[]', f);
            fd.append('paths[]', f.webkitRelativePath || f.name);
        });
        Swal.fire({ title: 'กำลังสแกน...', html: 'กรุณารอสักครู่', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        gcPost('bulk', 'scan', fd, true).then(r => {
            Swal.close();
            if (r.status !== 'ok') { Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message}); return; }
            bulkScanReport = r.report;
            renderBulkResults(r.report);
            document.getElementById('gcBulkStep1').classList.add('hidden');
            document.getElementById('gcBulkStep2').classList.remove('hidden');
        });
    };

    function renderBulkResults(report) {
        const sum = { matched: 0, ambiguous: 0, no_match: 0, exists: 0 };
        report.forEach(r => {
            if (r.already_exists) sum.exists++;
            else if (r.status === 'matched') sum.matched++;
            else if (r.status === 'ambiguous') sum.ambiguous++;
            else sum.no_match++;
        });

        document.getElementById('gcBulkSummary').innerHTML = `
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3"><p class="text-[10px] font-black text-emerald-600 uppercase">จับคู่สำเร็จ</p><p class="text-2xl font-black text-emerald-700">${sum.matched}</p></div>
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3"><p class="text-[10px] font-black text-amber-600 uppercase">ต้องเลือก</p><p class="text-2xl font-black text-amber-700">${sum.ambiguous}</p></div>
            <div class="bg-rose-50 border border-rose-200 rounded-xl px-4 py-3"><p class="text-[10px] font-black text-rose-600 uppercase">ไม่พบในระบบ</p><p class="text-2xl font-black text-rose-700">${sum.no_match}</p></div>
            <div class="bg-slate-100 border border-slate-200 rounded-xl px-4 py-3"><p class="text-[10px] font-black text-slate-500 uppercase">มีในระบบแล้ว</p><p class="text-2xl font-black text-slate-700">${sum.exists}</p></div>
        `;

        const tbody = document.getElementById('gcBulkResultRows');
        tbody.innerHTML = report.map((r, i) => {
            const monthCell = r.month_info
                ? `<span class="gc-badge bg-amber-50 text-amber-700 border border-amber-200" title="${escapeHtml(r.month_info.folder)}">${escapeHtml(r.month_info.label)}</span>`
                : `<span class="text-slate-300 text-[11px]">—</span>`;

            if (r.already_exists) {
                return `<tr class="border-b border-slate-100 bg-slate-50">
                    <td class="px-3 py-2 font-mono text-slate-500">${escapeHtml(r.filename)}</td>
                    <td class="px-3 py-2 text-center">${monthCell}</td>
                    <td class="px-3 py-2 text-slate-500">${escapeHtml(r.extracted_name || '—')}</td>
                    <td class="px-3 py-2 text-center"><span class="gc-badge bg-slate-200 text-slate-600">มีอยู่แล้ว</span></td>
                    <td class="px-3 py-2 text-slate-400" colspan="2">ข้าม (ไฟล์/ผู้ใช้นี้มีอยู่แล้ว)</td>
                </tr>`;
            }
            const statusBadge = {
                matched:   `<span class="gc-badge gc-badge-active">✓ จับคู่ได้</span>`,
                ambiguous: `<span class="gc-badge gc-badge-pending">⚠ ซ้ำ ${r.candidates.length} คน</span>`,
                no_match:  `<span class="gc-badge gc-badge-rejected">✗ ไม่พบ</span>`,
                no_name:   `<span class="gc-badge gc-badge-rejected">ชื่อว่าง</span>`,
                upload_error: `<span class="gc-badge gc-badge-rejected">upload error</span>`,
            }[r.status] || r.status;

            const userCell = r.user
                ? `<div class="font-black text-slate-800">${escapeHtml(r.user.full_name)}</div>
                   <div class="text-[10px] text-slate-500">รหัส: ${escapeHtml(r.user.student_personnel_id || '—')} · บัตรปชช.: ${escapeHtml(r.user.citizen_id || '—')}</div>`
                : `<select id="gcBulkSel_${i}" class="text-xs border border-slate-200 rounded-lg px-2 py-1.5 bg-slate-50 w-full" onchange="gcBulkSelectChange(${i},this.value)">
                       <option value="">— เลือกผู้ใช้ (พิมพ์เพื่อค้นหา) —</option>
                       ${(r.candidates || []).map(c => `<option value='${JSON.stringify(c)}'>${escapeHtml(c.full_name)} (${escapeHtml(c.student_personnel_id || '-')})</option>`).join('')}
                   </select>
                   <input type="text" placeholder="ค้นหาชื่อ/รหัส..." class="mt-1 text-xs border border-slate-200 rounded-lg px-2 py-1 bg-white w-full" onkeyup="gcBulkSearchUser(${i}, this.value)">`;

            const checked = r.status === 'matched' ? 'checked' : '';
            return `<tr class="border-b border-slate-100" data-bulk-idx="${i}">
                <td class="px-3 py-2 font-mono text-slate-700 text-[11px]" title="${escapeHtml(r.rel_path || r.filename)}">${escapeHtml(r.filename)}</td>
                <td class="px-3 py-2 text-center">${monthCell}</td>
                <td class="px-3 py-2 text-slate-700">${escapeHtml(r.extracted_name || '—')}</td>
                <td class="px-3 py-2 text-center">${statusBadge}</td>
                <td class="px-3 py-2">${userCell}</td>
                <td class="px-3 py-2 text-center">
                    <label class="inline-flex items-center gap-1 cursor-pointer">
                        <input type="checkbox" class="gc-bulk-include w-4 h-4 accent-amber-500" ${checked} onchange="gcBulkUpdateCount()">
                        <span class="text-[11px] font-bold">นำเข้า</span>
                    </label>
                </td>
            </tr>`;
        }).join('');

        gcBulkUpdateCount();
    }

    window.gcBulkSelectChange = function(i, val) {
        if (!val) { bulkScanReport[i].user = null; return; }
        try { bulkScanReport[i].user = JSON.parse(val); } catch {}
        gcBulkUpdateCount();
    };

    let userSearchTimer;
    window.gcBulkSearchUser = function(i, q) {
        clearTimeout(userSearchTimer);
        userSearchTimer = setTimeout(() => {
            if (q.length < 2) return;
            gcPost('bulk', 'search_users', { q }).then(r => {
                if (r.status !== 'ok') return;
                const sel = document.getElementById('gcBulkSel_' + i);
                sel.innerHTML = '<option value="">— เลือก —</option>' +
                    r.users.map(u => `<option value='${JSON.stringify(u)}'>${escapeHtml(u.full_name)} (${escapeHtml(u.student_personnel_id || '-')})</option>`).join('');
            });
        }, 300);
    };

    window.gcBulkUpdateCount = function() {
        const all = document.querySelectorAll('.gc-bulk-include');
        const checks = document.querySelectorAll('.gc-bulk-include:checked');
        document.getElementById('gcBulkCommitCount').textContent = checks.length;
        document.getElementById('gcBulkCommitBtn').disabled = checks.length === 0;

        // Sync master checkbox indeterminate state
        const master = document.getElementById('gcBulkMasterCheck');
        if (master) {
            master.checked = all.length > 0 && checks.length === all.length;
            master.indeterminate = checks.length > 0 && checks.length < all.length;
        }
    };

    /**
     * เลือกตามเงื่อนไข:
     *   'all'       — เลือกทุก row (รวม no_match)
     *   'matched'   — เฉพาะ status === 'matched'
     *   'with_user' — มี user (จาก match หรือ admin เลือกเอง)
     *   'none'      — ยกเลิกทั้งหมด
     */
    window.gcBulkSelectAll = function(mode) {
        document.querySelectorAll('.gc-bulk-include').forEach(cb => {
            const tr = cb.closest('tr');
            const idx = parseInt(tr.dataset.bulkIdx, 10);
            const r = bulkScanReport[idx];
            let on = false;
            switch (mode) {
                case 'all':       on = true; break;
                case 'matched':   on = r.status === 'matched'; break;
                case 'with_user': on = !!r.user; break;
                case 'none':      on = false; break;
            }
            cb.checked = on;
        });
        gcBulkUpdateCount();
    };

    window.gcBulkToggleMaster = function(masterCb) {
        const on = masterCb.checked;
        document.querySelectorAll('.gc-bulk-include').forEach(cb => cb.checked = on);
        gcBulkUpdateCount();
    };

    window.gcBulkCommit = function() {
        const items = [];
        document.querySelectorAll('.gc-bulk-include:checked').forEach(cb => {
            const tr = cb.closest('tr');
            const idx = parseInt(tr.dataset.bulkIdx);
            const r = bulkScanReport[idx];
            items.push({
                index: idx,
                name: r.user ? r.user.full_name : (r.extracted_name || ''),
                user_id: r.user ? r.user.id : null,
                citizen_id: r.user ? r.user.citizen_id : null,
                application_date: r.month_info ? r.month_info.application_date : null,
                coverage_start: r.month_info ? r.month_info.application_date : null,
            });
        });
        if (items.length === 0) { Swal.fire({icon:'info',title:'ยังไม่ได้เลือกรายการ'}); return; }

        const fd = new FormData();
        bulkFiles.forEach(f => {
            fd.append('files[]', f);
            fd.append('paths[]', f.webkitRelativePath || f.name);
        });
        fd.append('items', JSON.stringify(items));

        Swal.fire({ title: 'กำลังนำเข้า...', html: `กำลังประมวลผล ${items.length} รายการ`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        gcPost('bulk', 'commit', fd, true).then(r => {
            Swal.close();
            if (r.status !== 'ok') { Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message}); return; }
            document.getElementById('gcBulkStep2').classList.add('hidden');
            document.getElementById('gcBulkStep3').classList.remove('hidden');
            document.getElementById('gcBulkResultText').innerHTML =
                `สร้าง <b class="text-emerald-600 text-lg">${r.created}</b> รายการ` +
                (r.skipped > 0 ? ` · ข้าม <b class="text-slate-500">${r.skipped}</b>` : '') +
                (r.errors && r.errors.length > 0 ? `<div class="mt-3 text-xs text-rose-500 max-h-32 overflow-y-auto">${r.errors.map(e => '• ' + escapeHtml(e)).join('<br>')}</div>` : '');
        });
    };

    // ── init ────────────────────────────────────────────────────────
    gcLoadMembers(1);
    loadCharts();
})();
</script>

<!-- ⚡ Cinematic KPI Morph (overdrive) ─────────────────────────── -->
<script src="../assets/js/kpi-morph.js"></script>
<script>
    (function bootKPIMorph(){
        const init = () => {
            if (!window.KPIMorph) { return setTimeout(init, 50); }
            window.KPIMorph.init({
                csrf: '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>',
                endpoint: 'ajax_kpi_override.php',
                editable: <?= $canEditKPI ? 'true' : 'false' ?>,
            });
        };
        init();
    })();
</script>
