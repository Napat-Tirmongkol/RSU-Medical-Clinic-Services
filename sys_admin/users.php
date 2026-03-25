<?php
// admin/users.php
require_once __DIR__ . '/../admin/includes/auth.php';

$pdo = db();
$message = '';
$messageType = '';

// ==========================================
// ส่วนจัดการ POST Request (อัปเดตข้อมูลนักศึกษา)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $studentId = trim($_POST['student_personnel_id'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');

        if ($userId > 0 && $fullName !== '' && $studentId !== '') {
            try {
                $sql = "UPDATE sys_users 
                        SET full_name = :name, 
                            student_personnel_id = :studentid, 
                            citizen_id = :citizenid,
                            phone_number = :phone,
                            status = :status 
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':name' => $fullName,
                    ':studentid' => trim($_POST['student_personnel_id'] ?? ''),
                    ':citizenid' => trim($_POST['citizen_id'] ?? ''),
                    ':phone' => $phone,
                    ':status' => trim($_POST['status'] ?? ''),
                    ':id' => $userId
                ]);
                $message = "อัปเดตข้อมูลนักศึกษาเรียบร้อยแล้ว!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = "error";
            }
        } else {
            $message = "กรุณากรอกข้อมูลให้ครบถ้วน";
            $messageType = "error";
        }
    }
}

// ==========================================
// ดึงข้อมูลและระบบค้นหา (แก้ไข Error HY093)
// ==========================================
$search = $_GET['search'] ?? '';
$users = [];
$params = [];

try {
    $sql = "SELECT * FROM sys_users WHERE 1=1";
    
    if ($search !== '') {
        // 🌟 แยกชื่อ Parameter ให้ไม่ซ้ำกัน
        $sql .= " AND (full_name LIKE :search1 OR student_personnel_id LIKE :search2 OR phone_number LIKE :search3)";
        $searchTerm = "%{$search}%";
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
        $params[':search3'] = $searchTerm;
    }
    
    $sql .= " ORDER BY created_at DESC"; // เรียงจากคนที่สมัครล่าสุดขึ้นก่อน
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching users: " . $e->getMessage());
}

require_once __DIR__ . '/../admin/includes/header.php';
?>

