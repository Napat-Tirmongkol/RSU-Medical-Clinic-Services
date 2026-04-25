<?php
// admin/email_logs.php — ประวัติการส่งอีเมลของระบบ
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';


// ─── Pagination & filters ─────────────────────────────────────────────────────
$perPage  = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;
$search   = trim($_GET['q']    ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$embed    = isset($_GET['embed']);

// ─── Query builder ────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($search !== '') {
    $where[]        = '(recipient LIKE :q OR subject LIKE :q2)';
    $params[':q']   = "%{$search}%";
    $params[':q2']  = "%{$search}%";
}
if ($typeFilter !== '') {
    $where[]         = 'type = :type';
    $params[':type'] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[]           = 'status = :status';
    $params[':status'] = $statusFilter;
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $pdo = db();

    // ตรวจว่าตารางมีอยู่ (migration อาจยังไม่ได้รัน)
    $tableExists = $pdo->query("SHOW TABLES LIKE 'sys_email_logs'")->rowCount() > 0;

    if ($tableExists) {
        // re-fetch COUNT properly
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sys_email_logs {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmtRows = $pdo->prepare("
            SELECT id, recipient, subject, type, status, error_msg, sent_at
            FROM sys_email_logs
            {$whereSQL}
            ORDER BY sent_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmtRows->bindValue($k, $v);
        $stmtRows->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmtRows->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmtRows->execute();
        $logs = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

        // สถิติสรุป
        $stats = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(status='sent') AS sent,
                SUM(status='failed') AS failed,
                COUNT(DISTINCT recipient) AS unique_recipients
            FROM sys_email_logs
        ")->fetch(PDO::FETCH_ASSOC);

        // distinct types สำหรับ filter dropdown
        $types = $pdo->query("SELECT DISTINCT type FROM sys_email_logs ORDER BY type")
                     ->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $total = 0; $logs = []; $stats = []; $types = [];
    }
} catch (PDOException $e) {
    error_log('email_logs error: ' . $e->getMessage());
    $total = 0; $logs = []; $stats = []; $types = []; $tableExists = false;
}

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

// Type labels
$typeLabels = [
    'confirmation'      => ['label' => 'จองสำเร็จ',    'color' => '#059669', 'bg' => '#d1fae5', 'icon' => 'fa-calendar-check'],
    'approved'          => ['label' => 'อนุมัติคิว',   'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => 'fa-circle-check'],
    'cancelled_by_user' => ['label' => 'User ยกเลิก',  'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => 'fa-circle-xmark'],
    'cancelled_by_admin'=> ['label' => 'Admin ยกเลิก', 'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => 'fa-user-slash'],
    ''                  => ['label' => 'อื่นๆ',        'color' => '#64748b', 'bg' => '#f1f5f9', 'icon' => 'fa-envelope'],
];

// โหลด Header เสมอเพื่อให้ได้ CSS/JS แต่ซ่อน Layout ถ้าเป็น embed
require_once __DIR__ . '/includes/header.php';
?>

<style>
.el-card { background:#fff; border:1.5px solid #e5e7eb; border-radius:1.25rem; overflow:hidden; }
.stat-box { text-align:center; padding:1rem; }
.stat-num { font-size:1.75rem; font-weight:900; }
.stat-lbl { font-size:.7rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.08em; margin-top:2px; }
.log-row { display:grid; grid-template-columns:80px 1fr 160px 120px 90px 110px; align-items:center; gap:12px; padding:12px 20px; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.log-row:last-child { border-bottom:none; }
.log-row:hover { background:#fafafa; }
.type-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:.65rem; font-weight:800; white-space:nowrap; }
.status-badge-sent   { background:#d1fae5; color:#065f46; }
.status-badge-failed { background:#fee2e2; color:#991b1b; }
.search-input { width:100%; padding:.6rem 1rem; background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:.875rem; font-size:.85rem; font-weight:500; outline:none; transition:.2s; }
.search-input:focus { background:#fff; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.btn-page { padding:6px 14px; border-radius:10px; border:1.5px solid #e5e7eb; background:#fff; font-size:.8rem; font-weight:700; color:#374151; cursor:pointer; transition:.2s; }
.btn-page:hover:not(:disabled) { background:#f3f4f6; }
.btn-page:disabled { opacity:.4; cursor:not-allowed; }
.btn-page.active { background:#6366f1; color:#fff; border-color:#6366f1; }
@media(max-width:900px) {
    .log-row { grid-template-columns:1fr 1fr; row-gap:4px; }
    .col-id,.col-err { display:none; }
}
</style>

<div class="<?= $embed ? 'p-0' : 'max-w-7xl mx-auto px-4 py-8' ?>">

<?php if (!$embed): ?>
<?php
$header_actions = '<a href="index.php" class="bg-white border border-gray-100 hover:bg-gray-50 text-gray-500 px-5 py-2.5 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-sm text-sm group">
    <i class="fa-solid fa-arrow-left group-hover:-translate-x-1 transition-transform"></i> กลับ Dashboard
</a>';
renderPageHeader('Email Logs', 'ประวัติการส่งอีเมลทั้งหมดของระบบ', $header_actions);
?>
<?php else: ?>
    <div class="mb-6">
        <h1 class="text-2xl font-black text-gray-900">Email Logs</h1>
        <p class="text-xs text-gray-500">ประวัติการส่งอีเมลทั้งหมดของระบบ</p>
    </div>
<?php endif; ?>

<?php if (!$tableExists): ?>
<!-- Migration notice -->
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
    <div class="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
        <i class="fa-solid fa-triangle-exclamation text-amber-500 text-xl"></i>
    </div>
    <p class="font-black text-amber-800 mb-1">ยังไม่ได้รัน Migration</p>
    <p class="text-sm text-amber-600 mb-4">ต้องสร้างตาราง <code class="bg-amber-100 px-2 py-0.5 rounded font-mono">sys_email_logs</code> ก่อน</p>
    <div class="bg-white border border-amber-200 rounded-xl p-4 text-left max-w-lg mx-auto">
        <p class="text-xs font-black text-amber-700 mb-2">รันคำสั่ง SQL นี้ใน phpMyAdmin หรือ MySQL CLI:</p>
        <pre class="text-xs font-mono text-gray-700 whitespace-pre-wrap">CREATE TABLE IF NOT EXISTS `sys_email_logs` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(255) NOT NULL,
  `subject`  VARCHAR(500) NOT NULL,
  `type`     VARCHAR(80)  NOT NULL DEFAULT '',
  `status`   ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  `error_msg` TEXT NULL,
  `sent_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient`),
  KEY `idx_type` (`type`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
    </div>
</div>

<?php else: ?>

<!-- Stats Row -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $statCards = [
        ['num' => number_format((int)($stats['total'] ?? 0)),           'lbl' => 'ส่งทั้งหมด',         'color' => '#6366f1'],
        ['num' => number_format((int)($stats['sent'] ?? 0)),            'lbl' => 'สำเร็จ',             'color' => '#059669'],
        ['num' => number_format((int)($stats['failed'] ?? 0)),          'lbl' => 'ล้มเหลว',            'color' => '#dc2626'],
        ['num' => number_format((int)($stats['unique_recipients'] ?? 0)),'lbl' => 'ผู้รับไม่ซ้ำ',     'color' => '#d97706'],
    ];
    foreach ($statCards as $s): ?>
    <div class="el-card">
        <div class="stat-box">
            <div class="stat-num" style="color:<?= $s['color'] ?>"><?= $s['num'] ?></div>
            <div class="stat-lbl"><?= $s['lbl'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="el-card mb-5 p-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">ค้นหา</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="อีเมล, หัวข้อ..." class="search-input">
        </div>
        <div>
            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">ประเภท</label>
            <select name="type" class="search-input" style="width:auto;min-width:150px">
                <option value="">ทุกประเภท</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>>
                    <?= htmlspecialchars($typeLabels[$t]['label'] ?? $t) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">สถานะ</label>
            <select name="status" class="search-input" style="width:auto;min-width:120px">
                <option value="">ทั้งหมด</option>
                <option value="sent"   <?= $statusFilter === 'sent'   ? 'selected' : '' ?>>✅ สำเร็จ</option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>❌ ล้มเหลว</option>
            </select>
        </div>
        <button type="submit" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm transition-all flex items-center gap-2">
            <i class="fa-solid fa-magnifying-glass text-xs"></i> ค้นหา
        </button>
        <?php if ($search || $typeFilter || $statusFilter): ?>
        <a href="?<?= $embed ? 'embed=1' : '' ?>" class="px-5 py-2.5 border border-gray-200 bg-white text-gray-500 rounded-xl font-bold text-sm hover:bg-gray-50 transition-all flex items-center gap-2">
            <i class="fa-solid fa-xmark text-xs"></i> ล้างตัวกรอง
        </a>
        <?php endif; ?>
    </form>
</div>

<!-- Table -->
<div class="el-card">
    <!-- Header -->
    <div class="log-row bg-gray-50/80 font-black text-[10px] text-gray-400 uppercase tracking-wider" style="border-bottom:2px solid #f1f5f9">
        <div class="col-id">#ID</div>
        <div>ผู้รับ / หัวข้อ</div>
        <div>ประเภท</div>
        <div class="col-err">หมายเหตุ</div>
        <div>สถานะ</div>
        <div>วันเวลา</div>
    </div>

    <?php if (empty($logs)): ?>
    <div class="py-16 text-center text-gray-400">
        <i class="fa-regular fa-envelope text-4xl mb-3 block opacity-30"></i>
        <p class="font-bold">ไม่พบประวัติการส่งอีเมล</p>
        <?php if ($search || $typeFilter || $statusFilter): ?>
        <p class="text-sm mt-1">ลองล้างตัวกรองเพื่อดูข้อมูลทั้งหมด</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <?php foreach ($logs as $log):
        $tInfo    = $typeLabels[$log['type']] ?? $typeLabels[''];
        $isFailed = $log['status'] === 'failed';
        $dt       = new DateTime($log['sent_at']);
    ?>
    <div class="log-row">
        <!-- ID -->
        <div class="col-id text-gray-300 font-mono text-xs">#<?= $log['id'] ?></div>

        <!-- Recipient + Subject -->
        <div class="min-w-0">
            <div class="font-bold text-gray-900 text-[13px] truncate"><?= htmlspecialchars($log['recipient']) ?></div>
            <div class="text-gray-400 text-[11px] truncate mt-0.5"><?= htmlspecialchars($log['subject']) ?></div>
        </div>

        <!-- Type Badge -->
        <div>
            <span class="type-badge" style="background:<?= $tInfo['bg'] ?>;color:<?= $tInfo['color'] ?>">
                <i class="fa-solid <?= $tInfo['icon'] ?> text-[9px]"></i>
                <?= $tInfo['label'] ?>
            </span>
        </div>

        <!-- Error Message -->
        <div class="col-err text-[11px] text-red-500 truncate" title="<?= htmlspecialchars($log['error_msg'] ?? '') ?>">
            <?= $isFailed ? htmlspecialchars(mb_substr($log['error_msg'] ?? '', 0, 60)) : '' ?>
        </div>

        <!-- Status -->
        <div>
            <span class="type-badge <?= $isFailed ? 'status-badge-failed' : 'status-badge-sent' ?>">
                <i class="fa-solid <?= $isFailed ? 'fa-xmark' : 'fa-check' ?> text-[9px]"></i>
                <?= $isFailed ? 'ล้มเหลว' : 'สำเร็จ' ?>
            </span>
        </div>

        <!-- Timestamp -->
        <div class="text-right">
            <div class="font-bold text-gray-700 text-[12px]"><?= $dt->format('d M Y') ?></div>
            <div class="text-gray-400 text-[11px]"><?= $dt->format('H:i:s') ?></div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between mt-5">
    <p class="text-sm text-gray-400 font-medium">
        แสดง <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?> จาก <?= number_format($total) ?> รายการ
    </p>
    <div class="flex gap-2">
        <?php
        $baseUrl = '?' . http_build_query(array_filter([
            'q'      => $search,
            'type'   => $typeFilter,
            'status' => $statusFilter,
            'embed'  => $embed ? '1' : '',
        ]));
        ?>
        <a href="<?= $baseUrl ?>&page=<?= max(1, $page - 1) ?>"
           class="btn-page <?= $page <= 1 ? 'opacity-40 pointer-events-none' : '' ?>">
            <i class="fa-solid fa-chevron-left text-xs"></i>
        </a>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="<?= $baseUrl ?>&page=<?= $i ?>"
           class="btn-page <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <a href="<?= $baseUrl ?>&page=<?= min($totalPages, $page + 1) ?>"
           class="btn-page <?= $page >= $totalPages ? 'opacity-40 pointer-events-none' : '' ?>">
            <i class="fa-solid fa-chevron-right text-xs"></i>
        </a>
    </div>
</div>
<?php endif; ?>

<?php endif; // tableExists ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
