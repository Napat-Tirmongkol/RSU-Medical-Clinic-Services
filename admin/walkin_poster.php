<?php
// admin/walkin_poster.php — A4 print-ready Walk-in poster
// Layout: Clinic header → Campaign title → Large QR (~8x8cm) → Steps → Footer
// Print: A4 portrait · auto-trigger print on load (?autoprint=1)
// Download: html2pdf.js available via "ดาวน์โหลด PDF" button
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$campaignId = (int)($_GET['cid'] ?? 0);
$autoprint  = isset($_GET['autoprint']);

if ($campaignId <= 0) {
    http_response_code(400);
    exit('Invalid campaign ID');
}

// Ensure column exists (idempotent)
try {
    $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS walkin_enabled TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException) {}

$st = $pdo->prepare("
    SELECT c.id, c.title, c.description, c.type, c.status, c.walkin_enabled,
           c.available_from, c.available_until, c.contact_phone, c.what_to_bring,
           c.prerequisites, c.room_id,
           r.code AS room_code, r.name AS room_name, r.floor AS room_floor, r.type AS room_type
    FROM camp_list c
    LEFT JOIN sys_clinic_rooms r ON c.room_id = r.id
    WHERE c.id = :id
    LIMIT 1
");
$st->execute([':id' => $campaignId]);
$campaign = $st->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    http_response_code(404);
    exit('ไม่พบกิจกรรม');
}

// Build URL + token (same logic as user/api_walkin_qr.php)
$token  = hash_hmac('sha256', "qr:walkin:{$campaignId}", QR_SLOT_SECRET);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$walkinUrl = $scheme . '://' . $host . $base . '/user/walkin.php?cid=' . $campaignId . '&t=' . $token;
$qrImgUrl  = '../user/api_walkin_qr.php?campaign=' . $campaignId . '&size=14&margin=2';

