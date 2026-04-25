<?php
// includes/header.php
@session_start();

// ดึง Base Path ของ e_Borrow มาใช้เพื่อความแม่นยำของ Assets
$base_url = explode('/e_Borrow', $_SERVER['SCRIPT_NAME'])[0] . '/e_Borrow/';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo $base_url; ?>">

    <style>
        /* Smooth Page Transition */
        body {
            opacity: 1;
            transition: opacity 0.25s ease-out, transform 0.25s ease-out;
        }

        body.page-transitioning {
            opacity: 0;
            transform: translateY(10px);
        }

        /* Theme Toggle Button visibility fix */
        .theme-toggle-btn {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.08) !important;
            background: #f1f5f9 !important;
            color: #475569 !important;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        body.dark-mode .theme-toggle-btn {
            background: rgba(30, 41, 59, 0.5) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #f59e0b !important;
        }

        .theme-toggle-btn:hover {
            transform: scale(1.05);
            border-color: #3b82f6 !important;
        }
    </style>

    <title><?php echo isset($page_title) ? $page_title : 'ระบบยืม-คืนอุปกรณ์'; ?></title>

    <script>
        (function () {
            try {
                const theme = localStorage.getItem('theme');
                if (theme === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                }
            } catch (e) { console.error('Theme init error:', e); }
        })();
    </script>

    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <link rel="icon" href="data:,">
    <link rel="icon" type="image/png" href="assets/img/logo.png" sizes="any">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=2.5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>

<body class="page-transitioning">

    <script>
        window.addEventListener('DOMContentLoaded', () => document.body.classList.remove('page-transitioning'));
    </script>

    <?php $user_role = $_SESSION['role'] ?? 'employee'; ?>
    <header class="header">
        <div class="flex items-center gap-3">
            <h1 class="hidden xs:block">E-Borrow</h1>
            <?php if ($user_role !== 'employee'): ?>
            <a href="../portal/index.php"
                class="flex items-center gap-2 p-2 sm:px-4 sm:py-2 text-sm font-bold transition-all bg-white border border-gray-100 rounded-2xl text-slate-700 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-0.5 active:scale-95 group dark:bg-slate-800 dark:border-slate-700 dark:text-slate-300 dark:hover:text-white"
                title="กลับหน้าหลัก Portal">
                <div class="flex items-center justify-center w-7 h-7 sm:w-8 sm:h-8 transition-colors bg-indigo-50 rounded-xl group-hover:bg-indigo-100 dark:bg-indigo-900/30 dark:group-hover:bg-indigo-900/50">
                    <i class="fas fa-home text-indigo-500 text-[13px] sm:text-[14px] dark:text-indigo-400"></i>
                </div>
                <span class="hidden md:inline">หน้าหลัก Portal</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="user-info">
            <div class="user-greeting">
                <span>สวัสดี,</span> 
                <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'ผู้ใช้'); ?></strong>
                <span class="hidden sm:inline">
                (<?php
                if ($user_role == 'admin') {
                    echo '<span style="color: #ffc107; font-weight: bold;">Admin <i class="fa-solid fa-crown"></i></span>';
                } elseif ($user_role == 'employee') {
                    echo '<span style="color: #48c774;">Staff</span>';
                } else {
                    echo htmlspecialchars($user_role);
                }
                ?>)
                </span>
            </div>

            <button type="button" class="theme-toggle-btn" id="theme-toggle-btn" title="สลับโหมด มืด/สว่าง">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>

            <a href="admin/logout.php" class="btn btn-logout" title="ออกจากระบบ">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span class="logout-text">ออก</span>
            </a>
        </div>
    </header>

    <main class="content" style="margin-top: 80px;">