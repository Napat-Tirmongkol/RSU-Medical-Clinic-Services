<?php
// admin/activity_logs.php (V2 Portal - System Activity Log)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

$page_title = "บันทึกกิจกรรมระบบ";
$current_page = "activity_logs.php";

// 1. จัดการ Pagination & Filter
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 25;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';

$where = "WHERE l.clinic_id = ?";
$params = [clinic_id()];
if (!empty($search)) {
    $where .= " AND (l.action LIKE ? OR l.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

try {
    // 2. ดึงจำนวนทั้งหมด (เพื่อทำ Pagination)
    $count_sql = "SELECT COUNT(*) FROM sys_activity_logs l $where";
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // 3. ดึงข้อมูล Log (JOIN sys_admins, sys_staff และ sys_users เพื่อความครอบคลุม)
    $sql = "SELECT l.*, 
                   COALESCE(a.full_name, s.full_name, u.full_name, 'System Activity') as actor_name,
                   COALESCE(a.username, s.username, u.student_personnel_id, 'system') as actor_username,
                   CASE 
                       WHEN a.id IS NOT NULL THEN 'ADMIN'
                       WHEN s.id IS NOT NULL THEN 'STAFF'
                       WHEN u.id IS NOT NULL THEN 'USER'
                       ELSE 'SYSTEM'
                   END as actor_role
            FROM sys_activity_logs l
            LEFT JOIN sys_admins a ON l.user_id = a.id
            LEFT JOIN sys_staff s  ON l.user_id = s.id
            LEFT JOIN sys_users u  ON l.user_id = u.id
            $where
            ORDER BY l.timestamp DESC 
            LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ถ้ามีปัญหาที่ Database (เช่น ตารางไม่มีอยู่จริง) ให้แสดง Error และหยุดการทำงานเบื้องต้นเพื่อให้ตรวจสอบได้
    $db_error = $e->getMessage();
    $logs = [];
    $total_records = 0;
    $total_pages = 0;
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($db_error)): ?>
    <div class="mb-6 p-6 bg-red-50 border-2 border-red-200 rounded-3xl animate-slide-up">
        <div class="flex items-center gap-4 text-red-600">
            <div class="w-12 h-12 bg-red-100 rounded-2xl flex items-center justify-center text-2xl">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <h3 class="font-black text-lg">ตรวจพบข้อผิดพลาดที่ฐานข้อมูล</h3>
                <p class="text-sm opacity-80 font-medium">ไม่สามารถโหลดบันทึกกิจกรรมได้ในขณะนี้: <?= htmlspecialchars($db_error) ?></p>
            </div>
        </div>
        <div class="mt-4 p-3 bg-white/50 rounded-xl text-xs font-mono text-red-800 break-all">
            SQL Error: <?= htmlspecialchars($db_error) ?>
        </div>
        <p class="mt-4 text-xs text-red-500 font-bold"><i class="fa-solid fa-triangle-exclamation text-red-500 mr-1"></i> คำแนะนำ: ตรวจสอบว่ามีตาราง "sys_activity_logs" ในฐานข้อมูล "e-campaignv2_db" หรือยัง</p>
    </div>
<?php endif; ?>

<?php
// ACTION BAR HTML
$header_actions = '
<form action="" method="GET" class="flex gap-2">
    <div class="relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
        <input type="text" name="search" value="' . htmlspecialchars($search) . '" 
            placeholder="ค้นหากิจกรรม..." 
            class="pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 outline-none w-64 bg-white shadow-sm transition-all font-prompt">
    </div>
    <button type="submit" class="bg-[#0052CC] text-white px-5 py-2 rounded-xl text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm">
        ค้นหา
    </button>' . ($search ? '<a href="activity_logs.php" class="bg-gray-100 text-gray-600 px-3 py-2 rounded-xl text-sm font-medium hover:bg-gray-200 transition-colors shadow-sm flex items-center"><i class="fa-solid fa-times"></i></a>' : '') . '
</form>';

renderPageHeader("บันทึกกิจกรรมระบบ (Activity Logs)", "ติดตามทุกการเคลื่อนไหวและการเข้าถึงระบบเพื่อความปลอดภัย", $header_actions); 
?>
<div class="animate-slide-up delay-100">

    <!-- Log Table Section -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-48">วัน-เวลา</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-48">ผู้ดำเนินการ</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-40">กิจกรรม (Action)</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest">รายละเอียด</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-32">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center gap-2">
                                    <div class="w-12 h-12 bg-gray-50 rounded-full flex items-center justify-center text-gray-300">
                                        <i class="fa-solid fa-box-open text-xl"></i>
                                    </div>
                                    <p class="text-gray-400 text-sm">ไม่พบประวัติกิจกรรมในช่วงนี้</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            // สีของ Badge ตามประเภทกิจกรรม
                            $actionColor = "bg-gray-50 text-gray-400 border-gray-100";
                            if (strpos(strtolower($log['action']), 'login') !== false) $actionColor = "bg-emerald-50 text-emerald-600 border-emerald-100";
                            if (strpos(strtolower($log['action']), 'delete') !== false) $actionColor = "bg-rose-50 text-rose-600 border-rose-100";
                            if (strpos(strtolower($log['action']), 'update') !== false) $actionColor = "bg-sky-50 text-sky-600 border-sky-100";
                            if (strpos(strtolower($log['action']), 'campaign') !== false) $actionColor = "bg-indigo-50 text-indigo-600 border-indigo-100";
                            
                            $roleColors = [
                                'ADMIN'  => 'bg-rose-600 text-white',
                                'STAFF'  => 'bg-amber-500 text-white',
                                'USER'   => 'bg-[#0052CC] text-white',
                                'SYSTEM' => 'bg-gray-400 text-white'
                            ];
                            $roleClass = $roleColors[$log['actor_role']] ?? 'bg-gray-400 text-white';
                        ?>
                            <tr class="hover:bg-gray-50/60 transition-colors group border-b border-gray-50 last:border-0">
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-xl bg-gray-50 flex items-center justify-center text-gray-300 shrink-0">
                                            <i class="fa-regular fa-calendar-check text-sm"></i>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-[13px] font-bold text-gray-800 leading-tight"><?= date('d M Y', strtotime($log['timestamp'])) ?></span>
                                            <span class="text-[11px] font-medium text-gray-400 mt-0.5"><?= date('H:i:s', strtotime($log['timestamp'])) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="relative shrink-0">
                                            <div class="w-10 h-10 bg-white border border-gray-100 rounded-full flex items-center justify-center text-gray-400 text-sm font-bold shadow-sm overflow-hidden">
                                                <i class="fa-solid fa-user-astronaut"></i>
                                            </div>
                                            <!-- Online Indicator Dot -->
                                            <div class="absolute -bottom-0.5 -left-0.5 w-3.5 h-3.5 bg-white rounded-full flex items-center justify-center shadow-sm">
                                                <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></div>
                                            </div>
                                        </div>
                                        <div class="flex flex-col min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="text-[14px] font-bold text-gray-900 truncate"><?= htmlspecialchars($log['actor_name']) ?></span>
                                                <span class="px-1.5 py-0.5 rounded-[4px] text-[9px] font-black uppercase tracking-wider <?= $roleClass ?>">
                                                    <?= $log['actor_role'] ?>
                                                </span>
                                            </div>
                                            <span class="text-[11px] text-gray-400 font-mono mt-0.5">@<?= htmlspecialchars($log['actor_username']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-[0.05em] border shadow-sm <?= $actionColor ?>">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="text-[13px] text-gray-600 font-medium leading-relaxed"><?= htmlspecialchars($log['description']) ?></p>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="bg-white border border-gray-100 rounded-2xl px-3 py-1.5 shadow-sm inline-flex items-center">
                                        <code class="text-[10px] font-bold text-gray-500 tracking-tight"><?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?></code>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
            <div class="text-xs text-gray-500">
                แสดงผล <span class="font-bold text-gray-700"><?= number_format($offset + 1) ?> - <?= number_format(min($offset + $limit, $total_records)) ?></span> จากทั้งหมด <?= number_format($total_records) ?> รายการ
            </div>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-500 transition-all"><i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" 
                        class="w-10 h-10 flex items-center justify-center rounded-lg text-sm transition-all <?= $i == $page ? 'bg-[#0052CC] text-white font-bold shadow-md' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-500 transition-all"><i class="fa-solid fa-chevron-right text-[10px]"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php 
include 'includes/footer.php';
?>
