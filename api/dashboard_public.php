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

// ── Build response ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5 minutes
header('X-Content-Type-Options: nosniff');

$pdo = db();
$out = [
    'ok'           => true,
    'generated_at' => date('c'),
    'widgets'      => [],
];

try {
    $rows = $pdo->query("
        SELECT id, widget_type, title, subtitle, data_source, color_theme, size, sort_order
        FROM ins_dashboard_widgets
        WHERE is_visible = 1 AND is_public = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $w) {
        $data = dashboard_resolve_data($pdo, (string)$w['data_source']);
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