<style>
/* Animations & Glass Effects */
@keyframes slideUpFade {
    0% { opacity: 0; transform: translateY(15px); }
    100% { opacity: 1; transform: translateY(0); }
}
.animate-slide-up { animation: slideUpFade 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.delay-100 { animation-delay: 0.1s; }

.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.glass-modal {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}
</style>

<div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-end gap-5 animate-slide-up">
    <div>
        <h1 class="text-3xl font-extrabold text-[#0052CC] tracking-tight flex items-center gap-3">
            <i class="fa-solid fa-users-gear"></i> จัดการรายชื่อผู้ใช้งาน
        </h1>
        <p class="text-gray-500 text-sm mt-1.5 font-medium">จัดการข้อมูลส่วนตัวนักศึกษา/บุคลากรที่สมัครผ่าน LINE</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl text-sm font-semibold border <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100 mb-8 animate-slide-up delay-100 overflow-hidden relative">
    <form method="GET" class="flex flex-col md:flex-row gap-4 relative z-10 w-full relative">
        <div class="flex-1 relative">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-solid fa-search text-gray-400"></i>
            </div>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาด้วย ชื่อ นามสกุล, รหัส หรือเบอร์โทรศัพท์..." 
                   class="w-full pl-11 pr-4 py-3.5 bg-gray-50 border border-gray-200 rounded-[14px] focus:bg-white focus:ring-2 focus:ring-[#0052CC]/50 focus:border-[#0052CC] outline-none transition-all font-prompt text-sm font-semibold shadow-inner text-gray-800 placeholder-gray-400">
        </div>
        <div class="flex gap-2 w-full md:w-auto">
            <button type="submit" class="flex-1 md:flex-none bg-gradient-to-r from-blue-600 to-[#0052CC] hover:shadow-lg hover:shadow-blue-500/30 hover:-translate-y-0.5 text-white px-8 py-3.5 rounded-[14px] font-bold transition-all text-sm shadow-sm flex items-center justify-center gap-2">
                ค้นหาข้อมูล
            </button>
            <?php if ($search !== ''): ?>
                <a href="users.php" class="flex-1 md:flex-none bg-gray-100 hover:bg-gray-200 hover:-translate-y-0.5 text-gray-700 px-6 py-3.5 rounded-[14px] font-bold transition-all text-sm text-center flex items-center justify-center gap-2 shadow-sm">
                    <i class="fa-solid fa-rotate-left"></i> ล้างค่า
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="bg-white rounded-[24px] shadow-lg shadow-gray-200/40 border border-gray-100 overflow-hidden animate-slide-up delay-100 mb-10">
    <div class="overflow-x-auto custom-scrollbar pb-2">
        <table class="w-full text-left text-sm whitespace-nowrap">
            <thead class="bg-gray-50/90 text-gray-600 font-bold border-b border-gray-100 uppercase tracking-wider text-[11px] backdrop-blur-md sticky top-0">
                <tr>
                    <th class="px-6 py-5"><i class="fa-solid fa-hashtag mr-1"></i> ID</th>
                    <th class="px-6 py-5"><i class="fa-solid fa-user mr-1"></i> ชื่อ-นามสกุล</th>
                    <th class="px-6 py-5"><i class="fa-solid fa-id-card mr-1"></i> ID Card (13 Lak)</th>
                    <th class="px-6 py-5"><i class="fa-solid fa-graduation-cap mr-1"></i> Student ID (7 Lak)</th>
                    <th class="px-6 py-5">สถานะ</th>
                    <th class="px-6 py-5"><i class="fa-solid fa-phone mr-1"></i> เบอร์โทรศัพท์</th>
                    <th class="px-6 py-5"><i class="fa-regular fa-calendar-check mr-1"></i> วันที่ลงทะเบียน</th>
                    <th class="px-6 py-5 text-center"><i class="fa-solid fa-clock-rotate-left mr-1"></i> ประวัติ</th>
                    <th class="px-6 py-5 text-center"><i class="fa-solid fa-gear mr-1"></i> จัดการข้อมูล</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php if (count($users) === 0): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-20 text-center">
                            <div class="flex flex-col items-center justify-center text-gray-400">
                                <i class="fa-regular fa-folder-open text-6xl mb-4 text-gray-200"></i>
                                <p class="text-lg font-bold">ไม่พบรายชื่อผู้ใช้งานในระบบ</p>
                                <p class="text-sm mt-1">ลองเปลี่ยนคำค้นหาใหม่อีกครั้ง</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): 
                        $createdDate = date('d/m/Y H:i', strtotime($u['created_at']));
                        // เข้ารหัสข้อมูลสำหรับส่งไปที่ Javascript
                        $jsName = htmlspecialchars($u['full_name'] ?? '', ENT_QUOTES);
                        $jsStudentId = htmlspecialchars($u['student_personnel_id'] ?? '', ENT_QUOTES);
                        $jsCitizenId = htmlspecialchars($u['citizen_id'] ?? '', ENT_QUOTES);
                        $jsPhone = htmlspecialchars($u['phone_number'] ?? '', ENT_QUOTES);
                        $jsStatus = htmlspecialchars($u['status'] ?? '', ENT_QUOTES);

                        $statusBadge = '';
                        switch($u['status']) {
                            case 'student': $statusBadge = '<span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-600 text-[10px] font-bold border border-blue-100">นักศึกษา</span>'; break;
                            case 'staff': $statusBadge = '<span class="px-2.5 py-1 rounded-full bg-purple-50 text-purple-600 text-[10px] font-bold border border-purple-100">บุคลากร</span>'; break;
                            case 'external': $statusBadge = '<span class="px-2.5 py-1 rounded-full bg-gray-50 text-gray-600 text-[10px] font-bold border border-gray-100">บุคคลทั่วไป</span>'; break;
                            default: $statusBadge = '<span class="px-2.5 py-1 rounded-full bg-red-50 text-red-400 text-[10px] font-bold border border-red-100">ไม่ระบุ</span>';
                        }
                    ?>
                        <tr class="hover:bg-[#f8fafc] group transition-colors duration-200">
                            <td class="px-6 py-5 text-gray-400 font-bold text-xs">#<?= $u['id'] ?></td>
                            <td class="px-6 py-5">
                                <div class="font-extrabold text-gray-900 text-base group-hover:text-[#0052CC] transition-colors"><?= htmlspecialchars($u['full_name'] ?: 'ยังไม่กรอกโปรไฟล์') ?></div>
                            </td>
                            <td class="px-6 py-5 text-gray-600 font-bold"><span class="bg-blue-50 text-blue-700 px-2 py-1 rounded-md text-xs border border-blue-100"><?= htmlspecialchars($u['citizen_id'] ?: '-') ?></span></td>
                            <td class="px-6 py-5 text-gray-600 font-bold"><span class="bg-gray-100 text-gray-700 px-2 py-1 rounded-md text-xs border border-gray-200"><?= htmlspecialchars($u['student_personnel_id'] ?: '-') ?></span></td>
                            <td class="px-6 py-5"><?= $statusBadge ?></td>
                            <td class="px-6 py-5 text-gray-600 font-medium"><?= htmlspecialchars($u['phone_number'] ?: '-') ?></td>
                            <td class="px-6 py-5 text-xs text-gray-400 font-medium font-mono"><?= $createdDate ?></td>
                            <td class="px-6 py-5 text-center">
                                <a href="user_history.php?id=<?= $u['id'] ?>"
                                   class="inline-flex items-center gap-1.5 bg-blue-50 border border-blue-100 hover:bg-[#0052CC] hover:text-white text-[#0052CC] px-4 py-2.5 rounded-[10px] font-bold text-xs transition-all shadow-sm">
                                    <i class="fa-solid fa-clock-rotate-left"></i> ประวัติจองคิว
                                </a>
                            </td>
                            <td class="px-6 py-5 text-center">
                                <?php if ($u['full_name']): ?>
                                <button onclick="openEditModal(<?= $u['id'] ?>, '<?= $jsName ?>', '<?= $jsStudentId ?>', '<?= $jsCitizenId ?>', '<?= $jsPhone ?>', '<?= $jsStatus ?>')"
                                        class="bg-amber-50 hover:bg-amber-100 border border-amber-100 text-amber-600 px-4 py-2.5 rounded-[10px] font-bold text-[11px] uppercase tracking-wider transition-colors inline-flex items-center justify-center gap-2 shadow-sm mx-auto">
                                    <i class="fa-solid fa-user-pen text-sm"></i> แก้ไข
                                </button>
                                <?php else: ?>
                                    <span class="text-[11px] font-bold text-red-400 bg-red-50 border border-red-100 px-3 py-1.5 rounded-full inline-block"><i class="fa-solid fa-triangle-exclamation"></i> รอข้อมูล</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-modal rounded-[24px] w-full max-w-lg overflow-hidden animate-slide-up border border-white/50 shadow-2xl">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-white/50">
            <h3 class="text-xl font-black text-amber-600 flex items-center gap-3">
                <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-[14px] flex items-center justify-center text-lg shadow-inner"><i class="fa-solid fa-user-pen"></i></div>
                แก้ไขข้อมูลผู้ใช้งาน
            </h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="w-8 h-8 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full hover:bg-gray-200 hover:text-gray-800 transition-colors shadow-sm focus:outline-none">
                <i class="fa-solid fa-times font-bold"></i>
            </button>
        </div>
        <form method="POST" class="flex flex-col">
            <div class="p-6 space-y-5 flex-1 bg-gray-50/30">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div>
                    <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">ชื่อ-นามสกุล <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_full_name" name="full_name" required class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 outline-none font-prompt text-gray-800 font-bold shadow-sm transition-all focus:shadow-md">
                </div>
                
                <div>
                    <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">เลขบัตรประชาชน (13 หลัก) <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_citizen_id" name="citizen_id" required maxlength="13" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 outline-none font-prompt text-gray-800 font-bold shadow-sm transition-all focus:shadow-md tracking-wider">
                </div>

                <div>
                    <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">รหัสนักศึกษา / บุคลากร (7 หลัก)</label>
                    <input type="text" id="edit_student_id" name="student_personnel_id" maxlength="7" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 outline-none font-prompt text-gray-800 font-bold shadow-sm transition-all focus:shadow-md tracking-wider">
                </div>

                <div>
                    <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">เบอร์โทรศัพท์ (ไม่บังคับ)</label>
                    <input type="text" id="edit_phone" name="phone_number" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 outline-none font-prompt text-gray-800 font-bold shadow-sm transition-all focus:shadow-md tracking-wider">
                </div>

                <div>
                    <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">ประเภทผู้ใช้งาน <span class="text-red-500">*</span></label>
                    <select name="status" id="edit_status" required class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 outline-none font-prompt text-gray-800 font-bold shadow-sm transition-all focus:shadow-md">
                        <option value="">-- เลือกประเภท --</option>
                        <option value="student">นักศึกษา</option>
                        <option value="staff">บุคลากร/อาจารย์</option>
                        <option value="external">บุคคลทั่วไป</option>
                    </select>
                </div>
            </div>

            <div class="p-5 border-t border-gray-100 bg-white flex gap-3">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="w-1/3 bg-gray-50 text-gray-700 font-bold border-2 border-gray-200 py-3.5 rounded-[14px] hover:bg-gray-100 transition-colors shadow-sm">ยกเลิก</button>
                <button type="submit" class="w-2/3 bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold py-3.5 rounded-[14px] hover:shadow-lg hover:shadow-amber-500/30 hover:-translate-y-0.5 transition-all text-base tracking-wide shadow-sm flex items-center justify-center gap-2"><i class="fa-solid fa-save"></i> บันทึกข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<script>
// ฟังก์ชันโยนข้อมูลลงในช่องตอนเปิด Modal
function openEditModal(id, name, studentId, citizenId, phone, status) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_full_name').value = name;
    document.getElementById('edit_student_id').value = studentId;
    document.getElementById('edit_citizen_id').value = citizenId;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_status').value = status;
    
    document.getElementById('editModal').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>
