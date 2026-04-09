<?php
// admin/users.php
require_once __DIR__ . '/../config.php';
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
                log_activity("Updated User Profile", "แก้ไขข้อมูลงรายชื่อ: $fullName (#$studentId)");
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
    error_log("portal users error: " . $e->getMessage()); $users = [];
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

<div class="max-w-6xl mx-auto px-4 py-8">
<?php
    // (A) PREPARE HEADER ACTIONS
    $header_actions = '
    <div class="flex items-center gap-3">
        <a href="index.php" class="bg-white border border-gray-100 hover:bg-gray-50 text-gray-500 px-5 py-2.5 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-sm active:scale-95 group text-sm">
            <i class="fa-solid fa-arrow-left group-hover:-translate-x-1 transition-transform"></i> Back to Portal
        </a>
        <div class="relative group hidden sm:block">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 group-focus-within:text-blue-500 transition-colors"></i>
            <input type="text" placeholder="พิมพ์เพื่อกรองด่วน..." 
                   class="pl-10 pr-4 py-2.5 bg-white/50 border border-gray-100 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all w-64 shadow-sm" 
                   onkeyup="filterUsers(this.value)">
        </div>
    </div>';
    renderPageHeader("User Directory", "ศูนย์กลางจัดการรายชื่อ: ค้นหา ตรวจสอบความถูกต้อง และจัดการประวัติผู้ใช้งานทั้งแคมเปญ", $header_actions); 
