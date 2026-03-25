<?php
// includes/header.php
@session_start(); 
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <base href="<?php echo explode('/archive/e_Borrow', $_SERVER['SCRIPT_NAME'])[0] . '/archive/e_Borrow/'; ?>">
    <style>
        /* Smooth Page Transition */
        body { opacity: 1; transition: opacity 0.25s ease-out, transform 0.25s ease-out; }
        body.page-transitioning { opacity: 0; transform: translateY(10px); }
    </style>    <title><?php echo isset($page_title) ? $page_title : 'เธฃเธฐเธเธเธขเธทเธกเธเธทเธเธญเธธเธเธเธฃเธ“เน'; ?></title>
    
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
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/style.css?v=2.2">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="page-transitioning">
<script>
    window.addEventListener('DOMContentLoaded', () => document.body.classList.remove('page-transitioning'));
</script>
    <header class="header"> 
        <h1>E-Borrow - (เธฃเธฐเธเธ เธขเธทเธก-เธเธทเธ เธญเธธเธเธเธฃเธ“เน)</h1>
        
        <div class="user-info"> 
            
            <div class="user-greeting">
                เธชเธงเธฑเธชเธ”เธต, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                (<?php 
                    if ($_SESSION['role'] == 'admin') {
                        echo '<span style="color: var(--color-warning); font-weight: bold;">Admin <i class="fa-solid fa-crown"></i></span>';
                    } elseif ($_SESSION['role'] == 'employee') {
                        echo '<span style="color: #B7E5CD;">Employee</span>';
                    } else {
                        echo htmlspecialchars($_SESSION['role']);
                    }
                ?>)
            </div>

            <button type="button" class="theme-toggle-btn" id="theme-toggle-btn" title="เธชเธฅเธฑเธเนเธซเธกเธ”">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>
            
            <a href="admin/logout.php" class="btn btn-logout" title="เธญเธญเธเธเธฒเธเธฃเธฐเธเธ">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span class="logout-text">เธญเธญเธเธเธฒเธเธฃเธฐเธเธ</span>
            </a>
        </div>
    </header>

    <main class="content" style="margin-top: 80px;">