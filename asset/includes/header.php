<?php
// asset/includes/header.php
@session_start();
$base_url = explode('/asset', $_SERVER['SCRIPT_NAME'])[0] . '/asset/';
$page_title = $page_title ?? 'ระบบครุภัณฑ์สำนักงาน';
$user_role  = $_SESSION['role'] ?? 'guest';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/asset.css?v=1.0">
</head>
<body class="bg-slate-50 font-prompt">

<header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="index.php" class="flex items-center gap-2 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-md group-hover:scale-105 transition">
                    <i class="fas fa-boxes-stacked"></i>
                </div>
                <div>
                    <h1 class="text-base sm:text-lg font-extrabold text-slate-800 leading-tight">ครุภัณฑ์สำนักงาน</h1>
                    <p class="text-[11px] text-slate-500 leading-tight">RSU Medical Clinic</p>
                </div>
            </a>
        </div>
        <div class="flex items-center gap-3">
            <div class="hidden sm:block text-right">
                <div class="text-sm font-bold text-slate-700"><?= htmlspecialchars($_SESSION['full_name'] ?? 'ผู้ใช้') ?></div>
                <div class="text-[11px]">
                    <?php if ($user_role === 'admin'): ?>
                        <span class="text-amber-600 font-bold"><i class="fa-solid fa-crown"></i> Admin</span>
                    <?php elseif ($user_role === 'editor'): ?>
                        <span class="text-indigo-600 font-bold">Editor</span>
                    <?php else: ?>
                        <span class="text-emerald-600 font-bold">Staff</span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="../portal/index.php" class="hidden md:inline-flex items-center gap-2 px-3 py-2 text-sm font-bold rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 transition" title="กลับ Portal">
                <i class="fas fa-home"></i> Portal
            </a>
        </div>
    </div>

    <nav class="border-t border-slate-100 bg-slate-50">
        <div class="max-w-7xl mx-auto px-2 flex items-center gap-1 overflow-x-auto">
            <?php
            $current = $current_page ?? '';
            $tabs = [
                ['key' => 'index',      'href' => 'index.php',                'icon' => 'fa-tachometer-alt', 'label' => 'ภาพรวม',     'roles' => ['admin','editor','employee']],
                ['key' => 'assets',     'href' => 'admin/manage_assets.php',  'icon' => 'fa-boxes-stacked',  'label' => 'ครุภัณฑ์',     'roles' => ['admin','editor','employee']],
                ['key' => 'categories', 'href' => 'admin/manage_categories.php','icon'=> 'fa-tags',           'label' => 'หมวดหมู่',     'roles' => ['admin','editor']],
                ['key' => 'locations',  'href' => 'admin/manage_locations.php','icon' => 'fa-location-dot',   'label' => 'จุดใช้งาน',    'roles' => ['admin','editor']],
            ];
            foreach ($tabs as $t):
                if (!in_array($user_role, $t['roles'], true)) continue;
                $active = ($current === $t['key']);
            ?>
                <a href="<?= htmlspecialchars($t['href']) ?>"
                   class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap border-b-2 transition <?= $active ? 'border-indigo-600 text-indigo-700 bg-white' : 'border-transparent text-slate-600 hover:text-indigo-600 hover:bg-white/60' ?>">
                    <i class="fas <?= $t['icon'] ?>"></i>
                    <span><?= htmlspecialchars($t['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>

<main class="max-w-7xl mx-auto px-4 py-6">
