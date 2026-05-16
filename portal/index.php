<?php
/**
 * portal/index.php (v3.0 Dynamic & Scalable Edition)
 * Central Hub Dashboard สำหรับการจัดการระบบที่รองรับการขยายโปรเจกต์ในอนาคต
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // ตรวจสอบความปลอดภัย

$pdo = db();
$adminRole = $_SESSION['admin_role'] ?? 'admin';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);

// Registry-only mode: staff with only access_registry (no other access_* flag)
// → lock UI to registry_upload section, hide everything else
$registryOnly = !empty($_SESSION['access_registry'])
    && empty($_SESSION['access_insurance'])
    && empty($_SESSION['access_ecampaign'])
    && empty($_SESSION['access_eborrow'])
    && empty($_SESSION['access_system_logs'])
    && empty($_SESSION['access_site_settings'])
    && empty($_SESSION['access_edms'])
    && $adminRole !== 'superadmin';

$activeSection = $_GET['section'] ?? 'dashboard';
if ($registryOnly) $activeSection = 'registry_upload';

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
    'admin_tool' => 'tools',
    'future_app' => 'dev',
];

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
        href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css?v=<?= @filemtime(__DIR__ . '/../assets/css/portal.css') ?: (defined('APP_BUILD') ? APP_BUILD : time()) ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/safe-fetch.js?v=<?= @filemtime(__DIR__ . '/../assets/js/safe-fetch.js') ?: (defined('APP_BUILD') ? APP_BUILD : time()) ?>"></script>
    <script defer src="../assets/js/rsu-fx.js?v=<?= @filemtime(__DIR__ . '/../assets/js/rsu-fx.js') ?: (defined('APP_BUILD') ? APP_BUILD : time()) ?>"></script>
    <!-- Suppress harmless AbortError from skipped View Transitions
         (เกิดเมื่อนำทางมาจากหน้า admin/e_Borrow ที่เปิด @view-transition แล้วถูกข้าม) -->
    <script>
        window.addEventListener('unhandledrejection', function(e) {
            var r = e.reason;
            if (r && r.name === 'AbortError' && /transition/i.test(r.message || '')) {
                e.preventDefault();
            }
        });
    </script>
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

            // Apply saved per-group collapse state
            try {
                var saved = JSON.parse(localStorage.getItem('psb_groups_collapsed') || '[]');
                saved.forEach(function (key) {
                    var btn = document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
                    var grp = document.querySelector('.psb-group[data-group="' + key + '"]');
                    if (btn && grp) {
                        btn.classList.add('collapsed');
                        grp.classList.add('collapsed');
                    }
                });
            } catch (e) { /* silent */ }

            // Auto-expand the group containing the active item (override saved collapse)
            var activeItem = document.querySelector('.psb-item.psb-active');
            if (activeItem) {
                var grp = activeItem.closest('.psb-group');
                if (grp) {
                    var key = grp.getAttribute('data-group');
                    grp.classList.remove('collapsed');
                    var btn = document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
                    if (btn) btn.classList.remove('collapsed');
                }
            }
        });

        // Toggle a sidebar group open/closed; persist to localStorage
        window.togglePsbGroup = function (key, btnEl) {
            var btn = btnEl || document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
            var grp = document.querySelector('.psb-group[data-group="' + key + '"]');
            if (!btn || !grp) return;
            var nowCollapsed = btn.classList.toggle('collapsed');
            grp.classList.toggle('collapsed', nowCollapsed);

            try {
                var saved = JSON.parse(localStorage.getItem('psb_groups_collapsed') || '[]');
                var idx = saved.indexOf(key);
                if (nowCollapsed && idx < 0) saved.push(key);
                if (!nowCollapsed && idx >= 0) saved.splice(idx, 1);
                localStorage.setItem('psb_groups_collapsed', JSON.stringify(saved));
            } catch (e) { /* silent */ }
        };

        window.switchSection = function (sectionId, btn) {
            var target = document.getElementById('section-' + sectionId);
            if (!target) {
                // Section doesn't exist — log to backend and inform user
                if (typeof safeFetch !== 'undefined' && safeFetch.reportError) {
                    safeFetch.reportError(
                        window.location.pathname + '?section=' + sectionId,
                        404,
                        'switchSection: unknown sectionId="' + sectionId + '"'
                    );
                }
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่พบหน้านี้',
                        text: 'section "' + sectionId + '" ไม่มีในระบบ — อาจถูกย้ายหรือคุณไม่มีสิทธิ์เข้าถึง',
                        confirmButtonColor: '#0f766e',
                    });
                }
                return;
            }
            document.querySelectorAll('.portal-section').forEach(function (s) { s.style.display = 'none'; });
            target.style.display = '';
            document.querySelectorAll('.psb-item').forEach(function (b) {
                b.classList.remove('psb-active');
                b.removeAttribute('aria-current');
            });

            // If btn not provided, try to find it in sidebar
            if (!btn) {
                btn = document.querySelector('.psb-item[data-section="' + sectionId + '"]');
            }
            if (btn) {
                btn.classList.add('psb-active');
                btn.setAttribute('aria-current', 'page');
            }

            // Refresh batch_status data whenever the section becomes active
            if (sectionId === 'batch_status' && typeof window.bsLoad === 'function') {
                window.bsLoad(1);
            }
            // Activity Dashboard: start polling + Pusher subscription
            if (sectionId === 'activity_dashboard' && typeof window.adActivate === 'function') {
                window.adActivate();
            }

            var url = new URL(window.location.href);
            url.searchParams.set('section', sectionId);
            ['page','el_search','el_level','el_date','el_source','al_q','eml_q','eml_type','eml_status','cd_search','cd_view','s','p'].forEach(function(k){ url.searchParams.delete(k); });
            history.pushState({section: sectionId}, '', url.toString());
        };
    </script>
</head>

