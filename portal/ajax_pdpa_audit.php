<?php
// portal/ajax_pdpa_audit.php — PDPA Consent Audit (read-only)
// Actions:
//   stats   GET  → KPI numbers + version distribution
//   list    GET  → paginated table (filters: q, version, status)
//   detail  GET  → one user's full consent record + reconstructed text snippet
//   export  GET  → CSV download of the current filter
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper   = ($adminRole === 'superadmin');
$canAudit  = $isSuper || !empty($_SESSION['access_identity']);
if (!$canAudit) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'ต้องมีสิทธิ์ access_identity หรือ role: superadmin']);
    exit;
}

$pdo    = db();
$action = (string)($_GET['action'] ?? '');

// Reasonable per-page cap so a hostile URL can't ask for millions of rows
function pa_clamp_int($v, int $lo, int $hi, int $default): int {
    $n = is_numeric($v) ? (int)$v : $default;
    return max($lo, min($hi, $n));
}

// Build the WHERE clause + bindings shared by list and export.
// Keeps the two paths from drifting out of sync.
function pa_build_filter(array $query): array {
    $where  = [];
    $bind   = [];

    $q = trim((string)($query['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(u.full_name LIKE :q OR u.citizen_id LIKE :q OR u.line_user_id LIKE :q OR u.consent_ip LIKE :q)';
        $bind[':q'] = '%' . $q . '%';
    }

    $version = trim((string)($query['version'] ?? ''));
    // version tag follows the strict pdpa_vN_YYYY-MM regex — anything else is rejected
    // so an attacker can't smuggle SQL through the version filter
    if ($version !== '' && preg_match('/^pdpa_v\d+_\d{4}-\d{2}$/', $version)) {
        $where[] = '(u.consent_general_version = :ver OR u.consent_sensitive_version = :ver)';
        $bind[':ver'] = $version;
    }

    $status = (string)($query['status'] ?? '');
    switch ($status) {
        case 'full':
            $where[] = 'u.consent_general_accepted_at IS NOT NULL AND u.consent_sensitive_accepted_at IS NOT NULL';
            break;
        case 'partial':
            // One side consented but not the other — should not exist in correct flow
            $where[] = '((u.consent_general_accepted_at IS NULL) <> (u.consent_sensitive_accepted_at IS NULL))';
            break;
        case 'legacy':
            $where[] = 'u.consent_general_accepted_at IS NULL AND u.consent_sensitive_accepted_at IS NULL';
            break;
        case 'general_only':
            $where[] = 'u.consent_general_accepted_at IS NOT NULL AND u.consent_sensitive_accepted_at IS NULL';
            break;
    }

    $sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    return [$sql, $bind];
}

if ($action === 'stats') {
    try {
        // Touch the table first to make sure schema exists — same self-heal as save_profile
        $stats = [
            'total'        => (int)$pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn(),
            'full'         => (int)$pdo->query("SELECT COUNT(*) FROM sys_users WHERE consent_general_accepted_at IS NOT NULL AND consent_sensitive_accepted_at IS NOT NULL")->fetchColumn(),
            'partial'      => (int)$pdo->query("SELECT COUNT(*) FROM sys_users WHERE (consent_general_accepted_at IS NULL) <> (consent_sensitive_accepted_at IS NULL)")->fetchColumn(),
            'legacy'       => (int)$pdo->query("SELECT COUNT(*) FROM sys_users WHERE consent_general_accepted_at IS NULL AND consent_sensitive_accepted_at IS NULL")->fetchColumn(),
            'general_only' => (int)$pdo->query("SELECT COUNT(*) FROM sys_users WHERE consent_general_accepted_at IS NOT NULL AND consent_sensitive_accepted_at IS NULL")->fetchColumn(),
        ];
        // version distribution (general consent)
        $versions = $pdo->query("SELECT consent_general_version AS v, COUNT(*) AS n
                                 FROM sys_users
                                 WHERE consent_general_version IS NOT NULL
                                 GROUP BY consent_general_version
                                 ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
        // last 30-day consent rate
        $recent = $pdo->query("SELECT DATE(consent_general_accepted_at) AS d, COUNT(*) AS n
                               FROM sys_users
                               WHERE consent_general_accepted_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                               GROUP BY DATE(consent_general_accepted_at)
                               ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'       => true,
            'stats'    => $stats,
            'versions' => $versions,
            'recent'   => $recent,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[pdpa_audit] stats: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'ดึงสถิติไม่สำเร็จ: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'list') {
    try {
        $page    = pa_clamp_int($_GET['page'] ?? 1, 1, 100000, 1);
        $perPage = pa_clamp_int($_GET['per_page'] ?? 20, 5, 100, 20);
        $offset  = ($page - 1) * $perPage;

        [$whereSql, $bind] = pa_build_filter($_GET);

        $countSql = "SELECT COUNT(*) FROM sys_users u {$whereSql}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($bind);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT u.id, u.full_name, u.citizen_id, u.line_user_id,
                       u.consent_general_accepted_at,   u.consent_general_version,   u.consent_general_text_hash,
                       u.consent_sensitive_accepted_at, u.consent_sensitive_version, u.consent_sensitive_text_hash,
                       u.consent_ip, u.consent_user_agent
                FROM sys_users u
                {$whereSql}
                ORDER BY COALESCE(u.consent_general_accepted_at, '1970-01-01') DESC, u.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mask sensitive PII for table view — last 4 of citizen_id only,
        // first/last 4 of hash; full values reserved for the detail modal
        foreach ($rows as &$r) {
            $cid = (string)($r['citizen_id'] ?? '');
            $r['citizen_id_masked'] = $cid === '' ? '' : (str_repeat('•', max(0, strlen($cid) - 4)) . substr($cid, -4));
            unset($r['citizen_id']);
            $r['general_hash_short']   = $r['consent_general_text_hash']   ? substr($r['consent_general_text_hash'], 0, 12)   . '…' : null;
            $r['sensitive_hash_short'] = $r['consent_sensitive_text_hash'] ? substr($r['consent_sensitive_text_hash'], 0, 12) . '…' : null;
            unset($r['consent_general_text_hash'], $r['consent_sensitive_text_hash']);
            // Truncate UA so the table doesn't get blown out
            if (!empty($r['consent_user_agent'])) {
                $r['consent_user_agent_short'] = mb_substr((string)$r['consent_user_agent'], 0, 60);
                unset($r['consent_user_agent']);
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'         => true,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'page_count' => max(1, (int)ceil($total / $perPage)),
            'rows'       => $rows,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[pdpa_audit] list: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'ดึงรายการไม่สำเร็จ: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'detail') {
    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) throw new Exception('id ไม่ถูกต้อง');

        $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, line_user_id, status, email, phone_number,
                                      consent_general_accepted_at, consent_general_version, consent_general_text_hash,
                                      consent_sensitive_accepted_at, consent_sensitive_version, consent_sensitive_text_hash,
                                      consent_ip, consent_user_agent
                               FROM sys_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('ไม่พบผู้ใช้');

        // Re-compute the hash of the version tag to confirm DB record hasn't
        // been tampered with — should always match unless someone hand-edited
        $row['general_hash_verifies']   = $row['consent_general_version']   && $row['consent_general_text_hash']   === hash('sha256', (string)$row['consent_general_version']);
        $row['sensitive_hash_verifies'] = $row['consent_sensitive_version'] && $row['consent_sensitive_text_hash'] === hash('sha256', (string)$row['consent_sensitive_version']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'export') {
    try {
        [$whereSql, $bind] = pa_build_filter($_GET);
        $sql = "SELECT u.id, u.full_name, u.citizen_id, u.line_user_id, u.status, u.email,
                       u.consent_general_accepted_at,   u.consent_general_version,   u.consent_general_text_hash,
                       u.consent_sensitive_accepted_at, u.consent_sensitive_version, u.consent_sensitive_text_hash,
                       u.consent_ip, u.consent_user_agent
                FROM sys_users u
                {$whereSql}
                ORDER BY COALESCE(u.consent_general_accepted_at, '1970-01-01') DESC, u.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        $filename = 'pdpa_audit_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens Thai text correctly
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ID', 'ชื่อ-นามสกุล', 'เลขบัตร ปชช.', 'LINE User ID', 'สถานะ', 'อีเมล',
            'ยอมรับทั่วไป (เวลา)', 'เวอร์ชัน', 'Hash',
            'ยอมรับอ่อนไหว (เวลา)', 'เวอร์ชัน', 'Hash',
            'IP', 'User-Agent',
        ]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['id'],
                $r['full_name'],
                $r['citizen_id'],
                $r['line_user_id'],
                $r['status'],
                $r['email'],
                $r['consent_general_accepted_at'],
                $r['consent_general_version'],
                $r['consent_general_text_hash'],
                $r['consent_sensitive_accepted_at'],
                $r['consent_sensitive_version'],
                $r['consent_sensitive_text_hash'],
                $r['consent_ip'],
                $r['consent_user_agent'],
            ]);
        }
        fclose($out);
        exit;
    } catch (Throwable $e) {
        error_log('[pdpa_audit] export: ' . $e->getMessage());
        http_response_code(500);
        echo 'Export failed: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'unknown action']);