?>

    <!-- 📊 User Stats Summary -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 animate-slide-up">
        <div class="bg-gradient-to-br from-white to-gray-50/50 p-6 rounded-[28px] border border-gray-100 shadow-sm">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 rounded-2xl flex items-center justify-center text-[#0052CC]">
                    <i class="fa-solid fa-users text-xl"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Master Directory</p>
                    <p class="text-2xl font-black text-gray-900"><?= number_format(count($users)) ?> Users</p>
                </div>
            </div>
        </div>
        <div class="bg-gradient-to-br from-white to-gray-50/50 p-6 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 transition-transform duration-700">
                <i class="fa-solid fa-address-card text-8xl text-indigo-900"></i>
            </div>
            <div class="flex items-center gap-4 relative z-10">
                <div class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center text-indigo-600">
                    <i class="fa-solid fa-id-card-clip text-xl transition-transform group-hover:rotate-12"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Active Accounts</p>
<p class="text-2xl font-black text-gray-900">
    <?php 
        // นับเฉพาะ User ที่มีประวัติจองแคมเปญ (camp_bookings) หรือ ประวัติยืมของ (borrow_records)
        $activeSql = "
            SELECT COUNT(DISTINCT id) 
            FROM sys_users 
            WHERE id IN (SELECT student_id FROM camp_bookings WHERE student_id IS NOT NULL)
               OR id IN (SELECT borrower_student_id FROM borrow_records WHERE borrower_student_id IS NOT NULL)
        ";
        $activeCount = $pdo->query($activeSql)->fetchColumn();
        echo number_format($activeCount);
    ?>
</p>
                </div>
            </div>
        </div>
        <div class="bg-[#0052CC] p-7 rounded-[32px] shadow-lg shadow-blue-200/50 relative overflow-hidden group pointer-events-none sm:pointer-events-auto">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:bg-white/20 transition-all duration-500"></div>
            <div class="relative z-10">
                <p class="text-[11px] font-black text-white/50 uppercase tracking-[0.2em] mb-1">Advanced Tool</p>
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white tracking-tight leading-tight">Sync Users<br><span class="opacity-60 font-medium text-sm text-blue-100">Maintain Database</span></h3>
                    <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center text-white backdrop-blur-sm group-hover:scale-110 transition-transform shadow-xl">
                        <i class="fa-solid fa-arrows-rotate text-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 📋 Main User Table Content -->
    <div class="bg-white rounded-[32px] shadow-2xl shadow-gray-200/40 border border-gray-100/50 overflow-hidden animate-slide-up" style="animation-delay: 100ms;">
        <div class="px-8 py-7 border-b border-gray-50 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="flex items-center gap-3">
                <div class="w-2 h-6 bg-blue-600 rounded-full"></div>
                <h3 class="font-black text-gray-900 text-sm uppercase tracking-widest">Master Records</h3>
            </div>
            
            <form method="GET" class="flex items-center gap-3">
                <div class="relative">
                    <i class="fa-solid fa-filter absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="ระบุรหัสประจำตัว หรือชื่อเพื่อค้นหา..." 
                           class="pl-9 pr-4 py-2.5 bg-gray-50 border border-gray-100 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all w-full md:w-80 shadow-sm border-gray-200/50">
                </div>
                <button type="submit" class="bg-gray-900 hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-xs transition-all active:scale-95 shadow-lg shadow-gray-200 shadow-md">
                    <span>ตกลง</span>
                </button>
            </form>
        </div>

        <table class="w-full text-left border-collapse" id="userTable">
            <thead>
                <tr class="bg-gray-50/30 border-b border-gray-50 text-[10px] uppercase tracking-[0.2em] font-black text-gray-400">
                    <th class="px-8 py-5">Verified Identity</th>
                    <th class="px-8 py-5">Contact Node</th>
                    <th class="px-8 py-5">Onboarded</th>
                    <th class="px-8 py-5 text-right">Service</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="4" class="px-8 py-20 text-center">
                        <div class="flex flex-col items-center opacity-30">
                            <i class="fa-solid fa-ghost text-6xl mb-4"></i>
                            <p class="font-black uppercase tracking-widest text-sm text-gray-400">No records found</p>
                            <p class="text-xs font-bold mt-2">ไม่พบรายชื่อที่ต้องการค้นหาในขณะนี้</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-blue-50/20 transition-all group/row cursor-default">
                        <td class="px-8 py-6">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-gray-100 to-gray-200 text-gray-400 rounded-2xl flex items-center justify-center font-black shadow-sm group-hover/row:from-blue-600 group-hover/row:to-indigo-600 group-hover/row:text-white transition-all duration-500 text-sm">
                                    <?= mb_substr($user['full_name'], 0, 1) ?>
                                </div>
                                <div class="space-y-1">
                                    <div class="font-black text-gray-900 group-hover/row:text-blue-700 transition-colors uppercase tracking-tight"><?= htmlspecialchars($user['full_name']) ?></div>
                                    <div class="flex items-center gap-2">
                                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest bg-gray-50 px-2 py-0.5 rounded-md border border-gray-100">
                                            #<?= htmlspecialchars($user['student_personnel_id'] ?? '—') ?>
                                        </div>
                                        <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                                        <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider"><?= htmlspecialchars($user['status'] ?? 'Registered') ?></span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <div class="space-y-2">
                                <div class="flex items-center gap-2 group/link">
                                    <div class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] group-hover/link:bg-emerald-600 group-hover/link:text-white transition-all">
                                        <i class="fa-solid fa-phone"></i>
                                    </div>
                                    <span class="text-xs font-black text-gray-700 tracking-tight group-hover/link:text-emerald-700"><?= htmlspecialchars($user['phone_number'] ?: '---') ?></span>
                                </div>
                                <div class="flex items-center gap-2 group/link">
                                    <div class="w-6 h-6 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center text-[10px] group-hover/link:bg-sky-600 group-hover/link:text-white transition-all">
                                        <i class="fa-solid fa-envelope"></i>
                                    </div>
                                    <span class="text-[11px] font-bold text-gray-400 truncate max-w-[140px] tracking-tight group-hover/link:text-sky-700"><?= htmlspecialchars($user['email'] ?? 'No contact info') ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex flex-col">
                                <span class="text-xs font-black text-gray-800 tracking-tight"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest"><?= date('H:i', strtotime($user['created_at'])) ?> น.</span>
                            </div>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <div class="flex justify-end gap-2 translate-x-4 opacity-0 group-hover/row:translate-x-0 group-hover/row:opacity-100 transition-all duration-300">
                                <button onclick='openViewModal(<?= json_encode($user) ?>)' class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-[#0052CC] hover:border-blue-100 hover:shadow-lg hover:shadow-blue-50 transition-all active:scale-90 shadow-sm" title="Inspect Record">
                                    <i class="fa-solid fa-search text-xs"></i>
                                </button>
                                <button onclick='openEditModal(<?= json_encode($user) ?>)' class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-indigo-600 hover:border-indigo-100 hover:shadow-lg hover:shadow-indigo-50 transition-all active:scale-90 shadow-sm" title="Edit Profile">
                                    <i class="fa-solid fa-user-edit text-xs"></i>
                                </button>
                                <a href="../admin/user_history.php?id=<?= $user['id'] ?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-amber-600 hover:border-amber-100 hover:shadow-lg hover:shadow-amber-50 transition-all active:scale-90 shadow-sm" title="Transaction Log">
                                    <i class="fa-solid fa-history text-xs"></i>
                                </a>
                            </div>
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
                        <option value="other">บุคคลทั่วไป</option>
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

<!-- View Modal -->
<div id="viewModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-modal rounded-[24px] w-full max-w-lg overflow-hidden animate-slide-up border border-white/50 shadow-2xl">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-white/50">
            <h3 class="text-xl font-black text-blue-600 flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-[14px] flex items-center justify-center text-lg shadow-inner"><i class="fa-solid fa-user-circle"></i></div>
                ข้อมูลผู้ใช้งาน
            </h3>
            <button onclick="document.getElementById('viewModal').classList.add('hidden')" class="w-8 h-8 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full hover:bg-gray-200 hover:text-gray-800 transition-colors shadow-sm focus:outline-none">
                <i class="fa-solid fa-times font-bold"></i>
            </button>
        </div>
        <div class="p-6 space-y-5 flex-1 bg-gray-50/30">
            <div>
                <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">ชื่อ-นามสกุล</label>
                <p id="view_full_name" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] font-prompt text-gray-800 font-bold shadow-sm"></p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">เลขบัตรประชาชน (13 หลัก)</label>
                <p id="view_citizen_id" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] font-prompt text-gray-800 font-bold shadow-sm tracking-wider"></p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">รหัสนักศึกษา / บุคลากร (7 หลัก)</label>
                <p id="view_student_id" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] font-prompt text-gray-800 font-bold shadow-sm tracking-wider"></p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">เบอร์โทรศัพท์</label>
                <p id="view_phone" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] font-prompt text-gray-800 font-bold shadow-sm tracking-wider"></p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">ประเภทผู้ใช้งาน</label>
                <p id="view_status" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] font-prompt text-gray-800 font-bold shadow-sm"></p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider font-bold text-gray-500 mb-1.5">วันที่ลงทะเบียน</label>
                <p id="view_created_at" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-[14px] font-prompt text-gray-800 font-bold shadow-sm"></p>
            </div>
        </div>
        <div class="p-5 border-t border-gray-100 bg-white flex justify-end">
            <button type="button" onclick="document.getElementById('viewModal').classList.add('hidden')" class="bg-gray-50 text-gray-700 font-bold border-2 border-gray-200 px-6 py-3.5 rounded-[14px] hover:bg-gray-100 transition-colors shadow-sm">ปิด</button>
        </div>
    </div>
