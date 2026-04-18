<?php
/**
 * cron/send_error_digest.php
 * ส่ง Error Digest Email ให้ admin เมื่อมี error ใหม่ใน sys_error_logs
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/e-campaignv2/cron/send_error_digest.php?token=rsu_purge_a8f3k2m9x
 *  Schedule: ทุก 30 นาที
 *
 * ── ตั้งค่า ────────────────────────────────────────────────────────────────────
 *  secrets.php → 'ADMIN_ALERT_EMAIL' => 'admin@example.com'
 *  ถ้าว่างเปล่าจะไม่ส่ง
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

define('ERROR_DIGEST_TOKEN', 'rsu_purge_a8f3k2m9x');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(ERROR_DIGEST_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db_connect.php';
require_once $projectRoot . '/includes/mail_helper.php';

date_default_timezone_set('Asia/Bangkok');

$pdo = db();

// ── Read admin email: DB setting takes priority, fallback to secrets.php ──────
$adminEmail = '';
try {
    $row = $pdo->query("SELECT `value` FROM sys_settings WHERE `key` = 'admin_alert_email' LIMIT 1")->fetchColumn();
    $adminEmail = trim((string)($row ?: ''));
} catch (PDOException) { /* table may not exist yet */ }

if ($adminEmail === '') {
    $secrets    = get_secrets();
    $adminEmail = trim($secrets['ADMIN_ALERT_EMAIL'] ?? '');
}

if ($adminEmail === '') {
    echo "SKIP: ADMIN_ALERT_EMAIL not configured\n";
    exit;
}

// ── Auto-migrate: เพิ่มคอลัมน์ notified_at ถ้ายังไม่มี ────────────────────────
try {
    $pdo->exec("ALTER TABLE sys_error_logs ADD COLUMN notified_at DATETIME NULL DEFAULT NULL");
} catch (PDOException) { /* มีอยู่แล้ว */ }

// ── ดึง errors ที่ยังไม่ได้แจ้ง (level = error, notified_at IS NULL) ──────────
$stmt = $pdo->query("
    SELECT id, level, source, message, context, ip_address, user_id, created_at
    FROM sys_error_logs
    WHERE level = 'error'
      AND notified_at IS NULL
    ORDER BY created_at DESC
    LIMIT 50
");
$errors = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($errors)) {
    echo "OK: no new errors\n";
    exit;
}

$count = count($errors);
$now   = date('Y-m-d H:i:s');

// ── สร้าง HTML email ──────────────────────────────────────────────────────────
$rows = '';
foreach ($errors as $e) {
    $level   = htmlspecialchars($e['level']);
    $source  = htmlspecialchars($e['source'] ?: '-');
    $message = htmlspecialchars(mb_substr($e['message'], 0, 300));
    $time    = htmlspecialchars($e['created_at']);
    $userId  = $e['user_id'] ? '#' . $e['user_id'] : '-';
    $ip      = htmlspecialchars($e['ip_address'] ?: '-');

    $rows .= "
    <tr>
        <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;white-space:nowrap;color:#555;font-size:13px;'>{$time}</td>
        <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;'><span style='background:#fee2e2;color:#dc2626;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;'>{$level}</span></td>
        <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;font-family:monospace;font-size:12px;color:#666;'>{$source}</td>
        <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#1e293b;'>{$message}</td>
        <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;font-size:12px;color:#888;'>{$ip}</td>
        <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;font-size:12px;color:#888;'>{$userId}</td>
    </tr>";
}

$plural = $count > 1 ? 'errors' : 'error';
$body   = <<<HTML
<!DOCTYPE html>
<html lang="th">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Tahoma,sans-serif">
<div style="max-width:680px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:28px 32px;text-align:center">
        <div style="font-size:2rem;margin-bottom:8px">🚨</div>
        <div style="color:#fff;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;opacity:.8;margin-bottom:4px">RSU Medical Clinic Services</div>
        <h1 style="color:#fff;margin:0;font-size:1.25rem;font-weight:900">พบ {$count} {$plural} ใหม่ในระบบ</h1>
    </div>

    <!-- Body -->
    <div style="padding:24px 32px">
        <p style="color:#444;margin:0 0 20px;line-height:1.7">
            ระบบตรวจพบ <strong>{$count} error</strong> ที่ยังไม่ได้รับการแก้ไข กรุณาตรวจสอบรายละเอียดด้านล่าง
        </p>

        <div style="overflow-x:auto;border:1.5px solid #e8eef7;border-radius:12px;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:580px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #e8eef7;white-space:nowrap;">เวลา</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #e8eef7;">ระดับ</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #e8eef7;">Source</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #e8eef7;">ข้อความ</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #e8eef7;">IP</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:700;color:#555;border-bottom:2px solid #e8eef7;">User</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>

        <p style="color:#888;font-size:13px;margin:20px 0 0;">สามารถดูรายละเอียดเพิ่มเติมได้ที่ Portal → Error Logs</p>
    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #eef0f4">
        <p style="color:#aaa;font-size:11px;margin:0">© 2026 มหาวิทยาลัยรังสิต · คลินิกเวชกรรม</p>
        <p style="color:#bbb;font-size:11px;margin:4px 0 0">อีเมลนี้ส่งโดยอัตโนมัติเมื่อ {$now}</p>
    </div>
</div>
</body>
</html>
HTML;

// ── ส่งอีเมล ──────────────────────────────────────────────────────────────────
$subject = "🚨 [{$count} Error] RSU Medical Clinic — Error Digest " . date('d/m/Y H:i');
$ok      = send_campaign_email($adminEmail, $subject, $body, 'error_digest');

if ($ok) {
    // ── Mark as notified ───────────────────────────────────────────────────────
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $errors));
    $pdo->exec("UPDATE sys_error_logs SET notified_at = NOW() WHERE id IN ({$ids})");
    echo "OK: sent digest ({$count} errors) to {$adminEmail}\n";
} else {
    echo "FAILED: could not send email\n";
    http_response_code(500);
}
