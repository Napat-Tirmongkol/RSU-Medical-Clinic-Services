<?php
// Sub-view: Chain of Command / Org Chart manager
// - Drag-drop tree of positions (left)
// - Members panel for selected position (right)
$pdo = db();

// Auto-migrate (idempotent — same as ajax_clinic_master.php)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_org_positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NULL,
        title VARCHAR(255) NOT NULL,
        short_title VARCHAR(100) NULL,
        description TEXT NULL,
        level TINYINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        card_style ENUM('premium','simple') NOT NULL DEFAULT 'simple',
        show_section_header TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_parent (parent_id),
        INDEX idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_org_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        position_id INT NULL,
        prefix VARCHAR(50) NULL,
        full_name VARCHAR(255) NOT NULL,
        photo_url VARCHAR(500) NULL,
        license_no VARCHAR(100) NULL,
        responsibilities TEXT NULL,
        department VARCHAR(255) NULL,
        staff_id INT NULL,
        user_id INT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_position (position_id),
        INDEX idx_user (user_id),
        INDEX idx_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

$totalPositions = (int)$pdo->query("SELECT COUNT(*) FROM sys_org_positions WHERE is_active = 1")->fetchColumn();
$totalMembers   = (int)$pdo->query("SELECT COUNT(*) FROM sys_org_members WHERE is_active = 1")->fetchColumn();
$unassignedMembers = (int)$pdo->query("SELECT COUNT(*) FROM sys_org_members WHERE is_active = 1 AND position_id IS NULL")->fetchColumn();

