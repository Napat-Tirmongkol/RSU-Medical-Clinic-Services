<?php
/**
 * api/dashboard_public.php
 *
 * Public read-only endpoint สำหรับ dashboard สาธารณะ
 * - ไม่ต้อง login / ไม่ต้อง CSRF
 * - คืนเฉพาะ widget ที่ is_visible=1 AND is_public=1
 * - Cache 5 นาที (Cache-Control header)
 * - Rate limit แบบ best-effort: 60 req / IP / นาที (file-based)
 *
 * Output:
 *   {
 *     "ok": true,
 *     "generated_at": "2026-05-10T12:34:56+07:00",
 *     "widgets": [
 *       { "id":1, "type":"kpi", "title":"...", "data": {...} }, ...
 *     ]
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/dashboard_data_sources.php';

// ── Rate limit (best-effort, file-based) ──────────────────────────────────────
function dashboard_rate_limit_check(string $ip, int $maxPerMin = 60): bool
{
    $dir = sys_get_temp_dir() . '/rsu_dashboard_rl';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $bucket = $dir . '/' . date('YmdHi') . '_' . md5($ip);
    $cnt = is_file($bucket) ? (int)file_get_contents($bucket) : 0;
    if ($cnt >= $maxPerMin) return false;
    @file_put_contents($bucket, (string)($cnt + 1));

    // Cleanup old buckets occasionally
    if (random_int(1, 100) === 1) {
        $cutoff = (int)date('YmdHi', time() - 3600);
        foreach (glob($dir . '/*') ?: [] as $f) {
            $base = (int)substr(basename($f), 0, 12);
            if ($base < $cutoff) @unlink($f);
        }
    }
    return true;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!dashboard_rate_limit_check($ip)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Too many requests — กรุณารอสักครู่']);
    exit;
}

// ── Filter params (year/month) ────────────────────────────────────────────────
$year  = isset($_GET['year'])  && ctype_digit((string)$_GET['year'])  ? (int)$_GET['year']  : null;
$month = isset($_GET['month']) && ctype_digit((string)$_GET['month']) ? (int)$_GET['month'] : null;
if ($year !== null && ($year < 2000 || $year > 3000)) $year = null;
if ($month !== null && ($month < 1 || $month > 12))   $month = null;
$filter = ['year' => $year, 'month' => $month];
$hasFilter = $year !== null || $month !== null;

// ── Workbook param (?wb=<slug>) ───────────────────────────────────────────────
$wbSlug = isset($_GET['wb']) ? trim((string)$_GET['wb']) : '';
$wbSlug = preg_replace('/[^a-z0-9_\-]/', '', strtolower($wbSlug));

// ── Build response ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
// Cache 30 วินาที — สั้นพอที่ override จะเด้งมาเร็ว แต่ยังป้องกัน hammer ได้
header('Cache-Control: public, max-age=' . ($hasFilter ? 30 : 30) . ', must-revalidate');
header('X-Content-Type-Options: nosniff');

// ETag ตาม latest update เพื่อให้ browser revalidate ได้
$pdo = db();
try {
    $etagSrc = $pdo->query("
        SELECT GREATEST(
            COALESCE((SELECT MAX(updated_at) FROM ins_kpi_overrides), '0'),
            COALESCE((SELECT MAX(updated_at) FROM ins_dashboard_widgets), '0'),
            COALESCE((SELECT MAX(updated_at) FROM ins_dashboard_workbooks), '0')
        ) AS t
    ")->fetchColumn();
    $etag = '"' . md5(($etagSrc ?: '0') . '|' . ($year ?? '') . '|' . ($month ?? '') . '|' . $wbSlug) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
} catch (PDOException $e) { /* tables may not exist yet */ }

$out = [
    'ok'           => true,
    'generated_at' => date('c'),
    'filter'       => ['year' => $year, 'month' => $month],
    'widgets'      => [],
];

try {
    // Available years สำหรับ populate dropdown
    $out['available_years'] = dashboard_available_years($pdo);

    // ── Resolve workbook ────────────────────────────────────────────────
    $wb = null;
    try {
        if ($wbSlug !== '') {
            $stmt = $pdo->prepare("SELECT * FROM ins_dashboard_workbooks WHERE slug = ? AND is_public = 1 LIMIT 1");
            $stmt->execute([$wbSlug]);
            $wb = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$wb) {
                http_response_code(404);
                $out['ok'] = false;
                $out['message'] = 'ไม่พบ workbook หรือไม่ได้เปิด public: ' . htmlspecialchars($wbSlug);
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            // ใช้ default workbook
            $wb = $pdo->query("SELECT * FROM ins_dashboard_workbooks WHERE is_default = 1 AND is_public = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$wb) {
                // fallback: เอา public ตัวแรก
                $wb = $pdo->query("SELECT * FROM ins_dashboard_workbooks WHERE is_public = 1 ORDER BY sort_order ASC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (PDOException $e) { /* table not migrated yet */ }

    $out['workbook'] = $wb ? [
        'id'          => (int)$wb['id'],
        'slug'        => $wb['slug'],
        'name'        => $wb['name'],
        'description' => $wb['description'],
        'icon'        => $wb['icon'],
        'color'       => $wb['color'],
    ] : null;

    // List ของ workbook public ทั้งหมด (ให้ frontend แสดง tabs)
    try {
        $out['public_workbooks'] = $pdo->query("
            SELECT id, slug, name, icon, color, is_default
            FROM ins_dashboard_workbooks
            WHERE is_public = 1
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) { $out['public_workbooks'] = []; }

    // Widgets เฉพาะ workbook นี้
    $widgetsSql = "SELECT id, widget_type, title, subtitle, data_source, color_theme, size, sort_order
                   FROM ins_dashboard_widgets
                   WHERE is_visible = 1 AND is_public = 1";
    $widgetsParams = [];
    if ($wb) {
        $widgetsSql .= " AND workbook_id = ?";
        $widgetsParams[] = (int)$wb['id'];
    }
    $widgetsSql .= " ORDER BY sort_order ASC, id ASC";

    $stmt = $pdo->prepare($widgetsSql);
    $stmt->execute($widgetsParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $w) {
        $data = dashboard_resolve_data($pdo, (string)$w['data_source'], $filter);
        $out['widgets'][] = [
            'id'          => (int)$w['id'],
            'type'        => $w['widget_type'],
            'title'       => $w['title'],
            'subtitle'    => $w['subtitle'],
            'color'       => $w['color_theme'],
            'size'        => $w['size'],
            'data_source' => $w['data_source'],
            'data'        => $data,
        ];
    }
} catch (Throwable $e) {
    error_log('[dashboard_public] ' . $e->getMessage());
    $out['ok'] = false;
    $out['message'] = 'ระบบขัดข้องชั่วคราว';
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
