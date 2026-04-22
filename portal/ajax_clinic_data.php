<?php
// portal/ajax_clinic_data.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['action'] ?? '') !== 'search') {
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
            type       ENUM('faculty','department') NOT NULL DEFAULT 'faculty',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_name_th (name_th)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Add column if doesn't exist (for existing tables)
    try {
        $pdo->exec("ALTER TABLE sys_faculties ADD COLUMN type ENUM('faculty','department') NOT NULL DEFAULT 'faculty'");
    } catch (PDOException) {}
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถสร้างตารางได้: ' . $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// SEARCH / LISTING (AJAX)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'search') {
    $search = trim($_GET['cd_search'] ?? '');
    $page   = max(1, (int)($_GET['cd_page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;
    
    $where  = 'WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $where .= ' AND (name_th LIKE ? OR name_en LIKE ? OR code LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    try {
        $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_faculties $where");
        $sc->execute($params);
        $total = (int)$sc->fetchColumn();
        $totalPages = (int)ceil($total / $limit);

        $sr = $pdo->prepare("SELECT id, code, name_th, name_en, type FROM sys_faculties $where ORDER BY type ASC, name_th ASC LIMIT $limit OFFSET $offset");
        $sr->execute($params);
        $rows = $sr->fetchAll(PDO::FETCH_ASSOC);

        // Generate Rows HTML
        $rowsHtml = '';
        if (empty($rows)) {
            $rowsHtml = '<tr><td colspan="5" class="py-20 text-center">
                <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <p class="text-slate-400 font-black text-sm uppercase tracking-widest">ไม่พบข้อมูลที่ค้นหา</p>
            </td></tr>';
        } else {
            foreach ($rows as $i => $r) {
                $isF = $r['type'] === 'faculty';
                $idx = $offset + $i + 1;
                $codeLabel = !empty($r['code']) ? '<span class="inline-block text-[10px] font-mono font-black px-2 py-0.5 rounded-md bg-slate-100 text-slate-600">'.htmlspecialchars($r['code']).'</span>' : '<span class="text-slate-200">—</span>';
                $enLabel = htmlspecialchars($r['name_en'] ?? '') ?: '— NO ENGLISH NAME —';
                $typeBadge = $isF ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600';
                $typeText  = $isF ? 'FACULTY' : 'DEPT';
                $rowJson = json_encode($r, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT);
                $nameThEnc = htmlspecialchars(json_encode($r['name_th'], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                $rowsHtml .= "
                <tr class='border-b border-slate-50 hover:bg-slate-50/50 transition-all group' data-id='{$r['id']}'>
                    <td class='py-4 px-6 text-[11px] font-bold text-slate-400'>{$idx}</td>
                    <td class='py-4 px-6'>
                        <span class='inline-block text-[10px] font-black px-2.5 py-1 rounded-lg {$typeBadge}'>{$typeText}</span>
                    </td>
                    <td class='py-4 px-6'>{$codeLabel}</td>
                    <td class='py-4 px-6'>
                        <div class='font-black text-slate-800 text-sm'>".htmlspecialchars($r['name_th'])."</div>
                        <div class='text-[10px] text-slate-400 font-bold uppercase tracking-tight'>{$enLabel}</div>
                    </td>
                    <td class='py-4 px-6 text-right'>
                        <div class='flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all'>
                            <button onclick='cdEditRow({$rowJson})' class='w-8 h-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-all'><i class='fa-solid fa-pen text-[10px]'></i></button>
                            <button onclick='cdDelete({$r['id']}, {$nameThEnc})' class='w-8 h-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-all'><i class='fa-solid fa-trash text-[10px]'></i></button>
                        </div>
                    </td>
                </tr>";
            }
        }

        // Generate Pagination HTML
        $pagiHtml = '';
        if ($totalPages > 1) {
            $prevPage = max(1, $page - 1);
            $nextPage = min($totalPages, $page + 1);
            
            $pagiHtml = "<p class='text-[11px] font-black text-slate-400 uppercase tracking-widest'>PAGE $page / $totalPages</p>";
            $pagiHtml .= "<div class='flex items-center gap-1.5'>";
            
            // First & Prev
            $pagiHtml .= "<button onclick='cdDoSearch(1)' class='w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all'><i class='fa-solid fa-angles-left text-[10px]'></i></button>";
            $pagiHtml .= "<button onclick='cdDoSearch($prevPage)' class='w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all'><i class='fa-solid fa-angle-left text-[10px]'></i></button>";

            $startP = max(1, $page - 2);
            $endP   = min($totalPages, $page + 2);
            for ($p = $startP; $p <= $endP; $p++) {
                $activeClass = $p === $page ? 'bg-slate-800 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100';
                $pagiHtml .= "<button onclick='cdDoSearch($p)' class='w-8 h-8 flex items-center justify-center rounded-lg text-xs font-black transition-all $activeClass'>$p</button>";
            }

            // Next & Last
            $pagiHtml .= "<button onclick='cdDoSearch($nextPage)' class='w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all'><i class='fa-solid fa-angle-right text-[10px]'></i></button>";
            $pagiHtml .= "<button onclick='cdDoSearch($totalPages)' class='w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all'><i class='fa-solid fa-angles-right text-[10px]'></i></button>";
            $pagiHtml .= "</div>";
        }

        echo json_encode([
            'status' => 'ok',
            'rows' => $rowsHtml,
            'pagi' => $pagiHtml,
            'total' => number_format($total)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
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
    $type    = in_array($_POST['type'] ?? 'faculty', ['faculty', 'department'], true) ? $_POST['type'] : 'faculty';

    if ($nameTh === '') {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกชื่อ (ภาษาไทย)']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO sys_faculties (code, name_th, name_en, type)
            VALUES (:code, :name_th, :name_en, :type)
        ");
        $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':type' => $type]);
        log_activity('clinic_data', "เพิ่ม{($type === 'faculty' ? 'คณะ' : 'หน่วยงาน')}: {$nameTh}");
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
    $type    = in_array($_POST['type'] ?? 'faculty', ['faculty', 'department'], true) ? $_POST['type'] : 'faculty';

    if ($id <= 0 || $nameTh === '') {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE sys_faculties
            SET code = :code, name_th = :name_th, name_en = :name_en, type = :type
            WHERE id = :id
        ");
        $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':type' => $type, ':id' => $id]);
        log_activity('clinic_data', "แก้ไข{($type === 'faculty' ? 'คณะ' : 'หน่วยงาน')} #{$id}: {$nameTh}");
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

    $file      = $_FILES['import_file'];
    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpPath   = $file['tmp_name'];
    $maxBytes  = 5 * 1024 * 1024;
    $impType   = in_array($_POST['import_type'] ?? 'faculty', ['faculty', 'department'], true) ? $_POST['import_type'] : 'faculty';

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
        INSERT INTO sys_faculties (code, name_th, name_en, type)
        VALUES (:code, :name_th, :name_en, :type)
        ON DUPLICATE KEY UPDATE code = VALUES(code), name_en = VALUES(name_en), type = VALUES(type), updated_at = CURRENT_TIMESTAMP
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
            $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':type' => $impType]);
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
