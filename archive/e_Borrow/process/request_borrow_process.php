<?php
// [เนเธเนเนเธเนเธเธฅเน: process/request_borrow_process.php]

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
require_once('../includes/check_student_session_ajax.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 2. เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธเธเธญเธฃเนเธก
    $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $student_id = $_SESSION['student_id']; 
    $reason = isset($_POST['reason_for_borrowing']) ? trim($_POST['reason_for_borrowing']) : '';
    $staff_id = isset($_POST['lending_staff_id']) ? (int)$_POST['lending_staff_id'] : 0;
    $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : null;

    if ($type_id == 0 || $staff_id == 0 || empty($reason) || $due_date == null) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธเธฃเธเธ–เนเธงเธ (เน€เธซเธ•เธธเธเธฅ, เธเธนเนเธ”เธนเนเธฅ, เธซเธฃเธทเธญเธงเธฑเธเธ—เธตเนเธเธทเธ)';
        echo json_encode($response);
        exit;
    }

    // โ… (3) เธชเนเธงเธเธเธฑเธ”เธเธฒเธฃเนเธเธฅเนเธญเธฑเธเนเธซเธฅเธ” (เนเธเนเนเธเนเธซเนเธฃเธญเธเธฃเธฑเธเธฃเธนเธเธ เธฒเธ + เนเธเนเธเธฒเธฃเธชเนเธเธเนเธฒเธเธฅเธฑเธเนเธเธ JSON)
    $attachment_url = NULL; // เธเธณเธซเธเธ”เธเนเธฒเน€เธฃเธดเนเธกเธ•เนเธเน€เธเนเธ NULL

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        
        $file_tmp = $_FILES['attachment']['tmp_name'];
        $file_name = $_FILES['attachment']['name'];
        $file_size = $_FILES['attachment']['size'];
        
        // 1. [เนเธเนเนเธ] เน€เธเธดเนเธกเธเธฒเธกเธชเธเธธเธฅเธฃเธนเธเธ เธฒเธ (jpg, png)
        $allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
        
        // 2. [เนเธเนเนเธ] เน€เธเธดเนเธก MIME Types เธเธญเธเธฃเธนเธเธ เธฒเธ
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'image/jpeg', // เน€เธเธดเนเธก
            'image/png'   // เน€เธเธดเนเธก
        ];

        // เนเธขเธเธเธฒเธกเธชเธเธธเธฅเนเธเธฅเน
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // เธ•เธฃเธงเธเธชเธญเธ 1: เธเธฒเธกเธชเธเธธเธฅ
        if (!in_array($file_ext, $allowed_extensions)) {
            echo json_encode(['status' => 'error', 'message' => 'เธญเธเธธเธเธฒเธ•เน€เธเธเธฒเธฐเนเธเธฅเนเน€เธญเธเธชเธฒเธฃ (PDF, Word) เนเธฅเธฐเธฃเธนเธเธ เธฒเธ (JPG, PNG) เน€เธ—เนเธฒเธเธฑเนเธ']);
            exit;
        }

        // เธ•เธฃเธงเธเธชเธญเธ 2: MIME Type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_mimes)) {
            echo json_encode(['status' => 'error', 'message' => 'เนเธเธฅเนเนเธกเนเธ–เธนเธเธ•เนเธญเธ เธซเธฃเธทเธญเธญเธฒเธเน€เธเนเธเนเธเธฅเนเธญเธฑเธเธ•เธฃเธฒเธข']);
            exit;
        }

        // เธ•เธฃเธงเธเธชเธญเธ 3: เธเธเธฒเธ”เนเธเธฅเน (5MB)
        if ($file_size > 5 * 1024 * 1024) {
            echo json_encode(['status' => 'error', 'message' => 'เนเธเธฅเนเธกเธตเธเธเธฒเธ”เนเธซเธเนเน€เธเธดเธเนเธ (เธซเนเธฒเธกเน€เธเธดเธ 5MB)']);
            exit;
        }

        // 3. เธ•เธฑเนเธเธเธทเนเธญเนเธเธฅเนเนเธซเธกเน
        $new_filename = "req-" . uniqid() . "." . $file_ext;
        $upload_dir = '../uploads/attachments/';
        
        // เธชเธฃเนเธฒเธเนเธเธฅเน€เธ”เธญเธฃเนเธ–เนเธฒเธขเธฑเธเนเธกเนเธกเธต
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $destination = $upload_dir . $new_filename;

        if (move_uploaded_file($file_tmp, $destination)) {
            // เน€เธเนเธ Path เธฅเธเธ•เธฑเธงเนเธเธฃ $attachment_url (เธ•เธฑเธงเนเธเธฃเธเธตเนเนเธซเธฅเธฐเธ—เธตเนเธ•เนเธญเธเน€เธญเธฒเนเธเนเธเน)
            $attachment_url = 'uploads/attachments/' . $new_filename;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธญเธฑเธเนเธซเธฅเธ”เนเธเธฅเน (move_uploaded_file failed)']);
            exit;
        }
    }

    // 4. เน€เธฃเธดเนเธก Transaction
    try {
        $pdo->beginTransaction();

        // 4.1 เธเนเธเธซเธฒ "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เน
        $stmt_find = $pdo->prepare("SELECT id FROM borrow_items WHERE type_id = ? AND status = 'available' LIMIT 1 FOR UPDATE");
        $stmt_find->execute([$type_id]);
        $item_id = $stmt_find->fetchColumn();

        if (!$item_id) {
            throw new Exception("เธญเธธเธเธเธฃเธ“เนเธเธฃเธฐเน€เธ เธ—เธเธตเนเธ–เธนเธเธขเธทเธกเนเธเธซเธกเธ”เนเธฅเนเธงเนเธเธเธ“เธฐเธเธตเน");
        }

        // 4.2 "เธเธญเธ" เธญเธธเธเธเธฃเธ“เน
        $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
        $stmt_item->execute([$item_id]);

        // 4.3 "เธฅเธ”" เธเธณเธเธงเธเธเธญเธเธงเนเธฒเธ
        $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity - 1 WHERE id = ? AND available_quantity > 0");
        $stmt_type->execute([$type_id]);
        
        if ($stmt_item->rowCount() == 0 || $stmt_type->rowCount() == 0) {
             throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธญเธฑเธเน€เธ”เธ•เธชเธ•เนเธญเธเธญเธธเธเธเธฃเธ“เนเนเธ”เน");
        }

        // 4.4 "เธชเธฃเนเธฒเธ" เธเธณเธเธญเธขเธทเธก
        $sql_trans = "INSERT INTO borrow_records 
                        (type_id, item_id, equipment_id, borrower_student_id, reason_for_borrowing, 
                         attachment_url, 
                         lending_staff_id, due_date, 
                         status, approval_status, quantity) 
                      VALUES 
                        (?, ?, ?, ?, ?, 
                         ?, 
                         ?, ?, 
                         'borrowed', 'pending', 1)";
        
        $stmt_trans = $pdo->prepare($sql_trans);
        
        // โ… [เนเธเนเนเธเธเธธเธ”เธชเธณเธเธฑเธ]: เน€เธเธฅเธตเนเธขเธเธเธฒเธ $attachment_url_to_db เน€เธเนเธ $attachment_url
        $stmt_trans->execute([
            $type_id, $item_id, $item_id, $student_id, $reason, 
            $attachment_url, // <-- เนเธเนเธ•เธฃเธเธเธตเน (เน€เธ”เธดเธกเนเธเนเธ•เธฑเธงเนเธเธฃเธเธดเธ”)
            $staff_id, $due_date
        ]);

        $pdo->commit();

        $response['status'] = 'success';
        $response['message'] = 'เธชเนเธเธเธณเธเธญเธขเธทเธกเธชเธณเน€เธฃเนเธ! เธเธฃเธธเธ“เธฒเธฃเธญ Admin เธญเธเธธเธกเธฑเธ•เธด';

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// เธชเนเธเธเธณเธ•เธญเธ
echo json_encode($response);
exit;
?>