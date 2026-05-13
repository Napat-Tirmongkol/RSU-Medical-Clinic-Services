<?php
/**
 * line_api/line_richmenu_helper.php
 *
 * LINE Rich Menu helpers — รองรับ per-user binding เพื่อสลับเมนูระหว่าง
 *  "guest" (ยังไม่ลงทะเบียน) กับ "member" (มี record ใน sys_users + line_user_id)
 *
 * Settings เก็บใน sys_site_settings (keys):
 *   - line.richmenu.guest_id
 *   - line.richmenu.member_id
 *
 * API endpoints ที่ใช้:
 *   - POST   /v2/bot/user/{userId}/richmenu/{richMenuId}   ผูกเมนูให้ user คนเดียว
 *   - DELETE /v2/bot/user/{userId}/richmenu                ปลดเมนูจาก user
 *   - POST   /v2/bot/user/all/richmenu/{richMenuId}        ตั้ง default ให้ทุกคน
 *   - DELETE /v2/bot/user/all/richmenu                     ลบ default
 *   - GET    /v2/bot/richmenu/list                         list richmenus ที่สร้างผ่าน API
 */
declare(strict_types=1);

require_once __DIR__ . '/line_config.php';

if (!function_exists('line_richmenu_token')) {
    function line_richmenu_token(): string
    {
        return defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN')
            ? (string)LINE_MESSAGING_CHANNEL_ACCESS_TOKEN
            : '';
    }
}

if (!function_exists('line_richmenu_api')) {
    /**
     * Low-level: เรียก LINE API
     *
     * Akamai edge ของ api.line.me รับ libcurl ดีกว่าถ้าใส่ HTTP/1.1 + UA
     * + trim token + ใช้ CURLOPT_POST=true (ไม่ใช้ CUSTOMREQUEST=POST
     * ที่ทำให้ HTTP request line ผิดเพี้ยน)
     *
     * @return array{http:int, body:string, error:?string}
     */
    function line_richmenu_api(string $method, string $path, ?string $jsonBody = null): array
    {
        $token = line_richmenu_token();
        if ($token === '') {
            return ['http' => 0, 'body' => '', 'error' => 'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ยังไม่ได้ตั้งค่า'];
        }
        $token = trim($token);
        $url = 'https://api.line.me' . $path;
        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT      => 'RSU-Clinic-LineRichMenu/1.0',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ];

        // Per method — ใช้ option เฉพาะแทน CUSTOMREQUEST เพื่อให้ Akamai เห็น
        // request line ที่สะอาด (e.g. "POST /path HTTP/1.1" ไม่มี oddities)
        $upper = strtoupper($method);
        if ($upper === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $jsonBody ?? ''; // ถ้าไม่มี body ใส่ empty string (ไม่ใช่ null)
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Length: ' . strlen($jsonBody ?? '');
        } elseif ($upper === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } elseif ($upper === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $upper;
            if ($jsonBody !== null) $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        }

        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        return ['http' => $http, 'body' => $body, 'error' => $err];
    }
}

if (!function_exists('line_richmenu_validate_id_format')) {
    /**
     * ตรวจรูปแบบ richMenuId ตามที่ LINE กำหนด: "richmenu-" + 32 hex chars
     * คืนค่า true ถ้าผ่าน หรือ string ว่าง (ใช้แทน "ลบค่าตั้งเดิม" ได้)
     */
    function line_richmenu_validate_id_format(string $id): bool
    {
        if ($id === '') return true; // อนุญาตค่าว่าง = clear
        return (bool)preg_match('/^richmenu-[a-f0-9]{32}$/', $id);
    }
}

