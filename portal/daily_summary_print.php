<?php
// portal/daily_summary_print.php — A4 print-friendly daily summary
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$can = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_daily_summary']);
if (!$can) { http_response_code(403); exit('Access Denied'); }

$pdo = db();
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));

// Reuse aggregation logic by calling the AJAX endpoint internally would create a loop,
// so we replicate the queries here (read-only, safe).
// Productivity
$prodRows = $pdo->prepare("
    SELECT d.dept_id, dept.name AS dept_name, d.patients, d.rn_count, d.head_count, d.shift_hours,
           COALESCE(s.hpv, 0.24) AS hpv, COALESCE(s.threshold_low, 80) AS thr_low, COALESCE(s.threshold_high,110) AS thr_high
    FROM sys_nurse_productivity_daily d
    LEFT JOIN sys_departments dept ON dept.id = d.dept_id
    LEFT JOIN sys_nurse_productivity_settings s ON s.dept_id = d.dept_id
    WHERE d.entry_date = ?
    ORDER BY dept.sort_order, dept.name
");
$prodRows->execute([$date]);
$prods = [];
$totVisits = 0; $prodSum = 0; $prodN = 0;
foreach ($prodRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $needed = (int)$r['patients'] * (float)$r['hpv'];
    $avail  = ((int)$r['rn_count'] + (int)$r['head_count']) * (float)$r['shift_hours'];
    $prod   = $avail > 0 ? ($needed / $avail) * 100 : 0;
    $status = 'Optimal';
    if ($prod < (float)$r['thr_low'])      $status = 'Over staff';
    elseif ($prod > (float)$r['thr_high']) $status = 'Under staff';
    $prods[] = ['dept' => $r['dept_name'], 'p' => (int)$r['patients'], 'rn' => (int)$r['rn_count'], 'head' => (int)$r['head_count'], 'prod' => $prod, 'status' => $status];
    $totVisits += (int)$r['patients']; $prodSum += $prod; $prodN++;
}
$avgProd = $prodN ? $prodSum / $prodN : 0;

// Finance
$fin = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN kind='income'  THEN amount ELSE 0 END), 0) AS income,
    COALESCE(SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END), 0) AS expense,
    COUNT(*) AS cnt FROM sys_finance_transactions WHERE txn_date = ?");
$fin->execute([$date]);
$finR = $fin->fetch(PDO::FETCH_ASSOC);

$finCats = $pdo->prepare("
    SELECT c.name, c.kind, COUNT(t.id) AS cnt, COALESCE(SUM(t.amount), 0) AS total
    FROM sys_finance_transactions t LEFT JOIN sys_finance_categories c ON c.id = t.category_id
    WHERE t.txn_date = ? GROUP BY c.id, c.name, c.kind ORDER BY total DESC LIMIT 5");
$finCats->execute([$date]);
$finCatList = $finCats->fetchAll(PDO::FETCH_ASSOC);

// Stock
$stock = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN txn_type='receive' THEN qty_change ELSE 0 END), 0) AS qty_in,
    COALESCE(SUM(CASE WHEN txn_type='issue'   THEN -qty_change ELSE 0 END), 0) AS qty_out,
    COUNT(DISTINCT consumable_id) AS items, COUNT(*) AS cnt
    FROM consumable_transactions WHERE txn_date = ?");
$stock->execute([$date]);
$stockR = $stock->fetch(PDO::FETCH_ASSOC);

