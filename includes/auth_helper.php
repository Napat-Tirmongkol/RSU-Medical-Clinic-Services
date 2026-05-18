<?php
/**
 * includes/auth_helper.php
 * Centralized logic for password resets and authentication helpers.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mail_helper.php';

/**
 * Request a password reset.
 * Generates a token and sends an email.
 * 
 * @param string $email The user's email address
 * @param string $type  'admin' or 'staff'
 * @return array ['ok' => bool, 'message' => string]
 */
function requestPasswordReset(string $email, string $type): array {
    // Whitelist $type — anything else routes to sys_staff via the old ternary,
    // which created cross-table token confusion. Reject explicitly.
    if (!in_array($type, ['admin', 'staff'], true)) {
        return ['ok' => true, 'message' => 'หากอีเมลของคุณอยู่ในระบบ ระบบได้ส่งลิงก์รีเซ็ตให้แล้ว กรุณาตรวจสอบ Inbox'];
    }

    // Fail-closed if APP_BASE_URL is not configured — avoids host header injection
    // via $_SERVER['HTTP_HOST'] in outgoing reset links.
    if (!defined('APP_BASE_URL') || APP_BASE_URL === '') {
        error_log('requestPasswordReset: APP_BASE_URL is not configured — refusing to send reset email');
        return ['ok' => false, 'message' => 'ระบบยังไม่ได้ตั้งค่า Base URL กรุณาติดต่อผู้ดูแลระบบ'];
    }

    $pdo = db();
    $table = ($type === 'admin') ? 'sys_admins' : 'sys_staff';

    // Neutral message returned regardless of whether the email exists — prevents
    // account enumeration. Actual mail delivery is conditional on user existing.
    $neutral = ['ok' => true, 'message' => 'หากอีเมลของคุณอยู่ในระบบ ระบบได้ส่งลิงก์รีเซ็ตให้แล้ว กรุณาตรวจสอบ Inbox'];

    try {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM $table WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Pretend we sent the email. Log internally for audit.
            error_log("Password reset requested for unknown email ({$type}): {$email}");
            return $neutral;
        }

        // Generate token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $pdo->prepare("UPDATE $table SET reset_token = ?, reset_expiry = ? WHERE id = ?");
        $stmt->execute([$token, $expiry, $user['id']]);

        // Build reset link from server-controlled APP_BASE_URL constant only.
        $resetLink = APP_BASE_URL . "/admin/auth/reset_password.php?token=" . urlencode($token) . "&type=" . urlencode($type);

        $subject = "รีเซ็ตรหัสผ่าน — " . SITE_NAME;
        $details = [
            'ชื่อผู้ใช้งาน' => $user['full_name'],
            'ลิงก์รีเซ็ต' => "<a href='$resetLink' style='color:#2563eb;font-weight:700;'>คลิกที่นี่เพื่อตั้งรหัสผ่านใหม่</a>",
            'หมดอายุใน' => '1 ชั่วโมง',
        ];

        $body = get_email_template(
            "คำขอรีเซ็ตรหัสผ่าน",
            "คุณได้รับอีเมลนี้เนื่องจากมีการร้องขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณในระบบ " . SITE_NAME . " หากคุณไม่ได้เป็นผู้ร้องขอ โปรดเพิกเฉยต่ออีเมลฉบับนี้",
            $details,
            'info'
        );

        // Fetch SMTP config
        $smtp_secrets = get_secrets();
        
        // IMPORTANT: Passing 4 arguments to match mail_helper.php signature
        $sent = smtp_send($email, $subject, $body, $smtp_secrets);

        if ($sent) {
            log_activity("Password Reset Requested", "ส่งลิงก์รีเซ็ตรหัสผ่านไปที่ $email ($type)", (int)$user['id']);
        } else {
            error_log("Password reset SMTP send failed for {$email} ({$type})");
        }
        // Always return the neutral message — do not leak SMTP status to the caller.
        return $neutral;

    } catch (Exception $e) {
        error_log("Password reset request error: " . $e->getMessage());
        // Generic message; never leak exception details.
        return ['ok' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองอีกครั้งภายหลัง'];
    }
}

/**
 * Verify if a reset token is valid.
 */
function verifyResetToken(string $token, string $type): ?array {
    if (!in_array($type, ['admin', 'staff'], true)) return null;
    if (empty($token)) return null;
    $pdo = db();
    $table = ($type === 'admin') ? 'sys_admins' : 'sys_staff';
    
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM $table WHERE reset_token = ? AND reset_expiry > ? LIMIT 1");
        $stmt->execute([$token, $now]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        error_log("verifyResetToken Error ($type): " . $e->getMessage());
        return null;
    }
}

/**
 * Reset the password using a token.
 */
function resetPasswordWithToken(string $token, string $type, string $newPassword): array {
    if (!in_array($type, ['admin', 'staff'], true)) {
        return ['ok' => false, 'message' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว'];
    }
    $user = verifyResetToken($token, $type);
    if (!$user) {
        return ['ok' => false, 'message' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว'];
    }

    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร'];
    }

    $pdo = db();
    $table = ($type === 'admin') ? 'sys_admins' : 'sys_staff';
    $pwdColumn = ($type === 'admin') ? 'password' : 'password_hash';

    try {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE $table SET $pwdColumn = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
        $stmt->execute([$hashed, $user['id']]);
        
        log_activity("Password Reset Successful", "เปลี่ยนรหัสผ่านใหม่ผ่านระบบ Forgot Password", (int)$user['id']);
        return ['ok' => true, 'message' => 'เปลี่ยนรหัสผ่านใหม่เรียบร้อยแล้ว'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกรหัสผ่านใหม่'];
    }
}
