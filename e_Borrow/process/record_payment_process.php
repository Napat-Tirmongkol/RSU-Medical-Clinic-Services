<?php
// process/record_payment_process.php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');
require_once('../includes/line_config.php');
require_once(__DIR__ . '/../../includes/finance_sync_helper.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $fine_id     = isset($_POST['fine_id'])     ? (int)$_POST['fine_id']     : 0;
    $amount_paid = isset($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0;
    $staff_id    = (int)($_SESSION['user_id'] ?? 0);

    // Whitelist payment_method.
    $payment_method = (isset($_POST['payment_method']) && in_array($_POST['payment_method'], ['cash', 'bank_transfer'], true))
        ? $_POST['payment_method']
        : 'cash';

    $payment_slip_url = null;
    $receipt_number = null;

    if ($fine_id <= 0 || $amount_paid <= 0 || $staff_id <= 0) {
        $response['message'] = 'ข้อมูลไม่ครบถ้วน';
        echo json_encode($response);
        exit;
    }

  try {
        $pdo->beginTransaction();

        // 1. ดึงค่าปรับจริงจาก DB (server-truth) — ห้ามเชื่อยอดจาก client
        $stmt_get_fine = $pdo->prepare("SELECT transaction_id, student_id, amount, status FROM borrow_fines WHERE id = ? FOR UPDATE");
        $stmt_get_fine->execute([$fine_id]);
        $fine_data = $stmt_get_fine->fetch(PDO::FETCH_ASSOC);

        if (!$fine_data) {
            throw new Exception("ไม่พบรายการค่าปรับ");
        }
        if (($fine_data['status'] ?? '') === 'paid') {
            throw new Exception("รายการค่าปรับนี้ชำระเงินไปแล้ว");
        }
        $transaction_id = $fine_data['transaction_id'];
        $student_id     = $fine_data['student_id'];
        $fine_amount    = (float)$fine_data['amount'];

        if ($amount_paid + 0.01 < $fine_amount) {
            throw new Exception("ยอดชำระน้อยกว่าค่าปรับจริง (" . number_format($fine_amount, 2) . " บาท)");
        }

        // 2. จัดการอัปโหลดไฟล์สลิป — finfo MIME + random filename + size cap
        if ($payment_method == 'bank_transfer') {
            if (!isset($_FILES['payment_slip']) || $_FILES['payment_slip']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("กรุณาแนบสลิปการโอน");
            }
            $slip = $_FILES['payment_slip'];
            if ($slip['size'] > 8 * 1024 * 1024) {
                throw new Exception("ขนาดสลิปต้องไม่เกิน 8MB");
            }
            if (!is_uploaded_file($slip['tmp_name'])) {
                throw new Exception("ไฟล์อัปโหลดไม่ถูกต้อง");
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($slip['tmp_name']) ?: '';
            $mimeToExt = [
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/webp'      => 'webp',
                'application/pdf' => 'pdf',
            ];
            if (!isset($mimeToExt[$mime])) {
                throw new Exception("สลิปต้องเป็น JPG, PNG, WEBP หรือ PDF เท่านั้น");
            }

            $upload_dir_server = __DIR__ . '/../uploads/slips/';
            $upload_dir_db     = 'uploads/slips/';
            if (!is_dir($upload_dir_server)) mkdir($upload_dir_server, 0755, true);

            $new_filename = 'slip-fine-' . $fine_id . '-' . bin2hex(random_bytes(8)) . '.' . $mimeToExt[$mime];
            $target_file_server = $upload_dir_server . $new_filename;

            if (!move_uploaded_file($slip['tmp_name'], $target_file_server)) {
                throw new Exception("ย้ายไฟล์สลิปไม่สำเร็จ");
            }
            $payment_slip_url = $upload_dir_db . $new_filename;
        }

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

            // 7. Auto-sync เข้า Cash Book (รายรับ — ค่าปรับยืมเกินกำหนด)
            // idempotent ด้วย (source_module, source_id) — กดซ้ำไม่สร้างซ้ำ
            // ทำหลัง commit เพื่อกัน financial write ที่ fail ไม่ rollback การชำระจริง
            try {
                $infoStmt = $pdo->prepare("
                    SELECT s.full_name AS student_name, bc.name AS equipment_name
                    FROM borrow_records t
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    LEFT JOIN borrow_categories bc ON t.type_id = bc.id
                    WHERE t.id = ?
                ");
                $infoStmt->execute([$transaction_id]);
                $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $descText = 'ค่าปรับยืมเกินกำหนด: ' . ($info['student_name'] ?? '-')
                          . ' (' . ($info['equipment_name'] ?? '-') . ')';
                finance_sync_upsert($pdo, [
                    'source_module' => 'eborrow_payment',
                    'source_id'     => (string)$new_payment_id,
                    'kind'          => 'income',
                    'amount'        => $amount_paid,
                    'txn_date'      => date('Y-m-d'),
                    'description'   => $descText,
                    'category_name' => 'รายรับอื่นๆ',
                    'reference'     => 'e-Borrow Payment #' . $new_payment_id,
                    'admin_id'      => $staff_id,
                ]);
            } catch (Throwable $e) {
                error_log('[record_payment finance_sync] ' . $e->getMessage());
            }

            $response['status'] = 'success';
            $response['message'] = 'บันทึกการชำระเงินสำเร็จ';
            $response['new_payment_id'] = $new_payment_id;
        } else {
            throw new Exception("ไม่สามารถบันทึกการชำระเงินได้");
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException) {
            error_log('[record_payment] PDO: ' . $e->getMessage());
            $response['message'] = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่';
        } else {
            $response['message'] = $e->getMessage();
        }
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
