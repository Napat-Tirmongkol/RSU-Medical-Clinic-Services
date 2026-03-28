<?php
// admin/campaigns.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$message = '';
$messageType = '';

// ==========================================
// ส่วนจัดการ POST Request (เพิ่ม / แก้ไข / ลบ แคมเปญ)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $action = $_POST['action'] ?? '';
    
    $title = trim($_POST['title'] ?? '');
    $type = $_POST['type'] ?? 'other';
    $description = trim($_POST['description'] ?? '');
    $capacity = (int)($_POST['total_capacity'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $availableUntil = !empty($_POST['available_until']) ? $_POST['available_until'] : null;
    $isAutoApprove = (int)($_POST['is_auto_approve'] ?? 0); 

    // 1. สร้างแคมเปญใหม่
    if ($action === 'add' && $title && $capacity >= 0) {
        try {
            $sql = "INSERT INTO camp_list (title, type, description, total_capacity, available_until, status, is_auto_approve) 
                    VALUES (:title, :type, :description, :capacity, :until, :status, :auto_approve)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':title' => $title, ':type' => $type, ':description' => $description, 
                ':capacity' => $capacity, ':until' => $availableUntil, ':status' => $status,
                ':auto_approve' => $isAutoApprove
            ]);
            $message = "สร้างแคมเปญเรียบร้อยแล้ว!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // 2. แก้ไขแคมเปญ
    if ($action === 'edit') {
        $id = (int)($_POST['campaign_id'] ?? 0);
        if ($id > 0 && $title && $capacity >= 0) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = :id AND status IN ('booked', 'confirmed')");
                $check->execute([':id' => $id]);
                $used = (int)$check->fetchColumn();

                if ($capacity < $used) {
                    $message = "จำนวนโควต้ารวม ต้องไม่น้อยกว่าจำนวนผู้ที่ลงทะเบียนไปแล้ว ({$used} คน)";
                    $messageType = "error";
                } else {
                    $sql = "UPDATE camp_list SET title = :title, type = :type, description = :description, 
                            total_capacity = :capacity, available_until = :until, status = :status, is_auto_approve = :auto_approve WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':title' => $title, ':type' => $type, ':description' => $description, 
                        ':capacity' => $capacity, ':until' => $availableUntil, ':status' => $status, 
                        ':auto_approve' => $isAutoApprove, ':id' => $id
                    ]);
                    $message = "อัปเดตข้อมูลแคมเปญสำเร็จ!";
                    $messageType = "success";
                }
            } catch (PDOException $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }

    // 3. ลบแคมเปญ
    if ($action === 'delete') {
        $id = (int)($_POST['campaign_id'] ?? 0);
        if ($id > 0) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = :id");
                $check->execute([':id' => $id]);
                if ((int)$check->fetchColumn() > 0) {
                    $message = "ไม่สามารถลบได้ เนื่องจากมีประวัติลงทะเบียนในแคมเปญนี้แล้ว (แนะนำให้เปลี่ยนสถานะเป็นปิดชั่วคราวแทน)";
                    $messageType = "error";
                } else {
                    // ลบรอบเวลาที่เกี่ยวข้องทั้งหมดก่อนลบแคมเปญ
                    $pdo->prepare("DELETE FROM camp_slots WHERE campaign_id = :id")->execute([':id' => $id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM camp_list WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $message = "ลบแคมเปญสำเร็จ!";
                    $messageType = "success";
                }
            } catch (PDOException $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// ==========================================
// ดึงข้อมูลแคมเปญทั้งหมด
// ==========================================
$camp_list = [];
try {
    $sql = "
        SELECT 
            c.*,
            (SELECT COUNT(*) FROM camp_bookings a WHERE a.campaign_id = c.id AND a.status IN ('booked', 'confirmed')) AS used_capacity
        FROM camp_list c
        ORDER BY c.status ASC, c.created_at DESC
    ";
    $stmt = $pdo->query($sql);
    $camp_list = $stmt->fetchAll();
} catch (PDOException $e) {
    $message = "ไม่พบตารางข้อมูล กรุณาตรวจสอบ Database";
    $messageType = "error";
}

function getCampaignTypeDetails($type) {
    return match($type) {
        'vaccine' => ['label' => 'ฉีดวัคซีน', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100', 'icon' => 'fa-syringe', 'border' => 'border-blue-200'],
        'training' => ['label' => 'อบรม/สัมมนา', 'color' => 'text-purple-600', 'bg' => 'bg-purple-100', 'icon' => 'fa-chalkboard-user', 'border' => 'border-purple-200'],
        'health_check' => ['label' => 'ตรวจสุขภาพ', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-100', 'icon' => 'fa-stethoscope', 'border' => 'border-emerald-200'],
        default => ['label' => 'กิจกรรมอื่นๆ', 'color' => 'text-orange-600', 'bg' => 'bg-orange-100', 'icon' => 'fa-star', 'border' => 'border-orange-200'],
    };
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Animation */
@keyframes slideUpFade {
    0% { opacity: 0; transform: translateY(15px); }
    100% { opacity: 1; transform: translateY(0); }
}
.animate-slide-up { animation: slideUpFade 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.delay-100 { animation-delay: 0.1s; }

/* Custom Scrollbar */
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* Table enhancements */
.glass-table-container {
    background: #ffffff;
    border-radius: 24px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.03);
    border: 1px solid #f1f5f9;
    overflow: hidden;
}
.glass-tr {
    transition: all 0.3s ease;
}
.glass-tr:hover {
    background: #f8fafc;
    transform: scale(1.002);
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    z-index: 10;
    position: relative;
}

/* Modal styling */
.modal-glass {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}
</style>

<!-- HEADER SECTION -->
<?php 
$header_actions = '<button onclick="openAddModal()" class="bg-gradient-to-r from-[#0052CC] to-[#0043a8] hover:from-[#0043a8] hover:to-[#003688] text-white px-6 py-3 rounded-2xl font-bold transition-all shadow-lg hover:shadow-blue-900/20 hover:-translate-y-1 flex items-center gap-2">
    <i class="fa-solid fa-plus-circle text-lg"></i> สร้างแคมเปญใหม่
</button>';
renderPageHeader("จัดการแคมเปญ", "สร้างแคมเปญใหม่, กำหนดโควต้า, และตั้งเวลารับลงทะเบียน", $header_actions); 
?>

<!-- ALERT MESSAGES -->
<?php if ($message): ?>
    <div class="mb-6 p-4 rounded-2xl text-sm font-bold border flex items-center gap-3 animate-slide-up <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
        <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check text-green-500' : 'fa-circle-exclamation text-red-500' ?> text-lg"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- DATA TABLE -->
<div class="glass-table-container animate-slide-up delay-100 mb-10 overflow-x-auto">
    <table class="w-full text-left text-sm whitespace-nowrap">
        <thead class="bg-gray-50/80 text-gray-600 font-bold border-b border-gray-100 uppercase tracking-wider text-[11px]">
            <tr>
                <th class="px-6 py-5 rounded-tl-2xl">ชื่อแคมเปญ / ประเภท</th>
                <th class="px-6 py-5 text-center items-center"><i class="fa-regular fa-calendar mr-1"></i> เปิดรับถึงวันที่</th>
                <th class="px-6 py-5 text-center"><i class="fa-solid fa-users-viewfinder mr-1"></i> ที่นั่งคงเหลือ</th>
                <th class="px-6 py-5 text-center"><i class="fa-solid fa-toggle-on mr-1"></i> สถานะ</th>
                <th class="px-6 py-5 text-center rounded-tr-2xl sticky right-0 bg-gray-50/90 z-20 shadow-[-4px_0_10px_rgba(0,0,0,0.02)] border-l border-gray-100 backdrop-blur-sm"><i class="fa-solid fa-gear mr-1"></i> จัดการ</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php if (count($camp_list) === 0): ?>
                <tr>
                    <td colspan="5" class="px-6 py-16 text-center">
                        <div class="inline-flex flex-col items-center justify-center text-gray-400">
                            <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-3">
                                <i class="fa-solid fa-box-open text-2xl"></i>
                            </div>
                            <p class="font-medium text-gray-500">ยังไม่มีแคมเปญในระบบ</p>
                            <button onclick="openAddModal()" class="mt-4 text-[#0052CC] font-bold text-sm hover:underline">คลิกที่นี่เพื่อสร้างแคมเปญแรก</button>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($camp_list as $c): 
                    $remaining = $c['total_capacity'] - $c['used_capacity'];
                    $isLow = ($remaining <= 10 && $c['total_capacity'] > 0);
                    $typeDetails = getCampaignTypeDetails($c['type']);
                    $isExpired = $c['available_until'] && (strtotime($c['available_until']) < strtotime(date('Y-m-d')));
                    
                    $jsTitle = json_encode($c['title']);
                    $jsDesc = json_encode($c['description'] ?? '');
                ?>
                    <tr class="glass-tr group <?= ($c['status'] === 'inactive' || $isExpired) ? 'opacity-60 bg-gray-50/50' : '' ?>">
                        <td class="px-6 py-5">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 <?= $typeDetails['bg'] ?> <?= $typeDetails['color'] ?> rounded-[14px] flex items-center justify-center text-xl shrink-0 border <?= $typeDetails['border'] ?> shadow-inner">
                                    <i class="fa-solid <?= $typeDetails['icon'] ?>"></i>
                                </div>
                                <div>
                                    <div class="font-extrabold text-gray-900 text-base mb-0.5"><?= htmlspecialchars($c['title']) ?></div>
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $typeDetails['bg'] ?> <?= $typeDetails['color'] ?> uppercase tracking-wider">
                                            <?= $typeDetails['label'] ?>
                                        </span>
                                    </div>
                                    <div class="text-[12px] font-medium text-gray-500 flex items-center gap-2">
                                        <span class="bg-gray-100 px-2 py-0.5 rounded-md"><i class="fa-solid fa-users text-gray-400 mr-1"></i> รวม <?= number_format($c['total_capacity']) ?></span>
                                        <span class="bg-gray-100 px-2 py-0.5 rounded-md"><i class="fa-solid fa-user-check text-green-500 mr-1"></i> จองแล้ว <?= number_format($c['used_capacity']) ?></span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-center font-bold <?= $isExpired ? 'text-red-500' : 'text-gray-700' ?>">
                            <?= $c['available_until'] ? date('d / m / Y', strtotime($c['available_until'])) : '<span class="text-gray-300 font-medium">ไม่มีกำหนด</span>' ?>
                            <?php if ($isExpired): ?><div class="text-[11px] text-red-500 font-medium mt-1 bg-red-50 inline-block px-2 py-0.5 rounded-md">หมดเขตแล้ว</div><?php endif; ?>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full <?= $isLow ? 'bg-red-50 text-red-600 border border-red-100' : 'bg-green-50 text-green-600 border border-green-100' ?> shadow-inner">
                                <span class="font-black text-lg"><?= number_format($remaining) ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-center flex flex-col items-center justify-center gap-1.5 mt-2">
                            <?php if ($c['status'] === 'active' && !$isExpired): ?>
                                <span class="px-3 py-1 text-xs font-extrabold rounded-lg bg-emerald-100 text-emerald-700 border border-emerald-200 shadow-sm w-max">🟢 เปิดรับสมัคร</span>
                                <?php if($c['is_auto_approve']): ?>
                                    <span class="text-[10px] text-blue-600 font-bold bg-blue-50 px-2 py-0.5 rounded-md border border-blue-100"><i class="fa-solid fa-bolt text-yellow-500"></i> อนุมัติอัตโนมัติ</span>
                                <?php else: ?>
                                    <span class="text-[10px] text-gray-500 font-bold bg-gray-50 px-2 py-0.5 rounded-md border border-gray-100"><i class="fa-solid fa-user-shield text-gray-400"></i> แอดมินอนุมัติ</span>
                                <?php endif; ?>
                            <?php elseif ($isExpired): ?>
                                <span class="px-3 py-1 text-xs font-extrabold rounded-lg bg-red-100 text-red-600 border border-red-200 shadow-sm w-max">❌ หมดเขต</span>
                            <?php else: ?>
                                <span class="px-3 py-1 text-xs font-extrabold rounded-lg bg-gray-200 text-gray-600 border border-gray-300 shadow-sm w-max">⚪ ปิดชั่วคราว</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-5 text-center sticky right-0 <?= ($c['status'] === 'inactive' || $isExpired) ? 'bg-gray-50' : 'bg-white' ?> group-hover:bg-[#f8fafc] z-10 transition-colors shadow-[-4px_0_10px_rgba(0,0,0,0.02)] border-l border-gray-50">
                            <div class="flex items-center justify-center gap-2">
                                <button onclick='openEditModal(<?= $c['id'] ?>, <?= $jsTitle ?>, "<?= $c['type'] ?>", <?= $c['total_capacity'] ?>, "<?= $c['available_until'] ?>", "<?= $c['status'] ?>", <?= $jsDesc ?>, <?= $c['is_auto_approve'] ?>)' 
                                        class="w-9 h-9 bg-yellow-50 text-yellow-600 rounded-xl flex items-center justify-center hover:bg-yellow-400 hover:text-white transition-all shadow-sm border border-yellow-100" title="แก้ไขแคมเปญ">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <?php if ($c['used_capacity'] == 0): ?>
                                    <form method="POST" class="m-0" onsubmit="return confirm('ยืนยันการลบแคมเปญ <?= $jsTitle ?> ใช่หรือไม่? ข้อมูลจะไม่สามารถกู้คืนได้');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="w-9 h-9 bg-red-50 text-red-500 rounded-xl flex items-center justify-center hover:bg-red-500 hover:text-white transition-all shadow-sm border border-red-100" title="ลบแคมเปญ">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="w-9 h-9 bg-gray-100 text-gray-300 rounded-xl flex items-center justify-center cursor-not-allowed border border-gray-200" title="ไม่สามารถลบได้ มีผู้ลงทะเบียนแล้ว">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL SECTION -->
<div id="campaignModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-glass rounded-[24px] w-full max-w-xl overflow-hidden animate-slide-up my-8 border border-white/50">
        
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-white/50">
            <h3 class="text-2xl font-black text-gray-900 flex items-center gap-3" id="modal_title">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-bullhorn"></i></div>
                สร้างแคมเปญใหม่
            </h3>
            <button onclick="document.getElementById('campaignModal').classList.add('hidden')" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 hover:text-gray-800 transition-colors">
                <i class="fa-solid fa-times font-bold"></i>
            </button>
        </div>
        
        <form method="POST" class="p-6 space-y-5 bg-white">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="modal_action" value="add">
            <input type="hidden" name="campaign_id" id="modal_campaign_id">
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">ชื่อแคมเปญ/กิจกรรม <span class="text-red-500">*</span></label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                        <i class="fa-solid fa-heading"></i>
                    </div>
                    <input type="text" id="modal_title_input" name="title" required placeholder="เช่น อบรม CPR รุ่น 1" class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none font-prompt text-gray-800 transition-all font-medium placeholder-gray-400">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">ประเภทแคมเปญ <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <select id="modal_type" name="type" required class="w-full pl-11 pr-10 py-3 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none font-prompt text-gray-800 appearance-none font-medium cursor-pointer transition-all">
                            <option value="vaccine">💉 ฉีดวัคซีน</option>
                            <option value="training">👨‍🏫 อบรม/สัมมนา</option>
                            <option value="health_check">🩺 ตรวจสุขภาพ</option>
                            <option value="other">⭐ กิจกรรมอื่นๆ</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400"><i class="fa-solid fa-chevron-down text-sm"></i></div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">โควต้าผู้เข้าร่วม (คน) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <input type="number" id="modal_total_capacity" name="total_capacity" required min="0" class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none font-prompt text-gray-800 transition-all font-medium">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                    <label class="block text-sm font-bold text-gray-700 mb-2">สถานะแคมเปญ</label>
                    <div class="relative">
                        <select id="modal_status" name="status" class="w-full pl-4 pr-10 py-2.5 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-prompt text-gray-800 appearance-none font-medium cursor-pointer">
                            <option value="active">🟢 เปิดให้ลงทะเบียน</option>
                            <option value="inactive">⚪ ปิดชั่วคราว</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400"><i class="fa-solid fa-caret-down"></i></div>
                    </div>
                </div>
                <div class="bg-blue-50/50 p-4 rounded-2xl border border-blue-100/50">
                    <label class="block text-sm font-bold text-gray-700 mb-2">การอนุมัติรายชื่อผู้จอง</label>
                    <div class="relative">
                        <select id="modal_is_auto_approve" name="is_auto_approve" class="w-full pl-4 pr-10 py-2.5 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-prompt text-gray-800 appearance-none font-medium cursor-pointer">
                            <option value="0">✋ ให้แอดมินพิจารณาอนุมัติ</option>
                            <option value="1" class="text-blue-600 font-bold">⚡ อนุมัติสิทธิ์อัตโนมัติ (Auto)</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400"><i class="fa-solid fa-caret-down"></i></div>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">เปิดรับถึงวันที่ (ไม่บังคับ - ปล่อยว่างถ้าไม่มีกำหนด)</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                        <i class="fa-regular fa-calendar-xmark"></i>
                    </div>
                    <input type="date" id="modal_available_until" name="available_until" class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none font-prompt text-gray-800 transition-all font-medium">
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">รายละเอียดเงื่อนไข / สถานที่จัดงาน (ไม่บังคับ)</label>
                <div class="relative">
                    <div class="absolute top-4 left-4 pointer-events-none text-gray-400">
                        <i class="fa-solid fa-align-left"></i>
                    </div>
                    <textarea id="modal_description" name="description" rows="3" placeholder="ระบุข้อมูลที่ผู้เข้าร่วมควรทราบ..." class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-2xl focus:bg-white focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none font-prompt text-gray-800 resize-none transition-all custom-scrollbar font-medium placeholder-gray-400"></textarea>
                </div>
            </div>

            <div class="pt-2 flex gap-3">
                <button type="button" onclick="document.getElementById('campaignModal').classList.add('hidden')" class="w-1/3 bg-white border-2 border-gray-200 text-gray-700 font-bold py-3.5 rounded-2xl hover:bg-gray-50 hover:border-gray-300 transition-all">ยกเลิก</button>
                <button type="submit" id="modal_submit_btn" class="w-2/3 bg-gradient-to-r from-[#0052CC] to-[#0043a8] text-white font-bold py-3.5 rounded-2xl hover:shadow-lg hover:shadow-blue-500/30 hover:-translate-y-0.5 transition-all text-lg tracking-wide shadow-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-save"></i> <span>สร้างแคมเปญ</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modal_title').innerHTML = '<div class="w-10 h-10 bg-blue-100 text-[#0052CC] rounded-[14px] flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-bullhorn"></i></div> สร้างแคมเปญใหม่';
    document.getElementById('modal_action').value = 'add';
    document.getElementById('modal_campaign_id').value = '';
    document.getElementById('modal_title_input').value = '';
    document.getElementById('modal_type').value = 'vaccine';
    document.getElementById('modal_total_capacity').value = '0';
    document.getElementById('modal_available_until').value = '';
    document.getElementById('modal_status').value = 'active';
    document.getElementById('modal_is_auto_approve').value = '0'; 
    document.getElementById('modal_description').value = '';
    
    let btn = document.getElementById('modal_submit_btn');
    btn.innerHTML = '<i class="fa-solid fa-plus-circle"></i> <span>สร้างแคมเปญ</span>';
    btn.className = 'w-2/3 bg-gradient-to-r from-[#0052CC] to-[#0043a8] text-white font-bold py-3.5 rounded-2xl hover:shadow-lg hover:shadow-blue-500/30 hover:-translate-y-0.5 transition-all text-lg tracking-wide shadow-sm flex items-center justify-center gap-2';
    
    document.getElementById('campaignModal').classList.remove('hidden');
}

function openEditModal(id, title, type, capacity, until, status, desc, autoApprove) {
    document.getElementById('modal_title').innerHTML = '<div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-[14px] flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-pen-to-square"></i></div> <span class="text-amber-600">แก้ไขข้อมูลแคมเปญ</span>';
    document.getElementById('modal_action').value = 'edit';
    document.getElementById('modal_campaign_id').value = id;
    document.getElementById('modal_title_input').value = title;
    document.getElementById('modal_type').value = type;
    document.getElementById('modal_total_capacity').value = capacity;
    document.getElementById('modal_available_until').value = until || '';
    document.getElementById('modal_status').value = status;
    document.getElementById('modal_is_auto_approve').value = autoApprove;
    document.getElementById('modal_description').value = desc;
    
    let btn = document.getElementById('modal_submit_btn');
    btn.innerHTML = '<i class="fa-solid fa-save"></i> <span>บันทึกการแก้ไข</span>';
    btn.className = 'w-2/3 bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold py-3.5 rounded-2xl hover:shadow-lg hover:shadow-amber-500/30 hover:-translate-y-0.5 transition-all text-lg tracking-wide shadow-sm flex items-center justify-center gap-2';
    
    document.getElementById('campaignModal').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
