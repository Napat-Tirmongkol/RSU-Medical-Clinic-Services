<?php
/**
 * docs/scholarship_pitch.php
 * Print-ready handout — ระบบจัดการนักศึกษาทุน (presentation handout)
 * Restricted to portal admins (superadmin / admin / editor) — no sensitive data
 * but keep behind auth gate for consistency with other docs.
 */
declare(strict_types=1);
session_start();

$adminRole = $_SESSION['admin_role'] ?? '';
if (!in_array($adminRole, ['superadmin', 'admin', 'editor'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>Access Denied</title>
    <style>body{font-family:sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#0f172a;text-align:center}
    .box{background:#fff;padding:40px 56px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:480px}
    h1{color:#dc2626;margin:0 0 8px 0;font-size:24px}
    p{color:#64748b;margin:8px 0;font-size:14px}
    a{color:#0f7349;text-decoration:none;font-weight:700;display:inline-block;margin-top:16px}</style>
    </head><body><div class="box"><h1>🛡️ Access Denied</h1>
    <p>เอกสารนี้สำหรับผู้ใช้ระบบ Portal เท่านั้น</p>
    <a href="/e-campaignv2/admin/auth/login.php">→ เข้าสู่ระบบ</a></div></body></html><?php
    exit;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('j M Y');
$thaiMonths = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$ts = time();
$todayThai = (int)date('j', $ts) . ' ' . $thaiMonths[(int)date('n', $ts)] . ' ' . (date('Y', $ts) + 543);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ระบบจัดการนักศึกษาทุน — Scholarship Management System | RSU Medical Clinic</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
@page { size: A4; margin: 16mm 14mm 18mm 14mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: 'Sarabun', 'Sukhumvit Set', -apple-system, sans-serif;
    background: #f1f5f9;
    color: #0f172a;
    font-size: 13.5px;
    line-height: 1.55;
}

/* ── Print toolbar (no-print) ────────────────────────────────────────── */
.toolbar {
    position: sticky; top: 0; z-index: 100;
    background: #fff; border-bottom: 1px solid #e2e8f0;
    padding: 12px 24px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    box-shadow: 0 2px 8px rgba(15,23,42,.04);
}
.toolbar-brand { font-weight: 800; color: #0f172a; font-size: 14px; }
.toolbar-brand .accent { color: #2e9e63; }
.toolbar-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.tb-btn {
    padding: 7px 14px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #fff; color: #0f172a; font-size: 13px; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: all .15s;
}
.tb-btn:hover { background: #f8fafc; border-color: #94a3b8; }
.tb-btn--primary { background: #2e9e63; color: #fff; border-color: #2e9e63; }
.tb-btn--primary:hover { background: #268555; }

/* ── Page wrapper (A4) ───────────────────────────────────────────────── */
.page {
    background: #fff;
    width: 210mm; min-height: 297mm;
    margin: 16px auto;
    padding: 22mm 18mm;
    box-shadow: 0 8px 32px rgba(15,23,42,.08);
    position: relative;
    page-break-after: always;
}
.page:last-child { page-break-after: auto; }

/* ── Cover page ──────────────────────────────────────────────────────── */
.cover { display: flex; flex-direction: column; justify-content: center; padding: 50mm 18mm; min-height: 297mm; }
.cover-brand { font-size: 14px; font-weight: 700; color: #2e9e63; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 24px; }
.cover-title  { font-size: 38px; font-weight: 800; color: #0f172a; line-height: 1.15; margin: 0 0 16px; letter-spacing: -.01em; }
.cover-sub    { font-size: 20px; font-weight: 600; color: #475569; margin: 0 0 32px; }
.cover-tagline{ font-size: 16px; font-weight: 500; color: #64748b; line-height: 1.7; max-width: 480px; margin-bottom: 40px; }
.cover-meta   { font-size: 13px; color: #94a3b8; border-top: 2px solid #e2e8f0; padding-top: 18px; margin-top: auto; }
.cover-meta b { color: #0f172a; font-weight: 700; }
.cover-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: #14532d; padding: 8px 16px; border-radius: 99px;
    font-size: 13px; font-weight: 700; margin-bottom: 28px;
}

/* ── Headings ────────────────────────────────────────────────────────── */
h1.section { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
.section-sub { color: #64748b; font-size: 14px; margin-bottom: 28px; padding-bottom: 14px; border-bottom: 2px solid #2e9e63; }
h2 { font-size: 17px; font-weight: 700; color: #0f172a; margin: 24px 0 10px; display: flex; align-items: center; gap: 8px; }
h2 .h-num { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 8px; background: #2e9e63; color: #fff; font-size: 13px; font-weight: 800; }
h3 { font-size: 14.5px; font-weight: 700; color: #1e293b; margin: 16px 0 8px; }

/* ── Content blocks ──────────────────────────────────────────────────── */
.lead { font-size: 15px; line-height: 1.7; color: #334155; margin: 0 0 16px; }
.box {
    border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin: 12px 0;
    background: #fff;
}
.box.box--problem { background: #fff5f5; border-color: #fecaca; }
.box.box--solution { background: #f0fdf4; border-color: #bbf7d0; }
.box.box--info     { background: #eff6ff; border-color: #bfdbfe; }
.box.box--amber    { background: #fffbeb; border-color: #fde68a; }
.box-title { font-weight: 700; font-size: 14px; margin: 0 0 6px; color: #0f172a; display: flex; align-items: center; gap: 6px; }
.box-title i { color: #2e9e63; }
.box.box--problem .box-title i { color: #dc2626; }
.box.box--info .box-title i    { color: #2563eb; }
.box.box--amber .box-title i   { color: #d97706; }

ul.tick, ul.cross { list-style: none; padding-left: 0; margin: 8px 0 12px; }
ul.tick li, ul.cross li { padding: 4px 0 4px 24px; position: relative; font-size: 13.5px; line-height: 1.55; }
ul.tick li::before  { content: '✓'; position: absolute; left: 4px; color: #16a34a; font-weight: 800; }
ul.cross li::before { content: '✗'; position: absolute; left: 4px; color: #dc2626; font-weight: 800; }

/* ── Feature grid ────────────────────────────────────────────────────── */
.feature-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin: 14px 0; }
.feature {
    border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px;
    background: #fff;
}
.feature-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.feature-ic {
    width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.feature-ic.ic-emerald { background: #dcfce7; color: #166534; }
.feature-ic.ic-blue    { background: #dbeafe; color: #1e40af; }
.feature-ic.ic-amber   { background: #fef3c7; color: #92400e; }
.feature-ic.ic-rose    { background: #fee2e2; color: #9f1239; }
.feature-ic.ic-indigo  { background: #e0e7ff; color: #3730a3; }
.feature-ic.ic-cyan    { background: #cffafe; color: #0e7490; }
.feature-title { font-weight: 700; color: #0f172a; font-size: 13.5px; }
.feature-desc { font-size: 12.5px; color: #475569; line-height: 1.55; }
.feature ul { margin: 6px 0 0; padding-left: 18px; }
.feature ul li { font-size: 12.5px; color: #475569; line-height: 1.6; }

/* ── Metrics / impact table ──────────────────────────────────────────── */
.impact-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
.impact-table th, .impact-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
.impact-table th { background: #f8fafc; color: #64748b; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; }
.impact-table .before { color: #dc2626; font-weight: 600; }
.impact-table .after  { color: #16a34a; font-weight: 700; }
.impact-table .arrow  { color: #94a3b8; }

/* ── Workflow diagram (text-based) ───────────────────────────────────── */
.flow { display: flex; align-items: stretch; gap: 8px; margin: 14px 0; flex-wrap: wrap; }
.flow-step {
    flex: 1; min-width: 140px; padding: 12px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px;
    background: #fff; text-align: center; font-size: 12px;
}
.flow-step b { display: block; color: #0f172a; font-size: 13px; margin-bottom: 4px; }
.flow-step span { color: #64748b; }
.flow-arrow { display: flex; align-items: center; color: #94a3b8; font-size: 18px; flex-shrink: 0; }

/* ── Footer ──────────────────────────────────────────────────────────── */
.page-footer {
    position: absolute; bottom: 12mm; left: 18mm; right: 18mm;
    display: flex; justify-content: space-between;
    font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 8px;
}
.page-num::before { content: 'หน้า '; }

/* ── Print mode ──────────────────────────────────────────────────────── */
@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .page { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
    .page-footer { position: fixed; }
}

/* ── Mobile ──────────────────────────────────────────────────────────── */
@media (max-width: 820px) {
    .page { width: auto; padding: 28px 22px; }
    .cover { padding: 32px 22px; }
    .cover-title { font-size: 28px; }
    .feature-grid { grid-template-columns: 1fr; }
    .flow { flex-direction: column; }
    .flow-arrow { transform: rotate(90deg); justify-content: center; }
}
</style>
</head>
<body>

<!-- ── TOOLBAR (no-print) ─────────────────────────────────────────────── -->
<div class="toolbar">
    <div class="toolbar-brand">
        <i class="fa-solid fa-graduation-cap" style="color:#2e9e63"></i>
        <span class="accent">RSU</span> Scholarship · Handout
    </div>
    <div class="toolbar-btns">
        <button class="tb-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> พิมพ์</button>
        <button class="tb-btn" onclick="downloadPdf()"><i class="fa-solid fa-file-pdf"></i> PDF</button>
        <button class="tb-btn" onclick="downloadDoc()"><i class="fa-solid fa-file-word"></i> Word</button>
        <button class="tb-btn tb-btn--primary" onclick="window.history.back()"><i class="fa-solid fa-arrow-left"></i> กลับ</button>
    </div>
</div>

<!-- ══════════════════════════════ PAGE 1 — COVER ══════════════════════ -->
<div class="page cover">
    <div class="cover-brand">RSU Medical Clinic Services</div>

    <span class="cover-badge"><i class="fa-solid fa-sparkles"></i> Production-Ready System</span>

    <h1 class="cover-title">ระบบจัดการนักศึกษาทุน</h1>
    <p class="cover-sub">Scholarship Management System</p>

    <p class="cover-tagline">
        End-to-end workflow ตั้งแต่นักศึกษา clock-in ผ่าน LINE
        จนถึงการส่งค่าตอบแทนเข้าระบบการเงิน
        — ครบในระบบเดียว ตรวจสอบได้ทุกขั้น
    </p>

    <div class="cover-meta">
        <div><b>วันที่:</b> <?= htmlspecialchars($todayThai) ?></div>
        <div style="margin-top:4px"><b>เอกสารประกอบการนำเสนอ:</b> Handout สำหรับผู้ฟัง</div>
        <div style="margin-top:4px"><b>ผู้ดูแลระบบ:</b> ทีม IT คลินิก · มหาวิทยาลัยรังสิต</div>
    </div>
</div>

<!-- ══════════════════════════════ PAGE 2 — PROBLEM & SOLUTION ═════════ -->
<div class="page">
    <h1 class="section">ปัญหา &amp; ทางออก</h1>
    <p class="section-sub">ทำไมต้องมีระบบจัดการนักศึกษาทุน</p>

    <h2><span class="h-num">1</span> ปัญหาเดิม</h2>
    <div class="box box--problem">
        <div class="box-title"><i class="fa-solid fa-circle-exclamation"></i> ก่อนมีระบบ</div>
        <ul class="cross">
            <li>บันทึกชั่วโมงการทำงานด้วย <b>กระดาษ + Excel แยก</b> — เสียหาย/สูญหาย/แก้ไขย้อนหลังได้</li>
            <li><b>คำนวณค่าตอบแทนสิ้นเดือนช้า</b> — admin ใช้เวลา 2-3 วันต่อเดือน รวบรวมข้อมูล</li>
            <li><b>ตรวจสอบไม่ได้</b>ว่านักศึกษามาทำงานจริงหรือเปล่า — ไม่มีหลักฐาน GPS/เวลา</li>
            <li>การติดต่อนักศึกษาผ่าน <b>LINE chat ส่วนตัว</b> — ไม่เป็นระบบ ลืม/หาย</li>
            <li>แยกการคำนวณ <b>"เก็บชั่วโมงทุน" vs "ค่าตอบแทน"</b> สับสน คิดเอง</li>
        </ul>
    </div>

    <h2><span class="h-num">2</span> ทางออก — Integrated Workflow</h2>
    <div class="box box--solution">
        <div class="box-title"><i class="fa-solid fa-circle-check"></i> หลังมีระบบ</div>
        <p class="lead" style="margin:0 0 10px 0">
            ระบบครบวงจร เชื่อมต่อ <b>นักศึกษา (มือถือ LINE)</b> กับ <b>คลินิก (Portal Web)</b>
            ผ่าน LINE Messaging API + Web app — 2 ฝั่งคุยกันแบบ realtime
        </p>
        <div class="flow">
            <div class="flow-step"><b>นักศึกษา</b><span>เปิด LINE OA → กด "เข้างาน"</span></div>
            <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
            <div class="flow-step"><b>ระบบ</b><span>เช็ค GPS → บันทึก timestamp</span></div>
            <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
            <div class="flow-step"><b>Admin</b><span>เห็นในรายการรออนุมัติ</span></div>
            <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
            <div class="flow-step"><b>ระบบ</b><span>คำนวณ + ส่งเงินอัตโนมัติ</span></div>
        </div>
    </div>

    <div class="box box--info" style="margin-top:18px">
        <div class="box-title"><i class="fa-solid fa-bullseye"></i> เป้าหมายหลัก</div>
        <ul class="tick" style="margin:4px 0 0">
            <li><b>Speed</b> — อนุมัติเข้างานภายในวินาที ไม่ต้องเซ็นกระดาษ</li>
            <li><b>Accuracy</b> — คำนวณค่าตอบแทนแม่นนาทีต่อนาที</li>
            <li><b>Auditability</b> — ทุก action บันทึก audit log ครบ</li>
            <li><b>Integration</b> — ส่งเงินเข้า Cash Book อัตโนมัติ</li>
        </ul>
    </div>

    <div class="page-footer">
        <span>RSU Medical Clinic · ระบบจัดการนักศึกษาทุน</span>
        <span class="page-num">2</span>
    </div>
</div>

<!-- ══════════════════════════════ PAGE 3 — FEATURES 1-3 ═══════════════ -->
<div class="page">
    <h1 class="section">คุณสมบัติหลัก (1/2)</h1>
    <p class="section-sub">3 features แรก — Identity, Schedule, Check-in</p>

    <div class="feature-grid" style="grid-template-columns:1fr">

        <div class="feature">
            <div class="feature-head">
                <div class="feature-ic ic-blue"><i class="fa-solid fa-user-graduate"></i></div>
                <div>
                    <div class="feature-title">1. จัดการนักศึกษาทุน</div>
                    <div class="feature-desc">Student CRUD + LINE account linking ผ่าน LIFF</div>
                </div>
            </div>
            <ul>
                <li>Link LINE account 1-click — ไม่ต้องพิมพ์ UID เอง</li>
                <li>ข้อมูลครบ: รหัสนักศึกษา · คณะ · ภาคเรียน · ประเภททุน · อัตราค่าตอบแทน</li>
                <li>เห็น progress: ชั่วโมงสะสมต่อเดือน · % ครบโควต้า · เวลาคงเหลือ</li>
                <li>Status ENUM: active / pending / graduated · กรอง/ค้นหาได้</li>
            </ul>
        </div>

        <div class="feature">
            <div class="feature-head">
                <div class="feature-ic ic-emerald"><i class="fa-solid fa-calendar-week"></i></div>
                <div>
                    <div class="feature-title">2. ตารางงาน 3 มุมมอง</div>
                    <div class="feature-desc">Calendar · Custom shifts · Open slots ในที่เดียว</div>
                </div>
            </div>
            <ul>
                <li><b>ปฏิทินรวม</b> — เห็นใครจองรอบไหนทั้งเดือน · เชื่อมวันหยุดคลินิก</li>
                <li><b>ตารางกะ</b> — admin กำหนดเองรายคน (สำหรับ shift ประจำ)</li>
                <li><b>เปิดรอบให้จองเอง</b> — first-come-first-served · นักศึกษาเลือกเวลาที่ว่าง</li>
                <li>คุมจำนวนคนต่อรอบ (capacity) · จองทันทีไม่ต้องรออนุมัติ</li>
                <li>วันหยุดคลินิก sync อัตโนมัติ — ไม่เปิดรอบในวันหยุด</li>
            </ul>
        </div>

        <div class="feature">
            <div class="feature-head">
                <div class="feature-ic ic-rose"><i class="fa-solid fa-location-crosshairs"></i></div>
                <div>
                    <div class="feature-title">3. GPS-verified Check-in ผ่าน LINE</div>
                    <div class="feature-desc">ใช้ LINE LIFF อ่าน geolocation จาก browser</div>
                </div>
            </div>
            <ul>
                <li>นักศึกษาเปิด LINE → กด rich menu "เข้างาน" → ส่ง GPS อัตโนมัติ</li>
                <li>เช็คระยะห่างจากคลินิก — default radius 100 m (ปรับได้)</li>
                <li>ถ้านอก radius → mark ให้ admin review (ไม่ blocking)</li>
                <li>บันทึก: timestamp · GPS lat/lng · IP address · device UA</li>
                <li><b>ไม่ต้อง install app เพิ่ม</b> — ใช้ LINE ที่นักศึกษามีอยู่</li>
            </ul>
        </div>

    </div>

    <div class="page-footer">
        <span>RSU Medical Clinic · ระบบจัดการนักศึกษาทุน</span>
        <span class="page-num">3</span>
    </div>
</div>

<!-- ══════════════════════════════ PAGE 4 — FEATURES 4-6 ═══════════════ -->
<div class="page">
    <h1 class="section">คุณสมบัติหลัก (2/2)</h1>
    <p class="section-sub">3 features ถัดไป — Approval, Payouts, AI Brief</p>

    <div class="feature-grid" style="grid-template-columns:1fr">

        <div class="feature">
            <div class="feature-head">
                <div class="feature-ic ic-amber"><i class="fa-solid fa-bell"></i></div>
                <div>
                    <div class="feature-title">4. Approval Workflow — รวดเร็ว ตรวจสอบได้</div>
                    <div class="feature-desc">รออนุมัติเด่นที่สุดของ Dashboard</div>
                </div>
            </div>
            <ul>
                <li>นักศึกษา clock-in → Portal "ของต้องทำ" → admin ตรวจเวลา/GPS/กะ</li>
                <li>1-click อนุมัติ/ปฏิเสธ → LINE แจ้งนักศึกษาทันที (Flex Message)</li>
                <li>แบ่งสี: ในรัศมี (เขียว) / นอกรัศมี (แดง) — ตัดสินใจไว</li>
                <li>Bulk approve — ติ๊กหลายคนแล้วอนุมัติพร้อมกัน</li>
                <li>ทุก action บันทึก audit log ครบ — ใครอนุมัติ/ปฏิเสธ เมื่อไหร่ ด้วยเหตุผลใด</li>
            </ul>
        </div>

        <div class="feature">
            <div class="feature-head">
                <div class="feature-ic ic-emerald"><i class="fa-solid fa-money-check-dollar"></i></div>
                <div>
                    <div class="feature-title">5. Auto Payout + Finance Sync</div>
                    <div class="feature-desc">คำนวณเงินสิ้นเดือน → ส่ง Cash Book อัตโนมัติ</div>
                </div>
            </div>
            <ul>
                <li>กดปุ่ม "สร้างรายการจ่าย" → ระบบคำนวณ <b>ชั่วโมง × อัตรา</b> ทุกคน</li>
                <li>แยก "เก็บชั่วโมงทุน" vs "ค่าตอบแทน (จ่ายเป็นเงิน)" ชัดเจน</li>
                <li>Mark "อนุมัติการเงินแล้ว" → ส่งเข้า Cash Book หมวด "เงินเดือน/ค่าจ้าง"</li>
                <li>LINE แจ้งนักศึกษา: "ค่าตอบแทนเดือน X = Y บาท"</li>
                <li><b>Idempotent</b> — กดซ้ำไม่จ่ายซ้ำ (UNIQUE constraint)</li>
            </ul>
        </div>

        <div class="feature">
            <div class="feature-head">
                <div class="feature-ic ic-indigo"><i class="fa-solid fa-sun"></i></div>
                <div>
                    <div class="feature-title">6. Morning Brief — AI สรุปเช้า</div>
                    <div class="feature-desc">Gemini 2.5 Flash · ส่งทุกเช้า 08:00</div>
                </div>
            </div>
            <ul>
                <li>เปิด portal → AI สรุป: "วันนี้รออนุมัติ N · กะวันนี้ M · ค้างจ่าย X บาท"</li>
                <li>3 ช่องทาง: Portal widget · LINE (ตัวเอง + กลุ่ม) · Email</li>
                <li>เคารพปฏิทินคลินิก — วันหยุดไม่ส่ง</li>
                <li>Priorities list — AI จัดเรียงงานต้องทำ ตามความเร่งด่วน</li>
                <li>Cross-module: รวมข้อมูล scholarship + e-Campaign + EDMS + Inventory</li>
            </ul>
        </div>

    </div>

    <div class="page-footer">
        <span>RSU Medical Clinic · ระบบจัดการนักศึกษาทุน</span>
        <span class="page-num">4</span>
    </div>
</div>

<!-- ══════════════════════════════ PAGE 5 — TECH & IMPACT ══════════════ -->
<div class="page">
    <h1 class="section">เทคโนโลยี &amp; ผลลัพธ์</h1>
    <p class="section-sub">เบื้องหลังระบบ + ตัวเลขที่วัดได้</p>

    <h2><span class="h-num">7</span> Tech Stack — ทีมคลินิกบำรุงรักษาเองได้</h2>
    <div class="feature-grid">
        <div class="feature">
            <div class="feature-title" style="margin-bottom:6px"><i class="fa-solid fa-code text-emerald-600"></i> Backend</div>
            <ul>
                <li>PHP 8.2 + MySQL — stack มาตรฐาน</li>
                <li>ไม่ต้องใช้ framework หนัก</li>
                <li>Auto-migrate schema</li>
            </ul>
        </div>
        <div class="feature">
            <div class="feature-title" style="margin-bottom:6px"><i class="fa-brands fa-line" style="color:#06c755"></i> LINE Integration</div>
            <ul>
                <li>Messaging API + LIFF</li>
                <li>Flex Message + Quick Reply</li>
                <li>ฝั่ง user no-install</li>
            </ul>
        </div>
        <div class="feature">
            <div class="feature-title" style="margin-bottom:6px"><i class="fa-solid fa-robot text-indigo-600"></i> AI Layer</div>
            <ul>
                <li>Gemini 2.5 Flash</li>
                <li>responseSchema strict JSON</li>
                <li>Rule-based fallback ถ้า AI fail</li>
            </ul>
        </div>
        <div class="feature">
            <div class="feature-title" style="margin-bottom:6px"><i class="fa-solid fa-shield-halved text-rose-600"></i> Security &amp; Audit</div>
            <ul>
                <li>CSRF guard ทุก mutation</li>
                <li>Audit log append-only</li>
                <li>ตามมาตรฐาน ISO 27001 / PDPA</li>
            </ul>
        </div>
    </div>

    <h2 style="margin-top:24px"><span class="h-num">8</span> Impact — ก่อน vs หลัง</h2>
    <table class="impact-table">
        <thead>
            <tr>
                <th style="width:35%">เมตริก</th>
                <th>ก่อนระบบ</th>
                <th style="width:5%"></th>
                <th>หลังระบบ</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>เวลาในการอนุมัติ clock-in</td>
                <td class="before">1 วัน (เซ็นกระดาษ)</td>
                <td class="arrow">→</td>
                <td class="after">5 วินาที (1-click)</td>
            </tr>
            <tr>
                <td>สรุปจ่ายค่าตอบแทนสิ้นเดือน</td>
                <td class="before">2-3 วัน · manual Excel</td>
                <td class="arrow">→</td>
                <td class="after">10 นาที · auto-calc</td>
            </tr>
            <tr>
                <td>อัตราผิดคำนวณ</td>
                <td class="before">~5% (มนุษย์)</td>
                <td class="arrow">→</td>
                <td class="after">0% · exact to minute</td>
            </tr>
            <tr>
                <td>Audit trail</td>
                <td class="before">กระดาษ · หาไม่เจอ</td>
                <td class="arrow">→</td>
                <td class="after">100% digital · ค้นได้</td>
            </tr>
            <tr>
                <td>นักศึกษาลืมเข้างาน</td>
                <td class="before">บ่อย · ไม่มี reminder</td>
                <td class="arrow">→</td>
                <td class="after">LINE reminder อัตโนมัติ</td>
            </tr>
            <tr>
                <td>การยืนยันสถานที่ทำงาน</td>
                <td class="before">เซ็นชื่อเอง (ไว้ใจ)</td>
                <td class="arrow">→</td>
                <td class="after">GPS verification</td>
            </tr>
        </tbody>
    </table>

    <h2 style="margin-top:24px"><span class="h-num">9</span> Roadmap — ถัดไป</h2>
    <div class="box box--amber">
        <ul class="tick" style="margin:0">
            <li>Auto-reminder ก่อนเข้างาน 30 นาที (LINE push)</li>
            <li>รายงานสรุปต่อภาคเรียน — PDF print-ready</li>
            <li>Mobile-first admin view (อนุมัติบนมือถือเร็วขึ้น)</li>
            <li>Integration กับระบบบุคคล HRIS ของมหาวิทยาลัย</li>
            <li>ขยายไปใช้กับ part-time staff อื่นๆ ของคลินิก</li>
        </ul>
    </div>

    <div style="margin-top:32px;padding:18px;border:2px solid #2e9e63;border-radius:12px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);text-align:center">
        <div style="font-size:16px;font-weight:800;color:#14532d;margin-bottom:6px">
            <i class="fa-solid fa-circle-question"></i> มีคำถามเพิ่มเติม?
        </div>
        <div style="font-size:13px;color:#15803d">
            ติดต่อทีม IT คลินิก · มหาวิทยาลัยรังสิต<br>
            <span style="font-family:ui-monospace;font-size:11.5px;color:#0f7349;margin-top:4px;display:inline-block">healthycampus.rsu.ac.th/e-campaignv2</span>
        </div>
    </div>

    <div class="page-footer">
        <span>RSU Medical Clinic · ระบบจัดการนักศึกษาทุน · ขอบคุณครับ</span>
        <span class="page-num">5</span>
    </div>
</div>

<!-- ══════════════════════════════ SCRIPTS ═════════════════════════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPdf() {
    const el = document.querySelectorAll('.page');
    // Clone wrapper so we don't modify the visible DOM
    const wrap = document.createElement('div');
    el.forEach(p => wrap.appendChild(p.cloneNode(true)));
    html2pdf().from(wrap).set({
        filename: 'RSU-Scholarship-Handout-' + new Date().toISOString().slice(0,10) + '.pdf',
        margin: 0,
        image: { type: 'jpeg', quality: 0.96 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'legacy'] },
    }).save();
}

function downloadDoc() {
    // Save as .doc using msword MIME — Word จะ render HTML ในไฟล์ตามมาตรฐาน HTML email
    const pages = document.querySelectorAll('.page');
    let body = '';
    pages.forEach(p => { body += p.outerHTML; });
    const head = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><title>RSU Scholarship Handout</title>';
    // Pull all <style> from current page so Word gets the styling
    const styles = Array.from(document.querySelectorAll('style')).map(s => s.outerHTML).join('\n');
    const html = head + styles + '</head><body>' + body + '</body></html>';
    const blob = new Blob(['﻿', html], { type: 'application/msword' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'RSU-Scholarship-Handout-' + new Date().toISOString().slice(0,10) + '.doc';
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 500);
}
</script>

</body>
</html>
