<?php
/**
 * portal/ajax_gold_card.php
 * AJAX endpoint สำหรับจัดการสมาชิกบัตรทอง + เอกสารแนบ
 *
 * Pattern: entity:action
 *   member:list, member:get, member:save, member:delete, member:stats
 *   document:upload, document:list, document:download, document:delete
 *   chart:trend, chart:by_status, chart:by_hospital
 *
 * Auth: ต้องมี access_insurance หรือ superadmin
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

$adminRole   = $_SESSION['admin_role'] ?? '';
$adminId     = (int)($_SESSION['admin_id'] ?? 0);
$isSuper     = $adminRole === 'superadmin';
$hasAccess   = $isSuper || !empty($_SESSION['access_insurance']);

if (!$hasAccess) {
    json_err('ไม่มีสิทธิ์เข้าถึงระบบบัตรทอง', 403);
}

// Allow GET for document:download (inline image preview in <img src>)
$entity = $_POST['entity'] ?? $_GET['entity'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isInlinePreview = ($entity === 'document' && $action === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET');

if (!$isInlinePreview) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_err('Method not allowed', 405);
    }
    if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
}

$pdo = db();

// ── Helper: file storage dir ──────────────────────────────────────────────────
function gold_card_uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads/gold_card';
}

function gold_card_save_uploaded_file(array $file, int $memberId): ?array
{
    $maxSize = 20 * 1024 * 1024; // 20MB
    $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx'];

    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] <= 0 || $file['size'] > $maxSize) return null;

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    $year   = date('Y');
    $month  = date('m');
    $base   = gold_card_uploads_dir();
    $subdir = $base . "/$year/$month";
    if (!is_dir($subdir)) {
        if (!@mkdir($subdir, 0755, true)) return null;
    }

    $hash = sha1_file($file['tmp_name']) ?: bin2hex(random_bytes(8));
    $stored = sprintf('%s/%s/mem%d_%s.%s', $year, $month, $memberId, substr($hash, 0, 16), $ext);
    $target = $base . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) return null;

    return [
        'file_name'   => $origName,
        'stored_path' => $stored,
        'mime_type'   => $file['type'] ?? null,
        'file_size'   => (int)$file['size'],
        'sha1_hash'   => $hash,
    ];
}

function gold_card_log_history(PDO $pdo, ?int $memberId, string $action, $oldVal, $newVal, int $changedBy): void
{
    try {
        $pdo->prepare("INSERT INTO gold_card_history
            (member_id, action, old_value, new_value, changed_by, ip_address)
            VALUES (?,?,?,?,?,?)")->execute([
            $memberId,
            $action,
            is_string($oldVal) ? $oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE),
            is_string($newVal) ? $newVal : json_encode($newVal, JSON_UNESCAPED_UNICODE),
            $changedBy ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) { /* silent */ }
}

// ── Auto-migrate guard ────────────────────────────────────────────────────────
try {
    $pdo->query("SELECT 1 FROM gold_card_members LIMIT 1");
} catch (PDOException $e) {
    json_err('ตาราง gold_card_members ยังไม่ถูกสร้าง — กรุณารัน migrate_gold_card_module.php ก่อน');
}

// Auto-add bulk import columns ถ้าไม่มี (self-healing)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM gold_card_members")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('linked_user_id', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members ADD COLUMN linked_user_id INT UNSIGNED NULL AFTER citizen_id, ADD INDEX idx_linked_user (linked_user_id)");
    }
    if (!in_array('source_filename', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members ADD COLUMN source_filename VARCHAR(500) NULL AFTER remarks");
    }
} catch (PDOException $e) { /* silent */ }

// ── Helper: ดึงชื่อจาก filename + match กับ sys_users ─────────────────────────
function gc_extract_name(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    // ลบเลขนำหน้า เช่น "63123456_นายสมชาย" หรือ "631234_..."
    $name = preg_replace('/^[\d_\-\s.]+/u', '', $name);
    // ลบ suffix ที่เป็นตัวเลข/วันที่ ที่ตามหลัง _ หรือ -
    $name = preg_replace('/[_\-\s]+\d+$/u', '', $name);
    return trim($name);
}

/**
 * Parse Thai month folder name → ['year' => int (CE), 'month' => 1-12] | null
 * Patterns supported:
 *   "1มค68", "1มค 68", "มค68", "5พค 2568", "5 พ.ค. 68"
 */
