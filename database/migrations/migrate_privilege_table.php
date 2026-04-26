<?php
/**
 * dev/migrate_privilege_table.php
 * ขั้นตอนที่ 1: สร้างตาราง sys_admin_privilege_inventory และโฟลเดอร์จัดเก็บเอกสาร
 */
require_once __DIR__ . '/../config.php';

try {
    $pdo = db();
    
    // 1. สร้างตารางฐานข้อมูล
    $sql = "CREATE TABLE IF NOT EXISTS sys_admin_privilege_inventory (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        role_assigned ENUM('Super Admin', 'Admin', 'Executive') NOT NULL,
        justification TEXT NOT NULL,
        approved_by VARCHAR(255) NOT NULL,
        assigned_at DATE NOT NULL,
        expiry_date DATE NULL,
        revoked_at DATETIME NULL,
        document_path VARCHAR(500) NULL,
        status TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "✅ [Success] ตาราง sys_admin_privilege_inventory ถูกสร้างหรือตรวจสอบเรียบร้อยแล้ว\n";

    // 2. สร้างโฟลเดอร์จัดเก็บเอกสาร (อยู่นอก public path ถ้าเป็นไปได้ หรือป้องกันด้วย .htaccess)
    $storagePath = __DIR__ . '/../storage/access_requests';
    if (!file_exists($storagePath)) {
        if (mkdir($storagePath, 0755, true)) {
            echo "✅ [Success] สร้างโฟลเดอร์ storage/access_requests/ เรียบร้อยแล้ว\n";
            
            // สร้าง .htaccess เพื่อป้องกันการเข้าถึงไฟล์โดยตรงผ่าน URL
            $htaccessContent = "Order Deny,Allow\nDeny from all";
            file_put_contents($storagePath . '/.htaccess', $htaccessContent);
            echo "✅ [Success] สร้างไฟล์ .htaccess เพื่อป้องกันความปลอดภัยเรียบร้อยแล้ว\n";
        } else {
            echo "❌ [Error] ไม่สามารถสร้างโฟลเดอร์จัดเก็บได้\n";
        }
    } else {
        echo "ℹ️ [Info] โฟลเดอร์จัดเก็บเอกสารมีอยู่แล้ว\n";
    }

} catch (PDOException $e) {
    die("❌ [Error] ไม่สามารถสร้างตารางได้: " . $e->getMessage());
}
