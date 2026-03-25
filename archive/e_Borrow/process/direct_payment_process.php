<?php
// process/direct_payment_process.php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');
require_once('../includes/line_config.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. เธฃเธฑเธเธเนเธญเธกเธนเธฅ
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $amount_paid = isset($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $staff_id = $_SESSION['user_id'];
    
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
    $payment_slip_url = null;
    $receipt_number = null; 

    if ($transaction_id == 0 || $student_id == 0 || $amount <= 0 || $amount_paid <= 0) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธเธฃเธเธ–เนเธงเธ';
        echo json_encode($response);
        exit;
    }

   try {
        $pdo->beginTransaction();

        // 2. เธเธฑเธ”เธเธฒเธฃเธญเธฑเธเนเธซเธฅเธ”เนเธเธฅเน
        if ($payment_method == 'bank_transfer') {
            if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] == 0) {
                $upload_dir_server = '../uploads/slips/';
                $upload_dir_db = 'uploads/slips/';
                
                if (!is_dir($upload_dir_server)) mkdir($upload_dir_server, 0755, true);
                
                $file_extension = pathinfo($_FILES['payment_slip']['name'], PATHINFO_EXTENSION);
                $new_filename = 'slip-' . $transaction_id . '-' . uniqid() . '.' . strtolower($file_extension);
                
                $target_file_server = $upload_dir_server . $new_filename;
                $target_file_db = $upload_dir_db . $new_filename;

                if (move_uploaded_file($_FILES['payment_slip']['tmp_name'], $target_file_server)) {
                    $payment_slip_url = $target_file_db;
                } else {
                    throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธขเนเธฒเธขเนเธเธฅเนเธชเธฅเธดเธเนเธ”เน");
                }
            } else {
                throw new Exception("เธเธฃเธธเธ“เธฒเนเธเธเธชเธฅเธดเธเธเธฒเธฃเนเธญเธ");
            }
        }

        // 3. เธชเธฃเนเธฒเธเธฃเธฒเธขเธเธฒเธฃเธเนเธฒเธเธฃเธฑเธ
        $sql_fine = "INSERT INTO borrow_fines (transaction_id, student_id, amount, notes, created_by_staff_id, status) VALUES (?, ?, ?, ?, ?, 'paid')"; 
        $stmt_fine = $pdo->prepare($sql_fine);
        $stmt_fine->execute([$transaction_id, $student_id, $amount, $notes, $staff_id]);
        $new_fine_id = $pdo->lastInsertId();

        // 4. เธชเธฃเนเธฒเธเธฃเธฒเธขเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธ
        $sql_pay = "INSERT INTO borrow_payments (fine_id, amount_paid, payment_method, payment_slip_url, received_by_staff_id, receipt_number) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_pay = $pdo->prepare($sql_pay);
        $stmt_pay->execute([$new_fine_id, $amount_paid, $payment_method, $payment_slip_url, $staff_id, $receipt_number]);
        $new_payment_id = $pdo->lastInsertId();

        // 5. เธญเธฑเธเน€เธ”เธ• Transaction
        $sql_trans = "UPDATE borrow_records SET fine_status = 'paid' WHERE id = ?";
        $stmt_trans = $pdo->prepare($sql_trans);
        $stmt_trans->execute([$transaction_id]);

        // 6. เธเธฑเธเธ—เธถเธ Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' เธฃเธฑเธเธเธณเธฃเธฐเน€เธเธดเธ (Direct, {$payment_method}) เธขเธญเธ” {$amount_paid} เธเธฒเธ— (TID: {$transaction_id})";
        log_action($pdo, $staff_id, 'direct_payment', $log_desc);

        // 7. เธชเนเธเนเธเน€เธชเธฃเนเธเธ—เธฒเธ LINE
        sendLineReceipt($pdo, $transaction_id, $student_id, $new_payment_id, $amount_paid, $payment_method);

        $pdo->commit();

        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธเน€เธฃเธตเธขเธเธฃเนเธญเธข';
        $response['new_payment_id'] = $new_payment_id;

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'Method Not Allowed';
}

echo json_encode($response);
exit;

// Helper Function
function sendLineReceipt($pdo, $transaction_id, $student_id, $payment_id, $amount, $method) {
    $sql = "SELECT s.line_user_id, s.full_name, ei.name as item_name 
            FROM sys_users s
            JOIN borrow_records t ON t.borrower_student_id = s.id
            JOIN borrow_items ei ON t.item_id = ei.id
            WHERE s.id = ? AND t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id, $transaction_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data && !empty($data['line_user_id'])) {
        $line_user_id = $data['line_user_id'];
        $item_name = $data['item_name'];
        $date_now = date('d/m/Y H:i');
        $method_text = ($method == 'bank_transfer') ? 'เนเธญเธเน€เธเธดเธ' : 'เน€เธเธดเธเธชเธ”';

        $flexData = [
            "type" => "bubble", "size" => "giga",
            "body" => [
                "type" => "box", "layout" => "vertical",
                "contents" => [
                    ["type" => "text", "text" => "RECEIPT", "weight" => "bold", "color" => "#1DB446", "size" => "sm"],
                    ["type" => "text", "text" => "เนเธเน€เธชเธฃเนเธเธฃเธฑเธเน€เธเธดเธ", "weight" => "bold", "size" => "xl", "margin" => "md"],
                    ["type" => "separator", "margin" => "xxl"],
                    [
                        "type" => "box", "layout" => "vertical", "margin" => "xxl", "spacing" => "sm",
                        "contents" => [
                            ["type" => "box", "layout" => "horizontal", "contents" => [["type" => "text", "text" => "เน€เธฅเธเธ—เธตเนเธฃเธฒเธขเธเธฒเธฃ", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => "#PAY-" . $payment_id, "size" => "sm", "color" => "#111111", "align" => "end"]]],
                            ["type" => "box", "layout" => "horizontal", "contents" => [["type" => "text", "text" => "เธงเธฑเธเธ—เธตเนเธเธณเธฃเธฐ", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => $date_now, "size" => "sm", "color" => "#111111", "align" => "end"]]],
                            ["type" => "box", "layout" => "horizontal", "contents" => [["type" => "text", "text" => "เธญเธธเธเธเธฃเธ“เน", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => $item_name, "size" => "sm", "color" => "#111111", "align" => "end", "wrap" => true, "flex" => 2]]],
                            ["type" => "separator", "margin" => "xxl"],
                            ["type" => "box", "layout" => "horizontal", "margin" => "xxl", "contents" => [["type" => "text", "text" => "เธขเธญเธ”เธฃเธงเธกเธชเธธเธ—เธเธด", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => number_format($amount, 2) . " เธฟ", "size" => "xl", "color" => "#111111", "align" => "end", "weight" => "bold"]]]
                        ]
                    ]
                ]
            ]
        ];

        $payload = ['to' => $line_user_id, 'messages' => [['type' => 'flex', 'altText' => 'เนเธเน€เธชเธฃเนเธเธฃเธฑเธเน€เธเธดเธเธเนเธฒเธเธฃเธฑเธ', 'contents' => $flexData]]];
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . LINE_MESSAGING_API_TOKEN]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}
?>
