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
        /* ── Page transition (cross-document View Transitions API) ── */
        @view-transition { navigation: auto; }

        ::view-transition-old(root) {
            animation: ebPageOut 200ms cubic-bezier(.4,0,.2,1) both;
        }
        ::view-transition-new(root) {
            animation: ebPageIn 360ms cubic-bezier(.16,1,.3,1) both;
        }
        @keyframes ebPageOut {
            to { opacity: 0; transform: translateY(-6px); }
        }
        @keyframes ebPageIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Fallback for browsers without view transitions (Firefox, older Safari) */
        @supports not (view-transition-name: none) {
            body { animation: ebPageIn 380ms cubic-bezier(.16,1,.3,1) both; }
        }

        /* Respect reduced motion */
        @media (prefers-reduced-motion: reduce) {
            ::view-transition-old(root),
            ::view-transition-new(root) { animation: none !important; }
            body { animation: none !important; }
        }

        /* Theme toggle styling now lives in assets/css/eb-skin.css */
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

        // Suppress harmless AbortError from skipped View Transitions
        // (เกิดเมื่อนำทางซ้ำเร็วๆ / ไป download / กด back ระหว่าง transition)
        window.addEventListener('unhandledrejection', function(e) {
            var r = e.reason;
            if (r && r.name === 'AbortError' && /transition/i.test(r.message || '')) {
                e.preventDefault();
            }
        });
    </script>

    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <link rel="icon" href="data:,">
    <link rel="icon" type="image/png" href="assets/img/logo.png" sizes="any">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= defined('APP_VERSION') ? APP_VERSION : '1' ?>">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=2.5">
    <link rel="stylesheet" href="assets/css/eb-skin.css?v=<?= @filemtime(__DIR__ . '/../assets/css/eb-skin.css') ?: '1' ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script defer src="../assets/js/rsu-fx.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/rsu-fx.js') ?: '1' ?>"></script>
</head>

<body>

    <?php
    $user_role = $_SESSION['role'] ?? 'employee';
    $eb_user_name = $_SESSION['full_name'] ?? 'ผู้ใช้';
    $eb_user_initial = mb_substr(trim($eb_user_name), 0, 1, 'UTF-8') ?: 'U';
    ?>
    <header class="header">
        <div class="eb-brand">
            <div class="eb-brand-icon" title="E-Borrow"><i class="fa-solid fa-cubes-stacked"></i></div>
            <div class="eb-brand-text">
                E-Borrow
                <small>RSU Medical · ยืม-คืนอุปกรณ์</small>
            </div>
            <?php if ($user_role !== 'employee'): ?>
            <a href="../portal/index.php" class="eb-portal-link" title="กลับหน้าหลัก Portal">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Portal</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="user-info">
            <div class="eb-user-pill" title="<?= htmlspecialchars($eb_user_name) ?>">
                <div class="eb-user-avatar"><?= htmlspecialchars($eb_user_initial) ?></div>
                <div class="eb-user-text">
                    <?= htmlspecialchars($eb_user_name) ?>
                    <?php if ($user_role === 'admin'): ?>
                        <span class="eb-user-role eb-user-role--admin"><i class="fa-solid fa-crown"></i> Admin</span>
                    <?php elseif ($user_role === 'employee'): ?>
                        <span class="eb-user-role eb-user-role--employee">Staff</span>
                    <?php else: ?>
                        <span class="eb-user-role"><?= htmlspecialchars($user_role) ?></span>
                    <?php endif; ?>
                </div>
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

    <main class="content" style="margin-top: 64px;">