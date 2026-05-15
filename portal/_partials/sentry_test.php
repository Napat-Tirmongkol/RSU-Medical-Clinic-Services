<?php
// portal/_partials/sentry_test.php — included by portal/index.php
// config.php already loaded; Sentry SDK loaded via vendor/autoload.php

$_st_results = [];

// 1. DSN check
$_st_hasDsn = defined('SENTRY_BROWSER_KEY') && SENTRY_BROWSER_KEY !== '';
$_st_results[] = ['label'=>'DSN ถูกตั้งค่าใน secrets.php','pass'=>$_st_hasDsn,'detail'=>$_st_hasDsn?'Public key: '.SENTRY_BROWSER_KEY:'ไม่พบ SENTRY_DSN ใน config/secrets.php'];

// 2. Vendor
$_st_vendorExists = file_exists(__DIR__ . '/../../vendor/autoload.php');
$_st_results[] = ['label'=>'Composer vendor/ ถูกติดตั้ง','pass'=>$_st_vendorExists,'detail'=>$_st_vendorExists?'vendor/autoload.php พบแล้ว':'ยังไม่ได้รัน composer install'];

// 3. SDK loaded
$_st_sdkLoaded = class_exists('\Sentry\SentrySdk');
$_st_results[] = ['label'=>'Sentry PHP SDK โหลดสำเร็จ','pass'=>$_st_sdkLoaded,'detail'=>$_st_sdkLoaded?'sentry/sentry loaded':'ไม่พบ class \\Sentry\\SentrySdk'];

// 4. Hub active
$_st_hubActive = false;
$_st_hubDetail = 'Sentry ไม่ได้ถูก initialize';
if ($_st_sdkLoaded) {
    try {
        $client = \Sentry\SentrySdk::getCurrentHub()->getClient();
        $_st_hubActive = $client !== null && $client->getOptions()->getDsn() !== null;
        $_st_hubDetail = $_st_hubActive
            ? 'Hub active — DSN: ' . preg_replace('/\/\/([^@]+)@/', '//***@', (string)$client->getOptions()->getDsn())
            : 'Client หรือ DSN เป็น null';
    } catch (Throwable $e) {
        $_st_hubDetail = 'Error: ' . $e->getMessage();
    }
}
$_st_results[] = ['label'=>'Sentry Hub active','pass'=>$_st_hubActive,'detail'=>$_st_hubDetail];

// 5 & 6. Send test events (only on POST)
$_st_eventId   = null;
$_st_exEventId = null;
$_st_sendDetail = $_st_hubActive ? 'กดปุ่ม "ส่ง Test Event" เพื่อทดสอบ' : 'Sentry ไม่ได้ถูก initialize';
$_st_exDetail   = $_st_sendDetail;

if ($_st_hubActive && isset($_POST['send_test'])) {
    try {
        $_st_eventId   = \Sentry\captureMessage('[TEST] RSU Healthcare Sentry integration test — ' . date('Y-m-d H:i:s'), \Sentry\Severity::info());
        $_st_sendDetail = $_st_eventId ? 'Event ID: '.(string)$_st_eventId : 'captureMessage() คืนค่า null';
    } catch (Throwable $e) { $_st_sendDetail = 'Error: '.$e->getMessage(); }
    try {
        $_st_exEventId = \Sentry\captureException(new RuntimeException('[TEST] RSU Healthcare Sentry exception test — ' . date('Y-m-d H:i:s')));
        $_st_exDetail  = $_st_exEventId ? 'Event ID: '.(string)$_st_exEventId : 'captureException() คืนค่า null';
    } catch (Throwable $e) { $_st_exDetail = 'Error: '.$e->getMessage(); }
}
$_st_results[] = ['label'=>'ส่ง Test Message ไปยัง Sentry','pass'=>$_st_eventId!==null,'detail'=>$_st_sendDetail,'action'=>true];
$_st_results[] = ['label'=>'ส่ง Test Exception ไปยัง Sentry','pass'=>$_st_exEventId!==null,'detail'=>$_st_exDetail,'action'=>true];

