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
    // ปรับปรุงใหม่: ให้ดึงจากแคมเปญทั้งหมดเพื่อให้เห็นภาพรวมระบบ (หรือเฉพาะที่ยังไม่ลบ)
    $quotaRow = $pdo->query("
        SELECT 
            COALESCE(SUM(total_capacity), 0) AS total_quota,
            (SELECT COUNT(*) FROM camp_bookings WHERE status IN ('booked','confirmed')) AS used_quota
        FROM camp_list
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
    if ($pdo->query("SHOW TABLES LIKE 'sys_activity_logs'")->rowCount() > 0) {
        $kpis['logs'] = (int) $pdo->query("SELECT COUNT(*) FROM sys_activity_logs")->fetchColumn();
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
            ['label' => 'Open Insurance Sync Hub', 'url' => 'javascript:switchSection(\'insurance_sync\', document.querySelector(\'[data-section=insurance_sync]\'))', 'primary' => true],
        ]
    ],

    /**
     * ตัวอย่างการเพิ่มโปรเจกต์ในอนาคต:
     * เพียงแค่ก๊อปปี้บล็อกนี้แล้วเปลี่ยน URL/Icon ระบบจะวาดหน้า Layout ให้เองทันที
     */
    [
        'id' => 'privilege_inventory',
        'title' => 'Privileged Access (ISO)',
        'description' => 'ISO 27001 (A.5.18) - บันทึกและควบคุมสิทธิ์การเข้าถึงระดับสูง (Admin/Super Admin) พร้อมหลักฐานการอนุมัติ',
        'icon' => 'fa-shield-halved',
        'bg_color' => 'bg-emerald-50',
        'icon_color' => 'text-emerald-600',
        'border_color' => 'border-emerald-100',
        'allowed_roles' => ['superadmin'],
        'badges' => ['ISO 27001', 'Access Control'],
        'actions' => [
            ['label' => 'Open Inventory', 'url' => 'javascript:switchSection(\'privilege_inventory\', document.querySelector(\'[data-section=privilege_inventory]\'))', 'primary' => true],
        ]
    ],
    [
        'id' => 'line_messaging',
        'title' => 'LINE Messaging API',
        'description' => 'จัดการการแจ้งเตือนผ่าน LINE — ตั้งค่า Webhook URL, Channel Token และทดสอบการส่งข้อความ Push รายบุคคล',
        'icon' => 'fa-brands fa-line',
        'bg_color' => 'bg-green-50',
        'icon_color' => 'text-green-500',
        'border_color' => 'border-green-100',
        'allowed_roles' => ['superadmin'],
        'badges' => ['Notifications', 'Webhooks'],
        'actions' => [
            ['label' => 'Open Settings & Test', 'url' => 'javascript:switchSection(\'line_settings\', document.querySelector(\'[data-section=line_settings]\'))', 'primary' => true],
        ]
    ],
    [
        'id' => 'live_support_chat',
        'title' => 'Live Support Chat',
        'description' => 'ระบบแชทตอบกลับผู้ใช้งานแบบ Real-time — จัดการคำขอความช่วยเหลือและให้คำปรึกษาแก่ผู้ใช้งานผ่านหน้าเว็บ',
        'icon' => 'fa-comments',
        'bg_color' => 'bg-blue-50',
        'icon_color' => 'text-blue-600',
        'border_color' => 'border-blue-100',
        'allowed_roles' => ['admin', 'superadmin', 'editor'],
        'staff_visible' => true,
        'badges' => ['Live Chat', 'Support'],
        'actions' => [
            ['label' => 'Open Chat Center', 'url' => 'support_chat.php', 'primary' => true],
        ]
    ],
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
    'privilege_inventory' => 'tools',
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

/**
 * (4) PRIVILEGE INVENTORY FETCH (ISO 27001)
 */
$privilegeInventory = [];
if ($adminRole === 'superadmin') {
    try {
        $sql = "SELECT p.*, a.full_name as admin_full_name, a.username as admin_username 
                FROM sys_admin_privilege_inventory p
                LEFT JOIN sys_admins a ON p.user_id = a.id
                ORDER BY p.assigned_at DESC";
        $privilegeInventory = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* silent */ }
}

/**
 * (5) ADMIN LIST FOR DROPDOWNS
 */