<body class="font-sans text-gray-800 bg-[#f4f7f5]" style="height:100vh;overflow:hidden;display:flex;flex-direction:row">
<script>if(localStorage.getItem('ecampaign_theme')==='dark')document.body.setAttribute('data-theme','dark');</script>

    <a href="#portal-main" class="skip-to-content">ข้ามไปยังเนื้อหาหลัก</a>

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

        <!-- Nav items (grouped) -->
        <div style="padding:10px;flex:1;overflow-y:auto;display:flex;flex-direction:column;">
            <?php
            // Pre-compute role flags for cleaner conditionals
            $isSuper        = ($adminRole === 'superadmin');
            $hasRegistry    = $isSuper || !empty($_SESSION['access_registry']);
            $hasInsurance   = $isSuper || !empty($_SESSION['access_insurance']) || !empty($_SESSION['access_registry']);
            $hasSysLogs     = $isSuper || !empty($_SESSION['access_system_logs']);
            $hasSiteSet     = $isSuper || !empty($_SESSION['access_site_settings']);
            $hasEdms        = $isSuper || !empty($_SESSION['access_edms']);
            $hasScholarship = $isSuper || !empty($_SESSION['access_scholarship']);
            $hasDashboardAdmin = $isSuper || !empty($_SESSION['access_dashboard_admin']);
            $hasMonthlyReport  = $isSuper || !empty($_SESSION['access_monthly_report']) || !empty($_SESSION['access_director_view']);
            $hasNurseProductivity = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
            $hasDailySummary      = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_daily_summary']);
            $hasAsset          = $isSuper || in_array($_SESSION['role'] ?? '', ['admin','editor'], true) || !empty($_SESSION['access_asset']);
            $hasConsumables    = $isSuper || in_array($_SESSION['role'] ?? '', ['admin','editor'], true) || !empty($_SESSION['access_consumables']);
            $hasInventory      = $hasAsset || $hasConsumables;

            // EDMS pending count badge — count routings where current user is recipient and status is open
            $edmsInboxBadge = 0;
            if ($hasEdms) {
                $_uid = (int)($_SESSION['admin_id'] ?? 0);
                if ($_uid > 0) {
                    try {
                        $_st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = ? AND status IN ('pending','acknowledged')");
                        $_st->execute([$_uid]);
                        $edmsInboxBadge = (int)$_st->fetchColumn();
                    } catch (PDOException) { /* table not yet migrated */ }
                }
            }
            ?>

            <?php /* ── OVERVIEW ───────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly): ?>
                <button type="button" class="psb-section-toggle" data-group="overview" onclick="togglePsbGroup('overview',this)">
                    <i class="fa-solid fa-chart-line" style="color:#94a3b8"></i>
                    <span>OVERVIEW</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="overview">
                    <button class="psb-item <?= $activeSection==='dashboard'?'psb-active':'' ?>" data-section="dashboard" onclick="switchSection('dashboard',this)">
                        <div class="psb-icon"><i class="fa-solid fa-chart-pie" style="color:#059669"></i></div>
                        <span class="psb-label" style="color:#059669;font-weight:900">Dashboard</span>
                    </button>
                    <button class="psb-item <?= $activeSection==='apps'?'psb-active':'' ?>" data-section="apps" onclick="switchSection('apps',this)" id="psb-apps-launcher">
                        <div class="psb-icon"><i class="fa-solid fa-grip" style="color:#2e9e63"></i></div>
                        <span class="psb-label" style="color:#15803d;font-weight:900">App Launcher</span>
                        <span class="psb-new-badge" id="psb-apps-new-badge">NEW</span>
                    </button>
                    <?php if ($isStaff): ?>
                        <button class="psb-item <?= $activeSection==='profile'?'psb-active':'' ?>" data-section="profile" onclick="switchSection('profile',this)">
                            <div class="psb-icon"><i class="fa-solid fa-user-pen" style="color:#0891b2"></i></div>
                            <span class="psb-label" style="color:#0e7490;font-weight:900">โปรไฟล์ของฉัน</span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── AI SUITE ────────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly && ($isSuper || !empty($_SESSION['access_ai']))): ?>
                <button type="button" class="psb-section-toggle" data-group="ai" onclick="togglePsbGroup('ai',this)">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color:#a855f7"></i>
                    <span>AI Suite</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="ai">
                    <button class="psb-item" data-section="ai_assistant" onclick="switchSection('ai_assistant',this)">
                        <div class="psb-icon"><i class="fa-solid fa-wand-magic-sparkles" style="color:#8b5cf6"></i></div>
                        <span class="psb-label" style="color:#7c3aed;font-weight:900">AI Assistant</span>
                    </button>
                    <button class="psb-item <?= $activeSection==='ai_qa_lab'?'psb-active':'' ?>" data-section="ai_qa_lab" onclick="switchSection('ai_qa_lab',this)">
                        <div class="psb-icon"><i class="fa-solid fa-flask-vial" style="color:#a855f7"></i></div>
                        <span class="psb-label" style="color:#7c3aed;font-weight:900">AI QA Lab</span>
                    </button>
                    <button class="psb-item <?= $activeSection==='ai_prompts'?'psb-active':'' ?>" data-section="ai_prompts" onclick="switchSection('ai_prompts',this)">
                        <div class="psb-icon"><i class="fa-solid fa-code" style="color:#a855f7"></i></div>
                        <span class="psb-label" style="color:#7c3aed;font-weight:900">AI Prompts</span>
                    </button>
                    <button class="psb-item <?= $activeSection==='ai_knowledge'?'psb-active':'' ?>" data-section="ai_knowledge" onclick="switchSection('ai_knowledge',this)">
                        <div class="psb-icon"><i class="fa-solid fa-database" style="color:#10b981"></i></div>
                        <span class="psb-label" style="color:#059669;font-weight:900">AI Knowledge</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php /* ── สิทธิ์ & ความปลอดภัย ──────────────────────────────── */ ?>
            <?php if (!$registryOnly): ?>
                <button type="button" class="psb-section-toggle" data-group="security" onclick="togglePsbGroup('security',this)">
                    <i class="fa-solid fa-shield-halved" style="color:#2563eb"></i>
                    <span>สิทธิ์ &amp; ความปลอดภัย</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="security">
                    <?php if ($isSuper || !empty($_SESSION['access_identity'])): ?>
                    <button class="psb-item" data-section="identity" onclick="switchSection('identity',this)">
                        <div class="psb-icon"><i class="fa-solid fa-id-card-clip" style="color:#2563eb"></i></div>
                        <span class="psb-label" style="color:#1d4ed8;font-weight:900">Identity &amp; Governance</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($isSuper): ?>
                        <button class="psb-item" data-section="privilege_inventory" onclick="switchSection('privilege_inventory',this)">
                            <div class="psb-icon"><i class="fa-solid fa-shield-halved" style="color:#10b981"></i></div>
                            <span class="psb-label" style="color:#059669;font-weight:900">ISO Governance</span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── ประกันสุขภาพ ─────────────────────────────────────── */ ?>
            <?php if (!$registryOnly || $hasRegistry || $hasInsurance): ?>
                <button type="button" class="psb-section-toggle" data-group="insurance" onclick="togglePsbGroup('insurance',this)">
                    <i class="fa-solid fa-hospital-user" style="color:#0ea5e9"></i>
                    <span>ประกันสุขภาพ</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="insurance">
                    <?php if (!$registryOnly): ?>
                        <button class="psb-item <?= $activeSection==='insurance_dashboard'?'psb-active':'' ?>" data-section="insurance_dashboard" onclick="switchSection('insurance_dashboard',this)">
                            <div class="psb-icon"><i class="fa-solid fa-chart-pie" style="color:#3b82f6"></i></div>
                            <span class="psb-label" style="color:#1d4ed8;font-weight:900">Dashboard Workbook</span>
                        </button>
                        <button class="psb-item" data-section="insurance_sync" onclick="switchSection('insurance_sync',this)">
                            <div class="psb-icon"><i class="fa-solid fa-shield-halved" style="color:#0ea5e9"></i></div>
                            <span class="psb-label" style="color:#0284c7;font-weight:900">Insurance Hub</span>
                        </button>
                        <button class="psb-item <?= $activeSection==='gold_card_pending'?'psb-active':'' ?>" data-section="gold_card_pending" onclick="switchSection('gold_card_pending',this)">
                            <div class="psb-icon"><i class="fa-solid fa-hourglass-half" style="color:#3b82f6"></i></div>
                            <span class="psb-label" style="color:#1d4ed8;font-weight:900">ย้ายสิทธิ์บัตรทอง</span>
                            <?php
                            $pendingBadgeCount = 0;
                            try { $pendingBadgeCount = (int)db()->query("SELECT COUNT(*) FROM gold_card_members WHERE status = 'submitted'")->fetchColumn(); }
                            catch (PDOException) {}
                            if ($pendingBadgeCount > 0): ?>
                                <span class="ml-auto px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-black"><?= $pendingBadgeCount > 99 ? '99+' : $pendingBadgeCount ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="psb-item <?= $activeSection==='gold_card'?'psb-active':'' ?>" data-section="gold_card" onclick="switchSection('gold_card',this)">
                            <div class="psb-icon"><i class="fa-solid fa-id-card" style="color:#f59e0b"></i></div>
                            <span class="psb-label" style="color:#b45309;font-weight:900">บัตรทอง</span>
                        </button>
                    <?php endif; ?>
                    <?php if ($hasRegistry): ?>
                        <button class="psb-item <?= $activeSection==='registry_upload'?'psb-active':'' ?>" data-section="registry_upload" onclick="switchSection('registry_upload',this)">
                            <div class="psb-icon"><i class="fa-solid fa-id-card-clip" style="color:#06b6d4"></i></div>
                            <span class="psb-label" style="color:#0891b2;font-weight:900">อัพโหลดรายชื่อ (ทะเบียน)</span>
                        </button>
                    <?php endif; ?>
                    <?php if ($hasInsurance): ?>
                        <button class="psb-item <?= $activeSection==='batch_status'?'psb-active':'' ?>" data-section="batch_status" onclick="switchSection('batch_status',this)">
                            <div class="psb-icon"><i class="fa-solid fa-list-check" style="color:#0891b2"></i></div>
                            <span class="psb-label" style="color:#0e7490;font-weight:900">สถานะเอกสาร</span>
                        </button>
                    <?php endif; ?>
                    <?php if (!$registryOnly && $isSuper): ?>
                        <button class="psb-item" data-section="manage_insurance_partners" onclick="switchSection('manage_insurance_partners',this)">
                            <div class="psb-icon"><i class="fa-solid fa-handshake" style="color:#10b981"></i></div>
                            <span class="psb-label" style="color:#059669;font-weight:900">Insurance Partners</span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── สื่อสาร ──────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly): ?>
                <button type="button" class="psb-section-toggle" data-group="comm" onclick="togglePsbGroup('comm',this)">
                    <i class="fa-solid fa-bullhorn" style="color:#7c3aed"></i>
                    <span>สื่อสาร</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="comm">
                    <button class="psb-item" data-section="announcements" onclick="switchSection('announcements',this)">
                        <div class="psb-icon"><i class="fa-solid fa-bullhorn" style="color:#7c3aed"></i></div>
                        <span class="psb-label" style="color:#6d28d9;font-weight:900">ประกาศ</span>
                    </button>
                    <?php if ($hasEdms): ?>
                        <button class="psb-item <?= $activeSection==='edms'?'psb-active':'' ?>" data-section="edms" onclick="switchSection('edms',this)" style="position:relative">
                            <div class="psb-icon"><i class="fa-solid fa-folder-open" style="color:#0ea5e9"></i></div>
                            <span class="psb-label" style="color:#0284c7;font-weight:900">สารบรรณอิเล็กทรอนิกส์</span>
                            <?php if ($edmsInboxBadge > 0): ?>
                                <span style="margin-left:auto;display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:99px;background:#f59e0b;color:#fff;font-size:10px;font-weight:900;box-shadow:0 1px 2px rgba(0,0,0,.1)" title="<?= $edmsInboxBadge ?> รายการรอดำเนินการ">
                                    <?= $edmsInboxBadge > 99 ? '99+' : $edmsInboxBadge ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── คลังพัสดุ (Inventory) ────────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasInventory): ?>
                <button type="button" class="psb-section-toggle" data-group="inventory" onclick="togglePsbGroup('inventory',this)">
                    <i class="fa-solid fa-warehouse" style="color:#2e9e63"></i>
                    <span>คลังพัสดุ</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="inventory">
                    <?php if ($hasAsset): ?>
                        <a href="../asset/index.php" class="psb-item" style="text-decoration:none">
                            <div class="psb-icon"><i class="fa-solid fa-boxes-stacked" style="color:#0d9488"></i></div>
                            <span class="psb-label" style="color:#0f766e;font-weight:900">ครุภัณฑ์สำนักงาน</span>
                        </a>
                    <?php endif; ?>
                    <?php if ($hasConsumables): ?>
                        <a href="../consumables/index.php" class="psb-item" style="text-decoration:none">
                            <div class="psb-icon"><i class="fa-solid fa-box-open" style="color:#2e9e63"></i></div>
                            <span class="psb-label" style="color:#2e7d52;font-weight:900">วัสดุสิ้นเปลือง</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── การเงิน ──────────────────────────────────────────── */ ?>
            <?php
            $hasFinance = $isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance']);
            if (!$registryOnly && $hasFinance): ?>
                <button type="button" class="psb-section-toggle" data-group="finance" onclick="togglePsbGroup('finance',this)">
                    <i class="fa-solid fa-money-bill-trend-up" style="color:#059669"></i>
                    <span>การเงิน</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="finance">
                    <button class="psb-item <?= $activeSection==='finance'?'psb-active':'' ?>" data-section="finance" onclick="switchSection('finance',this)">
                        <div class="psb-icon"><i class="fa-solid fa-book" style="color:#059669"></i></div>
                        <span class="psb-label" style="color:#047857;font-weight:900">Cash Book</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php /* ── ติดตามระบบ ──────────────────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasSysLogs): ?>
                <button type="button" class="psb-section-toggle" data-group="monitor" onclick="togglePsbGroup('monitor',this)">
                    <i class="fa-solid fa-binoculars" style="color:#64748b"></i>
                    <span>ติดตามระบบ</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="monitor">
                    <?php if (($_SESSION['admin_role'] ?? '') === 'superadmin'): ?>
                    <button class="psb-item" data-section="activity_dashboard" onclick="switchSection('activity_dashboard',this)">
                        <div class="psb-icon"><i class="fa-solid fa-chart-line" style="color:#8b5cf6"></i></div>
                        <span class="psb-label" style="color:#6d28d9;font-weight:900">Activity Dashboard</span>
                    </button>
                    <?php endif; ?>
                    <button class="psb-item" data-section="activity_logs" onclick="switchSection('activity_logs',this)">
                        <div class="psb-icon"><i class="fa-solid fa-file-lines" style="color:#64748b"></i></div>
                        <span class="psb-label" style="color:#475569;font-weight:900">Activity Logs</span>
                    </button>
                    <button class="psb-item" data-section="error_logs" onclick="switchSection('error_logs',this)">
                        <div class="psb-icon"><i class="fa-solid fa-bug" style="color:#ef4444"></i></div>
                        <span class="psb-label" style="color:#dc2626;font-weight:900">Error Logs</span>
                    </button>
                    <?php if ($adminRole === 'superadmin'): ?>
                    <button class="psb-item <?= $activeSection==='sentry_events'?'psb-active':'' ?>" data-section="sentry_events" onclick="switchSection('sentry_events',this)">
                        <div class="psb-icon"><i class="fa-solid fa-radiation" style="color:#8b5cf6"></i></div>
                        <span class="psb-label" style="color:#6d28d9;font-weight:900">Sentry Events</span>
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── รายงาน ─────────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly && ($hasMonthlyReport || $hasNurseProductivity || $hasDailySummary)): ?>
                <button type="button" class="psb-section-toggle" data-group="reports" onclick="togglePsbGroup('reports',this)">
                    <i class="fa-solid fa-clipboard-list" style="color:#f59e0b"></i>
                    <span>รายงาน</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="reports">
                    <?php if ($hasDailySummary): ?>
                    <button class="psb-item <?= $activeSection==='daily_summary'?'psb-active':'' ?>" data-section="daily_summary" onclick="switchSection('daily_summary',this)">
                        <div class="psb-icon"><i class="fa-solid fa-clipboard-check" style="color:#f59e0b"></i></div>
                        <span class="psb-label" style="color:#b45309;font-weight:900">สรุปงานประจำวัน</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasMonthlyReport): ?>
                    <button class="psb-item <?= $activeSection==='monthly_report'?'psb-active':'' ?>" data-section="monthly_report" onclick="switchSection('monthly_report',this)">
                        <div class="psb-icon"><i class="fa-solid fa-calendar-days" style="color:#f59e0b"></i></div>
                        <span class="psb-label" style="color:#b45309;font-weight:900">รายงานประจำเดือน</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasNurseProductivity): ?>
                    <button class="psb-item <?= $activeSection==='nurse_productivity'?'psb-active':'' ?>" data-section="nurse_productivity" onclick="switchSection('nurse_productivity',this)">
                        <div class="psb-icon"><i class="fa-solid fa-user-nurse" style="color:#f59e0b"></i></div>
                        <span class="psb-label" style="color:#b45309;font-weight:900">Productivity พยาบาล</span>
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── เอกสาร / รายงาน (Document Library) ─────────────────── */ ?>
            <?php if (!$registryOnly && ($adminRole === 'superadmin' || $adminRole === 'admin')): ?>
                <button type="button" class="psb-section-toggle" data-group="docs" onclick="togglePsbGroup('docs',this)">
                    <i class="fa-solid fa-folder-tree" style="color:#0f7349"></i>
                    <span>เอกสาร</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="docs">
                    <button class="psb-item <?= $activeSection==='documents'?'psb-active':'' ?>" data-section="documents" onclick="switchSection('documents',this)">
                        <div class="psb-icon"><i class="fa-solid fa-file-lines" style="color:#0f7349"></i></div>
                        <span class="psb-label" style="color:#064e3b;font-weight:900">คลังเอกสาร</span>
                    </button>
                </div>
            <?php endif; ?>

            <div style="flex:1"></div> <!-- Spacer to push settings to bottom -->

            <?php /* ── ข้อมูลหลัก (Master Data) ─────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasSiteSet): ?>
                <button type="button" class="psb-section-toggle" data-group="masterdata" onclick="togglePsbGroup('masterdata',this)">
                    <i class="fa-solid fa-database" style="color:#0d9488"></i>
                    <span>ข้อมูลหลัก</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="masterdata">
                    <button class="psb-item <?= $activeSection==='clinic_data'?'psb-active':'' ?>" data-section="clinic_data" onclick="switchSection('clinic_data',this)">
                        <div class="psb-icon"><i class="fa-solid fa-hospital" style="color:#0d9488"></i></div>
                        <span class="psb-label" style="color:#0f766e;font-weight:900">ข้อมูลคลินิก</span>
                    </button>
                    <?php if ($hasScholarship): ?>
                        <button class="psb-item <?= $activeSection==='scholarship'?'psb-active':'' ?>" data-section="scholarship" onclick="switchSection('scholarship',this)">
                            <div class="psb-icon"><i class="fa-solid fa-graduation-cap" style="color:#10b981"></i></div>
                            <span class="psb-label" style="color:#059669;font-weight:900">นักศึกษาทุน</span>
                        </button>
                    <?php endif; ?>
                    <button class="psb-item <?= $activeSection==='nurse_schedule'?'psb-active':'' ?>" data-section="nurse_schedule" onclick="switchSection('nurse_schedule',this)">
                        <div class="psb-icon"><i class="fa-solid fa-user-nurse" style="color:#0ea5e9"></i></div>
                        <span class="psb-label" style="color:#0284c7;font-weight:900">ตารางเวรพยาบาล</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php /* ── ตั้งค่า (ล่างสุด) ─────────────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasSiteSet): ?>
                <button type="button" class="psb-section-toggle" data-group="settings" onclick="togglePsbGroup('settings',this)">
                    <i class="fa-solid fa-gear" style="color:#d97706"></i>
                    <span>ตั้งค่า</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="settings">
                    <button class="psb-item" data-section="settings" onclick="switchSection('settings',this)">
                        <div class="psb-icon"><i class="fa-solid fa-gear" style="color:#d97706"></i></div>
                        <span class="psb-label" style="color:#b45309;font-weight:900">Settings</span>
                    </button>
                </div>
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

                    <!-- ── PRIORITY PANEL: งานวันนี้ ──────────────────────────────── -->
                    <?php
                    // Role-aware capability flags
                    // - Portal admin (ไม่ใช่ staff) → เห็นทุกอย่างตามเดิม
                    // - Staff (is_ecampaign_staff) → จำกัดตาม access_* flag ที่ตั้งไว้ตอน login
                    $canEcampaign  = !$isStaff || !empty($_SESSION['access_ecampaign']);
                    $canEborrow    = !$isStaff || !empty($_SESSION['access_eborrow']);
                    $canSystemLogs = !$isStaff || !empty($_SESSION['access_system_logs']);

                    $today_items = [];

                    // e-Campaign signals — เน้น check-in workload วันนี้ (สำหรับ staff)
                    if ($canEcampaign) {
                        if ($kpis['pending_today'] > 0) {
                            $today_items[] = [
                                'label' => 'รอเช็คอินวันนี้',
                                'value' => $kpis['pending_today'],
                                'icon'  => 'fa-clock',
                                'tone'  => 'warning',
                                'href'  => '../admin/daily_report.php',
                            ];
                        }
                        if ($kpis['checkins_today'] > 0) {
                            $today_items[] = [
                                'label' => 'เช็คอินสำเร็จวันนี้',
                                'value' => $kpis['checkins_today'],
                                'icon'  => 'fa-circle-check',
                                'tone'  => 'success',
                                'href'  => '../admin/daily_report.php',
                            ];
                        }
                        if ($kpis['slots_today'] > 0) {
                            $today_items[] = [
                                'label' => 'Slot นัดหมายวันนี้',
                                'value' => $kpis['slots_today'],
                                'icon'  => 'fa-calendar-day',
                                'tone'  => 'info',
                                'href'  => '../admin/time_slots.php',
                            ];
                        }
                        if ($kpis['bookings_today'] > 0) {
                            $today_items[] = [
                                'label' => 'การจองใหม่ใน 24 ชม.',
                                'value' => $kpis['bookings_today'],
                                'icon'  => 'fa-bullhorn',
                                'tone'  => 'accent',
                                'href'  => '../admin/bookings.php',
                            ];
                        }
                    }

                    // e-Borrow signals — เฉพาะคนที่มีสิทธิ์ดูแล e-Borrow
                    if ($canEborrow) {
                        if ($kpis['borrows'] > 0) {
                            $today_items[] = [
                                'label' => 'อุปกรณ์รออนุมัติ',
                                'value' => $kpis['borrows'],
                                'icon'  => 'fa-box-open',
                                'tone'  => 'warning',
                                'href'  => '../e_Borrow/admin/index.php',
                            ];
                        }
                        if ($kpis['borrows_overdue'] > 0) {
                            $today_items[] = [
                                'label' => 'เลยกำหนดคืน',
                                'value' => $kpis['borrows_overdue'],
                                'icon'  => 'fa-clock-rotate-left',
                                'tone'  => 'danger',
                                'href'  => '../e_Borrow/admin/return_dashboard.php',
                            ];
                        }
                    }

                    // System logs — เฉพาะคนดูแลระบบ
                    if ($canSystemLogs && $kpis['errors_today'] > 0) {
                        $today_items[] = [
                            'label' => 'Error ใหม่ใน 24 ชม.',
                            'value' => $kpis['errors_today'],
                            'icon'  => 'fa-bug',
                            'tone'  => 'danger',
                            'href'  => 'javascript:switchSection(\'error_logs\')',
                        ];
                    }
                    ?>
                    <section class="au d1">
                        <?php
                        // Build 4 hero KPI tiles role-aware
                        $heroKpis = [];
                        if ($isStaff && $canEcampaign) {
                            $heroKpis[] = ['tone'=>'brand', 'icon'=>'fa-circle-check', 'num'=>$kpis['checkins_today'], 'sub'=>'/ '.number_format($kpis['appts_today']), 'label'=>'เช็คอินวันนี้ · จากนัดหมาย'];
                            $heroKpis[] = ['tone'=>'info',  'icon'=>'fa-calendar-day', 'num'=>$kpis['slots_today'],    'label'=>'Slot วันนี้'];
                            $heroKpis[] = ['tone'=>'amber', 'icon'=>'fa-clock',        'num'=>$kpis['pending_today'],  'label'=>'รอเช็คอิน'];
                            $heroKpis[] = ['tone'=>'accent','icon'=>'fa-bullhorn',     'num'=>$kpis['bookings_today'], 'label'=>'จองใหม่ใน 24 ชม.'];
                        } else {
                            $heroKpis[] = ['tone'=>'brand', 'icon'=>'fa-users',     'num'=>$kpis['users'],      'label'=>'บุคลากรและนักศึกษา', 'counter'=>true];
                            $heroKpis[] = ['tone'=>'info',  'icon'=>'fa-bullhorn',  'num'=>$kpis['camps'],      'label'=>'แคมเปญ active'];
                            $heroKpis[] = ['tone'=>'amber', 'icon'=>'fa-gauge-high','num'=>$kpis['used_quota'], 'sub'=>'/ '.number_format($kpis['total_quota']), 'label'=>'ใช้ไปแล้ว · จาก quota'];
                            $heroKpis[] = ['tone'=>'rose',  'icon'=>'fa-bug',       'num'=>$kpis['errors_today'],'label'=>'Error ใน 24 ชม.'];
                        }
                        $firstName = !empty($_SESSION['admin_username']) ? explode(' ', $_SESSION['admin_username'])[0] : '';
                        $hour = (int)date('G');
                        $greet = $hour < 12 ? 'อรุณสวัสดิ์' : ($hour < 17 ? 'สวัสดี' : ($hour < 21 ? 'สวัสดีตอนเย็น' : 'สวัสดีค่ำคืนนี้'));
                        $thaiMonths = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                        $thaiDays   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
                        $todayStr   = $thaiDays[(int)date('w')] . ' ' . (int)date('j') . ' ' . $thaiMonths[(int)date('n')] . ' ' . (date('Y')+543);
                        ?>
                        <div class="dash-hero">
                            <div class="dash-hero-glow"></div>
                            <div class="dash-hero-greet">
                                <div class="dash-hero-eyebrow">
                                    <i class="fa-solid fa-calendar-day"></i> <?= $todayStr ?>
                                    <?php if ($isStaff): ?><span class="dash-hero-role-pill"><i class="fa-solid fa-id-badge"></i> เจ้าหน้าที่</span><?php endif; ?>
                                </div>
                                <h1 class="dash-hero-title">
                                    <?= $greet ?><?= $firstName ? ' <span class="dash-hero-name">' . htmlspecialchars($firstName) . '</span>' : '' ?>
                                </h1>
                                <p class="dash-hero-sub">
                                    ภาพรวมระบบและงานวันนี้ของคุณ — เปิด App Launcher ที่ sidebar เพื่อเข้าระบบอื่นๆ
                                </p>
                            </div>
                            <div class="dash-hero-kpis">
                                <?php foreach ($heroKpis as $i => $k): ?>
                                <div class="dash-kpi fx-tilt fx-tilt-dark" data-tone="<?= $k['tone'] ?>" data-tilt="5" style="animation-delay:<?= 0.1 + $i * 0.08 ?>s">
                                    <div class="dash-kpi-ic"><i class="fa-solid <?= $k['icon'] ?>"></i></div>
                                    <div class="dash-kpi-body">
                                        <div class="dash-kpi-num">
                                            <span<?= !empty($k['counter']) ? ' id="kpi-users"' : '' ?> data-counter="<?= (int)$k['num'] ?>">0</span><?php if (!empty($k['sub'])): ?><span class="dash-kpi-sub"><?= $k['sub'] ?></span><?php endif; ?>
                                        </div>
                                        <div class="dash-kpi-label"><?= htmlspecialchars($k['label']) ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <!-- ── PRIORITY: งานต้องทำวันนี้ (clean, no greeting now) ───── -->
                    <section class="au d2">
                        <div class="priority-panel priority-panel--slim">
                            <div class="priority-panel-head">
                                <div>
                                    <div class="eyebrow">งานวันนี้ · ที่ต้องดำเนินการ</div>
                                    <div class="sec-title" style="margin-top:4px;font-size:1.05rem">Priorities</div>
                                </div>
                                <?php if (!empty($today_items)): ?>
                                <span class="priority-count-pill"><?= count($today_items) ?> รายการ</span>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($today_items)): ?>
                                <div class="priority-empty">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <div>
                                        <strong>ไม่มีงานค้าง</strong>
                                        <p>ทุกอย่างเรียบร้อยใน 24 ชั่วโมงที่ผ่านมา</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="priority-grid">
                                    <?php foreach ($today_items as $it): ?>
                                        <a href="<?= htmlspecialchars($it['href']) ?>" class="priority-item priority-item--<?= $it['tone'] ?> fx-tilt fx-tilt-light" data-tilt="4">
                                            <div class="priority-item-icon"><i class="fa-solid <?= $it['icon'] ?>"></i></div>
                                            <div class="priority-item-body">
                                                <div class="priority-item-num"><span data-counter="<?= (int)$it['value'] ?>">0</span></div>
                                                <div class="priority-item-label"><?= htmlspecialchars($it['label']) ?></div>
                                            </div>
                                            <i class="fa-solid fa-arrow-right priority-item-arrow"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- ── MAIN GRID: 3-column dashboard body (4 / 5 / 3) ──────── -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                        <!-- COL 1: Clinic calendar widget (4/12) -->
                        <section class="lg:col-span-4 au d3">
                            <?php include __DIR__ . '/_partials/dashboard_clinic_calendar.php'; ?>
                        </section>

                        <!-- COL 2: Activity feed (5/12) -->
                        <section class="lg:col-span-5 au d3">
                            <div class="dash-panel">
                                <div class="dash-panel-head">
                                    <div class="sec-title">
                                        กิจกรรมของฉันล่าสุด
                                    </div>
                                    <?php if (!empty($recentActivity)): ?>
                                        <span class="dash-panel-count"><?= count($recentActivity) ?></span>
                                    <?php endif; ?>
                                </div>
                                <ul class="activity-list" id="activity-feed" role="log" aria-live="polite" aria-label="ความเคลื่อนไหวล่าสุด">
                                    <?php
                                    if ($recentActivity):
                                        // map action keyword → tone (color)
                                        $eventTone = function (string $action): array {
                                            $a = strtolower($action);
                                            if (str_contains($a, 'error') || str_contains($a, 'fail')) return ['tone' => 'danger',  'icon' => 'fa-circle-exclamation'];
                                            if (str_contains($a, 'login'))                              return ['tone' => 'info',    'icon' => 'fa-right-to-bracket'];
                                            if (str_contains($a, 'logout'))                             return ['tone' => 'neutral', 'icon' => 'fa-right-from-bracket'];
                                            if (str_contains($a, 'register') || str_contains($a, 'create')) return ['tone' => 'success', 'icon' => 'fa-user-plus'];
                                            if (str_contains($a, 'migrate'))                            return ['tone' => 'accent',  'icon' => 'fa-arrows-rotate'];
                                            if (str_contains($a, 'delete') || str_contains($a, 'remove')) return ['tone' => 'danger', 'icon' => 'fa-trash-can'];
                                            if (str_contains($a, 'update') || str_contains($a, 'edit'))   return ['tone' => 'info',   'icon' => 'fa-pen'];
                                            return ['tone' => 'neutral', 'icon' => 'fa-circle-dot'];
                                        };
                                        foreach ($recentActivity as $log):
                                            $et = $eventTone($log['action']);
                                            $userName = trim((string)($log['admin_name'] ?? ''));
                                            if ($userName === '') $userName = 'ระบบ';
                                    ?>
                                        <li class="activity-row activity-row--<?= $et['tone'] ?>">
                                            <div class="activity-dot"><i class="fa-solid <?= $et['icon'] ?>"></i></div>
                                            <div class="activity-body">
                                                <div class="activity-line">
                                                    <strong class="activity-user"><?= htmlspecialchars($userName) ?></strong>
                                                    <span class="activity-tag"><?= htmlspecialchars(strtolower($log['action'])) ?></span>
                                                </div>
                                                <?php if (!empty($log['description'])): ?>
                                                    <p class="activity-desc"><?= htmlspecialchars($log['description']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <time class="activity-time" datetime="<?= htmlspecialchars($log['created_at']) ?>"
                                                  title="<?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>">
                                                <?= date('H:i', strtotime($log['created_at'])) ?>
                                            </time>
                                        </li>
                                    <?php endforeach; else: ?>
                                        <li class="activity-empty">
                                            <i class="fa-solid fa-circle-check"></i>
                                            ยังไม่มีกิจกรรมของคุณในระบบ
                                        </li>
                                    <?php endif; ?>
                                </ul>
                                <?php if ($isSuper || !empty($_SESSION['access_system_logs'])): ?>
                                <a href="javascript:switchSection('activity_logs', document.querySelector('[data-section=activity_logs]'))"
                                    class="activity-view-all">
                                    ดูของระบบทั้งหมด <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- COL 3: Pinned apps + Quick shortcuts + Slim migration banner (3/12) -->
                        <aside class="lg:col-span-3 flex flex-col gap-5 au d4">

                            <!-- Pinned apps mini-list -->
                            <?php
                            $pinnedProjects = [];
                            if (!empty($userPins)) {
                                foreach ($projects as $p) {
                                    if (in_array($p['id'], $userPins, true)) $pinnedProjects[] = $p;
                                }
                            }
                            ?>
                            <div class="dash-panel">
                                <div class="dash-panel-head">
                                    <div class="sec-title" style="font-size:.95rem">
                                        <i class="fa-solid fa-thumbtack" style="color:#f59e0b;font-size:.78rem;margin-right:2px"></i>
                                        ปักหมุด
                                    </div>
                                    <a href="javascript:switchSection('apps', document.querySelector('[data-section=apps]'))"
                                        class="dash-panel-link">
                                        ทั้งหมด <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                                <?php if (!empty($pinnedProjects)): ?>
                                <ul class="pinned-list">
                                    <?php foreach ($pinnedProjects as $pp):
                                        $primaryAction = $pp['actions'][0] ?? null;
                                        if (!$primaryAction) continue;
                                    ?>
                                    <li>
                                        <a href="<?= htmlspecialchars($primaryAction['url']) ?>" class="pinned-row">
                                            <span class="pinned-row-ic <?= $pp['bg_color'] ?> <?= $pp['icon_color'] ?>">
                                                <i class="fa-solid <?= $pp['icon'] ?>"></i>
                                            </span>
                                            <span class="pinned-row-label"><?= htmlspecialchars($pp['title']) ?></span>
                                            <i class="fa-solid fa-arrow-right pinned-row-arrow"></i>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                <div class="pinned-mini-empty">
                                    <i class="fa-solid fa-thumbtack"></i>
                                    <span>ปักหมุดระบบที่ใช้บ่อยใน <a href="javascript:switchSection('apps', document.querySelector('[data-section=apps]'))">App Launcher</a></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quick Shortcuts (flat, role-aware) -->
                            <?php
                            $quickShortcuts = [];
                            if ($isStaff && $canEcampaign) {
                                $quickShortcuts[] = ['url' => '../staff/index.php',        'icon' => 'fa-qrcode',         'label' => 'เปิดสแกน QR เช็คอิน'];
                                $quickShortcuts[] = ['url' => '../admin/daily_report.php', 'icon' => 'fa-clipboard-list', 'label' => 'รายงานเช็คอินวันนี้'];
                            }
                            if ($canEcampaign) {
                                $quickShortcuts[] = ['url' => '../admin/campaigns.php', 'icon' => 'fa-bullhorn',     'label' => 'Campaign Manager'];
                                $quickShortcuts[] = ['url' => '../admin/bookings.php',  'icon' => 'fa-calendar-check', 'label' => 'รายการนัดหมาย'];
                            }
                            if (!$isStaff || in_array($adminRole, ['admin', 'superadmin'], true)) {
                                $quickShortcuts[] = ['url' => 'users.php', 'icon' => 'fa-users', 'label' => 'Users Center'];
                            }
                            $quickShortcuts[] = ['url' => '../asset/index.php',       'icon' => 'fa-boxes-stacked', 'label' => 'ครุภัณฑ์สำนักงาน'];
                            $quickShortcuts[] = ['url' => '../consumables/index.php', 'icon' => 'fa-box-open',      'label' => 'วัสดุสิ้นเปลือง'];
                            if ($canSystemLogs) {
                                $quickShortcuts[] = [
                                    'url'   => "javascript:switchSection('error_logs', document.querySelector('[data-section=error_logs]'))",
                                    'icon'  => 'fa-bug',
                                    'label' => 'Error Logs',
                                ];
                            }
                            ?>
                            <div class="dash-panel quick-list">
                                <div class="dash-panel-head">
                                    <div class="sec-title" style="font-size:.95rem">
                                        <i class="fa-solid fa-bolt" style="color:#0ea5e9;font-size:.78rem;margin-right:2px"></i>
                                        ทางลัด
                                    </div>
                                </div>
                                <ul class="quick-items">
                                    <?php foreach ($quickShortcuts as $sc): ?>
                                        <li>
                                            <a href="<?= htmlspecialchars($sc['url']) ?>">
                                                <i class="fa-solid <?= htmlspecialchars($sc['icon']) ?>"></i>
                                                <?= htmlspecialchars($sc['label']) ?>
                                                <i class="fa-solid fa-arrow-right ml-auto"></i>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- 🚀 Slim migration banner (dismissable) -->
                            <div id="apps-migration-banner" class="apps-migration apps-migration--slim">
                                <div class="apps-migration-glow"></div>
                                <div class="apps-migration-body">
                                    <div class="apps-migration-eyebrow">
                                        <i class="fa-solid fa-sparkles"></i> ใหม่
                                    </div>
                                    <h2 class="apps-migration-title">
                                        เปิดระบบทั้งหมดที่ <span>App Launcher</span>
                                    </h2>
                                    <p class="apps-migration-desc">
                                        เมนูเปิดทุกระบบย้ายไปอยู่หน้าใหม่แล้ว — เปิดได้จาก sidebar กลุ่ม OVERVIEW
                                    </p>
                                    <div class="apps-migration-actions">
                                        <a href="javascript:switchSection('apps', document.querySelector('[data-section=apps]'))"
                                            class="apps-migration-cta" id="apps-migration-cta">
                                            <i class="fa-solid fa-grip"></i> เปิด
                                        </a>
                                        <button type="button" id="apps-migration-tour-btn" class="apps-migration-ghost">
                                            <i class="fa-solid fa-route"></i>
                                        </button>
                                        <button type="button" id="apps-migration-dismiss" class="apps-migration-dismiss" title="ซ่อน">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </div>
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

            <!-- ════════════ SECTION: APP LAUNCHER ════════════ -->
            <div id="section-apps" class="portal-section"
                style="<?= $activeSection==='apps'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); overflow-y:auto;">
                <?php include __DIR__ . '/_partials/apps_launcher.php'; ?>
            </div><!-- /section-apps -->

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
                            <div class="sec-title" style="margin-bottom:4px">
                                <div style="width:28px;height:28px;border-radius:8px;background:#fdf2f8;color:#db2777;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;">
                                    <i class="fa-solid fa-bullhorn"></i>
                                </div>
                                จัดการประกาศ
                            </div>
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
                                    <?php if (!empty($ann['title_en'])): ?>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe">EN</span>
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
                    <form method="POST" id="ann-form" enctype="multipart/form-data" style="padding:24px 28px;display:flex;flex-direction:column;gap:16px">
                        <?php csrf_field(); ?>
                        <input type="hidden" id="ann-form-action" name="ann_action" value="create">
                        <input type="hidden" id="ann-form-id" name="ann_id" value="0">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">หัวข้อประกาศ (TH) <span style="color:red">*</span></label>
                                <input type="text" id="ann-f-title" name="ann_title" required class="premium-input" placeholder="เช่น แจ้งวันหยุดให้บริการ">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">Announcement Title (EN)</label>
                                <input type="text" id="ann-f-title-en" name="ann_title_en" class="premium-input" placeholder="e.g. Holiday Announcement">
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">เนื้อหา (TH) <span style="color:red">*</span></label>
                                <textarea id="ann-f-content" name="ann_content" required rows="4" class="premium-input" style="resize:vertical" placeholder="รายละเอียดของประกาศ..."></textarea>
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">Content (EN)</label>
                                <textarea id="ann-f-content-en" name="ann_content_en" rows="4" class="premium-input" style="resize:vertical" placeholder="Announcement details in English..."></textarea>
                            </div>
                        </div>

                        <div>
                            <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">รูปภาพประกอบ (ถ้ามี)</label>
                            <input type="hidden" id="ann-f-image-existing" name="ann_image_existing" value="">
                            <input type="hidden" id="ann-f-image-clear"    name="ann_image_clear"    value="">

                            <!-- Drop zone / clickable -->
                            <label for="ann-f-image-file" id="ann-image-drop"
                                style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:18px;border:1.5px dashed #cbd5e1;border-radius:14px;background:#f8fafc;cursor:pointer;transition:all .2s;text-align:center">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size:22px;color:#7c3aed"></i>
                                <span style="font-size:13px;font-weight:700;color:#0f172a">คลิกเพื่อแนบไฟล์ภาพ</span>
                                <span style="font-size:11px;color:#94a3b8">JPG / PNG / WebP / GIF — สูงสุด 5 MB</span>
                            </label>
                            <input type="file" id="ann-f-image-file" name="ann_image_file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">

                            <!-- Preview -->
                            <div id="ann-image-preview-wrap" style="display:none;margin-top:10px;position:relative;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc">
                                <img id="ann-image-preview" src="" alt="preview"
                                    style="display:block;width:100%;max-height:220px;object-fit:contain;background:#fff">
                                <div id="ann-image-preview-meta" style="padding:8px 12px;font-size:11px;color:#64748b;background:#fff;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px">
                                    <span id="ann-image-preview-name" style="font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                                    <button type="button" onclick="annClearImage()"
                                        style="flex-shrink:0;padding:5px 10px;border-radius:8px;border:1px solid #fecaca;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;cursor:pointer">
                                        <i class="fa-solid fa-trash mr-1"></i> ลบรูป
                                    </button>
                                </div>
                            </div>
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
                <?php if (!($isSuper || !empty($_SESSION['access_identity']))): ?>
                    <div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_identity</span></div>
                <?php else: ?>
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
                            <button class="id-tab" data-tab="positions" onclick="switchIdTab('positions',this)">ตำแหน่งงาน
                                (<?= count($allPositions ?? []) ?>)</button>
                            <button class="id-tab" data-tab="departments" onclick="switchIdTab('departments',this)">ฝ่าย/หน่วยงาน
                                (<?= count($allDepartments ?? []) ?>)</button>
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
                        $lineMigrationCoverage = max(0, min(100, (float)($lineMigration['coverage'] ?? 0)));
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

                        <!-- LINE Provider Migration -->
                        <div style="background:#fff;border-radius:20px;padding:20px;margin-bottom:20px;border:1.5px solid #dbeafe;box-shadow:0 4px 15px rgba(0,0,0,0.02)">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px">
                                <div>
                                    <div style="font-size:12px;font-weight:900;color:#1e40af;text-transform:uppercase;letter-spacing:0.1em;display:flex;align-items:center;gap:7px;margin-bottom:5px">
                                        <i class="fa-brands fa-line" style="color:#06c755"></i> LINE Provider Migration
                                    </div>
                                    <div style="font-size:12px;font-weight:700;color:#64748b">Coverage <?= number_format($lineMigrationCoverage, 1) ?>%</div>
                                </div>
                                <div style="font-size:24px;font-weight:900;color:#1e293b;line-height:1"><?= number_format($lineMigrationCoverage, 1) ?>%</div>
                            </div>

                            <?php if (empty($lineMigration['has_new_column'])): ?>
                                <div style="display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;padding:12px 14px;margin-bottom:16px;color:#92400e;font-size:12px;font-weight:700;line-height:1.5">
                                    <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i>
                                    <span>ไม่พบคอลัมน์ <code style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#fef3c7;padding:1px 5px;border-radius:5px">line_user_id_new</code> กรุณารัน migration ก่อน เพื่อเริ่มเก็บ UID จาก LINE Provider ใหม่</span>
                                </div>
                            <?php endif; ?>

                            <div style="width:100%;height:14px;border-radius:99px;background:#e2e8f0;overflow:hidden;margin-bottom:16px;box-shadow:inset 0 2px 4px rgba(0,0,0,0.04)">
                                <div style="width:<?= $lineMigrationCoverage ?>%;height:100%;background:linear-gradient(90deg,#06c755,#22c55e);transition:width 1s"></div>
                            </div>

                            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
                                <div style="border:1.5px solid #e2e8f0;border-radius:14px;padding:12px;background:#f8fafc;min-width:0">
                                    <div style="font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">เดิม</div>
                                    <div style="font-size:20px;font-weight:900;color:#1e293b;line-height:1.1"><?= number_format((int)($lineMigration['old_uid_count'] ?? 0)) ?></div>
                                </div>
                                <div style="border:1.5px solid #bbf7d0;border-radius:14px;padding:12px;background:#f0fdf4;min-width:0">
                                    <div style="font-size:10px;font-weight:900;color:#15803d;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">ย้ายแล้ว</div>
                                    <div style="font-size:20px;font-weight:900;color:#166534;line-height:1.1"><?= number_format((int)($lineMigration['migrated_count'] ?? 0)) ?></div>
                                </div>
                                <div style="border:1.5px solid #fed7aa;border-radius:14px;padding:12px;background:#fff7ed;min-width:0">
                                    <div style="font-size:10px;font-weight:900;color:#c2410c;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">คงค้าง</div>
                                    <div style="font-size:20px;font-weight:900;color:#9a3412;line-height:1.1"><?= number_format((int)($lineMigration['pending_count'] ?? 0)) ?></div>
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
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="AI Suite"><i class="fa-solid fa-wand-magic-sparkles"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Consumables"><i class="fa-solid fa-syringe"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Asset"><i class="fa-solid fa-warehouse"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Scholarship"><i class="fa-solid fa-graduation-cap"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Dashboard Editor"><i class="fa-solid fa-chart-pie"></i></th>
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

                                            // New extension flags
                                            $aiAccess = (int)($st['access_ai'] ?? 0);
                                            $consAccess = (int)($st['access_consumables'] ?? 0);
                                            $assetAccess = (int)($st['access_asset'] ?? 0);
                                            $financeAccess = (int)($st['access_finance'] ?? 0);
                                            $scholarAccess = (int)($st['access_scholarship'] ?? 0);
                                            $dashAccess = (int)($st['access_dashboard_admin'] ?? 0);
                                            $aiIcon = $aiAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $consIcon = $consAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $assetIcon = $assetAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $scholarIcon = $scholarAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $dashIcon = $dashAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-staff-row hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:16px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div style="width:36px;height:36px;border-radius:10px;background:<?= $isActive ? 'linear-gradient(135deg,#3b82f6,#1d4ed8)' : '#f1f5f9' ?>;color:<?= $isActive ? '#fff' : '#94a3b8' ?>;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px">
                                                            <?= mb_substr($st['full_name'], 0, 1) ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:800;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:6px">
                                                                <?= htmlspecialchars($st['full_name']) ?>
                                                                <?php
                                                                $_jt = trim((string)($st['job_title'] ?? ''));
                                                                $_org = trim((string)($st['org_position_title'] ?? ''));
                                                                $_label = $_jt !== '' ? $_jt : $_org;
                                                                if ($_label !== ''):
                                                                ?>
                                                                <span title="<?= $_jt !== '' ? 'ตำแหน่งงาน' : 'จากผังองค์กร' ?>"
                                                                      style="display:inline-block;padding:1px 7px;border-radius:99px;background:<?= $_jt !== '' ? '#ecfeff' : '#f1f5f9' ?>;color:<?= $_jt !== '' ? '#0891b2' : '#64748b' ?>;border:1px solid <?= $_jt !== '' ? '#a5f3fc' : '#e2e8f0' ?>;font-size:10px;font-weight:800"><?= htmlspecialchars($_label) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div style="font-size:11px;color:#64748b;font-weight:600">@<?= htmlspecialchars($st['username']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:center"><?= $ebIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $ecIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $insIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $logsIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $settIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $aiIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $consIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $assetIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $scholarIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $dashIcon ?></td>
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

                    <!-- PANEL: Positions (ตำแหน่งงาน) -->
                    <?php if ($adminRole === 'superadmin'): ?>
                    <div id="id-panel-positions" class="id-panel">
                        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                                <div>
                                    <div style="font-size:14px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-user-tag" style="color:#7c3aed"></i>
                                        ตำแหน่งงาน (Position-based Access)
                                    </div>
                                    <p style="margin:4px 0 0;font-size:11px;color:#64748b;font-weight:600">
                                        สร้างตำแหน่งและกำหนด flag preset — staff ที่ผูกตำแหน่งจะได้รับ flag แบบ live link
                                    </p>
                                </div>
                                <button type="button" onclick="openAddPositionModal()" style="padding:10px 16px;border-radius:10px;border:none;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-weight:900;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 6px 14px -3px rgba(124,58,237,.35)">
                                    <i class="fa-solid fa-plus"></i> สร้างตำแหน่งใหม่
                                </button>
                            </div>

                            <?php if (empty($allPositions)): ?>
                                <div style="padding:60px 20px;text-align:center;color:#94a3b8">
                                    <i class="fa-solid fa-user-tag" style="font-size:38px;display:block;margin-bottom:12px;opacity:.4"></i>
                                    <p style="font-size:13px;font-weight:700;margin:0">ยังไม่มีตำแหน่งงานในระบบ</p>
                                    <p style="font-size:11px;color:#cbd5e1;margin:6px 0 0">คลิก "สร้างตำแหน่งใหม่" เพื่อเริ่มต้น</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto">
                                    <table style="width:100%;border-collapse:collapse;font-size:13px" id="idPositionTable">
                                        <thead>
                                            <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                                <th style="padding:14px 18px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">ตำแหน่ง</th>
                                                <th style="padding:14px 18px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">Flag ที่กำหนด</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">ผูกแล้ว</th>
                                                <th style="padding:14px 18px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $flagLabelMap = [
                                                'access_eborrow'        => ['e-Borrow',         '#f97316'],
                                                'access_ecampaign'      => ['e-Campaign',       '#2563eb'],
                                                'access_insurance'      => ['Insurance',        '#10b981'],
                                                'access_registry'       => ['Registry',         '#06b6d4'],
                                                'access_system_logs'    => ['Logs',             '#64748b'],
                                                'access_site_settings'  => ['Settings',         '#7c3aed'],
                                                'access_edms'           => ['EDMS',             '#0ea5e9'],
                                                'access_ai'             => ['AI Suite',         '#a855f7'],
                                                'access_consumables'    => ['Consumables',      '#f43f5e'],
                                                'access_asset'          => ['Asset',            '#f59e0b'],
                                                'access_finance'        => ['Finance',          '#059669'],
                                                'access_scholarship'    => ['Scholarship',      '#10b981'],
                                                'access_dashboard_admin'=> ['Dashboard Editor', '#3b82f6'],
                                            ];
                                            foreach ($allPositions as $pos):
                                                $posFlags = json_decode($pos['flags'] ?? '{}', true) ?: [];
                                                $activeFlags = array_keys(array_filter($posFlags, fn($v) => (int)$v === 1));
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:14px 18px;vertical-align:top">
                                                    <div style="font-weight:800;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:8px">
                                                        <i class="fa-solid fa-user-tag" style="color:#7c3aed;font-size:11px"></i>
                                                        <?= htmlspecialchars($pos['name']) ?>
                                                    </div>
                                                    <?php if (!empty($pos['description'])): ?>
                                                        <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:3px"><?= htmlspecialchars($pos['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px">
                                                    <?php if (empty($activeFlags)): ?>
                                                        <span style="font-size:11px;color:#cbd5e1;font-weight:700">— ไม่มี flag —</span>
                                                    <?php else: ?>
                                                        <div style="display:flex;flex-wrap:wrap;gap:5px">
                                                            <?php foreach ($activeFlags as $f):
                                                                [$label, $color] = $flagLabelMap[$f] ?? [$f, '#64748b'];
                                                            ?>
                                                                <span style="font-size:10px;font-weight:800;padding:3px 9px;border-radius:99px;background:<?= $color ?>15;color:<?= $color ?>;border:1px solid <?= $color ?>40">
                                                                    <?= htmlspecialchars($label) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="display:inline-block;font-size:11px;font-weight:900;padding:4px 10px;border-radius:99px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe"><?= (int)($pos['staff_count'] ?? 0) ?> คน</span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button type="button" onclick='openEditPositionModal(<?= json_encode($pos) ?>)' style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer" title="แก้ไข"><i class="fa-solid fa-pen-to-square" style="font-size:12px"></i></button>
                                                        <form method="POST" style="display:inline" onsubmit="return confirmDeletePosition(this, '<?= htmlspecialchars(addslashes($pos['name']), ENT_QUOTES) ?>', <?= (int)($pos['staff_count'] ?? 0) ?>)">
                                                            <input type="hidden" name="action" value="delete_position">
                                                            <input type="hidden" name="position_id" value="<?= (int)$pos['id'] ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit" style="width:32px;height:32px;border-radius:9px;border:1.5px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer" title="ลบ"><i class="fa-solid fa-trash-can" style="font-size:12px"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- PANEL: Departments (ฝ่าย/หน่วยงาน) -->
                    <?php if ($adminRole === 'superadmin'): ?>
                    <div id="id-panel-departments" class="id-panel">
                        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                                <div>
                                    <div style="font-size:14px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-sitemap" style="color:#6366f1"></i>
                                        ฝ่าย/หน่วยงาน (Department Master)
                                    </div>
                                    <p style="margin:4px 0 0;font-size:11px;color:#64748b;font-weight:600">
                                        จัดการฝ่ายของคลินิก — ใช้ผูกกับ Staff (ผู้กรอกรายงาน) และ Template ของรายงานประจำเดือน
                                    </p>
                                </div>
                                <button type="button" onclick="openAddDeptModal()" style="padding:10px 16px;border-radius:10px;border:none;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-weight:900;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 6px 14px -3px rgba(99,102,241,.35)">
                                    <i class="fa-solid fa-plus"></i> เพิ่มฝ่ายใหม่
                                </button>
                            </div>

                            <?php if (empty($allDepartments)): ?>
                                <div style="padding:60px 20px;text-align:center;color:#94a3b8">
                                    <i class="fa-solid fa-sitemap" style="font-size:38px;display:block;margin-bottom:12px;opacity:.4"></i>
                                    <p style="font-size:13px;font-weight:700;margin:0">ยังไม่มีฝ่ายในระบบ</p>
                                    <p style="font-size:11px;color:#cbd5e1;margin:6px 0 0">คลิก "เพิ่มฝ่ายใหม่" เพื่อเริ่มต้น</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto">
                                    <table style="width:100%;border-collapse:collapse;font-size:13px" id="idDeptTable">
                                        <thead>
                                            <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                                <th style="padding:14px 18px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">ชื่อฝ่าย</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:90px">ลำดับ</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">Staff ที่ผูก</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">รายงาน</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:90px">สถานะ</th>
                                                <th style="padding:14px 18px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($allDepartments as $dept): ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:14px 18px;vertical-align:top">
                                                    <div style="font-weight:800;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:8px">
                                                        <i class="fa-solid fa-building" style="color:#6366f1;font-size:11px"></i>
                                                        <?= htmlspecialchars($dept['name']) ?>
                                                    </div>
                                                    <?php if (!empty($dept['description'])): ?>
                                                        <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:3px"><?= htmlspecialchars($dept['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="font-size:12px;font-weight:800;color:#475569"><?= (int)($dept['sort_order'] ?? 0) ?></span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="display:inline-block;font-size:11px;font-weight:900;padding:4px 10px;border-radius:99px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe"><?= (int)($dept['staff_count'] ?? 0) ?> คน</span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="display:inline-block;font-size:11px;font-weight:900;padding:4px 10px;border-radius:99px;background:#fef3c7;color:#92400e;border:1px solid #fde68a"><?= (int)($dept['report_count'] ?? 0) ?></span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <?php if ((int)$dept['active'] === 1): ?>
                                                        <span style="font-size:10px;font-weight:900;padding:3px 9px;border-radius:99px;background:#d1fae5;color:#065f46">เปิดใช้</span>
                                                    <?php else: ?>
                                                        <span style="font-size:10px;font-weight:900;padding:3px 9px;border-radius:99px;background:#f1f5f9;color:#64748b">ปิด</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button type="button" onclick='openEditDeptModal(<?= json_encode($dept, JSON_UNESCAPED_UNICODE) ?>)' style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer" title="แก้ไข"><i class="fa-solid fa-pen-to-square" style="font-size:12px"></i></button>
                                                        <button type="button" onclick="deleteDept(<?= (int)$dept['id'] ?>, <?= json_encode($dept['name'], JSON_UNESCAPED_UNICODE) ?>, <?= (int)$dept['staff_count'] ?>, <?= (int)$dept['report_count'] ?>)" style="width:32px;height:32px;border-radius:9px;border:1.5px solid #fecaca;background:#fff;color:#dc2626;cursor:pointer" title="ลบ"><i class="fa-solid fa-trash" style="font-size:12px"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endif; ?>
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

                                    <!-- Job Title (free-text descriptor — e.g. พยาบาล/ธุรการ) — ไม่เกี่ยวกับ permission -->
                                    <div id="govJobTitleWrap" style="display:none">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">
                                            <i class="fa-solid fa-id-badge" style="color:#0891b2"></i> ตำแหน่งงาน (Job Title)
                                            <span style="color:#94a3b8;font-weight:normal;font-size:11px">เช่น พยาบาล / ธุรการ / แพทย์ — ไม่เกี่ยวกับสิทธิ์</span>
                                        </label>
                                        <input type="text" name="job_title" id="govJobTitle" class="premium-input" style="width:100%" maxlength="120"
                                               list="govJobTitleSuggest" placeholder="เช่น พยาบาล">
                                        <datalist id="govJobTitleSuggest">
                                            <option value="พยาบาลวิชาชีพ">
                                            <option value="พยาบาลเทคนิค">
                                            <option value="ผู้ช่วยพยาบาล">
                                            <option value="แพทย์">
                                            <option value="ผู้ช่วยแพทย์">
                                            <option value="ธุรการ">
                                            <option value="เภสัชกร">
                                            <option value="ผู้ช่วยเภสัชกร">
                                            <option value="หัวหน้าฝ่าย">
                                            <option value="ผู้จัดการ">
                                            <option value="IT Support">
                                        </datalist>
                                        <p id="govOrgPositionInfo" style="display:none;margin:6px 0 0;font-size:11px;color:#0891b2;font-weight:600">
                                            <i class="fa-solid fa-sitemap"></i> ตำแหน่งในผังองค์กร: <span id="govOrgPositionTitle"></span>
                                            <span style="color:#94a3b8">(แก้ที่ Chain of Command)</span>
                                        </p>
                                    </div>

                                    <!-- Permission Template selector — Hybrid: ผูก position = lock flag, Custom = override เอง -->
                                    <div id="govPositionWrap" style="display:none">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">
                                            <i class="fa-solid fa-user-tag" style="color:#7c3aed"></i> ชุดสิทธิ์ตำแหน่ง (Permission Template)
                                            <span style="color:#94a3b8;font-weight:normal;font-size:11px">ผูกแล้ว flag จะ lock ตามตำแหน่ง</span>
                                        </label>
                                        <select name="position_id" id="govPositionId" class="premium-input" style="width:100%;background-image:none" onchange="onGovPositionChange()">
                                            <option value="">— Custom (กำหนด flag เอง) —</option>
                                            <?php foreach (($allPositions ?? []) as $pos): ?>
                                                <option value="<?= (int)$pos['id'] ?>" data-flags='<?= htmlspecialchars($pos['flags'] ?? '{}', ENT_QUOTES) ?>'>
                                                    <?= htmlspecialchars($pos['name']) ?><?= !empty($pos['description']) ? ' — ' . htmlspecialchars($pos['description']) : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p id="govPositionLockNote" style="display:none;margin:6px 0 0;font-size:11px;color:#7c3aed;font-weight:700">
                                            <i class="fa-solid fa-lock"></i> Flag ของตำแหน่งจะถูก apply ทันที (live link) — ปลด lock โดยเลือก "Custom"
                                        </p>
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
                                            <!-- Registry (ฝ่ายทะเบียน — upload only) -->
                                            <div onclick="document.getElementById('govRegAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-id-card-clip text-cyan-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Registry Upload (ฝ่ายทะเบียน)</span>
                                                </div>
                                                <input type="checkbox" name="reg_access" id="govRegAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
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
                                            <!-- EDMS (สารบรรณอิเล็กทรอนิกส์) -->
                                            <div onclick="document.getElementById('govEdmsAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-folder-open text-sky-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">สารบรรณอิเล็กทรอนิกส์ (EDMS)</span>
                                                </div>
                                                <input type="checkbox" name="edms_access" id="govEdmsAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- AI Suite (Assistant / QA Lab / Prompts / Knowledge) -->
                                            <div onclick="document.getElementById('govAiAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-wand-magic-sparkles text-purple-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">AI Suite (Assistant / QA / Prompts / Knowledge)</span>
                                                </div>
                                                <input type="checkbox" name="ai_access" id="govAiAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Consumables (วัสดุสิ้นเปลือง) -->
                                            <div onclick="document.getElementById('govConsumablesAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-syringe text-rose-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">วัสดุสิ้นเปลือง (Consumables)</span>
                                                </div>
                                                <input type="checkbox" name="consumables_access" id="govConsumablesAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Asset (ครุภัณฑ์) -->
                                            <div onclick="document.getElementById('govAssetAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-warehouse text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">ครุภัณฑ์ (Asset Inventory)</span>
                                                </div>
                                                <input type="checkbox" name="asset_access" id="govAssetAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Finance (Cash Book) -->
                                            <div onclick="document.getElementById('govFinanceAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-money-bill-trend-up text-emerald-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">การเงิน (Cash Book)</span>
                                                </div>
                                                <input type="checkbox" name="finance_access" id="govFinanceAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Scholarship (นักศึกษาทุน) -->
                                            <div onclick="document.getElementById('govScholarshipAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-graduation-cap text-emerald-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">นักศึกษาทุน (Scholarship)</span>
                                                </div>
                                                <input type="checkbox" name="scholarship_access" id="govScholarshipAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Dashboard Admin (แก้ไข Insurance Dashboard) -->
                                            <div onclick="document.getElementById('govDashboardAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-chart-pie text-blue-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Dashboard Workbook Editor (สิทธิ์แก้ไข widget)</span>
                                                </div>
                                                <input type="checkbox" name="dashboard_admin_access" id="govDashboardAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Monthly Report (กรอกรายงานประจำเดือน) -->
                                            <div onclick="document.getElementById('govMonthlyReportAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-clipboard-list text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">รายงานประจำเดือน (กรอก/แก้ของฝ่ายตัวเอง)</span>
                                                </div>
                                                <input type="checkbox" name="monthly_report_access" id="govMonthlyReportAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Nurse Productivity -->
                                            <div onclick="document.getElementById('govNurseProductivityAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-user-nurse text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Productivity พยาบาล OPD (คำนวณภาระงาน)</span>
                                                </div>
                                                <input type="checkbox" name="nurse_productivity_access" id="govNurseProductivityAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Daily Summary -->
                                            <div onclick="document.getElementById('govDailySummaryAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-clipboard-check text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">สรุปงานประจำวัน (Daily Summary dashboard)</span>
                                                </div>
                                                <input type="checkbox" name="daily_summary_access" id="govDailySummaryAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Director View (ผู้อำนวยการ) -->
                                            <div onclick="document.getElementById('govDirectorViewAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-user-tie text-rose-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">ผู้อำนวยการ (ดูทุกฝ่าย + อนุมัติรายงาน)</span>
                                                </div>
                                                <input type="checkbox" name="director_view_access" id="govDirectorViewAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Identity & Governance (จัดการสิทธิ์ผู้ใช้) -->
                                            <div onclick="document.getElementById('govIdentityAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-id-card-clip text-blue-600"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Identity &amp; Governance (จัดการสิทธิ์/ตำแหน่ง/ฝ่าย)</span>
                                                </div>
                                                <input type="checkbox" name="identity_access" id="govIdentityAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Department dropdown -->
                                            <div class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px">
                                                <div style="display:flex;align-items:center;gap:10px;min-width:0">
                                                    <i class="fa-solid fa-sitemap text-indigo-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569;white-space:nowrap">ฝ่าย/หน่วยงาน</span>
                                                </div>
                                                <select name="department_id" id="govDepartmentId" class="premium-input" style="flex:1;height:32px;padding:0 8px;font-size:12px;font-weight:700">
                                                    <option value="">— ไม่ระบุ —</option>
                                                    <?php
                                                    try {
                                                        $deptRows = $pdo->query("SELECT id, name FROM sys_departments WHERE active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
                                                        foreach ($deptRows as $d) {
                                                            echo '<option value="' . (int)$d['id'] . '">' . htmlspecialchars($d['name']) . '</option>';
                                                        }
                                                    } catch (PDOException $e) { /* table not yet created */ }
                                                    ?>
                                                </select>
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

                <!-- Position (ตำแหน่งงาน) Modal -->
                <?php if ($adminRole === 'superadmin'): ?>
                <div id="idPosModal" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(15,23,42,.6);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:24px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)">
                        <form method="POST" id="idPosForm">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" id="posAction" value="add_position">
                            <input type="hidden" name="position_id" id="posId" value="">

                            <div style="padding:22px 26px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div style="width:38px;height:38px;background:#f5f3ff;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#7c3aed">
                                        <i class="fa-solid fa-user-tag"></i>
                                    </div>
                                    <span id="posModalTitle" style="font-size:15px;font-weight:900;color:#1e293b">สร้างตำแหน่งใหม่</span>
                                </div>
                                <button type="button" onclick="document.getElementById('idPosModal').style.display='none'" style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
                            </div>

                            <div style="padding:22px 26px;display:flex;flex-direction:column;gap:16px">
                                <div>
                                    <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">ชื่อตำแหน่ง <span style="color:#ef4444">*</span></label>
                                    <input type="text" name="position_name" id="posName" required class="premium-input" style="width:100%" placeholder="เช่น ธุรการ, ดูแลข้อมูลคลินิก, ดูแลนักศึกษาทุน">
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">คำอธิบาย <span style="font-weight:normal;color:#94a3b8;font-size:11px">(ไม่บังคับ)</span></label>
                                    <textarea name="position_description" id="posDescription" class="premium-input" style="width:100%;min-height:60px;resize:vertical" placeholder="หน้าที่ความรับผิดชอบหรือ scope ของตำแหน่งนี้"></textarea>
                                </div>

                                <div>
                                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#475569;margin-bottom:8px">
                                        <i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> เลือก Flag ที่ตำแหน่งนี้จะได้รับ
                                    </label>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                        <?php
                                        $posFlagInputs = [
                                            'access_eborrow'        => ['e-Borrow',         'fa-toolbox',            '#f97316'],
                                            'access_ecampaign'      => ['e-Campaign',       'fa-bullhorn',           '#2563eb'],
                                            'access_insurance'      => ['Insurance Sync',   'fa-shield-halved',      '#10b981'],
                                            'access_registry'       => ['Registry Upload',  'fa-id-card-clip',       '#06b6d4'],
                                            'access_system_logs'    => ['System Logs',      'fa-list-ul',            '#64748b'],
                                            'access_site_settings'  => ['Site Settings',    'fa-sliders',            '#7c3aed'],
                                            'access_edms'           => ['EDMS',             'fa-folder-open',        '#0ea5e9'],
                                            'access_ai'             => ['AI Suite',         'fa-wand-magic-sparkles','#a855f7'],
                                            'access_consumables'    => ['Consumables',      'fa-syringe',            '#f43f5e'],
                                            'access_asset'          => ['Asset Inventory',  'fa-warehouse',          '#f59e0b'],
                                            'access_finance'        => ['การเงิน (Cash Book)','fa-money-bill-trend-up','#059669'],
                                            'access_scholarship'    => ['Scholarship',      'fa-graduation-cap',     '#10b981'],
                                            'access_dashboard_admin'=> ['Dashboard Editor', 'fa-chart-pie',          '#3b82f6'],
                                            'access_monthly_report' => ['รายงานประจำเดือน',  'fa-clipboard-list',     '#f59e0b'],
                                            'access_nurse_productivity'=>['Productivity พยาบาล','fa-user-nurse',         '#f59e0b'],
                                            'access_daily_summary'  => ['สรุปงานประจำวัน',     'fa-clipboard-check',    '#f59e0b'],
                                            'access_director_view'  => ['ผู้อำนวยการ',       'fa-user-tie',           '#f43f5e'],
                                            'access_identity'       => ['Identity & Gov',     'fa-id-card-clip',       '#2563eb'],
                                        ];
                                        foreach ($posFlagInputs as $key => [$label, $icon, $color]):
                                        ?>
                                            <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all .15s;background:#fff" class="pos-flag-card">
                                                <input type="checkbox" name="flag_<?= $key ?>" id="posFlag_<?= $key ?>" value="1" style="width:15px;height:15px;cursor:pointer">
                                                <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;font-size:11px"></i>
                                                <span style="font-size:11.5px;font-weight:700;color:#475569"><?= $label ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div style="padding:18px 26px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;gap:10px">
                                <button type="button" onclick="document.getElementById('idPosModal').style.display='none'" style="flex:1;padding:11px;border-radius:11px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-weight:800;font-size:13px;cursor:pointer">ยกเลิก</button>
                                <button type="submit" style="flex:2;padding:11px;border-radius:11px;border:none;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-weight:900;font-size:13px;cursor:pointer;box-shadow:0 8px 16px -4px rgba(124,58,237,.3);display:flex;align-items:center;justify-content:center;gap:8px">
                                    <i class="fa-solid fa-floppy-disk"></i> บันทึกตำแหน่ง
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

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

            <!-- ════════════ SECTION: FINANCE (Cash Book) ════════════ -->
            <div id="section-finance" class="portal-section"
                style="<?= $activeSection==='finance'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance'])) {
                    include __DIR__ . '/_partials/finance.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_finance หรือ role: admin/superadmin</span></div>';
                }
                ?>
            </div>

            <?php
                // AI Suite gate (รวม Assistant / QA Lab / Prompts / Knowledge)
                $hasAi = $isSuper || !empty($_SESSION['access_ai']);
                $aiDeniedHtml = '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_ai</span></div>';
            ?>
            <!-- ════════════ SECTION: AI ASSISTANT ════════════ -->
            <div id="section-ai_assistant" class="portal-section"
                style="<?= $activeSection==='ai_assistant'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); overflow:hidden;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/ai_assistant.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
            </div>

            <!-- ════════════ SECTION: AI QA LAB ════════════ -->
            <div id="section-ai_prompts" class="portal-section"
                style="<?= $activeSection==='ai_prompts'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/ai_prompts.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
            </div>

            <div id="section-ai_knowledge" class="portal-section"
                style="<?= $activeSection==='ai_knowledge'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/ai_knowledge.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
            </div>

            <div id="section-ai_qa_lab" class="portal-section"
                style="<?= $activeSection==='ai_qa_lab'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/ai_qa_lab.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
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

            <!-- ════════════ SECTION: INSURANCE DASHBOARD (Admin View+Edit) ════════════ -->
            <div id="section-insurance_dashboard" class="portal-section"
                style="<?= $activeSection==='insurance_dashboard'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_insurance']) || !empty($_SESSION['access_dashboard_admin'])) {
                    include __DIR__ . '/_partials/insurance_dashboard.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_insurance หรือ access_dashboard_admin</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: GOLD CARD PENDING REVIEW ════════════ -->
            <div id="section-gold_card_pending" class="portal-section"
                style="<?= $activeSection==='gold_card_pending'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_insurance'])) {
                    include __DIR__ . '/_partials/gold_card_pending.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_insurance</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: GOLD CARD (บัตรทอง) ════════════ -->
            <div id="section-gold_card" class="portal-section"
                style="<?= $activeSection==='gold_card'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_insurance'])) {
                    include __DIR__ . '/_partials/gold_card.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_insurance</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: CLINIC DATA ════════════ -->
            <div id="section-clinic_data" class="portal-section"
                style="<?= $activeSection==='clinic_data'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php include __DIR__ . '/_partials/clinic_data.php'; ?>
            </div>

            <!-- ════════════ SECTION: SCHOLARSHIP (นักศึกษาทุน) ════════════ -->
            <div id="section-scholarship" class="portal-section"
                style="<?= $activeSection==='scholarship'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($hasScholarship) {
                    include __DIR__ . '/_partials/scholarship.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_scholarship</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: NURSE SCHEDULE (ตารางเวรพยาบาล) ════════════ -->
            <div id="section-nurse_schedule" class="portal-section"
                style="<?= $activeSection==='nurse_schedule'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); padding:0; overflow:hidden;">
                <iframe src="nurse_schedule.php"
                    style="width:100%; height:100%; border:0; display:block;"
                    title="ระบบจัดตารางเวรพยาบาล"
                    loading="lazy"></iframe>
            </div>

            <!-- ════════════ SECTION: MANAGE INSURANCE PARTNERS ════════════ -->
            <div id="section-manage_insurance_partners" class="portal-section"
                style="<?= $activeSection==='manage_insurance_partners'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin') {
                    include __DIR__ . '/_partials/manage_insurance_partners.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">เฉพาะ superadmin เท่านั้น</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: REGISTRY UPLOAD (ฝ่ายทะเบียน) ════════════ -->
            <div id="section-registry_upload" class="portal-section"
                style="<?= $activeSection==='registry_upload'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_registry']) || !empty($_SESSION['access_insurance'])) {
                    include __DIR__ . '/_partials/registry_upload.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_registry หรือ access_insurance</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: BATCH STATUS (Workflow Tracker) ════════════ -->
            <div id="section-batch_status" class="portal-section"
                style="<?= $activeSection==='batch_status'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_insurance']) || !empty($_SESSION['access_registry'])) {
                    include __DIR__ . '/_partials/batch_status.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED</div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: PROFILE (staff self-service) ════════════ -->
            <div id="section-profile" class="portal-section"
                style="<?= $activeSection==='profile'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($isStaff) {
                    include __DIR__ . '/_partials/profile.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">หน้าโปรไฟล์ใช้ได้เฉพาะบัญชีเจ้าหน้าที่ (e-Campaign Staff)</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: ACTIVITY DASHBOARD (superadmin only) ════════════ -->
            <div id="section-activity_dashboard" class="portal-section"
                style="<?= $activeSection==='activity_dashboard'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin') {
                    include __DIR__ . '/_partials/activity_dashboard.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">Activity Dashboard available to superadmin only.</span></div>';
                }
                ?>
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

            <!-- ════════════ SECTION: SENTRY EVENTS ════════════ -->
            <div id="section-sentry_events" class="portal-section"
                style="<?= $activeSection==='sentry_events'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($adminRole === 'superadmin') {
                    include __DIR__ . '/_partials/sentry_events.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">Sentry Events เปิดเฉพาะ superadmin (อาจมีข้อมูล PII / stack trace)</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: MONTHLY REPORT ════════════ -->
            <div id="section-monthly_report" class="portal-section"
                style="<?= $activeSection==='monthly_report'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_monthly_report']) || !empty($_SESSION['access_director_view'])) {
                    include __DIR__ . '/_partials/monthly_report.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_monthly_report หรือ access_director_view</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: DAILY SUMMARY ════════════ -->
            <div id="section-daily_summary" class="portal-section"
                style="<?= $activeSection==='daily_summary'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($hasDailySummary) {
                    include __DIR__ . '/_partials/daily_summary.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_daily_summary</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: NURSE PRODUCTIVITY ════════════ -->
            <div id="section-nurse_productivity" class="portal-section"
                style="<?= $activeSection==='nurse_productivity'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($hasNurseProductivity) {
                    include __DIR__ . '/_partials/nurse_productivity.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_nurse_productivity</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: DOCUMENTS (Document Library) ════════════ -->
            <div id="section-documents" class="portal-section"
                style="<?= $activeSection==='documents'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || $adminRole === 'admin') {
                    include __DIR__ . '/_partials/documents.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">คลังเอกสารใช้ได้เฉพาะ superadmin / admin</span></div>';
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

            <!-- ════════════ SECTION: SENTRY TEST (superadmin only) ════════════ -->
            <div id="section-sentry_test" class="portal-section"
                style="<?= $activeSection==='sentry_test'?'':'display:none;' ?> background:#f8fafc; overflow-y:auto;">
                <?php
                if ($isSuper) {
                    include __DIR__ . '/_partials/sentry_test.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">Superadmin only.</span></div>';
                }
                ?>
            </div>

            <!-- ════════════ SECTION: EDMS (สารบรรณอิเล็กทรอนิกส์) ════════════ -->
            <div id="section-edms" class="portal-section"
                style="<?= $activeSection==='edms'?'':'display:none;' ?> width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_edms'])) {
                    include __DIR__ . '/_partials/edms.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_edms</span></div>';
                }
                ?>
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
                                                <?php if ($row['document_path']):
                                                    // document_path stored as 'storage/access_requests/...' (project-root relative).
                                                    // Page rendered from /portal/index.php — needs '../' prefix.
                                                    $_docRaw  = (string)$row['document_path'];
                                                    $_docHref = (str_starts_with($_docRaw, '/') || str_starts_with($_docRaw, '../'))
                                                        ? $_docRaw
                                                        : '../' . ltrim($_docRaw, './');
                                                ?>
                                                    <a href="<?= htmlspecialchars($_docHref) ?>" target="_blank" style="font-size:10px;color:#2563eb;text-decoration:none">
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
            const btn = document.getElementById('darkModeToggle');
            if (theme === 'dark') {
                document.body.setAttribute('data-theme', 'dark');
                if (btn) btn.innerHTML = '<i class="fa-solid fa-sun text-amber-500"></i>';
                localStorage.setItem('ecampaign_theme', 'dark');
            } else {
                document.body.removeAttribute('data-theme');
                if (btn) btn.innerHTML = '<i class="fa-solid fa-moon"></i>';
                localStorage.setItem('ecampaign_theme', 'light');
            }
            document.querySelectorAll('iframe').forEach(iframe => {
                try { iframe.contentWindow.postMessage({ type: 'THEME_CHANGE', theme }, '*'); } catch(e) {}
            });
        }

        // Sync toggle icon with the theme already applied by the early inline script
        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('ecampaign_theme') === 'dark') {
                const btn = document.getElementById('darkModeToggle');
                if (btn) btn.innerHTML = '<i class="fa-solid fa-sun text-amber-500"></i>';
            }
        });
    </script>

    <!-- ── KPI counter is now handled by assets/js/rsu-fx.js (IntersectionObserver-based) ── -->
    <script>
        /* ── Ripple on buttons ──────────────────────────────────── */
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

        /* ── 4. Global Search Filtering (Moved to Local) ──────── */
        const globalSearch = document.getElementById('search-project');
        const projCards = document.querySelectorAll('.proj-card');
        const projEmpty = document.getElementById('proj-empty');

        if (globalSearch) {
            globalSearch.addEventListener('input', function() {
                const val = this.value.toLowerCase().trim();
                let matchCount = 0;

                projCards.forEach(card => {
                    const name = card.dataset.name || '';
                    const keywords = card.dataset.keywords || '';
                    const isMatch = name.includes(val) || keywords.includes(val);
                    
                    card.style.display = isMatch ? '' : 'none';
                    if (isMatch) matchCount++;
                });

                if (projEmpty) {
                    projEmpty.style.display = (matchCount === 0 && val !== '') ? 'block' : 'none';
                }
            });
        }

        /* ── 5. Project Pinning (Database Driven) ───────────── */
        window.togglePin = function(projId, btn) {
            btn.disabled = true;
            const isPinned = btn.classList.contains('active');
            
            const fd = new FormData();
            fd.append('project_id', projId);

            fetch('ajax_pins.php?action=toggle', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.action === 'added') {
                        btn.classList.add('active');
                        document.getElementById('proj-' + projId).dataset.pinned = '1';
                    } else {
                        btn.classList.remove('active');
                        document.getElementById('proj-' + projId).dataset.pinned = '0';
                    }
                    applyProjectOrder();
                }
            })
            .finally(() => {
                btn.disabled = false;
            });
        };

        function applyProjectOrder() {
            const container = document.getElementById('project-container');
            if (!container) return;
            const cards = Array.from(container.querySelectorAll('.proj-card'));

            cards.sort((a, b) => {
                const aPinned = a.dataset.pinned === '1';
                const bPinned = b.dataset.pinned === '1';
                if (aPinned && !bPinned) return -1;
                if (!aPinned && bPinned) return 1;
                return 0;
            });

            cards.forEach(card => container.appendChild(card));
        }

        applyProjectOrder();
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
            const govPosWrap = document.getElementById('govPositionWrap');
            const govJobWrap = document.getElementById('govJobTitleWrap');
            if (type === 'admin') {
                title.textContent = (mode === 'add' ? 'เพิ่ม System Admin' : 'จัดการสิทธิ์ System Admin');
                icon.style.background = '#f5f3ff';
                icon.style.color = '#7c3aed';
                icon.innerHTML = '<i class="fa-solid fa-crown"></i>';
                document.getElementById('govAdminOnlyCard').style.display = 'block';
                document.getElementById('govEbCard').style.opacity = '0.5'; // Adms might not need borrow roles
                document.getElementById('govEcCard').style.opacity = '1';
                if (govPosWrap) govPosWrap.style.display = 'none';
                if (govJobWrap) govJobWrap.style.display = 'none';
            } else {
                title.textContent = (mode === 'add' ? 'เพิ่ม Staff Record' : 'จัดการสิทธิ์ Staff & Roles');
                icon.style.background = '#eff6ff';
                icon.style.color = '#2563eb';
                if (govPosWrap) govPosWrap.style.display = 'block';
                if (govJobWrap) govJobWrap.style.display = 'block';
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
                        document.getElementById('govRegAccess').checked = parseInt(data.access_registry) === 1;
                        document.getElementById('govEdmsAccess').checked = parseInt(data.access_edms) === 1;
                        document.getElementById('govAiAccess').checked = parseInt(data.access_ai) === 1;
                        document.getElementById('govConsumablesAccess').checked = parseInt(data.access_consumables) === 1;
                        document.getElementById('govAssetAccess').checked = parseInt(data.access_asset) === 1;
                        document.getElementById('govFinanceAccess').checked = parseInt(data.access_finance) === 1;
                        document.getElementById('govScholarshipAccess').checked = parseInt(data.access_scholarship) === 1;
                        document.getElementById('govDashboardAccess').checked = parseInt(data.access_dashboard_admin) === 1;
                        const mrEl = document.getElementById('govMonthlyReportAccess');
                        if (mrEl) mrEl.checked = parseInt(data.access_monthly_report) === 1;
                        const npEl = document.getElementById('govNurseProductivityAccess');
                        if (npEl) npEl.checked = parseInt(data.access_nurse_productivity) === 1;
                        const dsEl = document.getElementById('govDailySummaryAccess');
                        if (dsEl) dsEl.checked = parseInt(data.access_daily_summary) === 1;
                        const dvEl = document.getElementById('govDirectorViewAccess');
                        if (dvEl) dvEl.checked = parseInt(data.access_director_view) === 1;
                        const idEl = document.getElementById('govIdentityAccess');
                        if (idEl) idEl.checked = parseInt(data.access_identity) === 1;
                        const deptSel = document.getElementById('govDepartmentId');
                        if (deptSel) deptSel.value = data.department_id ? String(data.department_id) : '';

                        // Position (Hybrid live link)
                        const posSel = document.getElementById('govPositionId');
                        if (posSel) {
                            posSel.value = data.position_id ? String(data.position_id) : '';
                            onGovPositionChange();
                        }

                        // Job title (free text) + Org chart position (read-only info)
                        const jt = document.getElementById('govJobTitle');
                        if (jt) jt.value = data.job_title || '';
                        const orgInfo = document.getElementById('govOrgPositionInfo');
                        const orgT = document.getElementById('govOrgPositionTitle');
                        if (orgInfo && orgT) {
                            if (data.org_position_title) {
                                orgT.textContent = data.org_position_title;
                                orgInfo.style.display = '';
                            } else {
                                orgInfo.style.display = 'none';
                            }
                        }
                    }
                } else {
                    // Reset Extension Checkboxes for new records
                    document.getElementById('govInsAccess').checked = false;
                    document.getElementById('govLogsAccess').checked = false;
                    document.getElementById('govSettAccess').checked = false;
                    document.getElementById('govRegAccess').checked = false;
                    document.getElementById('govEdmsAccess').checked = false;
                    document.getElementById('govAiAccess').checked = false;
                    document.getElementById('govConsumablesAccess').checked = false;
                    document.getElementById('govAssetAccess').checked = false;
                    document.getElementById('govFinanceAccess').checked = false;
                    document.getElementById('govScholarshipAccess').checked = false;
                    document.getElementById('govDashboardAccess').checked = false;
                    const mrElR = document.getElementById('govMonthlyReportAccess');
                    if (mrElR) mrElR.checked = false;
                    const npElR = document.getElementById('govNurseProductivityAccess');
                    if (npElR) npElR.checked = false;
                    const dsElR = document.getElementById('govDailySummaryAccess');
                    if (dsElR) dsElR.checked = false;
                    const dvElR = document.getElementById('govDirectorViewAccess');
                    if (dvElR) dvElR.checked = false;
                    const idElR = document.getElementById('govIdentityAccess');
                    if (idElR) idElR.checked = false;
                    const deptSelR = document.getElementById('govDepartmentId');
                    if (deptSelR) deptSelR.value = '';
                    const posSel = document.getElementById('govPositionId');
                    if (posSel) { posSel.value = ''; onGovPositionChange(); }
                    const jtR = document.getElementById('govJobTitle');
                    if (jtR) jtR.value = '';
                    const orgInfoR = document.getElementById('govOrgPositionInfo');
                    if (orgInfoR) orgInfoR.style.display = 'none';
                }
            // Update UI States
            syncGovUI('govEbAccess', 'govEbRole', 'govEbCard');
            syncGovUI('govEcAccess', 'govEcRole', 'govEcCard');

            m.style.display = 'flex';
        }

        /**
         * Position Modal — สร้าง / แก้ไข / ลบ ตำแหน่งงาน
         */
        const POS_FLAG_KEYS = [
            'access_eborrow','access_ecampaign','access_insurance','access_registry',
            'access_system_logs','access_site_settings','access_edms',
            'access_ai','access_consumables','access_asset','access_finance','access_scholarship',
            'access_dashboard_admin','access_monthly_report','access_nurse_productivity','access_daily_summary','access_director_view',
            'access_identity'
        ];

        function openAddPositionModal() {
            const modal = document.getElementById('idPosModal');
            if (!modal) return;
            document.getElementById('posModalTitle').textContent = 'สร้างตำแหน่งใหม่';
            document.getElementById('posAction').value = 'add_position';
            document.getElementById('posId').value = '';
            document.getElementById('posName').value = '';
            document.getElementById('posDescription').value = '';
            POS_FLAG_KEYS.forEach(k => {
                const cb = document.getElementById('posFlag_' + k);
                if (cb) cb.checked = false;
            });
            modal.style.display = 'flex';
        }

        function openEditPositionModal(pos) {
            const modal = document.getElementById('idPosModal');
            if (!modal) return;
            document.getElementById('posModalTitle').textContent = 'แก้ไขตำแหน่ง: ' + (pos.name || '');
            document.getElementById('posAction').value = 'edit_position';
            document.getElementById('posId').value = pos.id || '';
            document.getElementById('posName').value = pos.name || '';
            document.getElementById('posDescription').value = pos.description || '';
            let flags = {};
            try { flags = JSON.parse(pos.flags || '{}') || {}; } catch (e) { flags = {}; }
            POS_FLAG_KEYS.forEach(k => {
                const cb = document.getElementById('posFlag_' + k);
                if (cb) cb.checked = parseInt(flags[k]) === 1;
            });
            modal.style.display = 'flex';
        }

        function confirmDeletePosition(formEl, name, staffCount) {
            const msg = staffCount > 0
                ? `ต้องการลบตำแหน่ง "${name}"?\nstaff ${staffCount} คนที่ผูกอยู่จะถูกเปลี่ยนเป็น Custom (ติ๊ก flag เอง) อัตโนมัติ`
                : `ต้องการลบตำแหน่ง "${name}"?`;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'ยืนยันการลบตำแหน่ง',
                    text: msg,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'ลบเลย',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#94a3b8',
                    reverseButtons: true
                }).then((r) => { if (r.isConfirmed) formEl.submit(); });
                return false;
            }
            return confirm(msg);
        }

        /**
         * Department CRUD — ผ่าน ajax_monthly_report.php (entity=department)
         * ใช้ SweetAlert2 form แทน modal แยก (form สั้นพอ)
         */
        async function deptAjax(action, payload) {
            const fd = new FormData();
            fd.append('entity', 'department');
            fd.append('action', action);
            fd.append('csrf_token', portal_CSRF);
            for (const [k, v] of Object.entries(payload)) fd.append(k, v);
            const r = await fetch('ajax_monthly_report.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            return r.json();
        }

        function deptFormHtml(dept) {
            const d = dept || {};
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            return `
                <div style="text-align:left;display:flex;flex-direction:column;gap:12px">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">ชื่อฝ่าย <span style="color:#ef4444">*</span></label>
                        <input id="swDeptName" type="text" value="${esc(d.name || '')}" class="swal2-input" style="margin:0;width:100%" placeholder="เช่น หน่วยบริการสุขภาพ">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">คำอธิบาย (optional)</label>
                        <textarea id="swDeptDesc" class="swal2-textarea" style="margin:0;width:100%;min-height:60px" placeholder="หน้าที่หลักของฝ่ายนี้">${esc(d.description || '')}</textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">ลำดับการแสดง</label>
                            <input id="swDeptSort" type="number" value="${parseInt(d.sort_order ?? 0) || 0}" class="swal2-input" style="margin:0;width:100%">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">สถานะ</label>
                            <select id="swDeptActive" class="swal2-select" style="margin:0;width:100%">
                                <option value="1" ${(d.active ?? 1) == 1 ? 'selected' : ''}>เปิดใช้งาน</option>
                                <option value="0" ${(d.active ?? 1) == 0 ? 'selected' : ''}>ปิด</option>
                            </select>
                        </div>
                    </div>
                </div>`;
        }

        async function openAddDeptModal() {
            const result = await Swal.fire({
                title: '<i class="fa-solid fa-plus" style="color:#6366f1"></i> เพิ่มฝ่ายใหม่',
                html: deptFormHtml(null),
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#6366f1',
                focusConfirm: false,
                preConfirm: () => {
                    const name = document.getElementById('swDeptName').value.trim();
                    if (!name) { Swal.showValidationMessage('กรุณาระบุชื่อฝ่าย'); return false; }
                    return {
                        name,
                        description: document.getElementById('swDeptDesc').value.trim(),
                        sort_order:  document.getElementById('swDeptSort').value || 0,
                        active:      document.getElementById('swDeptActive').value,
                    };
                }
            });
            if (!result.isConfirmed) return;
            const res = await deptAjax('save', result.value);
            if (res.status === 'ok') {
                await Swal.fire({ icon:'success', title:'เพิ่มเรียบร้อย', timer:1100, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: res.message || '' });
            }
        }

        async function openEditDeptModal(dept) {
            const result = await Swal.fire({
                title: '<i class="fa-solid fa-pen-to-square" style="color:#6366f1"></i> แก้ไขฝ่าย',
                html: deptFormHtml(dept),
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#6366f1',
                focusConfirm: false,
                preConfirm: () => {
                    const name = document.getElementById('swDeptName').value.trim();
                    if (!name) { Swal.showValidationMessage('กรุณาระบุชื่อฝ่าย'); return false; }
                    return {
                        id: dept.id,
                        name,
                        description: document.getElementById('swDeptDesc').value.trim(),
                        sort_order:  document.getElementById('swDeptSort').value || 0,
                        active:      document.getElementById('swDeptActive').value,
                    };
                }
            });
            if (!result.isConfirmed) return;
            const res = await deptAjax('save', result.value);
            if (res.status === 'ok') {
                await Swal.fire({ icon:'success', title:'บันทึกเรียบร้อย', timer:1100, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: res.message || '' });
            }
        }

        async function deleteDept(id, name, staffCount, reportCount) {
            if (reportCount > 0) {
                return Swal.fire({
                    icon:'error', title:'ลบไม่ได้',
                    html: `ฝ่าย "<b>${name}</b>" มีรายงาน ${reportCount} ฉบับในระบบ<br><span style="font-size:12px;color:#64748b">ต้องลบรายงานก่อน หรือเปลี่ยนสถานะเป็น "ปิด" แทน</span>`,
                });
            }
            const warn = staffCount > 0
                ? `ฝ่าย "${name}" มี staff ${staffCount} คนผูกอยู่<br>หลังลบ — ค่า department_id ของ staff ทั้งหมดจะกลายเป็น NULL`
                : `ลบฝ่าย "${name}"?`;
            const { isConfirmed } = await Swal.fire({
                icon:'warning', title:'ยืนยันการลบฝ่าย', html: warn,
                showCancelButton:true, confirmButtonText:'ลบเลย', cancelButtonText:'ยกเลิก',
                confirmButtonColor:'#ef4444', reverseButtons:true,
            });
            if (!isConfirmed) return;
            const res = await deptAjax('delete', { id });
            if (res.status === 'ok') {
                await Swal.fire({ icon:'success', title:'ลบเรียบร้อย', timer:1100, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'ลบไม่สำเร็จ', text: res.message || '' });
            }
        }

        /**
         * Position change handler — Hybrid (Live Link)
         *   - มี position → load flag จาก position แล้ว disable checkboxes
         *   - Custom (NULL) → enable checkboxes ให้ติ๊กเอง
         */
        const GOV_FLAG_MAP = [
            ['access_eborrow',       'govEbAccess'],
            ['access_ecampaign',     'govEcAccess'],
            ['access_insurance',     'govInsAccess'],
            ['access_registry',      'govRegAccess'],
            ['access_system_logs',   'govLogsAccess'],
            ['access_site_settings', 'govSettAccess'],
            ['access_edms',          'govEdmsAccess'],
            ['access_ai',            'govAiAccess'],
            ['access_consumables',   'govConsumablesAccess'],
            ['access_asset',         'govAssetAccess'],
            ['access_finance',       'govFinanceAccess'],
            ['access_scholarship',   'govScholarshipAccess'],
            ['access_dashboard_admin','govDashboardAccess'],
            ['access_monthly_report','govMonthlyReportAccess'],
            ['access_nurse_productivity','govNurseProductivityAccess'],
            ['access_daily_summary',     'govDailySummaryAccess'],
            ['access_director_view', 'govDirectorViewAccess'],
            ['access_identity',      'govIdentityAccess'],
        ];

        function onGovPositionChange() {
            const sel = document.getElementById('govPositionId');
            const note = document.getElementById('govPositionLockNote');
            if (!sel) return;

            const opt = sel.options[sel.selectedIndex];
            const flagsRaw = opt ? opt.getAttribute('data-flags') : null;
            const isCustom = !sel.value || !flagsRaw;

            if (isCustom) {
                if (note) note.style.display = 'none';
                GOV_FLAG_MAP.forEach(([key, id]) => {
                    const cb = document.getElementById(id);
                    if (!cb) return;
                    cb.disabled = false;
                    const card = cb.closest('.premium-role-card');
                    if (card) card.style.filter = 'none';
                });
            } else {
                let posFlags = {};
                try { posFlags = JSON.parse(flagsRaw) || {}; } catch (e) { posFlags = {}; }
                if (note) note.style.display = 'block';
                GOV_FLAG_MAP.forEach(([key, id]) => {
                    const cb = document.getElementById(id);
                    if (!cb) return;
                    cb.checked = parseInt(posFlags[key]) === 1;
                    cb.disabled = true;
                    const card = cb.closest('.premium-role-card');
                    if (card) card.style.filter = 'grayscale(0.4) opacity(0.85)';
                });
            }
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
                                    <a href="../admin/user_history.php?id=${u.id}&redirect_back=${encodeURIComponent('../portal/index.php?section=identity')}"
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
        // PHP already rendered the correct section server-side, so on initial
        // load we just need to highlight the sidebar button — NOT call
        // switchSection (which strips cd_view/s/p and would break sub-view
        // pagination on refresh).
        (function () {
            var params = new URLSearchParams(window.location.search);
            var sec = params.get('section');
            var tab = params.get('tab');
            if (sec) {
                var btn = document.querySelector('.psb-item[data-section="' + sec + '"]');
                if (btn) {
                    document.querySelectorAll('.psb-item').forEach(function (b) {
                        b.classList.remove('psb-active');
                        b.removeAttribute('aria-current');
                    });
                    btn.classList.add('psb-active');
                    btn.setAttribute('aria-current', 'page');
                }
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
        const HAS_ACCESS_FINANCE = <?= json_encode($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance'])) ?>;

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
            document.getElementById('ann-f-title-en').value       = data ? (data.title_en || '') : '';
            document.getElementById('ann-f-content').value        = data ? (data.content  || '') : '';
            document.getElementById('ann-f-content-en').value      = data ? (data.content_en|| '') : '';
            document.getElementById('ann-f-type').value           = data ? (data.type || 'info') : 'info';
            document.getElementById('ann-f-audience').value       = data ? (data.target_audience || 'all') : 'all';
            document.getElementById('ann-f-start').value          = data ? (data.start_date || '') : '';
            document.getElementById('ann-f-end').value            = data ? (data.end_date   || '') : '';
            document.getElementById('ann-f-priority').value       = data ? (data.priority || 0) : 0;
            document.getElementById('ann-f-active').checked       = data ? (parseInt(data.is_active) === 1) : true;
            document.getElementById('ann-f-show-once').checked    = data ? (parseInt(data.show_once) === 1) : true;

            // ── Image preview / state ───────────────────────────────────
            const existingUrl = data ? (data.image_url || '') : '';
            document.getElementById('ann-f-image-existing').value = existingUrl;
            document.getElementById('ann-f-image-clear').value    = '';
            document.getElementById('ann-f-image-file').value     = '';
            const wrap = document.getElementById('ann-image-preview-wrap');
            const img  = document.getElementById('ann-image-preview');
            const name = document.getElementById('ann-image-preview-name');
            if (existingUrl) {
                img.src = existingUrl;
                name.textContent = existingUrl.split('/').pop();
                wrap.style.display = 'block';
            } else {
                img.src = '';
                name.textContent = '';
                wrap.style.display = 'none';
            }
            modal.style.display = 'flex';
        };

        // เคลียร์รูป (ทั้งของเดิมและที่เพิ่งเลือก) — ติด flag ให้ฝั่ง server รู้ว่าต้อง NULL
        window.annClearImage = function() {
            document.getElementById('ann-f-image-file').value     = '';
            document.getElementById('ann-f-image-existing').value = '';
            document.getElementById('ann-f-image-clear').value    = '1';
            const wrap = document.getElementById('ann-image-preview-wrap');
            document.getElementById('ann-image-preview').src = '';
            document.getElementById('ann-image-preview-name').textContent = '';
            wrap.style.display = 'none';
        };

        // เมื่อเลือกไฟล์ใหม่ → แสดง preview + ตรวจขนาด
        document.getElementById('ann-f-image-file')?.addEventListener('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            const maxBytes = 5 * 1024 * 1024;
            if (file.size > maxBytes) {
                Swal.fire({ icon: 'warning', title: 'ไฟล์ใหญ่เกินไป', text: 'รองรับสูงสุด 5 MB' });
                e.target.value = '';
                return;
            }
            const allowed = ['image/jpeg','image/png','image/webp','image/gif'];
            if (!allowed.includes(file.type)) {
                Swal.fire({ icon: 'warning', title: 'ชนิดไฟล์ไม่รองรับ', text: 'รองรับเฉพาะ JPG / PNG / WebP / GIF' });
                e.target.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(ev) {
                document.getElementById('ann-image-preview').src = ev.target.result;
                document.getElementById('ann-image-preview-name').textContent = file.name;
                document.getElementById('ann-image-preview-wrap').style.display = 'block';
                // เลือกไฟล์ใหม่ = ไม่ต้อง clear (server จะใช้ไฟล์ใหม่แทน existing เอง)
                document.getElementById('ann-f-image-clear').value = '';
            };
            reader.readAsDataURL(file);
        });

        // Drag & drop
        (function() {
            const dz = document.getElementById('ann-image-drop');
            if (!dz) return;
            ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => {
                e.preventDefault(); e.stopPropagation();
                dz.style.borderColor = '#7c3aed';
                dz.style.background = '#f5f3ff';
            }));
            ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => {
                e.preventDefault(); e.stopPropagation();
                dz.style.borderColor = '#cbd5e1';
                dz.style.background = '#f8fafc';
            }));
            dz.addEventListener('drop', e => {
                const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (!file) return;
                const input = document.getElementById('ann-f-image-file');
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                input.dispatchEvent(new Event('change'));
            });
        })();

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

    <!-- ════════════════════════════════════════════════════════════
         COMMAND PALETTE (⌘K) — added by /overdrive
         ════════════════════════════════════════════════════════════ -->
    <div id="cmdk-overlay" class="cmdk-overlay" role="dialog" aria-modal="true" aria-labelledby="cmdk-title" hidden>
        <div class="cmdk-panel" role="document">
            <div class="cmdk-search-wrap">
                <i class="fa-solid fa-magnifying-glass cmdk-search-icon" aria-hidden="true"></i>
                <input type="text" id="cmdk-input" class="cmdk-input"
                       placeholder="พิมพ์เพื่อค้นหาคำสั่ง / ระบบ / หน้า…"
                       aria-label="ค้นหาคำสั่ง"
                       autocomplete="off" spellcheck="false">
                <kbd class="cmdk-esc" aria-hidden="true">ESC</kbd>
            </div>
            <ul id="cmdk-list" class="cmdk-list" role="listbox" aria-label="ผลการค้นหา"></ul>
            <div class="cmdk-foot">
                <span><kbd>↑</kbd><kbd>↓</kbd> เลื่อน</span>
                <span><kbd>↵</kbd> เลือก</span>
                <span><kbd>ESC</kbd> ปิด</span>
                <span class="ml-auto cmdk-help-hint">กด <kbd>?</kbd> ดูคีย์ลัด</span>
            </div>
        </div>
    </div>

    <!-- Keyboard shortcuts help modal -->
    <div id="kbd-help-overlay" class="cmdk-overlay" role="dialog" aria-modal="true" aria-labelledby="kbd-help-title" hidden>
        <div class="cmdk-panel cmdk-panel--small">
            <div class="cmdk-help-head">
                <h2 id="kbd-help-title" class="font-bold text-slate-800 text-base">คีย์ลัด</h2>
                <button class="cmdk-close" onclick="kbdHelpClose()" aria-label="ปิด">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <dl class="kbd-help-list">
                <div><kbd>⌘</kbd>+<kbd>K</kbd> <span>เปิด Command Palette</span></div>
                <div><kbd>g</kbd> <kbd>d</kbd> <span>ไปหน้า Dashboard</span></div>
                <div><kbd>g</kbd> <kbd>i</kbd> <span>ไป Identity & Governance</span></div>
                <div><kbd>g</kbd> <kbd>a</kbd> <span>ไปประกาศ</span></div>
                <div><kbd>g</kbd> <kbd>e</kbd> <span>ไป Error Logs</span></div>
                <div><kbd>g</kbd> <kbd>s</kbd> <span>ไป Settings</span></div>
                <div><kbd>g</kbd> <kbd>r</kbd> <span>ไปครุภัณฑ์สำนักงาน</span></div>
                <div><kbd>/</kbd> <span>โฟกัสช่องค้นหา</span></div>
                <div><kbd>?</kbd> <span>เปิดคีย์ลัด (หน้านี้)</span></div>
                <div><kbd>ESC</kbd> <span>ปิด modal / palette</span></div>
            </dl>
        </div>
    </div>

    <script>
    (function () {
        // ── Command catalog ──────────────────────────────────────────────
        // type: 'section' = call switchSection, 'url' = navigate
        const ALL_COMMANDS = [
            { id: 'dashboard',     label: 'Dashboard',           desc: 'ภาพรวม + งานวันนี้', shortcut: 'g d', icon: 'fa-chart-pie',          tone: 'success', type: 'section', target: 'dashboard' },
            { id: 'ai_assistant',  label: 'AI Assistant',        desc: 'ผู้ช่วย AI',         icon: 'fa-wand-magic-sparkles', tone: 'accent', type: 'section', target: 'ai_assistant' },
            { id: 'ai_qa_lab',     label: 'AI QA Lab',           desc: 'Sandbox คำถามจาก user', icon: 'fa-flask-vial',      tone: 'accent', type: 'section', target: 'ai_qa_lab' },
            { id: 'identity',      label: 'Identity & Governance', desc: 'จัดการสิทธิ์ผู้ใช้', shortcut: 'g i', icon: 'fa-id-card-clip',  tone: 'info',    type: 'section', target: 'identity' },
            { id: 'insurance_sync', label: 'Insurance Hub',      desc: 'ระบบสิทธิ์ประกัน',   icon: 'fa-shield-halved',      tone: 'info',    type: 'section', target: 'insurance_sync' },
            { id: 'insurance_dashboard', label: 'Dashboard Workbook', desc: 'ภาพรวม + แก้ widgets · Multi-workbook', icon: 'fa-chart-pie',     tone: 'info',    type: 'section', target: 'insurance_dashboard' },
            { id: 'gold_card_pending', label: 'ย้ายสิทธิ์บัตรทอง', desc: 'คิวคำขอย้ายสิทธิ์บัตรทองจาก user', icon: 'fa-hourglass-half', tone: 'info',    type: 'section', target: 'gold_card_pending' },
            { id: 'gold_card',     label: 'บัตรทอง',             desc: 'จัดการบัตรทอง + เอกสาร', icon: 'fa-id-card',         tone: 'warning', type: 'section', target: 'gold_card' },
            { id: 'registry_upload', label: 'อัพโหลดรายชื่อ',    desc: 'ทะเบียน',            icon: 'fa-id-card-clip',      tone: 'info',    type: 'section', target: 'registry_upload' },
            { id: 'batch_status',  label: 'สถานะเอกสาร',         desc: 'Insurance Batch',    icon: 'fa-list-check',         tone: 'info',    type: 'section', target: 'batch_status' },
<?php if ($isSuper): ?>
            { id: 'manage_insurance_partners', label: 'Insurance Partners', desc: 'จัดการพาร์ทเนอร์', icon: 'fa-handshake', tone: 'success', type: 'section', target: 'manage_insurance_partners' },
<?php endif; ?>
            { id: 'announcements', label: 'ประกาศ',              desc: 'จัดการประกาศ Hub',  shortcut: 'g a', icon: 'fa-bullhorn',           tone: 'accent',  type: 'section', target: 'announcements' },
            { id: 'activity_logs', label: 'Activity Logs',       desc: 'บันทึกกิจกรรมระบบ',  icon: 'fa-file-lines',         tone: 'neutral', type: 'section', target: 'activity_logs' },
            { id: 'error_logs',    label: 'Error Logs',          desc: 'บันทึกข้อผิดพลาด',  shortcut: 'g e', icon: 'fa-bug',                tone: 'danger',  type: 'section', target: 'error_logs' },
            { id: 'privilege_inventory', label: 'ISO Governance', desc: 'Privileged Access', icon: 'fa-shield-halved',      tone: 'success', type: 'section', target: 'privilege_inventory' },
            { id: 'settings',      label: 'Settings',            desc: 'ตั้งค่าระบบ',        shortcut: 'g s', icon: 'fa-gear',               tone: 'warning', type: 'section', target: 'settings' },

            { id: 'open_asset',    label: 'ครุภัณฑ์สำนักงาน',   desc: 'ทะเบียนทรัพย์สิน',  shortcut: 'g r', icon: 'fa-boxes-stacked',     tone: 'success', type: 'url',     target: '../asset/index.php' },
            { id: 'open_campaign', label: 'Campaign Manager',    desc: 'จัดการแคมเปญ',      icon: 'fa-bullhorn',           tone: 'info',    type: 'url',     target: '../admin/campaigns.php' },
            { id: 'open_eborrow',  label: 'e-Borrow & Inventory', desc: 'ระบบยืม-คืนอุปกรณ์', icon: 'fa-toolbox',         tone: 'neutral', type: 'url',     target: '../e_Borrow/admin/index.php' },
            { id: 'open_users',    label: 'Users Center',        desc: 'รายชื่อผู้ใช้',     icon: 'fa-users',              tone: 'info',    type: 'url',     target: 'users.php' },
            { id: 'open_support',  label: 'Live Support Chat',   desc: 'แชทตอบกลับผู้ใช้',  icon: 'fa-comments',           tone: 'info',    type: 'url',     target: 'support_chat.php' },
        ];

        // Filter to commands that exist for this user
        // (sidebar links only render for sections the user can access)
        const accessibleSections = new Set(
            Array.from(document.querySelectorAll('[data-section]')).map(el => el.dataset.section)
        );
        const COMMANDS = ALL_COMMANDS.filter(c =>
            c.type === 'url' || accessibleSections.has(c.target)
        );

        // ── State ────────────────────────────────────────────────────────
        const overlay = document.getElementById('cmdk-overlay');
        const input   = document.getElementById('cmdk-input');
        const list    = document.getElementById('cmdk-list');
        const helpOverlay = document.getElementById('kbd-help-overlay');
        let activeIdx = 0;
        let filtered  = COMMANDS;
        let leaderKey = null;       // pending 'g'
        let leaderTimer = null;

        // ── Filtering ────────────────────────────────────────────────────
        function fuzzyMatch(query, text) {
            query = query.toLowerCase().trim();
            text  = text.toLowerCase();
            if (!query) return true;
            // Substring or all-chars-in-order
            if (text.includes(query)) return true;
            let qi = 0;
            for (let i = 0; i < text.length && qi < query.length; i++) {
                if (text[i] === query[qi]) qi++;
            }
            return qi === query.length;
        }

        function filter(query) {
            filtered = COMMANDS.filter(c =>
                fuzzyMatch(query, c.label + ' ' + (c.desc || '') + ' ' + (c.shortcut || ''))
            );
            activeIdx = 0;
            render();
        }

        // ── Render ───────────────────────────────────────────────────────
        function render() {
            if (!filtered.length) {
                list.innerHTML = '<li class="cmdk-empty">ไม่พบคำสั่งที่ตรง</li>';
                return;
            }
            list.innerHTML = filtered.map((c, i) => `
                <li class="cmdk-item cmdk-item--${c.tone || 'neutral'} ${i === activeIdx ? 'is-active' : ''}"
                    role="option" aria-selected="${i === activeIdx}" data-idx="${i}">
                    <div class="cmdk-item-icon"><i class="fa-solid ${c.icon}"></i></div>
                    <div class="cmdk-item-body">
                        <div class="cmdk-item-label">${c.label}</div>
                        ${c.desc ? `<div class="cmdk-item-desc">${c.desc}</div>` : ''}
                    </div>
                    ${c.shortcut ? `<kbd class="cmdk-item-kbd">${c.shortcut}</kbd>` : ''}
                </li>
            `).join('');
        }

        // ── Open / Close ────────────────────────────────────────────────
        function open() {
            overlay.hidden = false;
            requestAnimationFrame(() => overlay.classList.add('is-open'));
            input.value = '';
            filter('');
            input.focus();
        }
        function close() {
            overlay.classList.remove('is-open');
            setTimeout(() => { overlay.hidden = true; }, 180);
        }
        window.cmdkOpen = open;

        // Help modal
        function helpOpen() {
            helpOverlay.hidden = false;
            requestAnimationFrame(() => helpOverlay.classList.add('is-open'));
        }
        window.kbdHelpClose = function () {
            helpOverlay.classList.remove('is-open');
            setTimeout(() => { helpOverlay.hidden = true; }, 180);
        };

        // ── Execute ──────────────────────────────────────────────────────
        function execute(cmd) {
            close();
            if (!cmd) return;
            if (cmd.type === 'section') {
                if (typeof switchSection === 'function') {
                    const btn = document.querySelector(`[data-section="${cmd.target}"]`);
                    switchSection(cmd.target, btn);
                }
            } else if (cmd.type === 'url') {
                window.location.href = cmd.target;
            }
        }

        // ── Events ───────────────────────────────────────────────────────
        input.addEventListener('input', e => filter(e.target.value));
        input.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % filtered.length; render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + filtered.length) % filtered.length; render(); }
            else if (e.key === 'Enter')  { e.preventDefault(); execute(filtered[activeIdx]); }
        });
        list.addEventListener('click', e => {
            const li = e.target.closest('.cmdk-item');
            if (li) execute(filtered[parseInt(li.dataset.idx, 10)]);
        });
        overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
        helpOverlay.addEventListener('click', e => { if (e.target === helpOverlay) window.kbdHelpClose(); });

        // ── Global keyboard ─────────────────────────────────────────────
        function isTypingTarget(el) {
            if (!el) return false;
            const tag = el.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
        }

        document.addEventListener('keydown', e => {
            // ⌘K / Ctrl+K — open palette
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (overlay.hidden) open(); else close();
                return;
            }
            // ESC — close any open modal
            if (e.key === 'Escape') {
                if (!overlay.hidden) { e.preventDefault(); close(); }
                else if (!helpOverlay.hidden) { e.preventDefault(); window.kbdHelpClose(); }
                return;
            }
            // Don't trigger leader / help while typing
            if (isTypingTarget(e.target)) return;

            // ? — open shortcut help (use shift+/ which produces "?")
            if (e.key === '?') { e.preventDefault(); helpOpen(); return; }

            // / — focus project search
            if (e.key === '/') {
                e.preventDefault();
                const proj = document.getElementById('search-project');
                if (proj) proj.focus();
                return;
            }

            // Sequence shortcut (g + letter)
            if (e.key === 'g' && !e.metaKey && !e.ctrlKey && !e.altKey) {
                leaderKey = 'g';
                clearTimeout(leaderTimer);
                leaderTimer = setTimeout(() => { leaderKey = null; }, 900);
                return;
            }
            if (leaderKey === 'g') {
                const map = {
                    d: 'dashboard',
                    i: 'identity',
                    a: 'announcements',
                    e: 'error_logs',
                    s: 'settings',
                };
                const sec = map[e.key];
                if (sec) {
                    e.preventDefault();
                    leaderKey = null;
                    if (typeof switchSection === 'function') {
                        const btn = document.querySelector(`[data-section="${sec}"]`);
                        if (btn) switchSection(sec, btn);
                    }
                    return;
                }
                if (e.key === 'r') {
                    e.preventDefault(); leaderKey = null;
                    window.location.href = '../asset/index.php'; return;
                }
                leaderKey = null;
            }
        });
    })();
    </script>

