<?php
// portal/error_logs.php (Command Center Style)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ─── Auto-create tables ───────────────────────────────────────────────────────
try {
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
        source     VARCHAR(300)  NOT NULL DEFAULT '',
        message    TEXT          NOT NULL,
        context    TEXT          NOT NULL DEFAULT '',
        ip_address VARCHAR(45)   NOT NULL DEFAULT '',
        user_id    INT UNSIGNED  NULL,
        created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notified_at DATETIME     NULL DEFAULT NULL,
        status     ENUM('New', 'Active', 'Resolved') NOT NULL DEFAULT 'New',
        resolve_comment TEXT NULL,
        INDEX idx_level      (level),
        INDEX idx_created_at (created_at),
        INDEX idx_status     (status)
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
        `key`       VARCHAR(100) NOT NULL PRIMARY KEY,
        `value`     TEXT         NOT NULL DEFAULT '',
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    $fatal = $e->getMessage();
}

// ─── Save alert email setting ─────────────────────────────────────────────────
$savedOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_alert_email') {
    $emailVal = trim($_POST['alert_email'] ?? '');
    if ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
        $setting_error = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        $pdo->prepare("INSERT INTO sys_settings (`key`, `value`) VALUES ('admin_alert_email', ?)
                       ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$emailVal]);
        $savedOk = true;
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