function poster_fmt_date(string $d): string {
    $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $parts  = explode('-', $d);
    if (count($parts) !== 3) return $d;
    return (int)$parts[2] . ' ' . ($months[(int)$parts[1]] ?? '') . ' ' . ((int)$parts[0] + 543);
}
function poster_type_label(string $t): array {
    return match ($t) {
        'vaccine'      => ['ฉีดวัคซีน', '#16a34a'],
        'training'     => ['อบรม/สัมมนา', '#7c3aed'],
        'health_check' => ['ตรวจสุขภาพ', '#059669'],
        default        => ['กิจกรรมคลินิก', '#0ea5e9'],
    };
}
$typeInfo  = poster_type_label((string)$campaign['type']);
$siteName  = defined('SITE_NAME') ? SITE_NAME : 'RSU Medical Clinic';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>โปสเตอร์ Walk-in · <?= htmlspecialchars($campaign['title']) ?></title>
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  * { font-family: 'Sarabun', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { background: #e2e8f0; padding: 24px; margin: 0; }

  /* A4 page: 210 × 297 mm. Use mm for accurate print sizing.
     Hard height (not min-height) + overflow hidden enforces single-page fit. */
  .a4-page {
    width: 210mm;
    height: 297mm;
    margin: 0 auto;
    background: #fff;
    box-shadow: 0 10px 40px rgba(0,0,0,.15);
    padding: 14mm 14mm 12mm;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
  }

  /* Top rainbow strip */
  .a4-page::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 6mm;
    background: linear-gradient(90deg, #d97706 0%, #f59e0b 25%, #fbbf24 50%, #f59e0b 75%, #d97706 100%);
  }

  .clinic-header {
    margin-top: 2mm;
    margin-bottom: 4mm;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 4mm;
    border-bottom: 1.5px dashed #e2e8f0;
    flex-shrink: 0;
  }
  .clinic-logo {
    width: 14mm;
    height: 14mm;
    border-radius: 10px;
    background: linear-gradient(135deg, #d97706, #f59e0b);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(217,119,6,.30);
  }
  .clinic-label-row {
    flex: 1;
    min-width: 0;
  }
  .clinic-name {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
  }
  .clinic-sub {
    font-size: 10px;
    color: #64748b;
    margin-top: 1px;
  }
  .walkin-badge {
    background: linear-gradient(135deg, #d97706, #f59e0b);
    color: white;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(217,119,6,.25);
    flex-shrink: 0;
  }

  .campaign-title-row {
    text-align: center;
    margin-bottom: 4mm;
    flex-shrink: 0;
  }
  .type-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    margin-bottom: 6px;
    letter-spacing: 0.04em;
  }
  .campaign-title {
    font-size: 22px;
    font-weight: 900;
    color: #0f172a;
    line-height: 1.2;
    margin-bottom: 3px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .campaign-meta {
    font-size: 11px;
    color: #475569;
    margin-top: 3px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .campaign-meta-inline {
    font-size: 11px;
    color: #475569;
    margin-top: 4px;
    -webkit-line-clamp: unset;
    display: block;
    overflow: visible;
  }

  .qr-section {
    text-align: center;
    margin: 2mm 0 3mm;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 0;
  }
  .qr-frame {
    display: inline-block;
    padding: 7mm;
    background: white;
    border: 4px solid #d97706;
    border-radius: 16px;
    box-shadow: 0 12px 32px rgba(217,119,6,.20);
    position: relative;
  }
  .qr-frame::before, .qr-frame::after,
  .qr-corner-tl, .qr-corner-tr, .qr-corner-bl, .qr-corner-br {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    border-color: #d97706;
    border-style: solid;
  }
  .qr-corner-tl { top: 5px; left: 5px;  border-width: 3px 0 0 3px; }
  .qr-corner-tr { top: 5px; right: 5px; border-width: 3px 3px 0 0; }
  .qr-corner-bl { bottom: 5px; left: 5px;  border-width: 0 0 3px 3px; }
  .qr-corner-br { bottom: 5px; right: 5px; border-width: 0 3px 3px 0; }

  .qr-img {
    width: 72mm;
    height: 72mm;
    display: block;
  }
  .scan-label {
    margin-top: 3mm;
    font-size: 17px;
    font-weight: 900;
    color: #d97706;
    letter-spacing: 0.03em;
  }
  .scan-sublabel {
    font-size: 11px;
    color: #64748b;
    margin-top: 2px;
  }

  .steps-section {
    margin-top: 2mm;
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border-radius: 12px;
    padding: 4mm;
    border: 1.5px solid #fbbf24;
    flex-shrink: 0;
  }
  .steps-title {
    font-size: 12px;
    font-weight: 800;
    color: #92400e;
    margin-bottom: 5px;
    text-align: center;
    letter-spacing: 0.05em;
  }
  .steps-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
  }
  .step-card {
    background: white;
    border-radius: 9px;
    padding: 6px 4px;
    text-align: center;
    border: 1px solid #fde68a;
  }
  .step-num {
    display: inline-flex;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #d97706;
    color: white;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 900;
    margin-bottom: 3px;
  }
  .step-icon { font-size: 16px; color: #d97706; margin-bottom: 2px; display: block; }
  .step-text { font-size: 9px; font-weight: 700; color: #334155; line-height: 1.3; }

  .footer-row {
    margin-top: 3mm;
    padding-top: 3mm;
    border-top: 1.5px dashed #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10px;
    color: #64748b;
    flex-shrink: 0;
  }
  .contact-text { font-weight: 700; color: #475569; }

  /* Toolbar (hidden in print) */
  .toolbar {
    position: fixed;
    top: 16px;
    right: 16px;
    display: flex;
    gap: 8px;
    z-index: 50;
  }
  .toolbar button, .toolbar a {
    padding: 9px 16px;
    border-radius: 10px;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s ease;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
  }
  .toolbar button:hover, .toolbar a:hover {
    background: #f59e0b;
    color: white;
    border-color: #f59e0b;
  }
  .toolbar .btn-primary {
    background: linear-gradient(135deg, #d97706, #f59e0b);
    color: white;
    border-color: #d97706;
  }

  @media print {
    body { background: white; padding: 0; }
    .a4-page { box-shadow: none; margin: 0; }
    .toolbar { display: none !important; }
    @page { size: A4 portrait; margin: 0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="campaigns.php"><i class="fa-solid fa-arrow-left"></i> กลับ</a>
  <button onclick="downloadPdf()"><i class="fa-solid fa-file-pdf"></i> ดาวน์โหลด PDF</button>
  <button class="btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> พิมพ์โปสเตอร์</button>
</div>

<div class="a4-page" id="posterPage">

  <!-- Header -->
  <div class="clinic-header">
    <div class="clinic-logo">
      <i class="fa-solid fa-hospital"></i>
    </div>
    <div class="clinic-label-row">
      <div class="clinic-name"><?= htmlspecialchars($siteName) ?></div>
      <div class="clinic-sub">ระบบจัดการกิจกรรมคลินิก · e-Campaign</div>
    </div>
    <div class="walkin-badge">
      <i class="fa-solid fa-person-walking"></i> Walk-in
    </div>
  </div>

  <!-- Campaign title -->
  <div class="campaign-title-row">
    <div class="type-pill" style="background:<?= $typeInfo[1] ?>1a;color:<?= $typeInfo[1] ?>">
      <?= $typeInfo[0] ?>
    </div>
    <div class="campaign-title"><?= htmlspecialchars($campaign['title']) ?></div>
    <?php if (!empty($campaign['description'])): ?>
    <div class="campaign-meta"><?= htmlspecialchars($campaign['description']) ?></div>
    <?php endif; ?>
    <div class="campaign-meta-inline">
      <?php if ($campaign['available_until']): ?>
        <i class="fa-regular fa-calendar"></i>
        เปิดรับถึง <?= poster_fmt_date((string)$campaign['available_until']) ?>
      <?php endif; ?>
      <?php if (!empty($campaign['room_name'])): ?>
        <span style="margin-left:8px"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($campaign['room_name']) ?>
        <?php if (!empty($campaign['room_floor'])): ?> · ชั้น <?= htmlspecialchars((string)$campaign['room_floor']) ?><?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- QR -->
  <div class="qr-section">
    <div class="qr-frame">
      <span class="qr-corner-tl"></span>
      <span class="qr-corner-tr"></span>
      <span class="qr-corner-bl"></span>
      <span class="qr-corner-br"></span>
      <img src="<?= htmlspecialchars($qrImgUrl) ?>" alt="QR Walk-in" class="qr-img" id="qrImg">
    </div>
    <div class="scan-label">
      <i class="fa-solid fa-mobile-screen"></i> สแกนเพื่อลงทะเบียน
    </div>
    <div class="scan-sublabel">เปิดกล้องมือถือสแกน QR · ใช้เวลาไม่ถึง 1 นาที</div>
  </div>

  <!-- Steps -->
  <div class="steps-section">
    <div class="steps-title">ขั้นตอนการลงทะเบียน</div>
    <div class="steps-grid">
      <div class="step-card">
        <div class="step-num">1</div>
        <i class="fa-solid fa-camera step-icon"></i>
        <div class="step-text">เปิดกล้อง<br>มือถือ</div>
      </div>
      <div class="step-card">
        <div class="step-num">2</div>
        <i class="fa-solid fa-qrcode step-icon"></i>
        <div class="step-text">สแกน QR<br>ด้านบน</div>
      </div>
      <div class="step-card">
        <div class="step-num">3</div>
        <i class="fa-brands fa-line step-icon" style="color:#06c755"></i>
        <div class="step-text">เข้าสู่ระบบ<br>ด้วย LINE</div>
      </div>
      <div class="step-card">
        <div class="step-num">4</div>
        <i class="fa-solid fa-check-double step-icon"></i>
        <div class="step-text">ยืนยันข้อมูล<br>เสร็จสิ้น</div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer-row">
    <div>
      <?php if (!empty($campaign['contact_phone'])): ?>
        <span class="contact-text"><i class="fa-solid fa-phone"></i> ติดต่อ: <?= htmlspecialchars($campaign['contact_phone']) ?></span>
      <?php else: ?>
        <span class="contact-text"><i class="fa-solid fa-hospital"></i> สอบถามที่เคาน์เตอร์คลินิก</span>
      <?php endif; ?>
    </div>
    <div style="font-family:monospace;font-size:9px;color:#94a3b8">
      ID: <?= $campaignId ?> · v<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1.0' ?>
    </div>
  </div>

</div>

<script>
  function downloadPdf() {
    const el = document.getElementById('posterPage');
    const filename = 'walkin-poster-<?= $campaignId ?>-<?= date('Ymd') ?>.pdf';
    html2pdf().set({
      margin: 0,
      filename: filename,
      image:   { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true, allowTaint: true },
      jsPDF:   { unit: 'mm', format: 'a4', orientation: 'portrait' }
    }).from(el).save();
  }

  <?php if ($autoprint): ?>
  // Wait for QR image to load before printing
  document.addEventListener('DOMContentLoaded', () => {
    const qr = document.getElementById('qrImg');
    if (qr.complete) {
      setTimeout(() => window.print(), 300);
    } else {
      qr.addEventListener('load', () => setTimeout(() => window.print(), 300));
    }
  });
  <?php endif; ?>
</script>

</body>
</html>
