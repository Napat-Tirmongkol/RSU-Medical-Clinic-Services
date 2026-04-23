<?php
// portal/profile.php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../admin/auth/login.php');
    exit;
}

require_once __DIR__ . '/../config.php';
$pdo = db();
$adminId = $_SESSION['admin_id'];

$success_msg = '';
$error_msg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name)) {
        $error_msg = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif (!empty($new_password) && $new_password !== $confirm_password) {
        $error_msg = 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        try {
            if (!empty($new_password)) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE sys_staff SET full_name = :fname, password_hash = :pwd WHERE id = :id");
                $stmt->execute([':fname' => $full_name, ':pwd' => $hash, ':id' => $adminId]);
            } else {
                $stmt = $pdo->prepare("UPDATE sys_staff SET full_name = :fname WHERE id = :id");
                $stmt->execute([':fname' => $full_name, ':id' => $adminId]);
            }
            $_SESSION['admin_username'] = $full_name;
            $_SESSION['full_name'] = $full_name; // update e_Borrow session as well
            $success_msg = 'อัปเดตข้อมูลโปรไฟล์เรียบร้อยแล้ว';
        } catch (PDOException $e) {
            $error_msg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
        }
    }
}

// Fetch current user details
$stmt = $pdo->prepare("SELECT username, full_name, role, ecampaign_role FROM sys_staff WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $adminId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("ไม่พบข้อมูลผู้ใช้งาน");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการโปรไฟล์ | Staff Portal</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'rsufont', 'Prompt', sans-serif; box-sizing: border-box; }
        body { background-color: #f8fafc; color: #334155; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; padding: 2rem 1rem; }
        .profile-container { width: 100%; max-width: 600px; background: #ffffff; border-radius: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 2.5rem; border: 1px solid #e2e8f0; }
        .input-field { width: 100%; padding: 0.8rem 1.2rem; border: 1px solid #cbd5e1; border-radius: 12px; margin-top: 0.4rem; transition: all 0.2s; font-size: 1rem; }
        .input-field:focus { border-color: #4f46e5; outline: none; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .btn-save { background: #4f46e5; color: white; padding: 0.9rem 2rem; border-radius: 12px; font-weight: bold; width: 100%; text-align: center; border: none; cursor: pointer; transition: background 0.2s; font-size: 1.1rem; margin-top: 1.5rem; }
        .btn-save:hover { background: #4338ca; }
        .back-btn { display: inline-flex; items-center; gap: 0.5rem; color: #64748b; margin-bottom: 2rem; font-weight: 500; text-decoration: none; transition: color 0.2s; }
        .back-btn:hover { color: #4f46e5; }
        .badge { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 9999px; font-size: 0.75rem; font-weight: bold; background: #e0e7ff; color: #4338ca; text-transform: uppercase; letter-spacing: 0.05em; }
    </style>
</head>
<body>

<div class="profile-container">
    <a href="javascript:history.back()" class="back-btn">
        <i class="fas fa-arrow-left"></i> กลับไปหน้าก่อนหน้า
    </a>

    <div class="flex items-center gap-4 mb-8 pb-6 border-b border-slate-100">
        <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-2xl shadow-inner">
            <i class="fas fa-user-shield"></i>
        </div>
        <div>
            <h1 class="text-2xl font-black text-slate-800">จัดการโปรไฟล์</h1>
            <p class="text-slate-500 text-sm mt-1">อัปเดตข้อมูลส่วนตัวและรหัสผ่านสำหรับเข้าสู่ระบบ</p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl mb-6 flex items-center gap-3">
            <i class="fas fa-check-circle text-emerald-500"></i> <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl mb-6 flex items-center gap-3">
            <i class="fas fa-exclamation-circle text-rose-500"></i> <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-5">
            <label class="block text-sm font-bold text-slate-700">ชื่อผู้ใช้งาน (Username) <span class="badge ml-2"><?php echo htmlspecialchars($user['ecampaign_role']); ?></span></label>
            <input type="text" class="input-field bg-slate-50 text-slate-500" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
            <p class="text-xs text-slate-400 mt-1"><i class="fas fa-info-circle"></i> ไม่สามารถแก้ไข Username ได้</p>
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold text-slate-700">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
            <input type="text" name="full_name" class="input-field" value="<?php echo htmlspecialchars($user['full_name']); ?>" required placeholder="ระบุชื่อ-นามสกุลของคุณ">
        </div>

        <div class="mt-8 mb-4">
            <h3 class="text-lg font-bold text-slate-800 border-b border-slate-100 pb-2"><i class="fas fa-lock text-slate-400 mr-2"></i>เปลี่ยนรหัสผ่าน</h3>
            <p class="text-xs text-slate-500 mt-2">หากไม่ต้องการเปลี่ยนรหัสผ่าน ให้เว้นว่างช่องด้านล่างนี้ไว้</p>
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold text-slate-700">รหัสผ่านใหม่</label>
            <input type="password" name="new_password" class="input-field" placeholder="เว้นว่างไว้หากไม่ต้องการเปลี่ยน">
        </div>

        <div class="mb-6">
            <label class="block text-sm font-bold text-slate-700">ยืนยันรหัสผ่านใหม่</label>
            <input type="password" name="confirm_password" class="input-field" placeholder="พิมพ์รหัสผ่านใหม่อีกครั้ง">
        </div>

        <button type="submit" class="btn-save">
            <i class="fas fa-save mr-2"></i> บันทึกข้อมูล
        </button>
    </form>
</div>

</body>
</html>
