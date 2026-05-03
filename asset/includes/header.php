<?php
// asset/includes/header.php — portal-themed (green) header
@session_start();
$base_url   = explode('/asset', $_SERVER['SCRIPT_NAME'])[0] . '/asset/';
$page_title = $page_title ?? 'ระบบครุภัณฑ์สำนักงาน';
$user_role  = $_SESSION['role'] ?? 'guest';
$full_name  = $_SESSION['full_name'] ?? 'ผู้ใช้';

// Initials for avatar
$initials = mb_substr(trim(preg_replace('/\s+/u', ' ', $full_name)), 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($base_url) ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($page_title) ?> | ครุภัณฑ์สำนักงาน</title>

    <link rel="icon" href="<?= !empty($_SERVER['DOCUMENT_ROOT']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/favicon.ico') ? '../favicon.ico' : 'data:,' ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="assets/css/asset.css?v=2.0">
</head>
<body>

<header class="asset-header">
    <div class="max-w-[1280px] mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="asset-brand-icon group-hover:scale-105 transition">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <div class="leading-tight">
                    <div class="font-black text-slate-800 text-[15px] sm:text-[16px] tracking-tight">ครุภัณฑ์สำนักงาน</div>
                    <div class="text-[11px] text-slate-500 font-semibold">RSU Medical Clinic</div>
                </div>
            </a>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
            <a href="../portal/index.php" class="hidden md:inline-flex items-center gap-2 px-3 py-2 text-xs font-bold rounded-xl bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5] hover:bg-[#d6f0e2] transition" title="กลับ Portal">
                <i class="fa-solid fa-house"></i> Portal
            </a>
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

    <nav class="border-t border-[#f0faf4] bg-white/60 backdrop-blur">
        <div class="max-w-[1280px] mx-auto px-3 sm:px-6 py-2">
            <?php
            $current = $current_page ?? '';
            $tabs = [
                ['key' => 'index',      'href' => 'index.php',                  'icon' => 'fa-chart-pie',     'label' => 'ภาพรวม',     'roles' => ['admin','editor','employee']],
                ['key' => 'assets',     'href' => 'admin/manage_assets.php',    'icon' => 'fa-boxes-stacked', 'label' => 'ครุภัณฑ์',     'roles' => ['admin','editor','employee']],
                ['key' => 'reports',    'href' => 'admin/reports.php',          'icon' => 'fa-chart-line',    'label' => 'รายงาน',      'roles' => ['admin','editor']],
                ['key' => 'barcode',    'href' => 'admin/print_barcode.php',    'icon' => 'fa-barcode',       'label' => 'พิมพ์บาร์โค้ด','roles' => ['admin','editor']],
                ['key' => 'categories', 'href' => 'admin/manage_categories.php','icon' => 'fa-tags',          'label' => 'หมวดหมู่',     'roles' => ['admin','editor']],
                ['key' => 'locations',  'href' => 'admin/manage_locations.php', 'icon' => 'fa-location-dot',  'label' => 'จุดใช้งาน',    'roles' => ['admin','editor']],
            ];
            ?>
            <div class="asset-tabs">
                <?php foreach ($tabs as $t):
                    if (!in_array($user_role, $t['roles'], true)) continue;
                    $active = ($current === $t['key']);
                ?>
                    <a href="<?= htmlspecialchars($t['href']) ?>" class="asset-tab <?= $active ? 'active' : '' ?>">
                        <i class="fa-solid <?= $t['icon'] ?> text-[13px]"></i>
                        <span><?= htmlspecialchars($t['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>
</header>

<main class="max-w-[1280px] mx-auto px-4 sm:px-6 py-6">
