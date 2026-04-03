<?php
// staff/login.php
session_start();
require_once __DIR__ . '/../config.php';

// ถ้ายืนยันตัวตนแล้ว ให้ข้ามไปหน้าสแกนเลย
if (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $pin = $_POST['pin'] ?? '';
    
    // ดึงรหัส PIN จากศูนย์กลาง
    $secrets = require __DIR__ . '/../config/secrets.php';
    $validPin = $secrets['STAFF_SCAN_PIN'] ?? '123456';
    
    if ($pin === $validPin) { 
        session_regenerate_id(true); // ป้องกัน Session Fixation
        $_SESSION['staff_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'รหัส PIN ไม่ถูกต้อง กรุณาลองใหม่';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - ระบบสแกนเช็คอิน</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> body { font-family: 'Prompt', sans-serif; background-color: #f4f7fa; } </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4">
    <div class="bg-white p-8 rounded-3xl shadow-xl w-full max-w-sm text-center border border-gray-100">
        <div class="w-20 h-20 bg-blue-100 text-[#0052CC] rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
            <i class="fa-solid fa-qrcode"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-2">จุดลงทะเบียน</h1>
        <p class="text-sm text-gray-500 mb-6">สำหรับเจ้าหน้าที่สแกนเข้างาน</p>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 text-sm py-2 px-4 rounded-xl mb-4 font-semibold border border-red-100">
                <i class="fa-solid fa-circle-exclamation mr-1"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php csrf_field(); ?>
            <div>
                <input type="password" name="pin" required placeholder="ใส่รหัส PIN 6 หลัก" pattern="[0-9]*" inputmode="numeric"
                       class="w-full px-5 py-4 bg-gray-50 border border-gray-200 rounded-2xl text-center text-xl tracking-[0.5em] font-bold text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#0052CC] focus:bg-white transition-all">
            </div>
            <button type="submit" class="w-full bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-2xl transition-all shadow-md shadow-blue-200">
                เข้าสู่ระบบสแกน
            </button>
        </form>
    </div>
</body>
</html>