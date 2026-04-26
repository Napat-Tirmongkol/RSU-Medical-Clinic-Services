<?php
// admin/ajax/ajax_dashboard.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

try {
    $pdo = db();
    
    // 1. ดึงสถิติภาพรวม
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_campaigns,
            (SELECT COUNT(*) FROM camp_bookings WHERE status = 'booked' AND clinic_id = :cid1) as pending_count,
            (SELECT COUNT(*) FROM camp_bookings WHERE status = 'confirmed' AND clinic_id = :cid2) as confirmed_count,
            (SELECT COUNT(*) FROM camp_bookings WHERE created_at >= CURDATE() AND clinic_id = :cid3) as bookings_today,
            (SELECT COUNT(*) FROM sys_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND clinic_id = :cid4) as new_users_7d
        FROM camp_list WHERE status = 'active' AND clinic_id = :cid5
    ");
    $stmt->execute([
        ':cid1' => clinic_id(),
        ':cid2' => clinic_id(),
        ':cid3' => clinic_id(),
        ':cid4' => clinic_id(),
        ':cid5' => clinic_id(),
    ]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_campaigns' => 0,
        'pending_count' => 0,
        'confirmed_count' => 0,
        'bookings_today' => 0,
        'new_users_7d' => 0
    ];

    // 2. ดึง 5 แคมเปญยอดฮิต
    $popular_stmt = $pdo->query("
        SELECT c.title, COUNT(a.id) as booking_count
        FROM camp_list c
        LEFT JOIN camp_bookings a ON c.id = a.campaign_id AND a.status IN ('booked', 'confirmed')
        GROUP BY c.id
        ORDER BY booking_count DESC
        LIMIT 5
    ");
    $popular_campaigns = $popular_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. สร้างก้อน HTML สำหรับรายชื่อแคมเปญยอดฮิต
    $popular_html = '';
    if (count($popular_campaigns) > 0) {
        foreach($popular_campaigns as $index => $pc) {
            $rankColors = ['bg-orange-100 text-orange-600', 'bg-gray-100 text-gray-600', 'bg-amber-100 text-amber-600'];
            $rankClass = $index < 3 ? $rankColors[$index] : 'bg-[#e8f8f0] text-[#2e9e63]';
            $num = $index + 1;
            $title = htmlspecialchars($pc['title']);
            $count = number_format($pc['booking_count']);
            
            $popular_html .= '
            <div class="group flex justify-between items-center p-2.5 sm:p-3 rounded-2xl hover:bg-gray-50 transition-colors border border-transparent hover:border-gray-100 gap-3">
                <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                    <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center font-bold text-xs sm:text-sm flex-shrink-0 '.$rankClass.'">
                        '.$num.'
                    </div>
                    <span class="text-gray-800 font-semibold group-hover:text-[#2e9e63] transition-colors text-sm truncate">'.$title.'</span>
                </div>
                <span class="bg-white border border-gray-200 shadow-sm px-3 sm:px-4 py-1 sm:py-1.5 rounded-full text-xs font-bold text-gray-700 whitespace-nowrap flex-shrink-0">
                    '.$count.' คน
                </span>
            </div>';
        }
    } else {
        $popular_html = '
        <div class="text-center py-10">
            <i class="fa-solid fa-inbox text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-500 text-sm">ยังไม่มีข้อมูลการจอง</p>
        </div>';
    }

    // ส่งกลับไปให้ Javascript อัปเดตหน้าจอ
    echo json_encode([
        'status' => 'success',
        'stats' => [
            'total'          => (int)$stats['total_campaigns'],
            'pending'        => (int)$stats['pending_count'],
            'confirmed'      => (int)$stats['confirmed_count'],
            'bookings_today' => (int)$stats['bookings_today'],
            'new_users_7d'   => (int)$stats['new_users_7d'],
        ],
        'popular_html' => $popular_html
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
