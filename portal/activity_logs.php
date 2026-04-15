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
                <div style="font-size:1rem;font-weight:900;color:#0f172a;letter-spacing:-.01em">บันทึกกิจกรรมระบบ</div>
                <div style="font-size:10px;font-weight:700;color:#94a3b8;margin-top:1px;letter-spacing:.06em">ACTIVITY LOGS</div>
            </div>
        </div>

        <form action="" method="GET" style="display:flex;gap:6px;align-items:center">
            <?php if (isset($_GET['embed'])): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <div style="position:relative">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                    placeholder="ค้นหากิจกรรม..."
                    style="padding:8px 14px 8px 30px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:500;font-family:'Prompt',sans-serif;outline:none;width:220px;background:#fff;color:#0f172a">
            </div>
            <button type="submit" style="background:#2e9e63;color:#fff;border:none;padding:8px 16px;border-radius:10px;font-size:12px;font-weight:700;font-family:'Prompt',sans-serif;cursor:pointer;letter-spacing:.03em">ค้นหา</button>
            <?php if ($search): ?>
                <a href="activity_logs.php<?= isset($_GET['embed']) ? '?embed=1' : '' ?>" style="background:#f1f5f9;color:#64748b;padding:8px 10px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center"><i class="fa-solid fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- DB Error -->
    <?php if (isset($db_error)): ?>
        <div style="margin-bottom:16px;padding:14px 18px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:12px;color:#be123c;display:flex;align-items:flex-start;gap:10px;font-size:13px">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0"></i>
            <div>
                <strong style="display:block;font-weight:800;margin-bottom:2px">Database Error</strong>
                <?= htmlspecialchars($db_error) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Table container -->
    <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;text-align:left">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:150px">วัน-เวลา</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:200px">ผู้ดำเนินการ</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:160px">กิจกรรม</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">รายละเอียด</th>
                        <th style="padding:13px 20px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em;white-space:nowrap;width:110px">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="padding:48px 20px;text-align:center;color:#94a3b8">
                                <div style="width:44px;height:44px;background:#f8fafc;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                                    <i class="fa-solid fa-folder-open" style="font-size:16px;opacity:.4"></i>
                                </div>
                                <p style="font-size:13px;font-weight:700;margin:0">ไม่พบประวัติกิจกรรม</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log):
                            if (strpos($log['action'], 'login') !== false) {
                                $badgeBg = '#f0fdf4'; $badgeBorder = '#c7e8d5'; $badgeColor = '#166534'; $icon = 'fa-right-to-bracket'; $iconColor = '#2e9e63';
                            } elseif (strpos($log['action'], 'delete') !== false) {
                                $badgeBg = '#fff1f2'; $badgeBorder = '#fecaca'; $badgeColor = '#be123c'; $icon = 'fa-trash'; $iconColor = '#e11d48';
                            } elseif (strpos($log['action'], 'update') !== false) {
                                $badgeBg = '#eff6ff'; $badgeBorder = '#bfdbfe'; $badgeColor = '#1e40af'; $icon = 'fa-pen'; $iconColor = '#3b82f6';
                            } elseif (strpos($log['action'], 'campaign') !== false) {
                                $badgeBg = '#faf5ff'; $badgeBorder = '#e9d5ff'; $badgeColor = '#6b21a8'; $icon = 'fa-bullhorn'; $iconColor = '#9333ea';
                            } else {
                                $badgeBg = '#f8fafc'; $badgeBorder = '#e2e8f0'; $badgeColor = '#475569'; $icon = 'fa-bolt'; $iconColor = '#64748b';
                            }
                        ?>
                            <tr style="border-bottom:1px solid #f8fafc" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <td style="padding:13px 20px;font-size:11px;color:#64748b;font-weight:600;white-space:nowrap">
                                    <?= date('d/m/Y H:i', strtotime($log['timestamp'])) ?>
                                </td>
                                <td style="padding:13px 20px;white-space:nowrap">
                                    <div style="display:flex;align-items:center;gap:9px">
                                        <div style="width:30px;height:30px;background:#f0faf4;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#2e9e63;font-size:11px;font-weight:900;flex-shrink:0">
                                            <?= strtoupper(mb_substr($log['actor_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div style="font-size:13px;font-weight:700;color:#0f172a"><?= htmlspecialchars($log['actor_name']) ?></div>
                                            <div style="font-size:10px;color:#94a3b8;font-weight:600">@<?= htmlspecialchars($log['actor_username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:13px 20px;white-space:nowrap">
                                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:7px;border:1px solid <?= $badgeBorder ?>;background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em">
                                        <i class="fa-solid <?= $icon ?>" style="color:<?= $iconColor ?>;font-size:9px"></i> <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td style="padding:13px 20px">
                                    <p style="font-size:13px;color:#374151;font-weight:500;max-width:480px;line-height:1.55;word-break:break-word;margin:0"><?= htmlspecialchars($log['description']) ?></p>
                                </td>
                                <td style="padding:13px 20px;white-space:nowrap">
                                    <code style="font-size:10px;background:#f8fafc;border:1px solid #e2e8f0;padding:3px 7px;border-radius:6px;color:#64748b;font-family:monospace"><?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div style="padding:13px 20px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:11px;font-weight:700;color:#94a3b8">
                <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_records)) ?> / <?= number_format($total_records) ?>
            </div>
            <div style="display:flex;gap:4px">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#fff;border:1.5px solid #e2e8f0;color:#64748b;font-size:10px;text-decoration:none"><i class="fa-solid fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                    $isActive = $i == $page;
                ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>"
                        style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;<?= $isActive ? 'background:#2e9e63;color:#fff;border:none' : 'background:#fff;border:1.5px solid #e2e8f0;color:#64748b' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?><?= isset($_GET['embed']) ? '&embed=1' : '' ?>" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#fff;border:1.5px solid #e2e8f0;color:#64748b;font-size:10px;text-decoration:none"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
