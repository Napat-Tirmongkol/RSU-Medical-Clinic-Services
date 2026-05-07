<?php
// portal/_partials/clinic_data.php — router + card-grid landing
// Sub-views: profile / faculty / staff / rooms / hours / schedule
// Each sub-view gets back to landing via ?section=clinic_data (no cd_view)

$_view = $_GET['cd_view'] ?? '';
$_validViews = ['profile','faculty','staff','rooms','hours','schedule','calendar','survey'];

if (in_array($_view, $_validViews, true)) {
    include __DIR__ . '/clinic_data/' . $_view . '.php';
    return;
}

// ─── Landing page: stats per entity + cards ─────────────────────────────────
$pdo = db();

$_counts = ['profile'=>0, 'faculty'=>0, 'staff'=>0, 'rooms'=>0, 'hours'=>0, 'insurance_partners'=>0];
$_lastUpdated = ['profile'=>null, 'faculty'=>null, 'staff'=>null, 'rooms'=>null, 'hours'=>null];

// Helpers — silently ignore tables that don't exist yet
$_safeCount = function (string $sql) use ($pdo): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); } catch (PDOException) { return 0; }
};
$_safeMax = function (string $sql) use ($pdo): ?string {
    try { $v = $pdo->query($sql)->fetchColumn(); return $v ?: null; } catch (PDOException) { return null; }
};

$_counts['faculty']            = $_safeCount("SELECT COUNT(*) FROM sys_faculties");
$_counts['staff']              = $_safeCount("SELECT COUNT(*) FROM sys_medical_staff WHERE is_active = 1");
$_counts['rooms']              = $_safeCount("SELECT COUNT(*) FROM sys_clinic_rooms WHERE is_active = 1");
$_counts['hours']              = $_safeCount("SELECT COUNT(*) FROM sys_clinic_hours");
$_counts['insurance_partners'] = $_safeCount("SELECT COUNT(*) FROM insurance_partners");
try {
    $r = $pdo->query("SELECT name_th, updated_at FROM sys_clinic_profile WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $_counts['profile']     = $r && $r['name_th'] !== '' ? 1 : 0;
    $_lastUpdated['profile'] = $r['updated_at'] ?? null;
} catch (PDOException) {}

$_lastUpdated['faculty'] = $_safeMax("SELECT MAX(updated_at) FROM sys_faculties");
$_lastUpdated['staff']   = $_safeMax("SELECT MAX(updated_at) FROM sys_medical_staff");
$_lastUpdated['rooms']   = $_safeMax("SELECT MAX(updated_at) FROM sys_clinic_rooms");

$_relTime = function (?string $ts): string {
    if (!$ts) return 'ยังไม่เคยอัปเดต';
    $diff = time() - strtotime($ts);
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 86400 * 7) return floor($diff / 86400) . ' วันที่แล้ว';
    return date('d M Y', strtotime($ts));
};

