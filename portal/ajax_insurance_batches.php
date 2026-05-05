<?php
/**
 * portal/ajax_insurance_batches.php
 *
 * Batch workflow API for staff portal.
 * Scope rules:
 *   - superadmin / access_insurance      → see ALL batches
 *   - access_registry only               → see batches uploaded_by = self
 *
 * Approval / rejection requires access_insurance or superadmin (clinic role).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/includes/insurance_batch.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole    = $_SESSION['admin_role'] ?? '';
$adminId      = (int)($_SESSION['admin_id'] ?? 0);
$adminName    = $_SESSION['admin_username'] ?? 'staff';
$isSuper      = ($adminRole === 'superadmin');
$hasInsurance = !empty($_SESSION['access_insurance']) || $isSuper;
$hasRegistry  = !empty($_SESSION['access_registry']);

if (!$hasInsurance && !$hasRegistry) {
    json_err('ไม่มีสิทธิ์เข้าถึง', 403);
}

$pdo    = db();
$action = $_REQUEST['action'] ?? '';

// Auto-create insurance_companies if not yet migrated (safe, idempotent)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_companies (
        company_code  VARCHAR(20)  NOT NULL PRIMARY KEY,
        company_name  VARCHAR(200) NOT NULL DEFAULT '',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hasMTI = $pdo->query("SELECT COUNT(*) FROM insurance_companies WHERE company_code = 'MTI'")->fetchColumn();
    if (!$hasMTI) {
        $pdo->exec("INSERT IGNORE INTO insurance_companies (company_code, company_name) VALUES ('MTI', 'เมืองไทยประกันภัย')");
    }
} catch (Exception $e) {
    error_log('insurance_companies bootstrap: ' . $e->getMessage());
}

// Visibility scope: registry-only sees own batches; clinic/super sees all
function batch_scope_where(bool $hasInsurance, int $adminId): array
{
    if ($hasInsurance) return ['', []];
    return [' AND b.uploaded_by = :scope_uid', [':scope_uid' => $adminId]];
}

try {
    switch ($action) {
        // ─────── List ───────
        case 'list': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $statusF = trim((string)($_GET['status'] ?? ''));
            $companyF = trim((string)($_GET['company'] ?? ''));
            $search  = trim((string)($_GET['q'] ?? ''));

            [$scopeWhere, $scopeParams] = batch_scope_where($hasInsurance, $adminId);

            $where = ['1=1'];
            $params = $scopeParams;
            if ($statusF !== '')  { $where[] = 'b.status = :st'; $params[':st'] = $statusF; }
            if ($companyF !== '') { $where[] = 'b.insurance_company = :cc'; $params[':cc'] = $companyF; }
            if ($search !== '')   {
                $where[] = '(b.batch_code LIKE :q OR b.uploaded_by_name LIKE :q OR b.notes LIKE :q)';
                $params[':q'] = '%' . $search . '%';
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where) . $scopeWhere;

            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_batch b $whereSql");
            $totalStmt->execute($params);
            $total = (int)$totalStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            $listStmt = $pdo->prepare("
                SELECT b.*,
                       c.company_name
                FROM insurance_batch b
                LEFT JOIN insurance_companies c ON c.company_code = b.insurance_company
                $whereSql
                ORDER BY b.id DESC
                LIMIT $perPage OFFSET $offset
            ");
            $listStmt->execute($params);
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            // Recalc only batches in transitional states where counts can change.
            // Terminal states (completed, cancelled, rejected) and pre-approval
            // states (uploaded, pending_review) are skipped to avoid N+1 cost.
            $activeStates = ['approved', 'downloaded', 'in_progress', 'partial'];
            $recalcIds = [];
            foreach ($rows as $r) {
                if (in_array($r['status'], $activeStates, true)) {
                    $recalcIds[] = (int)$r['id'];
                }
            }
            if ($recalcIds) {
                foreach ($recalcIds as $rid) {
                    ins_batch_recalc($pdo, $rid);
                }
                $in = implode(',', array_map('intval', array_column($rows, 'id')));
                $rows = $pdo->query("
                    SELECT b.*, c.company_name
                    FROM insurance_batch b
                    LEFT JOIN insurance_companies c ON c.company_code = b.insurance_company
                    WHERE b.id IN ($in)
                    ORDER BY b.id DESC
                ")->fetchAll(PDO::FETCH_ASSOC);
            }

            json_ok([
                'data' => $rows,
                'pagination' => [
                    'page' => $page, 'per_page' => $perPage,
                    'total' => $total, 'total_pages' => $totalPages,
                ],
                'scope' => $hasInsurance ? 'all' : 'self',
                'can_approve' => $hasInsurance,
            ]);
            break;
        }

        // ─────── Detail ───────
        case 'detail': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_err('invalid id', 400);

            $b = $pdo->prepare("
                SELECT b.*, c.company_name
                FROM insurance_batch b
                LEFT JOIN insurance_companies c ON c.company_code = b.insurance_company
                WHERE b.id = :id
            ");
            $b->execute([':id' => $id]);
            $batch = $b->fetch(PDO::FETCH_ASSOC);
            if (!$batch) json_err('not found', 404);

            // Scope check
            if (!$hasInsurance && (int)$batch['uploaded_by'] !== $adminId) {
                json_err('ไม่มีสิทธิ์ดู batch นี้', 403);
            }

            // Recalc to ensure stats fresh
            ins_batch_recalc($pdo, $id);
            $b->execute([':id' => $id]);
            $batch = $b->fetch(PDO::FETCH_ASSOC);

            // Events timeline
            $eventsStmt = $pdo->prepare("
                SELECT * FROM insurance_batch_event
                WHERE batch_id = :bid
                ORDER BY id DESC
                LIMIT 100
            ");
            $eventsStmt->execute([':bid' => $id]);
            $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Members (paginated separately via 'members' action)
            $memCnt = $pdo->prepare("
                SELECT COUNT(DISTINCT member_id) FROM insurance_member_history WHERE sync_id = :sid
            ");
            $memCnt->execute([':sid' => (int)$batch['sync_id']]);

            json_ok([
                'batch'      => $batch,
                'events'     => $events,
                'member_count' => (int)$memCnt->fetchColumn(),
                'can_approve' => $hasInsurance,
                'is_owner'    => (int)$batch['uploaded_by'] === $adminId,
            ]);
            break;
        }

        // ─────── Members in batch (paginated 20/page) ───────
        case 'members': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_err('invalid id', 400);

            $b = $pdo->prepare("SELECT * FROM insurance_batch WHERE id = :id");
            $b->execute([':id' => $id]);
            $batch = $b->fetch(PDO::FETCH_ASSOC);
            if (!$batch) json_err('not found', 404);

            if (!$hasInsurance && (int)$batch['uploaded_by'] !== $adminId) {
                json_err('ไม่มีสิทธิ์', 403);
            }

            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $sid = (int)$batch['sync_id'];

            $totalStmt = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM insurance_member_history WHERE sync_id = :sid");
            $totalStmt->execute([':sid' => $sid]);
            $total = (int)$totalStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT m.member_id, m.full_name, m.member_status, m.position,
                       m.insurance_status, m.policy_number,
                       m.coverage_start, m.coverage_end,
                       h.change_type
                FROM (
                    SELECT DISTINCT member_id, MIN(change_type) AS change_type
                    FROM insurance_member_history
                    WHERE sync_id = :sid
                    GROUP BY member_id
                ) h
                LEFT JOIN insurance_members m ON m.member_id = h.member_id
                ORDER BY m.full_name
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute([':sid' => $sid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok([
                'data' => $rows,
                'pagination' => [
                    'page' => $page, 'per_page' => $perPage,
                    'total' => $total, 'total_pages' => $totalPages,
                ],
            ]);
            break;
        }

        // ─────── Approve ───────
        case 'approve': {
            if (!$hasInsurance) json_err('เฉพาะเจ้าหน้าที่คลินิกเท่านั้น', 403);
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($id <= 0) json_err('invalid id', 400);

            $b = $pdo->prepare("SELECT status FROM insurance_batch WHERE id = :id");
            $b->execute([':id' => $id]);
            $cur = $b->fetch(PDO::FETCH_ASSOC);
            if (!$cur) json_err('not found', 404);
            if (!in_array($cur['status'], ['pending_review', 'uploaded', 'rejected'], true)) {
                json_err('ไม่สามารถอนุมัติ batch ในสถานะนี้ (' . $cur['status'] . ')', 409);
            }

            $pdo->prepare("
                UPDATE insurance_batch
                SET status = 'approved',
                    reviewed_by = :uid,
                    reviewed_by_name = :un,
                    reviewed_at = NOW(),
                    review_note = :nt
                WHERE id = :id
            ")->execute([
                ':uid' => $adminId, ':un' => $adminName, ':nt' => $note, ':id' => $id,
            ]);

            ins_batch_log_event($pdo, $id, 'approved', $cur['status'], 'approved',
                'staff', $adminId, $adminName, $note);
            log_activity('insurance_batch_approve', "batch_id={$id}");
            json_ok(['status' => 'approved']);
            break;
        }

        // ─────── Reject ───────
        case 'reject': {
            if (!$hasInsurance) json_err('เฉพาะเจ้าหน้าที่คลินิกเท่านั้น', 403);
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($id <= 0) json_err('invalid id', 400);
            if ($note === '') json_err('กรุณาระบุเหตุผลการตีกลับ', 400);

            $b = $pdo->prepare("SELECT status FROM insurance_batch WHERE id = :id");
            $b->execute([':id' => $id]);
            $cur = $b->fetch(PDO::FETCH_ASSOC);
            if (!$cur) json_err('not found', 404);
            if (!in_array($cur['status'], ['pending_review', 'uploaded', 'approved'], true)) {
                json_err('ไม่สามารถตีกลับ batch ในสถานะนี้ (' . $cur['status'] . ')', 409);
            }

            $pdo->prepare("
                UPDATE insurance_batch
                SET status = 'rejected',
                    reviewed_by = :uid,
                    reviewed_by_name = :un,
                    reviewed_at = NOW(),
                    review_note = :nt
                WHERE id = :id
            ")->execute([
                ':uid' => $adminId, ':un' => $adminName, ':nt' => $note, ':id' => $id,
            ]);

            ins_batch_log_event($pdo, $id, 'rejected', $cur['status'], 'rejected',
                'staff', $adminId, $adminName, $note);
            log_activity('insurance_batch_reject', "batch_id={$id}, reason={$note}");
            json_ok(['status' => 'rejected']);
            break;
        }

        // ─────── Add note (any role with access) ───────
        case 'add_note': {
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($id <= 0 || $note === '') json_err('invalid input', 400);

            $b = $pdo->prepare("SELECT uploaded_by, status FROM insurance_batch WHERE id = :id");
            $b->execute([':id' => $id]);
            $cur = $b->fetch(PDO::FETCH_ASSOC);
            if (!$cur) json_err('not found', 404);

            if (!$hasInsurance && (int)$cur['uploaded_by'] !== $adminId) {
                json_err('ไม่มีสิทธิ์', 403);
            }

            ins_batch_log_event($pdo, $id, 'note_added', $cur['status'], $cur['status'],
                'staff', $adminId, $adminName, $note);
            json_ok(['ok' => true]);
            break;
        }

        // ─────── Stats summary (for cards on top) ───────
        case 'stats': {
            [$scopeWhere, $scopeParams] = batch_scope_where($hasInsurance, $adminId);
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) AS cnt
                FROM insurance_batch b
                WHERE 1=1 $scopeWhere
                GROUP BY status
            ");
            $stmt->execute($scopeParams);
            $bystatus = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $bystatus[$r['status']] = (int)$r['cnt'];
            json_ok(['by_status' => $bystatus]);
            break;
        }

        // ─────── Diagnostic — for debugging mismatch between stats and list ───────
        case 'diag': {
            $sessionInfo = [
                'admin_role'       => $adminRole,
                'admin_id'         => $adminId,
                'admin_name'       => $adminName,
                'isSuper'          => $isSuper,
                'hasInsurance'     => $hasInsurance,
                'hasRegistry'      => $hasRegistry,
                'session_keys'     => array_keys($_SESSION),
            ];

            $allBatches = $pdo->query("
                SELECT id, batch_code, status, uploaded_by, uploaded_by_name,
                       insurance_company, sync_id, uploaded_at
                FROM insurance_batch
                ORDER BY id DESC LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            $rawCount = (int)$pdo->query("SELECT COUNT(*) FROM insurance_batch")->fetchColumn();
            $byStatusRaw = $pdo->query("
                SELECT status, COUNT(*) AS cnt FROM insurance_batch GROUP BY status
            ")->fetchAll(PDO::FETCH_ASSOC);

            [$scopeWhere, $scopeParams] = batch_scope_where($hasInsurance, $adminId);
            $listSql = "SELECT b.*, c.company_name FROM insurance_batch b
                        LEFT JOIN insurance_companies c ON c.company_code = b.insurance_company
                        WHERE 1=1 AND b.status = 'pending_review' $scopeWhere
                        ORDER BY b.id DESC LIMIT 20";
            $listResult = $pdo->prepare($listSql);
            $listResult->execute($scopeParams);
            $listRows = $listResult->fetchAll(PDO::FETCH_ASSOC);

            $companiesExists = false;
            try {
                $pdo->query("SELECT 1 FROM insurance_companies LIMIT 1");
                $companiesExists = true;
            } catch (Exception $e) { /* table missing */ }

            json_ok([
                'session'           => $sessionInfo,
                'insurance_batch_total' => $rawCount,
                'by_status_raw'     => $byStatusRaw,
                'all_batches_sample'=> $allBatches,
                'list_query'        => $listSql,
                'list_params'       => $scopeParams,
                'list_result_count' => count($listRows),
                'list_rows'         => $listRows,
                'insurance_companies_table_exists' => $companiesExists,
            ]);
            break;
        }

        default:
            json_err('unknown action', 400);
    }
} catch (Exception $e) {
    error_log('ajax_insurance_batches: ' . $e->getMessage());
    json_err('server error', 500);
}
