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

    // Staff profile update — แก้ชื่อ/รหัสผ่านของ staff ที่ login อยู่ (sys_staff)
    if ($sectionAction === 'update_profile' && !empty($_SESSION['is_ecampaign_staff']) && !empty($_SESSION['admin_id'])) {
        validate_csrf_or_die();

        $fullName        = trim($_POST['full_name']        ?? '');
        $newPassword     = $_POST['new_password']          ?? '';
        $confirmPassword = $_POST['confirm_password']      ?? '';
        $staffId         = (int) $_SESSION['admin_id'];

        if ($fullName === '') {
            $_SESSION['profile_flash'] = ['type' => 'error', 'msg' => 'กรุณากรอกชื่อ-นามสกุล'];
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $_SESSION['profile_flash'] = ['type' => 'error', 'msg' => 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน'];
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            $_SESSION['profile_flash'] = ['type' => 'error', 'msg' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร'];
        } else {
            try {
                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE sys_staff SET full_name = :fname, password_hash = :pwd WHERE id = :id")
                        ->execute([':fname' => $fullName, ':pwd' => $hash, ':id' => $staffId]);
                } else {
                    $pdo->prepare("UPDATE sys_staff SET full_name = :fname WHERE id = :id")
                        ->execute([':fname' => $fullName, ':id' => $staffId]);
                }
                $_SESSION['admin_username'] = $fullName;
                $_SESSION['full_name']      = $fullName;
                $_SESSION['profile_flash']  = ['type' => 'success', 'msg' => 'อัปเดตข้อมูลโปรไฟล์เรียบร้อยแล้ว'];
                if (function_exists('log_activity')) {
                    log_activity('profile_update', "staff อัปเดตโปรไฟล์ของตนเอง", $staffId);
                }
            } catch (PDOException $e) {
                $_SESSION['profile_flash'] = ['type' => 'error', 'msg' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล'];
            }
        }

        // PRG redirect ป้องกัน double-submit เวลา refresh
        header('Location: profile.php');
        exit;
    }

    // Error Logs actions
    if (in_array($sectionAction, ['save_alert_email', 'clear', 'delete_one', 'update_status'], true)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_settings (`key` VARCHAR(100) NOT NULL PRIMARY KEY, `value` TEXT NOT NULL DEFAULT '', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Ensure sys_error_logs has status and resolve_comment
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM sys_error_logs LIKE 'status'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE sys_error_logs ADD COLUMN status ENUM('New', 'Active', 'Resolved') NOT NULL DEFAULT 'New' AFTER notified_at");
                    $pdo->exec("ALTER TABLE sys_error_logs ADD COLUMN resolve_comment TEXT NULL AFTER status");
                    $pdo->exec("CREATE INDEX idx_status ON sys_error_logs(status)");
                }
            } catch (PDOException $e) {}

            if ($sectionAction === 'save_alert_email') {
                $emailVal = trim($_POST['alert_email'] ?? '');
                $isValid = true;
                if ($emailVal !== '') {
                    $emails = array_map('trim', explode(',', $emailVal));
                    foreach ($emails as $e) {
                        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                            $isValid = false; break;
                        }
                    }
                }
                
                if (!$isValid) {
                    header('Location: error_logs.php?email_error=1');
                } else {
                    $pdo->prepare("INSERT INTO sys_settings (`key`,`value`) VALUES ('admin_alert_email',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$emailVal]);
                    header('Location: error_logs.php?saved=1');
                }
                exit;
            } elseif ($sectionAction === 'clear') {
                $cl = $_POST['clear_level'] ?? 'all';
                if ($cl === 'all') { $pdo->exec("TRUNCATE TABLE sys_error_logs"); }
                else { $pdo->prepare("DELETE FROM sys_error_logs WHERE level=?")->execute([$cl]); }
                header('Location: error_logs.php?cleared=1'); 
                exit;
            } elseif ($sectionAction === 'delete_one') {
                $lid = (int)($_POST['log_id'] ?? 0);
                if ($lid > 0) $pdo->prepare("DELETE FROM sys_error_logs WHERE id=?")->execute([$lid]);
                header('Location: error_logs.php'); 
                exit;
            } elseif ($sectionAction === 'update_status') {
                $lid = (int)($_POST['log_id'] ?? 0);
                $status = $_POST['status'] ?? 'New';
                $comment = $_POST['resolve_comment'] ?? '';
                if ($lid > 0 && in_array($status, ['New', 'Active', 'Resolved'], true)) {
                    $pdo->prepare("UPDATE sys_error_logs SET status=?, resolve_comment=? WHERE id=?")->execute([$status, $comment, $lid]);
                }
                header('Location: error_logs.php?updated=1');
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
