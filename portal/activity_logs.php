<?php
// portal/activity_logs.php (Command Center Style)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

$page_title = "บันทึกกิจกรรมระบบ";

// 1. จัดการ Pagination & Filter
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 25;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';

$where = "WHERE 1=1";
$params = [];
if (!empty($search)) {
    $where .= " AND (l.action LIKE ? OR l.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

try {
    $count_sql = "SELECT COUNT(*) FROM sys_activity_logs l $where";
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    $sql = "SELECT l.*, 
                   COALESCE(a.full_name, s.full_name, 'System Activity') as actor_name,
                   COALESCE(a.username, s.username, 'system') as actor_username
            FROM sys_activity_logs l
            LEFT JOIN sys_admins a ON l.user_id = a.id
            LEFT JOIN sys_staff s ON l.user_id = s.id
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
    $total_pages = 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
</head>
<body class="bg-slate-50 min-h-screen text-slate-800 font-prompt <?= isset($_GET['embed']) ? 'p-0 sm:p-0 lg:p-0' : 'p-4 sm:p-6 lg:p-8' ?>">

<div class="max-w-7xl mx-auto animate-fade-in <?= isset($_GET['embed']) ? 'px-4 sm:px-6 lg:px-8 py-6 max-w-none' : '' ?>" style="animation: fadeIn .4s cubic-bezier(0.16, 1, 0.3, 1) both;">
    <!-- Back btn -->
    <?php if (!isset($_GET['embed'])): ?>
    <a href="index.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl hover:bg-slate-50 hover:text-emerald-600 transition-all font-bold text-sm shadow-sm mb-6 group">
        <i class="fa-solid fa-arrow-left-long group-hover:-translate-x-1 transition-transform"></i> กลับหน้า Portal
    </a>
    <?php endif; ?>

    <!-- Header -->
    <div class="mb-8 flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <div class="w-12 h-12 bg-gradient-to-br from-emerald-50 to-emerald-200 text-emerald-600 rounded-2xl flex items-center justify-center text-xl mb-4 shadow-inner border border-emerald-100">
                <i class="fa-solid fa-file-lines"></i>
            </div>
            <h1 class="text-3xl md:text-4xl font-[900] text-slate-900 tracking-tight flex items-center gap-3">
                บันทึกกิจกรรมระบบ
            </h1>
            <p class="text-[11px] uppercase tracking-[0.2em] font-black text-slate-400 mt-2">Activity Logs Monitor</p>
        </div>
        
        <form action="" method="GET" class="flex gap-2">
            <?php if (isset($_GET['embed'])): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                    placeholder="ค้นหากิจกรรม..." 
                    class="pl-9 pr-4 py-2.5 border border-slate-200 rounded-2xl text-sm font-bold focus:ring-4 focus:ring-emerald-500/10 focus:border-emerald-400 outline-none w-64 bg-white shadow-sm transition-all font-prompt">
            </div>
            <button type="submit" class="bg-emerald-600 text-white px-5 py-2.5 rounded-2xl text-sm font-black uppercase tracking-wider hover:bg-emerald-700 transition-colors shadow-sm">ค้นหา</button>
            <?php if ($search): ?>
                <a href="activity_logs.php" class="bg-slate-100 text-slate-600 px-4 py-2.5 rounded-2xl text-sm font-medium hover:bg-slate-200 transition-colors shadow-sm flex items-center"><i class="fa-solid fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Error if DB fails -->
    <?php if (isset($db_error)): ?>
        <div class="mb-6 p-6 bg-rose-50 border border-rose-100 rounded-3xl text-rose-700 flex items-start gap-4">
            <div class="w-10 h-10 bg-rose-100 rounded-xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <strong class="block font-black text-rose-900 mb-1">Database Error</strong>
                <span class="text-sm"><?= htmlspecialchars($db_error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="bg-white rounded-[24px] shadow-lg shadow-slate-200/40 border border-slate-100/60 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-48">วัน-เวลา</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-48">ผู้ดำเนินการ</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-40">กิจกรรม</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">รายละเอียด</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-32">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-20 text-center text-slate-400">
                                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <i class="fa-solid fa-folder-open text-2xl opacity-40"></i>
                                </div>
                                <p class="text-sm font-bold tracking-wide">ไม่พบประวัติกิจกรรม</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            $actionColor = "bg-gradient-to-br from-slate-50 to-slate-100 text-slate-600 border-slate-200 shadow-inner";
                            $icon = "fa-bolt text-[10px]";
                            if (strpos($log['action'], 'login') !== false) { $actionColor = "bg-gradient-to-br from-emerald-50 to-emerald-100 text-emerald-700 border-emerald-200 shadow-inner"; $icon = "fa-right-to-bracket text-emerald-600"; }
                            if (strpos($log['action'], 'delete') !== false) { $actionColor = "bg-gradient-to-br from-rose-50 to-rose-100 text-rose-700 border-rose-200 shadow-inner"; $icon = "fa-trash text-rose-500"; }
                            if (strpos($log['action'], 'update') !== false) { $actionColor = "bg-gradient-to-br from-blue-50 to-blue-100 text-blue-700 border-blue-200 shadow-inner"; $icon = "fa-pen text-blue-500"; }
                            if (strpos($log['action'], 'campaign') !== false) { $actionColor = "bg-gradient-to-br from-purple-50 to-purple-100 text-purple-700 border-purple-200 shadow-inner"; $icon = "fa-bullhorn text-purple-500"; }
                        ?>
                            <tr class="hover:bg-slate-50/80 transition-colors group">
                                <td class="px-6 py-5 text-xs text-slate-500 font-bold whitespace-nowrap">
                                    <i class="fa-regular fa-clock mr-1.5 opacity-40"></i> <?= date('d/m/Y H:i:s', strtotime($log['timestamp'])) ?>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 bg-slate-100 rounded-xl flex items-center justify-center text-slate-600 text-[11px] font-black group-hover:bg-emerald-100 group-hover:text-emerald-600 transition-colors">
                                            <?= strtoupper(mb_substr($log['actor_name'], 0, 1)) ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-black text-slate-800 tracking-tight"><?= htmlspecialchars($log['actor_name']) ?></span>
                                            <span class="text-[10px] text-slate-400 font-bold">@<?= htmlspecialchars($log['actor_username']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-[10px] font-black uppercase tracking-widest <?= $actionColor ?>">
                                        <i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="text-xs md:text-sm text-slate-800 font-bold max-w-xl leading-relaxed break-words"><?= htmlspecialchars($log['description']) ?></p>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <code class="text-[10px] bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl text-slate-500 font-mono font-bold shadow-sm"><?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-5 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
            <div class="text-[11px] font-black uppercase tracking-widest text-slate-400">
                <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $limit, $total_records)) ?> <span class="text-slate-300 mx-1">/</span> <?= number_format($total_records) ?>
            </div>
            <div class="flex gap-1.5">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="w-8 h-8 flex flex-center rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-slate-500 transition-colors flex items-center justify-center font-bold text-xs"><i class="fa-solid fa-chevron-left"></i></a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" 
                        class="w-8 h-8 flex items-center justify-center rounded-xl text-xs font-black transition-all <?= $i == $page ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/20 border-none' : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-600' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="w-8 h-8 flex flex-center rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-slate-500 transition-colors flex items-center justify-center font-bold text-xs"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<style>
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
</body>
</html>
