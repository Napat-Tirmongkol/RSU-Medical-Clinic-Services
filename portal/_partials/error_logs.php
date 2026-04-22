<?php
// portal/_partials/error_logs.php — included by portal/index.php
// POST handlers (save_alert_email, clear, delete_one) and export are handled at portal/index.php top
// $pdo is available from parent scope

// Auto-create tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_error_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level ENUM('error','warning','info') NOT NULL DEFAULT 'error',
        source VARCHAR(300) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        context TEXT NOT NULL DEFAULT '',
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notified_at DATETIME NULL DEFAULT NULL,
        status ENUM('New', 'Active', 'Resolved') NOT NULL DEFAULT 'New',
        resolve_comment TEXT NULL,
        INDEX idx_level (level),
        INDEX idx_created_at (created_at),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ensure existing table has new columns
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sys_error_logs LIKE 'status'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE sys_error_logs ADD COLUMN status ENUM('New', 'Active', 'Resolved') NOT NULL DEFAULT 'New' AFTER notified_at");
            $pdo->exec("ALTER TABLE sys_error_logs ADD COLUMN resolve_comment TEXT NULL AFTER status");
            $pdo->exec("CREATE INDEX idx_status ON sys_error_logs(status)");
        }
    } catch (PDOException $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

// Load current alert email
$_el_alertEmail = (string)($pdo->query(
    "SELECT `value` FROM sys_settings WHERE `key` = 'admin_alert_email' LIMIT 1"
)->fetchColumn() ?: '');

// Validation error from redirect
$_el_emailError = isset($_GET['email_error']) ? 'รูปแบบอีเมลไม่ถูกต้อง' : null;

// Filters
$_el_page   = max(1, (int)($_GET['page'] ?? 1));
$_el_limit  = 50;
$_el_offset = ($_el_page - 1) * $_el_limit;
$_el_search  = trim($_GET['el_search'] ?? '');
$_el_level   = $_GET['el_level']  ?? '';
$_el_status  = $_GET['el_status'] ?? '';
$_el_date    = $_GET['el_date']   ?? '';
$_el_source  = $_GET['el_source'] ?? '';

$_el_where  = 'WHERE 1=1';
$_el_params = [];
if ($_el_search !== '') {
    $_el_where   .= ' AND (message LIKE ? OR source LIKE ?)';
    $_el_params[] = "%$_el_search%";
    $_el_params[] = "%$_el_search%";
}
if (in_array($_el_level, ['error', 'warning', 'info'], true)) {
    $_el_where   .= ' AND level = ?';
    $_el_params[] = $_el_level;
}
if (in_array($_el_status, ['New', 'Active', 'Resolved'], true)) {
    $_el_where   .= ' AND status = ?';
    $_el_params[] = $_el_status;
}
if ($_el_date !== '') {
    $_el_where   .= ' AND DATE(created_at) = ?';
    $_el_params[] = $_el_date;
}
if ($_el_source === 'js') {
    $_el_where .= " AND source LIKE '[JS]%'";
} elseif ($_el_source === 'php') {
    $_el_where .= " AND source NOT LIKE '[JS]%'";
}

$_el_logs = [];
$_el_summary = [];
$_el_total = 0;
$_el_totalPages = 0;
$_el_todayErrors = 0;
try {
    $_el_summary     = $pdo->query("SELECT level, COUNT(*) FROM sys_error_logs GROUP BY level")->fetchAll(PDO::FETCH_KEY_PAIR);
    $_el_todayErrors = (int)$pdo->query("SELECT COUNT(*) FROM sys_error_logs WHERE level='error' AND DATE(created_at)=CURDATE()")->fetchColumn();

    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_error_logs $_el_where");
    $sc->execute($_el_params);
    $_el_total      = (int)$sc->fetchColumn();
    $_el_totalPages = (int)ceil($_el_total / $_el_limit);

    $sr = $pdo->prepare("SELECT * FROM sys_error_logs $_el_where ORDER BY created_at DESC LIMIT $_el_limit OFFSET $_el_offset");
    $sr->execute($_el_params);
    $_el_logs = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_el_dbError = $e->getMessage();
}

function _el_badge(string $l): string {
    return match($l) {
        'error'   => 'background:#fff1f2;border:1px solid #fecaca;color:#be123c',
        'warning' => 'background:#fffbeb;border:1px solid #fde68a;color:#92400e',
        default   => 'background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af',
    };
}
function _el_icon(string $l): string {
    return match($l) { 'error' => 'fa-circle-xmark', 'warning' => 'fa-triangle-exclamation', default => 'fa-circle-info' };
}
function _el_iconColor(string $l): string {
    return match($l) { 'error' => '#e11d48', 'warning' => '#d97706', default => '#3b82f6' };
}
function _el_statusBadge(string $s): string {
    return match($s) {
        'New'      => 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b',
        'Active'   => 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8',
        'Resolved' => 'background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d',
        default    => 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b',
    };
}
function _el_statusIcon(string $s): string {
    return match($s) { 'New' => 'fa-sparkles', 'Active' => 'fa-spinner fa-spin', 'Resolved' => 'fa-check-circle', default => 'fa-circle' };
}

// Build filter querystring for export links
$_el_filterQs = http_build_query(array_filter([
    'el_search' => $_el_search,
    'el_level'  => $_el_level,
    'el_status' => $_el_status,
    'el_date'   => $_el_date,
    'el_source' => $_el_source,
]));
?>

<div class="p-6">

    <!-- Flash alerts -->
    <?php if (isset($_GET['saved'])): ?>
    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-2xl text-green-700 text-sm flex items-center gap-2">
        <i class="fa-solid fa-check-circle"></i> บันทึกการตั้งค่าเรียบร้อยแล้ว
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['cleared'])): ?>
    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-2xl text-green-700 text-sm flex items-center gap-2">
        <i class="fa-solid fa-check-circle"></i> ลบ Log เรียบร้อยแล้ว
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-2xl text-green-700 text-sm flex items-center gap-2">
        <i class="fa-solid fa-check-circle"></i> อัปเดตสถานะเรียบร้อยแล้ว
    </div>
    <?php endif; ?>

    <!-- Section Header -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-bug text-red-500"></i> Error Logs
            </h2>
            <p class="text-xs text-gray-400 mt-1">บันทึกข้อผิดพลาดและ PHP errors ทั้งหมดในระบบ</p>
        </div>
        <div class="flex gap-2">
            <button onclick="document.getElementById('errorLogUploadInput').click()"
               class="px-3 py-2 bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold rounded-xl hover:bg-blue-100 flex items-center gap-1.5">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload
            </button>
            <a href="../admin/ai_assistant.php"
               class="px-3 py-2 bg-purple-50 text-purple-700 border border-purple-200 text-xs font-bold rounded-xl hover:bg-purple-100 flex items-center gap-1.5">
                <i class="fa-solid fa-robot"></i> AI Analyze
            </a>
            <a href="?section=error_logs&export=csv<?= $_el_filterQs ? '&'.$_el_filterQs : '' ?>"
               class="px-3 py-2 bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold rounded-xl hover:bg-emerald-100 flex items-center gap-1.5">
                <i class="fa-solid fa-file-csv"></i> CSV
            </a>
            <a href="?section=error_logs&export=json<?= $_el_filterQs ? '&'.$_el_filterQs : '' ?>"
               class="px-3 py-2 bg-violet-50 text-violet-700 border border-violet-200 text-xs font-bold rounded-xl hover:bg-violet-100 flex items-center gap-1.5">
                <i class="fa-solid fa-file-code"></i> JSON
            </a>
        </div>
    </div>

    <!-- Hidden file input -->
    <input type="file" id="errorLogUploadInput" style="display:none" accept=".json,.csv,.txt,.log" onchange="uploadErrorLog(this)">

    <!-- Upload Progress Card -->
    <div id="uploadProgressCard" class="hidden mb-6 p-4 bg-blue-50 border border-blue-200 rounded-2xl">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-bold text-blue-900">กำลังอัพโหลด...</span>
            <i class="fa-solid fa-spinner fa-spin text-blue-500"></i>
        </div>
        <div class="w-full bg-blue-200 rounded-full h-2">
            <div id="uploadProgressBar" class="bg-blue-600 h-2 rounded-full transition-all" style="width:0%"></div>
        </div>
    </div>

    <!-- Upload Results -->
    <div id="uploadResultCard" class="hidden mb-6 p-4 border rounded-2xl">
        <div class="flex items-start gap-3 mb-3">
            <i id="uploadResultIcon" class="fa-solid fa-check-circle text-green-500 mt-0.5"></i>
            <div class="flex-1">
                <p id="uploadResultMsg" class="text-sm font-bold text-gray-900 mb-2"></p>
                <div id="uploadAnalysis" class="text-xs text-gray-600 space-y-1"></div>
            </div>
            <button onclick="document.getElementById('uploadResultCard').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <?php
        $cards = [
            ['label'=>'Error วันนี้', 'val'=>$_el_todayErrors,               'icon'=>'fa-circle-xmark',        'bg'=>'#fff1f2','ic'=>'#e11d48'],
            ['label'=>'Total Errors', 'val'=>$_el_summary['error']??0,        'icon'=>'fa-triangle-exclamation', 'bg'=>'#fff1f2','ic'=>'#e11d48'],
            ['label'=>'Warnings',     'val'=>$_el_summary['warning']??0,      'icon'=>'fa-circle-exclamation',   'bg'=>'#fffbeb','ic'=>'#d97706'],
            ['label'=>'Info',         'val'=>$_el_summary['info']??0,         'icon'=>'fa-circle-info',          'bg'=>'#eff6ff','ic'=>'#3b82f6'],
        ];
        foreach ($cards as $c): ?>
        <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0" style="background:<?= $c['bg'] ?>">
                <i class="fa-solid <?= $c['icon'] ?> text-lg" style="color:<?= $c['ic'] ?>"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-gray-900"><?= number_format((int)$c['val']) ?></p>
                <p class="text-xs text-gray-400 font-semibold mt-0.5"><?= $c['label'] ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Alert Email Settings -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-bell text-blue-500 text-sm"></i>
            </div>
            <div>
                <p class="text-sm font-bold text-gray-800">แจ้งเตือน Error ทางอีเมล</p>
                <p class="text-xs text-gray-400">ส่ง Error Digest ทุก 30 นาทีเมื่อมี error ใหม่ในระบบ</p>
            </div>
        </div>
        <form method="POST" action="index.php?section=error_logs" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="action" value="save_alert_email">
            <div class="flex-1 min-w-[220px]">
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">อีเมล Admin (คั่นด้วยคอมม่า , เพื่อส่งหลายคน)</label>
                <div class="relative">
                    <i class="fa-solid fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                    <input type="text" name="alert_email" value="<?= htmlspecialchars($_el_alertEmail) ?>"
                        placeholder="admin1@example.com, admin2@example.com"
                        class="w-full pl-8 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all">
                </div>
                <?php if ($_el_emailError): ?>
                <p class="text-xs text-red-500 font-semibold mt-1"><?= htmlspecialchars($_el_emailError) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" class="px-5 py-2.5 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 flex items-center gap-2 shadow-sm">
                <i class="fa-solid fa-floppy-disk"></i> บันทึก
            </button>
            <?php if ($_el_alertEmail): ?>
            <div class="flex items-center gap-2 text-xs text-emerald-700 font-semibold px-4 py-2.5 bg-emerald-50 border border-emerald-200 rounded-xl">
                <i class="fa-solid fa-circle-check text-emerald-500"></i> กำลังส่งไปยัง <?= htmlspecialchars($_el_alertEmail) ?>
            </div>
            <?php else: ?>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-semibold px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl">
                <i class="fa-solid fa-bell-slash"></i> ปิดการแจ้งเตือน
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="section" value="error_logs">
            <div class="flex-1 min-w-[180px]">
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ค้นหา</label>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                    <input type="text" name="el_search" value="<?= htmlspecialchars($_el_search) ?>"
                        placeholder="message หรือ source..."
                        class="w-full pl-8 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 transition-all bg-gray-50">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">Level</label>
                <select name="el_level" class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none bg-gray-50">
                    <option value="">ทั้งหมด</option>
                    <option value="error"   <?= $_el_level==='error'   ? 'selected':'' ?>>Error</option>
                    <option value="warning" <?= $_el_level==='warning' ? 'selected':'' ?>>Warning</option>
                    <option value="info"    <?= $_el_level==='info'    ? 'selected':'' ?>>Info</option>
                </select>
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">วันที่</label>
                <input type="date" name="el_date" value="<?= htmlspecialchars($_el_date) ?>"
                    class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none bg-gray-50">
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">สถานะ</label>
                <select name="el_status" class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none bg-gray-50">
                    <option value="">ทั้งหมด</option>
                    <option value="New"      <?= $_el_status==='New'      ? 'selected':'' ?>>New</option>
                    <option value="Active"   <?= $_el_status==='Active'   ? 'selected':'' ?>>Active</option>
                    <option value="Resolved" <?= $_el_status==='Resolved' ? 'selected':'' ?>>Resolved</option>
                </select>
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">แหล่งที่มา</label>
                <select name="el_source" class="py-2.5 px-3 border border-gray-200 rounded-xl text-sm outline-none bg-gray-50">
                    <option value="">ทั้งหมด</option>
                    <option value="js"  <?= $_el_source==='js'  ? 'selected':'' ?>>JavaScript</option>
                    <option value="php" <?= $_el_source==='php' ? 'selected':'' ?>>PHP</option>
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 bg-[#0052CC] text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
                <i class="fa-solid fa-filter mr-1"></i> กรอง
            </button>
            <?php if ($_el_search || $_el_level || $_el_status || $_el_date || $_el_source): ?>
            <a href="?section=error_logs" class="px-4 py-2.5 bg-gray-100 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-200 flex items-center gap-1">
                <i class="fa-solid fa-xmark text-xs"></i> ล้าง
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-4">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
            <p class="text-sm font-bold text-gray-700">
                พบ <span class="text-[#0052CC]"><?= number_format($_el_total) ?></span> รายการ
                <?php if ($_el_level || $_el_search || $_el_date): ?><span class="text-gray-400 font-normal">(กรองแล้ว)</span><?php endif; ?>
            </p>
            <form method="POST" action="index.php?section=error_logs" onsubmit="return confirm('ยืนยันการลบ log?')">
                <input type="hidden" name="action" value="clear">
                <div class="flex gap-2">
                    <select name="clear_level" class="py-1.5 px-2 border border-gray-200 rounded-lg text-xs outline-none bg-white">
                        <option value="all">ทั้งหมด</option>
                        <option value="error">เฉพาะ Error</option>
                        <option value="warning">เฉพาะ Warning</option>
                        <option value="info">เฉพาะ Info</option>
                    </select>
                    <button type="submit" class="px-4 py-1.5 bg-red-500 text-white text-xs font-bold rounded-lg hover:bg-red-600 flex items-center gap-1.5">
                        <i class="fa-solid fa-trash-can"></i> ล้าง Log
                    </button>
                </div>
            </form>
        </div>

        <?php if (isset($_el_dbError)): ?>
        <div class="p-6 text-red-600 text-sm"><i class="fa-solid fa-triangle-exclamation mr-2"></i> <?= htmlspecialchars($_el_dbError) ?></div>
        <?php elseif (empty($_el_logs)): ?>
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
                        <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-32">Status</th>
                        <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-44">เวลา</th>
                        <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-40">Source</th>
                        <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Message</th>
                        <th class="px-5 py-3 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest w-28">IP</th>
                        <th class="px-5 py-3 w-8"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($_el_logs as $log): ?>
                    <tr class="hover:bg-gray-50/60 transition-colors group">
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase" style="<?= _el_badge($log['level']) ?>">
                                <i class="fa-solid <?= _el_icon($log['level']) ?> text-[9px]" style="color:<?= _el_iconColor($log['level']) ?>"></i>
                                <?= $log['level'] ?>
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            <button onclick="openStatusModal(<?= $log['id'] ?>, '<?= $log['status'] ?>', <?= htmlspecialchars(json_encode($log['resolve_comment'] ?: '')) ?>)" 
                                id="status-btn-<?= $log['id'] ?>"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[10px] font-bold uppercase transition-all hover:scale-105 active:scale-95 hover:shadow-md ring-blue-100/50 cursor-pointer" style="<?= _el_statusBadge($log['status']) ?>">
                                <i class="fa-solid <?= _el_statusIcon($log['status']) ?> text-[9px]"></i>
                                <span class="status-label"><?= $log['status'] ?></span>
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-gray-500 whitespace-nowrap">
                            <?= date('d/m/y H:i:s', strtotime($log['created_at'])) ?>
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="text-[10px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-600 break-all"><?= htmlspecialchars($log['source']) ?></code>
                        </td>
                        <td class="px-5 py-3.5">
                        <td class="px-5 py-3.5">
                            <div id="message-container-<?= $log['id'] ?>">
                                <p class="text-sm text-gray-700 line-clamp-2 max-w-xl"><?= htmlspecialchars($log['message']) ?></p>
                                <div class="resolve-comment-area mt-1.5 <?= $log['resolve_comment'] ? '' : 'hidden' ?>">
                                    <div class="flex items-start gap-2 bg-green-50/50 border border-green-100 p-2 rounded-lg max-w-xl">
                                        <i class="fa-solid fa-comment-dots text-[10px] text-green-500 mt-1"></i>
                                        <p class="text-[11px] text-green-700 font-medium italic comment-text"><?= htmlspecialchars($log['resolve_comment'] ?: '') ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($log['context'])): ?>
                            <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="mt-1 text-[10px] text-blue-500 hover:underline font-semibold">
                                <i class="fa-solid fa-code text-[9px]"></i> ดู context
                            </button>
                            <pre class="hidden mt-2 text-[10px] bg-gray-50 border border-gray-200 rounded-lg p-3 overflow-x-auto text-gray-600 whitespace-pre-wrap max-w-xl"><?= htmlspecialchars($log['context']) ?></pre>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="text-[10px] text-gray-400"><?= htmlspecialchars($log['ip_address'] ?: '-') ?></code>
                        </td>
                        <td class="px-3 py-3.5">
                            <form method="POST" action="index.php?section=error_logs">
                                <input type="hidden" name="action" value="delete_one">
                                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                <button type="submit" onclick="return confirm('ลบรายการนี้?')"
                                    class="opacity-0 group-hover:opacity-100 w-7 h-7 flex items-center justify-center rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all">
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
        <?php if ($_el_totalPages > 1): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
            <p class="text-xs text-gray-500">
                <?= number_format($_el_offset + 1) ?>–<?= number_format(min($_el_offset + $_el_limit, $_el_total)) ?> / <?= number_format($_el_total) ?>
            </p>
            <div class="flex gap-1">
                <?php
                $_el_pgQs = $_el_filterQs ? '&'.$_el_filterQs : '';
                if ($_el_page > 1): ?>
                <a href="?section=error_logs&page=<?= $_el_page - 1 ?><?= $_el_pgQs ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400"><i class="fa-solid fa-chevron-left text-xs"></i></a>
                <?php endif; ?>
                <?php for ($i = max(1, $_el_page - 2); $i <= min($_el_totalPages, $_el_page + 2); $i++): ?>
                <a href="?section=error_logs&page=<?= $i ?><?= $_el_pgQs ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-sm <?= $i === $_el_page ? 'bg-[#0052CC] text-white font-bold shadow-md' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                <?php if ($_el_page < $_el_totalPages): ?>
                <a href="?section=error_logs&page=<?= $_el_page + 1 ?><?= $_el_pgQs ?>" class="p-2 border border-gray-200 rounded-lg hover:bg-white text-gray-400"><i class="fa-solid fa-chevron-right text-xs"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Status & Comment Modal -->
