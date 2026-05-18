<?php
http_response_code(404);

// IP-based rate limit BEFORE loading config.php — closes the 404-amplification
// DoS vector flagged in Phase 7 audit. An attacker spamming missing-resource
// URLs would otherwise trigger DB connect + log INSERT on every request.
// File-based bucket (independent of session) so blocked IPs can't bypass.
$__rl_dir = sys_get_temp_dir() . '/rsu_404_rl';
if (!is_dir($__rl_dir)) @mkdir($__rl_dir, 0755, true);
$__rl_ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$__rl_key = $__rl_dir . '/' . date('YmdHi') . '_' . md5($__rl_ip);
$__rl_cnt = is_file($__rl_key) ? (int)@file_get_contents($__rl_key) : 0;
$__rl_blocked = ($__rl_cnt >= 20); // 20 404s per minute per IP → stop logging
@file_put_contents($__rl_key, (string)($__rl_cnt + 1));

// Occasional cleanup of stale buckets
if (random_int(1, 100) === 1) {
    $cutoff = (int)date('YmdHi', time() - 3600);
    foreach (glob($__rl_dir . '/*') ?: [] as $f) {
        $base = (int)substr(basename($f), 0, 12);
        if ($base < $cutoff) @unlink($f);
    }
}

// Best-effort log to sys_error_logs (silently ignored if DB/config unavailable
// or if we're inside the rate-limit lockout).
try {
    if ($__rl_blocked) {
        // Skip DB connect entirely once IP is blacklisted for this minute.
        throw new RuntimeException('rate-limit-skip');
    }
    $configPath = __DIR__ . '/../config.php';
    if (is_readable($configPath)) {
        require_once __DIR__ . '/../includes/session_guard.php';
        if (function_exists('start_secure_session')) start_secure_session();
        require_once $configPath;

        if (function_exists('db')) {
            $pdo404 = db();
            $pdo404->exec("CREATE TABLE IF NOT EXISTS sys_error_logs (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
                source     VARCHAR(300)  NOT NULL DEFAULT '',
                message    TEXT          NOT NULL,
                context    TEXT          NOT NULL DEFAULT '',
                ip_address VARCHAR(45)   NOT NULL DEFAULT '',
                user_id    INT UNSIGNED  NULL,
                created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notified_at DATETIME     NULL DEFAULT NULL,
                status     ENUM('New', 'Active', 'Resolved') NOT NULL DEFAULT 'New',
                resolve_comment TEXT NULL,
                INDEX idx_level      (level),
                INDEX idx_created_at (created_at),
                INDEX idx_status     (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $reqUri  = $_SERVER['REQUEST_URI']     ?? '';
            $referer = $_SERVER['HTTP_REFERER']    ?? '';
            $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // Skip noisy/irrelevant 404s — favicons, robots, well-known probes
            $skipPatterns = ['/favicon.ico', '/robots.txt', '/.well-known/', '/apple-touch-icon'];
            $skip = false;
            foreach ($skipPatterns as $p) {
                if (stripos($reqUri, $p) !== false) { $skip = true; break; }
            }

            if (!$skip) {
                $ctx = json_encode([
                    'request_uri' => mb_substr($reqUri, 0, 500),
                    'method'      => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'referrer'    => mb_substr($referer, 0, 500),
                    'user_agent'  => mb_substr($ua, 0, 300),
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                $pdo404->prepare("
                    INSERT INTO sys_error_logs (level, source, message, context, ip_address, user_id)
                    VALUES ('warning', '[Apache] 404', :msg, :ctx, :ip, :uid)
                ")->execute([
                    ':msg' => '404 Not Found: ' . mb_substr($reqUri, 0, 400),
                    ':ctx' => $ctx,
                    ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':uid' => isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
                ]);
            }
        }
    }
} catch (Throwable $e) {
    // Suppress the rate-limit-skip sentinel from logs; log real errors only.
    if ($e->getMessage() !== 'rate-limit-skip') {
        error_log('404 log: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>404 — ไม่พบหน้าที่ต้องการ | RSU Medical Hub</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= defined('APP_VERSION') ? APP_VERSION : '1' ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_Regular.ttf') format('truetype'); }
        @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight:bold; }
        body { font-family:'RSU', sans-serif; background-color: #F8FAFF; }
        .premium-gradient { background: linear-gradient(135deg, #2e9e63 0%, #10b981 100%); }
        .glass-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.5); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6 bg-[#f0f4f9]">
    <div class="w-full max-w-md text-center animate-in zoom-in fade-in duration-700">
        <div class="glass-card rounded-[3.5rem] p-12 shadow-[0_30px_80px_rgba(0,0,0,0.06)] relative overflow-hidden">
            <!-- Decorative circles -->
            <div class="absolute -right-20 -top-20 w-48 h-48 bg-green-50 rounded-full blur-3xl opacity-30"></div>
            <div class="absolute -left-20 -bottom-20 w-48 h-48 bg-emerald-50 rounded-full blur-3xl opacity-30"></div>
            
            <div class="relative z-10">
                <div class="mb-6">
                    <span class="px-4 py-1.5 bg-green-50 text-green-600 rounded-full text-[10px] font-black uppercase tracking-[0.2em] border border-green-100">Error 404</span>
                </div>
                
                <h1 class="text-[8rem] font-black text-slate-100 leading-none mb-4 select-none tracking-tighter drop-shadow-sm">404</h1>
                
                <div class="w-20 h-20 premium-gradient rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-xl shadow-green-100 -mt-16 relative z-20">
                    <i class="fa-solid fa-compass text-white text-3xl animate-pulse"></i>
                </div>
                
                <h2 class="text-3xl font-black text-slate-900 mb-4 tracking-tight">ไม่พบหน้าที่ต้องการ</h2>
                <p class="text-slate-400 text-sm font-bold mb-10 leading-relaxed px-4">
                    ขออภัย ไม่พบหน้าที่คุณกำลังมองหา<br>
                    อาจถูกย้าย ลบออก หรือ URL ไม่ถูกต้องครับ
                </p>
                
                <div class="space-y-4">
                    <a href="javascript:history.back()" 
                       class="flex items-center justify-center gap-3 w-full py-5 premium-gradient text-white font-black rounded-2xl shadow-lg shadow-green-100 transition-all active:scale-95 text-sm uppercase tracking-wider">
                        <i class="fa-solid fa-arrow-left"></i>
                        กลับหน้าก่อนหน้า
                    </a>
                    
                    <a href="/e-campaignv2/user/hub.php" 
                       class="flex items-center justify-center gap-3 w-full py-5 bg-white text-slate-400 font-black rounded-2xl border border-slate-100 hover:bg-slate-50 transition-all active:scale-95 text-sm uppercase tracking-wider">
                        <i class="fa-solid fa-house"></i>
                        ไปหน้าหลัก
                    </a>
                </div>
                
                <div class="mt-10 pt-8 border-t border-slate-100 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-circle-info text-green-300 text-[10px]"></i>
                    <span class="text-slate-300 text-[10px] font-black uppercase tracking-widest">RSU Medical Clinic Services</span>
                </div>
            </div>
        </div>
        
        <p class="mt-8 text-slate-300 text-[10px] font-black uppercase tracking-[0.3em]">System Health: Online</p>
    </div>
</body>
</html>
