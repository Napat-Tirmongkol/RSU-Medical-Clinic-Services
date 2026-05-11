<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php', true, 303);
    exit;
}

validate_csrf_or_die();

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    die("Session หมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้งผ่าน LINE");
}

// ── Sanitize input ─────────────────────────────────────────────────────────
$_nameTitle  = trim((string) ($_POST['name_title']   ?? ''));
$_customTitle= trim((string) ($_POST['custom_title'] ?? ''));
$prefix      = ($_nameTitle === 'other') ? $_customTitle : $_nameTitle;
$firstName   = trim((string) ($_POST['first_name']   ?? ''));
$lastName    = trim((string) ($_POST['last_name']    ?? ''));
$fullName    = trim($firstName . ' ' . $lastName);
$idNumber    = trim((string) ($_POST['id_number']    ?? ''));
$citizenId   = trim((string) ($_POST['citizen_id']   ?? ''));
$idType      = trim((string) ($_POST['id_type']      ?? 'citizen'));
$phoneNumber = preg_replace('/\D/', '', (string) ($_POST['phone_number'] ?? '')) ?? '';
$status      = trim((string) ($_POST['status']       ?? ''));
$email       = trim((string) ($_POST['email']        ?? ''));
$gender      = trim((string) ($_POST['gender']       ?? ''));
$dobInput    = trim((string) ($_POST['date_of_birth'] ?? ''));
// Validate DOB: empty OR Y-m-d format AND not future
$dateOfBirth = null;
if ($dobInput !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $dobInput);
    if ($dt && $dt->format('Y-m-d') === $dobInput && $dt <= new DateTime()) {
        $dateOfBirth = $dobInput;
    }
}
$department  = trim((string) ($_POST['department']   ?? ''));
$redirectBack = trim((string) ($_POST['redirect_back'] ?? ''));

// New medical fields
$bloodType    = trim((string) ($_POST['blood_type']        ?? ''));
$heightCm     = $_POST['height_cm'] ?? '';
$weightKg     = $_POST['weight_kg'] ?? '';
$allergies    = trim((string) ($_POST['allergies']         ?? ''));
$chronic      = trim((string) ($_POST['chronic_conditions'] ?? ''));
$emName       = trim((string) ($_POST['emergency_contact_name']     ?? ''));
$emPhone      = preg_replace('/\D/', '', (string) ($_POST['emergency_contact_phone'] ?? '')) ?? '';
$emRelation   = trim((string) ($_POST['emergency_contact_relation'] ?? ''));

$heightCm = ($heightCm === '' || !is_numeric($heightCm)) ? null : (float) $heightCm;
$weightKg = ($weightKg === '' || !is_numeric($weightKg)) ? null : (float) $weightKg;

