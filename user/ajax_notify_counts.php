<?php
// user/ajax_notify_counts.php — lightweight poll endpoint for hub realtime/fallback
// Returns counts the bell + reminder strip rely on so the client can detect deltas.
declare(strict_types=1);
@session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

try {
    $pdo = db();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
    $stmt->execute([':lid' => $lineUserId]);
    $userId = (int) ($stmt->fetchColumn() ?: 0);
    if ($userId === 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'profile_not_found']);
        exit;
    }

    $upcoming = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings b
                            JOIN camp_slots s ON b.slot_id = s.id
                            WHERE b.student_id = :sid AND s.slot_date >= :today AND b.status != 'cancelled'");
        $s->execute([':sid' => $userId, ':today' => $today]);
        $upcoming = (int) $s->fetchColumn();
    } catch (Exception) {}

    $borrowPending = 0;
    $borrowOverdue = 0;
    $borrowFine    = 0.0;
    try {
        $s = $pdo->prepare("
            SELECT t.approval_status, t.due_date
            FROM borrow_records t
            WHERE t.borrower_student_id = :sid
              AND t.status = 'borrowed'
              AND t.approval_status IN ('approved','pending')
        ");
        $s->execute([':sid' => $userId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['approval_status'] ?? '') === 'pending') {
                $borrowPending++;
            } elseif (($row['approval_status'] ?? '') === 'approved'
                      && !empty($row['due_date'])
                      && $row['due_date'] < $today) {
                $borrowOverdue++;
            }
        }
    } catch (Exception) {}

    try {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(f.amount), 0)
            FROM borrow_fines f
            JOIN borrow_records t ON f.transaction_id = t.id
            WHERE t.borrower_student_id = :sid AND f.status = 'pending'
        ");
        $s->execute([':sid' => $userId]);
        $borrowFine = (float) $s->fetchColumn();
    } catch (Exception) {}

    $total = $upcoming + $borrowPending + $borrowOverdue;
    echo json_encode([
        'ok'        => true,
        'ts'        => time(),
        'total'     => $total,
        'upcoming'  => $upcoming,
        'pending'   => $borrowPending,
        'overdue'   => $borrowOverdue,
        'fine'      => $borrowFine,
    ]);
} catch (Exception $e) {
    error_log('ajax_notify_counts: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
