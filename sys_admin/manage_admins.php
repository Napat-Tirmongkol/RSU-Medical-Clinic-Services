<?php
// admin/manage_admins.php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/auth.php'; // ตรวจสอบการล็อกอิน

$error = '';
$success = '';

$pdo = db();

// 1. จัดการการเพิ่ม/แก้ไข/ลบ Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'admin';
        $adminId  = $_POST['admin_id'] ?? null;

        if ($fullName && $username && $email) {
            try {
                if ($action === 'add') {
                    // ตรวจสอบ Username ซ้ำ
                    $check = $pdo->prepare("SELECT id FROM sys_admins WHERE username = ? OR email = ?");
                    $check->execute([$username, $email]);
                    if ($check->fetch()) {
                        $error = "ชื่อผู้ใช้ หรือ อีเมล นี้มีในระบบแล้ว";
                    } else {
                        $hashed = password_hash($password ?: '1234', PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO sys_admins (full_name, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$fullName, $username, $email, $hashed, $role]);
                        $success = "เพิ่มผู้ดูแลระบบเรียบร้อยแล้ว (รหัสผ่านเริ่มต้น: 1234)";
                    }
                } else {
                    // Edit
                    $sql = "UPDATE sys_admins SET full_name = ?, username = ?, email = ?, role = ? WHERE id = ?";
                    $params = [$fullName, $username, $email, $role, $adminId];
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    // ถ้ามีการกรอกรหัสผ่านใหม่
                    if (!empty($password)) {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE sys_admins SET password = ? WHERE id = ?")->execute([$hashed, $adminId]);
                    }
                    $success = "แก้ไขข้อมูลเรียบร้อยแล้ว";
                }
            } catch (PDOException $e) {
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        } else {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        }
    }

    if ($action === 'delete') {
        $adminId = $_POST['admin_id'] ?? null;
        if ($adminId == $_SESSION['admin_id']) {
            $error = "ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้";
        } else {
            $pdo->prepare("DELETE FROM sys_admins WHERE id = ?")->execute([$adminId]);
            $success = "ลบผู้ดูแลระบบเรียบร้อยแล้ว";
        }
    }
}

