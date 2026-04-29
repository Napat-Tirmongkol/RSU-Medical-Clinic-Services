<?php
// portal/users.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/auth.php';

// ป้องกัน browser cache POST response
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$pdo = db();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Center - Central HUB</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<?php
// ==========================================
// POST Handler — PRG pattern (redirect หลัง save ทันที)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $userId      = (int)($_POST['user_id']               ?? 0);
    $fullName    = trim($_POST['full_name']               ?? '');
    $studentId   = trim($_POST['student_personnel_id']    ?? '');
    $citizenId   = trim($_POST['citizen_id']              ?? '');
    $phone       = trim($_POST['phone_number']            ?? '');
    $email       = trim($_POST['email']                   ?? '');
    $department  = trim($_POST['department']              ?? '');
    $gender      = trim($_POST['gender']                  ?? '');
    $status      = trim($_POST['status']                  ?? '');
    $statusOther = trim($_POST['status_other']            ?? '');
    $search      = trim($_POST['search_carry']            ?? '');

    if ($userId > 0 && $fullName !== '') {
        try {
            $pdo->prepare("UPDATE sys_users
                           SET full_name = :name,
                               student_personnel_id = :sid,
                               citizen_id = :cid,
                               phone_number = :phone,
                               email = :email,
                               department = :dept,
                               gender = :gender,
                               status = :status,
                               status_other = :sother
                           WHERE id = :id")
                ->execute([':name'=>$fullName,':sid'=>$studentId,':cid'=>$citizenId,
                           ':phone'=>$phone,':email'=>$email,':dept'=>$department ?: null,
                           ':gender'=>$gender ?: null,':status'=>$status,
                           ':sother'=>$statusOther ?: null,':id'=>$userId]);
            log_activity("Updated User Profile", "แก้ไขรายชื่อ: $fullName (#$studentId)");
            $qs = http_build_query(array_filter(['saved'=>'1','search'=>$search]));
        } catch (PDOException $e) {
            error_log("portal edit_user: " . $e->getMessage());
            $qs = http_build_query(array_filter(['error'=>'1','search'=>$search]));
        }
    } else {
        $qs = http_build_query(array_filter(['error'=>'missing','search'=>$search]));
    }
    header('Location: users.php' . ($qs ? "?$qs" : ''));
    exit;
}

// ==========================================
// GET — ดึงข้อมูลใหม่เสมอ (หลัง redirect ข้อมูลจะเป็นปัจจุบัน)
// ==========================================
$search   = trim($_GET['search'] ?? '');
$saved    = isset($_GET['saved'])  && $_GET['saved']  === '1';
$hasError = isset($_GET['error']);
$users    = [];
$params   = [];

