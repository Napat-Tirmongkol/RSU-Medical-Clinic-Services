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
 * (0) IDENTITY SECTION — POST HANDLER (edit user)
 */
$idSaved = isset($_GET['saved']) && $_GET['saved'] === '1';
$idError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'portal_edit_user') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $studentId = trim($_POST['student_personnel_id'] ?? '');
    $citizenId = trim($_POST['citizen_id'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $statusOther = trim($_POST['status_other'] ?? '');
    if ($userId > 0 && $fullName !== '') {
        try {
            $pdo->prepare("UPDATE sys_users SET full_name=:n, student_personnel_id=:s, citizen_id=:c, phone_number=:p, email=:email, department=:dept, gender=:gender, status=:st, status_other=:sother WHERE id=:id")
                ->execute([
                    ':n' => $fullName,
                    ':s' => $studentId,
                    ':c' => $citizenId,
                    ':p' => $phone,
                    ':email' => $email,
                    ':dept' => $department ?: null,
                    ':gender' => $gender ?: null,
                    ':st' => $status,
                    ':sother' => $statusOther ?: null,
                    ':id' => $userId
                ]);
            header('Location: index.php?section=identity&saved=1');
            exit;
        } catch (PDOException $e) {
            error_log("portal edit_user error: " . $e->getMessage());
            $idError = 'บันทึกไม่สำเร็จ กรุณาลองใหม่';
        }
    } else {
        $idError = 'ข้อมูลไม่ครบถ้วน';
    }
}

/**
 * (0b) IDENTITY SECTION — USER QUERY
 */
$idSearch = trim($_GET['id_search'] ?? '');
$idUsers = [];
$idActiveCount = 0;
try {
    $idSql = "SELECT id, full_name, student_personnel_id, citizen_id, phone_number, email, department, gender, status, status_other, created_at FROM sys_users";
    if ($idSearch !== '') {
        $idSql .= " WHERE full_name LIKE :s OR student_personnel_id LIKE :s2 OR citizen_id LIKE :s3";
    }
    $idSql .= " ORDER BY created_at DESC";
    $idStmt = $pdo->prepare($idSql);
    if ($idSearch !== '') {
        $like = "%{$idSearch}%";
        $idStmt->execute([':s' => $like, ':s2' => $like, ':s3' => $like]);
    } else {
        $idStmt->execute();
    }
    $idUsers = $idStmt->fetchAll(PDO::FETCH_ASSOC);

    $idActiveCount = (int) $pdo->query("
        SELECT COUNT(DISTINCT id) FROM sys_users
        WHERE id IN (SELECT student_id FROM camp_bookings WHERE student_id IS NOT NULL)
    ")->fetchColumn();
} catch (PDOException $e) {
    $idUsers = [];
}

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
        'key'        => 'e_campaign',
        'title'      => 'e-Campaign',
        'desc'       => 'ระบบจองและลงทะเบียนกิจกรรมสำหรับ User',
        'icon'       => 'fa-bullhorn',
        'icon_color' => '#2563eb',
        'icon_bg'    => '#eff6ff',
    ],
    [
        'key'        => 'e_borrow',
        'title'      => 'e-Borrow & Inventory',
        'desc'       => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์',
        'icon'       => 'fa-toolbox',
        'icon_color' => '#475569',
        'icon_bg'    => '#f1f5f9',
    ],
];
$allOnline = true;
foreach ($mProjects as $p) {
    if (($mData[$p['key']] ?? true) === false) { $allOnline = false; break; }
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
        'allowed_roles' => ['admin', 'superadmin'],
        'badges' => ['Campaigns', 'Activity'],
        'actions' => [
            ['label' => 'Launch Campaign Manager', 'url' => '../admin/index.php', 'primary' => true],
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
            ['label' => 'Open System', 'url' => '../archive/e_Borrow/admin/index.php', 'primary' => true],
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
        'id' => 'admin_tool',
        'title' => 'Admin Tool',
        'description' => 'เครื่องมือสำหรับผู้ดูแลระบบ จัดการและตั้งค่าระบบขั้นสูง (ตอนนี้รวมอยู่ในเมนู Settings แล้ว)',
        'icon' => 'fa-screwdriver-wrench',
        'bg_color' => 'bg-violet-50',
        'icon_color' => 'text-violet-600',
        'border_color' => 'border-violet-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges' => ['Tools', 'Settings'],
        'actions' => [
            ['label' => 'Open in Settings', 'url' => 'javascript:switchSection(\'settings\', document.querySelector(\'[data-section=settings]\'))', 'primary' => true],
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
    <title>Management Smart Portal - Central Intelligence HUB</title>

    <!-- UI Framework & Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@100;300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <style>
        /* ── Toggle Switch (Maintenance Mode) ──────────────────────────────── */
        .toggle-wrap { display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .toggle { position:relative; width:46px; height:24px; cursor:pointer; }
        .toggle input { opacity:0; width:0; height:0; position:absolute; }
        .toggle-track {
            position:absolute; inset:0;
            background:#e2e8f0; border-radius:99px;
            transition:background .25s cubic-bezier(.25,1,.5,1);
        }
        .toggle input:checked ~ .toggle-track { background:#2e9e63; }
        .toggle-thumb {
            position:absolute; top:3px; left:3px;
            width:18px; height:18px;
            background:#fff; border-radius:50%;
            box-shadow:0 1px 3px rgba(0,0,0,.15);
            transition:transform .3s cubic-bezier(.25,1,.5,1);
        }
        .toggle input:checked ~ .toggle-thumb { transform:translateX(22px); }

        @keyframes toggleRingOn {
            0%   { box-shadow:0 0 0 0   rgba(46,158,99,.4); }
            50%  { box-shadow:0 0 0 6px rgba(46,158,99,.15); }
            100% { box-shadow:0 0 0 0   rgba(46,158,99,.0); }
        }
        .toggle-ring-on  { animation:toggleRingOn .45s cubic-bezier(.25,1,.5,1) both; }

        /* ── Status badge ──────────────────────────────────────────────────── */
        .status-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:2px 9px; border-radius:99px;
            font-size:10px; font-weight:700;
        }
        .status-badge.on  { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .status-badge.off { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .status-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
        .status-badge.on  .status-dot { background:#22c55e; animation:livePulse 1.5s infinite; }
        .status-badge.off .status-dot { background:#ef4444; }

        @keyframes badgePop {
            0%   { opacity:.35; transform:scale(.82); }
            60%  { transform:scale(1.07); }
            100% { opacity:1;   transform:scale(1); }
        }
        .badge-pop { animation:badgePop .3s cubic-bezier(.25,1,.5,1) both; }

        /* ── Status banner (Settings) ───────────────────────────────────────── */
        #status-banner {
            background:#fffbeb; border:1.5px solid #fde68a;
            transition:all .3s ease;
        }
        #status-banner[data-state="ok"] { background:#f0fdf4; border-color:#bbf7d0; }
    </style>
</head>

<body class="font-sans text-gray-800" style="height:100vh;overflow:hidden;display:flex;flex-direction:column">

    <!-- ══════════════════ HEADER ══════════════════ -->
    <header class="portal-header au">
        <div class="max-w-[1280px] mx-auto px-3 sm:px-6 py-3 flex items-center justify-between gap-2 sm:gap-4">
            <!-- Brand -->
            <div class="flex items-center gap-2 sm:gap-3">
                <div class="brand-icon"><i class="fa-solid fa-heart"></i></div>
                <div>
                    <div class="font-black text-gray-900 text-[15px] sm:text-[17px] leading-none tracking-tight">Central
                        HUB</div>
                    <div class="hidden sm:block text-[10px] font-bold tracking-[.15em] uppercase opacity-70 mt-0.5"
                        style="color:#2e9e63">RSU Medical Clinic Portal</div>
                </div>
            </div>

            <!-- Right: user + logout -->
            <div class="flex items-center gap-2 sm:gap-3">

                <?php if ($adminRole === 'superadmin'): ?>
                    <!-- Git Pull Button (Superadmin only) -->
                    <button id="btnGitPull" onclick="triggerGitPull()" title="Pull โค้ดล่าสุดจาก Git"
                        style="display:flex;align-items:center;gap:6px;padding:6px 10px;border-radius:10px;border:1px solid #d1fae5;background:#f0fdf4;color:#16a34a;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;">
                        <i class="fa-solid fa-code-branch"></i>
                        <span class="hidden sm:inline">Git Pull</span>
                    </button>
                <?php endif; ?>

                <!-- Live connection badge -->
                <div id="ws-badge" title="Real-time connection status"
                    style="display:flex;align-items:center;gap:5px;padding:5px 8px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border:1px solid #c7e8d5;background:#f0fdf4;color:#16a34a;transition:all .3s">
                    <span id="ws-dot"
                        style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;animation:livePulse 1.6s infinite"></span>
                    <span id="ws-label" class="hidden sm:inline">Live</span>
                </div>
            </div>
        </div>
    </header>

    <!-- ══════════════════ APP SHELL ══════════════════ -->
    <div id="app-shell" style="display:flex;flex:1;min-height:0;overflow:hidden">

        <!-- ── Collapsible Sidebar ── -->
        <nav id="portal-sidebar">
            <!-- Brand / Toggle -->
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f0faf4;min-height:52px">
                <span id="psb-brand-text"
                    style="font-size:13px;font-weight:900;color:#0f172a;white-space:nowrap;transition:opacity .2s,width .28s">Portal</span>
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

            <!-- Bottom: user identity + logout -->
            <div id="psb-user-footer" style="border-top:1px solid #f0faf4;padding:10px">
                <!-- Expanded state: avatar + name + logout -->
                <div id="psb-user-expanded"
                    style="display:flex;align-items:center;gap:10px;padding:8px 6px;border-radius:12px;background:#f8fafc;overflow:hidden;transition:opacity .2s">
                    <div
                        style="width:32px;height:32px;border-radius:9px;background:#e8f8f0;color:#2e9e63;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fa-solid fa-user-shield" style="font-size:13px"></i>
                    </div>
                    <div class="psb-label" style="flex:1;min-width:0">
                        <div
                            style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;line-height:1;margin-bottom:2px">
                            Admin</div>
                        <div
                            style="font-size:12px;font-weight:900;color:#0f172a;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?></div>
                    </div>
                    <a href="../admin/logout.php" class="psb-label" title="ออกจากระบบ"
                        style="width:28px;height:28px;border-radius:8px;background:#fff1f2;color:#ef4444;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s;text-decoration:none"
                        onmouseover="this.style.background='#ef4444';this.style.color='#fff'"
                        onmouseout="this.style.background='#fff1f2';this.style.color='#ef4444'">
                        <i class="fa-solid fa-power-off" style="font-size:11px"></i>
                    </a>
                </div>
                <!-- Collapsed state: avatar only (centered) -->
                <div id="psb-user-collapsed" style="display:none;flex-direction:column;align-items:center;gap:6px">
                    <div
                        style="width:32px;height:32px;border-radius:9px;background:#e8f8f0;color:#2e9e63;display:flex;align-items:center;justify-content:center">
                        <i class="fa-solid fa-user-shield" style="font-size:13px"></i>
                    </div>
                    <a href="../admin/logout.php" title="ออกจากระบบ"
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
                                    if (!in_array($adminRole, $proj['allowed_roles']))
                                        continue;
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
                                                <?= $proj['title'] ?></h3>
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
                                                        <?= htmlspecialchars($log['admin_name'] ?? 'System') ?></p>
                                                    <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1">
                                                        <?= htmlspecialchars($log['description']) ?></p>
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
                        <!-- Search form -->
                        <form method="GET" style="display:flex;gap:8px;align-items:center"
                            onsubmit="event.preventDefault();idFilterUsers(document.getElementById('id-search-input').value)">
                            <input type="hidden" name="section" value="identity">
                            <div style="position:relative">
                                <i class="fa-solid fa-magnifying-glass"
                                    style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none"></i>
                                <input id="id-search-input" type="text" name="id_search"
                                    value="<?= htmlspecialchars($idSearch) ?>" placeholder="ค้นหาชื่อ / รหัส..."
                                    style="padding:8px 12px 8px 30px;border:1.5px solid #d0ead9;border-radius:12px;font-size:12px;font-family:inherit;outline:none;width:200px;transition:border-color .2s"
                                    oninput="idFilterUsers(this.value)" onfocus="this.style.borderColor='#2e9e63'"
                                    onblur="this.style.borderColor='#d0ead9'">
                            </div>
                        </form>
                    </div>

                    <!-- Stats bar -->
                    <div
                        style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px">
                        <div class="kpi-card" style="padding:18px 20px">
                            <div class="kpi-accent" style="background:linear-gradient(90deg,#6366f1,#a5b4fc)"></div>
                            <div class="kpi-icon" style="background:#eef2ff;color:#4f46e5"><i
                                    class="fa-solid fa-users"></i></div>
                            <div class="kpi-num" style="font-size:1.6rem">
                                <?= number_format(count($idUsers) ?: $kpis['users']) ?></div>
                            <div class="kpi-label">ผู้ใช้งานทั้งหมด</div>
                        </div>
                        <div class="kpi-card" style="padding:18px 20px">
                            <div class="kpi-accent" style="background:linear-gradient(90deg,#0ea5e9,#7dd3fc)"></div>
                            <div class="kpi-icon" style="background:#e0f2fe;color:#0284c7"><i
                                    class="fa-solid fa-id-card-clip"></i></div>
                            <div class="kpi-num" style="font-size:1.6rem"><?= number_format($idActiveCount) ?></div>
                            <div class="kpi-label">Active Accounts</div>
                        </div>
                        <div class="kpi-card" style="padding:18px 20px">
                            <div class="kpi-accent" style="background:linear-gradient(90deg,#2e9e63,#6ee7b7)"></div>
                            <div class="kpi-icon" style="background:#e8f8f0;color:#2e9e63"><i
                                    class="fa-solid fa-bullhorn"></i></div>
                            <div class="kpi-num" style="font-size:1.6rem"><?= number_format($kpis['camps']) ?></div>
                            <div class="kpi-label">แคมเปญ Active</div>
                        </div>
                        <div class="kpi-card" style="padding:18px 20px">
                            <div class="kpi-accent" style="background:linear-gradient(90deg,#8b5cf6,#c4b5fd)"></div>
                            <div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i
                                    class="fa-solid fa-chart-pie"></i></div>
                            <div class="kpi-num" style="font-size:1.6rem"><?= $kpis['booking_rate'] ?>%</div>
                            <div class="kpi-label">อัตราการจอง</div>
                        </div>
                    </div>

                    <!-- User table -->
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
                                <tbody>
                                    <?php if (empty($idUsers)): ?>
                                        <tr>
                                            <td colspan="4" style="padding:60px 20px;text-align:center;color:#cbd5e1">
                                                <i class="fa-solid fa-ghost"
                                                    style="font-size:2.5rem;display:block;margin-bottom:12px"></i>
                                                <p
                                                    style="font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.1em">
                                                    ไม่พบรายชื่อที่ค้นหา</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($idUsers as $u):
                                            $initial = mb_substr($u['full_name'], 0, 1);
                                            $statusTH = match ($u['status'] ?? '') { 'student' => 'นักศึกษา', 'staff' => 'บุคลากร', 'other' => 'บุคคลทั่วไป', default => ($u['status'] ?? '—')};
                                            ?>
                                            <tr class="id-user-row"
                                                style="border-bottom:1px solid #f8fafc;transition:background .15s"
                                                onmouseover="this.style.background='#f8fafc'"
                                                onmouseout="this.style.background=''">
                                                <td style="padding:14px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div
                                                            style="width:36px;height:36px;border-radius:10px;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;flex-shrink:0">
                                                            <?= htmlspecialchars($initial) ?></div>
                                                        <div>
                                                            <div style="font-weight:800;color:#0f172a;line-height:1.2">
                                                                <?= htmlspecialchars($u['full_name']) ?></div>
                                                            <div
                                                                style="font-size:10px;color:#94a3b8;font-weight:700;margin-top:2px">
                                                                #<?= htmlspecialchars($u['student_personnel_id'] ?? '—') ?> ·
                                                                <?= htmlspecialchars($statusTH) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:14px 20px">
                                                    <div style="font-size:12px;color:#374151;font-weight:600">
                                                        <?= htmlspecialchars($u['phone_number'] ?: '—') ?></div>
                                                    <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                                                        <?= htmlspecialchars($u['email'] ?? '—') ?></div>
                                                </td>
                                                <td style="padding:14px 20px">
                                                    <div style="font-size:12px;font-weight:700;color:#374151">
                                                        <?= date('d M Y', strtotime($u['created_at'])) ?></div>
                                                    <div style="font-size:10px;color:#94a3b8;margin-top:1px">
                                                        <?= date('H:i', strtotime($u['created_at'])) ?></div>
                                                </td>
                                                <td style="padding:14px 20px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button
                                                            onclick='idOpenView(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                                            onmouseover="this.style.background='#eef2ff';this.style.color='#4f46e5'"
                                                            onmouseout="this.style.background='#fff';this.style.color='#64748b'"
                                                            title="ดูข้อมูล">
                                                            <i class="fa-solid fa-eye" style="font-size:11px"></i>
                                                        </button>
                                                        <button
                                                            onclick='idOpenEdit(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                                            onmouseover="this.style.background='#fffbeb';this.style.color='#d97706'"
                                                            onmouseout="this.style.background='#fff';this.style.color='#64748b'"
                                                            title="แก้ไข">
                                                            <i class="fa-solid fa-pen" style="font-size:11px"></i>
                                                        </button>
                                                        <a href="../admin/user_history.php?id=<?= $u['id'] ?>"
                                                            style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s"
                                                            onmouseover="this.style.background='#fffbeb';this.style.color='#d97706'"
                                                            onmouseout="this.style.background='#fff';this.style.color='#64748b'"
                                                            title="ประวัติการใช้งาน">
                                                            <i class="fa-solid fa-clock-rotate-left" style="font-size:11px"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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
                                <i class="fa-solid fa-user-pen"></i></div>
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
                                <i class="fa-solid fa-user"></i></div>
                            <span style="font-size:15px;font-weight:900;color:#4f46e5">ข้อมูลผู้ใช้งาน</span>
                        </div>
                        <button onclick="document.getElementById('idViewModal').style.display='none'"
                            style="width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer">
                            <i class="fa-solid fa-times" style="font-size:12px"></i>
                        </button>
                    </div>
                    <div style="padding:20px 24px;display:flex;flex-direction:column;gap:12px" id="idViewBody"></div>
                    <div style="padding:14px 24px;border-top:1px solid #f1f5f9;text-align:right">
                        <button onclick="document.getElementById('idViewModal').style.display='none'"
                            style="padding:9px 22px;border-radius:10px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;cursor:pointer">ปิด</button>
                    </div>
                </div>
            </div>

            <!-- ════════════ SECTION: SETTINGS ════════════ -->
            <div id="section-settings" class="portal-section" style="display:none">
                <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

                    <div class="au d1">
                        <div class="sec-title mb-1">Settings &amp; Maintenance</div>
                        <p style="font-size:13px;color:#64748b;margin-bottom:24px">การตั้งค่าระบบและควบคุมสถานะการให้บริการของโปรเจกต์ต่างๆ
                        </p>

                        <!-- System Status Banner (Quick Glance) -->
                        <div id="status-banner" class="rounded-2xl border px-5 py-4 mb-6 flex items-center gap-4" data-state="<?= $allOnline ? 'ok' : 'warn' ?>">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="<?= $allOnline ? 'background:#dcfce7;color:#16a34a' : 'background:#fef3c7;color:#d97706' ?>">
                                <i id="banner-icon" class="fa-solid <?= $allOnline ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> text-base"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div id="banner-title" class="font-bold text-sm text-gray-900">
                                    <?= $allOnline ? 'ระบบทุกโปรเจกต์พร้อมใช้งาน' : 'มีบางโปรเจกต์ปิดปรับปรุงอยู่' ?>
                                </div>
                                <div id="banner-desc" class="text-xs text-gray-500 mt-0.5">
                                    <?= $allOnline ? 'User ทุกคนสามารถเข้าใช้งานได้ตามปกติ' : 'คุณสามารถคลิกเปิดระบบได้จากรายการด้านล่าง' ?>
                                </div>
                            </div>
                        </div>

                        <!-- Maintenance Mode Grid -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                            <?php foreach ($mProjects as $p): 
                                $isActive = $mData[$p['key']] ?? true;
                            ?>
                            <div class="bg-white rounded-2xl border border-gray-100 p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition-shadow" id="card-<?= $p['key'] ?>">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:<?= $p['icon_bg'] ?>; color:<?= $p['icon_color'] ?>;">
                                    <i class="fa-solid <?= $p['icon'] ?> text-sm"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <span class="font-black text-gray-900 text-sm"><?= htmlspecialchars($p['title']) ?></span>
                                        <span class="status-badge <?= $isActive ? 'on' : 'off' ?>" id="badge-<?= $p['key'] ?>">
                                            <span class="status-dot"></span>
                                            <?= $isActive ? 'เปิดใช้งาน' : 'ปรับปรุง' ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-400"><?= htmlspecialchars($p['desc']) ?></p>
                                </div>
                                <div class="toggle-wrap">
                                    <label class="toggle" title="<?= $p['title'] ?>">
                                        <input type="checkbox" data-project="<?= $p['key'] ?>" <?= $isActive ? 'checked' : '' ?> onchange="toggleMaintenance(this)">
                                        <div class="toggle-track"></div>
                                        <div class="toggle-thumb"></div>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- System Info -->
                            <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;padding:24px">
                                <div
                                    style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.12em;margin-bottom:16px">
                                    System Information</div>
                                <div style="display:flex;flex-direction:column;gap:12px">
                                    <?php $sysInfo = [
                                        ['PHP Version', phpversion()],
                                        ['Server', $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'],
                                        ['Memory Limit', ini_get('memory_limit')],
                                        ['Max Upload', ini_get('upload_max_filesize')],
                                        ['Date / Time', date('d M Y · H:i')],
                                    ]; ?>
                                    <?php foreach ($sysInfo as [$label, $val]): ?>
                                        <div
                                            style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9">
                                            <span
                                                style="font-size:12px;font-weight:700;color:#64748b"><?= htmlspecialchars($label) ?></span>
                                            <span
                                                style="font-size:12px;font-weight:800;color:#0f172a;font-family:monospace"><?= htmlspecialchars($val) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;padding:24px">
                                <div
                                    style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.12em;margin-bottom:16px">
                                    Quick Actions</div>
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
                                    <a href="../admin/sentry_test.php"
                                        style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;text-decoration:none;transition:all .2s">
                                        <i class="fa-brands fa-sentry" style="color:#94a3b8"></i> Sentry Monitoring
                                    </a>
                                    <a href="../admin/logout.php"
                                        style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1.5px solid #fee2e2;background:#fff1f2;color:#dc2626;font-size:13px;font-weight:700;text-decoration:none;transition:all .2s">
                                        <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Git Pull History -->
                    <div
                        style="background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;padding:24px;margin-top:24px">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                            <div style="display:flex;align-items:center;gap:10px">
                                <div
                                    style="width:32px;height:32px;background:#f0fdf4;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#16a34a">
                                    <i class="fa-solid fa-code-branch" style="font-size:13px"></i>
                                </div>
                                <div>
                                    <div
                                        style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.12em">
                                        Git Pull History</div>
                                    <div style="font-size:11px;color:#94a3b8;margin-top:1px">ประวัติการอัพเดทระบบ (30
                                        รายการล่าสุด)</div>
                                </div>
                            </div>
                            <?php if ($adminRole === 'superadmin'): ?>
                                <button onclick="triggerGitPull()" id="btnGitPullHistory"
                                    style="display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:10px;border:1.5px solid #d1fae5;background:#f0fdf4;color:#16a34a;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s">
                                    <i class="fa-solid fa-rotate"></i> Pull Now
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($gitPullLogs)): ?>
                            <div style="text-align:center;padding:40px 20px;color:#94a3b8">
                                <i class="fa-solid fa-inbox"
                                    style="font-size:32px;margin-bottom:12px;display:block;opacity:.4"></i>
                                <div style="font-size:13px;font-weight:700">ยังไม่มีประวัติการ Pull</div>
                                <div style="font-size:11px;margin-top:4px">กด "Pull Now" เพื่อเริ่มบันทึกประวัติ</div>
                            </div>
                        <?php else: ?>
                            <div
                                style="display:flex;flex-direction:column;gap:0;border:1px solid #f1f5f9;border-radius:14px;overflow:hidden">
                                <?php foreach ($gitPullLogs as $i => $log):
                                    $isOk = $log['status'] === 'success';
                                    $bgColor = $i % 2 === 0 ? '#fff' : '#fafafa';
                                    $dt = new DateTime($log['created_at']);
                                    ?>
                                    <div
                                        style="display:flex;align-items:center;gap:14px;padding:11px 16px;background:<?= $bgColor ?>;border-bottom:1px solid #f1f5f9">
                                        <!-- Status dot -->
                                        <div
                                            style="width:28px;height:28px;border-radius:8px;background:<?= $isOk ? '#f0fdf4' : '#fef2f2' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                            <i class="fa-solid <?= $isOk ? 'fa-check' : 'fa-xmark' ?>"
                                                style="font-size:11px;color:<?= $isOk ? '#16a34a' : '#dc2626' ?>"></i>
                                        </div>
                                        <!-- Info -->
                                        <div style="flex:1;min-width:0">
                                            <div
                                                style="font-size:12px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                                <?= htmlspecialchars($log['message'] ?? '') ?>
                                            </div>
                                            <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                                                <i class="fa-solid fa-user"
                                                    style="font-size:9px;margin-right:3px"></i><?= htmlspecialchars($log['triggered_by']) ?>
                                                <span style="margin:0 6px;opacity:.4">·</span>
                                                <?= htmlspecialchars($log['detail'] ?? '') ?>
                                            </div>
                                        </div>
                                        <!-- Timestamp -->
                                        <div
                                            style="font-size:11px;color:#94a3b8;white-space:nowrap;text-align:right;flex-shrink:0">
                                            <div style="font-weight:700;color:#374151"><?= $dt->format('d M Y') ?></div>
                                            <div><?= $dt->format('H:i:s') ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div><!-- /section-settings -->

            <!-- ════════════ SECTION: ACTIVITY LOGS ════════════ -->
            <div id="section-activity_logs" class="portal-section"
                style="display:none; height:100%; border-radius:inherit; background:#f8fafc;">
                <iframe src="activity_logs.php?embed=1"
                    style="width:100%; height:100%; border:none; border-radius:inherit;"></iframe>
            </div>

            <!-- ════════════ SECTION: ERROR LOGS ════════════ -->
            <div id="section-error_logs" class="portal-section"
                style="display:none; height:100%; border-radius:inherit; background:#f8fafc;">
                <iframe src="error_logs.php?embed=1"
                    style="width:100%; height:100%; border:none; border-radius:inherit;"></iframe>
            </div>

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
                allRows = Array.from(document.querySelectorAll('#idUserTable tbody .id-user-row'));
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
        ['idEditModal', 'idViewModal'].forEach(function (id) {
            document.getElementById(id).addEventListener('click', function (e) {
                if (e.target === this) this.style.display = 'none';
            });
        });
        // Auto-switch section from URL ?section=identity
        (function () {
            var params = new URLSearchParams(window.location.search);
            var sec = params.get('section');
            if (sec) {
                var btn = document.querySelector('.psb-item[data-section="' + sec + '"]');
                if (btn) switchSection(sec, btn);
            }
            // Auto-dismiss toast
            var toast = document.getElementById('id-toast');
            if (toast) setTimeout(function () { toast.style.transition = 'opacity .5s'; toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 500); }, 3000);
        })();

        /* ── Sidebar Controls ────────────────────────────────────────────────────── */
        function toggleSidebar() {
            var sidebar = document.getElementById('portal-sidebar');
            var icon = document.getElementById('sidebar-toggle-icon');
            var expanded = document.getElementById('psb-user-expanded');
            var collapsed = document.getElementById('psb-user-collapsed');
            sidebar.classList.toggle('collapsed');
            var isCollapsed = sidebar.classList.contains('collapsed');
            icon.style.transform = isCollapsed ? 'rotate(180deg)' : '';
            if (expanded) expanded.style.display = isCollapsed ? 'none' : 'flex';
            if (collapsed) collapsed.style.display = isCollapsed ? 'flex' : 'none';
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