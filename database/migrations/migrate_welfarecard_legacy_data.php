<?php
/**
 * database/migrations/migrate_welfarecard_legacy_data.php
 *
 * Migrate ข้อมูลจากระบบ welfarecard เก่า → gold_card_members + gold_card_documents
 *
 * Source (ต้อง import เข้า DB เดียวกันก่อนรัน):
 *  - welfarecard       (ข้อมูลสมาชิก ~5,628 rows)
 *  - welfareuser       (สำหรับ match กับ sys_users — optional)
 *
 * Source files:
 *  - /var/www/html/e-campaignv2/welfarecard_old/uploads/{pid}.jpg  (selfie photos)
 *  - signature: base64 ใน column ของ welfarecard
 *
 * Output:
 *  - gold_card_members rows (พร้อม legacy_id อ้างอิง welfarecard.id)
 *  - gold_card_documents rows (signature + photo)
 *  - Files copied to: uploads/gold_card/legacy/{year}/{month}/
 *
 * Features:
 *  ✓ Resumable      — skip records ที่ migrate แล้ว (ดูจาก legacy_id)
 *  ✓ Batch          — process ทีละ 100 rows + flush output แบบ real-time
 *  ✓ Dry-run        — ?dry=1 (แสดงสรุปไม่เขียน DB / ไม่ copy files)
 *  ✓ Reset          — ?reset=1 ลบ bulk-import เก่า (105 rows ที่ไม่มี legacy_id) ก่อนรัน
 *  ✓ Tolerant       — ไฟล์รูปไม่เจอ / signature decode fail → log + continue
 *  ✓ Auth           — ต้อง login เป็น superadmin
 *
 * Usage:
 *   ?dry=1            ทดสอบไม่เขียนจริง (recommended ก่อน)
 *   (no params)       รันจริง
 *   ?reset=1          ลบ bulk-import เก่า + รันจริง (ใช้ครั้งเดียว!)
 *   ?limit=500        จำกัดจำนวน (สำหรับ test)
 */
declare(strict_types=1);

set_time_limit(0);
ignore_user_abort(true);
@ini_set('memory_limit', '512M');
@ini_set('output_buffering', 'off');
@ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

require_once __DIR__ . '/../../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth: superadmin only ────────────────────────────────────────────────────
$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin') {
    http_response_code(403);
    die('❌ Migration นี้รันได้เฉพาะ superadmin — กรุณา login ที่ portal ก่อน');
}

$pdo     = db();
$dryRun  = !empty($_GET['dry']);
$reset   = !empty($_GET['reset']);
$limit   = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 0;
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// ── Config ───────────────────────────────────────────────────────────────────
$LEGACY_UPLOADS_DIR = dirname(__DIR__, 2) . '/welfarecard_old/uploads';
$NEW_UPLOADS_BASE   = dirname(__DIR__, 2) . '/uploads/gold_card';
$BATCH_SIZE         = 100;

// ── Helpers ──────────────────────────────────────────────────────────────────
function map_status(?string $thai): string {
    $thai = trim((string)$thai);
    return match ($thai) {
        'อนุมัติ', 'อนุมัติแล้ว', 'ใช้งาน', 'active', 'approved' => 'active',
        'ไม่อนุมัติ', 'ปฏิเสธ', 'rejected'                       => 'rejected',
        'รอส่ง', 'รอตัดสินใจ', 'submitted'                       => 'submitted',
        'หมดอายุ', 'expired'                                     => 'expired',
        default                                                  => 'pending',
    };
}

function map_gender(?string $g): ?string {
    if (!$g) return null;
    $g = trim((string)$g);
    if (in_array($g, ['ชาย', 'นาย', 'M', 'm', 'male', 'Male'], true)) return 'male';
    if (in_array($g, ['หญิง', 'นาง', 'นางสาว', 'F', 'f', 'female', 'Female'], true)) return 'female';
    return 'other';
}

