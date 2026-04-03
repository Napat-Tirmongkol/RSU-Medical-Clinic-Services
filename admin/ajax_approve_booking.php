<?php
// admin/ajax_approve_booking.php
session_start();
header('Content-Type: application/json');

// เช็คสิทธิ์ Admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

require_once __DIR__ . '/../config.php'; // ปรับ Path ให้ถอยออกไป 1 ชั้น

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId > 0) {
        try {
            $pdo = db();
            $sql = "UPDATE camp_bookings SET status = 'confirmed' WHERE id = :id AND status = 'booked'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $appointmentId]);

            if ($stmt->rowCount() > 0) {
                // 📧 ส่งอีเมลแจ้งเตือนการอนุมัติ
                try {
                    $stmtInfo = $pdo->prepare("
                        SELECT u.email, u.full_name, c.title,
                               s.slot_date, s.start_time, s.end_time
                        FROM camp_bookings b
                        JOIN sys_users  u ON b.student_id  = u.id
                        JOIN camp_list  c ON b.campaign_id = c.id
                        JOIN camp_slots s ON b.slot_id     = s.id
                        WHERE b.id = :id");
                    $stmtInfo->execute([':id' => $appointmentId]);
                    $bInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                    if ($bInfo && !empty($bInfo['email'])) {
                        require_once __DIR__ . '/../includes/mail_helper.php';
                        notify_booking_status($bInfo['email'], 'approved', [
                            'campaign_title' => $bInfo['title'],
                            'full_name'      => $bInfo['full_name'],
                            'date'           => date('d M Y', strtotime($bInfo['slot_date'])),
                            'time'           => substr($bInfo['start_time'], 0, 5) . ' - ' . substr($bInfo['end_time'], 0, 5),
                        ]);
                    }
                } catch (Exception $ex) {
                    error_log("Approval Email Error: " . $ex->getMessage());
                }

                echo json_encode(['status' => 'success', 'message' => 'อนุมัติคิวสำเร็จ']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถอัปเดตได้ คิวอาจถูกอนุมัติหรือถูกยกเลิกไปแล้ว']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