$_cards = [
    [
        'view'    => 'profile',
        'title'   => 'ข้อมูลคลินิก',
        'desc'    => 'ที่อยู่ ช่องทางติดต่อ ใบอนุญาต',
        'icon'    => 'fa-hospital',
        'tone'    => ['bg'=>'#ecfdf5','fg'=>'#047857','border'=>'#a7f3d0'],
        'used_by' => ['hub','profile','certificates'],
        'count'   => $_counts['profile'],
        'count_label' => $_counts['profile'] ? 'ตั้งค่าแล้ว' : 'ยังไม่ได้ตั้งค่า',
        'updated' => $_relTime($_lastUpdated['profile']),
    ],
    [
        'view'    => 'faculty',
        'title'   => 'คณะ / หน่วยงาน',
        'desc'    => 'รายชื่อคณะและหน่วยงานสำหรับ dropdown ฟอร์ม',
        'icon'    => 'fa-building-columns',
        'tone'    => ['bg'=>'#eff6ff','fg'=>'#1d4ed8','border'=>'#bfdbfe'],
        'used_by' => ['profile','e-Campaign','booking'],
        'count'   => $_counts['faculty'],
        'count_label' => 'รายการ',
        'updated' => $_relTime($_lastUpdated['faculty']),
    ],
    [
        'view'    => 'staff',
        'title'   => 'บุคลากรการแพทย์',
        'desc'    => 'แพทย์ พยาบาล เภสัชกร — ใช้กำหนดผู้อนุมัติคำขอ',
        'icon'    => 'fa-user-doctor',
        'tone'    => ['bg'=>'#eff6ff','fg'=>'#0369a1','border'=>'#bae6fd'],
        'used_by' => ['e-Borrow','prescription'],
        'count'   => $_counts['staff'],
        'count_label' => 'active',
        'updated' => $_relTime($_lastUpdated['staff']),
    ],
    [
        'view'    => 'rooms',
        'title'   => 'ห้อง / พื้นที่',
        'desc'    => 'ห้องตรวจ จุดฉีดวัคซีน ห้องแล็บ',
        'icon'    => 'fa-door-open',
        'tone'    => ['bg'=>'#fef3c7','fg'=>'#b45309','border'=>'#fde68a'],
        'used_by' => ['booking','slot scheduler'],
        'count'   => $_counts['rooms'],
        'count_label' => 'active',
        'updated' => $_relTime($_lastUpdated['rooms']),
    ],
    [
        'view'    => 'hours',
        'title'   => 'วันหยุด / ชั่วโมงทำการ',
        'desc'    => 'เวลาเปิด-ปิด และวันหยุดพิเศษ',
        'icon'    => 'fa-calendar-days',
        'tone'    => ['bg'=>'#faf5ff','fg'=>'#7c3aed','border'=>'#e9d5ff'],
        'used_by' => ['booking validation'],
        'count'   => $_counts['hours'],
        'count_label' => 'รายการ',
        'updated' => null,
    ],
    [
        'view'    => 'calendar',
        'title'   => 'ปฏิทินคลินิก',
        'desc'    => 'ภาพรวมรายเดือน — เวลาเปิด-ปิด, วันหยุด, แพทย์ออกตรวจ',
        'icon'    => 'fa-calendar',
        'tone'    => ['bg'=>'#ecfeff','fg'=>'#0e7490','border'=>'#a5f3fc'],
        'used_by' => ['hours','schedule','holidays'],
        'count'   => (int)date('t'),
        'count_label' => 'วันในเดือนนี้',
        'updated' => null,
    ],
    [
        'view'    => 'schedule',
        'title'   => 'ตารางแพทย์ออกตรวจ',
        'desc'    => 'จัดตารางเวรแพทย์ ห้องตรวจ และประเภทบริการ — drag-drop calendar',
        'icon'    => 'fa-user-clock',
        'tone'    => ['bg'=>'#ecfeff','fg'=>'#0e7490','border'=>'#a5f3fc'],
        'used_by' => ['internal scheduling'],
        'count'   => $_safeCount("SELECT COUNT(*) FROM sys_doctor_schedule WHERE is_active = 1"),
        'count_label' => 'shift',
        'updated' => $_safeMax("SELECT MAX(updated_at) FROM sys_doctor_schedule") ? $_relTime($_safeMax("SELECT MAX(updated_at) FROM sys_doctor_schedule")) : null,
    ],
    [
        'view'    => 'survey',
        'title'   => 'แบบสอบถามหลังเช็คอิน',
        'desc'    => 'จัดการคำถามที่ผู้ใช้ต้องตอบหลังเช็คอินเข้าร่วมกิจกรรม — บังคับตอบทุกครั้ง',
        'icon'    => 'fa-clipboard-question',
        'tone'    => ['bg'=>'#fdf2f8','fg'=>'#be185d','border'=>'#fbcfe8'],
        'used_by' => ['post-checkin','KPI'],
        'count'   => $_safeCount("SELECT COUNT(*) FROM sys_survey_questions WHERE survey_type = 'post_checkin' AND is_active = 1"),
        'count_label' => 'คำถาม active',
        'updated' => $_safeMax("SELECT MAX(updated_at) FROM sys_survey_questions") ? $_relTime($_safeMax("SELECT MAX(updated_at) FROM sys_survey_questions")) : null,
    ],
    [
        'view'    => null, // external link — managed in separate section
        'href'    => "javascript:switchSection('manage_insurance_partners')",
        'title'   => 'บริษัทประกัน',
        'desc'    => 'พาร์ทเนอร์บริษัทประกันที่ใช้ใน Insurance Sync',
        'icon'    => 'fa-shield-heart',
        'tone'    => ['bg'=>'#fef2f2','fg'=>'#be123c','border'=>'#fecaca'],
        'used_by' => ['Insurance Sync','profile'],
        'count'   => $_counts['insurance_partners'],
        'count_label' => 'บริษัท',
        'updated' => null,
        'is_external' => true,
    ],
];
?>
<div class="max-w-[1200px] mx-auto px-4 py-6">

    <!-- Header -->
    <div class="mb-6 flex items-center gap-4">
        <div class="w-12 h-12 bg-teal-50 text-teal-600 rounded-xl shadow-sm border border-teal-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-hospital"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ข้อมูลคลินิก</h2>
            <p class="text-slate-500 text-sm font-medium">ศูนย์กลางข้อมูล master ของคลินิก — ใช้ร่วมกันทุกระบบ</p>
        </div>
        <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest bg-slate-50 border border-slate-200 text-slate-600">
            <i class="fa-solid fa-database text-slate-400 text-[9px]"></i>
            <?= count($_cards) ?> หมวดหมู่
        </div>
    </div>

    <!-- Card Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <?php foreach ($_cards as $c):
            $href = $c['view'] ? "?section=clinic_data&cd_view=" . urlencode($c['view']) : ($c['href'] ?? '#');
            $isExt = !empty($c['is_external']);
        ?>
            <a href="<?= htmlspecialchars($href) ?>"
               class="group bg-white rounded-3xl border border-slate-200 shadow-sm hover:shadow-md hover:-translate-y-0.5 hover:border-teal-200 transition-all flex flex-col overflow-hidden">
                <!-- Header strip: icon + count -->
                <div class="p-5 pb-3 flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-xl shrink-0 border"
                         style="background:<?= $c['tone']['bg'] ?>;color:<?= $c['tone']['fg'] ?>;border-color:<?= $c['tone']['border'] ?>">
                        <i class="fa-solid <?= $c['icon'] ?>"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-2xl font-black text-slate-800 leading-none"><?= number_format((int)$c['count']) ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1"><?= htmlspecialchars($c['count_label']) ?></div>
                    </div>
                    <?php if ($isExt): ?>
                        <i class="fa-solid fa-arrow-up-right-from-square text-slate-300 text-xs"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-arrow-right text-slate-300 group-hover:text-teal-500 group-hover:translate-x-1 transition-all"></i>
                    <?php endif; ?>
                </div>

                <!-- Body -->
                <div class="px-5 pb-3 flex-1">
                    <h3 class="text-base font-black text-slate-800 leading-tight mb-1"><?= htmlspecialchars($c['title']) ?></h3>
                    <p class="text-[12px] text-slate-500 font-medium leading-relaxed"><?= htmlspecialchars($c['desc']) ?></p>
                </div>

                <!-- Footer: used by + last updated -->
                <div class="px-5 py-3 border-t border-slate-50 bg-slate-50/40 flex flex-wrap items-center gap-1.5">
                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-400 mr-1">Used by:</span>
                    <?php foreach (array_slice($c['used_by'], 0, 3) as $u): ?>
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-white border border-slate-200 text-[9px] font-black text-slate-600"><?= htmlspecialchars($u) ?></span>
                    <?php endforeach; ?>
                    <?php if ($c['updated']): ?>
                        <span class="ml-auto text-[9px] font-bold text-slate-400">
                            <i class="fa-regular fa-clock text-[8px]"></i> <?= htmlspecialchars($c['updated']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Footer note -->
    <div class="mt-6 p-4 rounded-2xl bg-slate-50 border border-slate-200 flex items-start gap-3 text-[12px] text-slate-600 font-medium">
        <i class="fa-solid fa-circle-info text-slate-400 mt-0.5"></i>
        <div>
            <strong class="text-slate-800 font-black">ข้อมูล master ที่แชร์กับทุกระบบ</strong> —
            แก้ไขที่นี่จะกระทบทันทีในระบบที่อ้างถึง (hub, e-Campaign, e-Borrow, profile ฯลฯ)
            แนะนำให้แจ้งผู้ใช้ก่อนถ้าเปลี่ยนข้อมูลที่อาจกระทบ workflow
        </div>
    </div>
</div>
