<?php
// user/logout.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

// ล้าง session ทั้งหมด
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: index.php', true, 303);
exit;
