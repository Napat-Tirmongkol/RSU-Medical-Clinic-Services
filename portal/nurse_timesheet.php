<?php
// portal/nurse_timesheet.php — ใบลงเวลาปฏิบัติงาน (A4 print-friendly) ต่อพยาบาล/ต่อเดือน
// Usage: nurse_timesheet.php?staff_id=NN&year=YYYY(BE)&month=MM
//    หรือ nurse_timesheet.php?org_member_id=NN&year=YYYY(BE)&month=MM
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$staffId = (int)($_GET['staff_id'] ?? 0);
$orgId   = (int)($_GET['org_member_id'] ?? 0);
$yearBE  = (int)($_GET['year'] ?? 0);
$month   = (int)($_GET['month'] ?? 0);

if (($staffId <= 0 && $orgId <= 0) || $yearBE < 2500 || $yearBE > 2700 || $month < 1 || $month > 12) {
    http_response_code(400);
    exit('Parameters ไม่ถูกต้อง: ต้องระบุ staff_id หรือ org_member_id + year(BE) + month');
}

// ── 1) โหลดข้อมูลพยาบาล ──
$nurse = null;
if ($staffId > 0) {
    $st = $pdo->prepare("SELECT s.id, s.full_name, s.national_id, s.official_title, s.hourly_rate, s.job_title,
                                (SELECT op.title FROM sys_org_members om
                                   INNER JOIN sys_org_positions op ON op.id = om.position_id
                                   WHERE om.staff_id = s.id AND om.is_active = 1
                                   ORDER BY om.display_order ASC, om.id ASC LIMIT 1) AS org_position_title
                         FROM sys_staff s WHERE s.id = ?");
    $st->execute([$staffId]);
    $nurse = $st->fetch(PDO::FETCH_ASSOC);
    $sourceKey = 'S' . $staffId;
} elseif ($orgId > 0) {
    $st = $pdo->prepare("SELECT om.id, TRIM(CONCAT(COALESCE(om.prefix,''),' ',om.full_name)) AS full_name,
                                om.national_id, om.official_title, om.hourly_rate,
                                op.title AS org_position_title, NULL AS job_title
                         FROM sys_org_members om
                         LEFT JOIN sys_org_positions op ON op.id = om.position_id
                         WHERE om.id = ?");
    $st->execute([$orgId]);
    $nurse = $st->fetch(PDO::FETCH_ASSOC);
    $sourceKey = 'O' . $orgId;
}
if (!$nurse) { http_response_code(404); exit('ไม่พบพยาบาล'); }

// ── 2) โหลด timesheet settings (ชื่อคลินิก/ผู้ลงนาม/ภาษี/อัตราเริ่มต้น) ──
$settings = [
    'clinic_name' => 'คลินิกเวชกรรม มหาวิทยาลัยรังสิต',
    'signer_name' => '',
    'signer_title' => '',
    'tax_rate' => 3.00,
    'default_hourly_rate' => 120.00,
];
try {
    $row = $pdo->query("SELECT * FROM sys_nurse_timesheet_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) $settings = array_merge($settings, $row);
} catch (Throwable $e) { /* table may not exist yet */ }

// อัตราต่อชั่วโมงที่ใช้: ของพยาบาลเอง > default
$hourlyRate = $nurse['hourly_rate'] !== null && $nurse['hourly_rate'] !== ''
    ? (float)$nurse['hourly_rate']
    : (float)$settings['default_hourly_rate'];

// ตำแหน่งที่ใช้แสดง
$displayTitle = trim((string)($nurse['official_title'] ?? '')) !== ''
    ? $nurse['official_title']
    : (trim((string)($nurse['org_position_title'] ?? '')) !== ''
        ? $nurse['org_position_title']
        : ($nurse['job_title'] ?? ''));

// ── 3) โหลดตารางเวรของเดือนนั้น ──
$schedule = [];   // ['nurseId-day' => shiftCode]
$leaves   = [];
$nursesJson = [];
$shiftTypesJson = [];

try {
    $stmt = $pdo->prepare("SELECT schedule_json, leaves_json FROM sys_nurse_schedule_monthly WHERE year_be = :y AND month = :m");
    $stmt->execute([':y' => $yearBE, ':m' => $month]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($m) {
        $schedule = json_decode((string)$m['schedule_json'], true) ?: [];
        $leaves   = json_decode((string)$m['leaves_json'], true) ?: [];
    }
    $g = $pdo->query("SELECT nurses_json, shift_types_json FROM sys_nurse_schedule_global WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($g) {
        $nursesJson     = json_decode((string)$g['nurses_json'], true) ?: [];
        $shiftTypesJson = json_decode((string)$g['shift_types_json'], true) ?: [];
    }
} catch (Throwable $e) { /* tables may not exist yet */ }

// ── 4) หา nurseId ใน state.nurses ที่ตรงกับ staff_id/org_member_id ──
$nurseRefId = null;
foreach ($nursesJson as $n) {
    if ($staffId > 0 && (int)($n['staffId'] ?? 0) === $staffId) { $nurseRefId = $n['id']; break; }
    if ($orgId   > 0 && (int)($n['orgMemberId'] ?? 0) === $orgId) { $nurseRefId = $n['id']; break; }
}

// ── 5) สร้าง map ของ shift type → start/end/hours ──
$DEFAULT_SHIFT_TYPES = [
    'ช'  => ['startTime' => '08:00', 'endTime' => '16:00', 'hours' => 8],
    'บ'  => ['startTime' => '16:00', 'endTime' => '20:00', 'hours' => 4],
    'ด'  => ['startTime' => '00:00', 'endTime' => '08:00', 'hours' => 8],
    'ชบ' => ['startTime' => '08:00', 'endTime' => '20:00', 'hours' => 12],
    'ดบ' => ['startTime' => '16:00', 'endTime' => '08:00', 'hours' => 16],
    'DN' => ['startTime' => '08:00', 'endTime' => '08:00', 'hours' => 24],
];
$shiftMap = $DEFAULT_SHIFT_TYPES;
foreach ($shiftTypesJson as $code => $ov) {
    if (!isset($shiftMap[$code])) continue;
    if (!empty($ov['startTime'])) $shiftMap[$code]['startTime'] = $ov['startTime'];
    if (!empty($ov['endTime']))   $shiftMap[$code]['endTime']   = $ov['endTime'];
    if (isset($ov['hours']) && is_numeric($ov['hours'])) $shiftMap[$code]['hours'] = (float)$ov['hours'];
}

// ── 6) คำนวณรายการต่อวัน ──
$THAI_MONTHS_SHORT = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$THAI_MONTHS_FULL  = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $yearBE - 543));

$rows = [];
$totalHours = 0.0;
if ($nurseRefId !== null) {
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $key = $nurseRefId . '-' . $d;
        $shift = $schedule[$key] ?? '';
        $leave = $leaves[$key]   ?? '';
        if ($leave) continue; // วันลาไม่นับ
        if (!$shift || !isset($shiftMap[$shift])) continue;
        $info = $shiftMap[$shift];
        if (($info['hours'] ?? 0) <= 0) continue;
        $rows[] = [
            'day'   => $d,
            'date'  => $d . ' ' . $THAI_MONTHS_SHORT[$month] . ' ' . $yearBE,
            'start' => $info['startTime'] ?? '-',
            'end'   => $info['endTime'] ?? '-',
            'hours' => (float)$info['hours'],
            'shift' => $shift,
        ];
        $totalHours += (float)$info['hours'];
    }
}

$gross  = round($totalHours * $hourlyRate, 2);
$taxPct = (float)$settings['tax_rate'];
$tax    = round($gross * $taxPct / 100, 2);
$net    = round($gross - $tax, 2);

// CSRF สำหรับปุ่ม "ส่งเข้า Cash Book"
$csrf = get_csrf_token();
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ใบลงเวลาปฏิบัติงาน — <?= htmlspecialchars($nurse['full_name']) ?> · <?= $THAI_MONTHS_FULL[$month] . ' ' . $yearBE ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
* { box-sizing: border-box; }
body { font-family: 'Sarabun', sans-serif; background: #e2e8f0; margin: 0; padding: 20px; color: #0f172a; }
.actions { max-width: 800px; margin: 0 auto 16px; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.actions button, .actions a { padding: 10px 18px; border-radius: 8px; border: none; font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-print { background: #2e9e63; color: #fff; }
.btn-print:hover { background: #0d8a52; }
.btn-cash { background: #f59e0b; color: #fff; }
.btn-cash:hover { background: #d97706; }
.btn-back { background: #cbd5e1; color: #1e293b; }
.sheet { max-width: 800px; margin: 0 auto; background: #fff; padding: 36px 44px; box-shadow: 0 6px 24px rgba(0,0,0,0.08); border-radius: 6px; }
.title-row { text-align: center; margin-bottom: 14px; }
.title-row h1 { font-size: 20px; font-weight: 800; margin: 0; }
.meta-row { font-size: 14px; margin: 6px 0; }
.meta-row .lbl { color: #475569; }
.tbl { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 13px; }
.tbl th, .tbl td { border: 1px solid #475569; padding: 6px 8px; text-align: center; }
.tbl th { background: #f1f5f9; font-weight: 700; }
.tbl .col-date { width: 90px; }
.tbl .col-time { width: 80px; }
.tbl .col-hours { width: 80px; }
.tbl .col-sign { width: 140px; text-align: center; }
.tbl tfoot td { font-weight: 700; background: #f8fafc; }
.summary { margin-top: 18px; font-size: 14px; }
.summary table { margin-left: auto; border-collapse: collapse; }
.summary table td { padding: 4px 12px; }
.summary table td.lbl { color: #475569; }
.summary table td.val { font-weight: 700; text-align: right; min-width: 110px; }
.summary table td.eq { color: #94a3b8; }
.summary table td.unit { color: #475569; }
.statement { margin-top: 20px; font-size: 13px; }
.signature { margin-top: 56px; text-align: right; font-size: 14px; }
.signature .line { display: inline-block; width: 260px; border-bottom: 1px dotted #475569; height: 22px; }
.signature .name { margin-top: 4px; }
.signature .title { color: #475569; }
@media print {
    body { background: #fff; padding: 0; }
    .actions { display: none; }
    .sheet { box-shadow: none; max-width: 100%; padding: 18px; }
}
</style>
</head>
<body>
<div class="actions">
    <a href="javascript:history.back()" class="btn-back">← กลับ</a>
    <?php if ($gross > 0): ?>
    <button class="btn-cash" id="btn-cash" type="button">💸 ส่งเข้า Cash Book</button>
    <?php endif; ?>
    <button class="btn-print" onclick="window.print()">🖨 พิมพ์</button>
</div>

<div class="sheet">
    <div class="title-row">
        <h1>ใบลงเวลาปฏิบัติงาน <?= htmlspecialchars((string)$settings['clinic_name']) ?></h1>
        <div class="meta-row">ประจำเดือน <strong><?= $THAI_MONTHS_FULL[$month] ?></strong> <strong><?= $yearBE ?></strong></div>
    </div>

    <div class="meta-row">
        <span class="lbl">ชื่อ-นามสกุล</span>
        <strong><?= htmlspecialchars($nurse['full_name']) ?></strong>
        <?php if (!empty($displayTitle)): ?>
        &nbsp;&nbsp;<?= htmlspecialchars($displayTitle) ?>
        <?php endif; ?>
        &nbsp;&nbsp;ประจำการ <?= htmlspecialchars((string)$settings['clinic_name']) ?>
    </div>
    <div class="meta-row">
        <span class="lbl">เลขที่บัตรประชาชน</span>
        <strong><?= htmlspecialchars($nurse['national_id'] ?? '—') ?></strong>
    </div>

    <table class="tbl">
        <thead>
            <tr>
                <th class="col-date" rowspan="2">วันที่</th>
                <th colspan="2">เวลาปฏิบัติงาน</th>
                <th class="col-hours" rowspan="2">จำนวน/ชั่วโมง</th>
                <th class="col-sign" rowspan="2">ลงนาม</th>
            </tr>
            <tr>
                <th class="col-time">เริ่ม/น</th>
                <th class="col-time">ถึง/น</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="padding:24px;color:#94a3b8">— ไม่มีตารางเวรในเดือนนี้ —</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td style="text-align:left;padding-left:10px"><?= htmlspecialchars($r['date']) ?></td>
                    <td><?= htmlspecialchars($r['start']) ?></td>
                    <td><?= htmlspecialchars($r['end']) ?></td>
                    <td><?= rtrim(rtrim(number_format($r['hours'], 2, '.', ''), '0'), '.') ?></td>
                    <td></td>
                </tr>
            <?php endforeach; endif; ?>
            <?php
            // เติมแถวว่างให้ครบประมาณ 25 แถว — เพื่อให้หน้ากระดาษเต็มเมื่อชั่วโมงน้อย
            $filler = max(0, 25 - count($rows));
            for ($i = 0; $i < $filler; $i++): ?>
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right">รวม</td>
                <td><?= rtrim(rtrim(number_format($totalHours, 2, '.', ''), '0'), '.') ?></td>
                <td style="text-align:left">ชม.</td>
            </tr>
        </tfoot>
    </table>

    <div class="statement">ขอรับรองว่าได้ตรวจสอบความถูกต้องแล้ว จึงขอให้ดำเนินการเบิกจ่ายค่าตอบแทนดังนี้</div>

    <div class="summary">
        <table>
            <tr>
                <td class="lbl">ค่าตอบแทนรวม</td>
                <td class="val"><?= rtrim(rtrim(number_format($totalHours, 2, '.', ''), '0'), '.') ?></td>
                <td class="unit">ชั่วโมงๆ ละ <?= number_format($hourlyRate, 0) ?> บาท</td>
                <td class="eq">=</td>
                <td class="val"><?= number_format($gross, 2) ?></td>
                <td class="unit">บาท</td>
            </tr>
            <tr>
                <td class="lbl">หักภาษี ณ ที่จ่าย <?= rtrim(rtrim(number_format($taxPct, 2, '.', ''), '0'), '.') ?>%</td>
                <td></td><td></td>
                <td class="eq">=</td>
                <td class="val"><?= number_format($tax, 2) ?></td>
                <td class="unit">บาท</td>
            </tr>
            <tr>
                <td class="lbl">คงเหลือ</td>
                <td></td><td></td>
                <td class="eq">=</td>
                <td class="val" style="color:#059669"><?= number_format($net, 2) ?></td>
                <td class="unit">บาท</td>
            </tr>
        </table>
    </div>

    <div class="signature">
        <div>ลงชื่อ<span class="line"></span></div>
        <div class="name"><?= htmlspecialchars((string)$settings['signer_name']) ?: '&nbsp;' ?></div>
        <div class="title"><?= htmlspecialchars((string)$settings['signer_title']) ?: '&nbsp;' ?></div>
    </div>
</div>

<?php if ($gross > 0): ?>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const SOURCE_ID = <?= json_encode('TS-' . $sourceKey . '-' . $yearBE . sprintf('%02d', $month)) ?>;
const NURSE_NAME = <?= json_encode($nurse['full_name']) ?>;
const PERIOD = <?= json_encode($THAI_MONTHS_FULL[$month] . ' ' . $yearBE) ?>;
const GROSS = <?= json_encode($gross) ?>;
const NET   = <?= json_encode($net) ?>;
const HOURS = <?= json_encode($totalHours) ?>;
const RATE  = <?= json_encode($hourlyRate) ?>;
const TAX   = <?= json_encode($tax) ?>;
const TAX_PCT = <?= json_encode($taxPct) ?>;
const TXN_DATE = <?= json_encode(sprintf('%04d-%02d-%02d', $yearBE - 543, $month, $daysInMonth)) ?>;

document.getElementById('btn-cash')?.addEventListener('click', async () => {
  const r = await Swal.fire({
    title: 'ส่งเข้า Cash Book',
    html: `<div class="text-left" style="text-align:left;font-size:14px">
      <div><b>${NURSE_NAME}</b> · ${PERIOD}</div>
      <div>ค่าตอบแทนรวม: <b>${GROSS.toLocaleString()} บาท</b> (${HOURS} ชม. × ${RATE.toLocaleString()})</div>
      <div>หักภาษี ${TAX_PCT}%: ${TAX.toLocaleString()} บาท</div>
      <div>คงเหลือ: <b style="color:#059669">${NET.toLocaleString()} บาท</b></div>
      <hr style="margin:8px 0">
      <div style="font-size:12px;color:#64748b">บันทึกเป็น "รายจ่าย" หมวด "เงินเดือน/ค่าจ้าง" วันที่ ${TXN_DATE}<br>ถ้ามีของพยาบาลคนนี้เดือนนี้อยู่แล้วจะอัปเดต (ไม่สร้างซ้ำ)</div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'ส่ง',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#f59e0b',
  });
  if (!r.isConfirmed) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'txn:upsert_from_source');
  fd.append('source_module', 'nurse_timesheet');
  fd.append('source_id', SOURCE_ID);
  fd.append('kind', 'expense');
  fd.append('amount', String(GROSS));
  fd.append('txn_date', TXN_DATE);
  fd.append('description', `ค่าตอบแทนพยาบาล ${NURSE_NAME} · ${PERIOD}`);
  fd.append('category_name', 'เงินเดือน/ค่าจ้าง');
  fd.append('note', `${HOURS} ชม. × ${RATE} บาท = ${GROSS} บาท · ภาษี ${TAX_PCT}% (${TAX}) · คงเหลือ ${NET}`);

  try {
    const res = await fetch('ajax_finance.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json();
    if (!j.ok) { Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: j.message || j.error || '' }); return; }
    Swal.fire({
      icon: 'success',
      title: j.mode === 'updated' ? 'อัปเดตใน Cash Book แล้ว' : 'ส่งเข้า Cash Book แล้ว',
      html: `<div style="font-size:14px">บันทึก ${GROSS.toLocaleString()} บาท · เปิด <a href="index.php?section=finance" target="_blank" style="color:#059669;text-decoration:underline">Cash Book</a></div>`,
      confirmButtonColor: '#059669',
    });
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) });
  }
});
</script>
<?php endif; ?>
</body>
</html>
