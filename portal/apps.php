<?php
/**
 * portal/apps.php
 * Application URLs
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$adminRole = $_SESSION['admin_role'] ?? 'admin';

// ── 1. DB Initialization (Auto Setup) ───────────────────────── //
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `sys_app_links` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `category` varchar(50) NOT NULL DEFAULT 'system',
        `title` varchar(255) NOT NULL,
        `description` varchar(500) DEFAULT NULL,
        `url` varchar(500) NOT NULL,
        `icon` varchar(100) DEFAULT 'fa-link',
        `color_theme` varchar(50) DEFAULT 'blue',
        `sort_order` int(11) DEFAULT 0,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // Seed data if empty
    if($pdo->query("SELECT COUNT(*) FROM sys_app_links")->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO sys_app_links (category, title, description, url, icon, color_theme, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute(['system', 'หน้าเว็บ E-Campaign เบื้องหน้า', 'ระบบลงทะเบียนกิจกรรมและจองคิว', '/e-campaignv2/', 'fa-hospital-user', 'emerald', 1]);
        $stmt->execute(['system', 'ระบบ E-Borrow', 'ค้นหาและทำรายการยืม-คืน อุปกรณ์', '/e-campaignv2/e_Borrow/', 'fa-box-open', 'blue', 2]);
        $stmt->execute(['system', 'Admin Login Portal', 'ทางเข้าการจัดการสำหรับเจ้าหน้าที่', '/e-campaignv2/login.php', 'fa-shield-halved', 'amber', 3]);
        
        $stmt->execute(['liff', 'LINE LIFF - ลงทะเบียนผู้ป่วยใหม่', 'ระบบฝังใน LINE OA สำหรับผู้ป่วยใหม่', 'https://liff.line.me/1234567890-abcdef', 'fa-line', 'line', 4]);
        $stmt->execute(['liff', 'LINE LIFF - แบบฟอร์มประเมิน', 'หน้าตอบแบบสอบถามและติดตามผล', 'https://liff.line.me/1234567890-ghijkl', 'fa-line', 'line', 5]);
    }
} catch (PDOException $e) { /* ignore silently */ }

