<?php
/**
 * portal/payroll_payslip.php
 * Standalone printable payslip — opens in new tab, prints via browser
 *
 * URL: payroll_payslip.php?entry_id=N
 * Auth: same as Payroll module (access_finance / access_payroll / admin / superadmin)
 */
declare(strict_types=1);

require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/payroll_helper.php';

$canPayroll = $isSuper || $adminRole === 'admin'
            || !empty($_SESSION['access_finance'])
            || !empty($_SESSION['access_payroll']);
if (!$canPayroll) {
    http_response_code(403);
    exit('Access Denied — ต้องมีสิทธิ์ access_finance หรือ access_payroll');
}

$entryId = (int)($_GET['entry_id'] ?? 0);
if ($entryId <= 0) { http_response_code(400); exit('entry_id ไม่ถูกต้อง'); }

$pdo = db();
pr_ensure_schema($pdo);

$st = $pdo->prepare("
    SELECT e.*, p.period_ym, p.status AS period_status, p.pay_date, p.paid_at,
           emp.bank_name, emp.bank_account, emp.tax_id, emp.sso_no,
           emp.is_in_sso, emp.is_in_pf
    FROM sys_payroll_entries e
    JOIN sys_payroll_periods p ON p.id = e.period_id
    LEFT JOIN sys_payroll_employees emp ON emp.id = e.employee_id
    WHERE e.id = :id
");
$st->execute([':id' => $entryId]);
$entry = $st->fetch(PDO::FETCH_ASSOC);
if (!$entry) { http_response_code(404); exit('ไม่พบรายการ'); }

function thMonth(string $ym): string {
    $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    [$y, $m] = explode('-', $ym);
    return $months[(int)$m] . ' ' . ((int)$y + 543);
}
function thDate(?string $d): string {
    if (!$d) return '—';
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    [$y, $m, $dd] = explode('-', substr($d, 0, 10));
    return (int)$dd . ' ' . $months[(int)$m] . ' ' . ((int)$y + 543);
}
function thBaht($n): string {
    return number_format((float)$n, 2, '.', ',');
}
$siteName = defined('SITE_NAME') ? SITE_NAME : 'RSU Medical Clinic';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Payslip · <?= htmlspecialchars($entry['full_name']) ?> · <?= thMonth($entry['period_ym']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { font-family: 'Sarabun', sans-serif; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { background: #e2e8f0; margin: 0; padding: 24px; }
    @page { size: A4; margin: 14mm; }

    .payslip {
        width: 180mm; max-width: 100%; margin: 0 auto;
        background: #fff; border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,.15);
        padding: 18mm;
        position: relative;
        overflow: hidden;
    }
    .payslip::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 6mm;
        background: linear-gradient(90deg, #2e9e63 0%, #3bba7a 50%, #2e9e63 100%);
    }
    .ps-head { display: flex; justify-content: space-between; align-items: start; margin-top: 6mm; margin-bottom: 8mm; padding-bottom: 4mm; border-bottom: 1.5px dashed #cbd5e1; }
    .ps-brand { display: flex; align-items: center; gap: 12px; }
    .ps-logo { width: 14mm; height: 14mm; border-radius: 10px; background: linear-gradient(135deg,#2e9e63,#3bba7a); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    .ps-brand-text h1 { margin: 0; font-size: 16px; font-weight: 800; color: #0f172a; }
    .ps-brand-text .sub { font-size: 11px; color: #64748b; margin-top: 1px; }
    .ps-badge { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 6px 14px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .04em; }

    .ps-title { text-align: center; margin: 4mm 0 8mm; }
    .ps-title h2 { margin: 0; font-size: 22px; font-weight: 900; color: #0f172a; }
    .ps-title .period { font-size: 13px; color: #475569; margin-top: 2px; font-weight: 600; }

    .ps-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm 10mm; font-size: 12px; margin-bottom: 8mm; }
    .ps-meta .label { font-size: 9.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
    .ps-meta .val { font-weight: 700; color: #0f172a; margin-top: 1px; }

    .ps-twocol { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-bottom: 8mm; }
    .ps-section { border-radius: 10px; padding: 4mm 5mm; }
    .ps-income { background: #f0fdf4; border: 1.5px solid #bbf7d0; }
    .ps-deduct { background: #fef2f2; border: 1.5px solid #fecaca; }
    .ps-section h3 { margin: 0 0 3mm; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
    .ps-income h3 { color: #15803d; }
    .ps-deduct h3 { color: #b91c1c; }
    .ps-line { display: flex; justify-content: space-between; padding: 2mm 0; border-bottom: 1px solid rgba(0,0,0,.05); font-size: 12px; }
    .ps-line:last-child { border-bottom: none; }
    .ps-line .name { color: #475569; }
    .ps-line .amt { font-weight: 700; font-variant-numeric: tabular-nums; color: #0f172a; }
    .ps-section .total { display: flex; justify-content: space-between; padding-top: 3mm; margin-top: 2mm; border-top: 2px solid currentColor; font-weight: 900; font-size: 13px; }
    .ps-income .total { color: #15803d; }
    .ps-deduct .total { color: #b91c1c; }

    .ps-net-box {
        background: linear-gradient(135deg, #2e9e63 0%, #3bba7a 100%);
        color: #fff; border-radius: 12px;
        padding: 5mm 6mm;
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 6mm;
    }
    .ps-net-box .label { font-size: 13px; font-weight: 800; letter-spacing: .03em; }
    .ps-net-box .amt { font-size: 24px; font-weight: 900; font-variant-numeric: tabular-nums; }
    .ps-net-box .amt-sub { font-size: 11px; opacity: .85; font-weight: 600; }

    .ps-foot { display: flex; justify-content: space-between; font-size: 10px; color: #64748b; padding-top: 4mm; border-top: 1.5px dashed #cbd5e1; }
    .ps-foot strong { color: #475569; }

    .toolbar { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 50; }
    .toolbar button, .toolbar a {
        padding: 9px 16px; border-radius: 10px;
        background: #fff; border: 1.5px solid #e2e8f0;
        font-size: 13px; font-weight: 700; color: #334155;
        cursor: pointer; text-decoration: none;
        display: inline-flex; align-items: center; gap: 6px;
        box-shadow: 0 2px 6px rgba(0,0,0,.08);
    }
    .toolbar .btn-primary {
        background: linear-gradient(135deg,#2e9e63,#3bba7a); color: #fff; border-color: #2e9e63;
    }

    @media print {
        body { background: #fff; padding: 0; }
        .payslip { box-shadow: none; margin: 0; padding: 14mm; width: 100%; border-radius: 0; }
        .toolbar { display: none !important; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="payroll_periods.php"><i class="fa-solid fa-arrow-left"></i> กลับ</a>
    <button class="btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> พิมพ์ / บันทึก PDF</button>
</div>

<div class="payslip">
    <div class="ps-head">
        <div class="ps-brand">
            <div class="ps-logo"><i class="fa-solid fa-hospital"></i></div>
            <div class="ps-brand-text">
                <h1><?= htmlspecialchars($siteName) ?></h1>
                <div class="sub">ใบรับเงินเดือน · Payslip</div>
            </div>
        </div>
        <div class="ps-badge">
            <i class="fa-solid fa-circle-check mr-1"></i>
            <?= $entry['period_status'] === 'paid' ? 'จ่ายแล้ว' : strtoupper($entry['period_status']) ?>
        </div>
    </div>

    <div class="ps-title">
        <h2><?= htmlspecialchars($entry['full_name']) ?></h2>
        <div class="period">
            <?= $entry['position_title'] ? htmlspecialchars($entry['position_title']) . ' · ' : '' ?>
            งวดประจำเดือน <b><?= thMonth($entry['period_ym']) ?></b>
        </div>
    </div>

    <div class="ps-meta">
        <div>
            <div class="label">รหัสพนักงาน</div>
            <div class="val"><?= htmlspecialchars($entry['employee_no'] ?? '—') ?></div>
        </div>
        <div>
            <div class="label">วันที่จ่าย</div>
            <div class="val"><?= thDate($entry['pay_date'] ?? null) ?></div>
        </div>
        <div>
            <div class="label">เลขประจำตัวผู้เสียภาษี</div>
            <div class="val"><?= htmlspecialchars($entry['tax_id'] ?? '—') ?></div>
        </div>
        <div>
            <div class="label">เลขประกันสังคม</div>
            <div class="val"><?= htmlspecialchars($entry['sso_no'] ?? '—') ?></div>
        </div>
        <?php if (!empty($entry['bank_name']) || !empty($entry['bank_account'])): ?>
        <div style="grid-column: 1 / -1">
            <div class="label">โอนเข้าบัญชี</div>
            <div class="val">
                <?= htmlspecialchars($entry['bank_name'] ?? '—') ?>
                · <?= htmlspecialchars($entry['bank_account'] ?? '—') ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="ps-twocol">
        <div class="ps-section ps-income">
            <h3><i class="fa-solid fa-arrow-trend-up"></i> รายได้</h3>
            <div class="ps-line"><span class="name">เงินเดือนพื้นฐาน</span><span class="amt"><?= thBaht($entry['base_salary']) ?></span></div>
            <?php if ((float)$entry['allowance'] > 0): ?>
            <div class="ps-line"><span class="name">ค่าครองชีพ/ตำแหน่ง</span><span class="amt"><?= thBaht($entry['allowance']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['ot_amount'] > 0): ?>
            <div class="ps-line"><span class="name">OT (<?= rtrim(rtrim($entry['ot_hours'], '0'), '.') ?> ชม.)</span><span class="amt"><?= thBaht($entry['ot_amount']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['bonus'] > 0): ?>
            <div class="ps-line"><span class="name">โบนัส</span><span class="amt"><?= thBaht($entry['bonus']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['other_income'] > 0): ?>
            <div class="ps-line"><span class="name">รายได้อื่นๆ</span><span class="amt"><?= thBaht($entry['other_income']) ?></span></div>
            <?php endif; ?>
            <div class="total"><span>รวมรายได้</span><span><?= thBaht($entry['gross_total']) ?></span></div>
        </div>

        <div class="ps-section ps-deduct">
            <h3><i class="fa-solid fa-arrow-trend-down"></i> รายการหัก</h3>
            <?php if ((float)$entry['tax_amount'] > 0): ?>
            <div class="ps-line"><span class="name">ภาษีหัก ณ ที่จ่าย (ภงด.1)</span><span class="amt"><?= thBaht($entry['tax_amount']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['sso_employee'] > 0): ?>
            <div class="ps-line"><span class="name">ประกันสังคม 5%</span><span class="amt"><?= thBaht($entry['sso_employee']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['pf_employee'] > 0): ?>
            <div class="ps-line"><span class="name">Provident Fund</span><span class="amt"><?= thBaht($entry['pf_employee']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['other_deductions'] > 0): ?>
            <div class="ps-line"><span class="name">หักอื่นๆ</span><span class="amt"><?= thBaht($entry['other_deductions']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$entry['total_deductions'] === 0.0): ?>
            <div class="ps-line" style="color:#94a3b8;font-style:italic"><span class="name">ไม่มีรายการหัก</span><span class="amt">—</span></div>
            <?php endif; ?>
            <div class="total"><span>รวมหัก</span><span><?= thBaht($entry['total_deductions']) ?></span></div>
        </div>
    </div>

    <div class="ps-net-box">
        <div>
            <div class="label">ยอดเงินสุทธิที่ได้รับ</div>
            <div class="amt-sub">Net pay this period</div>
        </div>
        <div class="amt"><?= thBaht($entry['net_amount']) ?> ฿</div>
    </div>

    <?php if ((float)$entry['sso_employer'] > 0): ?>
    <div style="font-size:10.5px;color:#64748b;margin-bottom:4mm">
        <i class="fa-solid fa-circle-info"></i>
        ข้อมูลเพิ่ม (นายจ้างสมทบ ไม่นับในยอดสุทธิ):
        ประกันสังคมฝั่งนายจ้าง <?= thBaht($entry['sso_employer']) ?> ฿
        <?php if ((float)$entry['pf_employer'] > 0): ?>· PF ฝั่งนายจ้าง <?= thBaht($entry['pf_employer']) ?> ฿<?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($entry['notes'])): ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:3mm 4mm;font-size:11px;color:#475569;margin-bottom:4mm">
        <strong>หมายเหตุ:</strong> <?= htmlspecialchars($entry['notes']) ?>
    </div>
    <?php endif; ?>

    <div class="ps-foot">
        <div>
            <strong>คลินิก:</strong> <?= htmlspecialchars($siteName) ?>
        </div>
        <div>
            ออกใบ ณ <?= thDate(date('Y-m-d')) ?>
            · <?= htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin') ?>
        </div>
    </div>
</div>

</body>
</html>
