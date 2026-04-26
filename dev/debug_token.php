<?php
require_once __DIR__ . '/../config.php';
$token = 'd233d457a1461eed';

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, title, status, share_token FROM camp_list WHERE share_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'found' => (bool)$camp,
        'data' => $camp,
        'db_error' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'found' => false,
        'db_error' => $e->getMessage()
    ]);
}
