<?php
/**
 * insurance_partner/login.php — Insurance Partner Portal Login
 * สำหรับเจ้าหน้าที่บริษัทประกัน (external user)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '1800');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth_guard.php';

// ถ้า login แล้ว → ไป dashboard
if (!empty($_SESSION['ins_partner_logged_in'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$reason = $_GET['reason'] ?? '';
if ($reason === 'timeout')   $error = 'เซสชันหมดอายุเนื่องจากไม่มีการใช้งาน กรุณาเข้าสู่ระบบใหม่';
elseif ($reason === 'logout') $error = '';

const MAX_FAILED_LOGINS  = 5;
const LOCKOUT_MINUTES    = 15;

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
                SELECT u.id, u.username, u.password_hash, u.full_name, u.account_status,
                       u.failed_logins, u.locked_until,
                       u.company_code, c.company_name, c.status AS company_status
                FROM insurance_partner_users u
                JOIN insurance_companies c ON c.company_code = u.company_code
                WHERE u.username = :uname
                LIMIT 1
            ");
            $stmt->execute([':uname' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
            } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $error = 'บัญชีนี้ถูกล็อคชั่วคราวจากการพยายามเข้าระบบผิดหลายครั้ง กรุณาลองใหม่ในภายหลัง';
            } elseif ($user['account_status'] !== 'Active') {
                $error = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อ RSU Medical Clinic';
            } elseif ($user['company_status'] !== 'Active') {
                $error = 'บริษัทประกันต้นสังกัดถูกระงับการใช้งาน กรุณาติดต่อ RSU Medical Clinic';
            } elseif (!password_verify($password, $user['password_hash'])) {
                // เพิ่ม failed_logins
                $newFailed = ((int)$user['failed_logins']) + 1;
                if ($newFailed >= MAX_FAILED_LOGINS) {
                    $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
                    $pdo->prepare("UPDATE insurance_partner_users SET failed_logins = :f, locked_until = :l WHERE id = :id")
                        ->execute([':f' => $newFailed, ':l' => $lockUntil, ':id' => $user['id']]);
                    $error = 'พยายามเข้าระบบผิดเกินกำหนด บัญชีถูกล็อค ' . LOCKOUT_MINUTES . ' นาที';
                } else {
                    $pdo->prepare("UPDATE insurance_partner_users SET failed_logins = :f WHERE id = :id")
                        ->execute([':f' => $newFailed, ':id' => $user['id']]);
                    $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
                }

                // log ความพยายามที่ล้มเหลว (ไม่มี user id ใน session)
                try {
                    $pdo->prepare("
                        INSERT INTO insurance_partner_activity_log
                            (partner_user_id, company_code, username, action, details, ip_address, user_agent)
                        VALUES (:uid, :cc, :un, 'login_failed', :det, :ip, :ua)
                    ")->execute([
                        ':uid' => $user['id'],
                        ':cc'  => $user['company_code'],
                        ':un'  => $username,
                        ':det' => "failed_logins={$newFailed}",
                        ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                } catch (Exception $e) { /* silent */ }
            } else {
                // ผ่าน — สร้าง session ใหม่
                session_regenerate_id(true);

                $pdo->prepare("
                    UPDATE insurance_partner_users
                    SET failed_logins = 0, locked_until = NULL,
                        last_login_at = NOW(), last_login_ip = :ip
                    WHERE id = :id
                ")->execute([
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':id' => $user['id'],
                ]);

                $_SESSION['ins_partner_logged_in']    = true;
                $_SESSION['ins_partner_id']           = (int)$user['id'];
                $_SESSION['ins_partner_username']     = $user['username'];
                $_SESSION['ins_partner_full_name']    = $user['full_name'];
                $_SESSION['ins_partner_company']      = $user['company_code'];
                $_SESSION['ins_partner_company_name'] = $user['company_name'];
                $_SESSION['_ins_partner_last_activity'] = time();

                ins_partner_log('login_success', "user={$user['username']}, company={$user['company_code']}");

                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('insurance_partner login: ' . $e->getMessage());
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
    <title>Insurance Partner Login — RSU Medical Clinic</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'rsufont', 'Prompt', sans-serif; box-sizing: border-box; }
        body {
            min-height: 100vh; background: #ecfdf5;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 1.5rem;
        }
        .brand-bar {
            position: fixed; top: 1.25rem; left: 1.5rem;
            display: flex; align-items: center; gap: .65rem;
            background: #fff; padding: .5rem .9rem;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            z-index: 10;
        }
        .brand-bar .heart {
            width: 2rem; height: 2rem; background: #059669;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-bar .heart i { color: #fff; font-size: .85rem; }
        .brand-bar span { font-weight: 700; font-size: .9rem; color: #064e3b; }
        .partner-badge {
            position: fixed; top: 1.25rem; right: 1.5rem;
            background: #059669; color: #fff;
            font-size: .65rem; font-weight: 800;
            letter-spacing: .12em; text-transform: uppercase;
            padding: .35rem .85rem; border-radius: 999px;
            z-index: 10;
        }
        .login-card {
            display: flex; width: 100%; max-width: 860px;
            border-radius: 1.5rem; overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.13);
            min-height: 460px;
        }
        .left-panel {
            flex: 0 0 42%;
            background: linear-gradient(145deg, #34d399 0%, #10b981 55%, #047857 100%);
            padding: 2.5rem 2rem;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            position: relative; overflow: hidden; gap: 1.2rem;
        }
        .left-panel::before {
            content: ''; position: absolute; top: -30px; right: -30px;
            width: 160px; height: 160px; border-radius: 50%;
            border: 40px solid rgba(255,255,255,.1);
        }
        .left-panel::after {
            content: ''; position: absolute; bottom: -40px; left: -40px;
            width: 180px; height: 180px; border-radius: 50%;
            border: 40px solid rgba(255,255,255,.08);
        }
        .left-icon-box {
            width: 3.5rem; height: 3.5rem; background: #fff;
            border-radius: .85rem;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(0,0,0,.12); z-index: 1;
        }
        .left-icon-box i { color: #059669; font-size: 1.4rem; }
        .left-title {
            font-size: 1.35rem; font-weight: 900; color: #fff;
            text-align: center; line-height: 1.25;
            letter-spacing: .02em; text-transform: uppercase; z-index: 1;
        }
        .left-sub {
            font-size: .7rem; font-weight: 800;
            color: rgba(255,255,255,.7);
            letter-spacing: .2em; text-transform: uppercase;
            text-align: center; z-index: 1;
        }
        .deco-icons { display: flex; gap: 1.5rem; margin: .5rem 0; z-index: 1; }
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
        .right-panel {
            flex: 1; background: #fff;
            padding: 2.8rem 2.5rem;
            display: flex; flex-direction: column; justify-content: center;
        }
        .welcome-label {
            font-size: .72rem; color: #555; font-weight: 500;
            padding-left: .75rem;
            border-left: 3px solid #10b981;
            margin-bottom: 1rem; line-height: 1.4;
        }
        .main-title {
            font-size: 1.75rem; font-weight: 900; color: #0d1f2d;
            line-height: 1.15; text-transform: uppercase;
            letter-spacing: .01em; margin-bottom: .4rem;
        }
        .sign-in-sub { font-size: .83rem; color: #6b7280; margin-bottom: 1.6rem; }
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
        .input-wrap input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,.12);
            background: #fff;
        }
        .input-wrap .eye-btn {
            position: absolute; right: .8rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: .85rem; padding: .2rem;
        }
        .input-wrap .eye-btn:hover { color: #10b981; }
        .btn-login {
            width: 100%; background: #059669; color: #fff;
            font-weight: 800; font-size: .88rem;
            letter-spacing: .12em; text-transform: uppercase;
            padding: .85rem; border-radius: 999px; border: none;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            box-shadow: 0 4px 14px rgba(5,150,105,.35);
            margin-bottom: 1.1rem;
            font-family: 'Prompt', sans-serif;
            transition: background .2s, transform .1s, box-shadow .2s;
        }
        .btn-login:hover { background: #047857; box-shadow: 0 6px 20px rgba(5,150,105,.4); }
        .btn-login:active { transform: scale(.98); }
        .error-box {
            background: #fef2f2; border: 1px solid #fecaca;
            color: #dc2626; padding: .65rem .9rem;
            border-radius: .65rem; font-size: .8rem;
            text-align: center; margin-bottom: 1rem;
        }
        .page-footer {
            margin-top: 1.8rem; font-size: .72rem;
            color: #9ca3af;
            display: flex; align-items: center; gap: .5rem;
        }
        @media (max-width: 640px) {
            .left-panel { display: none; }
            .login-card { max-width: 420px; }
            .right-panel { padding: 2rem 1.5rem; }
            .main-title { font-size: 1.35rem; }
        }
    </style>
</head>
<body>

<div class="brand-bar">
    <div class="heart"><i class="fa-solid fa-heart"></i></div>
    <span>RSU Medical Clinic</span>
</div>

<div class="partner-badge">
    <i class="fa-solid fa-handshake mr-1"></i> Insurance Partner
</div>

<div class="login-card">
    <div class="left-panel">
        <div class="left-icon-box"><i class="fa-solid fa-shield-heart"></i></div>
        <p class="left-title">Insurance<br>Partner</p>
        <p class="left-sub">Policy Management Portal</p>
        <div class="deco-icons">
            <div class="deco-icon"><i class="fa-solid fa-file-csv"></i></div>
            <div class="deco-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
            <div class="deco-icon"><i class="fa-solid fa-file-shield"></i></div>
        </div>
        <p class="left-bottom">Authorized Partner Access Only.</p>
    </div>

    <div class="right-panel">
        <p class="welcome-label">RSU Medical Clinic | Insurance Partner Portal</p>
        <h1 class="main-title">Partner<br>Sign In</h1>
        <p class="sign-in-sub">สำหรับเจ้าหน้าที่บริษัทประกันที่ได้รับสิทธิ์เท่านั้น</p>

        <?php if ($error): ?>
        <div class="error-box">
            <i class="fa-solid fa-circle-exclamation mr-1"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
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
                <button type="button" class="eye-btn" onclick="togglePw()">
                    <i class="fa-regular fa-eye" id="eyeIcon"></i>
                </button>
            </div>
            <button type="submit" class="btn-login">
                <i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ
            </button>
        </form>

        <p style="text-align:center; font-size:.7rem; color:#9ca3af; margin-top:.5rem;">
            หากไม่สามารถเข้าระบบ กรุณาติดต่อเจ้าหน้าที่ RSU Medical Clinic
        </p>
    </div>
</div>

<div class="page-footer">
    <i class="fa-solid fa-shield-halved"></i>
    Powered by RSU Healthy Campus Clinic Services
</div>

<script>
function togglePw() {
    const f = document.getElementById('pwField');
    const i = document.getElementById('eyeIcon');
    if (f.type === 'password') { f.type = 'text'; i.className = 'fa-regular fa-eye-slash'; }
    else { f.type = 'password'; i.className = 'fa-regular fa-eye'; }
}
</script>
</body>
</html>
