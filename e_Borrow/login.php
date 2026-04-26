<?php
// archive/e_Borrow/login.php
// หน้าหลักสำหรับแสดงผล Login ของฝั่งนักศึกษา โดยเชื่อมต่อกับ LINE OAuth
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_borrow');

// หาก Login อยู่แล้ว ให้ Redirect ไปหน้าหลักของ e_Borrow
if (!empty($_SESSION['student_id'])) {
    header('Location: index.php');
    exit;
}

// ตรวจสอบเหตุผลการเด้ง (เช่น timeout, logged_out)
$reason = $_GET['reason'] ?? ($_GET['timeout'] ?? '');
$timeout = $reason === 'timeout' || isset($_GET['timeout']);

// [NEW] ตรวจสอบความผิดพลาดจากการเข้าถึง (Access Control Enforcement)
$errorCode = $_GET['error'] ?? '';
$showError = false;
$errorMessage = '';

if ($errorCode === 'access_revoked' || $errorCode === 'access_denied_eborrow') {
    $showError = true;
    $errorMessage = 'คุณไม่มีสิทธิ์เข้าถึงระบบนี้ในขณะนี้ กรุณาติดต่อผู้ดูแลระบบ';
} elseif ($errorCode === 'no_staff_account') {
    $showError = true;
    $errorMessage = 'ไม่พบบัญชีเจ้าหน้าที่ในระบบ หรือสิทธิ์การใช้งานของคุณไม่ถูกต้อง';
}

// =========================================================
// สร้าง LINE Auth URL (ดึงมาจาก line_config)
// =========================================================
$state = bin2hex(random_bytes(16));
$_SESSION['line_login_state'] = $state;
$_SESSION['redirect_to']      = 'eborrow';

require_once __DIR__ . '/../line_api/line_config.php';

