<?php
// 1. เน€เธฃเธดเนเธก Session เน€เธชเธกเธญ
session_start();

// 2. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธกเธตเธเธฒเธฃเธชเนเธเธเนเธญเธกเธนเธฅเธกเธฒเนเธเธ POST เธซเธฃเธทเธญเนเธกเน
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 3. เน€เธฃเธตเธขเธเนเธเนเนเธเธฅเนเน€เธเธทเนเธญเธกเธ•เนเธญเธเธฒเธเธเนเธญเธกเธนเธฅ
    // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
    require_once(__DIR__ . '/../../../config/db_connect.php');
    require_once('../includes/log_function.php');

    // 4. เธฃเธฑเธเธเนเธฒเธเธฒเธเธเธญเธฃเนเธก
    $username = $_POST['username'];
    $password = $_POST['password'];

    try {
        // 5. เน€เธ•เธฃเธตเธขเธกเธเธณเธชเธฑเนเธ SQL
        $stmt = $pdo->prepare("SELECT * FROM sys_staff WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        // 6. เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเน
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 7. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒ: (1) เน€เธเธญเธเธนเนเนเธเน เนเธฅเธฐ (2) เธฃเธซเธฑเธชเธเนเธฒเธเธ–เธนเธเธ•เนเธญเธ
        if ($user && password_verify($password, $user['password_hash'])) {

        // (เนเธซเธกเน) 7.1 เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธเธฑเธเธเธตเธ–เธนเธเธฃเธฐเธเธฑเธเธซเธฃเธทเธญเนเธกเน
        if (isset($user['account_status']) && $user['account_status'] == 'disabled') {
            // เธฃเธซเธฑเธชเธ–เธนเธ เนเธ•เนเธเธฑเธเธเธตเธ–เธนเธเธฃเธฐเธเธฑเธ
            // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
            header("Location: ../admin/login.php?error=disabled");
            exit;
        }

            // 8. Log in เธชเธณเน€เธฃเนเธ! "เนเธเธเธเธฑเธ•เธฃเธเธเธฑเธเธเธฒเธ"
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role']; 

            $log_desc = "เธเธเธฑเธเธเธฒเธ '{$user['full_name']}' (Username: {$user['username']}) เนเธ”เนเน€เธเนเธฒเธชเธนเนเธฃเธฐเธเธ (เธเนเธฒเธ Password)";
            log_action($pdo, $user['id'], 'login_password', $log_desc);
            
            // 9. เธชเนเธเธเธฅเธฑเธเนเธเธซเธเนเธฒ index.php
            // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
            header("Location: ../admin/index.php");
            exit;

        } else {
            // 10. Log in เนเธกเนเธชเธณเน€เธฃเนเธ
            // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
            header("Location: ../admin/login.php?error=1");
            exit;
        }

    } catch (PDOException $e) {
        die("เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเน: " . $e->getMessage()); // (เธ–เธนเธเธ•เนเธญเธ)
    }

} else {
    // เธ–เนเธฒเน€เธเนเธฒเธกเธฒเธซเธเนเธฒเธเธตเนเธ•เธฃเธเน
    // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
    header("Location: ../admin/login.php");
    exit;
}
?>
