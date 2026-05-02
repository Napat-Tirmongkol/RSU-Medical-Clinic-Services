<?php
/**
 * insurance_partner/import_policy.php
 * อัปโหลด CSV เลขกรมธรรม์กลับ — update เฉพาะ row ที่ insurance_company ตรงกับ partner
 *
 * CSV columns ที่รองรับ (case-insensitive, header row บรรทัดแรก):
 *   - member_id        (required) — รหัสบุคลากร/นักศึกษา
 *   - policy_number    (required) — เลขกรมธรรม์
 *   - coverage_start   (optional, YYYY-MM-DD)
 *   - coverage_end     (optional, YYYY-MM-DD)
 *   - remarks          (optional)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_ins_partner_login();

$partner = current_ins_partner();
$companyCode = $partner['company_code'];
$pdo = db();

$alert = null; // ['type' => 'success|error', 'msg' => '..', 'detail' => [...]]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();

    if (!isset($_FILES['policy_file']) || $_FILES['policy_file']['error'] !== UPLOAD_ERR_OK) {
        $alert = ['type' => 'error', 'msg' => 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาเลือกไฟล์ใหม่'];
    } else {
        $tmp = $_FILES['policy_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['policy_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $alert = ['type' => 'error', 'msg' => 'รองรับเฉพาะไฟล์ .csv (กรุณา Save As CSV จาก Excel)'];
        } elseif (filesize($tmp) > 5 * 1024 * 1024) {
            $alert = ['type' => 'error', 'msg' => 'ขนาดไฟล์ต้องไม่เกิน 5MB'];
        } else {
            $raw = file_get_contents($tmp);
            // decode encoding
            if (mb_detect_encoding($raw, ['UTF-8'], true) !== 'UTF-8') {
                $cv = iconv('Windows-874', 'UTF-8//TRANSLIT//IGNORE', $raw);
                if ($cv !== false) $raw = $cv;
            }
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // strip BOM

            $lines = preg_split('/\r\n|\r|\n/', trim($raw));
            if (count($lines) < 2) {
                $alert = ['type' => 'error', 'msg' => 'ไฟล์ CSV ต้องมีอย่างน้อย 1 แถวข้อมูล (ไม่นับ header)'];
            } else {
                // parse header
                $headerCsv = str_getcsv(array_shift($lines));
                $headerMap = [];
                foreach ($headerCsv as $i => $h) {
                    $key = strtolower(trim((string)$h));
                    // alias mapping (รองรับชื่อภาษาไทย)
                    $aliases = [
                        'รหัสบุคลากร/นักศึกษา' => 'member_id',
                        'รหัสนักศึกษา'           => 'member_id',
                        'รหัสบุคลากร'           => 'member_id',
                        'memberid'              => 'member_id',
                        'เลขกรมธรรม์'           => 'policy_number',
                        'policy'                => 'policy_number',
                        'policyno'              => 'policy_number',
                        'policy_no'             => 'policy_number',
                        'วันเริ่มต้นสิทธิ์'      => 'coverage_start',
                        'coverage start'        => 'coverage_start',
                        'startdate'             => 'coverage_start',
                        'วันสิ้นสุดสิทธิ์'       => 'coverage_end',
                        'coverage end'          => 'coverage_end',
                        'enddate'               => 'coverage_end',
                        'หมายเหตุ'              => 'remarks',
                    ];
                    $key = $aliases[$key] ?? $key;
                    $headerMap[$i] = $key;
                }

                if (!in_array('member_id', $headerMap, true) || !in_array('policy_number', $headerMap, true)) {
                    $alert = ['type' => 'error', 'msg' => 'ไฟล์ต้องมี column "member_id" และ "policy_number" (หรือชื่อไทยที่รองรับ)'];
                } else {
                    $pdo->beginTransaction();
                    try {
                        $cntUpdated = 0;
                        $cntNotFound = 0;
                        $cntScopeBlocked = 0;
                        $errors = [];

                        $checkStmt = $pdo->prepare("
                            SELECT member_id, insurance_company, policy_number, insurance_status
                            FROM insurance_members
                            WHERE member_id = :mid
                            LIMIT 1
                        ");
                        $updateStmt = $pdo->prepare("
                            UPDATE insurance_members
                            SET policy_number  = :pn,
                                coverage_start = COALESCE(:cs, coverage_start),
                                coverage_end   = COALESCE(:ce, coverage_end),
                                remarks        = CASE WHEN :rm <> '' THEN :rm2 ELSE remarks END
                            WHERE member_id = :mid AND insurance_company = :cc
                        ");
                        $syncId = (int)$pdo->query("SELECT COALESCE(MAX(sync_id), 0) + 1 FROM insurance_member_history")->fetchColumn();
                        $historyStmt = $pdo->prepare("
                            INSERT INTO insurance_member_history
                                (member_id, sync_id, change_type, old_status, new_status, snapshot)
                            VALUES (:mid, :sid, 'policy_assigned', :old, :new, :snap)
                        ");

                        foreach ($lines as $ln => $line) {
                            $line = trim((string)$line);
                            if ($line === '') continue;
                            $cols = str_getcsv($line);
                            $row = [];
                            foreach ($cols as $i => $val) {
                                $key = $headerMap[$i] ?? null;
                                if ($key) $row[$key] = trim((string)$val);
                            }
                            $memberId = $row['member_id'] ?? '';
                            $policyNo = $row['policy_number'] ?? '';

                            if ($memberId === '' || $policyNo === '') {
                                $errors[] = "บรรทัด " . ($ln + 2) . ": member_id หรือ policy_number ว่าง";
                                continue;
                            }

                            $checkStmt->execute([':mid' => $memberId]);
                            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                            if (!$existing) {
                                $cntNotFound++;
                                $errors[] = "บรรทัด " . ($ln + 2) . ": ไม่พบ member_id={$memberId}";
                                continue;
                            }
                            if (($existing['insurance_company'] ?? '') !== $companyCode) {
                                $cntScopeBlocked++;
                                $errors[] = "บรรทัด " . ($ln + 2) . ": member_id={$memberId} ไม่ใช่ลูกค้าของบริษัทคุณ";
                                continue;
                            }

                            $cs = $row['coverage_start'] ?? '';
                            $ce = $row['coverage_end']   ?? '';
                            $rm = $row['remarks']        ?? '';

                            $updateStmt->execute([
                                ':pn'  => $policyNo,
                                ':cs'  => $cs !== '' ? $cs : null,
                                ':ce'  => $ce !== '' ? $ce : null,
                                ':rm'  => $rm,
                                ':rm2' => $rm,
                                ':mid' => $memberId,
                                ':cc'  => $companyCode,
                            ]);

                            if ($updateStmt->rowCount() > 0) {
                                $cntUpdated++;
                                $historyStmt->execute([
                                    ':mid'  => $memberId,
                                    ':sid'  => $syncId,
                                    ':old'  => $existing['policy_number'] ?: 'no_policy',
                                    ':new'  => $policyNo,
                                    ':snap' => json_encode([
                                        'policy_number'  => $policyNo,
                                        'coverage_start' => $cs,
                                        'coverage_end'   => $ce,
                                        'remarks'        => $rm,
                                        'by_partner'     => $partner['username'],
                                        'company'        => $companyCode,
                                    ], JSON_UNESCAPED_UNICODE),
                                ]);
                            }
                        }

                        $pdo->commit();

                        $detail = [
                            "อัปเดตเรียบร้อย {$cntUpdated} ราย",
                            "ไม่พบในระบบ {$cntNotFound} ราย",
                            "ไม่ใช่ลูกค้าบริษัทคุณ {$cntScopeBlocked} ราย",
                        ];
                        if ($errors) {
                            $detail = array_merge($detail, ['— รายละเอียด —'], array_slice($errors, 0, 50));
                            if (count($errors) > 50) $detail[] = "(และอีก " . (count($errors) - 50) . " บรรทัด)";
                        }

                        $alert = [
                            'type' => $cntUpdated > 0 ? 'success' : 'error',
                            'msg'  => $cntUpdated > 0
                                ? "สำเร็จ — อัปเดตเลขกรมธรรม์ {$cntUpdated} ราย"
                                : "ไม่มีรายการที่อัปเดตได้",
                            'detail' => $detail,
                        ];

                        ins_partner_log('import_policy',
                            "company={$companyCode}, sync_id={$syncId}, updated={$cntUpdated}, not_found={$cntNotFound}, blocked={$cntScopeBlocked}");
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $alert = ['type' => 'error', 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
                        ins_partner_log('import_policy_error', $e->getMessage());
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/layout.php';
ins_partner_layout_start('อัปโหลดเลขกรมธรรม์', 'import');
?>

<h1 class="ipp-page-title">อัปโหลดเลขกรมธรรม์กลับ</h1>
<p class="ipp-page-sub">อัปเดต policy_number จากไฟล์ CSV ที่ออกกรมธรรม์เรียบร้อยแล้ว</p>

<?php if ($alert): ?>
<div class="ipp-alert <?= htmlspecialchars($alert['type']) ?>">
    <strong>
        <i class="fa-solid fa-<?= $alert['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?> mr-1"></i>
        <?= htmlspecialchars($alert['msg']) ?>
    </strong>
    <?php if (!empty($alert['detail'])): ?>
    <ul style="margin-top:.5rem; font-size:.8rem;">
        <?php foreach ($alert['detail'] as $d): ?>
        <li><?= htmlspecialchars($d) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="ipp-card">
    <h3><i class="fa-solid fa-file-arrow-up mr-1"></i> อัปโหลดไฟล์ CSV</h3>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <div class="ipp-form-row">
            <label>เลือกไฟล์ CSV (ขนาดไม่เกิน 5MB)</label>
            <input type="file" name="policy_file" accept=".csv,.txt" required>
        </div>
        <button type="submit" class="ipp-btn">
            <i class="fa-solid fa-cloud-arrow-up"></i> เริ่มอัปโหลด
        </button>
    </form>
</div>

<div class="ipp-card">
    <h3><i class="fa-solid fa-circle-info mr-1"></i> รูปแบบไฟล์ที่รองรับ</h3>
    <p style="font-size:.85rem; color:#374151;">
        Header บรรทัดแรกต้องประกอบด้วย column ต่อไปนี้ (รองรับทั้งชื่ออังกฤษและไทย):
    </p>
    <table class="ipp-table" style="margin-top:.5rem;">
        <thead><tr><th>Column</th><th>จำเป็น</th><th>คำอธิบาย</th></tr></thead>
        <tbody>
            <tr><td><code>member_id</code> / รหัสบุคลากร/นักศึกษา</td><td>✅ ใช่</td><td>ต้องตรงกับที่ Export ออกไป</td></tr>
            <tr><td><code>policy_number</code> / เลขกรมธรรม์</td><td>✅ ใช่</td><td>เลขกรมธรรม์ที่บริษัทออกให้</td></tr>
            <tr><td><code>coverage_start</code> / วันเริ่มต้นสิทธิ์</td><td>—</td><td>YYYY-MM-DD (เช่น 2026-05-15)</td></tr>
            <tr><td><code>coverage_end</code> / วันสิ้นสุดสิทธิ์</td><td>—</td><td>YYYY-MM-DD</td></tr>
            <tr><td><code>remarks</code> / หมายเหตุ</td><td>—</td><td>ข้อความเพิ่มเติม (optional)</td></tr>
        </tbody>
    </table>
    <p style="font-size:.78rem; color:#6b7280; margin-top:.65rem;">
        <i class="fa-solid fa-shield-halved mr-1"></i>
        ระบบจะอัปเดตเฉพาะ member_id ที่อยู่ในขอบเขตของบริษัท <strong><?= htmlspecialchars($partner['company_name']) ?></strong> เท่านั้น
    </p>
</div>

<?php
ins_partner_layout_end();