$bloodAllowed = ['', 'A', 'B', 'AB', 'O', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
if (!in_array($bloodType, $bloodAllowed, true)) $bloodType = '';

// ── Validation ─────────────────────────────────────────────────────────────
if ($prefix === '') { header('Location: profile.php?error=no_prefix', true, 303); exit; }
if ($status === '') { header('Location: profile.php?error=no_status', true, 303); exit; }
if (!in_array($gender, ['male', 'female', 'other'], true)) {
    header('Location: profile.php?error=no_gender', true, 303); exit;
}
if ($firstName === '' || $lastName === '' || $citizenId === '' || $phoneNumber === '') {
    header('Location: profile.php?error=empty', true, 303); exit;
}
if (!preg_match('/^0\d{9}$/', $phoneNumber)) {
    header('Location: profile.php?error=invalid_phone', true, 303); exit;
}
if ($idType !== 'passport') {
    if (!preg_match('/^\d{13}$/', $citizenId)) {
        header('Location: profile.php?error=invalid_citizen', true, 303); exit;
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$citizenId[$i] * (13 - $i);
    $check = (11 - ($sum % 11)) % 10;
    if ($check !== (int)$citizenId[12]) {
        header('Location: profile.php?error=invalid_citizen', true, 303); exit;
    }
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: profile.php?error=invalid_email', true, 303); exit;
}
if ($status !== 'other' && $idNumber === '') {
    header('Location: profile.php?error=empty_student', true, 303); exit;
}

try {
    $pdo = db();

    // Migration: ensure columns exist
    foreach ([
        'prefix'                     => "VARCHAR(20) NOT NULL DEFAULT ''",
        'first_name'                 => "VARCHAR(100) NOT NULL DEFAULT ''",
        'last_name'                  => "VARCHAR(100) NOT NULL DEFAULT ''",
        'date_of_birth'              => "DATE NULL DEFAULT NULL",
        'blood_type'                 => "VARCHAR(8) NOT NULL DEFAULT ''",
        'height_cm'                  => "DECIMAL(5,2) NULL DEFAULT NULL",
        'weight_kg'                  => "DECIMAL(5,2) NULL DEFAULT NULL",
        'allergies'                  => "VARCHAR(500) NOT NULL DEFAULT ''",
        'chronic_conditions'         => "VARCHAR(500) NOT NULL DEFAULT ''",
        'emergency_contact_name'     => "VARCHAR(120) NOT NULL DEFAULT ''",
        'emergency_contact_phone'    => "VARCHAR(20)  NOT NULL DEFAULT ''",
        'emergency_contact_relation' => "VARCHAR(50)  NOT NULL DEFAULT ''",
        'member_id'                  => "VARCHAR(20) NOT NULL DEFAULT ''",
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS {$col} {$def}"); } catch (PDOException) {}
    }

    $sidValue = ($status === 'other') ? null : $idNumber;

    $stmtCheck = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmtCheck->execute([':line_id' => $lineUserId]);
    $existingUser = $stmtCheck->fetch();

    $params = [
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
        ':dob'        => $dateOfBirth,
        ':dept'       => $department,
        ':blood'      => $bloodType,
        ':height'     => $heightCm,
        ':weight'     => $weightKg,
        ':allergies'  => $allergies,
        ':chronic'    => $chronic,
        ':em_name'    => $emName,
        ':em_phone'   => $emPhone,
        ':em_rel'     => $emRelation,
        ':line_id'    => $lineUserId,
    ];

    if ($existingUser) {
        $sql = "UPDATE sys_users SET
                    prefix = :prefix, first_name = :first_name, last_name = :last_name,
                    full_name = :name, student_personnel_id = :sid,
                    citizen_id = :cid, phone_number = :phone, status = :status,
                    email = :email, gender = :gender, date_of_birth = :dob, department = :dept,
                    blood_type = :blood, height_cm = :height, weight_kg = :weight,
                    allergies = :allergies, chronic_conditions = :chronic,
                    emergency_contact_name = :em_name,
                    emergency_contact_phone = :em_phone,
                    emergency_contact_relation = :em_rel
                WHERE line_user_id = :line_id";
    } else {
        $sql = "INSERT INTO sys_users
                    (line_user_id, prefix, first_name, last_name, full_name,
                     student_personnel_id, citizen_id, phone_number, status, email,
                     gender, date_of_birth, department, blood_type, height_cm, weight_kg,
                     allergies, chronic_conditions,
                     emergency_contact_name, emergency_contact_phone, emergency_contact_relation)
                VALUES
                    (:line_id, :prefix, :first_name, :last_name, :name,
                     :sid, :cid, :phone, :status, :email,
                     :gender, :dob, :dept, :blood, :height, :weight,
                     :allergies, :chronic,
                     :em_name, :em_phone, :em_rel)";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $logAction = $existingUser ? 'Update Profile' : 'Register';
    $logDesc = $existingUser ? "ผู้ป่วยอัปเดตข้อมูลส่วนตัว '{$fullName}'" : "ผู้ป่วยลงทะเบียนเข้าใช้งานครั้งแรก '{$fullName}'";
    log_activity($logAction, $logDesc, (int)($existingUser['id'] ?? $pdo->lastInsertId()));

    $stmtGetId = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmtGetId->execute([':line_id' => $lineUserId]);
    $user = $stmtGetId->fetch();

    if ($user) {
        $studentPkId = (int) $user['id'];
        $_SESSION['student_id'] = $studentPkId;
        $_SESSION['student_full_name'] = $fullName;
    } else {
        throw new Exception("ไม่พบข้อมูลผู้ใช้งานในระบบ");
    }

    // ── Redirect logic (whitelist redirect_back) ─────────────────────────
    $safeRedirectBack = '';
    if ($redirectBack !== '' && preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?[^\s]*)?$/', $redirectBack)) {
        $safeRedirectBack = $redirectBack;
    }

    $inviteToken = $_SESSION['invite_token'] ?? '';
    unset($_SESSION['invite_token']);

    if ($safeRedirectBack !== '') {
        $sep = (strpos($safeRedirectBack, '?') !== false) ? '&' : '?';
        header('Location: ' . $safeRedirectBack . $sep . 'saved=1', true, 303);
    } elseif ($inviteToken !== '') {
        header('Location: c.php?t=' . urlencode($inviteToken), true, 303);
    } else {
        header('Location: profile.php?saved=1', true, 303);
    }
    exit;

} catch (Exception $e) {
    error_log("save_profile error: " . $e->getMessage());
    http_response_code(500);
    exit("เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง");
}
