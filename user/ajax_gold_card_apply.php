<?php
/**
 * user/ajax_gold_card_apply.php
 * AJAX endpoint รับใบสมัครบัตรทองจาก user
 *
 * POST inputs:
 *  csrf_token, citizen_id (13 digits mod-11), full_name, date_of_birth (Y-m-d),
 *  gender (male/female/other), phone (optional 10 digits),
 *  photo (multipart file), signature_base64 (data URI PNG)
 *
 * Output: JSON {status:ok, member_id} หรือ {status:error, message}
 *
 * Side effects:
 *  - INSERT gold_card_members (status=submitted, linked_user_id=current user)
 *  - Save photo → uploads/gold_card/YYYY/MM/photo_mem{id}_{hash}.jpg
 *  - Save signature → uploads/gold_card/YYYY/MM/sig_mem{id}_{hash}.png
 *  - INSERT 2 rows ใน gold_card_documents
 *  - INSERT history log
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

// ── Method + CSRF ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}
validate_csrf_or_die();

// ── Auth ─────────────────────────────────────────────────────────────────────
$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    json_err('Session หมดอายุ กรุณาเข้าสู่ระบบใหม่', 401);
}

$pdo = db();

// Load user
$stmt = $pdo->prepare("SELECT id, full_name, citizen_id, status FROM sys_users WHERE line_user_id = :lid LIMIT 1");
$stmt->execute([':lid' => $lineUserId]);
$user = $stmt->fetch();
if (!$user) {
    json_err('ไม่พบข้อมูลผู้ใช้', 404);
}
// บัตรทองเปิดให้สมัครเฉพาะ "นักศึกษา" เท่านั้น (sys_users.status)
if (($user['status'] ?? '') !== 'student') {
    json_err('ระบบบัตรทองเปิดให้สมัครเฉพาะนักศึกษาเท่านั้น', 403);
}
$userId = (int)$user['id'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function citizen_mod11(string $id): bool {
    if (!preg_match('/^\d{13}$/', $id)) return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$id[$i] * (13 - $i);
    return ((11 - ($sum % 11)) % 10) === (int)$id[12];
}

function valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $dt <= new DateTime();
}

function gold_card_dir(): string {
    return dirname(__DIR__) . '/uploads/gold_card';
}

// ── Validate inputs ──────────────────────────────────────────────────────────
$citizenId = trim((string)($_POST['citizen_id'] ?? ''));
$fullName  = trim((string)($_POST['full_name']  ?? ''));
$dob       = trim((string)($_POST['date_of_birth'] ?? ''));
$gender    = trim((string)($_POST['gender']     ?? ''));
$phone     = preg_replace('/\D/', '', (string)($_POST['phone'] ?? '')) ?? '';
$signatureB64 = (string)($_POST['signature_base64'] ?? '');

// ── PDPA consent capture (Tier 1 audit C16) ──────────────────────────────────
// Submission collects PHI (citizen_id, dob, photo of person+ID, biometric
// signature) — PDPA Art. 24 requires a lawful basis. Persist consent record
// with timestamp, IP, UA, and a hash of the notice text shown to the user.
$consentAccepted = !empty($_POST['consent_accepted']) && in_array(
    (string)$_POST['consent_accepted'], ['1', 'true', 'yes', 'on'], true
);
$consentVersion  = trim((string)($_POST['consent_version'] ?? ''));

if (!$consentAccepted) {
    json_err('กรุณายอมรับนโยบายความเป็นส่วนตัว (PDPA) ก่อนส่งใบสมัคร');
}
// Whitelist the version tag — anything that doesn't match the strict
// pdpa_vN_YYYY-MM regex is treated as a legacy / pre-migration submit
if (!preg_match('/^pdpa_v\d+_\d{4}-\d{2}$/', $consentVersion)) {
    if ($consentVersion !== '') {
        error_log('[gold_card_apply] invalid consent_version "' . $consentVersion . '" for user_id=' . $userId);
    }
    $consentVersion = 'legacy_pre_capture_v0';
}
// Hash the canonical version server-side. We deliberately DO NOT trust
// $_POST['consent_text_hash'] — a malicious client could submit the hash
// of a more permissive text and forge the forensic record. The hash is a
// derivative of the version tag (which is authoritative + git-tracked),
// so any reviewer can reconstruct what the user saw at that version.
$consentHash = hash('sha256', $consentVersion);

if (!citizen_mod11($citizenId)) json_err('รหัสบัตรประชาชนไม่ถูกต้อง');
if ($fullName === '' || mb_strlen($fullName) > 200) json_err('กรุณากรอกชื่อ-นามสกุล');
if (!valid_date($dob)) json_err('วันเกิดไม่ถูกต้อง');
if (!in_array($gender, ['male', 'female', 'other'], true)) json_err('กรุณาเลือกเพศ');
if ($phone !== '' && !preg_match('/^0\d{9}$/', $phone)) json_err('รูปแบบเบอร์โทรไม่ถูกต้อง');

// Photo
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    json_err('กรุณาแนบรูปถ่ายคู่บัตรประชาชน');
}
$photo = $_FILES['photo'];
if ($photo['size'] > 10 * 1024 * 1024) json_err('ไฟล์รูปใหญ่เกิน 10MB');
$mimeFinfo = new finfo(FILEINFO_MIME_TYPE);
$photoMime = $mimeFinfo->file($photo['tmp_name']) ?: '';
if (!in_array($photoMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    json_err('รูปต้องเป็น JPG/PNG/WEBP เท่านั้น');
}

// Signature base64 — defence-in-depth so a hostile client can't drop a
// PHP polyglot, SVG/script blob, or multi-GB DoS payload via this path
if (!str_starts_with($signatureB64, 'data:image/png;base64,')) {
    json_err('ลายเซ็นไม่ถูกต้อง');
}
$sigData = substr($signatureB64, strlen('data:image/png;base64,'));
$sigBin  = base64_decode($sigData, true);
if ($sigBin === false) {
    json_err('ลายเซ็นไม่ถูกต้อง — กรุณาเซ็นใหม่');
}
// Lower bound: signature_pad output is always larger than ~200B; upper
// bound caps payload size to prevent disk/memory DoS via base64 inflation
$sigLen = strlen($sigBin);
if ($sigLen < 200 || $sigLen > 2 * 1024 * 1024) {
    json_err('ลายเซ็นมีขนาดไม่ถูกต้อง — กรุณาเซ็นใหม่');
}
// Magic-bytes check — a real PNG starts with the 8-byte PNG signature.
// String-prefix in the data URL alone is not enough; the body could be
// anything once you strip the label.
if (substr($sigBin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    json_err('ลายเซ็นไม่ใช่ไฟล์ PNG ที่ถูกต้อง');
}
// Structural validation — confirms the byte stream actually decodes as a
// PNG image and isn't oversized in dimensions
$sigInfo = @getimagesizefromstring($sigBin);
if (!$sigInfo || ($sigInfo[2] ?? 0) !== IMAGETYPE_PNG || $sigInfo[0] > 4000 || $sigInfo[1] > 4000) {
    json_err('ลายเซ็นไม่ใช่ไฟล์ PNG ที่ถูกต้อง');
}

// ── Check duplicate application ──────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, status FROM gold_card_members
        WHERE (linked_user_id = :uid OR citizen_id = :cid)
          AND deleted_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':uid' => $userId, ':cid' => $citizenId]);
    $exist = $stmt->fetch();
    if ($exist && in_array($exist['status'] ?? '', ['pending', 'submitted', 'approved', 'active'], true)) {
        // Don't echo status — prevents account enumeration via probe.
        json_err('คุณมีใบสมัครอยู่ในระบบแล้ว กรุณาตรวจสอบสถานะที่หน้าโปรไฟล์');
    }
} catch (PDOException $e) {
    error_log('[ajax_gold_card_apply duplicate-check] ' . $e->getMessage());
    json_err('ระบบไม่พร้อมใช้งาน กรุณาลองอีกครั้งภายหลัง', 500);
}

// ── Self-healing: ensure 'signature' is in doc_type ENUM ────────────────────
try {
    $col = $pdo->query("SHOW COLUMNS FROM gold_card_documents WHERE Field = 'doc_type'")->fetch();
    if ($col && strpos($col['Type'], "'signature'") === false) {
        $pdo->exec("ALTER TABLE gold_card_documents
                    MODIFY COLUMN doc_type
                    ENUM('id_copy','house_reg','application','photo','medical','signature','other')
                    NOT NULL DEFAULT 'other'");
    }
} catch (PDOException $e) { /* silent */ }