try {
    $sql = "SELECT * FROM sys_users WHERE 1=1";
    if ($search !== '') {
        $sql .= " AND (full_name LIKE :s1 OR student_personnel_id LIKE :s2 OR phone_number LIKE :s3)";
        $like = "%{$search}%";
        $params = [':s1'=>$like,':s2'=>$like,':s3'=>$like];
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("portal users fetch: " . $e->getMessage());
    $users = [];
}

// portal/users.php ไม่แสดง admin sidebar ของ e-campaign
?>
<body style="background:#f4f7f5; min-height:100vh;">
    <?php include __DIR__ . '/_partials/header.php'; ?>
    
    <div class="p-5 md:p-10 animate-slide-up">

<style>
    /* ── Premium UI Elements ── */
    @keyframes slideUpFade {
        0% { opacity: 0; transform: translateY(20px) scale(0.98); }
        100% { opacity: 1; transform: translateY(0) scale(1); }
    }
    .animate-slide-up { animation: slideUpFade 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

    .premium-input {
        width: 100%;
        padding: 0.875rem 1.25rem;
        background-color: #f9fafb;
        border: 1.5px solid #e5e7eb;
        border-radius: 1.25rem;
        font-size: 0.9375rem;
        font-weight: 600;
        color: #111827;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        outline: none;
    }

    .premium-input:focus {
        background-color: #ffffff;
        border-color: #f59e0b; /* Orange-ish for users */
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        transform: translateY(-1px);
    }

    .premium-modal-header {
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid #f3f4f6;
    }

    /* Custom Scrollbar */
    .custom-scrollbar::-webkit-scrollbar { width: 5px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #d1d5db; }
</style>

<div class="max-w-6xl mx-auto px-4 py-8">

<?php if ($saved): ?>
<div id="u-toast" style="position:fixed;top:20px;right:20px;z-index:9999;display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:12px 18px;font-size:13px;font-weight:700;color:#15803d;box-shadow:0 4px 20px rgba(0,0,0,.08)">
    <i class="fa-solid fa-circle-check"></i> บันทึกข้อมูลสำเร็จ
</div>
<script>setTimeout(function(){var t=document.getElementById('u-toast');if(t){t.style.transition='opacity .5s';t.style.opacity='0';setTimeout(function(){t.remove()},500)}},3000)</script>
<?php elseif ($hasError): ?>
<div style="position:fixed;top:20px;right:20px;z-index:9999;display:flex;align-items:center;gap:10px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:14px;padding:12px 18px;font-size:13px;font-weight:700;color:#dc2626;box-shadow:0 4px 20px rgba(0,0,0,.08)">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= $_GET['error']==='missing' ? 'กรุณากรอกข้อมูลให้ครบถ้วน' : 'เกิดข้อผิดพลาด กรุณาลองใหม่' ?>
</div>
<?php endif; ?>

<?php
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
?>
    <div class="mb-6 md:mb-10 flex flex-col md:flex-row md:justify-between md:items-end gap-4 md:gap-6 au d1">
        <div class="relative">
            <h1 class="text-xl sm:text-3xl md:text-4xl font-[950] text-gray-900 tracking-tight flex items-center gap-3 sm:gap-4">
                <div class="w-1.5 h-8 sm:w-2 sm:h-10 rounded-full shadow-lg flex-shrink-0" style="background:linear-gradient(180deg,#2e9e63,#6ee7b7);box-shadow:0 4px 10px rgba(46,158,99,.3)"></div>
                User Directory
            </h1>
            <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] mt-2 sm:mt-3 ml-5 sm:ml-6 opacity-60" style="color:#2e7d52">ศูนย์กลางจัดการรายชื่อ: ค้นหา ตรวจสอบความถูกต้อง และจัดการประวัติผู้ใช้งานทั้งแคมเปญ</p>
        </div>
        <div class="flex flex-wrap gap-3 items-center ml-5 sm:ml-6 md:ml-0" style="position:relative;z-index:100">
            <?= $header_actions ?>
        </div>
    </div>

    <!-- User Stats Summary -->
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

    <!-- Main User Table Content -->
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
                                <a href="index.php?section=activity_logs&al_user_id=<?= (int)$user['id'] ?>&al_role=user" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-400 hover:text-emerald-600 hover:border-emerald-100 hover:shadow-lg hover:shadow-emerald-50 transition-all active:scale-90 shadow-sm" title="ดูบันทึกกิจกรรม">
                                    <i class="fa-solid fa-clipboard-list text-xs"></i>
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

<div id="editModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4" style="z-index:200;">
    <div id="editModalBox" class="bg-white rounded-[32px] shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0 duration-300">
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="search_carry" value="<?= htmlspecialchars($search) ?>">

            <!-- Header -->
            <div class="px-8 py-6 premium-modal-header flex justify-between items-center sticky top-0 z-10">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center shadow-inner">
                        <i class="fa-solid fa-user-pen text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-[900] text-gray-900 tracking-tight">แก้ไขข้อมูลผู้ใช้งาน</h3>
                        <p class="text-[11px] text-gray-400 font-bold uppercase tracking-wider">ปรับปรุงรายละเอียดส่วนบุคคลและสถานะ</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('edit')" class="w-10 h-10 flex items-center justify-center rounded-2xl hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-all duration-200">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="px-8 py-7 space-y-6 max-h-[70vh] overflow-y-auto custom-scrollbar bg-white/50">
                
                <div class="grid grid-cols-1 gap-5">
                    <!-- Full Name -->
                    <div>
                        <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">ชื่อ-นามสกุล <span class="text-red-500">*</span></label>
                        <input type="text" id="edit_full_name" name="full_name" required 
                               class="premium-input" placeholder="ระบุชื่อจริง-นามสกุล">
                    </div>

                    <!-- Citizen ID -->
                    <div>
                        <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">เลขบัตรประชาชน (13 หลัก) <span class="text-red-500">*</span></label>
                        <input type="text" id="edit_citizen_id" name="citizen_id" required maxlength="13" 
                               class="premium-input tracking-[0.1em]" placeholder="X-XXXX-XXXXX-XX-X">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Student/Staff ID -->
                        <div>
                            <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">รหัสนักศึกษา / บุคลากร</label>
                            <input type="text" id="edit_student_id" name="student_personnel_id" maxlength="15"
                                   class="premium-input" placeholder="7 หรือ 10 หลัก">
                        </div>
                        <!-- Phone -->
                        <div>
                            <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">เบอร์โทรศัพท์</label>
                            <input type="text" id="edit_phone" name="phone_number"
                                   class="premium-input" placeholder="0XXXXXXXXX">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Email -->
                        <div>
                            <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">อีเมล</label>
                            <input type="email" id="edit_email" name="email"
                                   class="premium-input" placeholder="example@rsu.ac.th">
                        </div>
                        <!-- Gender -->
                        <div>
                            <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">เพศ</label>
                            <div class="relative">
                                <select id="edit_gender" name="gender" class="premium-input appearance-none">
                                    <option value="">-- ไม่ระบุ --</option>
                                    <option value="male">ชาย</option>
                                    <option value="female">หญิง</option>
                                    <option value="other">อื่นๆ</option>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 pointer-events-none text-xs"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Department -->
                    <div>
                        <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">คณะ / หน่วยงาน</label>
                        <input type="text" id="edit_department" name="department"
                               class="premium-input" placeholder="เช่น คณะนิเทศศาสตร์, สำนักทะเบียน">
                    </div>

                    <!-- User Type -->
                    <div>
                        <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">ประเภทผู้ใช้งาน <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <select name="status" id="edit_status" required class="premium-input appearance-none"
                                    onchange="document.getElementById('edit_status_other_wrap').style.display=this.value==='other'?'block':'none'">
                                <option value="">-- เลือกประเภท --</option>
                                <option value="student">นักศึกษา (Student)</option>
                                <option value="staff">บุคลากร/อาจารย์ (Personnel)</option>
                                <option value="other">บุคคลทั่วไป (External)</option>
                            </select>
                            <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 pointer-events-none text-xs"></i>
                        </div>
                    </div>

                    <!-- Status Other (conditional) -->
                    <div id="edit_status_other_wrap" style="display:none">
                        <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2">ระบุสถานภาพ (กรณีเลือก "อื่นๆ")</label>
                        <input type="text" id="edit_status_other" name="status_other"
                               class="premium-input" placeholder="เช่น ศิษย์เก่า, ผู้ปกครอง">
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="px-8 py-6 border-t border-gray-100 flex gap-4 bg-gray-50/80 backdrop-blur-md">
                <button type="button" onclick="closeModal('edit')" 
                        class="px-8 py-3.5 rounded-2xl font-black text-gray-500 bg-white border border-gray-200 hover:bg-gray-100 hover:text-gray-700 transition-all duration-200 active:scale-95 text-sm uppercase tracking-widest shadow-sm">
                    ยกเลิก
                </button>
                <button type="submit" 
                        class="flex-1 py-3.5 rounded-2xl font-black text-white text-sm uppercase tracking-widest active:scale-95 transition-all duration-300 shadow-xl"
                        style="background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 25px rgba(245, 158, 11, 0.35);">
                    <i class="fa-solid fa-save mr-2"></i> บันทึกข้อมูล
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4" style="z-index:200;">
    <div id="viewModalBox" class="bg-white rounded-[32px] shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0 duration-300">
        <div class="px-8 py-6 premium-modal-header flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-id-card-clip text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-[900] text-gray-900 tracking-tight">ข้อมูลผู้ใช้งาน</h3>
                    <p class="text-[11px] text-gray-400 font-bold uppercase tracking-wider">ตรวจสอบประวัติและการลงทะเบียน</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('view')" class="w-10 h-10 flex items-center justify-center rounded-2xl hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-all duration-200">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        
        <div class="px-8 py-7 space-y-5 bg-white/50">
            <div class="grid grid-cols-1 gap-4">
                <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">ชื่อ-นามสกุล</span>
                    <span id="view_full_name" class="font-black text-gray-900 text-lg tracking-tight"></span>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">เลขบัตรประชาชน</span>
                        <span id="view_citizen_id" class="font-bold text-gray-700 tracking-wider text-sm"></span>
                    </div>
                    <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">รหัสประจำตัว</span>
                        <span id="view_student_id" class="font-bold text-gray-700 text-sm"></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">เบอร์โทรศัพท์</span>
                        <span id="view_phone" class="font-bold text-gray-700 text-sm"></span>
                    </div>
                    <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">อีเมล</span>
                        <span id="view_email" class="font-bold text-gray-700 text-sm break-all"></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">ประเภท</span>
                        <span id="view_status" class="font-bold text-blue-600 text-sm"></span>
                    </div>
                    <div class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">เพศ</span>
                        <span id="view_gender" class="font-bold text-gray-700 text-sm"></span>
                    </div>
                </div>

                <div id="view_dept_wrap" class="flex flex-col gap-1 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">คณะ / หน่วยงาน</span>
                    <span id="view_department" class="font-bold text-gray-700 text-sm"></span>
                </div>

                <div id="view_sother_wrap" class="flex flex-col gap-1 p-4 bg-amber-50/50 rounded-2xl border border-amber-100" style="display:none">
                    <span class="text-[10px] font-black text-amber-400 uppercase tracking-widest">ระบุสถานภาพ</span>
                    <span id="view_status_other" class="font-bold text-gray-700 text-sm"></span>
                </div>

                <div class="flex flex-col gap-1 p-4 bg-blue-50/30 rounded-2xl border border-blue-100/50">
                    <span class="text-[10px] font-black text-blue-400 uppercase tracking-widest">วันที่ลงทะเบียนเข้าระบบ</span>
                    <span id="view_created_at" class="font-bold text-gray-600 text-sm"></span>
                </div>
            </div>
        </div>

        <div class="px-8 py-6 border-t border-gray-100 bg-gray-50/80 flex justify-end">
            <button type="button" onclick="closeModal('view')" 
                    class="px-10 py-3.5 rounded-2xl font-black text-gray-600 bg-white border border-gray-200 hover:bg-gray-100 transition-all duration-200 active:scale-95 text-sm uppercase tracking-widest shadow-sm">
                รับทราบ
            </button>
        </div>
    </div>
</div>

<script>
// Premium Modal Controls
function closeModal(type) {
    const modal    = document.getElementById(type + 'Modal');
    const modalBox = document.getElementById(type + 'ModalBox');
    
    if (modalBox) {
        modalBox.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    } else {
        modal.classList.add('hidden');
    }
}

function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_full_name').value = user.full_name || '';
    document.getElementById('edit_student_id').value = user.student_personnel_id || '';
    document.getElementById('edit_citizen_id').value = user.citizen_id || '';
    document.getElementById('edit_phone').value = user.phone_number || '';
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_gender').value = user.gender || '';
    document.getElementById('edit_department').value = user.department || '';
    document.getElementById('edit_status').value = user.status || '';
    document.getElementById('edit_status_other').value = user.status_other || '';
    document.getElementById('edit_status_other_wrap').style.display = user.status === 'other' ? 'block' : 'none';
    
    const modal    = document.getElementById('editModal');
    const modalBox = document.getElementById('editModalBox');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modalBox.classList.remove('scale-95', 'opacity-0');
    }, 10);
}

