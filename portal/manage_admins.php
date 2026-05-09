<?php
/**
 * portal/manage_admins.php
 *
 * DEPRECATED — UI เก่าที่จัดการได้แค่ access_eborrow / access_ecampaign
 * ถูกแทนที่ด้วย Identity Governance ใน portal/index.php?section=identity
 * ซึ่งครอบคลุม access flag ทั้งหมด พร้อม audit log (ISO 27001)
 *
 * ปล่อย stub ไว้เพื่อ redirect link เก่าให้ไปยังหน้าใหม่อัตโนมัติ
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// คงเงื่อนไขเดิม: เฉพาะ superadmin เท่านั้น
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    header('Location: index.php');
    exit;
}

header('Location: index.php?section=identity&tab=staff', true, 302);
exit;
