<?php
/**
 * includes/access_denied_page.php
 * Render หน้า 403 (สิทธิ์ไม่เพียงพอ) แบบ premium UI สำหรับทุกโมดูล
 *
 * Usage:
 *   require_once __DIR__ . '/../../includes/access_denied_page.php';
 *   render_access_denied([
 *       'flag'       => 'access_asset',
 *       'module'     => 'Asset Inventory',
 *       'hub_url'    => '/portal/index.php',
 *       'logout_url' => '/portal/logout.php',
 *       'tailwind'   => '/assets/css/tailwind.min.css',
 *   ]);
 *   exit;  // function ออกเองด้วย exit เสมอ
 */

if (!function_exists('render_access_denied')) {
    function render_access_denied(array $opts = []): void {
        $flag      = $opts['flag']       ?? 'access';
        $module    = $opts['module']     ?? 'โมดูลนี้';
        $hubUrl    = $opts['hub_url']    ?? '/portal/index.php';
        $logoutUrl = $opts['logout_url'] ?? '/portal/logout.php';
        $tailwind  = $opts['tailwind']   ?? '/assets/css/tailwind.min.css';

        $flagSafe   = htmlspecialchars($flag, ENT_QUOTES);
        $moduleSafe = htmlspecialchars($module, ENT_QUOTES);
        $hubSafe    = htmlspecialchars($hubUrl, ENT_QUOTES);
        $logoutSafe = htmlspecialchars($logoutUrl, ENT_QUOTES);
        $cssSafe    = htmlspecialchars($tailwind, ENT_QUOTES);

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>403 — สิทธิ์ไม่เพียงพอ</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= $cssSafe ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { font-family: 'Sarabun', system-ui, sans-serif; box-sizing: border-box; }
    body {
        margin:0; min-height:100vh;
        background: radial-gradient(ellipse at top, #fff7ed 0%, #fef2f2 50%, #fef9c3 100%);
        display:flex; align-items:center; justify-content:center; padding:24px;
    }
    .ad-card {
        width: 100%; max-width: 520px;
        background: #fff;
        border-radius: 28px;
        box-shadow: 0 25px 50px -12px rgba(15, 23, 42, .15), 0 0 0 1px rgba(15, 23, 42, .04);
        overflow: hidden;
        animation: ad-rise .55s cubic-bezier(.16,1,.3,1);
    }
    @keyframes ad-rise {
        from { opacity:0; transform: translateY(18px); }
        to   { opacity:1; transform: translateY(0); }
    }
    .ad-banner {
        position: relative;
        padding: 48px 32px 28px;
        background: linear-gradient(135deg, #fff1f2 0%, #fef3c7 100%);
        text-align: center;
        overflow: hidden;
    }
    .ad-banner::before {
        content:""; position:absolute; inset:-40% -20% auto auto;
        width: 240px; height: 240px;
        background: radial-gradient(circle, rgba(244,63,94,.18) 0%, transparent 60%);
        pointer-events: none;
    }
    .ad-icon-wrap {
        position: relative;
        width: 88px; height: 88px;
        margin: 0 auto 20px;
        background: #fff;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 12px 28px -8px rgba(244, 63, 94, .35), inset 0 0 0 4px rgba(244, 63, 94, .12);
    }
    .ad-icon-wrap i {
        font-size: 36px;
        background: linear-gradient(135deg, #f43f5e, #d97706);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }
    .ad-icon-pulse {
        position: absolute; inset:-6px;
        border-radius: 50%;
        border: 2px dashed rgba(244, 63, 94, .35);
        animation: ad-spin 9s linear infinite;
    }
    @keyframes ad-spin { to { transform: rotate(360deg); } }
    .ad-title {
        font-size: 22px; font-weight: 900; color: #0f172a; margin: 0;
        letter-spacing: -0.01em;
    }
    .ad-sub {
        font-size: 13px; color: #475569; margin: 6px 0 0; font-weight: 600;
    }
    .ad-body { padding: 28px 32px; }
    .ad-row {
        display: flex; align-items: center; gap: 12px;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 10px;
    }
    .ad-row .ad-label {
        font-size: 10.5px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: .12em; min-width: 90px;
    }
    .ad-row .ad-value {
        font-size: 13.5px; font-weight: 700; color: #0f172a; word-break: break-all;
    }
    .ad-flag-chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 11px;
        background: #fff1f2; color: #be123c;
        border: 1px solid #fecdd3;
        border-radius: 999px;
        font-size: 11.5px; font-weight: 800; font-family: 'JetBrains Mono', ui-monospace, monospace;
    }
    .ad-help {
        margin-top: 14px; padding: 14px 16px;
        background: #fef9c3; border: 1.5px solid #fde047;
        border-radius: 14px;
        font-size: 12.5px; color: #713f12; font-weight: 600; line-height: 1.55;
        display: flex; gap: 10px;
    }
    .ad-help i { color: #ca8a04; flex-shrink: 0; margin-top: 2px; }
    .ad-actions {
        padding: 0 32px 32px;
        display: flex; gap: 10px;
    }
    .ad-btn {
        flex: 1;
        padding: 13px 16px;
        border-radius: 14px;
        border: none; cursor: pointer;
        font-size: 13.5px; font-weight: 800;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
        text-decoration: none;
    }
    .ad-btn-primary {
        background: linear-gradient(135deg, #4f46e5, #4338ca);
        color: #fff;
        box-shadow: 0 10px 22px -8px rgba(79, 70, 229, .55);
    }
    .ad-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 14px 28px -10px rgba(79, 70, 229, .6); }
    .ad-btn-ghost {
        background: #fff; color: #475569;
        border: 1.5px solid #e2e8f0;
    }
    .ad-btn-ghost:hover { background: #f8fafc; border-color: #cbd5e1; }
    .ad-foot {
        text-align: center; padding: 14px;
        font-size: 11px; color: #94a3b8; font-weight: 600;
        background: #fafbfc; border-top: 1px solid #f1f5f9;
        letter-spacing: .04em;
    }
    @media (max-width: 480px) {
        .ad-actions { flex-direction: column; }
        .ad-row { flex-direction: column; align-items: flex-start; }
    }
</style>
</head>
<body>
    <div class="ad-card" role="alert">
        <div class="ad-banner">
            <div class="ad-icon-wrap">
                <span class="ad-icon-pulse"></span>
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1 class="ad-title">สิทธิ์ไม่เพียงพอ</h1>
            <p class="ad-sub">บัญชีของคุณยังไม่ได้รับสิทธิ์เข้าถึงโมดูลนี้</p>
        </div>

        <div class="ad-body">
            <div class="ad-row">
                <span class="ad-label"><i class="fa-solid fa-cube" style="color:#7c3aed;margin-right:4px"></i> โมดูล</span>
                <span class="ad-value"><?= $moduleSafe ?></span>
            </div>
            <div class="ad-row">
                <span class="ad-label"><i class="fa-solid fa-key" style="color:#f43f5e;margin-right:4px"></i> Flag ที่ต้องขอ</span>
                <span class="ad-flag-chip"><i class="fa-solid fa-lock" style="font-size:9px"></i> <?= $flagSafe ?></span>
            </div>

            <div class="ad-help">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    โปรดติดต่อผู้ดูแลระบบ (Superadmin) เพื่อเปิดสิทธิ์ <code style="background:#fef3c7;padding:1px 6px;border-radius:6px;font-size:11.5px;font-family:'JetBrains Mono',monospace"><?= $flagSafe ?></code>
                    ผ่านหน้า <strong>Identity &amp; Governance</strong> ที่ Portal Admin
                </div>
            </div>
        </div>

        <div class="ad-actions">
            <a href="<?= $hubSafe ?>" class="ad-btn ad-btn-primary">
                <i class="fa-solid fa-house"></i> กลับสู่หน้าหลัก
            </a>
            <a href="<?= $logoutSafe ?>" class="ad-btn ad-btn-ghost">
                <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
            </a>
        </div>

        <div class="ad-foot">RSU MEDICAL CLINIC SERVICES · ERROR 403</div>
    </div>
</body>
</html><?php
        exit;
    }
}
