<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

session_start();

// 1. ตรวจสอบเงื่อนไขก่อนเริ่ม
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php', true, 303);
    exit;
}

validate_csrf_or_die();

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    die("Session หมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้งผ่าน LINE");
}

// 2. รับค่าและทำความสะอาดข้อมูล (Sanitize)
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$idNumber = trim((string) ($_POST['id_number'] ?? ''));
$citizenId = trim((string) ($_POST['citizen_id'] ?? ''));
$phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
$status = trim((string) ($_POST['status'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$redirectBack = trim((string) ($_POST['redirect_back'] ?? ''));

if ($status === '') {
    header('Location: profile.php?error=no_status', true, 303);
    exit;
}

if ($fullName === '' || $citizenId === '' || $phoneNumber === '') {
    header('Location: profile.php?error=empty', true, 303);
    exit;
}

// ถ้าไม่ใช่ other ต้องมีรหัส
if ($status !== 'other' && $idNumber === '') {
    header('Location: profile.php?error=empty_student', true, 303);
    exit;
}

try {
    $pdo = db();

    $sidValue = ($status === 'other') ? null : $idNumber;

    // 3. ตรวจสอบว่ามี Record อยู่แล้วหรือไม่
    $stmtCheck = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmtCheck->execute([':line_id' => $lineUserId]);
    $existingUser = $stmtCheck->fetch();

    if ($existingUser) {
        // --- UPDATE สำหรับผู้ใช้ที่มีอยู่แล้ว ---
        $sql = "UPDATE sys_users
                SET full_name = :name,
                    student_personnel_id = :sid,
                    citizen_id = :cid,
                    phone_number = :phone,
                    status = :status,
                    email = :email
                WHERE line_user_id = :line_id";
    } else {
        // --- INSERT สำหรับผู้ใช้ใหม่ที่ยังไม่เคยลงทะเบียน ---
        $sql = "INSERT INTO sys_users (line_user_id, full_name, student_personnel_id, citizen_id, phone_number, status, email)
                VALUES (:line_id, :name, :sid, :cid, :phone, :status, :email)";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name'    => $fullName,
        ':sid'     => $sidValue,
        ':cid'     => $citizenId,
        ':phone'   => $phoneNumber,
        ':status'  => $status,
        ':email'   => $email,
        ':line_id' => $lineUserId,
    ]);

    // 4. ดึง ID (PK) ของผู้ใช้เพื่อเก็บใส่ Session
    $stmtGetId = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmtGetId->execute([':line_id' => $lineUserId]);
    $user = $stmtGetId->fetch();

    if ($user) {
        $studentPkId = (int) $user['id'];
        $_SESSION['evax_student_id'] = $studentPkId;
        $_SESSION['evax_full_name'] = $fullName;
        // sync session สำหรับ e_Borrow ด้วย
        $_SESSION['student_id']        = $studentPkId;
        $_SESSION['student_full_name'] = $fullName;
    } else {
        throw new Exception("ไม่พบข้อมูลผู้ใช้งานในระบบ");
    }

    // 5. เงื่อนไขพิเศษ: เช็คประวัติการจอง
    // ถ้ามีคิวที่ยังไม่ถูกยกเลิก (confirmed/booked) ให้ไปหน้า My Bookings เลย
    $checkBookingSql = "
        SELECT COUNT(*) 
        FROM vac_appointments 
        WHERE student_id = :student_id AND status IN ('confirmed', 'booked')
    ";
    $stmtCheck = $pdo->prepare($checkBookingSql);
    $stmtCheck->execute([':student_id' => $studentPkId]);
    $hasBooking = (int) $stmtCheck->fetchColumn() > 0;

    // ถ้ามี redirect_back ที่ปลอดภัยให้ใช้
    $safeRedirectBack = '';
    if ($redirectBack !== '') {
        // อนุญาตเฉพาะ relative path ภายใน user/ เท่านั้น
        if (preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?[^\s]*)?$/', $redirectBack)) {
            $safeRedirectBack = $redirectBack;
        }
    }

    if ($safeRedirectBack !== '') {
        header('Location: ' . $safeRedirectBack, true, 303);
    } elseif ($hasBooking) {
        header('Location: my_bookings.php', true, 303);
    } else {
        header('Location: booking_campaign.php', true, 303);
    }
    exit;

} catch (Exception $e) {
    die("เกิดข้อผิดพลาด: " . $e->getMessage());
}