<div id="statusModal" class="hidden fixed inset-0 bg-gray-900/40 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <h3 class="text-lg font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-blue-500"></i> อัปเดตสถานะ Log
            </h3>
            <button onclick="closeStatusModal()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 text-gray-400 transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form id="statusUpdateForm" onsubmit="handleStatusUpdate(event)" class="p-6">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="log_id" id="modal_log_id">
            
            <div class="mb-5">
                <label class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2 block">เลือกสถานะใหม่</label>
                <div class="grid grid-cols-3 gap-3">
                    <!-- New -->
                    <label class="relative h-24 cursor-pointer group">
                        <input type="radio" name="modal_status" value="New" id="status_new" class="peer absolute inset-0 opacity-0 z-10 cursor-pointer">
                        <div class="absolute inset-0 flex flex-col items-center justify-center rounded-2xl border-2 border-gray-100 bg-white transition-all peer-checked:border-gray-500 peer-checked:bg-gray-50 group-hover:border-gray-200">
                            <i class="fa-solid fa-sparkles text-lg mb-2 text-gray-300 peer-checked:text-gray-600 transition-colors"></i>
                            <span class="text-[10px] font-black text-gray-400 peer-checked:text-gray-800 uppercase tracking-wider">NEW</span>
                        </div>
                    </label>
                    
                    <!-- Active -->
                    <label class="relative h-24 cursor-pointer group">
                        <input type="radio" name="modal_status" value="Active" id="status_active" class="peer absolute inset-0 opacity-0 z-10 cursor-pointer">
                        <div class="absolute inset-0 flex flex-col items-center justify-center rounded-2xl border-2 border-gray-100 bg-white transition-all peer-checked:border-blue-500 peer-checked:bg-blue-50 group-hover:border-blue-200">
                            <i class="fa-solid fa-spinner fa-spin-pulse text-lg mb-2 text-gray-300 peer-checked:text-blue-500 transition-colors"></i>
                            <span class="text-[10px] font-black text-gray-400 peer-checked:text-blue-800 uppercase tracking-wider">ACTIVE</span>
                        </div>
                    </label>
                    
                    <!-- Resolved -->
                    <label class="relative h-24 cursor-pointer group">
                        <input type="radio" name="modal_status" value="Resolved" id="status_resolved" class="peer absolute inset-0 opacity-0 z-10 cursor-pointer" onchange="toggleCommentField(this.checked)">
                        <div class="absolute inset-0 flex flex-col items-center justify-center rounded-2xl border-2 border-gray-100 bg-white transition-all peer-checked:border-green-500 peer-checked:bg-green-50 group-hover:border-green-200">
                            <i class="fa-solid fa-check-circle text-lg mb-2 text-gray-300 peer-checked:text-green-500 transition-colors"></i>
                            <span class="text-[10px] font-black text-gray-400 peer-checked:text-green-800 uppercase tracking-wider">RESOLVED</span>
                        </div>
                    </label>
                </div>
            </div>

            <div id="comment_field" class="mb-6 hidden">
                <label class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-2 block">บันทึกการแก้ไข (Comment)</label>
                <textarea name="resolve_comment" id="modal_comment" placeholder="ระบุรายละเอียดการแก้ไขปัญหา..."
                    class="w-full px-4 py-3 border border-gray-200 rounded-2xl text-sm outline-none focus:ring-4 focus:ring-green-50 focus:border-green-400 transition-all h-28 resize-none shadow-sm font-medium"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeStatusModal()" class="flex-1 px-5 py-3.5 border border-gray-200 text-gray-500 text-xs font-black uppercase tracking-widest rounded-2xl hover:bg-gray-50 transition-all active:scale-95">
                    ยกเลิก
                </button>
                <button type="submit" id="statusSubmitBtn" class="flex-1 px-5 py-3.5 bg-gray-900 text-white text-xs font-black uppercase tracking-widest rounded-2xl hover:bg-black transition-all active:scale-95 shadow-lg shadow-gray-200 flex items-center justify-center gap-2">
                    <span id="submitBtnText">บันทึกการเปลี่ยนแปลง</span>
                    <i id="submitBtnIcon" class="fa-solid fa-check"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
