<?php
/**
 * portal/index.php (v3.0 Dynamic & Scalable Edition)
 * Central Hub Dashboard สำหรับการจัดการระบบที่รองรับการขยายโปรเจกต์ในอนาคต
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // ตรวจสอบความปลอดภัย

$pdo = db();
$adminRole = $_SESSION['admin_role'] ?? 'admin'; // ตัวแปรบทบาทสำหรับเช็คสิทธิ์ (Mock role)

/**
 * 📊 (1) LIVE DATA & ROBUST STATS
 * ดึงสถิจริง พร้อมระบบป้องกันถ้าตารางในอนาคตยังไม่พร้อม
 */
$kpis = [
    'users'   => 0,
    'camps'   => 0,
    'borrows' => 0,
    'logs'    => 0
];

try {
    $kpis['users'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();
    $kpis['camps'] = (int)$pdo->query("SELECT COUNT(*) FROM camp_list WHERE status = 'active'")->fetchColumn();
    
    // เช็คตารางยืมอุปกรณ์ (โมดูลเสริม)
    if ($pdo->query("SHOW TABLES LIKE 'borrow_records'")->rowCount() > 0) {
        $kpis['borrows'] = (int)$pdo->query("SELECT COUNT(*) FROM borrow_records WHERE approval_status = 'pending'")->fetchColumn();
    }
    
    // เช็คตารางกิจกรรม (โมดูลสถิติ)
    if ($pdo->query("SHOW TABLES LIKE 'activity_logs'")->rowCount() > 0) {
        $kpis['logs'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Portal Stats Fetch Error: " . $e->getMessage());
}

/**
 * 🧩 (2) PROJECT CATALOG (SCALABLE STRUCTURE)
 * โครงสร้างอาเรย์สำหรับวนลูปโปรเจกต์ รองรับการเพิ่มโมดูลในอนาคตได้ทันที
 */
$projects = [
    [
        'id'            => 'identity_governance',
        'title'         => 'Identity & Governance',
        'description'   => 'ศูนย์กลางจัดการข้อมูลผู้ใช้งานและควบคุมสิทธิ์การเข้าถึงระบบสำหรับเจ้าหน้าที่และแอดมินระดับสูง',
        'icon'          => 'fa-id-card-clip',
        'bg_color'      => 'bg-amber-50',
        'icon_color'    => 'text-amber-500',
        'border_color'  => 'border-amber-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Central DB', 'Security Hub' ],
        'actions'       => [
            ['label' => 'Search Users', 'url' => 'users.php?layout=none', 'primary' => false],
            ['label' => 'Manage Admins', 'url' => 'manage_admins.php?layout=none', 'primary' => true],
        ]
    ],
    [
        'id'            => 'e_campaign',
        'title'         => 'e-Campaign',
        'description'   => 'ระบบจัดการแคมเปญ งานอบรม งานสแกนและการลงทะเบียนเข้าร่วมกิจกรรมแบบ Real-time',
        'icon'          => 'fa-bullhorn',
        'bg_color'      => 'bg-blue-50',
        'icon_color'    => 'text-blue-600',
        'border_color'  => 'border-blue-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Campaigns', 'Activity' ],
        'actions'       => [
            ['label' => 'Launch Campaign Manager', 'url' => '../admin/index.php', 'primary' => true],
        ]
    ],
    [
        'id'            => 'e_borrow',
        'title'         => 'e-Borrow & Inventory',
        'description'   => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์และเวชภัณฑ์ (Archive Support) จัดการสต็อกและพัสดุกลาง',
        'icon'          => 'fa-toolbox',
        'bg_color'      => 'bg-slate-100',
        'icon_color'    => 'text-slate-700',
        'border_color'  => 'border-slate-200',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Inventory', 'Asset Tracking' ],
        'actions'       => [
            ['label' => 'Open System', 'url' => '../archive/e_Borrow/admin/index.php', 'primary' => true],
        ]
    ],
    [
        'id'            => 'system_logs',
        'title'         => 'System Logs',
        'description'   => 'ติดตาม Error Log และ Activity Log ของระบบแบบ Real-time เพื่อตรวจสอบและแก้ไขปัญหาได้ทันที',
        'icon'          => 'fa-bug',
        'bg_color'      => 'bg-red-50',
        'icon_color'    => 'text-red-500',
        'border_color'  => 'border-red-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Monitoring', 'Debug' ],
        'actions'       => [
            ['label' => 'Error Logs',    'url' => '../admin/error_logs.php',    'primary' => true],
            ['label' => 'Activity Logs', 'url' => '../admin/activity_logs.php', 'primary' => false],
        ]
    ],
    /**
     * ตัวอย่างการเพิ่มโปรเจกต์ในอนาคต:
     * เพียงแค่ก๊อปปี้บล็อกนี้แล้วเปลี่ยน URL/Icon ระบบจะวาดหน้า Layout ให้เองทันที
     */
    [
        'id'            => 'future_app',
        'title'         => 'Upcoming Project...',
        'description'   => 'ระบบใหม่ที่กำลังอยู่ในระหว่างการพัฒนา เพื่อเสริมสร้างศักยภาพการจัดการข้อมูลในอนาคต',
        'icon'          => 'fa-plus-circle',
        'bg_color'      => 'bg-gray-50',
        'icon_color'    => 'text-gray-300',
        'border_color'  => 'border-gray-100',
        'allowed_roles' => ['superadmin'],
        'badges'        => [ 'Dev Stage' ],
        'actions'       => [
            ['label' => 'No actions yet', 'url' => '#', 'primary' => false],
        ]
    ]
];

