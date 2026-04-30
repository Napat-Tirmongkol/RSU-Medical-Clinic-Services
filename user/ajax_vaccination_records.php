<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$userId = $_SESSION['user_id'] ?? null;

if (!$userId && !empty($_SESSION['line_user_id'])) {
    try {
        $pdoTmp = db();
        $stmtUser = $pdoTmp->prepare("SELECT id FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
        $stmtUser->execute([':line_id' => $_SESSION['line_user_id']]);
        $userRow = $stmtUser->fetch();
        if ($userRow) {
            $userId = (int)$userRow['id'];
            $_SESSION['user_id'] = $userId;
        }
    } catch (Exception $e) {
        error_log('vaccination records user lookup error: ' . $e->getMessage());
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if ($perPage <= 0) {
    $perPage = 20;
}
$perPage = min(100, $perPage);
$offset = ($page - 1) * $perPage;

$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

try {
    $pdo = db();

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_vaccination_records'");
    if (!$tableCheck || !$tableCheck->fetch()) {
        echo json_encode([
            'ok' => true,
            'setup_required' => true,
            'rows' => [],
            'pagination' => [
                'page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'total_pages' => 1,
            ],
        ]);
        exit;
    }

    $where = ['user_id = :user_id'];
    $params = [':user_id' => (int)$userId];

    if ($search !== '') {
        $where[] = "(
            vaccine_name LIKE :search
            OR lot_number LIKE :search
            OR manufacturer LIKE :search
            OR provider_name LIKE :search
            OR location LIKE :search
            OR certificate_no LIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }

    if (in_array($status, ['completed', 'cancelled', 'entered_in_error'], true)) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM user_vaccination_records WHERE {$whereSql}");
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtCount->execute();
    $total = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $stmtRows = $pdo->prepare("
        SELECT
            id,
            vaccine_name,
            dose_number,
            lot_number,
            manufacturer,
            vaccinated_at,
            injection_site,
            provider_name,
            location,
            next_due_date,
            certificate_no,
            certificate_file,
            status,
            notes
        FROM user_vaccination_records
        WHERE {$whereSql}
        ORDER BY vaccinated_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $stmtRows->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtRows->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtRows->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtRows->execute();

    echo json_encode([
        'ok' => true,
        'setup_required' => false,
        'rows' => $stmtRows->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (PDOException $e) {
    error_log('vaccination records error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
