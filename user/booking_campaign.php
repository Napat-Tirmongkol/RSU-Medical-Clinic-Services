<?php
// user/booking_campaign.php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();
check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));

$camp_list = [];
$camp_error = null;
try {
    $pdo = db();
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
} catch (PDOException $e) {
    error_log("booking_campaign DB error: " . $e->getMessage());
    $camp_error = true;
}

function getBadge($type): array {
    return match($type) {
        'vaccine'      => ['label' => __('campaign.type_vaccine'), 'class' => 'bg-blue-100 text-blue-700',    'icon' => 'fa-syringe'],
        'training'     => ['label' => __('campaign.type_training'),'class' => 'bg-purple-100 text-purple-700', 'icon' => 'fa-chalkboard-user'],
        'health_check' => ['label' => __('campaign.type_health'),  'class' => 'bg-green-100 text-green-700',  'icon' => 'fa-stethoscope'],
        default        => ['label' => __('campaign.type_other'),   'class' => 'bg-gray-100 text-gray-700',    'icon' => 'fa-star'],
    };
}

render_header(__('campaign.page_title'));
?>

<div class="p-6 pt-10 pb-24 relative z-10 flex flex-col min-h-screen bg-white rounded-t-[32px] animate-in fade-in slide-in-from-right-4 duration-500">
    <!-- Header Section -->
    <div class="mb-8">
        <div class="flex items-center gap-3 mb-1">
            <div class="w-1.5 h-6 bg-orange-500 rounded-full"></div>
            <h1 class="text-2xl font-black text-gray-900 font-prompt tracking-tight">
                <?= htmlspecialchars(__('campaign.heading')) ?> <span class="text-[#0052CC]"><?= htmlspecialchars(__('campaign.heading_accent')) ?></span>
            </h1>
        </div>
        <p class="text-[13px] text-gray-400 font-medium font-prompt ml-4">
            <?= htmlspecialchars(__('campaign.desc')) ?>
        </p>
    </div>

    <?php if ($camp_error): ?>
        <div class="bg-red-50 rounded-3xl p-10 text-center border-2 border-dashed border-red-200">
            <div class="text-4xl mb-4 text-red-300"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <p class="text-red-500 font-medium">ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่อีกครั้ง</p>
        </div>
    <?php elseif (count($camp_list) === 0): ?>
        <div class="bg-gray-50 rounded-3xl p-10 text-center border-2 border-dashed border-gray-200">
            <div class="text-4xl mb-4 text-gray-300"><i class="fa-solid fa-calendar-xmark"></i></div>
            <p class="text-gray-500 font-medium"><?= __('campaign.empty') ?></p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <?php foreach ($camp_list as $c):
                $badge     = getBadge($c['type']);
                $remaining = $c['total_capacity'] - $c['used_seats'];
                $isFull    = ($remaining <= 0);
            ?>
                <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-shadow relative">
                    <?php if ($isFull): ?>
                        <div class="absolute inset-0 bg-white/60 backdrop-blur-[1px] z-10 flex items-center justify-center">
                            <span class="bg-red-500 text-white px-4 py-1 rounded-full text-sm font-bold rotate-[-5deg] shadow-lg">
                                <?= htmlspecialchars(__('campaign.full_badge')) ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $badge['class'] ?>">
                                <i class="fa-solid <?= $badge['icon'] ?> mr-1"></i> <?= htmlspecialchars($badge['label']) ?>
                            </span>
                            <?php if ($c['available_until']): ?>
                                <span class="text-[10px] text-red-500 font-bold italic">
                                    <i class="fa-solid fa-clock mr-1"></i>
                                    <?= htmlspecialchars(__('campaign.closes')) ?> <?= date('d/m/Y', strtotime($c['available_until'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <h3 class="text-lg font-bold text-gray-900 mb-2"><?= htmlspecialchars($c['title']) ?></h3>
                        <p class="text-gray-500 text-xs line-clamp-2 mb-4 leading-relaxed">
                            <?= nl2br(htmlspecialchars($c['description'] ?: __('campaign.no_desc'))) ?>
                        </p>

                        <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-50">
                            <div>
                                <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest">
                                    <?= htmlspecialchars(__('campaign.remaining')) ?>
                                </p>
                                <p class="text-xl font-black text-gray-900">
                                    <?= number_format(max(0, $remaining)) ?>
                                    <span class="text-xs font-normal text-gray-500"><?= htmlspecialchars(__('campaign.seats')) ?></span>
                                </p>
                            </div>

                            <?php if (!$isFull): ?>
                                <a href="booking_date.php?campaign_id=<?= $c['id'] ?>"
                                   class="bg-[#0052CC] hover:bg-blue-700 text-white w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200 transition-all active:scale-95">
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
            <i class="fa-solid fa-receipt mr-1"></i> <?= htmlspecialchars(__('campaign.my_bookings_link')) ?>
        </a>
    </div>
</div>

<?php render_footer(); ?>