if (!function_exists('line_richmenu_verify_id_exists')) {
    /**
     * เรียก LINE API ดู detail ของ richMenuId เพื่อยืนยันว่าใช้ได้จริง
     * (ใช้ตรวจก่อน save เพื่อกัน admin พิมพ์ผิดหรือใส่ ID ของ channel อื่น)
     *
     * @return array{ok:bool, http:int, error:?string, name:?string}
     */
    function line_richmenu_verify_id_exists(string $id): array
    {
        if ($id === '') return ['ok' => true, 'http' => 0, 'error' => null, 'name' => null];
        $r = line_richmenu_get_detail($id);
        if (!$r['ok']) {
            $err = (string)($r['error'] ?? '');
            // "owned by another channel" = LINE บอกว่า ID มีอยู่จริงแต่อยู่คนละ channel
            // เราถือว่า "ใช้ link ไม่ได้" — ต้องเตือน
            $reason = stripos($err, 'another channel') !== false
                ? 'rich menu นี้อยู่ภายใต้ channel อื่น — link ให้ user ไม่ได้'
                : ($r['http'] === 404 ? 'ไม่พบ richMenuId นี้บน LINE' : $err);
            return ['ok' => false, 'http' => $r['http'], 'error' => $reason, 'name' => null];
        }
        return [
            'ok'    => true,
            'http'  => $r['http'],
            'error' => null,
            'name'  => $r['data']['name'] ?? null,
        ];
    }
}

if (!function_exists('line_richmenu_audit_log')) {
    /**
     * เก็บประวัติการ link/unlink rich menu ต่อ user
     * - ทำ table ขึ้นเองถ้ายังไม่มี (lazy migration)
     * - ใส่ try/catch silent — log fail ไม่ควรทำให้ business flow fail
     */
    function line_richmenu_audit_log(string $lineUserId, string $action, string $state = '', string $richMenuId = '', ?string $error = null, string $source = ''): void
    {
        try {
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_line_richmenu_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_user_id VARCHAR(64) NOT NULL,
                action VARCHAR(20) NOT NULL,
                state VARCHAR(20) NOT NULL DEFAULT '',
                rich_menu_id VARCHAR(80) NOT NULL DEFAULT '',
                source VARCHAR(40) NOT NULL DEFAULT '',
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_line_user (line_user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $pdo->prepare("INSERT INTO sys_line_richmenu_audit
                (line_user_id, action, state, rich_menu_id, source, error_message)
                VALUES (:uid, :act, :state, :rid, :src, :err)");
            $stmt->execute([
                ':uid'   => $lineUserId,
                ':act'   => $action,
                ':state' => $state,
                ':rid'   => $richMenuId,
                ':src'   => $source,
                ':err'   => $error,
            ]);
        } catch (Throwable $e) {
            error_log('[line_richmenu_audit_log] ' . $e->getMessage());
        }
    }
}

if (!function_exists('line_richmenu_get_ids')) {
    /**
     * ดึง richMenuId ทั้ง 2 ค่าจาก sys_site_settings
     *
     * @return array{guest:string, member:string}
     */
    function line_richmenu_get_ids(): array
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM sys_site_settings
                                   WHERE setting_key IN ('line.richmenu.guest_id', 'line.richmenu.member_id')");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            return [
                'guest'  => (string)($rows['line.richmenu.guest_id']  ?? ''),
                'member' => (string)($rows['line.richmenu.member_id'] ?? ''),
            ];
        } catch (Throwable $e) {
            error_log('[line_richmenu_get_ids] ' . $e->getMessage());
            return ['guest' => '', 'member' => ''];
        }
    }
}

