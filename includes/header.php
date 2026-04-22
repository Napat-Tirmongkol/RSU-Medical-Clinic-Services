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
          * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'rsufont', sans-serif !important;
          }

          body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f4ec;
            background-image: radial-gradient(circle at 20% 20%, rgba(46, 158, 99, .1) 0, transparent 400px),
              radial-gradient(circle at 80% 80%, rgba(77, 201, 138, .07) 0, transparent 350px);
          }

          .card {
            background: #fff;
            border-radius: 28px;
            padding: 48px 36px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 12px 48px rgba(46, 158, 99, .12);
            border: 1.5px solid #c7e8d5;
          }

          .icon-wrap {
            width: 80px;
            height: 80px;
            border-radius: 24px;
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid #bbf7d0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            animation: spin 3s linear infinite;
          }

          @keyframes spin {

            0%,
            100% {
              transform: rotate(-5deg)
            }

            50% {
              transform: rotate(5deg)
            }
          }

          .icon-wrap i {
            font-size: 2rem;
            color: #2e9e63;
          }

          h1 {
            font-size: 1.4rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
          }

          p {
            font-size: .875rem;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 24px;
          }

          .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 99px;
            background: #f0fdf4;
            border: 1.5px solid #bbf7d0;
            font-size: .75rem;
            font-weight: 700;
            color: #16a34a;
          }

          .badge span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 1.5s ease infinite;
          }

          @keyframes pulse {

            0%,
            100% {
              opacity: 1
            }

            50% {
              opacity: .3
            }
          }

          .brand {
            margin-top: 32px;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: #94a3b8;
          }
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

// ── User Session & Data logic ────────────────────────────────────────────────
if ($isUserFolder && !in_array($currentPage, ['index.php', 'logout.php'])) {
  $lineUserId = $_SESSION['line_user_id'] ?? '';
  if ($lineUserId !== '') {
    try {
      require_once __DIR__ . '/../config/db_connect.php';
      $pdoCheck = db();

      // Safe auto-migration
      try {
        @$pdoCheck->exec("ALTER TABLE sys_users ADD COLUMN picture_url TEXT");
      } catch (Exception $e) { }

      $stmtCheck = $pdoCheck->prepare("SELECT prefix, full_name, student_personnel_id, citizen_id, phone_number, status, picture_url FROM sys_users WHERE line_user_id = :lid LIMIT 1");
      $stmtCheck->execute([':lid' => $lineUserId]);
      $uProf = $stmtCheck->fetch();

      // ── Profile Completion Check (Redirect) ──
      $redirectExcluded = ['profile.php', 'save_profile.php', 'consent.php'];
      if (!in_array($currentPage, $redirectExcluded)) {
          $isProfileIncomplete = (
              !$uProf ||
              trim((string)($uProf['full_name'] ?? '')) === '' ||
              trim((string)($uProf['citizen_id'] ?? '')) === '' ||
              trim((string)($uProf['phone_number'] ?? '')) === '' ||
              trim((string)($uProf['status'] ?? '')) === '' ||
              (($uProf['status'] ?? 'other') !== 'other' && trim((string)($uProf['student_personnel_id'] ?? '')) === '')
          );

          // [Safety Bypass] หากเป็นหน้า hub.php ให้ผ่านเข้ามาก่อน เพื่อเลิกการวนซ้ำ (Redirect Loop)
          if ($isProfileIncomplete && $currentPage !== 'hub.php') {
              header('Location: profile.php');
              exit;
          }
      }

      // Store global user data for header
      $GLOBALS['HEADER_USER_DATA'] = $uProf;

      // --- กำหนดสีธีมตามสถานะ ---
      $userStatus = $uProf['status'] ?? 'student';
      if ($userStatus === 'staff' || $userStatus === 'faculty') {
        // โทนสี Indigo สำหรับบุคลากร/อาจารย์
        $GLOBALS['THEME_COLOR'] = [
          'bg' => 'from-[#312e81] to-[#4338ca]', // Indigo 900 to 700
          'accent' => 'bg-white/10',
          'loader' => '#4338ca'
        ];
      } else {
        // โทนสี Blue เดิมสำหรับนักศึกษาและอื่นๆ
        $GLOBALS['THEME_COLOR'] = [
          'bg' => 'from-[#0052CC] to-[#0070f3]',
          'accent' => 'bg-white/15',
          'loader' => '#0052CC'
        ];
      }
    } catch (Exception $e) {
    }
  }
}