// 2. ดึงข้อมูล Admin ทั้งหมด
$admins = $pdo->query("SELECT * FROM sys_admins ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../admin/includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">จัดการผู้ดูแลระบบ (Admins)</h1>
            <p class="text-gray-500 mt-1">เพิ่ม แก้ไข หรือระงับสิทธิ์การเข้าถึงระบบจัดการ</p>
        </div>
        <button onclick="openAddModal()" class="bg-[#0052CC] hover:bg-blue-700 text-white px-6 py-3 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-lg shadow-blue-200 active:scale-95">
            <i class="fa-solid fa-user-plus"></i> เพิ่มแอดมินใหม่
        </button>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-xl mb-6 flex items-center gap-3 animate-in fade-in slide-in-from-top-2">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 p-4 rounded-xl mb-6 flex items-center gap-3 animate-in fade-in slide-in-from-top-2">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-[24px] shadow-xl shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-gray-50/50 border-b border-gray-100 text-[11px] uppercase tracking-[0.1em] font-bold text-gray-400">
                    <th class="px-8 py-5">ชื่อ-นามสกุล</th>
                    <th class="px-8 py-5">Username / Email</th>
                    <th class="px-8 py-5">สถานะ</th>
                    <th class="px-8 py-5 text-right">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($admins as $adm): ?>
                <tr class="hover:bg-gray-50/50 transition-colors group">
                    <td class="px-8 py-6">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center text-white font-bold shadow-sm">
                                <?= mb_substr($adm['full_name'], 0, 1) ?>
                            </div>
                            <div class="font-bold text-gray-800"><?= htmlspecialchars($adm['full_name']) ?></div>
                        </div>
                    </td>
                    <td class="px-8 py-6">
                        <div class="text-sm font-bold text-gray-900"><?= htmlspecialchars($adm['username']) ?></div>
                        <div class="text-xs text-gray-400 font-medium"><?= htmlspecialchars($adm['email']) ?></div>
                    </td>
                    <td class="px-8 py-6">
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $adm['role'] === 'admin' ? 'bg-blue-50 text-blue-600 border border-blue-100' : 'bg-amber-50 text-amber-600 border border-amber-100' ?>">
                            <?= $adm['role'] ?>
                        </span>
                    </td>
                    <td class="px-8 py-6 text-right">
                        <div class="flex justify-end gap-2">
                            <button onclick='openEditModal(<?= json_encode($adm) ?>)' class="w-9 h-9 flex items-center justify-center rounded-xl bg-gray-100 text-gray-500 hover:bg-blue-50 hover:text-blue-600 transition-all">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <?php if ($adm['id'] != $_SESSION['admin_id']): ?>
                            <form method="POST" onsubmit="return confirm('ยืนยันระบบความปลอดภัย: คุณต้องการลบผู้ดูแลระบบคนนี้ใช่หรือไม่?')" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                <?php csrf_field(); ?>
                                <button type="submit" class="w-9 h-9 flex items-center justify-center rounded-xl bg-gray-100 text-gray-500 hover:bg-red-50 hover:text-red-600 transition-all">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="adminModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-[32px] shadow-2xl w-full max-w-md overflow-hidden transform transition-all scale-95 opacity-0 duration-300 animate-in fade-in zoom-in-95">
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="admin_id" id="modalAdminId" value="">

            <div class="p-8 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h3 id="modalTitle" class="text-xl font-extrabold text-gray-900">เพิ่มแอดมินใหม่</h3>
                <button type="button" onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>

            <div class="p-8 space-y-5">
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">ชื่อ-นามสกุล</label>
                    <input type="text" name="full_name" id="modalFullName" required class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none font-medium">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Username</label>
                        <input type="text" name="username" id="modalUsername" required class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none font-medium text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Role</label>
                        <select name="role" id="modalRole" class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none font-medium text-sm">
                            <option value="admin">Administrator</option>
                            <option value="editor">Editor</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Email (Google Login)</label>
                    <input type="email" name="email" id="modalEmail" required class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none font-medium text-sm">
                </div>
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest">Password (เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</label>
                        <button type="button" onclick="generatePassword()" class="text-[#0052CC] text-[10px] font-bold uppercase tracking-wider hover:underline flex items-center gap-1">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> เจนรหัสอัตโนมัติ
                        </button>
                    </div>
                    <div class="relative">
                        <input type="password" name="password" id="modalPassword" class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none font-medium text-sm pr-12" placeholder="********">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i id="passwordToggleIcon" class="fa-solid fa-eye-slash text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-8 bg-gray-50/50 border-t border-gray-100 flex gap-3">
                <button type="button" onclick="closeModal()" class="flex-1 px-6 py-4 rounded-xl font-bold text-gray-500 hover:bg-gray-100 transition-colors">ยกเลิก</button>
                <button type="submit" class="flex-1 px-6 py-4 bg-[#0052CC] text-white rounded-xl font-bold hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all active:scale-95">บันทึกข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('adminModal');
    const modalContent = modal.querySelector('div');

    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'เพิ่มแอดมินใหม่';
        document.getElementById('modalAction').value = 'add';
        document.getElementById('modalAdminId').value = '';
        document.getElementById('modalFullName').value = '';
        document.getElementById('modalUsername').value = '';
        document.getElementById('modalEmail').value = '';
        document.getElementById('modalRole').value = 'admin';
        document.getElementById('modalPassword').placeholder = 'ใส่รหัสผ่าน (เริ่มต้น 1234)';
        
        modal.classList.remove('hidden');
        setTimeout(() => modalContent.classList.remove('scale-95', 'opacity-0'), 10);
    }

    function openEditModal(admin) {
        document.getElementById('modalTitle').innerText = 'แก้ไขผู้ดูแลระบบ';
        document.getElementById('modalAction').value = 'edit';
        document.getElementById('modalAdminId').value = admin.id;
        document.getElementById('modalFullName').value = admin.full_name;
        document.getElementById('modalUsername').value = admin.username;
        document.getElementById('modalEmail').value = admin.email;
        document.getElementById('modalRole').value = admin.role;
        document.getElementById('modalPassword').placeholder = 'เปลี่ยนรหัสผ่านใหม่ที่นี่';
        
        modal.classList.remove('hidden');
        setTimeout(() => modalContent.classList.remove('scale-95', 'opacity-0'), 10);
    }

    function closeModal() {
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            // รีเซ็ตสถานะรหัสผ่าน
            document.getElementById('modalPassword').type = 'password';
            document.getElementById('passwordToggleIcon').classList.replace('fa-eye', 'fa-eye-slash');
        }, 300);
    }

    function generatePassword() {
        const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+";
        let retVal = "";
        for (let i = 0; i < 12; ++i) {
            retVal += charset.charAt(Math.floor(Math.random() * charset.length));
        }
        const pwdInput = document.getElementById('modalPassword');
        pwdInput.value = retVal;
        pwdInput.type = 'text'; // แสดงรหัสที่เจนให้เห็น
        document.getElementById('passwordToggleIcon').classList.replace('fa-eye-slash', 'fa-eye');
    }

    function togglePasswordVisibility() {
        const pwdInput = document.getElementById('modalPassword');
        const icon = document.getElementById('passwordToggleIcon');
        if (pwdInput.type === 'password') {
            pwdInput.type = 'text';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        } else {
            pwdInput.type = 'password';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        }
    }
</script>

<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>

