<?php
/**
 * includes/mail_helper.php
 * ระบบส่งอีเมลแจ้งเตือนการจอง — รองรับ SMTP (ไม่ต้องใช้ library ภายนอก)
 *
 * ── ตั้งค่า SMTP ใน config/secrets.php ──────────────────────────────────────
 * 'SMTP_HOST'       => 'smtp.gmail.com'      // หรือ SMTP ของมหาวิทยาลัย
 * 'SMTP_PORT'       => 587                   // 587=TLS, 465=SSL, 25=plain
 * 'SMTP_USER'       => 'your@email.com'
 * 'SMTP_PASS'       => 'your_app_password'
 * 'SMTP_FROM_EMAIL' => 'noreply@rsu.ac.th'
 * 'SMTP_FROM_NAME'  => 'RSU Medical Clinic'
 * ────────────────────────────────────────────────────────────────────────────
 */

if (!function_exists('get_secrets')) {
    function get_secrets(): array {
        $path = __DIR__ . '/../config/secrets.php';
        return file_exists($path) ? (require $path) : [];
    }
}

// ─── SMTP sender (ไม่ต้องใช้ library ภายนอก) ─────────────────────────────────
function smtp_send(string $to, string $subject, string $htmlBody, array $cfg): bool {
    $host    = $cfg['SMTP_HOST'];
    $port    = (int)($cfg['SMTP_PORT'] ?? 587);
    $user    = $cfg['SMTP_USER']       ?? '';
    $pass    = $cfg['SMTP_PASS']       ?? '';
    $from    = $cfg['SMTP_FROM_EMAIL'] ?? $user;
    $name    = $cfg['SMTP_FROM_NAME']  ?? 'RSU Medical Clinic';
    $timeout = 15;

    // เลือก transport
    $useSsl = ($port === 465);

    // สร้าง SSL context ล่วงหน้า — ปิด verify_peer เพราะ mail server ในองค์กรมักใช้ self-signed cert
    $sslCtx = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);

    $proto = $useSsl ? 'ssl' : 'tcp';
    $sock  = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $sslCtx);
    if (!$sock) {
        error_log("SMTP connect failed ({$host}:{$port}): {$errstr}");
        return false;
    }

    // อ่านทุกบรรทัดของ SMTP multi-line response (ดูที่อักษรตัวที่ 4: '-'=ยังมีต่อ, ' '=บรรทัดสุดท้าย)
    $readAll = function() use ($sock): string {
        $full = '';
        while (true) {
            $line = fgets($sock, 515);
            if ($line === false || $line === '') break;
            $full .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $full;
    };
    $send = function(string $cmd) use ($sock, $readAll): string {
        fwrite($sock, $cmd . "\r\n");
        return $readAll();
    };

    try {
        $readAll(); // 220 greeting

        // EHLO — อ่านทุกบรรทัดจนบรรทัดสุดท้าย (250 xxx ไม่มีขีด)
        $ehlo = $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

        // STARTTLS (port 587)
        if ($port === 587 && str_contains($ehlo, 'STARTTLS')) {
            $startTlsResp = $send("STARTTLS");
            if (!str_starts_with(trim($startTlsResp), '220')) {
                error_log("SMTP STARTTLS rejected: {$startTlsResp}");
                fclose($sock);
                return false;
            }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("SMTP STARTTLS crypto failed");
                fclose($sock);
                return false;
            }
            $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }

        // AUTH LOGIN
        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $authResp = $send(base64_encode($pass));
        if (!str_starts_with(trim($authResp), '235')) {
            error_log("SMTP auth failed: {$authResp}");
            fclose($sock);
            return false;
        }

        // MAIL FROM / RCPT TO / DATA
        $send("MAIL FROM:<{$from}>");
        $send("RCPT TO:<{$to}>");
        $send("DATA");

        // Headers + body
        $boundary = md5(uniqid((string)time(), true));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName    = '=?UTF-8?B?' . base64_encode($name)    . '?=';

        $message  = "From: {$encodedName} <{$from}>\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "Subject: {$encodedSubject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $message .= "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $message .= "--{$boundary}--\r\n";
        $message .= ".";

        $dataResp = $send($message);
        $send("QUIT");
        fclose($sock);

        if (!str_starts_with(trim($dataResp), '250')) {
            error_log("SMTP DATA error: {$dataResp}");
            return false;
        }
        return true;

    } catch (Throwable $e) {
        error_log("SMTP exception: " . $e->getMessage());
        @fclose($sock);
        return false;
    }
}

