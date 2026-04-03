<?php
/**
 * e-campaignv2/index.php  — Root entry point
 *
 * Browser / LINE LIFF มักจะ request /e-campaignv2/index.php
 * ไฟล์นี้จะตรวจสอบ session แล้วส่งต่อไปหน้าที่ถูกต้อง
 * โดยไม่ต้องให้ user เห็นหน้าขาว
 */
declare(strict_types = 1)
;
session_start();

// กำหนด base URL ของ user section
define('USER_BASE', 'user/');

// ถ้า login อยู่แล้ว → ตรวจสอบสถานะและ redirect
if (!empty($_SESSION['line_user_id']) || !empty($_SESSION['evax_student_id'])) {
    require_once __DIR__ . '/config/db_connect.php';

    try {
        $pdo = db();
        $sid = $_SESSION['evax_student_id'] ?? null;

        if ($sid) {
            $stmtCheck = $pdo->prepare("
                SELECT COUNT(*) FROM camp_bookings
                WHERE student_id = :sid AND status IN ('confirmed','booked')
            ");
            $stmtCheck->execute([':sid' => $sid]);
            $hasBooking = (int)$stmtCheck->fetchColumn() > 0;

            $stmtProfile = $pdo->prepare("SELECT full_name, student_personnel_id, phone_number, status FROM sys_users WHERE id = :id LIMIT 1");
            $stmtProfile->execute([':id' => $sid]);
            $profile = $stmtProfile->fetch();

            if ($hasBooking) {
                header('Location: ' . USER_BASE . 'my_bookings.php', true, 302);
            }
            elseif (!empty($profile['full_name']) && !empty($profile['phone_number']) && !empty($profile['status']) &&
                    ($profile['status'] === 'other' || !empty($profile['student_personnel_id']))) {
                header('Location: ' . USER_BASE . 'booking_campaign.php', true, 302);
            }
            else {
                header('Location: ' . USER_BASE . 'profile.php', true, 302);
            }
        }
        else {
            // มี line_user_id แต่ยัง link กับ med_student ไม่ได้
            header('Location: ' . USER_BASE . 'profile.php', true, 302);
        }
        exit;
    }
    catch (PDOException $e) {
        // DB error → ล้าง session แล้ว redirect ไป login
        session_destroy();
        header('Location: ' . USER_BASE . 'index.php', true, 302);
    }
    exit;
}

// ยังไม่ login → ไปหน้า login (LINE OAuth)
header('Location: ' . USER_BASE . 'index.php', true, 302);
exit;

