<?php
/**
 * cron/morning_brief.php
 * ──────────────────────────────────────────────────────────────────────────────
 * รัน Morning Brief delivery ทุกชั่วโมง — สำหรับ user ที่ตั้ง delivery_hour ตรงกับ
 * ชั่วโมงปัจจุบัน + มี channel เปิด (LINE หรือ Email) จะถูกส่ง brief ของวันนั้น
 *
 * Pattern: zhao + idempotent (ส่งครั้งเดียวต่อ user/วัน/channel ผ่าน
 * sys_morning_brief_delivery UNIQUE KEY uniq_brief_staff_chan)
 *
 * ── การตั้งค่า cron ──────────────────────────────────────────────────────────
 *  URL     : https://<host>/portal/../cron/morning_brief.php?token=YOUR_SECRET
 *  Schedule: ทุกชั่วโมง (0 * * * *)  — สคริปต์จะ filter user ที่ตั้ง delivery_hour
 *            ตรงกับชั่วโมงนั้น ๆ
 *
 *  Optional params:
 *    ?hour=8       ── ทดสอบ ส่งเฉพาะ user ที่ตั้ง 08:00
 *    ?dryrun=1     ── ไม่ยิงจริง · พิมพ์ payload ที่จะส่ง
 *    ?force=1      ── ส่งซ้ำได้แม้เคยส่งแล้ว
 *
 * ── เงื่อนไขก่อนใช้ ──────────────────────────────────────────────────────────
 *  1. LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ตั้งค่าไว้ (เหมือนกับ daily_report)
 *  2. user ใน sys_morning_brief_prefs ต้องมี line_user_id ที่ถูกต้อง
 *     (รูปแบบ Uxxxxxxxx) สำหรับ channel_line
 *  3. user ต้องมี email สำหรับ channel_email · ระบบใช้ PHP mail() เป็น fallback
 *     เริ่มต้น — ถ้ามี SMTP config แยกค่อยปรับตอนหลัง
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

define('CRON_SECRET_TOKEN', 'rsu_morning_brief_h9k3m2p4w');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(CRON_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Bangkok');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/line_helper.php';
require_once __DIR__ . '/../includes/morning_brief_delivery.php';
require_once __DIR__ . '/../portal/queries/morning_brief_queries.php';
require_once __DIR__ . '/../portal/services/morning_brief_ai.php';

// ─── Params ─────────────────────────────────────────────────────────────────
$dryrun = !empty($_GET['dryrun']);
$force  = !empty($_GET['force']);
$hour   = isset($_GET['hour']) ? (int)$_GET['hour'] : (int)date('G');

$today = date('Y-m-d');
$startedAt = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] morning_brief cron — hour={$hour}" . ($dryrun ? ' (DRY RUN)' : '') . "\n";

$pdo = db();
ensure_morning_brief_schema($pdo);

// ─── Check clinic calendar — ถ้าหยุด จะ skip user ที่ตั้ง respect_clinic_calendar=1 ──
$clinicStatus = morning_brief_clinic_is_closed($pdo, $today);
if ($clinicStatus['closed'] && !$force) {
    echo "🏥 คลินิกหยุดวันนี้ (" . $clinicStatus['source']
        . ($clinicStatus['note'] ? ' · ' . $clinicStatus['note'] : '') . ")\n";
    echo "  → จะ skip user ที่ตั้ง respect_clinic_calendar=1 (ส่งเฉพาะคนที่ปิด option นี้)\n";
    echo "  → ใช้ ?force=1 เพื่อบังคับส่งทุกคน\n\n";
} elseif ($clinicStatus['closed'] && $force) {
    echo "🏥 คลินิกหยุดวันนี้ แต่ ?force=1 → จะส่งทุกคน\n\n";
}

// ─── Resolve LINE token ─────────────────────────────────────────────────────
$lineToken = mb_resolve_line_token();

// ─── Ensure brief exists for today ──────────────────────────────────────────
$brief = morning_brief_get_for_date($pdo, $today);
if (!$brief) {
    echo "  → brief สำหรับ {$today} ยังไม่มี · กำลังสร้าง...\n";
    $data = morning_brief_collect_all($pdo, $today);
    $narrative = morning_brief_generate_narrative($data);
    $data['_ai_priorities'] = $narrative['priorities'] ?? [];
    morning_brief_save($pdo, $today, $data,
        $narrative['narrative'] ?? null, $narrative['model'] ?? null,
        'cron', $narrative['urgency'] ?? 'normal');
    $brief = morning_brief_get_for_date($pdo, $today);
    echo "  → สร้างแล้ว · model=" . ($narrative['model'] ?? 'none') . "\n";
}
$briefId = (int)$brief['id'];
$briefData = $brief['data'] ?? [];
$priorities = $briefData['_ai_priorities'] ?? [];

// ─── Find users to deliver to ───────────────────────────────────────────────
$st = $pdo->prepare("SELECT * FROM sys_morning_brief_prefs
    WHERE delivery_hour = :h AND (channel_line = 1 OR channel_email = 1)");
$st->execute([':h' => $hour]);
$prefs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "พบ " . count($prefs) . " user(s) ที่ตั้ง delivery_hour=" . sprintf('%02d:00', $hour) . "\n\n";

$sent = ['line' => 0, 'email' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($prefs as $pref) {
    $sid = (int)$pref['staff_id'];
    $stype = $pref['staff_type'];
    echo "[staff_id={$sid} type={$stype}]\n";

    // ── Skip ถ้าคลินิกปิด + user ตั้ง respect_clinic_calendar=1 ──
    if ($clinicStatus['closed'] && !empty($pref['respect_clinic_calendar']) && !$force) {
        echo "  → SKIPPED ทั้ง user นี้ (คลินิกหยุด + ตั้ง respect_clinic_calendar)\n";
        $sent['skipped']++;
        continue;
    }

    // ── LINE channel ─────────────────────────────────────────────────────
    if (!empty($pref['channel_line']) && !empty($pref['line_user_id'])) {
        if (!$force && mb_already_delivered($pdo, $briefId, $sid, $stype, 'line')) {
            echo "  LINE → SKIPPED (already delivered)\n";
            $sent['skipped']++;
        } elseif (!$lineToken) {
            echo "  LINE → FAILED (no token)\n";
            $sent['failed']++;
            mb_log_delivery($pdo, $briefId, $sid, $stype, 'line', 'failed', 'no_token');
        } else {
            $flex = mb_build_line_flex($brief, $priorities);
            if ($dryrun) {
                echo "  LINE → DRY RUN (would push to {$pref['line_user_id']})\n";
                echo "    payload: " . json_encode($flex, JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                $ok = send_line_push($pref['line_user_id'], [$flex], $lineToken);
                if ($ok) {
                    echo "  LINE → SENT to {$pref['line_user_id']}\n";
                    $sent['line']++;
                    mb_log_delivery($pdo, $briefId, $sid, $stype, 'line', 'sent', null);
                } else {
                    echo "  LINE → FAILED · " . get_last_line_error() . "\n";
                    $sent['failed']++;
                    mb_log_delivery($pdo, $briefId, $sid, $stype, 'line', 'failed', get_last_line_error());
                }
            }
        }
    }

    // ── Email channel ────────────────────────────────────────────────────
    if (!empty($pref['channel_email']) && !empty($pref['email'])) {
        if (!$force && mb_already_delivered($pdo, $briefId, $sid, $stype, 'email')) {
            echo "  EMAIL → SKIPPED (already delivered)\n";
            $sent['skipped']++;
        } else {
            $subject = 'Morning Brief — ' . ($briefData['clinic']['date_thai'] ?? $today);
            $body = mb_build_email_html($brief, $priorities);
            if ($dryrun) {
                echo "  EMAIL → DRY RUN (would send to {$pref['email']})\n";
            } else {
                $ok = mb_send_email($pref['email'], $subject, $body);
                if ($ok) {
                    echo "  EMAIL → SENT to {$pref['email']}\n";
                    $sent['email']++;
                    mb_log_delivery($pdo, $briefId, $sid, $stype, 'email', 'sent', null);
                } else {
                    echo "  EMAIL → FAILED · mail() returned false\n";
                    $sent['failed']++;
                    mb_log_delivery($pdo, $briefId, $sid, $stype, 'email', 'failed', 'mail_failed');
                }
            }
        }
    }
}

$elapsed = number_format(microtime(true) - $startedAt, 2);
echo "\n=== สรุป ===\n";
echo "LINE sent  : {$sent['line']}\n";
echo "Email sent : {$sent['email']}\n";
echo "Skipped    : {$sent['skipped']}\n";
echo "Failed     : {$sent['failed']}\n";
echo "เวลา       : {$elapsed}s\n";

