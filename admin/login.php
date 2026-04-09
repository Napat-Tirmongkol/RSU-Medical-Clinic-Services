<?php
// admin/login.php
session_start();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/rate_limit.php';

// Check rate limit (5 attempts per 5 minutes)
rate_limit_check('admin_login', 5, 300, 'login.php');

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

if (($_GET['reason'] ?? '') === 'timeout') {
    $error = 'เซสชันหมดอายุเนื่องจากไม่มีการใช้งานนาน 2 ชั่วโมง กรุณาเข้าสู่ระบบใหม่';
}
if (($_GET['error'] ?? '') === 'too_many_attempts') {
    $wait = max(1, (int)($_GET['wait'] ?? 300));
    $mins = ceil($wait / 60);
    $error = "พยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ {$mins} นาทีแล้วลองใหม่";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM sys_admins WHERE username = :uname LIMIT 1");
        $stmt->execute([':uname' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            rate_limit_clear('admin_login');
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['full_name'] ?: $admin['username'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'];
            session_regenerate_id(true);
            header('Location: ../portal/index.php');
            exit;
        } else {
            rate_limit_hit('admin_login', 5, 300);
            $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
        }
    } catch (PDOException $e) {
        $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ในภายหลัง';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — E-Campaign V2</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; box-sizing: border-box; }

        body {
            min-height: 100vh;
            background: #e8f5ee;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        /* ── Top brand bar ────────────────────────────── */
        .brand-bar {
            position: fixed;
            top: 1.25rem;
            left: 1.5rem;
            display: flex;
            align-items: center;
            gap: .65rem;
            background: #fff;
            padding: .5rem .9rem;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            z-index: 10;
        }
        .brand-bar .heart {
            width: 2rem; height: 2rem;
            background: #2e7d52;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-bar .heart i { color: #fff; font-size: .85rem; }
        .brand-bar span { font-weight: 700; font-size: .9rem; color: #1a3d2b; }

        /* ── Main card ─────────────────────────────────── */
        .login-card {
            display: flex;
            width: 100%;
            max-width: 860px;
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.13);
            min-height: 460px;
        }

        /* ── Left panel ────────────────────────────────── */
        .left-panel {
            flex: 0 0 42%;
            background: linear-gradient(145deg, #4dc98a 0%, #3bba7a 60%, #2da06a 100%);
            padding: 2.5rem 2rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            gap: 1.2rem;
        }

        /* decorative bg icons */
        .left-panel::before {
            content: '';
            position: absolute;
            top: -30px; right: -30px;
            width: 160px; height: 160px;
            border-radius: 50%;
            border: 40px solid rgba(255,255,255,.1);
        }
        .left-panel::after {
            content: '';
            position: absolute;
            bottom: -40px; left: -40px;
            width: 180px; height: 180px;
            border-radius: 50%;
            border: 40px solid rgba(255,255,255,.08);
        }

        .left-icon-box {
            width: 3.5rem; height: 3.5rem;
            background: #fff;
            border-radius: .85rem;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(0,0,0,.12);
            z-index: 1;
        }
        .left-icon-box i { color: #2e9e63; font-size: 1.4rem; }

        .left-title {
            font-size: 1.35rem;
            font-weight: 900;
            color: #0d3d22;
            text-align: center;
            line-height: 1.25;
            letter-spacing: .02em;
            text-transform: uppercase;
            z-index: 1;
        }
        .left-sub {
            font-size: .7rem;
            font-weight: 800;
            color: #0d5c30;
            letter-spacing: .2em;
            text-transform: uppercase;
            text-align: center;
            z-index: 1;
        }

        /* decorative icons panel */
        .deco-icons {
            display: flex;
            gap: 1.5rem;
            margin: .5rem 0;
            z-index: 1;
        }
        .deco-icon {
            width: 3.8rem; height: 3.8rem;
            background: rgba(255,255,255,.18);
            border-radius: 1rem;
            display: flex; align-items: center; justify-content: center;
        }
        .deco-icon i { color: rgba(255,255,255,.8); font-size: 1.5rem; }

        .left-bottom {
            font-size: .65rem;
            font-weight: 800;
            color: #0d5c30;
            letter-spacing: .18em;
            text-transform: uppercase;
            text-align: center;
            z-index: 1;
        }

        /* ── Right panel ───────────────────────────────── */
        .right-panel {
            flex: 1;
            background: #fff;
            padding: 2.8rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .welcome-label {
            font-size: .72rem;
            color: #555;
            font-weight: 500;
            padding-left: .75rem;
            border-left: 3px solid #3bba7a;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .main-title {
            font-size: 1.75rem;
            font-weight: 900;
            color: #0d1f2d;
            line-height: 1.15;
            text-transform: uppercase;
            letter-spacing: .01em;
            margin-bottom: .4rem;
        }

        .sign-in-sub {
            font-size: .83rem;
            color: #6b7280;
            margin-bottom: 1.6rem;
        }

        /* ── Inputs ────────────────────────────────────── */
        .input-wrap {
            position: relative;
            margin-bottom: 1rem;
        }
        .input-wrap .icon-left {
            position: absolute;
            left: .9rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: .85rem;
            pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: .8rem .9rem .8rem 2.4rem;
            border: 1.5px solid #e5e7eb;
            border-radius: .75rem;
            font-size: .875rem;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: #fafafa;
            font-family: 'Prompt', sans-serif;
            color: #111;
        }
        .input-wrap input::placeholder { color: #9ca3af; }
        .input-wrap input:focus {
            border-color: #3bba7a;
            box-shadow: 0 0 0 3px rgba(59,186,122,.12);
            background: #fff;
        }
        .input-wrap .eye-btn {
            position: absolute;
            right: .8rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #9ca3af;
            font-size: .85rem;
            padding: .2rem;
        }
        .input-wrap .eye-btn:hover { color: #3bba7a; }

        /* ── Buttons ───────────────────────────────────── */
        .btn-login {
            width: 100%;
            background: #2e9e63;
            color: #fff;
            font-weight: 800;
            font-size: .88rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: .85rem;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            transition: background .2s, transform .1s, box-shadow .2s;
            box-shadow: 0 4px 14px rgba(46,158,99,.35);
            margin-bottom: 1.1rem;
            font-family: 'Prompt', sans-serif;
        }
        .btn-login:hover { background: #267d50; box-shadow: 0 6px 20px rgba(46,158,99,.4); }
        .btn-login:active { transform: scale(.98); }

        .divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1rem;
            color: #d1d5db;
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }

        .btn-google {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .65rem;
            border: 1.5px solid #e5e7eb;
            background: #fff;
            border-radius: 999px;
            padding: .75rem;
            font-size: .83rem;
            font-weight: 600;
            color: #374151;
            text-decoration: none;
            transition: background .2s, border-color .2s, box-shadow .2s;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            margin-bottom: 1.2rem;
            font-family: 'Prompt', sans-serif;
        }
        .btn-google:hover { background: #f9fafb; border-color: #d1d5db; box-shadow: 0 3px 10px rgba(0,0,0,.09); }

        /* ── Error ─────────────────────────────────────── */
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: .65rem .9rem;
            border-radius: .65rem;
            font-size: .8rem;
            text-align: center;
            margin-bottom: 1rem;
        }

        /* ── Bottom links ──────────────────────────────── */
        .bottom-links {
            display: flex;
            justify-content: space-between;
            font-size: .72rem;
            color: #6b7280;
        }
        .bottom-links a { color: #374151; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
        .bottom-links a:hover { color: #2e9e63; }

        /* ── Footer ────────────────────────────────────── */
        .page-footer {
            margin-top: 1.8rem;
            font-size: .72rem;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .page-footer i { font-size: .7rem; }

        /* ── Help btn ──────────────────────────────────── */
        .help-btn {
            position: fixed;
            bottom: 1.25rem;
            right: 1.25rem;
            width: 2.2rem; height: 2.2rem;
            background: #1a3d2b;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: .85rem;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }

        /* responsive */
        @media (max-width: 640px) {
            .left-panel { display: none; }
            .login-card { max-width: 420px; }
            .right-panel { padding: 2rem 1.5rem; }
            .main-title { font-size: 1.35rem; }
        }
    </style>
</head>
<body>

<!-- Top brand -->
<div class="brand-bar">
    <div class="heart"><i class="fa-solid fa-heart"></i></div>
    <span>RSU Healthcare</span>
</div>

<!-- Main card -->
<div class="login-card">

    <!-- Left decorative panel -->
    <div class="left-panel">
        <div class="left-icon-box">
            <i class="fa-solid fa-seedling"></i>
        </div>
        <p class="left-title">Your Trusted<br>Care Team.</p>
        <p class="left-sub">Promoting Campus Wellbeing.</p>

        <div class="deco-icons">
            <div class="deco-icon"><i class="fa-solid fa-stethoscope"></i></div>
            <div class="deco-icon"><i class="fa-solid fa-shield-heart"></i></div>
            <div class="deco-icon"><i class="fa-solid fa-hospital"></i></div>
        </div>

        <p class="left-bottom">Compassionate Clinic Services.</p>
    </div>

    <!-- Right form panel -->
    <div class="right-panel">

        <p class="welcome-label">Welcome to RSU Healthy Campus | Clinic Admin Portal</p>
        <h1 class="main-title">E-Campaign V2<br>Administration</h1>
        <p class="sign-in-sub">Sign in to your secure account.</p>

        <?php if ($error): ?>
        <div class="error-box">
            <i class="fa-solid fa-circle-exclamation mr-1"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?php csrf_field(); ?>
            <div class="input-wrap">
                <i class="fa-regular fa-user icon-left"></i>
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
            </div>
            <div class="input-wrap">
                <i class="fa-solid fa-lock icon-left"></i>
                <input type="password" name="password" id="pwField" placeholder="Password" required autocomplete="current-password">
                <button type="button" class="eye-btn" onclick="togglePw()" id="eyeBtn">
                    <i class="fa-regular fa-eye" id="eyeIcon"></i>
                </button>
            </div>

            <button type="submit" class="btn-login">
                Login <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>

        <div class="divider">หรือ</div>

        <a href="google_login.php" class="btn-google">
            <svg width="18" height="18" viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            เข้าสู่ระบบด้วย Google
        </a>

        <div class="bottom-links">
            <a href="staff_login.php">
                <i class="fa-solid fa-user-tie mr-1"></i>Staff Login
            </a>
            <a href="../portal/manage_admins.php">Register New Admin Account</a>
        </div>

    </div>
</div>

<!-- Footer -->
<div class="page-footer">
    <i class="fa-solid fa-location-dot"></i>
    <i class="fa-solid fa-phone"></i>
    Powered by RSU Healthy Campus Clinic Services
</div>

<!-- Help -->
<div class="help-btn">
    <i class="fa-solid fa-question"></i>
</div>

<script>
function togglePw() {
    const f = document.getElementById('pwField');
    const i = document.getElementById('eyeIcon');
    if (f.type === 'password') {
        f.type = 'text';
        i.className = 'fa-regular fa-eye-slash';
    } else {
        f.type = 'password';
        i.className = 'fa-regular fa-eye';
    }
}
</script>
</body>
</html>