if (!function_exists('line_richmenu_save_ids')) {
    /**
     * บันทึก richMenuId ลง sys_site_settings (ผ่าน upsert)
     */
    function line_richmenu_save_ids(string $guestId, string $memberId): bool
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO sys_site_settings (setting_key, setting_value)
                                   VALUES (:k, :v)
                                   ON DUPLICATE KEY UPDATE setting_value = :v2");
            $stmt->execute([':k' => 'line.richmenu.guest_id',  ':v' => $guestId,  ':v2' => $guestId]);
            $stmt->execute([':k' => 'line.richmenu.member_id', ':v' => $memberId, ':v2' => $memberId]);
            return true;
        } catch (Throwable $e) {
            error_log('[line_richmenu_save_ids] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('line_richmenu_link_user')) {
    /**
     * ผูก rich menu ให้ user คนเดียว
     *
     * @return array{ok:bool, http:int, error:?string}
     */
    function line_richmenu_link_user(string $lineUserId, string $richMenuId): array
    {
        if ($lineUserId === '' || $richMenuId === '') {
            return ['ok' => false, 'http' => 0, 'error' => 'lineUserId/richMenuId ว่าง'];
        }
        $r = line_richmenu_api('POST', "/v2/bot/user/$lineUserId/richmenu/$richMenuId");
        $ok = ($r['http'] >= 200 && $r['http'] < 300);
        return ['ok' => $ok, 'http' => $r['http'], 'error' => $ok ? null : ($r['body'] ?: $r['error'])];
    }
}

if (!function_exists('line_richmenu_unlink_user')) {
    function line_richmenu_unlink_user(string $lineUserId, string $source = ''): array
    {
        if ($lineUserId === '') return ['ok' => false, 'http' => 0, 'error' => 'lineUserId ว่าง'];
        $r = line_richmenu_api('DELETE', "/v2/bot/user/$lineUserId/richmenu");
        $ok = ($r['http'] >= 200 && $r['http'] < 300);
        $err = $ok ? null : ($r['body'] ?: $r['error']);
        line_richmenu_audit_log(
            $lineUserId,
            $ok ? 'unlink_ok' : 'unlink_failed',
            'unlinked',
            '',
            $err,
            $source
        );
        return ['ok' => $ok, 'http' => $r['http'], 'error' => $err];
    }
}

if (!function_exists('line_richmenu_set_default')) {
    /**
     * ตั้ง default rich menu สำหรับทุกคน (ผู้ที่ add friend ใหม่จะเห็นเมนูนี้)
     */
    function line_richmenu_set_default(string $richMenuId): array
    {
        if ($richMenuId === '') return ['ok' => false, 'http' => 0, 'error' => 'richMenuId ว่าง'];
        $r = line_richmenu_api('POST', "/v2/bot/user/all/richmenu/$richMenuId");
        $ok = ($r['http'] >= 200 && $r['http'] < 300);
        return ['ok' => $ok, 'http' => $r['http'], 'error' => $ok ? null : ($r['body'] ?: $r['error'])];
    }
}

if (!function_exists('line_richmenu_clear_default')) {
    function line_richmenu_clear_default(): array
    {
        $r = line_richmenu_api('DELETE', '/v2/bot/user/all/richmenu');
        $ok = ($r['http'] >= 200 && $r['http'] < 300);
        return ['ok' => $ok, 'http' => $r['http'], 'error' => $ok ? null : ($r['body'] ?: $r['error'])];
    }
}

if (!function_exists('line_richmenu_list')) {
    /**
     * ดึงรายการ rich menu ทั้งหมดที่สร้างผ่าน API (ไม่รวมที่สร้างใน Console)
     *
     * @return array{ok:bool, richmenus:array, error:?string}
     */
    function line_richmenu_list(): array
    {
        $r = line_richmenu_api('GET', '/v2/bot/richmenu/list');
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'richmenus' => [], 'error' => $r['body'] ?: $r['error']];
        }
        $data = json_decode($r['body'], true) ?: [];
        return ['ok' => true, 'richmenus' => $data['richmenus'] ?? [], 'error' => null];
    }
}

if (!function_exists('line_richmenu_create')) {
    /**
     * สร้าง rich menu ใหม่ผ่าน API
     * (ขั้นที่ 1 — ได้ richMenuId กลับมา ยังต้อง upload image ต่อด้วย line_richmenu_upload_image)
     *
     * @param array $config โครงสร้างของ rich menu (size, selected, name, chatBarText, areas)
     * @return array{ok:bool, richMenuId:?string, http:int, error:?string}
     */
    function line_richmenu_create(array $config): array
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $r = line_richmenu_api('POST', '/v2/bot/richmenu', $json);
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'richMenuId' => null, 'http' => $r['http'], 'error' => $r['body'] ?: $r['error']];
        }
        $data = json_decode($r['body'], true) ?: [];
        return ['ok' => true, 'richMenuId' => $data['richMenuId'] ?? null, 'http' => $r['http'], 'error' => null];
    }
}

