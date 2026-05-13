<?php
// docs/user_hub_proposal.php
// ─────────────────────────────────────────────────────────────────────────────
// Project proposal document — User-side scope of RSU Medical Clinic.
// Restricted to portal admins (superadmin / admin role) because it contains
// budget figures and asset valuation.
//
// Direct URL access by anyone is denied; admin must be logged in via
// portal/auth before this page renders.
// ─────────────────────────────────────────────────────────────────────────────
declare(strict_types=1);
session_start();

$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin' && $adminRole !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="th"><head><meta charset="UTF-8"><title>Access Denied</title>
    <style>body{font-family:sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#0f172a;text-align:center}
    .box{background:#fff;padding:40px 56px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:480px}
    h1{color:#dc2626;margin:0 0 8px 0;font-size:24px}
    p{color:#64748b;margin:8px 0;font-size:14px}
    a{color:#0f7349;text-decoration:none;font-weight:700;display:inline-block;margin-top:16px}</style>
    </head><body>
    <div class="box">
        <h1>🛡️ Access Denied</h1>
        <p>เอกสารนี้สำหรับผู้บริหารระบบ (Admin) เท่านั้น</p>
        <p>กรุณาเข้าสู่ระบบผ่านหน้า Portal Admin ก่อน</p>
        <a href="/e-campaignv2/admin/auth/login.php">→ เข้าสู่ระบบ</a>
    </div>
    </body></html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>โครงการพัฒนาระบบบริการสุขภาพออนไลน์สำหรับผู้รับบริการ (RSU Medical Clinic — User Hub)</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    /* ── Base / Print-friendly A4 ─────────────────────────────────── */
    @page {
        size: A4;
        margin: 18mm 16mm 20mm 16mm;
    }
    * { box-sizing: border-box; }
    html, body {
        font-family: 'Sarabun', 'TH Sarabun New', Arial, sans-serif;
        color: #1f2937;
        line-height: 1.55;
        margin: 0;
        padding: 0;
        background: #f1f5f9;
        font-size: 13pt;
    }
    .page {
        background: #fff;
        max-width: 800px;
        margin: 20px auto;
        padding: 32px 40px 40px 40px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        border-radius: 4px;
    }

    /* ── Cover page ───────────────────────────────────────────────── */
    .cover {
        min-height: 920px;
        background: linear-gradient(135deg, #e6f9ee 0%, #d1f7df 60%, #fef3c7 100%);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 60px 40px;
        border-radius: 4px;
        page-break-after: always;
    }
    .cover .top {
        text-align: center;
    }
    .cover-brand {
        font-size: 12pt;
        font-weight: 800;
        color: #0f7349;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        margin-bottom: 12px;
    }
    .cover h1 {
        font-size: 26pt;
        font-weight: 800;
        color: #0f172a;
        margin: 18px 0 12px 0;
        line-height: 1.3;
        letter-spacing: -0.01em;
    }
    .cover .subtitle {
        font-size: 14pt;
        color: #334155;
        font-weight: 600;
        margin-top: 8px;
    }
    .cover .en-title {
        font-size: 11pt;
        color: #64748b;
        margin-top: 18px;
        font-style: italic;
    }
    .cover .badge-row {
        display: flex;
        gap: 12px;
        justify-content: center;
        margin-top: 32px;
        flex-wrap: wrap;
    }
    .cover .badge {
        background: rgba(15, 115, 73, 0.1);
        border: 1px solid rgba(15, 115, 73, 0.3);
        color: #0f7349;
        padding: 6px 14px;
        border-radius: 99px;
        font-size: 10pt;
        font-weight: 700;
    }
    .cover .meta-box {
        background: rgba(255,255,255,0.7);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(15, 115, 73, 0.2);
        border-radius: 12px;
        padding: 20px 24px;
        font-size: 11pt;
    }
    .cover .meta-box .row {
        display: flex;
        margin: 4px 0;
    }
    .cover .meta-box .row .lbl {
        width: 130px;
        font-weight: 700;
        color: #475569;
    }
    .cover .meta-box .row .val {
        flex: 1;
        font-weight: 600;
        color: #0f172a;
    }

    /* ── Section styles ───────────────────────────────────────────── */
    .page h2 {
        font-size: 18pt;
        color: #0f7349;
        font-weight: 800;
        margin: 0 0 14px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #d1f7df;
        letter-spacing: -0.01em;
    }
    .page h2 .num {
        display: inline-block;
        background: #0f7349;
        color: #fff;
        width: 28px;
        height: 28px;
        line-height: 28px;
        border-radius: 50%;
        text-align: center;
        font-size: 13pt;
        margin-right: 10px;
        vertical-align: middle;
    }
    .page h3 {
        font-size: 14pt;
        color: #0f172a;
        font-weight: 800;
        margin: 18px 0 8px 0;
    }
    .page h4 {
        font-size: 12pt;
        color: #334155;
        font-weight: 700;
        margin: 12px 0 6px 0;
    }
    .page p { margin: 6px 0 10px 0; }
    .page ul, .page ol { margin: 6px 0 10px 0; padding-left: 22px; }
    .page li { margin: 4px 0; }
    .page strong { color: #0f172a; }

    /* ── Tables ───────────────────────────────────────────────────── */
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0 14px 0;
        font-size: 11pt;
    }
    th, td {
        text-align: left;
        padding: 8px 10px;
        border: 1px solid #e2e8f0;
        vertical-align: top;
    }
    th {
        background: #ecfdf5;
        font-weight: 800;
        color: #064e3b;
        font-size: 11pt;
    }
    tr:nth-child(even) td { background: #f8fafc; }

    /* ── Callout boxes ────────────────────────────────────────────── */
    .callout {
        background: #f0fdf4;
        border-left: 4px solid #0f7349;
        padding: 12px 16px;
        margin: 12px 0;
        border-radius: 4px;
        font-size: 11.5pt;
    }
    .callout.warn { background: #fef3c7; border-left-color: #d97706; }
    .callout.info { background: #eff6ff; border-left-color: #2563eb; }

    /* ── KPI grid ─────────────────────────────────────────────────── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin: 12px 0;
    }
    .kpi-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px 14px;
    }
    .kpi-card .kpi-label {
        font-size: 10pt;
        color: #64748b;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }
    .kpi-card .kpi-target {
        font-size: 13pt;
        color: #0f7349;
        font-weight: 800;
    }

    /* ── Feature pill row ─────────────────────────────────────────── */
    .pill-row { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0; }
    .pill {
        background: #ecfdf5;
        color: #064e3b;
        font-size: 10pt;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 99px;
        border: 1px solid #a7f3d0;
    }
    .pill.amber  { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    .pill.rose   { background: #fff1f2; color: #9f1239; border-color: #fecdd3; }
    .pill.sky    { background: #f0f9ff; color: #075985; border-color: #bae6fd; }
    .pill.violet { background: #faf5ff; color: #6b21a8; border-color: #e9d5ff; }

    /* ── Status badge ─────────────────────────────────────────────── */
    .status-done   { background: #dcfce7; color: #166534; padding: 1px 8px; border-radius: 4px; font-size: 10pt; font-weight: 800; }
    .status-progress { background: #fef3c7; color: #92400e; padding: 1px 8px; border-radius: 4px; font-size: 10pt; font-weight: 800; }
    .status-plan   { background: #e0e7ff; color: #3730a3; padding: 1px 8px; border-radius: 4px; font-size: 10pt; font-weight: 800; }

    /* ── Timeline ─────────────────────────────────────────────────── */
    .timeline {
        position: relative;
        padding-left: 28px;
        margin: 12px 0;
    }
    .timeline::before {
        content: '';
        position: absolute;
        left: 10px;
        top: 8px;
        bottom: 8px;
        width: 2px;
        background: #d1f7df;
    }
    .tl-item {
        position: relative;
        margin-bottom: 14px;
    }
    .tl-item::before {
        content: '';
        position: absolute;
        left: -22px;
        top: 6px;
        width: 10px;
        height: 10px;
        background: #0f7349;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px #0f7349;
    }
    .tl-item .tl-title {
        font-weight: 800;
        color: #0f172a;
        font-size: 12pt;
        margin-bottom: 2px;
    }
    .tl-item .tl-meta {
        font-size: 10pt;
        color: #64748b;
        font-weight: 600;
    }

    /* ── Print-only adjustments ───────────────────────────────────── */
    @media print {
        body { background: #fff; font-size: 12pt; }
        .page { box-shadow: none; margin: 0; max-width: 100%; padding: 0; }
        .cover { min-height: 100vh; page-break-after: always; }
        h2 { page-break-after: avoid; }
        h3 { page-break-after: avoid; }
        table, .callout, .kpi-grid, .timeline { page-break-inside: avoid; }
        .no-print { display: none !important; }
        .page-break { page-break-before: always; }
    }
    .no-print-tip {
        position: fixed; bottom: 16px; right: 16px;
        background: #0f7349; color: #fff;
        padding: 10px 16px; border-radius: 8px;
        font-size: 11pt; font-weight: 700;
        box-shadow: 0 4px 16px rgba(15,115,73,0.3);
        cursor: pointer;
    }
    .no-print-tip:hover { background: #0e5d3c; }

    /* ── Footer ───────────────────────────────────────────────────── */
    .doc-footer {
        text-align: center;
        font-size: 9pt;
        color: #94a3b8;
        margin-top: 24px;
        padding-top: 12px;
        border-top: 1px solid #e2e8f0;
    }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- COVER PAGE                                                      -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page cover">
    <div class="top">
        <div class="cover-brand">RSU Medical Clinic Services</div>
        <div style="font-size:36pt; margin: 24px 0 8px 0;">🏥</div>
        <h1>โครงการพัฒนาระบบบริการสุขภาพออนไลน์<br>สำหรับผู้รับบริการ</h1>
        <div class="subtitle">User Hub — แพลตฟอร์มเข้าถึงบริการสุขภาพในจุดเดียว</div>
        <div class="en-title">Development of an Integrated Online Healthcare Service Platform for End-Users</div>

        <div class="badge-row">
            <span class="badge">📱 LINE LIFF</span>
            <span class="badge">⚡ Real-time</span>
            <span class="badge">🩺 Health-First UX</span>
            <span class="badge">🔒 PDPA Compliant</span>
        </div>
    </div>

    <div class="meta-box">
        <div class="row"><span class="lbl">ผู้เสนอโครงการ</span><span class="val">ศูนย์บริการสุขภาพ มหาวิทยาลัยรังสิต</span></div>
        <div class="row"><span class="lbl">หน่วยงานรับผิดชอบ</span><span class="val">งานเทคโนโลยีสารสนเทศ คลินิก RSU Medical</span></div>
        <div class="row"><span class="lbl">ระยะเวลาดำเนินการ</span><span class="val">ปีงบประมาณ 2569 (พฤษภาคม 2569 — เมษายน 2570)</span></div>
        <div class="row"><span class="lbl">URL ระบบ</span><span class="val">https://healthycampus.rsu.ac.th/e-campaignv2/user/</span></div>
        <div class="row"><span class="lbl">เวอร์ชันเอกสาร</span><span class="val">v1.0 — พฤษภาคม 2569</span></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 1. บทสรุปผู้บริหาร                                              -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">1</span>บทสรุปผู้บริหาร <small style="font-size:11pt;color:#64748b;font-weight:500">(Executive Summary)</small></h2>

    <p>
        ระบบ <strong>RSU Medical Clinic — User Hub</strong> เป็นแพลตฟอร์มบริการสุขภาพออนไลน์
        ผ่าน LINE Official Account และ Web Application สำหรับ <strong>นักศึกษา · บุคลากร · ผู้รับบริการทั่วไป</strong>
        ของศูนย์บริการสุขภาพ มหาวิทยาลัยรังสิต ครอบคลุมการ <strong>จองนัดออนไลน์ · ตรวจสอบประวัติวัคซีน
        · ยืมอุปกรณ์การแพทย์ · ลงเวลานักศึกษาทุน · สมัครสิทธิบัตรทอง · รับประกาศจากคลินิก</strong>
        แบบ Real-time ในจุดเดียว
    </p>

    <h3>เป้าหมายของโครงการ</h3>
    <ul>
        <li>ลด <strong>อัตราการขาดนัด (No-show rate)</strong> จาก ≈20% เหลือ &lt;10% ภายใน 12 เดือน ด้วยระบบแจ้งเตือนล่วงหน้า</li>
        <li>เพิ่ม <strong>Throughput ของคลินิก</strong> โดยลดเวลาลงทะเบียนหน้างานจาก 5 นาที เหลือ &lt;1 นาที ผ่าน QR Identity</li>
        <li>ยกระดับ <strong>ความปลอดภัยของผู้ป่วย</strong> ด้วยการแสดงข้อมูลกรุ๊ปเลือด/แพ้ยา/โรคประจำตัวบนหน้าจอแรก</li>
        <li>ลด <strong>ภาระงานเจ้าหน้าที่</strong> ในการตอบคำถามทั่วไป ด้วย AI Chat Support และ FAQ ในตัว</li>
        <li>สอดคล้องกับ <strong>มาตรฐาน PDPA และ ISO 27001</strong> ในการจัดการข้อมูลสุขภาพ</li>
    </ul>

    <h3>สถานะปัจจุบัน</h3>
    <p>
        ระบบฝั่งผู้ใช้พัฒนาแล้ว <strong>~85%</strong> เปิดใช้งานจริง พร้อมฟีเจอร์หลักครบ — Booking, Vaccination Records, Gold Card,
        e-Borrow, Scholarship Clock-in, LINE Integration พร้อมระบบ Real-time Notifications ผ่าน Pusher และ Cron Daily Report
    </p>

    <div class="callout">
        <strong>💡 จุดแข็งของระบบ:</strong> ผู้ใช้ไม่ต้องติดตั้งแอปใหม่ — เข้าผ่าน LINE ที่มีอยู่แล้วได้ทันที
        ลดต้นทุนการตลาด/onboarding และเข้าถึงกลุ่มเป้าหมาย 95%+ ของประชากรในมหาวิทยาลัย
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 2. หลักการและเหตุผล                                             -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">2</span>หลักการและเหตุผล <small style="font-size:11pt;color:#64748b;font-weight:500">(Rationale)</small></h2>

    <h3>2.1 ปัญหาที่พบในปัจจุบัน</h3>
    <ul>
        <li><strong>การจองนัดผ่านโทรศัพท์/เดินไปคลินิก</strong> สร้างภาระให้ทั้งผู้ใช้และเจ้าหน้าที่
            — เจ้าหน้าที่รับสายซ้ำซ้อน ผู้ใช้ต้องโทรในเวลาทำการ</li>
        <li><strong>อัตรา No-show สูง</strong> (≈20% จากสถิติเดิม) ทำให้สูญเสียทรัพยากรแพทย์
            และผู้ป่วยรายอื่นต้องรอนานขึ้น</li>
        <li><strong>ผู้ใช้ไม่ทราบสิทธิประกัน</strong> ของตนเอง ทำให้ต้องสอบถามทุกครั้งที่มาใช้บริการ</li>
        <li><strong>การยืมอุปกรณ์การแพทย์</strong> ใช้กระดาษ ลายเซ็นต์มือ
            ตรวจสอบประวัติย้อนหลังยาก คำนวณค่าปรับเลยกำหนดผิดบ่อย</li>
        <li><strong>ประกาศจากคลินิก</strong> ส่งทาง LINE OA แบบ broadcast คนเปิดอ่านน้อย ไม่ทราบใครรับทราบแล้ว</li>
        <li><strong>นักศึกษาทุนลงเวลาทำงาน</strong> ใช้สมุดบันทึก ตรวจสอบล่าช้า เกิดข้อพิพาทเรื่องชั่วโมงสะสม</li>
    </ul>

    <h3>2.2 บริบทเชิงนโยบาย</h3>
    <ul>
        <li><strong>นโยบาย Digital Health</strong> ของกระทรวงสาธารณสุขและ สปสช.
            สนับสนุนการเข้าถึงข้อมูลสุขภาพออนไลน์ของประชาชน</li>
        <li><strong>พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล (PDPA)</strong> กำหนดให้คลินิกต้องมีระบบจัดการ
            consent / audit trail สำหรับข้อมูลสุขภาพ</li>
        <li><strong>มาตรฐาน ISO 27001</strong> ที่มหาวิทยาลัยกำลังขับเคลื่อน
            ต้องการระบบ access control + activity logging ที่ตรวจสอบได้</li>
        <li><strong>นโยบายมหาวิทยาลัยรังสิต</strong> ในการเป็นผู้นำด้าน HealthyCampus</li>
    </ul>

    <h3>2.3 เหตุผลที่เลือกพัฒนาบน LINE Platform</h3>
    <table>
        <tr><th>ปัจจัย</th><th>LINE LIFF</th><th>Native App</th><th>Web เปล่า</th></tr>
        <tr><td>Adoption rate ของกลุ่มเป้าหมาย</td><td>95%+</td><td>20-30%</td><td>50-60%</td></tr>
        <tr><td>ต้นทุนพัฒนา/ดูแล</td><td>ต่ำ</td><td>สูง (2 platforms)</td><td>ปานกลาง</td></tr>
        <tr><td>การแจ้งเตือน Push</td><td>ผ่าน LINE OA ฟรี</td><td>FCM/APNS ต้อง opt-in</td><td>Web Push (opt-in ยาก)</td></tr>
        <tr><td>การ Onboarding</td><td>เพิ่มเพื่อน 1 คลิก</td><td>ดาวน์โหลด+ติดตั้ง</td><td>จำ URL</td></tr>
        <tr><td>ค่าใช้จ่ายต่อเดือน</td><td>~0-1,500 บาท</td><td>~5,000+ บาท</td><td>~500 บาท</td></tr>
    </table>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 3. วัตถุประสงค์ + กลุ่มเป้าหมาย                                 -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">3</span>วัตถุประสงค์และกลุ่มเป้าหมาย</h2>

    <h3>3.1 วัตถุประสงค์</h3>
    <ol>
        <li><strong>เพื่อพัฒนาระบบบริการสุขภาพออนไลน์</strong> ที่ผู้ใช้เข้าถึงได้ทุกที่ทุกเวลา
            ผ่านอุปกรณ์มือถือที่มีอยู่แล้ว</li>
        <li><strong>เพื่อลดอัตราการขาดนัด</strong> ด้วยระบบแจ้งเตือนล่วงหน้าและ smart reminders</li>
        <li><strong>เพื่อยกระดับความปลอดภัยของผู้ป่วย</strong> ผ่านการเข้าถึงข้อมูลสุขภาพสำคัญ
            (กรุ๊ปเลือด, แพ้ยา, โรคประจำตัว) ในกรณีฉุกเฉิน</li>
        <li><strong>เพื่อลดภาระงานธุรการของเจ้าหน้าที่คลินิก</strong> ในงานที่สามารถ self-service ได้</li>
        <li><strong>เพื่อสร้างฐานข้อมูลกลาง</strong> สำหรับการวิเคราะห์เชิงสุขภาพและการวางแผนเชิงนโยบาย</li>
    </ol>

    <h3>3.2 กลุ่มเป้าหมาย</h3>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">👨‍🎓 นักศึกษา RSU</div>
            <div class="kpi-target">~28,000 คน</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">ทุกชั้นปี ทุกคณะ</p>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">👩‍🏫 บุคลากร / อาจารย์</div>
            <div class="kpi-target">~3,500 คน</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">รวมเจ้าหน้าที่สนับสนุน</p>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">🎓 นักศึกษาทุน</div>
            <div class="kpi-target">~200 คน</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">ลงเวลาทำงานในคลินิก</p>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">👥 บุคคลทั่วไป</div>
            <div class="kpi-target">~5,000 คน/ปี</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">ครอบครัว/ชุมชนรอบ</p>
        </div>
    </div>

    <div class="callout info">
        <strong>รวมกลุ่มเป้าหมายโดยตรง: ~36,700 คน/ปี</strong> ครอบคลุมการใช้งานทั้งแบบ <em>active user</em>
        (จองนัด, ตรวจสุขภาพ) และ <em>passive user</em> (รับประกาศ, ตรวจสิทธิ)
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 4. ขอบเขตและฟังก์ชันของระบบ                                     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">4</span>ขอบเขตและฟังก์ชันของระบบ <small style="font-size:11pt;color:#64748b;font-weight:500">(Scope &amp; Features)</small></h2>

    <p>ระบบ User Hub ประกอบด้วยฟังก์ชันหลัก <strong>6 หมวด</strong> รวม <strong>23 ฟีเจอร์</strong> ดังนี้</p>

    <h3>4.1 หมวด Identity &amp; Profile (อัตลักษณ์ผู้ใช้)</h3>
    <table>
        <tr><th style="width:35%">ฟีเจอร์</th><th style="width:50%">รายละเอียด</th><th style="width:15%">สถานะ</th></tr>
        <tr><td>เข้าสู่ระบบด้วย LINE</td><td>OAuth 2.0 ผ่าน LINE LIFF — ไม่ต้องจำรหัสผ่าน</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ลงทะเบียนข้อมูลส่วนตัว</td><td>ชื่อ-สกุล, เลขประจำตัว, เบอร์โทร, อีเมล, คณะ/หน่วยงาน</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>บันทึกข้อมูลสุขภาพ</td><td>กรุ๊ปเลือด · ส่วนสูง/น้ำหนัก · แพ้ยา/อาหาร · โรคประจำตัว · ผู้ติดต่อฉุกเฉิน</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>QR Code Member Card</td><td>แสดงให้สแกนหน้างานเพื่อลงทะเบียน ลดเวลาจาก 5 นาที → 30 วินาที</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>Identity Card บนหน้าหลัก</td><td>แสดงข้อมูลสำคัญ (กรุ๊ปเลือด, แพ้ยา, โรคประจำตัว) แบบ chips กรณีฉุกเฉิน</td><td><span class="status-done">เปิดใช้</span></td></tr>
    </table>

    <h3>4.2 หมวด Booking System (จองนัดออนไลน์)</h3>
    <table>
        <tr><th>ฟีเจอร์</th><th>รายละเอียด</th><th>สถานะ</th></tr>
        <tr><td>เรียกดูแคมเปญสุขภาพ</td><td>วัคซีน, ตรวจสุขภาพ, อบรม, ฯลฯ</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>จองนัดออนไลน์</td><td>เลือกแคมเปญ → วัน → เวลา → ยืนยัน (4 ขั้นตอน)</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>นัดหมายของฉัน</td><td>ดูทั้งที่จะมาถึง + ประวัติ + ยกเลิกได้</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>Check-in ด้วย QR</td><td>สแกน QR หน้าคลินิก → ลงทะเบียนอัตโนมัติ</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ส่งเข้าปฏิทินส่วนตัว</td><td>Export .ics สำหรับ Google/Apple Calendar</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>แจ้งเตือนล่วงหน้า</td><td>Push noti 1 วัน + 1 ชั่วโมง (Smart Reminders)</td><td><span class="status-done">เปิดใช้</span></td></tr>
    </table>

    <h3>4.3 หมวด Health Records (ประวัติสุขภาพ)</h3>
    <table>
        <tr><th>ฟีเจอร์</th><th>รายละเอียด</th><th>สถานะ</th></tr>
        <tr><td>ประวัติวัคซีน</td><td>รายการวัคซีนที่เคยฉีด + วัน next due + ดาวน์โหลดใบรับรอง</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ประวัติการเข้ารับบริการ</td><td>แสดงรายการ booking ที่ check-in แล้ว + สถานะ</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>แผนภูมิสัญญาณชีพ</td><td>BP, น้ำหนัก, อุณหภูมิ ตามช่วงเวลา</td><td><span class="status-plan">แผนระยะ 2</span></td></tr>
    </table>

    <h3>4.4 หมวด Clinic Services (บริการคลินิก)</h3>
    <table>
        <tr><th>ฟีเจอร์</th><th>รายละเอียด</th><th>สถานะ</th></tr>
        <tr><td>สมัครบัตรทอง (UC)</td><td>กรอกฟอร์ม + ลายเซ็นต์ดิจิทัล → ส่งให้คลินิกอนุมัติ</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ยืมอุปกรณ์การแพทย์ (e-Borrow)</td><td>เลือกอุปกรณ์ → กรอกเหตุผล → ส่งคำขอ → ติดตามสถานะ + คำนวณค่าปรับอัตโนมัติ</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ลงเวลานักศึกษาทุน</td><td>Clock-in/out + GPS verify + รออนุมัติเจ้าหน้าที่</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ข้อมูลสิทธิประกัน</td><td>แสดงประกันอุบัติเหตุ ฿250K + ค่ารักษา ฿40K (UC ผ่าน สปสช. ในแผนระยะ 2)</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ตารางแพทย์ออกตรวจ</td><td>FullCalendar view: เดือน/สัปดาห์/วัน — มีข้อมูลห้องและบริการ</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>ขอใบรับรองแพทย์</td><td>กรอกแบบฟอร์ม → admin อนุมัติ → ดาวน์โหลด PDF</td><td><span class="status-plan">แผนระยะ 2</span></td></tr>
    </table>

    <h3>4.5 หมวด Communication (สื่อสาร)</h3>
    <table>
        <tr><th>ฟีเจอร์</th><th>รายละเอียด</th><th>สถานะ</th></tr>
        <tr><td>ประกาศจากคลินิก</td><td>Pop-up carousel แสดงข่าวสาร + เก็บ read-tracking + รองรับ TH/EN</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>Live Chat กับเจ้าหน้าที่</td><td>แชทแบบ Real-time + Typing indicator + AI fallback</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>Real-time Notifications</td><td>การแจ้งเตือนเด้งทันที (Pusher) + Polling fallback</td><td><span class="status-done">เปิดใช้</span></td></tr>
        <tr><td>FAQ / ความช่วยเหลือ</td><td>ฐานความรู้คำถามที่พบบ่อย</td><td><span class="status-plan">แผนระยะ 2</span></td></tr>
    </table>

    <h3>4.6 หมวด User Experience (ประสบการณ์ใช้งาน)</h3>
    <div class="pill-row">
        <span class="pill">✨ ทักทายตามเวลา + Birthday Card</span>
        <span class="pill">🩺 Smart Reminders</span>
        <span class="pill">📡 Real-time Tick</span>
        <span class="pill">🔄 Pull-to-Refresh</span>
        <span class="pill">💀 Skeleton Loader</span>
        <span class="pill">♿ Keyboard A11y</span>
        <span class="pill">👆 Swipe Gestures</span>
        <span class="pill">📱 iOS Safe-Area</span>
        <span class="pill">🌗 Dark Mode Ready</span>
        <span class="pill">🇹🇭 Thai-First Design</span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 5. ผลที่คาดว่าจะได้รับ + ตัวชี้วัด                              -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">5</span>ผลที่คาดว่าจะได้รับและตัวชี้วัดความสำเร็จ</h2>

    <h3>5.1 ผลที่คาดว่าจะได้รับ</h3>
    <ul>
        <li><strong>ผู้ใช้ได้รับบริการสะดวกขึ้น</strong> — ไม่ต้องโทร ไม่ต้องเดิน ไม่ต้องจำเวลาทำการ</li>
        <li><strong>คลินิกบริหารจัดการทรัพยากรได้ดีขึ้น</strong> — ดูสถิติแบบ Real-time, วางแผนกำลังคนล่วงหน้า</li>
        <li><strong>เจ้าหน้าที่ลดภาระงานธุรการ</strong> ~30% ในช่วงเปิดเทอม</li>
        <li><strong>มหาวิทยาลัยมีฐานข้อมูลสุขภาพรวม</strong> สำหรับวางแผนสุขภาวะ (Healthy Campus)</li>
        <li><strong>สอดคล้องกับ PDPA และ ISO 27001</strong> ในการจัดการข้อมูลสุขภาพ</li>
    </ul>

    <h3>5.2 ตัวชี้วัด (KPIs)</h3>
    <table>
        <tr><th>ตัวชี้วัด</th><th>เป้าหมายปีแรก</th><th>วิธีวัด</th></tr>
        <tr><td>จำนวนผู้ลงทะเบียน Active</td><td>≥ 12,000 คน (33% ของกลุ่มเป้าหมาย)</td><td>นับจาก sys_users.line_user_id</td></tr>
        <tr><td>อัตรา No-show</td><td>&lt; 10% (จากเดิม ~20%)</td><td>booking ที่ไม่ check-in / booking ทั้งหมด</td></tr>
        <tr><td>จำนวนการจองนัดต่อเดือน</td><td>≥ 1,500 รายการ</td><td>camp_bookings ที่ status='booked'</td></tr>
        <tr><td>เวลาเฉลี่ยในการจองนัด</td><td>&lt; 60 วินาที</td><td>วัดจาก booking flow analytics</td></tr>
        <tr><td>Average Response Time (Chat)</td><td>&lt; 15 นาที (เวลาทำการ)</td><td>เวลา staff reply เฉลี่ย</td></tr>
        <tr><td>Real-time delivery rate (Pusher)</td><td>≥ 95%</td><td>Pusher event success rate</td></tr>
        <tr><td>คะแนนความพึงพอใจ (CSAT)</td><td>≥ 4.2/5.0</td><td>post-checkin survey</td></tr>
        <tr><td>Uptime ของระบบ</td><td>≥ 99%</td><td>Sentry/cron health check</td></tr>
    </table>

    <h3>5.3 ประโยชน์เชิงปริมาณ</h3>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">ลดเวลาลงทะเบียน</div>
            <div class="kpi-target">~4 นาที/คน</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">× 1,500 ราย/เดือน = 100 ชม.</p>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">ลด No-show</div>
            <div class="kpi-target">~10% → ประหยัด</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">~150 slot/เดือน คืนสู่ผู้ใช้อื่น</p>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">ลดการโทรหาคลินิก</div>
            <div class="kpi-target">~50%</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">คำถามทั่วไป self-service</p>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">เพิ่ม Engagement</div>
            <div class="kpi-target">×3 เท่า</div>
            <p style="font-size:10pt;color:#475569;margin:4px 0 0 0">ประกาศมี read-tracking</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 6. แผนการดำเนินงาน                                              -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">6</span>แผนการดำเนินงาน <small style="font-size:11pt;color:#64748b;font-weight:500">(Implementation Roadmap)</small></h2>

    <h3>6.1 ระยะที่ 1 — Core Platform (เสร็จสมบูรณ์)</h3>
    <div class="timeline">
        <div class="tl-item">
            <div class="tl-title">Q3-Q4 2568: ระบบจองนัด + Identity</div>
            <div class="tl-meta"><span class="status-done">เสร็จแล้ว</span> · Booking flow, QR check-in, profile, LINE login</div>
        </div>
        <div class="tl-item">
            <div class="tl-title">Q1 2569: e-Borrow + Scholarship + Gold Card</div>
            <div class="tl-meta"><span class="status-done">เสร็จแล้ว</span> · ยืมอุปกรณ์, ลงเวลานักศึกษาทุน, สมัคร UC</div>
        </div>
        <div class="tl-item">
            <div class="tl-title">Q2 2569: UX Polish + Real-time + Activity Dashboard</div>
            <div class="tl-meta"><span class="status-done">เสร็จแล้ว</span> · Smart reminders, Pusher, FullCalendar schedule view</div>
        </div>
    </div>

    <h3>6.2 ระยะที่ 2 — Expansion (Q3-Q4 2569)</h3>
    <div class="timeline">
        <div class="tl-item">
            <div class="tl-title">ใบรับรองแพทย์ดิจิทัล</div>
            <div class="tl-meta"><span class="status-progress">กำลังพัฒนา</span> · กรอกฟอร์ม → admin อนุมัติ → ดาวน์โหลด PDF พร้อมลายเซ็นต์ดิจิทัล</div>
        </div>
        <div class="tl-item">
            <div class="tl-title">FAQ / Knowledge Base</div>
            <div class="tl-meta"><span class="status-plan">แผน</span> · ฐานความรู้คำถามที่พบบ่อย + AI Search</div>
        </div>
        <div class="tl-item">
            <div class="tl-title">แผนภูมิสัญญาณชีพ (Vital Signs)</div>
            <div class="tl-meta"><span class="status-plan">แผน</span> · BP, น้ำหนัก, อุณหภูมิ ตามช่วงเวลา</div>
        </div>
        <div class="tl-item">
            <div class="tl-title">เชื่อมต่อ API สปสช. ตรวจสิทธิรักษา</div>
            <div class="tl-meta"><span class="status-plan">รอพิจารณา</span> · ต้องขึ้นทะเบียนหน่วยบริการก่อน</div>
        </div>
    </div>

    <h3>6.3 ระยะที่ 3 — Advanced (2570)</h3>
    <ul>
        <li><strong>Telemedicine</strong> — ปรึกษาแพทย์ออนไลน์ผ่าน video</li>
        <li><strong>Health Tracking</strong> — เชื่อมต่อ smartwatch/wearables</li>
        <li><strong>AI Symptom Checker</strong> — ประเมินอาการเบื้องต้นก่อนพบแพทย์</li>
        <li><strong>Multi-language</strong> — รองรับภาษาจีน/อังกฤษเต็มรูปแบบ (สำหรับนักศึกษาต่างชาติ)</li>
    </ul>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 7. งบประมาณและทรัพยากร                                          -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">7</span>งบประมาณและทรัพยากร <small style="font-size:11pt;color:#64748b;font-weight:500">(Budget &amp; Resources)</small></h2>

    <h3>7.1 ต้นทุนค่าใช้จ่ายต่อปี (Operating Cost)</h3>
    <table>
        <tr><th>หมวด</th><th>รายละเอียด</th><th style="text-align:right">งบประมาณ (บาท/ปี)</th></tr>
        <tr><td>Hosting / VPS</td><td>Server + Database + Backup</td><td style="text-align:right">~25,000</td></tr>
        <tr><td>Domain + SSL</td><td>healthycampus.rsu.ac.th</td><td style="text-align:right">~3,000</td></tr>
        <tr><td>LINE Messaging API</td><td>200K push/เดือน (Light Plan)</td><td style="text-align:right">~12,000</td></tr>
        <tr><td>Pusher Real-time</td><td>Sandbox plan ใช้ฟรี → Startup plan</td><td style="text-align:right">~18,000</td></tr>
        <tr><td>Gemini AI API</td><td>Chat + ประมวลผลภาพ schedule</td><td style="text-align:right">~24,000</td></tr>
        <tr><td>Sentry (Error Monitoring)</td><td>Developer plan</td><td style="text-align:right">~12,000</td></tr>
        <tr><td>Cron Service (cron-job.org)</td><td>Daily report + sync</td><td style="text-align:right">ฟรี</td></tr>
        <tr><td colspan="2" style="text-align:right;font-weight:800">รวมต้นทุนดำเนินงานต่อปี</td><td style="text-align:right;font-weight:800;background:#ecfdf5">~94,000 บาท</td></tr>
    </table>

    <h3>7.2 ต้นทุนการพัฒนาเพิ่มเติม (Phase 2)</h3>
    <table>
        <tr><th>รายการ</th><th>รายละเอียด</th><th style="text-align:right">งบประมาณ</th></tr>
        <tr><td>ใบรับรองแพทย์ดิจิทัล</td><td>UI + PDF template + admin approval (3-5 วัน)</td><td style="text-align:right">~25,000</td></tr>
        <tr><td>FAQ / Knowledge Base</td><td>CMS + AI search (5-7 วัน)</td><td style="text-align:right">~35,000</td></tr>
        <tr><td>แผนที่มหาวิทยาลัย + Wayfinding</td><td>Leaflet + indoor SVG (3-5 วัน)</td><td style="text-align:right">~30,000</td></tr>
        <tr><td>แผนภูมิสัญญาณชีพ</td><td>Data model + Chart.js viz (5-7 วัน)</td><td style="text-align:right">~30,000</td></tr>
        <tr><td>เชื่อม API สปสช.</td><td>ขึ้นกับการอนุมัติของ สปสช.</td><td style="text-align:right">~40,000</td></tr>
        <tr><td colspan="2" style="text-align:right;font-weight:800">รวมต้นทุน Phase 2</td><td style="text-align:right;font-weight:800;background:#fef3c7">~160,000 บาท</td></tr>
    </table>

    <h3>7.3 ทรัพยากรบุคคล</h3>
    <ul>
        <li><strong>นักพัฒนาระบบ (Full-stack)</strong> 1 คน — พัฒนาและดูแลระบบ</li>
        <li><strong>ผู้ดูแลระบบ (DevOps)</strong> 1 คน — server, backup, security</li>
        <li><strong>เจ้าหน้าที่คลินิก (Admin)</strong> 2-3 คน — ตอบ chat, อนุมัติคำขอ, ดูแลเนื้อหา</li>
        <li><strong>ที่ปรึกษาด้าน PDPA</strong> ตามวาระ — review consent flow และ data retention policy</li>
    </ul>

    <div class="callout">
        <strong>ROI ที่คาดหวัง:</strong> ต้นทุนดำเนินงาน ~94,000 บาท/ปี เทียบกับการประหยัดเวลาเจ้าหน้าที่
        ~100 ชม./เดือน (ค่าจ้างเฉลี่ย 200 บ./ชม. × 12 เดือน) = <strong>~240,000 บาท/ปี</strong>
        + ผลประโยชน์ทางอ้อม (ลด no-show, เพิ่มความพึงพอใจ) = <strong>ROI &gt; 250%</strong>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 8. สถาปัตยกรรมระบบและการรักษาความปลอดภัย                       -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">8</span>สถาปัตยกรรมระบบและความปลอดภัย <small style="font-size:11pt;color:#64748b;font-weight:500">(Architecture &amp; Security)</small></h2>

    <h3>8.1 Technology Stack</h3>
    <table>
        <tr><th>Layer</th><th>เทคโนโลยี</th></tr>
        <tr><td>Frontend (User)</td><td>HTML5, Tailwind CSS, Vanilla JS, FullCalendar, Chart.js, Pusher Client</td></tr>
        <tr><td>Backend</td><td>PHP 8.x, MySQL 8.x (InnoDB), PDO</td></tr>
        <tr><td>Authentication</td><td>LINE LIFF + LINE OAuth 2.0 + Session</td></tr>
        <tr><td>Real-time</td><td>Pusher Channels (+ polling fallback)</td></tr>
        <tr><td>AI / NLP</td><td>Google Gemini 2.5 Flash (chat + schedule image parsing)</td></tr>
        <tr><td>Notifications</td><td>LINE Messaging API (Push, Flex Messages)</td></tr>
        <tr><td>Monitoring</td><td>Sentry (errors), sys_activity_logs (audit), sys_error_logs</td></tr>
        <tr><td>Scheduling</td><td>cron-job.org (daily report, rich menu sync, expired bookings)</td></tr>
    </table>

    <h3>8.2 การรักษาความปลอดภัย (PDPA + ISO 27001)</h3>
    <ul>
        <li><strong>การเข้ารหัส:</strong> HTTPS/TLS 1.3 ทุก endpoint · Password hashing (bcrypt)</li>
        <li><strong>การควบคุมการเข้าถึง:</strong> Role-based access control (RBAC) · Session timeout</li>
        <li><strong>CSRF Protection:</strong> ทุก POST endpoint validate token</li>
        <li><strong>Mask ข้อมูลอ่อนไหว:</strong> เลขประจำตัวประชาชนแสดงเป็น <code>3xx****xx89</code></li>
        <li><strong>Audit Trail:</strong> sys_activity_logs บันทึก action ทั้งหมด (พร้อม IP + UA)</li>
        <li><strong>PDPA Consent:</strong> หน้า consent.php ก่อนใช้บริการครั้งแรก</li>
        <li><strong>Data Retention:</strong> Activity logs 90 วัน · Error logs 30 วัน (ตาม retention policy)</li>
        <li><strong>Backup:</strong> Daily backup ลง encrypted storage + offsite copy</li>
    </ul>

    <h3>8.3 ข้อมูลที่จัดเก็บ (Privacy Scope)</h3>
    <table>
        <tr><th>หมวด</th><th>ประเภทข้อมูล</th><th>Retention</th></tr>
        <tr><td>Identity</td><td>ชื่อ-สกุล, citizen_id, line_user_id, รูปโปรไฟล์</td><td>ตลอดอายุการใช้บริการ + 5 ปี</td></tr>
        <tr><td>Medical</td><td>กรุ๊ปเลือด, แพ้ยา, โรคประจำตัว, ประวัติวัคซีน</td><td>10 ปี (ตามมาตรฐานการแพทย์)</td></tr>
        <tr><td>Behavioral</td><td>Activity log, IP address, user agent</td><td>90 วัน</td></tr>
        <tr><td>Booking</td><td>นัดหมาย, check-in time, survey result</td><td>5 ปี</td></tr>
        <tr><td>Chat</td><td>ข้อความสนทนากับเจ้าหน้าที่</td><td>1 ปี</td></tr>
    </table>

    <h3>8.4 การประกันคุณภาพ</h3>
    <ul>
        <li><strong>Code Review:</strong> ทุก commit ผ่านการ review ก่อน merge เข้า main</li>
        <li><strong>Type Safety:</strong> ใช้ <code>declare(strict_types=1)</code> ใน PHP ทุกไฟล์</li>
        <li><strong>Error Monitoring:</strong> Sentry แจ้งเตือนทันทีเมื่อพบ error production</li>
        <li><strong>Health Check:</strong> Cron monitor uptime ทุก 5 นาที</li>
        <li><strong>Documentation:</strong> CLAUDE.md (developer guide) · AI_GUIDE.md · E_CAMPAIGN_GUIDE.md</li>
    </ul>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 9. ประเมินมูลค่าระบบที่พัฒนาแล้ว                                 -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">9</span>ประเมินมูลค่าระบบที่พัฒนาแล้ว <small style="font-size:11pt;color:#64748b;font-weight:500">(Asset Valuation)</small></h2>

    <p>
        การประเมินมูลค่าระบบที่พัฒนาเสร็จแล้ว เพื่อใช้ประกอบการพิจารณาขออนุมัติงบประมาณดูแลและพัฒนาต่อ
        ครอบคลุมงานพัฒนา <strong>ฝั่งผู้รับบริการ (User-side)</strong> ทั้งหมด
        ขนาดโค้ดรวม <strong>~14,200 บรรทัด</strong> ใน 30+ ไฟล์
    </p>

    <h3>9.1 วิธีประเมิน <small style="font-size:11pt;color:#64748b;font-weight:500">(Methodology)</small></h3>
    <ul>
        <li><strong>Bottom-up Estimation</strong> — แตกระบบเป็น 10 หมวด กำหนด person-days ต่อหมวด</li>
        <li><strong>Blended Day Rate</strong> — ฿5,000/วัน (Mid-Senior Full-stack Developer in Thailand, 2026)</li>
        <li><strong>Low/High Range</strong> — Low = junior rate ฿3,000/วัน · High = vendor outsource ฿9,000/วัน (1.8× factor)</li>
        <li><strong>ครอบคลุม</strong> — เฉพาะงาน development (ไม่รวม UI/UX design, testing manual, requirement gathering, deployment ops)</li>
    </ul>

    <h3>9.2 ประเมินต่อหมวดงาน <small style="font-size:11pt;color:#64748b;font-weight:500">(Feature-by-feature Breakdown)</small></h3>
    <table>
        <tr>
            <th style="width:5%">#</th>
            <th style="width:50%">หมวดงาน</th>
            <th style="width:15%;text-align:right">Person-Days</th>
            <th style="width:30%;text-align:right">มูลค่าประเมิน (฿)</th>
        </tr>
        <tr>
            <td><strong>A</strong></td>
            <td><strong>Identity &amp; Authentication</strong><br><span style="font-size:10pt;color:#64748b">LINE LIFF + OAuth, profile + medical fields, QR identity card, mask CID</span></td>
            <td style="text-align:right">9</td>
            <td style="text-align:right">45,000</td>
        </tr>
        <tr>
            <td><strong>B</strong></td>
            <td><strong>Booking System</strong><br><span style="font-size:10pt;color:#64748b">Browse campaigns → 4-step flow → my bookings + cancel + QR check-in + iCal export + post-checkin survey</span></td>
            <td style="text-align:right">19</td>
            <td style="text-align:right">95,000</td>
        </tr>
        <tr>
            <td><strong>C</strong></td>
            <td><strong>Health Records</strong><br><span style="font-size:10pt;color:#64748b">Vaccination history (AJAX + search + pagination), visit history</span></td>
            <td style="text-align:right">4</td>
            <td style="text-align:right">20,000</td>
        </tr>
        <tr>
            <td><strong>D</strong></td>
            <td><strong>Service Modules</strong><br><span style="font-size:10pt;color:#64748b">e-Borrow 3-step wizard, gold card + signature, scholarship clock-in + GPS, insurance display, FullCalendar schedule</span></td>
            <td style="text-align:right">24</td>
            <td style="text-align:right">120,000</td>
        </tr>
        <tr>
            <td><strong>E</strong></td>
            <td><strong>Hub Page Architecture</strong><br><span style="font-size:10pt;color:#64748b">3-tab dashboard, smart hero priority logic, quick stats, doctor schedule widget, bottom nav + FAB</span></td>
            <td style="text-align:right">8</td>
            <td style="text-align:right">40,000</td>
        </tr>
        <tr>
            <td><strong>F</strong></td>
            <td><strong>UX Polish</strong><br><span style="font-size:10pt;color:#64748b">Greeting + HBD, smart reminders, Pusher real-time, pull-to-refresh, skeleton, toast, medical chips, a11y carousel, chat UX, modal portal-escape, e-Borrow mobile polish</span></td>
            <td style="text-align:right">10</td>
            <td style="text-align:right">50,000</td>
        </tr>
        <tr>
            <td><strong>G</strong></td>
            <td><strong>Communication</strong><br><span style="font-size:10pt;color:#64748b">Announcement carousel (TH/EN + read tracking), AI chat fallback, contact modal</span></td>
            <td style="text-align:right">8</td>
            <td style="text-align:right">40,000</td>
        </tr>
        <tr>
            <td><strong>H</strong></td>
            <td><strong>LINE Integration</strong><br><span style="font-size:10pt;color:#64748b">Rich Menu sync system (validation + audit + chunked), webhook handlers, daily report cron to LINE group</span></td>
            <td style="text-align:right">11</td>
            <td style="text-align:right">55,000</td>
        </tr>
        <tr>
            <td><strong>I</strong></td>
            <td><strong>Backend / Infrastructure</strong><br><span style="font-size:10pt;color:#64748b">DB schema (15+ tables), 15+ AJAX endpoints, CSRF + session, error logging, activity audit + Activity Dashboard</span></td>
            <td style="text-align:right">13</td>
            <td style="text-align:right">65,000</td>
        </tr>
        <tr>
            <td><strong>J</strong></td>
            <td><strong>Documentation</strong><br><span style="font-size:10pt;color:#64748b">CLAUDE.md, AI_GUIDE.md, E_CAMPAIGN_GUIDE.md, proposal document with exports</span></td>
            <td style="text-align:right">3</td>
            <td style="text-align:right">15,000</td>
        </tr>
        <tr style="background:#ecfdf5">
            <td colspan="2" style="text-align:right;font-weight:800;font-size:12pt">รวมทั้งหมด (Mid Rate ฿5,000/วัน)</td>
            <td style="text-align:right;font-weight:800;font-size:13pt">109 วัน</td>
            <td style="text-align:right;font-weight:800;font-size:14pt;color:#0f7349">฿545,000</td>
        </tr>
    </table>

    <h3>9.3 สรุปต้นทุนตามช่วงราคา <small style="font-size:11pt;color:#64748b;font-weight:500">(Cost Range)</small></h3>
    <table>
        <tr>
            <th>ประเภท</th>
            <th>Day Rate</th>
            <th>คำอธิบาย</th>
            <th style="text-align:right">มูลค่ารวม</th>
        </tr>
        <tr>
            <td><strong>Low (Junior)</strong></td>
            <td>฿3,000/วัน</td>
            <td>นักพัฒนาระดับ junior หรือ in-house มหาวิทยาลัย</td>
            <td style="text-align:right;color:#16a34a;font-weight:800">฿327,000</td>
        </tr>
        <tr style="background:#ecfdf5">
            <td><strong>Mid (Senior In-house)</strong></td>
            <td>฿5,000/วัน</td>
            <td>นักพัฒนาระดับ senior ทำเอง (most likely)</td>
            <td style="text-align:right;color:#0f7349;font-weight:800;font-size:13pt">฿545,000</td>
        </tr>
        <tr>
            <td><strong>High (Vendor)</strong></td>
            <td>฿9,000/วัน</td>
            <td>จ้าง vendor บริษัทพัฒนา + project management + warranty</td>
            <td style="text-align:right;color:#dc2626;font-weight:800">฿981,000</td>
        </tr>
    </table>

    <div class="callout">
        <strong>ช่วงมูลค่าที่สมเหตุสมผล: ฿450,000 — ฿900,000</strong>
        — ขึ้นกับว่าใช้บุคลากร in-house หรือ outsource
        และจะคิด overhead (PM, QA, Design, DevOps) เข้าไปด้วยหรือไม่
    </div>

    <h3>9.4 เปรียบเทียบกับการจ้าง Vendor ใหม่</h3>
    <p>หากไม่ทำเองในมหาวิทยาลัย และจ้าง vendor พัฒนาระบบลักษณะนี้ใหม่ทั้งหมด ต้องคิดต้นทุนเพิ่ม:</p>
    <table>
        <tr>
            <th>รายการ</th>
            <th style="text-align:right">% ของ Dev Cost</th>
            <th style="text-align:right">มูลค่าโดยประมาณ (฿)</th>
        </tr>
        <tr>
            <td>UI/UX Design (Figma + Wireframe + Mockup)</td>
            <td style="text-align:right">20%</td>
            <td style="text-align:right">100,000 — 200,000</td>
        </tr>
        <tr>
            <td>Project Management + Business Analysis</td>
            <td style="text-align:right">15%</td>
            <td style="text-align:right">75,000 — 150,000</td>
        </tr>
        <tr>
            <td>QA / Testing (Manual + Automated)</td>
            <td style="text-align:right">15%</td>
            <td style="text-align:right">75,000 — 150,000</td>
        </tr>
        <tr>
            <td>DevOps / Deployment / CI-CD setup</td>
            <td style="text-align:right">5%</td>
            <td style="text-align:right">25,000 — 50,000</td>
        </tr>
        <tr>
            <td>Warranty + Bug fix 3-6 เดือน</td>
            <td style="text-align:right">10%</td>
            <td style="text-align:right">50,000 — 100,000</td>
        </tr>
        <tr>
            <td>Profit Margin ของ Vendor (20-30%)</td>
            <td style="text-align:right">25%</td>
            <td style="text-align:right">130,000 — 260,000</td>
        </tr>
        <tr style="background:#fff7ed">
            <td colspan="2" style="text-align:right;font-weight:800">รวมทั้ง overhead</td>
            <td style="text-align:right;font-weight:800;font-size:13pt;color:#9a3412">฿1,000,000 — ฿1,800,000</td>
        </tr>
    </table>

    <div class="callout warn">
        <strong>📊 ข้อสรุป:</strong> หากจ้าง vendor พัฒนาระบบลักษณะนี้ใหม่ทั้งหมด
        จะใช้งบประมาณ <strong>฿1.0 — ฿1.8 ล้านบาท</strong>
        ระบบที่พัฒนาเสร็จแล้วในมหาวิทยาลัย จึงมีมูลค่าเทียบเท่ากับ
        <strong>"ทรัพย์สินทางปัญญา (IP Asset)"</strong> ที่ประหยัดงบประมาณให้มหาวิทยาลัยได้ราว
        <strong>฿500,000 — ฿1,200,000</strong>
    </div>

    <h3>9.5 มูลค่าเชิงคุณภาพที่ประเมินเป็นเงินยาก <small style="font-size:11pt;color:#64748b;font-weight:500">(Intangible Value)</small></h3>
    <ul>
        <li><strong>Vendor Lock-in Avoidance</strong> — เป็นเจ้าของ source code 100% แก้ไขได้ทันทีโดยไม่ต้องจ่ายค่าจ้างเพิ่ม</li>
        <li><strong>Domain Knowledge</strong> — ทีมพัฒนาเข้าใจบริบทคลินิก/มหาวิทยาลัย ลด miscommunication</li>
        <li><strong>Speed of Iteration</strong> — แก้ requirement ใหม่ได้ภายใน 1-3 วัน (vendor: 2-4 สัปดาห์)</li>
        <li><strong>Compliance Confidence</strong> — ข้อมูล user อยู่ใน infrastructure ของมหาวิทยาลัย (PDPA-friendly)</li>
        <li><strong>Educational Value</strong> — นักศึกษา IT/CS ได้เรียนรู้จากระบบจริง ใช้เป็น case study ได้</li>
    </ul>

    <div class="doc-footer">
        เอกสารนี้จัดทำขึ้นเพื่อเสนอต่อผู้บริหารมหาวิทยาลัยรังสิตและศูนย์บริการสุขภาพ<br>
        © 2569 RSU Medical Clinic Services · เวอร์ชัน 1.1 · พฤษภาคม 2569
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- Floating action panel (hidden when printing)                    -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="no-print" style="position:fixed;bottom:16px;right:16px;display:flex;flex-direction:column;gap:8px;z-index:9999">
    <button id="btn-print" class="no-print-tip" onclick="window.print()" style="background:#475569">
        🖨️ พิมพ์ (Print)
    </button>
    <button id="btn-pdf" class="no-print-tip" onclick="downloadPdf()" style="background:#dc2626">
        📕 ดาวน์โหลด PDF
    </button>
    <button id="btn-doc" class="no-print-tip" onclick="exportToWord()" style="background:#2563eb">
        📘 ดาวน์โหลด .doc (Word)
    </button>
</div>

<!-- html2pdf.js — used by the direct PDF download button -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
/**
 * Download proposal as a real PDF file (no print dialog).
 * Uses html2pdf.js — html2canvas + jsPDF under the hood.
 *
 * Strategy:
 *  - Clone the .page wrappers (skip floating buttons)
 *  - Each .page becomes its own A4 page via CSS page-break
 *  - Render via html2canvas at 2x scale for sharper text
 *  - Embed pages into jsPDF; trigger blob download
 */
async function downloadPdf() {
    const btn = document.getElementById('btn-pdf');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ กำลังสร้าง PDF...';

    try {
        // Build a clean container with just the document pages
        const container = document.createElement('div');
        container.style.background = '#fff';
        container.style.padding = '0';
        document.querySelectorAll('.page').forEach(p => {
            const cloned = p.cloneNode(true);
            // Remove floating button overlays inside the clone
            cloned.querySelectorAll('.no-print').forEach(el => el.remove());
            // Reset visual margins / shadow for clean PDF output
            cloned.style.margin = '0';
            cloned.style.boxShadow = 'none';
            cloned.style.maxWidth = '100%';
            cloned.style.pageBreakAfter = 'always';
            container.appendChild(cloned);
        });

        const filename = 'RSU_User_Hub_Proposal_' + new Date().toISOString().substring(0,10) + '.pdf';
        const opt = {
            margin:       [10, 10, 12, 10], // mm: top, right, bottom, left
            filename:     filename,
            image:        { type: 'jpeg', quality: 0.95 },
            html2canvas:  {
                scale: 2,
                useCORS: true,
                logging: false,
                letterRendering: true,
                allowTaint: true,
            },
            jsPDF:        {
                unit: 'mm',
                format: 'a4',
                orientation: 'portrait',
                compress: true,
            },
            pagebreak:    { mode: ['css', 'legacy'], avoid: ['table', '.callout', '.kpi-grid', '.timeline'] },
        };

        await html2pdf().set(opt).from(container).save();
    } catch (e) {
        console.error('PDF generation failed:', e);
        alert('สร้าง PDF ไม่สำเร็จ ลองใช้ปุ่ม "พิมพ์" แล้วเลือก Save as PDF แทนได้ครับ\n\n' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = origText;
    }
}
</script>

<script>
/**
 * Export the current proposal page as a .doc file that opens cleanly in
 * Microsoft Word / LibreOffice / Google Docs.
 *
 * Technique: wrap the document body in a Word-namespaced HTML container,
 * inline a Word-friendly stylesheet (replaces complex CSS like gradients
 * and CSS variables that Word ignores), then trigger a Blob download with
 * application/msword MIME + .doc extension.
 */
function exportToWord() {
    // Clone the current document body, then strip elements that won't
    // render in Word (floating buttons, scripts)
    const clone = document.body.cloneNode(true);
    clone.querySelectorAll('.no-print, script').forEach(el => el.remove());

    // Strip the cover gradient background — Word renders gradients poorly
    clone.querySelectorAll('.cover').forEach(el => {
        el.style.background = '#fff';
        el.style.padding = '40px';
        el.style.minHeight = 'auto';
    });

    // Replace shadow on .page with simple border (Word doesn't render box-shadow)
    clone.querySelectorAll('.page').forEach(el => {
        el.style.boxShadow = 'none';
        el.style.margin = '0 auto 28px auto';
        el.style.padding = '24px 32px';
        el.style.border = '1px solid #d1d5db';
    });

    // Word-friendly stylesheet — most browser CSS still works, but
    // we override what doesn't render properly
    const wordCss = `
        @page {
            size: A4;
            margin: 2.5cm 2cm 2.5cm 2cm;
            mso-page-orientation: portrait;
        }
        body {
            font-family: 'Sarabun', 'TH Sarabun New', 'Cordia New', Arial, sans-serif;
            font-size: 13pt;
            color: #1f2937;
            line-height: 1.55;
        }
        h1 { font-size: 22pt; color: #0f172a; }
        h2 {
            font-size: 17pt;
            color: #0f7349;
            border-bottom: 2px solid #0f7349;
            padding-bottom: 6px;
            margin-top: 18pt;
            page-break-after: avoid;
        }
        h3 { font-size: 13pt; color: #0f172a; page-break-after: avoid; }
        h4 { font-size: 12pt; color: #334155; }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 8pt 0;
        }
        table, th, td { border: 1px solid #94a3b8; }
        th { background: #ecfdf5; padding: 6pt 8pt; text-align: left; font-weight: bold; }
        td { padding: 6pt 8pt; vertical-align: top; }
        .callout {
            background: #f0fdf4;
            border-left: 4px solid #0f7349;
            padding: 8pt 12pt;
            margin: 8pt 0;
        }
        .pill, .status-done, .status-progress, .status-plan {
            display: inline-block;
            padding: 1pt 6pt;
            border: 1px solid #94a3b8;
            border-radius: 4pt;
            font-size: 10pt;
            background: #f1f5f9;
        }
        .status-done { background: #dcfce7; color: #166534; border-color: #86efac; }
        .status-progress { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .status-plan { background: #e0e7ff; color: #3730a3; border-color: #a5b4fc; }
        .kpi-grid {
            display: table;
            width: 100%;
            margin: 8pt 0;
        }
        .kpi-card {
            display: table-cell;
            width: 50%;
            border: 1px solid #cbd5e1;
            padding: 8pt 12pt;
            vertical-align: top;
        }
        .kpi-card .kpi-target { font-size: 14pt; color: #0f7349; font-weight: bold; }
        .timeline { padding-left: 16pt; }
        .tl-item { margin-bottom: 8pt; }
        .tl-item .tl-title { font-weight: bold; color: #0f172a; }
        .tl-item .tl-meta { font-size: 10pt; color: #64748b; }
        .doc-footer {
            text-align: center;
            font-size: 9pt;
            color: #64748b;
            margin-top: 24pt;
            padding-top: 8pt;
            border-top: 1px solid #cbd5e1;
        }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: avoid; }
    `;

    // Build the final HTML — note the Office namespaces are critical for Word
    const header = `<!DOCTYPE html>
<html xmlns:o='urn:schemas-microsoft-com:office:office'
      xmlns:w='urn:schemas-microsoft-com:office:word'
      xmlns='http://www.w3.org/TR/REC-html40'>
<head>
<meta charset='utf-8'>
<title>โครงการพัฒนาระบบบริการสุขภาพออนไลน์สำหรับผู้รับบริการ — User Hub</title>
<!--[if gte mso 9]>
<xml>
    <w:WordDocument>
        <w:View>Print</w:View>
        <w:Zoom>100</w:Zoom>
        <w:DoNotPromptForConvert/>
        <w:DoNotShowRevisions/>
        <w:DoNotPrintRevisions/>
        <w:DoNotShowMarkup/>
        <w:DoNotShowComments/>
        <w:DoNotShowInsertionsAndDeletions/>
        <w:DoNotShowPropertyChanges/>
    </w:WordDocument>
</xml>
<![endif]-->
<style>${wordCss}</style>
</head>
<body>`;
    const footer = `</body></html>`;

    const html = header + clone.innerHTML + footer;

    // UTF-8 BOM + msword MIME triggers .doc handler
    const blob = new Blob(['﻿', html], {
        type: 'application/msword;charset=utf-8',
    });

    const filename = 'RSU_User_Hub_Proposal_' + new Date().toISOString().substring(0,10) + '.doc';
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }, 100);
}
</script>

</body>
</html>
