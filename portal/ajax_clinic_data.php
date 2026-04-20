<?php
// portal/ajax_clinic_data.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

validate_csrf_or_die();

$pdo = db();

// ── Ensure table exists ──────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sys_faculties (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            code       VARCHAR(50)  NULL,
            name_th    VARCHAR(255) NOT NULL,
            name_en    VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_name_th (name_th)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถสร้างตารางได้: ' . $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// ADD one row
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $code    = trim($_POST['code']    ?? '') ?: null;
    $nameTh  = trim($_POST['name_th'] ?? '');
    $nameEn  = trim($_POST['name_en'] ?? '') ?: null;

    if ($nameTh === '') {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกชื่อ (ภาษาไทย)']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO sys_faculties (code, name_th, name_en)
            VALUES (:code, :name_th, :name_en)
        ");
        $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn]);
        log_activity('clinic_data', "เพิ่มคณะ/หน่วยงาน: {$nameTh}");
        echo json_encode(['status' => 'ok', 'message' => 'เพิ่มข้อมูลเรียบร้อย', 'id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) {
            echo json_encode(['status' => 'error', 'message' => 'มีชื่อนี้อยู่ในระบบแล้ว']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'เพิ่มไม่สำเร็จ: ' . $e->getMessage()]);
        }
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// UPDATE one row
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id      = (int)($_POST['id'] ?? 0);
    $code    = trim($_POST['code']    ?? '') ?: null;
    $nameTh  = trim($_POST['name_th'] ?? '');
    $nameEn  = trim($_POST['name_en'] ?? '') ?: null;

    if ($id <= 0 || $nameTh === '') {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE sys_faculties
            SET code = :code, name_th = :name_th, name_en = :name_en
            WHERE id = :id
        ");
        $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':id' => $id]);
        log_activity('clinic_data', "แก้ไขคณะ/หน่วยงาน #{$id}: {$nameTh}");
        echo json_encode(['status' => 'ok', 'message' => 'บันทึกเรียบร้อย']);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) {
            echo json_encode(['status' => 'error', 'message' => 'มีชื่อนี้อยู่ในระบบแล้ว']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()]);
        }
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE one row
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID ไม่ถูกต้อง']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM sys_faculties WHERE id = :id");
        $stmt->execute([':id' => $id]);
        log_activity('clinic_data', "ลบคณะ/หน่วยงาน #{$id}");
        echo json_encode(['status' => 'ok', 'message' => 'ลบเรียบร้อย']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'ลบไม่สำเร็จ: ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CLEAR all
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clear_all') {
    try {
        $pdo->exec("DELETE FROM sys_faculties");
        log_activity('clinic_data', 'ลบข้อมูลคณะ/หน่วยงานทั้งหมด');
        echo json_encode(['status' => 'ok', 'message' => 'ลบข้อมูลทั้งหมดเรียบร้อย']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// IMPORT from Excel / CSV
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'import') {
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['import_file']['error'] ?? -1;
        echo json_encode(['status' => 'error', 'message' => "ไม่พบไฟล์หรืออัพโหลดล้มเหลว (code: $errCode)"]);
        exit;
    }

    $file     = $_FILES['import_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpPath  = $file['tmp_name'];
    $maxBytes = 5 * 1024 * 1024;

    if ($file['size'] > $maxBytes) {
        echo json_encode(['status' => 'error', 'message' => 'ไฟล์ใหญ่เกิน 5 MB']);
        exit;
    }
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'รองรับเฉพาะไฟล์ .xlsx และ .csv เท่านั้น']);
        exit;
    }

    $rows = $ext === 'xlsx' ? parseXlsx($tmpPath) : parseCsv($tmpPath);

    if (empty($rows)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลในไฟล์']);
        exit;
    }

    // Skip header row
    $firstCell = strtolower(trim($rows[0][0] ?? ''));
    if (in_array($firstCell, ['code', 'รหัส', 'name', 'ชื่อ', 'faculty', 'คณะ', 'department', 'หน่วยงาน', 'no', 'ลำดับ'], true)) {
        array_shift($rows);
    }

    $stmt = $pdo->prepare("
        INSERT INTO sys_faculties (code, name_th, name_en)
        VALUES (:code, :name_th, :name_en)
        ON DUPLICATE KEY UPDATE code = VALUES(code), name_en = VALUES(name_en), updated_at = CURRENT_TIMESTAMP
    ");

    $inserted = 0; $skipped = 0; $errors = [];
    foreach ($rows as $i => $row) {
        $cols = count($row);
        if ($cols === 0) { $skipped++; continue; }

        if ($cols === 1) {
            $code = null; $nameTh = trim($row[0]); $nameEn = null;
        } elseif ($cols === 2) {
            $isCode = strlen(trim($row[0])) <= 20 && !preg_match('/[\x{0E00}-\x{0E7F}]/u', $row[0]);
            $code   = $isCode ? (trim($row[0]) ?: null) : null;
            $nameTh = $isCode ? trim($row[1]) : trim($row[0]);
            $nameEn = $isCode ? null : trim($row[1]);
        } else {
            $code = trim($row[0]) ?: null;
            $nameTh = trim($row[1]);
            $nameEn = trim($row[2]) ?: null;
        }

        if ($nameTh === '') { $skipped++; continue; }

        try {
            $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++;
            if (count($errors) < 5) $errors[] = "แถว " . ($i + 1) . ": " . $e->getMessage();
        }
    }

    log_activity('clinic_data', "นำเข้าคณะ/หน่วยงาน {$inserted} รายการ จากไฟล์ " . htmlspecialchars($file['name']));

    echo json_encode([
        'status'   => 'ok',
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'message'  => "นำเข้าสำเร็จ {$inserted} รายการ" . ($skipped > 0 ? " (ข้ามไป {$skipped})" : ''),
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
function parseXlsx(string $filePath): array
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return [];

    $sharedStrings = [];
    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) $text .= (string)$r->t;
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $rows = [];
    if (($wsXml = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
        $ws = simplexml_load_string($wsXml);
        if ($ws) {
            foreach ($ws->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $cell) {
                    $type = (string)$cell['t'];
                    $val  = (string)$cell->v;
                    if ($type === 's')              $rowData[] = $sharedStrings[(int)$val] ?? '';
                    elseif ($type === 'inlineStr')  $rowData[] = (string)$cell->is->t;
                    else                            $rowData[] = $val;
                }
                if (array_filter($rowData, fn($v) => trim($v) !== '')) $rows[] = $rowData;
            }
        }
    }

    $zip->close();
    return $rows;
}

function parseCsv(string $filePath): array
{
    $rows = [];
    $fh = fopen($filePath, 'r');
    if ($fh === false) return [];
    while (($row = fgetcsv($fh)) !== false) {
        if (array_filter($row, fn($v) => trim($v) !== '')) $rows[] = array_map('trim', $row);
    }
    fclose($fh);
    return $rows;
}
