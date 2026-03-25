<?php
// e_Borrow/login.php
// หน้าสำหรับแสดงปุ่ม Login — ผู้ใช้ต้องกดเองเพื่อเริ่ม LINE OAuth
declare(strict_types=1);
session_start();

// ถ้า Login แล้ว ให้ Redirect ไปหน้าหลักของ e_Borrow เลย
if (!empty($_SESSION['student_id'])) {
    header('Location: index.php');
    exit;
}

// แจ้งสาเหตุถ้ามี (เช่น timeout, logged_out)
$reason = $_GET['reason'] ?? ($_GET['timeout'] ?? '');
$timeout = $reason === 'timeout' || isset($_GET['timeout']);

// =========================================================
// สร้าง LINE Auth URL ไว้ล่วงหน้า (เพื่อใส่ใน href ปุ่ม)
// เมื่อผู้ใช้กดปุ่ม ถึงจะเริ่ม Flow
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
    <title>เข้าสู่ระบบ — ระบบยืมคืนอุปกรณ์</title>
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
            background: linear-gradient(135deg, #0B6623 0%, #1a8c35 50%, #084C1A 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 380px;
            animation: fadeUp .45s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .card {
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18);
        }
        .card-header {
            background: linear-gradient(135deg, #0B6623, #1a8c35);
            padding: 36px 28px 28px;
            text-align: center;
        }
        .logo-box {
            width: 72px; height: 72px;
            background: #fff;
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            font-size: 2.2rem; color: #0B6623;
            transition: transform .3s;
        }
        .logo-box:hover { transform: rotate(-5deg) scale(1.05); }
        .card-header h1 { color: #fff; font-size: 1.3rem; font-weight: 700; margin-bottom: 4px; }
        .card-header p  { color: rgba(255,255,255,.78); font-size: .85rem; }
        .card-body { padding: 28px; }

        /* Timeout notice */
        .notice {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
            font-size: .82rem; color: #7c5c00;
        }

        /* LINE Button */
        .btn-line {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 15px;
            background: #00c300;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 14px;
            text-decoration: none;
            transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 16px rgba(0,195,0,.25);
        }
        .btn-line:hover {
            background: #00a800;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,195,0,.3);
        }
        .btn-line:active  { transform: scale(.97); }
        .btn-line i.fab   { font-size: 1.4rem; }

        .divider {
            display: flex; align-items: center; gap: 10px;
            margin: 20px 0; color: #aaa; font-size: .78rem;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px; background: #e8e8e8;
        }

        /* Info badges */
        .badges { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .badge-item {
            background: #f7f9fc;
            border-radius: 14px;
            padding: 12px 8px;
            text-align: center;
        }
        .badge-item i { font-size: 1.2rem; display: block; margin-bottom: 6px; }
        .badge-item p { font-size: .72rem; color: #555; line-height: 1.4; }
        .badge-item.green i { color: #0B6623; }
        .badge-item.blue  i { color: #0052CC; }
        .badge-item.gold  i { color: #e6a817; }

        .footer-note {
            margin-top: 20px;
            text-align: center;
            font-size: .73rem;
            color: rgba(255,255,255,.6);
            line-height: 1.6;
        }
        .footer-note a { color: rgba(255,255,255,.85); text-decoration: underline; }

        /* Staff link */
        .btn-staff {
            display: block;
            text-align: center;
            margin-top: 14px;
            padding: 11px;
            background: #f0f4f8;
            color: #444;
            font-size: .85rem;
            font-weight: 600;
            border-radius: 12px;
            text-decoration: none;
            transition: background .2s;
        }
        .btn-staff:hover { background: #e0e7ee; }
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
            <p>ระบบยืมคืนอุปกรณ์การแพทย์</p>
        </div>

        <div class="card-body">
            <?php if ($timeout): ?>
            <div class="notice">
                <i class="fa-solid fa-clock" style="color:#e6a817; font-size:1.1rem;"></i>
                <span>หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบอีกครั้ง</span>
            </div>
            <?php endif; ?>

            <!-- LINE Login Button -->
            <a href="<?= htmlspecialchars($authUrl, ENT_QUOTES) ?>" class="btn-line" id="btn-line-login">
                <i class="fab fa-line"></i>
                <span>เข้าสู่ระบบด้วย LINE</span>
            </a>

            <div class="divider">ข้อมูลของคุณได้รับการปกป้องโดย PDPA</div>

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
                    <p>แจ้งเตือน<br>ทาง LINE</p>
                </div>
            </div>

            <a href="admin/login.php" class="btn-staff">
                <i class="fa-solid fa-user-shield" style="margin-right:6px;"></i>
                สำหรับพนักงาน / เจ้าหน้าที่
            </a>
        </div>
    </div>

    <p class="footer-note">
        การเข้าใช้งานถือว่าคุณยอมรับ<br>
        <a href="#">นโยบายความเป็นส่วนตัว</a> และ <a href="#">ข้อกำหนดการใช้งาน</a>
    </p>
</div>
</body>
</html>