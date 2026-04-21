<?php
// process/record_payment_process.php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');
require_once('../includes/line_config.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $fine_id = isset($_POST['fine_id']) ? (int)$_POST['fine_id'] : 0;
    $amount_paid = isset($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0;
    $staff_id = $_SESSION['user_id'];
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
    $payment_slip_url = null;
    $receipt_number = null; 

    if ($fine_id == 0 || $amount_paid <= 0) {
        $response['message'] = 'ข้อมูลไม่ครบถ้วน';
        echo json_encode($response);
        exit;
    }

  try {
        $pdo->beginTransaction();

        // 1. จัดการอัปโหลดไฟล์
        if ($payment_method == 'bank_transfer') {
            if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] == 0) {
                $upload_dir_server = '../uploads/slips/';
                $upload_dir_db = 'uploads/slips/';
                if (!is_dir($upload_dir_server)) mkdir($upload_dir_server, 0755, true);
                
                $file_extension = pathinfo($_FILES['payment_slip']['name'], PATHINFO_EXTENSION);
                $new_filename = 'slip-fine-' . $fine_id . '-' . uniqid() . '.' . strtolower($file_extension);

                $target_file_server = $upload_dir_server . $new_filename;
                $target_file_db = $upload_dir_db . $new_filename;

                if (move_uploaded_file($_FILES['payment_slip']['tmp_name'], $target_file_server)) {
                    $payment_slip_url = $target_file_db;
                } else {
                    throw new Exception("ย้ายไฟล์สลิปไม่สำเร็จ");
                }
            } else {
                throw new Exception("กรุณาแนบสลิปการโอน");
            }
        }

        // 2. ดึง transaction_id
        $stmt_get_fine = $pdo->prepare("SELECT transaction_id, student_id FROM borrow_fines WHERE id = ?");
        $stmt_get_fine->execute([$fine_id]);
        $fine_data = $stmt_get_fine->fetch(PDO::FETCH_ASSOC);

        if (!$fine_data) {
            throw new Exception("ไม่พบรายการค่าปรับ");
        }
        $transaction_id = $fine_data['transaction_id'];
        $student_id = $fine_data['student_id'];

        // 3. สร้าง Payment
        $sql_pay = "INSERT INTO borrow_payments (fine_id, amount_paid, payment_method, payment_slip_url, received_by_staff_id, receipt_number) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_pay = $pdo->prepare($sql_pay);
        $stmt_pay->execute([$fine_id, $amount_paid, $payment_method, $payment_slip_url, $staff_id, $receipt_number]);
        $new_payment_id = $pdo->lastInsertId();

        // 4. อัปเดตสถานะ
        $sql_fine = "UPDATE borrow_fines SET status = 'paid' WHERE id = ?";
        $stmt_fine = $pdo->prepare($sql_fine);
        $stmt_fine->execute([$fine_id]);

        $sql_trans = "UPDATE borrow_records SET fine_status = 'paid' WHERE id = ?";
        $stmt_trans = $pdo->prepare($sql_trans);
        $stmt_trans->execute([$transaction_id]);

        if ($stmt_pay->rowCount() > 0) {
            
            // 5. บันทึก Log
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' รับชำระค่าปรับ (Pending, {$payment_method}) ยอด {$amount_paid} บาท (FineID: {$fine_id})";
            log_action($pdo, $staff_id, 'record_payment', $log_desc);

            // 6. ส่งใบเสร็จทาง LINE
            sendLineReceipt($pdo, $transaction_id, $student_id, $new_payment_id, $amount_paid, $payment_method);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'บันทึกการชำระเงินสำเร็จ';
            $response['new_payment_id'] = $new_payment_id;
        } else {
            throw new Exception("ไม่สามารถบันทึกการชำระเงินได้");
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'Method Not Allowed';
}

echo json_encode($response);
exit;

// Helper Function (Same as above)
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
        $method_text = ($method == 'bank_transfer') ? 'โอนเงิน' : 'เงินสด';

        $flexData = [
            "type" => "bubble", "size" => "giga",
            "body" => [
                "type" => "box", "layout" => "vertical",
                "contents" => [
                    ["type" => "text", "text" => "RECEIPT", "weight" => "bold", "color" => "#1DB446", "size" => "sm"],
                    ["type" => "text", "text" => "ใบเสร็จรับเงิน", "weight" => "bold", "size" => "xl", "margin" => "md"],
                    ["type" => "separator", "margin" => "xxl"],
                    [
                        "type" => "box", "layout" => "vertical", "margin" => "xxl", "spacing" => "sm",
                        "contents" => [
                            ["type" => "box", "layout" => "horizontal", "contents" => [["type" => "text", "text" => "เลขที่รายการ", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => "#PAY-" . $payment_id, "size" => "sm", "color" => "#111111", "align" => "end"]]],
                            ["type" => "box", "layout" => "horizontal", "contents" => [["type" => "text", "text" => "วันที่", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => $date_now, "size" => "sm", "color" => "#111111", "align" => "end"]]],
                            ["type" => "box", "layout" => "horizontal", "contents" => [["type" => "text", "text" => "อุปกรณ์", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => $item_name, "size" => "sm", "color" => "#111111", "align" => "end", "wrap" => true, "flex" => 2]]],
                            ["type" => "separator", "margin" => "xxl"],
                            ["type" => "box", "layout" => "horizontal", "margin" => "xxl", "contents" => [["type" => "text", "text" => "ยอดรวมสุทธิ", "size" => "sm", "color" => "#555555"], ["type" => "text", "text" => number_format($amount, 2) . " ฿", "size" => "xl", "color" => "#111111", "align" => "end", "weight" => "bold"]]]
                        ]
                    ]
                ]
            ]
        ];

        $payload = ['to' => $line_user_id, 'messages' => [['type' => 'flex', 'altText' => 'ใบเสร็จรับเงินค่าปรับ', 'contents' => $flexData]]];
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . LINE_MESSAGING_API_TOKEN]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    } else {
        error_log("[e_Borrow] LINE receipt not sent: no line_user_id for student_id={$student_id}, transaction_id={$transaction_id}");
    }
}
?>
