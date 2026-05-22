<?php
// docs/admin_manual.php
// ─────────────────────────────────────────────────────────────────────────────
// Admin/staff manual for RSU Medical Clinic Portal. Restricted to portal
// admins (superadmin or admin role) because it documents access controls
// and audit log policies.
// ─────────────────────────────────────────────────────────────────────────────
declare(strict_types=1);
session_start();

$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin' && $adminRole !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>Access Denied</title>
    <style>body{font-family:sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}
    .box{background:#fff;padding:40px 56px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:480px}
    h1{color:#dc2626;margin:0 0 8px 0}p{color:#64748b}a{color:#0f7349;font-weight:700}</style>
    </head><body><div class="box"><h1>🛡️ Access Denied</h1>
    <p>คู่มือ Admin สำหรับเจ้าหน้าที่ระดับผู้ดูแลระบบเท่านั้น</p>
    <a href="/rsu-clinic/admin/auth/login.php">→ เข้าสู่ระบบ</a>
    </div></body></html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>คู่มือการใช้งานระบบสำหรับเจ้าหน้าที่ — RSU Medical Clinic</title>
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
    .cover {
        min-height: 920px;
        background: linear-gradient(135deg, #eef2ff 0%, #ddd6fe 60%, #fef3c7 100%);
        display: flex; flex-direction: column; justify-content: space-between;
        padding: 60px 40px; border-radius: 4px;
        page-break-after: always;
    }
    .cover h1 { font-size: 28pt; font-weight: 800; color: #0f172a; margin: 18px 0 12px 0; line-height: 1.3; }
    .cover .brand { font-size: 12pt; font-weight: 800; color: #4f46e5; letter-spacing: 0.18em; text-transform: uppercase; }
    .cover .subtitle { font-size: 14pt; color: #334155; font-weight: 600; margin-top: 8px; }
    .cover .top { text-align: center; }

    .page h2 {
        font-size: 18pt; color: #4f46e5; font-weight: 800;
        margin: 0 0 14px 0; padding-bottom: 8px;
        border-bottom: 2px solid #ddd6fe;
    }
    .page h2 .num {
        display: inline-block; background: #4f46e5; color: #fff;
        width: 28px; height: 28px; line-height: 28px;
        border-radius: 50%; text-align: center; font-size: 13pt;
        margin-right: 10px; vertical-align: middle;
    }
    .page h3 { font-size: 14pt; color: #0f172a; font-weight: 800; margin: 18px 0 8px 0; }
    .page p { margin: 6px 0 10px 0; }
    .page ul, .page ol { margin: 6px 0 10px 0; padding-left: 22px; }
    .page li { margin: 4px 0; }
    .page strong { color: #0f172a; }
    code {
        background: #f1f5f9; color: #be185d;
        padding: 1px 6px; border-radius: 4px;
        font-family: ui-monospace, monospace; font-size: 11pt;
    }

    /* Step cards */
    .step-card {
        background: #eef2ff; border-left: 4px solid #4f46e5;
        padding: 12px 16px; margin: 10px 0; border-radius: 6px;
    }
    .step-card .step-num {
        display: inline-block; background: #4f46e5; color: #fff;
        width: 22px; height: 22px; line-height: 22px;
        border-radius: 50%; text-align: center; font-weight: 800;
        font-size: 10pt; margin-right: 6px;
    }
    .step-card h4 {
        font-size: 12pt; color: #4338ca; margin: 0 0 4px 0; font-weight: 800;
        display: inline-block;
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
        margin: 12px 0;
        background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 8px;
        padding: 28px 16px; text-align: center; color: #94a3b8;
    }
    .screenshot .icon { font-size: 22pt; display: block; margin-bottom: 6px; color: #cbd5e1; }
    .screenshot .label { font-weight: 800; color: #475569; margin-bottom: 4px; font-size: 11pt; }
    .screenshot .hint { font-size: 9pt; font-style: italic; }

    /* Tables */
    table {
        width: 100%; border-collapse: collapse;
        margin: 8pt 0; font-size: 11pt;
    }
    th, td { border: 1px solid #e2e8f0; padding: 6pt 8pt; text-align: left; vertical-align: top; }
    th { background: #eef2ff; font-weight: 800; color: #3730a3; }
    tr:nth-child(even) td { background: #f8fafc; }

    /* Role badges */
    .role-badge {
        display: inline-block; padding: 1pt 8pt;
        border-radius: 99px; font-size: 10pt; font-weight: 800;
    }
    .role-super { background: #fee2e2; color: #991b1b; }
    .role-admin { background: #ede9fe; color: #5b21b6; }
    .role-staff { background: #dbeafe; color: #1e40af; }

    /* TOC */
    .toc {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 12px; padding: 18px 22px; margin: 14px 0;
    }
    .toc-item {
        display: flex; align-items: center; gap: 10px;
        padding: 6px 0; border-bottom: 1px dashed #e2e8f0;
    }
    .toc-item:last-child { border-bottom: 0; }
    .toc-item .toc-num {
        background: #4f46e5; color: #fff;
        width: 26px; height: 26px; line-height: 26px;
        border-radius: 50%; text-align: center; font-weight: 800; font-size: 10pt;
        flex-shrink: 0;
    }
    .toc-item .toc-text { flex: 1; font-weight: 700; color: #0f172a; }
    .toc-item .toc-page { font-size: 10pt; color: #94a3b8; font-weight: 700; }

    /* Action panel */
    .no-print-tip {
        background: #4f46e5; color: #fff;
        padding: 10px 16px; border-radius: 8px;
        font-size: 11pt; font-weight: 700; border: 0;
        box-shadow: 0 4px 16px rgba(79,70,229,0.3);
        cursor: pointer; transition: transform .12s ease;
    }
    .no-print-tip:hover { transform: translateY(-1px); }
    .no-print-tip:disabled { opacity: 0.65; cursor: wait; }

    @media print {
        body { background: #fff; font-size: 12pt; }
        .page { box-shadow: none; margin: 0; max-width: 100%; padding: 0; }
        .cover { min-height: 100vh; page-break-after: always; }
        h2, h3 { page-break-after: avoid; }
        .step-card, .callout, .screenshot, table { page-break-inside: avoid; }
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

<!-- COVER -->
<div class="page cover">
    <div class="top">
        <div class="brand">RSU Medical Clinic Services</div>
        <div style="font-size:48pt; margin: 24px 0 8px 0;">🔧</div>
        <h1>คู่มือการใช้งานระบบ<br>สำหรับเจ้าหน้าที่</h1>
        <div class="subtitle">Admin Manual — Portal Operations Guide</div>
        <p style="margin-top:30px; color:#64748b; font-size:11pt">
            จัดการแคมเปญ · อนุมัติคำขอ · ตอบ chat · ดู Activity Dashboard<br>
            · ส่งรีพอร์ตเข้ากลุ่ม LINE · ดูแล LINE Rich Menu
        </p>
    </div>
    <div style="background:rgba(255,255,255,0.7); border:1px solid rgba(79,70,229,0.2); border-radius:12px; padding:20px 24px; font-size:11pt">
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">เป้าหมาย</span><span style="font-weight:600;color:#0f172a">เจ้าหน้าที่ระดับ <span class="role-badge role-super">superadmin</span> และ <span class="role-badge role-admin">admin</span></span></div>
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">URL Portal</span><span style="font-weight:600;color:#0f172a">healthycampus.rsu.ac.th/rsu-clinic/portal/</span></div>
        <div style="display:flex;justify-content:space-between;margin:3px 0"><span style="font-weight:700;color:#475569">เวอร์ชัน</span><span style="font-weight:600;color:#0f172a">v1.0 — พฤษภาคม 2569</span></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 1. ภาพรวม + Login                                                -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">1</span>ภาพรวมและการเข้าสู่ระบบ</h2>

    <p>
        <strong>Portal Admin</strong> คือพื้นที่จัดการระบบสำหรับเจ้าหน้าที่ — ครอบคลุมการจัดการแคมเปญสุขภาพ
        อนุมัติคำขอบริการ ตอบแชทผู้ใช้ ดูสถิติแบบ real-time และตั้งค่าระบบ
    </p>

    <div class="toc">
        <strong style="display:block;margin-bottom:8px;color:#0f172a;font-size:13pt">📑 สารบัญ</strong>
        <div class="toc-item"><span class="toc-num">1</span><span class="toc-text">เข้าสู่ระบบและภาพรวม Dashboard</span><span class="toc-page">หน้า 2</span></div>
        <div class="toc-item"><span class="toc-num">2</span><span class="toc-text">จัดการแคมเปญและการจอง</span><span class="toc-page">หน้า 3</span></div>
        <div class="toc-item"><span class="toc-num">3</span><span class="toc-text">บริการคลินิก (Gold Card · e-Borrow · ทุน)</span><span class="toc-page">หน้า 4</span></div>
        <div class="toc-item"><span class="toc-num">4</span><span class="toc-text">Activity Dashboard และระบบเอกสาร</span><span class="toc-page">หน้า 5</span></div>
    </div>

    <h3>1.1 บัญชีและสิทธิ์การเข้าใช้งาน</h3>
    <table>
        <tr><th style="width:25%">ระดับสิทธิ์</th><th>เข้าถึงได้</th></tr>
        <tr>
            <td><span class="role-badge role-super">superadmin</span></td>
            <td>ทุกเมนู — รวม Activity Dashboard, Identity Governance, Settings, คลังเอกสาร, AI Suite</td>
        </tr>
        <tr>
            <td><span class="role-badge role-admin">admin</span></td>
            <td>ทุกเมนูยกเว้น Activity Dashboard, Identity แก้สิทธิ์ admin ระดับสูงกว่า, Settings ระบบ</td>
        </tr>
        <tr>
            <td><span class="role-badge role-staff">staff</span></td>
            <td>เฉพาะหน่วยงานของตัวเอง: e-Borrow / Insurance / Asset / Consumables ตาม access flag</td>
        </tr>
    </table>

    <h3>1.2 วิธีเข้าสู่ระบบ</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>เปิด <code>healthycampus.rsu.ac.th/rsu-clinic/admin/auth/login.php</code></h4>
    </div>
    <div class="step-card">
        <span class="step-num">2</span><h4>กรอก username + password ที่ได้รับจาก superadmin</h4>
        <p>หากลืม password กดปุ่ม "Forgot Password" — ระบบจะส่งลิงก์ reset ไปยังอีเมล</p>
    </div>
    <div class="step-card">
        <span class="step-num">3</span><h4>เข้าหน้า Portal Dashboard</h4>
        <p>เมนูทางซ้ายจัดเป็น 10+ กลุ่ม — กดที่ "▼" เพื่อขยาย/ย่อ</p>
    </div>

    <div class="screenshot">
        <i class="fa-solid fa-image icon"></i>
        <div class="label">[ภาพหน้าจอ: Portal Dashboard + Sidebar]</div>
        <div class="hint">paste screenshot ของ portal/index.php?section=dashboard</div>
    </div>

    <h3>1.3 โครงสร้าง Sidebar (จากบนลงล่าง)</h3>
    <ul>
        <li><strong>OVERVIEW</strong> — Dashboard, โปรไฟล์ของฉัน</li>
        <li><strong>AI Suite</strong> — AI Assistant, QA Lab, Prompts, Knowledge</li>
        <li><strong>สิทธิ์ &amp; ความปลอดภัย</strong> — Identity &amp; Governance, ISO</li>
        <li><strong>ประกันสุขภาพ</strong> — Insurance Hub, Gold Card, Partners</li>
        <li><strong>สื่อสาร</strong> — ประกาศ, EDMS</li>
        <li><strong>คลังพัสดุ</strong> — ครุภัณฑ์, วัสดุสิ้นเปลือง</li>
        <li><strong>การเงิน</strong> — Cash Book</li>
        <li><strong>ติดตามระบบ</strong> — Activity Dashboard, Activity Logs, Error Logs</li>
        <li><strong>รายงาน</strong> — รายงานประจำเดือน</li>
        <li><strong>เอกสาร</strong> — คลังเอกสาร (proposal, manual)</li>
        <li><strong>ข้อมูลหลัก</strong> — Clinic Data, นักศึกษาทุน</li>
        <li><strong>ตั้งค่า</strong> — Settings ระบบ (อยู่ล่างสุดเสมอ)</li>
    </ul>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 2. แคมเปญ + Booking                                              -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">2</span>จัดการแคมเปญและการจอง</h2>

    <h3>2.1 สร้างแคมเปญสุขภาพใหม่</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>ไปที่ <code>admin/campaigns.php</code> หรือ Portal → ข้อมูลหลัก → แคมเปญ</h4>
    </div>
    <div class="step-card">
        <span class="step-num">2</span><h4>กดปุ่ม "+ สร้างแคมเปญ"</h4>
        <p>กรอก: ชื่อแคมเปญ · ประเภท (vaccine/health_check/dental/training) · วันเริ่ม-สิ้นสุด · จำนวนที่รับสูงสุด · สิทธิ์ผู้ใช้</p>
    </div>
    <div class="step-card">
        <span class="step-num">3</span><h4>สร้าง Time Slot ที่ admin/time_slots.php</h4>
        <p>เลือกแคมเปญ → เพิ่ม slot: วันที่ · เวลาเริ่ม-สิ้นสุด · จำนวนคนที่รับ (max_capacity)</p>
    </div>
    <div class="step-card">
        <span class="step-num">4</span><h4>เผยแพร่</h4>
        <p>เปลี่ยน status จาก <code>draft</code> → <code>active</code> — ผู้ใช้จะเห็นและจองได้ทันที</p>
    </div>

    <div class="callout">
        <strong>💡 Tip:</strong> ใช้ <code>status='coming_soon'</code> หากต้องการ tease ก่อนเปิดจอง
        — User เห็นแคมเปญแต่ยังกดจองไม่ได้
    </div>

    <h3>2.2 ดูรายงานประจำวัน</h3>
    <p>เข้า <code>admin/daily_report.php</code> — มี real-time refresh ทุก 60 วินาที:</p>
    <ul>
        <li><strong>มาตรงนัด</strong> — booking ที่ check-in ในวันนัด</li>
        <li><strong>มาก่อนนัด</strong> — booking ที่ check-in ก่อนวันนัด</li>
        <li><strong>ไม่มาตามนัด</strong> — booking ที่ผ่านวันแล้วยังไม่มี attendance</li>
        <li><strong>ยกเลิก</strong> — booking ที่ status = cancelled</li>
        <li><strong>อัตรา No-show %</strong> — เป้าหมาย &lt; 10%</li>
    </ul>

    <div class="screenshot">
        <i class="fa-solid fa-chart-pie icon"></i>
        <div class="label">[ภาพหน้าจอ: Daily Report Dashboard]</div>
        <div class="hint">paste screenshot ของ daily_report.php พร้อมแถบสถิติ</div>
    </div>

    <h3>2.3 รับรายงานสรุปทาง LINE ทุก 17:00</h3>
    <p>ระบบจะส่ง <strong>Flex Message สรุปประจำวัน</strong> เข้า LINE group ของคลินิกทุกวันเวลา 17:00 น. (ตั้งค่าผ่าน cron-job.org)</p>
    <ul>
        <li>หากวันใดไม่มีนัด ระบบจะ <strong>skip การส่ง</strong> ไม่กิน quota (เพิ่ม <code>?force=1</code> หากต้องการบังคับส่ง)</li>
        <li>ทดสอบ: เปิด URL พร้อม <code>?dryrun=1</code> จะแสดง payload แต่ไม่ส่งจริง</li>
        <li>ดูประวัติส่งใน Activity Log: <code>action='Daily Report Push'</code></li>
    </ul>

    <h3>2.4 ยกเลิก/เปลี่ยนแปลงการจอง</h3>
    <p>เข้า <code>admin/daily_report.php</code> → คลิกที่ slot → ดูรายการ booking → กด:</p>
    <ul>
        <li><strong>Force Cancel</strong> (ปุ่มแดง) — ยกเลิก booking + ส่ง LINE noti แจ้งผู้ใช้</li>
        <li><strong>Bulk Cancel</strong> — ยกเลิกหลายรายการพร้อมกันถ้าต้องเลื่อนแคมเปญ</li>
        <li><strong>Cancel Attendance</strong> — ลบ check-in (กรณีลงผิด)</li>
    </ul>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 3. บริการคลินิก                                                  -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">3</span>บริการคลินิก (Gold Card · e-Borrow · ทุน)</h2>

    <h3>3.1 อนุมัติคำขอบัตรทอง (Universal Coverage)</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>Portal → ประกันสุขภาพ → "บัตรทอง — รออนุมัติ"</h4>
        <p>เห็นรายการคำขอที่ผู้ใช้กรอกฟอร์ม + เซ็นชื่อแล้ว</p>
    </div>
    <div class="step-card">
        <span class="step-num">2</span><h4>คลิกที่รายการ → ตรวจสอบเอกสาร</h4>
        <p>เปรียบเทียบลายเซ็น + ข้อมูลกับบัตรประชาชน</p>
    </div>
    <div class="step-card">
        <span class="step-num">3</span><h4>กด "อนุมัติ" หรือ "ปฏิเสธ" พร้อมเหตุผล</h4>
        <p>ระบบส่ง LINE noti แจ้งผู้ใช้ + บันทึก audit log</p>
    </div>

    <div class="callout">
        <strong>📊 Bulk Import:</strong> ถ้ามีเอกสารหลายรายการ ใช้ปุ่ม
        "นำเข้าจากไฟล์ (Bulk Import)" — ลากโฟลเดอร์รายชื่อ + ระบบ AI จะจับคู่อัตโนมัติ
    </div>

    <h3>3.2 อนุมัติคำขอยืมอุปกรณ์ (e-Borrow)</h3>
    <p>ระบบ e-Borrow แยกเป็นเว็บไซต์ของตัวเอง — admin login เข้า <code>e_Borrow/login.php</code>:</p>
    <ul>
        <li>ดูคำขอใหม่ → กด <strong>"อนุมัติ"</strong> หรือ <strong>"ปฏิเสธ"</strong></li>
        <li>เมื่อผู้ใช้คืนของ → กด <strong>"บันทึกการคืน"</strong> → ระบบคำนวณค่าปรับอัตโนมัติ (10 บาท/วัน)</li>
        <li>ค่าปรับเข้า Cash Book ในโมดูล "การเงิน" อัตโนมัติ</li>
    </ul>

    <h3>3.3 อนุมัติเวลานักศึกษาทุน</h3>
    <p>นักศึกษาทุน clock-in/out ผ่าน LINE → ส่งคำขอเข้า LINE group → กดปุ่ม:</p>
    <ul>
        <li><strong>✓ อนุมัติ</strong> — บันทึกชั่วโมงเข้าระบบ + ส่งเข้า Cash Book (OT รายเดือน)</li>
        <li><strong>✗ ปฏิเสธ</strong> — พร้อมเหตุผล (รูปไม่ชัด, นอกเวลา, ฯลฯ)</li>
    </ul>
    <p>หรือดูใน Portal → ข้อมูลหลัก → นักศึกษาทุน</p>

    <h3>3.4 ตอบ Chat กับผู้ใช้</h3>
    <p>เมื่อผู้ใช้ส่งข้อความ → ระบบ AI ตอบเบื้องต้นก่อน → ส่งต่อให้ admin หากไม่ตรงคำถาม:</p>
    <ul>
        <li>เข้า Portal → ดู notification badge มุมขวาบน</li>
        <li>กดเข้าไป → เห็น chat ของผู้ใช้แต่ละราย</li>
        <li>พิมพ์ตอบ → ผู้ใช้เห็นใน LINE ภายใน &lt;1 วินาที (ผ่าน Pusher real-time)</li>
        <li>ระบบบันทึก response time → ใช้วัด KPI เป้าหมาย &lt;15 นาที</li>
    </ul>

    <div class="screenshot">
        <i class="fa-solid fa-comments icon"></i>
        <div class="label">[ภาพหน้าจอ: Admin Chat Console]</div>
        <div class="hint">paste screenshot หน้าจัดการ chat ของ admin</div>
    </div>

    <h3>3.5 ส่งประกาศใหม่</h3>
    <div class="step-card">
        <span class="step-num">1</span><h4>Portal → สื่อสาร → ประกาศ</h4>
    </div>
    <div class="step-card">
        <span class="step-num">2</span><h4>กด "+ เพิ่มประกาศ" → กรอก หัวข้อ + เนื้อหา (TH/EN) + รูปประกอบ</h4>
        <p>เลือกประเภท: <strong>info</strong> (ฟ้า) · <strong>warning</strong> (อำพัน) · <strong>success</strong> (เขียว) · <strong>urgent</strong> (แดง — pulse animation)</p>
    </div>
    <div class="step-card">
        <span class="step-num">3</span><h4>กำหนดกลุ่มเป้าหมายและช่วงเวลาที่แสดง</h4>
        <p>ผู้ใช้จะเห็น pop-up เมื่อเปิดแอปครั้งถัดไป — กดรับทราบแล้วระบบบันทึก audit</p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 4. Activity Dashboard + เอกสาร                                   -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="page">
    <h2><span class="num">4</span>Activity Dashboard และระบบเอกสาร</h2>

    <h3>4.1 Activity Dashboard <span class="role-badge role-super">superadmin only</span></h3>
    <p>Portal → ติดตามระบบ → <strong>Activity Dashboard</strong> — แดชบอร์ดสถิติ real-time</p>
    <ul>
        <li><strong>4 KPI Cards</strong>: Actions วันนี้ · Active Admins (24h) · Peak Hour · All-time total</li>
        <li><strong>Timeline 24h</strong>: line chart แสดงปริมาณ action รายชั่วโมง</li>
        <li><strong>Top Admins (7d)</strong>: leaderboard ของ admin ที่ active สูงสุด</li>
        <li><strong>Category Breakdown</strong>: auth · identity · booking · campaign · insurance · etc.</li>
        <li><strong>Heatmap 30 วัน</strong>: 7×24 ตาราง วัน × ชั่วโมง</li>
        <li><strong>Live Feed</strong>: real-time stream ของ 50 event ล่าสุด — เด้งทันทีผ่าน Pusher</li>
    </ul>

    <div class="screenshot">
        <i class="fa-solid fa-chart-line icon"></i>
        <div class="label">[ภาพหน้าจอ: Activity Dashboard]</div>
        <div class="hint">paste screenshot ของ activity_dashboard.php พร้อมกราฟ</div>
    </div>

    <h3>4.2 Activity Logs + Error Logs</h3>
    <p>สำหรับสืบสวน incident หรือตรวจการใช้งานย้อนหลัง:</p>
    <ul>
        <li><strong>Activity Logs</strong> — ทุก action ที่ admin/staff ทำ พร้อม IP + user agent</li>
        <li><strong>Error Logs</strong> — error ระบบ + Sentry alert</li>
        <li>Retention: Activity 90 วัน · Error 30 วัน (auto-purge ผ่าน cron)</li>
    </ul>

    <h3>4.3 LINE Rich Menu</h3>
    <p>Portal → ตั้งค่า → LINE Settings — จัดการเมนูที่แสดงใน LINE OA:</p>
    <ul>
        <li>ใส่ <strong>Guest Rich Menu ID</strong> + <strong>Member Rich Menu ID</strong></li>
        <li>กดปุ่ม "บันทึก" — ระบบ <strong>verify ID กับ LINE API</strong> อัตโนมัติ</li>
        <li>กด "Sync ทุก member" — link member menu ให้ user ทุกคนที่มี line_user_id (batch 50/รอบ + progress bar)</li>
        <li>ดู Audit log — ทุก link/unlink ถูกบันทึกใน <code>sys_line_richmenu_audit</code></li>
    </ul>

    <div class="callout info">
        <strong>🔁 Auto-sync:</strong> เมื่อ user สมัครเสร็จ ระบบจะ link member menu อัตโนมัติ
        — ไม่ต้องกด Sync ด้วยตัวเอง ปุ่ม Sync ใช้กับเคสพิเศษ เช่น เปลี่ยน Rich Menu ID
    </div>

    <h3>4.4 คลังเอกสาร</h3>
    <p>Portal → เอกสาร → <strong>คลังเอกสาร</strong>:</p>
    <ul>
        <li>ดู <strong>Project Proposal</strong>, <strong>คู่มือ User</strong>, <strong>คู่มือ Admin</strong> (เอกสารนี้)</li>
        <li>กด "เปิดเอกสาร" → เปิดในแท็บใหม่ → ดาวน์โหลด PDF / .doc / พิมพ์</li>
        <li>เอกสารใหม่เพิ่มผ่าน <code>portal/_partials/documents.php</code> — append entry ใน <code>$documents</code> array</li>
    </ul>

    <h3>4.5 เกร็ดเล็กน้อย</h3>
    <ul>
        <li>เปลี่ยน theme dark/light — กดไอคอนพระจันทร์มุมขวาบน</li>
        <li>ค้นหาทั่วระบบ — กดไอคอนแว่นขยายมุมขวาบน</li>
        <li>App Switcher — กด <code>::</code> มุมซ้ายบนเพื่อสลับไป e-Borrow / Asset / Consumables</li>
        <li>กดปุ่ม <strong>?</strong> มุมขวาล่างเพื่อเปิด Guided Tour</li>
    </ul>

    <h3>📞 ขอความช่วยเหลือทางเทคนิค</h3>
    <ul>
        <li><strong>Helpdesk:</strong> 02-997-2200 ต่อ 1234 (จ-ศ 8:30-16:30)</li>
        <li><strong>LINE:</strong> @rsu-helpdesk</li>
        <li><strong>Email:</strong> it-support@rsu.ac.th</li>
        <li><strong>Documentation:</strong> CLAUDE.md, AI_GUIDE.md ใน Git repository</li>
    </ul>

    <div class="doc-footer">
        คู่มือนี้สำหรับเจ้าหน้าที่ระดับ Admin/Superadmin เท่านั้น<br>
        © 2569 RSU Medical Clinic Services · v1.0 · พฤษภาคม 2569
    </div>
</div>

<!-- Floating action panel -->
<div class="no-print" style="position:fixed;bottom:16px;right:16px;display:flex;flex-direction:column;gap:8px;z-index:9999">
    <button id="btn-print" class="no-print-tip" onclick="window.print()" style="background:#475569">🖨️ พิมพ์ (Print)</button>
    <button id="btn-pdf"   class="no-print-tip" onclick="downloadPdf()" style="background:#dc2626">📕 ดาวน์โหลด PDF</button>
    <button id="btn-doc"   class="no-print-tip" onclick="exportToWord()" style="background:#4f46e5">📘 ดาวน์โหลด .doc</button>
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
        const filename = 'RSU_Admin_Manual_' + new Date().toISOString().substring(0,10) + '.pdf';
        await html2pdf().set({
            margin: [10,10,12,10], filename,
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, logging: false, letterRendering: true, allowTaint: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['.step-card', '.callout', '.screenshot', 'table'] },
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
        h2 { font-size: 17pt; color: #4f46e5; border-bottom: 2px solid #4f46e5; padding-bottom: 6px; page-break-after: avoid; }
        h3 { font-size: 13pt; page-break-after: avoid; }
        .step-card { background: #eef2ff; border-left: 4px solid #4f46e5; padding: 8pt 12pt; margin: 8pt 0; }
        .callout { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 8pt 12pt; margin: 8pt 0; }
        .screenshot { border: 2px dashed #cbd5e1; padding: 16pt; text-align: center; color: #94a3b8; }
        table { border-collapse: collapse; width: 100%; }
        table, th, td { border: 1px solid #94a3b8; }
        th { background: #eef2ff; padding: 6pt 8pt; }
        td { padding: 6pt 8pt; vertical-align: top; }
        .role-badge { padding: 1pt 8pt; border: 1px solid #cbd5e1; border-radius: 8pt; font-size: 10pt; }
        code { background: #f1f5f9; color: #be185d; padding: 1pt 4pt; border-radius: 3pt; font-family: monospace; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: avoid; }
    `;
    const header = `<!DOCTYPE html><html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>คู่มือ Admin</title><style>${wordCss}</style></head><body>`;
    const html = header + clone.innerHTML + '</body></html>';
    const blob = new Blob(['﻿', html], { type: 'application/msword;charset=utf-8' });
    const filename = 'RSU_Admin_Manual_' + new Date().toISOString().substring(0,10) + '.doc';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 100);
}
</script>

</body>
</html>