async function handleStatusUpdate(e) {
    e.preventDefault();
    const form = e.target;
    const logId = document.getElementById('modal_log_id').value;
    const statusInput = form.querySelector('input[name="modal_status"]:checked');
    if (!statusInput) return;
    
    const status = statusInput.value;
    const comment = document.getElementById('modal_comment').value;
    const submitBtn = document.getElementById('statusSubmitBtn');
    const btnText = document.getElementById('submitBtnText');
    const btnIcon = document.getElementById('submitBtnIcon');

    // Show loading
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
    btnText.textContent = 'กำลังบันทึก...';
    btnIcon.className = 'fa-solid fa-spinner fa-spin';

    try {
        const fd = new FormData();
        fd.append('action', 'update_status');
        fd.append('log_id', logId);
        fd.append('status', status);
        fd.append('resolve_comment', comment);

        const res = await fetch('ajax_error_logs.php', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.ok) {
            // Update UI
            updateStatusUI(logId, status, comment);
            
            // Show Success
            Swal.fire({
                icon: 'success',
                title: 'สำเร็จ',
                text: 'อัปเดตสถานะเรียบร้อยแล้ว',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            
            closeStatusModal();
        } else {
            throw new Error(data.error || 'Update failed');
        }
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: err.message
        });
    } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
        btnText.textContent = 'บันทึกการเปลี่ยนแปลง';
        btnIcon.className = 'fa-solid fa-check';
    }
}