/**
 * 🕒 (3) RECENT ACTIVITY FETCH
 * ดึงความเคลื่อนไหวล่าสุดจาก sys_activity_logs มาแสดงที่ Dashboard
 */
$recentActivity = [];
try {
    $sql = "SELECT l.action, l.description, l.timestamp as created_at, a.full_name as admin_name 
            FROM sys_activity_logs l
            LEFT JOIN sys_admins a ON l.user_id = a.id
            ORDER BY l.timestamp DESC 
            LIMIT 5";
    $recentActivity = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* silent */ }

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Smart Portal - Central Intelligence HUB</title>
    
    <!-- UI Framework & Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@100;300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Outfit', 'Prompt', 'sans-serif'], prompt: ['Prompt', 'sans-serif'] },
                    colors: { primary: '#0052CC', secondary: '#0747A6', accent: '#FFAB00', surface: '#F8FAFC' }
                }
            }
        }
    </script>
    
    <style>
        body { background: #fdfdfd; min-height: 100vh; overflow-x: hidden; }
        .bg-mesh {
            background: 
                radial-gradient(at 0% 0%, rgba(0, 82, 204, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(255, 171, 0, 0.05) 0px, transparent 50%);
        }
        .glass { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.4); }
        .card-shift:hover { transform: translateY(-6px); box-shadow: 0 20px 40px -20px rgba(0, 82, 204, 0.15); border-color: rgba(0, 82, 204, 0.2); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .animate-enter { animation: fadeIn 0.5s ease-out forwards; }
    </style>
</head>
<body class="bg-mesh font-sans text-gray-800 p-4 md:p-8">

    <div class="max-w-[1440px] mx-auto space-y-10">
        
        <!-- HEADER & COMMANDER INFO -->
        <header class="flex flex-col md:flex-row justify-between items-center gap-6 animate-enter">
            <div class="flex items-center gap-6">
                <div class="w-16 h-16 bg-gradient-to-br from-primary to-blue-800 rounded-[24px] flex items-center justify-center text-white text-3xl shadow-xl shadow-blue-200">
                    <i class="fa-solid fa-square-rss"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-[900] text-gray-900 tracking-tight leading-none uppercase">Central HUB</h1>
                    <p class="text-xs text-primary font-black tracking-[0.2em] uppercase mt-2 opacity-60">RSU Healthcare Management Portal</p>
                </div>
            </div>

            <div class="flex items-center gap-4 glass px-6 py-3 rounded-full shadow-sm">
                <div class="w-10 h-10 bg-blue-100 text-primary rounded-full flex items-center justify-center font-bold">
                    <i class="fa-solid fa-user-shield text-sm"></i>
                </div>
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-1">Administrative Node</p>
                    <p class="text-xs font-black text-gray-900 leading-none"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?></p>
                </div>
                <div class="h-6 w-px bg-gray-200 mx-2"></div>
                <a href="../admin/logout.php" class="text-gray-300 hover:text-red-500 transition-all"><i class="fa-solid fa-power-off"></i></a>
            </div>
        </header>

        <!-- DASHBOARD OVERVIEW (Top Stats KPI) -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 animate-enter" style="animation-delay: 0.1s;">
            <div class="glass p-7 rounded-[40px] flex flex-col justify-between h-36 relative overflow-hidden group">
                <div class="z-10 bg-amber-500/10 text-amber-600 w-10 h-10 rounded-2xl flex items-center justify-center text-lg mb-2"><i class="fa-solid fa-users"></i></div>
                <div class="z-10">
                    <h5 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">Total Members</h5>
                    <p class="text-3xl font-black"><?= number_format($kpis['users']) ?></p>
                </div>
                <div class="absolute -bottom-4 -right-4 text-7xl text-gray-50 opacity-20 group-hover:scale-110 transition-all"><i class="fa-solid fa-id-card"></i></div>
            </div>
            <div class="glass p-7 rounded-[40px] flex flex-col justify-between h-36 relative overflow-hidden group">
                <div class="z-10 bg-blue-500/10 text-blue-600 w-10 h-10 rounded-2xl flex items-center justify-center text-lg mb-2"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="z-10">
                    <h5 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">Running Camps</h5>
                    <p class="text-3xl font-black"><?= $kpis['camps'] ?></p>
                </div>
                <div class="absolute -bottom-4 -right-4 text-7xl text-gray-50 opacity-20"><i class="fa-solid fa-calendar-check"></i></div>
            </div>
            <div class="glass p-7 rounded-[40px] flex flex-col justify-between h-36 relative overflow-hidden group">
                <div class="z-10 bg-red-500/10 text-red-600 w-10 h-10 rounded-2xl flex items-center justify-center text-lg mb-2"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="z-10">
                    <h5 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">Pending Borrows</h5>
                    <div class="flex items-center gap-3">
                        <p class="text-3xl font-black"><?= $kpis['borrows'] ?></p>
                        <?php if($kpis['borrows'] > 0): ?>
                            <span class="px-2 py-1 bg-red-500 text-white text-[9px] font-black rounded-lg animate-pulse">URGENT</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="glass p-7 rounded-[40px] flex flex-col justify-between h-36 relative overflow-hidden group border-2 border-primary/5">
                <div class="z-10 bg-primary/10 text-primary w-10 h-10 rounded-2xl flex items-center justify-center text-lg mb-2"><i class="fa-solid fa-bolt"></i></div>
                <div class="z-10">
                    <h5 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">System Health</h5>
                    <p class="text-3xl font-black text-primary">Healthy</p>
                </div>
            </div>
        </section>

        <!-- MAIN LAYOUT: GRID & SIDEBAR -->
        <main class="grid grid-cols-1 lg:grid-cols-12 gap-10">
            
            <!-- (App Launcher Structure - 8 of 12 columns) -->
            <section class="lg:col-span-8 space-y-8 animate-enter" style="animation-delay: 0.2s;">
                <h2 class="text-2xl font-black text-gray-900 tracking-tight flex items-center gap-3">
                    <span class="w-1.5 h-7 bg-primary rounded-full"></span> 
                    Project Command Grid
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <?php 
                    /** 
                     * 🚀 DYNAMIC LOOP: เรนเดอร์การ์ดโปรเจกต์ตามสิทธิที่ระบุใน Array
                     */
                    foreach($projects as $proj):
                        $hasAccess = in_array($adminRole, $proj['allowed_roles']);
                        if (!$hasAccess) continue; // ซ่อนโปรเจกต์ถ้าแอดมินคนนี้ไม่มีสิทธิ์เข้า
                    ?>
                    
                    <div class="group relative bg-white border border-gray-100 p-8 rounded-[48px] flex flex-col justify-between card-shift transition-all duration-500 overflow-hidden">
                        <div>
                            <!-- Icon & Badges -->
                            <div class="flex justify-between items-start mb-8">
                                <div class="w-16 h-16 <?= $proj['bg_color'] ?> <?= $proj['icon_color'] ?> rounded-[24px] flex items-center justify-center text-3xl shadow-sm group-hover:scale-110 transition-all duration-500">
                                    <i class="fa-solid <?= $proj['icon'] ?>"></i>
                                </div>
                                <div class="flex flex-col gap-1 items-end">
                                    <?php foreach($proj['badges'] as $badge): ?>
                                        <span class="px-3 py-1 bg-gray-50 text-gray-400 rounded-full text-[9px] font-black uppercase tracking-widest border border-gray-100"><?= $badge ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Title & Desc -->
                            <h3 class="text-2xl font-black text-gray-900 mb-3 tracking-tight group-hover:text-primary transition-colors"><?= $proj['title'] ?></h3>
                            <p class="text-gray-500 text-xs leading-relaxed mb-10 opacity-80"><?= $proj['description'] ?></p>
                        </div>

                        <!-- Actions Grid -->
                        <div class="flex gap-3">
                            <?php foreach($proj['actions'] as $act): ?>
                                <a href="<?= $act['url'] ?>" class="flex-1 px-4 py-4 rounded-[20px] text-[10px] font-black uppercase tracking-widest text-center transition-all active:scale-95 
                                    <?= $act['primary'] ? 'bg-primary text-white shadow-lg shadow-blue-200 hover:brightness-110' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                                    <?= $act['label'] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- Decoration Element -->
                        <div class="absolute -top-10 -right-10 w-24 h-24 bg-gray-50 rounded-full opacity-50 blur-3xl group-hover:bg-primary/10 transition-all"></div>
                    </div>

                    <?php endforeach; ?>
                </div>
            </section>

            <!-- (Quick Action & Activity Feed - 4 of 12 columns) -->
            <aside class="lg:col-span-4 space-y-10 animate-enter" style="animation-delay: 0.3s;">
                
                <div>
                    <h2 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-3 mb-6">
                        <i class="fa-solid fa-bolt-lightning text-accent"></i> Activity Center
                    </h2>

                    <div class="bg-white border border-gray-100 rounded-[40px] shadow-sm overflow-hidden p-3 shadow-xl shadow-gray-200/50">
                        <div class="p-5 space-y-8">
                            <?php if($recentActivity): ?>
                                <?php foreach($recentActivity as $log): ?>
                                    <div class="relative pl-7 border-l-2 border-primary/10 last:border-0 pb-6 group">
                                        <div class="absolute -left-[5px] top-1 w-2.5 h-2.5 bg-blue-100 rounded-full group-hover:bg-primary transition-all duration-300 ring-4 ring-white"></div>
                                        <div class="flex flex-col gap-1">
                                            <div class="flex justify-between items-center mb-0.5">
                                                <span class="text-[11px] font-black text-primary uppercase tracking-wider"><?= htmlspecialchars($log['action']) ?></span>
                                                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-tighter opacity-70"><?= date('H:i | d M', strtotime($log['created_at'])) ?></span>
                                            </div>
                                            <p class="text-[13px] font-black text-gray-900 leading-snug group-hover:text-blue-700 transition-colors"><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></p>
                                            <p class="text-[11px] font-medium text-gray-400 leading-relaxed italic"><?= htmlspecialchars($log['description']) ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-10 opacity-30">
                                    <i class="fa-solid fa-ghost text-4xl mb-3"></i>
                                    <p class="text-xs font-bold uppercase tracking-widest">Quiet in the hub...</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-gray-50/50 p-5 text-center border-t border-gray-50 rounded-b-[40px]">
                            <a href="../admin/activity_logs.php" class="text-[10px] font-black uppercase tracking-widest text-primary hover:underline transition-all underline-offset-4">View Operational Logs <i class="fa-solid fa-chevron-right ml-1"></i></a>
                        </div>
                    </div>
                </div>

                <!-- QUICK SHORTCUTS -->
                <div class="bg-primary bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] p-8 rounded-[40px] text-white shadow-2xl shadow-blue-200 relative overflow-hidden">
                    <h4 class="text-lg font-black mb-4 relative z-10">System Shortcut</h4>
                    <div class="space-y-3 relative z-10">
                        <a href="users.php" class="flex items-center gap-3 p-3 bg-white/10 rounded-2xl hover:bg-white/20 transition-all text-sm font-bold border border-white/5">
                            <i class="fa-solid fa-users w-5"></i> Users Center
                        </a>
                        <a href="../admin/campaigns.php" class="flex items-center gap-3 p-3 bg-white/10 rounded-2xl hover:bg-white/20 transition-all text-sm font-bold">
                            <i class="fa-solid fa-plus w-5"></i> New Campaign
                        </a>
                    </div>
                    <i class="fa-solid fa-screwdriver-wrench absolute -bottom-10 -right-10 text-9xl text-white/5 rotate-12"></i>
                </div>

            </aside>
        </main>

        <!-- FOOTER & BRANDING -->
        <footer class="pt-20 pb-10 text-center animate-enter" style="animation-delay: 0.5s;">
            <div class="flex flex-col items-center gap-4 opacity-30">
                <i class="fa-solid fa-shield-halved text-primary text-2xl"></i>
                <p class="text-[10px] font-black uppercase tracking-[0.5em]">Central Command v3.0 Powered by Antigravity</p>
            </div>
        </footer>

    </div>

</body>
</html>