// ─── Update status action ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $lid = (int)($_POST['log_id'] ?? 0);
    $status = $_POST['status'] ?? 'New';
    $comment = $_POST['resolve_comment'] ?? '';
    if ($lid > 0 && in_array($status, ['New', 'Active', 'Resolved'], true)) {
        $pdo->prepare("UPDATE sys_error_logs SET status=?, resolve_comment=? WHERE id=?")->execute([$status, $comment, $lid]);
        header('Location: error_logs.php?updated=1' . (isset($_POST['embed']) ? '&embed=1' : ''));
        exit;
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
$filterStatus = $_GET['status'] ?? '';
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
if (in_array($filterStatus, ['New', 'Active', 'Resolved'], true)) {
    $where   .= " AND status = ?";
    $params[] = $filterStatus;
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
        'error'   => 'background:#fff1f2;border:1px solid #fecaca;color:#be123c',
        'warning' => 'background:#fffbeb;border:1px solid #fde68a;color:#92400e',
        default   => 'background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af',
    };
}
function levelIcon(string $level): string {
    return match($level) {
        'error'   => 'fa-circle-xmark',
        'warning' => 'fa-triangle-exclamation',
        default   => 'fa-circle-info',
    };
}
function levelIconColor(string $level): string {
    return match($level) {
        'error'   => '#e11d48',
        'warning' => '#d97706',
        default   => '#3b82f6',
    };
}
function statusBadge(string $s): string {
    return match($s) {
        'New'      => 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b',
        'Active'   => 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8',
        'Resolved' => 'background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d',
        default    => 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b',
    };
}
function statusIcon(string $s): string {
    return match($s) { 'New' => 'fa-sparkles', 'Active' => 'fa-spinner fa-spin', 'Resolved' => 'fa-check-circle', default => 'fa-circle' };
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Logs - Portal</title>
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body style="background:#f4f7f5;min-height:100vh;font-family:'rsufont',sans-serif;color:#0f172a;<?= isset($_GET['embed']) ? 'padding:0' : 'padding:24px 28px' ?>">

<div style="max-width:1200px;margin:0 auto">

    <!-- Back btn -->
    <?php if (!isset($_GET['embed'])): ?>
    <a href="index.php" style="display:inline-flex;align-items:center;gap:8px;padding:7px 16px;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;color:#64748b;font-size:12px;font-weight:700;text-decoration:none;margin-bottom:20px">
        <i class="fa-solid fa-arrow-left" style="font-size:10px"></i> กลับหน้า Portal
    </a>
    <?php endif; ?>

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:4px;height:22px;background:linear-gradient(180deg,#2e9e63,#6ee7b7);border-radius:99px;flex-shrink:0"></div>
            <div>
                <div style="font-size:1rem;font-weight:900;color:#0f172a;letter-spacing:-.01em">Error Logs</div>
                <div style="font-size:10px;font-weight:700;color:#94a3b8;margin-top:1px;letter-spacing:.06em">SYSTEM DIAGNOSTICS</div>
            </div>
        </div>

        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <a href="?export=csv&<?= http_build_query($_GET) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;color:#475569;font-size:12px;font-weight:700;text-decoration:none"><i class="fa-solid fa-file-csv" style="color:#2e9e63"></i> CSV</a>
            <a href="?export=json&<?= http_build_query($_GET) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;color:#475569;font-size:12px;font-weight:700;text-decoration:none"><i class="fa-solid fa-file-code" style="color:#3b82f6"></i> JSON</a>
            <button onclick="document.getElementById('clearModal').style.display='flex'" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:10px;color:#be123c;font-size:12px;font-weight:700;cursor:pointer;font-family:'Prompt',sans-serif"><i class="fa-solid fa-trash"></i> ล้าง Log</button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($fatal)): ?>
        <div style="margin-bottom:16px;padding:14px 18px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:12px;color:#be123c;display:flex;align-items:flex-start;gap:10px;font-size:13px">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0"></i>
            <div><strong style="display:block;font-weight:800;margin-bottom:2px">Fatal Initialization Error</strong><?= htmlspecialchars($fatal) ?></div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['cleared'])): ?>
    <div style="margin-bottom:16px;padding:12px 18px;background:#f0fdf4;border:1.5px solid #c7e8d5;border-radius:12px;color:#166534;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px">
        <i class="fa-solid fa-check-circle" style="color:#2e9e63"></i> ลบ Log เรียบร้อยแล้ว
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['saved'])): ?>
    <div style="margin-bottom:16px;padding:12px 18px;background:#f0fdf4;border:1.5px solid #c7e8d5;border-radius:12px;color:#166534;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px">
        <i class="fa-solid fa-check-circle" style="color:#2e9e63"></i> บันทึกการตั้งค่าเรียบร้อยแล้ว
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
    <div style="margin-bottom:16px;padding:12px 18px;background:#f0fdf4;border:1.5px solid #c7e8d5;border-radius:12px;color:#166534;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px">
        <i class="fa-solid fa-check-circle" style="color:#2e9e63"></i> อัปเดตสถานะเรียบร้อยแล้ว
    </div>
    <?php endif; ?>

    <!-- Summary strip -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">
        <a href="?level=error<?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;text-decoration:none;transition:border-color .18s" onmouseover="this.style.borderColor='#fecaca'" onmouseout="this.style.borderColor='#e2e8f0'">
            <div style="width:36px;height:36px;border-radius:10px;background:#fff1f2;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-circle-xmark" style="color:#e11d48;font-size:16px"></i></div>
            <div><div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">Error</div><div style="font-size:1.4rem;font-weight:900;color:#0f172a;line-height:1.1"><?= number_format($summary['error'] ?? 0) ?></div></div>
        </a>
        <a href="?level=warning<?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;text-decoration:none;transition:border-color .18s" onmouseover="this.style.borderColor='#fde68a'" onmouseout="this.style.borderColor='#e2e8f0'">
            <div style="width:36px;height:36px;border-radius:10px;background:#fffbeb;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:16px"></i></div>
            <div><div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">Warning</div><div style="font-size:1.4rem;font-weight:900;color:#0f172a;line-height:1.1"><?= number_format($summary['warning'] ?? 0) ?></div></div>
        </a>
        <a href="?level=info<?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;text-decoration:none;transition:border-color .18s" onmouseover="this.style.borderColor='#bfdbfe'" onmouseout="this.style.borderColor='#e2e8f0'">
            <div style="width:36px;height:36px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-circle-info" style="color:#3b82f6;font-size:16px"></i></div>
            <div><div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">Info</div><div style="font-size:1.4rem;font-weight:900;color:#0f172a;line-height:1.1"><?= number_format($summary['info'] ?? 0) ?></div></div>
        </a>
        <a href="?<?= isset($_GET['embed']) ? 'embed=1' : '' ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;text-decoration:none;transition:border-color .18s" onmouseover="this.style.borderColor='#c7e8d5'" onmouseout="this.style.borderColor='#e2e8f0'">
            <div style="width:36px;height:36px;border-radius:10px;background:#f0faf4;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-globe" style="color:#2e9e63;font-size:16px"></i></div>
            <div><div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">Total</div><div style="font-size:1.4rem;font-weight:900;color:#0f172a;line-height:1.1"><?= number_format(array_sum($summary)) ?></div></div>
        </a>
    </div>

    <!-- Alert Email Settings -->
    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px 20px;margin-bottom:14px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
            <div style="width:28px;height:28px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fa-solid fa-bell" style="color:#3b82f6;font-size:12px"></i>
            </div>
            <div>
                <div style="font-size:12px;font-weight:800;color:#0f172a">แจ้งเตือน Error ทางอีเมล</div>
                <div style="font-size:10px;color:#94a3b8;font-weight:600">ส่ง Error Digest ทุก 30 นาทีเมื่อมี error ใหม่</div>
            </div>
        </div>
        <form method="POST" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
            <input type="hidden" name="action" value="save_alert_email">
            <?php if (isset($_GET['embed'])): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <div style="flex:1;min-width:220px">
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">อีเมล Admin (ว่างเปล่า = ปิดการแจ้งเตือน)</label>
                <div style="position:relative">
                    <i class="fa-solid fa-envelope" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:10px"></i>
                    <input type="email" name="alert_email" value="<?= htmlspecialchars($currentAlertEmail) ?>" placeholder="admin@example.com"
                        style="width:100%;padding:8px 12px 8px 28px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:500;font-family:'rsufont',sans-serif;outline:none;background:#f8fafc;color:#0f172a;box-sizing:border-box">
                </div>
                <?php if (isset($setting_error)): ?>
                    <div style="font-size:11px;color:#dc2626;margin-top:4px;font-weight:600"><?= htmlspecialchars($setting_error) ?></div>
                <?php endif; ?>
            </div>
            <button type="submit" style="background:#3b82f6;color:#fff;border:none;padding:8px 18px;border-radius:9px;font-size:12px;font-weight:700;font-family:'rsufont',sans-serif;cursor:pointer;white-space:nowrap">
                <i class="fa-solid fa-floppy-disk" style="margin-right:5px"></i>บันทึก
            </button>
            <?php if ($currentAlertEmail): ?>
            <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:#059669;font-weight:700;padding:8px 12px;background:#f0fdf4;border:1.5px solid #c7e8d5;border-radius:9px">
                <i class="fa-solid fa-circle-check"></i> กำลังส่งไปยัง <?= htmlspecialchars($currentAlertEmail) ?>
            </div>
            <?php else: ?>
            <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:#94a3b8;font-weight:700;padding:8px 12px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:9px">
                <i class="fa-solid fa-bell-slash"></i> ปิดการแจ้งเตือน
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 16px;margin-bottom:14px">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <?php if (isset($_GET['embed'])): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <div style="flex:1;min-width:180px">
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">ค้นหาข้อความ</label>
                <div style="position:relative">
                    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:10px"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" style="width:100%;padding:8px 12px 8px 28px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:500;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#0f172a">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">ระดับ</label>
                <select name="level" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:600;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#374151">
                    <option value="">ทั้งหมด</option>
                    <option value="error" <?= $filterLevel === 'error' ? 'selected' : '' ?>>🔴 Error</option>
                    <option value="warning" <?= $filterLevel === 'warning' ? 'selected' : '' ?>>🟡 Warning</option>
                    <option value="info" <?= $filterLevel === 'info' ? 'selected' : '' ?>>🔵 Info</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">ประเภท</label>
                <select name="source" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:600;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#374151">
                    <option value="">ทั้งหมด</option>
                    <option value="php" <?= $filterSource === 'php' ? 'selected' : '' ?>>Backend (PHP)</option>
                    <option value="js" <?= $filterSource === 'js' ? 'selected' : '' ?>>Frontend (JS)</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">วันที่</label>
                <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:600;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#374151">
            </div>
            <div>
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">สถานะ</label>
                <select name="status" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:600;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#374151">
                    <option value="">ทั้งหมด</option>
                    <option value="New"      <?= $filterStatus === 'New' ? 'selected' : '' ?>>New</option>
                    <option value="Active"   <?= $filterStatus === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Resolved" <?= $filterStatus === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                </select>
            </div>
            <button type="submit" style="background:#2e9e63;color:#fff;border:none;padding:8px 18px;border-radius:9px;font-size:12px;font-weight:700;font-family:'Prompt',sans-serif;cursor:pointer;letter-spacing:.03em;align-self:flex-end">กรอง</button>
            <?php if ($search || $filterLevel || $filterStatus || $filterDate || $filterSource): ?>
                <a href="error_logs.php<?= isset($_GET['embed']) ? '?embed=1' : '' ?>" style="background:#f1f5f9;color:#64748b;padding:8px 14px;border-radius:9px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;align-self:flex-end">ล้างค่า</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;text-align:left">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:130px">ระดับ</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:120px">สถานะ</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:140px">วัน-เวลา</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ข้อความและรายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="3" style="padding:48px 20px;text-align:center;color:#94a3b8">
                                <div style="width:44px;height:44px;background:#f0fdf4;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                                    <i class="fa-solid fa-check" style="font-size:16px;color:#2e9e63;opacity:.6"></i>
                                </div>
                                <p style="font-size:13px;font-weight:700;margin:0">ไม่พบ Error Logs ตรงกับเงื่อนไข</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr style="border-bottom:1px solid #f8fafc;vertical-align:top" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <td style="padding:13px 20px;white-space:nowrap">
                                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:7px;<?= levelBadge($log['level']) ?>;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em">
                                        <i class="fa-solid <?= levelIcon($log['level']) ?>" style="color:<?= levelIconColor($log['level']) ?>;font-size:9px"></i> <?= $log['level'] ?>
                                    </span>
                                    <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-top:5px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($log['source']) ?>">
                                        <i class="fa-solid fa-code-branch" style="font-size:9px;opacity:.5;margin-right:3px"></i><?= htmlspecialchars($log['source'] ?: 'system') ?>
                                    </div>
                                </td>
                                <td style="padding:13px 20px">
                                    <button onclick="openStatusModal(<?= $log['id'] ?>, '<?= $log['status'] ?>', <?= htmlspecialchars(json_encode($log['resolve_comment'] ?: '')) ?>)" 
                                        id="status-btn-<?= $log['id'] ?>"
                                        style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:10px;<?= statusBadge($log['status']) ?>;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;transition:all .15s"
                                        onmouseover="this.style.transform='scale(1.05)';this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.1)'"
                                        onmouseout="this.style.transform='scale(1)';this.style.boxShadow='none'"
                                        onmousedown="this.style.transform='scale(0.95)'"
                                        onmouseup="this.style.transform='scale(1.05)'">
                                        <i class="fa-solid <?= statusIcon($log['status']) ?>" style="font-size:9px"></i> <span class="status-label"><?= $log['status'] ?></span>
                                    </button>
                                </td>
                                <td style="padding:13px 20px;font-size:11px;color:#64748b;font-weight:600;white-space:nowrap">
                                    <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                                    <div style="font-size:10px;margin-top:3px;color:#94a3b8;font-family:monospace"><?= htmlspecialchars($log['ip_address'] ?? '') ?></div>
                                </td>
                                <td style="padding:13px 20px">
                                    <div id="message-container-<?= $log['id'] ?>">
                                        <div style="font-size:13px;color:#0f172a;font-weight:600;line-height:1.55;word-break:break-word;margin-bottom:6px"><?= htmlspecialchars($log['message']) ?></div>
                                        <div class="resolve-comment-area" style="display: <?= $log['resolve_comment'] ? 'flex' : 'none' ?>; align-items:flex-start;gap:8px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin-bottom:8px">
                                            <i class="fa-solid fa-comment-dots" style="color:#15803d;font-size:12px;margin-top:2px"></i>
                                            <div class="comment-text" style="font-size:11px;color:#15803d;font-weight:600;font-style:italic"><?= htmlspecialchars($log['resolve_comment'] ?: '') ?></div>
                                        </div>
                                    </div>
                                    <?php if ($log['context']): ?>
                                        <pre style="font-size:10px;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;padding:10px 12px;border-radius:8px;overflow-x:auto;font-family:monospace;white-space:pre-wrap;line-height:1.55;margin:0"><code><?= htmlspecialchars($log['context']) ?></code></pre>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div style="padding:13px 20px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:11px;font-weight:700;color:#94a3b8">
                <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total)) ?> / <?= number_format($total) ?>
            </div>
            <div style="display:flex;gap:4px">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&level=<?= urlencode($filterLevel) ?>&date=<?= urlencode($filterDate) ?>&source=<?= urlencode($filterSource) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#fff;border:1.5px solid #e2e8f0;color:#64748b;font-size:10px;text-decoration:none"><i class="fa-solid fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                    $isActive = $i == $page;
                ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&level=<?= urlencode($filterLevel) ?>&date=<?= urlencode($filterDate) ?>&source=<?= urlencode($filterSource) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>"
                        style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;<?= $isActive ? 'background:#2e9e63;color:#fff;border:none' : 'background:#fff;border:1.5px solid #e2e8f0;color:#64748b' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&level=<?= urlencode($filterLevel) ?>&date=<?= urlencode($filterDate) ?>&source=<?= urlencode($filterSource) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#fff;border:1.5px solid #e2e8f0;color:#64748b;font-size:10px;text-decoration:none"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Clear Modal -->