function openViewModal(user) {
    var statusMap = {student:'นักศึกษา', staff:'บุคลากร/อาจารย์', teacher:'อาจารย์', other:'บุคคลทั่วไป'};
    var genderMap = {male:'ชาย', female:'หญิง', other:'อื่นๆ'};
    document.getElementById('view_full_name').innerText    = user.full_name || '-';
    document.getElementById('view_citizen_id').innerText   = user.citizen_id || '-';
    document.getElementById('view_student_id').innerText   = user.student_personnel_id || '-';
    document.getElementById('view_phone').innerText        = user.phone_number || '-';
    document.getElementById('view_email').innerText        = user.email || '-';
    document.getElementById('view_status').innerText       = statusMap[user.status] || user.status || '-';
    document.getElementById('view_gender').innerText       = genderMap[user.gender] || (user.gender ? user.gender : '-');
    document.getElementById('view_department').innerText   = user.department || '-';
    var sow = document.getElementById('view_sother_wrap');
    if (user.status === 'other' && user.status_other) {
        document.getElementById('view_status_other').innerText = user.status_other;
        sow.style.display = '';
    } else { sow.style.display = 'none'; }
    document.getElementById('view_created_at').innerText = new Date(user.created_at).toLocaleString('th-TH', {
        year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
    
    const modal    = document.getElementById('viewModal');
    const modalBox = document.getElementById('viewModalBox');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modalBox.classList.remove('scale-95', 'opacity-0');
    }, 10);
}

function filterUsers(val) {
    const rows = document.querySelectorAll('#userTable tbody tr');
    val = val.toLowerCase();
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(val) ? '' : 'none';
    });
}

// Close on backdrop click
document.getElementById('editModal').addEventListener('click', e => { if (e.target.id === 'editModal') closeModal('edit'); });
document.getElementById('viewModal').addEventListener('click', e => { if (e.target.id === 'viewModal') closeModal('view'); });
</script>

<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>
