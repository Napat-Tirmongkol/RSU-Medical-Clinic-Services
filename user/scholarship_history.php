<?php
// user/scholarship_history.php — ประวัติ clock log นักศึกษาทุน (paginated)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/scholarship_helper.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$pdo = db();
ensure_scholarship_schema($pdo);

$stmt = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
$stmt->execute([':lid' => $lineUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: index.php'); exit; }

$student = get_scholarship_student_by_user($pdo, (int)$user['id']);
if (!$student) {
    header('Location: scholarship.php');
    exit;
}

$studentId = (int)$student['id'];

// Pagination
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$statusFilter = (string)($_GET['status'] ?? '');
$validStatus = ['pending', 'approved', 'rejected'];

$where = "WHERE student_id = :sid";
$params = [':sid' => $studentId];
if (in_array($statusFilter, $validStatus, true)) {
    $where .= " AND status = :status";
    $params[':status'] = $statusFilter;
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_clock_logs $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM sys_scholarship_clock_logs $where ORDER BY event_at DESC, id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function vh(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageWindow = 2;
$startPage = max(1, $page - $pageWindow);
$endPage = min($totalPages, $page + $pageWindow);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>ประวัติเก็บชั่วโมง - RSU Medical</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . vh(SITE_LOGO) : '../favicon.ico?v=' . APP_VERSION ?>">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background: #F8FAFF; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); }
        .pg-btn {
            min-width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: .75rem; background: #fff; border: 1.5px solid #e2e8f0;
            font-weight: 800; font-size: 13px; color: #475569; transition: all .15s;
        }
        .pg-btn:hover:not(:disabled) { background: #f1f5f9; border-color: #cbd5e1; }
        .pg-btn:disabled { opacity: .35; cursor: not-allowed; }
        .pg-btn.active { background: #10b981; color: #fff; border-color: #10b981; }
    </style>
</head>
<body class="text-slate-900 pb-32">

<div class="max-w-md mx-auto relative min-h-screen">
    <header class="glass-header sticky top-0 z-50 px-6 py-5 flex items-center justify-between border-b border-slate-100">
        <button onclick="window.location.href='scholarship.php'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <h1 class="text-base font-black">ประวัติเก็บชั่วโมง</h1>
        <div class="w-11"></div>
    </header>

    <main class="px-5 pt-5 space-y-4">

        <!-- Filter chips -->
        <div class="flex gap-2 overflow-x-auto pb-1">
            <?php
            $filters = [
                ''         => ['ทั้งหมด', 'slate'],
                'pending'  => ['รออนุมัติ', 'rose'],
                'approved' => ['อนุมัติ', 'emerald'],
                'rejected' => ['ปฏิเสธ', 'slate'],
            ];
            foreach ($filters as $k => [$lbl, $color]):
                $active = $statusFilter === $k;
                $cls = $active
                    ? "bg-slate-900 text-white border-slate-900"
                    : "bg-white text-slate-600 border-slate-200";
            ?>
                <a href="?status=<?= vh($k) ?>" class="px-3 py-1.5 rounded-full text-xs font-black border-2 <?= $cls ?> shrink-0"><?= vh($lbl) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Summary -->
        <div class="bg-white rounded-2xl px-4 py-3 flex items-center justify-between border border-slate-100">
            <span class="text-xs text-slate-500">รวมทั้งหมด</span>
            <span class="text-base font-black text-slate-900"><?= number_format($total) ?> <span class="text-xs text-slate-400">รายการ</span></span>
        </div>

        <!-- Logs -->
        <?php if (empty($logs)): ?>
            <div class="bg-white rounded-3xl p-10 text-center border border-slate-100">
                <i class="fa-solid fa-inbox text-3xl text-slate-300 mb-3"></i>
                <p class="text-sm text-slate-500 font-bold">ยังไม่มีประวัติ</p>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl divide-y divide-slate-100 border border-slate-100 overflow-hidden">
                <?php foreach ($logs as $log):
                    $isIn = $log['action'] === 'clock_in';
                    $statusBadge = [
                        'pending'  => ['bg-rose-50', 'text-rose-600', 'รออนุมัติ'],
                        'approved' => ['bg-emerald-50', 'text-emerald-600', 'อนุมัติ'],
                        'rejected' => ['bg-slate-100', 'text-slate-500', 'ปฏิเสธ'],
                    ][$log['status']] ?? ['bg-slate-50', 'text-slate-500', $log['status']];
                ?>
                    <div class="p-3 flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl <?= $isIn ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?> flex items-center justify-center shrink-0">
                            <i class="fa-solid <?= $isIn ? 'fa-right-to-bracket' : 'fa-right-from-bracket' ?>"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-black"><?= $isIn ? 'เข้างาน' : 'ออกงาน' ?></p>
                                <span class="px-2 py-0.5 text-[10px] font-black rounded-full <?= $statusBadge[0] ?> <?= $statusBadge[1] ?>">
                                    <?= vh($statusBadge[2]) ?>
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5"><?= vh(date('d/m/Y · H:i:s', strtotime($log['event_at']))) ?></p>
                            <?php if ($log['distance_m'] !== null): ?>
                                <p class="text-[11px] text-slate-400 mt-0.5">
                                    <i class="fa-solid fa-location-dot mr-1"></i>
                                    ห่างจากคลินิก <?= number_format((float)$log['distance_m'], 0) ?> ม.
                                    <?php if (!$log['within_radius']): ?>
                                        <span class="text-amber-500 font-bold">(นอกรัศมี)</span>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($log['status'] === 'rejected' && $log['reject_reason']): ?>
                                <p class="text-[11px] text-rose-500 mt-1"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= vh($log['reject_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="space-y-2">
                <p class="text-center text-xs text-slate-500">หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= number_format($total) ?> รายการ</p>
                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                    <?php
                    $qs = $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '';
                    ?>
                    <a href="?page=1<?= $qs ?>" class="pg-btn <?= $page <= 1 ? 'pointer-events-none opacity-35' : '' ?>" title="หน้าแรก">«</a>
                    <a href="?page=<?= max(1, $page - 1) ?><?= $qs ?>" class="pg-btn <?= $page <= 1 ? 'pointer-events-none opacity-35' : '' ?>" title="ก่อนหน้า">‹</a>
                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a href="?page=<?= $p ?><?= $qs ?>" class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <a href="?page=<?= min($totalPages, $page + 1) ?><?= $qs ?>" class="pg-btn <?= $page >= $totalPages ? 'pointer-events-none opacity-35' : '' ?>" title="ถัดไป">›</a>
                    <a href="?page=<?= $totalPages ?><?= $qs ?>" class="pg-btn <?= $page >= $totalPages ? 'pointer-events-none opacity-35' : '' ?>" title="สุดท้าย">»</a>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php
$__navActive = '';
include __DIR__ . '/../includes/user_bottom_nav.php';
?>
</body>
</html>