function gc_parse_thai_month_folder(string $folderName): ?array
{
    static $months = [
        'มกราคม'=>1,'มค'=>1,'ม.ค.'=>1,
        'กุมภาพันธ์'=>2,'กพ'=>2,'ก.พ.'=>2,
        'มีนาคม'=>3,'มีค'=>3,'มี.ค.'=>3,
        'เมษายน'=>4,'เมย'=>4,'เม.ย.'=>4,
        'พฤษภาคม'=>5,'พค'=>5,'พ.ค.'=>5,
        'มิถุนายน'=>6,'มิย'=>6,'มิ.ย.'=>6,
        'กรกฎาคม'=>7,'กค'=>7,'ก.ค.'=>7,
        'สิงหาคม'=>8,'สค'=>8,'ส.ค.'=>8,
        'กันยายน'=>9,'กย'=>9,'ก.ย.'=>9,
        'ตุลาคม'=>10,'ตค'=>10,'ต.ค.'=>10,
        'พฤศจิกายน'=>11,'พย'=>11,'พ.ย.'=>11,
        'ธันวาคม'=>12,'ธค'=>12,'ธ.ค.'=>12,
    ];
    // Strip leading digits + separators (e.g. "1มค68" → "มค68")
    $s = preg_replace('/^[\d\s_\-.]+/u', '', $folderName);
    foreach ($months as $abbr => $m) {
        // ตามด้วยปี 2-4 หลัก (อาจมีช่องว่าง/เครื่องหมาย)
        if (preg_match('/^' . preg_quote($abbr, '/') . '[\s_\-.]*(\d{2,4})/u', $s, $mt)) {
            $year = (int)$mt[1];
            if ($year < 100) $year += 2500; // 2-digit → BE century
            if ($year > 2400) $year -= 543; // BE to CE
            return ['year' => $year, 'month' => $m];
        }
    }
    return null;
}

function _safe_count(PDO $pdo, string $sql): int
{
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (PDOException $e) { return 0; }
}

/**
 * อ่าน CreationDate จาก PDF (รองรับ /Info dictionary + XMP metadata)
 * Read แค่ 64KB หัว+ท้าย — metadata มักอยู่ที่หัวหรือท้ายไฟล์
 */
function gc_read_pdf_creation(string $path): ?DateTime
{
    $sz = @filesize($path);
    if (!$sz || $sz <= 0) return null;
    $chunk = 64 * 1024;
    $head = @file_get_contents($path, false, null, 0, min($chunk, $sz)) ?: '';
    $tail = '';
    if ($sz > $chunk) {
        $tail = @file_get_contents($path, false, null, max(0, $sz - $chunk)) ?: '';
    }
    $data = $head . $tail;

    // /CreationDate (D:20250515143025+07'00')
    if (preg_match('/\/CreationDate\s*\(\s*D:(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?/', $data, $m)) {
        try {
            $y = $m[1]; $mo = $m[2]; $d = $m[3];
            $h = $m[4] ?? '00'; $mi = $m[5] ?? '00'; $s = $m[6] ?? '00';
            return new DateTime("$y-$mo-$d $h:$mi:$s");
        } catch (Exception $e) { /* fall through */ }
    }
    // XMP <xmp:CreateDate>2025-05-15T14:30:25+07:00</xmp:CreateDate>
    if (preg_match('/<xmp:CreateDate[^>]*>([^<]+)<\/xmp:CreateDate>/', $data, $m)) {
        try { return new DateTime($m[1]); } catch (Exception $e) {}
    }
    // Fallback: <pdf:CreationDate> (ใน XMP บาง spec)
    if (preg_match('/<pdf:CreationDate[^>]*>([^<]+)<\/pdf:CreationDate>/', $data, $m)) {
        try { return new DateTime($m[1]); } catch (Exception $e) {}
    }
    return null;
}

/** อ่าน DateTimeOriginal จาก EXIF ของ JPEG/PNG */
function gc_read_image_exif(string $path): ?DateTime
{
    if (!function_exists('exif_read_data')) return null;
    $exif = @exif_read_data($path, 'EXIF', false, false);
    if (!is_array($exif)) return null;

    $candidates = [
        $exif['DateTimeOriginal']  ?? null,
        $exif['DateTimeDigitized'] ?? null,
        $exif['DateTime']          ?? null,
        $exif['EXIF']['DateTimeOriginal']  ?? null,
        $exif['EXIF']['DateTimeDigitized'] ?? null,
    ];
    foreach ($candidates as $s) {
        if (!$s) continue;
        // Format: "2024:05:15 14:30:25"
        $dt = DateTime::createFromFormat('Y:m:d H:i:s', $s);
        if ($dt) return $dt;
        try { return new DateTime($s); } catch (Exception $e) {}
    }
    return null;
}

/** อ่าน dcterms:created จาก docProps/core.xml ของ DOCX */
function gc_read_docx_created(string $path): ?DateTime
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $xml = $zip->getFromName('docProps/core.xml');
    $zip->close();
    if (!$xml) return null;

    if (preg_match('/<dcterms:created[^>]*>([^<]+)<\/dcterms:created>/', $xml, $m)) {
        try { return new DateTime($m[1]); } catch (Exception $e) {}
    }
    return null;
}

/**
 * Dispatcher: อ่านวันสร้างจาก metadata ของไฟล์ใดๆ
 * คืน array ['year'=>int, 'month'=>1-12, 'application_date'=>YYYY-MM-DD,
 *          'label'=>'พ.ค. 68', 'source'=>'pdf|exif|docx']
 * หรือ null ถ้าอ่านไม่ได้
 */
function gc_read_file_creation(string $path, string $filename): ?array
{
    static $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $dt = null; $source = null;

    if ($ext === 'pdf') {
        $dt = gc_read_pdf_creation($path);
        $source = 'pdf';
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $dt = gc_read_image_exif($path);
        $source = 'exif';
    } elseif ($ext === 'docx') {
        $dt = gc_read_docx_created($path);
        $source = 'docx';
    }

    if (!$dt) return null;

    $y = (int)$dt->format('Y');
    $m = (int)$dt->format('n');
    if ($y < 1990 || $y > 3000 || $m < 1 || $m > 12) return null;

    return [
        'year'             => $y,
        'month'            => $m,
        'application_date' => sprintf('%04d-%02d-01', $y, $m),
        'label'            => $thaiMonths[$m - 1] . ' ' . substr((string)($y + 543), -2),
        'source'           => $source,
        'iso'              => $dt->format('Y-m-d H:i:s'),
    ];
}

