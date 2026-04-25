<?php
// admin/manage_admins.php
if (session_status() === PHP_SESSION_NONE) {
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
        // Whitelist role ป้องกัน privilege escalation
        $allowedAdminRoles = ['admin', 'editor', 'superadmin'];
        $role    = in_array($_POST['role'] ?? '', $allowedAdminRoles, true) ? $_POST['role'] : 'admin';
        $adminId = $_POST['admin_id'] ?? null;

        if ($fullName && $username && $email) {
            try {
                if ($action === 'add') {
                    // บังคับกรอก password — ไม่มี default
                    if (empty($password)) {
                        $error = "กรุณาตั้งรหัสผ่านสำหรับ Admin ใหม่";
                    } elseif (strlen($password) < 8) {
                        $error = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
                    } else {
                    // ตรวจสอบ Username ซ้ำ
                    $check = $pdo->prepare("SELECT id FROM sys_admins WHERE username = ? OR email = ?");
                    $check->execute([$username, $email]);
                    if ($check->fetch()) {
                        $error = "ชื่อผู้ใช้ หรือ อีเมล นี้มีในระบบแล้ว";
                    } else {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO sys_admins (full_name, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$fullName, $username, $email, $hashed, $role]);
                        log_activity("Added Admin", "เพิ่มเจ้าหน้าที่ใหม่: $fullName ($username) [สิทธิ์: $role]");
                        $success = "เพิ่มผู้ดูแลระบบเรียบร้อยแล้ว";
                    }
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

// ── Staff CRUD ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $action = $_POST['action'] ?? '';

    // เพิ่ม Staff ใหม่
    if ($action === 'add_staff') {
        $fullName         = trim($_POST['sf_full_name'] ?? '');
        $username         = trim($_POST['sf_username'] ?? '');
        $password         = $_POST['sf_password'] ?? '';
        $role             = $_POST['sf_role'] ?? 'employee';
        $status           = $_POST['sf_status'] ?? 'active';
        $accessEborrow    = (int)($_POST['sf_access_eborrow']   ?? 0);
        $accessEcampaign  = (int)($_POST['sf_access_ecampaign'] ?? 0);
        $ecampaignRole    = $_POST['sf_ecampaign_role'] ?? 'admin';

        if ($fullName && $username && $password) {
            try {
                $check = $pdo->prepare("SELECT id FROM sys_staff WHERE username = ?");
                $check->execute([$username]);
                if ($check->fetch()) {
                    $error = "Username '$username' มีในระบบแล้ว";
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO sys_staff (username, password_hash, full_name, role, account_status, access_eborrow, access_ecampaign, ecampaign_role) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$username, $hashed, $fullName, $role, $status, $accessEborrow, $accessEcampaign, $ecampaignRole]);
                    log_activity("Added Staff", "เพิ่มเจ้าหน้าที่ใหม่: $fullName ($username) [role: $role]");
                    $success = "เพิ่มเจ้าหน้าที่เรียบร้อยแล้ว";
                }
            } catch (PDOException $e) {
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        } else {
            $error = "กรุณากรอกชื่อ, Username และ Password ให้ครบ";
        }
    }

    // แก้ไข Staff
    if ($action === 'edit_staff') {
        $staffId          = (int)($_POST['sf_id'] ?? 0);
        $fullName         = trim($_POST['sf_full_name'] ?? '');
        $username         = trim($_POST['sf_username'] ?? '');
        $password         = $_POST['sf_password'] ?? '';
        $role             = $_POST['sf_role'] ?? 'employee';
        $status           = $_POST['sf_status'] ?? 'active';
        $accessEborrow    = (int)($_POST['sf_access_eborrow']   ?? 0);
        $accessEcampaign  = (int)($_POST['sf_access_ecampaign'] ?? 0);
        $ecampaignRole    = $_POST['sf_ecampaign_role'] ?? 'admin';

        if ($staffId > 0 && $fullName && $username) {
            try {
                $pdo->prepare("UPDATE sys_staff SET full_name=?, username=?, role=?, account_status=?, access_eborrow=?, access_ecampaign=?, ecampaign_role=? WHERE id=?")
                    ->execute([$fullName, $username, $role, $status, $accessEborrow, $accessEcampaign, $ecampaignRole, $staffId]);
                if (!empty($password)) {
                    $pdo->prepare("UPDATE sys_staff SET password_hash=? WHERE id=?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $staffId]);
                }
                log_activity("Updated Staff", "แก้ไขข้อมูลเจ้าหน้าที่: $fullName ($username)");
                $success = "แก้ไขข้อมูลเรียบร้อยแล้ว";
            } catch (PDOException $e) {
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }
    }

    // ลบ Staff
    if ($action === 'delete_staff') {
        $staffId = (int)($_POST['sf_id'] ?? 0);
        if ($staffId > 0) {
            try {
                $pdo->prepare("DELETE FROM sys_staff WHERE id = ?")->execute([$staffId]);
                log_activity("Deleted Staff", "ลบเจ้าหน้าที่ ID: $staffId");
                $success = "ลบเจ้าหน้าที่เรียบร้อยแล้ว";
            } catch (PDOException $e) {
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }
    }
}

// 2. ดึงข้อมูล Admin ทั้งหมด
$admins = $pdo->query("SELECT * FROM sys_admins ORDER BY id DESC")->fetchAll();

// 3. ดึงข้อมูล Staff จาก sys_staff (รองรับ column ใหม่ด้วย IFNULL)
$staffList = [];
try {
    $staffList = $pdo->query("
        SELECT id, username, full_name, role, account_status, linked_line_user_id,
               IFNULL(access_eborrow,   1) AS access_eborrow,
               IFNULL(access_ecampaign, 0) AS access_ecampaign,
               IFNULL(ecampaign_role, 'admin') AS ecampaign_role
        FROM sys_staff ORDER BY role ASC, full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // access_eborrow column ยังไม่มี — เพิ่มอัตโนมัติแล้ว retry
    if (str_contains($e->getMessage(), 'access_eborrow')) {
        try {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_eborrow TINYINT(1) NOT NULL DEFAULT 1");
            $staffList = $pdo->query("
                SELECT id, username, full_name, role, account_status, linked_line_user_id,
                       access_eborrow,
                       IFNULL(access_ecampaign, 0) AS access_ecampaign,
                       IFNULL(ecampaign_role, 'admin') AS ecampaign_role
                FROM sys_staff ORDER BY role ASC, full_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) { /* ignore */ }
    }
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Governance - Central HUB</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .premium-input { width: 100%; padding: 0.75rem 1rem; border-radius: 12px; border: 1.5px solid #e5e7eb; outline: none; font-weight: 600; font-size: 0.875rem; transition: all 0.2s; }
        .premium-input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .premium-select { width: 100%; padding: 0.75rem 1rem; border-radius: 12px; border: 1.5px solid #e5e7eb; outline: none; font-weight: 600; font-size: 0.875rem; background: #fff; cursor: pointer; }
        .premium-role-card { border: 1.5px solid #f1f5f9; background: #fff; border-radius: 20px; transition: all 0.2s; overflow: hidden; }
        .premium-role-card.orange { border-color: #ffedd5; }
        .premium-role-card.blue { border-color: #dbeafe; }
        .role-icon { width: 40px; height: 40px; border-radius: 12px; background: #fff7ed; display: flex; items-center; justify-content: center; }
        .role-icon.blue { background: #eff6ff; }
        .premium-toggle { position: relative; display: inline-block; width: 44px; height: 24px; }
        .toggle-slider { position: absolute; cursor: pointer; inset: 0; background-color: #e2e8f0; transition: .4s; border-radius: 24px; }
        .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        input:checked + .toggle-slider { background-color: #2563eb; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }
    </style>
</head>
<body style="background:#f4f7f5; min-height:100vh;">
    <?php include __DIR__ . '/_partials/header.php'; ?>
    
    <div class="p-5 md:p-10 animate-slide-up">

<div class="max-w-6xl mx-auto px-4">
<?php 
// (A) PREPARE HEADER ACTIONS
$header_actions = '
<div class="flex items-center gap-3">
    <a href="index.php" class="bg-white border border-gray-100 hover:bg-gray-50 text-gray-500 px-5 py-3 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-sm active:scale-95 group">
        <i class="fa-solid fa-arrow-left group-hover:-translate-x-1 transition-transform"></i> Back to Portal
    </a>
    <button id="btnAddAdmin" onclick="openAddModal()"
        style="background:#2e9e63;color:#fff;padding:.75rem 1.5rem;border-radius:1rem;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;border:none;cursor:pointer;box-shadow:0 4px 14px rgba(46,158,99,.3)">
        <i class="fa-solid fa-user-plus"></i> เพิ่ม Admin
    </button>
    <button id="btnAddStaff" onclick="openAddStaffModal()"
        style="display:none;background:#2563eb;color:#fff;padding:.75rem 1.5rem;border-radius:1rem;font-weight:700;align-items:center;gap:.5rem;border:none;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.3)">
        <i class="fa-solid fa-user-plus"></i> เพิ่ม Staff
    </button>
</div>';
    <div class="mb-6 md:mb-10 flex flex-col md:flex-row md:justify-between md:items-end gap-4 md:gap-6 au d1">
        <div class="relative">
            <h1 class="text-xl sm:text-3xl md:text-4xl font-[950] text-gray-900 tracking-tight flex items-center gap-3 sm:gap-4">
                <div class="w-1.5 h-8 sm:w-2 sm:h-10 rounded-full shadow-lg flex-shrink-0" style="background:linear-gradient(180deg,#4f46e5,#818cf8);box-shadow:0 4px 10px rgba(79,70,229,.3)"></div>
                System Governance
            </h1>
            <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] mt-2 sm:mt-3 ml-5 sm:ml-6 opacity-60" style="color:#4f46e5">Hub บริหารจัดการ: เพิ่ม แก้ไข และควบคุมสิทธิ์การเข้าถึงระบบกลางของเจ้าหน้าที่</p>
        </div>
        <div class="flex flex-wrap gap-3 items-center ml-5 sm:ml-6 md:ml-0" style="position:relative;z-index:100">
            <?= $header_actions ?>
        </div>
    </div>
?>

    <!-- สรุปภาพรวม (Admin KPIs) -->
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
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Staff</p>
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
            <i class="fa-solid fa-id-badge"></i> Staff
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
                            <form method="POST" id="deleteAdminForm_<?= $adm['id'] ?>" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                <?php csrf_field(); ?>
                                <button type="button" onclick="confirmDeleteAdmin(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['full_name']), ENT_QUOTES) ?>')" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-rose-600 hover:border-rose-100 hover:shadow-lg hover:shadow-rose-50 transition-all active:scale-90 shadow-sm" title="Revoke Access">
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
    <div id="panelStaff" class="hidden bg-white rounded-[32px] shadow-2xl shadow-gray-200/40 border border-gray-100/50 overflow-hidden animate-slide-up" style="animation-delay:100ms">
        <div class="px-8 py-6 border-b border-gray-50 bg-gray-50/30 flex flex-wrap justify-between items-center gap-3">
            <div>
                <h3 class="font-black text-gray-900 text-sm uppercase tracking-widest">Staff Roster</h3>
                <p class="text-[10px] text-gray-400 font-bold mt-0.5">จัดการบทบาทและสิทธิ์การเข้าถึงระบบ</p>
            </div>
            <div class="relative group">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 group-focus-within:text-blue-500 transition-colors"></i>
                <input type="text" placeholder="ค้นหาเจ้าหน้าที่..." class="pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all w-56 shadow-sm" onkeyup="filterStaff(this.value)">
            </div>
        </div>

        <!-- Access legend -->
        <div class="px-8 py-3 bg-blue-50/40 border-b border-blue-50 flex flex-wrap gap-4 text-[10px] font-bold text-gray-500">
            <span class="flex items-center gap-1.5"><i class="fa-solid fa-toolbox text-orange-500"></i> e-Borrow Access</span>
            <span class="flex items-center gap-1.5"><i class="fa-solid fa-bullhorn text-blue-500"></i> e-Campaign Access</span>
            <span class="flex items-center gap-1.5"><i class="fa-brands fa-line text-green-500"></i> LINE Linked</span>
        </div>

        <?php if (empty($staffList)): ?>
        <div class="py-16 text-center text-gray-300">
            <i class="fa-solid fa-users-slash text-4xl mb-3 block"></i>
            <p class="text-sm font-bold">ยังไม่มีเจ้าหน้าที่ในระบบ</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[800px]" id="staffTable">
            <thead>
                <tr class="border-b border-gray-50 text-[10px] uppercase tracking-[0.2em] font-black text-gray-400">
                    <th class="px-6 py-4">เจ้าหน้าที่</th>
                    <th class="px-6 py-4">Username</th>
                    <th class="px-6 py-4">บทบาท e-Borrow</th>
                    <th class="px-6 py-4">สิทธิ์ระบบ</th>
                    <th class="px-6 py-4">สถานะ</th>
                    <th class="px-6 py-4 text-right">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50" id="staffTbody">
                <?php foreach ($staffList as $st):
                    $isActive        = ($st['account_status'] ?? 'active') === 'active';
                    $hasEborrow      = (int)($st['access_eborrow']   ?? 1) === 1;
                    $hasEcampaign    = (int)($st['access_ecampaign'] ?? 0) === 1;
                    $ecRole          = $st['ecampaign_role'] ?? 'admin';
                    $roleLabel = match($st['role'] ?? '') {
                        'admin'     => ['label' => 'Admin',    'css' => 'bg-purple-50 border-purple-200 text-purple-700', 'icon' => 'fa-bolt'],
                        'librarian' => ['label' => 'Librarian','css' => 'bg-blue-50 border-blue-200 text-blue-700',       'icon' => 'fa-book'],
                        'employee'  => ['label' => 'Employee', 'css' => 'bg-gray-50 border-gray-200 text-gray-600',       'icon' => 'fa-user-tie'],
                        default     => ['label' => ucfirst($st['role'] ?? 'Staff'), 'css' => 'bg-gray-50 border-gray-200 text-gray-600', 'icon' => 'fa-user'],
                    };
                    $ecRoleMap = ['superadmin' => 'Privileged', 'admin' => 'Admin', 'editor' => 'Editor'];
                ?>
                <tr class="hover:bg-gray-50/60 transition-all group/row">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm shrink-0 transition-all"
                                style="<?= $isActive ? 'background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff' : 'background:#f3f4f6;color:#9ca3af' ?>">
                                <?= mb_substr($st['full_name'] ?? '?', 0, 1) ?>
                            </div>
                            <div>
                                <div class="font-black text-gray-900 text-sm"><?= htmlspecialchars($st['full_name'] ?? '—') ?></div>
                                <div class="text-[10px] text-gray-400">ID: <?= (int)$st['id'] ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="font-mono text-sm font-bold text-gray-700"><?= htmlspecialchars($st['username'] ?? '—') ?></span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border <?= $roleLabel['css'] ?> text-[10px] font-black uppercase tracking-wide">
                            <i class="fa-solid <?= $roleLabel['icon'] ?> text-[9px]"></i>
                            <?= $roleLabel['label'] ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-1.5">
                            <?php if ($hasEborrow): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[9px] font-black bg-orange-50 border border-orange-200 text-orange-600">
                                <i class="fa-solid fa-toolbox"></i> e-Borrow
                            </span>
                            <?php endif; ?>
                            <?php if ($hasEcampaign): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[9px] font-black bg-blue-50 border border-blue-200 text-blue-600">
                                <i class="fa-solid fa-bullhorn"></i> e-Camp <span class="opacity-70">(<?= htmlspecialchars($ecRoleMap[$ecRole] ?? $ecRole) ?>)</span>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($st['linked_line_user_id'])): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[9px] font-black bg-green-50 border border-green-200 text-green-600">
                                <i class="fa-brands fa-line"></i> LINE
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($isActive): ?>
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-[10px] font-black uppercase">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Active
                        </div>
                        <?php else: ?>
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-50 border border-gray-200 text-gray-400 text-[10px] font-black uppercase">
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                            <?= htmlspecialchars($st['account_status'] ?? 'inactive') ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex justify-end gap-2 opacity-0 translate-x-2 group-hover/row:opacity-100 group-hover/row:translate-x-0 transition-all duration-200">
                            <button onclick='openEditStaffModal(<?= json_encode($st) ?>)'
                                class="w-9 h-9 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-blue-600 hover:border-blue-100 shadow-sm transition-all">
                                <i class="fa-solid fa-pen-nib text-xs"></i>
                            </button>
                            <form method="POST" id="deleteStaffForm_<?= (int)$st['id'] ?>" style="display:inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_staff">
                                <input type="hidden" name="sf_id" value="<?= (int)$st['id'] ?>">
                                <button type="button" onclick="confirmDeleteStaff(<?= (int)$st['id'] ?>, '<?= htmlspecialchars(addslashes($st['full_name'] ?? ''), ENT_QUOTES) ?>')" class="w-9 h-9 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-rose-600 hover:border-rose-100 shadow-sm transition-all">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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
                    <div class="relative w-full">
                        <input type="password" name="password" id="modalPassword" class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none font-medium text-sm pr-12" placeholder="********">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 px-4 flex items-center text-gray-400 hover:text-gray-600 transition-colors">
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

<!-- ═══ STAFF MODAL ═══ -->
<div id="staffModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm items-center justify-center p-4" style="z-index:200;display:none">
    <div id="staffModalBox" class="bg-white rounded-[32px] shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0 duration-300">
        <form method="POST" id="staffForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="sfAction" value="add_staff">
            <input type="hidden" name="sf_id" id="sfId" value="">

            <!-- Header -->
            <div class="px-8 py-6 border-b border-gray-100 flex justify-between items-center bg-white/80 backdrop-blur-md sticky top-0 z-10">
                <div>
                    <h3 id="sfTitle" class="text-xl font-[900] text-gray-900 tracking-tight">เพิ่มเจ้าหน้าที่</h3>
                    <p class="text-[11px] text-gray-400 font-bold uppercase tracking-wider mt-0.5">กำหนดบทบาทและสิทธิ์การเข้าถึงระบบ</p>
                </div>
                <button type="button" onclick="closeStaffModal()"
                        class="w-10 h-10 flex items-center justify-center rounded-2xl hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-all duration-200 active:scale-90">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="px-8 py-6 space-y-8 max-h-[72vh] overflow-y-auto custom-scrollbar bg-white/50">

                <!-- ── Section A: Account Info ── -->
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-4">ข้อมูลบัญชี</p>
                    <div class="space-y-4">
                        <!-- Full name -->
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1.5">ชื่อ-นามสกุล <span class="text-red-400">*</span></label>
                            <input type="text" name="sf_full_name" id="sfFullName" required class="premium-input" placeholder="ระบุชื่อจริง-นามสกุล">
                        </div>

                        <!-- Username + Status -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1.5">Username <span class="text-red-400">*</span></label>
                                <input type="text" name="sf_username" id="sfUsername" required class="premium-input" placeholder="ID เจ้าหน้าที่">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1.5">สถานะบัญชี</label>
                                <div class="relative">
                                    <select name="sf_status" id="sfStatus" class="premium-input appearance-none">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="disabled">Disabled</option>
                                    </select>
                                    <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 pointer-events-none text-[10px]"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Password -->
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label class="text-xs font-bold text-gray-500" id="sfPwLabel">Password</label>
                                <button type="button" onclick="genSfPw()" class="text-[11px] font-black text-blue-500 hover:text-blue-700 flex items-center gap-1 transition-colors uppercase tracking-tight">
                                    <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i> สร้างอัตโนมัติ
                                </button>
                            </div>
                            <div class="relative w-full">
                                <input type="password" name="sf_password" id="sfPassword" class="premium-input pr-12" placeholder="••••••••">
                                <button type="button" onclick="toggleSfPw()" class="absolute inset-y-0 right-0 px-4 flex items-center text-gray-400 hover:text-gray-600 transition-colors">
                                    <i id="sfEyeIcon" class="fa-solid fa-eye-slash text-sm"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-100"></div>

                <!-- ── Section B: System Access ── -->
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-4">สิทธิ์การเข้าถึงระบบ</p>
                    <div class="space-y-4">
                        <!-- e-Borrow (toggleable) -->
                        <div class="premium-role-card orange" id="eborrowBox">
                            <div class="flex items-center justify-between px-5 py-4">
                                <div class="flex items-center gap-4">
                                    <div class="role-icon">
                                        <i class="fa-solid fa-toolbox text-orange-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-gray-900 leading-none">e-Borrow & Inventory</p>
                                        <p class="text-[10px] font-bold text-gray-400 mt-1 uppercase tracking-tighter">ระบบยืม-คืนอุปกรณ์</p>
                                    </div>
                                </div>
                                <label class="premium-toggle">
                                    <input type="checkbox" name="sf_access_eborrow" id="sfAccessEborrow" value="1" onchange="toggleEborrowRole(this.checked)" class="sr-only peer">
                                    <div class="toggle-slider"></div>
                                </label>
                            </div>
                            <div id="sfEborrowRoleWrap" style="display:none" class="animate-in fade-in slide-in-from-top-2 duration-300">
                                <div class="role-config px-5 pb-5 border-t border-orange-50">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.15em] mt-3 mb-2">บทบาทในระบบนี้</label>
                                    <div class="relative">
                                        <select name="sf_role" id="sfRole" class="premium-select">
                                            <option value="employee">Employee — ผู้ยืมทั่วไป</option>
                                            <option value="librarian">Librarian — เจ้าหน้าที่จ่ายของ</option>
                                            <option value="admin">Admin — ผู้ดูแลคลัง</option>
                                        </select>
                                        <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 pointer-events-none text-[10px]"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- e-Campaign (toggleable) -->
                        <div class="premium-role-card blue" id="ecampaignBox">
                            <div class="flex items-center justify-between px-5 py-4">
                                <div class="flex items-center gap-4">
                                    <div class="role-icon blue">
                                        <i class="fa-solid fa-bullhorn text-blue-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-gray-900 leading-none">e-Campaign</p>
                                        <p class="text-[10px] font-bold text-gray-400 mt-1 uppercase tracking-tighter">ระบบจัดการแคมเปญ</p>
                                    </div>
                                </div>
                                <label class="premium-toggle">
                                    <input type="checkbox" name="sf_access_ecampaign" id="sfAccessEcamp" value="1" onchange="toggleEcampRole(this.checked)" class="sr-only peer">
                                    <div class="toggle-slider"></div>
                                </label>
                            </div>
                            <div id="sfEcampRoleWrap" style="display:none" class="animate-in fade-in slide-in-from-top-2 duration-300">
                                <div class="role-config px-5 pb-5 border-t border-blue-50">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.15em] mt-3 mb-2">บทบาทในระบบนี้</label>
                                    <div class="relative">
                                        <select name="sf_ecampaign_role" id="sfEcampRole" class="premium-select blue">
                                            <option value="editor">Editor — จัดการเนื้อหา</option>
                                            <option value="admin">Admin — ผู้ดูแลแคมเปญ</option>
                                            <option value="superadmin">Superadmin — ควบคุมทั้งหมด</option>
                                        </select>
                                        <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 pointer-events-none text-[10px]"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-8 py-6 border-t border-gray-100 flex gap-4 bg-gray-50/80 backdrop-blur-md">
                <button type="button" onclick="closeStaffModal()" class="px-8 py-3.5 rounded-2xl font-black text-gray-500 bg-white border border-gray-200 hover:bg-gray-100 hover:text-gray-700 transition-all duration-200 active:scale-95 text-sm uppercase tracking-widest shadow-sm">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 py-3.5 rounded-2xl font-black text-white text-sm uppercase tracking-widest active:scale-95 transition-all duration-300 shadow-xl" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);box-shadow:0 8px 25px rgba(37,99,235,0.35)">
                    <i class="fa-solid fa-save mr-2"></i> บันทึกข้อมูล
                </button>
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
        document.getElementById('modalPassword').placeholder = 'กรุณาตั้งรหัสผ่านอย่างน้อย 8 ตัว *';
        
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
            document.getElementById('btnAddAdmin').style.display = 'inline-flex';
            document.getElementById('btnAddStaff').style.display = 'none';
        } else {
            btnStaff.style.cssText  = ACTIVE_S + 'background:#2563eb;color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.3);';
            btnAdmins.style.cssText = INACTIVE_S;
            badgeStaff.style.cssText  = 'background:rgba(255,255,255,.25);color:#fff;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900';
            badgeAdmins.style.cssText = 'background:#f3f4f6;color:#6b7280;font-size:.65rem;padding:.15rem .5rem;border-radius:.4rem;font-weight:900';
            document.getElementById('btnAddAdmin').style.display = 'none';
            document.getElementById('btnAddStaff').style.display = 'inline-flex';
        }
    }

    /* ── Staff Modal ─────────────────────────────────────── */
    const staffModal    = document.getElementById('staffModal');
    const staffModalBox = document.getElementById('staffModalBox');

    function openAddStaffModal() {
        document.getElementById('sfTitle').innerText  = 'เพิ่มเจ้าหน้าที่ใหม่';
        document.getElementById('sfAction').value     = 'add_staff';
        document.getElementById('sfId').value         = '';
        document.getElementById('sfFullName').value   = '';
        document.getElementById('sfUsername').value   = '';
        document.getElementById('sfPassword').value   = '';
        document.getElementById('sfPassword').placeholder = 'กรุณากรอก Password *';
        document.getElementById('sfStatus').value     = 'active';
        document.getElementById('sfAccessEborrow').checked = false;
        document.getElementById('sfRole').value       = 'employee';
        document.getElementById('sfAccessEcamp').checked   = false;
        document.getElementById('sfEcampRole').value  = 'admin';
        toggleEborrowRole(false);
        toggleEcampRole(false);
        document.getElementById('sfPwLabel').innerText = 'Password *';
        staffModal.style.display = 'flex';
        setTimeout(() => { staffModalBox.classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function openEditStaffModal(st) {
        document.getElementById('sfTitle').innerText  = 'แก้ไขข้อมูลเจ้าหน้าที่';
        document.getElementById('sfAction').value     = 'edit_staff';
        document.getElementById('sfId').value         = st.id;
        document.getElementById('sfFullName').value   = st.full_name || '';
        document.getElementById('sfUsername').value   = st.username || '';
        document.getElementById('sfPassword').value   = '';
        document.getElementById('sfPassword').placeholder = 'เว้นว่างไว้หากไม่เปลี่ยน';
        document.getElementById('sfStatus').value     = st.account_status || 'active';
        const hasEborrow = parseInt(st.access_eborrow) === 1;
        document.getElementById('sfAccessEborrow').checked = hasEborrow;
        document.getElementById('sfRole').value       = st.role || 'employee';
        const hasEcamp = parseInt(st.access_ecampaign) === 1;
        document.getElementById('sfAccessEcamp').checked = hasEcamp;
        document.getElementById('sfEcampRole').value  = st.ecampaign_role || 'admin';
        toggleEborrowRole(hasEborrow);
        toggleEcampRole(hasEcamp);
        document.getElementById('sfPwLabel').innerText = 'Password (เว้นว่างไว้หากไม่เปลี่ยน)';
        staffModal.style.display = 'flex';
        setTimeout(() => { staffModalBox.classList.remove('scale-95', 'opacity-0'); }, 10);
    }

    function closeStaffModal() {
        staffModalBox.classList.add('scale-95', 'opacity-0');
        setTimeout(() => { staffModal.style.display = 'none'; }, 300);
    }

    function toggleEborrowRole(checked) {
        var wrap = document.getElementById('sfEborrowRoleWrap');
        var box  = document.getElementById('eborrowBox');
        wrap.style.display = checked ? 'block' : 'none';
        if (checked) {
            box.style.borderColor     = '#f97316';
            box.style.backgroundColor = '#fff7ed';
            box.style.boxShadow       = '0 10px 15px -3px rgba(249,115,22,0.15)';
        } else {
            box.style.borderColor     = '#ffedd5';
            box.style.backgroundColor = '#fffaf5';
            box.style.boxShadow       = 'none';
        }
    }

    function toggleEcampRole(checked) {
        var wrap = document.getElementById('sfEcampRoleWrap');
        var box  = document.getElementById('ecampaignBox');
        wrap.style.display     = checked ? 'block'   : 'none';
        
        if (checked) {
            box.style.borderColor = '#3b82f6';
            box.style.backgroundColor = '#f0f7ff';
            box.style.boxShadow = '0 10px 15px -3px rgba(59, 130, 246, 0.15)';
        } else {
            box.style.borderColor = '#f3f4f6';
            box.style.backgroundColor = '#ffffff';
            box.style.boxShadow = 'none';
        }
    }

    function genSfPw() {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        let pw = '';
        for (let i = 0; i < 12; i++) pw += chars[Math.floor(Math.random() * chars.length)];
        const el = document.getElementById('sfPassword');
        el.value = pw;
        el.type = 'text';
        document.getElementById('sfEyeIcon').className = 'fa-solid fa-eye text-sm';
    }

    function toggleSfPw() {
        const el = document.getElementById('sfPassword');
        const ic = document.getElementById('sfEyeIcon');
        el.type = el.type === 'password' ? 'text' : 'password';
        ic.className = el.type === 'text' ? 'fa-solid fa-eye text-sm' : 'fa-solid fa-eye-slash text-sm';
    }

    // close modal on backdrop click
    staffModal.addEventListener('click', e => { if (e.target === staffModal) closeStaffModal(); });

    /* ── SweetAlert2 Delete Confirmations ─────────────────── */
    function confirmDeleteAdmin(id, name) {
        Swal.fire({
            title: 'ลบผู้ดูแลระบบ?',
            html: `ต้องการลบ <b>${name}</b> ออกจากระบบ?<br><span style="font-size:.8rem;color:#9ca3af">การดำเนินการนี้ไม่สามารถยกเลิกได้</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-trash-can mr-1"></i> ลบออก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            reverseButtons: true,
            focusCancel: true,
            customClass: { popup: 'swal-font' },
        }).then(result => {
            if (result.isConfirmed) document.getElementById('deleteAdminForm_' + id).submit();
        });
    }

    function confirmDeleteStaff(id, name) {
        Swal.fire({
            title: 'ลบเจ้าหน้าที่?',
            html: `ต้องการลบ <b>${name}</b> ออกจากระบบ?<br><span style="font-size:.8rem;color:#9ca3af">การดำเนินการนี้ไม่สามารถยกเลิกได้</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-trash-can mr-1"></i> ลบออก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            reverseButtons: true,
            focusCancel: true,
            customClass: { popup: 'swal-font' },
        }).then(result => {
            if (result.isConfirmed) document.getElementById('deleteStaffForm_' + id).submit();
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
    .swal-font { font-family: 'Prompt', sans-serif !important; }

    /* ── Premium Staff Modal Styles ────────────────────────── */
    #staffModalBox {
        background-color: #ffffff;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .premium-input {
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: #f9fafb;
        border: 1.5px solid #e5e7eb;
        border-radius: 1rem;
        font-size: 0.875rem;
        font-weight: 500;
        color: #111827;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        outline: none;
    }

    .premium-input:focus {
        background-color: #ffffff;
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        transform: translateY(-1px);
    }

    .premium-role-card {
        border-radius: 1.5rem;
        border: 1.5px solid #f3f4f6;
        background-color: #ffffff;
        transition: all 0.3s ease;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .premium-role-card.orange { border-color: #ffedd5; background-color: #fffaf5; }
    .premium-role-card.blue { border-color: #dbeafe; background-color: #f8fbff; }

    .premium-role-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .role-icon {
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #fff7ed;
        font-size: 1.25rem;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
    }
    .role-icon.blue { background-color: #eff6ff; }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.625rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .status-badge.orange { background-color: #fff; color: #ea580c; border: 1px solid #ffedd5; }

    .premium-select {
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: #ffffff;
        border: 1.5px solid #e5e7eb;
        border-radius: 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        appearance: none;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .premium-select:focus {
        border-color: #f97316;
        box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.1);
        outline: none;
    }
    .premium-select.blue:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }

    .premium-toggle {
        position: relative;
        display: inline-flex;
        align-items: center;
        cursor: pointer;
    }

    .toggle-slider {
        width: 3rem;
        height: 1.5rem;
        background-color: #e5e7eb;
        border-radius: 9999px;
        position: relative;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
    }

    .toggle-slider::after {
        content: '';
        position: absolute;
        top: 0.125rem;
        left: 0.125rem;
        width: 1.25rem;
        height: 1.25rem;
        background-color: #ffffff;
        border-radius: 9999px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .premium-toggle input:checked + .toggle-slider {
        background-color: #3b82f6;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.05), 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .premium-toggle input:checked + .toggle-slider::after {
        transform: translateX(1.5rem);
    }

    .custom-scrollbar::-webkit-scrollbar { width: 5px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #d1d5db; }
</style>

</div>
<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>

