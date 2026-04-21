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
$adminRole = $_SESSION['admin_role'] ?? 'admin';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);

$activeSection = $_GET['section'] ?? 'dashboard';

// ── 1. Action Handlers (POST & Export) ──────────────────────────────────────
require_once __DIR__ . '/actions/portal_handlers.php';

$idSearch = $_GET['id_search'] ?? '';

require_once __DIR__ . '/actions/identity_actions.php';
require_once __DIR__ . '/queries/identity_queries.php';

/**
 * (0c) GIT PULL LOG — ดึงประวัติการ pull ล่าสุด 30 รายการ
 */
$gitPullLogs = [];
try {
    $gitPullLogs = $pdo->query(
        "SELECT triggered_by, status, message, detail, created_at
         FROM sys_git_pull_log
         ORDER BY created_at DESC
         LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ตารางอาจยังไม่มี (ยังไม่เคยกด pull ครั้งแรก) — ปล่อยผ่าน
}

/**
 * (0d) MAINTENANCE DATA — สำหรับ Settings Section
 */
$mFile = __DIR__ . '/../config/maintenance.json';
$mData = (file_exists($mFile) && ($d = json_decode(file_get_contents($mFile), true))) ? $d : [];
$mProjects = [
    [
        'key' => 'e_campaign',
        'title' => 'e-Campaign',
        'desc' => 'ระบบจองและลงทะเบียนกิจกรรมสำหรับ User',
        'icon' => 'fa-bullhorn',
        'icon_color' => '#2563eb',
        'icon_bg' => '#eff6ff',
    ],
    [
        'key' => 'e_borrow',
        'title' => 'e-Borrow & Inventory',
        'desc' => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์',
        'icon' => 'fa-toolbox',
        'icon_color' => '#475569',
        'icon_bg' => '#f1f5f9',
    ],
];
$allOnline = true;
foreach ($mProjects as $p) {
    if (($mData[$p['key']] ?? true) === false) {
        $allOnline = false;
        break;
    }
}

/**
 * (1) LIVE DATA & ROBUST STATS
 * ดึงสถิจริง พร้อมระบบป้องกันถ้าตารางในอนาคตยังไม่พร้อม
 */
$kpis = [
    'users' => 0,
    'camps' => 0,
    'borrows' => 0,
    'logs' => 0,
    'total_quota' => 0,
    'used_quota' => 0,
    'booking_rate' => 0,
];

try {
    $kpis['users'] = (int) $pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();
    $kpis['camps'] = (int) $pdo->query("SELECT COUNT(*) FROM camp_list WHERE status = 'active'")->fetchColumn();

    // Quota & booking rate (e-Campaign)
    $quotaRow = $pdo->query("
        SELECT COALESCE(SUM(c.total_capacity), 0) AS total_quota,
               (SELECT COUNT(*) FROM camp_bookings WHERE status IN ('booked','confirmed')) AS used_quota
        FROM camp_list c WHERE c.status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    $kpis['total_quota'] = (int) ($quotaRow['total_quota'] ?? 0);
    $kpis['used_quota'] = (int) ($quotaRow['used_quota'] ?? 0);
    $kpis['booking_rate'] = $kpis['total_quota'] > 0
        ? (int) round($kpis['used_quota'] / $kpis['total_quota'] * 100)
        : 0;

    // Equipment borrows (optional module)
    if ($pdo->query("SHOW TABLES LIKE 'borrow_records'")->rowCount() > 0) {
        $kpis['borrows'] = (int) $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE approval_status = 'pending'")->fetchColumn();
    }

    // Activity logs count (optional module)
    if ($pdo->query("SHOW TABLES LIKE 'activity_logs'")->rowCount() > 0) {
        $kpis['logs'] = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
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
        'id' => 'identity_governance',
        'title' => 'Identity & Governance',
        'description' => 'ศูนย์กลางจัดการข้อมูลผู้ใช้งานและควบคุมสิทธิ์การเข้าถึงระบบสำหรับเจ้าหน้าที่และแอดมินระดับสูง',
        'icon' => 'fa-id-card-clip',
        'bg_color' => 'bg-amber-50',
        'icon_color' => 'text-amber-500',
        'border_color' => 'border-amber-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges' => ['Central DB', 'Security Hub'],
        'actions' => array_filter([
            ['label' => 'Search Users', 'url' => 'users.php?layout=none', 'primary' => false],
            $adminRole === 'superadmin'
            ? ['label' => 'Manage Admins', 'url' => 'manage_admins.php?layout=none', 'primary' => true]
            : null,
        ])
    ],
    [
        'id' => 'e_campaign',
        'title' => 'e-Campaign',
        'description' => 'ระบบจัดการแคมเปญ งานอบรม งานสแกนและการลงทะเบียนเข้าร่วมกิจกรรมแบบ Real-time',
        'icon' => 'fa-bullhorn',
        'bg_color' => 'bg-blue-50',
        'icon_color' => 'text-blue-600',
        'border_color' => 'border-blue-100',
        'allowed_roles' => ['admin', 'superadmin', 'editor'],
        'staff_visible' => true,
        'badges' => ['Campaigns', 'Activity'],
        'actions' => [
            ['label' => 'Launch Campaign Manager', 'url' => '../admin/index.php', 'primary' => true],
        ]
    ],
    [
        'id' => 'staff_checkin',
        'title' => 'Staff Check-in Scanner',
        'description' => 'ระบบสแกน QR Code เพื่อเช็คอินผู้เข้าร่วมกิจกรรม ใช้งานผ่านกล้องมือถือหรือเว็บแคม',
        'icon' => 'fa-qrcode',
        'bg_color' => 'bg-cyan-50',
        'icon_color' => 'text-cyan-600',
        'border_color' => 'border-cyan-100',
        'allowed_roles' => ['admin', 'superadmin', 'editor'],
        'staff_visible' => true,
        'badges' => ['QR Scan', 'Check-in'],
        'actions' => [
            ['label' => 'เปิดระบบสแกน', 'url' => '../staff/index.php', 'primary' => true],
        ]
    ],
    [
        'id' => 'e_borrow',
        'title' => 'e-Borrow & Inventory',
        'description' => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์และเวชภัณฑ์ (Archive Support) จัดการสต็อกและพัสดุกลาง',
        'icon' => 'fa-toolbox',
        'bg_color' => 'bg-slate-100',
        'icon_color' => 'text-slate-700',
        'border_color' => 'border-slate-200',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges' => ['Inventory', 'Asset Tracking'],
        'actions' => [
            ['label' => 'Open System', 'url' => '../e_Borrow/admin/index.php', 'primary' => true],
        ]
    ],
    [
        'id' => 'system_logs',
        'title' => 'System Logs',
        'description' => 'ติดตาม Error Log และ Activity Log ของระบบแบบ Real-time เพื่อตรวจสอบและแก้ไขปัญหาได้ทันที',
        'icon' => 'fa-bug',
        'bg_color' => 'bg-red-50',
        'icon_color' => 'text-red-500',
        'border_color' => 'border-red-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges' => ['Monitoring', 'Debug'],
        'actions' => [
            ['label' => 'Error Logs', 'url' => 'javascript:switchSection(\'error_logs\', document.querySelector(\'[data-section=error_logs]\'))', 'primary' => true],
            ['label' => 'Activity Logs', 'url' => 'javascript:switchSection(\'activity_logs\', document.querySelector(\'[data-section=activity_logs]\'))', 'primary' => false],
        ]
    ],

    [
        'id' => 'insurance_sync',
        'title' => 'Insurance Sync Hub',
        'description' => 'ศูนย์กลางอัปเดตสิทธิ์ประกัน — นำเข้า CSV จากสำนักทะเบียน ตรวจสอบ Dry Run และจัดการสมาชิก Active/Inactive',
        'icon' => 'fa-shield-halved',
        'bg_color' => 'bg-indigo-50',
        'icon_color' => 'text-indigo-600',
        'border_color' => 'border-indigo-100',
        'allowed_roles' => ['admin', 'superadmin', 'editor'],
        'badges' => ['Insurance', 'Sync'],
        'actions' => [
            ['label' => 'Open Insurance Sync Hub', 'url' => 'insurance_sync.php', 'primary' => true],
        ]
    ],

    /**
     * ตัวอย่างการเพิ่มโปรเจกต์ในอนาคต:
     * เพียงแค่ก๊อปปี้บล็อกนี้แล้วเปลี่ยน URL/Icon ระบบจะวาดหน้า Layout ให้เองทันที
     */
    [
        'id' => 'future_app',
        'title' => 'Upcoming Project...',
        'description' => 'ระบบใหม่ที่กำลังอยู่ในระหว่างการพัฒนา เพื่อเสริมสร้างศักยภาพการจัดการข้อมูลในอนาคต',
        'icon' => 'fa-plus-circle',
        'bg_color' => 'bg-gray-50',
        'icon_color' => 'text-gray-300',
        'border_color' => 'border-gray-100',
        'allowed_roles' => ['superadmin'],
        'badges' => ['Dev Stage'],
        'actions' => [
            ['label' => 'No actions yet', 'url' => '#', 'primary' => false],
        ]
    ]
];

// Category assignments for filter tabs
$categoryMap = [
    'identity_governance' => 'core',
    'e_campaign' => 'core',
    'e_borrow' => 'core',
    'insurance_sync' => 'core',
    'system_logs' => 'tools',
    'admin_tool' => 'tools',
    'future_app' => 'dev',
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
} catch (PDOException $e) { /* silent */
}

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> - Central Intelligence HUB</title>
    <link rel="icon" href="../favicon.ico">

    <!-- UI Framework & Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@100;300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ── Toggle Switch (Maintenance Mode) ──────────────────────────────── */
        .toggle-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .toggle {
            position: relative;
            width: 46px;
            height: 24px;
            cursor: pointer;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .toggle-track {
            position: absolute;
            inset: 0;
            background: #e2e8f0;
            border-radius: 99px;
            transition: background .25s cubic-bezier(.25, 1, .5, 1);
        }

        .toggle input:checked~.toggle-track {
            background: #2e9e63;
        }

        .toggle-thumb {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 18px;
            height: 18px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .15);
            transition: transform .3s cubic-bezier(.25, 1, .5, 1);
        }

        .toggle input:checked~.toggle-thumb {
            transform: translateX(22px);
        }

        @keyframes toggleRingOn {
            0% {
                box-shadow: 0 0 0 0 rgba(46, 158, 99, .4);
            }

            50% {
                box-shadow: 0 0 0 6px rgba(46, 158, 99, .15);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(46, 158, 99, .0);
            }
        }

        .toggle-ring-on {
            animation: toggleRingOn .45s cubic-bezier(.25, 1, .5, 1) both;
        }

        /* ── Status badge ──────────────────────────────────────────────────── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 9px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-badge.on {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .status-badge.off {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-badge.on .status-dot {
            background: #22c55e;
            animation: livePulse 1.5s infinite;
        }

        .status-badge.off .status-dot {
            background: #ef4444;
        }

        @keyframes badgePop {
            0% {
                opacity: .35;
                transform: scale(.82);
            }

            60% {
                transform: scale(1.07);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .badge-pop {
            animation: badgePop .3s cubic-bezier(.25, 1, .5, 1) both;
        }

        #status-banner[data-state="ok"] {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        /* ── Identity Tabs ──────────────────────────────────────────────────── */
        .id-tab {
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 800;
            color: #64748b;
            background: transparent;
            border: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all .2s;
        }

        .id-tab.active {
            color: #2e9e63;
            border-bottom-color: #2e9e63;
        }

        .id-panel {
            display: none;
            animation: idFadeIn .3s ease;
        }

        .id-panel.active {
            display: block;
        }

        @keyframes idFadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Premium Form Inputs ────────────────────────────────────────────── */
        .premium-input {
            width: 100%;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            outline: none;
            transition: all .2s;
        }

        .premium-input:focus {
            background: #fff;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .premium-role-card {
            background: #fff;
            border: 1.5px solid #f1f5f9;
            border-radius: 20px;
            overflow: hidden;
            transition: all .2s;
        }

        .premium-role-card.blue {
            border-color: #dbeafe;
            background: #f0f7ff;
        }

        .premium-role-card.orange {
            border-color: #ffedd5;
            background: #fffaf5;
        }

        .role-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
    </style>
    <script>
        /* ── Critical Navigation Functions (Defined in Head for early availability) ── */
        window.toggleSidebar = function () {
            var sidebar = document.getElementById('portal-sidebar');
            var icon = document.getElementById('sidebar-toggle-icon');
            var expanded = document.getElementById('psb-user-expanded');
            var collapsed = document.getElementById('psb-user-collapsed');
            if (!sidebar) return;
            sidebar.classList.toggle('collapsed');
            var isCollapsed = sidebar.classList.contains('collapsed');
            if (icon) icon.style.transform = isCollapsed ? 'rotate(180deg)' : '';
            if (expanded) expanded.style.display = isCollapsed ? 'none' : 'flex';
            if (collapsed) collapsed.style.display = isCollapsed ? 'flex' : 'none';
        };

        window.switchSection = function (sectionId, btn) {
            document.querySelectorAll('.portal-section').forEach(function (s) { s.style.display = 'none'; });
            var target = document.getElementById('section-' + sectionId);
            if (target) target.style.display = '';
            document.querySelectorAll('.psb-item').forEach(function (b) { b.classList.remove('psb-active'); });
            
            // If btn not provided, try to find it in sidebar
            if (!btn) {
                btn = document.querySelector('.psb-item[data-section="' + sectionId + '"]');
            }
            if (btn) btn.classList.add('psb-active');
            
            var url = new URL(window.location.href);
            url.searchParams.set('section', sectionId);
            ['page','el_search','el_level','el_date','el_source','al_q','eml_q','eml_type','eml_status','cd_search'].forEach(function(k){ url.searchParams.delete(k); });
            history.pushState({section: sectionId}, '', url.toString());
        };
    </script>
</head>

<body class="font-sans text-gray-800 bg-[#f4f7f5]" style="height:100vh;overflow:hidden;display:flex;flex-direction:row">

    <!-- ── Collapsible Sidebar ── -->
    <nav id="portal-sidebar">
        <!-- Brand / Toggle -->
        <div
            style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f0faf4;min-height:60px">
            <div class="flex items-center gap-2" id="psb-brand-text">
                <div class="brand-icon" style="width:30px;height:30px;font-size:12px;border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? 'background:transparent;' : '' ?>">
                    <?php if (defined('SITE_LOGO') && SITE_LOGO !== ''): ?>
                        <img src="../<?= htmlspecialchars(SITE_LOGO) ?>" style="width:100%;height:100%;object-fit:contain;" alt="Logo">
                    <?php else: ?>
                        <i class="fa-solid fa-heart"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="font-black text-slate-800 text-[15px] leading-tight tracking-tight"><?= htmlspecialchars(SITE_NAME ?: 'Central HUB') ?></div>
                </div>
            </div>
            <button onclick="toggleSidebar()" id="sidebar-toggle" title="Toggle sidebar"
                style="width:28px;height:28px;border-radius:8px;border:none;cursor:pointer;background:#f0faf4;color:#2e9e63;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s">
                <i id="sidebar-toggle-icon" class="fa-solid fa-chevron-left"
                    style="font-size:11px;transition:transform .3s"></i>
            </button>
        </div>

        <!-- Nav items -->
        <div style="padding:10px;flex:1;overflow:hidden">
            <button class="psb-item psb-active" data-section="dashboard" onclick="switchSection('dashboard',this)">
                <div class="psb-icon"><i class="fa-solid fa-chart-pie"></i></div>
                <span class="psb-label">Dashboard</span>
            </button>
            <button class="psb-item" data-section="ai_assistant" onclick="switchSection('ai_assistant',this)">
                <div class="psb-icon"><i class="fa-solid fa-wand-magic-sparkles" style="color:#8b5cf6"></i></div>
                <span class="psb-label" style="color:#7c3aed;font-weight:900">AI Assistant</span>
            </button>
            <button class="psb-item" data-section="identity" onclick="switchSection('identity',this)">
                <div class="psb-icon"><i class="fa-solid fa-id-card-clip"></i></div>
                <span class="psb-label">Identity & Governance</span>
            </button>
            <button class="psb-item" data-section="activity_logs" onclick="switchSection('activity_logs',this)">
                <div class="psb-icon"><i class="fa-solid fa-file-lines"></i></div>
                <span class="psb-label">Activity Logs</span>
            </button>
            <button class="psb-item" data-section="error_logs" onclick="switchSection('error_logs',this)">
                <div class="psb-icon"><i class="fa-solid fa-bug"></i></div>
                <span class="psb-label">Error Logs</span>
            </button>
            <button class="psb-item" data-section="settings" onclick="switchSection('settings',this)">
                <div class="psb-icon"><i class="fa-solid fa-gear"></i></div>
                <span class="psb-label">Settings</span>
            </button>
        </div>
    </nav>

    <!-- ══════════════════ APP SHELL (Header + Main) ══════════════════ -->
    <div id="app-shell"
        style="display: grid !important; grid-template-rows: auto 1fr !important; flex: 1 !important; min-width: 0 !important; overflow: hidden !important; background: #f4f7f5;">

        <!-- ══════════════════ HEADER ══════════════════ -->
        <header class="portal-header au" style="background: #ffffff !important; border-bottom: 1.5px solid #e2e8f0 !important; box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; width: 100% !important; display: block !important; position: relative !important; z-index: 50 !important;">
            <div class="w-full px-5 sm:px-8 py-3 flex items-center justify-between gap-4" style="min-height:60px">

                <!-- Left/Center: Global Search -->
                <div style="flex: 1; display: flex; justify-content: flex-start;">
                    <div class="relative group w-full max-w-[400px]">
                        <input type="text" placeholder="ค้นหาเมนู หรือแคมเปญ"
                            class="w-full pl-5 pr-10 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px] font-bold text-slate-800 outline-none focus:bg-white focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition-all font-prompt">
                        <button
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-emerald-600 transition-colors flex items-center justify-center">
                            <i class="fa-solid fa-magnifying-glass text-sm"></i>
                        </button>
                    </div>
                </div>

                <!-- Right: user + logout -->
                <div class="flex items-center gap-3 sm:gap-4">

                    <?php if (isset($adminRole) && $adminRole === 'superadmin'): ?>
                        <!-- Git Pull Button -->
                        <button id="btnGitPull" onclick="triggerGitPull()" title="Pull โค้ดล่าสุดจาก Git"
                            class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-emerald-100 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 hover:text-emerald-700 font-bold text-[11px] transition-colors cursor-pointer">
                            <i class="fa-solid fa-code-branch"></i>
                            <span>Git Pull</span>
                        </button>
                    <?php endif; ?>

                    <!-- Live connection badge -->
                    <div id="ws-badge" title="Real-time connection status"
                        style="display:flex;align-items:center;gap:5px;padding:4px 8px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border:1px solid #c7e8d5;background:#f0fdf4;color:#16a34a;transition:all .3s">
                        <span id="ws-dot"
                            style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;animation:livePulse 1.6s infinite"></span>
                        <span id="ws-label" class="hidden sm:inline">Live</span>
                    </div>

                    <!-- Dark Mode Toggle Button -->
                    <button id="darkModeToggle" onclick="toggleDarkMode()" title="สลับโหมดมืด/สว่าง"
                        class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900 transition-colors shadow-sm dark-mode-btn">
                        <i class="fa-solid fa-moon"></i>
                    </button>

                    <!-- Divider -->
                    <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>

                    <!-- User Identity & Logout -->
                    <div class="flex items-center gap-2 sm:gap-3">
                        <div class="text-right hidden sm:block">
                            <div
                                class="text-[9px] font-extrabold uppercase tracking-widest text-slate-500 leading-none mb-1">
                                Admin</div>
                            <div class="text-[13px] font-black text-slate-900 leading-none">
                                <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?>
                            </div>
                        </div>
                        <div class="w-9 h-9 rounded-xl flex flex-shrink-0 items-center justify-center shadow-md shadow-emerald-500/20 text-sm"
                            style="background: linear-gradient(135deg, #2e9e63, #10b981); color:#fff;">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <a href="../admin/auth/logout.php" title="ออกจากระบบ"
                            class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex flex-shrink-0 items-center justify-center hover:bg-rose-500 hover:text-white transition-colors border border-rose-100 ml-1">
                            <i class="fa-solid fa-power-off text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- ── Main Content ── -->
        <main id="portal-main" style="flex:1;overflow-y:auto;min-width:0;">

            <!-- ════════════ SECTION: DASHBOARD ════════════ -->
            <div id="section-dashboard" class="portal-section" style="<?= $activeSection==='dashboard'?'':'display:none;' ?>">
                <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

                    <!-- KPI COMPACT STRIP -->
                    <?php $borrowUrgent = $kpis['borrows'] > 0; ?>
                    <section class="au d1">
                        <div class="kpi-strip">

                            <!-- Users -->
                            <div class="kpi-stat">
                                <div class="kpi-stat-icon" style="background:#f0fdf4;color:#2e9e63">
                                    <i class="fa-solid fa-users"></i>
                                </div>
                                <div>
                                    <div class="kpi-stat-num" id="kpi-users" data-counter="<?= $kpis['users'] ?>">0
                                    </div>
                                    <div class="kpi-stat-label">บุคลากรและนักศึกษา</div>
                                </div>
                            </div>

                            <!-- Campaigns -->
                            <div class="kpi-stat">
                                <div class="kpi-stat-icon" style="background:#f0fdf4;color:#2e9e63">
                                    <i class="fa-solid fa-bullhorn"></i>
                                </div>
                                <div>
                                    <div style="display:flex;align-items:baseline;gap:7px">
                                        <span class="kpi-stat-num" id="kpi-camps"
                                            data-counter="<?= $kpis['camps'] ?>">0</span>
                                        <span
                                            style="font-size:10px;font-weight:800;color:#2e9e63;background:#f0fdf4;padding:1px 7px;border-radius:99px;border:1px solid #c7e8d5">Active</span>
                                    </div>
                                    <div class="kpi-stat-label">โควต้า <strong
                                            style="color:#0f172a;font-weight:900"><?= number_format($kpis['total_quota']) ?></strong>
                                        ที่นั่ง</div>
                                </div>
                            </div>

                            <!-- Borrows -->
                            <div class="kpi-stat">
                                <div class="kpi-stat-icon"
                                    style="background:<?= $borrowUrgent ? '#fff1f2' : '#f8fafc' ?>;color:<?= $borrowUrgent ? '#ef4444' : '#94a3b8' ?>">
                                    <i class="fa-solid fa-box-open"></i>
                                </div>
                                <div>
                                    <div style="display:flex;align-items:center;gap:7px">
                                        <span class="kpi-stat-num" id="kpi-borrows"
                                            data-counter="<?= $kpis['borrows'] ?>">0</span>
                                        <?php if ($borrowUrgent): ?>
                                            <span
                                                style="font-size:9px;font-weight:900;color:#fff;background:#ef4444;padding:2px 6px;border-radius:5px;letter-spacing:.04em">URGENT</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="kpi-stat-label" style="color:<?= $borrowUrgent ? '#ef4444' : '' ?>">
                                        <?= $borrowUrgent ? 'รอการตรวจสอบ' : 'ไม่มีรายการค้าง' ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Booking Rate -->
                            <div class="kpi-stat" style="flex:1.3">
                                <div class="kpi-stat-icon" style="background:#f5f3ff;color:#7c3aed">
                                    <i class="fa-solid fa-chart-pie"></i>
                                </div>
                                <div style="flex:1;min-width:0">
                                    <div style="display:flex;align-items:baseline;gap:2px">
                                        <span class="kpi-stat-num" id="kpi-rate"><?= $kpis['booking_rate'] ?></span>
                                        <span style="font-size:12px;font-weight:700;color:#94a3b8">%</span>
                                    </div>
                                    <div style="margin-top:6px">
                                        <div
                                            style="width:100%;background:#f1f5f9;border-radius:99px;height:3px;overflow:hidden">
                                            <div id="kpi-rate-bar"
                                                style="height:3px;border-radius:99px;width:<?= $kpis['booking_rate'] ?>%;background:#7c3aed;transition:width .7s ease">
                                            </div>
                                        </div>
                                        <div class="kpi-stat-label" style="margin-top:3px">
                                            <span id="kpi-used"><?= number_format($kpis['used_quota']) ?></span> / <span
                                                id="kpi-total-quota"><?= number_format($kpis['total_quota']) ?></span>
                                            ที่นั่ง
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </section>

                    <!-- MAIN GRID -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                        <!-- PROJECT CARDS (8/12) -->
                        <section class="lg:col-span-8 au d2">

                            <!-- Control Bar -->
                            <div style="margin-bottom:20px">
                                <div
                                    style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px">
                                    <div class="sec-title">Systems</div>

                                    <div style="display:flex;align-items:center;gap:10px">
                                        <!-- Search -->
                                        <div style="position:relative">
                                            <i class="fa-solid fa-magnifying-glass"
                                                style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none"></i>
                                            <input type="text" id="search-project" placeholder="ค้นหาระบบ..."
                                                style="padding:7px 12px 7px 30px;border:1.5px solid #d0ead9;border-radius:12px;font-size:12px;outline:none;width:180px;font-family:inherit;color:#374151;background:#fff;transition:border-color .2s,box-shadow .2s"
                                                onfocus="this.style.borderColor='#2e9e63';this.style.boxShadow='0 0 0 3px rgba(46,158,99,.1)'"
                                                onblur="this.style.borderColor='#d0ead9';this.style.boxShadow='none'">
                                        </div>
                                        <!-- View toggle -->
                                        <div
                                            style="display:flex;background:#f1f5f9;border-radius:10px;padding:3px;gap:2px">
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
                                    <button class="proj-tab active" data-filter="all"
                                        onclick="projSetFilter(this)">ทั้งหมด</button>
                                    <button class="proj-tab" data-filter="core" onclick="projSetFilter(this)">ระบบหลัก
                                        (Core)</button>
                                    <button class="proj-tab" data-filter="tools"
                                        onclick="projSetFilter(this)">เครื่องมือ (Tools)</button>
                                    <button class="proj-tab" data-filter="dev" onclick="projSetFilter(this)">กำลังพัฒนา
                                        (Dev Stage)</button>
                                </div>
                            </div>

                            <!-- Cards -->
                            <div id="project-container" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <?php $cardIdx = 0;
                                foreach ($projects as $proj):
                                    // Staff เห็นเฉพาะ tile ที่ staff_visible = true
                                    if ($isStaff && !($proj['staff_visible'] ?? false)) continue;
                                    // Admin/Superadmin กรองตาม allowed_roles ปกติ
                                    if (!$isStaff && !in_array($adminRole, $proj['allowed_roles'])) continue;
                                    $cardDelay = round(0.1 + $cardIdx * 0.12, 2);
                                    $cardIdx++;
                                    $cat = $categoryMap[$proj['id']] ?? 'core';
                                    $keywords = strtolower(implode(' ', $proj['badges']) . ' ' . $proj['title']);
                                    ?>
                                    <div class="proj-card" data-category="<?= $cat ?>"
                                        data-name="<?= htmlspecialchars(strtolower($proj['title'])) ?>"
                                        data-keywords="<?= htmlspecialchars($keywords) ?>"
                                        style="animation-delay:<?= $cardDelay ?>s">

                                        <div class="proj-card-header">
                                            <div
                                                class="proj-card-icon <?= $proj['bg_color'] ?> <?= $proj['icon_color'] ?> <?= $proj['border_color'] ?>">
                                                <i class="fa-solid <?= $proj['icon'] ?>"></i>
                                            </div>
                                            <div class="proj-card-badges">
                                                <?php foreach ($proj['badges'] as $b): ?>
                                                    <span class="proj-badge"><?= $b ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="proj-card-body">
                                            <h3 class="text-[15px] font-black text-gray-900 mb-1.5 leading-tight">
                                                <?= $proj['title'] ?>
                                            </h3>
                                            <p class="text-[12px] text-gray-500 leading-relaxed"><?= $proj['description'] ?>
                                            </p>
                                        </div>

                                        <div class="proj-card-actions">
                                            <?php foreach ($proj['actions'] as $act): ?>
                                                <a href="<?= $act['url'] ?>"
                                                    class="proj-action <?= $act['primary'] ? 'primary' : 'secondary' ?>">
                                                    <?php if ($act['primary']): ?><i
                                                            class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i><?php endif; ?>
                                                    <?= $act['label'] ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Empty state -->
                                <div id="proj-empty"
                                    style="display:none;grid-column:1/-1;padding:48px 24px;text-align:center">
                                    <i class="fa-solid fa-magnifying-glass"
                                        style="font-size:2rem;color:#cbd5e1;margin-bottom:12px;display:block"></i>
                                    <p style="font-size:13px;font-weight:700;color:#94a3b8">ไม่พบระบบที่ค้นหา</p>
                                    <p style="font-size:11px;color:#cbd5e1;margin-top:4px">
                                        ลองเปลี่ยนคำค้นหาหรือล้างตัวกรอง</p>
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
                                    <?php if ($recentActivity): ?>
                                        <?php foreach ($recentActivity as $log): ?>
                                            <div class="feed-item">
                                                <div class="feed-dot">
                                                    <i class="fa-solid fa-bolt text-[11px]"></i>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2 mb-0.5">
                                                        <span class="text-[10px] font-black uppercase tracking-wider truncate"
                                                            style="color:#2e9e63"><?= htmlspecialchars($log['action']) ?></span>
                                                        <span
                                                            class="text-[9px] text-gray-400 whitespace-nowrap"><?= date('d M H:i', strtotime($log['created_at'])) ?></span>
                                                    </div>
                                                    <p class="text-[12px] font-bold text-gray-800 leading-snug truncate">
                                                        <?= htmlspecialchars($log['admin_name'] ?? 'System') ?>
                                                    </p>
                                                    <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1">
                                                        <?= htmlspecialchars($log['description']) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="py-12 text-center text-gray-300">
                                            <i class="fa-solid fa-ghost text-3xl mb-2 block"></i>
                                            <p class="text-[11px] font-bold uppercase tracking-widest">No activity yet</p>
                                        </div>
                                    <?php endif; ?>
                                    <a href="javascript:switchSection('activity_logs', document.querySelector('[data-section=activity_logs]'))"
                                        class="flex items-center justify-center gap-1.5 py-3 text-[10px] font-black uppercase tracking-wider transition-colors border-t border-gray-50 hover:bg-green-50"
                                        style="color:#2e9e63">
                                        View all logs <i class="fa-solid fa-chevron-right text-[9px]"></i>
                                    </a>
                                </div>
                            </div>

                            <!-- Quick Shortcuts -->
                            <div class="shortcut-card au d4">
                                <div class="text-xs font-black uppercase tracking-widest opacity-70 mb-1">Quick Access
                                </div>
                                <div class="font-black text-lg mb-4">System Shortcuts</div>
                                <div class="space-y-2">
                                    <a href="users.php" class="shortcut-link">
                                        <i class="fa-solid fa-users"></i> Users Center
                                    </a>
                                    <a href="../admin/campaigns.php" class="shortcut-link">
                                        <i class="fa-solid fa-bullhorn"></i> Campaign Manager
                                    </a>
                                    <a href="javascript:switchSection('error_logs', document.querySelector('[data-section=error_logs]'))"
                                        class="shortcut-link">
                                        <i class="fa-solid fa-bug"></i> Error Logs
                                    </a>
                                </div>
                                <i
                                    class="fa-solid fa-screwdriver-wrench absolute -bottom-6 -right-6 text-[6rem] opacity-5 rotate-12 pointer-events-none"></i>
                            </div>

                        </aside>
                    </div>

                    <!-- FOOTER -->
                    <footer class="pt-6 pb-4 text-center">
                        <div class="flex items-center justify-center gap-2 opacity-25">
                            <i class="fa-solid fa-shield-halved" style="color:#2e9e63"></i>
                            <span class="text-[10px] font-black uppercase tracking-[.4em]">Central Command v3.0 · RSU
                                Medical Clinic</span>
                        </div>
                    </footer>

                </div><!-- /section-dashboard inner -->
            </div><!-- /section-dashboard -->

            <!-- ════════════ SECTION: IDENTITY & GOVERNANCE ════════════ -->
            <div id="section-identity" class="portal-section" style="display:none">
                <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8">

                    <?php if ($idSaved): ?>
                        <div id="id-toast"
                            style="display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#15803d">
                            <i class="fa-solid fa-circle-check"></i> บันทึกข้อมูลสำเร็จ
                        </div>
                    <?php endif; ?>
                    <?php if ($idError): ?>
                        <div
                            style="display:flex;align-items:center;gap:10px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#dc2626">
                            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($idError) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Header row -->
                    <div
                        style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
                        <div>
                            <div class="sec-title" style="margin-bottom:2px">Identity &amp; Governance</div>
                            <p style="font-size:13px;color:#64748b">ศูนย์กลางจัดการผู้ใช้งาน สิทธิ์การเข้าถึง
                                และความปลอดภัยของระบบ</p>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center">
                            <?php if ($adminRole === 'superadmin'): ?>
                                <button id="id-btn-add-admin" onclick="openAddAdminModal()"
                                    style="display:none;background:#2e9e63;color:#fff;padding:8px 16px;border-radius:11px;font-size:12px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.25)">
                                    <i class="fa-solid fa-user-plus mr-1"></i> เพิ่ม Admin
                                </button>
                                <button id="id-btn-add-staff" onclick="openAddStaffModal()"
                                    style="display:none;background:#2563eb;color:#fff;padding:8px 16px;border-radius:11px;font-size:12px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,.25)">
                                    <i class="fa-solid fa-id-badge mr-1"></i> เพิ่ม Staff
                                </button>
                            <?php endif; ?>
                            <div id="id-search-wrap" style="position:relative">
                                <i class="fa-solid fa-magnifying-glass"
                                    style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none"></i>
                                <input id="id-search-input" type="text" placeholder="ค้นหาข้อมูล..."
                                    style="padding:8px 12px 8px 30px;border:1.5px solid #d0ead9;border-radius:12px;font-size:12px;font-family:inherit;outline:none;width:200px;transition:border-color .2s"
                                    oninput="idUniversalFilter(this.value)">
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div
                        style="display:flex;gap:6px;margin-bottom:20px;padding-bottom:2px;border-bottom:1px solid #f1f5f9">
                        <button class="id-tab active" data-tab="users" onclick="switchIdTab('users',this)">System Users
                            (<?= count($idUsers) ?>)</button>
                        <?php if ($adminRole === 'superadmin'): ?>
                            <button class="id-tab" data-tab="admins" onclick="switchIdTab('admins',this)">System Admins
                                (<?= count($allAdmins) ?>)</button>
                            <button class="id-tab" data-tab="staff" onclick="switchIdTab('staff',this)">Staff
                                (<?= count($allStaff) ?>)</button>
                        <?php endif; ?>
                    </div>

                    <!-- PANEL: Master Users -->
                    <div id="id-panel-users" class="id-panel active">
                        <?php
                        $statsUserType = ['student' => 0, 'staff' => 0, 'other' => 0];
                        foreach ($idUsers as $u) {
                            $statKey = in_array($u['status'], ['student', 'staff']) ? $u['status'] : 'other';
                            $statsUserType[$statKey]++;
                        }
                        $totalUsersCalc = count($idUsers);
                        $pctStudent = $totalUsersCalc > 0 ? round(($statsUserType['student'] / $totalUsersCalc) * 100) : 0;
                        $pctStaff = $totalUsersCalc > 0 ? round(($statsUserType['staff'] / $totalUsersCalc) * 100) : 0;
                        $pctOther = $totalUsersCalc > 0 ? (100 - $pctStudent - $pctStaff) : 0;
                        ?>
                        
                        <!-- Statistics Bar -->
                        <div style="background:#fff;border-radius:20px;padding:20px;margin-bottom:20px;border:1.5px solid #e2e8f0;box-shadow:0 4px 15px rgba(0,0,0,0.02)">
                            <div style="font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:15px;display:flex;align-items:center;gap:6px;">
                                <i class="fa-solid fa-chart-pie" style="color:#2e9e63"></i> สัดส่วนประเภทผู้ใช้งาน
                            </div>
                            
                            <!-- Visual Bar -->
                            <div style="width:100%;height:14px;border-radius:99px;background:#f1f5f9;display:flex;overflow:hidden;margin-bottom:12px;box-shadow:inset 0 2px 4px rgba(0,0,0,0.04)">
                                <?php if($totalUsersCalc > 0): ?>
                                    <div style="width:<?= $pctStudent ?>%;background:linear-gradient(90deg, #3b82f6, #60a5fa);transition:width 1s;border-right:2px solid #fff" title="นักศึกษา: <?= number_format($statsUserType['student']) ?> คน"></div>
                                    <div style="width:<?= $pctStaff ?>%;background:linear-gradient(90deg, #f59e0b, #fbbf24);transition:width 1s;border-right:2px solid #fff" title="บุคลากร: <?= number_format($statsUserType['staff']) ?> คน"></div>
                                    <div style="width:<?= $pctOther ?>%;background:linear-gradient(90deg, #8b5cf6, #a78bfa);transition:width 1s" title="บุคคลทั่วไป/อื่นๆ: <?= number_format($statsUserType['other']) ?> คน"></div>
                                <?php else: ?>
                                    <div style="width:100%;background:#e2e8f0;"></div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Legend -->
                            <div style="display:flex;flex-wrap:wrap;gap:20px;font-size:12px;font-weight:700">
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="width:12px;height:12px;border-radius:4px;background:#3b82f6;box-shadow:0 2px 4px rgba(59,130,246,0.3)"></div>
                                    <span style="color:#334155">นักศึกษา <span style="opacity:0.6;font-size:11px">(<?= $pctStudent ?>%)</span></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="width:12px;height:12px;border-radius:4px;background:#f59e0b;box-shadow:0 2px 4px rgba(245,158,11,0.3)"></div>
                                    <span style="color:#334155">บุคลากร <span style="opacity:0.6;font-size:11px">(<?= $pctStaff ?>%)</span></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="width:12px;height:12px;border-radius:4px;background:#8b5cf6;box-shadow:0 2px 4px rgba(139,92,246,0.3)"></div>
                                    <span style="color:#334155">บุคคลทั่วไป/อื่นๆ <span style="opacity:0.6;font-size:11px">(<?= $pctOther ?>%)</span></span>
                                </div>
                            </div>
                        </div>

                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div
                                style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div
                                    style="width:4px;height:18px;background:linear-gradient(180deg,#6366f1,#a5b4fc);border-radius:99px;flex-shrink:0">
                                </div>
                                <span
                                    style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Master
                                    Records</span>
                                <span
                                    style="margin-left:auto;font-size:11px;font-weight:700;color:#94a3b8"><?= number_format(count($idUsers)) ?>
                                    รายการ</span>
                            </div>
                            <div style="overflow-x:auto" id="idTableWrap">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idUserTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                ผู้ใช้งาน</th>
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                ติดต่อ</th>
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                วันที่ลงทะเบียน</th>
                                            <th
                                                style="padding:12px 20px;text-align:right;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idUserTbody">
                                        <?php foreach ($idUsers as $u):
                                            $statusTH = match ($u['status']) { 'student' => 'นักศึกษา', 'staff' => 'บุคลากร', 'other' => $u['status_other'] ?: 'บุคคลทั่วไป', default => 'ไม่ระบุ'};
                                            $initial = mb_substr($u['full_name'], 0, 1);
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-user-row">
                                                <td style="padding:14px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div
                                                            style="width:38px;height:38px;border-radius:11px;background:#f1f5f9;color:#64748b;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">
                                                            <?= htmlspecialchars($initial) ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:750;color:#0f172a">
                                                                <?= htmlspecialchars($u['full_name']) ?>
                                                            </div>
                                                            <div
                                                                style="font-size:10px;color:#94a3b8;font-weight:700;margin-top:2px">
                                                                #<?= htmlspecialchars($u['student_personnel_id'] ?? '—') ?>
                                                                · <?= htmlspecialchars($statusTH) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px">
                                                    <div style="font-size:12px;color:#374151;font-weight:600">
                                                        <?= htmlspecialchars($u['phone_number'] ?: '—') ?>
                                                    </div>
                                                    <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                                                        <?= htmlspecialchars($u['email'] ?? '—') ?>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px">
                                                    <div style="font-size:12px;font-weight:700;color:#374151">
                                                        <?= date('d M Y', strtotime($u['created_at'])) ?>
                                                    </div>
                                                    <div style="font-size:10px;color:#94a3b8;margin-top:1px">
                                                        <?= date('H:i', strtotime($u['created_at'])) ?>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button
                                                            onclick='idOpenView(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                                            title="ดูข้อมูล">
                                                            <i class="fa-solid fa-eye" style="font-size:11px"></i>
                                                        </button>
                                                        <button
                                                            onclick='idOpenEdit(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                                            title="แก้ไข">
                                                            <i class="fa-solid fa-pen" style="font-size:11px"></i>
                                                        </button>
                                                        <a href="../admin/user_history.php?id=<?= $u['id'] ?>"
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s"
                                                            onmouseover="this.style.background='#fffbeb';this.style.color='#d97706'"
                                                            onmouseout="this.style.background='#fff';this.style.color='#64748b'"
                                                            title="ประวัติการใช้งาน">
                                                            <i class="fa-solid fa-clock-rotate-left"
                                                                style="font-size:11px"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination bar -->
                            <div
                                style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid #f1f5f9">
                                <div style="display:flex;align-items:center;gap:6px">
                                    <span style="font-size:11px;font-weight:700;color:#94a3b8">แสดง</span>
                                    <?php foreach ([25, 50, 100] as $sz): ?>
                                        <button class="id-ps-btn" data-size="<?= $sz ?>" onclick="idSetPageSize(<?= $sz ?>)"
                                            style="padding:5px 13px;border-radius:8px;border:1.5px solid #e2e8f0;background:<?= $sz === 25 ? '#2e9e63' : '#f8fafc' ?>;color:<?= $sz === 25 ? '#fff' : '#374151' ?>;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s">
                                            <?= $sz ?>
                                        </button>
                                    <?php endforeach; ?>
                                    <span style="font-size:11px;font-weight:700;color:#94a3b8">รายการ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span id="id-page-info"
                                        style="font-size:12px;font-weight:700;color:#64748b;min-width:120px;text-align:center"></span>
                                    <button id="id-page-prev" onclick="idPrevPage()"
                                        style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;cursor:pointer;font-size:15px;font-weight:700;transition:all .15s;line-height:1"
                                        disabled>‹</button>
                                    <button id="id-page-next" onclick="idNextPage()"
                                        style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;cursor:pointer;font-size:15px;font-weight:700;transition:all .15s;line-height:1">›</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: System Admins -->
                    <div id="id-panel-admins" class="id-panel">
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div
                                style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div style="width:4px;height:18px;background:#2e9e63;border-radius:99px;flex-shrink:0">
                                </div>
                                <span
                                    style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Admin
                                    Accounts</span>
                            </div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idAdminTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                Admin Detail</th>
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                Privileges</th>
                                            <th
                                                style="padding:12px 20px;text-align:right;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idAdminTbody">
                                        <?php foreach ($allAdmins as $adm):
                                            $roleClass = match ($adm['role']) { 'superadmin' => 'bg-purple-100 text-purple-700', 'editor' => 'bg-rose-100 text-rose-700', default => 'bg-blue-100 text-blue-700'};
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-admin-row">
                                                <td style="padding:14px 20px">
                                                    <div style="font-weight:750;color:#0f172a">
                                                        <?= htmlspecialchars($adm['full_name']) ?>
                                                    </div>
                                                    <div style="font-size:11px;color:#94a3b8">
                                                        @<?= htmlspecialchars($adm['username']) ?> ·
                                                        <?= htmlspecialchars($adm['email']) ?>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px">
                                                    <span
                                                        style="font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;text-transform:uppercase"
                                                        class="<?= $roleClass ?>"><?= htmlspecialchars($adm['role']) ?></span>
                                                </td>
                                                <td style="padding:14px 20px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button onclick='openEditAdminModal(<?= json_encode($adm) ?>)'
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer"><i
                                                                class="fa-solid fa-pen"></i></button>
                                                        <?php if ($adm['id'] != $_SESSION['admin_id']): ?>
                                                            <form method="POST" style="display:inline"
                                                                onsubmit="return confirm('ยืนยันการลบ Admin ท่านนี้?')">
                                                                <input type="hidden" name="action" value="delete_admin">
                                                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                                                <?php csrf_field(); ?>
                                                                <button type="submit"
                                                                    style="width:32px;height:32px;border-radius:8px;border:1px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer"><i
                                                                        class="fa-solid fa-trash"></i></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: Staff -->
                    <div id="id-panel-staff" class="id-panel">
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div
                                style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div style="width:4px;height:18px;background:#2563eb;border-radius:99px;flex-shrink:0">
                                </div>
                                <span
                                    style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Staff
                                    Roster (e-Borrow)</span>
                            </div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idStaffTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                Staff</th>
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                System Access</th>
                                            <th
                                                style="padding:12px 20px;text-align:right;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idStaffTbody">
                                        <?php foreach ($allStaff as $st):
                                            $isActive = ($st['account_status'] ?? 'active') === 'active';
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-staff-row">
                                                <td style="padding:14px 20px">
                                                    <div style="font-weight:750;color:#0f172a">
                                                        <?= htmlspecialchars($st['full_name']) ?>
                                                    </div>
                                                    <div style="font-size:11px;color:#94a3b8">
                                                        @<?= htmlspecialchars($st['username']) ?> · <span
                                                            style="font-weight:700"><?= htmlspecialchars($st['role']) ?></span>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px">
                                                    <div style="display:flex;gap:4px">
                                                        <span title="e-Borrow Status"
                                                            style="font-size:9px;font-weight:800;padding:2px 6px;border-radius:5px;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa">e-Borrow</span>
                                                        <?php if ($st['access_ecampaign']): ?>
                                                            <span title="e-Campaign Role"
                                                                style="font-size:9px;font-weight:800;padding:2px 6px;border-radius:5px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe">e-Camp
                                                                (<?= $st['ecampaign_role'] ?>)</span>
                                                        <?php endif; ?>
                                                        <span
                                                            style="font-size:9px;font-weight:800;padding:2px 6px;border-radius:5px;background:<?= $isActive ? '#f0fdf4;color:#16a34a;border:1px solid #bbf7d0' : '#fef2f2;color:#dc2626;border:1px solid #fecaca' ?>"><?= strtoupper($st['account_status']) ?></span>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button onclick='openEditStaffModal(<?= json_encode($st) ?>)'
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer"><i
                                                                class="fa-solid fa-pen"></i></button>
                                                        <form method="POST" style="display:inline"
                                                            onsubmit="return confirm('ยืนยันการลบ Staff ท่านนี้?')">
                                                            <input type="hidden" name="action" value="delete_staff">
                                                            <input type="hidden" name="sf_id" value="<?= $st['id'] ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit"
                                                                style="width:32px;height:32px;border-radius:8px;border:1px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer"><i
                                                                    class="fa-solid fa-trash"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div><!-- /section-identity -->

            <!-- Edit Modal (Identity) -->
            <div id="idEditModal"
                style="display:none;position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                <div
                    style="background:#fff;border-radius:24px;width:100%;max-width:480px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)">
                    <div
                        style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div
                                style="width:36px;height:36px;background:#fffbeb;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#d97706">
                                <i class="fa-solid fa-user-pen"></i>
                            </div>
                            <span style="font-size:15px;font-weight:900;color:#d97706">แก้ไขข้อมูลผู้ใช้</span>
                        </div>
                        <button onclick="document.getElementById('idEditModal').style.display='none'"
                            style="width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer">
                            <i class="fa-solid fa-times" style="font-size:12px"></i>
                        </button>
                    </div>
                    <form method="POST" style="padding:20px 24px;display:flex;flex-direction:column;gap:14px">
                        <input type="hidden" name="action" value="portal_edit_user">
                        <input type="hidden" name="user_id" id="id_edit_uid">
                        <?php if (function_exists('csrf_field'))
                            csrf_field(); ?>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">ชื่อ-นามสกุล
                                <span style="color:#ef4444">*</span></label>
                            <input id="id_edit_name" name="full_name" required
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">เลขบัตรประชาชน</label>
                            <input id="id_edit_citizen" name="citizen_id" maxlength="13"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box;letter-spacing:.1em"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">รหัสนักศึกษา</label>
                                <input id="id_edit_sid" name="student_personnel_id" maxlength="15"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                    onfocus="this.style.borderColor='#6366f1'"
                                    onblur="this.style.borderColor='#e2e8f0'">
                            </div>
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">เบอร์โทร</label>
                                <input id="id_edit_phone" name="phone_number"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                    onfocus="this.style.borderColor='#6366f1'"
                                    onblur="this.style.borderColor='#e2e8f0'">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">อีเมล</label>
                                <input id="id_edit_email" name="email" type="email"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                    onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'"
                                    placeholder="example@rsu.ac.th">
                            </div>
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">เพศ</label>
                                <select id="id_edit_gender" name="gender"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;background:#fff">
                                    <option value="">-- ไม่ระบุ --</option>
                                    <option value="male">ชาย</option>
                                    <option value="female">หญิง</option>
                                    <option value="other">อื่นๆ</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">คณะ
                                / หน่วยงาน</label>
                            <input id="id_edit_dept" name="department"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'"
                                placeholder="เช่น คณะนิเทศศาสตร์">
                        </div>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">ประเภท
                                <span style="color:#ef4444">*</span></label>
                            <select id="id_edit_status" name="status"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;background:#fff"
                                onchange="document.getElementById('id_edit_sother_wrap').style.display=this.value==='other'?'block':'none'">
                                <option value="">-- เลือก --</option>
                                <option value="student">นักศึกษา</option>
                                <option value="staff">บุคลากร/อาจารย์</option>
                                <option value="other">บุคคลทั่วไป</option>
                            </select>
                        </div>
                        <div id="id_edit_sother_wrap" style="display:none">
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">ระบุสถานภาพ
                                (กรณีเลือก "อื่นๆ")</label>
                            <input id="id_edit_sother" name="status_other"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'"
                                placeholder="เช่น ศิษย์เก่า, ผู้ปกครอง">
                        </div>
                        <div style="display:flex;gap:10px;padding-top:6px">
                            <button type="button" onclick="document.getElementById('idEditModal').style.display='none'"
                                style="flex:1;padding:11px;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;cursor:pointer">ยกเลิก</button>
                            <button type="submit"
                                style="flex:2;padding:11px;border-radius:12px;border:none;background:linear-gradient(90deg,#d97706,#f59e0b);color:#fff;font-size:13px;font-weight:800;cursor:pointer">
                                <i class="fa-solid fa-floppy-disk" style="margin-right:6px"></i>บันทึก
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- View Modal (Identity) -->
            <div id="idViewModal"
                style="display:none;position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                <div
                    style="background:#fff;border-radius:24px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)">
                    <div
                        style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div
                                style="width:36px;height:36px;background:#eef2ff;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#4f46e5">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <span style="font-size:15px;font-weight:900;color:#4f46e5">ข้อมูลผู้ใช้งาน</span>
                        </div>
                        <button onclick="document.getElementById('idViewModal').style.display='none'"
                            style="width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer"><i
                                class="fa-solid fa-times" style="font-size:12px"></i></button>
                    </div>
                    <div style="padding:20px 24px;display:flex;flex-direction:column;gap:12px" id="idViewBody"></div>
                    <div style="padding:14px 24px;border-top:1px solid #f1f5f9;text-align:right">
                        <button onclick="document.getElementById('idViewModal').style.display='none'"
                            style="padding:9px 22px;border-radius:10px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;cursor:pointer">ปิด</button>
                    </div>
                </div>
            </div>

            <?php if ($adminRole === 'superadmin'): ?>
                <!-- Admin Modal -->
                <div id="admModal"
                    style="display:none;position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                    <div
                        style="background:#fff;border-radius:24px;width:100%;max-width:480px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)">
                        <div
                            style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                            <span id="admModalTitle" style="font-size:16px;font-weight:900;color:#0f172a">จัดการ
                                Admin</span>
                            <button onclick="document.getElementById('admModal').style.display='none'"
                                style="background:none;border:none;cursor:pointer;color:#94a3b8"><i
                                    class="fa-solid fa-times"></i></button>
                        </div>
                        <form method="POST" style="padding:24px" id="admForm">
                            <input type="hidden" name="action" id="admAction" value="add_admin">
                            <input type="hidden" name="admin_id" id="admId">
                            <?php csrf_field(); ?>
                            <div style="display:flex;flex-direction:column;gap:16px">
                                <div>
                                    <label
                                        style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ชื่อ-นามสกุล</label>
                                    <input type="text" name="full_name" id="admFullName" required class="premium-input">
                                </div>
                                <div>
                                    <label
                                        style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ชื่อผู้ใช้
                                        (Username)</label>
                                    <input type="text" name="username" id="admUsername" required class="premium-input">
                                </div>
                                <div>
                                    <label
                                        style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">อีเมล</label>
                                    <input type="email" name="email" id="admEmail" required class="premium-input">
                                </div>
                                <div>
                                    <label
                                        style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">รหัสผ่าน
                                        <span id="pwdNotice"
                                            style="font-weight:normal;color:#94a3b8;font-size:9px">(เว้นว่างหากไม่ต้องการเปลี่ยน)</span></label>
                                    <input type="password" name="password" id="admPassword" class="premium-input">
                                </div>
                                <div>
                                    <label
                                        style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ระดับสิทธิ์
                                        (Role)</label>
                                    <select name="role" id="admRole" class="premium-input">
                                        <option value="admin">Admin</option>
                                        <option value="editor">Editor</option>
                                        <option value="superadmin">Superadmin</option>
                                    </select>
                                </div>
                                <div style="display:flex;gap:10px;margin-top:8px">
                                    <button type="button" onclick="document.getElementById('admModal').style.display='none'"
                                        style="flex:1;padding:12px;border-radius:12px;background:#f1f5f9;color:#475569;font-weight:800;font-size:13px;border:none">ยกเลิก</button>
                                    <button type="submit"
                                        style="flex:1;padding:12px;border-radius:12px;background:#2e9e63;color:#fff;font-weight:800;font-size:13px;border:none">บันทึกข้อมูล</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Staff Modal (e-Borrow) -->
                <div id="sfModal"
                    style="display:none;position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                    <div
                        style="background:#fff;border-radius:24px;width:100%;max-width:600px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)">
                        <div
                            style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                            <span id="sfModalTitle" style="font-size:16px;font-weight:900;color:#0f172a">จัดการเจ้าหน้าที่
                                e-Borrow</span>
                            <button onclick="document.getElementById('sfModal').style.display='none'"
                                style="background:none;border:none;cursor:pointer;color:#94a3b8"><i
                                    class="fa-solid fa-times"></i></button>
                        </div>
                        <form method="POST" style="padding:24px" id="sfForm">
                            <input type="hidden" name="action" id="sfAction" value="add_staff">
                            <input type="hidden" name="sf_id" id="sfId">
                            <?php csrf_field(); ?>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                                <div style="display:flex;flex-direction:column;gap:14px">
                                    <div
                                        style="font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">
                                        General Info</div>
                                    <div>
                                        <label
                                            style="display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:6px">ชื่อ-นามสกุล</label>
                                        <input type="text" name="sf_full_name" id="sfFullName" required
                                            class="premium-input">
                                    </div>
                                    <div>
                                        <label
                                            style="display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:6px">Username</label>
                                        <input type="text" name="sf_username" id="sfUsername" required
                                            class="premium-input">
                                    </div>
                                    <div>
                                        <label
                                            style="display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:6px">รหัสผ่าน</label>
                                        <input type="password" name="sf_password" id="sfPassword" class="premium-input"
                                            placeholder="••••••••">
                                    </div>
                                    <div>
                                        <label
                                            style="display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:6px">สถานะบัญชี</label>
                                        <select name="sf_status" id="sfStatus" class="premium-input"
                                            style="background-image:none">
                                            <option value="active">Active (เข้าใช้งานได้ปกติ)</option>
                                            <option value="suspended">Suspended (ระงับการใช้งาน)</option>
                                        </select>
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:14px">
                                    <div
                                        style="font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">
                                        Permissions & Roles</div>

                                    <div class="premium-role-card orange p-3">
                                        <div class="flex items-center gap-3 mb-2">
                                            <div class="role-icon" style="color:#f59e0b"><i
                                                    class="fa-solid fa-box-archive"></i></div>
                                            <div class="font-black text-xs">e-Borrow System</div>
                                        </div>
                                        <label
                                            style="display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px">สิทธิ์ในระบบยืม-คืน</label>
                                        <select name="sf_role" id="sfRole" class="premium-input"
                                            style="padding:8px 12px; font-size:12px">
                                            <option value="employee">Employee (เจ้าหน้าที่ทั่วไป)</option>
                                            <option value="technician">Technician (ช่างเทคนิค)</option>
                                            <option value="supervisor">Supervisor (หัวหน้างาน)</option>
                                            <option value="admin">e-Borrow Admin (ผู้ดูแลสูงสุด)</option>
                                        </select>
                                    </div>

                                    <div class="premium-role-card blue p-3">
                                        <div class="flex items-center gap-3 mb-2">
                                            <div class="role-icon" style="color:#2563eb"><i
                                                    class="fa-solid fa-bullhorn"></i></div>
                                            <div class="font-black text-xs">e-Campaign System</div>
                                        </div>
                                        <div class="flex items-center gap-2 mb-3">
                                            <input type="checkbox" name="sf_access_ecampaign" id="sfAccessEc" value="1"
                                                style="width:16px;height:16px">
                                            <label for="sfAccessEc"
                                                style="font-size:12px;font-weight:700;color:#1e40af;cursor:pointer">อนุญาตให้จัดการแคมเปญ</label>
                                        </div>
                                        <label
                                            style="display:block;font-size:11px;font-weight:800;color:#1e40af;margin-bottom:4px">สิทธิ์ใน
                                            e-Campaign</label>
                                        <select name="sf_ecampaign_role" id="sfEcRole" class="premium-input"
                                            style="padding:8px 12px; font-size:12px">
                                            <option value="admin">Admin (จัดการได้ทุกอย่าง)</option>
                                            <option value="editor">Editor (ดูและแก้ไขข้อมูล)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;margin-top:24px">
                                <button type="button" onclick="document.getElementById('sfModal').style.display='none'"
                                    style="flex:1;padding:12px;border-radius:12px;background:#f1f5f9;color:#475569;font-weight:800;font-size:13px;border:none">ยกเลิก</button>
                                <button type="submit"
                                    style="flex:1;padding:12px;border-radius:12px;background:#2563eb;color:#fff;font-weight:800;font-size:13px;border:none">บันทึกข้อมูลเจ้าหน้าที่</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 
                DEVELOPER NOTE: HOW TO ADD NEW SECTIONS
                To add a new page/section, follow this template to ensure layout stability:
                <div id="section-NAME" class="portal-section" style="<?= $activeSection==='NAME'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                    <?php include __DIR__ . '/_partials/NAME.php'; ?>
                </div>
            -->

            <!-- ════════════ SECTION: SETTINGS ════════════ -->
            <div id="section-settings" class="portal-section"
                style="<?= $activeSection==='settings'?'':'display:none;' ?> background:#f1f5f9; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/settings.php'; ?>
            </div>

            <!-- ════════════ SECTION: AI ASSISTANT ════════════ -->
            <div id="section-ai_assistant" class="portal-section"
                style="<?= $activeSection==='ai_assistant'?'':'display:none;' ?> background:#fff; overflow:hidden;">
                <?php include __DIR__ . '/_partials/ai_assistant.php'; ?>
            </div>

            <!-- ════════════ SECTION: CLINIC DATA ════════════ -->
            <div id="section-clinic_data" class="portal-section"
                style="<?= $activeSection==='clinic_data'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/clinic_data.php'; ?>
            </div>

            <!-- ════════════ SECTION: ACTIVITY LOGS ════════════ -->
            <div id="section-activity_logs" class="portal-section"
                style="<?= $activeSection==='activity_logs'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/activity_logs.php'; ?>
            </div>

            <!-- ════════════ SECTION: ERROR LOGS ════════════ -->
            <div id="section-error_logs" class="portal-section"
                style="<?= $activeSection==='error_logs'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/error_logs.php'; ?>
            </div>

            <!-- ════════════ SECTION: EMAIL LOGS ════════════ -->
            <div id="section-email_logs" class="portal-section"
                style="<?= $activeSection==='email_logs'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/email_logs.php'; ?>
            </div>

            <!-- ════════════ SECTION: SMTP SETTINGS ════════════ -->
            <div id="section-smtp_settings" class="portal-section"
                style="<?= $activeSection==='smtp_settings'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/smtp_settings.php'; ?>
            </div>

            <!-- ════════════ SECTION: SENTRY TEST ════════════ -->
            <div id="section-sentry_test" class="portal-section"
                style="<?= $activeSection==='sentry_test'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/sentry_test.php'; ?>
            </div>

        </main><!-- /portal-main -->
    </div><!-- /app-shell -->

    <!-- Theme Handling Script -->
    <script>
        function toggleDarkMode() {
            const isDark = document.body.getAttribute('data-theme') === 'dark';
            applyTheme(isDark ? 'light' : 'dark');
        }

        function applyTheme(theme) {
            if (theme === 'dark') {
                document.body.setAttribute('data-theme', 'dark');
                document.getElementById('darkModeToggle').innerHTML = '<i class="fa-solid fa-sun text-amber-500"></i>';
                localStorage.setItem('ecampaign_theme', 'dark');
            } else {
                document.body.removeAttribute('data-theme');
                document.getElementById('darkModeToggle').innerHTML = '<i class="fa-solid fa-moon"></i>';
                localStorage.setItem('ecampaign_theme', 'light');
            }
            
            // Send message to all iframes to update their theme
            document.querySelectorAll('iframe').forEach(iframe => {
                if(iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ type: 'THEME_CHANGE', theme: theme }, '*');
                }
            });
        }

        // Apply theme on load
        if (localStorage.getItem('ecampaign_theme') === 'dark') {
            applyTheme('dark');
        }
    </script>

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
            btn.addEventListener('click', function (e) {
                const r = this.getBoundingClientRect();
                const size = Math.max(r.width, r.height);
                const el = document.createElement('span');
                el.className = 'ripple-wave';
                el.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - r.left - size / 2}px;top:${e.clientY - r.top - size / 2}px`;
                this.appendChild(el);
                el.addEventListener('animationend', () => el.remove());
            });
        });

        /* ── 3. 3D Tilt on project cards ───────────────────────── */
        document.querySelectorAll('.proj-card').forEach(card => {
            card.addEventListener('mousemove', function (e) {
                const r = this.getBoundingClientRect();
                const x = (e.clientX - r.left) / r.width - .5;
                const y = (e.clientY - r.top) / r.height - .5;
                this.style.transform = `translateY(-5px) rotateX(${-y * 8}deg) rotateY(${x * 8}deg)`;
                this.style.transition = 'transform .1s ease';
            });
            card.addEventListener('mouseleave', function () {
                this.style.transform = '';
                this.style.transition = 'transform .4s ease, box-shadow .25s, border-color .25s';
            });
        });
    </script>

    <?php if ($adminRole === 'superadmin'): ?>
        <script>
            function triggerGitPull() {
                Swal.fire({
                    title: 'กำลังดำเนินการ Git Pull...',
                    text: 'กรุณารอสักครู่ ระบบกำลังอัปเดตโค้ดล่าสุดจาก Server',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                        const btn = document.getElementById('btnGitPull');
                        const btnHistory = document.getElementById('btnGitPullHistory');
                        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
                        if (btnHistory) { btnHistory.disabled = true; btnHistory.style.opacity = '0.6'; }

                        fetch('../admin/ajax/ajax_git_pull.php', { method: 'POST' })
                            .then(r => r.json())
                            .then(data => {
                                if (data.status === 'success') {
                                    if (data.detail && !data.detail.includes('Already up to date')) {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'Git Pull สำเร็จ!',
                                            html: `<div style="text-align:left; font-size:13px; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0; font-family:monospace; margin-top:10px; max-height:200px; overflow-y:auto;">${data.detail.replace(/\n/g, '<br>')}</div><p style="margin-top:15px; font-weight:700;">รีโหลดหน้าเพื่อใช้งานโค้ดใหม่?</p>`,
                                            showCancelButton: true,
                                            confirmButtonText: 'ตกลง (Reload)',
                                            cancelButtonText: 'ยังไม่รีโหลด',
                                            confirmButtonColor: '#2e9e63'
                                        }).then((result) => {
                                            if (result.isConfirmed) {
                                                location.reload();
                                            }
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: 'info',
                                            title: 'Git Pull สำเร็จ',
                                            text: 'ระบบเป็นเวอร์ชันล่าสุดอยู่แล้ว (Already up to date)',
                                            confirmButtonColor: '#2e9e63'
                                        });
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Git Pull ล้มเหลว',
                                        text: data.message,
                                        footer: data.detail ? `<pre style="text-align:left; font-size:10px;">${data.detail}</pre>` : ''
                                    });
                                }
                            })
                            .catch((err) => {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาด',
                                    text: 'ไม่สามารถเชื่อมต่อกับ AJAX Git Pull ได้'
                                });
                            })
                            .finally(() => {
                                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                                if (btnHistory) { btnHistory.disabled = false; btnHistory.style.opacity = '1'; }
                            });
                    }
                });
            }
        </script>
    <?php endif; ?>

    <script>
        document.getElementById('siteSettingsForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const formData = new FormData(form);
            const btn = form.querySelector('button[type="submit"]');
            
            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก...';

            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: data.message,
                        confirmButtonColor: '#2563eb'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'ผิดพลาด',
                        text: data.message,
                        confirmButtonColor: '#ef4444'
                    });
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดระบบ',
                    text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้',
                    confirmButtonColor: '#ef4444'
                });
            })
            .finally(() => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> บันทึกการตั้งค่า';
            });
        });
    </script>

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
        const dot = document.getElementById('ws-dot');
        const label = document.getElementById('ws-label');

        function setBadge(state) {
            const styles = {
                live: { bg: '#f0fdf4', color: '#16a34a', border: '#c7e8d5', dot: '#22c55e', anim: 'livePulse 1.6s infinite', text: 'Live' },
                loading: { bg: '#fffbeb', color: '#d97706', border: '#fde68a', dot: '#f59e0b', anim: 'livePulse .8s infinite', text: 'Updating…' },
                offline: { bg: '#fef2f2', color: '#dc2626', border: '#fecaca', dot: '#ef4444', anim: 'none', text: 'Offline' },
            };
            const s = styles[state] || styles.offline;
            badge.style.cssText = `display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;transition:all .3s;background:${s.bg};color:${s.color};border:1px solid ${s.border}`;
            dot.style.background = s.dot;
            dot.style.animation = s.anim;
            label.textContent = s.text;
        }

        function animateKpi(el, toVal) {
            if (!el) return;
            const from = parseInt(el.textContent.replace(/,/g, ''), 10) || 0;
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
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function renderActivity(logs) {
            const feed = document.getElementById('activity-feed');
            const link = feed?.querySelector('a[href]');
            if (!feed) return;
            feed.querySelectorAll('.feed-item').forEach(el => el.remove());
            if (!logs?.length) return;
            const frag = document.createDocumentFragment();
            logs.forEach((log, i) => {
                const ts = new Date(log.timestamp.replace(' ', 'T'));
                const timeStr = ts.toLocaleString('th-TH', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
                const row = document.createElement('div');
                row.className = 'feed-item feed-new';
                row.style.animationDelay = (i * 0.04) + 's';
                row.innerHTML = `<div class="feed-dot"><i class="fa-solid fa-bolt text-[11px]"></i></div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2 mb-0.5">
                    <span class="text-[10px] font-black uppercase tracking-wider truncate" style="color:#2e9e63">${escHtml(log.action)}</span>
                    <span class="text-[9px] text-gray-400 whitespace-nowrap">${timeStr}</span>
                </div>
                <p class="text-[12px] font-bold text-gray-800 leading-snug truncate">${escHtml(log.admin_name || 'System')}</p>
                <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1">${escHtml(log.description || '')}</p>
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
                    animateKpi(document.getElementById('kpi-users'), d.users);
                    animateKpi(document.getElementById('kpi-camps'), d.camps);
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
                        const kpiUsed = document.getElementById('kpi-used');
                        const kpiTQ = document.getElementById('kpi-total-quota');
                        const kpiQuota = document.getElementById('kpi-quota');
                        if (rateBar) rateBar.style.width = rate + '%';
                        if (rateNum) rateNum.textContent = rate;
                        if (kpiUsed) kpiUsed.textContent = (d.used_quota ?? 0).toLocaleString();
                        if (kpiTQ) kpiTQ.textContent = d.total_quota.toLocaleString();
                        if (kpiQuota) kpiQuota.textContent = d.total_quota.toLocaleString();
                    }

                    if (Array.isArray(d.activity)) renderActivity(d.activity);
                    setBadge('live');
                })
                .catch(() => setBadge('offline'));
        }

        /* ── Project Grid Controls ────────────────────────────────────────────────── */
        (function () {
            var currentFilter = 'all';
            var searchQuery = '';

            function applyFilters() {
                var cards = document.querySelectorAll('#project-container .proj-card');
                var visible = 0;
                cards.forEach(function (card) {
                    var name = (card.dataset.name || '').toLowerCase();
                    var keywords = (card.dataset.keywords || '').toLowerCase();
                    var category = card.dataset.category || '';
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
                var btnGrid = document.getElementById('btn-grid');
                var btnList = document.getElementById('btn-list');
                var activeStyle = 'padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:#fff;color:#2e9e63;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:all .2s';
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

        /* ── Identity & Governance ─────────────────────────────────────────────── */
        function switchIdTab(tab, btn) {
            document.querySelectorAll('.id-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.id-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('id-panel-' + tab).classList.add('active');

            // Header visibility
            const isUsers = tab === 'users';
            const isAdmins = tab === 'admins';
            const isStaff = tab === 'staff';

            const btnAdmin = document.getElementById('id-btn-add-admin');
            const btnStaff = document.getElementById('id-btn-add-staff');
            if (btnAdmin) btnAdmin.style.display = isAdmins ? 'block' : 'none';
            if (btnStaff) btnStaff.style.display = isStaff ? 'block' : 'none';

            // Search behavior
            const search = document.getElementById('id-search-input');
            if (search) {
                search.value = '';
                idUniversalFilter('');
                search.placeholder = isUsers ? 'ค้นหา Users...' : (isAdmins ? 'ค้นหา Admins...' : 'ค้นหา Staff...');
            }
        }

        function idUniversalFilter(val) {
            val = val.toLowerCase().trim();
            const activePanel = document.querySelector('.id-panel.active');
            if (!activePanel) return;

            const rows = activePanel.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.cells.length < 2) return;
                row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function openAddAdminModal() {
            document.getElementById('admAction').value = 'add_admin';
            document.getElementById('admModalTitle').textContent = 'เพิ่ม System Admin';
            document.getElementById('admForm').reset();
            document.getElementById('admPassword').placeholder = '';
            document.getElementById('pwdNotice').style.display = 'none';
            document.getElementById('admModal').style.display = 'flex';
        }

        function openEditAdminModal(adm) {
            document.getElementById('admAction').value = 'edit_admin';
            document.getElementById('admModalTitle').textContent = 'แก้ไข System Admin';
            document.getElementById('admId').value = adm.id;
            document.getElementById('admFullName').value = adm.full_name;
            document.getElementById('admUsername').value = adm.username;
            document.getElementById('admEmail').value = adm.email;
            document.getElementById('admRole').value = adm.role;
            document.getElementById('admPassword').value = '';
            document.getElementById('admPassword').placeholder = '••••••••';
            document.getElementById('pwdNotice').style.display = 'inline';
            document.getElementById('admModal').style.display = 'flex';
        }

        function openAddStaffModal() {
            document.getElementById('sfAction').value = 'add_staff';
            document.getElementById('sfModalTitle').textContent = 'เพิ่มเจ้าหน้าที่ (Staff)';
            document.getElementById('sfForm').reset();
            document.getElementById('sfPassword').required = true;
            document.getElementById('sfModal').style.display = 'flex';
        }

        function openEditStaffModal(st) {
            document.getElementById('sfAction').value = 'edit_staff';
            document.getElementById('sfModalTitle').textContent = 'แก้ไขข้อมูลเจ้าหน้าที่';
            document.getElementById('sfId').value = st.id;
            document.getElementById('sfFullName').value = st.full_name;
            document.getElementById('sfUsername').value = st.username;
            document.getElementById('sfRole').value = st.role;
            document.getElementById('sfStatus').value = st.account_status;
            document.getElementById('sfAccessEc').checked = parseInt(st.access_ecampaign) === 1;
            document.getElementById('sfEcRole').value = st.ecampaign_role;
            document.getElementById('sfPassword').value = '';
            document.getElementById('sfPassword').required = false;
            document.getElementById('sfModal').style.display = 'flex';
        }

        function idOpenEdit(u) {
            document.getElementById('id_edit_uid').value = u.id;
            document.getElementById('id_edit_name').value = u.full_name || '';
            document.getElementById('id_edit_citizen').value = u.citizen_id || '';
            document.getElementById('id_edit_sid').value = u.student_personnel_id || '';
            document.getElementById('id_edit_phone').value = u.phone_number || '';
            document.getElementById('id_edit_email').value = u.email || '';
            document.getElementById('id_edit_gender').value = u.gender || '';
            document.getElementById('id_edit_dept').value = u.department || '';
            document.getElementById('id_edit_status').value = u.status || '';
            document.getElementById('id_edit_sother').value = u.status_other || '';
            document.getElementById('id_edit_sother_wrap').style.display = u.status === 'other' ? 'block' : 'none';
            var m = document.getElementById('idEditModal');
            m.style.display = 'flex';
        }
        function idOpenView(u) {
            var statusMap = { student: 'นักศึกษา', staff: 'บุคลากร/อาจารย์', teacher: 'อาจารย์', other: 'บุคคลทั่วไป' };
            var genderMap = { male: 'ชาย', female: 'หญิง', other: 'อื่นๆ' };
            var map = [
                ['ชื่อ-นามสกุล', u.full_name],
                ['เลขบัตรประชาชน', u.citizen_id],
                ['รหัสนักศึกษา / บุคลากร', u.student_personnel_id],
                ['เบอร์โทรศัพท์', u.phone_number],
                ['อีเมล', u.email],
                ['เพศ', genderMap[u.gender] || u.gender],
                ['คณะ / หน่วยงาน', u.department],
                ['ประเภท', statusMap[u.status] || u.status],
            ];
            if (u.status === 'other' && u.status_other) {
                map.push(['ระบุสถานภาพ', u.status_other]);
            }
            map.push(['วันที่ลงทะเบียน', u.created_at ? new Date(u.created_at.replace(' ', 'T')).toLocaleString('th-TH', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—']);
            document.getElementById('idViewBody').innerHTML = map.map(function (r) {
                return '<div><div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px">' + r[0] + '</div>'
                    + '<div style="padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;color:#0f172a">' + (r[1] || '—') + '</div></div>';
            }).join('');
            document.getElementById('idViewModal').style.display = 'flex';
        }
        /* ── Identity pagination ── */
        (function () {
            var allRows = [];
            var filtered = [];
            var currentPage = 1;
            var pageSize = 25;

            function init() {
                var tableBody = document.getElementById('idUserTbody');
                if (!tableBody) return;
                allRows = Array.from(tableBody.querySelectorAll('.id-user-row'));
                filtered = allRows.slice();
                render();
            }

            function render() {
                var total = filtered.length;
                var totalPages = Math.max(1, Math.ceil(total / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                var start = (currentPage - 1) * pageSize;
                var end = start + pageSize;

                allRows.forEach(function (r) { r.style.display = 'none'; });
                filtered.slice(start, end).forEach(function (r) { r.style.display = ''; });

                var from = total === 0 ? 0 : start + 1;
                var to = Math.min(end, total);
                var info = document.getElementById('id-page-info');
                if (info) info.textContent = total === 0 ? 'ไม่พบรายการ' : from + '–' + to + ' จาก ' + total.toLocaleString();

                var prev = document.getElementById('id-page-prev');
                var next = document.getElementById('id-page-next');
                if (prev) { prev.disabled = currentPage <= 1; prev.style.opacity = currentPage <= 1 ? '.35' : '1'; }
                if (next) { next.disabled = currentPage >= totalPages; next.style.opacity = currentPage >= totalPages ? '.35' : '1'; }
            }

            window.idFilterUsers = function (val) {
                val = val.toLowerCase().trim();
                filtered = val ? allRows.filter(function (r) { return r.innerText.toLowerCase().includes(val); }) : allRows.slice();
                currentPage = 1;
                render();
            };

            window.idSetPageSize = function (size) {
                pageSize = size;
                currentPage = 1;
                render();
                document.querySelectorAll('.id-ps-btn').forEach(function (b) {
                    var active = parseInt(b.dataset.size) === size;
                    b.style.background = active ? '#2e9e63' : '#f8fafc';
                    b.style.color = active ? '#fff' : '#374151';
                    b.style.borderColor = active ? '#2e9e63' : '#e2e8f0';
                });
            };

            window.idPrevPage = function () { if (currentPage > 1) { currentPage--; render(); } };
            window.idNextPage = function () {
                if (currentPage < Math.ceil(filtered.length / pageSize)) { currentPage++; render(); }
            };

            // run after DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        // Close modals on backdrop click
        ['idEditModal', 'idViewModal', 'admModal', 'sfModal'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function (e) {
                    if (e.target === this) this.style.display = 'none';
                });
            }
        });

        // Auto-switch section from URL ?section=...
        (function () {
            var params = new URLSearchParams(window.location.search);
            var sec = params.get('section');
            var tab = params.get('tab');
            if (sec) {
                var btn = document.querySelector('.psb-item[data-section="' + sec + '"]');
                if (btn) window.switchSection(sec, btn);
            }
            if (sec === 'identity' && tab) {
                var tabBtn = document.querySelector('.id-tab[data-tab="' + tab + '"]');
                if (tabBtn) switchIdTab(tab, tabBtn);
            }
            // Auto-dismiss toast
            var toast = document.getElementById('id-toast');
            if (toast) setTimeout(function () { toast.style.transition = 'opacity .5s'; toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 500); }, 3000);
        })();

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

        /* ── Maintenance Mode Logic (Merged from Admin Tool) ─────────────────────── */
        const portal_CSRF = <?= json_encode(get_csrf_token()) ?>;

        function showPortalToast(msg, type = 'success') {
            const id = 'portal-runtime-toast';
            let t = document.getElementById(id);
            if (!t) {
                t = document.createElement('div');
                t.id = id;
                t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:14px;font-size:13px;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,.12);transform:translateY(80px);opacity:0;transition:all .3s cubic-bezier(.16,1,.3,1);pointer-events:none;';
                document.body.appendChild(t);
            }
            t.textContent = msg;
            t.style.background = type === 'success' ? '#f0fdf4' : '#fef2f2';
            t.style.color = type === 'success' ? '#16a34a' : '#dc2626';
            t.style.border = type === 'success' ? '1.5px solid #bbf7d0' : '1.5px solid #fecaca';

            t.style.transform = 'translateY(0)';
            t.style.opacity = '1';
            clearTimeout(t._tid);
            t._tid = setTimeout(() => {
                t.style.transform = 'translateY(80px)';
                t.style.opacity = '0';
            }, 3000);
        }

        function updateMaintenanceUI(project, active) {
            const badge = document.getElementById('badge-' + project);
            if (badge) {
                badge.className = 'status-badge ' + (active ? 'on' : 'off');
                badge.innerHTML = `<span class="status-dot"></span>${active ? 'เปิดใช้งาน' : 'ปรับปรุง'}`;
                badge.classList.remove('badge-pop');
                void badge.offsetWidth;
                badge.classList.add('badge-pop');
            }

            // Update main status banner
            const toggles = document.querySelectorAll('[data-project]');
            const allOn = Array.from(toggles).every(t => t.checked);
            const banner = document.getElementById('status-banner');
            if (banner) {
                banner.dataset.state = allOn ? 'ok' : 'warn';
                const icon = document.getElementById('banner-icon');
                const title = document.getElementById('banner-title');
                const desc = document.getElementById('banner-desc');

                if (icon) icon.className = `fa-solid ${allOn ? 'fa-circle-check' : 'fa-triangle-exclamation'} text-base`;
                if (title) title.textContent = allOn ? 'ระบบทุกโปรเจกต์พร้อมใช้งาน' : 'มีบางโปรเจกต์ปิดปรับปรุงอยู่';
                if (desc) desc.textContent = allOn ? 'User ทุกคนสามารถเข้าใช้งานได้ตามปกติ' : 'คุณสามารถคลิกเปิดระบบได้จากรายการด้านล่าง';

                const iconWrap = icon?.parentElement;
                if (iconWrap) iconWrap.style.cssText = allOn ? 'background:#dcfce7;color:#16a34a' : 'background:#fef3c7;color:#d97706';
            }
        }

        function toggleMaintenance(input) {
            const project = input.dataset.project;
            const active = input.checked;

            // Optimistic UI update
            updateMaintenanceUI(project, active);

            const fd = new FormData();
            fd.append('action', 'set');
            fd.append('project', project);
            fd.append('active', active ? '1' : '0');
            fd.append('csrf_token', portal_CSRF);

            fetch('ajax_maintenance.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        showPortalToast(active ? `${project} เปิดใช้งานแล้ว` : `${project} ปิดปรับปรุงแล้ว`, active ? 'success' : 'error');
                    } else {
                        input.checked = !active;
                        updateMaintenanceUI(project, !active);
                        showPortalToast('ผิดพลาด: ' + (d.message || 'Unknown error'), 'error');
                    }
                })
                .catch(() => {
                    input.checked = !active;
                    updateMaintenanceUI(project, !active);
                    showPortalToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                });
        }

        // Start polling after page is fully loaded
        window.addEventListener('load', () => {
            setBadge('live'); // optimistic: page data is fresh on load
            pollTimer = setInterval(poll, POLL_INTERVAL);
        });
    </script>
</body>

</html>