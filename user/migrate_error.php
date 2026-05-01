<?php
// user/migrate_error.php — แสดงผล error จาก migrate flow + ปุ่มลองใหม่/ข้าม
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

$reason = $_GET['reason'] ?? 'unknown';

// แมพ reason → ข้อความที่ user เข้าใจได้
$messages = [
    'consent_denied'   => ['title' => 'ยังไม่ได้ให้สิทธิ์',          'desc' => 'คุณยังไม่ได้อนุญาตให้แอปเข้าถึงข้อมูล LINE ของคุณ กรุณาลองใหม่และกด "อนุญาต"'],
    'no_code'          => ['title' => 'ไม่ได้รับข้อมูลจาก LINE',     'desc' => 'การเชื่อมต่อกับ LINE ขาดหาย กรุณาลองใหม่อีกครั้ง'],
    'state_mismatch'   => ['title' => 'เซสชันหมดอายุ',                'desc' => 'เพื่อความปลอดภัย กรุณาเริ่ม login ใหม่อีกครั้ง'],
    'token_network'    => ['title' => 'เชื่อมต่อ LINE ไม่ได้',        'desc' => 'เครือข่ายมีปัญหา กรุณาลองใหม่ในอีกสักครู่'],
    'token_failed'     => ['title' => 'ยืนยันตัวตนไม่สำเร็จ',          'desc' => 'ไม่สามารถยืนยันตัวตนกับ LINE ได้ กรุณาลองใหม่'],
    'profile_failed'   => ['title' => 'ดึงข้อมูล LINE ไม่ได้',         'desc' => 'ไม่สามารถดึงข้อมูลโปรไฟล์ของคุณจาก LINE ได้'],
    'uid_collision'    => ['title' => 'พบบัญชี LINE ซ้ำ',             'desc' => 'บัญชี LINE นี้ถูกผูกกับผู้ใช้อื่นในระบบแล้ว กรุณาติดต่อเจ้าหน้าที่เพื่อตรวจสอบ', 'showRetry' => false],
    'uid_inconsistent' => ['title' => 'ข้อมูลบัญชีไม่ตรง',            'desc' => 'พบความไม่สอดคล้องของข้อมูล LINE กรุณาติดต่อเจ้าหน้าที่',                  'showRetry' => false],
    'user_not_found'   => ['title' => 'ไม่พบบัญชีผู้ใช้',              'desc' => 'ไม่พบบัญชีของคุณในระบบ กรุณา login ใหม่อีกครั้ง',                          'showRetry' => false],
    'db_error'         => ['title' => 'ระบบขัดข้อง',                  'desc' => 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่ในภายหลัง'],
    'unknown'          => ['title' => 'เกิดข้อผิดพลาด',               'desc' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ กรุณาลองใหม่อีกครั้ง'],
];

$info     = $messages[$reason] ?? $messages['unknown'];
$showRetry = $info['showRetry'] ?? true;

// ปุ่มลองใหม่ → กลับไป migrate_login.php (ยัง session เก่ายังอยู่ถ้า state mismatch จะหลุดเอง)
$retryUrl = '../line_api/migrate_login.php';
// ปุ่มข้ามไปก่อน → ใช้ destination ที่ตั้งไว้ ถ้าไม่มีก็ hub
$skipUrl  = $_SESSION['migrate_final_dest'] ?? 'hub.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตระบบไม่สำเร็จ - RSU Medical</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8') : '../favicon.ico?v=' . APP_VERSION ?>">
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css?v=<?= APP_VERSION ?>" rel="stylesheet">
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background-color: #F8FAFF; }
    </style>
</head>
<body class="text-slate-900">
    <div class="max-w-md mx-auto min-h-screen flex items-center justify-center px-6 py-10">
        <div class="w-full bg-white rounded-[2.5rem] p-8 border border-slate-50 shadow-sm">
            <div class="flex flex-col items-center text-center">
                <div class="w-20 h-20 rounded-3xl bg-amber-50 flex items-center justify-center mb-6">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-3xl"></i>
                </div>
                <h1 class="text-xl font-black text-slate-900 mb-2"><?= htmlspecialchars($info['title']) ?></h1>
                <p class="text-sm text-slate-500 leading-relaxed mb-8"><?= htmlspecialchars($info['desc']) ?></p>

                <div class="w-full space-y-3">
                    <?php if ($showRetry): ?>
                        <a href="<?= htmlspecialchars($retryUrl) ?>"
                           class="block w-full h-14 bg-[#2e9e63] text-white font-black rounded-2xl flex items-center justify-center shadow-lg shadow-emerald-100 active:scale-95 transition-all">
                            <i class="fa-solid fa-arrows-rotate mr-2"></i> ลองใหม่อีกครั้ง
                        </a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($skipUrl) ?>"
                       class="block w-full h-14 bg-slate-50 text-slate-600 font-black rounded-2xl flex items-center justify-center border border-slate-100 active:scale-95 transition-all">
                        ข้ามไปก่อน
                    </a>
                </div>

                <p class="text-[10px] text-slate-300 mt-6 font-mono">รหัสข้อผิดพลาด: <?= htmlspecialchars($reason) ?></p>
            </div>
        </div>
    </div>
</body>
</html>
