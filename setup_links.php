<?php
require_once __DIR__ . '/config.php';
$pdo = db();

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS `sys_app_links` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `category` varchar(50) NOT NULL DEFAULT 'system',
        `title` varchar(255) NOT NULL,
        `description` varchar(500) DEFAULT NULL,
        `url` varchar(500) NOT NULL,
        `icon` varchar(100) DEFAULT 'fa-link',
        `color_theme` varchar(50) DEFAULT 'blue',
        `sort_order` int(11) DEFAULT 0,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
    
    if($pdo->query("SELECT COUNT(*) FROM sys_app_links")->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO sys_app_links (category, title, description, url, icon, color_theme, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute(['system', 'หน้าเว็บ E-Campaign เบื้องหน้า', 'ระบบลงทะเบียนกิจกรรมและจองคิว', '/e-campaignv2/', 'fa-hospital-user', 'emerald', 1]);
        $stmt->execute(['system', 'ระบบ E-Borrow', 'ค้นหาและทำรายการยืม-คืน อุปกรณ์', '/e-campaignv2/e_Borrow/', 'fa-box-open', 'blue', 2]);
        $stmt->execute(['system', 'Admin Login Portal', 'ทางเข้าการจัดการสำหรับเจ้าหน้าที่', '/e-campaignv2/login.php', 'fa-shield-halved', 'amber', 3]);
        
        $stmt->execute(['liff', 'LINE LIFF - หน้าแรกผู้ป่วย', 'ระบบฝังใน LINE OA สำหรับผู้ป่วยดูคิวของตนเอง', 'https://liff.line.me/1234567890-abcdef', 'fa-line', 'line', 4]);
        $stmt->execute(['liff', 'LINE LIFF - แบบสอบถามคัดกรอง', 'หน้าตอบแบบสอบถามก่อนเข้าร่วมกิจกรรม', 'https://liff.line.me/1234567890-ghijkl', 'fa-line', 'line', 5]);
    }
    echo "SUCCESS";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
