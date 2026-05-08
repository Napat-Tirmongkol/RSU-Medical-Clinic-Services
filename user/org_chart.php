<?php
// user/org_chart.php — ผังองค์กร / Chain of Command (user-facing, view-only)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
$_testToken = $__secrets['PLAYWRIGHT_TEST_TOKEN'] ?? '';
$isTest = $_testToken !== '' && isset($_GET['test_token']) && hash_equals($_testToken, $_GET['test_token']);

if ($lineUserId === '' && !$isTest) {
    header('Location: index.php');
    exit;
}

$pdo = db();

// Auto-migrate (idempotent) — same definitions as portal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_org_positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NULL, title VARCHAR(255) NOT NULL, short_title VARCHAR(100) NULL,
        description TEXT NULL, level TINYINT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0,
        card_style ENUM('premium','simple') NOT NULL DEFAULT 'simple',
        show_section_header TINYINT(1) NOT NULL DEFAULT 1, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_parent (parent_id), INDEX idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_org_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        position_id INT NULL, prefix VARCHAR(50) NULL, full_name VARCHAR(255) NOT NULL,
        photo_url VARCHAR(500) NULL, license_no VARCHAR(100) NULL, responsibilities TEXT NULL,
        department VARCHAR(255) NULL, staff_id INT NULL, user_id INT NULL,
        display_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_position (position_id), INDEX idx_user (user_id), INDEX idx_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

// ── Resolve current user ID (sys_users.id) ─────────────────────────────────
$myUserId = null;
try {
    $st = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
    $st->execute([':lid' => $lineUserId]);
    $row = $st->fetch();
    if ($row) $myUserId = (int)$row['id'];
} catch (PDOException) {}