</div>

<script>
// ฟังก์ชันโยนข้อมูลลงในช่องตอนเปิด Modal
function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_student_id').value = user.student_personnel_id;
    document.getElementById('edit_citizen_id').value = user.citizen_id;
    document.getElementById('edit_phone').value = user.phone_number;
    document.getElementById('edit_status').value = user.status;
    
    document.getElementById('editModal').classList.remove('hidden');
}

function openViewModal(user) {
    document.getElementById('view_full_name').innerText = user.full_name || '-';
    document.getElementById('view_citizen_id').innerText = user.citizen_id || '-';
    document.getElementById('view_student_id').innerText = user.student_personnel_id || '-';
    document.getElementById('view_phone').innerText = user.phone_number || '-';
    document.getElementById('view_status').innerText = user.status ? (user.status === 'student' ? 'นักศึกษา' : (user.status === 'staff' ? 'บุคลากร/อาจารย์' : 'บุคคลทั่วไป')) : '-';
    document.getElementById('view_created_at').innerText = new Date(user.created_at).toLocaleString('th-TH', {
        year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
    document.getElementById('viewModal').classList.remove('hidden');
}

function filterUsers(val) {
    const rows = document.querySelectorAll('#userTable tbody tr');
    val = val.toLowerCase();
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(val) ? '' : 'none';
    });
}

// Close modal on click outside (for both modals)
document.getElementById('editModal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('editModal')) {
        document.getElementById('editModal').classList.add('hidden');
    }
});
document.getElementById('viewModal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('viewModal')) {
        document.getElementById('viewModal').classList.add('hidden');
    }
});
</script>

<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>