function updateStatusUI(id, status, comment) {
    const btn = document.getElementById('status-btn-' + id);
    const label = btn.querySelector('.status-label');
    const icon = btn.querySelector('i');
    
    // Update badge styles
    let styles = '';
    let iconClass = '';
    if (status === 'New') {
        styles = 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b';
        iconClass = 'fa-solid fa-sparkles';
    } else if (status === 'Active') {
        styles = 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8';
        iconClass = 'fa-solid fa-spinner fa-spin';
    } else {
        styles = 'background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d';
        iconClass = 'fa-solid fa-check-circle';
    }
    
    btn.style.cssText = styles + '; transition: all .15s';
    label.textContent = status;
    icon.className = iconClass + ' text-[9px]';
    
    // Update comment area
    const container = document.getElementById('message-container-' + id);
    const commentArea = container.querySelector('.resolve-comment-area');
    const commentText = container.querySelector('.comment-text');
    
    if (status === 'Resolved' && comment) {
        commentArea.classList.remove('hidden');
        commentText.textContent = comment;
    } else {
        commentArea.classList.add('hidden');
    }
    
    // Update button onclick for next time
    btn.setAttribute('onclick', `openStatusModal(${id}, '${status}', ${JSON.stringify(comment || '')})`);
}

function openStatusModal(id, status, comment) {
    document.getElementById('modal_log_id').value = id;
    document.getElementById('modal_comment').value = comment || '';
    
    // Reset and Select Radios
    const radios = document.getElementsByName('modal_status');
    radios.forEach(r => {
        r.checked = (r.value === status);
    });
    
    toggleCommentField(status === 'Resolved');
    document.getElementById('statusModal').classList.remove('hidden');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
}

