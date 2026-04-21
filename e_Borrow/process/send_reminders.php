<?php
// [แก้ไขไฟล์: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/process/send_reminders.php]
// สคริปต์สำหรับส่ง LINE แจ้งเตือน (v3 - แจ้งเตือน 2 รอบ)

// (เราได้ลบโค้ด Debug 3 บรรทัดบนสุดออกแล้ว)

// 1. ตั้งค่า Secret Key
$MY_SECRET_KEY = "E-Borrow-Cron-Key-987654321"; 

// 2. ตรวจสอบ Key
if (!isset($_GET['secret']) || $_GET['secret'] !== $MY_SECRET_KEY) {
    http_response_code(403); // Forbidden
    die("Access Denied."); // หยุดทำงานทันที
}

// 3. ถ้า Key ถูกต้อง สคริปต์จะทำงานต่อ...
require_once(__DIR__ . '/../includes/db_connect.php');
require_once(__DIR__ . '/../includes/line_config.php');

$push_api_url = 'https://api.line.me/v2/bot/message/push';
$access_token = LINE_MESSAGING_API_TOKEN;

echo "Starting Reminder Script...<br>";
echo "-----------------------------------<br>";

try {
    // =============================================
    // ✅ ส่วนที่ 1: แจ้งเตือนสำหรับคนที่ครบกำหนด "วันนี้"
    // =============================================
    echo "Running Part 1: Same-Day Reminders...<br>";

    $sql_today = "SELECT 
                t.id as transaction_id, s.full_name as student_name,
                s.line_user_id, ei.name as item_name, et.name as type_name
            FROM borrow_records t
            JOIN sys_users s ON t.borrower_student_id = s.id
            JOIN borrow_items ei ON t.item_id = ei.id
            JOIN borrow_categories et ON t.type_id = et.id
            WHERE t.status = 'borrowed'
              AND t.approval_status IN ('approved', 'staff_added')
              AND t.due_date = CURDATE() -- (เงื่อนไข: ครบกำหนดวันนี้)
              AND s.line_user_id IS NOT NULL";
    
    $stmt_today = $pdo->prepare($sql_today);
    $stmt_today->execute();
    $reminders_today = $stmt_today->fetchAll(PDO::FETCH_ASSOC);

    $success_count_today = 0;
    $fail_count_today = 0;

    if (empty($reminders_today)) {
        echo "No items due today.<br>";
    } else {
        echo "Found " . count($reminders_today) . " user(s) to remind (due today).<br>";
        
        foreach ($reminders_today as $item) {
            $line_user_id = $item['line_user_id'];
            
            $message_text = "สวัสดีคุณ {$item['student_name']},\n"
                          . "ระบบยืมคืนอุปกรณ์ MedLoan ขอแจ้งเตือน\n\n"
                          . "รายการ: {$item['item_name']} ({$item['type_name']})\n"
                          . "‼️ ครบกำหนดคืนวันนี้ ‼️ (" . date('d/m/Y') . ")\n\n"
                          . "กรุณานำมาคืนที่คลินิกเวชกรรมฯ ด้วยครับ";

            $body = ['to' => $line_user_id, 'messages' => [['type' => 'text', 'text' => $message_text]]];
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $access_token];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $push_api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code == 200) {
                echo "Successfully sent message to {$item['student_name']} (TID: {$item['transaction_id']})<br>";
                $success_count_today++;
            } else {
                echo "Failed to send message to {$item['student_name']}. Response: {$response}<br>";
                $fail_count_today++;
            }
        }
    }
    echo "Part 1 Finished. Success: {$success_count_today}, Failed: {$fail_count_today}<br>";
    echo "-----------------------------------<br>";


    // =============================================
    // ✅ ส่วนที่ 2: (เพิ่มใหม่) แจ้งเตือนสำหรับคนที่ครบกำหนด "พรุ่งนี้"
    // =============================================
    echo "Running Part 2: 1-Day Advance Reminders...<br>";

    $sql_tomorrow = "SELECT 
                t.id as transaction_id, s.full_name as student_name,
                s.line_user_id, ei.name as item_name, et.name as type_name
            FROM borrow_records t
            JOIN sys_users s ON t.borrower_student_id = s.id
            JOIN borrow_items ei ON t.item_id = ei.id
            JOIN borrow_categories et ON t.type_id = et.id
            WHERE t.status = 'borrowed'
              AND t.approval_status IN ('approved', 'staff_added')
              AND t.due_date = CURDATE() + INTERVAL 1 DAY -- (เงื่อนไข: ครบกำหนดพรุ่งนี้)
              AND s.line_user_id IS NOT NULL";
    
    $stmt_tomorrow = $pdo->prepare($sql_tomorrow);
    $stmt_tomorrow->execute();
    $reminders_tomorrow = $stmt_tomorrow->fetchAll(PDO::FETCH_ASSOC);

    $success_count_tomorrow = 0;
    $fail_count_tomorrow = 0;

    if (empty($reminders_tomorrow)) {
        echo "No items due tomorrow.<br>";
    } else {
        echo "Found " . count($reminders_tomorrow) . " user(s) to remind (due tomorrow).<br>";
        
        foreach ($reminders_tomorrow as $item) {
            $line_user_id = $item['line_user_id'];
            
            // (เปลี่ยนข้อความสำหรับแจ้งเตือนล่วงหน้า)
            $message_text = "สวัสดีคุณ {$item['student_name']},\n"
                          . "ระบบยืมคืนอุปกรณ์ MedLoan ขอแจ้งเตือนล่วงหน้า\n\n"
                          . "รายการ: {$item['item_name']} ({$item['type_name']})\n"
                          . "จะครบกำหนดคืนใน *วันพรุ่งนี้* (" . date('d/m/Y', strtotime('+1 day')) . ")\n\n"
                          . "ขอบคุณครับ";

            $body = ['to' => $line_user_id, 'messages' => [['type' => 'text', 'text' => $message_text]]];
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $access_token];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $push_api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code == 200) {
                echo "Successfully sent advance message to {$item['student_name']} (TID: {$item['transaction_id']})<br>";
                $success_count_tomorrow++;
            } else {
                echo "Failed to send advance message to {$item['student_name']}. Response: {$response}<br>";
                $fail_count_tomorrow++;
            }
        }
    }
    echo "Part 2 Finished. Success: {$success_count_tomorrow}, Failed: {$fail_count_tomorrow}<br>";
    echo "-----------------------------------<br>";


} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "<br>";
} catch (Throwable $e) {
    echo "General Error: " . $e->getMessage() . "<br>";
}

?>