<div id="clearModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:50;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;width:100%;max-width:360px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;background:#fff1f2">
            <div style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800;color:#be123c">
                <i class="fa-solid fa-trash-can" style="color:#e11d48"></i> ล้างข้อมูล Log
            </div>
            <button onclick="document.getElementById('clearModal').style.display='none'" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:none;background:transparent;cursor:pointer;color:#94a3b8;font-size:14px"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" style="padding:20px">
            <p style="font-size:13px;font-weight:500;color:#475569;margin:0 0 16px">คุณต้องการลบข้อมูล Error Logs ประเภทใด? ข้อมูลที่ถูกลบไปแล้วไม่สามารถกู้คืนได้</p>
            <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">เลือกระดับที่จะลบ</label>
            <select name="clear_level" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-weight:600;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#374151;margin-bottom:16px">
                <option value="all">ลบทั้งหมด (All Levels)</option>
                <option value="error">เฉพาะระดับ Error</option>
                <option value="warning">เฉพาะระดับ Warning</option>
                <option value="info">เฉพาะระดับ Info</option>
            </select>
            <input type="hidden" name="action" value="clear">
            <div style="display:flex;gap:8px">
                <button type="button" onclick="document.getElementById('clearModal').style.display='none'" style="flex:1;padding:9px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:13px;font-weight:700;font-family:'Prompt',sans-serif;cursor:pointer">ยกเลิก</button>
                <button type="submit" style="flex:1;padding:9px;border-radius:9px;border:none;background:#be123c;color:#fff;font-size:13px;font-weight:700;font-family:'Prompt',sans-serif;cursor:pointer">ยืนยันการลบ</button>
            </div>
        </form>
    </div>
