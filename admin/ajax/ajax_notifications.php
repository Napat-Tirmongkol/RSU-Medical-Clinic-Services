<?php
// admin/ajax_notifications.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

try {
    $pdo = db();

    $errors_today = (int)$pdo->query(
        "SELECT COUNT(*) FROM sys_error_logs WHERE level = 'error' AND DATE(created_at) = CURDATE()"
    )->fetchColumn();

    echo json_encode([
        'status'          => 'success',
        'errors_today'    => $errors_today,
        'pending_bookings'=> 0,
        'total'           => $errors_today,
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status'          => 'error',
        'errors_today'    => 0,
        'pending_bookings'=> 0,
        'total'           => 0,
    ]);
}