<!-- ════════════════════ App Switcher (Phase 1) ════════════════════ -->
<style>
    #app-switcher-backdrop {
        position: fixed; inset: 0; z-index: 9000;
        background: rgba(15,23,42,.55); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        opacity: 0; pointer-events: none; transition: opacity .25s;
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
    }
    #app-switcher-backdrop.show { opacity: 1; pointer-events: auto; }
    #app-switcher-modal {
        background: #fff; border-radius: 24px;
        width: 100%; max-width: 900px;
        max-height: 90vh; overflow-y: auto;
        box-shadow: 0 25px 60px -10px rgba(0,0,0,.35);
        transform: scale(.95); transition: transform .25s cubic-bezier(.34,1.56,.64,1);
        padding: 24px;
    }
    #app-switcher-backdrop.show #app-switcher-modal { transform: scale(1); }
    .aps-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1.5px solid #f1f5f9; }
    .aps-head h2 { margin: 0; font-size: 18px; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 10px; }
    .aps-head .aps-close { width: 36px; height: 36px; border-radius: 10px; border: none; background: #f1f5f9; color: #475569; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: background .15s; }
    .aps-head .aps-close:hover { background: #e2e8f0; color: #0f172a; }
    .aps-section-label { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .12em; color: #94a3b8; margin: 18px 0 10px; }
    .aps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
    .aps-card {
        display: flex; flex-direction: column; gap: 8px;
        padding: 16px; border-radius: 16px; cursor: pointer;
        background: #f8fafc; border: 1.5px solid #e2e8f0;
        text-decoration: none; color: #0f172a;
        transition: transform .15s, box-shadow .15s, border-color .15s, background .15s;
    }
    .aps-card:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(0,0,0,.08); }
    .aps-card.current { border-color: #10b981; background: #ecfdf5; box-shadow: inset 0 0 0 1px #10b981; }
    .aps-card.current::after { content: 'อยู่ที่นี่'; position: absolute; }
    .aps-card-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .aps-card-title { font-size: 14px; font-weight: 900; line-height: 1.2; }
    .aps-card-desc { font-size: 11px; color: #64748b; font-weight: 500; line-height: 1.4; }
    .aps-footer { margin-top: 20px; padding-top: 14px; border-top: 1.5px dashed #e2e8f0; font-size: 11px; color: #94a3b8; text-align: center; }
    .aps-footer kbd { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; padding: 1px 6px; font-family: monospace; font-size: 10px; }

    @media (prefers-reduced-motion: reduce) {
        #app-switcher-backdrop, #app-switcher-modal { transition: none !important; }
    }
</style>

<div id="app-switcher-backdrop" onclick="if(event.target===this)closeAppSwitcher()">
    <div id="app-switcher-modal" role="dialog" aria-modal="true">
        <div class="aps-head">
            <h2><i class="fa-solid fa-grip" style="color:#2e9e63"></i>เลือกระบบที่ต้องการใช้งาน</h2>
            <button class="aps-close" onclick="closeAppSwitcher()" aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="aps-section-label">โมดูลใน Portal</div>
        <div class="aps-grid">
            <a class="aps-card" data-app="overview"  href="index.php?section=dashboard">
                <div class="aps-card-icon" style="background:#ecfdf5;color:#059669"><i class="fa-solid fa-chart-line"></i></div>
                <div class="aps-card-title">ภาพรวม</div>
                <div class="aps-card-desc">Dashboard · โปรไฟล์ของฉัน</div>
            </a>
            <a class="aps-card" data-app="ai"        href="index.php?section=ai_assistant">
                <div class="aps-card-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <div class="aps-card-title">AI Suite</div>
                <div class="aps-card-desc">AI Assistant · QA Lab · Prompts</div>
            </a>
            <a class="aps-card" data-app="security"  href="index.php?section=identity">
                <div class="aps-card-icon" style="background:#eef2ff;color:#4f46e5"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="aps-card-title">สิทธิ์ &amp; ความปลอดภัย</div>
                <div class="aps-card-desc">Identity Governance · ISO</div>
            </a>
            <a class="aps-card" data-app="insurance" href="index.php?section=insurance_hub">
                <div class="aps-card-icon" style="background:#fff1f2;color:#e11d48"><i class="fa-solid fa-hospital-user"></i></div>
                <div class="aps-card-title">ประกันสุขภาพ</div>
                <div class="aps-card-desc">Insurance Hub · บัตรทอง · Partners</div>
            </a>
            <a class="aps-card" data-app="comm"      href="index.php?section=announcements">
                <div class="aps-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="aps-card-title">สื่อสาร</div>
                <div class="aps-card-desc">ประกาศ · EDMS</div>
            </a>
            <a class="aps-card" data-app="monitor"   href="index.php?section=activity_logs">
                <div class="aps-card-icon" style="background:#f1f5f9;color:#475569"><i class="fa-solid fa-binoculars"></i></div>
                <div class="aps-card-title">ติดตามระบบ</div>
                <div class="aps-card-desc">Activity Logs · Error Logs</div>
            </a>
            <a class="aps-card" data-app="masterdata" href="index.php?section=clinic_data">
                <div class="aps-card-icon" style="background:#ecfeff;color:#0891b2"><i class="fa-solid fa-database"></i></div>
                <div class="aps-card-title">ข้อมูลหลัก</div>
                <div class="aps-card-desc">คลินิก · นักศึกษาทุน · Master</div>
            </a>
            <a class="aps-card" data-app="masterdata" href="index.php?section=nurse_schedule">
                <div class="aps-card-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-user-nurse"></i></div>
                <div class="aps-card-title">ตารางเวรพยาบาล</div>
                <div class="aps-card-desc">จัดเวร · ใบลา · OT · สรุป</div>
            </a>
            <a class="aps-card" data-app="settings"  href="index.php?section=settings">
                <div class="aps-card-icon" style="background:#f9fafb;color:#374151"><i class="fa-solid fa-gear"></i></div>
                <div class="aps-card-title">ตั้งค่า</div>
                <div class="aps-card-desc">Settings</div>
            </a>
        </div>

        <div class="aps-section-label">โมดูลภายนอก (เปิดในแท็บใหม่)</div>
        <div class="aps-grid">
            <a class="aps-card" href="../admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#dcfce7;color:#15803d"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="aps-card-title">e-Campaign</div>
                <div class="aps-card-desc">จองรอบบริการ · รายงานประจำวัน</div>
            </a>
            <a class="aps-card" href="../e_Borrow/admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#ffedd5;color:#c2410c"><i class="fa-solid fa-toolbox"></i></div>
                <div class="aps-card-title">e-Borrow</div>
                <div class="aps-card-desc">ยืม-คืนอุปกรณ์</div>
            </a>
            <a class="aps-card" href="../consumables/admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#fce7f3;color:#be185d"><i class="fa-solid fa-syringe"></i></div>
                <div class="aps-card-title">Consumables</div>
                <div class="aps-card-desc">เวชภัณฑ์สิ้นเปลือง</div>
            </a>
            <a class="aps-card" href="../asset/admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#fef3c7;color:#b45309"><i class="fa-solid fa-warehouse"></i></div>
                <div class="aps-card-title">Asset Inventory</div>
                <div class="aps-card-desc">ครุภัณฑ์ · ทะเบียนทรัพย์สิน</div>
            </a>
        </div>

        <div class="aps-footer">
            กด <kbd>ESC</kbd> เพื่อปิด · กด <kbd>⌘K</kbd> เพื่อค้นหาเร็ว
        </div>
    </div>
</div>

<script>
(function() {
    const APP_LABELS = {
        overview: 'ภาพรวม', ai: 'AI Suite', security: 'สิทธิ์ & ความปลอดภัย',
        insurance: 'ประกันสุขภาพ', comm: 'สื่อสาร', monitor: 'ติดตามระบบ',
        masterdata: 'ข้อมูลหลัก', settings: 'ตั้งค่า',
    };

    function currentAppKey() {
        // หา group ที่มี active item
        const active = document.querySelector('.psb-item.psb-active');
        if (!active) return null;
        const grp = active.closest('.psb-group');
        return grp ? grp.getAttribute('data-group') : null;
    }

    function markCurrentApp() {
        const key = currentAppKey();
        // เคลียร์ current ของ card ทุกใบก่อน (กรณีเปลี่ยน section)
        document.querySelectorAll('.aps-card.current').forEach(c => c.classList.remove('current'));
        if (key) {
            document.querySelectorAll('.aps-card[data-app="' + key + '"]').forEach(c => c.classList.add('current'));
        }
        updateBreadcrumb();
    }

    function updateBreadcrumb() {
        const active = document.querySelector('.psb-item.psb-active');
        const bcApp = document.getElementById('bc-app');
        const bcSection = document.getElementById('bc-section');
        const bcSep = document.getElementById('bc-sep');
        if (!bcApp || !bcSection) return;
        if (!active) {
            bcApp.textContent = '';
            bcSection.textContent = '';
            if (bcSep) bcSep.style.display = 'none';
            return;
        }
        const sectionLabel = (active.querySelector('.psb-label')?.textContent || active.textContent || '').trim();
        const grp = active.closest('.psb-group');
        const key = grp?.getAttribute('data-group');
        const appLabel = (key && APP_LABELS[key]) || '';
        bcApp.textContent = appLabel;
        bcSection.textContent = sectionLabel;
        if (bcSep) bcSep.style.display = appLabel ? '' : 'none';
        // อัปเดต document.title ด้วยให้สวยใน browser tab
        if (sectionLabel) document.title = sectionLabel + ' · Portal';
    }

    window.openAppSwitcher = function() {
        document.getElementById('app-switcher-backdrop').classList.add('show');
        document.body.style.overflow = 'hidden';
    };
    window.closeAppSwitcher = function() {
        document.getElementById('app-switcher-backdrop').classList.remove('show');
        document.body.style.overflow = '';
    };

    // ESC ปิด
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && document.getElementById('app-switcher-backdrop').classList.contains('show')) {
            closeAppSwitcher();
        }
    });

    // Phase 2: Sidebar contextualization — show current app only
    function applyCurrentAppOnly() {
        const key = currentAppKey();
        if (!key) return;
        document.querySelectorAll('.psb-group').forEach(grp => {
            const k = grp.getAttribute('data-group');
            if (!k) return;
            const btn = document.querySelector('.psb-section-toggle[data-group="' + k + '"]');
            if (k === key) {
                grp.classList.remove('collapsed');
                if (btn) btn.classList.remove('collapsed');
            } else {
                grp.classList.add('collapsed');
                if (btn) btn.classList.add('collapsed');
            }
        });
    }
    window.applyCurrentAppOnly = applyCurrentAppOnly;

    function applyAndMark() {
        markCurrentApp();
        if (localStorage.getItem('portal_current_app_only') !== '0') {
            applyCurrentAppOnly();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        applyAndMark();

        // เมื่อ user คลิก sidebar item (อาจข้าม group) — re-apply
        document.querySelectorAll('.psb-item').forEach(item => {
            item.addEventListener('click', () => setTimeout(applyAndMark, 0));
        });

        // Wrap switchSection ให้ breadcrumb อัปเดตเมื่อ nav จาก dashboard cards หรือที่อื่น
        if (typeof window.switchSection === 'function' && !window._switchWrapped) {
            const _orig = window.switchSection;
            window.switchSection = function(sectionId, btn) {
                const r = _orig.apply(this, arguments);
                setTimeout(applyAndMark, 0);
                return r;
            };
            window._switchWrapped = true;
        }
    });
})();
</script>

<!-- ════════════ Guided Tour (Driver.js) ════════════ -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script src="../assets/js/rsu-tour.js"></script>
<script>
(function () {
    const portalSteps = [
        { popover: { title: 'ยินดีต้อนรับสู่ Portal', description: 'ระบบจัดการคลินิก RSU Medical Clinic Services — ทัวร์สั้นๆ ดูเมนูหลักกัน' } },
        { element: '#portal-sidebar', popover: { title: 'Sidebar เมนู', description: 'เมนูจัดเป็นกลุ่ม (OVERVIEW / AI Suite / สิทธิ์ / ประกัน / สื่อสาร / คลังพัสดุ / ติดตามระบบ / ข้อมูลหลัก / ตั้งค่า) — คลิกหัวกลุ่มเพื่อเปิด/ปิด', side: 'right' } },
        { element: '#psb-apps-launcher', popover: { title: 'App Launcher (ใหม่!)', description: 'เมนูเปิดทุกระบบ (e-Borrow, ครุภัณฑ์, วัสดุ, Insurance Sync, ISO, LINE ฯลฯ) ย้ายมาอยู่ที่นี่แล้ว — Dashboard เลยโล่งขึ้น', side: 'right' } },
        { element: '.psb-section-toggle[data-group="inventory"]', popover: { title: 'คลังพัสดุ', description: 'รวมทางเข้า "ครุภัณฑ์สำนักงาน" + "วัสดุสิ้นเปลือง" ไว้กลุ่มเดียว', side: 'right' } },
        { element: '[data-section="settings"]', popover: { title: 'ตั้งค่าระบบ', description: 'ที่อยู่ของ Site Settings, Maintenance, LINE, AI ฯลฯ', side: 'right' } },
        { popover: { title: 'เริ่มใช้งานได้เลย', description: 'กดปุ่ม <i class="fa-solid fa-question"></i> มุมขวาล่างเมื่อต้องการดูทัวร์ซ้ำได้ตลอด' } },
    ];
    window.RsuTour && RsuTour.maybeAutoStart('portal_v2', portalSteps);
    window._portalTourSteps = portalSteps;

    // ── App Launcher migration banner: dismiss + mini-tour ─────────
    const APPS_MIGR_KEY  = 'apps_migration_dismissed_v1';
    const APPS_NEW_KEY   = 'apps_launcher_new_seen_v1';

    document.addEventListener('DOMContentLoaded', function () {
        const banner   = document.getElementById('apps-migration-banner');
        const newBadge = document.getElementById('psb-apps-new-badge');
        const dismissBtn = document.getElementById('apps-migration-dismiss');
        const tourBtn  = document.getElementById('apps-migration-tour-btn');
        const ctaBtn   = document.getElementById('apps-migration-cta');

        // Hide banner if user previously dismissed it
        try {
            if (banner && localStorage.getItem(APPS_MIGR_KEY) === '1') {
                banner.classList.add('is-dismissed');
            }
            if (newBadge && localStorage.getItem(APPS_NEW_KEY) === '1') {
                newBadge.classList.add('is-dismissed');
            }
        } catch (e) { /* silent */ }

        // Dismiss banner (does NOT hide the sidebar item or NEW badge)
        if (dismissBtn && banner) {
            dismissBtn.addEventListener('click', function () {
                banner.classList.add('is-dismissed');
                try { localStorage.setItem(APPS_MIGR_KEY, '1'); } catch (e) {}
            });
        }

        // Mark NEW badge as seen once user clicks the sidebar item or CTA
        function markSeen() {
            try { localStorage.setItem(APPS_NEW_KEY, '1'); } catch (e) {}
            if (newBadge) newBadge.classList.add('is-dismissed');
        }
        const sidebarApps = document.getElementById('psb-apps-launcher');
        if (sidebarApps) sidebarApps.addEventListener('click', markSeen);
        if (ctaBtn)      ctaBtn.addEventListener('click', markSeen);

        // "ดูตำแหน่งใหม่" — mini-tour that highlights the new sidebar location
        if (tourBtn && window.RsuTour) {
            const miniSteps = [
                { element: '#psb-apps-launcher', popover: {
                    title: 'นี่คือทางเข้าใหม่ของ App Launcher',
                    description: 'อยู่ใน sidebar กลุ่ม OVERVIEW · คลิกปุ่มนี้เมื่อใดก็ได้เพื่อเปิดหน้ารวมระบบทั้งหมด',
                    side: 'right'
                }},
                { element: '#apps-migration-cta', popover: {
                    title: 'หรือกดที่นี่ตอนนี้เลย',
                    description: 'ไปยังหน้า App Launcher ทันที — ที่นั่นสามารถปักหมุดระบบที่ใช้บ่อย แล้วจะมาโผล่ที่ Dashboard ใต้แบนเนอร์นี้',
                    side: 'top'
                }},
            ];
            tourBtn.addEventListener('click', function () {
                window.RsuTour.start(miniSteps, 'apps_migration');
            });
        }

        // First-visit nudge: if user has never seen the new badge AND never dismissed,
        // gently pulse the sidebar item so it draws the eye (animation already wired via CSS).
        // (No popover here — popover only shows on portal tour or user-triggered mini-tour.)
    });
})();
</script>
<button id="rsu-tour-fab" type="button" aria-label="ดู Tour อีกครั้ง" title="ดู Tour อีกครั้ง"
    onclick="window.RsuTour && RsuTour.start(window._portalTourSteps, 'portal_v2')"
    style="position:fixed;bottom:20px;right:20px;width:44px;height:44px;border-radius:50%;border:none;background:#2e9e63;color:#fff;font-size:16px;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.35);z-index:90;transition:transform .15s">
    <i class="fa-solid fa-question"></i>
</button>

</body>

</html>