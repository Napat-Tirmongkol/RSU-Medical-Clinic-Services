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

$currentPage = basename($_SERVER['PHP_SELF']);
$excludedPages = ['profile.php', 'save_profile.php', 'index.php', 'logout.php', 'consent.php'];
$isUserFolder = (strpos($_SERVER['REQUEST_URI'], '/user/') !== false);

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
<script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
      
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
      
      <style>
        .custom-scrollbar::-webkit-scrollbar { display: none; }
        .custom-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
      </style>
    </head>
    
    <body class="bg-[#f4f7fa] h-[100dvh] w-full overflow-hidden flex justify-center text-gray-900 font-prompt">
      
      <div id="page-loader" class="fixed inset-0 z-[9999] bg-[#f4f7fa] flex flex-col items-center justify-center transition-opacity duration-300">
        <div class="relative w-16 h-16">
          <div class="absolute inset-0 rounded-full border-4 border-blue-100"></div>
          <div class="absolute inset-0 rounded-full border-4 border-[#0052CC] border-t-transparent animate-spin"></div>
        </div>
        <p class="mt-4 text-[#0052CC] font-semibold text-sm animate-pulse font-prompt">กำลังโหลดข้อมูล...</p>
      </div>
      
      <main class="w-full max-w-md h-full bg-white shadow-xl relative overflow-y-auto overflow-x-hidden custom-scrollbar">

        <?php if ($isUserFolder && !in_array($currentPage, ['index.php', 'profile.php', 'save_profile.php', 'consent.php', 'logout.php'])): ?>
        <div class="fixed top-3 right-3 z-50" style="right: max(12px, calc(50vw - 224px + 12px))">
          <button onclick="document.getElementById('logoutConfirm').classList.remove('hidden')"
            class="w-9 h-9 flex items-center justify-center rounded-full bg-white/80 backdrop-blur-sm border border-gray-200 text-gray-400 hover:text-red-500 hover:border-red-200 hover:bg-red-50 shadow-sm transition-all"
            title="ออกจากระบบ">
            <i class="fa-solid fa-right-from-bracket text-sm"></i>
          </button>
        </div>

        <!-- Logout confirmation overlay -->
        <div id="logoutConfirm" class="hidden fixed inset-0 z-[200] bg-black/40 backdrop-blur-sm flex items-end justify-center p-4"
          onclick="if(event.target===this) this.classList.add('hidden')">
          <div class="w-full max-w-md bg-white rounded-3xl p-6 shadow-2xl animate-in slide-in-from-bottom-4 duration-300">
            <div class="flex items-center gap-3 mb-4">
              <div class="w-11 h-11 bg-red-100 rounded-2xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-right-from-bracket text-red-500 text-lg"></i>
              </div>
              <div>
                <p class="font-black text-gray-900 text-base">ออกจากระบบ?</p>
                <p class="text-xs text-gray-500 mt-0.5">คุณจะต้อง Login ผ่าน LINE ใหม่อีกครั้ง</p>
              </div>
            </div>
            <div class="flex gap-3 mt-5">
              <button onclick="document.getElementById('logoutConfirm').classList.add('hidden')"
                class="flex-1 py-3 border-2 border-gray-200 rounded-2xl font-bold text-gray-600 hover:bg-gray-50 transition-all text-sm">
                ยกเลิก
              </button>
              <a href="logout.php"
                class="flex-1 py-3 bg-red-500 hover:bg-red-600 text-white rounded-2xl font-bold text-center transition-all text-sm shadow-md shadow-red-200 active:scale-[0.98]">
                ออกจากระบบ
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>
  <?php
}
