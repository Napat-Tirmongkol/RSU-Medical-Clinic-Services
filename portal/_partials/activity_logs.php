<?php
// portal/_partials/activity_logs.php — included by portal/index.php
// $pdo is available from parent scope

$_al_page   = max(1, (int)($_GET['page'] ?? 1));
$_al_limit  = 25;
$_al_offset = ($_al_page - 1) * $_al_limit;
$_al_search = trim($_GET['al_q'] ?? '');
$_al_type   = trim($_GET['al_type'] ?? '');

$_al_where  = 'WHERE 1=1';
$_al_params = [];

if ($_al_search !== '') {
    $_al_where   .= ' AND (l.action LIKE ? OR l.description LIKE ? OR l.actor_username_literal LIKE ?)';
    $_al_params[] = "%$_al_search%";
    $_al_params[] = "%$_al_search%";
    $_al_params[] = "%$_al_search%";
}

// Filter by type using subqueries or joins
if ($_al_type === 'admin')  $_al_where .= " AND l.user_id IN (SELECT id FROM sys_admins)";
if ($_al_type === 'staff')  $_al_where .= " AND l.user_id IN (SELECT id FROM sys_staff)";
if ($_al_type === 'user')   $_al_where .= " AND l.user_id IN (SELECT id FROM sys_users)";
if ($_al_type === 'system') $_al_where .= " AND l.user_id IS NULL";

$_al_logs = [];
$_al_total = 0;
$_al_total_pages = 0;
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_activity_logs l $_al_where");
    $sc->execute($_al_params);
    $_al_total = (int)$sc->fetchColumn();
    $_al_total_pages = (int)ceil($_al_total / $_al_limit);

    $sr = $pdo->prepare("
        SELECT l.*,
               COALESCE(a.full_name, s.full_name, u.full_name, 'System Activity') AS actor_name,
               COALESCE(a.username, s.username, u.student_personnel_id, 'system') AS actor_username,
               CASE 
                   WHEN a.id IS NOT NULL THEN 'admin'
                   WHEN s.id IS NOT NULL THEN 'staff'
                   WHEN u.id IS NOT NULL THEN 'user'
                   ELSE 'system'
               END AS actor_type
        FROM sys_activity_logs l
        LEFT JOIN sys_admins a ON l.user_id = a.id
        LEFT JOIN sys_staff s ON l.user_id = s.id
        LEFT JOIN sys_users u ON l.user_id = u.id
        $_al_where
        ORDER BY l.timestamp DESC
        LIMIT $_al_limit OFFSET $_al_offset
    ");
    $sr->execute($_al_params);
    $_al_logs = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_al_db_error = $e->getMessage();
}
?>

