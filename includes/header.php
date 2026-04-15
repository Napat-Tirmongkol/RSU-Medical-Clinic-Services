<?php
declare(strict_types=1);

/**
 * Shared header (Tailwind CDN + Prompt)
 * Usage:
 * require_once __DIR__ . '/header.php';
 * render_header('Page Title');
 */

// --- เพิ่มระบบเช็คการกรอกข้อมูลโปรไฟล์ ---
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// ── Language system (e-Campaign pages only) ──────────────────────────────────
if (!function_exists('__')) {
    require_once __DIR__ . '/lang.php';
}

$currentPage = basename($_SERVER['PHP_SELF']);
$excludedPages = ['profile.php', 'save_profile.php', 'index.php', 'logout.php', 'consent.php'];
$isUserFolder = (strpos($_SERVER['REQUEST_URI'], '/user/') !== false);

// ── Maintenance Check ────────────────────────────────────────────────────────
if ($isUserFolder) {
    $_mFile = __DIR__ . '/../config/maintenance.json';
    if (file_exists($_mFile)) {
        $_mData = json_decode(file_get_contents($_mFile), true) ?? [];
        if (isset($_mData['e_campaign']) && $_mData['e_campaign'] === false) {
            // แสดงหน้าปรับปรุงแล้วหยุด
            http_response_code(503);
            ?><!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ระบบปรับปรุง — RSU Medical Clinic</title>
  <link rel="stylesheet" href="../assets/css/rsufont.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    *{box-sizing:border-box;margin:0;padding:0;font-family:'rsufont',sans-serif !important;}
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;
         background:#e8f4ec;background-image:radial-gradient(circle at 20% 20%,rgba(46,158,99,.1) 0,transparent 400px),
         radial-gradient(circle at 80% 80%,rgba(77,201,138,.07) 0,transparent 350px);}
    .card{background:#fff;border-radius:28px;padding:48px 36px;max-width:420px;width:90%;text-align:center;
          box-shadow:0 12px 48px rgba(46,158,99,.12);border:1.5px solid #c7e8d5;}
    .icon-wrap{width:80px;height:80px;border-radius:24px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);
               border:2px solid #bbf7d0;display:flex;align-items:center;justify-content:center;
               margin:0 auto 24px;animation:spin 3s linear infinite;}
    @keyframes spin{0%,100%{transform:rotate(-5deg)}50%{transform:rotate(5deg)}}
    .icon-wrap i{font-size:2rem;color:#2e9e63;}
    h1{font-size:1.4rem;font-weight:800;color:#0f172a;margin-bottom:8px;}
    p{font-size:.875rem;color:#64748b;line-height:1.6;margin-bottom:24px;}
    .badge{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:99px;
           background:#f0fdf4;border:1.5px solid #bbf7d0;font-size:.75rem;font-weight:700;color:#16a34a;}
    .badge span{width:8px;height:8px;border-radius:50%;background:#22c55e;animation:pulse 1.5s ease infinite;}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
    .brand{margin-top:32px;font-size:.7rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#94a3b8;}
  </style>
</head>
<body>
  <div class="card">
    <div class="icon-wrap"><i class="fa-solid fa-screwdriver-wrench"></i></div>
    <h1>ระบบอยู่ในขั้นตอนการปรับปรุง</h1>
    <p>ขออภัยในความไม่สะดวก<br>ทีมงานกำลังดำเนินการปรับปรุงระบบ<br>กรุณากลับมาใหม่ในภายหลัง</p>
    <div class="badge"><span></span> กำลังดำเนินการปรับปรุง</div>
    <div class="brand">RSU Medical Clinic Services</div>
  </div>
</body>
</html><?php
            exit;
        }
    }
}

if ($isUserFolder && !in_array($currentPage, $excludedPages)) {
    $lineUserId = $_SESSION['line_user_id'] ?? '';
    if ($lineUserId !== '') {
        try {
            require_once __DIR__ . '/../config/db_connect.php';
            $pdoCheck = db();
            $stmtCheck = $pdoCheck->prepare("SELECT full_name, student_personnel_id, citizen_id, phone_number, status FROM sys_users WHERE line_user_id = :lid LIMIT 1");
            $stmtCheck->execute([':lid' => $lineUserId]);
            $uProf = $stmtCheck->fetch();

            if (!$uProf || empty($uProf['full_name']) || empty($uProf['citizen_id']) || empty($uProf['phone_number']) || empty($uProf['status']) || ($uProf['status'] !== 'other' && empty($uProf['student_personnel_id']))) {
                header('Location: profile.php');
                exit;
            }
        } catch (Exception $e) {
            // ปล่อยผ่านถ้า DB มีปัญหา
        }
    }
}

function render_header(string $title = 'E-Vax'): void {
  global $isUserFolder;
  ?>
  <!doctype html>
  <html lang="th">
    <head>
      <meta charset="utf-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
      <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>

      <link rel="icon" href="data:,">

      <link rel="stylesheet" href="../assets/css/tailwind.min.css">
	  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
      <link rel="stylesheet" href="../assets/css/rsufont.css">
<script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
      
      <style>
        .custom-scrollbar::-webkit-scrollbar { display: none; }
        .custom-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
      </style>
      <?php if (defined('SENTRY_BROWSER_KEY') && SENTRY_BROWSER_KEY !== ''): ?>
      <script>window.sentryOnLoad = function() { Sentry.init({ tracesSampleRate: 0.1 }); };</script>
      <script src="https://js.sentry-cdn.com/<?= htmlspecialchars(SENTRY_BROWSER_KEY, ENT_QUOTES) ?>.min.js" crossorigin="anonymous" defer></script>
      <?php endif; ?>
    </head>
    
    <body class="bg-[#f4f7fa] h-[100dvh] w-full overflow-hidden flex justify-center text-gray-900">
      
      <div id="page-loader" class="fixed inset-0 z-[9999] bg-[#f4f7fa] flex flex-col items-center justify-center transition-opacity duration-300">
        <div class="relative w-16 h-16">
          <div class="absolute inset-0 rounded-full border-4 border-blue-100"></div>
          <div class="absolute inset-0 rounded-full border-4 border-[#0052CC] border-t-transparent animate-spin"></div>
        </div>
        <p class="mt-4 text-[#0052CC] font-semibold text-sm animate-pulse font-prompt"><?= htmlspecialchars(__('loading')) ?></p>
      </div>

      <main class="w-full max-w-md h-full bg-white shadow-xl relative overflow-y-auto overflow-x-hidden custom-scrollbar">

        <?php if ($isUserFolder): ?>
        <!-- Language Switcher -->
        <a href="<?= htmlspecialchars(lang_switch_url()) ?>"
           class="fixed top-3 right-3 z-[200] flex items-center gap-1.5 bg-white/90 backdrop-blur-sm border border-gray-200 rounded-full px-3 py-1.5 text-[11px] font-bold text-gray-500 hover:text-[#0052CC] hover:border-[#0052CC] shadow-sm transition-all select-none">
          <i class="fa-solid fa-globe text-[10px]"></i><?= htmlspecialchars(__('lang.switch')) ?>
        </a>
        <?php endif; ?>

  <?php
}
