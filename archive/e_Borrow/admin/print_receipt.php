<?php
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเน€เธเนเธฒเธ–เธถเธเธซเธเนเธฒเธเธตเน");
}

// 2. เธฃเธฑเธ Payment ID
$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
if ($payment_id == 0) {
    die("เนเธกเนเนเธ”เนเธฃเธฐเธเธธเน€เธฅเธเธ—เธตเนเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธ");
}

// 3. [เนเธเนเนเธ] เธ”เธถเธเธเนเธญเธกเธนเธฅเธ—เธฑเนเธเธซเธกเธ”เธ—เธตเนเน€เธเธตเนเธขเธงเธเนเธญเธ (เน€เธเธดเนเธก payment_method, payment_slip_url)
try {
    $sql = "SELECT 
                p.id as payment_id, p.amount_paid, p.payment_date, p.receipt_number,
                p.payment_method, p.payment_slip_url,
                f.amount as fine_amount, f.notes as fine_notes,
                t.due_date,
                ei.name as equipment_name,
                s.full_name as student_name, s.student_personnel_id,
                u_staff.full_name as staff_name
            FROM borrow_payments p
            JOIN borrow_fines f ON p.fine_id = f.id
            JOIN borrow_records t ON f.transaction_id = t.id
            JOIN borrow_items ei ON t.equipment_id = ei.id
            JOIN sys_users s ON f.student_id = s.id
            JOIN sys_staff u_staff ON p.received_by_staff_id = u_staff.id
            WHERE p.id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$payment_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        die("เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธเธเธตเน");
    }
} catch (PDOException $e) {
    die("เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo explode('/e_Borrow', $_SERVER['SCRIPT_NAME'])[0] . '/e_Borrow/'; ?>">
    
    <title>เนเธเน€เธชเธฃเนเธเธฃเธฑเธเน€เธเธดเธเธเนเธฒเธเธฃเธฑเธ (Payment ID: <?php echo $data['payment_id']; ?>)</title>
    
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
            color: var(--color-primary, #0B6623);
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
            color: var(--color-primary, #0B6623);
        }
        .receipt-footer {
            margin-top: 40px;
            text-align: center;
            color: #888;
            font-size: 0.9em;
        }
        /* (เน€เธเธดเนเธก เธชเนเธ•เธฅเนเธชเธณเธซเธฃเธฑเธเธชเธฅเธดเธ) */
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
        <i class="fas fa-print"></i> เธเธดเธกเธเนเนเธเน€เธชเธฃเนเธ
    </a>

    <div class="receipt-container" id="receiptContent">
        <div class="receipt-header">
            <img src="assets/img/logo.png" alt="Logo">
            <h1>เนเธเน€เธชเธฃเนเธเธฃเธฑเธเน€เธเธดเธ (เธเนเธฒเธเธฃเธฑเธ)</h1>
            <p>เธฃเธฐเธเธเธขเธทเธกเธเธทเธเธญเธธเธเธเธฃเธ“เน เธกเธซเธฒเธงเธดเธ—เธขเธฒเธฅเธฑเธขเธฃเธฑเธเธชเธดเธ•</p>
        </div>

        <div class="receipt-details">
            <div>
                <strong>เธเธณเธฃเธฐเนเธ”เธข:</strong> <?php echo htmlspecialchars($data['student_name']); ?><br>
                <strong>เธฃเธซเธฑเธชเธเธฃเธฐเธเธณเธ•เธฑเธง:</strong> <?php echo htmlspecialchars($data['student_personnel_id'] ?? '-'); ?><br>
                <strong>เน€เธฅเธเธ—เธตเนเธญเนเธฒเธเธญเธดเธ:</strong> <?php echo $data['payment_id']; ?>
            </div>
            <div>
                <strong>เธงเธฑเธเธ—เธตเนเธเธณเธฃเธฐ:</strong> <?php echo date('d/m/Y H:i', strtotime($data['payment_date'])); ?><br>
                <strong>เธฃเธฑเธเธเธณเธฃเธฐเนเธ”เธข:</strong> <?php echo htmlspecialchars($data['staff_name']); ?><br>
                
                <strong>เธงเธดเธเธตเธเธณเธฃเธฐเน€เธเธดเธ:</strong> 
                <?php 
                    if ($data['payment_method'] == 'bank_transfer') {
                        echo 'เธเธฑเธเธเธตเธเธเธฒเธเธฒเธฃ';
                    } else {
                        echo 'เน€เธเธดเธเธชเธ”';
                    }
                ?>
                </div>
        </div>

        <div class="receipt-items">
            <table>
                <thead>
                    <tr>
                        <th>เธฃเธฒเธขเธเธฒเธฃ</th>
                        <th>เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”</th>
                        <th style="text-align: right;">เธเธณเธเธงเธเน€เธเธดเธ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>เธเนเธฒเธเธฃเธฑเธเธขเธทเธกเธญเธธเธเธเธฃเธ“เนเน€เธเธดเธเธเธณเธซเธเธ”</td>
                        <td>
                            เธญเธธเธเธเธฃเธ“เน: <?php echo htmlspecialchars($data['equipment_name']); ?><br>
                            (เธเธณเธซเธเธ”เธเธทเธ: <?php echo date('d/m/Y', strtotime($data['due_date'])); ?>)
                        </td>
                        <td style="text-align: right;"><?php echo number_format($data['amount_paid'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="receipt-total">
            เธขเธญเธ”เธเธณเธฃเธฐเธ—เธฑเนเธเธชเธดเนเธ: <?php echo number_format($data['amount_paid'], 2); ?> เธเธฒเธ—
        </div>

        <?php if ($data['payment_method'] == 'bank_transfer' && !empty($data['payment_slip_url'])): ?>
            <div class="payment-slip">
                <strong>เธซเธฅเธฑเธเธเธฒเธเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธ (เธชเธฅเธดเธ):</strong><br>
                
                <a href="<?php echo htmlspecialchars($data['payment_slip_url']); ?>" target="_blank">
                    <img src="<?php echo htmlspecialchars($data['payment_slip_url']); ?>" alt="Payment Slip">
                </a>
            </div>
        <?php endif; ?>
        <div class="receipt-footer">
            เธเธญเธเธญเธเธเธธเธ“เธ—เธตเนเนเธเนเธเธฃเธดเธเธฒเธฃ
        </div>
    </div>

</body>
</html>
