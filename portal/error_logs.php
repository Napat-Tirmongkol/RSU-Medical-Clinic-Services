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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Logs - Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body style="background:#f4f7f5;min-height:100vh;font-family:'Prompt',sans-serif;color:#0f172a;<?= isset($_GET['embed']) ? 'padding:0' : 'padding:24px 28px' ?>">

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
            <button type="submit" style="background:#2e9e63;color:#fff;border:none;padding:8px 18px;border-radius:9px;font-size:12px;font-weight:700;font-family:'Prompt',sans-serif;cursor:pointer;letter-spacing:.03em;align-self:flex-end">กรอง</button>
            <?php if ($search || $filterLevel || $filterDate || $filterSource): ?>
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
                                <td style="padding:13px 20px;font-size:11px;color:#64748b;font-weight:600;white-space:nowrap">
                                    <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                                    <div style="font-size:10px;margin-top:3px;color:#94a3b8;font-family:monospace"><?= htmlspecialchars($log['ip_address'] ?? '') ?></div>
                                </td>
                                <td style="padding:13px 20px">
                                    <div style="font-size:13px;color:#0f172a;font-weight:600;line-height:1.55;word-break:break-word;margin-bottom:6px"><?= htmlspecialchars($log['message']) ?></div>
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

</body>
</html>