function normalize_date(?string $d): ?string {
    if (!$d) return null;
    $d = trim($d);
    if ($d === '' || $d === '0000-00-00' || str_starts_with($d, '0000-')) return null;
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : null;
}

function normalize_datetime(?string $d): ?string {
    if (!$d) return null;
    $d = trim($d);
    if ($d === '' || str_starts_with($d, '0000-')) return null;
    $ts = strtotime($d);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function table_exists(PDO $pdo, string $name): bool {
    try { $pdo->query("SELECT 1 FROM `$name` LIMIT 1"); return true; }
    catch (PDOException $e) { return false; }
}

function get_columns(PDO $pdo, string $table): array {
    return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

/** หาคอลัมน์ที่มีจริงจาก list ตามลำดับความสำคัญ */
function pick_col(array $available, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $available, true)) return $c;
    }
    return null;
}

/** Save base64 signature → file. Returns relative path or null. */
function save_signature(string $base64, int $legacyId, string $newBase, bool $dry): ?array {
    $base64 = trim($base64);
    if ($base64 === '') return null;
    // Strip data URI prefix
    if (str_contains($base64, ',')) $base64 = substr($base64, strpos($base64, ',') + 1);
    $bin = base64_decode($base64, true);
    if ($bin === false || strlen($bin) < 100) return null;

    $year = date('Y'); $month = date('m');
    $rel = "legacy/$year/$month/sig_$legacyId.png";
    $abs = $newBase . '/' . $rel;

    if (!$dry) {
        $dir = dirname($abs);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (file_put_contents($abs, $bin) === false) return null;
    }

    return [
        'file_name'   => "signature_$legacyId.png",
        'stored_path' => $rel,
        'mime_type'   => 'image/png',
        'file_size'   => strlen($bin),
        'sha1_hash'   => sha1($bin),
    ];
}

/** Copy selfie photo from legacy → new. Returns relative path or null. */
function copy_photo(string $pid, int $legacyId, string $legacyDir, string $newBase, bool $dry): ?array {
    if ($pid === '') return null;
    $candidates = ["$legacyDir/$pid.jpg", "$legacyDir/$pid.JPG", "$legacyDir/$pid.jpeg", "$legacyDir/$pid.png"];
    $src = null;
    foreach ($candidates as $c) {
        if (is_file($c)) { $src = $c; break; }
    }
    if (!$src) return null;

    $size = filesize($src);
    $hash = sha1_file($src) ?: bin2hex(random_bytes(8));
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';

    $year = date('Y'); $month = date('m');
    $rel = "legacy/$year/$month/photo_{$legacyId}.{$ext}";
    $abs = $newBase . '/' . $rel;

    if (!$dry) {
        $dir = dirname($abs);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!@copy($src, $abs)) return null;
    }

    return [
        'file_name'   => basename($src),
        'stored_path' => $rel,
        'mime_type'   => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
        'file_size'   => $size ?: 0,
        'sha1_hash'   => $hash,
    ];
}