// ─── บันทึก log การส่งอีเมล ───────────────────────────────────────────────────
function log_email(string $to, string $subject, string $type, bool $ok, string $err = ''): void {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("
            INSERT INTO sys_email_logs (recipient, subject, type, status, error_msg)
            VALUES (:r, :s, :t, :st, :e)
        ");
        $stmt->execute([
            ':r'  => $to,
            ':s'  => mb_substr($subject, 0, 500),
            ':t'  => $type,
            ':st' => $ok ? 'sent' : 'failed',
            ':e'  => $ok ? null : mb_substr($err, 0, 500),
        ]);
    } catch (Throwable) {
        // log ล้มเหลว — ignore เพื่อไม่กระทบ flow หลัก
    }
}

// ─── ฟังก์ชันหลักสำหรับส่งอีเมล (พร้อม logging) ──────────────────────────────
function send_campaign_email(string $to, string $subject, string $body, string $type = ''): bool {
    if (empty($to)) return false;

    $secrets = get_secrets();
    $host    = $secrets['SMTP_HOST'] ?? '';
    $ok      = false;
    $errMsg  = '';

    // ถ้ามี SMTP config → ส่งผ่าน SMTP
    if (!empty($host) && !empty($secrets['SMTP_USER']) && !empty($secrets['SMTP_PASS'])) {
        // จับ error_log ชั่วคราวเพื่อดักข้อความ error
        $ok = smtp_send($to, $subject, $body, $secrets);
        if (!$ok) $errMsg = 'SMTP send failed — check PHP error_log for details';
    } else {
        // Fallback: php mail()
        $fromEmail = $secrets['SMTP_FROM_EMAIL'] ?? 'no-reply@rsu.ac.th';
        $fromName  = $secrets['SMTP_FROM_NAME']  ?? 'RSU Medical Clinic';
        $headers   = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$fromEmail}>",
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);
        $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        if (!$ok) $errMsg = 'php mail() returned false — sendmail not configured';
    }

    log_email($to, $subject, $type, $ok, $errMsg);
    return $ok;
}

// ─── HTML Email Template ──────────────────────────────────────────────────────
function get_email_template(string $title, string $message, array $details = [], string $type = 'info'): string {
    $accentColor = match($type) {
        'success'  => '#059669',
        'approved' => '#0052CC',
        'cancel'   => '#dc2626',
        'reminder' => '#d97706',
        default    => '#0052CC',
    };
    $iconEmoji = match($type) {
        'success'  => '✅',
        'approved' => '✅',
        'cancel'   => '❌',
        'reminder' => '⏰',
        default    => 'ℹ️',
    };

    $detailRows = '';
    foreach ($details as $label => $value) {
        $detailRows .= "
        <tr>
            <td style='padding:10px 14px;border-bottom:1px solid #f0f0f0;font-weight:700;color:#555;width:35%;white-space:nowrap'>{$label}</td>
            <td style='padding:10px 14px;border-bottom:1px solid #f0f0f0;color:#222'>{$value}</td>
        </tr>";
    }

    return <<<HTML
    <!DOCTYPE html>
    <html lang="th">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
    <body style="margin:0;padding:0;background:#f4f6fb;font-family:Tahoma,sans-serif">
        <div style="max-width:580px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">

            <!-- Header -->
            <div style="background:{$accentColor};padding:28px 32px;text-align:center">
                <div style="font-size:2.2rem;margin-bottom:8px">{$iconEmoji}</div>
                <div style="color:#fff;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;opacity:.8;margin-bottom:4px">RSU Medical Clinic Services</div>
                <h1 style="color:#fff;margin:0;font-size:1.35rem;font-weight:900">{$title}</h1>
            </div>

            <!-- Body -->
            <div style="padding:28px 32px">
                <p style="color:#444;line-height:1.7;margin:0 0 20px">{$message}</p>

                <!-- Details table -->
                <div style="border:1.5px solid #e8eef7;border-radius:12px;overflow:hidden;margin-bottom:24px">
                    <table style="width:100%;border-collapse:collapse;font-size:14px">
                        {$detailRows}
                    </table>
                </div>

                <p style="color:#888;font-size:13px;margin:0">ขอบคุณที่ใช้บริการของเรา หากมีข้อสงสัยกรุณาติดต่อเจ้าหน้าที่</p>
            </div>

            <!-- Footer -->
            <div style="background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #eef0f4">
                <p style="color:#aaa;font-size:11px;margin:0">© 2026 มหาวิทยาลัยรังสิต · คลินิกเวชกรรม</p>
                <p style="color:#bbb;font-size:11px;margin:4px 0 0">อีเมลนี้เป็นการแจ้งเตือนอัตโนมัติ กรุณาอย่าตอบกลับ</p>
            </div>
        </div>
    </body>
    </html>
    HTML;
}

