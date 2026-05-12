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
     * @return array{http:int, body:string, error:?string}
     */
    function line_richmenu_api(string $method, string $path): array
    {
        $token = line_richmenu_token();
        if ($token === '') {
            return ['http' => 0, 'body' => '', 'error' => 'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ยังไม่ได้ตั้งค่า'];
        }
        $url = 'https://api.line.me' . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        return ['http' => $http, 'body' => $body, 'error' => $err];
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
    function line_richmenu_unlink_user(string $lineUserId): array
    {
        if ($lineUserId === '') return ['ok' => false, 'http' => 0, 'error' => 'lineUserId ว่าง'];
        $r = line_richmenu_api('DELETE', "/v2/bot/user/$lineUserId/richmenu");
        $ok = ($r['http'] >= 200 && $r['http'] < 300);
        return ['ok' => $ok, 'http' => $r['http'], 'error' => $ok ? null : ($r['body'] ?: $r['error'])];
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
        $token = line_richmenu_token();
        if ($token === '') return ['ok' => false, 'richMenuId' => null, 'http' => 0, 'error' => 'LINE token ยังไม่ได้ตั้งค่า'];

        $ch = curl_init('https://api.line.me/v2/bot/richmenu');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => json_encode($config, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);

        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'richMenuId' => null, 'http' => $http, 'error' => $body ?: $err];
        }
        $data = json_decode($body, true) ?: [];
        return ['ok' => true, 'richMenuId' => $data['richMenuId'] ?? null, 'http' => $http, 'error' => null];
    }
}

if (!function_exists('line_richmenu_upload_image')) {
    /**
     * อัพโหลดรูปสำหรับ rich menu (ขั้นที่ 2 หลัง create)
     * ใช้ api-data.line.me (data plane) — ไม่ใช่ api.line.me ปกติ
     *
     * @return array{ok:bool, http:int, error:?string}
     */
    function line_richmenu_upload_image(string $richMenuId, string $imagePath, string $mimeType = 'image/png'): array
    {
        $token = line_richmenu_token();
        if ($token === '') return ['ok' => false, 'http' => 0, 'error' => 'LINE token ยังไม่ได้ตั้งค่า'];
        if (!file_exists($imagePath)) return ['ok' => false, 'http' => 0, 'error' => "ไม่พบไฟล์ภาพ: $imagePath"];
        if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
            return ['ok' => false, 'http' => 0, 'error' => 'รองรับเฉพาะ image/png หรือ image/jpeg'];
        }

        $fh = fopen($imagePath, 'rb');
        if (!$fh) return ['ok' => false, 'http' => 0, 'error' => 'เปิดไฟล์ภาพไม่ได้'];
        $size = filesize($imagePath);

        $ch = curl_init("https://api-data.line.me/v2/bot/richmenu/$richMenuId/content");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . $size,
            ],
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        fclose($fh);

        $ok = ($http >= 200 && $http < 300);
        return ['ok' => $ok, 'http' => $http, 'error' => $ok ? null : ($body ?: $err)];
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
     * ตรวจว่า lineUserId นี้มี record ใน sys_users หรือยัง
     * (รองรับทั้ง line_user_id เดิม และ line_user_id_new — เผื่อย้าย channel)
     */
    function line_richmenu_is_registered_user(string $lineUserId): bool
    {
        if ($lineUserId === '') return false;
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM sys_users
                                   WHERE line_user_id = :uid OR line_user_id_new = :uid2
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
    function line_richmenu_sync_user(string $lineUserId, ?bool $forceIsMember = null): array
    {
        if ($lineUserId === '') return ['ok' => false, 'state' => 'none', 'error' => 'lineUserId ว่าง'];

        $ids = line_richmenu_get_ids();
        $isMember = $forceIsMember ?? line_richmenu_is_registered_user($lineUserId);
        $targetId = $isMember ? $ids['member'] : $ids['guest'];
        $state    = $isMember ? 'member' : 'guest';

        if ($targetId === '') {
            return ['ok' => false, 'state' => $state, 'error' => "ยังไม่ได้ตั้ง richMenuId สำหรับ $state"];
        }
        $r = line_richmenu_link_user($lineUserId, $targetId);
        return ['ok' => $r['ok'], 'state' => $state, 'error' => $r['error']];
    }
}