$_st_overallPass = $_st_hasDsn && $_st_vendorExists && $_st_sdkLoaded && $_st_hubActive;
?>
<style>
.st-row-pass{background:#f0fdf4;border-color:#bbf7d0}
.st-row-fail{background:#fef2f2;border-color:#fecaca}
.st-row-skip{background:#f9fafb;border-color:#e5e7eb}

/* ── Sentry Test — DARK MODE ─────────────────────────────── */
body[data-theme='dark'] #section-sentry_test .st-row-pass { background: rgba(16,185,129,.18); border-color: rgba(16,185,129,.30); }
body[data-theme='dark'] #section-sentry_test .st-row-fail { background: rgba(244,63,94,.18); border-color: rgba(244,63,94,.30); }
body[data-theme='dark'] #section-sentry_test .st-row-skip { background: rgba(148,163,184,.10); border-color:#1e293b; }
body[data-theme='dark'] #section-sentry_test .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-sentry_test .text-gray-900 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-sentry_test .text-gray-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-sentry_test .text-gray-500 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-sentry_test .border-gray-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-sentry_test .border-gray-100 { border-color:#1e293b !important; }
</style>

<div class="p-6">
    <div class="mb-6">
        <h2 class="text-2xl font-black text-gray-900 flex items-center gap-2">
            <i class="fa-brands fa-sentry" style="color:#362d59"></i> Sentry Connection Test
        </h2>
        <p class="text-xs text-gray-400 mt-1">ตรวจสอบการเชื่อมต่อ Sentry Error Monitoring</p>
    </div>

    <!-- Status Banner -->
    <div class="mb-6 flex items-center gap-4 p-4 rounded-2xl border-2"
         style="background:<?= $_st_overallPass?'#f0fdf4':'#fffbeb' ?>;border-color:<?= $_st_overallPass?'#86efac':'#fcd34d' ?>">
        <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0"
             style="background:<?= $_st_overallPass?'#dcfce7':'#fef3c7' ?>">
            <i class="fa-solid <?= $_st_overallPass?'fa-circle-check':'fa-triangle-exclamation' ?> text-xl"
               style="color:<?= $_st_overallPass?'#2e9e63':'#f59e0b' ?>"></i>
        </div>
        <div>
            <div class="font-black text-gray-900 text-lg"><?= $_st_overallPass?'Sentry เชื่อมต่อสำเร็จ':'ต้องการการตั้งค่าเพิ่มเติม' ?></div>
            <div class="text-sm text-gray-500 mt-0.5">
                <?= $_st_overallPass ? 'ระบบพร้อมรับ error reports แล้ว' : 'กรุณาตรวจสอบรายการที่ <span class="text-red-500 font-bold">ล้มเหลว</span>' ?>
            </div>
        </div>
    </div>

    <!-- Checklist -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-6 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-50">
            <h3 class="font-bold text-gray-900">ผลการตรวจสอบ</h3>
        </div>
        <div class="divide-y divide-gray-50">
            <?php foreach ($_st_results as $r):
                $isAction = $r['action'] ?? false;
                $rowCls   = $isAction && !isset($_POST['send_test']) ? 'st-row-skip' : ($r['pass'] ? 'st-row-pass' : 'st-row-fail');
                $icon     = $isAction && !isset($_POST['send_test']) ? 'fa-minus text-gray-400' : ($r['pass'] ? 'fa-circle-check text-green-500' : 'fa-circle-xmark text-red-500');
            ?>
            <div class="flex items-start gap-4 p-4 border <?= $rowCls ?>">
                <i class="fa-solid <?= $icon ?> text-base mt-0.5 shrink-0"></i>
                <div class="min-w-0 flex-1">
                    <div class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($r['label']) ?></div>
                    <div class="text-xs text-gray-500 mt-0.5 font-mono break-all"><?= htmlspecialchars($r['detail']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <form method="POST">
            <input type="hidden" name="send_test" value="1">
            <input type="hidden" name="section" value="sentry_test">
            <?php csrf_field(); ?>
            <button type="submit"
                class="w-full flex items-center justify-center gap-2 py-3 px-5 rounded-xl font-bold text-sm transition-all
                       <?= $_st_hubActive ? 'text-white hover:shadow-md' : 'bg-gray-100 text-gray-400 cursor-not-allowed' ?>"
                style="<?= $_st_hubActive ? 'background:#362d59' : '' ?>"
                <?= $_st_hubActive ? '' : 'disabled' ?>>
                <i class="fa-solid fa-paper-plane"></i> ส่ง Test Events ไปยัง Sentry
            </button>
            <?php if (!$_st_hubActive): ?>
            <p class="text-xs text-gray-400 text-center mt-2">ต้องตั้งค่า SENTRY_DSN ก่อน</p>
            <?php endif; ?>
        </form>
        <a href="https://sentry.io" target="_blank" rel="noopener"
           class="flex items-center justify-center gap-2 py-3 px-5 rounded-xl font-bold text-sm border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-all">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> เปิด Sentry Dashboard
        </a>
    </div>

    <?php if (isset($_POST['send_test']) && $_st_hubActive): ?>
    <div class="p-4 rounded-2xl border <?= ($_st_eventId || $_st_exEventId) ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?>">
        <div class="flex items-center gap-2 font-bold text-sm mb-2 <?= ($_st_eventId || $_st_exEventId) ? 'text-green-800' : 'text-red-700' ?>">
            <i class="fa-solid <?= ($_st_eventId || $_st_exEventId) ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
            <?= ($_st_eventId || $_st_exEventId) ? 'Events ถูกส่งสำเร็จ!' : 'ส่ง Events ไม่สำเร็จ' ?>
        </div>
        <?php if ($_st_eventId || $_st_exEventId): ?>
        <p class="text-xs text-green-700">ไปที่ <strong>sentry.io → Issues</strong> เพื่อดู events <code class="bg-green-100 px-1 rounded">[TEST] RSU Healthcare</code></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$_st_overallPass): ?>
    <div class="mt-6 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <h4 class="font-bold text-gray-900 mb-3 flex items-center gap-2">
            <i class="fa-solid fa-book" style="color:#362d59"></i> วิธีตั้งค่า
        </h4>
        <ol class="space-y-3 text-sm text-gray-700">
            <li class="flex gap-3"><span class="w-6 h-6 text-white rounded-full flex items-center justify-center text-xs font-black shrink-0" style="background:#362d59">1</span><span>สมัคร sentry.io → สร้าง Project (PHP)</span></li>
            <li class="flex gap-3"><span class="w-6 h-6 text-white rounded-full flex items-center justify-center text-xs font-black shrink-0" style="background:#362d59">2</span><span>Project → Settings → Client Keys (DSN) → คัดลอก DSN</span></li>
            <li class="flex gap-3"><span class="w-6 h-6 text-white rounded-full flex items-center justify-center text-xs font-black shrink-0" style="background:#362d59">3</span><span>เปิด <code class="bg-gray-100 px-1.5 py-0.5 rounded">config/secrets.php</code> แล้วใส่ <code class="bg-gray-100 px-1.5 py-0.5 rounded">'SENTRY_DSN' => '...'</code></span></li>
        </ol>
    </div>
    <?php endif; ?>
</div>
