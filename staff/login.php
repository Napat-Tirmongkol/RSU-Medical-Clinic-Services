<?php
// staff/login.php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/rate_limit.php';

rate_limit_check('staff_login', 5, 300, 'login.php');

$redirect = $_GET['redirect'] ?? 'index.php';
// Whitelist: ให้ redirect ได้เฉพาะ path ใน /staff/ เท่านั้น
if (!preg_match('#^/?(staff/)#', $redirect)) {
    $redirect = 'index.php';
}

if (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true) {
    header('Location: ' . $redirect);
    exit;
}

$error = '';
if (($_GET['error'] ?? '') === 'too_many_attempts') {
    $wait = max(1, (int)($_GET['wait'] ?? 300));
    $error = 'พยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ ' . ceil($wait / 60) . ' นาทีแล้วลองใหม่';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'กรุณากรอกเลขประจำตัวและรหัสผ่าน';
    } else {
        try {
            $pdo  = db();
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, full_name, account_status
                FROM sys_staff
                WHERE username = :uname
                LIMIT 1
            ");
            $stmt->execute([':uname' => $username]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($staff && password_verify($password, $staff['password_hash'])) {
                if ($staff['account_status'] === 'disabled') {
                    $error = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
                } else {
                    rate_limit_clear('staff_login');
                    session_regenerate_id(true);
                    $_SESSION['staff_logged_in'] = true;
                    $_SESSION['staff_id']        = (int)$staff['id'];
                    $_SESSION['staff_name']      = $staff['full_name'] ?: $staff['username'];
                    $_SESSION['staff_username']  = $staff['username'];
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                rate_limit_hit('staff_login', 5, 300);
                $error = 'เลขประจำตัว หรือ รหัสผ่านไม่ถูกต้อง';
            }
        } catch (PDOException $e) {
            $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login — ระบบสแกนเช็คอิน</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background: #f0f4ff; }

        .input-field {
            width: 100%;
            padding: .9rem 1rem .9rem 2.8rem;
            background: #f8faff;
            border: 1.5px solid #e5e7eb;
            border-radius: 1rem;
            font-size: .95rem;
            font-family: 'Prompt', sans-serif;
            color: #111;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .input-field:focus {
            border-color: #0052CC;
            box-shadow: 0 0 0 3px rgba(0,82,204,.1);
            background: #fff;
        }
        .input-icon {
            position: absolute; left: .9rem; top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; font-size: .9rem; pointer-events: none;
        }
        .eye-btn {
            position: absolute; right: .85rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #9ca3af; padding: .2rem;
        }
        .eye-btn:hover { color: #0052CC; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4">

<div class="w-full max-w-sm">
    <!-- Icon + Title -->
    <div class="text-center mb-7">
        <div class="w-20 h-20 bg-[#0052CC] rounded-2xl flex items-center justify-center text-3xl text-white mx-auto mb-5 shadow-lg shadow-blue-200">
            <i class="fa-solid fa-qrcode"></i>
        </div>
        <h1 class="text-2xl font-black text-gray-900">จุดลงทะเบียน</h1>
        <p class="text-sm text-gray-500 mt-1">เข้าสู่ระบบด้วยเลขประจำตัวเจ้าหน้าที่</p>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-100 p-7">

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm py-2.5 px-4 rounded-xl mb-5 font-semibold flex items-center gap-2">
            <i class="fa-solid fa-circle-exclamation shrink-0"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php csrf_field(); ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <!-- Username -->
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">
                    เลขประจำตัว (7 หลัก)
                </label>
                <div class="relative">
                    <i class="fa-solid fa-id-card input-icon"></i>
                    <input type="text" name="username" inputmode="numeric"
                        placeholder="เช่น 1234567"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        required autocomplete="username"
                        maxlength="20"
                        class="input-field">
                </div>
            </div>

            <!-- Password -->
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">
                    รหัสผ่าน
                </label>
                <div class="relative">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" name="password" id="pwField"
                        placeholder="รหัสผ่าน"
                        required autocomplete="current-password"
                        class="input-field pr-10">
                    <button type="button" class="eye-btn" onclick="togglePw()">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit"
                class="w-full bg-[#0052CC] hover:bg-blue-700 active:scale-[.98] text-white font-bold py-3.5 rounded-2xl transition-all shadow-md shadow-blue-200 mt-2 flex items-center justify-center gap-2">
                <i class="fa-solid fa-right-to-bracket"></i>
                เข้าสู่ระบบสแกน
            </button>
        </form>
    </div>

    <p class="text-center text-xs text-gray-400 mt-5">
        ติดต่อผู้ดูแลระบบหากลืมรหัสผ่าน
    </p>
</div>

<script>
function togglePw() {
    const f = document.getElementById('pwField');
    const i = document.getElementById('eyeIcon');
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
}
</script>
</body>
</html>
