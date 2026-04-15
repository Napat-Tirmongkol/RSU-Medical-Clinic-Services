<?php
// admin/staff_login.php — e-Campaign Staff Login (sys_staff)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 7200);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ถ้า login แล้ว ข้ามไปหน้า index
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config.php';

$error = '';

// แสดง error จาก redirect
$errCode = $_GET['error'] ?? '';
if ($errCode === '1')        $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
elseif ($errCode === 'disabled')  $error = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
elseif ($errCode === 'no_access') $error = 'บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าใช้งาน e-Campaign';
elseif ($errCode === 'db')        $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ในภายหลัง';

if (($_GET['reason'] ?? '') === 'timeout') {
    $error = 'เซสชันหมดอายุเนื่องจากไม่มีการใช้งานนาน 2 ชั่วโมง กรุณาเข้าสู่ระบบใหม่';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'กรุณากรอก Username และ Password';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, full_name, role, account_status,
                       IFNULL(access_ecampaign, 0) AS access_ecampaign,
                       IFNULL(ecampaign_role, 'editor') AS ecampaign_role
                FROM sys_staff
                WHERE username = :uname
                LIMIT 1
            ");
            $stmt->execute([':uname' => $username]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($staff && password_verify($password, $staff['password_hash'])) {

                if ($staff['account_status'] === 'disabled') {
                    $error = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
                } elseif (!(int)$staff['access_ecampaign']) {
                    $error = 'บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าใช้งาน e-Campaign กรุณาติดต่อผู้ดูแลระบบ';
                } else {
                    // Whitelist ecampaign_role ป้องกัน privilege escalation
                    $allowedRoles = ['admin', 'editor', 'superadmin'];
                    $ecRole = in_array($staff['ecampaign_role'], $allowedRoles, true)
                        ? $staff['ecampaign_role']
                        : 'editor';

                    session_regenerate_id(true);

                    $_SESSION['admin_logged_in']       = true;
                    $_SESSION['admin_id']              = (int)$staff['id'];
                    $_SESSION['admin_username']        = $staff['full_name'] ?: $staff['username'];
                    $_SESSION['admin_role']            = $ecRole;
                    $_SESSION['is_ecampaign_staff']    = true;   // flag: ไม่ใช่ portal admin
                    $_SESSION['_admin_last_activity']  = time();

                    log_activity('staff_login', "เจ้าหน้าที่ '{$staff['full_name']}' (Username: {$staff['username']}) เข้าสู่ระบบ e-Campaign", (int)$staff['id']);

                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
            }
        } catch (PDOException $e) {
            $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ในภายหลัง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login — E-Campaign</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'rsufont', 'Prompt', sans-serif; box-sizing: border-box; }

        body {
            min-height: 100vh;
            background: #eef2ff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        /* ── Brand bar ─────────────────────────────────── */
        .brand-bar {
            position: fixed;
            top: 1.25rem; left: 1.5rem;
            display: flex; align-items: center; gap: .65rem;
            background: #fff;
            padding: .5rem .9rem;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            z-index: 10;
        }
        .brand-bar .heart {
            width: 2rem; height: 2rem;
            background: #4f46e5;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-bar .heart i { color: #fff; font-size: .85rem; }
        .brand-bar span { font-weight: 700; font-size: .9rem; color: #1e1b4b; }

        /* ── Staff badge (top right) ───────────────────── */
        .staff-badge {
            position: fixed;
            top: 1.25rem; right: 1.5rem;
            background: #4f46e5;
            color: #fff;
            font-size: .65rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: .35rem .85rem;
            border-radius: 999px;
            z-index: 10;
        }

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
            background: linear-gradient(145deg, #818cf8 0%, #6366f1 55%, #4338ca 100%);
            padding: 2.5rem 2rem;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            position: relative; overflow: hidden; gap: 1.2rem;
        }
        .left-panel::before {
            content: '';
            position: absolute; top: -30px; right: -30px;
            width: 160px; height: 160px;
            border-radius: 50%;
            border: 40px solid rgba(255,255,255,.1);
        }
        .left-panel::after {
            content: '';
            position: absolute; bottom: -40px; left: -40px;
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
        .left-icon-box i { color: #4f46e5; font-size: 1.4rem; }
        .left-title {
            font-size: 1.35rem; font-weight: 900;
            color: #fff;
            text-align: center; line-height: 1.25;
            letter-spacing: .02em; text-transform: uppercase;
            z-index: 1;
        }
        .left-sub {
            font-size: .7rem; font-weight: 800;
            color: rgba(255,255,255,.7);
            letter-spacing: .2em; text-transform: uppercase;
            text-align: center; z-index: 1;
        }
        .deco-icons {
            display: flex; gap: 1.5rem;
            margin: .5rem 0; z-index: 1;
        }
        .deco-icon {
            width: 3.8rem; height: 3.8rem;
            background: rgba(255,255,255,.18);
            border-radius: 1rem;
            display: flex; align-items: center; justify-content: center;
        }
        .deco-icon i { color: rgba(255,255,255,.85); font-size: 1.5rem; }
        .left-bottom {
            font-size: .65rem; font-weight: 800;
            color: rgba(255,255,255,.55);
            letter-spacing: .18em; text-transform: uppercase;
            text-align: center; z-index: 1;
        }

        /* ── Right panel ───────────────────────────────── */
        .right-panel {
            flex: 1; background: #fff;
            padding: 2.8rem 2.5rem;
            display: flex; flex-direction: column; justify-content: center;
        }

        .welcome-label {
            font-size: .72rem; color: #555; font-weight: 500;
            padding-left: .75rem;
            border-left: 3px solid #6366f1;
            margin-bottom: 1rem; line-height: 1.4;
        }
        .main-title {
            font-size: 1.75rem; font-weight: 900; color: #0d1f2d;
            line-height: 1.15; text-transform: uppercase;
            letter-spacing: .01em; margin-bottom: .4rem;
        }
        .sign-in-sub {
            font-size: .83rem; color: #6b7280; margin-bottom: 1.6rem;
        }

        /* ── Inputs ────────────────────────────────────── */
        .input-wrap { position: relative; margin-bottom: 1rem; }
        .input-wrap .icon-left {
            position: absolute; left: .9rem; top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; font-size: .85rem; pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: .8rem .9rem .8rem 2.4rem;
            border: 1.5px solid #e5e7eb;
            border-radius: .75rem;
            font-size: .875rem; outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: #fafafa;
            font-family: 'Prompt', sans-serif; color: #111;
        }
        .input-wrap input::placeholder { color: #9ca3af; }
        .input-wrap input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.12);
            background: #fff;
        }
        .input-wrap .eye-btn {
            position: absolute; right: .8rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: .85rem; padding: .2rem;
        }
        .input-wrap .eye-btn:hover { color: #6366f1; }

        /* ── Button ────────────────────────────────────── */
        .btn-login {
            width: 100%; background: #4f46e5; color: #fff;
            font-weight: 800; font-size: .88rem;
            letter-spacing: .12em; text-transform: uppercase;
            padding: .85rem; border-radius: 999px; border: none;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            transition: background .2s, transform .1s, box-shadow .2s;
            box-shadow: 0 4px 14px rgba(79,70,229,.35);
            margin-bottom: 1.1rem;
            font-family: 'Prompt', sans-serif;
        }
        .btn-login:hover { background: #4338ca; box-shadow: 0 6px 20px rgba(79,70,229,.4); }
        .btn-login:active { transform: scale(.98); }

        /* ── Error ─────────────────────────────────────── */
        .error-box {
            background: #fef2f2; border: 1px solid #fecaca;
            color: #dc2626; padding: .65rem .9rem;
            border-radius: .65rem; font-size: .8rem;
            text-align: center; margin-bottom: 1rem;
        }

        /* ── Bottom links ──────────────────────────────── */
        .bottom-links {
            display: flex; justify-content: space-between;
            font-size: .72rem; color: #6b7280;
            margin-top: .5rem;
        }
        .bottom-links a {
            color: #374151; font-weight: 600;
            text-decoration: underline; text-underline-offset: 2px;
        }
        .bottom-links a:hover { color: #4f46e5; }

        /* ── Footer ────────────────────────────────────── */
        .page-footer {
            margin-top: 1.8rem; font-size: .72rem;
            color: #9ca3af;
            display: flex; align-items: center; gap: .5rem;
        }
        .page-footer i { font-size: .7rem; }

        @media (max-width: 640px) {
            .left-panel { display: none; }
            .login-card { max-width: 420px; }
            .right-panel { padding: 2rem 1.5rem; }
            .main-title { font-size: 1.35rem; }
        }
    </style>
</head>
<body>

<!-- Brand bar -->
<div class="brand-bar">
    <div class="heart"><i class="fa-solid fa-heart"></i></div>
    <span>RSU Medical Clinic</span>
</div>

<!-- Staff badge -->
<div class="staff-badge">
    <i class="fa-solid fa-user-tie mr-1"></i> Staff Portal
</div>

<!-- Main card -->
<div class="login-card">

    <!-- Left decorative panel -->
    <div class="left-panel">
        <div class="left-icon-box">
            <i class="fa-solid fa-user-tie"></i>
        </div>
        <p class="left-title">Staff<br>Portal</p>
        <p class="left-sub">e-Campaign Management</p>

        <div class="deco-icons">
            <div class="deco-icon"><i class="fa-solid fa-bullhorn"></i></div>
            <div class="deco-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="deco-icon"><i class="fa-solid fa-users"></i></div>
        </div>

        <p class="left-bottom">Authorized Staff Access Only.</p>
    </div>

    <!-- Right form panel -->
    <div class="right-panel">

        <p class="welcome-label">RSU Medical Clinic | เข้าสู่ระบบเจ้าหน้าที่ e-Campaign</p>
        <h1 class="main-title">Staff<br>Sign In</h1>
        <p class="sign-in-sub">ใช้ Username และ Password ของเจ้าหน้าที่</p>

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
                <input type="text" name="username" placeholder="Username" required autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="input-wrap">
                <i class="fa-solid fa-lock icon-left"></i>
                <input type="password" name="password" id="pwField"
                    placeholder="Password" required autocomplete="current-password">
                <button type="button" class="eye-btn" onclick="togglePw()" id="eyeBtn">
                    <i class="fa-regular fa-eye" id="eyeIcon"></i>
                </button>
            </div>

            <button type="submit" class="btn-login">
                <i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ
            </button>
        </form>

        <div class="bottom-links">
            <a href="login.php">
                <i class="fa-solid fa-shield-halved mr-1"></i>Portal Admin Login
            </a>
            <a href="../index.php">
                <i class="fa-solid fa-house mr-1"></i>หน้าหลัก
            </a>
        </div>

    </div>
</div>

<!-- Footer -->
<div class="page-footer">
    <i class="fa-solid fa-location-dot"></i>
    <i class="fa-solid fa-phone"></i>
    Powered by RSU Healthy Campus Clinic Services
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
