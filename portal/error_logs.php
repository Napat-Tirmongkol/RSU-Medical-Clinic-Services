<?php
// portal/error_logs.php (Command Center Style)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ─── Auto-create table if not exists ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_error_logs (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
        source     VARCHAR(300)  NOT NULL DEFAULT '',
        message    TEXT          NOT NULL,
        context    TEXT          NOT NULL DEFAULT '',
        ip_address VARCHAR(45)   NOT NULL DEFAULT '',
        user_id    INT UNSIGNED  NULL,
        created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_level      (level),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    $fatal = $e->getMessage();
}

// ─── Clear logs action ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $clearLevel = $_POST['clear_level'] ?? 'all';
    try {
        if ($clearLevel === 'all') {
            $pdo->exec("TRUNCATE TABLE sys_error_logs");
        } else {
            $stmt = $pdo->prepare("DELETE FROM sys_error_logs WHERE level = ?");
            $stmt->execute([$clearLevel]);
        }
        header('Location: error_logs.php?cleared=1');
        exit;
    } catch (PDOException $e) {
        $clear_error = $e->getMessage();
    }
}

// ─── Export action ────────────────────────────────────────────────────────────
$exportFormat = $_GET['export'] ?? '';
if (in_array($exportFormat, ['csv', 'json'], true)) {
    $expSearch = trim($_GET['search'] ?? '');
    $expLevel  = $_GET['level']  ?? '';
    $expDate   = $_GET['date']   ?? '';

    $expWhere  = "WHERE 1=1";
    $expParams = [];
    if ($expSearch !== '') {
        $expWhere   .= " AND (message LIKE ? OR source LIKE ?)";
        $expParams[] = "%$expSearch%";
        $expParams[] = "%$expSearch%";
    }
    if (in_array($expLevel, ['error','warning','info'], true)) {
        $expWhere   .= " AND level = ?";
        $expParams[] = $expLevel;
    }
    if ($expDate !== '') {
        $expWhere   .= " AND DATE(created_at) = ?";
        $expParams[] = $expDate;
    }

    $expStmt = $pdo->prepare("SELECT id, level, source, message, context, ip_address, user_id, created_at FROM sys_error_logs $expWhere ORDER BY created_at DESC LIMIT 10000");
    $expStmt->execute($expParams);
    $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'error_logs_' . date('Ymd_His');

    if ($exportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}.csv");
        header('Cache-Control: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        fputcsv($out, ['ID','Level','Source','Message','Context','IP Address','User ID','Created At']);
        foreach ($expRows as $r) {
            fputcsv($out, [
                $r['id'], $r['level'], $r['source'], $r['message'],
                $r['context'], $r['ip_address'], $r['user_id'], $r['created_at']
            ]);
        }
        fclose($out);
        exit;
    }

    if ($exportFormat === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}.json");
        header('Cache-Control: no-cache');
        echo json_encode([
            'exported_at' => date('Y-m-d H:i:s'),
            'total'       => count($expRows),
            'filters'     => ['search' => $expSearch, 'level' => $expLevel, 'date' => $expDate],
            'logs'        => $expRows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$page        = max(1, (int)($_GET['page']   ?? 1));
$limit       = 50;
$offset      = ($page - 1) * $limit;
$search      = trim($_GET['search']  ?? '');
$filterLevel = $_GET['level']   ?? '';
$filterDate  = $_GET['date']    ?? '';
$filterSource = $_GET['source'] ?? '';

$where  = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where   .= " AND (message LIKE ? OR source LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($filterLevel, ['error','warning','info'], true)) {
    $where   .= " AND level = ?";
    $params[] = $filterLevel;
}
if ($filterDate !== '') {
    $where   .= " AND DATE(created_at) = ?";
    $params[] = $filterDate;
}
if ($filterSource === 'js') {
    $where   .= " AND source LIKE '[JS]%'";
} elseif ($filterSource === 'php') {
    $where   .= " AND source NOT LIKE '[JS]%'";
}

try {
    $summary = $pdo->query("SELECT level, COUNT(*) as cnt FROM sys_error_logs GROUP BY level")->fetchAll(PDO::FETCH_KEY_PAIR);
    $todayErrors = (int)$pdo->query("SELECT COUNT(*) FROM sys_error_logs WHERE level='error' AND DATE(created_at)=CURDATE()")->fetchColumn();

    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM sys_error_logs $where");
    $stmtCnt->execute($params);
    $total      = (int)$stmtCnt->fetchColumn();
    $totalPages = (int)ceil($total / $limit);

    $sql  = "SELECT * FROM sys_error_logs $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = $e->getMessage();
    $logs = [];
    $summary = [];
    $total = 0;
    $totalPages = 0;
    $todayErrors = 0;
}

function levelBadge(string $level): string {
    return match($level) {
        'error'   => 'bg-rose-50 text-rose-700 border border-rose-200',
        'warning' => 'bg-amber-50 text-amber-700 border border-amber-200',
        default   => 'bg-blue-50 text-blue-700 border border-blue-200',
    };
}
function levelIcon(string $level): string {
    return match($level) {
        'error'   => 'fa-circle-xmark text-rose-500',
        'warning' => 'fa-triangle-exclamation text-amber-500',
        default   => 'fa-circle-info text-blue-500',
    };
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Logs - Portal</title>
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
            <div class="w-12 h-12 bg-rose-100 text-rose-600 rounded-2xl flex items-center justify-center text-xl mb-4 shadow-inner">
                <i class="fa-solid fa-bug"></i>
            </div>
            <h1 class="text-3xl md:text-4xl font-[900] text-slate-900 tracking-tight flex items-center gap-3">
                Error Logs
            </h1>
            <p class="text-[11px] uppercase tracking-[0.2em] font-black text-slate-400 mt-2">System Diagnostics</p>
        </div>
        
        <div class="flex flex-wrap items-center gap-2">
            <a href="?export=csv&<?= http_build_query($_GET) ?>" class="bg-white border border-slate-200 text-slate-600 px-4 py-2.5 rounded-2xl text-xs font-black uppercase tracking-wider hover:bg-slate-50 transition-colors shadow-sm inline-flex items-center gap-2"><i class="fa-solid fa-file-csv text-emerald-600"></i> CSV</a>
            <a href="?export=json&<?= http_build_query($_GET) ?>" class="bg-white border border-slate-200 text-slate-600 px-4 py-2.5 rounded-2xl text-xs font-black uppercase tracking-wider hover:bg-slate-50 transition-colors shadow-sm inline-flex items-center gap-2"><i class="fa-solid fa-file-code text-blue-600"></i> JSON</a>
            <button onclick="document.getElementById('clearModal').style.display='flex'" class="bg-rose-50 border border-rose-200 text-rose-600 px-4 py-2.5 rounded-2xl text-xs font-black uppercase tracking-wider hover:bg-rose-100 transition-colors shadow-sm inline-flex items-center gap-2"><i class="fa-solid fa-trash"></i> ล้าง Log</button>
        </div>
    </div>

    <!-- Alert / Messages -->
    <?php if (isset($fatal)): ?>
        <div class="mb-6 p-6 bg-rose-50 border border-rose-100 rounded-3xl text-rose-700 flex items-start gap-4">
            <div class="w-10 h-10 bg-rose-100 rounded-xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <strong class="block font-black text-rose-900 mb-1">Fatal Initialization Error</strong>
                <span class="text-sm font-medium"><?= htmlspecialchars($fatal) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['cleared'])): ?>
    <div class="mb-6 px-6 py-4 bg-emerald-50 border border-emerald-100 rounded-2xl text-emerald-700 text-sm font-black flex items-center gap-2 shadow-sm animate-fade-in" style="animation-duration: .3s;">
        <i class="fa-solid fa-check-circle text-emerald-500 text-lg"></i> ลบ Log เรียบร้อยแล้ว
    </div>
    <?php endif; ?>

    <!-- Summary Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <a href="?level=error<?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="bg-white border border-slate-100 rounded-2xl p-4 flex items-center gap-4 hover:border-rose-300 hover:shadow-md transition-all">
            <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-500 flex items-center justify-center text-lg"><i class="fa-solid fa-circle-xmark"></i></div>
            <div><div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Error</div><div class="text-xl font-black text-slate-800"><?= number_format($summary['error'] ?? 0) ?></div></div>
        </a>
        <a href="?level=warning<?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="bg-white border border-slate-100 rounded-2xl p-4 flex items-center gap-4 hover:border-amber-300 hover:shadow-md transition-all">
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center text-lg"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div><div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Warning</div><div class="text-xl font-black text-slate-800"><?= number_format($summary['warning'] ?? 0) ?></div></div>
        </a>
        <a href="?level=info<?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="bg-white border border-slate-100 rounded-2xl p-4 flex items-center gap-4 hover:border-blue-300 hover:shadow-md transition-all">
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center text-lg"><i class="fa-solid fa-info-circle"></i></div>
            <div><div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Info</div><div class="text-xl font-black text-slate-800"><?= number_format($summary['info'] ?? 0) ?></div></div>
        </a>
        <a href="?<?= isset($_GET['embed']) ? 'embed=1' : '' ?>" class="bg-white border border-slate-100 rounded-2xl p-4 flex items-center gap-4 hover:border-emerald-300 hover:shadow-md transition-all">
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center text-lg"><i class="fa-solid fa-globe"></i></div>
            <div><div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total</div><div class="text-xl font-black text-slate-800"><?= number_format(array_sum($summary)) ?></div></div>
        </a>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <?php if (isset($_GET['embed'])): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5 ml-1">ค้นหาข้อความ</label>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-emerald-500 outline-none bg-slate-50 transition-all font-prompt">
                </div>
            </div>
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5 ml-1">ระดับ (Level)</label>
                <select name="level" class="pl-4 pr-8 py-2 border border-slate-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-emerald-500 outline-none bg-slate-50 text-slate-700">
                    <option value="">ทั้งหมด</option>
                    <option value="error" <?= $filterLevel === 'error' ? 'selected' : '' ?>>🔴 Error</option>
                    <option value="warning" <?= $filterLevel === 'warning' ? 'selected' : '' ?>>🟡 Warning</option>
                    <option value="info" <?= $filterLevel === 'info' ? 'selected' : '' ?>>🔵 Info</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5 ml-1">ประเภท</label>
                <select name="source" class="pl-4 pr-8 py-2 border border-slate-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-emerald-500 outline-none bg-slate-50 text-slate-700">
                    <option value="">ทั้งหมด</option>
                    <option value="php" <?= $filterSource === 'php' ? 'selected' : '' ?>>Backend (PHP)</option>
                    <option value="js" <?= $filterSource === 'js' ? 'selected' : '' ?>>Frontend (JS)</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5 ml-1">วันที่</label>
                <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" class="px-4 py-2 border border-slate-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-emerald-500 outline-none bg-slate-50 text-slate-700">
            </div>
            <button type="submit" class="bg-emerald-600 text-white px-5 py-2 rounded-xl text-sm font-black uppercase tracking-wider hover:bg-emerald-700 transition-colors shadow-sm">กรอง</button>
            <?php if ($search || $filterLevel || $filterDate || $filterSource): ?>
                <a href="error_logs.php<?= isset($_GET['embed']) ? '?embed=1' : '' ?>" class="bg-slate-200 text-slate-600 px-4 py-2 rounded-xl text-sm font-black uppercase tracking-wider hover:bg-slate-300 transition-colors shadow-sm">ล้างค่า</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-[24px] shadow-lg shadow-slate-200/40 border border-slate-100/60 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-32">ระดับ</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-40">วัน-เวลา</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">ข้อความและรายละเอียด</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="3" class="px-6 py-20 text-center text-slate-400">
                                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <i class="fa-solid fa-check text-2xl text-emerald-400 opacity-60"></i>
                                </div>
                                <p class="text-sm font-bold tracking-wide">ไม่พบ Error Logs ตรงกับเงื่อนไข</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors group items-start">
                                <td class="px-6 py-5 whitespace-nowrap align-top">
                                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider shadow-sm <?= levelBadge($log['level']) ?>">
                                        <i class="fa-solid <?= levelIcon($log['level']) ?> text-xs"></i> <?= $log['level'] ?>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-bold mt-2 truncate w-28" title="<?= htmlspecialchars($log['source']) ?>">
                                        <?= htmlspecialchars($log['source'] ?: 'system') ?>
                                    </div>
                                </td>
                                <td class="px-6 py-5 text-xs text-slate-500 font-bold whitespace-nowrap align-top">
                                    <i class="fa-regular fa-clock mr-1 opacity-40"></i> <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                                    <div class="text-[10px] mt-1 text-slate-400 font-mono"><?= htmlspecialchars($log['ip_address'] ?? '') ?></div>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="text-sm text-slate-800 font-bold leading-relaxed mb-2 break-all"><?= htmlspecialchars($log['message']) ?></div>
                                    <?php if ($log['context']): ?>
                                        <pre class="text-[10px] text-slate-500 bg-slate-50 border border-slate-100 p-3 rounded-xl overflow-x-auto font-mono whitespace-pre-wrap"><code class="break-all"><?= htmlspecialchars($log['context']) ?></code></pre>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <?php if ($totalPages > 1): ?>
        <div class="px-6 py-5 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
            <div class="text-[11px] font-black uppercase tracking-widest text-slate-400">
                <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $limit, $total)) ?> <span class="text-slate-300 mx-1">/</span> <?= number_format($total) ?>
            </div>
            <div class="flex gap-1.5">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&level=<?= urlencode($filterLevel) ?>&date=<?= urlencode($filterDate) ?>&source=<?= urlencode($filterSource) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="w-8 h-8 flex flex-center rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-slate-500 transition-colors flex items-center justify-center font-bold text-xs"><i class="fa-solid fa-chevron-left"></i></a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&level=<?= urlencode($filterLevel) ?>&date=<?= urlencode($filterDate) ?>&source=<?= urlencode($filterSource) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" 
                        class="w-8 h-8 flex items-center justify-center rounded-xl text-xs font-black transition-all <?= $i == $page ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/20 border-none' : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-600' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&level=<?= urlencode($filterLevel) ?>&date=<?= urlencode($filterDate) ?>&source=<?= urlencode($filterSource) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" class="w-8 h-8 flex flex-center rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-slate-500 transition-colors flex items-center justify-center font-bold text-xs"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Clear Modal -->
