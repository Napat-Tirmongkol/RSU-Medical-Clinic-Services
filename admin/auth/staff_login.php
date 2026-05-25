<?php
// admin/staff_login.php — e-Campaign Staff Login (sys_staff)
require_once __DIR__ . '/../../includes/session_guard.php';
start_secure_session();

// ถ้า login แล้ว ข้ามไปหน้า index
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ../../portal/index.php');
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

// IP-based brute-force protection: 5 failed attempts → 5 minute lockout.
// Replaces session-only rate limit which was bypassable by rotating cookies.
rate_limit_ip_check_or_redirect('staff_login', 5, 300);

$error = '';

// แสดง error จาก redirect
$errCode = $_GET['error'] ?? '';
if ($errCode === '1')        $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
elseif ($errCode === 'disabled')  $error = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
elseif ($errCode === 'no_access') $error = 'บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าใช้งาน e-Campaign';
elseif ($errCode === 'db')        $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ในภายหลัง';
elseif ($errCode === 'too_many_attempts') {
    $wait = max(1, (int)($_GET['wait'] ?? 300));
    $mins = max(1, (int)ceil($wait / 60));
    $error = "พยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ {$mins} นาทีแล้วลองใหม่";
}

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
                SELECT s.id, s.username, s.password_hash, s.full_name, s.role, s.account_status,
                       s.position_id,
                       p.flags AS position_flags,
                       IFNULL(s.access_ecampaign, 0) AS access_ecampaign,
                       IFNULL(s.ecampaign_role, 'editor') AS ecampaign_role,
                       IFNULL(s.access_eborrow, 0) AS access_eborrow,
                       IFNULL(s.access_insurance, 0) AS access_insurance,
                       IFNULL(s.access_system_logs, 0) AS access_system_logs,
                       IFNULL(s.access_site_settings, 0) AS access_site_settings,
                       IFNULL(s.access_registry, 0) AS access_registry,
                       IFNULL(s.access_edms, 0) AS access_edms,
                       IFNULL(s.access_edms_sla_admin, 0) AS access_edms_sla_admin,
                       IFNULL(s.access_ai, 0) AS access_ai,
                       IFNULL(s.access_consumables, 0) AS access_consumables,
                       IFNULL(s.access_asset, 0) AS access_asset,
                       IFNULL(s.access_finance, 0) AS access_finance,
                       IFNULL(s.access_scholarship, 0) AS access_scholarship,
                       IFNULL(s.access_dashboard_admin, 0) AS access_dashboard_admin,
                       IFNULL(s.access_monthly_report, 0) AS access_monthly_report,
                       IFNULL(s.access_nurse_productivity, 0) AS access_nurse_productivity,
                       IFNULL(s.access_daily_summary, 0) AS access_daily_summary,
                       IFNULL(s.access_director_view, 0) AS access_director_view,
                       IFNULL(s.access_identity, 0) AS access_identity,
                       s.department_id
                FROM sys_staff s
                LEFT JOIN sys_staff_positions p ON p.id = s.position_id
                WHERE s.username = :uname
                LIMIT 1
            ");
            $stmt->execute([':uname' => $username]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);

            // Live link: ถ้าผูก position อยู่ ใช้ flag จาก position แทน flag ใน sys_staff
            if ($staff && !empty($staff['position_id']) && !empty($staff['position_flags'])) {
                $posFlags = json_decode($staff['position_flags'], true) ?: [];
                foreach ([
                    'access_ecampaign','access_eborrow','access_insurance','access_system_logs',
                    'access_site_settings','access_registry','access_edms','access_edms_sla_admin',
                    'access_ai','access_consumables','access_asset','access_finance','access_scholarship',
                    'access_dashboard_admin','access_monthly_report','access_nurse_productivity','access_daily_summary','access_director_view',
                    'access_identity'
                ] as $flagKey) {
                    $staff[$flagKey] = (int)($posFlags[$flagKey] ?? 0);
                }
            }

            // Mask username-enumeration timing oracle — ถ้า user ไม่มีจริง ก็ยัง
            // ต้องเสีย CPU เทียบเท่า password_verify() กัน attacker วัดเวลาตอบ
            // dummy hash: bcrypt valid + cost ตรงกับ production (PHP 8.x default = 12)
            // regenerate: php -r "echo password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);"
            $dummyHash = '$2y$12$IVQJwsWr.gGbN7/sS3Z9..5ie9lN55IVDUWV.sr70DjTvB0jRbVWu';
            $passwordOk = password_verify($password, $staff ? $staff['password_hash'] : $dummyHash);

            $ipForLog = $_SERVER['REMOTE_ADDR'] ?? '?';

            if ($staff && $passwordOk) {

                // Whitelist account_status — เฉพาะ 'active' เท่านั้นที่ login ได้
                // (สถานะอื่นจาก ENUM: disabled / suspended / inactive จะถูก block ทั้งหมด)
                $accountStatus = strtolower(trim((string)($staff['account_status'] ?? 'active')));
                if ($accountStatus !== 'active') {
                    log_activity('staff_login_blocked',
                        "Blocked login (status={$accountStatus}): username='{$staff['username']}' from IP {$ipForLog}",
                        (int)$staff['id']);
                    $error = 'บัญชีนี้ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
                } elseif (!(int)$staff['access_ecampaign'] && !(int)$staff['access_eborrow'] && !(int)$staff['access_insurance'] && !(int)$staff['access_registry'] && !(int)$staff['access_edms'] && !(int)$staff['access_ai'] && !(int)$staff['access_consumables'] && !(int)$staff['access_asset'] && !(int)$staff['access_finance'] && !(int)$staff['access_scholarship'] && !(int)$staff['access_dashboard_admin'] && !(int)$staff['access_monthly_report'] && !(int)$staff['access_nurse_productivity'] && !(int)$staff['access_daily_summary'] && !(int)$staff['access_director_view'] && !(int)$staff['access_identity']) {
                    log_activity('staff_login_blocked',
                        "Blocked login (no access flags): username='{$staff['username']}' from IP {$ipForLog}",
                        (int)$staff['id']);
                    $error = 'บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าใช้งานระบบใดๆ กรุณาติดต่อผู้ดูแลระบบ';
                } else {
                    // Whitelist ecampaign_role ป้องกัน privilege escalation
                    $allowedRoles = ['admin', 'editor', 'superadmin'];
                    $ecRole = in_array($staff['ecampaign_role'], $allowedRoles, true)
                        ? $staff['ecampaign_role']
                        : 'editor';

                    session_regenerate_id(true);
                    rate_limit_ip_clear('staff_login');

                    $_SESSION['admin_logged_in']       = true;
                    $_SESSION['admin_id']              = (int)$staff['id'];
                    $_SESSION['admin_username']        = $staff['full_name'] ?: $staff['username'];
                    $_SESSION['admin_role']            = $ecRole;
                    $_SESSION['is_ecampaign_staff']    = true;   // flag: ไม่ใช่ portal admin
                    
                    // Extended Access Flags
                    $_SESSION['access_ecampaign']      = (int)$staff['access_ecampaign'];
                    $_SESSION['access_eborrow']        = (int)$staff['access_eborrow'];
                    $_SESSION['access_insurance']      = (int)$staff['access_insurance'];
                    $_SESSION['access_system_logs']    = (int)$staff['access_system_logs'];
                    $_SESSION['access_site_settings']  = (int)$staff['access_site_settings'];
                    $_SESSION['access_registry']       = (int)$staff['access_registry'];
                    $_SESSION['access_edms']           = (int)$staff['access_edms'];
                    $_SESSION['access_edms_sla_admin'] = (int)$staff['access_edms_sla_admin'];
                    $_SESSION['access_ai']             = (int)$staff['access_ai'];
                    $_SESSION['access_consumables']    = (int)$staff['access_consumables'];
                    $_SESSION['access_asset']          = (int)$staff['access_asset'];
                    $_SESSION['access_finance']        = (int)$staff['access_finance'];
                    $_SESSION['access_scholarship']    = (int)$staff['access_scholarship'];
                    $_SESSION['access_dashboard_admin'] = (int)$staff['access_dashboard_admin'];
                    $_SESSION['access_monthly_report']  = (int)$staff['access_monthly_report'];
                    $_SESSION['access_nurse_productivity'] = (int)$staff['access_nurse_productivity'];
                    $_SESSION['access_daily_summary']      = (int)$staff['access_daily_summary'];
                    $_SESSION['access_director_view']   = (int)$staff['access_director_view'];
                    $_SESSION['access_identity']        = (int)$staff['access_identity'];
                    $_SESSION['department_id']          = $staff['department_id'] !== null ? (int)$staff['department_id'] : null;

                    $_SESSION['_admin_last_activity']  = time();

                    log_activity('staff_login', "เจ้าหน้าที่ '{$staff['full_name']}' (Username: {$staff['username']}) เข้าสู่ระบบ e-Campaign", (int)$staff['id']);

                    header('Location: ../../portal/index.php');
                    exit;
                }
            } else {
                rate_limit_ip_hit('staff_login', 5, 300);
                // SIEM/intrusion-detection trail — log แม้ไม่รู้ว่า username ถูกหรือผิด
                // (ไม่ leak ว่ามี user อยู่จริงไหม เพราะ message เดียวกัน)
                log_activity('staff_login_failed',
                    "Failed login attempt: username='" . mb_substr($username, 0, 100) . "' from IP {$ipForLog}",
                    null);
                $error = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
            }
        } catch (PDOException $e) {
            error_log('[staff_login] ' . $e->getMessage());
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
    <link rel="stylesheet" href="../../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/rsufont.css">
    <style>
        * { font-family: 'Sarabun', sans-serif; box-sizing: border-box; }

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
            font-family: 'Sarabun', sans-serif; color: #111;
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
            font-family: 'Sarabun', sans-serif;
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

            <div class="flex justify-end mb-4" style="margin-top:-0.5rem;">
                <a href="forgot_password.php?type=staff" class="text-xs font-semibold text-gray-400 hover:text-indigo-600 transition-colors">
                    ลืมรหัสผ่าน?
                </a>
            </div>

            <button type="submit" class="btn-login">
                <i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ
            </button>
        </form>

        <div class="bottom-links">
            <a href="login.php">
                <i class="fa-solid fa-shield-halved mr-1"></i>Portal Admin Login
            </a>
            <a href="../../index.php">
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
