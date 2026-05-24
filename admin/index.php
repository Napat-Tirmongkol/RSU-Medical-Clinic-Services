<?php
// admin/index.php — Dashboard
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

/* ──────────────────────────────────────────────────────────────────────
   KPI snapshot (current totals) + delta vs same period yesterday
   ────────────────────────────────────────────────────────────────────── */
$row = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM camp_list WHERE status='active')                                   AS active_campaigns,
        (SELECT COUNT(*) FROM camp_bookings WHERE status='booked')                               AS pending_count,
        (SELECT COUNT(*) FROM camp_bookings WHERE status='confirmed')                            AS confirmed_count,
        (SELECT COUNT(*) FROM camp_bookings WHERE DATE(created_at)=CURDATE())                    AS bookings_today,
        (SELECT COUNT(*) FROM camp_bookings WHERE DATE(created_at)=CURDATE()-INTERVAL 1 DAY)     AS bookings_yesterday,
        (SELECT COUNT(*) FROM sys_users     WHERE created_at >= NOW()-INTERVAL 7 DAY)            AS new_users_7d,
        (SELECT COUNT(*) FROM sys_users     WHERE created_at >= NOW()-INTERVAL 14 DAY AND created_at < NOW()-INTERVAL 7 DAY) AS new_users_prev_7d,
        (SELECT COUNT(*) FROM camp_bookings WHERE created_at >= NOW()-INTERVAL 7 DAY)            AS bookings_7d,
        (SELECT COUNT(*) FROM camp_bookings WHERE created_at >= NOW()-INTERVAL 14 DAY AND created_at < NOW()-INTERVAL 7 DAY) AS bookings_prev_7d
")->fetch(PDO::FETCH_ASSOC);

$stats = [
    'active_campaigns'   => (int)($row['active_campaigns']   ?? 0),
    'pending_count'      => (int)($row['pending_count']      ?? 0),
    'confirmed_count'    => (int)($row['confirmed_count']    ?? 0),
    'bookings_today'     => (int)($row['bookings_today']     ?? 0),
    'bookings_yesterday' => (int)($row['bookings_yesterday'] ?? 0),
    'new_users_7d'       => (int)($row['new_users_7d']       ?? 0),
    'new_users_prev_7d'  => (int)($row['new_users_prev_7d']  ?? 0),
    'bookings_7d'        => (int)($row['bookings_7d']        ?? 0),
    'bookings_prev_7d'   => (int)($row['bookings_prev_7d']   ?? 0),
];

/**
 * Compute %change delta vs prior period.
 * Returns ['pct' => int|null, 'dir' => 'up'|'down'|'flat', 'label' => 'NN%']
 */
function periodDelta(int $current, int $prior): array {
    if ($prior === 0) {
        return [
            'pct'   => $current > 0 ? 100 : null,
            'dir'   => $current > 0 ? 'up' : 'flat',
            'label' => $current > 0 ? 'ใหม่' : '—',
        ];
    }
    $pct = (int) round((($current - $prior) / $prior) * 100);
    $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
    return [
        'pct'   => $pct,
        'dir'   => $dir,
        'label' => ($pct > 0 ? '+' : '') . $pct . '%',
    ];
}

$delta_bookings_day = periodDelta($stats['bookings_today'], $stats['bookings_yesterday']);
$delta_users_week   = periodDelta($stats['new_users_7d'],   $stats['new_users_prev_7d']);
$delta_bookings_week= periodDelta($stats['bookings_7d'],    $stats['bookings_prev_7d']);

/* ──────────────────────────────────────────────────────────────────────
   Popular campaigns (top 5)
   ────────────────────────────────────────────────────────────────────── */
