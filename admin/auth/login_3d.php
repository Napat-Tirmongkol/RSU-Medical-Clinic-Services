<?php
// admin/auth/login_3d.php
// 3D Clinic Scene + Glassmorphism prototype — visual variant ของ login.php
// Three.js scene ธีมคลินิก (DNA helix + cells + pulse rings + capsule)
// PHP logic เหมือน login.php ทุกประการ (CSRF / rate limit / session)
session_start();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

rate_limit_ip_check_or_redirect('admin_login', 5, 300);

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
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM sys_admins WHERE username = :uname LIMIT 1");
        $stmt->execute([':uname' => $username]);
        $admin = $stmt->fetch();

        $dummyHash = '$2y$12$IVQJwsWr.gGbN7/sS3Z9..5ie9lN55IVDUWV.sr70DjTvB0jRbVWu';
        $passwordOk = password_verify($password, $admin ? $admin['password'] : $dummyHash);

        $ipForLog = rate_limit_ip_addr();

        if ($admin && $passwordOk) {
            rate_limit_ip_clear('admin_login');
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['full_name'] ?: $admin['username'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'];
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);

            log_activity('Login', "Admin '{$admin['username']}' เข้าสู่ระบบระบบจัดการกลาง (Portal)", (int)$admin['id']);

            header('Location: ../../portal/index.php');
            exit;
        } else {
            rate_limit_ip_hit('admin_login', 5, 300);
            log_activity('admin_login_failed',
                "Failed login attempt: username='" . mb_substr($username, 0, 100) . "' from IP {$ipForLog}",
                null);
            $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
        }
    } catch (PDOException $e) {
        error_log('[admin_login_3d] ' . $e->getMessage());
        $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ในภายหลัง';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/rsufont.css">

    <!-- Three.js + custom clinic scene (DNA helix + cells + pulse rings + capsule) -->
    <script defer src="https://cdn.jsdelivr.net/npm/three@0.149.0/build/three.min.js"></script>
    <script defer src="../../assets/js/clinic-3d-scene.js"></script>

    <style>
        * { font-family: 'Sarabun', sans-serif; box-sizing: border-box; }

        html, body { margin: 0; padding: 0; min-height: 100vh; overflow-x: hidden; }

        body {
            min-height: 100vh;
            background: #061a13;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5rem 1.5rem 4rem;
            position: relative;
            overflow: hidden;
        }

        /* ── Layer 0: CSS animated background (fallback + always-on base) ── */
        .bg-fallback {
            position: fixed;
            inset: 0;
            z-index: 0;
            background:
                radial-gradient(900px circle at 18% 25%, rgba(77,201,138,.55), transparent 55%),
                radial-gradient(800px circle at 82% 75%, rgba(6,194,164,.40), transparent 55%),
                radial-gradient(1100px circle at 50% 50%, rgba(11,55,38,.7), transparent 65%),
                linear-gradient(135deg, #03130d 0%, #0a3322 45%, #02110b 100%);
            animation: bgShift 22s ease-in-out infinite;
        }
        @keyframes bgShift {
            0%, 100% { filter: hue-rotate(0deg) brightness(1); }
            50%      { filter: hue-rotate(8deg) brightness(1.06); }
        }

        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(90px);
            opacity: .55;
            z-index: 1;
            pointer-events: none;
            animation: float 18s ease-in-out infinite;
            will-change: transform;
        }
        .blob-1 { width: 540px; height: 540px; background: #4dc98a; top: -12%; left: -8%; }
        .blob-2 { width: 460px; height: 460px; background: #06c2a4; bottom: -10%; right: -10%; animation-delay: -6s; }
        .blob-3 { width: 320px; height: 320px; background: #38d399; top: 55%; left: 60%; animation-delay: -12s; opacity: .4; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33%      { transform: translate(40px, -30px) scale(1.07); }
            66%      { transform: translate(-30px, 30px) scale(.95); }
        }

        /* Subtle grain for premium texture */
        .grain {
            position: fixed; inset: 0; z-index: 2; pointer-events: none; opacity: .35;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.95' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 .08 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
            mix-blend-mode: overlay;
        }

        /* ── Layer 3: Three.js 3D canvas (clinic scene) ── */
        .scene3d {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            z-index: 3;
            opacity: 0;
            transition: opacity 1.1s ease;
            display: block;
            pointer-events: none;     /* clicks pass through to form */
        }
        .scene3d.is-ready { opacity: 1; }

        /* ── Layer 4: dark vignette for form contrast ── */
        .vignette {
            position: fixed; inset: 0; z-index: 4; pointer-events: none;
            background:
                radial-gradient(ellipse 60% 70% at center, transparent 0%, rgba(0,0,0,.35) 60%, rgba(0,0,0,.55) 100%);
        }

        /* ── Brand pill (top-left) ── */
        .brand-bar {
            position: fixed;
            top: 1.25rem; left: 1.5rem;
            display: flex; align-items: center; gap: .65rem;
            background: rgba(255,255,255,.10);
            backdrop-filter: blur(14px) saturate(180%);
            -webkit-backdrop-filter: blur(14px) saturate(180%);
            padding: .55rem 1rem .55rem .55rem;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,.18);
            box-shadow: 0 8px 24px rgba(0,0,0,.18);
            z-index: 20;
            animation: fadeDown .6s cubic-bezier(.16,1,.3,1) both;
        }
        .brand-bar .heart {
            width: 2rem; height: 2rem;
            background: linear-gradient(135deg, #4dc98a, #2e9e63);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(46,158,99,.5);
        }
        .brand-bar .heart i { color: #fff; font-size: .85rem; }
        .brand-bar span { font-weight: 700; font-size: .88rem; color: #fff; letter-spacing: .01em; }

        /* ── Main login card (glassmorphism) ── */
        .login-card {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            padding: 2.6rem 2.4rem 2.2rem;
            background: rgba(255,255,255,.07);
            backdrop-filter: blur(28px) saturate(180%);
            -webkit-backdrop-filter: blur(28px) saturate(180%);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 1.75rem;
            box-shadow:
                0 30px 80px rgba(0,0,0,.55),
                inset 0 1px 0 rgba(255,255,255,.12);
            animation: cardIn .75s cubic-bezier(.16,1,.3,1) both;
            transform-style: preserve-3d;
            transition: transform .25s ease;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(40px) scale(.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Card header ── */
        .logo-wrap {
            display: flex; align-items: center; gap: .8rem;
            margin-bottom: 1.7rem;
        }
        .logo-orb {
            width: 3.2rem; height: 3.2rem;
            background: linear-gradient(135deg, #4dc98a 0%, #2e9e63 60%, #1d7048 100%);
            border-radius: 1rem;
            display: flex; align-items: center; justify-content: center;
            box-shadow:
                0 12px 28px rgba(46,158,99,.55),
                inset 0 1px 0 rgba(255,255,255,.3);
            position: relative;
            animation: orbBob 4s ease-in-out infinite;
        }
        .logo-orb i { color: #fff; font-size: 1.35rem; }
        @keyframes orbBob {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-4px); }
        }
        .logo-text-lg {
            font-size: 1.05rem; font-weight: 800; color: #fff; letter-spacing: .01em;
        }
        .logo-text-sm {
            font-size: .72rem; font-weight: 600; color: rgba(255,255,255,.65);
            letter-spacing: .14em; text-transform: uppercase; margin-top: .1rem;
        }

        .welcome-label {
            font-size: .72rem;
            color: rgba(255,255,255,.7);
            font-weight: 600;
            padding-left: .75rem;
            border-left: 3px solid #4dc98a;
            margin-bottom: 1rem;
            line-height: 1.4;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .main-title {
            font-size: 1.85rem; font-weight: 900;
            line-height: 1.1;
            margin-bottom: .35rem;
            background: linear-gradient(135deg, #ffffff 0%, #b8f0d4 60%, #4dc98a 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -.01em;
        }
        .sign-in-sub {
            font-size: .84rem; color: rgba(255,255,255,.6);
            margin-bottom: 1.6rem;
        }

        /* ── Inputs ── */
        .input-wrap { position: relative; margin-bottom: .9rem; }
        .input-wrap .icon-left {
            position: absolute; left: 1rem; top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,.5);
            font-size: .85rem; pointer-events: none;
            transition: color .2s;
        }
        .input-wrap input {
            width: 100%;
            padding: .9rem 1rem .9rem 2.6rem;
            border: 1px solid rgba(255,255,255,.16);
            border-radius: .9rem;
            font-size: .9rem;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
            background: rgba(255,255,255,.06);
            font-family: 'Sarabun', sans-serif;
            color: #fff;
        }
        .input-wrap input::placeholder { color: rgba(255,255,255,.4); }
        .input-wrap input:focus {
            border-color: #4dc98a;
            box-shadow: 0 0 0 4px rgba(77,201,138,.18), 0 6px 18px rgba(77,201,138,.18);
            background: rgba(255,255,255,.10);
        }
        .input-wrap input:focus ~ .icon-left,
        .input-wrap:focus-within .icon-left { color: #4dc98a; }
        .input-wrap .eye-btn {
            position: absolute; right: .9rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: rgba(255,255,255,.5);
            font-size: .9rem; padding: .2rem;
            transition: color .2s;
        }
        .input-wrap .eye-btn:hover { color: #4dc98a; }

        .forgot-row {
            display: flex; justify-content: flex-end; margin: -.15rem 0 1rem;
        }
        .forgot-row a {
            font-size: .76rem; font-weight: 600;
            color: rgba(255,255,255,.6);
            text-decoration: none;
            transition: color .2s;
        }
        .forgot-row a:hover { color: #4dc98a; }

        /* ── Primary CTA ── */
        .btn-login {
            position: relative;
            width: 100%;
            background: linear-gradient(135deg, #4dc98a 0%, #2e9e63 60%, #1d7048 100%);
            color: #fff;
            font-weight: 800;
            font-size: .9rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: .95rem;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            gap: .55rem;
            transition: transform .2s, box-shadow .25s, filter .2s;
            box-shadow: 0 14px 40px rgba(46,158,99,.55), inset 0 1px 0 rgba(255,255,255,.25);
            margin-bottom: 1.15rem;
            font-family: 'Sarabun', sans-serif;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,.35) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform .65s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 50px rgba(46,158,99,.65), inset 0 1px 0 rgba(255,255,255,.3);
            filter: brightness(1.05);
        }
        .btn-login:hover::before { transform: translateX(100%); }
        .btn-login:active { transform: translateY(0) scale(.99); }
        .btn-login i { transition: transform .25s; }
        .btn-login:hover i { transform: translateX(3px); }

        /* ── Divider ── */
        .divider {
            display: flex; align-items: center; gap: .8rem;
            margin-bottom: 1rem;
            color: rgba(255,255,255,.35);
            font-size: .72rem; font-weight: 600;
            letter-spacing: .12em; text-transform: uppercase;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.2), transparent);
        }

        /* ── Google button ── */
        .btn-google {
            width: 100%;
            display: flex; align-items: center; justify-content: center; gap: .65rem;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.06);
            backdrop-filter: blur(10px);
            border-radius: 999px;
            padding: .8rem;
            font-size: .84rem; font-weight: 600;
            color: #fff;
            text-decoration: none;
            transition: background .2s, border-color .2s, transform .15s;
            margin-bottom: 1.25rem;
            font-family: 'Sarabun', sans-serif;
        }
        .btn-google:hover { background: rgba(255,255,255,.12); border-color: rgba(255,255,255,.3); transform: translateY(-1px); }

        /* ── Error box ── */
        .error-box {
            background: rgba(220,38,38,.12);
            border: 1px solid rgba(252,165,165,.35);
            color: #fecaca;
            padding: .7rem .95rem;
            border-radius: .75rem;
            font-size: .8rem;
            text-align: center;
            margin-bottom: 1rem;
            backdrop-filter: blur(8px);
            animation: shake .45s cubic-bezier(.36,.07,.19,.97);
        }
        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }

        /* ── Bottom links ── */
        .bottom-links {
            display: flex; justify-content: space-between;
            font-size: .74rem; color: rgba(255,255,255,.55);
        }
        .bottom-links a {
            color: rgba(255,255,255,.75);
            font-weight: 600; text-decoration: none;
            border-bottom: 1px dashed rgba(255,255,255,.25);
            padding-bottom: 2px;
            transition: color .2s, border-color .2s;
        }
        .bottom-links a:hover { color: #4dc98a; border-bottom-color: #4dc98a; }

        /* ── Page footer ── */
        .page-footer {
            position: fixed;
            bottom: 1.25rem; left: 50%;
            transform: translateX(-50%);
            font-size: .72rem; color: rgba(255,255,255,.45);
            display: flex; align-items: center; gap: .55rem;
            white-space: nowrap;
            z-index: 20;
            animation: fadeDown .8s .3s cubic-bezier(.16,1,.3,1) both;
        }

        /* ── Help button ── */
        .help-btn {
            position: fixed;
            bottom: 1.25rem; right: 1.25rem;
            width: 2.4rem; height: 2.4rem;
            background: rgba(255,255,255,.10);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: .9rem; cursor: pointer;
            transition: background .2s, transform .15s;
            z-index: 20;
        }
        .help-btn:hover { background: rgba(77,201,138,.25); transform: scale(1.08); }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            body { padding: 4.5rem 1rem 4rem; }
            .login-card { padding: 2rem 1.5rem 1.75rem; border-radius: 1.4rem; }
            .main-title { font-size: 1.45rem; }
            .logo-orb { width: 2.8rem; height: 2.8rem; }
            .page-footer { font-size: .68rem; }
        }
        @media (max-height: 640px) {
            body { padding-top: 4.5rem; align-items: flex-start; }
            .login-card { padding: 1.6rem 1.5rem 1.4rem; }
            .logo-wrap { margin-bottom: 1.1rem; }
            .main-title { font-size: 1.45rem; }
            .sign-in-sub { margin-bottom: 1rem; }
        }

        /* ── Motion-safety ── */
        @media (prefers-reduced-motion: reduce) {
            .blob, .logo-orb, .bg-fallback { animation: none !important; }
            .login-card, .brand-bar, .page-footer { animation: none !important; opacity: 1; transform: none; }
            .btn-login::before { display: none; }
        }
    </style>
</head>
<body>

<!-- Layer 0-2: animated background (always on — fallback ถ้า WebGL ไม่รองรับ) -->
<div class="bg-fallback"></div>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>
<div class="grain"></div>

<!-- Layer 3: Three.js clinic scene (DNA helix + cells + pulse rings + capsule) -->
<canvas id="scene3d" class="scene3d" aria-hidden="true"></canvas>

<!-- Layer 4: vignette overlay for form contrast -->
<div class="vignette"></div>

<!-- Brand pill -->
<div class="brand-bar">
    <div class="heart"><i class="fa-solid fa-heart"></i></div>
    <span>RSU Medical Clinic</span>
</div>

<!-- Main glass card -->
<div class="login-card" id="loginCard">

    <div class="logo-wrap">
        <div class="logo-orb">
            <i class="fa-solid fa-shield-heart"></i>
        </div>
        <div>
            <div class="logo-text-lg">Healthy Campus</div>
            <div class="logo-text-sm">Admin Portal</div>
        </div>
    </div>

    <p class="welcome-label">Welcome back · Clinic Administration</p>
    <h1 class="main-title">Sign in to<br>continue your work</h1>
    <p class="sign-in-sub">เข้าสู่ระบบจัดการกลางของคลินิก เพื่อดำเนินงานต่อ</p>

    <?php if ($error): ?>
    <div class="error-box">
        <i class="fa-solid fa-circle-exclamation mr-1"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
        <?php csrf_field(); ?>

        <div class="input-wrap">
            <i class="fa-regular fa-user icon-left"></i>
            <input type="text" name="username" placeholder="Username" required autocomplete="username">
        </div>

        <div class="input-wrap">
            <i class="fa-solid fa-lock icon-left"></i>
            <input type="password" name="password" id="pwField" placeholder="Password" required autocomplete="current-password">
            <button type="button" class="eye-btn" onclick="togglePw()" aria-label="Show password">
                <i class="fa-regular fa-eye" id="eyeIcon"></i>
            </button>
        </div>

        <div class="forgot-row">
            <a href="forgot_password.php?type=admin">ลืมรหัสผ่าน?</a>
        </div>

        <button type="submit" class="btn-login">
            Login <i class="fa-solid fa-arrow-right"></i>
        </button>
    </form>

    <div class="divider">หรือ</div>

    <a href="../google_login.php" class="btn-google">
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
        <a href="login.php" title="กลับไปยัง classic theme">
            <i class="fa-solid fa-rotate-left mr-1"></i>Classic theme
        </a>
    </div>

</div>

<!-- Footer -->
<div class="page-footer">
    <i class="fa-solid fa-location-dot"></i>
    Powered by RSU Healthy Campus Clinic Services · 3D Preview
</div>

<!-- Help -->
<div class="help-btn" title="ต้องการความช่วยเหลือ?">
    <i class="fa-solid fa-question"></i>
</div>

<script>
// ─────────────────────────────────────────────────────────────────────
// 🧬 Clinic Scene (Three.js) — ปรับแต่ง element ได้ผ่าน opts
//   • colorPrimary / colorAccent / colorCapsule = สีหลัก/รอง/แคปซูล
//   • particles  = จำนวน cells/molecules ลอยใน scene
//   • helixPairs = ความหนาแน่นของ DNA helix
//
//   ถ้าอุปกรณ์ไม่รองรับ WebGL หรือผู้ใช้เปิด prefers-reduced-motion
//   → scene ไม่โหลด, CSS fallback (gradient + blob) แสดงอย่างเดียว
// ─────────────────────────────────────────────────────────────────────
(function bootClinicScene() {
    function start() {
        if (!window.ClinicScene) return;
        const canvas = document.getElementById('scene3d');
        const handle = window.ClinicScene.init(canvas, {
            colorPrimary: 0x4dc98a,   // brand green
            colorAccent:  0x06c2a4,   // cyan-teal
            colorCapsule: 0xff6b6b,   // pill red
            particles:    180,
            helixPairs:   48,
        });
        if (handle) {
            // Wait one frame so first render completes before fade-in
            requestAnimationFrame(() => canvas.classList.add('is-ready'));
        }
    }
    // Both scripts defer-loaded → init after DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

// ── Password visibility toggle
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

// ── Subtle 3D parallax tilt บน card (กัน touch + reduced-motion)
(function initTilt() {
    const card = document.getElementById('loginCard');
    if (!card) return;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const touch  = window.matchMedia('(hover: none)').matches;
    if (reduce || touch) return;

    let raf = 0;
    document.addEventListener('mousemove', e => {
        if (raf) return;
        raf = requestAnimationFrame(() => {
            const rx = (e.clientY / window.innerHeight - 0.5) * -3;
            const ry = (e.clientX / window.innerWidth  - 0.5) *  4;
            card.style.transform = `perspective(1100px) rotateX(${rx}deg) rotateY(${ry}deg)`;
            raf = 0;
        });
    });
    document.addEventListener('mouseleave', () => {
        card.style.transform = '';
    });
})();
</script>
</body>
</html>
