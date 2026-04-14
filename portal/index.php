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
 * (1) LIVE DATA & ROBUST STATS
 * ดึงสถิจริง พร้อมระบบป้องกันถ้าตารางในอนาคตยังไม่พร้อม
 */
$kpis = [
    'users'        => 0,
    'camps'        => 0,
    'borrows'      => 0,
    'logs'         => 0,
    'total_quota'  => 0,
    'used_quota'   => 0,
    'booking_rate' => 0,
];

try {
    $kpis['users'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();
    $kpis['camps'] = (int)$pdo->query("SELECT COUNT(*) FROM camp_list WHERE status = 'active'")->fetchColumn();

    // Quota & booking rate (e-Campaign)
    $quotaRow = $pdo->query("
        SELECT COALESCE(SUM(c.total_capacity), 0) AS total_quota,
               (SELECT COUNT(*) FROM camp_bookings WHERE status IN ('booked','confirmed')) AS used_quota
        FROM camp_list c WHERE c.status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    $kpis['total_quota'] = (int)($quotaRow['total_quota'] ?? 0);
    $kpis['used_quota']  = (int)($quotaRow['used_quota']  ?? 0);
    $kpis['booking_rate'] = $kpis['total_quota'] > 0
        ? (int)round($kpis['used_quota'] / $kpis['total_quota'] * 100)
        : 0;

    // Equipment borrows (optional module)
    if ($pdo->query("SHOW TABLES LIKE 'borrow_records'")->rowCount() > 0) {
        $kpis['borrows'] = (int)$pdo->query("SELECT COUNT(*) FROM borrow_records WHERE approval_status = 'pending'")->fetchColumn();
    }

    // Activity logs count (optional module)
    if ($pdo->query("SHOW TABLES LIKE 'activity_logs'")->rowCount() > 0) {
        $kpis['logs'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Portal Stats Fetch Error: " . $e->getMessage());
}

/**
 * (2) PROJECT CATALOG (SCALABLE STRUCTURE)
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
        'actions'       => array_filter([
            ['label' => 'Search Users',  'url' => 'users.php?layout=none',        'primary' => false],
            $adminRole === 'superadmin'
                ? ['label' => 'Manage Admins', 'url' => 'manage_admins.php?layout=none', 'primary' => true]
                : null,
        ])
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
    [
        'id'            => 'admin_tool',
        'title'         => 'Admin Tool',
        'description'   => 'เครื่องมือสำหรับผู้ดูแลระบบ จัดการและตั้งค่าระบบขั้นสูง',
        'icon'          => 'fa-screwdriver-wrench',
        'bg_color'      => 'bg-violet-50',
        'icon_color'    => 'text-violet-600',
        'border_color'  => 'border-violet-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Tools', 'Settings' ],
        'actions'       => [
            ['label' => 'Open Admin Tool', 'url' => 'admin_tool.php', 'primary' => true],
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

// Category assignments for filter tabs
$categoryMap = [
    'identity_governance' => 'core',
    'e_campaign'          => 'core',
    'e_borrow'            => 'core',
    'system_logs'         => 'tools',
    'admin_tool'          => 'tools',
    'future_app'          => 'dev',
];

/**
 * (3) RECENT ACTIVITY FETCH
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
    <link rel="stylesheet" href="../assets/css/portal.css">
</head>
<body class="font-sans text-gray-800" style="min-height:100vh;display:flex;flex-direction:column">

    <!-- Ambient background dots -->
    <div class="amb-dot" style="width:320px;height:320px;background:rgba(46,158,99,.1);top:5%;left:10%;--dur:16s;--delay:0s;--dx:40px;--dy:-30px"></div>
    <div class="amb-dot" style="width:240px;height:240px;background:rgba(77,201,138,.07);top:60%;right:8%;--dur:20s;--delay:-5s;--dx:-35px;--dy:25px"></div>
    <div class="amb-dot" style="width:180px;height:180px;background:rgba(59,186,122,.07);bottom:15%;left:30%;--dur:13s;--delay:-8s;--dx:25px;--dy:30px"></div>
    <div class="amb-dot" style="width:200px;height:200px;background:rgba(13,61,34,.05);top:35%;right:25%;--dur:17s;--delay:-3s;--dx:-20px;--dy:-25px"></div>
    <div class="amb-dot" style="width:150px;height:150px;background:rgba(110,231,183,.06);top:80%;left:55%;--dur:22s;--delay:-11s;--dx:30px;--dy:-20px"></div>

    <!-- ══════════════════ HEADER ══════════════════ -->
    <header class="portal-header au">
        <div class="max-w-[1280px] mx-auto px-3 sm:px-6 py-3 flex items-center justify-between gap-2 sm:gap-4">
            <!-- Brand -->
            <div class="flex items-center gap-2 sm:gap-3">
                <div class="brand-icon"><i class="fa-solid fa-heart"></i></div>
                <div>
                    <div class="font-black text-gray-900 text-[15px] sm:text-[17px] leading-none tracking-tight">Central HUB</div>
                    <div class="hidden sm:block text-[10px] font-bold tracking-[.15em] uppercase opacity-70 mt-0.5" style="color:#2e9e63">RSU Medical Clinic Portal</div>
                </div>
            </div>

            <!-- Right: user + logout -->
            <div class="flex items-center gap-2 sm:gap-3">

                <?php if ($adminRole === 'superadmin'): ?>
                <!-- Git Pull Button (Superadmin only) -->
                <button id="btnGitPull"
                        onclick="triggerGitPull()"
                        title="Pull โค้ดล่าสุดจาก Git"
                        style="display:flex;align-items:center;gap:6px;padding:6px 10px;border-radius:10px;border:1px solid #d1fae5;background:#f0fdf4;color:#16a34a;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;">
                    <i class="fa-solid fa-code-branch"></i>
                    <span class="hidden sm:inline">Git Pull</span>
                </button>
                <?php endif; ?>

                <!-- Live connection badge -->
                <div id="ws-badge" title="Real-time connection status"
                     style="display:flex;align-items:center;gap:5px;padding:5px 8px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border:1px solid #c7e8d5;background:#f0fdf4;color:#16a34a;transition:all .3s">
                    <span id="ws-dot" style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;animation:livePulse 1.6s infinite"></span>
                    <span id="ws-label" class="hidden sm:inline">Live</span>
                </div>
            </div>
        </div>
    </header>

    <!-- ══════════════════ APP SHELL ══════════════════ -->
    <div id="app-shell" style="display:flex;flex:1;min-height:0">

        <!-- ── Collapsible Sidebar ── -->
        <nav id="portal-sidebar">
            <!-- Brand / Toggle -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f0faf4;min-height:52px">
                <span id="psb-brand-text" style="font-size:13px;font-weight:900;color:#0f172a;white-space:nowrap;transition:opacity .2s,width .28s">Portal</span>
                <button onclick="toggleSidebar()" id="sidebar-toggle" title="Toggle sidebar"
                        style="width:28px;height:28px;border-radius:8px;border:none;cursor:pointer;background:#f0faf4;color:#2e9e63;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s">
                    <i id="sidebar-toggle-icon" class="fa-solid fa-chevron-left" style="font-size:11px;transition:transform .3s"></i>
                </button>
            </div>

            <!-- Nav items -->
            <div style="padding:10px;flex:1;overflow:hidden">
                <button class="psb-item psb-active" data-section="dashboard" onclick="switchSection('dashboard',this)">
                    <div class="psb-icon"><i class="fa-solid fa-chart-pie"></i></div>
                    <span class="psb-label">Dashboard</span>
                </button>
                <button class="psb-item" data-section="identity" onclick="switchSection('identity',this)">
                    <div class="psb-icon"><i class="fa-solid fa-id-card-clip"></i></div>
                    <span class="psb-label">Identity & Governance</span>
                </button>
                <button class="psb-item" data-section="settings" onclick="switchSection('settings',this)">
                    <div class="psb-icon"><i class="fa-solid fa-gear"></i></div>
                    <span class="psb-label">Settings</span>
                </button>
            </div>

            <!-- Bottom: user identity + logout -->
            <div id="psb-user-footer" style="border-top:1px solid #f0faf4;padding:10px">
                <!-- Expanded state: avatar + name + logout -->
                <div id="psb-user-expanded" style="display:flex;align-items:center;gap:10px;padding:8px 6px;border-radius:12px;background:#f8fafc;overflow:hidden;transition:opacity .2s">
                    <div style="width:32px;height:32px;border-radius:9px;background:#e8f8f0;color:#2e9e63;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fa-solid fa-user-shield" style="font-size:13px"></i>
                    </div>
                    <div class="psb-label" style="flex:1;min-width:0">
                        <div style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;line-height:1;margin-bottom:2px">Admin</div>
                        <div style="font-size:12px;font-weight:900;color:#0f172a;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?></div>
                    </div>
                    <a href="../admin/logout.php" class="psb-label"
                       title="ออกจากระบบ"
                       style="width:28px;height:28px;border-radius:8px;background:#fff1f2;color:#ef4444;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s;text-decoration:none"
                       onmouseover="this.style.background='#ef4444';this.style.color='#fff'"
                       onmouseout="this.style.background='#fff1f2';this.style.color='#ef4444'">
                        <i class="fa-solid fa-power-off" style="font-size:11px"></i>
                    </a>
                </div>
                <!-- Collapsed state: avatar only (centered) -->
                <div id="psb-user-collapsed" style="display:none;flex-direction:column;align-items:center;gap:6px">
                    <div style="width:32px;height:32px;border-radius:9px;background:#e8f8f0;color:#2e9e63;display:flex;align-items:center;justify-content:center">
                        <i class="fa-solid fa-user-shield" style="font-size:13px"></i>
                    </div>
                    <a href="../admin/logout.php"
                       title="ออกจากระบบ"
                       style="width:28px;height:28px;border-radius:8px;background:#fff1f2;color:#ef4444;display:flex;align-items:center;justify-content:center;transition:background .18s;text-decoration:none"
                       onmouseover="this.style.background='#ef4444';this.style.color='#fff'"
                       onmouseout="this.style.background='#fff1f2';this.style.color='#ef4444'">
                        <i class="fa-solid fa-power-off" style="font-size:11px"></i>
                    </a>
                </div>
            </div>
        </nav>

        <!-- ── Main Content ── -->
        <main id="portal-main" style="flex:1;overflow-y:auto;min-width:0">

        <!-- ════════════ SECTION: DASHBOARD ════════════ -->
        <div id="section-dashboard" class="portal-section">
        <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

        <!-- KPI STRIP -->
        <?php $borrowUrgent = $kpis['borrows'] > 0; ?>
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 au d1">

            <!-- 1. Total Members -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#f59e0b,#fbbf24)"></div>
                <div class="kpi-icon" style="background:#fffbeb;color:#d97706">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="kpi-num text-gray-900" id="kpi-users" data-counter="<?= $kpis['users'] ?>">0</div>
                <div class="kpi-label" style="margin-bottom:10px">บุคลากรและนักศึกษา</div>
                <div style="display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:800;color:#d97706;background:#fffbeb;border:1px solid #fde68a;padding:2px 8px;border-radius:99px;">
                    <i class="fa-solid fa-users" style="font-size:8px"></i> Total Members
                </div>
            </div>

            <!-- 2. Active Campaigns -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#2e9e63,#6ee7b7)"></div>
                <div class="kpi-icon" style="background:#e8f8f0;color:#2e9e63">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div class="kpi-num text-gray-900" id="kpi-camps" data-counter="<?= $kpis['camps'] ?>">0</div>
                <div class="kpi-label" style="margin-bottom:10px">แคมเปญสุขภาพ (Active)</div>
                <div style="font-size:10px;color:#94a3b8;font-weight:600">
                    โควต้ารวม <span id="kpi-quota" style="font-weight:900;color:#374151"><?= number_format($kpis['total_quota']) ?></span> ที่นั่ง
                </div>
            </div>

            <!-- 3. Pending Borrows -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,<?= $borrowUrgent ? '#ef4444,#fca5a5' : '#94a3b8,#cbd5e1' ?>)"></div>
                <div class="kpi-icon" style="background:<?= $borrowUrgent ? '#fff1f2' : '#f8fafc' ?>;color:<?= $borrowUrgent ? '#ef4444' : '#94a3b8' ?>">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div style="display:flex;align-items:flex-end;gap:6px">
                    <div class="kpi-num text-gray-900" id="kpi-borrows" data-counter="<?= $kpis['borrows'] ?>">0</div>
                    <span id="borrows-urgent" style="margin-bottom:4px;padding:2px 6px;background:#ef4444;color:#fff;font-size:8px;font-weight:900;border-radius:6px;line-height:1;<?= $borrowUrgent ? '' : 'display:none' ?>" class="animate-pulse">URGENT</span>
                </div>
                <div class="kpi-label" style="margin-bottom:10px">คำขอยืมอุปกรณ์</div>
                <div id="borrows-sub" style="font-size:10px;font-weight:700;color:<?= $borrowUrgent ? '#ef4444' : '#94a3b8' ?>">
                    <?php if ($borrowUrgent): ?>
                        <i class="fa-solid fa-circle-exclamation" style="margin-right:3px"></i>รอการตรวจสอบ
                    <?php else: ?>
                        ไม่มีรายการค้างในระบบ
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4. Booking Rate -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#8b5cf6,#c4b5fd)"></div>
                <div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div class="kpi-num text-gray-900" style="display:flex;align-items:baseline;gap:2px">
                    <span id="kpi-rate"><?= $kpis['booking_rate'] ?></span><span style="font-size:.875rem;font-weight:700;color:#9ca3af">%</span>
                </div>
                <div class="kpi-label" style="margin-bottom:10px">อัตราการจองโควต้า</div>
                <div>
                    <div style="width:100%;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;margin-bottom:4px">
                        <div id="kpi-rate-bar" style="height:5px;border-radius:99px;transition:width .7s ease;width:<?= $kpis['booking_rate'] ?>%;background:linear-gradient(90deg,#8b5cf6,#c4b5fd)"></div>
                    </div>
                    <p style="font-size:9px;color:#94a3b8;font-weight:600;text-align:right">
                        <span id="kpi-used"><?= number_format($kpis['used_quota']) ?></span> / <span id="kpi-total-quota"><?= number_format($kpis['total_quota']) ?></span> ที่นั่ง
                    </p>
                </div>
            </div>

        </section>

        <!-- MAIN GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- PROJECT CARDS (8/12) -->
            <section class="lg:col-span-8 au d2">

                <!-- Control Bar -->
                <div style="margin-bottom:20px">
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px">
                        <div class="sec-title">Project Command Grid</div>

                        <div style="display:flex;align-items:center;gap:10px">
                            <!-- Search -->
                            <div style="position:relative">
                                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none"></i>
                                <input type="text" id="search-project" placeholder="ค้นหาระบบ..."
                                       style="padding:7px 12px 7px 30px;border:1.5px solid #d0ead9;border-radius:12px;font-size:12px;outline:none;width:180px;font-family:inherit;color:#374151;background:#fff;transition:border-color .2s,box-shadow .2s"
                                       onfocus="this.style.borderColor='#2e9e63';this.style.boxShadow='0 0 0 3px rgba(46,158,99,.1)'"
                                       onblur="this.style.borderColor='#d0ead9';this.style.boxShadow='none'">
                            </div>
                            <!-- View toggle -->
                            <div style="display:flex;background:#f1f5f9;border-radius:10px;padding:3px;gap:2px">
                                <button id="btn-grid" onclick="projSetView('grid')" title="มุมมองการ์ด"
                                        style="padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:#fff;color:#2e9e63;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:all .2s">
                                    <i class="fa-solid fa-border-all" style="font-size:12px"></i>
                                </button>
                                <button id="btn-list" onclick="projSetView('list')" title="มุมมองรายการ"
                                        style="padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:transparent;color:#94a3b8;transition:all .2s">
                                    <i class="fa-solid fa-list" style="font-size:12px"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Filter tabs -->
                    <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:2px">
                        <button class="proj-tab active" data-filter="all"   onclick="projSetFilter(this)">ทั้งหมด</button>
                        <button class="proj-tab"        data-filter="core"  onclick="projSetFilter(this)">ระบบหลัก (Core)</button>
                        <button class="proj-tab"        data-filter="tools" onclick="projSetFilter(this)">เครื่องมือ (Tools)</button>
                        <button class="proj-tab"        data-filter="dev"   onclick="projSetFilter(this)">กำลังพัฒนา (Dev Stage)</button>
                    </div>
                </div>

                <!-- Cards -->
                <div id="project-container" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <?php $cardIdx = 0; foreach($projects as $proj):
                        if (!in_array($adminRole, $proj['allowed_roles'])) continue;
                        $cardDelay = round(0.1 + $cardIdx * 0.12, 2);
                        $cardIdx++;
                        $cat      = $categoryMap[$proj['id']] ?? 'core';
                        $keywords = strtolower(implode(' ', $proj['badges']) . ' ' . $proj['title']);
                    ?>
                    <div class="proj-card"
                         data-category="<?= $cat ?>"
                         data-name="<?= htmlspecialchars(strtolower($proj['title'])) ?>"
                         data-keywords="<?= htmlspecialchars($keywords) ?>"
                         style="animation-delay:<?= $cardDelay ?>s">

                        <div class="proj-card-header">
                            <div class="proj-card-icon <?= $proj['bg_color'] ?> <?= $proj['icon_color'] ?> <?= $proj['border_color'] ?>">
                                <i class="fa-solid <?= $proj['icon'] ?>"></i>
                            </div>
                            <div class="proj-card-badges">
                                <?php foreach($proj['badges'] as $b): ?>
                                    <span class="proj-badge"><?= $b ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="proj-card-body">
                            <h3 class="text-[15px] font-black text-gray-900 mb-1.5 leading-tight"><?= $proj['title'] ?></h3>
                            <p class="text-[12px] text-gray-500 leading-relaxed"><?= $proj['description'] ?></p>
                        </div>

                        <div class="proj-card-actions">
                            <?php foreach($proj['actions'] as $act): ?>
                                <a href="<?= $act['url'] ?>" class="proj-action <?= $act['primary'] ? 'primary' : 'secondary' ?>">
                                    <?php if($act['primary']): ?><i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i><?php endif; ?>
                                    <?= $act['label'] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Empty state -->
                    <div id="proj-empty" style="display:none;grid-column:1/-1;padding:48px 24px;text-align:center">
                        <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;color:#cbd5e1;margin-bottom:12px;display:block"></i>
                        <p style="font-size:13px;font-weight:700;color:#94a3b8">ไม่พบระบบที่ค้นหา</p>
                        <p style="font-size:11px;color:#cbd5e1;margin-top:4px">ลองเปลี่ยนคำค้นหาหรือล้างตัวกรอง</p>
                    </div>
                </div>

            </section>

            <!-- SIDEBAR (4/12) -->
            <aside class="lg:col-span-4 flex flex-col gap-5 au d3">

                <!-- Activity Feed -->
                <div>
                    <div class="sec-title mb-4">
                        Recent Activity
                        <span class="ml-auto live-badge">LIVE</span>
                    </div>
                    <div class="feed-card" id="activity-feed">
                        <?php if($recentActivity): ?>
                            <?php foreach($recentActivity as $log): ?>
                                <div class="feed-item">
                                    <div class="feed-dot">
                                        <i class="fa-solid fa-bolt text-[11px]"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2 mb-0.5">
                                            <span class="text-[10px] font-black uppercase tracking-wider truncate" style="color:#2e9e63"><?= htmlspecialchars($log['action']) ?></span>
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
                           class="flex items-center justify-center gap-1.5 py-3 text-[10px] font-black uppercase tracking-wider transition-colors border-t border-gray-50 hover:bg-green-50" style="color:#2e9e63">
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
                <i class="fa-solid fa-shield-halved" style="color:#2e9e63"></i>
                <span class="text-[10px] font-black uppercase tracking-[.4em]">Central Command v3.0 · RSU Medical Clinic</span>
            </div>
        </footer>

        </div><!-- /section-dashboard inner -->
        </div><!-- /section-dashboard -->

        <!-- ════════════ SECTION: IDENTITY & GOVERNANCE ════════════ -->
        <div id="section-identity" class="portal-section" style="display:none">
        <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

            <div class="au d1">
                <div class="sec-title mb-1">Identity &amp; Governance</div>
                <p style="font-size:13px;color:#64748b;margin-bottom:24px">ศูนย์กลางจัดการผู้ใช้งาน สิทธิ์การเข้าถึง และความปลอดภัยของระบบ</p>

                <!-- Quick-access cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
                    <a href="users.php" class="proj-card" style="text-decoration:none;display:flex;flex-direction:column;gap:10px">
                        <div class="proj-card-header">
                            <div class="proj-card-icon bg-amber-50 text-amber-500 border-amber-100">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <div class="proj-card-badges"><span class="proj-badge">Directory</span></div>
                        </div>
                        <div class="proj-card-body">
                            <h3 class="text-[15px] font-black text-gray-900 mb-1">Users Center</h3>
                            <p class="text-[12px] text-gray-500">ค้นหา แก้ไข และจัดการข้อมูลผู้ใช้งานทั้งหมด</p>
                        </div>
                        <div class="proj-card-actions">
                            <span class="proj-action primary"><i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i>เปิด Users Center</span>
                        </div>
                    </a>
                    <?php if ($adminRole === 'superadmin'): ?>
                    <a href="manage_admins.php" class="proj-card" style="text-decoration:none;display:flex;flex-direction:column;gap:10px">
                        <div class="proj-card-header">
                            <div class="proj-card-icon bg-red-50 text-red-500 border-red-100">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div class="proj-card-badges"><span class="proj-badge">Superadmin</span></div>
                        </div>
                        <div class="proj-card-body">
                            <h3 class="text-[15px] font-black text-gray-900 mb-1">Manage Admins</h3>
                            <p class="text-[12px] text-gray-500">เพิ่ม แก้ไข หรือลบบัญชีแอดมินและกำหนดสิทธิ์</p>
                        </div>
                        <div class="proj-card-actions">
                            <span class="proj-action primary"><i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i>จัดการแอดมิน</span>
                        </div>
                    </a>
                    <?php endif; ?>
                    <a href="../admin/activity_logs.php" class="proj-card" style="text-decoration:none;display:flex;flex-direction:column;gap:10px">
                        <div class="proj-card-header">
                            <div class="proj-card-icon bg-slate-100 text-slate-600 border-slate-200">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div class="proj-card-badges"><span class="proj-badge">Audit</span></div>
                        </div>
                        <div class="proj-card-body">
                            <h3 class="text-[15px] font-black text-gray-900 mb-1">Activity Logs</h3>
                            <p class="text-[12px] text-gray-500">ดูประวัติการใช้งาน การเปลี่ยนแปลง และเหตุการณ์สำคัญ</p>
                        </div>
                        <div class="proj-card-actions">
                            <span class="proj-action secondary">ดู Audit Trail</span>
                        </div>
                    </a>
                </div>

                <!-- Stats summary -->
                <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:16px;padding:20px 24px;display:flex;gap:32px;flex-wrap:wrap">
                    <div>
                        <div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">ผู้ใช้งานทั้งหมด</div>
                        <div style="font-size:28px;font-weight:900;color:#0f172a" id="id-users-count"><?= number_format($kpis['users']) ?></div>
                    </div>
                    <div style="width:1px;background:#e2e8f0;align-self:stretch"></div>
                    <div>
                        <div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">แคมเปญ Active</div>
                        <div style="font-size:28px;font-weight:900;color:#0f172a"><?= number_format($kpis['camps']) ?></div>
                    </div>
                    <div style="width:1px;background:#e2e8f0;align-self:stretch"></div>
                    <div>
                        <div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">โควต้าที่จองแล้ว</div>
                        <div style="font-size:28px;font-weight:900;color:#0f172a"><?= number_format($kpis['used_quota']) ?> <span style="font-size:14px;font-weight:600;color:#94a3b8">/ <?= number_format($kpis['total_quota']) ?></span></div>
                    </div>
                </div>
            </div>

        </div>
        </div><!-- /section-identity -->

        <!-- ════════════ SECTION: SETTINGS ════════════ -->
        <div id="section-settings" class="portal-section" style="display:none">
        <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

            <div class="au d1">
                <div class="sec-title mb-1">Settings</div>
                <p style="font-size:13px;color:#64748b;margin-bottom:24px">การตั้งค่าระบบและข้อมูลสภาพแวดล้อม</p>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- System Info -->
                    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;padding:24px">
                        <div style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.12em;margin-bottom:16px">System Information</div>
                        <div style="display:flex;flex-direction:column;gap:12px">
                            <?php $sysInfo = [
                                ['PHP Version',    phpversion()],
                                ['Server',         $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'],
                                ['Memory Limit',   ini_get('memory_limit')],
                                ['Max Upload',     ini_get('upload_max_filesize')],
                                ['Date / Time',    date('d M Y · H:i')],
                            ]; ?>
                            <?php foreach ($sysInfo as [$label, $val]): ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9">
                                <span style="font-size:12px;font-weight:700;color:#64748b"><?= htmlspecialchars($label) ?></span>
                                <span style="font-size:12px;font-weight:800;color:#0f172a;font-family:monospace"><?= htmlspecialchars($val) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;padding:24px">
                        <div style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.12em;margin-bottom:16px">Quick Actions</div>
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <?php if ($adminRole === 'superadmin'): ?>
                            <button onclick="triggerGitPull()" id="btnGitPull"
                                    style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1.5px solid #d1fae5;background:#f0fdf4;color:#16a34a;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;text-align:left">
                                <i class="fa-solid fa-code-branch"></i> <span>Git Pull — Update System</span>
                            </button>
                            <?php endif; ?>
                            <a href="../admin/error_logs.php"
                               style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;text-decoration:none;transition:all .2s">
                                <i class="fa-solid fa-bug" style="color:#94a3b8"></i> Error Logs
                            </a>
                            <a href="logout.php"
                               style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1.5px solid #fee2e2;background:#fff1f2;color:#dc2626;font-size:13px;font-weight:700;text-decoration:none;transition:all .2s">
                                <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        </div><!-- /section-settings -->

        </main><!-- /portal-main -->
    </div><!-- /app-shell -->

<script>
/* ── 1. KPI Number Counter ──────────────────────────────── */
document.querySelectorAll('[data-counter]').forEach(el => {
    const target = parseInt(el.dataset.counter, 10) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    const duration = 1200;
    const start = performance.now();
    const easeOut = t => 1 - Math.pow(1 - t, 3);
    function tick(now) {
        const p = Math.min((now - start) / duration, 1);
        el.textContent = Math.floor(easeOut(p) * target).toLocaleString();
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = target.toLocaleString();
    }
    requestAnimationFrame(tick);
});

/* ── 2. Ripple on buttons ──────────────────────────────── */
document.querySelectorAll('.proj-action').forEach(btn => {
    btn.addEventListener('click', function(e) {
        const r = this.getBoundingClientRect();
        const size = Math.max(r.width, r.height);
        const el = document.createElement('span');
        el.className = 'ripple-wave';
        el.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-r.left-size/2}px;top:${e.clientY-r.top-size/2}px`;
        this.appendChild(el);
        el.addEventListener('animationend', () => el.remove());
    });
});

/* ── 3. 3D Tilt on project cards ───────────────────────── */
document.querySelectorAll('.proj-card').forEach(card => {
    card.addEventListener('mousemove', function(e) {
        const r = this.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width  - .5;
        const y = (e.clientY - r.top)  / r.height - .5;
        this.style.transform = `translateY(-5px) rotateX(${-y*8}deg) rotateY(${x*8}deg)`;
        this.style.transition = 'transform .1s ease';
    });
    card.addEventListener('mouseleave', function() {
        this.style.transform = '';
        this.style.transition = 'transform .4s ease, box-shadow .25s, border-color .25s';
    });
});
</script>

<?php if ($adminRole === 'superadmin'): ?>
<script>
function triggerGitPull() {
    const btn = document.getElementById('btnGitPull');
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Pulling...</span>';

    fetch('../admin/ajax_git_pull.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                btn.style.background = '#dcfce7';
                btn.style.color = '#15803d';
                btn.innerHTML = '<i class="fa-solid fa-check"></i> <span>สำเร็จ!</span>';
                if (data.detail && !data.detail.includes('Already up to date')) {
                    // มีโค้ดใหม่ — แจ้งให้ refresh
                    setTimeout(() => {
                        if (confirm('Git Pull สำเร็จ!\n\n' + data.detail + '\n\nรีโหลดหน้าเพื่อใช้งานโค้ดใหม่?')) {
                            location.reload();
                        }
                    }, 500);
                }
            } else {
                btn.style.background = '#fef2f2';
                btn.style.color = '#dc2626';
                btn.style.borderColor = '#fecaca';
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> <span>ล้มเหลว</span>';
                alert('Git Pull ล้มเหลว:\n' + data.message + (data.detail ? '\n\n' + data.detail : ''));
            }
        })
        .catch(() => {
            btn.style.background = '#fef2f2';
            btn.style.color = '#dc2626';
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> <span>Error</span>';
        })
        .finally(() => {
            // Reset button หลัง 3 วินาที
            setTimeout(() => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.background = '#f0fdf4';
                btn.style.color = '#16a34a';
                btn.style.borderColor = '#d1fae5';
                btn.innerHTML = '<i class="fa-solid fa-code-branch"></i> <span>Git Pull</span>';
            }, 3000);
        });
}
</script>
<?php endif; ?>

<script>
/* ══════════════════════════════════════════════════════════════
   POLLING — live dashboard updates every 20s (no persistent connection)
   ══════════════════════════════════════════════════════════════ */

const _liveStyle = document.createElement('style');
_liveStyle.textContent = `
  @keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }
  @keyframes kpiFade   { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
  @keyframes feedSlide { from{opacity:0;transform:translateX(10px)} to{opacity:1;transform:translateX(0)} }
  .kpi-updated { animation: kpiFade .4s ease both; }
  .feed-new    { animation: feedSlide .3s ease both; }
`;
document.head.appendChild(_liveStyle);

const badge = document.getElementById('ws-badge');
const dot   = document.getElementById('ws-dot');
const label = document.getElementById('ws-label');

function setBadge(state) {
    const styles = {
        live:       { bg:'#f0fdf4', color:'#16a34a', border:'#c7e8d5', dot:'#22c55e', anim:'livePulse 1.6s infinite', text:'Live' },
        loading:    { bg:'#fffbeb', color:'#d97706', border:'#fde68a', dot:'#f59e0b', anim:'livePulse .8s infinite',  text:'Updating…' },
        offline:    { bg:'#fef2f2', color:'#dc2626', border:'#fecaca', dot:'#ef4444', anim:'none',                    text:'Offline' },
    };
    const s = styles[state] || styles.offline;
    badge.style.cssText = `display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;transition:all .3s;background:${s.bg};color:${s.color};border:1px solid ${s.border}`;
    dot.style.background  = s.dot;
    dot.style.animation   = s.anim;
    label.textContent     = s.text;
}

function animateKpi(el, toVal) {
    if (!el) return;
    const from = parseInt(el.textContent.replace(/,/g,''), 10) || 0;
    if (from === toVal) return;
    const dur = 600, start = performance.now();
    const ease = t => 1 - Math.pow(1 - t, 3);
    el.classList.remove('kpi-updated'); void el.offsetWidth; el.classList.add('kpi-updated');
    (function tick(now) {
        const p = Math.min((now - start) / dur, 1);
        el.textContent = Math.floor(ease(p) * (toVal - from) + from).toLocaleString();
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = toVal.toLocaleString();
    })(start);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderActivity(logs) {
    const feed = document.getElementById('activity-feed');
    const link = feed?.querySelector('a[href]');
    if (!feed) return;
    feed.querySelectorAll('.feed-item').forEach(el => el.remove());
    if (!logs?.length) return;
    const frag = document.createDocumentFragment();
    logs.forEach((log, i) => {
        const ts = new Date(log.timestamp.replace(' ','T'));
        const timeStr = ts.toLocaleString('th-TH',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
        const row = document.createElement('div');
        row.className = 'feed-item feed-new';
        row.style.animationDelay = (i * 0.04) + 's';
        row.innerHTML = `<div class="feed-dot"><i class="fa-solid fa-bolt text-[11px]"></i></div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2 mb-0.5">
                    <span class="text-[10px] font-black uppercase tracking-wider truncate" style="color:#2e9e63">${escHtml(log.action)}</span>
                    <span class="text-[9px] text-gray-400 whitespace-nowrap">${timeStr}</span>
                </div>
                <p class="text-[12px] font-bold text-gray-800 leading-snug truncate">${escHtml(log.admin_name||'System')}</p>
                <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1">${escHtml(log.description||'')}</p>
            </div>`;
        frag.appendChild(row);
    });
    feed.insertBefore(frag, link);
}

// ── Polling ───────────────────────────────────────────────────────────────────
const POLL_INTERVAL = 20000; // 20 seconds
let pollTimer = null;

function poll() {
    setBadge('loading');
    fetch('ajax_stats.php', { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(d => {
            if (!d.ok) { setBadge('offline'); return; }
            animateKpi(document.getElementById('kpi-users'),   d.users);
            animateKpi(document.getElementById('kpi-camps'),   d.camps);
            animateKpi(document.getElementById('kpi-borrows'), d.borrows);

            // Borrows urgency badge + sub text
            const ub = document.getElementById('borrows-urgent');
            if (ub) ub.style.display = d.borrows > 0 ? 'inline' : 'none';
            const borrowsSub = document.getElementById('borrows-sub');
            if (borrowsSub) {
                if (d.borrows > 0) {
                    borrowsSub.style.color = '#ef4444';
                    borrowsSub.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:3px"></i>รอการตรวจสอบ';
                } else {
                    borrowsSub.style.color = '#94a3b8';
                    borrowsSub.textContent = 'ไม่มีรายการค้างในระบบ';
                }
            }

            // Quota & booking rate
            if (d.total_quota !== undefined) {
                const rate = d.booking_rate ?? 0;
                const rateBar = document.getElementById('kpi-rate-bar');
                const rateNum = document.getElementById('kpi-rate');
                const kpiUsed  = document.getElementById('kpi-used');
                const kpiTQ    = document.getElementById('kpi-total-quota');
                const kpiQuota = document.getElementById('kpi-quota');
                if (rateBar)  rateBar.style.width = rate + '%';
                if (rateNum)  rateNum.textContent  = rate;
                if (kpiUsed)  kpiUsed.textContent  = (d.used_quota  ?? 0).toLocaleString();
                if (kpiTQ)    kpiTQ.textContent     = d.total_quota.toLocaleString();
                if (kpiQuota) kpiQuota.textContent  = d.total_quota.toLocaleString();
            }

            if (Array.isArray(d.activity)) renderActivity(d.activity);
            setBadge('live');
        })
        .catch(() => setBadge('offline'));
}

/* ── Project Grid Controls ────────────────────────────────────────────────── */
(function () {
    var currentFilter = 'all';
    var searchQuery   = '';

    function applyFilters() {
        var cards   = document.querySelectorAll('#project-container .proj-card');
        var visible = 0;
        cards.forEach(function (card) {
            var name     = (card.dataset.name     || '').toLowerCase();
            var keywords = (card.dataset.keywords || '').toLowerCase();
            var category =  card.dataset.category || '';
            var matchSearch = !searchQuery || name.includes(searchQuery) || keywords.includes(searchQuery);
            var matchFilter = currentFilter === 'all' || category === currentFilter;
            if (matchSearch && matchFilter) {
                card.style.display = ''; visible++;
            } else {
                card.style.display = 'none';
            }
        });
        var empty = document.getElementById('proj-empty');
        if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
    }

    window.projSetFilter = function (btn) {
        document.querySelectorAll('.proj-tab').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        currentFilter = btn.dataset.filter;
        applyFilters();
    };

    window.projSetView = function (view) {
        var container = document.getElementById('project-container');
        var btnGrid   = document.getElementById('btn-grid');
        var btnList   = document.getElementById('btn-list');
        var activeStyle   = 'padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:#fff;color:#2e9e63;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:all .2s';
        var inactiveStyle = 'padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:transparent;color:#94a3b8;transition:all .2s';
        if (view === 'list') {
            container.classList.add('list-mode');
            btnGrid.style.cssText = inactiveStyle;
            btnList.style.cssText = activeStyle;
        } else {
            container.classList.remove('list-mode');
            btnGrid.style.cssText = activeStyle;
            btnList.style.cssText = inactiveStyle;
        }
    };

    var searchInput = document.getElementById('search-project');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            searchQuery = this.value.toLowerCase().trim();
            applyFilters();
        });
    }
})();

/* ── Sidebar Controls ────────────────────────────────────────────────────── */
function toggleSidebar() {
    var sidebar   = document.getElementById('portal-sidebar');
    var icon      = document.getElementById('sidebar-toggle-icon');
    var expanded  = document.getElementById('psb-user-expanded');
    var collapsed = document.getElementById('psb-user-collapsed');
    sidebar.classList.toggle('collapsed');
    var isCollapsed = sidebar.classList.contains('collapsed');
    icon.style.transform = isCollapsed ? 'rotate(180deg)' : '';
    if (expanded)  expanded.style.display  = isCollapsed ? 'none'  : 'flex';
    if (collapsed) collapsed.style.display = isCollapsed ? 'flex'  : 'none';
}

function switchSection(sectionId, btn) {
    document.querySelectorAll('.portal-section').forEach(function (s) { s.style.display = 'none'; });
    var target = document.getElementById('section-' + sectionId);
    if (target) target.style.display = '';
    document.querySelectorAll('.psb-item').forEach(function (b) { b.classList.remove('psb-active'); });
    btn.classList.add('psb-active');
}

// Pause when tab hidden, resume when visible
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(pollTimer);
        pollTimer = null;
    } else {
        poll();
        pollTimer = setInterval(poll, POLL_INTERVAL);
    }
});

// Start polling after page is fully loaded
window.addEventListener('load', () => {
    setBadge('live'); // optimistic: page data is fresh on load
    pollTimer = setInterval(poll, POLL_INTERVAL);
});
</script>
</body>
</html>