$popular_campaigns = $pdo->query("
    SELECT c.id, c.title, c.type, c.total_capacity,
           COUNT(b.id) AS booking_count
    FROM camp_list c
    LEFT JOIN camp_bookings b ON c.id = b.campaign_id AND b.status IN ('booked','confirmed','completed')
    GROUP BY c.id
    ORDER BY booking_count DESC, c.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────────────
   Booking funnel (active bookings overall)
   ────────────────────────────────────────────────────────────────────── */
$funnel_row = $pdo->query("
    SELECT
        SUM(status='booked')                                          AS booked,
        SUM(status='confirmed')                                       AS confirmed,
        SUM(status='completed')                                       AS completed,
        SUM(status IN ('cancelled','cancelled_by_admin','expired'))   AS cancelled,
        COUNT(*)                                                      AS total
    FROM camp_bookings
")->fetch(PDO::FETCH_ASSOC);
$funnel = [
    'booked'    => (int)($funnel_row['booked']    ?? 0),
    'confirmed' => (int)($funnel_row['confirmed'] ?? 0),
    'completed' => (int)($funnel_row['completed'] ?? 0),
    'cancelled' => (int)($funnel_row['cancelled'] ?? 0),
    'total'     => (int)($funnel_row['total']     ?? 0),
];
$funnel_active = $funnel['booked'] + $funnel['confirmed'] + $funnel['completed'];
$noshow_rate = $funnel_active > 0
    ? round(($funnel['cancelled'] / max(1, $funnel['cancelled'] + $funnel_active)) * 100, 1)
    : 0.0;

/* ──────────────────────────────────────────────────────────────────────
   12-week booking trend
   ────────────────────────────────────────────────────────────────────── */
$trend_rows = $pdo->query("
    SELECT YEARWEEK(created_at, 1) AS yw,
           MIN(DATE(created_at))   AS week_start,
           COUNT(*)                AS cnt
    FROM camp_bookings
    WHERE created_at >= CURDATE() - INTERVAL 12 WEEK
    GROUP BY yw
    ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

$trend_labels = [];
$trend_values = [];
foreach ($trend_rows as $r) {
    $trend_labels[] = date('d M', strtotime($r['week_start']));
    $trend_values[] = (int)$r['cnt'];
}

/* ──────────────────────────────────────────────────────────────────────
   Campaign type distribution (active campaigns only)
   ────────────────────────────────────────────────────────────────────── */
$type_rows = $pdo->query("
    SELECT IFNULL(type,'other') AS type, COUNT(*) AS cnt
    FROM camp_list
    WHERE status='active'
    GROUP BY type
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────────────
   Appointment density heatmap
   ────────────────────────────────────────────────────────────────────── */
$heatmap_rows = $pdo->query("
    SELECT DAYOFWEEK(s.slot_date) AS dow,
           HOUR(s.start_time)     AS hr,
           COUNT(*)               AS cnt
    FROM camp_bookings b
    JOIN camp_slots s ON b.slot_id = s.id
    WHERE b.status IN ('booked','confirmed','completed')
    GROUP BY dow, hr
")->fetchAll(PDO::FETCH_ASSOC);

$heatmap  = [];
$hmap_max = 1;
foreach ($heatmap_rows as $r) {
    if ($r['hr'] === null) continue;
    $heatmap[(int)$r['dow']][(int)$r['hr']] = (int)$r['cnt'];
    if ((int)$r['cnt'] > $hmap_max) $hmap_max = (int)$r['cnt'];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Dashboard-specific tiles ───────────────────────────────────────── */
.ec-kpi {
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    border-left: 4px solid var(--kpi-accent, var(--ec-brand-500));
    border-radius: 18px;
    padding: 18px;
    position: relative;
    overflow: hidden;
    transition: transform .2s cubic-bezier(.16,1,.3,1), box-shadow .2s, border-color .2s;
}
.ec-kpi:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 30px -10px rgba(15,23,42,.12);
}
body[data-theme='dark'] .ec-kpi:hover {
    box-shadow: 0 14px 30px -10px rgba(0,0,0,.6);
}
.ec-kpi-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--ec-ink-3);
    letter-spacing: .04em;
    margin-bottom: 4px;
}
.ec-kpi-value {
    font-size: 30px;
    font-weight: 800;
    color: var(--ec-ink-1);
    line-height: 1.1;
    letter-spacing: -.02em;
    transition: transform .2s, color .2s;
}
.ec-kpi-value.flash {
    color: var(--ec-brand-500);
    transform: scale(1.06);
}
.ec-kpi-icon {
    width: 42px; height: 42px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
}
.ec-kpi-delta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 10px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.ec-kpi-delta[data-dir="up"]    { background: #ecfdf5; color: #047857; }
.ec-kpi-delta[data-dir="down"]  { background: #fef2f2; color: #b91c1c; }
.ec-kpi-delta[data-dir="flat"]  { background: var(--ec-surface-2); color: var(--ec-ink-3); }
body[data-theme='dark'] .ec-kpi-delta[data-dir="up"]   { background: rgba(16,185,129,.15); color: #6ee7b7; }
body[data-theme='dark'] .ec-kpi-delta[data-dir="down"] { background: rgba(239,68,68,.15);  color: #fca5a5; }
body[data-theme='dark'] .ec-kpi-delta[data-dir="flat"] { background: rgba(255,255,255,.05); color: var(--ec-ink-4); }

/* ── Generic card ───────────────────────────────────────────────────── */
.ec-card {
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    border-radius: 20px;
    overflow: hidden;
}
.ec-card-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--ec-border-soft);
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px;
}
.ec-card-title {
    font-size: 15px;
    font-weight: 800;
    color: var(--ec-ink-1);
    margin: 0;
}
.ec-card-sub {
    font-size: 11px;
    color: var(--ec-ink-3);
    margin-top: 3px;
}
.ec-card-icon {
    width: 40px; height: 40px;
    border-radius: 999px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 16px;
}

/* Popular campaign rows */
.ec-popular-row {
    display: flex; align-items: center; gap: 14px;
    padding: 10px 12px;
    border-radius: 14px;
    border: 1px solid transparent;
    transition: background .15s, border-color .15s;
    text-decoration: none;
}
.ec-popular-row:hover {
    background: var(--ec-surface-2);
    border-color: var(--ec-border);
}
.ec-rank {
    width: 28px; height: 28px;
    border-radius: 999px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-size: 12px;
    flex-shrink: 0;
}
.ec-rank-1 { background: #fff7ed; color: #ea580c; }
.ec-rank-2 { background: #f1f5f9; color: #475569; }
.ec-rank-3 { background: #fef3c7; color: #b45309; }
.ec-rank-x { background: var(--ec-brand-50); color: var(--ec-brand-700); }
body[data-theme='dark'] .ec-rank-1 { background: rgba(234,88,12,.18); color: #fdba74; }
body[data-theme='dark'] .ec-rank-2 { background: rgba(255,255,255,.06); color: var(--ec-ink-3); }
body[data-theme='dark'] .ec-rank-3 { background: rgba(245,158,11,.18); color: #fcd34d; }
body[data-theme='dark'] .ec-rank-x { background: rgba(46,158,99,.18); color: var(--ec-brand-400); }

.ec-popular-title {
    flex: 1; min-width: 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--ec-ink-1);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.ec-popular-meta {
    flex-shrink: 0;
    padding: 4px 12px;
    border-radius: 999px;
    background: var(--ec-surface-2);
    border: 1px solid var(--ec-border);
    font-size: 11px;
    font-weight: 700;
    color: var(--ec-ink-2);
}
body[data-theme='dark'] .ec-popular-meta {
    background: rgba(255,255,255,.04);
}

/* Quick action cards */
.ec-action {
    position: relative;
    overflow: hidden;
    padding: 22px;
    border-radius: 22px;
    display: flex; flex-direction: column; justify-content: space-between;
    min-height: 168px;
    text-decoration: none;
    transition: transform .2s cubic-bezier(.16,1,.3,1), box-shadow .2s;
}
.ec-action:hover { transform: translateY(-3px); }
.ec-action-primary {
    background: linear-gradient(135deg, #1f7a4d 0%, #2e9e63 60%, #34d399 100%);
    color: #fff;
    box-shadow: 0 14px 30px -10px rgba(46,158,99,.45);
}
.ec-action-primary:hover { box-shadow: 0 22px 40px -10px rgba(46,158,99,.55); }
.ec-action-secondary {
    background: var(--ec-surface);
    color: var(--ec-ink-1);
    border: 1px solid var(--ec-border);
}
.ec-action-secondary:hover {
    border-color: var(--ec-brand-200);
    box-shadow: 0 14px 30px -10px rgba(46,158,99,.18);
}
body[data-theme='dark'] .ec-action-secondary:hover {
    border-color: rgba(46,158,99,.4);
}
.ec-action-icon {
    width: 48px; height: 48px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    margin-bottom: 12px;
}
.ec-action-primary .ec-action-icon {
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(6px);
}
.ec-action-secondary .ec-action-icon {
    background: var(--ec-brand-50);
    color: var(--ec-brand-700);
}
body[data-theme='dark'] .ec-action-secondary .ec-action-icon {
    background: rgba(46,158,99,.18);
    color: var(--ec-brand-400);
}
.ec-action-title { font-size: 18px; font-weight: 800; margin-bottom: 4px; }
.ec-action-sub   { font-size: 12px; opacity: .85; }
.ec-action-arrow {
    position: absolute; bottom: 18px; right: 18px;
    font-size: 16px;
    opacity: 0;
    transform: translateX(-6px);
    transition: opacity .25s, transform .25s;
}
.ec-action:hover .ec-action-arrow { opacity: 1; transform: translateX(0); }
.ec-action-primary .ec-action-arrow { color: #fff; }
.ec-action-secondary .ec-action-arrow { color: var(--ec-brand-600); }

/* Funnel bar */
.ec-funnel-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 2px;
    height: 14px;
    border-radius: 999px;
    overflow: hidden;
    background: var(--ec-surface-2);
}
.ec-funnel-seg {
    height: 100%;
    transition: opacity .15s;
}
.ec-funnel-seg:hover { opacity: .85; }
.ec-funnel-legend {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px 18px;
    margin-top: 14px;
}
@media (min-width: 640px) {
    .ec-funnel-legend { grid-template-columns: repeat(4, 1fr); }
}
.ec-funnel-item {
    display: flex; flex-direction: column; gap: 2px;
}
.ec-funnel-item-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--ec-ink-3);
    display: inline-flex; align-items: center; gap: 6px;
}
.ec-funnel-dot {
    width: 8px; height: 8px;
    border-radius: 2px;
    display: inline-block;
}
.ec-funnel-item-value {
    font-size: 18px;
    font-weight: 800;
    color: var(--ec-ink-1);
    line-height: 1.1;
}

/* Heatmap */
.ec-heatmap-wrap { overflow-x: auto; }
.ec-heatmap { min-width: 560px; }
.ec-heatmap-row { display: flex; align-items: center; margin-bottom: 3px; }
.ec-heatmap-label {
    width: 38px;
    flex-shrink: 0;
    font-size: 11px;
    font-weight: 700;
    color: var(--ec-ink-3);
    text-align: right;
    padding-right: 8px;
}
.ec-heatmap-cells { flex: 1; display: flex; gap: 3px; }
.ec-heatmap-cell {
    flex: 1;
    aspect-ratio: 1/1;
    border-radius: 3px;
    min-width: 0;
    transition: opacity .15s;
}
.ec-heatmap-cell:hover { opacity: .65; }
.ec-heatmap-hours {
    display: flex; flex: 1; gap: 3px;
    margin-bottom: 6px;
}
.ec-heatmap-hour {
    flex: 1; text-align: center;
    font-size: 9px;
    font-weight: 600;
    color: var(--ec-ink-4);
}
.ec-heatmap-legend {
    display: flex; align-items: center; gap: 6px;
    margin-top: 16px; padding-left: 38px;
    font-size: 10px;
    color: var(--ec-ink-4);
    font-weight: 600;
}
.ec-heatmap-legend-cell {
    width: 13px; height: 13px;
    border-radius: 3px;
    flex-shrink: 0;
}

/* Chip toggle */
.ec-chip-row {
    display: flex; flex-wrap: wrap; gap: 6px;
}
.ec-chip {
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: var(--ec-surface-2);
    color: var(--ec-ink-3);
    border: 1px solid var(--ec-border);
    cursor: pointer;
    transition: all .15s;
}
.ec-chip:hover {
    border-color: var(--ec-brand-200);
    color: var(--ec-brand-700);
}
body[data-theme='dark'] .ec-chip:hover { color: var(--ec-brand-400); }
.ec-chip.is-active {
    background: linear-gradient(135deg, #2e9e63, #34d399);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 4px 12px -2px rgba(46,158,99,.4);
}

/* Skeleton loader */
.ec-skeleton {
    background: linear-gradient(90deg, var(--ec-surface-2) 0%, var(--ec-border-soft) 50%, var(--ec-surface-2) 100%);
    background-size: 200% 100%;
    animation: ecShimmer 1.4s ease-in-out infinite;
    border-radius: 6px;
}
@keyframes ecShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
@media (prefers-reduced-motion: reduce) {
    .ec-skeleton { animation: none; }
}

/* Empty state */
.ec-empty {
    text-align: center;
    padding: 32px 16px;
    color: var(--ec-ink-3);
}
.ec-empty-icon {
    font-size: 36px;
    color: var(--ec-ink-4);
    margin-bottom: 10px;
    display: block;
}
.ec-empty-text { font-size: 13px; font-weight: 600; }

/* Grids */
.ec-grid-kpi {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
@media (min-width: 1024px) {
    .ec-grid-kpi { grid-template-columns: repeat(4, 1fr); gap: 18px; }
}
.ec-grid-main {
    display: grid;
    grid-template-columns: 1fr;
    gap: 18px;
}
@media (min-width: 1024px) {
    .ec-grid-main { grid-template-columns: 1fr 1fr; gap: 24px; }
}

/* Live pulse next to title */
.ec-live-pulse {
    position: relative;
    display: inline-flex;
    width: 14px; height: 14px;
}
.ec-live-pulse-ping {
    position: absolute; inset: 0;
    border-radius: 999px;
    background: #34d399;
    animation: ping 1.6s cubic-bezier(0,0,.2,1) infinite;
    opacity: .75;
}
.ec-live-pulse-dot {
    position: absolute; top: 2px; left: 2px;
    width: 10px; height: 10px;
    border-radius: 999px;
    background: #2e9e63;
}
@keyframes ping {
    75%, 100% { transform: scale(2); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .ec-live-pulse-ping { animation: none; }
}
</style>

<?php
$header_title = 'ภาพรวมระบบ <span class="ec-live-pulse" title="อัปเดตอัตโนมัติทุก 15 วินาที"><span class="ec-live-pulse-ping"></span><span class="ec-live-pulse-dot"></span></span>';
renderPageHeader($header_title, 'สถิติแบบเรียลไทม์ของระบบจองและเช็คอินแคมเปญ');
?>

<!-- KPI grid -->
<div class="ec-grid-kpi animate-slide-up">

    <!-- 1. Active Campaigns -->
    <div class="ec-kpi" style="--kpi-accent: var(--ec-brand-500);">
        <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
                <div class="ec-kpi-label">แคมเปญที่เปิด</div>
                <div id="stat-total" class="ec-kpi-value"><?= number_format($stats['active_campaigns']) ?></div>
            </div>
            <div class="ec-kpi-icon" style="background:var(--ec-brand-50); color:var(--ec-brand-700);">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
        </div>
        <span class="ec-kpi-delta" data-dir="flat">
            <i class="fa-solid fa-circle-check"></i> Active
        </span>
    </div>

    <!-- 2. Bookings Today + delta -->
    <div class="ec-kpi" style="--kpi-accent: #14b8a6;">
        <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
                <div class="ec-kpi-label">จองวันนี้</div>
                <div id="stat-bookings-today" class="ec-kpi-value"><?= number_format($stats['bookings_today']) ?></div>
            </div>
            <div class="ec-kpi-icon" style="background:#ccfbf1; color:#0f766e;">
                <i class="fa-solid fa-calendar-day"></i>
            </div>
        </div>
        <span id="delta-bookings-today" class="ec-kpi-delta" data-dir="<?= $delta_bookings_day['dir'] ?>">
            <i class="fa-solid fa-arrow-<?= $delta_bookings_day['dir']==='up' ? 'trend-up' : ($delta_bookings_day['dir']==='down'?'trend-down':'right') ?>"></i>
            <?= htmlspecialchars($delta_bookings_day['label']) ?> vs เมื่อวาน
        </span>
    </div>

    <!-- 3. New Users 7d + delta -->
    <div class="ec-kpi" style="--kpi-accent: #6366f1;">
        <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
                <div class="ec-kpi-label">ผู้ใช้ใหม่ 7 วัน</div>
                <div id="stat-new-users" class="ec-kpi-value"><?= number_format($stats['new_users_7d']) ?></div>
            </div>
            <div class="ec-kpi-icon" style="background:#e0e7ff; color:#4338ca;">
                <i class="fa-solid fa-user-plus"></i>
            </div>
        </div>
        <span id="delta-new-users" class="ec-kpi-delta" data-dir="<?= $delta_users_week['dir'] ?>">
            <i class="fa-solid fa-arrow-<?= $delta_users_week['dir']==='up' ? 'trend-up' : ($delta_users_week['dir']==='down'?'trend-down':'right') ?>"></i>
            <?= htmlspecialchars($delta_users_week['label']) ?> vs 7 วันก่อน
        </span>
    </div>

    <!-- 4. Pending Approval -->
    <div class="ec-kpi" style="--kpi-accent: #f59e0b;">
        <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
                <div class="ec-kpi-label">รออนุมัติ</div>
                <div id="stat-pending" class="ec-kpi-value"><?= number_format($stats['pending_count']) ?></div>
            </div>
            <div class="ec-kpi-icon" style="background:#fef3c7; color:#b45309;">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
        </div>
        <?php if ($stats['pending_count'] > 0): ?>
        <a href="bookings.php" class="ec-kpi-delta" data-dir="up" style="text-decoration:none;">
            <i class="fa-solid fa-circle-exclamation"></i> รอดำเนินการ
        </a>
        <?php else: ?>
        <span class="ec-kpi-delta" data-dir="flat">
            <i class="fa-solid fa-check"></i> ไม่มีค้าง
        </span>
        <?php endif; ?>
    </div>

</div>

<!-- Booking Funnel -->
<div class="ec-card animate-slide-up delay-100" style="margin-bottom:24px;">
    <div class="ec-card-header">
        <div>
            <h3 class="ec-card-title">ภาพรวมสถานะการจอง (Funnel)</h3>
            <p class="ec-card-sub">การกระจายตัวของสถานะทุกการจองในระบบ</p>
        </div>
        <div class="ec-card-icon" style="background:#e0e7ff; color:#4338ca;">
            <i class="fa-solid fa-filter"></i>
        </div>
    </div>
    <div style="padding:20px;">
        <?php if ($funnel['total'] > 0):
            $segs = [
                ['booked',    'รออนุมัติ',  '#f59e0b', $funnel['booked']],
                ['confirmed', 'ยืนยันแล้ว', '#0ea5e9', $funnel['confirmed']],
                ['completed', 'เช็คอินแล้ว','#10b981', $funnel['completed']],
                ['cancelled', 'ยกเลิก/พลาด','#94a3b8', $funnel['cancelled']],
            ];
        ?>
        <div class="ec-funnel-bar">
            <?php foreach ($segs as $s):
                [$key, $label, $color, $val] = $s;
                $pct = $funnel['total'] > 0 ? max(0.5, ($val / $funnel['total']) * 100) : 0;
            ?>
            <div class="ec-funnel-seg"
                 style="background:<?= $color ?>; flex: <?= $val ?> 0 0;"
                 title="<?= htmlspecialchars($label . ': ' . number_format($val)) ?>"></div>
            <?php endforeach; ?>
        </div>
        <div class="ec-funnel-legend">
            <?php foreach ($segs as $s):
                [$key, $label, $color, $val] = $s;
                $pct = $funnel['total'] > 0 ? round(($val / $funnel['total']) * 100) : 0;
            ?>
            <div class="ec-funnel-item">
                <span class="ec-funnel-item-label">
                    <span class="ec-funnel-dot" style="background:<?= $color ?>"></span>
                    <?= htmlspecialchars($label) ?>
                </span>
                <span class="ec-funnel-item-value"><?= number_format($val) ?></span>
                <span style="font-size:10px; color:var(--ec-ink-4); font-weight:600;"><?= $pct ?>% ของทั้งหมด</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ec-empty">
            <i class="fa-solid fa-inbox ec-empty-icon"></i>
            <div class="ec-empty-text">ยังไม่มีการจองในระบบ</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Main 2-column grid: Trend + Type donut -->
<div class="ec-grid-main animate-slide-up delay-200" style="margin-bottom:24px;">

    <!-- Booking trend (12 weeks) -->
    <div class="ec-card">
        <div class="ec-card-header">
            <div>
                <h3 class="ec-card-title">การจอง 12 สัปดาห์ล่าสุด</h3>
                <p class="ec-card-sub">จำนวนการจองใหม่ต่อสัปดาห์ (รวมทุกสถานะ)</p>
            </div>
            <div class="ec-card-icon" style="background:#dcfce7; color:#15803d;">
                <i class="fa-solid fa-chart-line"></i>
            </div>
        </div>
        <div style="padding:18px;">
            <?php if (count($trend_values) > 0): ?>
            <canvas id="trendChart" height="170"></canvas>
            <?php else: ?>
            <div class="ec-empty">
                <i class="fa-solid fa-chart-line ec-empty-icon"></i>
                <div class="ec-empty-text">ยังไม่มีข้อมูลการจอง 12 สัปดาห์ล่าสุด</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Campaign type donut + Popular campaigns -->
    <div class="ec-card">
        <div class="ec-card-header">
            <div>
                <h3 class="ec-card-title">แคมเปญที่เปิดอยู่</h3>
                <p class="ec-card-sub">แยกตามประเภทการให้บริการ</p>
            </div>
            <div class="ec-card-icon" style="background:#fae8ff; color:#a21caf;">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
        </div>
        <div style="padding:18px;">
            <?php if (count($type_rows) > 0): ?>
            <div style="position:relative; max-width:280px; margin:0 auto;">
                <canvas id="typeDonut" height="220"></canvas>
                <div id="typeDonutCenter" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; pointer-events:none;">
                    <span style="font-size:26px; font-weight:800; color:var(--ec-ink-1);"><?= number_format(array_sum(array_column($type_rows, 'cnt'))) ?></span>
                    <span style="font-size:11px; font-weight:600; color:var(--ec-ink-3); margin-top:2px;">แคมเปญ</span>
                </div>
            </div>
            <?php else: ?>
            <div class="ec-empty">
                <i class="fa-solid fa-chart-pie ec-empty-icon"></i>
                <div class="ec-empty-text">ไม่มีแคมเปญที่เปิดอยู่</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Bottom 2-column grid: Popular + Quick actions -->
<div class="ec-grid-main animate-slide-up delay-300" style="margin-bottom:24px;">

    <!-- POPULAR campaigns -->
    <div class="ec-card">
        <div class="ec-card-header">
            <div>
                <h3 class="ec-card-title">แคมเปญยอดฮิต</h3>
                <p class="ec-card-sub">5 อันดับที่มีผู้สนใจมากที่สุด</p>
            </div>
            <div class="ec-card-icon" style="background:#fff7ed; color:#ea580c;">
                <i class="fa-solid fa-fire"></i>
            </div>
        </div>
        <div style="padding:14px; max-height: 360px; overflow-y: auto;">
            <div id="popular-camp_list-container" style="display:flex; flex-direction:column; gap:8px;">
                <?php if (empty($popular_campaigns)): ?>
                <div class="ec-empty">
                    <i class="fa-solid fa-inbox ec-empty-icon"></i>
                    <div class="ec-empty-text">ยังไม่มีข้อมูลการจอง</div>
                </div>
                <?php else:
                    foreach ($popular_campaigns as $idx => $pc):
                        $rankClass = $idx === 0 ? 'ec-rank-1' : ($idx === 1 ? 'ec-rank-2' : ($idx === 2 ? 'ec-rank-3' : 'ec-rank-x'));
                ?>
                <a href="campaign_overview.php?id=<?= (int)$pc['id'] ?>" class="ec-popular-row">
                    <div class="ec-rank <?= $rankClass ?>"><?= $idx + 1 ?></div>
                    <span class="ec-popular-title"><?= htmlspecialchars($pc['title']) ?></span>
                    <span class="ec-popular-meta"><?= number_format($pc['booking_count']) ?> คน</span>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">

        <a href="campaigns.php" class="ec-action ec-action-primary" style="grid-column: 1 / -1;">
            <div>
                <div class="ec-action-icon"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="ec-action-title">สร้างแคมเปญใหม่</div>
                <div class="ec-action-sub">เริ่มเปิดโครงการให้คนเข้ามาจองสิทธิ์</div>
            </div>
            <i class="fa-solid fa-arrow-right ec-action-arrow"></i>
        </a>

        <a href="bookings.php" class="ec-action ec-action-secondary">
            <div>
                <div class="ec-action-icon"><i class="fa-solid fa-users"></i></div>
                <div class="ec-action-title" style="font-size:15px;">ผู้จอง</div>
                <div class="ec-action-sub">ตรวจสอบและอนุมัติ</div>
            </div>
            <i class="fa-solid fa-arrow-right ec-action-arrow"></i>
        </a>

        <a href="daily_report.php" class="ec-action ec-action-secondary">
            <div>
                <div class="ec-action-icon"><i class="fa-solid fa-calendar-day"></i></div>
                <div class="ec-action-title" style="font-size:15px;">รายงานวันนี้</div>
                <div class="ec-action-sub">ใครมา / ไม่มา</div>
            </div>
            <i class="fa-solid fa-arrow-right ec-action-arrow"></i>
        </a>

    </div>
</div>

<!-- PEAK TIMES HEATMAP -->
<div class="ec-card animate-slide-up" style="animation-delay:.32s;">
    <div class="ec-card-header">
        <div>
            <h3 class="ec-card-title">ช่วงเวลาที่ผู้ใช้นัดเข้ามามากที่สุด</h3>
            <p class="ec-card-sub">ความหนาแน่นของการจอง × วัน × ชั่วโมง (ทุกเวลา)</p>
        </div>
        <div class="ec-card-icon" style="background:#fef2f2; color:#dc2626;">
            <i class="fa-solid fa-fire-flame-curved"></i>
        </div>
    </div>
    <div style="padding:20px;">
        <div class="ec-heatmap-wrap">
            <div class="ec-heatmap">

                <!-- Hour labels -->
                <div class="ec-heatmap-row">
                    <div class="ec-heatmap-label" style="visibility:hidden;">.</div>
                    <div class="ec-heatmap-hours">
                        <?php
                        $hourLabels = [0=>'00',3=>'03',6=>'06',9=>'09',12=>'12',15=>'15',18=>'18',21=>'21'];
                        for ($h = 0; $h < 24; $h++): ?>
                        <div class="ec-heatmap-hour"><?= $hourLabels[$h] ?? '' ?></div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Grid rows -->
                <?php
                $hmapDays = [
                    ['label'=>'จ.','dow'=>2],
                    ['label'=>'อ.','dow'=>3],
                    ['label'=>'พ.','dow'=>4],
                    ['label'=>'พฤ.','dow'=>5],
                    ['label'=>'ศ.','dow'=>6],
                    ['label'=>'ส.','dow'=>7],
                    ['label'=>'อา.','dow'=>1],
                ];
                $colorStops = ['#f1f5f9','#dcfce7','#86efac','#4ade80','#22c55e','#16a34a'];
                $colorStopsDark = ['#1e293b','#064e3b','#047857','#10b981','#34d399','#6ee7b7'];

                function hmapColor(int $cnt, int $max, array $stops): string {
                    if ($cnt === 0 || $max === 0) return $stops[0];
                    $ratio = $cnt / $max;
                    if ($ratio < 0.15) return $stops[1];
                    if ($ratio < 0.35) return $stops[2];
                    if ($ratio < 0.60) return $stops[3];
                    if ($ratio < 0.80) return $stops[4];
                    return $stops[5];
                }

                foreach ($hmapDays as $day): ?>
                <div class="ec-heatmap-row">
                    <div class="ec-heatmap-label"><?= $day['label'] ?></div>
                    <div class="ec-heatmap-cells">
                        <?php for ($h = 0; $h < 24; $h++):
                            $cnt = $heatmap[$day['dow']][$h] ?? 0;
                            $lightCol = hmapColor($cnt, $hmap_max, $colorStops);
                            $darkCol  = hmapColor($cnt, $hmap_max, $colorStopsDark);
                            $timeLabel = sprintf('%02d:00', $h);
                            $tip = $cnt > 0
                                ? "$timeLabel น. · {$cnt} คิว"
                                : "$timeLabel น. · ไม่มีการจอง";
                        ?>
                        <div class="ec-heatmap-cell"
                             data-light="<?= $lightCol ?>"
                             data-dark="<?= $darkCol ?>"
                             style="background:<?= $lightCol ?>;"
                             title="<?= htmlspecialchars($tip) ?>"></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Legend -->
                <div class="ec-heatmap-legend">
                    <span>น้อย</span>
                    <?php foreach ($colorStops as $i => $lc): ?>
                    <div class="ec-heatmap-legend-cell"
                         data-light="<?= $lc ?>"
                         data-dark="<?= $colorStopsDark[$i] ?>"
                         style="background:<?= $lc ?>"></div>
                    <?php endforeach; ?>
                    <span>มาก</span>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {

    /* ── Number count-up animation ────────────────────────────────── */
    function animateValue(el, end, duration) {
        if (!el) return;
        var start = 0;
        var startTs = null;
        function step(ts) {
            if (!startTs) startTs = ts;
            var progress = Math.min((ts - startTs) / duration, 1);
            el.textContent = Math.floor(progress * (end - start) + start).toLocaleString();
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }
    animateValue(document.getElementById('stat-total'),          <?= (int)$stats['active_campaigns'] ?>, 900);
    animateValue(document.getElementById('stat-bookings-today'), <?= (int)$stats['bookings_today'] ?>,   900);
    animateValue(document.getElementById('stat-new-users'),      <?= (int)$stats['new_users_7d'] ?>,     900);
    animateValue(document.getElementById('stat-pending'),        <?= (int)$stats['pending_count'] ?>,    900);

    /* ── Heatmap theme switcher ───────────────────────────────────── */
    function applyHeatmapTheme() {
        var dark = document.body.getAttribute('data-theme') === 'dark';
        document.querySelectorAll('.ec-heatmap-cell, .ec-heatmap-legend-cell').forEach(function(el) {
            var c = dark ? el.dataset.dark : el.dataset.light;
            if (c) el.style.background = c;
        });
    }
    applyHeatmapTheme();
    window.addEventListener('ec-theme-change', applyHeatmapTheme);
    new MutationObserver(function(muts) {
        muts.forEach(function(m) {
            if (m.attributeName === 'data-theme') applyHeatmapTheme();
        });
    }).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });

    /* ── Chart.js — theme-aware helpers ───────────────────────────── */
    function chartTheme() {
        var dark = document.body.getAttribute('data-theme') === 'dark';
        return {
            tick:   dark ? '#cbd5e1' : '#64748b',
            grid:   dark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)',
            legend: dark ? '#e2e8f0' : '#334155',
            border: dark ? '#0f172a' : '#fff',
            tooltipBg: dark ? '#1e293b' : '#fff',
            tooltipFg: dark ? '#f1f5f9' : '#0f172a',
        };
    }

    var trendChart = null;
    var typeDonut  = null;

    /* ── Trend chart (12-week bar) ────────────────────────────────── */
    var trendCanvas = document.getElementById('trendChart');
    var trendLabels = <?= json_encode($trend_labels) ?>;
    var trendValues = <?= json_encode($trend_values) ?>;
    if (trendCanvas && trendValues.length > 0 && window.Chart) {
        function buildTrend() {
            var th = chartTheme();
            if (trendChart) trendChart.destroy();
            var ctx = trendCanvas.getContext('2d');
            var grad = ctx.createLinearGradient(0, 0, 0, 200);
            grad.addColorStop(0, 'rgba(46,158,99,0.85)');
            grad.addColorStop(1, 'rgba(46,158,99,0.25)');
            trendChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'การจอง',
                        data: trendValues,
                        backgroundColor: grad,
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 28,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: th.tooltipBg,
                            titleColor: th.tooltipFg,
                            bodyColor: th.tooltipFg,
                            borderColor: th.grid,
                            borderWidth: 1,
                            padding: 10,
                            displayColors: false,
                            callbacks: {
                                label: function(c) { return c.parsed.y.toLocaleString() + ' คิว'; }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { color: th.tick, font: { size: 10, weight: 600 } }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: th.grid, drawBorder: false },
                            ticks: { color: th.tick, font: { size: 10 }, precision: 0 }
                        }
                    }
                }
            });
        }
        buildTrend();
        window.addEventListener('ec-theme-change', buildTrend);
        new MutationObserver(function(muts) {
            muts.forEach(function(m) { if (m.attributeName === 'data-theme') buildTrend(); });
        }).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });
    }

    /* ── Type donut ────────────────────────────────────────────────── */
    var donutCanvas = document.getElementById('typeDonut');
    var typeRows = <?= json_encode($type_rows) ?>;
    if (donutCanvas && typeRows.length > 0 && window.Chart) {
        var typeLabels = {
            'vaccine':      'วัคซีน',
            'training':     'อบรม',
            'health_check': 'ตรวจสุขภาพ',
            'health':       'ตรวจสุขภาพ',
            'other':        'อื่นๆ'
        };
        var typeColors = {
            'vaccine':      '#2e9e63',
            'training':     '#6366f1',
            'health_check': '#0ea5e9',
            'health':       '#0ea5e9',
            'other':        '#94a3b8'
        };
        function buildDonut() {
            var th = chartTheme();
            if (typeDonut) typeDonut.destroy();
            typeDonut = new Chart(donutCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: typeRows.map(function(r) { return typeLabels[r.type] || r.type; }),
                    datasets: [{
                        data: typeRows.map(function(r) { return parseInt(r.cnt, 10) || 0; }),
                        backgroundColor: typeRows.map(function(r) { return typeColors[r.type] || '#94a3b8'; }),
                        borderColor: th.border,
                        borderWidth: 3,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: th.legend,
                                font: { size: 11, weight: 600 },
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 12,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: th.tooltipBg,
                            titleColor: th.tooltipFg,
                            bodyColor: th.tooltipFg,
                            borderColor: th.grid,
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                label: function(c) { return c.label + ': ' + c.parsed + ' แคมเปญ'; }
                            }
                        }
                    }
                }
            });
        }
        buildDonut();
        window.addEventListener('ec-theme-change', buildDonut);
        new MutationObserver(function(muts) {
            muts.forEach(function(m) { if (m.attributeName === 'data-theme') buildDonut(); });
        }).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });
    }

    /* ── Live updater (visibility-aware) ──────────────────────────── */
    var isFetching = false;
    var abortController = null;
    var REFRESH_MS = 15000;

    function updateWithFlash(id, newVal) {
        var el = document.getElementById(id);
        if (!el) return;
        var currentVal = parseInt(el.textContent.replace(/[^0-9-]/g, ''), 10) || 0;
        if (currentVal !== parseInt(newVal, 10)) {
            el.classList.add('flash');
            setTimeout(function() {
                el.textContent = parseInt(newVal, 10).toLocaleString();
                el.classList.remove('flash');
            }, 200);
        }
    }

    function refreshDashboard() {
        if (document.hidden) return; // skip when tab not visible
        if (isFetching && abortController) abortController.abort();
        isFetching = true;
        abortController = new AbortController();
        fetch('./ajax/ajax_dashboard.php', {
            signal: abortController.signal,
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            isFetching = false;
            if (data.status !== 'success') return;
            updateWithFlash('stat-total',          data.stats.total);
            updateWithFlash('stat-bookings-today', data.stats.bookings_today);
            updateWithFlash('stat-new-users',      data.stats.new_users_7d);
            updateWithFlash('stat-pending',        data.stats.pending);
            var container = document.getElementById('popular-camp_list-container');
            if (container && data.popular_html && container.innerHTML.trim() !== data.popular_html.trim()) {
                container.innerHTML = data.popular_html;
            }
        })
        .catch(function(err) {
            isFetching = false;
            if (err.name === 'AbortError') return;
            if (err instanceof TypeError && /failed to fetch|network/i.test(err.message)) return;
            console.error('Dashboard refresh failed:', err);
        });
    }

    setInterval(refreshDashboard, REFRESH_MS);
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) refreshDashboard();
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
