<?php
// portal/ajax_account.php — actions for the "บัญชีของฉัน" partial
// Actions: save_profile, change_password, save_prefs, save_avatar
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}
validate_csrf_or_die();

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo    = db();
$action = (string)($_POST['action'] ?? '');

function respond(array $payload): void {
    echo json_encode($payload);
    exit;
}

function fetch_admin(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT id, full_name, username, email, phone, avatar_path,
                                  theme_pref, notif_email, notif_inapp, role, status, created_at
                           FROM sys_admins WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    switch ($action) {

        // ── Profile (name + email + phone) ───────────────────────────────
        case 'save_profile': {
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email    = trim((string)($_POST['email']     ?? ''));
            $phone    = trim((string)($_POST['phone']     ?? ''));

            if ($fullName === '' || mb_strlen($fullName) > 150) {
                respond(['ok' => false, 'message' => 'กรุณากรอกชื่อ-นามสกุล (ไม่เกิน 150 ตัวอักษร)']);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                respond(['ok' => false, 'message' => 'กรุณากรอกอีเมลที่ถูกต้อง']);
            }
            if ($phone !== '' && !preg_match('/^[0-9 +\-\(\)]{6,30}$/', $phone)) {
                respond(['ok' => false, 'message' => 'รูปแบบเบอร์โทรไม่ถูกต้อง']);
            }

            // email uniqueness (other rows)
            $chk = $pdo->prepare("SELECT id FROM sys_admins WHERE email = :e AND id <> :id LIMIT 1");
            $chk->execute([':e' => $email, ':id' => $adminId]);
            if ($chk->fetch()) {
                respond(['ok' => false, 'message' => 'อีเมลนี้ถูกใช้โดยบัญชีอื่นแล้ว']);
            }

            $upd = $pdo->prepare("UPDATE sys_admins
                                  SET full_name = :n, email = :e, phone = :p
                                  WHERE id = :id");
            $upd->execute([':n' => $fullName, ':e' => $email, ':p' => $phone !== '' ? $phone : null, ':id' => $adminId]);
            log_activity('Updated Profile', "แก้ไขข้อมูลบัญชีของตัวเอง: $fullName");
            respond(['ok' => true, 'message' => 'บันทึกโปรไฟล์เรียบร้อย', 'admin' => fetch_admin($pdo, $adminId)]);
        }

        // ── Change password ──────────────────────────────────────────────
        case 'change_password': {
            $current = (string)($_POST['current_password']  ?? '');
            $new     = (string)($_POST['new_password']      ?? '');
            $confirm = (string)($_POST['confirm_password']  ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                respond(['ok' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบทุกช่อง']);
            }
            if ($new !== $confirm) {
                respond(['ok' => false, 'message' => 'รหัสผ่านใหม่และยืนยันไม่ตรงกัน']);
            }
            if (strlen($new) < 8) {
                respond(['ok' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร']);
            }

            $stmt = $pdo->prepare("SELECT password FROM sys_admins WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $adminId]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                respond(['ok' => false, 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
            }

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE sys_admins SET password = :h WHERE id = :id");
            $upd->execute([':h' => $newHash, ':id' => $adminId]);
            log_activity('Changed Password', 'เปลี่ยนรหัสผ่านบัญชีของตัวเอง');
            respond(['ok' => true, 'message' => 'เปลี่ยนรหัสผ่านเรียบร้อย']);
        }

        // ── Preferences (theme + notifications) ──────────────────────────
        case 'save_prefs': {
            $theme       = (string)($_POST['theme_pref']  ?? 'light');
            $notifEmail  = isset($_POST['notif_email']) ? 1 : 0;
            $notifInapp  = isset($_POST['notif_inapp']) ? 1 : 0;

            $allowedTheme = ['light', 'dark', 'auto'];
            if (!in_array($theme, $allowedTheme, true)) $theme = 'light';

            $upd = $pdo->prepare("UPDATE sys_admins
                                  SET theme_pref = :t, notif_email = :ne, notif_inapp = :ni
                                  WHERE id = :id");
            $upd->execute([':t' => $theme, ':ne' => $notifEmail, ':ni' => $notifInapp, ':id' => $adminId]);
            respond(['ok' => true, 'message' => 'บันทึกการตั้งค่าเรียบร้อย', 'admin' => fetch_admin($pdo, $adminId)]);
        }

        // ── Avatar upload ────────────────────────────────────────────────
        case 'save_avatar': {
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                respond(['ok' => false, 'message' => 'กรุณาเลือกไฟล์รูป']);
            }
            $file    = $_FILES['avatar'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $maxSize = 2 * 1024 * 1024; // 2 MB

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            if (!isset($allowed[$mime])) {
                respond(['ok' => false, 'message' => 'รองรับเฉพาะ JPG, PNG, WEBP']);
            }
            if ($file['size'] > $maxSize) {
                respond(['ok' => false, 'message' => 'ขนาดไฟล์ต้องไม่เกิน 2MB']);
            }

            $dir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $ext  = $allowed[$mime];
            $name = 'admin_' . $adminId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                respond(['ok' => false, 'message' => 'ไม่สามารถบันทึกไฟล์ได้']);
            }

            // delete previous avatar (best-effort) before saving the new path
            $stmtOld = $pdo->prepare("SELECT avatar_path FROM sys_admins WHERE id = :id LIMIT 1");
            $stmtOld->execute([':id' => $adminId]);
            $oldRel = (string)$stmtOld->fetchColumn();
            if ($oldRel !== '' && strpos($oldRel, 'uploads/avatars/') === 0) {
                @unlink(__DIR__ . '/../' . $oldRel);
            }

            $rel = 'uploads/avatars/' . $name;
            $pdo->prepare("UPDATE sys_admins SET avatar_path = :p WHERE id = :id")
                ->execute([':p' => $rel, ':id' => $adminId]);
            respond(['ok' => true, 'message' => 'อัปเดตรูปโปรไฟล์เรียบร้อย', 'avatar_path' => $rel]);
        }

        default:
            respond(['ok' => false, 'message' => 'unknown action']);
    }
} catch (Throwable $e) {
    error_log('ajax_account error: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'เกิดข้อผิดพลาดของระบบ']);
}
