<?php
// Sub-view: Doctor Schedule (drag-drop calendar)
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doctor_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        type ENUM('regular','override','off') NOT NULL DEFAULT 'regular',
        weekday TINYINT NULL,
        specific_date DATE NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        room_id INT NULL,
        service_type VARCHAR(100) NULL,
        notes VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_staff (staff_id),
        INDEX idx_weekday (weekday),
        INDEX idx_date (specific_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

// Pre-fetch dropdowns
$staffList = [];
$roomsList = [];
try {
    $staffList = $pdo->query("SELECT id, title, full_name, role FROM sys_medical_staff WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $roomsList = $pdo->query("SELECT id, code, name, type FROM sys_clinic_rooms WHERE is_active = 1 ORDER BY type, code")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$serviceTypes = ['ตรวจทั่วไป', 'วัคซีน', 'ตรวจสุขภาพ', 'ปรึกษา', 'ทันตกรรม', 'อื่นๆ'];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<style>
    .fc { font-family: inherit; font-size: 13px; }
    .fc .fc-toolbar-title { font-weight: 900; color: #1e293b; }
    .fc .fc-button-primary {
        background: #fff; border-color: #e5e7eb; color: #475569;
        font-weight: 800; font-size: 12px; padding: 6px 14px;
    }
    .fc .fc-button-primary:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .fc .fc-button-primary:not(:disabled).fc-button-active,
    .fc .fc-button-primary:not(:disabled):active { background: #0e7490; border-color: #0e7490; }
    .fc-event { cursor: pointer; border-radius: 6px; padding: 2px 4px; font-weight: 700; }
    .fc-event.svc-ตรวจทั่วไป { background:#0ea5e9; border-color:#0284c7; }
    .fc-event.svc-วัคซีน      { background:#10b981; border-color:#059669; }
    .fc-event.svc-ตรวจสุขภาพ  { background:#a855f7; border-color:#9333ea; }
    .fc-event.svc-ปรึกษา      { background:#f59e0b; border-color:#d97706; }
    .fc-event.svc-ทันตกรรม    { background:#ec4899; border-color:#db2777; }
    .fc-event.svc-off         { background:#94a3b8; border-color:#64748b; opacity:.85; }

    /* Layout: ปฏิทิน + palette ด้านขวา (ไม่พึ่ง Tailwind JIT) */
    .ds-layout {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    @media (min-width: 768px) {
        .ds-layout { grid-template-columns: minmax(0, 1fr) 240px; }
    }
    @media (min-width: 1280px) {
        .ds-layout { grid-template-columns: minmax(0, 1fr) 260px; }
    }
    .ds-palette {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,.05);
        padding: .75rem;
    }
    @media (min-width: 768px) {
        .ds-palette {
            position: sticky;
            top: 1rem;
            align-self: start;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }
    }
    .ds-cal-wrap {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,.05);
        padding: 1rem;
        min-width: 0;
    }

    /* Doctor palette draggable cards */
    .ds-doc-card {
        cursor: grab; user-select: none;
        transition: transform .15s, box-shadow .15s, border-color .15s;
    }
    .ds-doc-card:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(14,116,144,.15); border-color: #06b6d4; }
    .ds-doc-card:active { cursor: grabbing; transform: scale(.98); }
    .ds-doc-card.fc-event { background: transparent; border: none; padding: 0; } /* override fc default */
    .fc-highlight { background: #ecfeff !important; border: 2px dashed #06b6d4 !important; }

    .ds-btn-import { background: #7c3aed; color: #fff; }
    .ds-btn-import:hover { background: #6d28d9; }

    /* Import modal — fix uncompiled Tailwind arbitrary-value classes */
    #ds-import-modal           { z-index: 300; }
    #ds-import-modal-box       { max-height: 90vh; }
    #ds-import-step3           { min-height: 0; }
    #ds-import-confirm-btn     { flex: 2 2 0%; }

    /* Holiday events — block scheduling, distinct rose tone */
    .fc-event.ds-holiday-evt {
        background: #fee2e2 !important;
        border-color: #fca5a5 !important;
        color: #991b1b !important;
        font-weight: 800;
    }
    .fc-event.ds-holiday-evt .fc-event-title,
    .fc-event.ds-holiday-evt .fc-event-time { color: #991b1b !important; }
    .fc-event.ds-holiday-evt .ds-holiday-icon { margin-right: 4px; }

    /* Hide doctor shift events that fall on a holiday — holiday implies closure */
    .fc-event.ds-hidden-on-holiday,
    .fc-bg-event.ds-hidden-on-holiday,
    .fc-daygrid-event.ds-hidden-on-holiday { display: none !important; }
</style>

<div class="max-w-[1400px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-cyan-50 rounded-xl shadow-sm border border-cyan-100 flex items-center justify-center text-cyan-600 text-xl">
            <i class="fa-solid fa-user-clock"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ตารางแพทย์ออกตรวจ</h2>
            <p class="text-slate-500 text-sm font-medium">drag-drop เพื่อย้าย shift · คลิก slot ว่างเพื่อเพิ่ม · คลิก event เพื่อแก้/ลบ</p>
        </div>
        <button onclick="dsOpenAdd()" class="px-4 py-2 bg-cyan-600 text-white rounded-xl text-sm font-black hover:bg-cyan-700 transition-all flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus"></i>เพิ่ม shift
        </button>
        <button onclick="dsOpenImport()" class="ds-btn-import px-4 py-2 rounded-xl text-sm font-black transition-all flex items-center gap-2 shadow-sm" title="นำเข้าตารางจากรูปภาพด้วย AI">
            <i class="fa-solid fa-camera"></i>นำเข้าจากรูป
        </button>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 mb-4 flex flex-wrap items-center gap-3 text-[11px]">
        <span class="font-black text-slate-500 uppercase tracking-widest mr-2">Service:</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-sky-500"></span>ตรวจทั่วไป</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-500"></span>วัคซีน</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-purple-500"></span>ตรวจสุขภาพ</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-500"></span>ปรึกษา</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-pink-500"></span>ทันตกรรม</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-slate-400"></span>ลา/หยุด</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-rose-200 border border-rose-300"></span>วันหยุดคลินิก</span>
    </div>

    <div class="ds-layout">

        <!-- Calendar -->
        <div class="ds-cal-wrap">
            <div id="ds-calendar"></div>
        </div>

        <!-- Doctor Palette (drag source) -->
        <aside class="ds-palette">
            <div class="flex items-center justify-between mb-3 px-1">
                <h4 class="text-[11px] font-black uppercase tracking-widest text-slate-500">
                    <i class="fa-solid fa-hand-pointer text-cyan-500 mr-1"></i> ลากแพทย์ลงปฏิทิน
                </h4>
                <span class="text-[10px] font-bold text-slate-400"><?= count($staffList) ?> ท่าน</span>
            </div>
            <div class="mb-2.5">
                <input type="text" id="ds-doc-search" placeholder="ค้นหาแพทย์..."
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 outline-none focus:border-cyan-400">
            </div>
            <div id="ds-doc-list" class="space-y-1.5">
                <?php if (empty($staffList)): ?>
                <p class="text-xs text-slate-400 italic text-center py-6">ยังไม่มีบุคลากร — ไป<a href="?section=clinic_data&cd_view=staff" class="text-cyan-600 underline">เพิ่มบุคลากร</a></p>
                <?php else: foreach ($staffList as $s):
                    $roleColors = [
                        'doctor'=>['bg'=>'#dbeafe','fg'=>'#1d4ed8','label'=>'แพทย์'],
                        'nurse'=>['bg'=>'#fce7f3','fg'=>'#be185d','label'=>'พยาบาล'],
                        'pharmacist'=>['bg'=>'#dcfce7','fg'=>'#15803d','label'=>'เภสัช'],
                        'dentist'=>['bg'=>'#fef3c7','fg'=>'#a16207','label'=>'ทันตะ'],
                        'other'=>['bg'=>'#e2e8f0','fg'=>'#475569','label'=>'อื่นๆ'],
                    ];
                    $rc = $roleColors[$s['role']] ?? $roleColors['other'];
                ?>
                <div class="ds-doc-card flex items-center gap-2 px-2.5 py-2 bg-white border border-slate-200 rounded-lg"
                    data-staff-id="<?= (int)$s['id'] ?>"
                    data-staff-name="<?= htmlspecialchars(trim($s['title'].' '.$s['full_name']), ENT_QUOTES) ?>"
                    data-staff-search="<?= htmlspecialchars(mb_strtolower(trim($s['title'].' '.$s['full_name'])), ENT_QUOTES) ?>">
                    <div class="w-7 h-7 rounded-md flex items-center justify-center text-[10px] font-black shrink-0" style="background:<?= $rc['bg'] ?>; color:<?= $rc['fg'] ?>;">
                        <i class="fa-solid fa-user-doctor text-[10px]"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[12px] font-black text-slate-800 truncate leading-tight"><?= htmlspecialchars(trim($s['title'].' '.$s['full_name'])) ?></div>
                        <div class="text-[9px] font-black uppercase tracking-wider" style="color:<?= $rc['fg'] ?>"><?= htmlspecialchars($rc['label']) ?></div>
                    </div>
                    <i class="fa-solid fa-grip-vertical text-slate-300 text-[10px]"></i>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="mt-3 pt-3 border-t border-slate-100 text-[10px] text-slate-400 leading-relaxed px-1">
                <p class="mb-1"><i class="fa-solid fa-circle-info mr-1"></i>ลาก card ลง slot บนปฏิทิน</p>
                <p>ระบบสร้าง shift 1 ชม. ที่เวลาที่วาง — แก้รายละเอียดเพิ่มเติมได้</p>
            </div>
        </aside>
    </div>
</div>

<!-- Edit/Add Modal -->
<div id="ds-modal" class="hidden fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 id="ds-modal-title" class="font-black text-slate-800">เพิ่ม shift</h3>
            <button onclick="dsCloseModal()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="ds-form" onsubmit="dsSave(event)" class="p-6 space-y-4">
            <input type="hidden" name="id" id="ds-id">
            <input type="hidden" id="ds-clicked-date">

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ประเภท *</label>
                <div class="grid grid-cols-3 gap-1.5 p-1 bg-slate-50 border border-slate-200 rounded-xl">
                    <?php foreach (['regular'=>'ทุกสัปดาห์', 'override'=>'แทนที่ (เฉพาะวัน)', 'off'=>'ลา/หยุด'] as $k => $l): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="<?= $k ?>" class="peer hidden" <?= $k === 'regular' ? 'checked' : '' ?> onchange="dsToggleType()">
                        <div class="text-center py-2 rounded-lg text-[11px] font-black text-slate-500 peer-checked:bg-white peer-checked:text-cyan-700 peer-checked:shadow-sm transition-all"><?= $l ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="ds-field-weekday">
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">วันในสัปดาห์ *</label>
                <select name="weekday" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <?php foreach (['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'] as $i=>$n): ?>
                        <option value="<?= $i ?>"><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">สิ้นสุดซ้ำ <span class="font-medium normal-case text-slate-400">(ว่าง = ไม่มีกำหนด)</span></label>
                    <input type="date" name="recur_end_date" id="ds-recur-end"
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                </div>
            </div>

            <div id="ds-field-date" class="hidden">
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">วันที่ *</label>
                <input type="date" name="specific_date" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            </div>

            <div id="ds-field-time" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">เริ่ม *</label>
                    <input type="time" name="start_time" value="09:00" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">สิ้นสุด *</label>
                    <input type="time" name="end_time" value="12:00" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                </div>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">แพทย์ *</label>
                <select name="staff_id" required class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <option value="">— เลือกแพทย์ —</option>
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars(trim($s['title'].' '.$s['full_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ห้อง</label>
                <select name="room_id" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($roomsList as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['code'].' · '.$r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ประเภทบริการ</label>
                <select name="service_type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($serviceTypes as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">หมายเหตุ</label>
                <input type="text" name="notes" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            </div>

            <div class="flex gap-2 pt-3 border-t border-slate-100">
                <button type="button" id="ds-delete-btn" onclick="dsDelete()" class="hidden flex-1 px-4 py-2.5 bg-rose-50 text-rose-600 border border-rose-200 rounded-xl text-sm font-black hover:bg-rose-100 transition-all">
                    <i class="fa-solid fa-trash"></i> ลบ
                </button>
                <button type="button" onclick="dsCloseModal()" class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl text-sm font-black hover:bg-slate-200 transition-all">ยกเลิก</button>
                <button type="submit" class="flex-[2] px-4 py-2.5 bg-cyan-600 text-white rounded-xl text-sm font-black hover:bg-cyan-700 transition-all shadow-sm">
                    <i class="fa-solid fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Import from Photo Modal -->
<div id="ds-import-modal" class="hidden fixed inset-0 items-center justify-center bg-black/40 p-4">
    <div id="ds-import-modal-box" class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 class="font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-camera text-purple-500"></i> นำเข้าตารางจากรูป (AI)
            </h3>
            <button onclick="dsCloseImport()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Step 1: Upload dropzone -->
        <div id="ds-import-step1" class="p-6">
            <div id="ds-import-dropzone"
                class="border-2 border-dashed border-slate-300 rounded-2xl p-12 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition-all">
                <i class="fa-solid fa-camera-retro text-4xl text-slate-300 mb-4 block"></i>
                <p class="text-slate-700 font-bold text-sm">คลิกหรือลากรูปภาพมาวางที่นี่</p>
                <p class="text-slate-400 text-xs mt-1">รองรับ JPEG, PNG, WEBP · สูงสุด 10MB</p>
            </div>
            <input type="file" id="ds-import-file" accept="image/*" class="hidden">
        </div>

        <!-- Step 2: Processing indicator -->
        <div id="ds-import-step2" class="hidden p-10 text-center">
            <div class="flex flex-col items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-purple-50 flex items-center justify-center">
                    <i class="fa-solid fa-robot text-purple-500 text-2xl animate-pulse"></i>
                </div>
                <p class="font-bold text-slate-700">AI กำลังวิเคราะห์รูปภาพ...</p>
                <p class="text-sm text-slate-400">อาจใช้เวลา 5–15 วินาที</p>
            </div>
        </div>

        <!-- Step 3: Preview parsed shifts -->
        <div id="ds-import-step3" class="hidden flex-col flex-1">
            <div class="px-4 pt-4 pb-2 flex gap-4 shrink-0">
                <img id="ds-import-thumb" src="" alt="" class="w-28 h-auto rounded-xl border border-slate-200 object-contain shrink-0">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-slate-700">พบ <span id="ds-import-count" class="text-purple-600">0</span> shift จากรูปภาพ</p>
                    <p class="text-xs text-slate-400 mt-1">ตรวจสอบและปรับแก้ข้อมูลก่อนนำเข้า · แถวที่ยังไม่จับคู่แพทย์จะถูกข้ามไป</p>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 pb-2">
                <table class="w-full text-xs border-collapse">
                    <thead class="sticky top-0 z-10">
                        <tr class="bg-slate-50">
                            <th class="px-3 py-2 text-left font-black text-slate-500 border-b border-slate-200">ชื่อในรูป</th>
                            <th class="px-3 py-2 text-left font-black text-slate-500 border-b border-slate-200">แพทย์ในระบบ</th>
                            <th class="px-3 py-2 text-left font-black text-slate-500 border-b border-slate-200">วัน/วันที่</th>
                            <th class="px-3 py-2 text-left font-black text-slate-500 border-b border-slate-200">เวลา</th>
                            <th class="px-3 py-2 text-left font-black text-slate-500 border-b border-slate-200">บริการ</th>
                            <th class="px-3 py-2 border-b border-slate-200 w-8"></th>
                        </tr>
                    </thead>
                    <tbody id="ds-import-tbody"></tbody>
                </table>
            </div>

            <div class="px-4 py-3 flex gap-2 border-t border-slate-100 shrink-0">
                <button onclick="dsCloseImport()" class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl text-sm font-black hover:bg-slate-200 transition-all">ยกเลิก</button>
                <button onclick="dsImportConfirm()" id="ds-import-confirm-btn"
                    class="ds-btn-import px-4 py-2.5 rounded-xl text-sm font-black transition-all shadow-sm">
                    <i class="fa-solid fa-file-import"></i> นำเข้า shift ทั้งหมด
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const STAFF_LIST = <?= json_encode($staffList, JSON_UNESCAPED_UNICODE) ?>;
const ROOMS_LIST = <?= json_encode($roomsList, JSON_UNESCAPED_UNICODE) ?>;
let dsCalendar = null;
let dsRows = [];
const DS_HOLIDAYS = new Map(); // 'YYYY-MM-DD' → note (for blocking + lookup)

function dsHolidayBlock(dateStr, verb = 'ลงตารางแพทย์') {
    if (!DS_HOLIDAYS.has(dateStr)) return false;
    Swal.fire({
        icon: 'warning',
        title: 'วันหยุดคลินิก',
        text: `ไม่สามารถ${verb}ในวันหยุด: ${DS_HOLIDAYS.get(dateStr)}`,
        confirmButtonColor: '#0ea5e9',
    });
    return true;
}

// ── Local time helpers (avoid toISOString() UTC shift in Asia/Bangkok) ──
function dsPad2(n) { return String(n).padStart(2, '0'); }
function dsLocalDate(d) {
    return `${d.getFullYear()}-${dsPad2(d.getMonth()+1)}-${dsPad2(d.getDate())}`;
}
function dsLocalTime(d) {
    return `${dsPad2(d.getHours())}:${dsPad2(d.getMinutes())}`;
}
function dsAddMin(d, mins) {
    return new Date(d.getTime() + mins * 60000);
}

async function dsPost(action, data) {
    const fd = new FormData();
    fd.append('entity', 'schedule'); fd.append('action', action); fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    const res = await fetch('ajax_clinic_master.php', { method: 'POST', body: fd });
    return res.json();
}

function rowToEvent(row) {
    const staff = STAFF_LIST.find(s => +s.id === +row.staff_id);
    const docName = row.doc_name || (staff ? (staff.title + ' ' + staff.full_name).trim() : 'แพทย์');
    const room = row.room_name ? ' · ' + (row.room_code || '') + ' ' + row.room_name : '';
    const svcKey = row.type === 'off' ? 'off' : (row.service_type || 'ตรวจทั่วไป');

    const base = {
        id: String(row.id),
        title: docName + (row.type === 'off' ? ' (ลา)' : '') + room,
        classNames: ['svc-' + svcKey],
        extendedProps: row,
    };

    if (row.type === 'regular') {
        const ev = {
            daysOfWeek: [Number(row.weekday)],
            startTime: row.start_time,
            endTime:   row.end_time,
            startRecur: '2024-01-01',
        };
        if (row.recur_end_date) ev.endRecur = row.recur_end_date;
        return Object.assign(base, ev);
    }
    if (row.type === 'off') {
        return Object.assign(base, {
            start: row.specific_date,
            allDay: true,
            classNames: ['svc-off'],
        });
    }
    // override
    return Object.assign(base, {
        start: row.specific_date + 'T' + row.start_time,
        end:   row.specific_date + 'T' + row.end_time,
    });
}

async function dsLoadAndRender() {
    const res = await dsPost('list', {});
    if (!res.ok) { Swal.fire('Error', res.message || 'โหลดไม่สำเร็จ', 'error'); return; }
    dsRows = res.rows;

    DS_HOLIDAYS.clear();
    (res.holidays || []).forEach(h => {
        DS_HOLIDAYS.set(h.specific_date, h.note || 'วันหยุด');
    });

    dsCalendar.removeAllEvents();

    // Holiday events (background tint + visible label) — render before shifts
    (res.holidays || []).forEach(h => {
        dsCalendar.addEvent({
            start: h.specific_date,
            allDay: true,
            display: 'background',
            backgroundColor: '#fee2e2',
            extendedProps: { __holiday: true },
        });
        dsCalendar.addEvent({
            title: h.note || 'วันหยุด',
            start: h.specific_date,
            allDay: true,
            classNames: ['ds-holiday-evt'],
            editable: false,
            startEditable: false,
            durationEditable: false,
            extendedProps: { __holiday: true },
        });
    });

    dsRows.forEach(r => dsCalendar.addEvent(rowToEvent(r)));
}

function dsToggleType() {
    const t = document.querySelector('[name=type]:checked').value;
    document.getElementById('ds-field-weekday').classList.toggle('hidden', t !== 'regular');
    document.getElementById('ds-field-date').classList.toggle('hidden', t === 'regular');
    document.getElementById('ds-field-time').classList.toggle('hidden', t === 'off');
    document.querySelector('[name=specific_date]').required = (t !== 'regular');
}

function dsDefaultRecurEnd() {
    const d = new Date();
    d.setMonth(d.getMonth() + 1);
    return d.toISOString().substring(0, 10);
}

function dsOpenAdd(prefill = {}) {
    document.getElementById('ds-modal-title').textContent = 'เพิ่ม shift';
    document.getElementById('ds-id').value = '';
    document.getElementById('ds-delete-btn').classList.add('hidden');
    document.getElementById('ds-form').reset();
    document.querySelector('[name=type][value=regular]').checked = true;
    document.getElementById('ds-recur-end').value = dsDefaultRecurEnd();
    if (prefill.weekday !== undefined) document.querySelector('[name=weekday]').value = prefill.weekday;
    if (prefill.date) {
        document.querySelector('[name=type][value=override]').checked = true;
        document.querySelector('[name=specific_date]').value = prefill.date;
        document.getElementById('ds-recur-end').value = '';
    }
    if (prefill.time) document.querySelector('[name=start_time]').value = prefill.time;
    dsToggleType();
    document.getElementById('ds-modal').classList.remove('hidden');
    document.getElementById('ds-modal').classList.add('flex');
}

function dsOpenEdit(row, clickedDate = null) {
    document.getElementById('ds-modal-title').textContent = 'แก้ shift #' + row.id;
    document.getElementById('ds-id').value = row.id;
    document.getElementById('ds-clicked-date').value = (row.type === 'regular' && clickedDate) ? dsLocalDate(clickedDate) : '';
    document.getElementById('ds-delete-btn').classList.remove('hidden');
    document.querySelector(`[name=type][value=${row.type}]`).checked = true;
    document.querySelector('[name=weekday]').value = row.weekday ?? 0;
    document.querySelector('[name=specific_date]').value = row.specific_date ?? '';
    document.querySelector('[name=start_time]').value = (row.start_time || '').substring(0,5);
    document.querySelector('[name=end_time]').value   = (row.end_time   || '').substring(0,5);
    document.querySelector('[name=staff_id]').value = row.staff_id;
    document.querySelector('[name=room_id]').value = row.room_id ?? '';
    document.querySelector('[name=service_type]').value = row.service_type ?? '';
    document.querySelector('[name=notes]').value = row.notes ?? '';
    document.getElementById('ds-recur-end').value = row.recur_end_date ?? '';
    dsToggleType();
    document.getElementById('ds-modal').classList.remove('hidden');
    document.getElementById('ds-modal').classList.add('flex');
}

function dsCloseModal() {
    document.getElementById('ds-modal').classList.add('hidden');
    document.getElementById('ds-modal').classList.remove('flex');
}

async function dsSave(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd.entries());
    if (data.type === 'override' && data.specific_date && dsHolidayBlock(data.specific_date)) return;
    if (data.type !== 'off') {
        if (!data.start_time || !data.end_time) {
            Swal.fire({ icon: 'warning', title: 'กรุณาระบุเวลา', text: 'ต้องระบุเวลาเริ่มและเวลาสิ้นสุด' });
            return;
        }
        if (data.start_time >= data.end_time) {
            Swal.fire({
                icon: 'warning',
                title: 'เวลาไม่ถูกต้อง',
                text: `เวลาเริ่ม (${data.start_time}) ต้องน้อยกว่าเวลาสิ้นสุด (${data.end_time})`,
            });
            return;
        }
    }
    const action = data.id ? 'update' : 'add';
    let res = await dsPost(action, data);
    // Soft warning: adding a regular shift on a weekday that already
    // has upcoming closures. Backend reports preview:true + a list of
    // dates so admin can decide to proceed (recurring intent allowed
    // to overlap) or back off.
    if (res.ok && res.preview && Array.isArray(res.conflicts)) {
        const escH = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const rows = res.conflicts.map(c =>
            `<li class="text-sm"><b>${escH(c.date)}</b>${c.note ? ' — ' + escH(c.note) : ''}</li>`
        ).join('');
        const r = await Swal.fire({
            icon: 'warning',
            title: 'พบ schedule ทับวันหยุด',
            html: `<div class="text-left">
                <p class="text-sm text-slate-600 mb-2">มี <b>${res.conflicts.length}</b> วันหยุดในอีก 90 วันที่ wd ตรงกัน — shift นี้จะไม่แสดงในวันเหล่านี้:</p>
                <ul class="space-y-1 bg-amber-50 border border-amber-200 rounded p-3 max-h-60 overflow-y-auto">${rows}</ul>
                <p class="text-xs text-slate-500 mt-2">ยืนยันเพิ่ม regular shift นี้?</p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: 'ยืนยันเพิ่ม',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#d97706',
            width: 520,
        });
        if (!r.isConfirmed) return;
        res = await dsPost(action, { ...data, confirm: '1' });
    }
    if (res.ok) {
        showPortalToast(res.message, 'success');
        dsCloseModal();
        dsLoadAndRender();
    } else {
        Swal.fire('Error', res.message || 'บันทึกไม่สำเร็จ', 'error');
    }
}

async function dsDelete() {
    const id = document.getElementById('ds-id').value;
    if (!id) return;
    const row = dsRows.find(r => +r.id === +id);
    const clickedDate = document.getElementById('ds-clicked-date').value;

    if (row && row.type === 'regular' && clickedDate) {
        // Recurring delete dialog — 3 options like Google Calendar
        const result = await Swal.fire({
            title: 'ลบกิจกรรมที่เกิดซ้ำ',
            input: 'radio',
            inputOptions: {
                'this':              'กิจกรรมนี้',
                'this_and_following':'กิจกรรมนี้และกิจกรรมที่ตามมาทั้งหมด',
                'all':              'กิจกรรมทั้งหมด',
            },
            inputValue: 'this',
            showCancelButton: true,
            confirmButtonText: 'ตกลง',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#0ea5e9',
            didOpen: () => {
                // style radio options ให้ขึ้นบรรทัดใหม่แต่ละตัว
                document.querySelectorAll('.swal2-radio label').forEach(el => {
                    el.style.display = 'flex';
                    el.style.alignItems = 'center';
                    el.style.gap = '10px';
                    el.style.padding = '6px 0';
                    el.style.fontSize = '15px';
                });
            },
        });
        if (!result.isConfirmed) return;

        if (result.value === 'this') {
            // สร้าง 'off' override วันนั้น → ซ่อนแค่ occurrence เดียว
            const res = await dsPost('add', {
                type: 'off',
                specific_date: clickedDate,
                staff_id: row.staff_id,
            });
            if (res.ok) { showPortalToast('ข้ามกิจกรรมวันที่ ' + clickedDate + ' แล้ว', 'success'); dsCloseModal(); dsLoadAndRender(); }
            else Swal.fire('Error', res.message || 'ไม่สำเร็จ', 'error');

        } else if (result.value === 'this_and_following') {
            // ตั้ง recur_end_date เป็นวันก่อนหน้า clickedDate
            const prev = new Date(clickedDate);
            prev.setDate(prev.getDate() - 1);
            const endDate = dsLocalDate(prev);
            const res = await dsPost('update', {
                id,
                type: 'regular',
                weekday: row.weekday,
                recur_end_date: endDate,
                start_time: (row.start_time || '').substring(0, 5),
                end_time:   (row.end_time   || '').substring(0, 5),
                staff_id: row.staff_id,
                room_id: row.room_id || '',
                service_type: row.service_type || '',
                notes: row.notes || '',
            });
            if (res.ok) { showPortalToast('ลบกิจกรรมที่ตามมาทั้งหมดแล้ว', 'success'); dsCloseModal(); dsLoadAndRender(); }
            else Swal.fire('Error', res.message || 'ไม่สำเร็จ', 'error');

        } else {
            // ลบทั้งหมด
            const res = await dsPost('delete', {id});
            if (res.ok) { showPortalToast('ลบ shift ทั้งหมดแล้ว', 'success'); dsCloseModal(); dsLoadAndRender(); }
            else Swal.fire('Error', res.message || 'ไม่สำเร็จ', 'error');
        }
        return;
    }

    // Non-recurring
    const c = await Swal.fire({title:'ลบ shift นี้?', icon:'warning', showCancelButton:true, confirmButtonColor:'#e11d48'});
    if (!c.isConfirmed) return;
    const res = await dsPost('delete', {id});
    if (res.ok) { showPortalToast('ลบแล้ว', 'success'); dsCloseModal(); dsLoadAndRender(); }
    else Swal.fire('Error', res.message, 'error');
}

// ── Import from Photo (Gemini Vision) ────────────────────────────────────────
const DS_WEEKDAY_NAMES = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
let dsImportShifts = [];
let dsImportStaffPool = [];

function dsImportSetStep(step) {
    document.getElementById('ds-import-step1').classList.toggle('hidden', step !== 1);
    document.getElementById('ds-import-step2').classList.toggle('hidden', step !== 2);
    const s3 = document.getElementById('ds-import-step3');
    if (step === 3) { s3.classList.remove('hidden'); s3.classList.add('flex'); }
    else            { s3.classList.add('hidden');    s3.classList.remove('flex'); }
}

function dsOpenImport() {
    dsImportShifts = [];
    dsImportStaffPool = [];
    document.getElementById('ds-import-file').value = '';
    document.getElementById('ds-import-thumb').src = '';
    dsImportSetStep(1);
    document.getElementById('ds-import-modal').classList.remove('hidden');
    document.getElementById('ds-import-modal').classList.add('flex');
}

function dsCloseImport() {
    document.getElementById('ds-import-modal').classList.add('hidden');
    document.getElementById('ds-import-modal').classList.remove('flex');
}

async function dsImportUpload(file) {
    if (!file) return;
    // Show thumbnail preview
    const reader = new FileReader();
    reader.onload = e => { document.getElementById('ds-import-thumb').src = e.target.result; };
    reader.readAsDataURL(file);

    dsImportSetStep(2);

    const fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', portal_CSRF);

    try {
        const res  = await fetch('ajax_schedule_import.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.ok) {
            Swal.fire('วิเคราะห์ไม่สำเร็จ', data.message || 'เกิดข้อผิดพลาด', 'error');
            dsCloseImport();
            return;
        }

        dsImportShifts   = data.shifts;
        dsImportStaffPool = data.staff;
        dsRenderImportTable();
        document.getElementById('ds-import-count').textContent = dsImportShifts.length;
        dsImportSetStep(3);

    } catch (err) {
        Swal.fire('Error', 'เกิดข้อผิดพลาด: ' + err.message, 'error');
        dsCloseImport();
    }
}

function dsRenderImportTable() {
    const tbody = document.getElementById('ds-import-tbody');
    tbody.innerHTML = '';

    dsImportShifts.forEach((shift, idx) => {
        const opts = dsImportStaffPool.map(s =>
            `<option value="${s.id}"${shift.staff_id == s.id ? ' selected' : ''}>${s.name}</option>`
        ).join('');

        const dayLabel = shift.date
            ? shift.date
            : (shift.weekday !== null ? DS_WEEKDAY_NAMES[shift.weekday] ?? '?' : '?');

        const badge = shift.match_status === 'matched'
            ? '<span class="inline-block px-1.5 py-0.5 rounded text-[9px] font-black bg-emerald-50 text-emerald-700">จับคู่แล้ว</span>'
            : '<span class="inline-block px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-50 text-amber-700">ยังไม่จับคู่</span>';

        const tr = document.createElement('tr');
        tr.className = 'border-b border-slate-100 hover:bg-slate-50';
        tr.innerHTML = `
            <td class="px-3 py-2">
                <div class="font-bold text-slate-700 leading-tight">${shift.doctor_name}</div>
                <div class="mt-0.5">${badge}</div>
            </td>
            <td class="px-3 py-2">
                <select class="ds-imp-sel w-full px-2 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 outline-none focus:border-purple-400" data-idx="${idx}">
                    <option value="">— ไม่ระบุ (ข้าม) —</option>
                    ${opts}
                </select>
            </td>
            <td class="px-3 py-2 font-bold text-slate-600 whitespace-nowrap">${dayLabel}</td>
            <td class="px-3 py-2 font-bold text-slate-600 whitespace-nowrap">${shift.start_time}–${shift.end_time}</td>
            <td class="px-3 py-2 text-slate-500 whitespace-nowrap">${shift.service_type || '—'}</td>
            <td class="px-3 py-2 text-center">
                <button type="button" onclick="dsImportRemoveRow(${idx})" class="text-slate-300 hover:text-rose-500 transition-colors p-1">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </td>`;
        tbody.appendChild(tr);
    });

    // Sync staff_id on dropdown change
    tbody.querySelectorAll('.ds-imp-sel').forEach(sel => {
        sel.addEventListener('change', () => {
            const i = parseInt(sel.dataset.idx);
            dsImportShifts[i].staff_id = sel.value ? parseInt(sel.value) : null;
            dsImportShifts[i].match_status = sel.value ? 'matched' : 'unmatched';
        });
    });
}

function dsImportRemoveRow(idx) {
    dsImportShifts.splice(idx, 1);
    document.getElementById('ds-import-count').textContent = dsImportShifts.length;
    dsRenderImportTable();
}

async function dsImportConfirm() {
    const toImport = dsImportShifts.filter(s => s.staff_id);
    const skipped  = dsImportShifts.length - toImport.length;

    if (toImport.length === 0) {
        Swal.fire('', 'กรุณาเลือกแพทย์ในระบบสำหรับแถวที่ต้องการนำเข้าก่อน', 'warning');
        return;
    }

    const { isConfirmed } = await Swal.fire({
        title: 'ยืนยันการนำเข้า',
        text: skipped > 0
            ? `นำเข้า ${toImport.length} shift (ข้าม ${skipped} แถวที่ไม่มีแพทย์)?`
            : `นำเข้า ${toImport.length} shift ทั้งหมด?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'นำเข้า',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#7c3aed',
    });
    if (!isConfirmed) return;

    const btn = document.getElementById('ds-import-confirm-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังนำเข้า...';

    let success = 0, failed = 0;
    for (const shift of toImport) {
        const data = {
            staff_id:     shift.staff_id,
            start_time:   shift.start_time,
            end_time:     shift.end_time,
            service_type: shift.service_type || '',
        };
        if (shift.date) {
            data.type          = 'override';
            data.specific_date = shift.date;
        } else {
            data.type    = 'regular';
            data.weekday = shift.weekday ?? 1;
        }
        const res = await dsPost('add', data);
        res.ok ? success++ : failed++;
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-import"></i> นำเข้า shift ทั้งหมด';

    dsCloseImport();
    dsLoadAndRender();
    showPortalToast(
        failed > 0 ? `นำเข้าสำเร็จ ${success} รายการ · ล้มเหลว ${failed} รายการ` : `นำเข้าสำเร็จ ${success} รายการ`,
        failed > 0 ? 'warning' : 'success'
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('ds-calendar');
    if (!el || !window.FullCalendar) return;

    dsCalendar = new FullCalendar.Calendar(el, {
        initialView: 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
        locale: 'th',
        firstDay: 0,
        slotMinTime: '07:00:00',
        slotMaxTime: '21:00:00',
        nowIndicator: true,
        height: 700,
        editable: true,
        selectable: true,
        eventClick: (info) => {
            if (info.event.extendedProps.__holiday) return; // holidays are read-only markers
            const row = dsRows.find(r => +r.id === +info.event.id);
            if (row) dsOpenEdit(row, info.event.start);
        },
        eventClassNames: (info) => {
            // Hide any non-holiday event that falls on a holiday date
            if (info.event.extendedProps.__holiday) return [];
            const startDate = info.event.start ? dsLocalDate(info.event.start) : null;
            if (startDate && DS_HOLIDAYS.has(startDate)) return ['ds-hidden-on-holiday'];
            return [];
        },
        eventDidMount: (info) => {
            if (info.event.extendedProps.__holiday) {
                info.el.title = 'วันหยุดคลินิก: ' + info.event.title;
                const titleEl = info.el.querySelector('.fc-event-title');
                if (titleEl && !titleEl.querySelector('.ds-holiday-icon')) {
                    const ico = document.createElement('i');
                    ico.className = 'fa-solid fa-calendar-xmark ds-holiday-icon';
                    titleEl.prepend(ico);
                }
            }
        },
        dateClick: (info) => {
            const ds = info.dateStr.substring(0, 10);
            if (dsHolidayBlock(ds)) return;
            // Clicking on empty slot opens add modal pre-filled with date+time
            dsOpenAdd({ date: ds, time: info.dateStr.substring(11,16) || '09:00' });
        },
        droppable: true, // Accept external drag from doctor palette
        drop: async (info) => {
            const el = info.draggedEl;
            const staffId = el?.dataset?.staffId;
            const staffName = el?.dataset?.staffName || 'แพทย์';
            if (!staffId) return;

            // ถ้า drop ใน month view (allDay) ใช้ default 09:00–10:00
            // ถ้า week/day view ใช้เวลาที่วาง + 60 นาที
            const start = info.date;
            const isAllDay = info.allDay;
            const startTime = isAllDay ? '09:00' : dsLocalTime(start);
            const endTime   = isAllDay ? '10:00' : dsLocalTime(dsAddMin(start, 60));

            const dateStr = dsLocalDate(start);
            if (dsHolidayBlock(dateStr)) return;

            const data = {
                type: 'override',
                specific_date: dateStr,
                start_time: startTime,
                end_time:   endTime,
                staff_id:   staffId,
                service_type: 'ตรวจทั่วไป',
            };
            const res = await dsPost('add', data);
            if (res.ok) {
                showPortalToast(`เพิ่ม shift ${staffName} แล้ว`, 'success');
                dsLoadAndRender();
            } else {
                Swal.fire('Error', res.message || 'เพิ่ม shift ไม่สำเร็จ', 'error');
            }
        },
        eventDrop: async (info) => {
            if (info.event.extendedProps.__holiday) { info.revert(); return; }
            const row = dsRows.find(r => +r.id === +info.event.id);
            if (!row) return;
            const newStart = info.event.start;
            const newEnd   = info.event.end || new Date(newStart.getTime() + 60*60*1000);

            // Block moving an override onto a holiday date
            if (row.type === 'override') {
                const newDateStr = dsLocalDate(newStart);
                if (dsHolidayBlock(newDateStr, 'ย้าย shift')) { info.revert(); return; }
            }

            const data = { id: row.id };
            if (row.type === 'regular') {
                data.weekday = newStart.getDay();
                data.start_time = dsLocalTime(newStart);
                data.end_time   = dsLocalTime(newEnd);
            } else {
                data.specific_date = dsLocalDate(newStart);
                data.start_time = dsLocalTime(newStart);
                data.end_time   = dsLocalTime(newEnd);
            }
            // Carry over other fields
            data.staff_id = row.staff_id;
            data.room_id  = row.room_id || '';
            data.service_type = row.service_type || '';
            data.notes = row.notes || '';
            const res = await dsPost('update', data);
            if (res.ok) showPortalToast('ย้าย shift แล้ว', 'success');
            else { Swal.fire('Error', res.message, 'error'); info.revert(); }
            dsLoadAndRender();
        },
        eventResize: async (info) => {
            const row = dsRows.find(r => +r.id === +info.event.id);
            if (!row) return;
            const data = {
                id: row.id,
                start_time: dsLocalTime(info.event.start),
                end_time:   dsLocalTime(info.event.end),
                staff_id:   row.staff_id,
            };
            if (row.type === 'regular') data.weekday = row.weekday;
            else data.specific_date = dsLocalDate(new Date(row.specific_date));
            data.room_id = row.room_id || '';
            data.service_type = row.service_type || '';
            data.notes = row.notes || '';
            const res = await dsPost('update', data);
            if (res.ok) showPortalToast('ปรับเวลาแล้ว', 'success');
            else { Swal.fire('Error', res.message, 'error'); info.revert(); }
        },
    });
    dsCalendar.render();
    dsLoadAndRender();

    // ── External draggable: doctor palette → calendar ──
    const palette = document.getElementById('ds-doc-list');
    if (palette && window.FullCalendar?.Draggable) {
        new FullCalendar.Draggable(palette, {
            itemSelector: '.ds-doc-card',
            eventData: function (el) {
                return {
                    title: el.dataset.staffName || 'แพทย์',
                    duration: '01:00',
                    create: false, // เราจัดการสร้าง event ผ่าน drop handler เอง
                };
            },
        });
    }

    // ── Import modal: file input + drag-drop ──────────────────────────────────
    const importFile     = document.getElementById('ds-import-file');
    const importDropzone = document.getElementById('ds-import-dropzone');

    if (importFile) {
        importFile.addEventListener('change', () => {
            if (importFile.files[0]) dsImportUpload(importFile.files[0]);
        });
    }
    if (importDropzone) {
        importDropzone.addEventListener('click', () => importFile && importFile.click());
        importDropzone.addEventListener('dragover', e => {
            e.preventDefault();
            importDropzone.classList.add('border-purple-400', 'bg-purple-50');
        });
        importDropzone.addEventListener('dragleave', () => {
            importDropzone.classList.remove('border-purple-400', 'bg-purple-50');
        });
        importDropzone.addEventListener('drop', e => {
            e.preventDefault();
            importDropzone.classList.remove('border-purple-400', 'bg-purple-50');
            const file = e.dataTransfer?.files?.[0];
            if (file) dsImportUpload(file);
        });
    }

    // ── Doctor palette search filter ──
    const search = document.getElementById('ds-doc-search');
    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('.ds-doc-card').forEach(card => {
                const name = (card.dataset.staffSearch || '').toLowerCase();
                card.style.display = (q === '' || name.includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>
