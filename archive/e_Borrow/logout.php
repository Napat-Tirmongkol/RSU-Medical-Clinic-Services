<?php
// e_Borrow/logout.php
session_start();

// ��ҧ Session ������ (������ e-campaign sessions ����������ѹ)
$_SESSION = [];

// ź Session Cookie � Browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// ����� Session �� Server
session_destroy();

// �觡�Ѻ˹�� Login �ͧ e_Borrow �µç (����ҹ index.php)
header("Location: login.php");
exit;