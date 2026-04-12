<?php
// user/booking_campaign.php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();
check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));

$pdo = db();

// ดึงรายการแคมเปญที่สถานะเป็น active และยังไม่หมดเขต
$today = date('Y-m-d');
$sql = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM camp_bookings a WHERE a.campaign_id = c.id AND a.status IN ('booked', 'confirmed')) as used_seats
    FROM camp_list c
    WHERE c.status = 'active' 
    AND (c.available_until IS NULL OR c.available_until >= :today)
    ORDER BY c.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':today' => $today]);
$camp_list = $stmt->fetchAll();

// ฟังก์ชันช่วยแสดงผลประเภทกิจกรรม
function getBadge($type) {
    return match($type) {
        'vaccine' => ['label' => 'ฉีดวัคซีน', 'class' => 'bg-blue-100 text-blue-700', 'icon' => 'fa-syringe'],
        'training' => ['label' => 'อบรม/สัมมนา', 'class' => 'bg-purple-100 text-purple-700', 'icon' => 'fa-chalkboard-user'],
        'health_check' => ['label' => 'ตรวจสุขภาพ', 'class' => 'bg-green-100 text-green-700', 'icon' => 'fa-stethoscope'],
        default => ['label' => 'กิจกรรม', 'class' => 'bg-gray-100 text-gray-700', 'icon' => 'fa-star'],
    };
}

// เรียกใช้ฟังก์ชันเพื่อวาด Header (โหลด CSS/Font)
render_header('เลือกแคมเปญ - E-Campaign');
?>

<div class="max-w-md mx-auto px-4 py-6 pb-24">
    <div class="mb-8">
        <h1 class="text-2xl font-black text-gray-900 leading-tight">เลือกแคมเปญ <br><span class="text-[#0052CC]">ที่คุณสนใจ</span></h1>
        <p class="text-gray-500 mt-2 text-sm">เลือกกิจกรรมด้านล่างเพื่อทำการจองรอบเวลา</p>
    </div>

    <?php if (count($camp_list) === 0): ?>
        <div class="bg-gray-50 rounded-3xl p-10 text-center border-2 border-dashed border-gray-200">
            <div class="text-4xl mb-4 text-gray-300"><i class="fa-solid fa-calendar-xmark"></i></div>
            <p class="text-gray-500 font-medium">ขออภัย ขณะนี้ยังไม่มี <br> แคมเปญที่เปิดรับลงทะเบียน</p>
        </div>
    <?php else: ?>
        <div class="grid gap-5">
            <?php foreach ($camp_list as $c): 
                $badge = getBadge($c['type']);
                $remaining = $c['total_capacity'] - $c['used_seats'];
                $isFull = ($remaining <= 0);
            ?>
                <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-shadow relative">
                    <?php if ($isFull): ?>
                        <div class="absolute inset-0 bg-white/60 backdrop-blur-[1px] z-10 flex items-center justify-center">
                            <span class="bg-red-500 text-white px-4 py-1 rounded-full text-sm font-bold rotate-[-5deg] shadow-lg">เต็มแล้ว (Full)</span>
                        </div>
                    <?php endif; ?>

                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $badge['class'] ?>">
                                <i class="fa-solid <?= $badge['icon'] ?> mr-1"></i> <?= $badge['label'] ?>
                            </span>
                            <?php if ($c['available_until']): ?>
                                <span class="text-[10px] text-red-500 font-bold italic">
                                    <i class="fa-solid fa-clock mr-1"></i> ปิดรับ <?= date('d/m/Y', strtotime($c['available_until'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <h3 class="text-lg font-bold text-gray-900 mb-2"><?= htmlspecialchars($c['title']) ?></h3>
                        <p class="text-gray-500 text-xs line-clamp-2 mb-4 leading-relaxed">
                            <?= nl2br(htmlspecialchars($c['description'] ?: 'ไม่มีรายละเอียดเพิ่มเติมสำหรับกิจกรรมนี้')) ?>
                        </p>

                        <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-50">
                            <div>
                                <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest">คงเหลือ</p>
                                <p class="text-xl font-black text-gray-900"><?= number_format(max(0, $remaining)) ?> <span class="text-xs font-normal text-gray-500">ที่นั่ง</span></p>
                            </div>
                            
                            <?php if (!$isFull): ?>
                                <a href="booking_date.php?campaign_id=<?= $c['id'] ?>" class="bg-[#0052CC] hover:bg-blue-700 text-white w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200 transition-all active:scale-95">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <button disabled class="bg-gray-100 text-gray-400 w-12 h-12 rounded-2xl flex items-center justify-center">
                                    <i class="fa-solid fa-lock"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-10 text-center">
        <a href="my_bookings.php" class="text-sm font-bold text-gray-400 hover:text-[#0052CC] transition-colors">
            <i class="fa-solid fa-receipt mr-1"></i> ตรวจสอบสถานะการจองของฉัน
        </a>
    </div>
</div>

<?php 
// เรียกใช้ฟังก์ชันเพื่อปิด Tag HTML
render_footer(); 
?>