// ── HTML output start ────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Migrate Welfarecard Legacy Data</title>
    <style>
        body { font-family: 'Sarabun', -apple-system, sans-serif; max-width: 1100px; margin: 20px auto; padding: 0 20px; background: #f8fafc; color: #1e293b; }
        h2 { margin-bottom: 4px; }
        .subtitle { color: #64748b; margin-bottom: 16px; }
        .badges { margin-bottom: 16px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; margin-right: 8px; }
        .badge.dry { background: #fef3c7; color: #b45309; }
        .badge.live { background: #dcfce7; color: #15803d; }
        .badge.reset { background: #fee2e2; color: #b91c1c; }
        .log { background: #0f172a; color: #e2e8f0; padding: 16px 20px; border-radius: 10px; font-family: 'SF Mono', Monaco, monospace; font-size: 12.5px; line-height: 1.55; height: 520px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .log .ok    { color: #4ade80; }
        .log .warn  { color: #fbbf24; }
        .log .err   { color: #f87171; }
        .log .info  { color: #60a5fa; }
        .log .dim   { color: #64748b; }
        .summary { background: white; padding: 20px 24px; border-radius: 10px; margin-top: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-top: 16px; }
        .stat { padding: 12px 16px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #6366f1; }
        .stat-num { font-size: 22px; font-weight: 700; color: #6366f1; }
        .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
    </style>
</head>
<body>
    <h2>🚀 Migrate Welfarecard Legacy Data</h2>
    <p class="subtitle">โอนย้ายสมาชิกจากระบบบัตรสวัสดิการเก่า → gold_card_members</p>
    <div class="badges">
        <?php if ($dryRun): ?><span class="badge dry">⚠ DRY-RUN — ไม่เขียน DB</span><?php else: ?><span class="badge live">🔥 LIVE MODE</span><?php endif; ?>
        <?php if ($reset): ?><span class="badge reset">🗑 RESET — ลบ bulk-import เก่า</span><?php endif; ?>
        <?php if ($limit): ?><span class="badge dry">📏 LIMIT <?= $limit ?></span><?php endif; ?>
    </div>
    <div class="log" id="log"><?php

flush();
$startTime = microtime(true);

// ── 1. Verify source tables ──────────────────────────────────────────────────
echo "[" . date('H:i:s') . "] เริ่ม migration...\n";
if (!table_exists($pdo, 'welfarecard')) {
    echo "<span class='err'>❌ ตาราง `welfarecard` ไม่พบ — กรุณา import welfarecard.sql ก่อน</span>\n";
    echo "</div></body></html>";
    exit;
}
echo "<span class='ok'>✓ พบตาราง `welfarecard`</span>\n";

$hasUserTable = table_exists($pdo, 'welfareuser');
echo $hasUserTable
    ? "<span class='ok'>✓ พบตาราง `welfareuser`</span>\n"
    : "<span class='warn'>⚠ ไม่พบ `welfareuser` — จะ skip user matching</span>\n";

// ── 2. Reset bulk-import ─────────────────────────────────────────────────────
if ($reset && !$dryRun) {
    echo "\n<span class='warn'>🗑️  RESET: ลบ rows ที่ไม่มี legacy_id (bulk-import เก่า)</span>\n";
    $delCount = $pdo->exec("DELETE FROM gold_card_members WHERE legacy_id IS NULL");
    echo "<span class='warn'>   → ลบไป $delCount rows + cascading documents/history</span>\n";
}

// ── 3. Detect welfarecard schema ─────────────────────────────────────────────
$wcCols = get_columns($pdo, 'welfarecard');
echo "\n<span class='info'>📋 welfarecard columns (" . count($wcCols) . "): " . implode(', ', $wcCols) . "</span>\n";

$colMap = [
    'pid'        => pick_col($wcCols, ['pid', 'citizen_id', 'cid', 'national_id', 'idcard']),
    'name'       => pick_col($wcCols, ['username', 'name', 'fullname', 'full_name', 'fname']),
    'gender'     => pick_col($wcCols, ['gender', 'sex', 'title', 'prefix']),
    'dob'        => pick_col($wcCols, ['birth', 'dob', 'birthday', 'birthdate', 'date_of_birth', 'birth_date']),
    'phone'      => pick_col($wcCols, ['phone', 'tel', 'telephone', 'mobile', 'phone_number']),
    'address'    => pick_col($wcCols, ['address', 'addr', 'home_address']),
    'hospital'   => pick_col($wcCols, ['hospital', 'hosp_main', 'main_hospital', 'hospital_main', 'hosp']),
    'sub_hosp'   => pick_col($wcCols, ['sub_hospital', 'hosp_sub', 'hospital_sub', 'sub_hosp']),
    'signature'  => pick_col($wcCols, ['signature', 'sign', 'signature_base64', 'sig']),
    'status'     => pick_col($wcCols, ['status', 'state', 'card_status']),
    'submitdate' => pick_col($wcCols, ['submitdate', 'submit_date', 'created_at', 'date', 'created']),
    'remarks'    => pick_col($wcCols, ['remarks', 'remark', 'note', 'comment', 'notes']),
    'member_type'=> pick_col($wcCols, ['member_type', 'type', 'category', 'role']),
    'position'   => pick_col($wcCols, ['position', 'job', 'occupation']),
    'registrar'  => pick_col($wcCols, ['registrar', 'registered_by', 'staff_name']),
];

echo "<span class='info'>🗺️  Column mapping:</span>\n";
foreach ($colMap as $logical => $actual) {
    $sym = $actual ? "<span class='ok'>✓</span>" : "<span class='dim'>—</span>";
    $req = in_array($logical, ['pid', 'name'], true) ? ' <span class=\'warn\'>(required)</span>' : '';
    echo "   $sym $logical → " . ($actual ?? '(not found)') . "$req\n";
}

if (!$colMap['pid'] || !$colMap['name']) {
    echo "<span class='err'>\n❌ ขาด required columns (pid + name) — abort</span>\n";
    echo "</div></body></html>";
    exit;
}

// ── 4. Detect sys_users matching columns ─────────────────────────────────────
$sysUsersExists = table_exists($pdo, 'sys_users');
$sysCitizenCol = $sysLineCol = null;
if ($sysUsersExists) {
    $sysCols = get_columns($pdo, 'sys_users');
    $sysCitizenCol = pick_col($sysCols, ['citizen_id', 'national_id', 'cid', 'idcard']);
    $sysLineCol    = pick_col($sysCols, ['line_user_id', 'line_id', 'lineid']);
    echo "<span class='info'>👤 sys_users matching: citizen=" . ($sysCitizenCol ?? 'none') . ", line=" . ($sysLineCol ?? 'none') . "</span>\n";
}

// ── 5. Resume support: get already-migrated legacy_ids ───────────────────────
$migratedRows = $pdo->query("SELECT legacy_id FROM gold_card_members WHERE legacy_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$migratedSet = array_flip(array_map('intval', $migratedRows));
echo "<span class='info'>♻️  Resume: skip " . count($migratedSet) . " records ที่ migrate แล้ว</span>\n";

// ── 6. Total count ───────────────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM welfarecard")->fetchColumn();
$todo  = $total - count($migratedSet);
echo "<span class='info'>📊 Total welfarecard: $total | จะ migrate: $todo " . ($limit ? "(limit $limit)" : "") . "</span>\n\n";

if ($todo === 0) {
    echo "<span class='ok'>✅ ไม่มีอะไรต้อง migrate — ทุก rows เสร็จหมดแล้ว</span>\n";
    echo "</div></body></html>";
    exit;
}

// ── 7. Build SELECT ──────────────────────────────────────────────────────────
$selectFields = ['id'];
foreach ($colMap as $logical => $actual) {
    if ($actual) $selectFields[] = "`$actual` AS `c_$logical`";
}
$selectSql = "SELECT " . implode(', ', $selectFields) . " FROM welfarecard ORDER BY id ASC LIMIT ? OFFSET ?";
$stmtSelect = $pdo->prepare($selectSql);

// Prepared statements for INSERT
$stmtInsertMember = $pdo->prepare("
    INSERT INTO gold_card_members
    (citizen_id, linked_user_id, full_name, gender, date_of_birth, member_type, position, phone,
     hospital_main, hospital_sub, application_date, status, remarks, source_filename,
     legacy_id, migrated_at, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
");
$stmtInsertDoc = $pdo->prepare("
    INSERT INTO gold_card_documents
    (member_id, doc_type, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by, uploaded_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

// User matching
$stmtMatchByCitizen = ($sysCitizenCol ? $pdo->prepare("SELECT id FROM sys_users WHERE `$sysCitizenCol` = ? LIMIT 1") : null);

// ── 8. Main batch loop ───────────────────────────────────────────────────────
$stats = [
    'processed'   => 0,
    'inserted'    => 0,
    'skipped'     => 0,
    'photos'      => 0,
    'signatures'  => 0,
    'no_photo'    => 0,
    'no_sig'      => 0,
    'matched_user'=> 0,
    'errors'      => 0,
];

$offset = 0;
$BATCH = $BATCH_SIZE;

while (true) {
    if ($limit && $stats['processed'] >= $limit) break;

    $stmtSelect->execute([$BATCH, $offset]);
    $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) break;

    foreach ($rows as $row) {
        if ($limit && $stats['processed'] >= $limit) break 2;
        $stats['processed']++;

        $legacyId = (int)$row['id'];

        // Skip if already migrated
        if (isset($migratedSet[$legacyId])) {
            $stats['skipped']++;
            continue;
        }

        try {
            $pid       = trim((string)($row['c_pid'] ?? ''));
            $name      = trim((string)($row['c_name'] ?? ''));
            $gender    = map_gender($row['c_gender'] ?? null);
            $dob       = normalize_date($row['c_dob'] ?? null);
            $phone     = trim((string)($row['c_phone'] ?? ''));
            $hospital  = trim((string)($row['c_hospital'] ?? ''));
            $subHosp   = trim((string)($row['c_sub_hosp'] ?? ''));
            $signature = (string)($row['c_signature'] ?? '');
            $statusRaw = (string)($row['c_status'] ?? '');
            $submitdate= normalize_datetime($row['c_submitdate'] ?? null);
            $remarks   = trim((string)($row['c_remarks'] ?? ''));
            $memberType= trim((string)($row['c_member_type'] ?? '')) ?: 'บุคคลทั่วไป';
            $position  = trim((string)($row['c_position'] ?? ''));
            $registrar = trim((string)($row['c_registrar'] ?? ''));

            // Combine registrar info into remarks (audit trail)
            if ($registrar !== '') {
                $registrarNote = "ผู้ลงทะเบียน (ระบบเก่า): $registrar";
                $remarks = $remarks ? "$remarks\n$registrarNote" : $registrarNote;
            }

            $status = map_status($statusRaw);
            $appDate = $submitdate ? substr($submitdate, 0, 10) : null;
            $createdAt = $submitdate ?: date('Y-m-d H:i:s');

            // Match user by citizen_id
            $linkedUserId = null;
            if ($pid !== '' && $stmtMatchByCitizen) {
                $stmtMatchByCitizen->execute([$pid]);
                $linkedUserId = $stmtMatchByCitizen->fetchColumn() ?: null;
                if ($linkedUserId) $stats['matched_user']++;
            }

            $sourceFile = "welfarecard_legacy:$legacyId";

            if (!$dryRun) {
                $stmtInsertMember->execute([
                    $pid !== '' ? $pid : null,
                    $linkedUserId ?: null,
                    $name,
                    $gender,
                    $dob,
                    $memberType,
                    $position,
                    $phone,
                    $hospital,
                    $subHosp,
                    $appDate,
                    $status,
                    $remarks ?: null,
                    $sourceFile,
                    $legacyId,
                    $adminId ?: null,
                    $createdAt,
                ]);
                $memberId = (int)$pdo->lastInsertId();
            } else {
                $memberId = -$legacyId; // pseudo for dry-run
            }
            $stats['inserted']++;

            // Save signature
            if ($signature !== '') {
                $sig = save_signature($signature, $legacyId, $NEW_UPLOADS_BASE, $dryRun);
                if ($sig) {
                    if (!$dryRun) {
                        $stmtInsertDoc->execute([
                            $memberId, 'signature', $sig['file_name'], $sig['stored_path'],
                            $sig['mime_type'], $sig['file_size'], $sig['sha1_hash'], $adminId ?: null,
                        ]);
                    }
                    $stats['signatures']++;
                } else {
                    $stats['no_sig']++;
                }
            } else {
                $stats['no_sig']++;
            }

            // Copy photo
            if ($pid !== '') {
                $photo = copy_photo($pid, $legacyId, $LEGACY_UPLOADS_DIR, $NEW_UPLOADS_BASE, $dryRun);
                if ($photo) {
                    if (!$dryRun) {
                        $stmtInsertDoc->execute([
                            $memberId, 'photo', $photo['file_name'], $photo['stored_path'],
                            $photo['mime_type'], $photo['file_size'], $photo['sha1_hash'], $adminId ?: null,
                        ]);
                    }
                    $stats['photos']++;
                } else {
                    $stats['no_photo']++;
                }
            } else {
                $stats['no_photo']++;
            }

        } catch (Throwable $e) {
            $stats['errors']++;
            echo "<span class='err'>  ✗ legacy_id=$legacyId: " . htmlspecialchars($e->getMessage()) . "</span>\n";
        }
    }

    // Progress update
    $pct = $todo > 0 ? round(($stats['processed'] / min($todo, $limit ?: $todo)) * 100, 1) : 100;
    $elapsed = microtime(true) - $startTime;
    $rate = $elapsed > 0 ? round($stats['processed'] / $elapsed, 1) : 0;
    $msg = sprintf(
        "[%s] batch offset=%d → processed=%d (%.1f%%) | inserted=%d skipped=%d | sig=%d photo=%d | matched_user=%d errors=%d | %.1f rows/s",
        date('H:i:s'), $offset, $stats['processed'], $pct,
        $stats['inserted'], $stats['skipped'],
        $stats['signatures'], $stats['photos'],
        $stats['matched_user'], $stats['errors'], $rate
    );
    echo "<span class='info'>$msg</span>\n";
    flush();

    $offset += $BATCH;
}

$elapsed = microtime(true) - $startTime;
echo "\n<span class='ok'>✅ เสร็จสิ้น — ใช้เวลา " . round($elapsed, 1) . "s</span>\n";

?></div>

<div class="summary">
    <h3 style="margin-top:0">📊 สรุปผล</h3>
    <div class="stats">
        <div class="stat"><div class="stat-num"><?= number_format($stats['processed']) ?></div><div class="stat-label">Processed</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['inserted']) ?></div><div class="stat-label">Inserted</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['skipped']) ?></div><div class="stat-label">Skipped</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['signatures']) ?></div><div class="stat-label">Signatures saved</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['photos']) ?></div><div class="stat-label">Photos copied</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['matched_user']) ?></div><div class="stat-label">Matched users</div></div>
        <div class="stat"><div class="stat-num" style="color:#f59e0b"><?= number_format($stats['no_photo']) ?></div><div class="stat-label">No photo</div></div>
        <div class="stat"><div class="stat-num" style="color:#ef4444"><?= number_format($stats['errors']) ?></div><div class="stat-label">Errors</div></div>
    </div>

    <?php if ($dryRun): ?>
        <p style="margin-top:20px; padding: 12px 16px; background: #fef3c7; border-radius:8px; border-left: 3px solid #f59e0b;">
            <strong>💡 Dry-run mode:</strong> ไม่เขียน DB / ไม่ copy files จริง — ลอง <code>migrate_welfarecard_legacy_data.php</code> (ไม่มี ?dry=1) เพื่อรันจริง
        </p>
    <?php else: ?>
        <p style="margin-top:20px; padding: 12px 16px; background: #dcfce7; border-radius:8px; border-left: 3px solid #22c55e;">
            <strong>✅ Migration เสร็จ:</strong> ขั้นตอนต่อไป — รัน <code>migrate_welfarelog_history.php</code> เพื่อ migrate audit log
        </p>
    <?php endif; ?>
</div>

</body>
</html>
