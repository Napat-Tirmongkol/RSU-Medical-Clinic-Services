<?php
/**
 * admin/auth/reset_password.php
 * UI for setting a new password using a reset token.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

$token = $_GET['token'] ?? '';
$type = $_GET['type'] ?? 'admin';
$user = verifyResetToken($token, $type);

$message = '';
$error = '';
$success = false;
$themeColor = ($type === 'staff') ? '#4f46e5' : '#2e9e63';

if (!$user) {
    $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง หรือหมดอายุแล้ว กรุณาทำรายการใหม่อีกครั้ง';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
    
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if ($password !== $confirm) {
        $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    } else {
        $result = resetPasswordWithToken($token, $type, $password);
        if ($result['ok']) {
            $message = $result['message'];
            $success = true;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/rsufont.css">
    <style>
        * { font-family: 'rsufont', 'Prompt', sans-serif; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: <?= ($type === 'staff') ? '#eef2ff' : '#e8f5ee' ?>;
            display: flex; align-items: center; justify-content: center; padding: 1.5rem;
        }
        .login-card {
            width: 100%; max-width: 480px;
            background: #fff; border-radius: 1.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,.1);
            overflow: hidden; padding: 2.5rem;
            animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .logo-box {
            width: 4rem; height: 4rem; background: <?= $themeColor ?>;
            border-radius: 1rem; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem; color: #fff; font-size: 1.8rem;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .main-title { font-size: 1.5rem; font-weight: 900; color: #0d1f2d; text-align: center; margin-bottom: 0.5rem; }
        .sub-title { font-size: 0.875rem; color: #6b7280; text-align: center; margin-bottom: 2rem; }
        .input-wrap { position: relative; margin-bottom: 1.25rem; }
        .input-wrap i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .input-wrap input {
            width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 1.5px solid #e5e7eb; border-radius: 0.75rem;
            font-size: 0.95rem; outline: none; transition: all 0.2s;
        }
        .input-wrap input:focus { border-color: <?= $themeColor ?>; box-shadow: 0 0 0 4px <?= $themeColor ?>20; }
        .btn-submit {
            width: 100%; background: <?= $themeColor ?>; color: #fff;
            padding: 0.9rem; border-radius: 999px; border: none; font-weight: 800;
            cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 14px <?= $themeColor ?>40;
            margin-bottom: 1.5rem;
        }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); }
        .alert { padding: 1rem; border-radius: 0.75rem; font-size: 0.875rem; margin-bottom: 1.5rem; text-align: center; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .back-link { display: block; text-align: center; color: #6b7280; font-size: 0.875rem; text-decoration: none; font-weight: 600; }
        .back-link:hover { color: <?= $themeColor ?>; }
        .user-info {
            background: #f9fafb; padding: 0.75rem; border-radius: 0.75rem;
            font-size: 0.825rem; color: #4b5563; margin-bottom: 1.5rem; text-align: center;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-box">
        <i class="fa-solid fa-lock-open"></i>
    </div>
    <h1 class="main-title">ตั้งรหัสผ่านใหม่</h1>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php if (!$user): ?>
            <a href="forgot_password.php?type=<?= $type ?>" class="back-link">ขอลิงก์รีเซ็ตรหัสผ่านใหม่</a>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <a href="<?= ($type === 'staff') ? 'staff_login.php' : 'login.php' ?>" class="btn-submit" style="display:block; text-align:center; text-decoration:none;">เข้าสู่ระบบได้เลย</a>
    <?php endif; ?>

    <?php if ($user && !$success): ?>
        <p class="sub-title">กรุณาระบุรหัสผ่านใหม่ที่คุณต้องการใช้งาน</p>
        <div class="user-info">
            กำลังเปลี่ยนรหัสผ่านสำหรับ: <strong><?= htmlspecialchars($user['full_name']) ?></strong>
        </div>
        <form method="POST">
            <?php csrf_field(); ?>
            <div class="input-wrap">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="password" placeholder="รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)" required minlength="8">
            </div>
            <div class="input-wrap">
                <i class="fa-solid fa-check-double"></i>
                <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่านใหม่" required minlength="8">
            </div>
            <button type="submit" class="btn-submit">บันทึกรหัสผ่านใหม่</button>
        </form>
    <?php endif; ?>

    <?php if (!$success): ?>
    <a href="<?= ($type === 'staff') ? 'staff_login.php' : 'login.php' ?>" class="back-link">
        <i class="fa-solid fa-arrow-left mr-2"></i>ยกเลิก
    </a>
    <?php endif; ?>
</div>

</body>
</html>