</div>

<!-- Status Modal -->
<div id="statusModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:50;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;width:100%;max-width:380px;overflow:hidden;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1)">
        <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;background:#f8fafc">
            <div style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800;color:#0f172a">
                <i class="fa-solid fa-pen-to-square" style="color:#3b82f6"></i> อัปเดตสถานะ Error
            </div>
            <button onclick="closeStatusModal()" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:none;background:transparent;cursor:pointer;color:#94a3b8;font-size:14px"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="statusUpdateForm" onsubmit="handleStatusUpdate(event)" style="padding:20px">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="log_id" id="modal_log_id">
            <?php if (isset($_GET['embed'])): ?><input type="hidden" name="embed" value="1"><?php endif; ?>

            <div style="margin-bottom:16px">
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:8px">เลือกสถานะ</label>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                    <label style="cursor:pointer" class="group">
                        <input type="radio" name="modal_status" value="New" style="display:none" class="status-radio" id="radio_new">
                        <div class="status-box" style="padding:12px 5px;text-align:center;border:2px solid #f1f5f9;border-radius:12px;font-size:10px;font-weight:800;color:#64748b;transition:all .15s">
                            <i class="fa-solid fa-sparkles" style="display:block;margin-bottom:4px;font-size:14px;opacity:.5"></i> NEW
                        </div>
                    </label>
                    <label style="cursor:pointer" class="group">
                        <input type="radio" name="modal_status" value="Active" style="display:none" class="status-radio" id="radio_active">
                        <div class="status-box" style="padding:12px 5px;text-align:center;border:2px solid #f1f5f9;border-radius:12px;font-size:10px;font-weight:800;color:#64748b;transition:all .15s">
                            <i class="fa-solid fa-spinner fa-spin-pulse" style="display:block;margin-bottom:4px;font-size:14px;opacity:.5"></i> ACTIVE
                        </div>
                    </label>
                    <label style="cursor:pointer" class="group">
                        <input type="radio" name="modal_status" value="Resolved" style="display:none" class="status-radio" id="radio_resolved" onchange="toggleComment(this.checked)">
                        <div class="status-box" style="padding:12px 5px;text-align:center;border:2px solid #f1f5f9;border-radius:12px;font-size:10px;font-weight:800;color:#64748b;transition:all .15s">
                            <i class="fa-solid fa-check-circle" style="display:block;margin-bottom:4px;font-size:14px;opacity:.5"></i> RESOLVED
                        </div>
                    </label>
                </div>
            </div>

            <div id="comment_field" style="display:none;margin-bottom:20px">
                <label style="display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:5px">บันทึกการแก้ไข (Comment)</label>
                <textarea name="resolve_comment" id="modal_comment" placeholder="ระบุรายละเอียดการแก้ไขปัญหา..." style="width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:'Prompt',sans-serif;outline:none;background:#f8fafc;color:#0f172a;height:90px;resize:none;box-sizing:border-box;font-weight:500"></textarea>
            </div>

            <div style="display:flex;gap:8px">
                <button type="button" onclick="closeStatusModal()" style="flex:1;padding:12px;border-radius:12px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:12px;font-weight:800;text-transform:uppercase;font-family:'Prompt',sans-serif;cursor:pointer">ยกเลิก</button>
                <button type="submit" id="statusSubmitBtn" style="flex:1;padding:12px;border-radius:12px;border:none;background:#0f172a;color:#fff;font-size:12px;font-weight:800;text-transform:uppercase;font-family:'Prompt',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px shadow-lg shadow-gray-100">
                    <span id="submitBtnText">บันทึก</span>
                    <i id="submitBtnIcon" class="fa-solid fa-check"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.status-radio:checked + .status-box { border-color: #3b82f6; background: #eff6ff; color: #1d4ed8; }
.status-radio:checked + .status-box i { opacity: 1 !important; color: #3b82f6; }
#radio_active:checked + .status-box { border-color: #3b82f6; background: #eff6ff; color: #1d4ed8; }
#radio_resolved:checked + .status-box { border-color: #10b981; background: #f0fdf4; color: #065f46; }
#radio_resolved:checked + .status-box i { opacity: 1 !important; color: #10b981; }
.status-box:hover { border-color: #e2e8f0; background: #f8fafc; }
.group:active .status-box { transform: scale(0.95); }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    submitBtn.style.opacity = '0.7';
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
            updateStatusUI(logId, status, comment);
            
            Swal.fire({
                icon: 'success',
                title: 'สำเร็จ',
                text: 'อัปเดตสถานะเรียบร้อยแล้ว',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
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
        submitBtn.style.opacity = '1';
        btnText.textContent = 'บันทึก';
        btnIcon.className = 'fa-solid fa-check';
    }
}

function updateStatusUI(id, status, comment) {
    const btn = document.getElementById('status-btn-' + id);
    const label = btn.querySelector('.status-label');
    const icon = btn.querySelector('i');
    
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
    
    btn.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:10px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;transition:all .15s;' + styles;
    label.textContent = status;
    icon.className = iconClass;
    
    const container = document.getElementById('message-container-' + id);
    const commentArea = container.querySelector('.resolve-comment-area');
    const commentText = container.querySelector('.comment-text');
    
    if (status === 'Resolved' && comment) {
        commentArea.style.display = 'flex';
        commentText.textContent = comment;
    } else {
        commentArea.style.display = 'none';
    }
    
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
    
    toggleComment(status === 'Resolved');
    document.getElementById('statusModal').style.display = 'flex';
}
function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}
function toggleComment(show) {
    document.getElementById('comment_field').style.display = show ? 'block' : 'none';
}
</script>

</body>
</html>
