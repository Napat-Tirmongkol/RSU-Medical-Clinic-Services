<?php
// admin/user_history.php
require_once __DIR__ . '/includes/auth.php';

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: users.php');
    exit;
}

$pdo = db();

// ดึงข้อมูล User
try {
    $stmtUser = $pdo->prepare("SELECT * FROM sys_users WHERE id = :id LIMIT 1");
    $stmtUser->execute([':id' => $userId]);
    $user = $stmtUser->fetch();
    if (!$user) {
        header('Location: users.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("admin error: " . $e->getMessage()); http_response_code(500); exit("เกิดข้อผิดพลาด");
}

// ดึงประวัติการจองทั้งหมดของ User นี้
try {
    $sql = "
        SELECT 
            a.id            AS appointment_id,
            a.status,
            a.created_at    AS booked_at,
            a.attended_at,
            t.slot_date,
            t.start_time,
            t.end_time,
            c.title         AS campaign_title,
            c.description   AS campaign_desc
        FROM camp_bookings a
        JOIN camp_slots t ON a.slot_id = t.id
        JOIN camp_list c       ON a.campaign_id = c.id
        WHERE a.student_id = :uid
        ORDER BY a.created_at DESC
    ";
    $stmtBookings = $pdo->prepare($sql);
    $stmtBookings->execute([':uid' => $userId]);
    $bookings = $stmtBookings->fetchAll();
} catch (PDOException $e) {
    error_log("user_history error: " . $e->getMessage()); $history = [];
}

// สรุปสถิติ
$total     = count($bookings);
$attended  = count(array_filter($bookings, fn($b) => !empty($b['attended_at'])));
$confirmed = count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed' && empty($b['attended_at'])));
$pending   = count(array_filter($bookings, fn($b) => $b['status'] === 'booked'));
$cancelled = count(array_filter($bookings, fn($b) => in_array($b['status'], ['cancelled', 'cancelled_by_admin'])));

require_once __DIR__ . '/includes/header.php';

// Status helper
function statusBadge(array $b): string {
    if (!empty($b['attended_at']))              return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold rounded-full bg-sky-100 text-sky-700 border border-sky-200"><i class="fa-solid fa-check-double text-[9px]"></i>เช็คอินแล้ว</span>';
    if ($b['status'] === 'booked')              return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold rounded-full bg-amber-100 text-amber-700 border border-amber-200"><i class="fa-solid fa-hourglass-half text-[9px]"></i>รออนุมัติ</span>';
    if ($b['status'] === 'confirmed')           return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200"><i class="fa-solid fa-circle-check text-[9px]"></i>ยืนยันแล้ว</span>';
    if ($b['status'] === 'cancelled_by_admin')  return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold rounded-full bg-orange-100 text-orange-700 border border-orange-200"><i class="fa-solid fa-rotate-right text-[9px]"></i>เลื่อนคิว</span>';
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold rounded-full bg-red-100 text-red-600 border border-red-200"><i class="fa-solid fa-xmark text-[9px]"></i>ยกเลิก</span>';
}
?>

<!-- ===== PAGE HEADER ===== -->
<div class="mb-6">
    <a href="users.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-[#0052CC] transition-colors mb-4 group">
        <i class="fa-solid fa-arrow-left text-xs group-hover:-translate-x-0.5 transition-transform"></i> กลับหน้ารายชื่อผู้ใช้
    </a>

    <!-- User Profile Card -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex flex-col md:flex-row md:items-center gap-5">
        <!-- Avatar -->
        <div class="flex-shrink-0 w-14 h-14 rounded-2xl bg-gradient-to-br from-[#0052CC] to-[#0070f3] flex items-center justify-center shadow-md">
            <span class="text-white text-xl font-bold">
                <?= mb_substr($user['full_name'] ?? '?', 0, 1) ?>
            </span>
        </div>

        <!-- Info -->
        <div class="flex-1">
            <h1 class="text-xl font-bold text-gray-900">
                <?= htmlspecialchars($user['full_name'] ?: 'ไม่ระบุชื่อ') ?>
            </h1>
            <div class="flex flex-wrap gap-4 mt-1.5 text-sm text-gray-500">
                <span><i class="fa-solid fa-id-card text-xs mr-1 text-gray-400"></i><?= htmlspecialchars($user['student_personnel_id'] ?: '-') ?></span>
                <span><i class="fa-solid fa-phone text-xs mr-1 text-gray-400"></i><?= htmlspecialchars($user['phone_number'] ?: '-') ?></span>
                <span><i class="fa-brands fa-line text-xs mr-1 text-green-500"></i><span class="font-mono text-xs"><?= htmlspecialchars(substr($user['line_user_id'] ?? '-', 0, 16)) ?>...</span></span>
            </div>
        </div>

        <!-- Action -->
        <a href="users.php" class="text-sm text-[#0052CC] hover:underline font-medium whitespace-nowrap">
            <i class="fa-solid fa-pen-to-square mr-1"></i>แก้ไขข้อมูล
        </a>
    </div>
</div>

<!-- ===== STATS ROW ===== -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $stats = [
        ['label' => 'ทั้งหมด',     'value' => $total,     'icon' => 'fa-layer-group',     'color' => 'text-gray-700',    'bg' => 'bg-gray-50',    'border' => 'border-gray-200'],
        ['label' => 'เช็คอินแล้ว', 'value' => $attended,  'icon' => 'fa-check-double',    'color' => 'text-sky-700',     'bg' => 'bg-sky-50',     'border' => 'border-sky-200'],
        ['label' => 'รออนุมัติ',   'value' => $pending,   'icon' => 'fa-hourglass-half',  'color' => 'text-amber-700',   'bg' => 'bg-amber-50',   'border' => 'border-amber-200'],
        ['label' => 'ยกเลิกแล้ว', 'value' => $cancelled, 'icon' => 'fa-ban',             'color' => 'text-red-600',     'bg' => 'bg-red-50',     'border' => 'border-red-200'],
    ];
    foreach ($stats as $s): ?>
    <div class="bg-white rounded-xl border <?= $s['border'] ?> p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg <?= $s['bg'] ?> flex items-center justify-center">
            <i class="fa-solid <?= $s['icon'] ?> <?= $s['color'] ?>"></i>
        </div>
        <div>
            <p class="text-2xl font-bold <?= $s['color'] ?>"><?= $s['value'] ?></p>
            <p class="text-xs text-gray-500"><?= $s['label'] ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ===== HISTORY TABLE ===== -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div>
            <h2 class="text-base font-bold text-gray-900">ประวัติการจองทั้งหมด</h2>
            <p class="text-xs text-gray-400 mt-0.5">แสดงทุกกิจกรรมที่เคยจอง เรียงจากล่าสุด</p>
        </div>
        <span class="text-xs font-bold text-gray-400 bg-gray-100 px-3 py-1 rounded-full"><?= $total ?> รายการ</span>
    </div>

    <?php if ($total === 0): ?>
    <div class="py-20 text-center">
        <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-regular fa-folder-open text-2xl text-gray-300"></i>
        </div>
        <p class="font-bold text-gray-500">ยังไม่มีประวัติการจอง</p>
        <p class="text-sm text-gray-400 mt-1">ผู้ใช้งานนี้ยังไม่เคยจองกิจกรรมใดๆ</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">#</th>
                    <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">กิจกรรม</th>
                    <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">วัน / เวลา</th>
                    <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">วันที่จอง</th>
                    <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">เช็คอิน</th>
                    <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">สถานะ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($bookings as $i => $b):
                    $slotDate  = date('d/m/Y', strtotime($b['slot_date']));
                    $slotTime  = substr($b['start_time'], 0, 5) . ' – ' . substr($b['end_time'], 0, 5);
                    $bookedAt  = date('d/m/Y H:i', strtotime($b['booked_at']));
                    $checkedAt = $b['attended_at'] ? date('d/m/Y H:i', strtotime($b['attended_at'])) : null;
                ?>
                <tr class="hover:bg-gray-50/60 transition-colors">
                    <td class="px-5 py-4 text-gray-400 font-mono text-xs"><?= $b['appointment_id'] ?></td>
                    <td class="px-5 py-4">
                        <p class="font-bold text-gray-900 text-[13px]"><?= htmlspecialchars($b['campaign_title']) ?></p>
                        <p class="text-xs text-gray-400 mt-0.5 max-w-[220px] truncate"><?= htmlspecialchars($b['campaign_desc'] ?? '') ?></p>
                    </td>
                    <td class="px-5 py-4">
                        <p class="font-semibold text-gray-800 text-[13px]"><?= $slotDate ?></p>
                        <p class="text-xs text-[#0052CC] mt-0.5"><i class="fa-regular fa-clock text-[10px] mr-1"></i><?= $slotTime ?></p>
                    </td>
                    <td class="px-5 py-4 text-xs text-gray-500"><?= $bookedAt ?></td>
                    <td class="px-5 py-4">
                        <?php if ($checkedAt): ?>
                            <p class="text-xs font-bold text-sky-600"><?= $checkedAt ?></p>
                        <?php else: ?>
                            <span class="text-xs text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-4"><?= statusBadge($b) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

