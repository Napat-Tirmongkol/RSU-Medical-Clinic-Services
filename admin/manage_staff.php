<?php
// admin/manage_staff.php — จัดการ Staff สำหรับ e-Campaign
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

// เฉพาะ admin และ superadmin เท่านั้น
$myRole = $_SESSION['admin_role'] ?? '';
if (!in_array($myRole, ['admin', 'superadmin'], true)) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$error   = '';
$success = '';

// ── CRUD ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $action = $_POST['action'] ?? '';

    // เพิ่ม Staff ใหม่
    if ($action === 'add') {
        $fullName        = trim($_POST['full_name'] ?? '');
        $username        = trim($_POST['username'] ?? '');
        $password        = $_POST['password'] ?? '';
        $role            = $_POST['role'] ?? 'employee';
        $status          = $_POST['account_status'] ?? 'active';
        $accessEc        = (int)($_POST['access_ecampaign'] ?? 0);
        $ecRole          = $_POST['ecampaign_role'] ?? 'editor';

        $allowedRoles    = ['admin', 'employee', 'editor', 'librarian'];
        $allowedEcRoles  = ['admin', 'editor', 'superadmin'];
        $role   = in_array($role, $allowedRoles, true)   ? $role   : 'employee';
        $ecRole = in_array($ecRole, $allowedEcRoles, true) ? $ecRole : 'editor';

        if (!$fullName || !$username || !$password) {
            $error = 'กรุณากรอกชื่อ, Username และ Password ให้ครบ';
        } elseif (strlen($password) < 8) {
            $error = 'Password ต้องมีอย่างน้อย 8 ตัวอักษร';
        } else {
            try {
                $dup = $pdo->prepare("SELECT id FROM sys_staff WHERE username = ?");
                $dup->execute([$username]);
                if ($dup->fetch()) {
                    $error = "Username '$username' มีในระบบแล้ว";
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO sys_staff
                        (username, password_hash, full_name, role, account_status, access_ecampaign, ecampaign_role)
                        VALUES (?,?,?,?,?,?,?)")
                        ->execute([$username, $hashed, $fullName, $role, $status, $accessEc, $ecRole]);
                    log_activity('staff_add', "เพิ่มเจ้าหน้าที่ใหม่: $fullName ($username) [e-Campaign: " . ($accessEc ? $ecRole : 'ไม่มีสิทธิ์') . "]");
                    $success = "เพิ่มเจ้าหน้าที่ '$fullName' เรียบร้อยแล้ว";
                }
            } catch (PDOException $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }

    // แก้ไข Staff
    if ($action === 'edit') {
        $staffId  = (int)($_POST['staff_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'employee';
        $status   = $_POST['account_status'] ?? 'active';
        $accessEc = (int)($_POST['access_ecampaign'] ?? 0);
        $ecRole   = $_POST['ecampaign_role'] ?? 'editor';

        $allowedRoles   = ['admin', 'employee', 'editor', 'librarian'];
        $allowedEcRoles = ['admin', 'editor', 'superadmin'];
        $role   = in_array($role, $allowedRoles, true)    ? $role   : 'employee';
        $ecRole = in_array($ecRole, $allowedEcRoles, true) ? $ecRole : 'editor';

        if ($staffId <= 0 || !$fullName || !$username) {
            $error = 'ข้อมูลไม่ครบถ้วน';
        } else {
            try {
                $pdo->prepare("UPDATE sys_staff
                    SET full_name=?, username=?, role=?, account_status=?, access_ecampaign=?, ecampaign_role=?
                    WHERE id=?")
                    ->execute([$fullName, $username, $role, $status, $accessEc, $ecRole, $staffId]);
                if (!empty($password)) {
                    if (strlen($password) < 8) {
                        $error = 'Password ต้องมีอย่างน้อย 8 ตัวอักษร';
                    } else {
                        $pdo->prepare("UPDATE sys_staff SET password_hash=? WHERE id=?")
                            ->execute([password_hash($password, PASSWORD_DEFAULT), $staffId]);
                    }
                }
                if (!$error) {
                    log_activity('staff_edit', "แก้ไขเจ้าหน้าที่: $fullName ($username)");
                    $success = "แก้ไขข้อมูล '$fullName' เรียบร้อยแล้ว";
                }
            } catch (PDOException $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }

    // ลบ Staff
    if ($action === 'delete') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        // ป้องกันลบตัวเอง (ถ้าเป็น staff login)
        if ($staffId > 0 && !(isset($_SESSION['is_ecampaign_staff']) && (int)$_SESSION['admin_id'] === $staffId)) {
            try {
                $row = $pdo->prepare("SELECT full_name FROM sys_staff WHERE id=?");
                $row->execute([$staffId]);
                $name = $row->fetchColumn() ?: "ID $staffId";
                $pdo->prepare("DELETE FROM sys_staff WHERE id=?")->execute([$staffId]);
                log_activity('staff_delete', "ลบเจ้าหน้าที่: $name");
                $success = "ลบเจ้าหน้าที่ '$name' เรียบร้อยแล้ว";
            } catch (PDOException $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        } else {
            $error = 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้';
        }
    }

    // Toggle สถานะ (active / disabled)
    if ($action === 'toggle_status') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        if ($staffId > 0) {
            $cur = $pdo->prepare("SELECT account_status FROM sys_staff WHERE id=?");
            $cur->execute([$staffId]);
            $curStatus = $cur->fetchColumn();
            $newStatus = ($curStatus === 'active') ? 'disabled' : 'active';
            $pdo->prepare("UPDATE sys_staff SET account_status=? WHERE id=?")
                ->execute([$newStatus, $staffId]);
            log_activity('staff_toggle', "เปลี่ยนสถานะเจ้าหน้าที่ ID $staffId เป็น $newStatus");
            $success = 'อัปเดตสถานะเรียบร้อยแล้ว';
        }
    }
}

// ── ดึงข้อมูล Staff ทั้งหมด ──────────────────────────────────────────────────
try {
    $staffList = $pdo->query("
        SELECT id, username, full_name, role, account_status,
               IFNULL(access_ecampaign,0) AS access_ecampaign,
               IFNULL(ecampaign_role,'editor') AS ecampaign_role
        FROM sys_staff
        ORDER BY account_status ASC, full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $staffList = [];
    $error = 'ไม่สามารถดึงข้อมูลเจ้าหน้าที่ได้: ' . $e->getMessage();
}

$totalStaff    = count($staffList);
$activeCount   = count(array_filter($staffList, fn($s) => $s['account_status'] === 'active'));
$ecAccessCount = count(array_filter($staffList, fn($s) => (int)$s['access_ecampaign'] === 1));

require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 pb-10">

<?php
$header_actions = '
<a href="index.php" class="bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 px-4 py-2.5 rounded-xl font-bold flex items-center gap-2 transition-all text-sm shadow-sm active:scale-95">
    <i class="fa-solid fa-arrow-left"></i> Dashboard
</a>
<button onclick="openAddModal()"
    class="bg-[#0052CC] hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-bold flex items-center gap-2 transition-all text-sm shadow-md active:scale-95">
    <i class="fa-solid fa-user-plus"></i> เพิ่มเจ้าหน้าที่
</button>';
renderPageHeader('จัดการเจ้าหน้าที่', 'STAFF MANAGEMENT · E-CAMPAIGN', $header_actions);
?>

<?php if ($success): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm font-semibold flex items-center gap-2">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-600 rounded-xl text-sm font-semibold flex items-center gap-2">
    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">เจ้าหน้าที่ทั้งหมด</p>
        <p class="text-3xl font-black text-gray-900"><?= $totalStaff ?></p>
    </div>
    <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">บัญชี Active</p>
        <p class="text-3xl font-black text-green-600"><?= $activeCount ?></p>
    </div>
    <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">สิทธิ์ e-Campaign</p>
        <p class="text-3xl font-black text-blue-600"><?= $ecAccessCount ?></p>
    </div>
</div>

<!-- Staff Table -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-bold text-gray-800 flex items-center gap-2">
            <i class="fa-solid fa-users text-blue-500"></i> รายชื่อเจ้าหน้าที่
        </h2>
        <input type="text" id="searchInput" onkeyup="filterTable()" placeholder="ค้นหาชื่อ / username..."
            class="text-sm border border-gray-200 rounded-xl px-4 py-2 w-64 focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
    </div>

    <?php if (empty($staffList)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="fa-solid fa-users text-4xl mb-3 block"></i>
        <p class="font-semibold">ยังไม่มีเจ้าหน้าที่ในระบบ</p>
        <button onclick="openAddModal()" class="mt-4 text-blue-600 font-bold text-sm underline">+ เพิ่มเจ้าหน้าที่คนแรก</button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full" id="staffTable">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">ชื่อ / Username</th>
                    <th class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">บทบาท</th>
                    <th class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">สถานะ</th>
                    <th class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">e-Campaign</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50" id="staffTableBody">
                <?php foreach ($staffList as $s):
                    $isSelf = isset($_SESSION['is_ecampaign_staff']) && (int)$_SESSION['admin_id'] === (int)$s['id'];
                    $hasEc  = (int)$s['access_ecampaign'] === 1;
                    $ecRoleLabels = ['admin' => 'Admin', 'editor' => 'Editor', 'superadmin' => 'Superadmin'];
                    $roleLabels   = ['admin' => 'Admin', 'employee' => 'Employee', 'editor' => 'Editor', 'librarian' => 'Librarian'];
                ?>
                <tr class="hover:bg-gray-50 transition-colors staff-row">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-sm shrink-0
                                <?= $hasEc ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-500' ?>">
                                <?= mb_strtoupper(mb_substr($s['full_name'] ?: $s['username'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm staff-name"><?= htmlspecialchars($s['full_name']) ?></p>
                                <p class="text-xs text-gray-400 staff-username">@<?= htmlspecialchars($s['username']) ?></p>
                            </div>
                            <?php if ($isSelf): ?>
                            <span class="text-[10px] bg-blue-100 text-blue-600 font-bold px-2 py-0.5 rounded-full">คุณ</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-5 py-4">
                        <?php
                        $roleBadge = ['admin'=>'bg-purple-100 text-purple-700','employee'=>'bg-gray-100 text-gray-600',
                                      'editor'=>'bg-yellow-100 text-yellow-700','librarian'=>'bg-teal-100 text-teal-700'];
                        $rc = $roleBadge[$s['role']] ?? 'bg-gray-100 text-gray-600';
                        ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full <?= $rc ?>">
                            <?= $roleLabels[$s['role']] ?? $s['role'] ?>
                        </span>
                    </td>
                    <td class="px-5 py-4">
                        <?php if ($s['account_status'] === 'active'): ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-green-100 text-green-700">Active</span>
                        <?php else: ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-red-100 text-red-600">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-4">
                        <?php if ($hasEc): ?>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                            <span class="text-xs font-bold text-blue-600"><?= $ecRoleLabels[$s['ecampaign_role']] ?? $s['ecampaign_role'] ?></span>
                        </div>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-4 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <!-- Toggle status -->
                            <form method="POST" onsubmit="return confirm('เปลี่ยนสถานะบัญชีนี้?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                                <button type="submit" title="Toggle Status"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center transition-colors
                                    <?= $s['account_status'] === 'active' ? 'bg-green-50 text-green-600 hover:bg-green-100' : 'bg-gray-50 text-gray-400 hover:bg-gray-100' ?>">
                                    <i class="fa-solid fa-power-off text-xs"></i>
                                </button>
                            </form>
                            <!-- Edit -->
                            <button onclick='openEditModal(<?= json_encode($s) ?>)'
                                class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 flex items-center justify-center transition-colors"
                                title="แก้ไข">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>
                            <!-- Activity Logs -->
                            <a href="activity_logs.php?user_id=<?= (int)$s['id'] ?>&role=STAFF"
                                class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 flex items-center justify-center transition-colors"
                                title="ดูบันทึกกิจกรรม">
                                <i class="fa-solid fa-clipboard-list text-xs"></i>
                            </a>
                            <!-- Delete -->
                            <?php if (!$isSelf): ?>
                            <form method="POST" onsubmit="return confirm('ยืนยันลบเจ้าหน้าที่ \'<?= htmlspecialchars(addslashes($s['full_name'])) ?>\'?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                                <button type="submit" title="ลบ"
                                    class="w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 flex items-center justify-center transition-colors">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="w-8 h-8"></div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div><!-- /.max-w -->

<!-- ── Add Modal ──────────────────────────────────────────────────────────── -->
<div id="addModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeAddModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg z-10 overflow-hidden">
        <div class="bg-[#0052CC] px-6 py-4 flex items-center justify-between">
            <h3 class="text-white font-bold flex items-center gap-2">
                <i class="fa-solid fa-user-plus"></i> เพิ่มเจ้าหน้าที่ใหม่
            </h3>
            <button onclick="closeAddModal()" class="text-white/70 hover:text-white">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">ชื่อ-สกุล *</label>
                    <input type="text" name="full_name" required
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Username *</label>
                    <input type="text" name="username" required autocomplete="off"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Password * (อย่างน้อย 8 ตัว)</label>
                <input type="password" name="password" required minlength="8" autocomplete="new-password"
                    class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">บทบาทหลัก</label>
                    <select name="role" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400">
                        <option value="employee">Employee</option>
                        <option value="editor">Editor</option>
                        <option value="librarian">Librarian</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">สถานะบัญชี</label>
                    <select name="account_status" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400">
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
            </div>
            <div class="border border-blue-100 bg-blue-50 rounded-xl p-4 space-y-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="access_ecampaign" value="1" id="addEcCheck"
                        onchange="document.getElementById('addEcRoleWrap').classList.toggle('hidden', !this.checked)"
                        class="w-4 h-4 rounded text-blue-600">
                    <span class="text-sm font-semibold text-blue-800">ให้สิทธิ์เข้าใช้งาน e-Campaign</span>
                </label>
                <div id="addEcRoleWrap" class="hidden">
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">สิทธิ์ใน e-Campaign</label>
                    <select name="ecampaign_role" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 bg-white">
                        <option value="editor">Editor — ดูและแก้ไขข้อมูล</option>
                        <option value="admin">Admin — จัดการแคมเปญและ Booking</option>
                        <?php if ($myRole === 'superadmin'): ?>
                        <option value="superadmin">Superadmin — สิทธิ์สูงสุด</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeAddModal()"
                    class="px-5 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                    ยกเลิก
                </button>
                <button type="submit"
                    class="px-6 py-2.5 rounded-xl bg-[#0052CC] hover:bg-blue-700 text-white text-sm font-bold transition-colors shadow-md">
                    <i class="fa-solid fa-plus mr-1"></i> เพิ่มเจ้าหน้าที่
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────── -->
<div id="editModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeEditModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg z-10 overflow-hidden">
        <div class="bg-gray-800 px-6 py-4 flex items-center justify-between">
            <h3 class="text-white font-bold flex items-center gap-2">
                <i class="fa-solid fa-pen"></i> แก้ไขเจ้าหน้าที่
            </h3>
            <button onclick="closeEditModal()" class="text-white/70 hover:text-white">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4" id="editForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="staff_id" id="editStaffId">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">ชื่อ-สกุล *</label>
                    <input type="text" name="full_name" id="editFullName" required
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Username *</label>
                    <input type="text" name="username" id="editUsername" required autocomplete="off"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">
                    Password ใหม่ <span class="font-normal normal-case">(เว้นว่างถ้าไม่เปลี่ยน)</span>
                </label>
                <input type="password" name="password" minlength="8" autocomplete="new-password"
                    placeholder="(ไม่เปลี่ยน)"
                    class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">บทบาทหลัก</label>
                    <select name="role" id="editRole" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400">
                        <option value="employee">Employee</option>
                        <option value="editor">Editor</option>
                        <option value="librarian">Librarian</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">สถานะบัญชี</label>
                    <select name="account_status" id="editStatus" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400">
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
            </div>
            <div class="border border-blue-100 bg-blue-50 rounded-xl p-4 space-y-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="access_ecampaign" value="1" id="editEcCheck"
                        onchange="document.getElementById('editEcRoleWrap').classList.toggle('hidden', !this.checked)"
                        class="w-4 h-4 rounded text-blue-600">
                    <span class="text-sm font-semibold text-blue-800">ให้สิทธิ์เข้าใช้งาน e-Campaign</span>
                </label>
                <div id="editEcRoleWrap" class="hidden">
                    <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">สิทธิ์ใน e-Campaign</label>
                    <select name="ecampaign_role" id="editEcRole" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-400 bg-white">
                        <option value="editor">Editor — ดูและแก้ไขข้อมูล</option>
                        <option value="admin">Admin — จัดการแคมเปญและ Booking</option>
                        <?php if ($myRole === 'superadmin'): ?>
                        <option value="superadmin">Superadmin — สิทธิ์สูงสุด</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeEditModal()"
                    class="px-5 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                    ยกเลิก
                </button>
                <button type="submit"
                    class="px-6 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-white text-sm font-bold transition-colors shadow-md">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึกการเปลี่ยนแปลง
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modals ─────────────────────────────────────────────────────────────────
function openAddModal()  { document.getElementById('addModal').classList.remove('hidden'); }
function closeAddModal() { document.getElementById('addModal').classList.add('hidden'); }
function closeEditModal(){ document.getElementById('editModal').classList.add('hidden'); }

function openEditModal(s) {
    document.getElementById('editStaffId').value  = s.id;
    document.getElementById('editFullName').value = s.full_name;
    document.getElementById('editUsername').value = s.username;
    document.getElementById('editRole').value     = s.role;
    document.getElementById('editStatus').value   = s.account_status;

    const ecCheck = document.getElementById('editEcCheck');
    const ecWrap  = document.getElementById('editEcRoleWrap');
    ecCheck.checked = parseInt(s.access_ecampaign) === 1;
    ecWrap.classList.toggle('hidden', !ecCheck.checked);
    document.getElementById('editEcRole').value = s.ecampaign_role || 'editor';

    document.getElementById('editModal').classList.remove('hidden');
}

// ── Table search ───────────────────────────────────────────────────────────
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.staff-row').forEach(row => {
        const name = row.querySelector('.staff-name')?.textContent.toLowerCase() || '';
        const user = row.querySelector('.staff-username')?.textContent.toLowerCase() || '';
        row.style.display = (name.includes(q) || user.includes(q)) ? '' : 'none';
    });
}

// Close modal on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeAddModal(); closeEditModal(); }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
