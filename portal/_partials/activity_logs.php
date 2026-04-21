<?php
// portal/_partials/activity_logs.php — included by portal/index.php
// $pdo is available from parent scope

$_al_page   = max(1, (int)($_GET['page'] ?? 1));
$_al_limit  = 25;
$_al_offset = ($_al_page - 1) * $_al_limit;
$_al_search = trim($_GET['al_q'] ?? '');

$_al_where  = 'WHERE 1=1';
$_al_params = [];
if ($_al_search !== '') {
    $_al_where   .= ' AND (l.action LIKE ? OR l.description LIKE ?)';
    $_al_params[] = "%$_al_search%";
    $_al_params[] = "%$_al_search%";
}

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
               COALESCE(a.username, s.username, u.student_personnel_id, 'system') AS actor_username
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
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-blue-500"></i> Activity Logs
            </h2>
            <p class="text-xs text-gray-400 mt-1">ติดตามทุกการเคลื่อนไหวและการเข้าถึงระบบ</p>
        </div>
        <form method="GET" class="flex gap-2">
            <input type="hidden" name="section" value="activity_logs">
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                <input type="text" name="al_q" value="<?= htmlspecialchars($_al_search) ?>"
                    placeholder="ค้นหากิจกรรม..."
                    class="pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm outline-none w-52 bg-white">
            </div>
            <button type="submit" class="bg-[#0052CC] text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-blue-700 transition-colors">ค้นหา</button>
            <?php if ($_al_search): ?>
            <a href="?section=activity_logs" class="bg-gray-100 text-gray-600 px-3 py-2 rounded-xl text-sm font-medium hover:bg-gray-200 flex items-center"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (isset($_al_db_error)): ?>
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm">
        <i class="fa-solid fa-triangle-exclamation mr-2"></i> DB Error: <?= htmlspecialchars($_al_db_error) ?>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-44">วัน-เวลา</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-44">ผู้ดำเนินการ</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-36">กิจกรรม</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest">รายละเอียด</th>
                        <th class="px-6 py-4 text-[11px] font-extrabold text-gray-500 uppercase tracking-widest w-28">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($_al_logs)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <div class="w-12 h-12 bg-gray-50 rounded-full flex items-center justify-center text-gray-300"><i class="fa-solid fa-box-open text-xl"></i></div>
                                <p class="text-gray-400 text-sm">ไม่พบประวัติกิจกรรม</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: foreach ($_al_logs as $log):
                        $ac = 'bg-gray-100 text-gray-600';
                        if (str_contains($log['action'], 'login'))    $ac = 'bg-green-100 text-green-700';
                        if (str_contains($log['action'], 'delete'))   $ac = 'bg-red-100 text-red-700';
                        if (str_contains($log['action'], 'update'))   $ac = 'bg-blue-100 text-blue-700';
                        if (str_contains($log['action'], 'campaign')) $ac = 'bg-purple-100 text-purple-700';
                    ?>
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="px-6 py-4 text-xs text-gray-500 whitespace-nowrap">
                            <i class="fa-regular fa-clock mr-1 opacity-60"></i>
                            <?= date('d/m/Y H:i:s', strtotime($log['timestamp'])) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 bg-blue-50 rounded-full flex items-center justify-center text-[#0052CC] text-[10px] font-bold"><?= strtoupper(substr($log['actor_name'], 0, 1)) ?></div>
                                <div>
                                    <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($log['actor_name']) ?></div>
                                    <div class="text-[10px] text-gray-400">@<?= htmlspecialchars($log['actor_username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $ac ?>"><?= htmlspecialchars($log['action']) ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-sm text-gray-600 line-clamp-2 max-w-xl"><?= htmlspecialchars($log['description']) ?></p>
                        </td>
                        <td class="px-6 py-4">
                            <code class="text-[10px] bg-gray-100 px-2 py-1 rounded text-gray-500"><?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?></code>
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
                <a href="?section=activity_logs&page=<?= $_al_page - 1 ?>&al_q=<?= urlencode($_al_search) ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400"><i class="fa-solid fa-chevron-left text-xs"></i></a>
                <?php endif; ?>
                <?php for ($i = max(1, $_al_page - 2); $i <= min($_al_total_pages, $_al_page + 2); $i++): ?>
                <a href="?section=activity_logs&page=<?= $i ?>&al_q=<?= urlencode($_al_search) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-sm <?= $i === $_al_page ? 'bg-[#0052CC] text-white font-bold' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                <?php if ($_al_page < $_al_total_pages): ?>
                <a href="?section=activity_logs&page=<?= $_al_page + 1 ?>&al_q=<?= urlencode($_al_search) ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400"><i class="fa-solid fa-chevron-right text-xs"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
