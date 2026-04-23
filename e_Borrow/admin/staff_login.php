<?php
// archive/e_Borrow/admin/staff_login.php
// หน้า Login สำหรับเจ้าหน้าที่ e-Borrow (sys_staff) โดยเฉพาะ
session_start();

// ถ้า login อยู่แล้ว → ไป index
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Error messages
$errors = [
    '1'        => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
    'disabled' => 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
    'db'       => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาลองใหม่อีกครั้ง',
];
$error = isset($_GET['error']) ? ($errors[$_GET['error']] ?? 'เกิดข้อผิดพลาด') : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบเจ้าหน้าที่ - E-Borrow</title>
    <link rel="icon" type="image/png" href="../assets/img/logo.png" sizes="any">
    <link rel="stylesheet" href="../assets/css/style.css?v=2.2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Prompt', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            animation: fadeUp .4s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card {
            background: #fff;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
        }

        /* ── Header ── */
        .card-header {
            background: #0052CC;
            padding: 40px 28px 32px;
            text-align: center;
        }
        .logo-box {
            width: 80px; height: 80px;
            background: #fff;
            border-radius: 22px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px;
            font-size: 2.5rem; color: #0052CC;
            box-shadow: 0 8px 20px rgba(0,0,0,.15);
            transition: transform .3s;
        }
        .logo-box:hover { transform: translateY(-4px) scale(1.04); }
        .card-header h1 { color: #fff; font-size: 1.8rem; font-weight: 800; margin-bottom: 4px; }
        .card-header p  { color: rgba(255,255,255,.8); font-size: .9rem; }

        /* ── Body ── */
        .card-body { padding: 32px; }

        .role-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #eff6ff; color: #16a34a;
            border: 1px solid #bbf7d0;
            border-radius: 20px;
            padding: 5px 14px;
            font-size: .8rem; font-weight: 700;
            margin-bottom: 24px;
        }

        /* ── Error ── */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 16px;
            color: #dc2626;
            font-size: .88rem;
            font-weight: 600;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px;
        }

        /* ── Form ── */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: .85rem; font-weight: 700;
            color: #374151;
            margin-bottom: 7px;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap i.icon-left {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; font-size: .95rem;
        }
        .input-wrap input {
            width: 100%;
            padding: 13px 14px 13px 40px;
            border: 1.5px solid #e5e7eb;
            border-radius: 14px;
            font-size: .95rem;
            font-family: 'Prompt', sans-serif;
            color: #111827;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }
        .input-wrap input:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22,163,74,.15);
        }
        /* password toggle */
        .input-wrap .toggle-pw {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #9ca3af; cursor: pointer; font-size: .9rem;
            padding: 4px;
        }
        .input-wrap .toggle-pw:hover { color: #374151; }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: #0052CC;
            color: #fff;
            font-size: 1rem; font-weight: 700;
            font-family: 'Prompt', sans-serif;
            border: none; border-radius: 16px;
            cursor: pointer;
            transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 14px rgba(11,102,35,.3);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-login:hover  { background: #0a5c1f; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(11,102,35,.4); }
        .btn-login:active { transform: scale(.98); }

        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 24px 0;
            color: #9ca3af; font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;
        }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }

        .btn-portal {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%;
            padding: 12px;
            background: #f8fafc; color: #475569;
            font-size: .9rem; font-weight: 700;
            font-family: 'Prompt', sans-serif;
            border: 1.5px solid #e2e8f0; border-radius: 14px;
            text-decoration: none;
            transition: background .2s, color .2s;
        }
        .btn-portal:hover { background: #e2e8f0; color: #1e293b; }

        .back-link {
            display: block; text-align: center;
            margin-top: 20px;
            font-size: .82rem; color: rgba(255,255,255,.7);
            text-decoration: none;
        }
        .back-link:hover { color: #fff; }
        .back-link i { margin-right: 5px; }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="card">

        <!-- Header -->
        <div class="card-header">
            <div class="logo-box">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h1>เจ้าหน้าที่ระบบ</h1>
            <p>E-Borrow — ระบบยืม-คืนอุปกรณ์</p>
        </div>

        <!-- Body -->
        <div class="card-body">

            <div class="role-badge">
                <i class="fa-solid fa-id-badge"></i>
                Staff Login
            </div>

            <?php if ($error): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-xmark"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="../process/login_process.php" autocomplete="off">

                <div class="form-group">
                    <label for="username"><i class="fa-solid fa-user" style="margin-right:5px;color:#0052CC;"></i>ชื่อผู้ใช้</label>
                    <div class="input-wrap">
                        <i class="icon-left fa-solid fa-at"></i>
                        <input type="text" id="username" name="username"
                               placeholder="กรอก Username"
                               value="<?= htmlspecialchars($_GET['u'] ?? '') ?>"
                               required autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><i class="fa-solid fa-lock" style="margin-right:5px;color:#0052CC;"></i>รหัสผ่าน</label>
                    <div class="input-wrap">
                        <i class="icon-left fa-solid fa-key"></i>
                        <input type="password" id="password" name="password"
                               placeholder="กรอกรหัสผ่าน"
                               required autocomplete="current-password">
                        <button type="button" class="toggle-pw" onclick="togglePw()" tabindex="-1">
                            <i class="fa-solid fa-eye" id="pw-eye"></i>
                        </button>
                    </div>
                    <div style="text-align:right; margin-top:8px;">
                        <a href="../../admin/auth/forgot_password.php?type=staff" style="font-size:0.75rem; color:#6b7280; text-decoration:none; font-weight:600;">ลืมรหัสผ่าน?</a>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    เข้าสู่ระบบ
                </button>
            </form>

            <div class="divider">หรือ</div>

            <a href="../../admin/auth/login.php" class="btn-portal">
                <i class="fa-solid fa-crown"></i>
                เข้าสู่ระบบ Portal Admin
            </a>

        </div>
    </div>

    <a href="../login.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> กลับหน้าหลัก
    </a>
</div>

<script>
function togglePw() {
    const input = document.getElementById('password');
    const eye   = document.getElementById('pw-eye');
    if (input.type === 'password') {
        input.type = 'text';
        eye.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        eye.className = 'fa-solid fa-eye';
    }
}
</script>

</body>
</html>
