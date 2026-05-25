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

// ── PDPA v2 consent (Sec. 24 + Sec. 26 split) ──────────────────────────────
// Both checkboxes are required for first-time registration. Edit-mode
// submissions from users who already have a consent record on file don't
// re-stamp (we trust the existing consent, see check further down).
$consentGeneral   = !empty($_POST['consent_general'])   && (string)$_POST['consent_general']   === '1';
$consentSensitive = !empty($_POST['consent_sensitive']) && (string)$_POST['consent_sensitive'] === '1';
$pdpaVersion      = trim((string) ($_POST['pdpa_version'] ?? ''));
// Whitelist to prevent a malicious POST from stamping an arbitrary version
if (!preg_match('/^pdpa_v\d+_\d{4}-\d{2}$/', $pdpaVersion)) {
    $pdpaVersion = 'pdpa_v2_2025-05';
}
$consentIp        = $_SERVER['REMOTE_ADDR'] ?? '';
$consentUserAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
// Hash the version tag so we can spot if someone replays an old record
$consentTextHash  = hash('sha256', $pdpaVersion);

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
        // PDPA v2 granular consent columns (Sec. 24 + Sec. 26)
        'consent_general_accepted_at'    => "DATETIME NULL DEFAULT NULL",
        'consent_general_version'        => "VARCHAR(50)  NULL DEFAULT NULL",
        'consent_general_text_hash'      => "VARCHAR(64)  NULL DEFAULT NULL",
        'consent_sensitive_accepted_at'  => "DATETIME NULL DEFAULT NULL",
        'consent_sensitive_version'      => "VARCHAR(50)  NULL DEFAULT NULL",
        'consent_sensitive_text_hash'    => "VARCHAR(64)  NULL DEFAULT NULL",
        'consent_ip'                     => "VARCHAR(45)  NULL DEFAULT NULL",
        'consent_user_agent'             => "VARCHAR(500) NULL DEFAULT NULL",
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS {$col} {$def}"); } catch (PDOException) {}
    }

    $sidValue = ($status === 'other') ? null : $idNumber;

    $stmtCheck = $pdo->prepare("SELECT id, consent_general_accepted_at, consent_sensitive_accepted_at
                                FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmtCheck->execute([':line_id' => $lineUserId]);
    $existingUser = $stmtCheck->fetch();

    // PDPA gate (Sec. 24 + Sec. 26):
    //   ทั้ง first-time และ existing user ต้องยอมรับทั้ง 2 ข้อทุกครั้งที่บันทึก —
    //   ป้องกันกรณีผู้ใช้ uncheck checkbox แล้วกดบันทึก (ก่อนหน้านี้ระบบบันทึก
    //   เงียบ ๆ โดย preserve timestamp เดิมไว้ ทำให้กลายเป็นข้อความ "กลับมาเป็น
    //   กดยอมรับ" บนหน้าจอ ซึ่งทำให้ผู้ใช้สับสน). ถ้าต้องการถอนความยินยอม
    //   จริง ๆ ต้องผ่าน flow แยก (PDPA withdrawal) — บันทึก profile ปกติต้อง
    //   ยังคงยินยอมอยู่ทั้ง 2 ข้อ.
    $isFirstTime = empty($existingUser);
    $hasPriorGeneral   = !empty($existingUser['consent_general_accepted_at']);
    $hasPriorSensitive = !empty($existingUser['consent_sensitive_accepted_at']);

    if (!$consentGeneral) {
        header('Location: profile.php?mode=edit&error=no_consent_general', true, 303); exit;
    }
    if (!$consentSensitive) {
        header('Location: profile.php?mode=edit&error=no_consent_sensitive', true, 303); exit;
    }
    // We only stamp the consent timestamp on the row when the user is actually
    // giving NEW consent. SQL below uses COALESCE(:new, existing) so a return
    // visitor whose checkboxes are pre-checked doesn't reset their original date.
    $stampGeneral   = ($consentGeneral   && (!$hasPriorGeneral   || $isFirstTime)) ? date('Y-m-d H:i:s') : null;
    $stampSensitive = ($consentSensitive && (!$hasPriorSensitive || $isFirstTime)) ? date('Y-m-d H:i:s') : null;

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
        ':consent_g_at'   => $stampGeneral,
        ':consent_g_ver'  => $stampGeneral   !== null ? $pdpaVersion     : null,
        ':consent_g_hash' => $stampGeneral   !== null ? $consentTextHash : null,
        ':consent_s_at'   => $stampSensitive,
        ':consent_s_ver'  => $stampSensitive !== null ? $pdpaVersion     : null,
        ':consent_s_hash' => $stampSensitive !== null ? $consentTextHash : null,
        ':consent_ip'     => ($stampGeneral !== null || $stampSensitive !== null) ? $consentIp        : null,
        ':consent_ua'     => ($stampGeneral !== null || $stampSensitive !== null) ? $consentUserAgent : null,
    ];

    if ($existingUser) {
        // COALESCE keeps prior consent timestamps when a user is just editing
        // other fields — we only OVERWRITE when they explicitly re-consent
        $sql = "UPDATE sys_users SET
                    prefix = :prefix, first_name = :first_name, last_name = :last_name,
                    full_name = :name, student_personnel_id = :sid,
                    citizen_id = :cid, phone_number = :phone, status = :status,
                    email = :email, gender = :gender, date_of_birth = :dob, department = :dept,
                    blood_type = :blood, height_cm = :height, weight_kg = :weight,
                    allergies = :allergies, chronic_conditions = :chronic,
                    emergency_contact_name = :em_name,
                    emergency_contact_phone = :em_phone,
                    emergency_contact_relation = :em_rel,
                    consent_general_accepted_at   = COALESCE(:consent_g_at, consent_general_accepted_at),
                    consent_general_version       = COALESCE(:consent_g_ver, consent_general_version),
                    consent_general_text_hash     = COALESCE(:consent_g_hash, consent_general_text_hash),
                    consent_sensitive_accepted_at = COALESCE(:consent_s_at, consent_sensitive_accepted_at),
                    consent_sensitive_version     = COALESCE(:consent_s_ver, consent_sensitive_version),
                    consent_sensitive_text_hash   = COALESCE(:consent_s_hash, consent_sensitive_text_hash),
                    consent_ip                    = COALESCE(:consent_ip, consent_ip),
                    consent_user_agent            = COALESCE(:consent_ua, consent_user_agent)
                WHERE line_user_id = :line_id";
    } else {
        $sql = "INSERT INTO sys_users
                    (line_user_id, prefix, first_name, last_name, full_name,
                     student_personnel_id, citizen_id, phone_number, status, email,
                     gender, date_of_birth, department, blood_type, height_cm, weight_kg,
                     allergies, chronic_conditions,
                     emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                     consent_general_accepted_at, consent_general_version, consent_general_text_hash,
                     consent_sensitive_accepted_at, consent_sensitive_version, consent_sensitive_text_hash,
                     consent_ip, consent_user_agent)
                VALUES
                    (:line_id, :prefix, :first_name, :last_name, :name,
                     :sid, :cid, :phone, :status, :email,
                     :gender, :dob, :dept, :blood, :height, :weight,
                     :allergies, :chronic,
                     :em_name, :em_phone, :em_rel,
                     :consent_g_at, :consent_g_ver, :consent_g_hash,
                     :consent_s_at, :consent_s_ver, :consent_s_hash,
                     :consent_ip, :consent_ua)";
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

    // Sync LINE rich menu → member (เฉพาะตอนสมัครครั้งแรก หรือ update ก็ปลอดภัย เพราะ idempotent)
    if (!empty($lineUserId)) {
        try {
            require_once __DIR__ . '/../line_api/line_richmenu_helper.php';
            line_richmenu_sync_user((string)$lineUserId, true, 'save_profile'); // force member = true
        } catch (Throwable $e) {
            error_log('[save_profile] richmenu sync: ' . $e->getMessage());
        }
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