function toggleCommentField(show) {
    const field = document.getElementById('comment_field');
    if (show) {
        field.classList.remove('hidden');
        document.getElementById('modal_comment').focus();
    } else {
        field.classList.add('hidden');
    }
}
// Existing uploadErrorLog function follows...
async function uploadErrorLog(input) {
    const file = input.files[0];
    if (!file) return;

    // Show progress
    document.getElementById('uploadProgressCard').classList.remove('hidden');
    document.getElementById('uploadProgressBar').style.width = '0%';
    document.getElementById('uploadResultCard').classList.add('hidden');

    const fd = new FormData();
    fd.append('error_log_file', file);
    fd.append('csrf_token', '<?= get_csrf_token() ?>');

    try {
        // Simulate progress
        let progress = 0;
        const progressInterval = setInterval(() => {
            if (progress < 90) {
                progress += Math.random() * 30;
                document.getElementById('uploadProgressBar').style.width = Math.min(progress, 90) + '%';
            }
        }, 100);

        const res = await fetch('../admin/ajax/ajax_upload_error_logs.php', {
            method: 'POST',
            body: fd
        });

        clearInterval(progressInterval);
        document.getElementById('uploadProgressBar').style.width = '100%';

        const data = await res.json();

        // Hide progress
        setTimeout(() => {
            document.getElementById('uploadProgressCard').classList.add('hidden');
        }, 500);

        // Show results
        const resultCard = document.getElementById('uploadResultCard');
        const resultIcon = document.getElementById('uploadResultIcon');
        const resultMsg = document.getElementById('uploadResultMsg');
        const analysisDiv = document.getElementById('uploadAnalysis');

        if (data.ok) {
            resultIcon.className = 'fa-solid fa-check-circle text-green-500 mt-0.5';
            resultCard.className = 'mb-6 p-4 border border-green-200 rounded-2xl bg-green-50';
            resultMsg.textContent = `✓ ${data.message}`;

            if (data.analysis) {
                let html = `<p><strong>📊 Analysis Results:</strong></p>`;
                if (data.analysis.total_errors) {
                    html += `<p>• Total Errors: ${data.analysis.total_errors}</p>`;
                }
                if (data.analysis.error_types && Object.keys(data.analysis.error_types).length > 0) {
                    html += `<p>• Error Types: ${Object.entries(data.analysis.error_types).map(([k,v]) => `${k} (${v})`).join(', ')}</p>`;
                }
                if (data.analysis.failed_recipients && data.analysis.failed_recipients.length > 0) {
                    html += `<p>• Failed Recipients: ${data.analysis.failed_recipients.slice(0,3).join(', ')}${data.analysis.failed_recipients.length > 3 ? '...' : ''}</p>`;
                }
                analysisDiv.innerHTML = html;
            }
        } else {
            resultIcon.className = 'fa-solid fa-exclamation-circle text-red-500 mt-0.5';
            resultCard.className = 'mb-6 p-4 border border-red-200 rounded-2xl bg-red-50';
            resultMsg.textContent = `✗ ${data.error}`;
            analysisDiv.innerHTML = '';
        }

        resultCard.classList.remove('hidden');

        // Reset input
        input.value = '';

    } catch (err) {
        document.getElementById('uploadProgressCard').classList.add('hidden');

        const resultCard = document.getElementById('uploadResultCard');
        resultCard.className = 'mb-6 p-4 border border-red-200 rounded-2xl bg-red-50';
        document.getElementById('uploadResultIcon').className = 'fa-solid fa-exclamation-circle text-red-500 mt-0.5';
        document.getElementById('uploadResultMsg').textContent = '✗ ' + (err.message || 'Upload failed');
        document.getElementById('uploadAnalysis').innerHTML = '';
        resultCard.classList.remove('hidden');

        input.value = '';
    }
}
</script>
