<?php
/**
 * tests/test_cancel_booking.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Diagnostic script: ทดสอบระบบ "User Cancel Booking" + Email ทุก Step
 * ⚠️  ใช้สำหรับ DEV/TEST เท่านั้น — ห้ามนำขึ้น Production
 *
 * วิธีรัน: http://localhost/e-campaignv2/tests/test_cancel_booking.php
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

// ─── Security Guard (token-based) ───────────────────────────────────────────
define('DIAG_TOKEN', 'rsu-diag-2026');
if (($_GET['token'] ?? '') !== DIAG_TOKEN) {
    http_response_code(403);
    die('Access denied — ต้องระบุ ?token=rsu-diag-2026');
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/mail_helper.php';

// ─── Result Collector ────────────────────────────────────────────────────────
$results = [];

function pass(string $label, string $detail = ''): array
{
    return ['status' => 'PASS', 'label' => $label, 'detail' => $detail];
}
function fail(string $label, string $detail = ''): array
{
    return ['status' => 'FAIL', 'label' => $label, 'detail' => $detail];
}
function warn(string $label, string $detail = ''): array
{
    return ['status' => 'WARN', 'label' => $label, 'detail' => $detail];
}
function info(string $label, string $detail = ''): array
{
    return ['status' => 'INFO', 'label' => $label, 'detail' => $detail];
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 1 — DB Connection
// ═════════════════════════════════════════════════════════════════════════════
try {
    $pdo = db();
    $results[] = pass('DB Connection', 'PDO เชื่อมต่อฐานข้อมูลสำเร็จ');
} catch (Exception $e) {
    $results[] = fail('DB Connection', $e->getMessage());
    // หยุดทดสอบถ้า DB ใช้ไม่ได้
    renderAndExit($results);
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 2 — ตรวจสอบตาราง camp_bookings มีอยู่และ schema ถูกต้อง
// ═════════════════════════════════════════════════════════════════════════════
try {
    $cols = $pdo->query("DESCRIBE camp_bookings")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id', 'student_id', 'campaign_id', 'slot_id', 'status'];
    $missing = array_diff($required, $cols);
    if (empty($missing)) {
        $results[] = pass('ตาราง camp_bookings', 'columns: ' . implode(', ', $cols));
    } else {
        $results[] = fail('ตาราง camp_bookings', 'Missing columns: ' . implode(', ', $missing));
    }
} catch (Exception $e) {
    $results[] = fail('ตาราง camp_bookings', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 3 — หา booking ที่สามารถ cancel ได้ (status = confirmed, วันที่ >= วันนี้)
// ═════════════════════════════════════════════════════════════════════════════
$sampleBooking = null;
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.student_id, b.status,
               u.email, u.full_name,
               c.title,
               s.slot_date, s.start_time, s.end_time
        FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_list c  ON b.campaign_id = c.id
        JOIN camp_slots s ON b.slot_id     = s.id
        WHERE b.status IN ('confirmed', 'booked')
          AND s.slot_date >= CURDATE()
          AND u.email IS NOT NULL
          AND u.email != ''
        ORDER BY b.id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $sampleBooking = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sampleBooking) {
        $results[] = pass(
            'พบ Booking ที่ทดสอบได้',
            "ID #{$sampleBooking['id']} | {$sampleBooking['full_name']} | {$sampleBooking['email']} | กิจกรรม: {$sampleBooking['title']} | วันที่: {$sampleBooking['slot_date']} | status: {$sampleBooking['status']}"
        );
    } else {
        $results[] = warn(
            'ไม่พบ Booking ที่ทดสอบได้',
            'ต้องมี booking ที่ status = confirmed/booked, วันที่ >= วันนี้ และมี email ผู้ใช้'
        );
    }
} catch (Exception $e) {
    $results[] = fail('Query Booking', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 4 — ตรวจสอบ cancel_booking.php logic (simulate UPDATE โดยไม่ commit จริง)
// ═════════════════════════════════════════════════════════════════════════════
if ($sampleBooking) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE camp_bookings
            SET status = 'cancelled'
            WHERE id = :aid AND student_id = :sid
        ");
        $ok = $stmt->execute([
            ':aid' => (int) $sampleBooking['id'],
            ':sid' => (int) $sampleBooking['student_id'],
        ]);
        $rowsAffected = $stmt->rowCount();

        $pdo->rollBack(); // ← rollback ทันที ไม่แก้ข้อมูลจริง

        if ($ok && $rowsAffected === 1) {
            $results[] = pass('UPDATE status = cancelled', "rowCount = {$rowsAffected} (Rolled back — ข้อมูลไม่ถูกเปลี่ยนจริง)");
        } else {
            $results[] = fail('UPDATE status = cancelled', "rows affected = {$rowsAffected}");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $results[] = fail('UPDATE status = cancelled', $e->getMessage());
    }
} else {
    $results[] = info('UPDATE Test', 'ข้ามเพราะไม่มี booking ตัวอย่าง');
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 5 — ตรวจสอบ SMTP Config
// ═════════════════════════════════════════════════════════════════════════════
$secrets = get_secrets();
$smtpHost = $secrets['SMTP_HOST'] ?? '';
$smtpUser = $secrets['SMTP_USER'] ?? '';
$smtpPass = $secrets['SMTP_PASS'] ?? '';

if (!empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
    $results[] = pass('SMTP Config', "Host: {$smtpHost} | Port: " . ($secrets['SMTP_PORT'] ?? 587) . " | From: " . ($secrets['SMTP_FROM_EMAIL'] ?? ''));
} else {
    $missing = [];
    if (empty($smtpHost))
        $missing[] = 'SMTP_HOST';
    if (empty($smtpUser))
        $missing[] = 'SMTP_USER';
    if (empty($smtpPass))
        $missing[] = 'SMTP_PASS';
    $results[] = fail('SMTP Config', 'ค่าว่างใน secrets.php: ' . implode(', ', $missing) . ' → ระบบจะ fallback เป็น php mail() ซึ่งส่งไม่ได้บน XAMPP');
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 6 — อ่าน email template สำหรับ cancelled_by_user
// ═════════════════════════════════════════════════════════════════════════════
try {
    $html = get_email_template(
        'ยกเลิกการจองแล้ว',
        'สวัสดีคุณทดสอบ การจองกิจกรรมต่อไปนี้ถูกยกเลิกตามคำขอของคุณเรียบร้อยแล้ว',
        [
            'กิจกรรม' => 'Test Campaign',
            'วันที่' => '16/04/2569',
            'เวลา' => '09:00 - 10:00',
        ],
        'cancel'
    );
    if (strlen($html) > 200 && str_contains($html, '#dc2626')) {
        $results[] = pass('Email Template (cancelled_by_user)', 'HTML ยาว ' . strlen($html) . ' chars | accent color #dc2626 ✓');
    } else {
        $results[] = warn('Email Template (cancelled_by_user)', 'HTML สั้นผิดปกติหรือไม่มี accent color: ' . substr($html, 0, 100));
    }
} catch (Throwable $e) {
    $results[] = fail('Email Template', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 7 — ส่งอีเมลจริง (ถ้ามี booking ตัวอย่างและมี SMTP config)
// ═════════════════════════════════════════════════════════════════════════════
$sendEmailTest = isset($_GET['send_email']) && $_GET['send_email'] === '1';

if ($sampleBooking && !empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
    if ($sendEmailTest) {
        $ok = notify_booking_status(
            $sampleBooking['email'],
            'cancelled_by_user',
            [
                'campaign_title' => $sampleBooking['title'] . ' [TEST - ไม่ใช่การยกเลิกจริง]',
                'date' => date('d/m/Y', strtotime($sampleBooking['slot_date'])),
                'time' => substr($sampleBooking['start_time'], 0, 5) . ' - ' . substr($sampleBooking['end_time'], 0, 5),
                'full_name' => $sampleBooking['full_name'],
            ]
        );
        if ($ok) {
            $results[] = pass('ส่ง Email จริง', "ส่งอีเมลไปยัง {$sampleBooking['email']} สำเร็จ ✉️");
        } else {
            $results[] = fail('ส่ง Email จริง', "ส่งอีเมลไปยัง {$sampleBooking['email']} ล้มเหลว — ดู error_log ของ PHP");
        }
    } else {
        $results[] = info(
            'ส่ง Email จริง (ยังไม่รัน)',
            "คลิก <a href='?send_email=1' style='color:#0052CC;font-weight:bold'>ส่งอีเมลทดสอบ</a> เพื่อส่งจริงไปยัง {$sampleBooking['email']}"
        );
    }
} elseif ($sampleBooking && (empty($smtpHost) || empty($smtpUser) || empty($smtpPass))) {
    $results[] = warn('ส่ง Email จริง', 'ข้ามเพราะ SMTP ยังไม่ได้ตั้งค่า → กรุณาใส่ค่าใน config/secrets.php');
} else {
    $results[] = info('ส่ง Email จริง', 'ข้ามเพราะไม่มี booking ตัวอย่าง');
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 8 — ตรวจสอบ missing full_name bug ใน cancel_booking.php
// ═════════════════════════════════════════════════════════════════════════════
$cancelSrc = file_get_contents(__DIR__ . '/../user/cancel_booking.php');
if (str_contains($cancelSrc, 'full_name') && str_contains($cancelSrc, 'notify_booking_status')) {
    $results[] = pass('full_name ใน notify_booking_status', 'ส่ง full_name ไปด้วยแล้ว — greeting จะมีชื่อ');
} elseif (str_contains($cancelSrc, 'notify_booking_status') && !str_contains($cancelSrc, 'full_name')) {
    $results[] = warn(
        'full_name ไม่ถูกส่งใน notify_booking_status',
        'cancel_booking.php ไม่ได้ดึง full_name หรือส่งไปใน $data → greeting จะเป็นแค่ "สวัสดี" ไม่มีชื่อ'
    );
}

// ─── Render ───────────────────────────────────────────────────────────────────
renderAndExit($results);

function renderAndExit(array $results): never
{
    $totalPass = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
    $totalFail = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
    $totalWarn = count(array_filter($results, fn($r) => $r['status'] === 'WARN'));
    $total = count($results);

    $statusColor = $totalFail > 0 ? '#dc2626' : ($totalWarn > 0 ? '#d97706' : '#059669');
    $statusText = $totalFail > 0 ? "❌ มีปัญหา {$totalFail} จาก {$total}" : ($totalWarn > 0 ? "⚠️ ผ่าน แต่มีคำเตือน {$totalWarn}" : "✅ ผ่านทั้งหมด {$total}/{$total}");
    ?>
    <!DOCTYPE html>
    <html lang="th">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>🧪 Cancel Booking — Diagnostic</title>
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Segoe UI', Tahoma, sans-serif;
                background: #f1f5f9;
                min-height: 100vh;
                padding: 24px 16px;
            }

            .container {
                max-width: 860px;
                margin: 0 auto;
            }

            .header {
                background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
                border-radius: 16px;
                padding: 28px 32px;
                margin-bottom: 20px;
                color: white;
            }

            .header h1 {
                font-size: 1.5rem;
                font-weight: 800;
                margin-bottom: 4px;
            }

            .header p {
                font-size: 0.85rem;
                opacity: 0.7;
            }

            .summary {
                display: flex;
                gap: 12px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .stat {
                flex: 1;
                min-width: 120px;
                background: white;
                border-radius: 12px;
                padding: 16px;
                text-align: center;
                box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
            }

            .stat .num {
                font-size: 2rem;
                font-weight: 900;
            }

            .stat .lbl {
                font-size: 0.75rem;
                color: #94a3b8;
                margin-top: 2px;
            }

            .result-card {
                background: white;
                border-radius: 12px;
                padding: 16px 20px;
                margin-bottom: 10px;
                display: flex;
                align-items: flex-start;
                gap: 14px;
                box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
                border-left: 4px solid transparent;
            }

            .result-card.PASS {
                border-color: #059669;
            }

            .result-card.FAIL {
                border-color: #dc2626;
                background: #fff5f5;
            }

            .result-card.WARN {
                border-color: #d97706;
                background: #fffbeb;
            }

            .result-card.INFO {
                border-color: #0052CC;
                background: #f0f7ff;
            }

            .badge {
                font-size: 0.65rem;
                font-weight: 800;
                padding: 3px 8px;
                border-radius: 20px;
                white-space: nowrap;
                flex-shrink: 0;
                margin-top: 2px;
                letter-spacing: .05em;
            }

            .PASS .badge {
                background: #d1fae5;
                color: #065f46;
            }

            .FAIL .badge {
                background: #fee2e2;
                color: #991b1b;
            }

            .WARN .badge {
                background: #fef3c7;
                color: #92400e;
            }

            .INFO .badge {
                background: #dbeafe;
                color: #1e40af;
            }

            .label {
                font-weight: 700;
                font-size: 0.9rem;
                color: #1e293b;
            }

            .detail {
                font-size: 0.78rem;
                color: #64748b;
                margin-top: 4px;
                line-height: 1.5;
                word-break: break-all;
            }

            .overall {
                background: white;
                border-radius: 12px;
                padding: 18px 24px;
                margin-bottom: 20px;
                text-align: center;
                border: 2px solid
                    <?= $statusColor ?>
                ;
            }

            .overall .text {
                font-size: 1.15rem;
                font-weight: 800;
                color:
                    <?= $statusColor ?>
                ;
            }

            .actions {
                text-align: center;
                margin-top: 8px;
            }

            .btn {
                display: inline-block;
                background: #0052CC;
                color: white;
                padding: 10px 22px;
                border-radius: 10px;
                font-weight: 700;
                font-size: 0.85rem;
                text-decoration: none;
                margin: 4px;
                transition: background .2s;
            }

            .btn:hover {
                background: #003d99;
            }

            .btn.secondary {
                background: #64748b;
            }

            .ts {
                font-size: 0.72rem;
                color: #94a3b8;
                text-align: right;
                margin-top: 16px;
            }
        </style>
    </head>

    <body>
        <div class="container">

            <div class="header">
                <h1>🧪 Cancel Booking — Diagnostic</h1>
                <p>ตรวจสอบระบบ User Cancel Booking + Email | <?= date('d/m/Y H:i:s') ?></p>
            </div>

            <div class="overall">
                <div class="text"><?= $statusText ?></div>
            </div>

            <div class="summary">
                <div class="stat">
                    <div class="num" style="color:#059669"><?= $totalPass ?></div>
                    <div class="lbl">PASS</div>
                </div>
                <div class="stat">
                    <div class="num" style="color:#dc2626"><?= $totalFail ?></div>
                    <div class="lbl">FAIL</div>
                </div>
                <div class="stat">
                    <div class="num" style="color:#d97706"><?= $totalWarn ?></div>
                    <div class="lbl">WARN</div>
                </div>
                <div class="stat">
                    <div class="num" style="color:#64748b"><?= $total ?></div>
                    <div class="lbl">ทั้งหมด</div>
                </div>
            </div>

            <?php foreach ($results as $i => $r): ?>
                <div class="result-card <?= $r['status'] ?>">
                    <span class="badge"><?= $r['status'] ?></span>
                    <div>
                        <div class="label"><?= ($i + 1) ?>. <?= htmlspecialchars($r['label']) ?></div>
                        <?php if ($r['detail']): ?>
                            <div class="detail"><?= $r['detail'] /* already safe or HTML */ ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="actions">
                <a href="?" class="btn secondary">🔄 รัน Diagnostic อีกครั้ง</a>
                <a href="../portal/index.php?section=smtp_settings" class="btn" target="_blank">⚙️ ตั้งค่า SMTP</a>
            </div>

            <div class="ts">Generated at <?= date('Y-m-d H:i:s') ?> | Server: <?= php_uname('n') ?> | PHP <?= PHP_VERSION ?>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}