if (!function_exists('line_richmenu_upload_image')) {
    /**
     * อัพโหลดรูปสำหรับ rich menu (ขั้นที่ 2 หลัง create)
     * ใช้ api-data.line.me (data plane) — ไม่ใช่ api.line.me ปกติ
     *
     * Multi-transport: ลอง curl ก่อน → ถ้า fail (Akamai Bad Request)
     * fallback ไป PHP stream wrapper → ถ้ายัง fail fallback ไป exec curl CLI
     *
     * @return array{ok:bool, http:int, error:?string, verbose:?string, transport:string}
     */
    function line_richmenu_upload_image(string $richMenuId, string $imagePath, string $mimeType = 'image/png'): array
    {
        $token = line_richmenu_token();
        if ($token === '') return ['ok' => false, 'http' => 0, 'error' => 'LINE token ยังไม่ได้ตั้งค่า', 'verbose' => null, 'transport' => 'none'];
        if (!file_exists($imagePath)) return ['ok' => false, 'http' => 0, 'error' => "ไม่พบไฟล์ภาพ: $imagePath", 'verbose' => null, 'transport' => 'none'];
        if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
            return ['ok' => false, 'http' => 0, 'error' => 'รองรับเฉพาะ image/png หรือ image/jpeg', 'verbose' => null, 'transport' => 'none'];
        }

        $imgData = @file_get_contents($imagePath);
        if ($imgData === false) return ['ok' => false, 'http' => 0, 'error' => 'อ่านไฟล์ภาพไม่ได้', 'verbose' => null, 'transport' => 'none'];

        $token = trim($token);
        $url = "https://api-data.line.me/v2/bot/richmenu/$richMenuId/content";
        $log = ''; // accumulated transport log

        // ── Transport 1: cURL ─────────────────────────────────────────
        $log .= "=== Transport 1: cURL (HTTP/1.1, raw POST body) ===\n";
        $verboseLog = fopen('php://temp', 'w+');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POSTFIELDS     => $imgData,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT      => 'RSU-Clinic-LineRichMenu/1.0',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . strlen($imgData),
            ],
            CURLOPT_VERBOSE        => true,
            CURLOPT_STDERR         => $verboseLog,
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        rewind($verboseLog);
        $verbose1 = stream_get_contents($verboseLog);
        fclose($verboseLog);
        curl_close($ch);
        $log .= "HTTP $http\n" . substr($verbose1, 0, 1500) . "\nresponse body (first 200): " . substr($body, 0, 200) . "\n\n";

        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'http' => $http, 'error' => null, 'verbose' => null, 'transport' => 'curl'];
        }

        // ── Transport 2: PHP stream wrapper ───────────────────────────
        $log .= "=== Transport 2: PHP stream wrapper (file_get_contents) ===\n";
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Authorization: Bearer $token\r\nContent-Type: $mimeType\r\nContent-Length: " . strlen($imgData) . "\r\nUser-Agent: RSU-Clinic-LineRichMenu/1.0\r\n",
                'content'       => $imgData,
                'ignore_errors' => true,
                'timeout'       => 60,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body2 = @file_get_contents($url, false, $ctx);
        $http2 = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $http2 = (int)$m[1];
                    break;
                }
            }
            $log .= "Response headers:\n" . implode("\n", $http_response_header) . "\n";
        }
        $log .= "HTTP $http2\nresponse body (first 200): " . substr((string)$body2, 0, 200) . "\n\n";

        if ($http2 >= 200 && $http2 < 300) {
            return ['ok' => true, 'http' => $http2, 'error' => null, 'verbose' => null, 'transport' => 'stream'];
        }

        // ── Transport 3: exec curl CLI ─────────────────────────────────
        $log .= "=== Transport 3: exec curl CLI ===\n";
        if (function_exists('exec') && !ini_get('safe_mode')) {
            $cmd = sprintf(
                'curl -sS -X POST %s -H %s -H %s --data-binary @%s -w "\nHTTP_CODE:%%{http_code}" 2>&1',
                escapeshellarg($url),
                escapeshellarg('Authorization: Bearer ' . $token),
                escapeshellarg('Content-Type: ' . $mimeType),
                escapeshellarg($imagePath)
            );
            $output = [];
            $rc = 0;
            @exec($cmd, $output, $rc);
            $outStr = implode("\n", $output);
            $http3 = 0;
            if (preg_match('/HTTP_CODE:(\d+)/', $outStr, $m)) {
                $http3 = (int)$m[1];
            }
            $log .= "exec rc=$rc, HTTP $http3\noutput (first 400): " . substr($outStr, 0, 400) . "\n";

            if ($http3 >= 200 && $http3 < 300) {
                return ['ok' => true, 'http' => $http3, 'error' => null, 'verbose' => null, 'transport' => 'exec'];
            }
            $http = $http3 ?: $http2 ?: $http;
        } else {
            $log .= "exec ไม่สามารถใช้ได้ (disabled หรือ safe_mode)\n";
        }

        // ทั้ง 3 transport fail
        error_log('[line_richmenu_upload_image] all transports failed: ' . substr($log, 0, 2000));
        return [
            'ok'        => false,
            'http'      => $http,
            'error'     => substr($body ?: $body2 ?: 'all transports failed', 0, 300),
            'verbose'   => substr($log, 0, 5000),
            'transport' => 'all-failed',
        ];
    }
}

