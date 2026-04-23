<?php
// return_dashboard.php (อัปเดต V3.2 - แก้ไข SQL และค่าคงที่ป้องกัน 500 Error)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../config.php');

$pdo = db();

// 2. ตรวจสอบสิทธิ์ (อนุญาต Admin, Employee และ Editor)
$allowed_roles = ['admin', 'employee', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 3. (SQL) ดึงข้อมูลอุปกรณ์ที่ถูกยืม
$borrowed_items = [];
try {
    // แก้ไข SQL: Join borrow_categories เพื่อเอาชื่ออุปกรณ์
    $sql = "SELECT 
                t.id as transaction_id, 
                t.equipment_id, 
                t.due_date, 
                t.fine_status,
                bc.name as equipment_name, 
                ei.serial_number as equipment_serial,
                s.id as student_id, 
                s.full_name as borrower_name, 
                s.phone_number as borrower_contact,
                t.borrow_date, 
                DATEDIFF(CURDATE(), t.due_date) AS days_overdue
            FROM borrow_records t
            JOIN borrow_categories bc ON t.type_id = bc.id
            JOIN borrow_items ei ON t.equipment_id = ei.id
            LEFT JOIN sys_users s ON t.borrower_student_id = s.id
            WHERE t.status = 'borrowed'
              AND t.approval_status IN ('approved', 'staff_added') 
            ORDER BY t.due_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $borrowed_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // บันทึกลง Log เพื่อตรวจสอบ
    error_log("DB Error in return_dashboard: " . $e->getMessage());
    die("เกิดข้อผิดพลาดในการดึงข้อมูล โปรดตรวจสอบ Log ของเซิร์ฟเวอร์");
}

// 4. ตั้งค่าตัวแปรสำหรับ Header
$page_title = "คืนอุปกรณ์";
$current_page = "return"; 

// 5. เรียกใช้ Header
include('../includes/header.php'); 
?>

<div class="p-4 sm:p-8 max-w-7xl mx-auto">
    <!-- Header Section -->
    <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 animate-slide-up">
        <div>
            <h2 class="text-3xl font-black text-gray-900 flex items-center gap-3">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center text-xl shadow-sm">
                    <i class="fas fa-undo-alt"></i>
                </div>
                <span>รายการอุปกรณ์ที่ต้องรับคืน</span>
            </h2>
            <p class="text-gray-500 mt-2 text-sm font-medium">จัดการรับคืนอุปกรณ์และตรวจสอบค่าปรับ (Real-time)</p>
        </div>
    </div>

    <!-- Mobile-First List / Desktop Table Container -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 animate-slide-up delay-100">
        <?php if (empty($borrowed_items)): ?>
            <div class="col-span-full bg-white rounded-[24px] border-2 border-dashed border-gray-100 p-16 flex flex-col items-center justify-center text-center">
                <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center text-gray-300 mb-4">
                    <i class="fas fa-box-open text-4xl"></i>
                </div>
                <p class="text-gray-400 font-bold text-lg">ไม่มีอุปกรณ์ที่กำลังถูกยืมในขณะนี้</p>
                <p class="text-gray-300 text-sm mt-1">รายการอุปกรณ์ที่ถูกยืมทั้งหมดจะแสดงที่นี่</p>
            </div>
        <?php else: ?>
            <?php foreach ($borrowed_items as $row): 
                $days_overdue = (int)$row['days_overdue'];
                if ($days_overdue < 0) $days_overdue = 0;
                $is_overdue = ($days_overdue > 0);
                $is_fine_paid = ($row['fine_status'] == 'paid');
                $calculated_fine = $days_overdue * FINE_RATE_PER_DAY;
            ?>
                <!-- CARD ITEM -->
                <div class="group relative bg-white rounded-[28px] border border-gray-100 shadow-sm hover:shadow-xl hover:border-blue-200 transition-all duration-300 overflow-hidden flex flex-col">
                    
                    <!-- Top Badge (Status) -->
                    <?php if ($is_overdue): ?>
                        <div class="absolute top-4 right-4 bg-red-500 text-white px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider animate-pulse z-10">
                            เกินกำหนดคืน
                        </div>
                    <?php endif; ?>

                    <!-- Content Section -->
                    <div class="p-6 flex-1">
                        <div class="flex items-start gap-4 mb-5">
                            <div class="w-14 h-14 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-400 group-hover:bg-blue-50 group-hover:text-blue-500 transition-colors">
                                <i class="fas fa-box text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="font-black text-gray-900 text-lg leading-tight mb-1"><?php echo htmlspecialchars($row['equipment_name']); ?></h3>
                                <p class="text-xs font-mono text-gray-400 bg-gray-50 px-2 py-0.5 rounded w-max border border-gray-100">
                                    S/N: <?php echo htmlspecialchars($row['equipment_serial'] ?? '-'); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Info Grid -->
                        <div class="space-y-3 pt-4 border-t border-gray-50">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-400 font-medium">ผู้ยืม:</span>
                                <span class="text-gray-900 font-bold"><?php echo htmlspecialchars($row['borrower_name'] ?? '[N/A]'); ?></span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-400 font-medium">วันที่ยืม:</span>
                                <span class="text-gray-600"><?php echo date('d M Y', strtotime($row['borrow_date'])); ?></span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-400 font-medium">กำหนดคืน:</span>
                                <span class="font-black <?php echo $is_overdue ? 'text-red-500 bg-red-50 px-2 py-0.5 rounded' : 'text-emerald-600'; ?>">
                                    <?php echo date('d M Y', strtotime($row['due_date'])); ?>
                                    <?php if($is_overdue): ?>
                                        <span class="text-[10px] block text-right">(เกิน <?php echo $days_overdue; ?> วัน)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Section -->
                    <div class="p-4 bg-slate-50/50 border-t border-gray-100 mt-auto">
                        <?php if ($is_overdue && !$is_fine_paid): ?>
                            <button type="button" 
                                    class="w-full bg-red-600 hover:bg-red-700 text-white rounded-2xl px-6 py-4 font-bold shadow-lg shadow-red-200 transition-all flex items-center justify-center gap-3 group/btn"
                                    onclick="openFineAndReturnPopup(
                                        <?php echo $row['transaction_id']; ?>,
                                        <?php echo $row['student_id'] ?? 0; ?>,
                                        '<?php echo htmlspecialchars(addslashes($row['borrower_name'] ?? '[N/A]')); ?>',
                                        '<?php echo htmlspecialchars(addslashes($row['equipment_name'] ?? 'N/A')); ?>',
                                        <?php echo $days_overdue; ?>,
                                        <?php echo $calculated_fine; ?>,
                                        <?php echo $row['equipment_id']; ?> 
                                    )">
                                <i class="fas fa-coins text-lg group-hover/btn:rotate-12 transition-transform"></i>
                                <span>คืนของ/ชำระค่าปรับ</span>
                            </button>
                        <?php else: ?>
                            <button type="button" 
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-2xl px-6 py-4 font-bold shadow-lg shadow-blue-200 transition-all flex items-center justify-center gap-3 group/btn"
                                    onclick="openReturnPopup(<?php echo $row['equipment_id']; ?>)">
                                <i class="fas fa-undo text-lg group-hover/btn:-rotate-45 transition-transform"></i>
                                <span>รับคืนอุปกรณ์</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    /* CSS เพิ่มเติมสำหรับหน้านี้โดยเฉพาะ */
    .admin-wrap { background: transparent !important; }
    
    @keyframes slide-up {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-slide-up { animation: slide-up 0.5s ease-out forwards; }
    .delay-100 { animation-delay: 0.1s; }

    /* Dark Mode Support */
    body.dark-mode .text-gray-900 { color: #f8fafc !important; }
    body.dark-mode .text-gray-600 { color: #cbd5e1 !important; }
    body.dark-mode .text-gray-500 { color: #94a3b8 !important; }
    body.dark-mode .bg-white { background-color: #1e293b !important; border-color: #334155 !important; }
    body.dark-mode .bg-slate-50\/50 { background-color: rgba(30, 41, 59, 0.5) !important; }
    body.dark-mode .border-gray-100, 
    body.dark-mode .border-gray-50 { border-color: #334155 !important; }
    body.dark-mode .bg-gray-50 { background-color: #0f172a !important; color: #94a3b8 !important; }
</style>

<?php
include('../includes/footer.php'); 
?>
