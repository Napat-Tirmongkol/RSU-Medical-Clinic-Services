<?php
// includes/student_header.php
@session_start(); 
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <base href="<?php echo explode('/e_Borrow', $_SERVER['SCRIPT_NAME'])[0] . '/e_Borrow/'; ?>">

    <title><?php echo isset($page_title) ? $page_title : 'ระบบยืม-คืนอุปกรณ์'; ?></title>
    
    <script>
        (function() {
            try {
                const theme = localStorage.getItem('theme');
                if (theme === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                }
            } catch (e) { 
                console.error('Theme init error:', e); 
            }
        })();
    </script>

    <link rel="icon" type="image/png" href="assets/img/logo.png" sizes="any">
    
    <link rel="stylesheet" href="assets/css/style.css?v=2.2">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
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
    </style>
</head>
<body class="page-transitioning">

<script>
    window.addEventListener('DOMContentLoaded', () => {
        document.body.classList.remove('page-transitioning');
    });
</script>

    <header class="header">
        <div style="display:flex;flex-direction:column;gap:4px;">
            <?php if (!empty($_SESSION['evax_student_id'])): ?>
            <a href="../user/hub.php" style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:rgba(255,255,255,.75);text-decoration:none;background:rgba(255,255,255,.15);padding:3px 10px;border-radius:20px;width:fit-content;margin-bottom:2px;">
                <i class="fas fa-arrow-left" style="font-size:10px;"></i> RSU Medical Hub
            </a>
            <?php endif; ?>
            <h1 style="margin:0;">MedLoan (สำหรับนักศึกษา)</h1>
        </div>

        <div class="user-info">

            <button type="button" class="theme-toggle-btn" id="theme-toggle-btn" title="ปรับธีม">
                <i class="fas fa-moon"></i> <i class="fas fa-sun"></i>
            </button>
            <?php echo htmlspecialchars($_SESSION['student_full_name'] ?? 'ผู้ใช้'); ?>

            <a href="logout.php" class="btn btn-logout">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> ออกจากระบบ
            </a>
        </div>
    </header>

    <main class="content">