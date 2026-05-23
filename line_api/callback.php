<?php
// line_api/callback.php
declare(strict_types=1);
session_start();

// ดึงการตั้งค่า LINE และเชื่อมต่อ Database ของระบบหลัก
require_once __DIR__ . '/line_config.php';
require_once __DIR__ . '/../config.php';

// ตรวจสอบว่า User จะไปหน้าไหนหลัง Login (e-campaign หรือ e_Borrow)
$redirectTarget = $_SESSION['redirect_to'] ?? 'ecampaign';
unset($_SESSION['redirect_to']); // ล้างทันที

// รับค่าจาก LINE หลังจากล็อกอิน
$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    die("ผู้ใช้ปฏิเสธการเข้าถึง หรือเกิดข้อผิดพลาด: " . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}

if (!$code) {
    die("ไม่พบ Authorization Code (ไม่มีการส่งค่า Code กลับมา)");
}

// ตรวจสอบ State เพื่อป้องกัน CSRF Attack
if (!isset($_SESSION['line_login_state']) || !hash_equals($_SESSION['line_login_state'], (string)$state)) {
    die("เกิดข้อผิดด้านความปลอดภัย: State ไม่ตรงกัน (อาจเป็น CSRF Attack)");
}
unset($_SESSION['line_login_state']); // ล้าง state หลังใช้แล้ว ป้องกัน Replay

// 1. นำ Code ไปแลกเป็น Access Token
$tokenUrl = "https://api.line.me/oauth2/v2.1/token";
$data = http_build_query([
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => LINE_LOGIN_CALLBACK_URL,
    'client_id'     => LINE_LOGIN_CHANNEL_ID,
    'client_secret' => LINE_LOGIN_CHANNEL_SECRET
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log("LINE Token cURL Error: " . $curlError);
    die("ไม่สามารถเชื่อมต่อ LINE Server ได้ กรุณาลองใหม่อีกครั้ง");
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die("Authentication failed. (ไม่สามารถรับ Access Token ได้)");
}

// 2. ใช้ Access Token ดึง Profile ของผู้ใช้
$ch = curl_init('https://api.line.me/v2/profile');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
$profileRes = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profileRes, true);

$line_user_id  = $profile['userId']      ?? null;
$displayName   = $profile['displayName'] ?? null;
$linePicture   = $profile['pictureUrl']  ?? '';

if (!$line_user_id) {
    die("Authentication failed. (ไม่สามารถรับ Profile ได้)");
}