<div class="p-6">
    <!-- Header -->
    <div class="mb-8 flex flex-wrap items-end justify-between gap-6">
        <div>
            <h2 class="text-2xl font-black text-gray-900 flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                Activity Logs
            </h2>
            <p class="text-slate-500 text-sm font-medium mt-1">ติดตามทุกการเคลื่อนไหวและการเข้าถึงระบบอย่างละเอียด</p>
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-3 bg-white p-2 rounded-2xl border border-slate-100 shadow-sm">
            <input type="hidden" name="section" value="activity_logs">
            
            <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-xl border border-slate-100">
                <i class="fa-solid fa-filter text-slate-400 text-[10px]"></i>
                <select name="al_type" onchange="this.form.submit()" class="bg-transparent text-xs font-black text-slate-700 outline-none cursor-pointer">
                    <option value="">ทั้งหมด (All Roles)</option>
                    <option value="admin" <?= $_al_type === 'admin' ? 'selected' : '' ?>>เฉพาะ Admin</option>
                    <option value="staff" <?= $_al_type === 'staff' ? 'selected' : '' ?>>เฉพาะ Staff</option>
                    <option value="user"  <?= $_al_type === 'user'  ? 'selected' : '' ?>>เฉพาะ User</option>
                    <option value="system" <?= $_al_type === 'system' ? 'selected' : '' ?>>System Only</option>
                </select>
            </div>

            <div class="relative group">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300 text-xs group-focus-within:text-blue-500 transition-colors"></i>
                <input type="text" name="al_q" value="<?= htmlspecialchars($_al_search) ?>"
                    placeholder="ค้นหากิจกรรม, รายละเอียด..."
                    class="pl-10 pr-4 py-2 bg-slate-50 border border-slate-100 rounded-xl text-xs font-bold outline-none w-56 focus:ring-2 focus:ring-blue-50 focus:border-blue-200 transition-all">
            </div>

            <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-xs font-black hover:bg-black transition-all shadow-md shadow-slate-200">
                ค้นหา
            </button>
            
            <?php if ($_al_search || $_al_type): ?>
            <a href="?section=activity_logs" class="w-9 h-9 flex items-center justify-center bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-100 transition-all" title="ล้างการค้นหา">
                <i class="fa-solid fa-xmark"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (isset($_al_db_error)): ?>
    <div class="mb-6 p-4 bg-red-50 border border-red-100 rounded-2xl text-red-600 text-xs font-bold flex items-center gap-3">
        <i class="fa-solid fa-triangle-exclamation text-base"></i>
        <span>DB Error: <?= htmlspecialchars($_al_db_error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-48">วัน-เวลา</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-56">ผู้ดำเนินการ</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-40">กิจกรรม</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em]">รายละเอียด</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-32 text-right">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($_al_logs)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-24 text-center">
                            <div class="flex flex-col items-center gap-4">
                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center text-slate-200 text-3xl shadow-inner">
                                    <i class="fa-solid fa-folder-open"></i>
                                </div>
                                <div>
                                    <p class="text-slate-800 font-black text-sm">ไม่พบประวัติกิจกรรม</p>
                                    <p class="text-slate-400 text-xs font-medium mt-1">ลองเปลี่ยนคำค้นหาหรือตัวกรองใหม่</p>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php else: foreach ($_al_logs as $log):
                        // Action Color Logic
                        $ac = 'bg-slate-100 text-slate-600';
                        if (str_contains($log['action'], 'login'))    $ac = 'bg-emerald-50 text-emerald-600 border border-emerald-100';
                        if (str_contains($log['action'], 'delete'))   $ac = 'bg-rose-50 text-rose-600 border border-rose-100';
                        if (str_contains($log['action'], 'update'))   $ac = 'bg-sky-50 text-sky-600 border border-sky-100';
                        if (str_contains($log['action'], 'campaign')) $ac = 'bg-violet-50 text-violet-600 border border-violet-100';
                        
                        // Actor Type Logic
                        $typeLabel = '';
                        $typeClass = '';
                        switch($log['actor_type']) {
                            case 'admin':  $typeLabel = 'ADMIN';   $typeClass = 'bg-purple-600 text-white shadow-purple-100'; break;
                            case 'staff':  $typeLabel = 'STAFF';   $typeClass = 'bg-amber-500 text-white shadow-amber-100'; break;
                            case 'user':   $typeLabel = 'USER';    $typeClass = 'bg-blue-500 text-white shadow-blue-100'; break;
                            default:       $typeLabel = 'SYSTEM';  $typeClass = 'bg-slate-400 text-white shadow-slate-100'; break;
                        }
                    ?>
                    <tr class="hover:bg-slate-50/80 transition-all group">
                        <td class="px-6 py-5">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-slate-50 flex items-center justify-center text-slate-300 group-hover:text-blue-500 transition-colors">
                                    <i class="fa-regular fa-calendar-check text-xs"></i>
                                </div>
                                <div>
                                    <div class="text-[11px] font-black text-slate-800 leading-tight">
                                        <?= date('d M Y', strtotime($log['timestamp'])) ?>
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400">
                                        <?= date('H:i:s', strtotime($log['timestamp'])) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5">
                            <div class="flex items-center gap-3">
                                <div class="relative">
                                    <div class="w-9 h-9 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-sm font-black border-2 border-white shadow-sm overflow-hidden">
                                        <?= strtoupper(substr($log['actor_name'], 0, 1)) ?>
                                    </div>
                                    <span class="absolute -top-1 -right-1 w-3 h-3 border-2 border-white rounded-full <?= $log['actor_type'] === 'system' ? 'bg-slate-400' : 'bg-emerald-500' ?>"></span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <div class="text-sm font-black text-slate-800"><?= htmlspecialchars($log['actor_name']) ?></div>
                                        <span class="text-[9px] font-black px-1.5 py-0.5 rounded shadow-sm <?= $typeClass ?>"><?= $typeLabel ?></span>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-bold mt-0.5">@<?= htmlspecialchars($log['actor_username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5">
                            <span class="inline-block px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider <?= $ac ?>">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-5">
                            <div class="text-[13px] font-medium text-slate-600 line-clamp-2 max-w-xl group-hover:text-slate-900 transition-colors">
                                <?= htmlspecialchars($log['description']) ?>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-right">
                            <code class="text-[10px] bg-slate-50 border border-slate-100 px-2 py-1 rounded-lg text-slate-500 font-mono font-bold group-hover:bg-white transition-all">
                                <?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?>
                            </code>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($_al_total_pages > 1): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
            <div class="text-xs text-gray-500">
                <?= number_format($_al_offset + 1) ?>–<?= number_format(min($_al_offset + $_al_limit, $_al_total)) ?> / <?= number_format($_al_total) ?>
            </div>
            <div class="flex gap-1">
                <?php if ($_al_page > 1): ?>
                <a href="?section=activity_logs&page=<?= $_al_page - 1 ?>&al_q=<?= urlencode($_al_search) ?>&al_type=<?= urlencode($_al_type) ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400"><i class="fa-solid fa-chevron-left text-xs"></i></a>
                <?php endif; ?>
                <?php for ($i = max(1, $_al_page - 2); $i <= min($_al_total_pages, $_al_page + 2); $i++): ?>
                <a href="?section=activity_logs&page=<?= $i ?>&al_q=<?= urlencode($_al_search) ?>&al_type=<?= urlencode($_al_type) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-sm <?= $i === $_al_page ? 'bg-[#0052CC] text-white font-bold' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                <?php if ($_al_page < $_al_total_pages): ?>
                <a href="?section=activity_logs&page=<?= $_al_page + 1 ?>&al_q=<?= urlencode($_al_search) ?>&al_type=<?= urlencode($_al_type) ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400"><i class="fa-solid fa-chevron-right text-xs"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
