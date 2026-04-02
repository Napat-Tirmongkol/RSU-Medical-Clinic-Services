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
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    
    <style>
        /* ── Base ─────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background: #f4f6fb;
            background-image:
                radial-gradient(circle at 18% 12%, rgba(0,82,204,.06) 0, transparent 420px),
                radial-gradient(circle at 85% 80%, rgba(255,171,0,.05) 0, transparent 380px);
            min-height: 100vh;
        }

        /* ── Animations ───────────────────────────────────────── */
        @keyframes up { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
        .au  { animation: up .5s cubic-bezier(.16,1,.3,1) both; }
        .d1  { animation-delay: .08s; }
        .d2  { animation-delay: .16s; }
        .d3  { animation-delay: .24s; }
        .d4  { animation-delay: .32s; }

        /* ── Header ───────────────────────────────────────────── */
        .portal-header {
            background: #fff;
            border-bottom: 1.5px solid #e8eef7;
            box-shadow: 0 2px 12px rgba(0,82,204,.05);
            position: sticky; top: 0; z-index: 40;
        }
        .brand-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #0052CC 0%, #1a6fe8 100%);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.15rem;
            box-shadow: 0 4px 12px rgba(0,82,204,.3);
            flex-shrink: 0;
        }
        .user-pill {
            display: flex; align-items: center; gap: 10px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 99px;
            padding: 6px 14px 6px 8px;
        }
        .user-avatar {
            width: 30px; height: 30px;
            background: linear-gradient(135deg,#0052CC,#1a6fe8);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: .7rem;
        }

        /* ── KPI cards ────────────────────────────────────────── */
        .kpi-card {
            background: #fff;
            border-radius: 18px;
            padding: 22px 24px;
            border: 1.5px solid #e8eef7;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
            position: relative; overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }
        .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,82,204,.1); }
        .kpi-accent { position: absolute; top:0; left:0; right:0; height:3px; border-radius:18px 18px 0 0; }
        .kpi-icon {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: .95rem;
            margin-bottom: 16px;
        }
        .kpi-num { font-size: 2rem; font-weight: 900; line-height: 1; margin-bottom: 4px; }
        .kpi-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: #94a3b8; }

        /* ── Section heading ──────────────────────────────────── */
        .sec-title {
            font-size: 1rem; font-weight: 900; color: #0f172a;
            display: flex; align-items: center; gap: 10px;
            letter-spacing: -.01em;
        }
        .sec-title::before {
            content: '';
            display: block;
            width: 4px; height: 20px;
            background: linear-gradient(180deg,#0052CC,#60a5fa);
            border-radius: 99px;
        }

        /* ── Project cards ────────────────────────────────────── */
        .proj-card {
            background: #fff;
            border: 1.5px solid #e8eef7;
            border-radius: 22px;
            padding: 24px;
            display: flex; flex-direction: column;
            transition: box-shadow .25s, transform .25s, border-color .25s;
            position: relative; overflow: hidden;
        }
        .proj-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 40px rgba(0,82,204,.1);
            border-color: rgba(0,82,204,.2);
        }
        .proj-card-icon {
            width: 52px; height: 52px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
            border-width: 1.5px; border-style: solid;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
            transition: transform .25s;
        }
        .proj-card:hover .proj-card-icon { transform: scale(1.08) rotate(-3deg); }
        .proj-badge {
            font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em;
            padding: 3px 8px;
            background: #f1f5f9; color: #64748b;
            border-radius: 99px;
            border: 1px solid #e2e8f0;
        }
        .proj-action {
            display: flex; align-items: center; justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em;
            transition: all .18s ease;
            flex: 1;
            text-decoration: none;
        }
        .proj-action.primary {
            background: linear-gradient(135deg,#0052CC,#1a6fe8);
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,82,204,.25);
        }
        .proj-action.primary:hover { box-shadow: 0 6px 18px rgba(0,82,204,.4); filter:brightness(1.07); }
        .proj-action.secondary {
            background: #f1f5f9; color: #475569;
            border: 1.5px solid #e2e8f0;
        }
        .proj-action.secondary:hover { background: #e2e8f0; }

        /* ── Activity feed ────────────────────────────────────── */
        .feed-card {
            background: #fff;
            border: 1.5px solid #e8eef7;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,.04);
        }
        .feed-item {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            display: flex; gap: 12px; align-items: flex-start;
        }
        .feed-item:last-child { border-bottom: none; }
        .feed-dot {
            width: 34px; height: 34px; border-radius: 10px;
            background: #eff6ff; color: #0052CC;
            display: flex; align-items: center; justify-content: center;
            font-size: .7rem; flex-shrink: 0;
        }

        /* ── Shortcut card ────────────────────────────────────── */
        .shortcut-card {
            background: linear-gradient(135deg, #0052CC 0%, #1a6fe8 100%);
            border-radius: 20px;
            padding: 22px;
            color: #fff;
            position: relative; overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,82,204,.3);
        }
        .shortcut-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px;
            background: rgba(255,255,255,.12);
            border-radius: 12px;
            color: #fff; text-decoration: none;
            font-size: .8rem; font-weight: 700;
            border: 1px solid rgba(255,255,255,.1);
            transition: background .18s;
        }
        .shortcut-link:hover { background: rgba(255,255,255,.22); }
        .shortcut-link i { width: 16px; text-align: center; opacity: .8; }

        /* ── Scrollbar ────────────────────────────────────────── */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
    </style>
