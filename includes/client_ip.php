<?php
/**
 * includes/client_ip.php
 *
 * Resolve the real client IP, accounting for trusted reverse proxies.
 *
 * Default behavior (no config): use REMOTE_ADDR เปล่า — ปลอดภัยเสมอ ไม่ trust
 * client-controlled header. ถ้า production deploy อยู่หลัง reverse proxy
 * (nginx, AWS ALB, Cloudflare, etc.) → ตั้ง 'TRUSTED_PROXIES' ใน secrets.php
 * เป็น CIDR list ของ proxy IPs แล้ว helper จะอ่าน forwarded headers ให้
 *
 * Trust chain:
 *   1. REMOTE_ADDR ต้องอยู่ใน TRUSTED_PROXIES list ก่อน → ถึงจะอ่าน header
 *   2. ลำดับ header ที่ trust: CF-Connecting-IP > X-Real-IP > X-Forwarded-For
 *   3. X-Forwarded-For: เดินจาก left-most หา IP แรกที่ "ไม่ใช่ proxy ที่ trust"
 *
 * Anti-spoofing: header เหล่านี้ client ส่งมาได้เอง — จึงต้องตรวจ REMOTE_ADDR
 * ก่อนเสมอว่าเป็น proxy ที่เราตั้งใจ trust จริง ๆ
 */
declare(strict_types=1);

if (!function_exists('get_real_client_ip')) {

    function get_real_client_ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        static $trustedProxies = null;
        if ($trustedProxies === null) {
            $trustedProxies = [];
            $secretsFile = __DIR__ . '/../config/secrets.php';
            if (is_file($secretsFile)) {
                $secrets = @include $secretsFile;
                if (is_array($secrets)
                    && !empty($secrets['TRUSTED_PROXIES'])
                    && is_array($secrets['TRUSTED_PROXIES'])) {
                    $trustedProxies = $secrets['TRUSTED_PROXIES'];
                }
            }
        }

        // ไม่ตั้ง trusted proxy → ใช้ REMOTE_ADDR เปล่า (safe default)
        if (empty($trustedProxies)) {
            return $remote;
        }

        // REMOTE_ADDR ไม่ใช่ trusted proxy → ถือว่าเป็น direct client, REMOTE_ADDR = real IP
        if (!ip_in_trusted_cidrs($remote, $trustedProxies)) {
            return $remote;
        }

        // Cloudflare (single hop, header trusted format)
        $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
            return $cfIp;
        }

        // nginx-style single hop
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        // X-Forwarded-For: เดิน left-most → หา IP แรกที่ valid + ไม่ใช่ trusted proxy
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $hops = array_map('trim', explode(',', $xff));
            foreach ($hops as $hop) {
                if ($hop === '' || filter_var($hop, FILTER_VALIDATE_IP) === false) continue;
                if (!ip_in_trusted_cidrs($hop, $trustedProxies)) {
                    return $hop;
                }
            }
        }

        // Forwarded headers ทั้งหมดเป็น trusted proxy หรือ invalid → fallback REMOTE_ADDR
        return $remote;
    }
}

if (!function_exists('ip_in_trusted_cidrs')) {

    function ip_in_trusted_cidrs(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (is_string($cidr) && $cidr !== '' && ip_in_cidr($ip, $cidr)) return true;
        }
        return false;
    }
}

if (!function_exists('ip_in_cidr')) {

    function ip_in_cidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        [$subnet, $maskStr] = explode('/', $cidr, 2);
        $mask = (int)$maskStr;

        // IPv6
        if (strpos($ip, ':') !== false || strpos($subnet, ':') !== false) {
            if ($mask < 0 || $mask > 128) return false;
            $ipBin = @inet_pton($ip);
            $subnetBin = @inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) return false;
            $bytes = intdiv($mask, 8);
            $bits  = $mask % 8;
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) return false;
            if ($bits === 0) return true;
            $maskByte = chr(0xff & (0xff << (8 - $bits)));
            return (ord($ipBin[$bytes]) & ord($maskByte)) === (ord($subnetBin[$bytes]) & ord($maskByte));
        }

        // IPv4
        if ($mask < 0 || $mask > 32) return false;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $maskLong = $mask === 0 ? 0 : ((-1 << (32 - $mask)) & 0xffffffff);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