if (!function_exists('line_richmenu_delete')) {
    /**
     * ลบ rich menu ตาม id
     */
    function line_richmenu_delete(string $richMenuId): array
    {
        if ($richMenuId === '') return ['ok' => false, 'http' => 0, 'error' => 'richMenuId ว่าง'];
        $r = line_richmenu_api('DELETE', "/v2/bot/richmenu/$richMenuId");
        $ok = ($r['http'] >= 200 && $r['http'] < 300);
        return ['ok' => $ok, 'http' => $r['http'], 'error' => $ok ? null : ($r['body'] ?: $r['error'])];
    }
}

if (!function_exists('line_richmenu_get_detail')) {
    /**
     * ดึงรายละเอียดของ rich menu (size, areas, name, chatBarText) ตาม id
     * ใช้ clone config มา fill ลง form สร้างใหม่ — กรณีอยากใช้ layout เดิม
     * แต่สร้างผ่าน Messaging API channel ของเรา
     *
     * หมายเหตุ: ถ้า rich menu owned by another channel API อาจ block
     *   - LINE บางครั้งอนุญาตให้ GET ดู detail ได้แม้คนละ channel
     *   - แต่ไม่อนุญาตให้ POST link หรือ delete
     */
    function line_richmenu_get_detail(string $richMenuId): array
    {
        if ($richMenuId === '') return ['ok' => false, 'data' => null, 'http' => 0, 'error' => 'richMenuId ว่าง'];
        $r = line_richmenu_api('GET', "/v2/bot/richmenu/$richMenuId");
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'data' => null, 'http' => $r['http'], 'error' => $r['body'] ?: $r['error']];
        }
        $data = json_decode($r['body'], true) ?: null;
        return ['ok' => true, 'data' => $data, 'http' => $r['http'], 'error' => null];
    }
}

if (!function_exists('line_richmenu_get_default')) {
    /**
     * ดึง richMenuId ของ default rich menu ปัจจุบัน
     * (ใช้ดู ID ของ rich menu ที่สร้างผ่าน Console ได้ — ถ้าตั้งเป็น default แล้ว)
     */
    function line_richmenu_get_default(): array
    {
        $r = line_richmenu_api('GET', '/v2/bot/user/all/richmenu');
        if ($r['http'] === 404) {
            return ['ok' => false, 'richMenuId' => null, 'http' => 404, 'error' => 'ยังไม่ได้ตั้ง default rich menu'];
        }
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'richMenuId' => null, 'http' => $r['http'], 'error' => $r['body'] ?: $r['error']];
        }
        $data = json_decode($r['body'], true) ?: [];
        return ['ok' => true, 'richMenuId' => $data['richMenuId'] ?? null, 'http' => $r['http'], 'error' => null];
    }
}

