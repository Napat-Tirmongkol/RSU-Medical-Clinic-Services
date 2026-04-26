<?php
// user/index.php — User Portal Entry
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

// If already logged in, check if profile is complete
$lineUserId = $_SESSION['line_user_id'] ?? '';

if ($lineUserId !== '') {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id AND clinic_id = :clinic_id LIMIT 1");
        $stmt->execute([':line_id' => $lineUserId, ':clinic_id' => CLINIC_ID]);
        $user = $stmt->fetch();

        if ($user) {
            // Check if they have at least a name (basic profile completion)
            if (empty($user['full_name'])) {
                header('Location: profile.php');
                exit;
            }
            
            // Check for pending/confirmed bookings for redirection logic if needed
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings b JOIN sys_users u ON b.student_id = u.student_personnel_id WHERE u.line_user_id = :line_id AND b.status IN ('confirmed', 'booked')");
            $stmtCheck->execute([':line_id' => $lineUserId]);
            $hasBooking = (int)$stmtCheck->fetchColumn() > 0;

            header('Location: hub.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Index login error: " . $e->getMessage());
    }
}

// If not logged in, redirect to LINE login
header('Location: line_login.php');
exit;