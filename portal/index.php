<?php
/**
 * portal/index.php
 *
 * Backwards-compat router after the multi-page refactor (2026-05-23).
 *
 * Old monolithic index.php (6476 lines) has been split into 42 standalone
 * section page files (dashboard.php, edms.php, finance.php, ...).
 * This stub handles legacy `?section=X` query-string URLs by 302-redirecting
 * to the matching X.php — bookmarks and external deep-links keep working.
 *
 * If no section is given (or it's invalid), fall back to dashboard.php.
 *
 * The original index.php content lives in version control history at commit
 * b88c6a9 — restore from there if a rollback is needed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';   // session + auth gate

// Whitelist of known sections (must match generated page files in portal/)
$known = [
    'dashboard','apps','announcements','identity','settings','pdpa_audit',
    'db_schema','sql_console','vaccinations','vaccine_catalog','finance',
    'ai_assistant','admin_chat','line_chat','ai_prompts','ai_knowledge',
    'ai_qa_lab','insurance_sync','insurance_dashboard','gold_card_pending',
    'gold_card','clinic_data','scholarship','nurse_schedule',
    'manage_insurance_partners','registry_upload','batch_status','profile',
    'activity_dashboard','activity_logs','error_logs','sentry_events',
    'monthly_report','daily_summary','nurse_productivity','accident_log','gold_card_stats','documents',
    'email_logs','smtp_settings','sentry_test','edms','line_settings',
    'privilege_inventory',
];

$section = $_GET['section'] ?? 'dashboard';
if (!in_array($section, $known, true)) {
    $section = 'dashboard';
}

// Special case: EDMS sub-views moved from ?edms_view=X to edms.php?view=X
if ($section === 'edms' && !empty($_GET['edms_view'])) {
    $view = $_GET['edms_view'];
    $extra = [];
    foreach (['type','id','filter','status','priority','from','to','s','p'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $extra[$k] = $_GET[$k];
    }
    $qs = http_build_query(array_merge(['view' => $view], $extra));
    header('Location: edms.php?' . $qs, true, 302);
    exit;
}

// Preserve other useful query params (preserve all except `section`)
$qs = $_GET;
unset($qs['section']);
$qsStr = !empty($qs) ? '?' . http_build_query($qs) : '';

header('Location: ' . rawurlencode($section) . '.php' . $qsStr, true, 302);
exit;
