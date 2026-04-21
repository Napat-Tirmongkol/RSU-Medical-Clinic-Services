<?php
/**
 * portal/actions/portal_handlers.php
 * จัดการ POST Requests และการ Export ข้อมูลต่างๆ ของ Portal
 */
declare(strict_types=1);

// ป้องกันการเข้าถึงไฟล์นี้โดยตรง (ทางเลือก)
if (!isset($pdo)) {
    die('Direct access not permitted');
}

// ── 1. POST handlers for embedded section partials ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sectionAction = $_POST['action'] ?? '';

    // Error Logs actions
    if (in_array($sectionAction, ['save_alert_email', 'clear', 'delete_one'], true)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_settings (`key` VARCHAR(100) NOT NULL PRIMARY KEY, `value` TEXT NOT NULL DEFAULT '', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            if ($sectionAction === 'save_alert_email') {
                $emailVal = trim($_POST['alert_email'] ?? '');
                if ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
                    header('Location: index.php?section=error_logs&email_error=1');
                } else {
                    $pdo->prepare("INSERT INTO sys_settings (`key`,`value`) VALUES ('admin_alert_email',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$emailVal]);
                    header('Location: index.php?section=error_logs&saved=1');
                }
                exit;
            } elseif ($sectionAction === 'clear') {
                $cl = $_POST['clear_level'] ?? 'all';
                if ($cl === 'all') { $pdo->exec("TRUNCATE TABLE sys_error_logs"); }
                else { $pdo->prepare("DELETE FROM sys_error_logs WHERE level=?")->execute([$cl]); }
                header('Location: index.php?section=error_logs&cleared=1'); 
                exit;
            } elseif ($sectionAction === 'delete_one') {
                $lid = (int)($_POST['log_id'] ?? 0);
                if ($lid > 0) $pdo->prepare("DELETE FROM sys_error_logs WHERE id=?")->execute([$lid]);
                header('Location: index.php?section=error_logs'); 
                exit;
            }
        } catch (PDOException $e) {
            // Log error if needed
        }
    }
}

// ── 2. Export handlers for error_logs ────────────────────────────────────────
if ($activeSection === 'error_logs' && isset($_GET['export'])) {
    $expFmt    = $_GET['export'];
    $expSearch = trim($_GET['el_search'] ?? '');
    $expLevel  = $_GET['el_level']  ?? '';
    $expDate   = $_GET['el_date']   ?? '';
    
    $expWhere  = 'WHERE 1=1'; 
    $expParams = [];
    
    if ($expSearch !== '') { 
        $expWhere .= ' AND (message LIKE ? OR source LIKE ?)'; 
        $expParams[] = "%$expSearch%"; 
        $expParams[] = "%$expSearch%"; 
    }
    if (in_array($expLevel, ['error','warning','info'], true)) { 
        $expWhere .= ' AND level=?'; 
        $expParams[] = $expLevel; 
    }
    if ($expDate !== '') { 
        $expWhere .= ' AND DATE(created_at)=?'; 
        $expParams[] = $expDate; 
    }

    try {
        $expStmt = $pdo->prepare("SELECT id,level,source,message,context,ip_address,user_id,created_at FROM sys_error_logs $expWhere ORDER BY created_at DESC LIMIT 10000");
        $expStmt->execute($expParams);
        $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);
        $expFile = 'error_logs_' . date('Ymd_His');

        if ($expFmt === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename={$expFile}.csv");
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['ID','Level','Source','Message','Context','IP','UserID','Created']);
            foreach ($expRows as $r) fputcsv($out, [$r['id'],$r['level'],$r['source'],$r['message'],$r['context'],$r['ip_address'],$r['user_id'],$r['created_at']]);
            fclose($out); 
            exit;
        }

        if ($expFmt === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename={$expFile}.json");
            echo json_encode(['exported_at'=>date('Y-m-d H:i:s'),'total'=>count($expRows),'logs'=>$expRows], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (PDOException $e) {
        die('Export failed');
    }
}