// Build the rendered preview HTML server-side so admins see the actual
// org chart users would see (with users feature gated, this is the only
// way to inspect the result while editing).
require_once __DIR__ . '/../../../includes/org_chart_renderer.php';
$ocPreviewPositions = $pdo->query("SELECT * FROM sys_org_positions WHERE is_active = 1 ORDER BY level ASC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$ocPreviewMembers   = $pdo->query("SELECT * FROM sys_org_members WHERE is_active = 1 ORDER BY position_id ASC, display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$ocPreview = ocrBuildChart($ocPreviewPositions, $ocPreviewMembers, null);
?>
<div class="max-w-[1400px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <!-- Header -->
    <div class="mb-5 flex items-center gap-4 flex-wrap">
        <div class="w-12 h-12 bg-emerald-50 rounded-xl shadow-sm border border-emerald-100 flex items-center justify-center text-emerald-600 text-xl">
            <i class="fa-solid fa-sitemap"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">Chain of Command — ผังองค์กร</h2>
            <p class="text-slate-500 text-sm font-medium">จัดการตำแหน่งและสมาชิกในผังองค์กรคลินิก · ลากเพื่อเรียงลำดับ/ย้ายระดับ</p>
        </div>
        <div class="flex gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-widest">
                <i class="fa-solid fa-layer-group text-[9px]"></i>
                <span id="oc-total-pos"><?= $totalPositions ?></span> ตำแหน่ง
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black uppercase tracking-widest">
                <i class="fa-solid fa-users text-[9px]"></i>
                <span id="oc-total-mem"><?= $totalMembers ?></span> สมาชิก
            </span>
        </div>
    </div>

    <!-- View toggle: Manage vs Preview -->
    <div class="oc-view-tabs">
        <button type="button" class="oc-view-tab is-active" data-view="manage" onclick="ocSwitchView('manage')">
            <i class="fa-solid fa-pen-to-square"></i> จัดการ
        </button>
        <button type="button" class="oc-view-tab" data-view="preview" onclick="ocSwitchView('preview')">
            <i class="fa-solid fa-eye"></i> ดูผัง
        </button>
    </div>

    <!-- ── View: Manage ─────────────────────────────────────────────────── -->
    <div id="oc-view-manage">

    <!-- Toolbar -->
    <div class="flex flex-wrap gap-2 mb-4">
        <button onclick="ocAddPosition(null)" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-black shadow-sm">
            <i class="fa-solid fa-plus"></i> เพิ่มตำแหน่งระดับบนสุด
        </button>
        <button onclick="ocAddMember(null)" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-black shadow-sm">
            <i class="fa-solid fa-user-plus"></i> เพิ่มสมาชิก
        </button>
        <button onclick="ocLoadAll()" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-white hover:bg-slate-50 border border-slate-200 text-slate-600 text-sm font-bold">
            <i class="fa-solid fa-rotate"></i> รีเฟรช
        </button>
        <span class="ml-auto inline-flex items-center text-[12px] text-slate-500 font-bold">
            <i class="fa-solid fa-circle-info text-slate-400 mr-1.5"></i> ลากตำแหน่ง/สมาชิก เพื่อจัดลำดับหรือย้ายลำดับชั้น
        </span>
    </div>

    <!-- Layout: tree (left) + members (right) -->
    <div class="grid grid-cols-1 lg:grid-cols-[420px_1fr] gap-5">
        <!-- ── Tree Panel ───────────────────────────────────────────────── -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-4 flex flex-col" style="min-height:520px;">
            <div class="flex items-center justify-between mb-3 px-1">
                <h3 class="text-[13px] font-black uppercase tracking-widest text-slate-500">
                    <i class="fa-solid fa-folder-tree text-emerald-500 mr-1"></i>โครงสร้างตำแหน่ง
                </h3>
                <span class="text-[10px] font-bold text-slate-400">คลิกเพื่อเลือก</span>
            </div>
            <div id="oc-tree" class="flex-1 overflow-y-auto pr-1">
                <div class="text-center py-8 text-slate-400 text-sm">
                    <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...
                </div>
            </div>
        </div>

        <!-- ── Members Panel ────────────────────────────────────────────── -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-4 flex flex-col" style="min-height:520px;">
            <div class="flex items-center justify-between mb-3 px-1 flex-wrap gap-2">
                <div>
                    <h3 class="text-[13px] font-black uppercase tracking-widest text-slate-500">
                        <i class="fa-solid fa-users text-blue-500 mr-1"></i>สมาชิก
                    </h3>
                    <p class="text-base font-black text-slate-800 mt-1" id="oc-current-pos-title">ทั้งหมด (ยังไม่ได้เลือกตำแหน่ง)</p>
                </div>
                <div class="flex gap-2">
                    <button id="oc-add-mem-btn" onclick="ocAddMember(window.ocCurrentPositionId || null)"
                            class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold shadow-sm">
                        <i class="fa-solid fa-plus"></i> เพิ่มสมาชิก
                    </button>
                </div>
            </div>
            <div id="oc-members" class="flex-1 overflow-y-auto pr-1">
                <div class="text-center py-8 text-slate-400 text-sm">
                    <i class="fa-solid fa-arrow-left mr-2"></i>เลือกตำแหน่งจากด้านซ้าย หรือดูสมาชิกทั้งหมด
                </div>
            </div>
            <?php if ($unassignedMembers > 0): ?>
            <div class="mt-3 p-3 rounded-2xl bg-amber-50 border border-amber-100 text-[12px] text-amber-700 font-bold flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation"></i>
                มีสมาชิก <span id="oc-unassigned-count"><?= $unassignedMembers ?></span> คนที่ยังไม่ได้จัดเข้าตำแหน่ง —
                <button onclick="ocSelectPosition(null, 'ยังไม่จัด')" class="underline font-black">ดูทั้งหมด</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    </div><!-- /#oc-view-manage -->

    <!-- ── View: Preview (rendered chart, what users will see) ────────── -->
    <div id="oc-view-preview" style="display:none;">
        <div class="oc-preview-toolbar">
            <div class="flex items-center gap-2 text-[12px] font-bold text-slate-500">
                <i class="fa-solid fa-circle-info text-amber-400"></i>
                แสดงผังตามที่ผู้ใช้จะเห็น (ไม่ไฮไลต์ "ตัวเอง" เพราะเป็นมุมมอง admin)
            </div>
            <button type="button" onclick="ocRefreshPreview()" class="oc-preview-refresh-btn">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
        </div>

        <div class="oc-preview-frame">
            <div class="oc-preview-stats">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white shadow">
                    <i class="fa-solid fa-people-group"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Chain of Command</p>
                    <h3 class="text-base font-black text-slate-800 mt-0.5">โครงสร้างคลินิก</h3>
                    <p class="text-[11px] font-bold text-slate-500 mt-0.5">
                        <span id="oc-preview-pos-count"><?= $ocPreview['totalPositions'] ?></span> ตำแหน่ง ·
                        <span id="oc-preview-mem-count"><?= $ocPreview['totalMembers'] ?></span> สมาชิก
                    </p>
                </div>
            </div>

            <div class="oc-preview-chart" id="oc-preview-html">
                <?php if (empty($ocPreviewPositions) || empty($ocPreviewMembers)): ?>
                    <div class="text-center py-14 text-slate-400">
                        <i class="fa-solid fa-folder-open text-5xl mb-3 block text-slate-200"></i>
                        <p class="text-sm font-bold">ยังไม่มีข้อมูลผังองค์กร</p>
                        <p class="text-[11px] font-medium mt-1">เพิ่มตำแหน่งและสมาชิกในแท็บ "จัดการ" ก่อน</p>
                    </div>
                <?php else: ?>
                    <?= $ocPreview['html'] ?>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /#oc-view-preview -->

</div>

<!-- ══════════════ Modal: Position ══════════════ -->
<div id="oc-pos-modal" class="oc-modal" style="display:none;">
    <div class="oc-modal-card">
        <div class="flex items-center gap-2 mb-4">
            <i class="fa-solid fa-sitemap text-emerald-600 text-lg"></i>
            <h3 id="oc-pos-modal-title" class="text-base font-black text-slate-800">เพิ่มตำแหน่ง</h3>
        </div>
        <form id="oc-pos-form" onsubmit="ocSavePosition(event)">
            <input type="hidden" name="id" id="oc-pos-id">
            <input type="hidden" name="parent_id" id="oc-pos-parent">

            <div id="oc-pos-parent-info" class="mb-3 p-2.5 rounded-xl bg-slate-50 text-[12px] font-bold text-slate-600" style="display:none;"></div>

            <div class="oc-form-row">
                <label>ชื่อตำแหน่ง <span class="text-rose-500">*</span></label>
                <input type="text" name="title" id="oc-pos-title" required maxlength="255" placeholder="เช่น ผู้อำนวยการ, หัวหน้าพยาบาล">
            </div>
            <div class="oc-form-row">
                <label>ตัวย่อ (เลือกใส่)</label>
                <input type="text" name="short_title" id="oc-pos-short" maxlength="100" placeholder="เช่น ผอ., หน. พยาบาล">
            </div>
            <div class="oc-form-row">
                <label>คำอธิบาย</label>
                <textarea name="description" id="oc-pos-desc" rows="2" placeholder="ขอบเขตหน้าที่ของตำแหน่งนี้"></textarea>
            </div>
            <div class="oc-form-row">
                <label>รูปแบบการ์ดในผัง</label>
                <div class="flex gap-2">
                    <label class="oc-radio-card">
                        <input type="radio" name="card_style" value="premium" checked>
                        <span><i class="fa-solid fa-crown"></i> Premium <small>(ระดับบน)</small></span>
                    </label>
                    <label class="oc-radio-card">
                        <input type="radio" name="card_style" value="simple">
                        <span><i class="fa-solid fa-id-card"></i> Simple <small>(ระดับล่าง)</small></span>
                    </label>
                </div>
            </div>
            <div class="oc-form-row">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="show_section_header" id="oc-pos-section" checked>
                    <span class="text-sm font-bold text-slate-700">โชว์หัวข้อกลุ่ม (เช่น "พยาบาลประจำการ") เมื่อ render ผัง</span>
                </label>
            </div>
            <div class="flex gap-2 justify-end mt-4">
                <button type="button" onclick="ocCloseModal('oc-pos-modal')" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-black">
                    <i class="fa-solid fa-floppy-disk mr-1"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════ Modal: Member ══════════════ -->
<div id="oc-mem-modal" class="oc-modal" style="display:none;">
    <div class="oc-modal-card oc-modal-card-lg">
        <div class="flex items-center gap-2 mb-4">
            <i class="fa-solid fa-user-plus text-blue-600 text-lg"></i>
            <h3 id="oc-mem-modal-title" class="text-base font-black text-slate-800">เพิ่มสมาชิก</h3>
        </div>
        <form id="oc-mem-form" onsubmit="ocSaveMember(event)" enctype="multipart/form-data">
            <input type="hidden" name="id" id="oc-mem-id">

            <div class="grid grid-cols-1 sm:grid-cols-[140px_1fr] gap-4">
                <!-- Photo -->
                <div>
                    <label class="oc-form-row label-block">รูปถ่าย</label>
                    <div class="oc-photo-upload">
                        <img id="oc-mem-photo-preview" src="" alt="" class="w-32 h-32 rounded-2xl object-cover border-2 border-slate-200 bg-slate-50" style="display:none;">
                        <div id="oc-mem-photo-placeholder" class="w-32 h-32 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 flex flex-col items-center justify-center text-slate-400">
                            <i class="fa-solid fa-camera text-2xl mb-1"></i>
                            <span class="text-[10px] font-bold">เลือกรูป</span>
                        </div>
                        <input type="file" name="photo" id="oc-mem-photo" accept="image/*" class="mt-2 text-[11px]" onchange="ocPreviewPhoto(this)">
                        <input type="hidden" name="photo_url" id="oc-mem-photo-url">
                        <small class="block text-[10px] text-slate-400 mt-1">jpg/png/webp ≤ 5MB</small>
                    </div>
                </div>

                <!-- Form fields -->
                <div>
                    <div class="oc-form-row">
                        <label>ตำแหน่ง</label>
                        <select name="position_id" id="oc-mem-position">
                            <option value="">— ยังไม่จัดเข้าตำแหน่ง —</option>
                        </select>
                    </div>
                    <div class="oc-form-row grid grid-cols-[100px_1fr] gap-2">
                        <div>
                            <label>คำนำหน้า</label>
                            <input type="text" name="prefix" id="oc-mem-prefix" maxlength="50" placeholder="รศ.ดร., นาย">
                        </div>
                        <div>
                            <label>ชื่อ-สกุล <span class="text-rose-500">*</span></label>
                            <input type="text" name="full_name" id="oc-mem-name" required maxlength="255">
                        </div>
                    </div>
                    <div class="oc-form-row">
                        <label>หน่วยงาน / แผนก</label>
                        <input type="text" name="department" id="oc-mem-dept" maxlength="255" placeholder="เช่น คลินิกเวชกรรม ม.รังสิต">
                    </div>
                    <div class="oc-form-row">
                        <label>เลขใบอนุญาตฯ <small class="text-slate-400 font-bold">(สำหรับการ์ด Premium)</small></label>
                        <input type="text" name="license_no" id="oc-mem-license" maxlength="100" placeholder="6811334711">
                    </div>
                    <div class="oc-form-row">
                        <label>หน้าที่ / บทบาท <small class="text-slate-400 font-bold">(สำหรับการ์ด Premium)</small></label>
                        <textarea name="responsibilities" id="oc-mem-resp" rows="3" placeholder="• การรักษาและตรวจวินิจฉัยพยาบาลขั้นที่ 1&#10;• การประสาน&#10;• พยาบาลการแพทย์มหาวิทยาลัยรังสิต"></textarea>
                    </div>

                    <!-- Staff account link picker -->
                    <div class="oc-form-row">
                        <label>ลิงก์กับบัญชี Staff <small class="text-slate-400 font-bold">(admin login จาก Identity & Governance — เลือกเพื่อให้ระบบ highlight ตอน staff คนนั้นล็อกอิน)</small></label>
                        <input type="hidden" name="staff_id" id="oc-mem-staff-id">

                        <!-- Linked badge (when already linked) -->
                        <div id="oc-mem-staff-linked" class="oc-staff-linked" style="display:none;">
                            <i class="fa-solid fa-link text-emerald-500"></i>
                            <span class="flex-1" id="oc-mem-staff-linked-name"></span>
                            <button type="button" class="oc-staff-unlink" onclick="ocUnlinkStaff()" title="ยกเลิกการลิงก์">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Search box -->
                        <div id="oc-mem-staff-search-wrap" class="relative">
                            <input type="text" id="oc-mem-staff-search" placeholder="ค้นหาด้วย ชื่อ / username / อีเมล…" autocomplete="off">
                            <div id="oc-mem-staff-results" class="oc-staff-results" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 justify-end mt-4">
                <button type="button" onclick="ocCloseModal('oc-mem-modal')" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-black">
                    <i class="fa-solid fa-floppy-disk mr-1"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Tree styling — nested ULs with custom indent */
.oc-tree-list { list-style: none; padding: 0; margin: 0; }
.oc-tree-list .oc-tree-list { padding-left: 1.25rem; border-left: 2px dashed #e2e8f0; margin-left: .75rem; }
.oc-tree-node { padding: .55rem .65rem; border-radius: .65rem; background: #f8fafc; border: 1px solid #e2e8f0; margin-bottom: .35rem; cursor: grab; transition: background .15s, border-color .15s; display: flex; align-items: center; gap: .5rem; }
.oc-tree-node:hover { background: #ecfdf5; border-color: #a7f3d0; }
.oc-tree-node.is-selected { background: #d1fae5; border-color: #10b981; box-shadow: 0 2px 6px rgba(16,185,129,.2); }
.oc-tree-node.dragging { opacity: .5; cursor: grabbing; }
.oc-tree-node.sortable-ghost { opacity: .35; background: #fef3c7 !important; border-color: #fbbf24 !important; }
.oc-tree-node-title { flex: 1; font-weight: 800; color: #0f172a; font-size: .85rem; }
.oc-tree-node-meta  { font-size: .7rem; color: #64748b; font-weight: 600; }
.oc-tree-node-actions { display: flex; gap: .25rem; opacity: 0; transition: opacity .15s; }
.oc-tree-node:hover .oc-tree-node-actions { opacity: 1; }
.oc-tree-node-actions button { width: 1.65rem; height: 1.65rem; border-radius: .4rem; background: #fff; border: 1px solid #e2e8f0; color: #64748b; cursor: pointer; font-size: .7rem; }
.oc-tree-node-actions button:hover { background: #f0fdf4; color: #059669; border-color: #a7f3d0; }
.oc-tree-node-actions .oc-btn-danger:hover { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.oc-tree-style-badge { font-size: .6rem; font-weight: 800; padding: .1rem .35rem; border-radius: .25rem; text-transform: uppercase; }
.oc-tree-style-premium { background: #fef3c7; color: #b45309; }
.oc-tree-style-simple  { background: #e0e7ff; color: #4338ca; }
.oc-empty-children-drop { min-height: 1.5rem; border: 2px dashed transparent; border-radius: .5rem; margin-top: .25rem; transition: border-color .15s; }
.oc-tree-list.oc-drop-active .oc-empty-children-drop { border-color: #fbbf24; }

/* Member card in admin panel */
.oc-mem-card { display: flex; align-items: center; gap: .75rem; padding: .75rem; background: #fff; border: 1px solid #e2e8f0; border-radius: .85rem; margin-bottom: .5rem; cursor: grab; transition: border-color .15s, box-shadow .15s; }
.oc-mem-card:hover { border-color: #93c5fd; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.oc-mem-card.dragging { opacity: .5; }
.oc-mem-card.sortable-ghost { opacity: .35; background: #fef3c7 !important; border-color: #fbbf24 !important; }
.oc-mem-photo { width: 3rem; height: 3rem; border-radius: .65rem; object-fit: cover; background: #f1f5f9; border: 1px solid #e2e8f0; flex-shrink: 0; }
.oc-mem-photo-placeholder { width: 3rem; height: 3rem; border-radius: .65rem; background: linear-gradient(135deg,#34d399,#10b981); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-weight: 900; font-size: 1rem; }
.oc-mem-info { flex: 1; min-width: 0; }
.oc-mem-name { font-weight: 800; color: #0f172a; font-size: .9rem; line-height: 1.25; }
.oc-mem-meta { font-size: .72rem; color: #64748b; font-weight: 600; margin-top: .15rem; }
.oc-mem-actions { display: flex; gap: .25rem; }
.oc-mem-actions button { width: 1.85rem; height: 1.85rem; border-radius: .45rem; border: 1px solid #e2e8f0; background: #fff; color: #475569; cursor: pointer; font-size: .75rem; }
.oc-mem-actions button:hover { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
.oc-mem-actions .oc-btn-danger:hover { background: #fee2e2; color: #dc2626; border-color: #fecaca; }

/* Modal */
.oc-modal { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px); }
.oc-modal-card { background: #fff; border-radius: 1rem; padding: 1.5rem; width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 80px rgba(0,0,0,.25); }
.oc-modal-card-lg { max-width: 720px; }

.oc-form-row { display: flex; flex-direction: column; gap: .25rem; margin-bottom: .85rem; }
.oc-form-row.label-block { display: block; }
.oc-form-row label { font-size: .78rem; font-weight: 800; color: #0f172a; }
.oc-form-row input[type="text"], .oc-form-row input[type="number"], .oc-form-row select, .oc-form-row textarea {
    padding: .55rem .75rem; border: 1.5px solid #e2e8f0; border-radius: .55rem; font-size: .85rem; font-family: 'Sarabun', sans-serif; outline: none; width: 100%;
}
.oc-form-row input:focus, .oc-form-row select:focus, .oc-form-row textarea:focus {
    border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.15);
}

.oc-radio-card { flex: 1; cursor: pointer; }
.oc-radio-card input { display: none; }
.oc-radio-card span { display: block; padding: .55rem .75rem; border: 1.5px solid #e2e8f0; border-radius: .55rem; font-size: .82rem; font-weight: 700; color: #475569; text-align: center; transition: all .15s; }
.oc-radio-card input:checked + span { border-color: #10b981; background: #ecfdf5; color: #065f46; box-shadow: 0 0 0 3px rgba(16,185,129,.12); }

/* Empty state in members list */
.oc-empty-state { text-align: center; padding: 2rem 1rem; color: #94a3b8; font-size: .85rem; }

/* Staff link picker */
.oc-staff-linked { display: flex; align-items: center; gap: .5rem; padding: .55rem .75rem; background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: .55rem; font-size: .85rem; font-weight: 700; color: #065f46; }
.oc-staff-unlink { width: 1.5rem; height: 1.5rem; border-radius: .35rem; background: #fff; border: 1px solid #a7f3d0; color: #065f46; cursor: pointer; font-size: .75rem; display: flex; align-items: center; justify-content: center; }
.oc-staff-unlink:hover { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.oc-staff-results { position: absolute; top: 100%; left: 0; right: 0; max-height: 280px; overflow-y: auto; background: #fff; border: 1.5px solid #e2e8f0; border-radius: .55rem; margin-top: .25rem; box-shadow: 0 10px 30px rgba(0,0,0,.1); z-index: 10; }
.oc-staff-result-item { padding: .55rem .75rem; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: .82rem; transition: background .12s; }
.oc-staff-result-item:hover, .oc-staff-result-item.is-active { background: #ecfdf5; }
.oc-staff-result-item:last-child { border-bottom: none; }
.oc-staff-result-name { font-weight: 800; color: #0f172a; }
.oc-staff-result-meta { font-size: .72rem; color: #64748b; font-weight: 600; margin-top: .15rem; }
.oc-staff-no-result { padding: .75rem; text-align: center; color: #94a3b8; font-size: .82rem; font-weight: 600; }

/* Member card linked badge */
.oc-mem-linked-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .1rem .4rem; border-radius: .35rem; background: #ecfdf5; color: #065f46; font-size: .65rem; font-weight: 800; border: 1px solid #a7f3d0; margin-left: .35rem; }

/* View toggle tabs (Manage / Preview) */
.oc-view-tabs { display: inline-flex; gap: .35rem; padding: .35rem; background: #f1f5f9; border-radius: .9rem; margin-bottom: 1.25rem; }
.oc-view-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .55rem 1.1rem; border-radius: .65rem; font-size: .82rem; font-weight: 800; color: #64748b; background: transparent; border: none; cursor: pointer; transition: all .18s; }
.oc-view-tab:hover { color: #0f172a; background: rgba(255,255,255,.65); }
.oc-view-tab.is-active { background: #fff; color: #047857; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.oc-view-tab i { font-size: .8rem; }

/* Preview pane */
.oc-preview-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; padding: .8rem 1rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: .9rem; margin-bottom: 1rem; }
.oc-preview-refresh-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .9rem; border-radius: .55rem; background: #fff; border: 1px solid #fde68a; color: #b45309; font-size: .78rem; font-weight: 800; cursor: pointer; transition: all .15s; }
.oc-preview-refresh-btn:hover { background: #fef3c7; border-color: #fcd34d; }
.oc-preview-refresh-btn:disabled { opacity: .55; cursor: wait; }
.oc-preview-frame { background: #f8faff; border: 1.5px solid #e2e8f0; border-radius: 1.5rem; padding: 1.5rem 1rem; }
.oc-preview-stats { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; background: #fff; border: 1px solid #f1f5f9; border-radius: 1.5rem; margin-bottom: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
.oc-preview-chart { background: #fff; border: 1px solid #f1f5f9; border-radius: 1.5rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
@media (max-width: 640px) {
    .oc-preview-frame { padding: .75rem .5rem; }
    .oc-preview-chart { padding: .85rem; }
}
</style>

<script src="../assets/vendor/Sortable.min.js"></script>
<script>
(function() {
    // CSRF is defined later in portal/index.php — read lazily on each request
    function getCSRF() {
        return (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '';
    }

    // State
    window.ocPositions = [];
    window.ocMembers   = [];
    window.ocCurrentPositionId = null;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function ocPost(entity, action, data, isFormData = false) {
        let body;
        if (isFormData) {
            body = data;
            body.append('entity', entity);
            body.append('action', action);
            body.append('csrf_token', getCSRF());
        } else {
            body = new FormData();
            body.append('entity', entity);
            body.append('action', action);
            body.append('csrf_token', getCSRF());
            Object.entries(data || {}).forEach(([k, v]) => {
                if (v !== null && v !== undefined) body.append(k, v);
            });
        }
        const res = await fetch('ajax_clinic_master.php', { method: 'POST', body });
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            // Server returned non-JSON (e.g. 403 CSRF error page)
            return { ok: false, message: text.substring(0, 200) || ('HTTP ' + res.status) };
        }
    }

    // ── Load all data ────────────────────────────────────────────────
    window.ocLoadAll = async function() {
        const [posRes, memRes] = await Promise.all([
            ocPost('position', 'list', {}),
            ocPost('org_member', 'list', { all: 1 }),
        ]);
        if (!posRes.ok) { Swal.fire('โหลดตำแหน่งล้มเหลว', posRes.message || '', 'error'); return; }
        if (!memRes.ok) { Swal.fire('โหลดสมาชิกล้มเหลว', memRes.message || '', 'error'); return; }
        window.ocPositions = posRes.data || [];
        window.ocMembers = memRes.data || [];
        ocRenderTree();
        ocRenderMembers();
        ocSyncPositionDropdown();
        document.getElementById('oc-total-pos').textContent = window.ocPositions.length;
        document.getElementById('oc-total-mem').textContent = window.ocMembers.length;
    };

    // ── Build tree from flat list ────────────────────────────────────
    function ocBuildTree(flatList) {
        const map = {};
        const roots = [];
        flatList.forEach(p => { map[p.id] = { ...p, children: [] }; });
        flatList.forEach(p => {
            if (p.parent_id && map[p.parent_id]) {
                map[p.parent_id].children.push(map[p.id]);
            } else {
                roots.push(map[p.id]);
            }
        });
        return roots;
    }

    function ocCountMembers(positionId) {
        return window.ocMembers.filter(m => Number(m.position_id) === Number(positionId)).length;
    }

    // ── Render tree ──────────────────────────────────────────────────
    window.ocRenderTree = function() {
        const tree = ocBuildTree(window.ocPositions);
        const el = document.getElementById('oc-tree');
        if (tree.length === 0) {
            el.innerHTML = '<div class="oc-empty-state"><i class="fa-solid fa-folder-open text-3xl mb-2 block text-slate-300"></i>ยังไม่มีตำแหน่ง<br><button onclick="ocAddPosition(null)" class="mt-2 text-emerald-600 underline font-bold">เพิ่มตำแหน่งแรก</button></div>';
            return;
        }
        // Root needs its own .oc-tree-list wrapper so Sortable can pick up
        // top-level positions and admins can reorder them. Empty data-parent-id
        // signals "root" to the move/reorder handlers.
        el.innerHTML = `<ul class="oc-tree-list" data-parent-id="">${ocRenderTreeNodes(tree, null)}</ul>`;
        ocInitSortableTree();
    };

    function ocRenderTreeNodes(nodes, parentId) {
        const items = nodes.map(n => `
            <li>
                <div class="oc-tree-node" data-id="${n.id}" onclick="event.stopPropagation(); ocSelectPosition(${n.id}, ${JSON.stringify(n.title)})">
                    <i class="fa-solid fa-grip-vertical text-slate-400 text-[10px] cursor-grab"></i>
                    <span class="oc-tree-node-title">${esc(n.title)}</span>
                    <span class="oc-tree-style-badge ${n.card_style === 'premium' ? 'oc-tree-style-premium' : 'oc-tree-style-simple'}">${n.card_style === 'premium' ? 'P' : 'S'}</span>
                    <span class="oc-tree-node-meta">${ocCountMembers(n.id)} คน</span>
                    <div class="oc-tree-node-actions" onclick="event.stopPropagation()">
                        <button onclick="ocAddPosition(${n.id})" title="เพิ่มลูก"><i class="fa-solid fa-plus"></i></button>
                        <button onclick="ocEditPosition(${n.id})" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                        <button class="oc-btn-danger" onclick="ocDeletePosition(${n.id}, ${JSON.stringify(n.title)})" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
                <ul class="oc-tree-list" data-parent-id="${n.id}">
                    ${ocRenderTreeNodes(n.children, n.id)}
                </ul>
            </li>
        `).join('');
        return items || '<li class="oc-empty-children-drop"></li>';
    }

    function ocInitSortableTree() {
        document.querySelectorAll('.oc-tree-list').forEach(list => {
            new Sortable(list, {
                group: 'oc-tree',
                animation: 180,
                handle: '.oc-tree-node',
                draggable: 'li',
                ghostClass: 'sortable-ghost',
                fallbackOnBody: true,
                swapThreshold: 0.6,
                onEnd: async (evt) => {
                    const id = parseInt(evt.item.querySelector('.oc-tree-node')?.dataset.id || '0', 10);
                    if (!id) return;
                    const parentList = evt.to;
                    const newParentId = parentList.dataset.parentId ? parseInt(parentList.dataset.parentId, 10) : null;
                    const oldParentId = evt.from.dataset.parentId ? parseInt(evt.from.dataset.parentId, 10) : null;

                    // If moved to a different list — call move
                    if (newParentId !== oldParentId) {
                        const r = await ocPost('position', 'move', { id, parent_id: newParentId || '' });
                        if (!r.ok) { Swal.fire('ย้ายไม่สำเร็จ', r.message || '', 'error'); ocLoadAll(); return; }
                    }
                    // Always update sort_order of siblings in destination list
                    const siblings = Array.from(parentList.querySelectorAll(':scope > li > .oc-tree-node'))
                        .map(n => n.dataset.id).filter(Boolean);
                    if (siblings.length > 0) {
                        await ocPost('position', 'reorder', { ids: siblings.join(',') });
                    }
                    showPortalToast('อัปเดตโครงสร้างแล้ว', 'success');
                    ocLoadAll();
                },
            });
        });
    }

    // ── Select position + render members ─────────────────────────────
    window.ocSelectPosition = function(positionId, title) {
        window.ocCurrentPositionId = positionId;
        document.querySelectorAll('.oc-tree-node').forEach(n => n.classList.remove('is-selected'));
        if (positionId) {
            const node = document.querySelector(`.oc-tree-node[data-id="${positionId}"]`);
            if (node) node.classList.add('is-selected');
        }
        document.getElementById('oc-current-pos-title').textContent = title || 'ทั้งหมด';
        ocRenderMembers();
    };

    window.ocRenderMembers = function() {
        const el = document.getElementById('oc-members');
        let list;
        if (window.ocCurrentPositionId === null) {
            // Show unassigned members when explicitly chosen, otherwise all
            list = window.ocMembers;
        } else {
            list = window.ocMembers.filter(m => Number(m.position_id) === Number(window.ocCurrentPositionId));
        }
        if (list.length === 0) {
            el.innerHTML = `<div class="oc-empty-state"><i class="fa-solid fa-users-slash text-3xl mb-2 block text-slate-300"></i>ยังไม่มีสมาชิก<br><button onclick="ocAddMember(window.ocCurrentPositionId)" class="mt-2 text-blue-600 underline font-bold">เพิ่มคนแรก</button></div>`;
            return;
        }
        el.innerHTML = `<ul class="oc-mem-list" id="oc-mem-list" style="list-style:none;padding:0;margin:0;">` +
            list.map(m => ocRenderMemberCard(m)).join('') +
            `</ul>`;
        ocInitMembersSortable();
    };

    function ocRenderMemberCard(m) {
        const photo = m.photo_url
            ? `<img src="${esc(m.photo_url)}" class="oc-mem-photo" alt="">`
            : `<div class="oc-mem-photo-placeholder">${esc((m.full_name || '?').charAt(0))}</div>`;
        const positionLabel = m.position_title ? esc(m.position_title) : '<em class="text-amber-600">— ยังไม่จัด —</em>';
        const linkedBadge = m.staff_id
            ? `<span class="oc-mem-linked-badge" title="ลิงก์กับบัญชี Staff #${m.staff_id}"><i class="fa-solid fa-link text-[8px]"></i> Linked</span>`
            : '';
        return `
            <li class="oc-mem-card" data-id="${m.id}">
                <i class="fa-solid fa-grip-vertical text-slate-300 text-[10px]"></i>
                ${photo}
                <div class="oc-mem-info">
                    <div class="oc-mem-name">${esc((m.prefix || '') + ' ' + m.full_name).trim()}${linkedBadge}</div>
                    <div class="oc-mem-meta">${positionLabel}${m.department ? ' · ' + esc(m.department) : ''}</div>
                </div>
                <div class="oc-mem-actions">
                    <button onclick="ocEditMember(${m.id})" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                    <button class="oc-btn-danger" onclick="ocDeleteMember(${m.id}, ${JSON.stringify(m.full_name)})" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                </div>
            </li>`;
    }

    function ocInitMembersSortable() {
        const list = document.getElementById('oc-mem-list');
        if (!list) return;
        new Sortable(list, {
            animation: 180,
            handle: '.oc-mem-card',
            ghostClass: 'sortable-ghost',
            onEnd: async () => {
                const ids = Array.from(list.querySelectorAll('li.oc-mem-card')).map(li => li.dataset.id);
                if (ids.length === 0) return;
                await ocPost('org_member', 'reorder', { ids: ids.join(',') });
                showPortalToast('จัดลำดับสมาชิกใหม่แล้ว', 'success');
            },
        });
    }

    function ocSyncPositionDropdown() {
        const sel = document.getElementById('oc-mem-position');
        if (!sel) return;
        const opts = ['<option value="">— ยังไม่จัดเข้าตำแหน่ง —</option>'];
        // Sort by level then sort_order to flatten tree-like dropdown
        const sorted = [...window.ocPositions].sort((a, b) => {
            if (a.level !== b.level) return a.level - b.level;
            return a.sort_order - b.sort_order;
        });
        sorted.forEach(p => {
            const indent = '— '.repeat(p.level);
            opts.push(`<option value="${p.id}">${indent}${esc(p.title)}</option>`);
        });
        sel.innerHTML = opts.join('');
    }

    // ── Position CRUD ────────────────────────────────────────────────
    window.ocAddPosition = function(parentId) {
        document.getElementById('oc-pos-modal-title').textContent = parentId ? 'เพิ่มตำแหน่ง (ลูก)' : 'เพิ่มตำแหน่งระดับบนสุด';
        document.getElementById('oc-pos-id').value = '';
        document.getElementById('oc-pos-parent').value = parentId || '';
        document.getElementById('oc-pos-title').value = '';
        document.getElementById('oc-pos-short').value = '';
        document.getElementById('oc-pos-desc').value = '';
        document.querySelector('input[name="card_style"][value="premium"]').checked = true;
        document.getElementById('oc-pos-section').checked = true;

        const info = document.getElementById('oc-pos-parent-info');
        if (parentId) {
            const parent = window.ocPositions.find(p => Number(p.id) === Number(parentId));
            info.style.display = '';
            info.innerHTML = `<i class="fa-solid fa-arrow-up text-emerald-500"></i> เพิ่มภายใต้: <strong>${esc(parent?.title || '')}</strong>`;
        } else {
            info.style.display = 'none';
        }
        document.getElementById('oc-pos-modal').style.display = 'flex';
    };

    window.ocEditPosition = function(id) {
        const p = window.ocPositions.find(x => Number(x.id) === Number(id));
        if (!p) return;
        document.getElementById('oc-pos-modal-title').textContent = 'แก้ไขตำแหน่ง';
        document.getElementById('oc-pos-id').value = p.id;
        document.getElementById('oc-pos-parent').value = p.parent_id || '';
        document.getElementById('oc-pos-title').value = p.title;
        document.getElementById('oc-pos-short').value = p.short_title || '';
        document.getElementById('oc-pos-desc').value = p.description || '';
        document.querySelector(`input[name="card_style"][value="${p.card_style}"]`).checked = true;
        document.getElementById('oc-pos-section').checked = !!Number(p.show_section_header);

        const info = document.getElementById('oc-pos-parent-info');
        if (p.parent_id) {
            const parent = window.ocPositions.find(x => Number(x.id) === Number(p.parent_id));
            info.style.display = '';
            info.innerHTML = `<i class="fa-solid fa-arrow-up text-emerald-500"></i> ภายใต้: <strong>${esc(parent?.title || '')}</strong>`;
        } else {
            info.style.display = 'none';
        }
        document.getElementById('oc-pos-modal').style.display = 'flex';
    };

    window.ocSavePosition = async function(e) {
        e.preventDefault();
        const id = document.getElementById('oc-pos-id').value;
        const action = id ? 'update' : 'create';
        const fd = new FormData(e.target);
        // Checkbox needs explicit "0" when unchecked
        if (!document.getElementById('oc-pos-section').checked) {
            fd.set('show_section_header', '0');
        } else {
            fd.set('show_section_header', '1');
        }
        const r = await ocPost('position', action, Object.fromEntries(fd.entries()));
        if (!r.ok) return Swal.fire('Error', r.message || 'บันทึกไม่สำเร็จ', 'error');
        ocCloseModal('oc-pos-modal');
        showPortalToast(r.message, 'success');
        ocLoadAll();
    };

    window.ocDeletePosition = async function(id, title) {
        const c = await Swal.fire({
            title: 'ลบตำแหน่งนี้?',
            html: `<strong>${esc(title)}</strong><br><span class="text-sm text-slate-500">ตำแหน่งลูกจะถูกย้ายขึ้นเป็นระดับบน · สมาชิกจะอยู่ในกลุ่ม "ยังไม่จัด"</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });
        if (!c.isConfirmed) return;
        const r = await ocPost('position', 'delete', { id });
        if (!r.ok) return Swal.fire('Error', r.message || 'ลบไม่สำเร็จ', 'error');
        showPortalToast(r.message, 'success');
        if (Number(window.ocCurrentPositionId) === Number(id)) {
            window.ocCurrentPositionId = null;
            document.getElementById('oc-current-pos-title').textContent = 'ทั้งหมด';
        }
        ocLoadAll();
    };

    // ── Member CRUD ──────────────────────────────────────────────────
    window.ocAddMember = function(positionId) {
        document.getElementById('oc-mem-modal-title').textContent = 'เพิ่มสมาชิก';
        document.getElementById('oc-mem-id').value = '';
        document.getElementById('oc-mem-position').value = positionId || '';
        ['oc-mem-prefix','oc-mem-name','oc-mem-dept','oc-mem-license','oc-mem-resp','oc-mem-photo-url','oc-mem-staff-id']
            .forEach(id => { document.getElementById(id).value = ''; });
        document.getElementById('oc-mem-photo').value = '';
        document.getElementById('oc-mem-photo-preview').style.display = 'none';
        document.getElementById('oc-mem-photo-placeholder').style.display = '';
        ocClearStaffPicker();
        document.getElementById('oc-mem-modal').style.display = 'flex';
    };

    window.ocEditMember = async function(id) {
        const m = window.ocMembers.find(x => Number(x.id) === Number(id));
        if (!m) return;
        document.getElementById('oc-mem-modal-title').textContent = 'แก้ไขสมาชิก';
        document.getElementById('oc-mem-id').value = m.id;
        document.getElementById('oc-mem-position').value = m.position_id || '';
        document.getElementById('oc-mem-prefix').value = m.prefix || '';
        document.getElementById('oc-mem-name').value = m.full_name || '';
        document.getElementById('oc-mem-dept').value = m.department || '';
        document.getElementById('oc-mem-license').value = m.license_no || '';
        document.getElementById('oc-mem-resp').value = m.responsibilities || '';
        document.getElementById('oc-mem-staff-id').value = m.staff_id || '';
        document.getElementById('oc-mem-photo-url').value = m.photo_url || '';
        document.getElementById('oc-mem-photo').value = '';

        if (m.photo_url) {
            const img = document.getElementById('oc-mem-photo-preview');
            img.src = m.photo_url;
            img.style.display = '';
            document.getElementById('oc-mem-photo-placeholder').style.display = 'none';
        } else {
            document.getElementById('oc-mem-photo-preview').style.display = 'none';
            document.getElementById('oc-mem-photo-placeholder').style.display = '';
        }

        // Resolve linked staff display name
        if (m.staff_id) {
            const r = await ocPost('staff', 'get', { id: m.staff_id });
            if (r.ok && r.data) {
                ocShowLinkedStaff(r.data);
            } else {
                ocClearStaffPicker();
            }
        } else {
            ocClearStaffPicker();
        }
        document.getElementById('oc-mem-modal').style.display = 'flex';
    };

    window.ocPreviewPhoto = function(input) {
        const file = input.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        const img = document.getElementById('oc-mem-photo-preview');
        img.src = url;
        img.style.display = '';
        document.getElementById('oc-mem-photo-placeholder').style.display = 'none';
    };

    window.ocSaveMember = async function(e) {
        e.preventDefault();
        const id = document.getElementById('oc-mem-id').value;
        const action = id ? 'update' : 'create';
        const fd = new FormData(e.target);
        const r = await ocPost('org_member', action, fd, true);
        if (!r.ok) return Swal.fire('Error', r.message || 'บันทึกไม่สำเร็จ', 'error');
        ocCloseModal('oc-mem-modal');
        showPortalToast(r.message, 'success');
        ocLoadAll();
    };

    window.ocDeleteMember = async function(id, name) {
        const c = await Swal.fire({
            title: 'ลบสมาชิกนี้?',
            text: name,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });
        if (!c.isConfirmed) return;
        const r = await ocPost('org_member', 'delete', { id });
        if (!r.ok) return Swal.fire('Error', r.message || 'ลบไม่สำเร็จ', 'error');
        showPortalToast(r.message, 'success');
        ocLoadAll();
    };

    window.ocCloseModal = function(modalId) {
        document.getElementById(modalId).style.display = 'none';
    };

    // ── Staff picker (search-as-you-type) ────────────────────────────
    let ocStaffSearchTimer = null;

    function ocClearStaffPicker() {
        document.getElementById('oc-mem-staff-id').value = '';
        document.getElementById('oc-mem-staff-linked').style.display = 'none';
        document.getElementById('oc-mem-staff-search-wrap').style.display = '';
        document.getElementById('oc-mem-staff-search').value = '';
        document.getElementById('oc-mem-staff-results').style.display = 'none';
    }

    function ocShowLinkedStaff(staff) {
        document.getElementById('oc-mem-staff-id').value = staff.id;
        const label = `${staff.full_name}`
            + (staff.username ? ` · @${staff.username}` : '')
            + (staff.role ? ` · ${staff.role}` : '');
        document.getElementById('oc-mem-staff-linked-name').textContent = label;
        document.getElementById('oc-mem-staff-linked').style.display = '';
        document.getElementById('oc-mem-staff-search-wrap').style.display = 'none';
        document.getElementById('oc-mem-staff-results').style.display = 'none';
    }

    window.ocUnlinkStaff = function() {
        ocClearStaffPicker();
    };

    window.ocPickStaff = function(staff, autoFill = true) {
        ocShowLinkedStaff(staff);
        if (!autoFill) return;
        // Auto-fill full_name only if empty (sys_staff doesn't carry
        // prefix / license / department — those stay manual)
        const nameEl = document.getElementById('oc-mem-name');
        if (nameEl && !nameEl.value && staff.full_name) nameEl.value = staff.full_name;
    };

    async function ocSearchStaff(q) {
        const r = await ocPost('staff', 'search', { q });
        const el = document.getElementById('oc-mem-staff-results');
        if (!r.ok) { el.style.display = 'none'; return; }
        if (!r.data || r.data.length === 0) {
            el.innerHTML = `<div class="oc-staff-no-result">ไม่พบบัญชี Staff ที่ตรงกับ "${esc(q)}"<br><small>ลองพิมพ์ชื่อ username หรืออีเมล · หรือเพิ่ม Staff ที่หน้า Identity &amp; Governance</small></div>`;
            el.style.display = '';
            return;
        }
        el.innerHTML = r.data.map(s => `
            <div class="oc-staff-result-item" onclick='ocPickStaff(${JSON.stringify(s).replace(/'/g, "&apos;")})'>
                <div class="oc-staff-result-name">${esc(s.full_name)}</div>
                <div class="oc-staff-result-meta">
                    <i class="fa-solid fa-at text-emerald-400"></i> ${esc(s.username || '-')}
                    ${s.role ? ' · <span class="text-emerald-600 font-bold">' + esc(s.role) + '</span>' : ''}
                    ${s.email ? ' · ' + esc(s.email) : ''}
                </div>
            </div>
        `).join('');
        el.style.display = '';
    }

    // Wire up search input — debounce 250ms
    document.addEventListener('input', (e) => {
        if (e.target.id !== 'oc-mem-staff-search') return;
        const q = e.target.value.trim();
        clearTimeout(ocStaffSearchTimer);
        if (q.length < 1) {
            // Show top-20 list when empty (helpful)
            ocStaffSearchTimer = setTimeout(() => ocSearchStaff(''), 100);
            return;
        }
        ocStaffSearchTimer = setTimeout(() => ocSearchStaff(q), 250);
    });

    // Show top results when focusing empty search box
    document.addEventListener('focusin', (e) => {
        if (e.target.id !== 'oc-mem-staff-search') return;
        ocSearchStaff(e.target.value.trim());
    });

    // Hide dropdown when clicking outside picker
    document.addEventListener('click', (e) => {
        const wrap = document.getElementById('oc-mem-staff-search-wrap');
        if (!wrap || !wrap.contains(e.target)) {
            const el = document.getElementById('oc-mem-staff-results');
            if (el) el.style.display = 'none';
        }
    });

    // ── View toggle (Manage / Preview) ────────────────────────────────
    // Initial render is server-side; switching back into preview re-fetches
    // so admins always see the latest after editing in the manage tab.
    let ocPreviewLoadedAt = Date.now();

    window.ocSwitchView = function(view) {
        const manageEl = document.getElementById('oc-view-manage');
        const previewEl = document.getElementById('oc-view-preview');
        if (!manageEl || !previewEl) return;
        manageEl.style.display = (view === 'manage') ? '' : 'none';
        previewEl.style.display = (view === 'preview') ? '' : 'none';
        document.querySelectorAll('.oc-view-tab').forEach(b => {
            b.classList.toggle('is-active', b.dataset.view === view);
        });
        // Refresh on every entry into preview so edits made in manage
        // tab show up immediately without an explicit click.
        if (view === 'preview') ocRefreshPreview();
    };

    window.ocRefreshPreview = async function() {
        const btn = document.querySelector('.oc-preview-refresh-btn');
        if (btn) btn.disabled = true;
        const r = await ocPost('org', 'render', {});
        if (btn) btn.disabled = false;
        if (!r || !r.ok) {
            Swal.fire({ icon: 'error', title: 'รีเฟรชไม่สำเร็จ', text: r?.message || '' });
            return;
        }
        document.getElementById('oc-preview-html').innerHTML = r.html || '';
        document.getElementById('oc-preview-pos-count').textContent = r.totalPositions ?? 0;
        document.getElementById('oc-preview-mem-count').textContent = r.totalMembers ?? 0;
        ocPreviewLoadedAt = Date.now();
    };

    // Init — defer until after portal_CSRF script (defined later in portal/index.php) has run
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ocLoadAll);
    } else {
        // Already past parsing — wait one tick so any pending inline scripts complete
        setTimeout(ocLoadAll, 0);
    }
})();
</script>
