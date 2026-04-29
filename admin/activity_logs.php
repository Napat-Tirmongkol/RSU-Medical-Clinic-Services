<?php
// admin/activity_logs.php (V2 Portal - System Activity Log)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

$page_title = "บันทึกกิจกรรมระบบ";
$current_page = "activity_logs.php";

// 1. จัดการ Pagination & Filter
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

// Filter ตาม User เฉพาะคน
$filter_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filter_role    = strtoupper(trim($_GET['role'] ?? ''));
if (!in_array($filter_role, ['ADMIN', 'STAFF', 'USER'], true)) {
    $filter_role = '';
}

// ดึงข้อมูล Subject (ผู้ใช้ที่เรากำลังดู log) ถ้ามี
$subject = null;
if ($filter_user_id > 0 && $filter_role !== '') {
    try {
        if ($filter_role === 'ADMIN') {
            $stmt = $pdo->prepare("SELECT id, full_name, username, 'ADMIN' AS role FROM sys_admins WHERE id = ? LIMIT 1");
        } elseif ($filter_role === 'STAFF') {
            $stmt = $pdo->prepare("SELECT id, full_name, username, 'STAFF' AS role FROM sys_staff WHERE id = ? LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name, student_personnel_id AS username, 'USER' AS role FROM sys_users WHERE id = ? LIMIT 1");
        }
        $stmt->execute([$filter_user_id]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $subject = null;
    }
}

// Where clause สำหรับ search + user filter
$where = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (l.action LIKE ? OR l.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($subject) {
    // กรองทั้ง user_id และให้ตรงกับ JOIN ที่ตรงกับ role เพื่อกัน id ชนกันข้ามตาราง
    if ($filter_role === 'ADMIN') {
        $where .= " AND l.user_id = ? AND a.id IS NOT NULL";
    } elseif ($filter_role === 'STAFF') {
        $where .= " AND l.user_id = ? AND s.id IS NOT NULL";
    } else {
        $where .= " AND l.user_id = ? AND u.id IS NOT NULL";
    }
    $params[] = $filter_user_id;
}

try {
    // 2. ดึงจำนวนทั้งหมด (เพื่อทำ Pagination) — ต้อง JOIN เหมือน query หลักเพราะ filter ใช้คอลัมน์ของ JOIN
    $count_sql = "SELECT COUNT(*) FROM sys_activity_logs l
                  LEFT JOIN sys_admins a ON l.user_id = a.id
                  LEFT JOIN sys_staff s  ON l.user_id = s.id
                  LEFT JOIN sys_users u  ON l.user_id = u.id
                  $where";
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_records = (int)$stmt_count->fetchColumn();
    $total_pages = max(1, (int)ceil($total_records / $limit));
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $limit;
    }

    // 3. ดึงข้อมูล Log
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
    $db_error = $e->getMessage();
    $logs = [];
    $total_records = 0;
    $total_pages = 1;
}

// helper: สร้าง URL คงค่าฟิลเตอร์
$buildUrl = function (int $p, bool $keepSearch = true) use ($search, $filter_user_id, $filter_role) {
    $qs = ['page' => $p];
    if ($keepSearch && $search !== '') $qs['search']  = $search;
    if ($filter_user_id)               $qs['user_id'] = $filter_user_id;
    if ($filter_role)                  $qs['role']    = $filter_role;
    return '?' . http_build_query($qs);
};

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
// ACTION BAR HTML — เก็บค่า user_id/role ไว้ใน hidden input เพื่อให้ค้นหาภายใต้ user เดิม
$hidden_filter = '';
if ($filter_user_id) $hidden_filter .= '<input type="hidden" name="user_id" value="' . (int)$filter_user_id . '">';
if ($filter_role)    $hidden_filter .= '<input type="hidden" name="role" value="' . htmlspecialchars($filter_role) . '">';

$header_actions = '
<form action="" method="GET" class="flex gap-2">
    ' . $hidden_filter . '
    <div class="relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
        <input type="text" name="search" value="' . htmlspecialchars($search) . '"
            placeholder="ค้นหากิจกรรม..."
            class="pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 outline-none w-64 bg-white shadow-sm transition-all font-prompt">
    </div>
    <button type="submit" class="bg-[#0052CC] text-white px-5 py-2 rounded-xl text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm">
        ค้นหา
    </button>' . ($search ? '<a href="' . htmlspecialchars($buildUrl(1, false)) . '" class="bg-gray-100 text-gray-600 px-3 py-2 rounded-xl text-sm font-medium hover:bg-gray-200 transition-colors shadow-sm flex items-center" title="ล้างคำค้น"><i class="fa-solid fa-times"></i></a>' : '') . '
</form>';

$header_subtitle = $subject
    ? 'กำลังแสดงบันทึกกิจกรรมเฉพาะของ ' . htmlspecialchars($subject['full_name'] ?? '-')
    : 'ติดตามทุกการเคลื่อนไหวและการเข้าถึงระบบเพื่อความปลอดภัย';

renderPageHeader("บันทึกกิจกรรมระบบ (Activity Logs)", $header_subtitle, $header_actions);
?>

<?php if ($subject):
    $subjectRoleColors = [
        'ADMIN'  => 'bg-rose-600 text-white',
        'STAFF'  => 'bg-amber-500 text-white',
        'USER'   => 'bg-[#0052CC] text-white',
    ];
    $subjectRoleClass = $subjectRoleColors[$subject['role']] ?? 'bg-gray-400 text-white';
?>
<div class="mb-6 animate-slide-up">
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex flex-col md:flex-row md:items-center gap-5">
        <div class="flex-shrink-0 w-14 h-14 rounded-2xl bg-gradient-to-br from-[#0052CC] to-[#0070f3] flex items-center justify-center shadow-md">
            <span class="text-white text-xl font-bold">
                <?= htmlspecialchars(mb_substr($subject['full_name'] ?? '?', 0, 1)) ?>
            </span>
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-lg font-bold text-gray-900 truncate"><?= htmlspecialchars($subject['full_name'] ?: 'ไม่ระบุชื่อ') ?></h2>
                <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider <?= $subjectRoleClass ?>">
                    <?= htmlspecialchars($subject['role']) ?>
                </span>
            </div>
            <div class="flex flex-wrap gap-4 mt-1 text-sm text-gray-500">
                <span class="font-mono text-xs">@<?= htmlspecialchars($subject['username'] ?: '-') ?></span>
                <span><i class="fa-solid fa-list-check text-xs mr-1 text-gray-400"></i><?= number_format($total_records) ?> กิจกรรม</span>
            </div>
        </div>
        <a href="activity_logs.php" class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-[#0052CC] transition-colors">
            <i class="fa-solid fa-xmark"></i> ล้างฟิลเตอร์
        </a>
    </div>
</div>
<?php endif; ?>

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
        <?php if ($total_records > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-col md:flex-row items-center justify-between gap-3">
            <div class="text-xs text-gray-500">
                หน้า <span class="font-bold text-gray-700"><?= $page ?></span> / <?= $total_pages ?>
                · รวม <span class="font-bold text-gray-700"><?= number_format($total_records) ?></span> รายการ
                · แสดง <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_records)) ?>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="flex gap-1 flex-wrap justify-center">
                <a href="<?= htmlspecialchars($buildUrl(1)) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $page === 1 ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="หน้าแรก" aria-label="หน้าแรก">
                    <i class="fa-solid fa-angles-left text-[10px]"></i>
                </a>
                <a href="<?= htmlspecialchars($buildUrl(max(1, $page - 1))) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $page === 1 ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="ก่อนหน้า" aria-label="ก่อนหน้า">
                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                </a>

                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="<?= htmlspecialchars($buildUrl($i)) ?>"
                        class="w-9 h-9 flex items-center justify-center rounded-lg text-sm transition-all <?= $i == $page ? 'bg-[#0052CC] text-white font-bold shadow-md' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <a href="<?= htmlspecialchars($buildUrl(min($total_pages, $page + 1))) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $page === $total_pages ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="ถัดไป" aria-label="ถัดไป">
                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                </a>
                <a href="<?= htmlspecialchars($buildUrl($total_pages)) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $page === $total_pages ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="หน้าสุดท้าย" aria-label="หน้าสุดท้าย">
                    <i class="fa-solid fa-angles-right text-[10px]"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
include 'includes/footer.php';
?>
