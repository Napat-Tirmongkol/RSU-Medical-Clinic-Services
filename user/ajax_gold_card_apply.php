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
$stmt = $pdo->prepare("SELECT id, full_name, citizen_id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
$stmt->execute([':lid' => $lineUserId]);
$user = $stmt->fetch();
if (!$user) {
    json_err('ไม่พบข้อมูลผู้ใช้', 404);
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

// Signature base64
if (!str_starts_with($signatureB64, 'data:image/png;base64,')) {
    json_err('ลายเซ็นไม่ถูกต้อง');
}
$sigData = substr($signatureB64, strlen('data:image/png;base64,'));
$sigBin  = base64_decode($sigData, true);
if ($sigBin === false || strlen($sigBin) < 200) {
    json_err('ลายเซ็นไม่ถูกต้อง — กรุณาเซ็นใหม่');
}

// ── Check duplicate application ──────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, status FROM gold_card_members
        WHERE linked_user_id = :uid OR citizen_id = :cid
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':uid' => $userId, ':cid' => $citizenId]);
    $exist = $stmt->fetch();
    if ($exist && in_array($exist['status'] ?? '', ['pending', 'submitted', 'approved', 'active'], true)) {
        json_err('คุณมีใบสมัครอยู่ในระบบแล้ว (สถานะ: ' . $exist['status'] . ')');
    }
} catch (PDOException $e) {
    json_err('ระบบไม่พร้อมใช้งาน: ' . $e->getMessage(), 500);
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
                'submitted_by' => $userId,
                'full_name'    => $fullName,
                'citizen_id'   => $citizenId,
                'channel'      => 'liff',
            ], JSON_UNESCAPED_UNICODE),
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) { /* silent */ }

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
    json_err('ส่งใบสมัครไม่สำเร็จ: ' . $e->getMessage(), 500);
}