function gc_match_user(PDO $pdo, string $filename): array
{
    $name = gc_extract_name($filename);
    if ($name === '') return ['status' => 'no_name', 'name' => '', 'candidates' => []];

    // 1) Exact match
    $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id, status
                           FROM sys_users WHERE TRIM(full_name) = ? LIMIT 5");
    $stmt->execute([$name]);
    $exact = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($exact) === 1) return ['status' => 'matched', 'name' => $name, 'user' => $exact[0]];
    if (count($exact) > 1)   return ['status' => 'ambiguous', 'name' => $name, 'candidates' => $exact];

    // 2) Fuzzy match (substring)
    $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id, status
                           FROM sys_users
                           WHERE full_name LIKE CONCAT('%', ?, '%')
                              OR ? LIKE CONCAT('%', full_name, '%')
                           LIMIT 5");
    $stmt->execute([$name, $name]);
    $fuzzy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($fuzzy) === 1) return ['status' => 'matched', 'name' => $name, 'user' => $fuzzy[0]];
    if (count($fuzzy) > 1)   return ['status' => 'ambiguous', 'name' => $name, 'candidates' => $fuzzy];

    return ['status' => 'no_match', 'name' => $name, 'candidates' => []];
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
try {
    switch ("$entity:$action") {

        case 'chart:overview': {
            // Built-in charts สำหรับหน้า gold_card (ไม่ผูกกับ widget config)
            require_once __DIR__ . '/../includes/dashboard_data_sources.php';
            json_ok([
                'trend'    => dashboard_resolve_data($pdo, 'gold_trend_12m'),
                'hospital' => dashboard_resolve_data($pdo, 'gold_by_hospital'),
            ]);
        }

        case 'folder:download': {
            // ดาวน์โหลด ZIP ของทุกเอกสารใน folder year+month (หรือ no_date)
            if (!class_exists('ZipArchive')) json_err('PHP ext-zip ไม่พร้อมใช้งาน');

            $year   = (int)($_POST['year'] ?? 0);
            $month  = (int)($_POST['month'] ?? 0);
            $noDate = !empty($_POST['no_date']);

            $where = ''; $params = [];
            if ($noDate) {
                $where = 'WHERE m.application_date IS NULL';
            } elseif ($year > 1900 && $month >= 1 && $month <= 12) {
                $where = 'WHERE YEAR(m.application_date) = ? AND MONTH(m.application_date) = ?';
                $params = [$year, $month];
            } else {
                json_err('ระบุ year/month หรือ no_date ไม่ถูกต้อง');
            }

            $sql = "SELECT m.id AS member_id, m.full_name, m.citizen_id,
                           d.id AS doc_id, d.doc_type, d.file_name, d.stored_path
                    FROM gold_card_members m
                    INNER JOIN gold_card_documents d ON d.member_id = m.id
                    $where
                    ORDER BY m.full_name ASC, d.uploaded_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) json_err('โฟลเดอร์ว่าง — ไม่มีไฟล์ให้ดาวน์โหลด');

            // ชื่อโฟลเดอร์ใน ZIP + ชื่อ file ZIP
            $thaiAbbr = ['','มค','กพ','มีค','เมย','พค','มิย','กค','สค','กย','ตค','พย','ธค'];
            $zipFolderName = $noDate
                ? 'ไม่ระบุเดือน'
                : $month . $thaiAbbr[$month] . substr((string)($year + 543), -2);

            // สร้าง ZIP ใน temp file
            $tmpZip = tempnam(sys_get_temp_dir(), 'gc_zip_');
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                @unlink($tmpZip);
                json_err('สร้าง ZIP ไม่สำเร็จ');
            }

            $base = gold_card_uploads_dir();
            $added = 0;
            $missing = 0;
            $usedNames = [];

            foreach ($rows as $r) {
                $src = $base . '/' . $r['stored_path'];
                if (!is_file($src)) { $missing++; continue; }

                // ใช้ชื่อสมาชิกเป็นชื่อไฟล์ (flat) — ตัดอักขระที่ใช้ใน path ไม่ได้
                $memName = preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/u', '_', $r['full_name'] ?: ('member_' . $r['member_id']));
                $memName = trim($memName) ?: ('member_' . $r['member_id']);

                // เก็บ extension จากไฟล์ต้นทาง
                $ext = pathinfo($r['file_name'], PATHINFO_EXTENSION);
                $extPart = $ext ? '.' . strtolower($ext) : '';

                // Flat structure: ZIP/<folder>/<ชื่อสมาชิก>.<ext>
                $internalPath = "$zipFolderName/$memName$extPart";

                // Collision (สมาชิกคนเดียวกันมีหลายไฟล์ หรือชื่อซ้ำ) → append _2, _3
                $i = 2;
                while (isset($usedNames[$internalPath])) {
                    $internalPath = "$zipFolderName/{$memName}_{$i}{$extPart}";
                    $i++;
                }
                $usedNames[$internalPath] = true;

                $zip->addFile($src, $internalPath);
                $added++;
            }

            // เพิ่มไฟล์ index.txt สรุปเนื้อหา
            $summary = "📦 บัตรทอง — โฟลเดอร์ $zipFolderName\n";
            $summary .= "สร้างเมื่อ: " . date('Y-m-d H:i:s') . "\n";
            $summary .= "ไฟล์ทั้งหมด: $added" . ($missing > 0 ? " (ไฟล์หายจาก storage: $missing)" : '') . "\n";
            $summary .= "สมาชิก: " . count(array_unique(array_column($rows, 'member_id'))) . " ราย\n";
            $zip->addFromString("$zipFolderName/_README.txt", $summary);

            $zip->close();

            if ($added === 0) {
                @unlink($tmpZip);
                json_err('ไม่พบไฟล์เอกสารใน storage');
            }

            // Stream ZIP กลับ
            $zipFileName = 'gold_card_' . $zipFolderName . '.zip';
            header_remove('Content-Type');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . rawurlencode($zipFileName) . '"; filename*=UTF-8\'\'' . rawurlencode($zipFileName));
            header('Content-Length: ' . filesize($tmpZip));
            header('Cache-Control: no-store');
            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        }

        case 'folder:delete': {
            // ลบสมาชิกทั้งหมดในโฟลเดอร์ year+month (หรือ no_date)
            $year   = (int)($_POST['year'] ?? 0);
            $month  = (int)($_POST['month'] ?? 0);
            $noDate = !empty($_POST['no_date']);

            $where = ''; $params = [];
            if ($noDate) {
                $where = 'WHERE application_date IS NULL';
            } elseif ($year > 1900 && $month >= 1 && $month <= 12) {
                $where = 'WHERE YEAR(application_date) = ? AND MONTH(application_date) = ?';
                $params = [$year, $month];
            } else {
                json_err('ระบุ year/month หรือ no_date ไม่ถูกต้อง');
            }

            // Lookup ids ที่จะลบ
            $sel = $pdo->prepare("SELECT id FROM gold_card_members $where");
            $sel->execute($params);
            $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
            if (empty($ids)) json_err('โฟลเดอร์ว่าง — ไม่มีอะไรให้ลบ');

            // ลบไฟล์เอกสารออกจาก disk ก่อน (FK CASCADE จะลบ rows ใน DB)
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $docs = $pdo->prepare("SELECT stored_path FROM gold_card_documents WHERE member_id IN ($placeholders)");
            $docs->execute($ids);
            $base = gold_card_uploads_dir();
            foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $sp) {
                $p = $base . '/' . $sp;
                if (is_file($p)) @unlink($p);
            }

            // ลบสมาชิก
            $del = $pdo->prepare("DELETE FROM gold_card_members $where");
            $del->execute($params);
            $deleted = $del->rowCount();

            gold_card_log_history($pdo, null, 'folder_deleted', null, [
                'year' => $year, 'month' => $month, 'no_date' => $noDate,
                'deleted_count' => $deleted, 'member_ids' => array_slice($ids, 0, 50),
            ], $adminId);

            json_ok([
                'deleted' => $deleted,
                'message' => "ลบโฟลเดอร์เรียบร้อย — $deleted รายการ",
            ]);
        }

        case 'folder:tree': {
            // คืน folder hierarchy: group by year + month ของ application_date
            $rows = $pdo->query("
                SELECT
                    YEAR(application_date)  AS y,
                    MONTH(application_date) AS m,
                    COUNT(*)                AS cnt,
                    SUM(status IN ('approved','active'))            AS approved,
                    SUM(status IN ('pending','submitted'))          AS pending
                FROM gold_card_members
                WHERE application_date IS NOT NULL
                GROUP BY y, m
                ORDER BY y DESC, m ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $thaiAbbr = ['','มค','กพ','มีค','เมย','พค','มิย','กค','สค','กย','ตค','พย','ธค'];
            $folders = [];
            foreach ($rows as $r) {
                $y = (int)$r['y']; $m = (int)$r['m'];
                $beShort = substr((string)($y + 543), -2);
                $folders[] = [
                    'year'      => $y,
                    'month'     => $m,
                    'label'     => $m . $thaiAbbr[$m] . $beShort,            // 5พค68
                    'full_label'=> $thaiAbbr[$m] . ' ' . ($y + 543),         // พค 2568
                    'count'     => (int)$r['cnt'],
                    'approved'  => (int)$r['approved'],
                    'pending'   => (int)$r['pending'],
                ];
            }
            $noDate = (int)_safe_count($pdo, "SELECT COUNT(*) FROM gold_card_members WHERE application_date IS NULL");
            json_ok(['folders' => $folders, 'no_date_count' => $noDate]);
        }

        case 'member:stats': {
            $row = $pdo->query("
                SELECT
                    COUNT(*)                                                          AS total,
                    SUM(status IN ('approved','active'))                              AS approved,
                    SUM(status IN ('pending','submitted'))                            AS pending,
                    SUM(status = 'rejected')                                          AS rejected,
                    SUM(status = 'active' AND coverage_end BETWEEN CURDATE()
                        AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))                     AS expiring,
                    SUM(member_type = 'บุคลากร')                                       AS staff,
                    SUM(member_type = 'นักศึกษา')                                      AS student
                FROM gold_card_members
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            json_ok(['stats' => array_map('intval', $row)]);
        }

        case 'member:list': {
            $page     = max(1, (int)($_POST['page'] ?? 1));
            $pageSize = max(10, min(100, (int)($_POST['page_size'] ?? 20)));
            $offset   = ($page - 1) * $pageSize;
            $search   = trim((string)($_POST['search'] ?? ''));
            $type     = trim((string)($_POST['type'] ?? ''));
            $status   = trim((string)($_POST['status'] ?? ''));
            $hospital = trim((string)($_POST['hospital'] ?? ''));
            $year     = isset($_POST['year'])  && ctype_digit((string)$_POST['year'])  ? (int)$_POST['year']  : null;
            $month    = isset($_POST['month']) && ctype_digit((string)$_POST['month']) ? (int)$_POST['month'] : null;
            $noDate   = !empty($_POST['no_date']); // กรองเฉพาะคนที่ application_date เป็น NULL

            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = "(full_name LIKE :s1 OR citizen_id LIKE :s2 OR phone LIKE :s3)";
                $params[':s1'] = "%$search%";
                $params[':s2'] = "%$search%";
                $params[':s3'] = "%$search%";
            }
            if ($type !== '')     { $where[] = "member_type = :type";    $params[':type'] = $type; }
            if ($status !== '')   { $where[] = "status = :status";       $params[':status'] = $status; }
            if ($hospital !== '') { $where[] = "hospital_main LIKE :h";  $params[':h'] = "%$hospital%"; }
            if ($noDate)          { $where[] = "application_date IS NULL"; }
            if ($year !== null && $year >= 2000 && $year <= 3000) {
                $where[] = "YEAR(application_date) = :year";
                $params[':year'] = $year;
            }
            if ($month !== null && $month >= 1 && $month <= 12) {
                $where[] = "MONTH(application_date) = :month";
                $params[':month'] = $month;
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM gold_card_members $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT m.*,
                           (SELECT COUNT(*) FROM gold_card_documents d WHERE d.member_id = m.id) AS doc_count
                    FROM gold_card_members m
                    $whereSql
                    ORDER BY m.updated_at DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok([
                'rows'      => $rows,
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
                'pages'     => (int)ceil($total / $pageSize),
            ]);
        }

        case 'member:get': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT * FROM gold_card_members WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) json_err('ไม่พบสมาชิก');

            $docs = $pdo->prepare("SELECT id, doc_type, file_name, mime_type, file_size, uploaded_at
                                   FROM gold_card_documents WHERE member_id = ?
                                   ORDER BY uploaded_at DESC");
            $docs->execute([$id]);

            $hist = $pdo->prepare("SELECT action, old_value, new_value, changed_at
                                   FROM gold_card_history WHERE member_id = ?
                                   ORDER BY changed_at DESC LIMIT 50");
            $hist->execute([$id]);

            json_ok([
                'member'    => $member,
                'documents' => $docs->fetchAll(PDO::FETCH_ASSOC),
                'history'   => $hist->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        case 'member:save': {
            $id          = (int)($_POST['id'] ?? 0);
            $citizenId   = preg_replace('/\D/', '', trim((string)($_POST['citizen_id'] ?? '')));
            $fullName    = trim((string)($_POST['full_name'] ?? ''));
            $memberType  = trim((string)($_POST['member_type'] ?? ''));
            $position    = trim((string)($_POST['position'] ?? ''));
            $phone       = trim((string)($_POST['phone'] ?? ''));
            $hospMain    = trim((string)($_POST['hospital_main'] ?? ''));
            $hospSub     = trim((string)($_POST['hospital_sub'] ?? ''));
            $applyDate   = trim((string)($_POST['application_date'] ?? '')) ?: null;
            $covStart    = trim((string)($_POST['coverage_start'] ?? '')) ?: null;
            $covEnd      = trim((string)($_POST['coverage_end'] ?? '')) ?: null;
            $status      = trim((string)($_POST['status'] ?? 'pending'));
            $remarks     = trim((string)($_POST['remarks'] ?? '')) ?: null;

            $allowedStatus = ['pending','submitted','approved','active','rejected','expired'];
            if (!in_array($status, $allowedStatus, true)) $status = 'pending';

            if (strlen($citizenId) !== 13) json_err('เลขบัตรประชาชนต้องเป็น 13 หลัก');
            if ($fullName === '') json_err('กรุณาระบุชื่อ-นามสกุล');

            if ($id > 0) {
                $old = $pdo->prepare("SELECT * FROM gold_card_members WHERE id = ?");
                $old->execute([$id]);
                $oldRow = $old->fetch(PDO::FETCH_ASSOC);
                if (!$oldRow) json_err('ไม่พบสมาชิก');

                $stmt = $pdo->prepare("UPDATE gold_card_members SET
                    citizen_id=?, full_name=?, member_type=?, position=?, phone=?,
                    hospital_main=?, hospital_sub=?, application_date=?, coverage_start=?, coverage_end=?,
                    status=?, remarks=?
                    WHERE id=?");
                $stmt->execute([
                    $citizenId, $fullName, $memberType, $position, $phone,
                    $hospMain, $hospSub, $applyDate, $covStart, $covEnd,
                    $status, $remarks, $id
                ]);

                if (($oldRow['status'] ?? '') !== $status) {
                    gold_card_log_history($pdo, $id, 'status_changed', $oldRow['status'], $status, $adminId);
                }
                gold_card_log_history($pdo, $id, 'updated', $oldRow, [
                    'full_name'=>$fullName, 'status'=>$status, 'hospital_main'=>$hospMain
                ], $adminId);

                json_ok(['id' => $id, 'message' => 'บันทึกข้อมูลเรียบร้อย']);
            } else {
                $exists = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");
                $exists->execute([$citizenId]);
                if ($exists->fetchColumn()) json_err('เลขบัตรประชาชนนี้มีอยู่ในระบบแล้ว');

                $stmt = $pdo->prepare("INSERT INTO gold_card_members
                    (citizen_id, full_name, member_type, position, phone,
                     hospital_main, hospital_sub, application_date, coverage_start, coverage_end,
                     status, remarks, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $citizenId, $fullName, $memberType, $position, $phone,
                    $hospMain, $hospSub, $applyDate, $covStart, $covEnd,
                    $status, $remarks, $adminId ?: null
                ]);
                $newId = (int)$pdo->lastInsertId();
                gold_card_log_history($pdo, $newId, 'created', null, [
                    'full_name'=>$fullName, 'status'=>$status
                ], $adminId);

                json_ok(['id' => $newId, 'message' => 'เพิ่มสมาชิกใหม่เรียบร้อย']);
            }
        }

        case 'member:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $row = $pdo->prepare("SELECT full_name, citizen_id FROM gold_card_members WHERE id = ?");
            $row->execute([$id]);
            $info = $row->fetch(PDO::FETCH_ASSOC);
            if (!$info) json_err('ไม่พบสมาชิก');

            $docs = $pdo->prepare("SELECT stored_path FROM gold_card_documents WHERE member_id = ?");
            $docs->execute([$id]);
            $base = gold_card_uploads_dir();
            foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $sp) {
                $p = $base . '/' . $sp;
                if (is_file($p)) @unlink($p);
            }

            $pdo->prepare("DELETE FROM gold_card_members WHERE id = ?")->execute([$id]);
            gold_card_log_history($pdo, null, 'deleted', $info, null, $adminId);

            json_ok(['message' => 'ลบสมาชิกและเอกสารทั้งหมดเรียบร้อย']);
        }

        case 'document:upload': {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $docType  = trim((string)($_POST['doc_type'] ?? 'other'));
            $allowed  = ['id_copy','house_reg','application','photo','medical','other'];
            if (!in_array($docType, $allowed, true)) $docType = 'other';

            if ($memberId <= 0) json_err('ระบุ member_id ไม่ถูกต้อง');
            if (empty($_FILES['file']['name'])) json_err('ไม่พบไฟล์');

            $check = $pdo->prepare("SELECT id FROM gold_card_members WHERE id = ?");
            $check->execute([$memberId]);
            if (!$check->fetchColumn()) json_err('ไม่พบสมาชิก');

            $saved = gold_card_save_uploaded_file($_FILES['file'], $memberId);
            if (!$saved) json_err('อัปโหลดไม่สำเร็จ — ตรวจสอบนามสกุลและขนาดไฟล์ (≤20MB, PDF/JPG/PNG/DOC)');

            $stmt = $pdo->prepare("INSERT INTO gold_card_documents
                (member_id, doc_type, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $memberId, $docType,
                $saved['file_name'], $saved['stored_path'],
                $saved['mime_type'], $saved['file_size'], $saved['sha1_hash'],
                $adminId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            gold_card_log_history($pdo, $memberId, 'doc_added', null, [
                'doc_id'=>$newId, 'doc_type'=>$docType, 'file_name'=>$saved['file_name']
            ], $adminId);

            json_ok([
                'id' => $newId,
                'file_name' => $saved['file_name'],
                'message' => 'อัปโหลดเอกสารเรียบร้อย',
            ]);
        }

        case 'document:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT member_id, stored_path, file_name FROM gold_card_documents WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('ไม่พบไฟล์');

            $pdo->prepare("DELETE FROM gold_card_documents WHERE id = ?")->execute([$id]);
            $path = gold_card_uploads_dir() . '/' . $row['stored_path'];
            if (is_file($path)) @unlink($path);

            gold_card_log_history($pdo, (int)$row['member_id'], 'doc_removed', [
                'doc_id'=>$id, 'file_name'=>$row['file_name']
            ], null, $adminId);

            json_ok(['message' => 'ลบเอกสารเรียบร้อย']);
        }

        case 'document:download': {
            // ส่งไฟล์ออก (ไม่ใช่ JSON) — handle separately
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT file_name, stored_path, mime_type FROM gold_card_documents WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('ไม่พบไฟล์');

            $path = gold_card_uploads_dir() . '/' . $row['stored_path'];
            if (!is_file($path)) json_err('ไฟล์หายไปจาก storage');

            // Override JSON header
            header_remove('Content-Type');
            header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . rawurlencode($row['file_name']) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }

        case 'bulk:scan': {
            // รับไฟล์หลายๆ ไฟล์ → preview ผลการ match (ยังไม่ commit)
            // Files: $_FILES['files']['name'][...]
            // Optional: paths[] = relative path of each file (for folder upload เพื่อ parse เดือน)
            if (empty($_FILES['files']['name'])) json_err('ไม่พบไฟล์');
            if (!is_array($_FILES['files']['name'])) json_err('ใช้ <input type="file" multiple> เท่านั้น');

            $report = [];
            $names  = $_FILES['files']['name'];
            $errors = $_FILES['files']['error'];
            $sizes  = $_FILES['files']['size'];
            $paths  = $_POST['paths'] ?? [];

            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

            foreach ($names as $i => $fname) {
                if ($errors[$i] !== UPLOAD_ERR_OK) {
                    $report[] = ['filename' => $fname, 'status' => 'upload_error', 'name' => '', 'size' => 0];
                    continue;
                }
                $match = gc_match_user($pdo, $fname);
                $extracted = $match['name'];

                // ── Parse เดือนจาก path (folder mode) ──────────────────
                $folderMonth = null;
                $relPath = is_array($paths) && isset($paths[$i]) ? (string)$paths[$i] : '';
                if ($relPath !== '') {
                    $segments = array_filter(explode('/', str_replace('\\', '/', $relPath)));
                    array_pop($segments); // ตัด filename
                    foreach (array_reverse($segments) as $seg) {
                        $parsed = gc_parse_thai_month_folder($seg);
                        if ($parsed) {
                            $folderMonth = $parsed + [
                                'folder' => $seg,
                                'application_date' => sprintf('%04d-%02d-01', $parsed['year'], $parsed['month']),
                                'label' => $thaiMonths[$parsed['month'] - 1] . ' ' . substr((string)($parsed['year'] + 543), -2),
                            ];
                            break;
                        }
                    }
                }

                // ── Parse เดือนจาก metadata ของไฟล์ (ใหม่) ─────────────
                $tmpName = $_FILES['files']['tmp_name'][$i] ?? '';
                $metaMonth = null;
                if ($tmpName && is_uploaded_file($tmpName)) {
                    $metaMonth = gc_read_file_creation($tmpName, $fname);
                }

                // ── Effective month: metadata wins over folder ─────────
                $effective = $metaMonth ?: $folderMonth;

                // Check ซ้ำ
                $existsId = null;
                if (!empty($match['user']['citizen_id'])) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");
                    $st->execute([$match['user']['citizen_id']]);
                    $existsId = (int)$st->fetchColumn() ?: null;
                }
                if (!$existsId) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE source_filename = ? LIMIT 1");
                    $st->execute([$fname]);
                    $existsId = (int)$st->fetchColumn() ?: null;
                }

                $report[] = [
                    'index'      => $i,
                    'filename'   => $fname,
                    'rel_path'   => $relPath,
                    'extracted_name' => $extracted,
                    'size'       => $sizes[$i],
                    'status'     => $match['status'],
                    'user'       => $match['user'] ?? null,
                    'candidates' => $match['candidates'] ?? [],
                    'already_exists' => $existsId,
                    'folder_month' => $folderMonth,  // จากชื่อโฟลเดอร์
                    'meta_month'   => $metaMonth,    // จาก PDF/EXIF/DOCX metadata
                    'month_info'   => $effective,    // ใช้ตอน commit (metadata ชนะ)
                ];
            }
            json_ok(['report' => $report]);
        }

        case 'bulk:commit': {
            // Commit รายการที่ admin confirm แล้ว
            // POST: items[] = JSON array of:
            //   { filename, name, user_id (nullable), citizen_id (nullable), tmp_idx }
            // Files ยัง stay ใน $_FILES['files']
            if (empty($_FILES['files']['name'])) json_err('ไม่พบไฟล์');
            $itemsJson = $_POST['items'] ?? '[]';
            $items = json_decode($itemsJson, true);
            if (!is_array($items)) json_err('items ไม่ถูกต้อง');

            $created = 0; $attached = 0; $skipped = 0; $errs = [];

            foreach ($items as $it) {
                $idx       = (int)($it['index'] ?? -1);
                $filename  = $_FILES['files']['name'][$idx] ?? '';
                $tmpName   = $_FILES['files']['tmp_name'][$idx] ?? '';
                $errCode   = $_FILES['files']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
                $userId    = !empty($it['user_id']) ? (int)$it['user_id'] : null;
                $citizenId = !empty($it['citizen_id']) ? preg_replace('/\D/', '', (string)$it['citizen_id']) : null;
                $fullName  = trim((string)($it['name'] ?? ''));
                $applyDate = !empty($it['application_date']) ? (string)$it['application_date'] : date('Y-m-d');
                $covStart  = !empty($it['coverage_start']) ? (string)$it['coverage_start'] : $applyDate;

                if ($errCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
                    $skipped++; $errs[] = "$filename: upload error"; continue;
                }
                if ($fullName === '') { $skipped++; $errs[] = "$filename: ไม่มีชื่อ"; continue; }

                // ── Check existing member (citizen_id หรือ linked user) ──
                $existingId = null;
                if ($citizenId) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");
                    $st->execute([$citizenId]);
                    $existingId = (int)$st->fetchColumn() ?: null;
                }
                if (!$existingId && $userId) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE linked_user_id = ? LIMIT 1");
                    $st->execute([$userId]);
                    $existingId = (int)$st->fetchColumn() ?: null;
                }

                if ($existingId) {
                    // ── สมาชิกมีอยู่แล้ว → เพิ่มไฟล์เป็นเอกสารแนบเพิ่ม ──
                    $newId = $existingId;
                    $isNewMember = false;
                } else {
                    // ── สมาชิกใหม่ → สร้าง record + attach file ──
                    $stmt = $pdo->prepare("INSERT INTO gold_card_members
                        (citizen_id, linked_user_id, full_name, member_type, status,
                         source_filename, application_date, coverage_start, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?)");
                    $memberType = $userId ? 'นักศึกษา' : 'บุคคลทั่วไป';
                    $stmt->execute([
                        $citizenId ?: null,
                        $userId,
                        $fullName,
                        $memberType,
                        $userId ? 'active' : 'pending',
                        $filename,
                        $applyDate,
                        $covStart,
                        $adminId ?: null
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    $isNewMember = true;
                }

                // ── Skip ถ้าไฟล์เดียวกัน (sha1) แนบให้สมาชิกเดียวกันแล้ว ──
                $sha = sha1_file($tmpName) ?: '';
                if (!$isNewMember && $sha) {
                    $dup = $pdo->prepare("SELECT id FROM gold_card_documents WHERE member_id = ? AND sha1_hash = ? LIMIT 1");
                    $dup->execute([$newId, $sha]);
                    if ($dup->fetchColumn()) {
                        $skipped++;
                        $errs[] = "$filename: ไฟล์เดิม (hash ซ้ำ) — ข้าม";
                        continue;
                    }
                }

                // ── Save file ──
                $saved = gold_card_save_uploaded_file([
                    'name' => $filename, 'tmp_name' => $tmpName,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $_FILES['files']['size'][$idx] ?? 0,
                    'type' => $_FILES['files']['type'][$idx] ?? null,
                ], $newId);

                if ($saved) {
                    $pdo->prepare("INSERT INTO gold_card_documents
                        (member_id, doc_type, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by)
                        VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([
                            $newId, 'application',
                            $saved['file_name'], $saved['stored_path'],
                            $saved['mime_type'], $saved['file_size'], $saved['sha1_hash'],
                            $adminId ?: null,
                       ]);
                }

                gold_card_log_history($pdo, $newId,
                    $isNewMember ? 'bulk_imported' : 'doc_attached_via_bulk',
                    null,
                    [
                        'filename' => $filename,
                        'matched_user_id' => $userId,
                        'is_new_member' => $isNewMember,
                    ], $adminId);

                if ($isNewMember) $created++;
                else $attached++;
            }

            $msgParts = [];
            if ($created > 0)  $msgParts[] = "สร้างใหม่ $created";
            if ($attached > 0) $msgParts[] = "เพิ่มเอกสาร $attached";
            if ($skipped > 0)  $msgParts[] = "ข้าม $skipped";
            json_ok([
                'created'  => $created,
                'attached' => $attached,
                'skipped'  => $skipped,
                'errors'   => $errs,
                'message'  => $msgParts ? implode(' · ', $msgParts) . ' รายการ' : 'ไม่มีรายการที่ดำเนินการ',
            ]);
        }

        case 'bulk:link_user': {
            // Manual link: admin เลือก member + user จาก dropdown
            $memberId = (int)($_POST['member_id'] ?? 0);
            $userId   = (int)($_POST['user_id'] ?? 0);
            if ($memberId <= 0 || $userId <= 0) json_err('ระบุ member_id + user_id ไม่ถูกต้อง');

            $u = $pdo->prepare("SELECT id, full_name, citizen_id FROM sys_users WHERE id = ? LIMIT 1");
            $u->execute([$userId]);
            $user = $u->fetch(PDO::FETCH_ASSOC);
            if (!$user) json_err('ไม่พบ user');

            $pdo->prepare("UPDATE gold_card_members
                           SET linked_user_id = ?, citizen_id = COALESCE(NULLIF(citizen_id,''), ?), status = 'active'
                           WHERE id = ?")
                ->execute([$userId, $user['citizen_id'] ?: null, $memberId]);

            gold_card_log_history($pdo, $memberId, 'linked_to_user', null, [
                'user_id' => $userId, 'user_name' => $user['full_name']
            ], $adminId);

            json_ok(['message' => 'Link เรียบร้อย — สถานะเปลี่ยนเป็น Active']);
        }

        case 'bulk:search_users': {
            // ค้นหา users ใน sys_users สำหรับ manual matching dropdown
            $q = trim((string)($_POST['q'] ?? ''));
            if (mb_strlen($q) < 2) json_ok(['users' => []]);
            $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id, status
                                   FROM sys_users
                                   WHERE full_name LIKE ? OR student_personnel_id LIKE ? OR citizen_id LIKE ?
                                   ORDER BY full_name ASC LIMIT 20");
            $like = "%$q%";
            $stmt->execute([$like, $like, $like]);
            json_ok(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        default:
            json_err('ไม่รู้จัก entity:action — ' . htmlspecialchars("$entity:$action"));
    }
} catch (Throwable $e) {
    error_log('[ajax_gold_card] ' . $e->getMessage());
    json_err('ระบบขัดข้อง: ' . $e->getMessage(), 500);
}
