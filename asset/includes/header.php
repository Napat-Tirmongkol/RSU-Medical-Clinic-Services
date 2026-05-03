<?php
// asset/includes/header.php — sidebar layout matching portal
@session_start();
$base_url   = explode('/asset', $_SERVER['SCRIPT_NAME'])[0] . '/asset/';
$page_title = $page_title ?? 'ระบบครุภัณฑ์สำนักงาน';
$user_role  = $_SESSION['role'] ?? 'guest';
$full_name  = $_SESSION['full_name'] ?? 'ผู้ใช้';
$initials   = mb_substr(trim(preg_replace('/\s+/u', ' ', $full_name)), 0, 1, 'UTF-8');
$current    = $current_page ?? '';

$navGroups = [
    [
        'label' => 'OVERVIEW',
        'items' => [
            ['key' => 'index', 'href' => 'index.php', 'icon' => 'fa-chart-pie', 'color' => '#059669', 'label' => 'ภาพรวม', 'roles' => ['admin','editor','employee']],
        ],
    ],
    [
        'label' => 'ทะเบียน',
        'items' => [
            ['key' => 'assets',     'href' => 'admin/manage_assets.php',     'icon' => 'fa-boxes-stacked', 'color' => '#2e9e63', 'label' => 'ครุภัณฑ์',  'roles' => ['admin','editor','employee']],
            ['key' => 'categories', 'href' => 'admin/manage_categories.php', 'icon' => 'fa-tags',          'color' => '#d97706', 'label' => 'หมวดหมู่',  'roles' => ['admin','editor']],
            ['key' => 'locations',  'href' => 'admin/manage_locations.php',  'icon' => 'fa-location-dot',  'color' => '#dc2626', 'label' => 'จุดใช้งาน', 'roles' => ['admin','editor']],
        ],
    ],
    [
        'label' => 'ปฏิบัติการ',
        'items' => [
            ['key' => 'scan',       'href' => 'admin/scan.php',         'icon' => 'fa-qrcode',          'color' => '#2e9e63', 'label' => 'สแกน QR',    'roles' => ['admin','editor','employee']],
            ['key' => 'stock_take', 'href' => 'admin/stock_take.php',    'icon' => 'fa-clipboard-check', 'color' => '#0891b2', 'label' => 'ตรวจนับ',      'roles' => ['admin','editor','employee']],
            ['key' => 'barcode',    'href' => 'admin/print_barcode.php', 'icon' => 'fa-barcode',         'color' => '#0f172a', 'label' => 'พิมพ์บาร์โค้ด','roles' => ['admin','editor']],
        ],
    ],
    [
        'label' => 'รายงาน',
        'items' => [
            ['key' => 'reports', 'href' => 'admin/reports.php', 'icon' => 'fa-chart-line', 'color' => '#7c3aed', 'label' => 'รายงาน', 'roles' => ['admin','editor']],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($base_url) ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($page_title) ?> | ครุภัณฑ์สำนักงาน</title>

    <link rel="icon" href="data:,">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#2e9e63">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ครุภัณฑ์">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="assets/css/asset.css?v=2.1">
    <script>
        (function () {
            try {
                if (localStorage.getItem('asset-sidebar-collapsed') === '1') {
                    document.documentElement.classList.add('sidebar-collapsed-init');
                }
            } catch (e) {}
        })();
    </script>
</head>
<body class="font-sans text-gray-800 bg-[#f4f7f5]" style="height:100vh;overflow:hidden;display:flex;flex-direction:row">

<!-- ════════════ Sidebar ════════════ -->
<nav id="portal-sidebar" class="<?= ($_COOKIE['asset_sidebar_collapsed'] ?? '') === '1' ? 'collapsed' : '' ?>">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f0faf4;min-height:60px">
        <a href="index.php" class="flex items-center gap-2" id="psb-brand-text" style="text-decoration:none">
            <div class="brand-icon" style="width:30px;height:30px;font-size:12px;border-radius:10px;">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <div>
                <div class="font-black text-slate-800 text-[15px] leading-tight tracking-tight">ครุภัณฑ์</div>
                <div class="text-[10px] text-slate-500 leading-tight">RSU Medical Clinic</div>
            </div>
        </a>
        <button onclick="assetToggleSidebar()" id="asset-sb-toggle" title="ย่อ/ขยาย"
            style="width:28px;height:28px;border-radius:8px;border:none;cursor:pointer;background:#f0faf4;color:#2e9e63;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s">
            <i id="asset-sb-icon" class="fa-solid fa-chevron-left" style="font-size:11px;transition:transform .3s"></i>
        </button>
    </div>

    <div style="padding:10px;flex:1;overflow-y:auto;display:flex;flex-direction:column;">
        <?php foreach ($navGroups as $gIdx => $group):
            // กรองเฉพาะ items ที่ user role เห็นได้
            $visibleItems = array_filter($group['items'], fn($it) => in_array($user_role, $it['roles'], true));
            if (empty($visibleItems)) continue;
        ?>
            <div class="psb-section-label" <?= $gIdx === 0 ? 'style="margin-top:4px"' : '' ?>>
                <?= htmlspecialchars($group['label']) ?>
            </div>
            <?php foreach ($visibleItems as $item):
                $active = ($current === $item['key']);
            ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="psb-item <?= $active ? 'psb-active' : '' ?>" style="text-decoration:none">
                    <div class="psb-icon"><i class="fa-solid <?= $item['icon'] ?>" style="color: <?= $item['color'] ?>"></i></div>
                    <span class="psb-label" style="color: <?= $item['color'] ?>; font-weight:900"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div style="flex:1"></div>

        <div class="psb-section-label">ทั่วไป</div>
        <a href="../portal/index.php" class="psb-item" style="text-decoration:none" title="กลับ Portal">
            <div class="psb-icon"><i class="fa-solid fa-house" style="color:#475569"></i></div>
            <span class="psb-label" style="color:#475569;font-weight:900">กลับ Portal</span>
        </a>
    </div>
</nav>

<!-- ════════════ Mobile top bar ════════════ -->
<div id="asset-mobile-bar"
     style="display:none;position:fixed;top:0;left:0;right:0;z-index:50;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border-bottom:1.5px solid rgba(199,232,213,.6);padding:10px 14px;align-items:center;justify-content:space-between;gap:10px">
    <button onclick="assetMobileMenu()" style="width:38px;height:38px;border-radius:10px;border:1.5px solid #c7e8d5;background:#f0faf4;color:#2e9e63">
        <i class="fas fa-bars"></i>
    </button>
    <div class="flex items-center gap-2">
        <div class="brand-icon" style="width:30px;height:30px;font-size:12px"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="font-black text-slate-800 text-[14px]">ครุภัณฑ์</div>
    </div>
    <div class="asset-user-avatar" style="width:32px;height:32px"><?= htmlspecialchars($initials ?: '?') ?></div>
</div>

<!-- ════════════ Main shell ════════════ -->
<div id="app-shell" style="flex:1;min-width:0;background:#f4f7f5;height:100vh;overflow-y:auto;display:flex;flex-direction:column;">
    <header class="asset-header" style="position:sticky;top:0;z-index:30">
        <div class="px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
            <div>
                <div class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">RSU Medical Clinic · ครุภัณฑ์สำนักงาน</div>
                <h1 class="text-base sm:text-lg font-extrabold text-slate-800 leading-tight"><?= htmlspecialchars($page_title) ?></h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="asset-user-pill">
                    <div class="asset-user-avatar"><?= htmlspecialchars($initials ?: '?') ?></div>
                    <div class="hidden sm:block leading-tight">
                        <div class="text-[12.5px] font-bold text-slate-800"><?= htmlspecialchars($full_name) ?></div>
                        <div class="text-[10px] font-bold uppercase tracking-wider">
                            <?php if ($user_role === 'admin'): ?>
                                <span class="text-amber-600"><i class="fa-solid fa-crown"></i> Admin</span>
                            <?php elseif ($user_role === 'editor'): ?>
                                <span class="text-[#2e9e63]">Editor</span>
                            <?php else: ?>
                                <span class="text-[#2e9e63]">Staff</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="px-4 sm:px-6 py-6 max-w-[1400px] w-full mx-auto">
