<?php
// admin/logout.php
session_start();

// ล้าง session ทุก key ก่อน destroy (ป้องกัน session fixation)
session_unset();
session_destroy();

// ลบ session cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 86400,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}

header('Location: login.php');
exit;