// ── 2. Data Fetch ───────────────────────── //
$links = [];
try {
    // Resolve dynamic path base for internal links
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
    
    $stmt = $pdo->query("SELECT * FROM sys_app_links WHERE is_active = 1 ORDER BY category DESC, sort_order ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($rows as $row) {
        // If it starts with slash, prepend base_url
        if(strpos($row['url'], '/') === 0) {
            $row['resolved_url'] = rtrim($base_url, '/') . $row['url'];
        } else {
            $row['resolved_url'] = $row['url'];
        }
        $links[$row['category']][] = $row;
    }
} catch (PDOException $e) { }

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Directory - Management Smart Portal</title>
    <!-- UI Framework & Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@100;300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../e_Borrow/assets/img/logo.png">
    
    <style>
        .theme-blue { --tc: #3b82f6; --bgc: rgba(59, 130, 246, 0.15); }
        .theme-emerald { --tc: #10b981; --bgc: rgba(16, 185, 129, 0.15); }
        .theme-amber { --tc: #f59e0b; --bgc: rgba(245, 158, 11, 0.15); }
        .theme-line { --tc: #06C755; --bgc: rgba(6, 199, 85, 0.15); } /* LINE Official Green */
        .theme-default { --tc: #94a3b8; --bgc: rgba(148, 163, 184, 0.15); }
    </style>
</head>
<body class="font-sans text-gray-800" style="min-height:100vh">

    <!-- Ambient background dots -->
    <div class="amb-dot" style="width:320px;height:320px;background:rgba(46,158,99,.1);top:5%;left:10%;--dur:16s;--delay:0s;--dx:40px;--dy:-30px"></div>
    <div class="amb-dot" style="width:240px;height:240px;background:rgba(77,201,138,.07);top:60%;right:8%;--dur:20s;--delay:-5s;--dx:-35px;--dy:25px"></div>

    <!-- HEADER -->
    <header class="portal-header au">
        <div class="max-w-[1280px] mx-auto px-6 py-3 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="index.php" class="brand-icon hover:scale-110 transition-transform"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <div class="font-black text-gray-900 text-[17px] leading-none tracking-tight">Application Directory</div>
                    <div class="text-[10px] font-bold tracking-[.15em] uppercase opacity-70 mt-0.5" style="color:#2e9e63">Copy & Share Center</div>
                </div>
            </div>
            <!-- Right: user -->
            <div class="flex items-center gap-3">
                <div class="user-pill">
                    <div class="user-avatar"><i class="fa-solid fa-user-shield text-[11px]"></i></div>
                    <div class="hidden sm:block">
                        <div class="text-[9px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">Admin</div>
                        <div class="text-xs font-black text-gray-900 leading-none">
                            <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- PAGE BODY -->
    <div class="max-w-[900px] mx-auto px-5 md:px-8 py-12 space-y-12 au d1 relative z-10">
        
        <?php foreach(['system' => 'Main System Links (หน้าเว็บหลัก)', 'liff' => 'LINE LIFF Applications (แอปพลิเคชันผ่านไลน์)'] as $catKey => $catTitle): ?>
        <?php if(!empty($links[$catKey])): ?>
        <div>
            <div class="flex items-center gap-3 mb-6">
                <div class="w-1.5 h-6 rounded-full <?= $catKey === 'liff' ? 'bg-[#06C755]' : 'bg-[#2e9e63]' ?>"></div>
                <h2 class="font-black text-xl text-gray-900 uppercase tracking-wide opacity-90"><?= $catTitle ?></h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach($links[$catKey] as $app): 
                    $theme = "theme-" . ($app['color_theme'] ?: 'default');
                ?>
                <div class="bg-white/70 backdrop-blur-md rounded-[1.25rem] p-5 shadow-sm border border-gray-100 hover:border-green-300 hover:shadow-lg transition-all group relative overflow-hidden flex flex-col justify-between h-full">
                    
                    <!-- Decorative Gradient -->
                    <div class="absolute -right-12 -top-12 w-32 h-32 rounded-full opacity-20 blur-2xl pointer-events-none <?= $catKey === 'liff' ? 'bg-[#06C755]' : 'bg-emerald-400' ?>"></div>
                    
                    <div class="mb-4 relative z-10">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="<?= $theme ?> w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 shadow-inner" style="background-color: var(--bgc); color: var(--tc);">
                                <i class="<?= strpos($app['icon'], 'fa-') === 0 && strpos($app['icon'], ' ') === false ? 'fa-brands ' . $app['icon'] : 'fa-solid ' . $app['icon'] ?> text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-gray-900 font-bold text-[15px] leading-tight mb-1"><?= htmlspecialchars($app['title']) ?></h3>
                                <p class="text-gray-500 text-[11px] leading-snug"><?= htmlspecialchars($app['description']) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="relative z-10 mt-auto">
                        <div class="flex items-center gap-2 bg-gray-50 rounded-xl p-1.5 border border-gray-100 focus-within:border-green-300 focus-within:ring-2 focus-within:ring-green-100 transition-all">
                            <input type="text" value="<?= htmlspecialchars($app['resolved_url']) ?>" readonly 
                                class="bg-transparent border-none text-[12px] font-mono text-gray-700 outline-none cursor-text w-full truncate pl-2 py-1 select-all" title="<?= htmlspecialchars($app['resolved_url']) ?>">
                            <button onclick="copyAppUrl(this, '<?= htmlspecialchars($app['resolved_url']) ?>')" title="Copy URL"
                                class="h-8 px-4 flex items-center justify-center gap-1.5 bg-white text-gray-600 hover:bg-[#2e9e63] hover:text-white hover:border-[#2e9e63] border border-gray-200 font-bold rounded-lg transition-all shadow-sm shrink-0">
                                <i class="fa-regular fa-copy text-xs"></i> <span class="text-[11px]">คัดลอก</span>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>

    </div>

    <!-- FOOTER -->
    <footer class="pt-10 pb-4 text-center">
        <div class="flex items-center justify-center gap-2 opacity-25">
            <i class="fa-solid fa-shield-halved" style="color:#2e9e63"></i>
            <span class="text-[10px] font-black uppercase tracking-[.4em]">Central Command v3.0 · RSU Medical Clinic</span>
        </div>
    </footer>

    <script>
        window.copyAppUrl = function(btn, txt) {
            navigator.clipboard.writeText(txt).then(() => {
                const icon = btn.querySelector('i');
                const textSpan = btn.querySelector('span');
                const origClass = icon.className;
                const origText = textSpan.innerText;
                
                icon.className = 'fa-solid fa-check text-xs';
                textSpan.innerText = 'สำเร็จ!';
                btn.classList.add('!bg-green-500', '!text-white', '!border-green-500');
                
                setTimeout(() => {
                    icon.className = origClass;
                    textSpan.innerText = origText;
                    btn.classList.remove('!bg-green-500', '!text-white', '!border-green-500');
                }, 1500);
            });
        };
    </script>
</body>
</html>
