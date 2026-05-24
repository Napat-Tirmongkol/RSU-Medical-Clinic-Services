<?php
// admin/ajax/ajax_dashboard.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

try {
    $pdo = db();

    // 1) Top-line KPIs
    $row = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM camp_list WHERE status='active')                          AS active_campaigns,
            (SELECT COUNT(*) FROM camp_bookings WHERE status='booked')                      AS pending_count,
            (SELECT COUNT(*) FROM camp_bookings WHERE status='confirmed')                   AS confirmed_count,
            (SELECT COUNT(*) FROM camp_bookings WHERE created_at >= CURDATE())              AS bookings_today,
            (SELECT COUNT(*) FROM sys_users     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_users_7d
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2) Top 5 popular campaigns
    $popular = $pdo->query("
        SELECT c.id, c.title, COUNT(b.id) AS booking_count
        FROM camp_list c
        LEFT JOIN camp_bookings b ON c.id = b.campaign_id AND b.status IN ('booked','confirmed','completed')
        GROUP BY c.id
        ORDER BY booking_count DESC, c.id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3) Render popular HTML using new .ec-popular-row classes
    $popular_html = '';
    if (count($popular) > 0) {
        foreach ($popular as $idx => $pc) {
            $rankClass = $idx === 0
                ? 'ec-rank-1'
                : ($idx === 1 ? 'ec-rank-2' : ($idx === 2 ? 'ec-rank-3' : 'ec-rank-x'));
            $id    = (int)$pc['id'];
            $title = htmlspecialchars($pc['title'], ENT_QUOTES, 'UTF-8');
            $cnt   = number_format($pc['booking_count']);
            $rank  = $idx + 1;
            $popular_html .= <<<HTML
<a href="campaign_overview.php?id={$id}" class="ec-popular-row">
    <div class="ec-rank {$rankClass}">{$rank}</div>
    <span class="ec-popular-title">{$title}</span>
    <span class="ec-popular-meta">{$cnt} คน</span>
</a>
HTML;
        }
    } else {
        $popular_html = <<<HTML
<div class="ec-empty">
    <i class="fa-solid fa-inbox ec-empty-icon"></i>
    <div class="ec-empty-text">ยังไม่มีข้อมูลการจอง</div>
</div>
HTML;
    }

    echo json_encode([
        'status' => 'success',
        'stats'  => [
            'total'          => (int)($row['active_campaigns']   ?? 0),
            'pending'        => (int)($row['pending_count']      ?? 0),
            'confirmed'      => (int)($row['confirmed_count']    ?? 0),
            'bookings_today' => (int)($row['bookings_today']     ?? 0),
            'new_users_7d'   => (int)($row['new_users_7d']       ?? 0),
        ],
        'popular_html' => $popular_html,
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
