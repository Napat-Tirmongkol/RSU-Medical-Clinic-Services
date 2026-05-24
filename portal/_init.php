<?php
/**
 * portal/_init.php
 * Shared bootstrap for all portal page files.
 * Sets up session, role flags, and DB connection — keeps it lean.
 * Per-page data fetching happens in each section's own file.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // session + redirect-if-not-logged-in

$pdo       = db();
$adminRole = $_SESSION['admin_role'] ?? 'admin';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);
$isSuper   = ($adminRole === 'superadmin');

// Shared POST action handlers (profile update, log clear, etc.) — no-op on GET
require_once __DIR__ . '/actions/portal_handlers.php';

// Registry-only mode: partner ภายนอกที่มีแค่ access_registry → ใช้ได้แต่ upload รายชื่อ
$registryOnly = !empty($_SESSION['access_registry'])
    && empty($_SESSION['access_insurance'])
    && empty($_SESSION['access_ecampaign'])
    && empty($_SESSION['access_eborrow'])
    && empty($_SESSION['access_system_logs'])
    && empty($_SESSION['access_site_settings'])
    && empty($_SESSION['access_edms'])
    && !$isSuper;

// Role flag pre-compute — ใช้ใน sidebar gates + access checks
$hasRegistry          = $isSuper || !empty($_SESSION['access_registry']);
$hasInsurance         = $isSuper || !empty($_SESSION['access_insurance']) || !empty($_SESSION['access_registry']);
$hasSysLogs           = $isSuper || !empty($_SESSION['access_system_logs']);
$hasSiteSet           = $isSuper || !empty($_SESSION['access_site_settings']);
$hasEdms              = $isSuper || !empty($_SESSION['access_edms']);
$hasScholarship       = $isSuper || !empty($_SESSION['access_scholarship']);
$hasDashboardAdmin    = $isSuper || !empty($_SESSION['access_dashboard_admin']);
$hasMonthlyReport     = $isSuper || !empty($_SESSION['access_monthly_report']) || !empty($_SESSION['access_director_view']);
$hasNurseProductivity = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
$hasDailySummary      = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_daily_summary']);
$hasAsset             = $isSuper || in_array($_SESSION['role'] ?? '', ['admin','editor'], true) || !empty($_SESSION['access_asset']);
$hasConsumables       = $isSuper || in_array($_SESSION['role'] ?? '', ['admin','editor'], true) || !empty($_SESSION['access_consumables']);
$hasInventory         = $hasAsset || $hasConsumables;
$hasFinance           = $isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance']);
$hasInsuranceGroup    = $isSuper || $hasInsurance || $hasRegistry;
$hasSecurityGroup     = $isSuper || !empty($_SESSION['access_identity']);
$canEcampaign         = !$isStaff || !empty($_SESSION['access_ecampaign']);
$canEborrow           = !$isStaff || !empty($_SESSION['access_eborrow']);
$canSystemLogs        = !$isStaff || !empty($_SESSION['access_system_logs']);
$hasAi                = $isSuper || !empty($_SESSION['access_ai']);

// Common "ACCESS DENIED" markup snippets — used by inline gate checks in section files
$aiDeniedHtml = '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_ai</span></div>';

// Default state for vars referenced by _layout_bottom.php on every page.
// Only pages that include _portal_data.php (dashboard / announcements / identity / settings)
// will overwrite these with real values — the rest stay safe at defaults.
$ann_saved = false;

// EDMS inbox / SLA badge counts.
// Used in 2 scopes: sidebar (badge on "สารบรรณ" item) + dashboard priority panel.
// Compute once here so both surfaces share the same data without re-querying.
$edmsInboxBadge   = 0;
$edmsBreachedMine = 0;
$edmsWarningMine  = 0;
$edmsTaskMine     = 0;
if ($hasEdms) {
    $_uid = (int)($_SESSION['admin_id'] ?? 0);
    if ($_uid > 0) {
        try {
            $_st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = ? AND status IN ('pending','acknowledged')");
            $_st->execute([$_uid]);
            $edmsInboxBadge = (int)$_st->fetchColumn();
        } catch (PDOException) { /* table not yet migrated */ }

        try {
            $_st = $pdo->prepare("SELECT
                SUM(CASE WHEN sla_state = 'breached' THEN 1 ELSE 0 END) AS breached,
                SUM(CASE WHEN sla_state = 'warning' THEN 1 ELSE 0 END) AS warning
                FROM sys_doc_routings
                WHERE to_user_id = ? AND status IN ('pending','acknowledged')");
            $_st->execute([$_uid]);
            $_sr = $_st->fetch(PDO::FETCH_ASSOC) ?: [];
            $edmsBreachedMine = (int)($_sr['breached'] ?? 0);
            $edmsWarningMine  = (int)($_sr['warning'] ?? 0);
        } catch (PDOException) { /* sla columns not yet migrated */ }

        try {
            $_st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_routings r
                JOIN sys_doc_documents d ON d.id = r.doc_id
                WHERE r.to_user_id = ? AND r.status IN ('pending','acknowledged') AND d.doc_type = 'task'");
            $_st->execute([$_uid]);
            $edmsTaskMine = (int)$_st->fetchColumn();
        } catch (PDOException) { /* schema gap */ }
    }
}

// Per-page section name — set by each section file before calling layout_start()
// Default = empty (page must declare its own)
$activeSection = $_GET['section'] ?? '';

/**
 * Access guard helper — render ACCESS DENIED + exit.
 * ใช้ใน section file หลัง _init แต่ก่อน layout_start หากต้อง gate
 */
if (!function_exists('portal_access_guard')) {
    function portal_access_guard(bool $allowed, string $missingFlag = ''): void {
        if ($allowed) return;
        $msg = $missingFlag ? "ต้องมีสิทธิ์ {$missingFlag}" : 'หน้านี้สงวนสำหรับผู้ดูแลระบบ';
        // Inline render (no layout) — เป็นการ exit แบบเร็ว
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8"><title>Access Denied</title>';
        echo '<link rel="stylesheet" href="../assets/css/tailwind.min.css">';
        echo '</head><body class="bg-slate-50 min-h-screen flex items-center justify-center font-sans">';
        echo '<div class="text-center max-w-md p-8">';
        echo '<i class="fa-solid fa-shield-slash text-6xl text-rose-400 mb-4 block"></i>';
        echo '<h1 class="text-2xl font-bold text-slate-800 mb-2">ไม่มีสิทธิ์เข้าถึง</h1>';
        echo '<p class="text-slate-500 mb-6">' . htmlspecialchars($msg) . '</p>';
        echo '<a href="dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700">';
        echo '<i class="fa-solid fa-arrow-left"></i> กลับหน้าหลัก</a>';
        echo '</div></body></html>';
        exit;
    }
}