</head>
<body class="font-sans text-gray-800" style="min-height:100vh">

    <!-- ══════════════════ HEADER ══════════════════ -->
    <header class="portal-header au">
        <div class="max-w-[1280px] mx-auto px-6 py-3 flex items-center justify-between gap-4">
            <!-- Brand -->
            <div class="flex items-center gap-3">
                <div class="brand-icon"><i class="fa-solid fa-square-rss"></i></div>
                <div>
                    <div class="font-black text-gray-900 text-[17px] leading-none tracking-tight">Central HUB</div>
                    <div class="text-[10px] font-bold text-[#0052CC] tracking-[.15em] uppercase opacity-60 mt-0.5">RSU Healthcare Portal</div>
                </div>
            </div>

            <!-- Right: user + logout -->
            <div class="flex items-center gap-3">
                <div class="user-pill">
                    <div class="user-avatar"><i class="fa-solid fa-user-shield text-[11px]"></i></div>
                    <div class="hidden sm:block">
                        <div class="text-[9px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">Admin</div>
                        <div class="text-xs font-black text-gray-900 leading-none"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?></div>
                    </div>
                </div>
                <a href="../admin/logout.php"
                   class="w-9 h-9 rounded-xl bg-red-50 text-red-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition-all border border-red-100"
                   title="ออกจากระบบ">
                    <i class="fa-solid fa-power-off text-sm"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- ══════════════════ PAGE BODY ══════════════════ -->
    <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

        <!-- KPI STRIP -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 au d1">
            <!-- Total Members -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#f59e0b,#fbbf24)"></div>
                <div class="kpi-icon" style="background:#fffbeb; color:#d97706">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="kpi-num text-gray-900"><?= number_format($kpis['users']) ?></div>
                <div class="kpi-label">Total Members</div>
            </div>

            <!-- Running Campaigns -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#0052CC,#60a5fa)"></div>
                <div class="kpi-icon" style="background:#eff6ff; color:#0052CC">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div class="kpi-num text-gray-900"><?= $kpis['camps'] ?></div>
                <div class="kpi-label">Active Campaigns</div>
            </div>

            <!-- Pending Borrows -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#ef4444,#fca5a5)"></div>
                <div class="kpi-icon" style="background:#fff1f2; color:#ef4444">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div class="flex items-end gap-2">
                    <div class="kpi-num text-gray-900"><?= $kpis['borrows'] ?></div>
                    <?php if($kpis['borrows'] > 0): ?>
                        <span class="mb-1 px-1.5 py-0.5 bg-red-500 text-white text-[8px] font-black rounded-md leading-none animate-pulse">URGENT</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">Pending Borrows</div>
            </div>

            <!-- System Health -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#10b981,#6ee7b7)"></div>
                <div class="kpi-icon" style="background:#ecfdf5; color:#059669">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div class="kpi-num" style="color:#059669; font-size:1.5rem">Healthy</div>
                <div class="kpi-label">System Status</div>
            </div>
        </section>

        <!-- MAIN GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- PROJECT CARDS (8/12) -->
            <section class="lg:col-span-8 au d2">
                <div class="sec-title mb-5">Project Command Grid</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <?php foreach($projects as $proj):
                        if (!in_array($adminRole, $proj['allowed_roles'])) continue;
                    ?>
                    <div class="proj-card">
                        <!-- Card top row -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="proj-card-icon <?= $proj['bg_color'] ?> <?= $proj['icon_color'] ?> <?= $proj['border_color'] ?>">
                                <i class="fa-solid <?= $proj['icon'] ?>"></i>
                            </div>
                            <div class="flex flex-wrap justify-end gap-1">
                                <?php foreach($proj['badges'] as $b): ?>
                                    <span class="proj-badge"><?= $b ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Title & description -->
                        <h3 class="text-[15px] font-black text-gray-900 mb-1.5 leading-tight"><?= $proj['title'] ?></h3>
                        <p class="text-[12px] text-gray-500 leading-relaxed mb-5 flex-1"><?= $proj['description'] ?></p>

                        <!-- Actions -->
                        <div class="flex gap-2 mt-auto">
                            <?php foreach($proj['actions'] as $act): ?>
                                <a href="<?= $act['url'] ?>" class="proj-action <?= $act['primary'] ? 'primary' : 'secondary' ?>">
                                    <?php if($act['primary']): ?><i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i><?php endif; ?>
                                    <?= $act['label'] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- SIDEBAR (4/12) -->
            <aside class="lg:col-span-4 flex flex-col gap-5 au d3">

                <!-- Activity Feed -->
                <div>
                    <div class="sec-title mb-4">
                        Recent Activity
                        <span class="ml-auto text-[10px] font-bold text-[#0052CC] bg-blue-50 px-2 py-0.5 rounded-md">LIVE</span>
                    </div>
                    <div class="feed-card">
                        <?php if($recentActivity): ?>
                            <?php foreach($recentActivity as $log): ?>
                                <div class="feed-item">
                                    <div class="feed-dot">
                                        <i class="fa-solid fa-bolt text-[11px]"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2 mb-0.5">
                                            <span class="text-[10px] font-black text-[#0052CC] uppercase tracking-wider truncate"><?= htmlspecialchars($log['action']) ?></span>
                                            <span class="text-[9px] text-gray-400 whitespace-nowrap"><?= date('d M H:i', strtotime($log['created_at'])) ?></span>
                                        </div>
                                        <p class="text-[12px] font-bold text-gray-800 leading-snug truncate"><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></p>
                                        <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1"><?= htmlspecialchars($log['description']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="py-12 text-center text-gray-300">
                                <i class="fa-solid fa-ghost text-3xl mb-2 block"></i>
                                <p class="text-[11px] font-bold uppercase tracking-widest">No activity yet</p>
                            </div>
                        <?php endif; ?>
                        <a href="../admin/activity_logs.php"
                           class="flex items-center justify-center gap-1.5 py-3 text-[10px] font-black text-[#0052CC] uppercase tracking-wider hover:bg-blue-50 transition-colors border-t border-gray-50">
                            View all logs <i class="fa-solid fa-chevron-right text-[9px]"></i>
                        </a>
                    </div>
                </div>

                <!-- Quick Shortcuts -->
                <div class="shortcut-card au d4">
                    <div class="text-xs font-black uppercase tracking-widest opacity-70 mb-1">Quick Access</div>
                    <div class="font-black text-lg mb-4">System Shortcuts</div>
                    <div class="space-y-2">
                        <a href="users.php" class="shortcut-link">
                            <i class="fa-solid fa-users"></i> Users Center
                        </a>
                        <a href="../admin/campaigns.php" class="shortcut-link">
                            <i class="fa-solid fa-bullhorn"></i> Campaign Manager
                        </a>
                        <a href="../admin/error_logs.php" class="shortcut-link">
                            <i class="fa-solid fa-bug"></i> Error Logs
                        </a>
                    </div>
                    <i class="fa-solid fa-screwdriver-wrench absolute -bottom-6 -right-6 text-[6rem] opacity-5 rotate-12 pointer-events-none"></i>
                </div>

            </aside>
        </div>

        <!-- FOOTER -->
        <footer class="pt-6 pb-4 text-center">
            <div class="flex items-center justify-center gap-2 opacity-25">
                <i class="fa-solid fa-shield-halved text-[#0052CC]"></i>
                <span class="text-[10px] font-black uppercase tracking-[.4em]">Central Command v3.0 · RSU Healthcare</span>
            </div>
        </footer>

    </div>

</body>
</html>
