<?php
// process/send_receipt_manual.php
// เธชเธณเธซเธฃเธฑเธเนเธญเธ”เธกเธดเธเธเธ”เธชเนเธเนเธเน€เธชเธฃเนเธเธขเนเธญเธเธซเธฅเธฑเธ

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
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
    echo json_encode(['status' => 'error', 'message' => 'เนเธกเนเธเธเธฃเธซเธฑเธชเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธ']);
    exit;
}

try {
    // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅเธ—เธตเนเธเธณเน€เธเนเธเธชเธณเธซเธฃเธฑเธเธชเธฃเนเธฒเธเนเธเน€เธชเธฃเนเธ
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
        throw new Exception("เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธ");
    }
    
    if (empty($data['line_user_id'])) {
        throw new Exception("เธเธนเนเนเธเนเธเธฒเธเธฃเธฒเธขเธเธตเนเนเธกเนเนเธ”เนเธเธนเธเธเธฑเธเธเธต LINE");
    }

    // 2. เน€เธ•เธฃเธตเธขเธกเธเนเธญเธกเธนเธฅ
    $line_user_id = $data['line_user_id'];
    $item_name = $data['item_name'];
    $amount = $data['amount_paid'];
    $date_txt = date('d/m/Y H:i', strtotime($data['payment_date']));
    $method_text = ($data['payment_method'] == 'bank_transfer') ? 'เนเธญเธเน€เธเธดเธ' : 'เน€เธเธดเธเธชเธ”';

    // 3. เธชเธฃเนเธฒเธ Flex Message (Copy เธกเธฒเธเธฒเธเนเธเนเธ”เน€เธ”เธดเธกเน€เธเธทเนเธญเนเธซเนเธซเธเนเธฒเธ•เธฒเน€เธซเธกเธทเธญเธเธเธฑเธ)
    $flexData = [
        "type" => "bubble",
        "size" => "giga",
        "body" => [
            "type" => "box",
            "layout" => "vertical",
            "contents" => [
                ["type" => "text", "text" => "RECEIPT", "weight" => "bold", "color" => "#1DB446", "size" => "sm"],
                ["type" => "text", "text" => "เนเธเน€เธชเธฃเนเธเธฃเธฑเธเน€เธเธดเธ (เธชเนเธเธเนเธณ)", "weight" => "bold", "size" => "xl", "margin" => "md"],
                ["type" => "text", "text" => "เธเธณเธฃเธฐเธเนเธฒเธเธฃเธฑเธเธญเธธเธเธเธฃเธ“เน", "size" => "xs", "color" => "#aaaaaa", "wrap" => true],
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
                                ["type" => "text", "text" => "เน€เธฅเธเธ—เธตเนเธฃเธฒเธขเธเธฒเธฃ", "size" => "sm", "color" => "#555555"],
                                ["type" => "text", "text" => "#PAY-" . $payment_id, "size" => "sm", "color" => "#111111", "align" => "end"]
                            ]
                        ],
                        [
                            "type" => "box", "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "เธงเธฑเธเธ—เธตเนเธเธณเธฃเธฐ", "size" => "sm", "color" => "#555555"],
                                ["type" => "text", "text" => $date_txt, "size" => "sm", "color" => "#111111", "align" => "end"]
                            ]
                        ],
                         [
                            "type" => "box", "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "เธญเธธเธเธเธฃเธ“เน", "size" => "sm", "color" => "#555555", "flex" => 0],
                                ["type" => "text", "text" => $item_name, "size" => "sm", "color" => "#111111", "align" => "end", "wrap" => true, "flex" => 2]
                            ]
                        ],
                        [
                            "type" => "box",
                            "layout" => "horizontal",
                            "contents" => [
                                ["type" => "text", "text" => "เธงเธดเธเธตเธเธณเธฃเธฐ", "size" => "sm", "color" => "#555555", "flex" => 0],
                                ["type" => "text", "text" => $method_text, "size" => "sm", "color" => "#111111", "align" => "end"]
                            ]
                        ],
                        ["type" => "separator", "margin" => "xxl"],
                        [
                            "type" => "box", "layout" => "horizontal", "margin" => "xxl",
                            "contents" => [
                                ["type" => "text", "text" => "เธฃเธงเธกเธ—เธฑเนเธเธชเธดเนเธ", "size" => "sm", "color" => "#555555"],
                                ["type" => "text", "text" => number_format($amount, 2) . " เธฟ", "size" => "xl", "color" => "#111111", "align" => "end", "weight" => "bold"]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        "styles" => ["footer" => ["separator" => true]]
    ];

    // 4. เธขเธดเธ API เนเธเธ—เธตเน LINE
    $payload = [
        'to' => $line_user_id,
        'messages' => [['type' => 'flex', 'altText' => 'เนเธเน€เธชเธฃเนเธเธฃเธฑเธเน€เธเธดเธเธเนเธฒเธเธฃเธฑเธ', 'contents' => $flexData]]
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
        echo json_encode(['status' => 'success', 'message' => 'เธชเนเธเนเธเน€เธชเธฃเนเธเน€เธเนเธฒ LINE เน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเนเธง']);
    } else {
        throw new Exception("LINE API Error: $httpCode");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
