<?php
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../includes/db_connect.php');
$pdo = db();

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

// 2. รับ Payment ID
$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
if ($payment_id == 0) {
    die("ไม่ได้ระบุเลขที่การชำระเงิน");
}

// 3. [แก้ไข] ดึงข้อมูลทั้งหมดที่เกี่ยวข้อง (เพิ่ม payment_method, payment_slip_url)
try {
    $sql = "SELECT 
                p.id as payment_id, p.amount_paid, p.payment_date, p.receipt_number,
                p.payment_method, p.payment_slip_url,
                COALESCE(f.amount, p.amount_paid) as fine_amount, 
                COALESCE(f.notes, '') as fine_notes,
                t.due_date,
                COALESCE(bc.name, ei.name, 'ไม่ระบุชื่ออุปกรณ์') as equipment_name,
                COALESCE(s.full_name, s2.full_name, 'ไม่ระบุชื่อผู้ยืม') as student_name, 
                COALESCE(s.student_personnel_id, s2.student_personnel_id, '-') as student_personnel_id,
                COALESCE(u_staff.full_name, u_admins.full_name, 'เจ้าหน้าที่') as staff_name
            FROM borrow_payments p
            LEFT JOIN borrow_fines f ON p.fine_id = f.id
            LEFT JOIN borrow_records t ON f.transaction_id = t.id
            LEFT JOIN borrow_categories bc ON t.type_id = bc.id
            LEFT JOIN borrow_items ei ON t.equipment_id = ei.id
            LEFT JOIN sys_users s ON f.student_id = s.id
            LEFT JOIN sys_users s2 ON t.borrower_student_id = s2.id
            LEFT JOIN sys_staff u_staff ON p.received_by_staff_id = u_staff.id
            LEFT JOIN sys_admins u_admins ON p.received_by_staff_id = u_admins.id
            WHERE p.id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$payment_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        die("ไม่พบข้อมูลการชำระเงินนี้ (ID: $payment_id)");
    }
} catch (PDOException $e) {
    error_log("print_receipt error: " . $e->getMessage()); 
    exit("เกิดข้อผิดพลาดในการดึงข้อมูล");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo explode('/e_Borrow', $_SERVER['SCRIPT_NAME'])[0] . '/e_Borrow/'; ?>">
    
    <title>ใบเสร็จรับเงินค่าปรับ (Payment ID: <?php echo $data['payment_id']; ?>)</title>
    
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        body {
            background-color: #f4f4f4;
            font-family: 'RSU', Arial, sans-serif;
            color: #333;
        }
        .receipt-container {
            width: 800px;
            max-width: 95%;
            margin: 20px auto;
            padding: 30px;
            background: #fff;
            box-shadow: var(--box-shadow-main);
            border-radius: var(--border-radius-main);
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .receipt-header img {
            max-width: 100px;
            margin-bottom: 10px;
        }
        .receipt-header h1 {
            margin: 0;
            color: var(--color-primary, #0052CC);
        }
        .receipt-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .receipt-details div { line-height: 1.7; }
        .receipt-details strong { min-width: 120px; display: inline-block; }
        
        .receipt-items table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-items th, .receipt-items td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .receipt-items th { background-color: #f9f9f9; }
        .receipt-total {
            margin-top: 20px;
            text-align: right;
            font-size: 1.5em;
            font-weight: bold;
            color: var(--color-primary, #0052CC);
        }
        .receipt-footer {
            margin-top: 40px;
            text-align: center;
            color: #888;
            font-size: 0.9em;
        }
        /* (เพิ่ม สไตล์สำหรับสลิป) */
        .payment-slip {
            margin-top: 20px;
            border-top: 1px dashed #ccc;
            padding-top: 20px;
        }
        .payment-slip img {
            max-width: 200px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .print-button {
            display: block;
            width: 200px;
            margin: 20px auto;
            padding: 12px;
            text-align: center;
            background: var(--color-primary);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 1.1em;
            cursor: pointer;
        }
        @media print {
            body { background-color: #fff; }
            .receipt-container { 
                margin: 0; 
                padding: 0; 
                box-shadow: none; 
                border: none;
                width: 100%;
                max-width: 100%;
            }
            .print-button { display: none; }
            .payment-slip img { max-width: 250px; }
        }
    </style>
</head>
<body>

    <a href="javascript:window.print()" class="print-button">
        <i class="fas fa-print"></i> พิมพ์ใบเสร็จ
    </a>

    <div class="receipt-container" id="receiptContent">
        <div class="receipt-header">
            <img src="assets/img/logo.png" alt="Logo">
            <h1>ใบเสร็จรับเงิน (ค่าปรับ)</h1>
            <p>ระบบยืมคืนอุปกรณ์ มหาวิทยาลัยรังสิต</p>
        </div>

        <div class="receipt-details">
            <div>
                <strong>ชำระโดย:</strong> <?php echo htmlspecialchars($data['student_name']); ?><br>
                <strong>รหัสประจำตัว:</strong> <?php echo htmlspecialchars($data['student_personnel_id'] ?? '-'); ?><br>
                <strong>เลขที่อ้างอิง:</strong> <?php echo $data['payment_id']; ?>
            </div>
            <div>
                <strong>วันที่ชำระ:</strong> <?php echo date('d/m/Y H:i', strtotime($data['payment_date'])); ?><br>
                <strong>รับชำระโดย:</strong> <?php echo htmlspecialchars($data['staff_name']); ?><br>
                
                <strong>วิธีชำระเงิน:</strong> 
                <?php 
                    if ($data['payment_method'] == 'bank_transfer') {
                        echo 'บัญชีธนาคาร';
                    } else {
                        echo 'เงินสด';
                    }
                ?>
                </div>
        </div>

        <div class="receipt-items">
            <table>
                <thead>
                    <tr>
                        <th>รายการ</th>
                        <th>รายละเอียด</th>
                        <th style="text-align: right;">จำนวนเงิน</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>ค่าปรับยืมอุปกรณ์เกินกำหนด</td>
                        <td>
                            อุปกรณ์: <?php echo htmlspecialchars($data['equipment_name']); ?><br>
                            (กำหนดคืน: <?php echo date('d/m/Y', strtotime($data['due_date'])); ?>)
                        </td>
                        <td style="text-align: right;"><?php echo number_format($data['amount_paid'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="receipt-total">
            ยอดชำระทั้งสิ้น: <?php echo number_format($data['amount_paid'], 2); ?> บาท
        </div>

        <?php if ($data['payment_method'] == 'bank_transfer' && !empty($data['payment_slip_url'])): ?>
            <div class="payment-slip">
                <strong>หลักฐานการชำระเงิน (สลิป):</strong><br>
                
                <a href="<?php echo htmlspecialchars($data['payment_slip_url']); ?>" target="_blank">
                    <img src="<?php echo htmlspecialchars($data['payment_slip_url']); ?>" alt="Payment Slip">
                </a>
            </div>
        <?php endif; ?>
        <div class="receipt-footer">
            ขอขอบคุณที่ใช้บริการ
        </div>
    </div>

</body>
</html>
