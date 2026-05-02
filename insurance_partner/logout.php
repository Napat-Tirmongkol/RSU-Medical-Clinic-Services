<?php
/**
 * insurance_partner/logout.php
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';

if (!empty($_SESSION['ins_partner_logged_in'])) {
    ins_partner_log('logout', 'user_logout');
}

// ล้างเฉพาะ key ของ partner — ไม่ทำลาย session ทั้งหมด (กันกระทบ namespace อื่น)
foreach ($_SESSION as $k => $_) {
    if (strpos($k, 'ins_partner_') === 0 || $k === '_ins_partner_last_activity') {
        unset($_SESSION[$k]);
    }
}

header('Location: login.php?reason=logout');
exit;
