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
// name_title = ค่าจาก select, custom_title = กรณีเลือก "other"
$_nameTitle  = trim((string) ($_POST['name_title']   ?? ''));
$_customTitle= trim((string) ($_POST['custom_title'] ?? ''));
$prefix      = ($_nameTitle === 'other') ? $_customTitle : $_nameTitle;
$firstName   = trim((string) ($_POST['first_name']   ?? ''));
$lastName    = trim((string) ($_POST['last_name']    ?? ''));
$fullName    = trim($firstName . ' ' . $lastName);  // sync ให้ code เดิมยังใช้ได้
$idNumber    = trim((string) ($_POST['id_number']    ?? ''));
$citizenId   = trim((string) ($_POST['citizen_id']   ?? ''));
$phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
$status      = trim((string) ($_POST['status']       ?? ''));
$email       = trim((string) ($_POST['email']        ?? ''));
$gender      = trim((string) ($_POST['gender']       ?? ''));
$department  = trim((string) ($_POST['department']   ?? ''));
$redirectBack = trim((string) ($_POST['redirect_back'] ?? ''));

if ($prefix === '') {
    header('Location: profile.php?error=no_prefix', true, 303);
    exit;
}

if ($status === '') {
    header('Location: profile.php?error=no_status', true, 303);
    exit;
}

if (!in_array($gender, ['male', 'female', 'other'], true)) {
    header('Location: profile.php?error=no_gender', true, 303);
    exit;
}

if ($firstName === '' || $lastName === '' || $citizenId === '' || $phoneNumber === '') {
    header('Location: profile.php?error=empty', true, 303);
    exit;
}

// ตรวจสอบความถูกต้องของอีเมล (ถ้ากรอกมา)
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: profile.php?error=invalid_email', true, 303);
    exit;
}

// ถ้าไม่ใช่ other ต้องมีรหัส
if ($status !== 'other' && $idNumber === '') {
    header('Location: profile.php?error=empty_student', true, 303);
    exit;
}

try {
    $pdo = db();

    // Migration: เพิ่ม columns ที่ยังไม่มี
    try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS prefix     VARCHAR(20)  NOT NULL DEFAULT ''"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NOT NULL DEFAULT ''"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100) NOT NULL DEFAULT ''"); } catch (PDOException) {}

    $sidValue = ($status === 'other') ? null : $idNumber;

    // 3. ตรวจสอบว่ามี Record อยู่แล้วหรือไม่ (scoped to clinic)
    $stmtCheck = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id AND clinic_id = :clinic_id LIMIT 1");
    $stmtCheck->execute([':line_id' => $lineUserId, ':clinic_id' => CLINIC_ID]);
    $existingUser = $stmtCheck->fetch();

    if ($existingUser) {
        // --- UPDATE (clinic-scoped) ---
        $sql = "UPDATE sys_users
                SET prefix = :prefix,
                    first_name = :first_name,
                    last_name  = :last_name,
                    full_name  = :name,
                    student_personnel_id = :sid,
                    citizen_id   = :cid,
                    phone_number = :phone,
                    status = :status,
                    email  = :email,
                    gender = :gender,
                    department = :dept
                WHERE line_user_id = :line_id AND clinic_id = :clinic_id";
    } else {
        // --- INSERT (with clinic_id) ---
        $sql = "INSERT INTO sys_users
                    (clinic_id, line_user_id, prefix, first_name, last_name, full_name, student_personnel_id, citizen_id, phone_number, status, email, gender, department)
                VALUES
                    (:clinic_id, :line_id, :prefix, :first_name, :last_name, :name, :sid, :cid, :phone, :status, :email, :gender, :dept)";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':clinic_id'  => CLINIC_ID,
        ':prefix'     => $prefix,
        ':first_name' => $firstName,
        ':last_name'  => $lastName,
        ':name'       => $fullName,
        ':sid'        => $sidValue,
        ':cid'        => $citizenId,
        ':phone'      => $phoneNumber,
        ':status'     => $status,
        ':email'      => $email,
        ':gender'     => $gender,
        ':dept'       => $department,
        ':line_id'    => $lineUserId,
    ]);

    // ✅ บันทึก Log: ลงทะเบียนหรือแก้ไขโปรไฟล์
    $logAction = $existingUser ? 'Update Profile' : 'Register';
    $logDesc = $existingUser ? "ผู้ป่วยอัปเดตข้อมูลส่วนตัว '{$fullName}'" : "ผู้ป่วยลงทะเบียนเข้าใช้งานครั้งแรก '{$fullName}'";
    log_activity($logAction, $logDesc, (int)($existingUser['id'] ?? $pdo->lastInsertId()));

    // 4. ดึง ID (PK) ของผู้ใช้เพื่อเก็บใส่ Session (clinic-scoped)
    $stmtGetId = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id AND clinic_id = :clinic_id LIMIT 1");
    $stmtGetId->execute([':line_id' => $lineUserId, ':clinic_id' => CLINIC_ID]);
    $user = $stmtGetId->fetch();

    if ($user) {
        $studentPkId = (int) $user['id'];
        $_SESSION['student_id'] = $studentPkId;
        $_SESSION['student_full_name'] = $fullName;
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

    // ลำดับความสำคัญของ redirect:
    // 1. redirect_back (แก้ไขโปรไฟล์จากหน้าใดหน้าหนึ่ง)
    // 2. invite_token (มาจากลิงก์ campaign เฉพาะ)
    // 3. hasBooking → my_bookings
    // 4. default → booking_campaign
    $safeRedirectBack = '';
    if ($redirectBack !== '') {
        if (preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?[^\s]*)?$/', $redirectBack)) {
            $safeRedirectBack = $redirectBack;
        }
    }

    $inviteToken = $_SESSION['invite_token'] ?? '';
    unset($_SESSION['invite_token']);

    if ($safeRedirectBack !== '') {
        header('Location: ' . $safeRedirectBack, true, 303);
    } elseif ($inviteToken !== '') {
        header('Location: c.php?t=' . urlencode($inviteToken), true, 303);
    } else {
        header('Location: hub.php', true, 303);
    }
    exit;

} catch (Exception $e) {
    error_log("save_profile error: " . $e->getMessage()); http_response_code(500); exit("เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง");
}
