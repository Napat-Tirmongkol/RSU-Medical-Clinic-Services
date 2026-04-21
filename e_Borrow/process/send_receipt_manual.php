<?php
// process/send_receipt_manual.php
// สำหรับแอดมินกดส่งใบเสร็จย้อนหลัง

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');
require_once('../includes/line_config.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;

if ($payment_id == 0) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสการชำระเงิน']);
    exit;
}

try {
    // 1. ดึงข้อมูลที่จำเป็นสำหรับสร้างใบเสร็จ
    $sql = "SELECT 
                p.amount_paid, p.payment_method, p.payment_date,
                s.line_user_id, s.full_name, 
                ei.name as item_name
            FROM borrow_payments p
            JOIN borrow_fines f ON p.fine_id = f.id
            JOIN sys_users s ON f.student_id = s.id
            JOIN borrow_records t ON f.transaction_id = t.id
            JOIN borrow_items ei ON t.equipment_id = ei.id
            WHERE p.id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$payment_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new Exception("ไม่พบข้อมูลการชำระเงิน");
    }
    
    if (empty($data['line_user_id'])) {
        throw new Exception("ผู้ใช้งานรายนี้ไม่ได้ผูกบัญชี LINE");
    }

    // 2. เตรียมข้อมูล
    $line_user_id = $data['line_user_id'];
    $item_name = $data['item_name'];
    $amount = $data['amount_paid'];
    $date_txt = date('d/m/Y H:i', strtotime($data['payment_date']));
    $method_text = ($data['payment_method'] == 'bank_transfer') ? 'โอนเงิน' : 'เงินสด';

    // 3. สร้าง Flex Message (Copy มาจากโค้ดเดิมเพื่อให้หน้าตาเหมือนกัน)
    $flexData = [
        "type" => "bubble",
        "size" => "giga",
        "body" => [
            "type" => "box",
            "layout" => "vertical",
            "contents" => [
                ["type" => "text", "text" => "RECEIPT", "weight" => "bold", "color" => "#1DB446", "size" => "sm"],
                ["type" => "text", "text" => "ใบเสร็จรับเงิน (ส่งซ้ำ)", "weight" => "bold", "size" => "xl", "margin" => "md"],
                ["type" => "text", "text" => "ชำระค่าปรับอุปกรณ์", "size" => "xs", "color" => "#aaaaaa", "wrap" => true],
                ["type" => "separator", "margin" => "xxl"],
                [
                    "type" => "box",
                    "layout" => "vertical",
                    "margin" => "xxl",
                    "spacing" => "sm",
                    "contents" => [
                        [
                            "type" => "box", "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "เลขที่รายการ", "size" => "sm", "color" => "#555555"],
                                ["type" => "text", "text" => "#PAY-" . $payment_id, "size" => "sm", "color" => "#111111", "align" => "end"]
                            ]
                        ],
                        [
                            "type" => "box", "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "วันที่ชำระ", "size" => "sm", "color" => "#555555"],
                                ["type" => "text", "text" => $date_txt, "size" => "sm", "color" => "#111111", "align" => "end"]
                            ]
                        ],
                         [
                            "type" => "box", "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "อุปกรณ์", "size" => "sm", "color" => "#555555", "flex" => 0],
                                ["type" => "text", "text" => $item_name, "size" => "sm", "color" => "#111111", "align" => "end", "wrap" => true, "flex" => 2]
                            ]
                        ],
                        [
                            "type" => "box",
                            "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "วิธีชำระ", "size" => "sm", "color" => "#555555", "flex" => 0],
                                ["type" => "text", "text" => $method_text, "size" => "sm", "color" => "#111111", "align" => "end"]
                            ]
                        ],
                        ["type" => "separator", "margin" => "xxl"],
                        [
                            "type" => "box", "layout" => "horizontal", "margin" => "xxl",
                            "contents" => [
                                ["type" => "text", "text" => "รวมทั้งสิ้น", "size" => "sm", "color" => "#555555"],
                                ["type" => "text", "text" => number_format($amount, 2) . " ฿", "size" => "xl", "color" => "#111111", "align" => "end", "weight" => "bold"]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        "styles" => ["footer" => ["separator" => true]]
    ];

    // 4. ยิง API ไปที่ LINE
    $payload = [
        'to' => $line_user_id,
        'messages' => [['type' => 'flex', 'altText' => 'ใบเสร็จรับเงินค่าปรับ', 'contents' => $flexData]]
    ];

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_MESSAGING_API_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        echo json_encode(['status' => 'success', 'message' => 'ส่งใบเสร็จเข้า LINE เรียบร้อยแล้ว']);
    } else {
        throw new Exception("LINE API Error: $httpCode");
    }

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
