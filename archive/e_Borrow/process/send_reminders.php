<?php
// [เนเธเนเนเธเนเธเธฅเน: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/process/send_reminders.php]
// เธชเธเธฃเธดเธเธ•เนเธชเธณเธซเธฃเธฑเธเธชเนเธ LINE เนเธเนเธเน€เธ•เธทเธญเธ (v3 - เนเธเนเธเน€เธ•เธทเธญเธ 2 เธฃเธญเธ)

// (เน€เธฃเธฒเนเธ”เนเธฅเธเนเธเนเธ” Debug 3 เธเธฃเธฃเธ—เธฑเธ”เธเธเธชเธธเธ”เธญเธญเธเนเธฅเนเธง)

// 1. เธ•เธฑเนเธเธเนเธฒ Secret Key
$MY_SECRET_KEY = "E-Borrow-Cron-Key-987654321"; 

// 2. เธ•เธฃเธงเธเธชเธญเธ Key
if (!isset($_GET['secret']) || $_GET['secret'] !== $MY_SECRET_KEY) {
    http_response_code(403); // Forbidden
    die("Access Denied."); // เธซเธขเธธเธ”เธ—เธณเธเธฒเธเธ—เธฑเธเธ—เธต
}

// 3. เธ–เนเธฒ Key เธ–เธนเธเธ•เนเธญเธ เธชเธเธฃเธดเธเธ•เนเธเธฐเธ—เธณเธเธฒเธเธ•เนเธญ...
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once(__DIR__ . '/../includes/line_config.php');

$push_api_url = 'https://api.line.me/v2/bot/message/push';
$access_token = LINE_MESSAGING_API_TOKEN;

echo "Starting Reminder Script...<br>";
echo "-----------------------------------<br>";

try {
    // =============================================
    // โ… เธชเนเธงเธเธ—เธตเน 1: เนเธเนเธเน€เธ•เธทเธญเธเธชเธณเธซเธฃเธฑเธเธเธเธ—เธตเนเธเธฃเธเธเธณเธซเธเธ” "เธงเธฑเธเธเธตเน"
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
              AND t.due_date = CURDATE() -- (เน€เธเธทเนเธญเธเนเธ: เธเธฃเธเธเธณเธซเธเธ”เธงเธฑเธเธเธตเน)
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
            
            $message_text = "เธชเธงเธฑเธชเธ”เธตเธเธธเธ“ {$item['student_name']},\n"
                          . "เธฃเธฐเธเธเธขเธทเธกเธเธทเธเธญเธธเธเธเธฃเธ“เน MedLoan เธเธญเนเธเนเธเน€เธ•เธทเธญเธ\n\n"
                          . "เธฃเธฒเธขเธเธฒเธฃ: {$item['item_name']} ({$item['type_name']})\n"
                          . "โ€ผ๏ธ เธเธฃเธเธเธณเธซเธเธ”เธเธทเธเธงเธฑเธเธเธตเน โ€ผ๏ธ (" . date('d/m/Y') . ")\n\n"
                          . "เธเธฃเธธเธ“เธฒเธเธณเธกเธฒเธเธทเธเธ—เธตเนเธเธฅเธดเธเธดเธเน€เธงเธเธเธฃเธฃเธกเธฏ เธ”เนเธงเธขเธเธฃเธฑเธ";

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
    // โ… เธชเนเธงเธเธ—เธตเน 2: (เน€เธเธดเนเธกเนเธซเธกเน) เนเธเนเธเน€เธ•เธทเธญเธเธชเธณเธซเธฃเธฑเธเธเธเธ—เธตเนเธเธฃเธเธเธณเธซเธเธ” "เธเธฃเธธเนเธเธเธตเน"
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
              AND t.due_date = CURDATE() + INTERVAL 1 DAY -- (เน€เธเธทเนเธญเธเนเธ: เธเธฃเธเธเธณเธซเธเธ”เธเธฃเธธเนเธเธเธตเน)
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
            
            // (เน€เธเธฅเธตเนเธขเธเธเนเธญเธเธงเธฒเธกเธชเธณเธซเธฃเธฑเธเนเธเนเธเน€เธ•เธทเธญเธเธฅเนเธงเธเธซเธเนเธฒ)
            $message_text = "เธชเธงเธฑเธชเธ”เธตเธเธธเธ“ {$item['student_name']},\n"
                          . "เธฃเธฐเธเธเธขเธทเธกเธเธทเธเธญเธธเธเธเธฃเธ“เน MedLoan เธเธญเนเธเนเธเน€เธ•เธทเธญเธเธฅเนเธงเธเธซเธเนเธฒ\n\n"
                          . "เธฃเธฒเธขเธเธฒเธฃ: {$item['item_name']} ({$item['type_name']})\n"
                          . "เธเธฐเธเธฃเธเธเธณเธซเธเธ”เธเธทเธเนเธ *เธงเธฑเธเธเธฃเธธเนเธเธเธตเน* (" . date('d/m/Y', strtotime('+1 day')) . ")\n\n"
                          . "เธเธญเธเธเธธเธ“เธเธฃเธฑเธ";

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
} catch (Exception $e) {
    echo "General Error: " . $e->getMessage() . "<br>";
}

?>
