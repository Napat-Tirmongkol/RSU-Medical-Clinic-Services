<?php
// docs/user_manual.php
// ─────────────────────────────────────────────────────────────────────────────
// End-user manual for RSU Medical Clinic User Hub.
// Open to anyone who's logged in (or even public viewing — users need it
// before they login). No auth guard required.
// ─────────────────────────────────────────────────────────────────────────────
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>คู่มือการใช้งานระบบสำหรับผู้ใช้ — RSU Medical Clinic</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    @page { size: A4; margin: 18mm 16mm 20mm 16mm; }
    * { box-sizing: border-box; }
    html, body {
        font-family: 'Sarabun', 'TH Sarabun New', Arial, sans-serif;
        color: #1f2937; line-height: 1.55;
        margin: 0; padding: 0; background: #f1f5f9; font-size: 13pt;
    }
    .page {
        background: #fff; max-width: 800px; margin: 20px auto;
        padding: 32px 40px 40px 40px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08); border-radius: 4px;
    }

    /* Cover */
    .cover {
        min-height: 920px;
        background: linear-gradient(135deg, #ecfdf5 0%, #d1f7df 60%, #fef3c7 100%);
        display: flex; flex-direction: column; justify-content: space-between;
        padding: 60px 40px; border-radius: 4px;
        page-break-after: always;
    }
    .cover h1 {
        font-size: 28pt; font-weight: 800; color: #0f172a;
        margin: 18px 0 12px 0; line-height: 1.3;
    }
    .cover .brand { font-size: 12pt; font-weight: 800; color: #0f7349; letter-spacing: 0.18em; text-transform: uppercase; }
    .cover .subtitle { font-size: 14pt; color: #334155; font-weight: 600; margin-top: 8px; }
    .cover .top { text-align: center; }

    /* Section styles */
    .page h2 {
        font-size: 18pt; color: #0f7349; font-weight: 800;
        margin: 0 0 14px 0; padding-bottom: 8px;
        border-bottom: 2px solid #d1f7df;
    }
    .page h2 .num {
        display: inline-block; background: #0f7349; color: #fff;
        width: 28px; height: 28px; line-height: 28px;
        border-radius: 50%; text-align: center; font-size: 13pt;
        margin-right: 10px; vertical-align: middle;
    }
    .page h3 { font-size: 14pt; color: #0f172a; font-weight: 800; margin: 18px 0 8px 0; }
    .page p { margin: 6px 0 10px 0; }
    .page ul, .page ol { margin: 6px 0 10px 0; padding-left: 22px; }
    .page li { margin: 4px 0; }
    .page strong { color: #0f172a; }

    /* Step cards */
    .step-card {
        background: #f0fdf4; border-left: 4px solid #0f7349;
        padding: 14px 18px; margin: 12px 0; border-radius: 6px;
    }
    .step-card .step-num {
        display: inline-block; background: #0f7349; color: #fff;
        width: 24px; height: 24px; line-height: 24px;
        border-radius: 50%; text-align: center; font-weight: 800;
        font-size: 11pt; margin-right: 8px;
    }
    .step-card h4 {
        font-size: 13pt; color: #0f7349; margin: 0 0 6px 0; font-weight: 800;
        display: inline-block; vertical-align: middle;
    }

    /* Callout */
    .callout {
        background: #fffbeb; border-left: 4px solid #f59e0b;
        padding: 10px 14px; margin: 10px 0; border-radius: 4px;
        font-size: 11.5pt;
    }
    .callout.info { background: #eff6ff; border-left-color: #2563eb; }
    .callout.danger { background: #fef2f2; border-left-color: #dc2626; }

    /* Screenshot placeholder */
    .screenshot {
        margin: 14px 0;
        background: #f8fafc;
        border: 2px dashed #cbd5e1;
        border-radius: 8px;
        padding: 32px 16px;
        text-align: center;
        color: #94a3b8;
        font-size: 11pt;
        font-weight: 600;
    }
    .screenshot .icon { font-size: 24pt; display: block; margin-bottom: 6px; color: #cbd5e1; }
    .screenshot .label { font-weight: 800; color: #475569; margin-bottom: 4px; }
    .screenshot .hint { font-size: 9pt; color: #94a3b8; font-style: italic; }

    /* Feature pills */
    .pill {
        display: inline-block; background: #ecfdf5; color: #064e3b;
        font-size: 10pt; font-weight: 700;
        padding: 2px 9px; border-radius: 99px;
        border: 1px solid #a7f3d0; margin: 2px 2px;
    }
    .pill.amber { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    .pill.rose  { background: #fff1f2; color: #9f1239; border-color: #fecdd3; }
    .pill.sky   { background: #f0f9ff; color: #075985; border-color: #bae6fd; }

    /* TOC */
    .toc {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 12px; padding: 18px 22px; margin: 14px 0;
    }
    .toc-item {
        display: flex; align-items: center; gap: 10px;
        padding: 6px 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    .toc-item:last-child { border-bottom: 0; }
    .toc-item .toc-num {
        background: #0f7349; color: #fff;
        width: 26px; height: 26px; line-height: 26px;
        border-radius: 50%; text-align: center; font-weight: 800; font-size: 10pt;
        flex-shrink: 0;
    }
    .toc-item .toc-text { flex: 1; font-weight: 700; color: #0f172a; }
    .toc-item .toc-page { font-size: 10pt; color: #94a3b8; font-weight: 700; }

    /* Action panel */
    .no-print-tip {
        background: #0f7349; color: #fff;
        padding: 10px 16px; border-radius: 8px;
        font-size: 11pt; font-weight: 700; border: 0;
        box-shadow: 0 4px 16px rgba(15,115,73,0.3);
        cursor: pointer;
        transition: transform .12s ease;
    }
    .no-print-tip:hover { transform: translateY(-1px); }
    .no-print-tip:disabled { opacity: 0.65; cursor: wait; }

    /* FAQ */
    .faq-item {
        background: #fff; border: 1px solid #e2e8f0;
        border-radius: 10px; padding: 14px 18px; margin: 8px 0;
    }
    .faq-q {
        font-weight: 800; color: #0f7349; font-size: 12pt;
        margin-bottom: 6px;
    }
    .faq-q::before { content: 'Q: '; color: #64748b; }
    .faq-a { color: #475569; font-size: 11.5pt; }
    .faq-a::before { content: 'A: '; color: #64748b; font-weight: 800; }

    @media print {
        body { background: #fff; font-size: 12pt; }
        .page { box-shadow: none; margin: 0; max-width: 100%; padding: 0; }
        .cover { min-height: 100vh; page-break-after: always; }
        h2, h3 { page-break-after: avoid; }
        .step-card, .callout, .screenshot, .faq-item { page-break-inside: avoid; }
        .no-print { display: none !important; }
    }

    .doc-footer {
        text-align: center; font-size: 9pt; color: #94a3b8;
        margin-top: 24px; padding-top: 12px;
        border-top: 1px solid #e2e8f0;
    }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- COVER                                                            -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page cover">
    <div class="top">
        <div class="brand">RSU Medical Clinic Services</div>
        <div style="font-size:48pt; margin: 24px 0 8px 0;">📘</div>
        <h1>คู่มือการใช้งานระบบ<br>สำหรับผู้รับบริการ</h1>
        <div class="subtitle">User Manual — RSU Medical Clinic User Hub</div>
        <p style="margin-top:30px; color:#64748b; font-size:11pt">
            เรียนรู้วิธีจองนัดออนไลน์ · ตรวจสอบประวัติวัคซีน · ยืมอุปกรณ์<br>
            · สมัครบัตรทอง · ลงเวลานักศึกษาทุน — ทั้งหมดในที่เดียว
        </p>
    </div>
    <div style="background:rgba(255,255,255,0.7); border:1px solid rgba(15,115,73,0.2); border-radius:12px; padding:20px 24px; font-size:11pt">
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">ใช้กับ</span><span style="font-weight:600;color:#0f172a">นักศึกษา · บุคลากร · บุคคลทั่วไป</span></div>
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">เข้าใช้งานผ่าน</span><span style="font-weight:600;color:#0f172a">LINE OA: @RSU-MedicalClinic</span></div>
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">URL ระบบ</span><span style="font-weight:600;color:#0f172a">healthycampus.rsu.ac.th/rsu-clinic/user/</span></div>
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">เวอร์ชัน</span><span style="font-weight:600;color:#0f172a">v1.0 — พฤษภาคม 2569</span></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 1. เริ่มต้นใช้งาน                                                -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">1</span>เริ่มต้นใช้งาน <small style="font-size:11pt;color:#64748b;font-weight:500">(Getting Started)</small></h2>

    <p>
        ระบบ <strong>RSU Medical Hub</strong> เป็นบริการสุขภาพออนไลน์
        ที่คุณสามารถ <strong>จองนัด · ตรวจประวัติวัคซีน · ยืมอุปกรณ์ · ติดต่อเจ้าหน้าที่</strong>
        ผ่านมือถือได้ทุกที่ทุกเวลา — ไม่ต้องติดตั้งแอปใหม่ ใช้ผ่าน LINE ที่มีอยู่แล้วได้เลย
    </p>

    <div class="toc">
        <strong style="display:block;margin-bottom:8px;color:#0f172a;font-size:13pt">📑 สารบัญ</strong>
        <div class="toc-item"><span class="toc-num">1</span><span class="toc-text">เริ่มต้นใช้งาน — เพิ่มเพื่อน + สมัครสมาชิก</span><span class="toc-page">หน้า 2</span></div>
        <div class="toc-item"><span class="toc-num">2</span><span class="toc-text">จองนัดและเช็คอินที่คลินิก</span><span class="toc-page">หน้า 3</span></div>
        <div class="toc-item"><span class="toc-num">3</span><span class="toc-text">ตรวจประวัติวัคซีนและบริการอื่นๆ</span><span class="toc-page">หน้า 4</span></div>
        <div class="toc-item"><span class="toc-num">4</span><span class="toc-text">คำถามที่พบบ่อย (FAQ)</span><span class="toc-page">หน้า 5</span></div>
    </div>

    <h3>1.1 เพิ่มเพื่อน LINE OA ของคลินิก</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>เปิด LINE → สแกน QR Code ของคลินิก</h4>
        <p>หา QR Code ได้ที่ป้ายประชาสัมพันธ์หน้าคลินิก หรือเว็บไซต์ ม.รังสิต</p>
    </div>
    <div class="screenshot">
        <i class="fa-solid fa-image icon"></i>
        <div class="label">[ภาพหน้าจอ: QR Code เพิ่มเพื่อน LINE OA]</div>
        <div class="hint">paste รูป QR Code ของ LINE OA @RSU-MedicalClinic ตรงนี้</div>
    </div>

    <div class="step-card">
        <span class="step-num">2</span><h4>กด "เพิ่มเพื่อน"</h4>
        <p>เมื่อเพิ่มเพื่อนเรียบร้อย ระบบจะส่งข้อความต้อนรับให้คุณ พร้อมเมนูด้านล่างหน้าจอแชท</p>
    </div>

    <h3>1.2 สมัครสมาชิกครั้งแรก</h3>
    <div class="step-card">
        <span class="step-num">3</span><h4>กดเมนู "เริ่มใช้งาน" บน Rich Menu</h4>
        <p>ระบบจะเปิดหน้าลงทะเบียนข้อมูลส่วนตัว</p>
    </div>
    <div class="step-card">
        <span class="step-num">4</span><h4>กรอกข้อมูลที่จำเป็น</h4>
        <ul style="margin:6px 0 0 0">
            <li><strong>ชื่อ-นามสกุล</strong> (จำเป็น)</li>
            <li><strong>เลขประจำตัวประชาชน</strong> (จำเป็น สำหรับเชื่อมสิทธิประกัน)</li>
            <li><strong>รหัสนักศึกษา/พนักงาน</strong> (สำหรับนักศึกษา/บุคลากร)</li>
            <li><strong>เบอร์โทร · อีเมล · คณะ/หน่วยงาน</strong></li>
        </ul>
    </div>
    <div class="step-card">
        <span class="step-num">5</span><h4>เพิ่มข้อมูลสุขภาพ (แนะนำ)</h4>
        <p>กรุ๊ปเลือด · แพ้ยา/อาหาร · โรคประจำตัว · ผู้ติดต่อฉุกเฉิน
        — <strong>ข้อมูลเหล่านี้ช่วยเจ้าหน้าที่ให้บริการได้รวดเร็วในกรณีฉุกเฉิน</strong></p>
    </div>

    <div class="callout info">
        <strong>🔒 ข้อมูลของคุณปลอดภัย</strong> — ระบบปฏิบัติตาม PDPA
        เลขประจำตัวประชาชนจะถูก mask เป็น 3xx****xx89 บนหน้าจอเสมอ
        ข้อมูลสุขภาพแสดงเฉพาะคุณและเจ้าหน้าที่ที่ได้รับสิทธิ์
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 2. จองนัด + Check-in                                             -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">2</span>จองนัดและเช็คอินที่คลินิก</h2>

    <h3>2.1 จองนัดผ่าน Rich Menu</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>กด "จองนัด" ที่ปุ่ม + มุมบนซ้าย</h4>
        <p>หรือกดที่ Rich Menu ของ LINE ของคลินิก</p>
    </div>

    <div class="screenshot">
        <i class="fa-solid fa-mobile-screen icon"></i>
        <div class="label">[ภาพหน้าจอ: หน้าหลัก User Hub พร้อมปุ่ม + จองนัด]</div>
        <div class="hint">paste screenshot ของหน้า hub.php ที่เห็นปุ่ม + (สีเขียวมุมบนซ้าย)</div>
    </div>

    <div class="step-card">
        <span class="step-num">2</span><h4>เลือกแคมเปญสุขภาพ</h4>
        <p>เช่น <span class="pill">วัคซีนไข้หวัดใหญ่</span> <span class="pill">ตรวจสุขภาพประจำปี</span> <span class="pill">ทันตกรรม</span></p>
    </div>

    <div class="step-card">
        <span class="step-num">3</span><h4>เลือกวันและเวลา</h4>
        <p>ระบบจะแสดงเฉพาะ slot ที่ยังว่าง — ไม่ต้องโทรหาคลินิกเพื่อเช็ค</p>
    </div>

    <div class="step-card">
        <span class="step-num">4</span><h4>ยืนยันการจอง</h4>
        <p>ระบบจะส่ง LINE notification ยืนยัน พร้อมให้คุณส่งเข้าปฏิทินมือถือได้ (Google/Apple Calendar)</p>
    </div>

    <h3>2.2 Check-in หน้าคลินิก</h3>
    <div class="step-card">
        <span class="step-num">5</span><h4>เปิดแอป → กด QR ที่มุมบนขวา</h4>
        <p>QR Code นี้คือ <strong>บัตรประจำตัวสมาชิก</strong> แสดงให้เจ้าหน้าที่สแกน</p>
    </div>

    <div class="screenshot">
        <i class="fa-solid fa-qrcode icon"></i>
        <div class="label">[ภาพหน้าจอ: QR Identity Card]</div>
        <div class="hint">paste screenshot ของ QR Modal ที่กดเข้ามาแล้ว</div>
    </div>

    <div class="step-card">
        <span class="step-num">6</span><h4>เจ้าหน้าที่สแกน → ลงทะเบียนอัตโนมัติ</h4>
        <p>ไม่ต้องกรอกชื่อหรือเลขประจำตัวซ้ำ — ทุกอย่างเชื่อมโยงจากระบบ</p>
    </div>

    <div class="callout">
        <strong>⏰ จะมีแจ้งเตือนล่วงหน้า:</strong> 1 วันก่อนนัด + 1 ชั่วโมงก่อนนัด
        ผ่าน LINE — ห้ามลืม! ถ้ายกเลิกได้ กรุณายกเลิกก่อนเวลานัด
        เพื่อให้ผู้อื่นได้ใช้ slot ของคุณ
    </div>

    <h3>2.3 ดูนัดของฉัน</h3>
    <p>ใน hub.php → tab <strong>"วันนี้"</strong> จะแสดง:</p>
    <ul>
        <li><strong>Smart Hero Card</strong> — นัดถัดไป + วันเหลือ</li>
        <li><strong>Reminder Pills</strong> — รายการที่ต้องดำเนินการเร่งด่วน</li>
        <li><strong>Upcoming Appointments</strong> — รายการนัดทั้งหมด</li>
    </ul>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 3. ตรวจประวัติวัคซีน + บริการอื่นๆ                              -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">3</span>ตรวจประวัติวัคซีนและบริการอื่นๆ</h2>

    <h3>3.1 ประวัติวัคซีน</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>เปิดแอป → tab "สุขภาพ"</h4>
        <p>กดที่ card <strong>"วัคซีน"</strong> → ดูประวัติทั้งหมด พร้อมวันครบกำหนด booster ครั้งถัดไป</p>
    </div>

    <div class="screenshot">
        <i class="fa-solid fa-syringe icon"></i>
        <div class="label">[ภาพหน้าจอ: รายการวัคซีนที่เคยฉีด + next due]</div>
        <div class="hint">paste screenshot ของ vaccination-modal</div>
    </div>

    <h3>3.2 ยืมอุปกรณ์การแพทย์ (e-Borrow)</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>tab "บริการ" → กด "ยืมอุปกรณ์"</h4>
        <p>เลือกประเภทอุปกรณ์ → กรอกเหตุผล + วันคืน → ส่งคำขอ</p>
    </div>
    <div class="step-card">
        <span class="step-num">2</span><h4>รออนุมัติจากเจ้าหน้าที่</h4>
        <p>ปกติใช้เวลา 1-3 ชั่วโมงทำการ — LINE จะแจ้งเมื่ออนุมัติแล้ว</p>
    </div>
    <div class="step-card">
        <span class="step-num">3</span><h4>มารับของที่คลินิก + คืนตามกำหนด</h4>
        <p>หากเลยกำหนดคืน <strong>มีค่าปรับวันละ 10 บาท</strong> — ระบบคำนวณอัตโนมัติ</p>
    </div>

    <h3>3.3 สมัครบัตรทอง (Universal Coverage)</h3>
    <p>เฉพาะ <strong>นักศึกษา ม.รังสิต</strong> ที่ยังไม่มีสิทธิประกันสุขภาพ — กดเมนู <strong>"สมัครบัตรทอง"</strong> ใน tab "บริการ"</p>
    <ul>
        <li>กรอกข้อมูล + เซ็นชื่อบน touchscreen</li>
        <li>รอเจ้าหน้าที่ตรวจสอบ ~3-5 วันทำการ</li>
        <li>เมื่ออนุมัติ ระบบจะเชื่อมสิทธิให้อัตโนมัติ</li>
    </ul>

    <h3>3.4 ลงเวลานักศึกษาทุน</h3>
    <p>เฉพาะ <strong>นักศึกษาทุน</strong> ที่ทำงานในคลินิก:</p>
    <ul>
        <li>เปิดเมนู "ลงเวลานักศึกษาทุน"</li>
        <li>กด <strong>Clock-In</strong> เมื่อมาถึง (ระบบจะเช็ค GPS)</li>
        <li>กด <strong>Clock-Out</strong> เมื่อออก</li>
        <li>รอเจ้าหน้าที่อนุมัติชั่วโมงทำงาน → ดูยอดสะสมในเมนู "ประวัติทุน"</li>
    </ul>

    <h3>3.5 รับประกาศจากคลินิก</h3>
    <p>ประกาศใหม่จะ pop-up ขึ้นมาเมื่อเปิดแอปครั้งถัดไป — สามารถ:</p>
    <ul>
        <li>กดปุ่ม <strong>"รับทราบ"</strong> เพื่อปิดแบบถาวร</li>
        <li>กดปุ่ม <strong>"ข้ามทั้งหมด"</strong> เพื่อปิดชั่วคราว — จะแสดงอีกครั้งครั้งถัดไป</li>
        <li><strong>Swipe ซ้าย-ขวา</strong> เพื่อดูประกาศอื่น</li>
        <li>กด <strong>"EN"</strong> เพื่อสลับภาษา (ถ้ามีฉบับอังกฤษ)</li>
    </ul>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 4. คำถามที่พบบ่อย (FAQ)                                          -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">4</span>คำถามที่พบบ่อย <small style="font-size:11pt;color:#64748b;font-weight:500">(FAQ)</small></h2>

    <div class="faq-item">
        <div class="faq-q">ต้องโหลดแอปอะไรเพิ่มไหม?</div>
        <div class="faq-a">ไม่ต้อง — ใช้ผ่าน LINE ที่คุณมีอยู่แล้ว เพียงเพิ่มเพื่อนกับ LINE OA ของคลินิก</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">ลืม login ทำยังไง?</div>
        <div class="faq-a">เปิด LINE → แชทกับคลินิก → ระบบจะ login อัตโนมัติเมื่อกดเข้าเมนูใดๆ — ไม่ต้องจำรหัสผ่าน</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">จองนัดแล้วไปไม่ทันทำไงดี?</div>
        <div class="faq-a">เข้าเมนู "นัดของฉัน" → กดยกเลิก <strong>ก่อนเวลานัด</strong> — ถ้าไม่ยกเลิก ระบบจะนับว่าเป็น "no-show" ซึ่งอาจมีผลต่อการจองครั้งถัดไป</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">เปลี่ยนเบอร์โทร/อีเมลได้ไหม?</div>
        <div class="faq-a">ได้ — กดที่ Identity Card บนหน้าหลัก (Wallet card สีเขียว) → ระบบจะเปิดหน้าโปรไฟล์ → แก้ไขข้อมูล → กดบันทึก</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">ระบบจะส่งข้อมูลของฉันให้ใครบ้าง?</div>
        <div class="faq-a">เจ้าหน้าที่คลินิกเท่านั้น — ข้อมูลไม่ถูกส่งออกนอกระบบ ปฏิบัติตามมาตรฐาน PDPA และ ISO 27001</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">ติดต่อเจ้าหน้าที่ได้อย่างไร?</div>
        <div class="faq-a">กดปุ่ม "?" สีส้ม ในแถบ Quick Contact → เปิด chat กับเจ้าหน้าที่ พร้อม AI ช่วยตอบเบื้องต้น 24 ชม.</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">มีปัญหาทางเทคนิคทำยังไง?</div>
        <div class="faq-a">โทร <strong>02-997-2200 ต่อ 1234</strong> หรือ Line @rsu-helpdesk (เวลาทำการ จ-ศ 8:30-16:30)</div>
    </div>

    <h3>📞 ติดต่อคลินิก</h3>
    <ul>
        <li><strong>ที่ตั้ง:</strong> อาคาร 12/1 มหาวิทยาลัยรังสิต ต.หลักหก จ.ปทุมธานี</li>
        <li><strong>โทรศัพท์:</strong> 02-997-2200 ต่อ 5555</li>
        <li><strong>LINE OA:</strong> @RSU-MedicalClinic</li>
        <li><strong>เวลาทำการ:</strong> จันทร์ - ศุกร์ 8:30 - 16:30 (ปิดวันหยุดราชการ)</li>
    </ul>

    <div class="doc-footer">
        คู่มือนี้สำหรับผู้ใช้บริการศูนย์บริการสุขภาพ มหาวิทยาลัยรังสิต<br>
        © 2569 RSU Medical Clinic Services · v1.0 · พฤษภาคม 2569
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- Floating action panel                                            -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="no-print" style="position:fixed;bottom:16px;right:16px;display:flex;flex-direction:column;gap:8px;z-index:9999">
    <button id="btn-print" class="no-print-tip" onclick="window.print()" style="background:#475569">🖨️ พิมพ์ (Print)</button>
    <button id="btn-pdf"   class="no-print-tip" onclick="downloadPdf()" style="background:#dc2626">📕 ดาวน์โหลด PDF</button>
    <button id="btn-doc"   class="no-print-tip" onclick="exportToWord()" style="background:#2563eb">📘 ดาวน์โหลด .doc (Word)</button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
async function downloadPdf() {
    const btn = document.getElementById('btn-pdf');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '⏳ กำลังสร้าง PDF...';
    try {
        const container = document.createElement('div');
        container.style.background = '#fff';
        document.querySelectorAll('.page').forEach(p => {
            const c = p.cloneNode(true);
            c.querySelectorAll('.no-print').forEach(el => el.remove());
            c.style.margin = '0'; c.style.boxShadow = 'none';
            c.style.maxWidth = '100%'; c.style.pageBreakAfter = 'always';
            container.appendChild(c);
        });
        const filename = 'RSU_User_Manual_' + new Date().toISOString().substring(0,10) + '.pdf';
        await html2pdf().set({
            margin: [10,10,12,10],
            filename,
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, logging: false, letterRendering: true, allowTaint: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['.step-card', '.callout', '.faq-item', '.screenshot'] },
        }).from(container).save();
    } catch (e) {
        alert('สร้าง PDF ไม่สำเร็จ ลองใช้ปุ่มพิมพ์แทน\n\n' + e.message);
    } finally {
        btn.disabled = false; btn.innerHTML = orig;
    }
}

function exportToWord() {
    const clone = document.body.cloneNode(true);
    clone.querySelectorAll('.no-print, script').forEach(el => el.remove());
    clone.querySelectorAll('.cover').forEach(el => { el.style.background = '#fff'; el.style.padding = '40px'; el.style.minHeight = 'auto'; });
    clone.querySelectorAll('.page').forEach(el => { el.style.boxShadow = 'none'; el.style.margin = '0 auto 28px auto'; el.style.padding = '24px 32px'; el.style.border = '1px solid #d1d5db'; });

    const wordCss = `
        @page { size: A4; margin: 2.5cm 2cm; }
        body { font-family: 'Sarabun', 'TH Sarabun New', Arial, sans-serif; font-size: 13pt; }
        h2 { font-size: 17pt; color: #0f7349; border-bottom: 2px solid #0f7349; padding-bottom: 6px; page-break-after: avoid; }
        h3 { font-size: 13pt; page-break-after: avoid; }
        .step-card { background: #f0fdf4; border-left: 4px solid #0f7349; padding: 8pt 12pt; margin: 8pt 0; }
        .callout { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 8pt 12pt; margin: 8pt 0; }
        .faq-item { background: #fff; border: 1px solid #cbd5e1; padding: 10pt 14pt; margin: 6pt 0; }
        .faq-q { font-weight: bold; color: #0f7349; font-size: 12pt; }
        .pill { display: inline-block; padding: 1pt 6pt; border: 1px solid #94a3b8; border-radius: 4pt; font-size: 10pt; }
        .screenshot { border: 2px dashed #cbd5e1; padding: 16pt; text-align: center; color: #94a3b8; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: avoid; }
    `;
    const header = `<!DOCTYPE html><html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>คู่มือผู้ใช้</title><style>${wordCss}</style></head><body>`;
    const html = header + clone.innerHTML + '</body></html>';
    const blob = new Blob(['﻿', html], { type: 'application/msword;charset=utf-8' });
    const filename = 'RSU_User_Manual_' + new Date().toISOString().substring(0,10) + '.doc';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 100);
}
</script>

</body>
</html>