$adminListForSelect = $pdo->query("SELECT id, full_name, username FROM sys_admins ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

/**
 * (6) ANNOUNCEMENTS FETCH & ACTIONS
 */
$announcements_list = [];
$ann_saved = false;
$ann_error = '';

// ตรวจสอบและสร้างตารางถ้ายังไม่มี
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        type ENUM('info', 'warning', 'success', 'urgent') DEFAULT 'info',
        target_audience ENUM('all', 'student', 'staff', 'other') DEFAULT 'all',
        priority TINYINT UNSIGNED DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        show_once TINYINT(1) DEFAULT 1,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        read_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_announcement_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unq_ann_user (announcement_id, user_id),
        FOREIGN KEY (announcement_id) REFERENCES sys_announcements(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) { /* silent fail */ }

// จัดการ POST actions สำหรับประกาศ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ann_action'])) {
    validate_csrf_or_die();
    $annAction = $_POST['ann_action'];

    if ($annAction === 'create' || $annAction === 'edit') {
        $annId        = (int)($_POST['ann_id'] ?? 0);
        $annTitle     = trim($_POST['ann_title'] ?? '');
        $annContent   = trim($_POST['ann_content'] ?? '');
        $annType      = in_array($_POST['ann_type'] ?? '', ['info','warning','success','urgent']) ? $_POST['ann_type'] : 'info';
        $annPriority  = max(0, min(255, (int)($_POST['ann_priority'] ?? 0)));
        $annAudience  = in_array($_POST['ann_audience'] ?? '', ['all','student','staff','other']) ? $_POST['ann_audience'] : 'all';
        $annActive    = isset($_POST['ann_active']) ? 1 : 0;
        $annShowOnce  = isset($_POST['ann_show_once']) ? 1 : 0;
        $annImageUrl  = trim($_POST['ann_image_url'] ?? '');
        $annStart     = $_POST['ann_start'] ?? null;
        $annEnd       = $_POST['ann_end'] ?? null;
        $annStart     = $annStart ?: null;
        $annEnd       = $annEnd ?: null;

        if ($annTitle && $annContent) {
            try {
                if ($annAction === 'create') {
                    $pdo->prepare("
                        INSERT INTO sys_announcements
                            (title, content, image_url, type, priority, target_audience, is_active, show_once, start_date, end_date, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$annTitle, $annContent, $annImageUrl ?: null, $annType, $annPriority, $annAudience, $annActive, $annShowOnce, $annStart, $annEnd, $_SESSION['admin_id'] ?? null]);
                    log_activity('Announcement Created', "สร้างประกาศ: $annTitle");
                } else {
                    $pdo->prepare("
                        UPDATE sys_announcements
                        SET title=?, content=?, image_url=?, type=?, priority=?, target_audience=?, is_active=?, show_once=?, start_date=?, end_date=?
                        WHERE id=?
                    ")->execute([$annTitle, $annContent, $annImageUrl ?: null, $annType, $annPriority, $annAudience, $annActive, $annShowOnce, $annStart, $annEnd, $annId]);
                    log_activity('Announcement Updated', "แก้ไขประกาศ: $annTitle");
                }
                $ann_saved = true;
            } catch (PDOException $e) {
                $ann_error = $e->getMessage();
            }
        } else {
            $ann_error = 'กรุณากรอกหัวข้อและเนื้อหาให้ครบถ้วน';
        }
    } elseif ($annAction === 'delete') {
        $delId = (int)($_POST['ann_id'] ?? 0);
        if ($delId > 0) {
            try {
                $pdo->prepare("DELETE FROM sys_announcement_reads WHERE announcement_id = ?")->execute([$delId]);
                $pdo->prepare("DELETE FROM sys_announcements WHERE id = ?")->execute([$delId]);
                log_activity('Announcement Deleted', "ลบประกาศ ID: $delId");
                $ann_saved = true;
            } catch (PDOException $e) {
                $ann_error = $e->getMessage();
            }
        }
    } elseif ($annAction === 'toggle') {
        $togId     = (int)($_POST['ann_id'] ?? 0);
        $togActive = (int)($_POST['ann_active_val'] ?? 0);
        if ($togId > 0) {
            try {
                $pdo->prepare("UPDATE sys_announcements SET is_active = ? WHERE id = ?")->execute([$togActive, $togId]);
                $ann_saved = true;
            } catch (PDOException $e) { /* silent */ }
        }
    }
}

try {
    $announcements_list = $pdo->query("
        SELECT a.*, 
               (SELECT COUNT(*) FROM sys_announcement_reads r WHERE r.announcement_id = a.id) AS read_count
        FROM sys_announcements a
        ORDER BY a.priority DESC, a.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $announcements_list = []; // ตารางยังไม่มี
}

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> - Central Intelligence HUB</title>
    <link rel="icon" href="<?= !empty(SITE_LOGO) ? '../' . SITE_LOGO : '../favicon.ico' ?>">

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
            localStorage.setItem('portal_sidebar_collapsed', isCollapsed ? '1' : '0');
        };

        // Auto-apply sidebar state on load
        window.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('portal_sidebar_collapsed') === '1') {
                var sidebar = document.getElementById('portal-sidebar');
                if (sidebar) {
                    sidebar.classList.add('collapsed');
                    var icon = document.getElementById('sidebar-toggle-icon');
                    if (icon) icon.style.transform = 'rotate(180deg)';
                    var expanded = document.getElementById('psb-user-expanded');
                    var collapsed = document.getElementById('psb-user-collapsed');
                    if (expanded) expanded.style.display = 'none';
                    if (collapsed) collapsed.style.display = 'flex';
                }
            }
        });

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
        <div style="padding:10px;flex:1;overflow:hidden;display:flex;flex-direction:column;">
            <button class="psb-item psb-active" data-section="dashboard" onclick="switchSection('dashboard',this)">
                <div class="psb-icon"><i class="fa-solid fa-chart-pie" style="color:#059669"></i></div>
                <span class="psb-label" style="color:#059669;font-weight:900">Dashboard</span>
            </button>
            <button class="psb-item" data-section="ai_assistant" onclick="switchSection('ai_assistant',this)">
                <div class="psb-icon"><i class="fa-solid fa-wand-magic-sparkles" style="color:#8b5cf6"></i></div>
                <span class="psb-label" style="color:#7c3aed;font-weight:900">AI Assistant</span>
            </button>
            <button class="psb-item" data-section="identity" onclick="switchSection('identity',this)">
                <div class="psb-icon"><i class="fa-solid fa-id-card-clip" style="color:#2563eb"></i></div>
                <span class="psb-label" style="color:#1d4ed8;font-weight:900">Identity & Governance</span>
            </button>
            <button class="psb-item" data-section="insurance_sync" onclick="switchSection('insurance_sync',this)">
                <div class="psb-icon"><i class="fa-solid fa-shield-halved" style="color:#0ea5e9"></i></div>
                <span class="psb-label" style="color:#0284c7;font-weight:900">Insurance Hub</span>
            </button>
            <?php if ($adminRole === 'superadmin' || !empty($_SESSION['access_system_logs'])): ?>
            <button class="psb-item" data-section="activity_logs" onclick="switchSection('activity_logs',this)">
                <div class="psb-icon"><i class="fa-solid fa-file-lines" style="color:#64748b"></i></div>
                <span class="psb-label" style="color:#475569;font-weight:900">Activity Logs</span>
            </button>
            <button class="psb-item" data-section="error_logs" onclick="switchSection('error_logs',this)">
                <div class="psb-icon"><i class="fa-solid fa-bug" style="color:#ef4444"></i></div>
                <span class="psb-label" style="color:#dc2626;font-weight:900">Error Logs</span>
            </button>
            <?php endif; ?>
            <?php if ($adminRole === 'superadmin'): ?>
            <button class="psb-item" data-section="privilege_inventory" onclick="switchSection('privilege_inventory',this)">
                <div class="psb-icon"><i class="fa-solid fa-shield-halved" style="color:#10b981"></i></div>
                <span class="psb-label" style="color:#059669;font-weight:900">ISO Governance</span>
            </button>
            <?php endif; ?>
            <button class="psb-item" data-section="announcements" onclick="switchSection('announcements',this)">
                <div class="psb-icon"><i class="fa-solid fa-bullhorn" style="color:#7c3aed"></i></div>
                <span class="psb-label" style="color:#6d28d9;font-weight:900">ประกาศ</span>
            </button>

            <div style="flex:1"></div> <!-- Spacer to push settings to bottom -->

            <?php if ($adminRole === 'superadmin' || !empty($_SESSION['access_site_settings'])): ?>
            <button class="psb-item" data-section="settings" onclick="switchSection('settings',this)">
                <div class="psb-icon"><i class="fa-solid fa-gear" style="color:#d97706"></i></div>
                <span class="psb-label" style="color:#b45309;font-weight:900">Settings</span>
            </button>
            <?php endif; ?>
        </div>
    </nav>

    <div id="app-shell" style="flex:1;min-width:0;background:#f4f7f5;height:100vh;overflow:hidden;display:flex;flex-direction:column;">

        <!-- ══════════════════ HEADER ══════════════════ -->
        <?php include __DIR__ . '/_partials/header.php'; ?>

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
                                    // Robust access control check
                                    $hasAccess = false;
                                    if ($adminRole === 'superadmin') {
                                        $hasAccess = true;
                                    } else {
                                        // Check project-specific flags
                                        if ($proj['id'] === 'e_borrow' && !empty($_SESSION['access_eborrow'])) $hasAccess = true;
                                        elseif ($proj['id'] === 'e_campaign' && !empty($_SESSION['access_ecampaign'])) $hasAccess = true;
                                        elseif ($proj['id'] === 'insurance_sync' && !empty($_SESSION['access_insurance'])) $hasAccess = true;
                                        elseif ($proj['id'] === 'system_logs' && !empty($_SESSION['access_system_logs'])) $hasAccess = true;
                                        elseif ($proj['id'] === 'line_messaging' && !empty($_SESSION['access_site_settings'])) $hasAccess = true;
                                        elseif ($proj['id'] === 'privilege_inventory' && $adminRole === 'superadmin') $hasAccess = true;
                                        elseif ($proj['id'] === 'identity_governance' && in_array($adminRole, ['admin', 'superadmin'])) $hasAccess = true;
                                        
                                        // Fallback to role-based or staff-visibility
                                        if (!$hasAccess) {
                                            if (in_array($adminRole, $proj['allowed_roles'])) $hasAccess = true;
                                            if ($isStaff && ($proj['staff_visible'] ?? false)) $hasAccess = true;
                                        }
                                    }

                                    if (!$hasAccess) continue;
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

            <!-- ════════════ SECTION: ANNOUNCEMENTS ════════════ -->
            <div id="section-announcements" class="portal-section" 
                style="<?= $activeSection==='announcements'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <div class="px-5 md:px-8 py-8">

                    <?php if ($ann_saved): ?>
                    <div style="display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#15803d">
                        <i class="fa-solid fa-circle-check"></i> บันทึกข้อมูลสำเร็จ
                    </div>
                    <?php endif; ?>
                    <?php if ($ann_error): ?>
                    <div style="display:flex;align-items:center;gap:10px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#dc2626">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($ann_error) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
                        <div>
                            <div class="sec-title" style="margin-bottom:4px">📢 จัดการประกาศ</div>
                            <p style="font-size:13px;color:#64748b">สร้างและแก้ไขประกาศที่จะปรากฏเป็น Popup ให้ผู้ใช้เห็นเมื่อเข้าหน้า Hub</p>
                        </div>
                        <button onclick="annOpenForm('create')"
                            style="background:#7c3aed;color:#fff;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:700;border:none;cursor:pointer;display:flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(124,58,237,.3)">
                            <i class="fa-solid fa-plus"></i> สร้างประกาศใหม่
                        </button>
                    </div>

                    <!-- Announcement List -->
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <?php if (empty($announcements_list)): ?>
                        <div style="text-align:center;padding:60px 20px;background:#fff;border-radius:24px;border:1.5px dashed #e2e8f0;color:#94a3b8">
                            <i class="fa-solid fa-bullhorn" style="font-size:2.5rem;margin-bottom:12px;display:block;opacity:.3"></i>
                            <p style="font-weight:700;font-size:14px">ยังไม่มีประกาศ</p>
                            <p style="font-size:12px;margin-top:4px">กดปุ่ม "สร้างประกาศใหม่" เพื่อเพิ่มประกาศแรก</p>
                        </div>
                        <?php else: ?>
                        <?php
                            $typeStyles = [
                                'info'    => ['bg'=>'#eff6ff','color'=>'#1d4ed8','icon'=>'fa-bullhorn',        'label'=>'ข้อมูลทั่วไป'],
                                'warning' => ['bg'=>'#fffbeb','color'=>'#b45309','icon'=>'fa-triangle-exclamation','label'=>'แจ้งเตือน'],
                                'success' => ['bg'=>'#f0fdf4','color'=>'#15803d','icon'=>'fa-circle-check',   'label'=>'ข่าวดี'],
                                'urgent'  => ['bg'=>'#fff1f2','color'=>'#dc2626','icon'=>'fa-siren-on',       'label'=>'ด่วน!'],
                            ];
                        ?>
                        <?php foreach ($announcements_list as $ann): ?>
                        <?php $ts = $typeStyles[$ann['type']] ?? $typeStyles['info']; ?>
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #f1f5f9;padding:18px 22px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
                            <!-- Icon -->
                            <div style="width:44px;height:44px;border-radius:14px;background:<?= $ts['bg'] ?>;color:<?= $ts['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <i class="fa-solid <?= $ts['icon'] ?> text-lg"></i>
                            </div>
                            <!-- Info -->
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:2px">
                                    <span style="font-weight:800;font-size:14px;color:#0f172a"><?= htmlspecialchars($ann['title']) ?></span>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $ts['bg'] ?>;color:<?= $ts['color'] ?>"><?= $ts['label'] ?></span>
                                    <?php if ($ann['target_audience'] !== 'all'): ?>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#f1f5f9;color:#64748b"><?= htmlspecialchars($ann['target_audience']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!$ann['is_active']): ?>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#fef2f2;color:#dc2626">ปิดอยู่</span>
                                    <?php endif; ?>
                                </div>
                                <p style="font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:500px"><?= htmlspecialchars(mb_substr($ann['content'], 0, 100)) ?>...</p>
                                <div style="display:flex;align-items:center;gap:12px;margin-top:4px;font-size:11px;color:#94a3b8;font-weight:600">
                                    <span><i class="fa-solid fa-eye mr-1"></i><?= (int)$ann['read_count'] ?> คนอ่านแล้ว</span>
                                    <?php if ($ann['end_date']): ?><span><i class="fa-regular fa-calendar-xmark mr-1"></i>หมดอายุ <?= date('d/m/Y', strtotime($ann['end_date'])) ?></span><?php endif; ?>
                                    <?php if ($ann['image_url']): ?><span><i class="fa-solid fa-image mr-1"></i>มีรูปภาพ</span><?php endif; ?>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div style="display:flex;gap:6px;flex-shrink:0">
                                <!-- Toggle -->
                                <form method="POST" style="display:inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="ann_action" value="toggle">
                                    <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                                    <input type="hidden" name="ann_active_val" value="<?= $ann['is_active'] ? '0' : '1' ?>">
                                    <button type="submit" title="<?= $ann['is_active'] ? 'ปิด' : 'เปิด' ?>ประกาศ"
                                        style="width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;color:<?= $ann['is_active'] ? '#22c55e' : '#94a3b8' ?>;display:flex;align-items:center;justify-content:center">
                                        <i class="fa-solid <?= $ann['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?> text-lg"></i>
                                    </button>
                                </form>
                                <!-- Edit -->
                                <button onclick="annOpenForm('edit', <?= htmlspecialchars(json_encode($ann, JSON_UNESCAPED_UNICODE)) ?>)"
                                    style="width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;color:#6366f1;display:flex;align-items:center;justify-content:center">
                                    <i class="fa-solid fa-pen-to-square text-sm"></i>
                                </button>
                                <!-- Delete -->
                                <button onclick="annConfirmDelete(<?= $ann['id'] ?>, '<?= htmlspecialchars(addslashes($ann['title'])) ?>')"
                                    style="width:34px;height:34px;border-radius:10px;border:1px solid #fee2e2;background:#fff1f2;cursor:pointer;color:#ef4444;display:flex;align-items:center;justify-content:center">
                                    <i class="fa-solid fa-trash text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>
            </div><!-- /section-announcements -->

            <!-- Announcement Form Modal -->
            <div id="ann-form-modal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                <div style="background:#fff;border-radius:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 30px 60px rgba(0,0,0,.2)">
                    <div style="padding:24px 28px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:10">
                        <div>
                            <p style="font-size:11px;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.1em;margin-bottom:2px">ระบบประกาศ</p>
                            <h3 id="ann-form-title" style="font-size:18px;font-weight:900;color:#0f172a">สร้างประกาศใหม่</h3>
                        </div>
                        <button onclick="annCloseForm()" style="width:36px;height:36px;border-radius:10px;border:none;background:#f1f5f9;color:#64748b;cursor:pointer;display:flex;align-items:center;justify-content:center">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <form method="POST" id="ann-form" style="padding:24px 28px;display:flex;flex-direction:column;gap:16px">
                        <?php csrf_field(); ?>
                        <input type="hidden" id="ann-form-action" name="ann_action" value="create">
                        <input type="hidden" id="ann-form-id" name="ann_id" value="0">

                        <div>
                            <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">หัวข้อประกาศ <span style="color:red">*</span></label>
                            <input type="text" id="ann-f-title" name="ann_title" required class="premium-input" placeholder="เช่น แจ้งวันหยุดให้บริการ">
                        </div>

                        <div>
                            <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">เนื้อหา <span style="color:red">*</span></label>
                            <textarea id="ann-f-content" name="ann_content" required rows="4" class="premium-input" style="resize:vertical" placeholder="รายละเอียดของประกาศ..."></textarea>
                        </div>

                        <div>
                            <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">URL รูปภาพประกอบ (ถ้ามี)</label>
                            <input type="url" id="ann-f-image" name="ann_image_url" class="premium-input" placeholder="https://example.com/image.jpg">
                            <p style="font-size:11px;color:#94a3b8;margin-top:4px">ใส่ URL ของรูปภาพ (JPG, PNG, WebP)</p>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">ประเภท</label>
                                <select id="ann-f-type" name="ann_type" class="premium-input">
                                    <option value="info">📘 ข้อมูลทั่วไป</option>
                                    <option value="warning">⚠️ แจ้งเตือน</option>
                                    <option value="success">✅ ข่าวดี</option>
                                    <option value="urgent">🚨 ด่วน!</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">กลุ่มเป้าหมาย</label>
                                <select id="ann-f-audience" name="ann_audience" class="premium-input">
                                    <option value="all">ทุกคน</option>
                                    <option value="student">นักศึกษา</option>
                                    <option value="staff">บุคลากร</option>
                                    <option value="other">บุคคลทั่วไป</option>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">วันเริ่ม (ไม่บังคับ)</label>
                                <input type="date" id="ann-f-start" name="ann_start" class="premium-input">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">วันหมดอายุ (ไม่บังคับ)</label>
                                <input type="date" id="ann-f-end" name="ann_end" class="premium-input">
                            </div>
                        </div>

                        <div>
                            <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">ลำดับความสำคัญ (0-255)</label>
                            <input type="number" id="ann-f-priority" name="ann_priority" min="0" max="255" value="0" class="premium-input">
                        </div>

                        <div style="display:flex;gap:20px">
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
                                <input type="checkbox" id="ann-f-active" name="ann_active" value="1" checked style="width:16px;height:16px;accent-color:#7c3aed">
                                เปิดใช้งานทันที
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
                                <input type="checkbox" id="ann-f-show-once" name="ann_show_once" value="1" checked style="width:16px;height:16px;accent-color:#7c3aed">
                                แสดงครั้งเดียวต่อ User
                            </label>
                        </div>

                        <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid #f1f5f9">
                            <button type="button" onclick="annCloseForm()"
                                style="flex:none;padding:11px 20px;border-radius:12px;border:1.5px solid #e2e8f0;background:#fff;font-size:13px;font-weight:700;color:#64748b;cursor:pointer">
                                ยกเลิก
                            </button>
                            <button type="submit"
                                style="flex:1;padding:11px 20px;border-radius:12px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 4px 14px rgba(124,58,237,.3)">
                                <i class="fa-solid fa-save mr-1.5"></i> บันทึกประกาศ
                            </button>
                        </div>
                    </form>
                </div>
            </div><!-- /ann-form-modal -->

            <!-- Delete form (hidden) -->
            <form id="ann-delete-form" method="POST" style="display:none">
                <?php csrf_field(); ?>
                <input type="hidden" name="ann_action" value="delete">
                <input type="hidden" id="ann-delete-id" name="ann_id" value="">
            </form>

            <!-- ════════════ SECTION: IDENTITY & GOVERNANCE ════════════ -->
            <div id="section-identity" class="portal-section" 
                style="<?= $activeSection==='identity'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <div class="px-5 md:px-8 py-8">

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
                            (<?= number_format($totalIdUsers) ?>)</button>
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
                        // Stats are pre-calculated in identity_queries.php via SQL
                        $totalUsersCalc = $totalIdUsers;
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
                                    style="margin-left:auto;font-size:11px;font-weight:700;color:#94a3b8"><?= number_format($totalIdUsers) ?>
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
                                        <!-- Dynamically loaded via AJAX -->
                                        <tr>
                                            <td colspan="4" style="padding:40px;text-align:center;color:#94a3b8">
                                                <i class="fa-solid fa-spinner fa-spin mr-2"></i> กำลังโหลดข้อมูล...
                                            </td>
                                        </tr>
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
                                        <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                            <th style="padding:16px 20px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em"><i class="fa-solid fa-user-shield mr-2"></i>Admin Detail</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:150px"><i class="fa-solid fa-key mr-2"></i>Access Level</th>
                                            <th style="padding:16px 20px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idAdminTbody">
                                        <?php foreach ($allAdmins as $adm): 
                                            $role = $adm['role'] ?? 'admin';
                                            $roleIcon = '<i class="fa-solid fa-user-shield"></i>';
                                            $roleLabel = 'Standard Admin';
                                            $roleColor = '#3b82f6';
                                            $roleBg = '#eff6ff';
                                            $roleBorder = '#bfdbfe';

                                            if ($role === 'superadmin') {
                                                $roleIcon = '<i class="fa-solid fa-crown"></i>';
                                                $roleLabel = 'Super Administrator';
                                                $roleColor = '#7c3aed';
                                                $roleBg = '#f5f3ff';
                                                $roleBorder = '#ddd6fe';
                                            } elseif ($role === 'editor') {
                                                $roleIcon = '<i class="fa-solid fa-pen-to-square"></i>';
                                                $roleLabel = 'Content Editor';
                                                $roleColor = '#e11d48';
                                                $roleBg = '#fff1f2';
                                                $roleBorder = '#fecdd3';
                                            }
                                        ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-admin-row hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:16px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg, <?= $roleColor ?>, <?= $roleColor ?>dd);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;box-shadow:0 4px 10px -2px <?= $roleColor ?>66">
                                                            <?= mb_substr($adm['full_name'], 0, 1) ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:800;color:#1e293b;font-size:13.5px"><?= htmlspecialchars($adm['full_name']) ?></div>
                                                            <div style="font-size:11px;color:#64748b;font-weight:600">@<?= htmlspecialchars($adm['username']) ?> · <?= htmlspecialchars($adm['email']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:center">
                                                    <div style="display:inline-flex;align-items:center;gap:8px;padding:4px 12px;border-radius:8px;background:<?= $roleBg ?>;color:<?= $roleColor ?>;border:1.5px solid <?= $roleBorder ?>;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:0.05em">
                                                        <?= $roleIcon ?> <?= $roleLabel ?>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:right">
                                                    <div style="display:flex;gap:8px;justify-content:flex-end">
                                                        <button onclick='openEditAdminModal(<?= json_encode($adm) ?>)' 
                                                            class="id-action-btn"
                                                            style="width:34px;height:34px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-pen-to-square"></i></button>
                                                        <?php if ($adm['id'] != $_SESSION['admin_id']): ?>
                                                            <form method="POST" style="display:inline" onsubmit="return confirm('ยืนยันการลบ Admin ท่านนี้?')">
                                                                <input type="hidden" name="action" value="delete_admin">
                                                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                                                <?php csrf_field(); ?>
                                                                <button type="submit" 
                                                                    class="id-action-btn-danger"
                                                                    style="width:34px;height:34px;border-radius:10px;border:1.5px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-trash-can"></i></button>
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

                    <!-- PANEL: Staff Matrix -->
                    <div id="id-panel-staff" class="id-panel">
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div style="width:4px;height:18px;background:#2563eb;border-radius:99px;flex-shrink:0"></div>
                                <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Staff Permission Matrix</span>
                            </div>
                            <!-- Matrix Legend -->
                            <div style="padding:12px 24px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;flex-wrap:wrap;gap:20px;align-items:center">
                                <div style="font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">Matrix Legend:</div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#ea580c"><i class="fa-solid fa-shield-halved"></i></span> Admin
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#7c3aed"><i class="fa-solid fa-crown"></i></span> Super
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#2563eb"><i class="fa-solid fa-pen-to-square"></i></span> Editor
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#16a34a"><i class="fa-solid fa-user"></i></span> Standard
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#94a3b8">
                                    <i class="fa-solid fa-circle-xmark"></i> No Access
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <i class="fa-solid fa-circle-check text-emerald-500"></i> Active Flag
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <i class="fa-solid fa-circle-minus text-slate-200"></i> Disabled
                                </div>
                            </div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idStaffTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                            <th style="padding:16px 20px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em"><i class="fa-solid fa-user-gear mr-2"></i>Staff Details</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px"><i class="fa-solid fa-box-archive mr-2"></i>e-Borrow</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px"><i class="fa-solid fa-bullhorn mr-2"></i>e-Campaign</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Insurance Sync"><i class="fa-solid fa-shield-heart"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="System Logs"><i class="fa-solid fa-list-ul"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="System Settings"><i class="fa-solid fa-sliders"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:100px">Status</th>
                                            <th style="padding:16px 20px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idStaffTbody">
                                        <?php foreach ($allStaff as $st):
                                            $isActive = ($st['account_status'] ?? 'active') === 'active';
                                            
                                            // e-Borrow Matrix Mapping
                                            $ebAccess = (int)($st['access_eborrow'] ?? 1);
                                            $ebRole = $st['role'] ?? 'none';
                                            $ebIcon = '<i class="fa-solid fa-circle-xmark" style="color:#cbd5e1;font-size:14px"></i>';
                                            if ($ebAccess) {
                                                if ($ebRole === 'admin') {
                                                    $ebIcon = '<div style="background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Borrow: Administrator"><i class="fa-solid fa-shield-halved"></i></div>';
                                                } elseif ($ebRole === 'librarian' || $ebRole === 'technician' || $ebRole === 'supervisor') {
                                                    $ebIcon = '<div style="background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Borrow: Staff/Librarian"><i class="fa-solid fa-pen-to-square"></i></div>';
                                                } elseif ($ebRole === 'employee') {
                                                    $ebIcon = '<div style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Borrow: Standard User"><i class="fa-solid fa-user"></i></div>';
                                                }
                                            }

                                            // e-Campaign Matrix Mapping
                                            $ecAccess = (int)($st['access_ecampaign'] ?? 0);
                                            $ecRole = $st['ecampaign_role'] ?? 'none';
                                            $ecIcon = '<i class="fa-solid fa-circle-xmark" style="color:#cbd5e1;font-size:14px"></i>';
                                            if ($ecAccess) {
                                                if ($ecRole === 'admin' || $ecRole === 'superadmin') {
                                                    $ecIcon = '<div style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Campaign: Administrator"><i class="fa-solid fa-crown"></i></div>';
                                                } else {
                                                    $ecIcon = '<div style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Campaign: Editor"><i class="fa-solid fa-file-signature"></i></div>';
                                                }
                                            }

                                            // Portal Extensions
                                            $insAccess = (int)($st['access_insurance'] ?? 0);
                                            $logsAccess = (int)($st['access_system_logs'] ?? 0);
                                            $settAccess = (int)($st['access_site_settings'] ?? 0);
                                            
                                            $insIcon = $insAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $logsIcon = $logsAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $settIcon = $settAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-staff-row hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:16px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div style="width:36px;height:36px;border-radius:10px;background:<?= $isActive ? 'linear-gradient(135deg,#3b82f6,#1d4ed8)' : '#f1f5f9' ?>;color:<?= $isActive ? '#fff' : '#94a3b8' ?>;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px">
                                                            <?= mb_substr($st['full_name'], 0, 1) ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:800;color:#1e293b;font-size:13.5px"><?= htmlspecialchars($st['full_name']) ?></div>
                                                            <div style="font-size:11px;color:#64748b;font-weight:600">@<?= htmlspecialchars($st['username']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:center"><?= $ebIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $ecIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $insIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $logsIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $settIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center">
                                                    <span style="font-size:10px;font-weight:900;padding:4px 10px;border-radius:99px;background:<?= $isActive ? '#f0fdf4;color:#16a34a;border:1px solid #bbf7d0' : '#fef2f2;color:#dc2626;border:1px solid #fecaca' ?>"><?= strtoupper($st['account_status']) ?></span>
                                                </td>
                                                <td style="padding:16px 20px;text-align:right">
                                                    <div style="display:flex;gap:8px;justify-content:flex-end">
                                                        <button onclick='openEditStaffModal(<?= json_encode($st) ?>)' class="id-action-btn" style="width:34px;height:34px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-pen-to-square"></i></button>
                                                        <form method="POST" style="display:inline" onsubmit="return confirm('ยืนยันการลบ Staff ท่านนี้?')">
                                                            <input type="hidden" name="action" value="delete_staff">
                                                            <input type="hidden" name="sf_id" value="<?= $st['id'] ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit" class="id-action-btn-danger" style="width:34px;height:34px;border-radius:10px;border:1.5px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-trash-can"></i></button>
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
                <!-- UNIFIED IDENTITY GOVERNANCE MODAL (ISO 27001 COMPLIANT) -->
                <div id="idGovModal" style="display:none;position:fixed;inset:0;z-index:300;background:rgba(15,23,42,.6);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:28px;width:100%;max-width:720px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.3);display:flex;flex-direction:column;max-height:90vh">
                        <!-- Modal Header -->
                        <div style="padding:24px 30px;background:linear-gradient(90deg,#f8fafc,#fff);border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                            <div style="display:flex;align-items:center;gap:15px">
                                <div id="govModalIcon" style="width:45px;height:45px;border-radius:14px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 10px rgba(37,99,235,0.1)">
                                    <i class="fa-solid fa-user-shield"></i>
                                </div>
                                <div>
                                    <h3 id="govModalTitle" style="margin:0;font-size:18px;font-weight:900;color:#0f172a">จัดการสิทธิ์ผู้ใช้งานระบบ</h3>
                                    <p style="margin:2px 0 0;font-size:12px;color:#64748b;font-weight:600">Identity & Access Governance Interface</p>
                                </div>
                            </div>
                            <button onclick="document.getElementById('idGovModal').style.display='none'" style="width:36px;height:36px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#94a3b8;cursor:pointer;transition:all 0.2s" onmouseover="this.style.color='#ef4444';this.style.borderColor='#fecaca'" onmouseout="this.style.color='#94a3b8';this.style.borderColor='#e2e8f0'">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Modal Body (Scrollable) -->
                        <form method="POST" id="idGovForm" style="overflow-y:auto;padding:30px">
                            <input type="hidden" name="action" id="govAction" value="save_identity_gov">
                            <input type="hidden" name="target_id" id="govTargetId">
                            <input type="hidden" name="target_type" id="govTargetType"> <!-- 'admin' or 'staff' -->
                            <?php csrf_field(); ?>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px">
                                <!-- Column 1: Core Identity -->
                                <div style="display:flex;flex-direction:column;gap:20px">
                                    <div style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-id-card"></i> ข้อมูลพื้นฐานบัญชี
                                    </div>
                                    
                                    <div>
                                        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">ชื่อ-นามสกุล <span style="color:#ef4444">*</span></label>
                                        <input type="text" name="full_name" id="govFullName" required class="premium-input" style="width:100%">
                                    </div>
                                    
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                                        <div>
                                            <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">Username</label>
                                            <input type="text" name="username" id="govUsername" required class="premium-input" style="width:100%">
                                        </div>
                                        <div>
                                            <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">สถานะบัญชี</label>
                                            <select name="status" id="govStatus" class="premium-input" style="width:100%;background-image:none">
                                                <option value="active">Active</option>
                                                <option value="suspended">Suspended</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">อีเมล</label>
                                        <input type="email" name="email" id="govEmail" class="premium-input" style="width:100%" placeholder="— ไม่มีข้อมูล —">
                                    </div>

                                    <div>
                                        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">รหัสผ่าน <span style="font-weight:normal;color:#94a3b8;font-size:11px">(เว้นว่างหากไม่เปลี่ยน)</span></label>
                                        <input type="password" name="password" id="govPassword" class="premium-input" style="width:100%" placeholder="••••••••">
                                    </div>
                                </div>

                                <!-- Column 2: System Roles -->
                                <div style="display:flex;flex-direction:column;gap:20px">
                                    <div style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-shield-halved"></i> กำหนดสิทธิ์รายระบบ
                                    </div>

                                    <!-- e-Borrow Card -->
                                    <div id="govEbCard" onclick="toggleGovAccess('govEbAccess', 'govEbRole', this)" class="premium-role-card orange p-4" style="border-radius:18px;border:1.5px solid #fed7aa;background:#fffaf5;cursor:pointer;transition:all 0.2s">
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <div id="govEbIcon" style="width:32px;height:32px;background:#ffedd5;color:#ea580c;border-radius:8px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-box-archive"></i></div>
                                                <span style="font-weight:900;font-size:13px;color:#9a3412">e-Borrow & Inventory</span>
                                            </div>
                                            <input type="checkbox" id="govEbAccess" name="eb_access" value="1" checked style="width:18px;height:18px;cursor:pointer" onclick="event.stopPropagation(); syncGovUI('govEbAccess', 'govEbRole', 'govEbCard')">
                                        </div>
                                        <select name="eb_role" id="govEbRole" class="premium-input" style="width:100%;font-size:12px;border-color:#fed7aa" onclick="event.stopPropagation()">
                                            <option value="employee">Employee (เจ้าหน้าที่ทั่วไป)</option>
                                            <option value="librarian">Librarian (บรรณารักษ์)</option>
                                            <option value="technician">Technician (ช่างเทคนิค)</option>
                                            <option value="supervisor">Supervisor (หัวหน้างาน)</option>
                                            <option value="admin">System Administrator (ผู้ดูแลสูงสุด)</option>
                                        </select>
                                    </div>

                                    <!-- e-Campaign Card -->
                                    <div id="govEcCard" onclick="toggleGovAccess('govEcAccess', 'govEcRole', this)" class="premium-role-card blue p-4" style="border-radius:18px;border:1.5px solid #bfdbfe;background:#f0f7ff;cursor:pointer;transition:all 0.2s">
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <div id="govEcIcon" style="width:32px;height:32px;background:#dbeafe;color:#2563eb;border-radius:8px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-bullhorn"></i></div>
                                                <span style="font-weight:900;font-size:13px;color:#1e40af">e-Campaign System</span>
                                            </div>
                                            <input type="checkbox" name="ec_access" id="govEcAccess" value="1" style="width:18px;height:18px;cursor:pointer" onclick="event.stopPropagation(); syncGovUI('govEcAccess', 'govEcRole', 'govEcCard')">
                                        </div>
                                        <select name="ec_role" id="govEcRole" class="premium-input" style="width:100%;font-size:12px;border-color:#bfdbfe" onclick="event.stopPropagation()">
                                            <option value="editor">Content Editor (จัดการกิจกรรม)</option>
                                            <option value="admin">System Administrator (ผู้ดูแลสูงสุด)</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Portal Role Card (Only for Admins) -->
                                    <div id="govAdminOnlyCard" style="display:none;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:18px;padding:15px">
                                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                            <div style="width:30px;height:30px;background:#ede9fe;color:#7c3aed;border-radius:8px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-crown"></i></div>
                                            <span style="font-weight:900;font-size:13px;color:#5b21b6">Portal Management</span>
                                        </div>
                                        <select name="admin_role" id="govAdminRole" class="premium-input" style="width:100%;font-size:12px;border-color:#ddd6fe">
                                            <option value="admin">Standard Admin</option>
                                            <option value="editor">Standard Editor</option>
                                            <option value="superadmin">Super Administrator (FULL CONTROL)</option>
                                        </select>
                                    </div>

                                    <!-- Portal Extension Rights -->
                                    <div style="display:flex;flex-direction:column;gap:12px">
                                        <div style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:8px">
                                            <i class="fa-solid fa-puzzle-piece"></i> ส่วนขยาย (Extensions)
                                        </div>
                                        <div style="display:grid;grid-template-columns:1fr;gap:10px">
                                            <!-- Insurance -->
                                            <div onclick="document.getElementById('govInsAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-shield-heart text-emerald-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Insurance Sync Hub</span>
                                                </div>
                                                <input type="checkbox" name="ins_access" id="govInsAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Logs -->
                                            <div onclick="document.getElementById('govLogsAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-list-ul text-slate-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">System Activity Logs</span>
                                                </div>
                                                <input type="checkbox" name="logs_access" id="govLogsAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Settings -->
                                            <div onclick="document.getElementById('govSettAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-sliders text-slate-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Global Site Settings</span>
                                                </div>
                                                <input type="checkbox" name="sett_access" id="govSettAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Audit Justification -->
                            <div style="margin-top:30px;padding-top:20px;border-top:1.5px dashed #e2e8f0">
                                <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:900;color:#dc2626;margin-bottom:8px">
                                    <i class="fa-solid fa-shield-check"></i> เหตุผลความจำเป็นในการปรับสิทธิ์ (Justification) <span style="color:#ef4444">*</span>
                                </label>
                                <textarea name="justification" id="govJustification" required class="premium-input" style="width:100%;height:70px;padding:12px;font-size:13px;border-color:#fecaca" placeholder="ตัวอย่าง: ได้รับมอบหมายให้ดูแลระบบ e-Borrow เพิ่มเติมตามคำสั่งคณะ..."></textarea>
                                <p style="margin:6px 0 0;font-size:10px;color:#94a3b8;font-weight:700"><i class="fa-solid fa-info-circle"></i> ISO 27001 Requirement: ทุกการปรับเปลี่ยนสิทธิ์ต้องมีการระบุเหตุผลความจำเป็นทางธุรกิจ</p>
                            </div>
                        </form>

                        <!-- Modal Footer -->
                        <div style="padding:24px 30px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;gap:12px">
                            <button type="button" onclick="document.getElementById('idGovModal').style.display='none'" style="flex:1;padding:13px;border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-weight:800;font-size:14px;cursor:pointer">ยกเลิก</button>
                            <button type="button" onclick="confirmGovSubmit()" style="flex:2;padding:13px;border-radius:14px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-weight:900;font-size:14px;cursor:pointer;box-shadow:0 10px 20px -5px rgba(37,99,235,0.3);display:flex;align-items:center;justify-content:center;gap:8px">
                                <i class="fa-solid fa-check-double"></i> ยืนยันการปรับปรุงสิทธิ์
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Add Privilege Modal -->
                <div id="privModal" style="display:none;position:fixed;inset:0;z-index:500;background:rgba(15,23,42,.6);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:28px;width:100%;max-width:480px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden">
                        <div style="padding:24px;background:#fcfdfd;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                            <h3 style="margin:0;font-size:18px;font-weight:900;color:#0f172a">🛡️ บันทึกการถือสิทธิ์ระดับสูง</h3>
                            <button type="button" onclick="document.getElementById('privModal').style.display='none'" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:20px"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <form id="privForm" style="padding:24px" enctype="multipart/form-data">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ผู้รับสิทธิ์ (Admin)</label>
                                    <select name="user_id" class="premium-input" style="width:100%" required>
                                        <option value="">-- เลือกเจ้าหน้าที่ --</option>
                                        <?php foreach ($adminListForSelect as $adm): ?>
                                            <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['full_name']) ?> (@<?= htmlspecialchars($adm['username']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">บทบาท/ระดับสิทธิ์</label>
                                    <input type="text" name="role_assigned" class="premium-input" style="width:100%" required placeholder="เช่น Super Admin">
                                </div>
                            </div>
                            <div style="margin-bottom:16px">
                                <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">เหตุผลความจำเป็น (Justification)</label>
                                <textarea name="justification" class="premium-input" style="width:100%;height:60px" required placeholder="ระบุเหตุผลในการให้สิทธิ์..."></textarea>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ผู้อนุมัติ (Approved By)</label>
                                    <input type="text" name="approved_by" class="premium-input" style="width:100%" required placeholder="ชื่อผู้อนุมัติ">
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">วันหมดอายุ (ถ้ามี)</label>
                                    <input type="date" name="expiry_date" class="premium-input" style="width:100%">
                                </div>
                            </div>
                            <div style="margin-bottom:24px">
                                <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">หลักฐานการอนุมัติ (PDF/Image)</label>
                                <input type="file" name="approval_doc" class="premium-input" style="width:100%" accept=".pdf,image/*">
                            </div>
                            <div style="display:flex;gap:12px">
                                <button type="button" onclick="document.getElementById('privModal').style.display='none'" style="flex:1;padding:12px;border-radius:14px;background:#f1f5f9;color:#475569;font-weight:800;border:none;cursor:pointer">ยกเลิก</button>
                                <button type="submit" id="btnSavePriv" style="flex:1;padding:12px;border-radius:14px;background:#2e9e63;color:#fff;font-weight:800;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.2)">บันทึกรายการ</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                    function openAddPrivilegeModal() {
                        document.getElementById('privModal').style.display = 'flex';
                    }
                    document.getElementById('privForm')?.addEventListener('submit', function(e) {
                        e.preventDefault();
                        const fd = new FormData(this);
                        const btn = document.getElementById('btnSavePriv');
                        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> กำลังบันทึก...';
                        
                        fetch('ajax_privilege_inventory.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(d => {
                            if(d.status === 'success') {
                                Swal.fire({ icon: 'success', title: 'สำเร็จ', text: d.message }).then(() => location.reload());
                            } else {
                                Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: d.message });
                                btn.disabled = false; btn.textContent = 'บันทึกรายการ';
                            }
                        })
                        .catch(err => {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้' });
                            btn.disabled = false; btn.textContent = 'บันทึกรายการ';
                        });
                    });
                </script>
            <?php endif; ?>

            <?php /*
                DEVELOPER NOTE: HOW TO ADD NEW SECTIONS
                To add a new page/section, follow this template to ensure layout stability:
                <div id="section-NAME" class="portal-section" style="<?= $activeSection==='NAME'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                    <?php include __DIR__ . '/_partials/NAME.php'; ?>
                </div>
            */ ?>

            <div id="section-settings" class="portal-section"
                style="<?= $activeSection==='settings'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_site_settings'])) {
                    include __DIR__ . '/_partials/settings.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">You do not have permission to manage site settings.</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: AI ASSISTANT ════════════ -->
            <div id="section-ai_assistant" class="portal-section"
                style="<?= $activeSection==='ai_assistant'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); overflow:hidden;">
                <?php include __DIR__ . '/_partials/ai_assistant.php'; ?>
            </div>

            <!-- ════════════ SECTION: INSURANCE SYNC HUB ════════════ -->
            <div id="section-insurance_sync" class="portal-section"
                style="<?= $activeSection==='insurance_sync'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_insurance'])) {
                    include __DIR__ . '/_partials/insurance_sync.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED</div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: CLINIC DATA ════════════ -->
            <div id="section-clinic_data" class="portal-section"
                style="<?= $activeSection==='clinic_data'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/clinic_data.php'; ?>
            </div>

            <!-- ════════════ SECTION: ACTIVITY LOGS ════════════ -->
            <div id="section-activity_logs" class="portal-section"
                style="<?= $activeSection==='activity_logs'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_system_logs'])) {
                    include __DIR__ . '/_partials/activity_logs.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">You do not have permission to view activity logs.</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: ERROR LOGS ════════════ -->
            <div id="section-error_logs" class="portal-section"
                style="<?= $activeSection==='error_logs'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_system_logs'])) {
                    include __DIR__ . '/_partials/error_logs.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">You do not have permission to view system error logs.</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: EMAIL LOGS ════════════ -->
            <div id="section-email_logs" class="portal-section"
                style="<?= $activeSection==='email_logs'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_system_logs'])) {
                    include __DIR__ . '/_partials/email_logs.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED</div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: SMTP SETTINGS ════════════ -->
            <div id="section-smtp_settings" class="portal-section"
                style="<?= $activeSection==='smtp_settings'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_site_settings'])) {
                    include __DIR__ . '/_partials/smtp_settings.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED</div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: SENTRY TEST ════════════ -->
            <div id="section-sentry_test" class="portal-section"
                style="<?= $activeSection==='sentry_test'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/sentry_test.php'; ?>
            </div>

            <!-- ════════════ SECTION: LINE MESSAGING API ════════════ -->
            <div id="section-line_settings" class="portal-section"
                style="<?= $activeSection==='line_settings'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_site_settings'])) {
                    include __DIR__ . '/_partials/line_settings.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED</div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: PRIVILEGE INVENTORY (ISO 27001) ════════════ -->
            <div id="section-privilege_inventory" class="portal-section" 
                style="<?= $activeSection==='privilege_inventory'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <div class="px-5 md:px-8 py-8">
                    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
                        <div>
                            <div class="sec-title" style="margin-bottom:2px">🛡️ Privileged Access Inventory</div>
                            <p style="font-size:13px;color:#64748b">ISO 27001:2022 Control A.5.18 - การจัดการสิทธิ์การเข้าถึงที่ได้รับสิทธิพิเศษ</p>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center">
                            <button onclick="openAddPrivilegeModal()"
                                style="background:#2e9e63;color:#fff;padding:8px 16px;border-radius:11px;font-size:12px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.25)">
                                <i class="fa-solid fa-plus mr-1"></i> บันทึกการให้สิทธิ์ใหม่
                            </button>
                        </div>
                    </div>

                    <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                        <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px;background:#fcfdfc">
                            <i class="fa-solid fa-list-check text-emerald-600"></i>
                            <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">บันทึกประวัติการถือสิทธิ์ระดับสูง</span>
                        </div>
                        <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:13px">
                                <thead>
                                    <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ผู้ได้รับสิทธิ์</th>
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ระดับสิทธิ์ / บทบาท</th>
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">วันที่ได้รับ / หมดอายุ</th>
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ผู้อนุมัติ (Approved By)</th>
                                        <th style="padding:12px 20px;text-align:center;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($privilegeInventory)): ?>
                                        <tr>
                                            <td colspan="5" style="padding:40px;text-align:center;color:#94a3b8">
                                                <i class="fa-solid fa-folder-open text-4xl mb-3 block opacity-20"></i>
                                                <p class="font-bold">ยังไม่มีการบันทึกข้อมูลในระบบ Inventory</p>
                                                <p class="text-[11px]">กรุณาคลิก "บันทึกการให้สิทธิ์ใหม่" เพื่อเริ่มจัดเก็บประภูมิตามมาตรฐาน ISO</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($privilegeInventory as $row): 
                                            $isExpired = $row['expiry_date'] && strtotime($row['expiry_date']) < time();
                                            $statusColor = $row['status'] == 1 && !$isExpired ? '#16a34a' : '#dc2626';
                                            $statusBg = $row['status'] == 1 && !$isExpired ? '#f0fdf4' : '#fef2f2';
                                            $statusText = $row['status'] == 1 && !$isExpired ? 'Active' : ($isExpired ? 'Expired' : 'Revoked');
                                        ?>
                                        <tr style="border-bottom:1px solid #f1f5f9">
                                            <td style="padding:14px 20px">
                                                <div style="font-weight:750;color:#0f172a"><?= htmlspecialchars($row['admin_full_name'] ?? '—') ?></div>
                                                <div style="font-size:11px;color:#64748b">@<?= htmlspecialchars($row['admin_username'] ?? 'unknown') ?></div>
                                            </td>
                                            <td style="padding:14px 20px">
                                                <div style="font-size:12px;font-weight:800;color:#1e293b"><?= htmlspecialchars($row['role_assigned'] ?? '—') ?></div>
                                                <div style="font-size:10px;color:#94a3b8;max-width:200px" class="truncate" title="<?= htmlspecialchars($row['justification'] ?? '') ?>">
                                                    Reason: <?= htmlspecialchars($row['justification'] ?? '—') ?>
                                                </div>
                                            </td>
                                            <td style="padding:14px 20px">
                                                <div style="font-size:12px;font-weight:700;color:#334155"><?= date('d M Y', strtotime($row['assigned_at'])) ?></div>
                                                <div style="font-size:10px;color:<?= $isExpired ? '#ef4444' : '#94a3b8' ?>">
                                                    Exp: <?= $row['expiry_date'] ? date('d M Y', strtotime($row['expiry_date'])) : 'Permanent' ?>
                                                </div>
                                            </td>
                                            <td style="padding:14px 20px">
                                                <div style="font-size:12px;font-weight:700;color:#475569"><?= htmlspecialchars($row['approved_by'] ?? '—') ?></div>
                                                <?php if ($row['document_path']): ?>
                                                    <a href="<?= htmlspecialchars($row['document_path']) ?>" target="_blank" style="font-size:10px;color:#2563eb;text-decoration:none">
                                                        <i class="fa-solid fa-file-pdf mr-1"></i> ดูเอกสารประกอบ
                                                    </a>
                                                <?php else: ?>
                                                    <span style="font-size:10px;color:#cbd5e1;font-style:italic">No document</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 20px;text-align:center">
                                                <span style="padding:3px 10px;border-radius:99px;font-size:10px;font-weight:800;background:<?= $statusBg ?>;color:<?= $statusColor ?>;border:1px solid <?= $statusColor ?>40">
                                                    <?= $statusText ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="padding:15px 24px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                            <div style="font-size:11px;color:#94a3b8;font-weight:700">
                                <i class="fa-solid fa-circle-info mr-1"></i> ข้อมูลนี้ถูกใช้เพื่อการ Audit มาตรฐานความปลอดภัยสารสนเทศ
                            </div>
                        </div>
                    </div>
                </div>
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
            if (!badge || !dot || !label) return;
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
            openGovModal('admin', 'add');
        }

        function openAddStaffModal() {
            openGovModal('staff', 'add');
        }

        function openEditAdminModal(adm) {
            openGovModal('admin', 'edit', adm);
        }

        function openEditStaffModal(st) {
            openGovModal('staff', 'edit', st);
        }

        /**
         * Unified Governance Modal Handler
         */
        function openGovModal(type, mode, data = null) {
            const m = document.getElementById('idGovModal');
            const f = document.getElementById('idGovForm');
            const title = document.getElementById('govModalTitle');
            const icon = document.getElementById('govModalIcon');
            
            f.reset();
            document.getElementById('govJustification').value = '';
            document.getElementById('govTargetType').value = type;
            document.getElementById('govTargetId').value = data ? data.id : '';
            document.getElementById('govAction').value = (mode === 'add' ? 'add_identity_gov' : 'save_identity_gov');
            
            // Set visuals based on type
            if (type === 'admin') {
                title.textContent = (mode === 'add' ? 'เพิ่ม System Admin' : 'จัดการสิทธิ์ System Admin');
                icon.style.background = '#f5f3ff';
                icon.style.color = '#7c3aed';
                icon.innerHTML = '<i class="fa-solid fa-crown"></i>';
                document.getElementById('govAdminOnlyCard').style.display = 'block';
                document.getElementById('govEbCard').style.opacity = '0.5'; // Adms might not need borrow roles
                document.getElementById('govEcCard').style.opacity = '1';
            } else {
                title.textContent = (mode === 'add' ? 'เพิ่ม Staff Record' : 'จัดการสิทธิ์ Staff & Roles');
                icon.style.background = '#eff6ff';
                icon.style.color = '#2563eb';
                icon.innerHTML = '<i class="fa-solid fa-id-card-clip"></i>';
                document.getElementById('govAdminOnlyCard').style.display = 'none';
                document.getElementById('govEbCard').style.opacity = '1';
                document.getElementById('govEcCard').style.opacity = '1';
            }

            // Fill data if editing
            if (data) {
                document.getElementById('govFullName').value = data.full_name || '';
                document.getElementById('govUsername').value = data.username || '';
                document.getElementById('govEmail').value = data.email || '';
                document.getElementById('govStatus').value = data.account_status || data.status || 'active';
                
                    if (type === 'admin') {
                        document.getElementById('govAdminRole').value = data.role || 'admin';
                    } else {
                        document.getElementById('govEbAccess').checked = (data.access_eborrow === undefined) ? true : (parseInt(data.access_eborrow) === 1);
                        document.getElementById('govEbRole').value = data.role || 'employee';
                        document.getElementById('govEcAccess').checked = parseInt(data.access_ecampaign) === 1;
                        document.getElementById('govEcRole').value = data.ecampaign_role || 'editor';
                        
                        document.getElementById('govInsAccess').checked = parseInt(data.access_insurance) === 1;
                        document.getElementById('govLogsAccess').checked = parseInt(data.access_system_logs) === 1;
                        document.getElementById('govSettAccess').checked = parseInt(data.access_site_settings) === 1;
                    }
                } else {
                    // Reset Extension Checkboxes for new records
                    document.getElementById('govInsAccess').checked = false;
                    document.getElementById('govLogsAccess').checked = false;
                    document.getElementById('govSettAccess').checked = false;
                }
            // Update UI States
            syncGovUI('govEbAccess', 'govEbRole', 'govEbCard');
            syncGovUI('govEcAccess', 'govEcRole', 'govEcCard');

            m.style.display = 'flex';
        }

        /**
         * Toggle helper for the whole card
         */
        function toggleGovAccess(checkId, selectId, cardEl) {
            const cb = document.getElementById(checkId);
            cb.checked = !cb.checked;
            syncGovUI(checkId, selectId, cardEl.id);
        }

        /**
         * Visual Sync for Roles
         */
        function syncGovUI(checkId, selectId, cardId) {
            const cb = document.getElementById(checkId);
            const sel = document.getElementById(selectId);
            const card = document.getElementById(cardId);
            
            if (cb.checked) {
                sel.disabled = false;
                sel.style.opacity = '1';
                card.style.filter = 'none';
                card.style.background = (cardId === 'govEcCard' ? '#f0f7ff' : '#fffaf5');
            } else {
                sel.disabled = true;
                sel.style.opacity = '0.5';
                card.style.filter = 'grayscale(0.6)';
                card.style.background = '#f8fafc';
            }
        }


        function confirmGovSubmit() {
            const reason = document.getElementById('govJustification').value.trim();
            if (!reason) {
                Swal.fire({
                    title: 'ระบุเหตุผล',
                    text: 'กรุณากรอกเหตุผลความจำเป็นในการปรับสิทธิ์ก่อนบันทึกครับ (ISO 27001 Requirement)',
                    icon: 'warning',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }

            Swal.fire({
                title: 'ยืนยันการบันทึกสิทธิ์?',
                text: "การเปลี่ยนแปลงสิทธิ์จะถูกบันทึกเข้าสู่ Audit Log พร้อมเหตุผลที่คุณระบุ และจะมีผลต่อการเข้าถึงระบบทันที",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'ใช่, ยืนยันการบันทึก',
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'กำลังบันทึกข้อมูล...',
                        text: 'กรุณารอสักครู่ ระบบกำลังดำเนินการปรับปรุงสิทธิ์และบันทึก Audit Log',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    document.getElementById('idGovForm').submit();
                }
            });
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
        /* ── Identity & Governance AJAX Pagination ── */
        (function () {
            var currentPage = 1;
            var pageSize = 25;
            var searchQuery = '';
            var isInitialLoad = true;

            function loadUsers() {
                var tbody = document.getElementById('idUserTbody');
                if (!tbody) return;

                // Show loading state
                tbody.style.opacity = '0.5';
                
                var url = 'ajax_identity_users.php?page=' + currentPage + '&pageSize=' + pageSize + '&search=' + encodeURIComponent(searchQuery);

                fetch(url)
                    .then(res => res.json())
                    .then(res => {
                        tbody.style.opacity = '1';
                        if (res.status === 'success') {
                            renderRows(res.data);
                            renderPagination(res.pagination);
                        } else {
                            tbody.innerHTML = '<tr><td colspan="4" style="padding:40px;text-align:center;color:#ef4444">เกิดข้อผิดพลาด: ' + res.message + '</td></tr>';
                        }
                    })
                    .catch(err => {
                        tbody.style.opacity = '1';
                        tbody.innerHTML = '<tr><td colspan="4" style="padding:40px;text-align:center;color:#ef4444">ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้</td></tr>';
                    });
            }

            function renderRows(users) {
                var tbody = document.getElementById('idUserTbody');
                if (!tbody) return;

                if (users.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="padding:60px;text-align:center;color:#94a3b8"><i class="fa-solid fa-ghost text-3xl mb-3 block"></i>ไม่พบข้อมูลผู้ใช้งาน</td></tr>';
                    return;
                }

                var statusMap = { student: 'นักศึกษา', staff: 'บุคลากร', other: 'บุคคลทั่วไป' };
                
                var html = users.map(function(u) {
                    var statusTH = statusMap[u.status] || u.status_other || 'ไม่ระบุ';
                    var initial = (u.full_name || '?').charAt(0);
                    var dateObj = new Date(u.created_at.replace(' ', 'T'));
                    var dateStr = dateObj.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: 'numeric' });
                    var timeStr = dateObj.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });

                    return `
                        <tr style="border-bottom:1px solid #f1f5f9" class="id-user-row animate-fade-in">
                            <td style="padding:14px 20px">
                                <div style="display:flex;align-items:center;gap:12px">
                                    <div style="width:38px;height:38px;border-radius:11px;background:#f1f5f9;color:#64748b;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">
                                        ${initial}
                                    </div>
                                    <div>
                                        <div style="font-weight:750;color:#0f172a">${u.full_name}</div>
                                        <div style="font-size:10px;color:#94a3b8;font-weight:700;margin-top:2px">
                                            #${u.student_personnel_id || '—'} · ${statusTH}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:14px 20px">
                                <div style="font-size:12px;color:#374151;font-weight:600">${u.phone_number || '—'}</div>
                                <div style="font-size:11px;color:#94a3b8;margin-top:2px">${u.email || '—'}</div>
                            </td>
                            <td style="padding:14px 20px">
                                <div style="font-size:12px;font-weight:700;color:#374151">${dateStr}</div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:1px">${timeStr}</div>
                            </td>
                            <td style="padding:14px 20px;text-align:right">
                                <div style="display:flex;gap:6px;justify-content:flex-end">
                                    <button onclick='idOpenView(${JSON.stringify(u).replace(/'/g, "&apos;")})'
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                        title="ดูข้อมูล">
                                        <i class="fa-solid fa-eye" style="font-size:11px"></i>
                                    </button>
                                    <button onclick='idOpenEdit(${JSON.stringify(u).replace(/'/g, "&apos;")})'
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                        title="แก้ไข">
                                        <i class="fa-solid fa-pen" style="font-size:11px"></i>
                                    </button>
                                    <a href="../admin/user_history.php?id=${u.id}"
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s"
                                        onmouseover="this.style.background='#fffbeb';this.style.color='#d97706'"
                                        onmouseout="this.style.background='#fff';this.style.color='#64748b'"
                                        title="ประวัติการใช้งาน">
                                        <i class="fa-solid fa-clock-rotate-left" style="font-size:11px"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>`;
                }).join('');
                tbody.innerHTML = html;
            }

            function renderPagination(p) {
                var info = document.getElementById('id-page-info');
                if (info) {
                    var from = p.total === 0 ? 0 : (p.page - 1) * p.pageSize + 1;
                    var to = Math.min(p.page * p.pageSize, p.total);
                    info.textContent = p.total === 0 ? 'ไม่พบรายการ' : from + '–' + to + ' จาก ' + p.total.toLocaleString();
                }

                var prev = document.getElementById('id-page-prev');
                var next = document.getElementById('id-page-next');
                if (prev) {
                    prev.disabled = p.page <= 1;
                    prev.style.opacity = p.page <= 1 ? '.35' : '1';
                }
                if (next) {
                    next.disabled = p.page >= p.totalPages;
                    next.style.opacity = p.page >= p.totalPages ? '.35' : '1';
                }
            }

            window.idUniversalFilter = function (val) {
                // If on users tab, use AJAX. Otherwise use client-side filter
                const activeTab = document.querySelector('.id-tab.active');
                if (activeTab && activeTab.dataset.tab === 'users') {
                    searchQuery = val;
                    currentPage = 1;
                    clearTimeout(window._idSearchTimer);
                    window._idSearchTimer = setTimeout(loadUsers, 400);
                } else {
                    // Original client-side filter for admins/staff
                    val = val.toLowerCase().trim();
                    const activePanel = document.querySelector('.id-panel.active');
                    if (!activePanel) return;
                    const rows = activePanel.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        if (row.cells.length < 2) return;
                        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
                    });
                }
            };

            window.idSetPageSize = function (size) {
                pageSize = size;
                currentPage = 1;
                loadUsers();
                document.querySelectorAll('.id-ps-btn').forEach(function (b) {
                    var active = parseInt(b.dataset.size) === size;
                    b.style.background = active ? '#2e9e63' : '#f8fafc';
                    b.style.color = active ? '#fff' : '#374151';
                    b.style.borderColor = active ? '#2e9e63' : '#e2e8f0';
                });
            };

            window.idPrevPage = function () { if (currentPage > 1) { currentPage--; loadUsers(); } };
            window.idNextPage = function () { currentPage++; loadUsers(); };

            if (isInitialLoad) {
                isInitialLoad = false;
                loadUsers();
            }
        })();

        /**
         * switchIdTab - Handles switching between Identity sub-panels
         */
        function switchIdTab(tabName, btn) {
            // Update tabs
            document.querySelectorAll('.id-tab').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            // Update panels
            document.querySelectorAll('.id-panel').forEach(p => p.classList.remove('active'));
            const targetPanel = document.getElementById('id-panel-' + tabName);
            if (targetPanel) targetPanel.classList.add('active');

            // Show/Hide relevant Add buttons (Superadmin only)
            const addAdmin = document.getElementById('id-btn-add-admin');
            const addStaff = document.getElementById('id-btn-add-staff');
            if (addAdmin) addAdmin.style.display = (tabName === 'admins') ? 'block' : 'none';
            if (addStaff) addStaff.style.display = (tabName === 'staff') ? 'block' : 'none';
        }

        // Close modals on backdrop click
        ['idEditModal', 'idViewModal', 'idGovModal', 'privModal'].forEach(function (id) {
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
            const actionText = active ? 'เปิดใช้งาน' : 'ปิดปรับปรุง';
            const confirmText = active ? 'ใช่, เปิดระบบ' : 'ใช่, ปิดปรับปรุงระบบ';
            const confirmColor = active ? '#10b981' : '#f43f5e';

            // Reset input state immediately (we will set it after confirmation)
            input.checked = !active;

            Swal.fire({
                title: `ยืนยันการ${actionText}ระบบ?`,
                text: `คุณกำลังจะทำการ${actionText}โปรเจกต์ ${project} ยืนยันการดำเนินการหรือไม่?`,
                icon: active ? 'info' : 'warning',
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                cancelButtonColor: '#94a3b8',
                confirmButtonText: confirmText,
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Proceed with update
                    input.checked = active;
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
                                Swal.fire('ผิดพลาด', d.message || 'Unknown error', 'error');
                            }
                        })
                        .catch(() => {
                            input.checked = !active;
                            updateMaintenanceUI(project, !active);
                            showPortalToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                        });
                }
            });
        }

        // ── ฟังก์ชัน Announcement Form ─────────────────────────────────────────
        window.annOpenForm = function(mode, data) {
            const modal = document.getElementById('ann-form-modal');
            document.getElementById('ann-form-title').textContent = mode === 'create' ? 'สร้างประกาศใหม่' : 'แก้ไขประกาศ';
            document.getElementById('ann-form-action').value      = mode;
            document.getElementById('ann-form-id').value          = data ? data.id : 0;
            document.getElementById('ann-f-title').value          = data ? (data.title    || '') : '';
            document.getElementById('ann-f-content').value        = data ? (data.content  || '') : '';
            document.getElementById('ann-f-image').value          = data ? (data.image_url|| '') : '';
            document.getElementById('ann-f-type').value           = data ? (data.type || 'info') : 'info';
            document.getElementById('ann-f-audience').value       = data ? (data.target_audience || 'all') : 'all';
            document.getElementById('ann-f-start').value          = data ? (data.start_date || '') : '';
            document.getElementById('ann-f-end').value            = data ? (data.end_date   || '') : '';
            document.getElementById('ann-f-priority').value       = data ? (data.priority || 0) : 0;
            document.getElementById('ann-f-active').checked       = data ? (parseInt(data.is_active) === 1) : true;
            document.getElementById('ann-f-show-once').checked    = data ? (parseInt(data.show_once) === 1) : true;
            modal.style.display = 'flex';
        };

        window.annCloseForm = function() {
            document.getElementById('ann-form-modal').style.display = 'none';
        };

        window.annConfirmDelete = function(id, title) {
            Swal.fire({
                title: 'ลบประกาศ?',
                html: `ต้องการลบประกาศ <b>"${title}"</b> ออกจากระบบ?<br><small style="color:#94a3b8">การลบจะไม่สามารถกู้คืนได้</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'ลบเลย',
                cancelButtonText: 'ยกเลิก',
            }).then(result => {
                if (result.isConfirmed) {
                    document.getElementById('ann-delete-id').value = id;
                    document.getElementById('ann-delete-form').submit();
                }
            });
        };

        document.getElementById('ann-form-modal')?.addEventListener('click', function(e) {
            if (e.target === this) window.annCloseForm();
        });

        <?php if ($ann_saved): ?>
        switchSection('announcements', document.querySelector('[data-section="announcements"]'));
        <?php endif; ?>

    </script>
</body>

</html>