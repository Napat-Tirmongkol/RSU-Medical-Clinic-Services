<?php
// admin/error_logs.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ─── Auto-create tables ───────────────────────────────────────────────────────
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
        notified_at DATETIME     NULL DEFAULT NULL,
        INDEX idx_level      (level),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_settings (
        `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
        `value`    TEXT         NOT NULL DEFAULT '',
        updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    $fatal = $e->getMessage();
}

// ─── Save alert email setting ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_alert_email') {
    $emailVal = trim($_POST['alert_email'] ?? '');
    $isValid = true;
    if ($emailVal !== '') {
        $emails = array_map('trim', explode(',', $emailVal));
        foreach ($emails as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $isValid = false; break;
            }
        }
    }
    
    if (!$isValid) {
        $setting_error = 'รูปแบบอีเมลไม่ถูกต้อง (กรณีหลายอีเมลให้คั่นด้วยคอมม่า)';
    } else {
        $pdo->prepare("INSERT INTO sys_settings (`key`, `value`) VALUES ('admin_alert_email', ?)
                       ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$emailVal]);
        header('Location: error_logs.php?saved=1' . (isset($_GET['embed']) ? '&embed=1' : ''));
        exit;
    }
}

// ─── Load current alert email ─────────────────────────────────────────────────
$currentAlertEmail = (string)($pdo->query(
    "SELECT `value` FROM sys_settings WHERE `key` = 'admin_alert_email' LIMIT 1"
)->fetchColumn() ?: '');

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
        header('Location: error_logs.php?cleared=1' . (isset($_GET['embed']) ? '&embed=1' : ''));
        exit;
    } catch (PDOException $e) {
        $clear_error = $e->getMessage();
    }
}

// ─── Export action ────────────────────────────────────────────────────────────
$exportFormat = $_GET['export'] ?? '';
if (in_array($exportFormat, ['csv', 'json'], true)) {
    // ใช้ filter เดิม แต่ดึงข้อมูลทั้งหมด (ไม่ paginate)
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
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM สำหรับ Excel
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

$filterSource = $_GET['source'] ?? ''; // 'js' หรือ 'php' หรือ ''

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
    // Counts per level (for summary badges)
    $summary = $pdo->query(
        "SELECT level, COUNT(*) as cnt FROM sys_error_logs GROUP BY level"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $todayErrors = (int)$pdo->query(
        "SELECT COUNT(*) FROM sys_error_logs WHERE level='error' AND DATE(created_at)=CURDATE()"
    )->fetchColumn();

    // Paginate
    $total   = (int)$pdo->prepare("SELECT COUNT(*) FROM sys_error_logs $where")
                         ->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM sys_error_logs $where") : null;
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

// ─── Helpers ──────────────────────────────────────────────────────────────────
function levelBadge(string $level): string {
    return match($level) {
        'error'   => 'bg-red-100 text-red-700 border border-red-200',
        'warning' => 'bg-amber-100 text-amber-700 border border-amber-200',
        default   => 'bg-blue-100 text-blue-700 border border-blue-200',
    };
}
function levelIcon(string $level): string {
    return match($level) {
        'error'   => 'fa-circle-xmark text-red-500',
        'warning' => 'fa-triangle-exclamation text-amber-500',
        default   => 'fa-circle-info text-blue-500',
    };
}

$page_title   = "Error Logs";
$current_page = "error_logs.php";
require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($fatal)): ?>
<div class="mb-6 p-5 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm">
    <strong>ไม่สามารถสร้างตาราง sys_error_logs ได้:</strong> <?= htmlspecialchars($fatal) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['cleared'])): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-2xl text-green-700 text-sm flex items-center gap-2">
    <i class="fa-solid fa-check-circle"></i> ลบ log เรียบร้อยแล้ว
</div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-2xl text-green-700 text-sm flex items-center gap-2">
    <i class="fa-solid fa-check-circle"></i> บันทึกการตั้งค่าเรียบร้อยแล้ว
</div>
<?php endif; ?>

<?php
// Build filter query string (ไม่รวม page)
$filterQs = http_build_query(array_filter([
    'search' => $search,
    'level'  => $filterLevel,
    'date'   => $filterDate,
]));

renderPageHeader(
    '<i class="fa-solid fa-bug mr-2 text-red-500"></i> Error Logs',
    'บันทึกข้อผิดพลาดและ PHP errors ทั้งหมดในระบบ'
);
?>

<!-- ─── Summary Cards ─────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 animate-slide-up">
    <?php
    $cards = [
        ['label'=>'Errors วันนี้',  'value'=>$todayErrors,           'icon'=>'fa-circle-xmark',         'color'=>'red'],
        ['label'=>'Total Errors',   'value'=>$summary['error']??0,   'icon'=>'fa-triangle-exclamation',  'color'=>'red'],
        ['label'=>'Warnings',       'value'=>$summary['warning']??0, 'icon'=>'fa-circle-exclamation',    'color'=>'amber'],
        ['label'=>'Info',           'value'=>$summary['info']??0,    'icon'=>'fa-circle-info',           'color'=>'blue'],
    ];
    foreach ($cards as $c):
        $colorMap = [
            'red'   => ['bg-red-50',   'text-red-500',   'text-red-700'],
            'amber' => ['bg-amber-50', 'text-amber-500', 'text-amber-700'],
            'blue'  => ['bg-blue-50',  'text-blue-500',  'text-blue-700'],
        ];
        [$bg, $ic, $tx] = $colorMap[$c['color']];
    ?>
    <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex items-center gap-4">
        <div class="w-11 h-11 <?= $bg ?> rounded-xl flex items-center justify-center shrink-0">
            <i class="fa-solid <?= $c['icon'] ?> <?= $ic ?> text-lg"></i>
        </div>
        <div>
            <p class="text-2xl font-black <?= $tx ?>"><?= number_format((int)$c['value']) ?></p>
            <p class="text-xs text-gray-400 font-semibold mt-0.5"><?= $c['label'] ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ─── Alert Email Settings ──────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6 animate-slide-up">
    <div class="flex items-center gap-3 mb-4">
        <div class="w-9 h-9 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
            <i class="fa-solid fa-bell text-blue-500 text-sm"></i>
        </div>
        <div>
            <p class="text-sm font-bold text-gray-800">แจ้งเตือน Error ทางอีเมล</p>
            <p class="text-xs text-gray-400">ส่ง Error Digest ทุก 30 นาทีเมื่อมี error ใหม่ในระบบ</p>
        </div>
    </div>
    <form method="POST" class="flex flex-wrap gap-3 items-end">
        <input type="hidden" name="action" value="save_alert_email">
        <div class="flex-1 min-w-[220px]">
            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">อีเมล Admin (คั่นด้วยคอมม่า , เพื่อส่งหลายคน)</label>
            <div class="relative">
                <i class="fa-solid fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input type="text" name="alert_email" value="<?= htmlspecialchars($currentAlertEmail) ?>"
                    placeholder="admin1@example.com, admin2@example.com"
                    class="w-full pl-8 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all">
            </div>
            <?php if (isset($setting_error)): ?>
                <p class="text-xs text-red-500 font-semibold mt-1"><?= htmlspecialchars($setting_error) ?></p>
            <?php endif; ?>
        </div>
        <button type="submit"
            class="px-5 py-2.5 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-floppy-disk"></i> บันทึก
        </button>
        <?php if ($currentAlertEmail): ?>
        <div class="flex items-center gap-2 text-xs text-emerald-700 font-semibold px-4 py-2.5 bg-emerald-50 border border-emerald-200 rounded-xl">
            <i class="fa-solid fa-circle-check text-emerald-500"></i>
            กำลังส่งไปยัง <?= htmlspecialchars($currentAlertEmail) ?>
        </div>
        <?php else: ?>
        <div class="flex items-center gap-2 text-xs text-gray-400 font-semibold px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl">
            <i class="fa-solid fa-bell-slash"></i> ปิดการแจ้งเตือน
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- ─── Filter Bar ────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6 animate-slide-up">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <!-- Search -->
        <div class="flex-1 min-w-[200px]">
            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ค้นหา</label>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                    placeholder="ค้นหา message หรือ source..."
                    class="w-full pl-8 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all">
            </div>
        </div>

        <!-- Level -->
        <div>
            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Level</label>
            <select name="level" class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-white">
                <option value="">ทั้งหมด</option>
                <option value="error"   <?= $filterLevel==='error'   ? 'selected':'' ?>>Error</option>
                <option value="warning" <?= $filterLevel==='warning' ? 'selected':'' ?>>Warning</option>
                <option value="info"    <?= $filterLevel==='info'    ? 'selected':'' ?>>Info</option>
            </select>
        </div>

        <!-- Date -->
        <div>
            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">วันที่</label>
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>"
                class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400">
        </div>

        <!-- Source: JS / PHP -->
        <div>
            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">แหล่งที่มา</label>
            <select name="source" class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 bg-white">
                <option value="">ทั้งหมด</option>
                <option value="js"  <?= $filterSource==='js'  ? 'selected':'' ?>>JavaScript (Frontend)</option>
                <option value="php" <?= $filterSource==='php' ? 'selected':'' ?>>PHP (Backend)</option>
            </select>
        </div>

        <button type="submit"
            class="px-5 py-2.5 bg-[#0052CC] text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
            <i class="fa-solid fa-filter mr-1"></i> กรอง
        </button>
        <?php if ($search || $filterLevel || $filterDate || $filterSource): ?>
        <a href="error_logs.php"
            class="px-4 py-2.5 bg-gray-100 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-200 transition-colors flex items-center gap-1">
            <i class="fa-solid fa-xmark text-xs"></i> ล้าง
        </a>
        <?php endif; ?>

        <!-- Export buttons -->
        <div class="ml-auto flex gap-2">
            <?php
            $exportQs = http_build_query(array_filter([
                'search' => $search, 'level' => $filterLevel, 'date' => $filterDate
            ]));
            ?>
            <a href="error_logs.php?export=csv<?= $exportQs ? '&'.$exportQs : '' ?>"
               class="px-4 py-2.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-sm font-bold rounded-xl hover:bg-emerald-100 transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-file-csv text-sm"></i> Export CSV
            </a>
            <a href="error_logs.php?export=json<?= $exportQs ? '&'.$exportQs : '' ?>"
               class="px-4 py-2.5 bg-violet-50 text-violet-700 border border-violet-200 text-sm font-bold rounded-xl hover:bg-violet-100 transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-file-code text-sm"></i> Export JSON
            </a>
        </div>
    </form>
</div>

<!-- ─── Log Table ─────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden animate-slide-up mb-6">

    <!-- Table header with clear button -->
    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
        <p class="text-sm font-bold text-gray-700">
            พบ <span class="text-[#0052CC]"><?= number_format($total) ?></span> รายการ
            <?php if ($filterLevel || $search || $filterDate): ?>
                <span class="text-gray-400 font-normal">(กรองแล้ว)</span>
            <?php endif; ?>
        </p>
        <form method="POST" onsubmit="return confirm('ยืนยันการลบ log ทั้งหมดหรือไม่?')">
            <input type="hidden" name="action" value="clear">
            <div class="flex gap-2">
                <select name="clear_level" class="py-1.5 px-2 border border-gray-200 rounded-lg text-xs outline-none bg-white">
                    <option value="all">ทั้งหมด</option>
                    <option value="error">เฉพาะ Error</option>
                    <option value="warning">เฉพาะ Warning</option>
                    <option value="info">เฉพาะ Info</option>
                </select>
                <button type="submit"
                    class="px-4 py-1.5 bg-red-500 text-white text-xs font-bold rounded-lg hover:bg-red-600 transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-trash-can"></i> ล้าง Log
                </button>
            </div>
        </form>
    </div>

    <?php if (isset($db_error)): ?>
    <div class="p-6 text-red-600 text-sm">
        <i class="fa-solid fa-triangle-exclamation mr-2"></i> DB Error: <?= htmlspecialchars($db_error) ?>
    </div>
    <?php elseif (empty($logs)): ?>
    <div class="py-16 text-center text-gray-400">
        <i class="fa-solid fa-check-circle text-4xl text-green-300 mb-3 block"></i>
        <p class="font-semibold">ไม่พบ error log</p>
        <p class="text-sm mt-1">ระบบทำงานปกติ</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-32">Level</th>
                    <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-44">เวลา</th>
                    <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-44">Source</th>
                    <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Message</th>
                    <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-28">IP</th>
                    <th class="px-5 py-3 w-10"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($logs as $log): ?>
                <tr class="hover:bg-gray-50/60 transition-colors group" id="row-<?= $log['id'] ?>">
                    <td class="px-5 py-3.5">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide <?= levelBadge($log['level']) ?>">
                            <i class="fa-solid <?= levelIcon($log['level']) ?> text-[9px]"></i>
                            <?= $log['level'] ?>
                        </span>
                    </td>
                    <td class="px-5 py-3.5 text-xs text-gray-500 whitespace-nowrap">
                        <?= date('d/m/y H:i:s', strtotime($log['created_at'])) ?>
                    </td>
                    <td class="px-5 py-3.5">
                        <code class="text-[10px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-600 break-all"><?= htmlspecialchars($log['source']) ?></code>
                    </td>
                    <td class="px-5 py-3.5">
                        <p class="text-sm text-gray-700 line-clamp-2 max-w-xl"><?= htmlspecialchars($log['message']) ?></p>
                        <?php if (!empty($log['context'])): ?>
                        <button onclick="toggleContext(<?= $log['id'] ?>)"
                            class="mt-1 text-[10px] text-blue-500 hover:underline font-semibold">
                            <i class="fa-solid fa-code text-[9px]"></i> ดู context
                        </button>
                        <pre id="ctx-<?= $log['id'] ?>" class="hidden mt-2 text-[10px] bg-gray-50 border border-gray-200 rounded-lg p-3 overflow-x-auto text-gray-600 whitespace-pre-wrap max-w-xl"><?= htmlspecialchars($log['context']) ?></pre>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5">
                        <code class="text-[10px] text-gray-400"><?= htmlspecialchars($log['ip_address'] ?: '-') ?></code>
                    </td>
                    <td class="px-3 py-3.5">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action"   value="delete_one">
                            <input type="hidden" name="log_id"   value="<?= $log['id'] ?>">
                            <button type="submit"
                                class="opacity-0 group-hover:opacity-100 w-7 h-7 flex items-center justify-center rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all"
                                title="ลบรายการนี้"
                                onclick="return confirm('ลบรายการนี้?')">
                                <i class="fa-solid fa-xmark text-xs"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            แสดง <b><?= number_format($offset+1) ?>–<?= number_format(min($offset+$limit,$total)) ?></b>
            จาก <?= number_format($total) ?> รายการ
        </p>
        <div class="flex gap-1">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&<?= $filterQs ?>"
                class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400 transition-all">
                <i class="fa-solid fa-chevron-left text-[10px]"></i>
            </a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <a href="?page=<?= $i ?>&<?= $filterQs ?>"
                class="w-9 h-9 flex items-center justify-center rounded-lg text-sm transition-all
                       <?= $i===$page ? 'bg-[#0052CC] text-white font-bold shadow-md' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page+1 ?>&<?= $filterQs ?>"
                class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400 transition-all">
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleContext(id) {
    const el = document.getElementById('ctx-' + id);
    el.classList.toggle('hidden');
}
</script>

<?php
// Handle delete one
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_one') {
    $logId = (int)($_POST['log_id'] ?? 0);
    if ($logId > 0) {
        try {
            $pdo->prepare("DELETE FROM sys_error_logs WHERE id = ?")->execute([$logId]);
        } catch (PDOException) {}
    }
    header('Location: error_logs.php?' . $filterQs . (isset($_GET['embed']) ? '&embed=1' : ''));
    exit;
}

include 'includes/footer.php';
?>
