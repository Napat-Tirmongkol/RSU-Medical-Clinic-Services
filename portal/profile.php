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
    <title>จัดการโปรไฟล์ - Central HUB</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'rsufont', 'Prompt', sans-serif; box-sizing: border-box; }
        .profile-container { width: 100%; max-width: 600px; background: #ffffff; border-radius: 28px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.05); padding: 2.5rem; border: 1px solid #eef2f6; }
        .input-field { width: 100%; padding: 0.8rem 1.2rem; border: 1.5px solid #e2e8f0; border-radius: 14px; margin-top: 0.4rem; transition: all 0.2s; font-size: 1rem; font-weight: 600; color: #1e293b; background: #f8fafc; }
        .input-field:focus { border-color: #10b981; outline: none; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); background: #fff; }
        .btn-save { background: linear-gradient(135deg, #2e9e63, #10b981); color: white; padding: 1rem 2rem; border-radius: 14px; font-weight: 900; width: 100%; text-align: center; border: none; cursor: pointer; transition: all 0.3s; font-size: 1.1rem; margin-top: 1.5rem; box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.4); }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 15px 25px -5px rgba(16, 185, 129, 0.5); }
        .btn-save:active { transform: scale(0.98); }
        .back-btn { display: inline-flex; items-center; gap: 0.5rem; color: #64748b; margin-bottom: 2rem; font-weight: 800; text-decoration: none; transition: color 0.2s; font-size: 0.9rem; }
        .back-btn:hover { color: #10b981; }
        .badge { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 10px; font-size: 0.7rem; font-weight: 900; background: #f0fdf4; color: #166534; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid #dcfce7; }
    </style>
</head>
<body style="background:#f4f7f5; min-height:100vh;">
    
    <?php include __DIR__ . '/_partials/header.php'; ?>

    <div style="display: flex; justify-content: center; align-items: flex-start; padding: 3rem 1.5rem;" class="animate-slide-up">
        <div class="profile-container">
            <a href="index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> กลับไปยัง Central HUB
            </a>

            <div class="flex items-center gap-5 mb-8 pb-8 border-b border-slate-100">
                <div class="w-16 h-16 rounded-2xl bg-emerald-50 flex items-center justify-center text-emerald-600 text-2xl shadow-sm border border-emerald-100/50">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">จัดการโปรไฟล์</h1>
                    <p class="text-slate-500 text-sm font-bold mt-1">ตั้งค่าข้อมูลส่วนตัวและความปลอดภัย</p>
                </div>
            </div>

            <?php if ($success_msg): ?>
                <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 px-5 py-4 rounded-2xl mb-6 flex items-center gap-3 font-bold text-sm">
                    <i class="fas fa-check-circle text-emerald-500"></i> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="bg-rose-50 border border-rose-100 text-rose-700 px-5 py-4 rounded-2xl mb-6 flex items-center gap-3 font-bold text-sm">
                    <i class="fas fa-exclamation-circle text-rose-500"></i> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-6">
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">ชื่อผู้ใช้งาน (Username)</label>
                    <div class="flex items-center gap-2">
                        <input type="text" class="input-field bg-slate-50 text-slate-400 cursor-not-allowed" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                        <span class="badge"><?php echo htmlspecialchars($user['ecampaign_role']); ?></span>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                    <input type="text" name="full_name" class="input-field" value="<?php echo htmlspecialchars($user['full_name']); ?>" required placeholder="ระบุชื่อ-นามสกุลของคุณ">
                </div>

                <div class="mt-10 mb-6 pt-6 border-t border-slate-100">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-1"><i class="fas fa-lock text-emerald-500 mr-2"></i>เปลี่ยนรหัสผ่าน</h3>
                    <p class="text-[11px] text-slate-400 font-bold">หากไม่ต้องการเปลี่ยนรหัสผ่าน ให้เว้นว่างช่องด้านล่างไว้</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">รหัสผ่านใหม่</label>
                        <input type="password" name="new_password" class="input-field" placeholder="••••••••">
                    </div>

                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" name="confirm_password" class="input-field" placeholder="••••••••">
                    </div>
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save mr-2"></i> บันทึกข้อมูลโปรไฟล์
                </button>
            </form>
        </div>
    </div>

</body>
</html>
