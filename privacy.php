<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>นโยบายความเป็นส่วนตัว (Privacy Policy) - <?= SITE_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #f8fafc; color: #1e293b; line-height: 1.8; }
        .content-card { background: white; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); }
        h1, h2, h3 { color: #0f172a; font-weight: 700; }
        .section-title { border-left: 4px solid #3b82f6; padding-left: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body class="py-12 px-4">
    <div class="max-w-3xl mx-auto">
        <!-- Logo & Header -->
        <div class="text-center mb-12">
            <div class="inline-block p-4 bg-blue-50 rounded-2xl mb-4">
                <svg class="w-12 h-12 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
            </div>
            <h1 class="text-3xl mb-2">นโยบายความเป็นส่วนตัว</h1>
            <p class="text-slate-500">Privacy Policy for LINE Official Account & Services</p>
            <div class="mt-4 text-sm text-slate-400">อัปเดตล่าสุด: <?= date('d/m/Y') ?></div>
        </div>

        <!-- Content Card -->
        <div class="content-card p-8 md:p-12 mb-8">
            <section class="mb-10">
                <h2 class="text-xl section-title">1. บทนำ</h2>
                <p>ยินดีต้อนรับสู่บริการของ <strong><?= SITE_NAME ?></strong> เราให้ความสำคัญกับความเป็นส่วนตัวของท่านอย่างสูงสุด นโยบายนี้อธิบายถึงวิธีการที่เราเก็บรวบรวม ใช้ และป้องกันข้อมูลส่วนบุคคลของท่านเมื่อท่านใช้งานผ่าน LINE Official Account และระบบงานที่เกี่ยวข้องของเรา</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">2. ข้อมูลที่เราจัดเก็บ</h2>
                <p>เมื่อท่านปฏิสัมพันธ์กับบริการของเราผ่าน LINE เราอาจจัดเก็บข้อมูลดังต่อไปนี้:</p>
                <ul class="list-disc ml-6 mt-2 space-y-1">
                    <li><strong>LINE User ID:</strong> รหัสประจำตัวผู้ใช้งานที่ออกโดย LINE (เพื่อใช้ในการระบุตัวตนและส่งข้อความแจ้งเตือน)</li>
                    <li><strong>โปรไฟล์สาธารณะ:</strong> ชื่อที่แสดง (Display Name) และรูปภาพโปรไฟล์ (Profile Picture)</li>
                    <li><strong>ข้อมูลการใช้งาน:</strong> ประวัติการทำรายการ การจอง หรือการตอบโต้ผ่านระบบของเรา</li>
                    <li><strong>ข้อมูลการติดต่อ:</strong> อีเมล หรือเบอร์โทรศัพท์ (ในกรณีที่ท่านให้ข้อมูลเพิ่มเติมผ่านแบบฟอร์ม)</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">3. วัตถุประสงค์การใช้ข้อมูล</h2>
                <p>เราใช้ข้อมูลของท่านเพื่อวัตถุประสงค์ดังนี้:</p>
                <ul class="list-disc ml-6 mt-2 space-y-1">
                    <li>เพื่อให้บริการและบริหารจัดการบัญชีผู้ใช้งานของท่าน</li>
                    <li>เพื่อส่งข้อความแจ้งเตือนสำคัญ (Push Notifications) เช่น การยืนยันการจอง หรือการแจ้งเตือนระบบ</li>
                    <li>เพื่อตอบข้อซักถามและให้ความช่วยเหลือแก่ท่าน</li>
                    <li>เพื่อพัฒนาและปรับปรุงคุณภาพการบริการให้ดียิ่งขึ้น</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">4. การรักษาความปลอดภัย</h2>
                <p>เรามาตรการรักษาความปลอดภัยทางเทคนิคและทางบริหารจัดการที่เหมาะสม เพื่อป้องกันไม่ให้ข้อมูลส่วนบุคคลของท่านสูญหาย ถูกนำไปใช้โดยไม่ได้รับอนุญาต หรือถูกเข้าถึงโดยบุคคลที่ไม่เกี่ยวข้อง ข้อมูลทั้งหมดจะถูกเก็บรักษาไว้ในระบบที่มีการควบคุมการเข้าถึงอย่างเข้มงวด</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">5. การเปิดเผยข้อมูลแก่บุคคลภายนอก</h2>
                <p>เราจะไม่ขายหรือแลกเปลี่ยนข้อมูลส่วนบุคคลของท่านให้แก่บุคคลภายนอก ยกเว้นเป็นการปฏิบัติตามกฎหมาย หรือได้รับความยินยอมจากท่านล่วงหน้า</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">6. สิทธิของเจ้าของข้อมูล</h2>
                <p>ท่านมีสิทธิในการเข้าถึง ขอสำเนา แก้ไข หรือขอให้ลบข้อมูลส่วนบุคคลของท่านออกจากระบบของเราได้ทุกเมื่อ โดยสามารถแจ้งความประสงค์ผ่านช่องทางติดต่อของเรา</p>
            </section>

            <section>
                <h2 class="text-xl section-title">7. ติดต่อเรา</h2>
                <p>หากท่านมีข้อสงสัยเกี่ยวกับนโยบายความเป็นส่วนตัวนี้ สามารถติดต่อเราได้ที่:</p>
                <div class="mt-4 p-4 bg-slate-50 rounded-xl border border-slate-100">
                    <p class="font-semibold text-slate-700"><?= SITE_NAME ?></p>
                    <p class="text-sm text-slate-500">Website: <?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" ?></p>
                </div>
            </section>
        </div>

        <div class="text-center">
            <a href="javascript:window.close();" class="text-blue-600 hover:text-blue-800 text-sm font-medium">ปิดหน้านี้</a>
        </div>
    </div>
</body>
</html>
