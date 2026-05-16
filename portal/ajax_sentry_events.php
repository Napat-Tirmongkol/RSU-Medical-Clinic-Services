<?php
/**
 * portal/ajax_sentry_events.php
 * Read + retry endpoint for the Sentry Events admin UI.
 *
 * Superadmin only (events can contain stack traces / URLs / user IDs).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    echo json_encode(['ok' => false, 'message' => 'Superadmin only']);
    exit;
}

$pdo    = db();
$action = $_REQUEST['action'] ?? 'list';

// Self-heal schema — receiver does the same on first webhook hit, but if the
// admin opens this page before any new event arrives (or before the receiver
// is redeployed) the github_* columns might still be missing.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_sentry_events (
        id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sentry_id            VARCHAR(64)  NOT NULL DEFAULT '',
        resource             VARCHAR(40)  NOT NULL DEFAULT '',
        action               VARCHAR(40)  NOT NULL DEFAULT '',
        level                VARCHAR(20)  NOT NULL DEFAULT '',
        title                VARCHAR(500) NOT NULL DEFAULT '',
        culprit              VARCHAR(500) NOT NULL DEFAULT '',
        environment          VARCHAR(60)  NOT NULL DEFAULT '',
        url                  TEXT         NULL,
        raw_payload          MEDIUMTEXT   NULL,
        github_issue_url     VARCHAR(255) NULL,
        github_issue_number  INT UNSIGNED NULL,
        github_error         VARCHAR(500) NULL,
        received_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sentry_id   (sentry_id),
        INDEX idx_resource    (resource),
        INDEX idx_action      (action),
        INDEX idx_received_at (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE sys_sentry_events ADD COLUMN github_issue_url VARCHAR(255) NULL"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_sentry_events ADD COLUMN github_issue_number INT UNSIGNED NULL"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_sentry_events ADD COLUMN github_error VARCHAR(500) NULL"); } catch (PDOException) {}
} catch (Throwable) { /* table will be created on first webhook anyway */ }

try {
    switch ($action) {

        case 'list': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;

            $period   = (string)($_GET['period']      ?? 'all');
            $resource = (string)($_GET['resource']    ?? '');
            $level    = (string)($_GET['level']       ?? '');
            $envFlt   = (string)($_GET['environment'] ?? '');
            $q        = trim((string)($_GET['q']      ?? ''));
            $ghFilter = (string)($_GET['github']      ?? ''); // '' | 'created' | 'pending' | 'failed'

            $where  = ['1=1'];
            $params = [];

            if ($period !== 'all') {
                $map = ['today' => 1, '7d' => 7, '30d' => 30];
                if (isset($map[$period])) {
                    $where[] = 'received_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
                    $params[] = $map[$period];
                }
            }
            if ($resource !== '') { $where[] = 'resource = ?'; $params[] = $resource; }
            if ($level    !== '') { $where[] = 'level    = ?'; $params[] = $level; }
            if ($envFlt   !== '') { $where[] = 'environment = ?'; $params[] = $envFlt; }
            if ($q !== '') {
                $where[] = '(title LIKE ? OR culprit LIKE ? OR sentry_id LIKE ?)';
                $like    = '%' . $q . '%';
                array_push($params, $like, $like, $like);
            }
            if ($ghFilter === 'created') $where[] = 'github_issue_url IS NOT NULL';
            elseif ($ghFilter === 'pending') $where[] = '(github_issue_url IS NULL AND github_error IS NULL)';
            elseif ($ghFilter === 'failed')  $where[] = 'github_error IS NOT NULL';

            $whereSql = implode(' AND ', $where);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_sentry_events WHERE $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $list = $pdo->prepare("SELECT id, sentry_id, resource, action, level, title, culprit,
                                          environment, url, github_issue_url, github_issue_number, github_error, received_at
                                   FROM sys_sentry_events
                                   WHERE $whereSql
                                   ORDER BY id DESC
                                   LIMIT $perPage OFFSET $offset");
            $list->execute($params);

            // Stat tiles (independent of pagination but follow same date filter logic)
            $stats = $pdo->query("SELECT
                COUNT(*) AS total,
                SUM(received_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS d1,
                SUM(received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS d7,
                SUM(level = 'error' OR level = 'fatal') AS errors,
                SUM(github_issue_url IS NOT NULL) AS gh_created,
                SUM(github_error IS NOT NULL) AS gh_failed
                FROM sys_sentry_events")->fetch(PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'ok' => true,
                'page'    => $page,
                'pages'   => max(1, (int)ceil($total / $perPage)),
                'total'   => $total,
                'rows'    => $list->fetchAll(PDO::FETCH_ASSOC),
                'stats'   => $stats,
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'get_raw': {
            $id = (int)($_GET['id'] ?? 0);
            $st = $pdo->prepare("SELECT id, sentry_id, resource, action, level, title, culprit,
                                        environment, url, raw_payload,
                                        github_issue_url, github_issue_number, github_error, received_at
                                 FROM sys_sentry_events WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'message' => 'not found']); break; }

            // Pretty-print raw payload for the viewer
            $raw = (string)($row['raw_payload'] ?? '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $row['raw_pretty'] = $decoded !== null
                ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : $raw;

            echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'retry_github': {
            require_method_post();
            validate_csrf_or_die();

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'bad id']); break; }

            $st = $pdo->prepare("SELECT * FROM sys_sentry_events WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'message' => 'not found']); break; }
            if (!empty($row['github_issue_url'])) {
                echo json_encode(['ok' => true, 'message' => 'already created', 'url' => $row['github_issue_url']]);
                break;
            }

            $secretsPath = __DIR__ . '/../config/secrets.php';
            $secrets = file_exists($secretsPath) ? require $secretsPath : [];

            require_once __DIR__ . '/../includes/github_issue_helper.php';
            $gh = github_issue_create_from_sentry($row, is_array($secrets) ? $secrets : []);

            if ($gh['ok']) {
                $pdo->prepare("UPDATE sys_sentry_events
                    SET github_issue_url = ?, github_issue_number = ?, github_error = NULL
                    WHERE id = ?")->execute([$gh['url'] ?? '', $gh['number'] ?? null, $id]);
            } else {
                $pdo->prepare("UPDATE sys_sentry_events SET github_error = ? WHERE id = ?")
                    ->execute([mb_substr((string)$gh['message'], 0, 500), $id]);
            }
            echo json_encode($gh, JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'unknown action']);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

function require_method_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'POST required']);
        exit;
    }
}
