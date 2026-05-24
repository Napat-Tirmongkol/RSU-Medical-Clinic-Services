<?php
/**
 * portal/_layout.php
 * Shared layout for portal admin pages — extracted from monolithic index.php.
 *
 * Usage (in each section page):
 *   require __DIR__ . '/_init.php';
 *   layout_start(['section' => 'edms', 'title' => 'สารบรรณ']);
 *   include __DIR__ . '/_partials/edms.php';
 *   layout_end();
 */
declare(strict_types=1);

if (!function_exists('layout_start')) {

/**
 * Internal state shared between layout_start() and layout_end().
 * (Function-local vars in layout_start aren't visible in layout_end —
 *  use a static holder so _layout_bottom.php can read $activeSection etc.)
 */
function _layout_state(?array $set = null): array
{
    static $state = ['section' => '', 'title' => ''];
    if ($set !== null) {
        $state = array_merge($state, $set);
    }
    return $state;
}

/**
 * Render layout top (DOCTYPE → opening <main>).
 * Caller MUST have required _init.php first (we use its globals).
 *
 * @param array $opts {
 *   @type string $section  active section key (for sidebar highlight)
 *   @type string $title    page title (for <title> + UI)
 * }
 */
function layout_start(array $opts = []): void
{
    // Pull in globals from _init.php so the extracted template can use them
    global $pdo, $adminRole, $isStaff, $isSuper, $registryOnly,
           $hasRegistry, $hasInsurance, $hasSysLogs, $hasSiteSet, $hasEdms,
           $hasScholarship, $hasDashboardAdmin, $hasMonthlyReport,
           $hasNurseProductivity, $hasDailySummary, $hasAsset, $hasConsumables,
           $hasInventory, $hasFinance, $hasInsuranceGroup, $hasSecurityGroup,
           $canEcampaign, $canEborrow, $canSystemLogs,
           $edmsInboxBadge, $edmsBreachedMine, $edmsWarningMine, $edmsTaskMine;

    $activeSection = $opts['section'] ?? '';
    $pageTitle     = $opts['title']   ?? '';

    // Persist so layout_end() can recover these
    _layout_state(['section' => $activeSection, 'title' => $pageTitle]);

    // Used by some sidebar items / header
    $idSearch = $_GET['id_search'] ?? '';

    include __DIR__ . '/_layout_top.php';
}

/**
 * Render layout bottom (closing </main> → </html>) + all global scripts/modals.
 */
function layout_end(): void
{
    global $pdo, $adminRole, $isStaff, $isSuper, $registryOnly,
           $hasRegistry, $hasInsurance, $hasSysLogs, $hasSiteSet, $hasEdms,
           $hasInsuranceGroup, $hasSecurityGroup;

    // Recover the section context that layout_start() saved.
    // _layout_bottom.php references $activeSection (e.g. LINE-link prompt skip on profile)
    $_state        = _layout_state();
    $activeSection = $_state['section'];
    $pageTitle     = $_state['title'];

    include __DIR__ . '/_layout_bottom.php';
}

/**
 * Render an "Access Denied" panel inside an already-opened layout.
 * Use when a section needs a gate but the user shouldn't be 403'd.
 */
function layout_access_denied(string $message = ''): void
{
    $msg = $message !== '' ? $message : 'หน้านี้สงวนสำหรับผู้ดูแลระบบ';
    echo '<div style="padding:100px 20px;text-align:center;font-weight:900;color:#dc2626">';
    echo '<i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i>';
    echo 'ACCESS DENIED<br>';
    echo '<span style="font-size:14px;color:#94a3b8;font-weight:600">' . htmlspecialchars($msg) . '</span>';
    echo '</div>';
}

} // end function_exists guard