// ─── Notification Types ───────────────────────────────────────────────────────
function notify_booking_status(string $to, string $type, array $data): bool {
    $title   = $data['campaign_title'] ?? '-';
    $date    = $data['date']           ?? '-';
    $time    = $data['time']           ?? '-';
    $name    = $data['full_name']      ?? '';

    $greeting = $name ? "สวัสดีคุณ {$name}" : "สวัสดี";

    $details = [
        'กิจกรรม' => $title,
        'วันที่'   => $date,
        'เวลา'    => $time,
    ];
    if (!empty($data['status_label'])) {
        $details['สถานะ'] = $data['status_label'];
    }

    switch ($type) {
        case 'confirmation':
            $subject = "ยืนยันการจองกิจกรรม: {$title}";
            $emailTitle = 'จองกิจกรรมสำเร็จ!';
            $message  = "{$greeting} ระบบได้รับการลงทะเบียนของคุณเรียบร้อยแล้ว กรุณาตรวจสอบรายละเอียดด้านล่าง";
            $tplType  = 'success';
            break;

        case 'approved':
            $subject = "การจองได้รับการอนุมัติ: {$title}";
            $emailTitle = 'การจองได้รับการอนุมัติแล้ว!';
            $message  = "{$greeting} เจ้าหน้าที่ได้อนุมัติคิวการจองของคุณเรียบร้อยแล้ว กรุณาเตรียมตัวเข้าร่วมตามวันและเวลาที่กำหนด";
            $tplType  = 'approved';
            break;

        case 'cancelled_by_user':
            $subject = "ยกเลิกการจอง: {$title}";
            $emailTitle = 'ยกเลิกการจองแล้ว';
            $message  = "{$greeting} การจองกิจกรรมต่อไปนี้ถูกยกเลิกตามคำขอของคุณเรียบร้อยแล้ว";
            $tplType  = 'cancel';
            break;

        case 'cancelled_by_admin':
            $subject = "แจ้งยกเลิกคิวกิจกรรม: {$title}";
            $emailTitle = 'ขออภัย — มีการยกเลิกคิวของคุณ';
            $message  = "{$greeting} เจ้าหน้าที่ขอยกเลิกคิวเดิมของคุณ เนื่องจากมีเหตุจำเป็น กรุณาเข้าสู่ระบบเพื่อจองรอบเวลาใหม่";
            $details['สถานะ'] = 'คิวถูกยกเลิก (กรุณาจองใหม่)';
            $tplType  = 'cancel';
            break;

        case 'reminder':
            $subject    = "⏰ แจ้งเตือน: นัดหมายพรุ่งนี้ — {$title}";
            $emailTitle = 'แจ้งเตือนนัดหมายพรุ่งนี้';
            $message    = "{$greeting} ขอเตือนว่าคุณมีนัดหมายกิจกรรม <strong>{$title}</strong> ในวันพรุ่งนี้ กรุณาเตรียมตัวและมาตามนัดหมายด้วยนะคะ";
            $tplType    = 'reminder';
            break;

        default:
            return false;
    }

    $body = get_email_template($emailTitle, $message, $details, $tplType);
    return send_campaign_email($to, $subject, $body, $type);
}