<div id="clearModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl w-full max-w-sm overflow-hidden animate-fade-in" style="animation-duration:.2s">
        <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-rose-50/50">
            <h3 class="font-black text-rose-800 text-lg flex items-center gap-2"><i class="fa-solid fa-trash-can text-rose-500"></i> ล้างข้อมูล Log</h3>
            <button onclick="document.getElementById('clearModal').style.display='none'" class="text-slate-400 hover:text-slate-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-slate-100 transition-colors"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="clear">
            <p class="text-sm font-medium text-slate-600 mb-4">คุณต้องการลบข้อมูล Error Logs ประเภทใด? ข้อมูลที่ถูกลบไปแล้วไม่สามารถกู้คืนได้</p>
            
            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5 ml-1">เลือกระดับที่จะลบ</label>
            <select name="clear_level" class="w-full pl-4 pr-8 py-2.5 border border-slate-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-rose-500 outline-none bg-slate-50 text-slate-700 mb-6 font-prompt">
                <option value="all">ลบทั้งหมด (All Levels)</option>
                <option value="error">เฉพาะระดับ Error</option>
                <option value="warning">เฉพาะระดับ Warning</option>
                <option value="info">เฉพาะระดับ Info</option>
            </select>
            
            <div class="flex gap-3 mt-2">
                <button type="button" onclick="document.getElementById('clearModal').style.display='none'" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition-colors text-sm">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700 transition-colors text-sm shadow-sm">ยืนยันการลบ</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
</body>
</html>