// ── Load positions + members ──────────────────────────────────────────────
$positions = [];
$members   = [];
try {
    $positions = $pdo->query("SELECT * FROM sys_org_positions WHERE is_active = 1 ORDER BY level ASC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $members   = $pdo->query("SELECT * FROM sys_org_members WHERE is_active = 1 ORDER BY position_id ASC, display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

// Build lookup tables
$posById = [];
$childrenByParent = [];
foreach ($positions as $p) {
    $posById[(int)$p['id']] = $p;
    $pid = $p['parent_id'] !== null ? (int)$p['parent_id'] : 0;
    $childrenByParent[$pid][] = $p;
}
$membersByPos = [];
foreach ($members as $m) {
    $pid = $m['position_id'] !== null ? (int)$m['position_id'] : 0;
    $membersByPos[$pid][] = $m;
}

// ── Find "me" + ancestor chain ────────────────────────────────────────────
$myMember = null;
$myPositionId = null;
$ancestorPositionIds = []; // positions in my chain of command (going up)
if ($myUserId !== null) {
    foreach ($members as $m) {
        if ($m['user_id'] !== null && (int)$m['user_id'] === $myUserId) {
            $myMember = $m;
            $myPositionId = $m['position_id'] !== null ? (int)$m['position_id'] : null;
            break;
        }
    }
    if ($myPositionId !== null && isset($posById[$myPositionId])) {
        $cur = $posById[$myPositionId];
        $guard = 0;
        while ($cur && $cur['parent_id'] !== null && $guard++ < 50) {
            $parentId = (int)$cur['parent_id'];
            if (!isset($posById[$parentId])) break;
            $ancestorPositionIds[] = $parentId;
            $cur = $posById[$parentId];
        }
    }
}
$ancestorSet = array_flip($ancestorPositionIds);

// Build chain-of-command path (root → me)
$chainPath = [];
if ($myMember && $myPositionId !== null) {
    $chainPath = array_reverse($ancestorPositionIds);
    $chainPath[] = $myPositionId;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function ocEsc(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ocPhotoOrInitial(array $m, string $size = 'lg'): string {
    if (!empty($m['photo_url'])) {
        $cls = $size === 'lg' ? 'ocp-photo' : 'ocs-photo';
        return '<img src="' . ocEsc($m['photo_url']) . '" alt="" class="' . $cls . '" loading="lazy">';
    }
    $initial = mb_substr(trim((string)$m['full_name']) ?: '?', 0, 1, 'UTF-8');
    $bg = $size === 'lg'
        ? 'background:linear-gradient(135deg,#34d399,#059669);'
        : 'background:linear-gradient(160deg,#34d399,#10b981);';
    $cls = $size === 'lg'
        ? 'ocp-photo flex items-center justify-center text-white text-5xl font-black'
        : 'ocs-photo flex items-center justify-center text-white text-3xl font-black';
    return '<div class="' . $cls . '" style="' . $bg . '">' . ocEsc($initial) . '</div>';
}

function ocResponsibilitiesHtml(string $resp): string {
    $lines = array_filter(array_map('trim', preg_split('/\r?\n|•/u', $resp)));
    if (empty($lines)) return '';
    $out = '';
    foreach ($lines as $line) {
        $out .= '<div>• ' . ocEsc($line) . '</div>';
    }
    return $out;
}

// Render functions: premium card and simple card
function ocRenderPremiumCard(array $m, bool $isMe, bool $inChain): string {
    $classes = ['org-card-premium'];
    if ($isMe) $classes[] = 'org-card-me';
    if ($inChain) $classes[] = 'org-card-in-chain';
    $cls = implode(' ', $classes);

    $name = trim(($m['prefix'] ?? '') . ' ' . $m['full_name']);
    $resp = !empty($m['responsibilities']) ? ocResponsibilitiesHtml($m['responsibilities']) : '';
    $deptOrLicense = '';
    if (!empty($m['license_no'])) {
        $deptOrLicense = '<div class="ocp-license"><i class="fa-solid fa-id-badge mr-1"></i>ใบอนุญาตฯ: ' . ocEsc($m['license_no']) . '</div>';
    }
    $body = '';
    if ($resp || !empty($m['department'])) {
        $body .= '<div class="ocp-body">';
        if ($resp) {
            $body .= '<strong>หน้าที่</strong>' . $resp;
        }
        if (!empty($m['department'])) {
            $body .= '<div class="mt-1 pt-1 border-t border-emerald-100"><strong>สังกัด:</strong> ' . ocEsc($m['department']) . '</div>';
        }
        $body .= '</div>';
    }

    return '<article class="' . $cls . '">
        <svg class="ocp-bg-shapes" viewBox="0 0 288 380" preserveAspectRatio="none" aria-hidden="true">
            <circle cx="55" cy="55" r="55" fill="rgba(255,255,255,0.12)"/>
            <circle cx="240" cy="40" r="35" fill="rgba(251,191,36,0.18)"/>
            <circle cx="260" cy="120" r="20" fill="rgba(255,255,255,0.18)"/>
            <path d="M0 320 Q 144 280 288 320 L 288 380 L 0 380 Z" fill="rgba(255,255,255,0.10)"/>
            <circle cx="40" cy="350" r="12" fill="rgba(251,191,36,0.25)"/>
        </svg>
        <div class="ocp-photo-wrap">' . ocPhotoOrInitial($m, 'lg') . '</div>
        <div class="ocp-name-pill">' . ocEsc($name) . '</div>
        ' . $deptOrLicense . $body . '
    </article>';
}

function ocRenderSimpleCard(array $m, string $positionTitle, bool $isMe, bool $inChain): string {
    $classes = ['org-card-simple'];
    if ($isMe) $classes[] = 'org-card-me';
    if ($inChain) $classes[] = 'org-card-in-chain';
    $cls = implode(' ', $classes);

    $name = trim(($m['prefix'] ?? '') . ' ' . $m['full_name']);

    return '<article class="' . $cls . '">
        <div class="ocs-bg">
            <svg class="absolute inset-0 w-full h-full pointer-events-none opacity-90" viewBox="0 0 176 160" preserveAspectRatio="none" aria-hidden="true">
                <circle cx="35" cy="25" r="6" fill="rgba(255,255,255,0.40)"/>
                <circle cx="155" cy="20" r="4" fill="rgba(251,191,36,0.55)"/>
                <circle cx="20" cy="135" r="5" fill="rgba(251,191,36,0.40)"/>
                <path d="M0 0 L 30 0 L 0 30 Z" fill="rgba(255,255,255,0.20)"/>
            </svg>
            <div class="relative">' . ocPhotoOrInitial($m, 'sm') . '</div>
        </div>
        <div class="ocs-pills">
            <span class="ocs-name-pill">' . ocEsc($name) . '</span>
            <span class="ocs-role-pill">' . ocEsc($positionTitle) . '</span>
        </div>
    </article>';
}

// Recursive renderer (DFS pre-order — parent then children)
function ocRenderTree(array $childrenByParent, int $parentId, array $membersByPos, array $posById, array $ancestorSet, ?array $myMember, ?int $myPositionId): string {
    $out = '';
    $kids = $childrenByParent[$parentId] ?? [];
    foreach ($kids as $pos) {
        $pid = (int)$pos['id'];
        $posMembers = $membersByPos[$pid] ?? [];
        $hasContent = count($posMembers) > 0;
        $inChain = isset($ancestorSet[$pid]) || ($myPositionId !== null && $myPositionId === $pid);
        $isMyPos = ($myPositionId !== null && $myPositionId === $pid);

        if ($hasContent) {
            if (!empty($pos['show_section_header'])) {
                $out .= '<div class="org-section-title">' . ocEsc($pos['title']) . '</div>';
                $out .= '<div class="org-section-underline"></div>';
            }
            $out .= '<div class="org-row">';
            foreach ($posMembers as $m) {
                $isMe = ($myMember && (int)$m['id'] === (int)$myMember['id']);
                if ($pos['card_style'] === 'premium') {
                    $out .= ocRenderPremiumCard($m, $isMe, $inChain && !$isMe);
                } else {
                    $out .= ocRenderSimpleCard($m, $pos['title'], $isMe, $inChain && !$isMe);
                }
            }
            $out .= '</div>';
        }
        // Recurse to children
        $out .= ocRenderTree($childrenByParent, $pid, $membersByPos, $posById, $ancestorSet, $myMember, $myPositionId);
    }
    return $out;
}

$mainHtml = ocRenderTree($childrenByParent, 0, $membersByPos, $posById, $ancestorSet, $myMember, $myPositionId);
$totalPositions = count($positions);
$totalMembers   = count($members);

$__navActive = '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ผังองค์กร · RSU Medical Clinic</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . ocEsc(SITE_LOGO) : '../favicon.ico?v=' . APP_VERSION ?>">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css?v=<?= APP_VERSION ?>" rel="stylesheet">
    <style>
        body { font-family: 'RSU', 'Sarabun', sans-serif; background-color: #F8FAFF; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
    </style>
</head>
<body class="text-slate-900 pb-32">

    <div class="max-w-3xl mx-auto relative min-h-screen">

        <!-- Header -->
        <header class="glass-header sticky top-0 z-[60] px-6 py-5 flex items-center justify-between border-b border-slate-100 shadow-sm">
            <button onclick="window.location.href='hub.php'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition-all">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <h1 class="text-lg font-black text-slate-900 tracking-tight">ผังองค์กร</h1>
            <div class="w-11 h-11 flex items-center justify-center bg-emerald-50 rounded-2xl text-emerald-600">
                <i class="fa-solid fa-sitemap"></i>
            </div>
        </header>

        <main class="px-4 sm:px-6 pt-6 pb-12">

            <!-- Hero / stats -->
            <div class="bg-white rounded-[2rem] p-5 border border-slate-100 shadow-sm mb-6 flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white text-xl shadow">
                    <i class="fa-solid fa-people-group"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Chain of Command</p>
                    <h2 class="text-base font-black text-slate-800 mt-0.5">โครงสร้างคลินิก</h2>
                    <p class="text-[11px] font-bold text-slate-500 mt-0.5">
                        <?= $totalPositions ?> ตำแหน่ง · <?= $totalMembers ?> สมาชิก
                    </p>
                </div>
            </div>

            <?php if ($myMember && !empty($chainPath)): ?>
                <!-- "Your chain of command" panel -->
                <div class="bg-amber-50 border border-amber-100 rounded-[1.5rem] p-4 mb-6">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-700 mb-2">
                        <i class="fa-solid fa-route mr-1"></i>สายบังคับบัญชาของคุณ
                    </p>
                    <div class="flex flex-wrap items-center gap-2 text-[13px] font-bold text-slate-700">
                        <?php foreach ($chainPath as $i => $pid):
                            if (!isset($posById[$pid])) continue;
                            $p = $posById[$pid];
                            $isLast = ($i === count($chainPath) - 1);
                        ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full <?= $isLast ? 'bg-amber-500 text-white shadow' : 'bg-white border border-amber-200 text-slate-700' ?>">
                                <?php if ($isLast): ?><i class="fa-solid fa-user mr-1.5 text-[10px]"></i><?php endif; ?>
                                <?= ocEsc($p['title']) ?>
                            </span>
                            <?php if (!$isLast): ?>
                                <i class="fa-solid fa-arrow-right text-amber-400 text-[10px]"></i>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <p class="mt-3 text-[11px] font-bold text-slate-500">
                        <i class="fa-solid fa-circle-info text-amber-400 mr-1"></i>
                        การ์ดของคุณมีกรอบสีทอง · ตำแหน่งสายบังคับบัญชามีกรอบประ
                    </p>
                </div>
            <?php endif; ?>

            <!-- Org Chart Body -->
            <div class="bg-white rounded-[2rem] p-5 sm:p-7 border border-slate-100 shadow-sm">
                <?php if (empty($positions) || empty($members)): ?>
                    <div class="text-center py-14 text-slate-400">
                        <i class="fa-solid fa-folder-open text-5xl mb-3 block text-slate-200"></i>
                        <p class="text-sm font-bold">ยังไม่มีข้อมูลผังองค์กร</p>
                        <p class="text-[11px] font-medium mt-1">ผู้ดูแลระบบกำลังจัดเตรียมข้อมูล</p>
                    </div>
                <?php else: ?>
                    <?= $mainHtml ?>
                <?php endif; ?>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/user_bottom_nav.php'; ?>
    </div>
</body>
</html>
