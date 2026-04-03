<?php
// portal/includes/auth.php

// ── Session Security Settings ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 7200);
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ── Idle Timeout ─────────────────────────────────────────────────────────────
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (isset($_SESSION['_admin_last_activity'])) {
        if (time() - $_SESSION['_admin_last_activity'] > 7200) {
            session_unset();
            session_destroy();
            header('Location: ../admin/login.php?reason=timeout');
            exit;
        }
    }
    $_SESSION['_admin_last_activity'] = time();
}

// ── Auth Check ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}
