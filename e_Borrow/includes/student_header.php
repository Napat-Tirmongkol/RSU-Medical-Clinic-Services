<?php
// e_Borrow/includes/student_header.php — Tailwind shell aligned with user/hub
@session_start();
$page_title  = $page_title ?? 'ระบบยืม-คืนอุปกรณ์';
$active_page = $active_page ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> · MedLoan</title>

    <link rel="icon" type="image/png" href="assets/img/logo.png" sizes="any">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background-color: #F8FAFF; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255,255,255,.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        body { opacity: 1; transition: opacity .25s ease-out, transform .25s ease-out; }
        body.page-transitioning { opacity: 0; transform: translateY(10px); }
    </style>
</head>
<body class="page-transitioning text-slate-900 pb-32">
<script>window.addEventListener('DOMContentLoaded', () => document.body.classList.remove('page-transitioning'));</script>

<div class="max-w-md mx-auto relative min-h-screen">

    <!-- ── Header ── -->
    <header class="glass-header sticky top-0 z-[60] px-6 py-5 flex items-center justify-between border-b border-slate-100 shadow-sm shadow-slate-50">
        <button onclick="window.location.href='../user/hub.php'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition-all">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div class="flex flex-col items-center">
            <p class="text-[9px] font-black uppercase tracking-[0.25em] text-slate-400">MedLoan</p>
            <h1 class="text-base font-black text-slate-900 tracking-tight"><?= htmlspecialchars($page_title) ?></h1>
        </div>
        <a href="../user/profile.php" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition-all">
            <i class="fa-solid fa-user"></i>
        </a>
    </header>

    <!-- ── Sub-tabs ── -->
    <nav class="px-6 pt-5">
        <div class="bg-white rounded-2xl p-1.5 border border-slate-100 shadow-sm flex gap-1">
            <?php
            $tabs = [
                'home'    => ['index.php',   'fa-hand-holding-medical', 'ยืมอยู่'],
                'borrow'  => ['borrow.php',  'fa-boxes-stacked',        'ยืมอุปกรณ์'],
                'history' => ['history.php', 'fa-clock-rotate-left',    'ประวัติ'],
            ];
            foreach ($tabs as $key => $t):
                $isActive = $active_page === $key;
            ?>
            <a href="<?= $t[0] ?>" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-[11px] font-black transition-all <?= $isActive ? 'bg-[#2e9e63] text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                <i class="fa-solid <?= $t[1] ?> text-xs"></i>
                <span><?= $t[2] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <main class="px-6 pt-6">