// ── Self-healing: gold_card_consents table ──────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gold_card_consents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        member_id INT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        consent_version VARCHAR(50) NOT NULL,
        consent_text_hash VARCHAR(128) NOT NULL,
        accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        KEY idx_member  (member_id),
        KEY idx_user    (user_id),
        KEY idx_version (consent_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { error_log('[gold_card_consents schema] ' . $e->getMessage()); }

// ── Begin transaction ────────────────────────────────────────────────────────
$pdo->beginTransaction();

try {
    // INSERT member
    $stmt = $pdo->prepare("
        INSERT INTO gold_card_members
        (citizen_id, linked_user_id, full_name, member_type, position, phone,
         hospital_main, hospital_sub, application_date, status, source_filename, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $citizenId,
        $userId,
        $fullName,
        'บุคคลทั่วไป', // member_type — admin can change in review
        '',           // position
        $phone,
        '', '',       // hospital — admin assigns in review
        date('Y-m-d'),
        'submitted',
        'user_apply',
        $userId,
    ]);
    $memberId = (int)$pdo->lastInsertId();

    // Try to set gender + dob (if columns exist from legacy migration)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM gold_card_members")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('gender', $cols, true) && in_array('date_of_birth', $cols, true)) {
            $upd = $pdo->prepare("UPDATE gold_card_members SET gender = ?, date_of_birth = ? WHERE id = ?");
            $upd->execute([$gender, $dob, $memberId]);
        }
    } catch (PDOException $e) { /* silent — columns optional */ }

    // ── Save files ───────────────────────────────────────────────────────────
    $year  = date('Y');
    $month = date('m');
    $base  = gold_card_dir();
    $dir   = $base . "/$year/$month";
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ uploads ได้');
    }

    // Photo
    $photoHash = sha1_file($photo['tmp_name']) ?: bin2hex(random_bytes(8));
    $photoExt  = match ($photoMime) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    $photoRel = "$year/$month/photo_mem{$memberId}_" . substr($photoHash, 0, 16) . ".$photoExt";
    $photoAbs = "$base/$photoRel";
    if (!@move_uploaded_file($photo['tmp_name'], $photoAbs)) {
        throw new RuntimeException('บันทึกรูปไม่สำเร็จ');
    }

    // Signature
    $sigHash = sha1($sigBin);
    $sigRel  = "$year/$month/sig_mem{$memberId}_" . substr($sigHash, 0, 16) . ".png";
    $sigAbs  = "$base/$sigRel";
    if (file_put_contents($sigAbs, $sigBin) === false) {
        throw new RuntimeException('บันทึกลายเซ็นไม่สำเร็จ');
    }

    // INSERT documents
    $insDoc = $pdo->prepare("
        INSERT INTO gold_card_documents
        (member_id, doc_type, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insDoc->execute([
        $memberId, 'photo',
        "selfie_{$citizenId}.{$photoExt}",
        $photoRel, $photoMime, (int)$photo['size'], $photoHash, $userId,
    ]);
    $insDoc->execute([
        $memberId, 'signature',
        "signature_{$citizenId}.png",
        $sigRel, 'image/png', strlen($sigBin), $sigHash, $userId,
    ]);

    // History log
    try {
        $stmt = $pdo->prepare("
            INSERT INTO gold_card_history (member_id, action, old_value, new_value, changed_by, ip_address)
            VALUES (?, 'user_apply', NULL, ?, ?, ?)
        ");
        $stmt->execute([
            $memberId,
            json_encode([
                'submitted_by'    => $userId,
                'full_name'       => $fullName,
                'citizen_id'      => $citizenId,
                'channel'         => 'liff',
                'consent_version' => $consentVersion,
            ], JSON_UNESCAPED_UNICODE),
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) { /* silent */ }

    // PDPA consent capture row — lawful basis of processing.
    try {
        $pdo->prepare("INSERT INTO gold_card_consents
            (member_id, user_id, consent_version, consent_text_hash, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                $memberId,
                $userId,
                $consentVersion,
                $consentHash,
                $_SERVER['REMOTE_ADDR'] ?? null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
    } catch (PDOException $e) {
        // Don't fail the whole submission if consent table write fails — but
        // emit a loud audit signal so this can be investigated.
        error_log('[gold_card_consents] failed to record consent for member_id=' . $memberId
                  . ' user_id=' . $userId . ': ' . $e->getMessage());
    }

    $pdo->commit();

    // Activity log
    if (function_exists('log_activity')) {
        log_activity('gold_card_apply', "User submitted gold card application (member_id=$memberId)", $userId);
    }

    json_ok(['member_id' => $memberId, 'status' => 'submitted']);

} catch (Throwable $e) {
    $pdo->rollBack();
    // Cleanup partial files
    if (isset($photoAbs) && is_file($photoAbs)) @unlink($photoAbs);
    if (isset($sigAbs) && is_file($sigAbs)) @unlink($sigAbs);
    error_log('[ajax_gold_card_apply] ' . $e->getMessage());
    json_err('ส่งใบสมัครไม่สำเร็จ กรุณาลองอีกครั้ง', 500);
}
