<?php
// portal/finance_receipt.php — print-friendly receipt page
// Usage: portal/finance_receipt.php?id=NN&sig=HMAC
//   sig is generated server-side in the list response (fin_receipt_sig).
//   Editor-role users must present a matching sig; admin/superadmin bypass.
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/finance_link.php';

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper = ($adminRole === 'superadmin');
$canFinance = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_finance']);
if (!$canFinance) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงโมดูลการเงิน');
}

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');
if ($id <= 0) { http_response_code(400); exit('ไม่ระบุ id'); }
// Signature required for editor-level users to defeat id enumeration.
// Admin/superadmin can still hit the URL without sig (back-office tooling
// and admin-built links that pre-date signed URLs).
$isAdminRole = $isSuper || ($adminRole === 'admin');
if (!fin_receipt_verify($id, $sig) && !$isAdminRole) {
    http_response_code(403);
    exit('ลิงก์ไม่ถูกต้องหรือหมดอายุ');
}

$stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
    FROM sys_finance_transactions t LEFT JOIN sys_finance_categories c ON c.id = t.category_id
    WHERE t.id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('ไม่พบรายการ'); }

// Auto-assign receipt no ถ้ายังไม่มี (atomic — FOR UPDATE locks the
// matching prefix+year rows so concurrent receipt pages can't allocate
// the same running number)
if (empty($row['receipt_no'])) {
    $prefix = ($row['kind'] === 'income') ? 'RCP' : 'PV';
    $yearBE = (int)date('Y', strtotime($row['txn_date'])) + 543;
    $like = $prefix . '-' . $yearBE . '-%';
    $pdo->beginTransaction();
    try {
        $maxStmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(receipt_no, " . (strlen($prefix) + 7) . ") AS UNSIGNED)) FROM sys_finance_transactions WHERE receipt_no LIKE ? FOR UPDATE");
        $maxStmt->execute([$like]);
        $next = ((int)$maxStmt->fetchColumn()) + 1;
        $receiptNo = sprintf('%s-%d-%06d', $prefix, $yearBE, $next);
        $pdo->prepare("UPDATE sys_finance_transactions SET receipt_no=?, updated_by=? WHERE id=? AND (receipt_no IS NULL OR receipt_no='')")
            ->execute([$receiptNo, (int)($_SESSION['admin_id'] ?? 0) ?: null, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Re-read in case another request beat us to the update
    $reread = $pdo->prepare("SELECT receipt_no FROM sys_finance_transactions WHERE id=?");
    $reread->execute([$id]);
    $row['receipt_no'] = (string)$reread->fetchColumn();
}

// Get clinic profile
$clinic = ['name' => 'RSU Medical Clinic Services', 'address' => '', 'phone' => '', 'tax_id' => ''];
try {
    $c = $pdo->query("SELECT * FROM sys_clinic_profile WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if ($c) $clinic = array_merge($clinic, $c);
} catch (Throwable $e) {}

$isIncome = $row['kind'] === 'income';
$title = $isIncome ? 'ใบเสร็จรับเงิน' : 'ใบสำคัญจ่าย';
$amount = (float)$row['amount'];

function bahtText(float $n): string {
    // Number to Thai baht text (basic)
    $units = ['', 'หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า'];
    $places = ['','สิบ','ร้อย','พัน','หมื่น','แสน','ล้าน'];
    $int = (int)floor($n);
    $satang = (int)round(($n - $int) * 100);
    if ($int === 0) $bahtStr = 'ศูนย์';
    else {
        $bahtStr = '';
        $digits = str_split((string)$int);
        $len = count($digits);
        for ($i = 0; $i < $len; $i++) {
            $d = (int)$digits[$i];
            $place = $len - $i - 1;
            if ($d === 0) continue;
            if ($place === 1 && $d === 1) $bahtStr .= 'สิบ';
            elseif ($place === 1 && $d === 2) $bahtStr .= 'ยี่สิบ';
            elseif ($place === 0 && $d === 1 && $len > 1) $bahtStr .= 'เอ็ด';
            else $bahtStr .= $units[$d] . $places[$place];
        }
    }
    $out = $bahtStr . 'บาท';
    if ($satang === 0) $out .= 'ถ้วน';
    else {
        $s = '';
        if ($satang >= 10) { $t = intdiv($satang, 10); $s .= ($t === 1 ? 'สิบ' : ($t === 2 ? 'ยี่สิบ' : $units[$t] . 'สิบ')); }
        $u = $satang % 10;
        if ($u > 0) $s .= ($satang >= 10 && $u === 1 ? 'เอ็ด' : $units[$u]);
        $out .= $s . 'สตางค์';
    }
    return $out;
}

$bahtText = bahtText($amount);
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($row['receipt_no']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body { font-family: 'Sarabun', sans-serif; background: #e2e8f0; margin: 0; padding: 20px; color: #0f172a; }
.actions { max-width: 760px; margin: 0 auto 16px; display: flex; gap: 8px; justify-content: flex-end; }
.actions button, .actions a { padding: 10px 18px; border-radius: 8px; border: none; font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-print { background: #2e9e63; color: #fff; }
.btn-back { background: #cbd5e1; color: #1e293b; }
.receipt { max-width: 760px; margin: 0 auto; background: #fff; padding: 40px 48px; box-shadow: 0 6px 24px rgba(0,0,0,0.08); border-radius: 6px; }
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 2.5px solid #2e9e63; }
.clinic { flex: 1; }
.clinic .name { font-size: 22px; font-weight: 800; color: #0f172a; }
.clinic .sub { font-size: 12px; color: #64748b; margin-top: 4px; }
.title-box { text-align: right; }
.title-box .t { font-size: 24px; font-weight: 800; color: <?= $isIncome ? '#059669' : '#dc2626' ?>; }
.title-box .copy { font-size: 11px; color: #94a3b8; margin-top: 4px; }
.meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; margin-bottom: 24px; font-size: 13px; }
.meta .lbl { color: #64748b; font-weight: 600; }
.meta .val { font-weight: 700; color: #0f172a; }
.table { width: 100%; border-collapse: collapse; margin: 20px 0; }
.table th { background: #f1f5f9; padding: 10px; text-align: left; font-size: 12px; font-weight: 700; color: #475569; border: 1px solid #cbd5e1; }
.table td { padding: 12px 10px; border: 1px solid #cbd5e1; font-size: 13px; }
.table td.amt { text-align: right; font-weight: 800; }
.total { display: flex; justify-content: flex-end; margin-top: 12px; font-size: 16px; font-weight: 800; }
.total .lbl { color: #64748b; margin-right: 16px; font-weight: 600; }
.total .num { color: <?= $isIncome ? '#059669' : '#dc2626' ?>; }
.baht-text { background: #f8fafc; padding: 10px 16px; border-radius: 8px; margin: 12px 0; font-weight: 700; color: #0f172a; font-size: 14px; }
.signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 48px; padding-top: 16px; }
.sig-box { text-align: center; }
.sig-line { border-top: 1px dotted #475569; margin: 50px 12px 6px; }
.sig-label { font-size: 12px; color: #64748b; font-weight: 600; }
.footer { margin-top: 30px; padding-top: 14px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 10px; color: #94a3b8; }
@media print {
    body { background: #fff; padding: 0; }
    .actions { display: none; }
    .receipt { box-shadow: none; max-width: 100%; padding: 24px; }
}
</style>
</head>
<body>
<div class="actions">
    <a href="javascript:history.back()" class="btn-back">← กลับ</a>
    <button class="btn-print" onclick="window.print()">🖨 พิมพ์</button>
</div>
<div class="receipt">
    <div class="header">
        <div class="clinic">
            <div class="name"><?= htmlspecialchars((string)($clinic['name'] ?? '')) ?></div>
            <?php if (!empty($clinic['address'])): ?><div class="sub"><?= htmlspecialchars((string)$clinic['address']) ?></div><?php endif; ?>
            <?php if (!empty($clinic['phone'])): ?><div class="sub">โทร: <?= htmlspecialchars((string)$clinic['phone']) ?></div><?php endif; ?>
            <?php if (!empty($clinic['tax_id'])): ?><div class="sub">เลขประจำตัวผู้เสียภาษี: <?= htmlspecialchars((string)$clinic['tax_id']) ?></div><?php endif; ?>
        </div>
        <div class="title-box">
            <div class="t"><?= htmlspecialchars($title) ?></div>
            <div class="copy">(ต้นฉบับ)</div>
        </div>
    </div>

    <div class="meta">
        <div><span class="lbl">เลขที่:</span> <span class="val"><?= htmlspecialchars($row['receipt_no']) ?></span></div>
        <div><span class="lbl">วันที่:</span> <span class="val"><?= date('d/m/', strtotime($row['txn_date'])) . (date('Y', strtotime($row['txn_date'])) + 543) ?></span></div>
        <div><span class="lbl">หมวด:</span> <span class="val"><?= htmlspecialchars($row['category_name'] ?? '-') ?></span></div>
        <div><span class="lbl">วิธีชำระ:</span> <span class="val"><?= htmlspecialchars($row['payment_method'] ?? '-') ?></span></div>
        <?php if (!empty($row['reference'])): ?>
        <div style="grid-column: 1/-1"><span class="lbl">อ้างอิง:</span> <span class="val"><?= htmlspecialchars($row['reference']) ?></span></div>
        <?php endif; ?>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width:50px;text-align:center">ลำดับ</th>
                <th>รายการ</th>
                <th style="width:160px;text-align:right">จำนวนเงิน (บาท)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="text-align:center">1</td>
                <td><?= htmlspecialchars($row['description'] ?: ($row['category_name'] ?? '-')) ?></td>
                <td class="amt"><?= number_format($amount, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="total"><span class="lbl">รวมทั้งสิ้น</span><span class="num"><?= number_format($amount, 2) ?> บาท</span></div>
    <div class="baht-text">(<?= htmlspecialchars($bahtText) ?>)</div>

    <?php if (!empty($row['note'])): ?>
    <div style="margin-top:16px;font-size:12px;color:#64748b;background:#fefce8;padding:8px 12px;border-radius:6px;border-left:3px solid #eab308">
        <strong>หมายเหตุ:</strong> <?= nl2br(htmlspecialchars($row['note'])) ?>
    </div>
    <?php endif; ?>

    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-label">ผู้รับเงิน / ผู้จ่ายเงิน</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-label">ผู้อนุมัติ</div>
        </div>
    </div>

    <div class="footer">
        ออกโดยระบบ RSU Medical Clinic — Cash Book · <?= date('d/m/Y H:i') ?>
    </div>
</div>
</body>
</html>