$lowStock = $pdo->query("
    SELECT name, qty_on_hand, min_stock, unit_piece
    FROM consumables WHERE min_stock > 0 AND qty_on_hand <= min_stock
    ORDER BY (qty_on_hand / GREATEST(min_stock,1)) ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Other
$goldCnt = 0; $insCnt = 0; $assetCnt = 0;
try { $st = $pdo->prepare("SELECT COUNT(*) FROM gold_card_history WHERE DATE(changed_at) = ?"); $st->execute([$date]); $goldCnt = (int)$st->fetchColumn(); } catch (Throwable $e) {}
try { $st = $pdo->prepare("SELECT COUNT(*) FROM insurance_batch WHERE DATE(uploaded_at) = ?"); $st->execute([$date]); $insCnt = (int)$st->fetchColumn(); } catch (Throwable $e) {}
try { $st = $pdo->prepare("SELECT COUNT(*) FROM asset_movements WHERE DATE(moved_at) = ?"); $st->execute([$date]); $assetCnt = (int)$st->fetchColumn(); } catch (Throwable $e) {}

$THAI_DAYS_FULL = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$dayName = $THAI_DAYS_FULL[(int)date('w', strtotime($date))];
$yearBE = (int)date('Y', strtotime($date)) + 543;
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>สรุปงานประจำวัน — <?= htmlspecialchars($date) ?></title>
<style>
  @page { size: A4; margin: 14mm; }
  body { font-family: 'Sarabun', 'TH SarabunPSK', sans-serif; font-size: 12px; color:#0f172a; margin:0; }
  h1 { font-size: 18px; margin: 0 0 4px; color: #1f7a4c; }
  .hdr { padding: 10px 0; border-bottom: 2px solid #2e9e63; margin-bottom: 12px; display:flex; justify-content:space-between; align-items:flex-end; }
  .hdr-sub { font-size: 11px; color: #475569; }
  h2 { font-size: 13px; margin: 16px 0 6px; color: #1f7a4c; border-left: 3px solid #2e9e63; padding-left: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #f0fdf4; padding: 6px 8px; text-align: left; border-bottom: 1.5px solid #2e9e63; font-weight: 700; }
  td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
  td.num, th.num { text-align: right; }
  .kpi-row { display:flex; gap:8px; margin-bottom:12px; }
  .kpi { flex:1; padding:8px 10px; border-radius:6px; background:#f8fafc; border-left:3px solid #2e9e63; }
  .kpi.expense { border-left-color:#ef4444; background:#fef2f2; }
  .kpi.prod    { border-left-color:#3b82f6; background:#eff6ff; }
  .kpi.alert   { border-left-color:#f59e0b; background:#fffbeb; }
  .kpi label { font-size: 10px; color: #64748b; display:block; }
  .kpi strong { font-size: 16px; color: #0f172a; }
  .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
  .pill-ok { background: #d1fae5; color: #047857; }
  .pill-und { background: #fee2e2; color: #b91c1c; }
  .pill-ovr { background: #fef3c7; color: #b45309; }
  .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #94a3b8; }
  .print-btn { position: fixed; top: 10px; right: 10px; padding: 8px 14px; background: #2e9e63; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 12px; }
  .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .empty { color:#94a3b8; font-style:italic; padding:8px 0; font-size:11px; }
  @media print { .print-btn { display: none; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ พิมพ์</button>

<div class="hdr">
    <div>
        <h1>สรุปงานประจำวัน</h1>
        <div class="hdr-sub">วัน<?= $dayName ?> ที่ <?= date('d/m/', strtotime($date)) . $yearBE ?></div>
    </div>
    <div class="hdr-sub" style="text-align:right">พิมพ์เมื่อ <?= date('d/m/Y H:i') ?></div>
</div>

<!-- HERO KPIs -->
<div class="kpi-row">
    <div class="kpi"><label>ผู้ป่วยทั้งหมด</label><strong><?= number_format($totVisits) ?> คน</strong></div>
    <div class="kpi prod"><label>Productivity เฉลี่ย</label><strong><?= $avgProd ? number_format($avgProd, 1) . '%' : '—' ?></strong></div>
    <div class="kpi"><label>รายรับ</label><strong>฿<?= number_format($finR['income']) ?></strong></div>
    <div class="kpi expense"><label>รายจ่าย</label><strong>฿<?= number_format($finR['expense']) ?></strong></div>
    <div class="kpi alert"><label>แจ้งเตือนสต็อก</label><strong><?= count($lowStock) ?> รายการ</strong></div>
</div>

<!-- Productivity per dept -->
<h2>1. Productivity รายหน่วยงาน</h2>
<?php if (!$prods): ?>
    <div class="empty">— ยังไม่มีหน่วยงานที่กรอกข้อมูลในวันนี้ —</div>
<?php else: ?>
<table>
    <thead><tr><th>หน่วยงาน</th><th class="num">ผู้ป่วย</th><th class="num">RN</th><th class="num">หัวหน้า</th><th class="num">Prod %</th><th>สถานะ</th></tr></thead>
    <tbody>
    <?php foreach ($prods as $p): $pill = $p['status']==='Optimal'?'pill-ok':($p['status']==='Under staff'?'pill-und':'pill-ovr'); ?>
        <tr>
            <td><?= htmlspecialchars($p['dept']) ?></td>
            <td class="num"><?= number_format($p['p']) ?></td>
            <td class="num"><?= $p['rn'] ?></td>
            <td class="num"><?= $p['head'] ?></td>
            <td class="num"><strong><?= number_format($p['prod'], 1) ?>%</strong></td>
            <td><span class="pill <?= $pill ?>"><?= htmlspecialchars($p['status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Finance + Stock side by side -->
<div class="grid-2">
    <div>
        <h2>2. รายรับ-รายจ่าย</h2>
        <p style="font-size:11px;margin:0 0 6px">
            รวม <?= $finR['cnt'] ?> รายการ ·
            รายรับ ฿<?= number_format($finR['income']) ?> ·
            รายจ่าย ฿<?= number_format($finR['expense']) ?> ·
            สุทธิ <strong>฿<?= number_format((float)$finR['income'] - (float)$finR['expense']) ?></strong>
        </p>
        <?php if (!$finCatList): ?>
            <div class="empty">— ไม่มีรายการในวันนี้ —</div>
        <?php else: ?>
        <table>
            <thead><tr><th>หมวด</th><th>ประเภท</th><th class="num">จำนวน</th><th class="num">รวม</th></tr></thead>
            <tbody>
            <?php foreach ($finCatList as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['name'] ?: '— ไม่ระบุหมวด —') ?></td>
                    <td><?= ($c['kind'] ?? '') === 'income' ? 'รายรับ' : 'รายจ่าย' ?></td>
                    <td class="num"><?= $c['cnt'] ?></td>
                    <td class="num">฿<?= number_format((float)$c['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div>
        <h2>3. สต็อกวัสดุ</h2>
        <p style="font-size:11px;margin:0 0 6px">
            รับเข้า <strong><?= number_format((int)$stockR['qty_in']) ?></strong> ชิ้น ·
            เบิกออก <strong><?= number_format((int)$stockR['qty_out']) ?></strong> ชิ้น ·
            เคลื่อนไหว <?= $stockR['items'] ?> รายการ
        </p>
        <strong style="color:#b45309;font-size:11px">⚠ ใกล้หมด — ต้องสั่งซื้อ</strong>
        <?php if (!$lowStock): ?>
            <div class="empty">— ไม่มีรายการที่ต่ำกว่าจุดสั่งซื้อ —</div>
        <?php else: ?>
        <table style="margin-top:4px">
            <thead><tr><th>รายการ</th><th class="num">คงเหลือ</th><th class="num">เกณฑ์</th></tr></thead>
            <tbody>
            <?php foreach ($lowStock as $l): $unit = htmlspecialchars($l['unit_piece'] ?: 'ชิ้น'); ?>
                <tr>
                    <td><?= htmlspecialchars($l['name']) ?></td>
                    <td class="num"><strong style="color:#b91c1c"><?= number_format((int)$l['qty_on_hand']) ?></strong> <?= $unit ?></td>
                    <td class="num"><?= number_format((int)$l['min_stock']) ?> <?= $unit ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Other events -->
<h2>4. เหตุการณ์อื่นๆ</h2>
<div class="kpi-row">
    <div class="kpi"><label>บัตรทอง — โอนย้าย</label><strong><?= number_format($goldCnt) ?> ราย</strong></div>
    <div class="kpi"><label>ประกัน — Batch ใหม่</label><strong><?= number_format($insCnt) ?> batch</strong></div>
    <div class="kpi"><label>ครุภัณฑ์ — มีปัญหา</label><strong><?= number_format($assetCnt) ?> รายการ</strong></div>
</div>

<div class="footer">
    เอกสารนี้สร้างจาก RSU Medical Clinic Services · พิมพ์โดย <?= htmlspecialchars($_SESSION['admin_username'] ?? '—') ?>
</div>

</body></html>