$authUrl = "https://access.line.me/oauth2/v2.1/authorize?" . http_build_query([
    'response_type' => 'code',
    'client_id'     => LINE_LOGIN_CHANNEL_ID,
    'redirect_uri'  => LINE_LOGIN_CALLBACK_URL,
    'state'         => $state,
    'scope'         => 'profile openid',
]);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบยืม-คืนอุปกรณ์</title>
    <link rel="icon" type="image/png" href="assets/img/logo.png" sizes="any">
    <link rel="stylesheet" href="assets/css/style.css?v=2.2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        (function() {
            try {
                if (localStorage.getItem('theme') === 'dark')
                    document.documentElement.classList.add('dark-mode');
            } catch(e) {}
        })();
    </script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Prompt', sans-serif;
            background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 420px;
            animation: fadeUp .45s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .card {
            background: #fff;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0,0,0,0.25);
        }
        .card-header {
            background: #0052CC;
            padding: 48px 28px 36px;
            text-align: center;
        }
        .logo-box {
            width: 84px; height: 84px;
            background: #fff;
            border-radius: 24px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            font-size: 2.8rem; color: #0052CC;
            transition: transform .3s;
        }
        .logo-box:hover { transform: translateY(-5px) scale(1.05); }
        .card-header h1 { color: #fff; font-size: 2rem; font-weight: 800; margin-bottom: 6px; letter-spacing: -0.02em; }
        .card-header p  { color: rgba(255,255,255,.8); font-size: 0.95rem; font-weight: 500; }
        .card-body { padding: 32px; }

        /* Timeout notice */
        .notice {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px;
            font-size: 0.9rem; color: #7c5c00;
        }

        /* LINE Button */
        .btn-line {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            padding: 16px;
            background: #06c755;
            color: #fff;
            font-size: 1.1rem;
            font-weight: 700;
            border-radius: 18px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(6, 199, 85, 0.3);
        }
        .btn-line:hover {
            background: #05ae4a;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(6, 199, 85, 0.4);
        }
        .btn-line:active  { transform: scale(.97); }
        .btn-line i.fab   { font-size: 1.6rem; }

        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 28px 0; color: #94a3b8; font-size: 0.8rem;
            font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px; background: #e2e8f0;
        }

        /* Info badges */
        .badges { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 28px; }
        .badge-item {
            background: #f8fafc;
            border-radius: 18px;
            padding: 16px 12px;
            text-align: center;
            transition: transform 0.2s ease;
        }
        .badge-item:hover { transform: translateY(-3px); background: #f1f5f9; }
        .badge-item i { font-size: 1.5rem; display: block; margin-bottom: 8px; }
        .badge-item p { font-size: 0.75rem; color: #475569; font-weight: 600; line-height: 1.4; }
        .badge-item.green i { color: #16a34a; }
        .badge-item.blue  i { color: #2563eb; }
        .badge-item.gold  i { color: #ca8a04; }

        .footer-note {
            margin-top: 24px;
            text-align: center;
            font-size: 0.8rem;
            color: rgba(255,255,255,0.7);
            line-height: 1.6;
        }
        .footer-note a { color: #fff; text-decoration: none; border-bottom: 1px solid rgba(255,255,255,0.3); padding-bottom: 1px; }

        /* Staff link */
        .btn-staff {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.95rem;
            font-weight: 700;
            border-radius: 16px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-staff:hover { background: #e2e8f0; color: #1e293b; }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="card">
        <div class="card-header">
            <div class="logo-box">
                <i class="fa-solid fa-hand-holding-medical"></i>
            </div>
            <h1>MedLoan</h1>
            <p>ระบบยืม-คืนอุปกรณ์ทางการแพทย์</p>
        </div>

        <div class="card-body">
            <?php if ($timeout): ?>
            <div class="notice">
                <i class="fa-solid fa-triangle-exclamation" style="color:#d97706; font-size:1.2rem;"></i>
                <span>เซสชั่นหมดอายุ กรุณาเข้าสู่ระบบอีกครั้งเพื่อดำเนินการต่อ</span>
            </div>
            <?php endif; ?>

            <?php if ($showError): ?>
            <div class="notice" style="background:#fef2f2; border-color:#fecaca; color:#dc2626;">
                <i class="fa-solid fa-circle-exclamation" style="color:#ef4444; font-size:1.2rem;"></i>
                <span style="font-weight:700;"><?= htmlspecialchars($errorMessage) ?></span>
            </div>
            <?php endif; ?>

            <!-- LINE Login Button -->
            <a href="<?= htmlspecialchars($authUrl, ENT_QUOTES) ?>" class="btn-line" id="btn-line-login" title="เข้าสู่ระบบด้วย LINE">
                <i class="fab fa-line"></i>
                <span>เข้าสู่ระบบด้วย LINE</span>
            </a>

            <div class="divider">คุ้มครองข้อมูลส่วนบุคคล PDPA</div>

            <div class="badges">
                <div class="badge-item green">
                    <i class="fa-solid fa-shield-halved"></i>
                    <p>ปลอดภัย<br>100%</p>
                </div>
                <div class="badge-item blue">
                    <i class="fa-solid fa-clock"></i>
                    <p>รวดเร็ว<br>ทันใจ</p>
                </div>
                <div class="badge-item gold">
                    <i class="fa-solid fa-bell"></i>
                    <p>แจ้งเตือน<br>ผ่าน LINE</p>
                </div>
            </div>

            <a href="admin/staff_login.php" class="btn-staff">
                <i class="fa-solid fa-user-shield"></i>
                สำหรับเจ้าหน้าที่ / ผู้ดูแลระบบ
            </a>
        </div>
    </div>

    <p class="footer-note">
        เข้าใช้งานระบบเพื่อความสะดวกของคุณ<br>
        <a href="#">นโยบายความเป็นส่วนตัว</a> และ <a href="#">ข้อกำหนดการใช้งาน</a>
    </p>
</div>
</body>
</html>