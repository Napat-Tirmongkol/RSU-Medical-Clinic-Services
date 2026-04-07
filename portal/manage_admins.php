<?php
// admin/manage_admins.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/auth.php'; // ตรวจสอบการล็อกอิน

// เฉพาะ superadmin เท่านั้นที่จัดการ admin คนอื่นได้
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    header('Location: index.php');
    exit;
}

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
                        log_activity("Added Admin", "เพิ่มเจ้าหน้าที่ใหม่: $fullName ($username) [สิทธิ์: $role]");
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
                    log_activity("Updated Admin", "แก้ไขข้อมูลเจ้าหน้าที่: $fullName ($username)");
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
            log_activity("Deleted Admin", "ลบเจ้าหน้าที่ ID: $adminId เรียบร้อยแล้ว");
            $success = "ลบผู้ดูแลระบบเรียบร้อยแล้ว";
        }
    }
}

// 2. ดึงข้อมูล Admin ทั้งหมด
$admins = $pdo->query("SELECT * FROM sys_admins ORDER BY id DESC")->fetchAll();

// 3. ดึงข้อมูล Staff จาก sys_staff
$staffList = [];
try {
    $staffList = $pdo->query("SELECT id, username, full_name, role, account_status, linked_line_user_id FROM sys_staff ORDER BY role ASC, full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist */ }

require_once __DIR__ . '/../admin/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4">
<?php 
// (A) PREPARE HEADER ACTIONS
$header_actions = '
<div class="flex items-center gap-3">
    <a href="index.php" class="bg-white border border-gray-100 hover:bg-gray-50 text-gray-500 px-5 py-3 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-sm active:scale-95 group">
        <i class="fa-solid fa-arrow-left group-hover:-translate-x-1 transition-transform"></i> Back to Portal
    </a>
    <button onclick="openAddModal()" class="bg-[#0052CC] hover:bg-blue-700 text-white px-6 py-3 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-lg shadow-blue-200 active:scale-95 group">
        <i class="fa-solid fa-user-plus group-hover:rotate-12 transition-transform"></i> เพิ่มเจ้าหน้าที่ใหม่
    </button>
</div>';
renderPageHeader("System Governance", "Hub บริหารจัดการ: เพิ่ม แก้ไข และควบคุมสิทธิ์การเข้าถึงระบบกลางของเจ้าหน้าที่", $header_actions); 
?>

    <!-- 📊 สรุปภาพรวม (Admin KPIs) -->
    <?php
    $super_count  = count(array_filter($admins, fn($a) => ($a['role'] ?? '') === 'superadmin'));
    $staff_active = count(array_filter($staffList, fn($s) => ($s['account_status'] ?? '') === 'active'));
    ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 animate-slide-up">
        <div class="bg-gradient-to-br from-white to-gray-50/50 p-5 rounded-[24px] border border-gray-100 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-green-100 rounded-2xl flex items-center justify-center text-green-600 shrink-0">
                    <i class="fa-solid fa-users-gear text-lg"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">System Admins</p>
                    <p class="text-2xl font-black text-gray-900"><?= count($admins) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-gradient-to-br from-white to-gray-50/50 p-5 rounded-[24px] border border-gray-100 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-purple-100 rounded-2xl flex items-center justify-center text-purple-600 shrink-0">
                    <i class="fa-solid fa-shield-halved text-lg"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Privileged</p>
                    <p class="text-2xl font-black text-gray-900"><?= $super_count ?></p>
                </div>
            </div>
        </div>
        <div class="bg-gradient-to-br from-white to-gray-50/50 p-5 rounded-[24px] border border-gray-100 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-blue-100 rounded-2xl flex items-center justify-center text-blue-600 shrink-0">
                    <i class="fa-solid fa-id-badge text-lg"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">e-Borrow Staff</p>
                    <p class="text-2xl font-black text-gray-900"><?= count($staffList) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-gradient-to-br from-white to-gray-50/50 p-5 rounded-[24px] border border-gray-100 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-emerald-100 rounded-2xl flex items-center justify-center text-emerald-600 shrink-0">
                    <i class="fa-solid fa-circle-dot text-lg"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Staff Active</p>
                    <p class="text-2xl font-black text-gray-900"><?= $staff_active ?></p>
                </div>
            </div>
        </div>
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

    <!-- Tabs -->
    <div class="flex gap-2 mb-4 animate-slide-up" style="animation-delay:80ms">
        <button id="tabAdmins" onclick="switchTab('admins')"
            style="padding:.6rem 1.25rem;border-radius:.75rem;font-size:.875rem;font-weight:900;transition:all .2s;background:#2e9e63;color:#fff;box-shadow:0 4px 12px rgba(46,158,99,.3);border:none;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem">
            <i class="fa-solid fa-users-gear"></i> System Admins
            <span style="background:rgba(255,255,255,.25);color:#fff;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900"><?= count($admins) ?></span>
        </button>
        <button id="tabStaff" onclick="switchTab('staff')"
            style="padding:.6rem 1.25rem;border-radius:.75rem;font-size:.875rem;font-weight:900;transition:all .2s;background:#fff;color:#6b7280;border:1.5px solid #e5e7eb;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem">
            <i class="fa-solid fa-id-badge"></i> e-Borrow Staff
            <span style="background:#f3f4f6;color:#6b7280;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900"><?= count($staffList) ?></span>
        </button>
    </div>

    <!-- ═══ TABLE: ADMINS ═══ -->
    <div id="panelAdmins" class="bg-white rounded-[32px] shadow-2xl shadow-gray-200/40 border border-gray-100/50 overflow-hidden animate-slide-up" style="animation-delay: 100ms;">
        <div class="px-8 py-6 border-b border-gray-50 bg-gray-50/30 flex justify-between items-center">
            <h3 class="font-black text-gray-900 text-sm uppercase tracking-widest">Administrative Roster</h3>
            <div class="relative group">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 group-focus-within:text-green-500 transition-colors"></i>
                <input type="text" placeholder="ค้นหาแอดมิน..." class="pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-green-100 focus:border-green-400 transition-all w-64 shadow-sm" onkeyup="filterAdmins(this.value)">
            </div>
        </div>
        <table class="w-full text-left border-collapse" id="adminTable">
            <thead>
                <tr class="bg-white/50 border-b border-gray-50 text-[10px] uppercase tracking-[0.2em] font-black text-gray-400">
                    <th class="px-8 py-5">Officer Details</th>
                    <th class="px-8 py-5">Account Credentials</th>
                    <th class="px-8 py-5">Privilege Level</th>
                    <th class="px-8 py-5 text-right">Operations</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($admins as $adm): ?>
                <tr class="hover:bg-blue-50/30 transition-all group/row">
                    <td class="px-8 py-6">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-gray-100 to-gray-200 text-gray-400 rounded-2xl flex items-center justify-center font-black shadow-sm group-hover/row:from-blue-500 group-hover/row:to-indigo-600 group-hover/row:text-white transition-all duration-500">
                                <?= mb_substr($adm['full_name'], 0, 1) ?>
                            </div>
                            <div>
                                <div class="font-black text-gray-900 group-hover/row:text-blue-700 transition-colors uppercase tracking-tight"><?= htmlspecialchars($adm['full_name']) ?></div>
                                <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Online
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-6 text-sm">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-user-circle text-gray-300 text-xs"></i>
                                <span class="font-black text-gray-800 tracking-tight"><?= htmlspecialchars($adm['username']) ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-envelope text-gray-300 text-[10px]"></i>
                                <span class="text-xs text-gray-400 font-bold tracking-tight"><?= htmlspecialchars($adm['email']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-6">
                        <?php
                            $r = $adm['role'] ?? 'admin';
                            if ($r === 'superadmin') {
                                $badgeCls = 'bg-purple-50 border-purple-200 text-purple-700';
                                $icon     = 'fa-bolt';
                                $label    = 'System Privileged';
                            } elseif ($r === 'editor') {
                                $badgeCls = 'bg-rose-50 border-rose-100 text-rose-600';
                                $icon     = 'fa-crown';
                                $label    = 'Editor';
                            } else {
                                $badgeCls = 'bg-blue-50 border-blue-100 text-[#0052CC]';
                                $icon     = 'fa-user-shield';
                                $label    = 'Administrator';
                            }
                        ?>
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl border <?= $badgeCls ?> shadow-sm">
                            <i class="fa-solid <?= $icon ?> text-[10px]"></i>
                            <span class="text-[10px] font-black uppercase tracking-widest"><?= $label ?></span>
                        </div>
                    </td>
                    <td class="px-8 py-6 text-right">
                        <div class="flex justify-end gap-2 translate-x-4 opacity-0 group-hover/row:translate-x-0 group-hover/row:opacity-100 transition-all duration-300">
                            <button onclick='openEditModal(<?= json_encode($adm) ?>)' class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-blue-600 hover:border-blue-100 hover:shadow-lg hover:shadow-blue-50 transition-all active:scale-90 shadow-sm" title="Edit Master">
                                <i class="fa-solid fa-pen-nib text-sm"></i>
                            </button>
                            <?php if ($adm['id'] != $_SESSION['admin_id']): ?>
                            <form method="POST" onsubmit="return confirm('CRITICAL ACTION: ยืนยันการลบทรัพยากรแอดมินคนนี้?')" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                <?php csrf_field(); ?>
                                <button type="submit" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-rose-600 hover:border-rose-100 hover:shadow-lg hover:shadow-rose-50 transition-all active:scale-90 shadow-sm" title="Revoke Access">
                                    <i class="fa-solid fa-trash-can text-sm"></i>
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

    <!-- ═══ TABLE: STAFF ═══ -->
    <div id="panelStaff" class="hidden bg-white rounded-[32px] shadow-2xl shadow-gray-200/40 border border-gray-100/50 overflow-hidden animate-slide-up" style="animation-delay: 100ms;">
        <div class="px-8 py-6 border-b border-gray-50 bg-gray-50/30 flex justify-between items-center">
            <h3 class="font-black text-gray-900 text-sm uppercase tracking-widest">
                e-Borrow Staff Roster
                <span class="ml-2 text-[10px] font-bold text-gray-400 normal-case">(ข้อมูลจาก sys_staff)</span>
            </h3>
            <div class="relative group">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 group-focus-within:text-blue-500 transition-colors"></i>
                <input type="text" placeholder="ค้นหาเจ้าหน้าที่..." class="pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all w-64 shadow-sm" onkeyup="filterStaff(this.value)">
            </div>
        </div>
        <?php if (empty($staffList)): ?>
        <div class="py-16 text-center text-gray-300">
            <i class="fa-solid fa-users-slash text-4xl mb-3 block"></i>
            <p class="text-sm font-bold">ไม่พบข้อมูล Staff หรือตาราง sys_staff ยังไม่มีในระบบ</p>
        </div>
        <?php else: ?>
        <table class="w-full text-left border-collapse" id="staffTable">
            <thead>
                <tr class="bg-white/50 border-b border-gray-50 text-[10px] uppercase tracking-[0.2em] font-black text-gray-400">
                    <th class="px-8 py-5">Staff Details</th>
                    <th class="px-8 py-5">Username</th>
                    <th class="px-8 py-5">Role</th>
                    <th class="px-8 py-5">Status</th>
                    <th class="px-8 py-5">LINE</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50" id="staffTbody">
                <?php foreach ($staffList as $st):
                    $isActive = ($st['account_status'] ?? '') === 'active';
                    $roleLabel = match($st['role'] ?? '') {
                        'admin'     => ['label' => 'Admin',    'css' => 'bg-purple-50 border-purple-200 text-purple-700', 'icon' => 'fa-bolt'],
                        'librarian' => ['label' => 'Librarian','css' => 'bg-blue-50 border-blue-200 text-blue-700',       'icon' => 'fa-book'],
                        default     => ['label' => ucfirst($st['role'] ?? 'Staff'), 'css' => 'bg-gray-50 border-gray-200 text-gray-600', 'icon' => 'fa-user-tie'],
                    };
                ?>
                <tr class="hover:bg-blue-50/20 transition-all group/row">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-4">
                            <div class="w-11 h-11 rounded-2xl flex items-center justify-center font-black text-sm shadow-sm transition-all duration-300
                                <?= $isActive ? 'bg-gradient-to-br from-blue-400 to-blue-600 text-white' : 'bg-gray-100 text-gray-400' ?>">
                                <?= mb_substr($st['full_name'] ?? '?', 0, 1) ?>
                            </div>
                            <div>
                                <div class="font-black text-gray-900 tracking-tight"><?= htmlspecialchars($st['full_name'] ?? '—') ?></div>
                                <div class="text-[10px] text-gray-400 font-bold">ID: <?= (int)$st['id'] ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-user-circle text-gray-300 text-xs"></i>
                            <span class="font-black text-gray-800 text-sm"><?= htmlspecialchars($st['username'] ?? '—') ?></span>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl border <?= $roleLabel['css'] ?> shadow-sm">
                            <i class="fa-solid <?= $roleLabel['icon'] ?> text-[10px]"></i>
                            <span class="text-[10px] font-black uppercase tracking-widest"><?= $roleLabel['label'] ?></span>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <?php if ($isActive): ?>
                        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest">Active</span>
                        </div>
                        <?php else: ?>
                        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-50 border border-gray-200 text-gray-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest"><?= htmlspecialchars($st['account_status'] ?? 'inactive') ?></span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-5">
                        <?php if (!empty($st['linked_line_user_id'])): ?>
                        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-green-50 border border-green-200 text-green-700">
                            <i class="fa-brands fa-line text-sm"></i>
                            <span class="text-[10px] font-black uppercase tracking-widest">Linked</span>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-300 text-xs font-bold">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div id="adminModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4" style="z-index:200">
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
                            <option value="superadmin">System Privileged</option>
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

    function filterAdmins(val) {
        const rows = document.querySelectorAll('#adminTable tbody tr');
        val = val.toLowerCase();
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
        });
    }

    function filterStaff(val) {
        const rows = document.querySelectorAll('#staffTbody tr');
        val = val.toLowerCase();
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
        });
    }

    const ACTIVE_S  = 'padding:.6rem 1.25rem;border-radius:.75rem;font-size:.875rem;font-weight:900;transition:all .2s;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem;';
    const INACTIVE_S = 'padding:.6rem 1.25rem;border-radius:.75rem;font-size:.875rem;font-weight:900;transition:all .2s;background:#fff;color:#6b7280;border:1.5px solid #e5e7eb;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem;';

    function switchTab(tab) {
        const isAdmins = tab === 'admins';
        document.getElementById('panelAdmins').classList.toggle('hidden', !isAdmins);
        document.getElementById('panelStaff').classList.toggle('hidden', isAdmins);

        const btnAdmins   = document.getElementById('tabAdmins');
        const btnStaff    = document.getElementById('tabStaff');
        const badgeAdmins = btnAdmins.querySelector('span');
        const badgeStaff  = btnStaff.querySelector('span');

        if (isAdmins) {
            btnAdmins.style.cssText = ACTIVE_S + 'background:#2e9e63;color:#fff;box-shadow:0 4px 12px rgba(46,158,99,.3);';
            btnStaff.style.cssText  = INACTIVE_S;
            badgeAdmins.style.cssText = 'background:rgba(255,255,255,.25);color:#fff;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900';
            badgeStaff.style.cssText  = 'background:#f3f4f6;color:#6b7280;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900';
        } else {
            btnStaff.style.cssText  = ACTIVE_S + 'background:#2563eb;color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.3);';
            btnAdmins.style.cssText = INACTIVE_S;
            badgeStaff.style.cssText  = 'background:rgba(255,255,255,.25);color:#fff;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900';
            badgeAdmins.style.cssText = 'background:#f3f4f6;color:#6b7280;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900';
        }
    }
</script>

<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>