if (!function_exists('line_richmenu_get_user_linked')) {
    /**
     * ดึง richMenuId ที่ผูกกับ user คนหนึ่ง
     * (ใช้ดู ID ของ rich menu ที่ user เห็นจริง — ใช้ดู Console rich menu ที่
     * เห็นในมือถือของตัวเองได้)
     */
    function line_richmenu_get_user_linked(string $lineUserId): array
    {
        if ($lineUserId === '') return ['ok' => false, 'richMenuId' => null, 'http' => 0, 'error' => 'lineUserId ว่าง'];
        $r = line_richmenu_api('GET', "/v2/bot/user/$lineUserId/richmenu");
        if ($r['http'] === 404) {
            return ['ok' => false, 'richMenuId' => null, 'http' => 404, 'error' => 'user นี้ยังไม่มี rich menu ผูกอยู่ (จะใช้ default)'];
        }
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['ok' => false, 'richMenuId' => null, 'http' => $r['http'], 'error' => $r['body'] ?: $r['error']];
        }
        $data = json_decode($r['body'], true) ?: [];
        return ['ok' => true, 'richMenuId' => $data['richMenuId'] ?? null, 'http' => $r['http'], 'error' => null];
    }
}

if (!function_exists('line_richmenu_is_registered_user')) {
    /**
     * ตรวจว่า lineUserId นี้มี record ใน sys_users **และกรอก profile ครบ** หรือยัง
     * (รองรับทั้ง line_user_id เดิม และ line_user_id_new — เผื่อย้าย channel)
     *
     * "Registered" = มี record + full_name ไม่ว่าง (กันเคส partial row ที่
     * บางครั้งถูกสร้างก่อน save_profile.php — ไม่ควรถือว่าเป็น member)
     */
    function line_richmenu_is_registered_user(string $lineUserId): bool
    {
        if ($lineUserId === '') return false;
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM sys_users
                                   WHERE (line_user_id = :uid OR line_user_id_new = :uid2)
                                     AND full_name IS NOT NULL
                                     AND TRIM(full_name) <> ''
                                   LIMIT 1");
            $stmt->execute([':uid' => $lineUserId, ':uid2' => $lineUserId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[line_richmenu_is_registered_user] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('line_richmenu_sync_user')) {
    /**
     * Sync เมนูของ user คนเดียว: ถ้าเป็น member → link member menu, ไม่ใช่ → link guest menu
     * (เรียกหลัง: follow event / หลัง INSERT sys_users / login callback)
     *
     * @param string $lineUserId
     * @param bool|null $forceIsMember  null = auto detect จาก DB, true/false = override
     * @return array{ok:bool, state:string, error:?string}
     */
    function line_richmenu_sync_user(string $lineUserId, ?bool $forceIsMember = null, string $source = ''): array
    {
        if ($lineUserId === '') return ['ok' => false, 'state' => 'none', 'error' => 'lineUserId ว่าง'];

        $ids = line_richmenu_get_ids();
        $isMember = $forceIsMember ?? line_richmenu_is_registered_user($lineUserId);
        $targetId = $isMember ? $ids['member'] : $ids['guest'];
        $state    = $isMember ? 'member' : 'guest';

        if ($targetId === '') {
            $err = "ยังไม่ได้ตั้ง richMenuId สำหรับ $state";
            line_richmenu_audit_log($lineUserId, 'sync_failed', $state, '', $err, $source);
            return ['ok' => false, 'state' => $state, 'error' => $err];
        }
        $r = line_richmenu_link_user($lineUserId, $targetId);
        line_richmenu_audit_log(
            $lineUserId,
            $r['ok'] ? 'sync_ok' : 'sync_failed',
            $state,
            $targetId,
            $r['ok'] ? null : $r['error'],
            $source
        );
        return ['ok' => $r['ok'], 'state' => $state, 'error' => $r['error']];
    }
}
