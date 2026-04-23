<?php
/**
 * admin/auth/forgot_password.php
 * UI for requesting a password reset link.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

$message = '';
$error = '';
$type = $_GET['type'] ?? 'admin'; // 'admin' or 'staff'
$title = ($type === 'staff') ? 'Staff Password Recovery' : 'Admin Password Recovery';
$themeColor = ($type === 'staff') ? '#4f46e5' : '#2e9e63';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Check
    if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
    
    $email = trim($_POST['email'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result = requestPasswordReset($email, $type);
        if ($result['ok']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    } else {
        $error = 'กรุณาระบุอีเมลที่ถูกต้อง';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> — <?= htmlspecialchars(SITE_NAME) ?></title>
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
        .input-wrap { position: relative; margin-bottom: 1.5rem; }
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
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-box">
        <i class="fa-solid fa-key"></i>
    </div>
    <h1 class="main-title">ลืมรหัสผ่าน?</h1>
    <p class="sub-title">ระบุอีเมลของคุณเพื่อรับลิงก์สำหรับตั้งรหัสผ่านใหม่</p>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$message): ?>
    <form method="POST">
        <?php csrf_field(); ?>
        <div class="input-wrap">
            <i class="fa-regular fa-envelope"></i>
            <input type="email" name="email" placeholder="Email Address" required autocomplete="email">
        </div>
        <button type="submit" class="btn-submit">ส่งลิงก์รีเซ็ตรหัสผ่าน</button>
    </form>
    <?php endif; ?>

    <a href="<?= ($type === 'staff') ? 'staff_login.php' : 'login.php' ?>" class="back-link">
        <i class="fa-solid fa-arrow-left mr-2"></i>กลับหน้าเข้าสู่ระบบ
    </a>
</div>

</body>
</html>
