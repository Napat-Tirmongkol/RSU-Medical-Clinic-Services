<?php
// admin/login.php
session_start();

// ถ้า Login อยู่แล้ว ให้ข้ามไปหน้า Dashboard เลย
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config.php';

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

// แสดง timeout message
if (($_GET['reason'] ?? '') === 'timeout') {
    $error = 'เซสชันหมดอายุเนื่องจากไม่มีการใช้งานนาน 2 ชั่วโมง กรุณาเข้าสู่ระบบใหม่';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // ดึงข้อมูลจากฐานข้อมูล
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM sys_admins WHERE username = :uname LIMIT 1");
        $stmt->execute([':uname' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            // ✅ ล็อกอินสำเร็จ
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['full_name'] ?: $admin['username'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'];

            session_regenerate_id(true);
            header('Location: ../portal/index.php');
            exit;
        } else {
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
    <title>Admin Login - E-Vax</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Prompt', sans-serif; } </style>
</head>
<body class="bg-gray-50 h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-sm border border-gray-100">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-[#0052CC]">E-Vax Admin</h1>
            <p class="text-sm text-gray-500 mt-1">ระบบจัดการหลังบ้าน</p>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-50 text-red-500 text-sm p-3 rounded-lg mb-4 text-center border border-red-100">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <?php csrf_field(); ?>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Username</label>
                <input type="text" name="username" required class="w-full p-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] outline-none transition-all">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full p-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] outline-none transition-all">
            </div>
            <button type="submit" class="w-full bg-[#0052CC] text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition-colors shadow-sm active:scale-[0.98]">
                เข้าสู่ระบบด้วยรหัสผ่าน
            </button>
        </form>

        <!-- Divider -->
        <div class="flex items-center gap-3 my-6">
            <div class="flex-1 h-px bg-gray-100"></div>
            <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">หรือ</span>
            <div class="flex-1 h-px bg-gray-100"></div>
        </div>

        <!-- Google Login Button -->
        <a href="google_login.php" 
           target="_top"
           class="w-full flex items-center justify-center gap-3 bg-white border border-gray-200 hover:bg-gray-50 active:bg-gray-100 text-gray-700 font-bold py-3 px-6 rounded-xl transition-all duration-200 shadow-sm active:scale-[0.98] hover:shadow-md">
            <svg class="w-5 h-5" viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"></path>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"></path>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"></path>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"></path>
                <path fill="none" d="M0 0h48v48H0z"></path>
            </svg>
            <span class="text-[14px]">เข้าสู่ระบบด้วย Google</span>
        </a>
    </div>
</body>
</html>
