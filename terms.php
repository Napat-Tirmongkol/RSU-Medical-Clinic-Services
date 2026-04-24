<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อกำหนดการใช้งาน (Terms of Use) - <?= SITE_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #f8fafc; color: #1e293b; line-height: 1.8; }
        .content-card { background: white; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); }
        h1, h2, h3 { color: #0f172a; font-weight: 700; }
        .section-title { border-left: 4px solid #10b981; padding-left: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body class="py-12 px-4">
    <div class="max-w-3xl mx-auto">
        <!-- Logo & Header -->
        <div class="text-center mb-12">
            <div class="inline-block p-4 bg-emerald-50 rounded-2xl mb-4">
                <svg class="w-12 h-12 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <h1 class="text-3xl mb-2">ข้อกำหนดการใช้งาน</h1>
            <p class="text-slate-500">Terms of Use for LINE Official Account & Services</p>
            <div class="mt-4 text-sm text-slate-400">อัปเดตล่าสุด: <?= date('d/m/Y') ?></div>
        </div>

        <!-- Content Card -->
        <div class="content-card p-8 md:p-12 mb-8">
            <section class="mb-10">
                <h2 class="text-xl section-title">1. การยอมรับข้อกำหนด</h2>
                <p>การเข้าถึงหรือใช้งานบริการของ <strong><?= SITE_NAME ?></strong> ผ่านทาง LINE Official Account หรือระบบงานที่เกี่ยวข้อง ถือว่าท่านได้รับทราบและตกลงที่จะปฏิบัติตามข้อกำหนดและเงื่อนไขเหล่านี้ทุกประการ หากท่านไม่ตกลงตามข้อกำหนด โปรดงดเว้นการใช้งานบริการของเรา</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">2. ขอบเขตการบริการ</h2>
                <p>เราให้บริการระบบแจ้งเตือน การจอง และการบริหารจัดการข้อมูลที่เกี่ยวข้องผ่านแพลตฟอร์ม LINE เพื่ออำนวยความสะดวกแก่ผู้ใช้งาน โดยเราขอสงวนสิทธิ์ในการปรับปรุง เปลี่ยนแปลง หรือยุติการให้บริการบางส่วนหรือทั้งหมดได้ทุกเมื่อ โดยไม่ต้องแจ้งให้ทราบล่วงหน้า</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">3. ข้อตกลงการใช้งาน</h2>
                <p>ในการใช้งานบริการ ท่านตกลงที่จะ:</p>
                <ul class="list-disc ml-6 mt-2 space-y-1">
                    <li>ไม่ใช้งานบริการเพื่อวัตถุประสงค์ที่ผิดกฎหมายหรือละเมิดสิทธิ์ของบุคคลอื่น</li>
                    <li>ไม่พยายามเข้าถึงระบบโดยมิชอบ หรือกระทำการใดๆ ที่ส่งผลกระทบต่อประสิทธิภาพการทำงานของระบบ</li>
                    <li>ให้ข้อมูลที่เป็นจริงและเป็นปัจจุบันในการสมัครหรือใช้งานบริการ</li>
                    <li>รักษาความลับของบัญชีผู้ใช้งานและข้อมูลส่วนตัวของท่านเอง</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">4. ทรัพย์สินทางปัญญา</h2>
                <p>เนื้อหา ข้อความ รูปภาพ ซอฟต์แวร์ และเครื่องหมายการค้าทั้งหมดที่ปรากฏในบริการนี้ เป็นทรัพย์สินของ <strong><?= SITE_NAME ?></strong> หรือผู้ได้รับอนุญาต ห้ามมิให้ผู้ใดคัดลอก ดัดแปลง หรือนำไปใช้เพื่อประโยชน์ทางการค้าโดยไม่ได้รับอนุญาตเป็นลายลักษณ์อักษร</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">5. การจำกัดความรับผิดชอบ</h2>
                <p>เราพยายามอย่างเต็มที่เพื่อให้บริการมีความถูกต้องและต่อเนื่อง อย่างไรก็ตาม เราไม่รับผิดชอบต่อความเสียหายใดๆ ที่เกิดจากการใช้งาน หรือการไม่สามารถเข้าใช้งานบริการได้ อันเนื่องมาจากเหตุขัดข้องทางเทคนิค หรือปัจจัยภายนอกที่อยู่นอกเหนือการควบคุมของเรา</p>
            </section>

            <section class="mb-10">
                <h2 class="text-xl section-title">6. การสิ้นสุดการให้บริการ</h2>
                <p>เราขอสงวนสิทธิ์ในการระงับหรือยกเลิกการเข้าถึงบริการของท่าน หากพบว่าท่านละเมิดข้อกำหนดการใช้งานเหล่านี้ หรือมีการใช้งานที่อาจก่อให้เกิดความเสียหายต่อระบบหรือผู้ใช้งานรายอื่น</p>
            </section>

            <section>
                <h2 class="text-xl section-title">7. การเปลี่ยนแปลงข้อกำหนด</h2>
                <p>เราอาจปรับปรุงข้อกำหนดการใช้งานนี้เป็นครั้งคราวตามความเหมาะสม การที่ท่านใช้งานบริการต่อไปภายหลังการเปลี่ยนแปลง ถือว่าท่านยอมรับข้อกำหนดใหม่นั้นแล้ว</p>
            </section>
        </div>

        <div class="text-center">
            <a href="javascript:window.close();" class="text-emerald-600 hover:text-emerald-800 text-sm font-medium">ปิดหน้านี้</a>
        </div>
    </div>
</body>
</html>