// ════════════════════════════════════════════════════════════════════════
// Staff Link Flow — ถ้ามาจาก staff_link_line.php → จัดการแยก ไม่ใช่ user flow
// ════════════════════════════════════════════════════════════════════════
if (($_SESSION['line_login_flow'] ?? '') === 'staff_link') {
    $staffId = (int)($_SESSION['line_login_staff_id'] ?? 0);
    $startedAt = (int)($_SESSION['line_login_started_at'] ?? 0);

    // Cleanup markers
    unset($_SESSION['line_login_flow'], $_SESSION['line_login_staff_id'], $_SESSION['line_login_started_at']);

    // Safety: ถ้า session marker เก่าเกิน 10 นาที → reject
    if ($staffId <= 0 || (time() - $startedAt) > 600) {
        $_SESSION['line_link_flash'] = ['ok' => false, 'msg' => 'session หมดอายุ กรุณาเริ่มเชื่อม LINE อีกครั้ง'];
        header('Location: ../portal/index.php?section=profile');
        exit;
    }

    try {
        $pdo = db();

        // ── Auto-migrate columns เผื่อยังไม่รัน migration ──
        try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS notify_sla_via_line TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS line_display_name VARCHAR(120) NULL DEFAULT NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS line_picture_url VARCHAR(500) NULL DEFAULT NULL"); } catch (PDOException) {}

        // ── ตรวจว่า line_user_id นี้ถูกผูกกับ staff คนอื่นแล้วหรือไม่ ──
        $check = $pdo->prepare("SELECT id, full_name FROM sys_staff WHERE linked_line_user_id = ? AND id != ? LIMIT 1");
        $check->execute([$line_user_id, $staffId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $_SESSION['line_link_flash'] = [
                'ok' => false,
                'msg' => 'LINE บัญชีนี้ถูกผูกกับ Staff คนอื่นแล้ว (' . htmlspecialchars($existing['full_name']) . ') — กรุณาใช้บัญชี LINE อื่น',
            ];
            header('Location: ../portal/index.php?section=profile');
            exit;
        }

        // ── Save linked_line_user_id + display name + picture + audit ──
        $upd = $pdo->prepare("UPDATE sys_staff SET linked_line_user_id = ?, line_display_name = ?, line_picture_url = ? WHERE id = ?");
        $upd->execute([$line_user_id, $displayName, $linePicture ?: null, $staffId]);

        // Audit log (best-effort)
        try {
            $audit = $pdo->prepare("INSERT INTO sys_access_audit_logs (target_id, target_type, changed_by, justification, change_snapshot) VALUES (?, 'staff_line_link', ?, ?, ?)");
            $audit->execute([
                $staffId,
                $staffId,
                'Self-service LINE link via OAuth',
                json_encode([
                    'line_user_id' => $line_user_id,
                    'display_name' => $displayName,
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (PDOException) { /* table may not exist */ }

        // ── ส่ง LINE push ยืนยัน (best-effort) ──
        try {
            require_once __DIR__ . '/../includes/line_helper.php';
            $secretsPath = __DIR__ . '/../config/secrets.php';
            $secrets = is_file($secretsPath) ? (require $secretsPath) : [];
            $msgToken = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';
            if ($msgToken !== '') {
                $st2 = $pdo->prepare("SELECT full_name FROM sys_staff WHERE id = ?");
                $st2->execute([$staffId]);
                $staffName = $st2->fetchColumn() ?: '';
                $welcome = "✅ เชื่อมบัญชีสำเร็จ\n\nยินดีต้อนรับ คุณ {$staffName}\n\nคุณจะได้รับการแจ้งเตือนผ่าน LINE สำหรับ:\n• SLA warning / breach\n• เอกสารใหม่ที่ถูกมอบหมาย\n• การ escalation\n\nตั้งค่าได้ที่หน้าโปรไฟล์ของระบบ";
                send_line_push($line_user_id, [['type' => 'text', 'text' => $welcome]], $msgToken);
            }
        } catch (Throwable $e) {
            error_log('[staff_link] welcome push failed: ' . $e->getMessage());
        }

        // Invalidate header LINE profile cache → reload on next request
        unset($_SESSION['_line_profile_cache']);

        $_SESSION['line_link_flash'] = ['ok' => true, 'msg' => 'เชื่อมบัญชี LINE สำเร็จ — เราได้ส่งข้อความยืนยันไปยัง LINE ของคุณแล้ว'];
    } catch (Throwable $e) {
        error_log('[staff_link] save failed: ' . $e->getMessage());
        $_SESSION['line_link_flash'] = ['ok' => false, 'msg' => 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage()];
    }

    header('Location: ../portal/index.php?section=profile');
    exit;
}

try {
    $pdo = db();
    
    // Migration: เพิ่ม column picture_url ถ้ายังไม่มี
    try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS picture_url TEXT"); } catch (PDOException $e) {}

    // ── Migration helper: เผื่อมี column line_user_id_new ──────────
    $hasNewUidColumn = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM sys_users LIKE 'line_user_id_new'")->fetch();
        $hasNewUidColumn = (bool)$colCheck;
    } catch (PDOException $e) { /* ignore */ }

    // ตรวจสอบว่าผู้ใช้ใน LINE นี้มีอยู่ในฐานข้อมูลหรือไม่
    $sqlSelect = $hasNewUidColumn
        ? "SELECT id, full_name, line_user_id, line_user_id_new FROM sys_users WHERE line_user_id = :line_user_id LIMIT 1"
        : "SELECT id, full_name, line_user_id FROM sys_users WHERE line_user_id = :line_user_id LIMIT 1";
    $stmt = $pdo->prepare($sqlSelect);
    $stmt->execute([':line_user_id' => $line_user_id]);
    $user = $stmt->fetch();

    if ($user) {
        // อัปเดตรูปโปรไฟล์ล่าสุดเสมอ
        if (!empty($profile['pictureUrl'])) {
            $stmtUpdate = $pdo->prepare("UPDATE sys_users SET picture_url = :pic WHERE id = :id");
            $stmtUpdate->execute([':pic' => $profile['pictureUrl'], ':id' => $user['id']]);
        }

        // ✅ พบ User เดิม — ตั้ง Session ร่วมที่รองรับทั้ง e-campaign และ e_Borrow
        $_SESSION['line_user_id']      = $user['line_user_id'];
        $_SESSION['line_picture']      = $profile['pictureUrl'] ?? '';

        // Session สำหรับ e-campaign
        $_SESSION['student_id']   = (int)$user['id'];
        $_SESSION['student_full_name']    = $user['full_name'];

        // Session สำหรับ e_Borrow (ใช้ชื่อ key เดิมที่ e_Borrow คาดหวัง)
        $_SESSION['student_id']        = (int)$user['id'];
        $_SESSION['student_full_name'] = $user['full_name'];
        $_SESSION['student_line_id']   = $user['line_user_id'];

        session_regenerate_id(true); // ป้องกัน Session Fixation

        // ✅ บันทึก Log: ผู้ใช้งานเข้าสู่ระบบ
        log_activity('Login', "ผู้ป่วย '{$user['full_name']}' เข้าสู่ระบบผ่าน LINE Success", (int)$user['id']);

        // ตรวจสอบว่ามี invite_token ค้างอยู่หรือไม่ (มาจาก c.php?t=TOKEN)
        $inviteToken = $_SESSION['invite_token'] ?? '';

        // กำหนด final destination
        if ($redirectTarget === 'eborrow') {
            $finalDest = LINE_APP_BASE_PATH . '/e_Borrow/index.php';
        } elseif ($inviteToken !== '') {
            unset($_SESSION['invite_token']);
            $finalDest = LINE_APP_BASE_PATH . '/user/c.php?t=' . urlencode($inviteToken);
        } elseif (!empty($_SESSION['checkin_return'])) {
            $dest = $_SESSION['checkin_return'];
            unset($_SESSION['checkin_return']);
            // Guard against open redirect: only allow URLs on the same host
            $allowedPrefix = rtrim(LINE_APP_BASE_PATH, '/');
            $finalDest = str_starts_with($dest, $allowedPrefix . '/') ? $dest : $allowedPrefix . '/user/hub.php';
        } else {
            $finalDest = LINE_APP_BASE_PATH . '/user/hub.php';
        }

        // ── Migrate LINE Login Provider ──────────────────────────────
        // ถ้าเปิด migrate flow ไว้ และ user ยังไม่มี new UID → เด้งไปหน้า migrate
        $needsMigrate = defined('LINE_MIGRATE_ENABLED')
            && LINE_MIGRATE_ENABLED
            && $hasNewUidColumn
            && empty($user['line_user_id_new']);

        if ($needsMigrate) {
            $_SESSION['migrate_old_uid']   = $user['line_user_id'];
            $_SESSION['migrate_final_dest'] = $finalDest;
            header('Location: ' . LINE_APP_BASE_PATH . '/line_api/migrate_login.php');
            exit;
        }

        header("Location: {$finalDest}");
        exit;

    } else {
        // ❌ ไม่พบ User — ผู้ใช้ใหม่ ให้กรอกข้อมูลส่วนตัวครั้งแรก
        $_SESSION['line_user_id']      = $line_user_id;
        $_SESSION['line_picture_url']  = $linePicture;
        $_SESSION['pending_redirect']  = $redirectTarget;

        header('Location: ' . LINE_APP_BASE_PATH . '/user/profile.php');
        exit;
    }

} catch (PDOException $e) {
    error_log("LINE callback DB error: " . $e->getMessage()); http_response_code(500); exit("เกิดข้อผิดพลาด กรุณาลองใหม่");
}
