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
    $pdo = db();
    $table = ($type === 'admin') ? 'sys_admins' : 'sys_staff';
    
    try {
        // 1. Check if email exists
        $stmt = $pdo->prepare("SELECT id, full_name FROM $table WHERE email = ? OR (username = ? AND email IS NOT NULL) LIMIT 1");
        // For staff, email might be different, let's assume we search by email
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM $table WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Security: Don't reveal if email exists or not, but for internal systems we can be more helpful
            return ['ok' => false, 'message' => 'ไม่พบอีเมลนี้ในระบบ'];
        }

        // 2. Generate Token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // 3. Save to DB
        // Note: Ensure columns reset_token, reset_expiry exist
        $stmt = $pdo->prepare("UPDATE $table SET reset_token = ?, reset_expiry = ? WHERE id = ?");
        $stmt->execute([$token, $expiry, $user['id']]);

        // 4. Send Email
        $resetLink = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['SCRIPT_NAME']) . "/reset_password.php?token=$token&type=$type";
        
        // Handle path differences (admin/auth/...)
        $baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        $resetLink = $baseUrl . "/e-campaignv2/admin/auth/reset_password.php?token=$token&type=$type";

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

        $sent = smtp_send($email, $subject, $body);

        if ($sent) {
            log_activity("Password Reset Requested", "ส่งลิงก์รีเซ็ตรหัสผ่านไปที่ $email ($type)", (int)$user['id']);
            return ['ok' => true, 'message' => 'ระบบได้ส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ไปที่อีเมลของคุณแล้ว โปรดตรวจสอบ Inbox (หรือ Junk Mail)'];
        } else {
            return ['ok' => false, 'message' => 'ไม่สามารถส่งอีเมลได้ในขณะนี้ กรุณาติดต่อผู้ดูแลระบบ'];
        }

    } catch (Exception $e) {
        error_log("Password reset request error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ: ' . $e->getMessage()];
    }
}

/**
 * Verify if a reset token is valid.
 * 
 * @param string $token
 * @param string $type
 * @return array|null User record if valid, null otherwise
 */
function verifyResetToken(string $token, string $type): ?array {
    if (empty($token)) return null;
    $pdo = db();
    $table = ($type === 'admin') ? 'sys_admins' : 'sys_staff';
    
    try {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM $table WHERE reset_token = ? AND reset_expiry > NOW() LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Reset the password using a token.
 * 
 * @param string $token
 * @param string $type
 * @param string $newPassword
 * @return array
 */
function resetPasswordWithToken(string $token, string $type, string $newPassword): array {
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
        return ['ok' => true, 'message' => 'เปลี่ยนรหัสผ่านใหม่เรียบร้อยแล้ว คุณสามารถเข้าสู่ระบบด้วยรหัสผ่านใหม่ได้ทันที'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกรหัสผ่านใหม่'];
    }
}
