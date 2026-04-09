<?php
/*
 * archive/e_Borrow/includes/db_connect.php
 * โหลด DB credentials จาก config/secrets.php (gitignored)
 * ห้าม hardcode credentials ในไฟล์นี้
 */

$secretsPath = __DIR__ . '/../../../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];

$db_host = $secrets['EBORROW_DB_HOST'] ?? $secrets['DB_HOST'] ?? '127.0.0.1';
$db_user = $secrets['EBORROW_DB_USER'] ?? $secrets['DB_USER'] ?? '';
$db_pass = $secrets['EBORROW_DB_PASS'] ?? $secrets['DB_PASS'] ?? '';
$db_name = $secrets['EBORROW_DB_NAME'] ?? $secrets['DB_NAME'] ?? 'e_Borrow';
$db_port = (int)($secrets['EBORROW_DB_PORT'] ?? $secrets['DB_PORT'] ?? 3306);

try {
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("e_Borrow DB connection failed: " . $e->getMessage());
    http_response_code(503);
    exit("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาลองใหม่ภายหลัง");
}

define('FINE_RATE_PER_DAY', 10.00);

require_once __DIR__ . '/../../../includes/error_logger.php';
