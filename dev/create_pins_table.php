<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_portal_pins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_type VARCHAR(20) NOT NULL,
        project_id VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_proj (user_id, user_type, project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "Table created successfully\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
