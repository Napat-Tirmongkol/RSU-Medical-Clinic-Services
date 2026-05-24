<?php
/**
 * portal/_dashboard_data.php
 * Data preparation for dashboard page — extracted from monolithic index.php.
 * Includes: action handlers, KPI queries, recent activity, pinned projects, maintenance, etc.
 * Required globals: $pdo, $adminRole, $isStaff, $isSuper, $registryOnly (from _init.php).
 */
declare(strict_types=1);

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
require_once __DIR__ . '/../includes/maintenance_helper.php';
$mData = maint_load();
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
    [
        'key' => 'gold_card_apply',
        'title' => 'สมัครบัตรทอง',
        'desc' => 'ปุ่ม "สมัครบัตรทอง" ในหน้า User Hub — ปิดเมื่อหยุดรับสมัคร',
        'icon' => 'fa-shield-heart',
        'icon_color' => '#d97706',
        'icon_bg' => '#fef3c7',
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
    'borrows_overdue' => 0,
    'logs' => 0,
    'total_quota' => 0,
    'used_quota' => 0,
    'booking_rate' => 0,
    'bookings_today' => 0,
    'errors_today'   => 0,
    // Staff-focused signals (today's check-in workload)
    'slots_today'        => 0, // จำนวน time slots ของวันนี้
    'appts_today'        => 0, // นัดหมายของวันนี้ (booked + confirmed + completed; ไม่นับ cancelled)
    'checkins_today'     => 0, // เช็คอินสำเร็จวันนี้ (attended_at = วันนี้)
    'pending_today'      => 0, // นัดวันนี้ที่ยังไม่เช็คอิน (slot_date = วันนี้ AND attended_at IS NULL AND ไม่ถูกยกเลิก)
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

    // "งานวันนี้" — actionable signals สำหรับ admin daily user
    try {
        $kpis['bookings_today'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM camp_bookings WHERE created_at >= NOW() - INTERVAL 24 HOUR"
        )->fetchColumn();
    } catch (PDOException $e) { /* ignore */ }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'sys_error_logs'")->rowCount() > 0) {
            $kpis['errors_today'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM sys_error_logs WHERE created_at >= NOW() - INTERVAL 24 HOUR"
            )->fetchColumn();
        }
    } catch (PDOException $e) { /* ignore */ }

    // Staff workload — เน้นการเช็คอินของวันนี้ (อิง slot_date = CURDATE())
    try {
        $kpis['slots_today'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM camp_slots WHERE slot_date = CURDATE()"
        )->fetchColumn();

        $todayRow = $pdo->query("
            SELECT
                SUM(CASE WHEN b.status NOT IN ('cancelled','cancelled_by_admin','expired') THEN 1 ELSE 0 END) AS appts_today,
                SUM(CASE WHEN DATE(b.attended_at) = CURDATE() THEN 1 ELSE 0 END) AS checkins_today,
                SUM(CASE WHEN b.attended_at IS NULL
                          AND b.status IN ('booked','confirmed') THEN 1 ELSE 0 END) AS pending_today
            FROM camp_bookings b
            JOIN camp_slots s ON b.slot_id = s.id
            WHERE s.slot_date = CURDATE()
        ")->fetch(PDO::FETCH_ASSOC);

        $kpis['appts_today']    = (int) ($todayRow['appts_today']    ?? 0);
        $kpis['checkins_today'] = (int) ($todayRow['checkins_today'] ?? 0);
        $kpis['pending_today']  = (int) ($todayRow['pending_today']  ?? 0);
    } catch (PDOException $e) { /* ignore */ }

    // Equipment borrows (optional module)
    if ($pdo->query("SHOW TABLES LIKE 'borrow_records'")->rowCount() > 0) {
        $kpis['borrows'] = (int) $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE approval_status = 'pending'")->fetchColumn();
        $kpis['borrows_overdue'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM borrow_records
             WHERE status = 'borrowed'
               AND approval_status IN ('approved','staff_added')
               AND due_date < CURDATE()"
        )->fetchColumn();
    }

    // Activity logs count (optional module)
    if ($pdo->query("SHOW TABLES LIKE 'sys_activity_logs'")->rowCount() > 0) {
        $kpis['logs'] = (int) $pdo->query("SELECT COUNT(*) FROM sys_activity_logs")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Portal Stats Fetch Error: " . $e->getMessage());
}

/**
 * (1.1) FETCH USER PINS
 * ดึงรายการโปรเจกต์ที่ปักหมุดไว้จาก Database
 */
$userPins = [];
try {
    $userId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'staff';
    if ($userId) {
        $stmt = $pdo->prepare("SELECT project_id FROM sys_portal_pins WHERE user_id = ? AND user_type = ?");
        $stmt->execute([$userId, $userType]);
        $userPins = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) { /* Table might not exist yet */ }

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
        'id' => 'asset_management',
        'title' => 'ครุภัณฑ์สำนักงาน',
        'description' => 'ทะเบียนครุภัณฑ์สำนักงาน — บันทึก ติดตาม และจัดการทรัพย์สินของหน่วยงาน พร้อมประวัติการเปลี่ยนแปลงและจุดใช้งาน',
        'icon' => 'fa-boxes-stacked',
        'bg_color' => 'bg-green-50',
        'icon_color' => 'text-green-600',
        'border_color' => 'border-green-100',
        'allowed_roles' => ['admin', 'superadmin', 'editor'],
        'staff_visible' => true,
        'badges' => ['Asset Register', 'Inventory'],
        'actions' => [
            ['label' => 'เปิดระบบครุภัณฑ์', 'url' => '../asset/index.php', 'primary' => true],
        ]
    ],
    [
        'id' => 'consumables',
        'title' => 'วัสดุสิ้นเปลือง',
        'description' => 'จัดการสต็อกวัสดุสิ้นเปลือง — รับเข้า เบิกออก ตามหน่วยงาน/คณะ พร้อมแจ้งเตือนเมื่อใกล้หมด เหมาะสำหรับเวชภัณฑ์ ถุงยาง หน้ากาก ฯลฯ',
        'icon' => 'fa-box-open',
        'bg_color' => 'bg-emerald-50',
        'icon_color' => 'text-emerald-600',
        'border_color' => 'border-emerald-100',
        'allowed_roles' => ['admin', 'superadmin', 'editor'],
        'staff_visible' => true,
        'badges' => ['Stock', 'Issue/Receive'],
        'actions' => [
            ['label' => 'เปิดระบบวัสดุ', 'url' => '../consumables/index.php', 'primary' => true],
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

// Sort projects: Pinned ones first
if (!empty($userPins)) {
    usort($projects, function($a, $b) use ($userPins) {
        $aPinned = in_array($a['id'], $userPins);
        $bPinned = in_array($b['id'], $userPins);
        if ($aPinned && !$bPinned) return -1;
        if (!$aPinned && $bPinned) return 1;
        return 0;
    });
}

// Category assignments for filter tabs
$categoryMap = [
    'identity_governance' => 'core',
    'e_campaign' => 'core',
    'e_borrow' => 'core',
    'asset_management' => 'core',
    'consumables' => 'core',
    'insurance_sync' => 'core',
    'insurance_dashboard' => 'core',
    'gold_card' => 'core',
    'gold_card_pending' => 'core',
    'monthly_report' => 'core',
    'nurse_productivity' => 'core',
    'daily_summary' => 'core',
    'system_logs' => 'tools',
    'sentry_events' => 'tools',
    'privilege_inventory' => 'tools',
    'pdpa_audit' => 'tools',
    'db_schema' => 'tools',
    'sql_console' => 'tools',
    'vaccinations' => 'core',
    'vaccine_catalog' => 'core',
    'admin_tool' => 'tools',
    'future_app' => 'dev',
];

/**
 * (3a) LINE LINK PROMPT — ตรวจว่า staff ยังไม่ผูก LINE + ยังไม่เลือก "ไม่ต้องเตือนอีก"
 * แสดง popup ตอน login portal ครั้งแรกของ session (handled ที่ JS ใช้ sessionStorage)
 */
$_showLineLinkPrompt = false;
if (!empty($_SESSION['is_ecampaign_staff']) && !empty($_SESSION['admin_id'])) {
    try {
        // Auto-migrate dismissed flag (idempotent)
        try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS dismissed_line_link_prompt TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}

        $_lcheck = $pdo->prepare("SELECT IFNULL(linked_line_user_id, '') AS uid, IFNULL(dismissed_line_link_prompt, 0) AS dismissed FROM sys_staff WHERE id = ? LIMIT 1");
        $_lcheck->execute([(int)$_SESSION['admin_id']]);
        $_lr = $_lcheck->fetch(PDO::FETCH_ASSOC);
        if ($_lr && $_lr['uid'] === '' && (int)$_lr['dismissed'] === 0) {
            $_showLineLinkPrompt = true;
        }
    } catch (PDOException $e) {
        error_log('[line_link_prompt] check failed: ' . $e->getMessage());
    }
}

/**
 * (3) RECENT ACTIVITY FETCH
 * แสดงเฉพาะกิจกรรมของ user ปัจจุบัน (privacy: ไม่ปนกับคนอื่น)
 */
$recentActivity = [];
$_currentUserId = $_SESSION['admin_id'] ?? null;
if ($_currentUserId) {
    try {
        $stmt = $pdo->prepare("SELECT action, description, timestamp as created_at
                               FROM sys_activity_logs
                               WHERE user_id = :uid
                               ORDER BY timestamp DESC
                               LIMIT 5");
        $stmt->execute([':uid' => (int)$_currentUserId]);
        $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $_myName = $_SESSION['admin_username'] ?? '';
        foreach ($recentActivity as &$_r) { $_r['admin_name'] = $_myName; }
        unset($_r);
    } catch (PDOException $e) { /* table not ready */ }
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
        title_en VARCHAR(255) DEFAULT NULL,
        content TEXT NOT NULL,
        content_en TEXT DEFAULT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        type ENUM('info', 'warning', 'success', 'urgent') DEFAULT 'info',
        target_audience ENUM('all', 'student', 'staff', 'other') DEFAULT 'all',
        priority TINYINT UNSIGNED DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        show_once TINYINT(1) DEFAULT 1,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        read_count INT DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // ตรวจสอบและเพิ่มคอลัมน์ถ้ายังไม่มี
    $cols = $pdo->query("SHOW COLUMNS FROM sys_announcements")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('title_en', $cols)) $pdo->exec("ALTER TABLE sys_announcements ADD COLUMN title_en VARCHAR(255) AFTER title");
    if (!in_array('content_en', $cols)) $pdo->exec("ALTER TABLE sys_announcements ADD COLUMN content_en TEXT AFTER content");
    if (!in_array('created_by', $cols)) $pdo->exec("ALTER TABLE sys_announcements ADD COLUMN created_by INT UNSIGNED AFTER end_date");

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
        $annTitleEn   = trim($_POST['ann_title_en'] ?? '');
        $annContent   = trim($_POST['ann_content'] ?? '');
        $annContentEn = trim($_POST['ann_content_en'] ?? '');
        $annType      = in_array($_POST['ann_type'] ?? '', ['info','warning','success','urgent']) ? $_POST['ann_type'] : 'info';
        $annPriority  = max(0, min(255, (int)($_POST['ann_priority'] ?? 0)));
        $annAudience  = in_array($_POST['ann_audience'] ?? '', ['all','student','staff','other']) ? $_POST['ann_audience'] : 'all';
        $annActive    = isset($_POST['ann_active']) ? 1 : 0;
        $annShowOnce  = isset($_POST['ann_show_once']) ? 1 : 0;

        // ── Image handling: file upload > existing URL > clear flag ──────
        // - ถ้ามีไฟล์ใหม่ใน $_FILES['ann_image_file'] → upload + ใช้ path ใหม่
        // - ถ้ามี $_POST['ann_image_clear'] = '1' → ล้างค่า (NULL)
        // - มิฉะนั้น → คงค่าเดิม ($_POST['ann_image_existing'])
        $annImageUrl  = trim($_POST['ann_image_existing'] ?? '');
        if (!empty($_POST['ann_image_clear'])) {
            $annImageUrl = '';
        }
        $ann_image_err = null;
        if (isset($_FILES['ann_image_file']) && is_array($_FILES['ann_image_file'])
            && $_FILES['ann_image_file']['error'] === UPLOAD_ERR_OK
            && (int)$_FILES['ann_image_file']['size'] > 0) {
            $f = $_FILES['ann_image_file'];
            $maxBytes = 5 * 1024 * 1024; // 5 MB
            if ($f['size'] > $maxBytes) {
                $ann_image_err = 'ไฟล์ใหญ่เกิน 5 MB';
            } else {
                $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : ($f['type'] ?? '');
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];
                if (!isset($allowed[$mime])) {
                    $ann_image_err = 'รองรับเฉพาะ JPG / PNG / WebP / GIF';
                } else {
                    $ext       = $allowed[$mime];
                    $uploadDir = __DIR__ . '/../assets/uploads/announcements/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                    // Defense-in-depth: block any script execution under upload dir
                    $htaccess = $uploadDir . '.htaccess';
                    if (!file_exists($htaccess)) {
                        @file_put_contents($htaccess, "# Auto-generated — block any script execution\n"
                            . "<FilesMatch \"\\.(php|php3|php4|php5|php7|phtml|phar|pl|py|cgi|sh)$\">\n"
                            . "    Require all denied\n"
                            . "</FilesMatch>\n"
                            . "Options -ExecCGI -Indexes\n"
                            . "AddType text/plain .php .phtml .phar .pl .py\n");
                    }
                    $newName = 'ann_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $uploadDir . $newName)) {
                        $annImageUrl = '../assets/uploads/announcements/' . $newName;
                    } else {
                        $ann_image_err = 'อัปโหลดไม่สำเร็จ';
                    }
                }
            }
        }

        $annStart     = $_POST['ann_start'] ?? null;
        $annEnd       = $_POST['ann_end'] ?? null;
        $annStart     = $annStart ?: null;
        $annEnd       = $annEnd ?: null;

        if ($ann_image_err) {
            $ann_error = $ann_image_err;
        } elseif ($annTitle && $annContent) {
            try {
                if ($annAction === 'create') {
                    $pdo->prepare("
                        INSERT INTO sys_announcements
                            (title, title_en, content, content_en, image_url, type, priority, target_audience, is_active, show_once, start_date, end_date, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$annTitle, $annTitleEn ?: null, $annContent, $annContentEn ?: null, $annImageUrl ?: null, $annType, $annPriority, $annAudience, $annActive, $annShowOnce, $annStart, $annEnd, $_SESSION['admin_id'] ?? null]);
                    log_activity('Announcement Created', "สร้างประกาศ: $annTitle");
                } else {
                    $pdo->prepare("
                        UPDATE sys_announcements
                        SET title=?, title_en=?, content=?, content_en=?, image_url=?, type=?, priority=?, target_audience=?, is_active=?, show_once=?, start_date=?, end_date=?
                        WHERE id=?
                    ")->execute([$annTitle, $annTitleEn ?: null, $annContent, $annContentEn ?: null, $annImageUrl ?: null, $annType, $annPriority, $annAudience, $annActive, $annShowOnce, $annStart, $annEnd, $annId]);
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

