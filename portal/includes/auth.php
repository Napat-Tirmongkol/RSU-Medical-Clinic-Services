<?php
// portal/includes/auth.php
require_once __DIR__ . '/../../includes/session_guard.php';
start_secure_session();

// Calculate relative path to admin/auth/ based on current directory depth
$scriptName = $_SERVER['SCRIPT_NAME'];
$portalPos  = strpos($scriptName, '/portal/');
if ($portalPos !== false) {
    $afterPortal = substr($scriptName, $portalPos + 8);
    $depth = substr_count($afterPortal, '/');
    $_pfx  = str_repeat('../', $depth + 1) . 'admin/auth/';
} else {
    $_pfx = '../admin/auth/';
}

check_admin_session($_pfx . 'login.php', $_pfx . 'staff_login.php');