function render_header(string $title = 'E-Vax'): void
{
  global $isUserFolder, $currentPage, $excludedPages;
  $user = $GLOBALS['HEADER_USER_DATA'] ?? null;
  ?>
  <!doctype html>
  <html lang="th">

  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= !empty(SITE_LOGO) ? '../' . SITE_LOGO : '../favicon.ico' ?>">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
      .custom-scrollbar::-webkit-scrollbar {
        display: none;
      }

      .custom-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
      }

      @font-face {
        font-family: 'rsufont';
        src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype');
        font-weight: bold;
      }

      @font-face {
        font-family: 'rsufont';
        src: url('../assets/fonts/RSU_REGULAR.ttf') format('truetype');
        font-weight: normal;
      }

      * {
        font-family: 'rsufont', 'Prompt', sans-serif;
      }
    </style>
  </head>

  <body class="bg-[#f4f7fa] h-[100dvh] w-full overflow-hidden flex justify-center text-gray-900">
    <?php
    $tBg = $GLOBALS['THEME_COLOR']['bg'] ?? 'from-[#0052CC] to-[#0070f3]';
    $tLoader = $GLOBALS['THEME_COLOR']['loader'] ?? '#0052CC';
    $tAccent = $GLOBALS['THEME_COLOR']['accent'] ?? 'bg-white/15';
    ?>
    <div id="page-loader"
      class="fixed inset-0 z-[9999] bg-[#f4f7fa] flex flex-col items-center justify-center transition-opacity duration-300">
      <div class="relative w-16 h-16">
        <div class="absolute inset-0 rounded-full border-4 border-gray-100"></div>
        <div class="absolute inset-0 rounded-full border-4 border-[<?= $tLoader ?>] border-t-transparent animate-spin">
        </div>
      </div>
      <p class="mt-4 text-[<?= $tLoader ?>] font-semibold text-sm animate-pulse font-prompt">
        <?= htmlspecialchars(__('loading')) ?></p>
    </div>

    <main
      class="w-full md:max-w-2xl lg:max-w-4xl h-full bg-white shadow-xl relative overflow-y-auto overflow-x-hidden custom-scrollbar transition-all duration-300">
      
      <?php
      // --- Maintenance Announcement ---
      $_mFile = __DIR__ . '/../config/maintenance.json';
      $_mData = file_exists($_mFile) ? json_decode(file_get_contents($_mFile), true) : [];
      if (!empty($_mData['announcement_active']) && !empty($_mData['announcement_message'])): ?>
        <div style="background-color: #d97706 !important; color: #ffffff !important;" class="px-4 py-3 flex items-center gap-3 shadow-lg relative z-[70] border-b border-white/10">
            <div style="background-color: rgba(255,255,255,0.2) !important;" class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 shadow-inner">
                <i class="fa-solid fa-bullhorn text-sm" style="color: #ffffff !important;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p style="color: #ffffff !important; font-weight: 900 !important;" class="text-xs md:text-sm leading-tight drop-shadow-sm">
                    <?= htmlspecialchars($_mData['announcement_message']) ?>
                </p>
            </div>
        </div>
      <?php endif; ?>
      <?php if ($isUserFolder && $user && !in_array($currentPage, ['index.php', 'logout.php'])): ?>
        <?php
        $statusMap = ['student' => 'นักศึกษา', 'faculty' => 'อาจารย์', 'staff' => 'เจ้าหน้าที่', 'other' => 'บุคคลทั่วไป'];
        $statusLabel = $statusMap[$user['status'] ?? ''] ?? ($user['status'] ?? '');
        $displayName = ($user['prefix'] ?? '') . ($user['full_name'] ?? 'ผู้ใช้');
        ?>
        <!-- ── Global User Header ────────────────────────────────────────── -->
        <div
          class="sticky top-0 z-50 bg-gradient-to-br <?= $tBg ?> p-6 pb-11 flex-shrink-0 relative overflow-hidden transition-colors duration-500 shadow-lg shadow-blue-900/10">
          <!-- Ambient Light Effect -->
          <div class="absolute -right-10 -top-10 w-40 h-40 bg-white/10 rounded-full blur-3xl"></div>

          <div class="flex items-center justify-between mb-5 relative z-10">
            <div class="flex items-center gap-3">
              <?php if ($currentPage !== 'hub.php'): ?>
                <a href="javascript:history.length > 1 ? history.back() : location.href='hub.php'"
                  class="w-8 h-8 flex items-center justify-center bg-white/10 hover:bg-white/20 rounded-full text-white transition-all">
                  <i class="fa-solid fa-chevron-left text-sm"></i>
                </a>
              <?php endif; ?>
              <div class="inline-flex items-center gap-2 py-1.5 px-3 bg-white/5 rounded-full border border-white/10 backdrop-blur-sm shadow-sm">
                  <i class="fa-solid fa-heart-pulse text-[10px] text-white/40 animate-pulse"></i>
                  <span class="text-[10px] font-black uppercase tracking-[0.2em] text-white/80 leading-none">RSU Medical Hub</span>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <!-- Language Switcher -->
              <a href="<?= htmlspecialchars(lang_switch_url()) ?>"
                class="flex items-center gap-1.5 py-1.5 px-3 bg-white/10 hover:bg-white/20 border border-white/10 rounded-full text-[10px] font-black text-white transition-all">
                <i class="fa-solid fa-globe"></i> <?= htmlspecialchars(__('lang.switch')) ?>
              </a>
              <a href="logout.php"
                class="flex items-center gap-1.5 py-1.5 px-3 bg-white/10 hover:bg-white/20 border border-white/10 rounded-full text-[10px] font-black text-white transition-all">
                <i class="fa-solid fa-power-off"></i>
              </a>
            </div>
          </div>

          <div class="flex items-center gap-4 relative z-10">
            <?php
              $profilePic = $user['picture_url'] ?? $_SESSION['line_picture'] ?? '';
            ?>
            <div class="w-14 h-14 rounded-2xl bg-white/15 backdrop-blur-md flex items-center justify-center border-2 border-white/20 shadow-lg overflow-hidden">
              <?php if (!empty($profilePic)): ?>
                <img src="<?= htmlspecialchars($profilePic, ENT_QUOTES) ?>"
                     alt="Profile"
                     class="w-full h-full object-cover"
                     onerror="this.parentElement.innerHTML='<div class=\'w-full h-full bg-white/15 backdrop-blur-md flex items-center justify-center\'><i class=\'fa-solid fa-user text-2xl text-white\'></i></div>'">
              <?php else: ?>
                <i class="fa-solid fa-user text-2xl text-white"></i>
              <?php endif; ?>
            </div>
            <div class="flex-1">
              <h2 class="text-[17px] font-extrabold text-white leading-tight">สวัสดี, <?= htmlspecialchars($displayName) ?>
              </h2>
              <div class="flex items-center gap-1.5 mt-1.5">
                <span
                  class="inline-flex items-center gap-1.5 px-3 py-1 <?= $tAccent ?> border border-white/20 rounded-full text-[10px] font-black text-white uppercase tracking-wider shadow-sm backdrop-blur-md">
                  <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                  <?= htmlspecialchars($statusLabel) ?>
                </span>
                <?php if (!empty($user['student_personnel_id'])): ?>
                  <span class="text-[10px] font-bold text-white/60">• รหัส
                    <?= htmlspecialchars($user['student_personnel_id']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <!-- Pull-up content container indicator (handled by individual page margins usually) -->
      <?php endif; ?>
      <?php
}
