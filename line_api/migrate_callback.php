<?php
// line_api/migrate_callback.php
// รับ callback จาก LINE Login (provider ใหม่) แล้วบันทึก mapping old_uid → new_uid
declare(strict_types=1);
session_start();
require_once __DIR__ . '/line_config.php';
require_once __DIR__ . '/../../config.php';

/** ส่งผู้ใช้ไปหน้า error พร้อมเหตุผล */
function migrate_redirect_error(string $reason): void {
    header('Location: ../../user/migrate_error.php?reason=' . urlencode($reason));
    exit;
}

/** ส่งผู้ใช้ไปยัง destination ที่ตั้งไว้ก่อน migrate (หรือ hub) */
function migrate_redirect_final(): void {
    $dest = $_SESSION['migrate_final_dest'] ?? '../../user/hub.php';
    unset($_SESSION['migrate_old_uid'], $_SESSION['migrate_final_dest'], $_SESSION['line_migrate_state']);
    header("Location: {$dest}");
    exit;
}

// ── ตรวจ pre-condition ──────────────────────────────────────────
$oldUid = $_SESSION['migrate_old_uid'] ?? '';
if ($oldUid === '') {
    // ไม่มี context เก่า — อาจเข้าหน้านี้ตรงๆ
    header('Location: ../../user/index.php');
    exit;
}

// ── รับค่าจาก LINE ─────────────────────────────────────────────
$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    // user ปฏิเสธ consent — log แล้วเด้งไป error page
    error_log("LINE migrate consent denied: " . $error);
    migrate_redirect_error('consent_denied');
}

if (!$code) {
    migrate_redirect_error('no_code');
}

// CSRF check
if (!isset($_SESSION['line_migrate_state']) || !hash_equals($_SESSION['line_migrate_state'], (string)$state)) {
    migrate_redirect_error('state_mismatch');
}
unset($_SESSION['line_migrate_state']);

// ── 1. แลก code → access token (ใช้ credentials ใหม่) ───────────
$ch = curl_init('https://api.line.me/oauth2/v2.1/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => LINE_LOGIN_CALLBACK_URL_NEW,
        'client_id'     => LINE_LOGIN_CHANNEL_ID_NEW,
        'client_secret' => LINE_LOGIN_CHANNEL_SECRET_NEW,
    ]),
    CURLOPT_TIMEOUT        => 10,
]);
$tokenRes = curl_exec($ch);
$tokenErr = curl_error($ch);
curl_close($ch);

if ($tokenRes === false) {
    error_log("LINE migrate token cURL error: " . $tokenErr);
    migrate_redirect_error('token_network');
}

$tokenData = json_decode((string)$tokenRes, true);
if (empty($tokenData['access_token'])) {
    error_log("LINE migrate token failed: " . $tokenRes);
    migrate_redirect_error('token_failed');
}

// ── 2. ดึง profile → ได้ new UID ───────────────────────────────
$ch = curl_init('https://api.line.me/v2/profile');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_TIMEOUT        => 10,
]);
$profileRes = curl_exec($ch);
curl_close($ch);

$profile = json_decode((string)$profileRes, true);
$newUid  = $profile['userId'] ?? null;

if (!$newUid) {
    migrate_redirect_error('profile_failed');
}

// ── 3. บันทึก mapping ในฐานข้อมูล ──────────────────────────────
try {
    $pdo = db();

    // 3a. ตรวจสอบว่า new_uid นี้ถูกผูกกับ user อื่นไปแล้วหรือยัง (collision)
    $stmt = $pdo->prepare("SELECT id, line_user_id FROM sys_users WHERE line_user_id_new = :new_uid LIMIT 1");
    $stmt->execute([':new_uid' => $newUid]);
    $collision = $stmt->fetch();

    if ($collision && $collision['line_user_id'] !== $oldUid) {
        // new UID นี้มี user อื่นใช้แล้ว → ไม่ทับ ให้แอดมินตรวจ
        error_log("LINE migrate UID collision: new_uid={$newUid} already linked to user_id={$collision['id']}, attempted from old_uid={$oldUid}");
        log_activity('Migrate-LINE-Collision',
            "New UID ชนกับ user อื่น (new_uid={$newUid}, target_old_uid={$oldUid}, existing_user_id={$collision['id']})"
        );
        migrate_redirect_error('uid_collision');
    }

    // 3b. ตรวจ user ปลายทางว่ามี new_uid อยู่แล้วและเหมือนกันไหม (idempotent)
    $stmt = $pdo->prepare("SELECT id, line_user_id_new FROM sys_users WHERE line_user_id = :old_uid LIMIT 1");
    $stmt->execute([':old_uid' => $oldUid]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        // user หาย? อาจถูกลบระหว่างทาง
        migrate_redirect_error('user_not_found');
    }

    if (!empty($userRow['line_user_id_new']) && $userRow['line_user_id_new'] === $newUid) {
        // migrate เคยทำสำเร็จไปแล้ว — แค่เด้งกลับ
        migrate_redirect_final();
    }

    if (!empty($userRow['line_user_id_new']) && $userRow['line_user_id_new'] !== $newUid) {
        // user คนนี้เคย migrate แต่ใช้คนละ new_uid? ผิดปกติ — ให้แอดมินตรวจ
        error_log("LINE migrate inconsistent: user_id={$userRow['id']} already has new_uid={$userRow['line_user_id_new']}, but got different new_uid={$newUid}");
        migrate_redirect_error('uid_inconsistent');
    }

    // 3c. บันทึก new_uid (case ปกติ — ยังไม่เคย migrate)
    $stmt = $pdo->prepare("UPDATE sys_users SET line_user_id_new = :new_uid WHERE id = :id");
    $stmt->execute([':new_uid' => $newUid, ':id' => $userRow['id']]);

    log_activity('Migrate-LINE-Success',
        "Migrate LINE UID สำเร็จ (user_id={$userRow['id']}, old_uid={$oldUid}, new_uid={$newUid})",
        (int)$userRow['id']
    );

} catch (PDOException $e) {
    error_log("LINE migrate DB error: " . $e->getMessage());
    migrate_redirect_error('db_error');
}

// ── 4. เด้งกลับไปยัง destination ที่ user ตั้งใจไป ─────────────
migrate_redirect_final();
