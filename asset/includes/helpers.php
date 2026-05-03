<?php
/**
 * asset/includes/helpers.php
 * ฟังก์ชันช่วยสำหรับโมดูลครุภัณฑ์
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

if (!function_exists('asset_status_label')) {
    /** label + สีของสถานะ → ['label','class'] */
    function asset_status_label(string $status): array {
        return match ($status) {
            'in_use'   => ['label' => 'ใช้งาน',  'class' => 'bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5]'],
            'repair'   => ['label' => 'ซ่อม',    'class' => 'bg-amber-50 text-amber-700 border border-amber-200'],
            'reserve'  => ['label' => 'สำรอง',   'class' => 'bg-sky-50 text-sky-700 border border-sky-200'],
            'disposed' => ['label' => 'จำหน่าย', 'class' => 'bg-slate-100 text-slate-600 border border-slate-200'],
            'lost'     => ['label' => 'สูญหาย',  'class' => 'bg-rose-50 text-rose-700 border border-rose-200'],
            default    => ['label' => $status,   'class' => 'bg-slate-100 text-slate-600'],
        };
    }
}

if (!function_exists('asset_status_options')) {
    function asset_status_options(): array {
        return [
            'in_use'   => 'ใช้งาน',
            'repair'   => 'ซ่อม',
            'reserve'  => 'สำรอง',
            'disposed' => 'จำหน่าย',
            'lost'     => 'สูญหาย',
        ];
    }
}

if (!function_exists('asset_generate_code')) {
    /** สร้าง asset_code อัตโนมัติแบบ AST-YYYY-#### (running per year) */
    function asset_generate_code(PDO $pdo): string {
        $year   = date('Y');
        $prefix = "AST-{$year}-";
        $stmt   = $pdo->prepare(
            "SELECT asset_code FROM assets WHERE asset_code LIKE ? ORDER BY id DESC LIMIT 1"
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

if (!function_exists('asset_log_movement')) {
    function asset_log_movement(
        PDO $pdo,
        int $assetId,
        string $action,
        ?int $fromLocId = null,
        ?int $toLocId = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $reason = null
    ): void {
        $stmt = $pdo->prepare(
            "INSERT INTO asset_movements
                (asset_id, action, from_location_id, to_location_id, from_status, to_status, reason, moved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $assetId, $action, $fromLocId, $toLocId,
            $fromStatus, $toStatus, $reason,
            $_SESSION['user_id'] ?? null,
        ]);
    }
}

if (!function_exists('asset_pagination_html')) {
    /**
     * เรนเดอร์ pagination control ตาม CLAUDE.md
     *  - ปุ่ม «  ‹  เลขหน้า (window ±2)  ›  »
     *  - แสดง "หน้า X / Y · รวม N รายการ"
     *  - ใช้ query string เดิม (เช่น search/filter) แล้วเปลี่ยนเฉพาะ page
     */
    function asset_pagination_html(int $page, int $totalPages, int $totalItems, array $extraQuery = []): string {
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
        $html .= $btn($build(1),          '«', $page <= 1);
        $html .= $btn($build($page - 1),  '‹', $page <= 1);
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
        $html .= $btn($build($page + 1),    '›', $page >= $totalPages);
        $html .= $btn($build($totalPages),  '»', $page >= $totalPages);
        $html .= '</div></div>';
        return $html;
    }
}

if (!function_exists('asset_handle_image_upload')) {
    /**
     * จัดการ upload รูปภาพ ($_FILES key)
     * คืน path สำหรับเก็บใน DB หรือ null
     * โยน Exception ถ้าผิด format/ใหญ่เกินไป
     */
    function asset_handle_image_upload(string $field, ?string $oldPath = null): ?string {
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
        $name    = 'asset_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = __DIR__ . '/../uploads/';
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $destPath = $destDir . $name;
        if (!move_uploaded_file($f['tmp_name'], $destPath)) {
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
        }
        // ลบไฟล์เก่า (ถ้ามี และไม่ใช่ค่าเริ่มต้น)
        if ($oldPath && str_starts_with($oldPath, 'uploads/')) {
            $abs = __DIR__ . '/../' . $oldPath;
            if (is_file($abs)) @unlink($abs);
        }
        return 'uploads/' . $name;
    }
}
