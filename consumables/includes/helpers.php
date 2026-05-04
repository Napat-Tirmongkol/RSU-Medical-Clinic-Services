<?php
/**
 * consumables/includes/helpers.php
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

if (!function_exists('csm_status_options')) {
    function csm_status_options(): array {
        return ['active' => 'ใช้งาน', 'inactive' => 'ปิด'];
    }
}

if (!function_exists('csm_txn_label')) {
    /** label + class ของประเภทรายการ */
    function csm_txn_label(string $type): array {
        return match ($type) {
            'receive' => ['label' => 'รับเข้า',  'class' => 'bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5]'],
            'issue'   => ['label' => 'เบิกออก',  'class' => 'bg-amber-50 text-amber-700 border border-amber-200'],
            'adjust'  => ['label' => 'ปรับยอด',  'class' => 'bg-sky-50 text-sky-700 border border-sky-200'],
            'dispose' => ['label' => 'จำหน่าย',  'class' => 'bg-rose-50 text-rose-700 border border-rose-200'],
            default   => ['label' => $type,      'class' => 'bg-slate-100 text-slate-600'],
        };
    }
}

if (!function_exists('csm_txn_options')) {
    function csm_txn_options(): array {
        return [
            'receive' => 'รับเข้า',
            'issue'   => 'เบิกออก',
            'adjust'  => 'ปรับยอด',
            'dispose' => 'จำหน่าย',
        ];
    }
}

if (!function_exists('csm_generate_code')) {
    /** สร้าง code อัตโนมัติแบบ CSM-YYYY-#### (running per year) */
    function csm_generate_code(PDO $pdo): string {
        $year   = date('Y');
        $prefix = "CSM-{$year}-";
        $stmt   = $pdo->prepare(
            "SELECT code FROM consumables WHERE code LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) {
            $next = (int)$m[1] + 1;
        }
        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('csm_log_txn')) {
    /**
     * บันทึก transaction + อัปเดต qty_on_hand แบบ atomic
     * คืน balance_after, หรือ throw RuntimeException ถ้า stock ไม่พอ
     */
    function csm_log_txn(
        PDO $pdo,
        int $consumableId,
        string $txnType,        // receive|issue|adjust|dispose
        int $qtyChange,         // จำนวนชิ้น signed (+/-)
        string $unitInput,      // pack|piece
        int $qtyInput,
        ?int $facultyId = null,
        ?string $requesterName = null,
        ?string $purpose = null,
        ?string $reference = null,
        ?string $note = null,
        ?string $txnDate = null
    ): int {
        $txnDate = $txnDate ?: date('Y-m-d');

        // lock row + อ่านยอดปัจจุบัน
        $stmt = $pdo->prepare("SELECT qty_on_hand FROM consumables WHERE id = ? FOR UPDATE");
        $stmt->execute([$consumableId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('ไม่พบรายการวัสดุสิ้นเปลือง');
        }
        $balanceAfter = (int)$current + $qtyChange;
        if ($balanceAfter < 0) {
            throw new RuntimeException(
                'จำนวนคงเหลือไม่พอ (คงเหลือ ' . (int)$current . ' ชิ้น, ขอเบิก ' . abs($qtyChange) . ' ชิ้น)'
            );
        }

        // อัปเดต stock
        $up = $pdo->prepare("UPDATE consumables SET qty_on_hand = ?, updated_by = ? WHERE id = ?");
        $up->execute([$balanceAfter, $_SESSION['user_id'] ?? null, $consumableId]);

        // บันทึก transaction
        $ins = $pdo->prepare("INSERT INTO consumable_transactions
            (consumable_id, txn_type, qty_change, unit_input, qty_input, balance_after,
             faculty_id, requester_name, purpose, reference, note, txn_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $consumableId, $txnType, $qtyChange, $unitInput, $qtyInput, $balanceAfter,
            $facultyId, $requesterName, $purpose, $reference, $note, $txnDate,
            $_SESSION['user_id'] ?? null,
        ]);
        return $balanceAfter;
    }
}

if (!function_exists('csm_pagination_html')) {
    /**
     * Pagination control ตาม CLAUDE.md (« ‹ เลขหน้า ±2 › »)
     */
    function csm_pagination_html(int $page, int $totalPages, int $totalItems, array $extraQuery = []): string {
        if ($totalPages < 1) $totalPages = 1;
        $page = max(1, min($page, $totalPages));

        $build = function (int $p) use ($extraQuery): string {
            $q = array_merge($extraQuery, ['page' => $p]);
            return '?' . http_build_query($q);
        };
        $btn = function (string $href, string $label, bool $disabled = false, bool $active = false) {
            $cls = $active ? 'active' : ($disabled ? 'disabled' : '');
            return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">' . $label . '</a>';
        };

        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);

        $html  = '<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-5 pt-4 border-t border-[#f0faf4]">';
        $html .= '<div class="text-sm text-slate-600">หน้า <strong class="text-[#2e9e63]">' . $page . '</strong> / ' . $totalPages
              . ' · รวม <strong>' . number_format($totalItems) . '</strong> รายการ</div>';
        $html .= '<div class="asset-pagination flex flex-wrap items-center gap-1">';
        $html .= $btn($build(1),         '«', $page <= 1);
        $html .= $btn($build($page - 1), '‹', $page <= 1);
        if ($start > 1) {
            $html .= $btn($build(1), '1');
            if ($start > 2) $html .= '<span class="px-2 text-slate-400">…</span>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $html .= $btn($build($i), (string)$i, false, $i === $page);
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) $html .= '<span class="px-2 text-slate-400">…</span>';
            $html .= $btn($build($totalPages), (string)$totalPages);
        }
        $html .= $btn($build($page + 1),   '›', $page >= $totalPages);
        $html .= $btn($build($totalPages), '»', $page >= $totalPages);
        $html .= '</div></div>';
        return $html;
    }
}

if (!function_exists('csm_handle_image_upload')) {
    function csm_handle_image_upload(string $field, ?string $oldPath = null): ?string {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return $oldPath;
        }
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (error ' . $f['error'] . ')');
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('ไฟล์ใหญ่เกิน 5 MB');
        }
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('รองรับเฉพาะ JPG/PNG/WebP/GIF');
        }
        $ext     = $allowed[$mime];
        $name    = 'csm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = __DIR__ . '/../uploads/';
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        if (!move_uploaded_file($f['tmp_name'], $destDir . $name)) {
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
        }
        if ($oldPath && str_starts_with($oldPath, 'uploads/')) {
            $abs = __DIR__ . '/../' . $oldPath;
            if (is_file($abs)) @unlink($abs);
        }
        return 'uploads/' . $name;
    }
}

if (!function_exists('csm_faculty_list')) {
    /** ดึงรายการหน่วยงาน/คณะ จัดกลุ่มตาม type */
    function csm_faculty_list(PDO $pdo): array {
        try {
            $rows = $pdo->query("SELECT id, name_th, type FROM sys_faculties ORDER BY type, name_th")
                        ->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['faculty' => [], 'department' => []];
        }
        $grouped = ['faculty' => [], 'department' => []];
        foreach ($rows as $r) {
            $type = $r['type'] ?? 'faculty';
            if (!isset($grouped[$type])) $grouped[$type] = [];
            $grouped[$type][] = $r;
        }
        return $grouped;
    }
}
