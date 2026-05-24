<?php
// admin/campaigns.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$message = '';
$messageType = '';

// Ensure qr_enabled column exists
try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN qr_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}
// Ensure room_id (link to sys_clinic_rooms) — added for campaign location
try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN room_id INT UNSIGNED NULL DEFAULT NULL"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE camp_list ADD INDEX idx_room_id (room_id)"); } catch (PDOException) {}

// ── Self-heal additional fields (added per UX audit) ─────────────
foreach ([
    "ADD COLUMN available_from DATE NULL DEFAULT NULL",
    "ADD COLUMN target_audience ENUM('all','student','staff','other') NOT NULL DEFAULT 'all'",
    "ADD COLUMN what_to_bring TEXT NULL",
    "ADD COLUMN prerequisites TEXT NULL",
    "ADD COLUMN contact_phone VARCHAR(30) NULL",
    "ADD COLUMN cover_image VARCHAR(255) NULL",
    "ADD COLUMN max_per_user TINYINT UNSIGNED NOT NULL DEFAULT 1",
    "ADD COLUMN cancel_deadline_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24",
] as $_ddl) {
    try { $pdo->exec("ALTER TABLE camp_list $_ddl"); } catch (PDOException) {}
}

// Load active clinic rooms for the location selector
$clinicRooms = [];
try {
    $clinicRooms = $pdo->query(
        "SELECT id, code, name, type, floor
           FROM sys_clinic_rooms
          WHERE is_active = 1
          ORDER BY type ASC, code ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}
$roomTypeLabels = [
    'exam'        => 'ห้องตรวจ',
    'vaccination' => 'ห้องฉีดวัคซีน',
    'lab'         => 'ห้องแล็บ',
    'consult'     => 'ห้องให้คำปรึกษา',
    'other'       => 'อื่นๆ',
];

// ==========================================
// ส่วนจัดการ POST Request (เพิ่ม / แก้ไข / ลบ แคมเปญ)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $action = $_POST['action'] ?? '';

    $title = trim($_POST['title'] ?? '');
    $type = $_POST['type'] ?? 'other';
    $description = trim($_POST['description'] ?? '');
    $capacity = (int) ($_POST['total_capacity'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $availableUntil = !empty($_POST['available_until']) ? $_POST['available_until'] : null;
    $isAutoApprove = (int) ($_POST['is_auto_approve'] ?? 0);
    // Optional vaccine-catalog linkage. Only stored when type='vaccine' —
    // otherwise we explicitly NULL it so changing a vaccine campaign to a
    // different type doesn't leave a dangling reference.
    $vaccineTypeId = ($type === 'vaccine' && !empty($_POST['vaccine_type_id']))
        ? (int)$_POST['vaccine_type_id']
        : null;
    $roomId = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;

    $availableFrom        = !empty($_POST['available_from']) ? $_POST['available_from'] : null;
    $targetAudience       = in_array($_POST['target_audience'] ?? 'all', ['all','student','staff','other'], true) ? $_POST['target_audience'] : 'all';
    $whatToBring          = trim($_POST['what_to_bring'] ?? '');
    $prerequisites        = trim($_POST['prerequisites'] ?? '');
    $contactPhone         = trim($_POST['contact_phone'] ?? '');
    $maxPerUser           = max(1, min(99, (int)($_POST['max_per_user'] ?? 1)));
    $cancelDeadlineHours  = max(0, min(720, (int)($_POST['cancel_deadline_hours'] ?? 24)));

    // ── Cover image — keep existing OR upload new OR remove ───────
    // Logic priority: remove_cover=1 → NULL · new file → save · else keep current
    $coverImage = trim($_POST['cover_image_existing'] ?? '');
    $removeCover = !empty($_POST['remove_cover']) && $_POST['remove_cover'] == '1';
    $coverUploadError = '';

    if ($removeCover) {
        // Delete old file from disk if it's under uploads/campaigns/
        if (!empty($coverImage)) {
            $oldAbs = realpath(__DIR__ . '/../' . $coverImage);
            $root   = realpath(__DIR__ . '/../uploads/campaigns') ?: '';
            if ($oldAbs && $root && strpos($oldAbs, $root) === 0 && is_file($oldAbs)) {
                @unlink($oldAbs);
            }
        }
        $coverImage = '';
    } elseif (!empty($_FILES['cover_image']['name']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['cover_image'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            $coverUploadError = 'ขนาดรูปต้องไม่เกิน 5MB';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $coverUploadError = 'ไฟล์อัปโหลดไม่ถูกต้อง';
        } else {
            $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']) ?: '';
            if (!isset($mimeToExt[$mime])) {
                $coverUploadError = 'รองรับเฉพาะ JPG, PNG, WEBP';
            } else {
                $sub = date('Y/m');
                $dir = __DIR__ . "/../uploads/campaigns/$sub";
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                // drop .htaccess deny-all at uploads/campaigns root once
                $rootHt = __DIR__ . '/../uploads/campaigns/.htaccess';
                if (!file_exists($rootHt)) {
                    @file_put_contents($rootHt, "Order deny,allow\nDeny from all\n<FilesMatch \"\\.(jpg|jpeg|png|webp)$\">\n    Order allow,deny\n    Allow from all\n</FilesMatch>\n");
                }
                $name   = bin2hex(random_bytes(12)) . '.' . $mimeToExt[$mime];
                $target = "$dir/$name";
                if (move_uploaded_file($file['tmp_name'], $target)) {
                    // Delete old file if replacing
                    if (!empty($coverImage)) {
                        $oldAbs = realpath(__DIR__ . '/../' . $coverImage);
                        $root   = realpath(__DIR__ . '/../uploads/campaigns') ?: '';
                        if ($oldAbs && $root && strpos($oldAbs, $root) === 0 && is_file($oldAbs)) {
                            @unlink($oldAbs);
                        }
                    }
                    $coverImage = "uploads/campaigns/$sub/$name";
                } else {
                    $coverUploadError = 'บันทึกไฟล์ไม่สำเร็จ';
                }
            }
        }
    }

    // 1. สร้างแคมเปญใหม่
    if ($action === 'add' && $title && $capacity >= 0) {
        try {
            $newToken = bin2hex(random_bytes(8)); // 16-char hex token
            // Ensure vaccine_type_id column exists before INSERT (self-heal)
            try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS vaccine_type_id INT UNSIGNED NULL DEFAULT NULL"); } catch (PDOException) {}
            $sql = "INSERT INTO camp_list (title, type, description, total_capacity,
                    available_from, available_until, status, is_auto_approve, share_token, vaccine_type_id, room_id,
                    target_audience, what_to_bring, prerequisites, contact_phone, cover_image, max_per_user, cancel_deadline_hours)
                    VALUES (:title, :type, :description, :capacity,
                    :avail_from, :until, :status, :auto_approve, :token, :vtid, :room_id,
                    :audience, :bring, :prereq, :phone, :cover, :max_per_user, :cancel_hours)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':title' => $title,
                ':type' => $type,
                ':description' => $description,
                ':capacity' => $capacity,
                ':avail_from'    => $availableFrom,
                ':until' => $availableUntil,
                ':status' => $status,
                ':auto_approve' => $isAutoApprove,
                ':token' => $newToken,
                ':vtid' => $vaccineTypeId,
                ':room_id' => $roomId,
                ':audience'      => $targetAudience,
                ':bring'         => $whatToBring ?: null,
                ':prereq'        => $prerequisites ?: null,
                ':phone'         => $contactPhone ?: null,
                ':cover'         => $coverImage ?: null,
                ':max_per_user'  => $maxPerUser,
                ':cancel_hours'  => $cancelDeadlineHours,
            ]);
            $message = "สร้างแคมเปญเรียบร้อยแล้ว!" . ($coverUploadError ? " ⚠ รูปหน้าปก: {$coverUploadError}" : '');
            $messageType = $coverUploadError ? "warning" : "success";
            log_activity('create_campaign', "สร้างแคมเปญใหม่: {$title} (จุ {$capacity} คน)");
        } catch (PDOException $e) {
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $messageType = "error";
        }
    }

    // 2. แก้ไขแคมเปญ
    if ($action === 'edit') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id > 0 && $title && $capacity >= 0) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = :id AND status IN ('booked', 'confirmed')");
                $check->execute([':id' => $id]);
                $used = (int) $check->fetchColumn();

                if ($capacity < $used) {
                    $message = "จำนวนโควต้ารวม ต้องไม่น้อยกว่าจำนวนผู้ที่ลงทะเบียนไปแล้ว ({$used} คน)";
                    $messageType = "error";
                } else {
                    try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS vaccine_type_id INT UNSIGNED NULL DEFAULT NULL"); } catch (PDOException) {}
                    $sql = "UPDATE camp_list SET title = :title, type = :type, description = :description,
                            total_capacity = :capacity, available_from = :avail_from, available_until = :until, status = :status,
                            is_auto_approve = :auto_approve, vaccine_type_id = :vtid, room_id = :room_id,
                            target_audience = :audience, what_to_bring = :bring, prerequisites = :prereq,
                            contact_phone = :phone, cover_image = :cover,
                            max_per_user = :max_per_user, cancel_deadline_hours = :cancel_hours
                            WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':title' => $title,
                        ':type' => $type,
                        ':description' => $description,
                        ':capacity' => $capacity,
                        ':avail_from'    => $availableFrom,
                        ':until' => $availableUntil,
                        ':status' => $status,
                        ':auto_approve' => $isAutoApprove,
                        ':vtid' => $vaccineTypeId,
                        ':room_id' => $roomId,
                        ':audience'      => $targetAudience,
                        ':bring'         => $whatToBring ?: null,
                        ':prereq'        => $prerequisites ?: null,
                        ':phone'         => $contactPhone ?: null,
                        ':cover'         => $coverImage ?: null,
                        ':max_per_user'  => $maxPerUser,
                        ':cancel_hours'  => $cancelDeadlineHours,
                        ':id' => $id
                    ]);
                    $message = "อัปเดตข้อมูลแคมเปญสำเร็จ!" . ($coverUploadError ? " ⚠ รูปหน้าปก: {$coverUploadError}" : '');
                    $messageType = $coverUploadError ? "warning" : "success";
                    log_activity('update_campaign', "แก้ไขแคมเปญ ID: {$id} ({$title})");

                    // Propagate the catalog linkage to historical
                    // user_vaccination_records — only fills in NULL fields so
                    // a manual override on any record stays intact. Same
                    // intent as Phase-2 backfill but triggered eagerly when
                    // the admin saves the campaign.
                    if ($type === 'vaccine' && $vaccineTypeId !== null) {
                        try {
                            $stmt = $pdo->prepare("
                                UPDATE user_vaccination_records v
                                JOIN camp_bookings b ON b.id = v.campaign_booking_id
                                LEFT JOIN sys_vaccine_types t ON t.id = :vtid
                                SET v.vaccine_type_id = COALESCE(v.vaccine_type_id, :vtid),
                                    v.manufacturer    = COALESCE(NULLIF(v.manufacturer, ''), t.default_manufacturer)
                                WHERE b.campaign_id = :cid
                                  AND (v.vaccine_type_id IS NULL
                                       OR (v.manufacturer IS NULL OR v.manufacturer = ''))
                            ");
                            $stmt->execute([':vtid' => $vaccineTypeId, ':cid' => $id]);
                            $propagated = $stmt->rowCount();
                            if ($propagated > 0) {
                                log_activity('Vaccine Catalog Propagate', "campaign_id={$id} vaccine_type_id={$vaccineTypeId} synced={$propagated}");
                            }
                        } catch (PDOException $e) {
                            error_log("[campaign] catalog propagate: " . $e->getMessage());
                        }
                    }
                }
            } catch (PDOException $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }

    // 3a. สร้าง/รีเซ็ต share token
    if ($action === 'gen_token') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id > 0) {
            try {
                $newToken = bin2hex(random_bytes(8));
                $pdo->prepare("UPDATE camp_list SET share_token = :token WHERE id = :id")
                    ->execute([':token' => $newToken, ':id' => $id]);
                $message = "สร้าง URL แชร์เรียบร้อยแล้ว!";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }

    // 3. ลบแคมเปญ
    if ($action === 'delete') {
        $id = (int) ($_POST['campaign_id'] ?? 0);
        if ($id > 0) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = :id");
                $check->execute([':id' => $id]);
                if ((int) $check->fetchColumn() > 0) {
                    $message = "ไม่สามารถลบได้ เนื่องจากมีประวัติลงทะเบียนในแคมเปญนี้แล้ว (แนะนำให้เปลี่ยนสถานะเป็นปิดชั่วคราวแทน)";
                    $messageType = "error";
                } else {
                    // ลบรอบเวลาที่เกี่ยวข้องทั้งหมดก่อนลบแคมเปญ
                    $pdo->prepare("DELETE FROM camp_slots WHERE campaign_id = :id")->execute([':id' => $id]);

                    $stmt = $pdo->prepare("DELETE FROM camp_list WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $message = "ลบแคมเปญสำเร็จ!";
                    $messageType = "success";
                    log_activity('delete_campaign', "ลบแคมเปญ ID: {$id} (พร้อมลบรอบเวลาทั้งหมดที่เกี่ยวข้อง)");
                }
            } catch (PDOException $e) {
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// ==========================================
// ดึงข้อมูลแคมเปญทั้งหมด
// ==========================================
$camp_list = [];
try {
    $sql = "
        SELECT
            c.*,
            r.code AS room_code, r.name AS room_name, r.type AS room_type, r.floor AS room_floor,
            (SELECT COUNT(*) FROM camp_bookings a WHERE a.campaign_id = c.id AND a.status IN ('booked', 'confirmed')) AS used_capacity,
            (SELECT COUNT(*) FROM camp_bookings a WHERE a.campaign_id = c.id AND a.status IN ('booked', 'confirmed', 'completed')) AS occupied_capacity
        FROM camp_list c
        LEFT JOIN sys_clinic_rooms r ON c.room_id = r.id
        ORDER BY
            CASE
                WHEN c.status = 'active' AND (c.available_until IS NULL OR c.available_until >= CURDATE()) THEN 0
                ELSE 1
            END ASC,
            c.created_at DESC
    ";
    $stmt = $pdo->query($sql);
    $camp_list = $stmt->fetchAll();

    // Active vaccine catalog — drives the "ประเภทวัคซีน" dropdown in the
    // create/edit modal. Wrapped in try/catch so a fresh deploy (where
    // sys_vaccine_types may not exist yet) doesn't break the page.
    $vaccineTypes = [];
    try {
        $vaccineTypes = $pdo->query("
            SELECT id, code, name_th, default_doses, interval_days, default_manufacturer
            FROM sys_vaccine_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, name_th ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* catalog not migrated yet — dropdown stays empty */ }
} catch (PDOException $e) {
    $message = "ไม่พบตารางข้อมูล กรุณาตรวจสอบ Database";
    $messageType = "error";
}

function getCampaignTypeDetails($type)
{
    return match ($type) {
        'vaccine' => ['label' => 'ฉีดวัคซีน', 'color' => 'text-[#2e9e63]', 'bg' => 'bg-emerald-50', 'icon' => 'fa-syringe', 'border' => 'border-emerald-100'],
        'training' => ['label' => 'อบรม/สัมมนา', 'color' => 'text-purple-600', 'bg' => 'bg-purple-100', 'icon' => 'fa-chalkboard-user', 'border' => 'border-purple-200'],
        'health_check' => ['label' => 'ตรวจสุขภาพ', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-100', 'icon' => 'fa-stethoscope', 'border' => 'border-emerald-200'],
        default => ['label' => 'กิจกรรมอื่นๆ', 'color' => 'text-orange-600', 'bg' => 'bg-orange-100', 'icon' => 'fa-star', 'border' => 'border-orange-200'],
    };
}

function buildShareUrl(string $token): string {
    // บังคับใช้ https เสมอเพื่อความปลอดภัยและป้องกันปัญหา Redirect ใน Browser มือถือ
    $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'];
    $adminDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $baseDir  = str_replace('\\', '/', dirname($adminDir));
    if ($baseDir === '/') $baseDir = '';
    return $scheme . '://' . $host . $baseDir . '/user/c.php?t=' . $token;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* ── Animations ───────────────────────────────────────────── */
    @keyframes slideUpFade {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%       { opacity: 0.6; transform: scale(1.4); }
    }

    .animate-slide-up { animation: slideUpFade 0.45s cubic-bezier(0.16, 1, 0.3, 1) both; }
    .delay-100 { animation-delay: 0.08s; }
    .delay-200 { animation-delay: 0.16s; }

    /* ── Scrollbar ────────────────────────────────────────────── */
    .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    /* ── Table container ──────────────────────────────────────── */
    .glass-table-container {
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 8px 32px rgba(46,158,99,.05);
        border: 1px solid #e8eef7;
        overflow: hidden;
    }

    /* Gradient thead strip */
    .glass-table-container thead tr {
        background: linear-gradient(135deg, #2e9e63 0%, #10b981 60%, #34d399 100%);
    }
    .glass-table-container thead th {
        color: rgba(255,255,255,0.85) !important;
        font-size: 10px;
        letter-spacing: .12em;
        padding-top: 18px;
        padding-bottom: 18px;
        border-bottom: none !important;
    }
    .glass-table-container thead th i {
        opacity: .7;
    }

    /* Divider colour */
    .glass-table-container tbody { border-color: #f0f4fa; }
    .glass-table-container tbody tr + tr { border-top: 1px solid #f0f4fa; }

    /* ── Row hover ────────────────────────────────────────────── */
    .glass-tr { transition: background .18s ease, box-shadow .18s ease; }
    .glass-tr:hover {
        background: #f0fdf4 !important;
        box-shadow: inset 3px 0 0 #2e9e63;
    }

    /* ── Campaign-type icon ───────────────────────────────────── */
    .camp-icon {
        width: 46px; height: 46px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,.08);
    }

    /* ── Capacity bar ─────────────────────────────────────────── */
    .cap-bar-wrap {
        width: 64px; height: 6px;
        background: #ecfdf5;
        border-radius: 99px;
        overflow: hidden;
        margin: 0 auto;
    }
    .cap-bar-fill {
        height: 100%;
        border-radius: 99px;
        transition: width .4s ease;
    }

    /* Remaining seats ring */
    .seat-ring {
        display: inline-flex; flex-direction: column; align-items: center; gap: 4px;
    }
    .seat-circle {
        width: 50px; height: 50px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 900;
        font-size: 1.1rem;
        border-width: 2px;
        border-style: solid;
        transition: transform .2s;
    }
    .glass-tr:hover .seat-circle { transform: scale(1.08); }

    /* ── Status badges ────────────────────────────────────────── */
    .status-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
        border-width: 1px;
        border-style: solid;
    }
    .status-dot {
        width: 7px; height: 7px;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }
    .dot-active { background: #10b981; animation: pulse-dot 2s ease-in-out infinite; }
    .dot-inactive { background: #9ca3af; }
    .dot-expired { background: #ef4444; }

    .badge-active   { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .badge-inactive { background: #f9fafb; color: #4b5563; border-color: #d1d5db; }
    .badge-expired  { background: #fff1f2; color: #9f1239; border-color: #fecdd3; }
    .badge-draft    { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }
    .badge-coming   { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }
    .badge-full     { background: #fef2f2; color: #ef4444; border-color: #fecaca; }
    .badge-closed   { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
    .badge-archived { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
    .badge-private  { background: #fff7ed; color: #ea580c; border-color: #ffedd5; }

    .approve-badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 10px;
        font-weight: 700;
        margin-top: 4px;
    }
    .approve-auto   { background: #f0fdf4; color: #16a34a; border: 1px solid #bcf0da; }
    .approve-manual { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }

    /* ── Action buttons ───────────────────────────────────────── */
    .act-btn {
        width: 36px; height: 36px;
        border-radius: 10px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 14px;
        transition: all .18s ease;
        border-width: 1px;
        border-style: solid;
        cursor: pointer;
    }
    .act-btn-edit {
        background: #fffbeb; color: #d97706; border-color: #fde68a;
    }
    .act-btn-edit:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; box-shadow: 0 4px 12px rgba(245,158,11,.35); transform: translateY(-1px); }
    .act-btn-delete {
        background: #fff1f2; color: #e11d48; border-color: #fecdd3;
    }
    .act-btn-delete:hover { background: #e11d48; color: #fff; border-color: #e11d48; box-shadow: 0 4px 12px rgba(225,29,72,.3); transform: translateY(-1px); }
    .act-btn-disabled {
        background: #f1f5f9; color: #94a3b8; border-color: #cbd5e1; cursor: not-allowed;
    }

    /* ── Modal ────────────────────────────────────────────────── */
    #campaignModal { z-index: 200; }
    #campQrOverlay { z-index: 210; }
    .modal-glass {
        background: #fff;
        box-shadow: 0 32px 64px rgba(0,0,0,.22), 0 0 0 1px rgba(0,0,0,.04);
        border-radius: 22px;
    }
    .modal-header {
        background: linear-gradient(135deg, #2e9e63 0%, #34d399 100%);
        padding: 22px 24px;
        border-bottom: none;
    }
    .modal-header h3 { color: #fff; font-size: 1.2rem; font-weight: 900; display: flex; align-items: center; gap: 12px; }
    .modal-header .modal-icon {
        width: 40px; height: 40px;
        background: rgba(255,255,255,.15);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem;
        color: #fff;
    }
    .modal-close-btn {
        width: 32px; height: 32px;
        border-radius: 8px;
        background: rgba(255,255,255,.15);
        color: rgba(255,255,255,.9);
        display: flex; align-items: center; justify-content: center;
        transition: background .15s;
        cursor: pointer;
        border: none;
    }
    .modal-close-btn:hover { background: rgba(255,255,255,.28); }

    /* ── Modal icon-picker cards (type) ──────────────────────── */
    .modal-type-card {
        flex: 1; min-width: 0;
        display: flex; flex-direction: column; align-items: center; gap: 6px;
        padding: 12px 4px; border: 2px solid #e5e7eb; border-radius: 12px;
        background: #fff; cursor: pointer;
        transition: border-color .18s, background .18s, color .18s, transform .15s;
        font-size: 11px; font-weight: 700; color: #9ca3af; text-align: center;
    }
    .modal-type-card i { font-size: 1.25rem; transition: color .18s; }
    .modal-type-card:hover { transform: translateY(-2px); border-color: #c7d2fe; }
    .modal-type-card.is-selected {
        border-color: var(--sel-border);
        background: var(--sel-bg);
        color: var(--sel-color);
    }

    /* ── Modal status/approve pills ──────────────────────────── */
    .modal-status-pill, .modal-approve-pill {
        flex: 1; display: flex; align-items: center; justify-content: center;
        gap: 6px; padding: 9px 10px;
        border: 2px solid #e5e7eb; border-radius: 10px;
        background: #fff; cursor: pointer;
        transition: border-color .18s, background .18s, color .18s;
        font-size: 12px; font-weight: 700; color: #9ca3af; white-space: nowrap;
    }
    .modal-status-pill.is-selected, .modal-approve-pill.is-selected {
        border-color: var(--sel-border);
        background: var(--sel-bg);
        color: var(--sel-color);
    }

    /* Form inputs */
    .form-input {
        width: 100%;
        padding: 11px 14px 11px 42px;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        font-family: 'Sarabun', sans-serif;
        color: #1e293b;
        font-size: .9rem;
        transition: border-color .15s, background .15s, box-shadow .15s;
        outline: none;
    }
    .form-input:focus {
        background: #fff;
        border-color: #2e9e63;
        box-shadow: 0 0 0 3px rgba(46,158,99,.1);
    }
    .form-input-no-icon {
        padding-left: 14px;
    }
    .form-input-icon {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: #94a3b8; pointer-events: none; font-size: .85rem;
    }
    /* ── Cover image dropzone ─────────────────────────────── */
    .cover-dropzone {
        position: relative;
        min-height: 140px;
        border: 2px dashed #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        cursor: pointer;
        transition: all .15s;
        overflow: hidden;
    }
    .cover-dropzone:hover { border-color: #2e9e63; background: #f0fdf4; }
    .cover-dropzone.is-dragover { border-color: #2e9e63; background: #ecfdf5; border-style: solid; }
    .cover-dropzone-empty {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        height: 140px; text-align: center; padding: 16px;
    }
    .cover-dropzone-preview { position: relative; min-height: 140px; }
    .cover-dropzone-preview img {
        display: block; width: 100%; max-height: 240px;
        object-fit: cover; background: #f1f5f9;
    }
    .cover-remove-btn {
        position: absolute; top: 8px; right: 8px;
        width: 32px; height: 32px; border-radius: 50%;
        background: rgba(15,23,42,.7); color: #fff; border: 0;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: all .15s;
        font-size: 14px;
    }
    .cover-remove-btn:hover { background: #ef4444; transform: scale(1.08); }
    body[data-theme='dark'] .cover-dropzone {
        background: rgba(15,23,42,.5); border-color: #334155;
    }
    body[data-theme='dark'] .cover-dropzone:hover {
        background: rgba(46,158,99,.10); border-color: var(--ec-brand-500, #2e9e63);
    }

    .form-label {
        display: block;
        font-size: .8rem;
        font-weight: 700;
        color: #374151;
        margin-bottom: 6px;
    }
    .form-section-card {
        background: #f8fafc;
        border: 1.5px solid #e8eef7;
        border-radius: 14px;
        padding: 14px;
    }

    /* Toggle label */
    .toggle-label {
        display: inline-flex; align-items: center; gap: 10px;
        background: #fff;
        padding: 8px 16px;
        border-radius: 12px;
        border: 1.5px solid #e2e8f0;
        cursor: pointer;
        font-size: .82rem;
        font-weight: 700;
        color: #475569;
        box-shadow: 0 1px 4px rgba(0,0,0,.04);
        transition: border-color .15s, box-shadow .15s;
    }
    .toggle-label:hover { border-color: #2e9e63; box-shadow: 0 2px 8px rgba(46,158,99,.1); }
</style>

<?php
$header_actions = '
<a href="../e_campaign_help.php" target="_blank" rel="noopener"
   class="bg-emerald-50 text-emerald-700 border-2 border-emerald-200 px-4 py-3 rounded-2xl font-bold transition-all hover:bg-emerald-500 hover:text-white hover:border-emerald-500 inline-flex items-center gap-2"
   title="เปิดคู่มือใช้งานในแท็บใหม่">
    <i class="fa-solid fa-book-open"></i> คู่มือ
</a>
<button onclick="openAddModal()" class="bg-[#2e9e63] text-white px-6 py-3 rounded-2xl font-bold transition-all shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/40 hover:-translate-y-1 flex items-center gap-2" style="background-color: #2e9e63;">
    <i class="fa-solid fa-plus-circle text-lg"></i> สร้างแคมเปญใหม่
</button>';
renderPageHeader("สร้างแคมเปญ", "สร้างแคมเปญใหม่, กำหนดโควต้า, และตั้งเวลารับลงทะเบียน", $header_actions);
?>

<?php if ($message): ?>
    <div
        class="mb-6 p-4 rounded-2xl text-sm font-bold border flex items-center gap-3 animate-slide-up <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
        <i
            class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check text-green-500' : 'fa-circle-exclamation text-red-500' ?> text-lg"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="flex justify-end mb-4 animate-slide-up delay-100">
    <label for="toggleInactive" class="toggle-label">
        <div class="relative">
            <input type="checkbox" id="toggleInactive" class="sr-only peer" onchange="toggleInactiveCampaigns()">
            <div class="w-10 h-5 bg-gray-200 rounded-full peer peer-checked:bg-[#2e9e63] after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border after:border-gray-300 after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:after:border-white"></div>
        </div>
        <i id="toggleIcon" class="fa-solid fa-eye-slash text-gray-400 text-xs"></i>
        <span id="toggleLabel">แสดงแคมเปญที่ปิด/หมดเขต</span>
    </label>
</div>

<div class="glass-table-container animate-slide-up delay-100 mb-10 overflow-x-auto">
    <table class="w-full text-left text-sm" style="table-layout:fixed; min-width:680px">
        <colgroup>
            <col><!-- title: flexible -->
            <col style="width:150px"><!-- date -->
            <col style="width:120px"><!-- seats -->
            <col style="width:150px"><!-- status -->
            <col style="width:110px"><!-- actions -->
        </colgroup>
        <thead>
            <tr>
                <th class="px-5 py-[18px] text-left">ชื่อแคมเปญ / ประเภท</th>
                <th class="px-4 py-[18px] text-center whitespace-nowrap"><i class="fa-regular fa-calendar mr-1"></i> เปิดรับถึงวันที่</th>
                <th class="px-4 py-[18px] text-center whitespace-nowrap"><i class="fa-solid fa-users-viewfinder mr-1"></i> ที่นั่งคงเหลือ</th>
                <th class="px-4 py-[18px] text-center whitespace-nowrap"><i class="fa-solid fa-toggle-on mr-1"></i> สถานะ</th>
                <th class="px-4 py-[18px] text-center whitespace-nowrap sticky right-0 z-20" style="background:linear-gradient(135deg,#2e9e63,#34d399);">
                    <i class="fa-solid fa-gear mr-1"></i> จัดการ</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php if (count($camp_list) === 0): ?>
                <tr>
                    <td colspan="5" class="px-6 py-16 text-center">
                        <div class="inline-flex flex-col items-center justify-center text-gray-400">
                            <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-3">
                                <i class="fa-solid fa-box-open text-2xl"></i>
                            </div>
                            <p class="font-medium text-gray-500">ยังไม่มีแคมเปญในระบบ</p>
                            <button onclick="openAddModal()"
                                class="mt-4 text-[#2e9e63] font-bold text-sm hover:underline">คลิกที่นี่เพื่อสร้างแคมเปญแรก</button>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($camp_list as $c):
                    // occupied = booked + confirmed + completed (โควต้าที่ถูกใช้ไปจริง · ตรงกับหน้าภาพรวมแคมเปญ)
                    $occupied  = (int)($c['occupied_capacity'] ?? $c['used_capacity']);
                    $remaining = max(0, $c['total_capacity'] - $occupied);
                    $isLow = ($remaining <= 10 && $c['total_capacity'] > 0);
                    $typeDetails = getCampaignTypeDetails($c['type']);
                    $isExpired = $c['available_until'] && (strtotime($c['available_until']) < strtotime(date('Y-m-d')));
                    $isInactive = ($c['status'] === 'inactive' || $isExpired);
                    ?>
                    <?php
                        $usedPct = $c['total_capacity'] > 0 ? min(100, round($occupied / $c['total_capacity'] * 100)) : 0;
                        $barColor = $usedPct >= 90 ? '#ef4444' : ($usedPct >= 60 ? '#f59e0b' : '#10b981');
                    ?>
                    <tr class="glass-tr group campaign-row <?= $isInactive ? 'is-inactive' : 'is-active' ?>" style="<?= $isInactive ? 'opacity:.55' : '' ?>">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="camp-icon <?= $typeDetails['bg'] ?> <?= $typeDetails['color'] ?> border <?= $typeDetails['border'] ?>" style="flex-shrink:0;width:40px;height:40px;border-radius:12px;font-size:1rem">
                                    <i class="fa-solid <?= $typeDetails['icon'] ?>"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-extrabold text-gray-900 text-[14px] leading-snug mb-1 break-words">
                                        <?= htmlspecialchars($c['title']) ?>
                                    </div>
                                    <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold <?= $typeDetails['bg'] ?> <?= $typeDetails['color'] ?> uppercase tracking-wider mb-1.5">
                                        <?= $typeDetails['label'] ?>
                                    </span>
                                    <div class="flex flex-wrap items-center gap-1 text-[11px] text-gray-500 font-semibold">
                                        <span class="bg-gray-100 px-2 py-0.5 rounded-md whitespace-nowrap" title="โควต้ารวม">
                                            <i class="fa-solid fa-users text-gray-400 mr-1"></i>โควต้า <?= number_format($c['total_capacity']) ?>
                                        </span>
                                        <span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-md whitespace-nowrap" title="จำนวนที่จองทั้งหมด">
                                            <i class="fa-solid fa-user-check mr-1"></i>จองแล้ว <?= number_format($c['used_capacity']) ?> คน
                                        </span>
                                        <?php if (!empty($c['room_name'])): ?>
                                        <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded-md whitespace-nowrap" title="<?= htmlspecialchars($c['room_code'] . ' · ' . ($roomTypeLabels[$c['room_type']] ?? '') . (!empty($c['room_floor']) ? ' · ชั้น ' . $c['room_floor'] : '')) ?>">
                                            <i class="fa-solid fa-location-dot mr-1"></i><?= htmlspecialchars($c['room_name']) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <?php if ($c['available_until']): ?>
                                <div class="font-bold text-sm <?= $isExpired ? 'text-red-500' : 'text-gray-700' ?>">
                                    <?= date('d M Y', strtotime($c['available_until'])) ?>
                                </div>
                                <?php if ($isExpired): ?>
                                    <span class="inline-block mt-1 text-[10px] font-bold text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-md">หมดเขตแล้ว</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-300 font-medium text-sm">ไม่มีกำหนด</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <div class="seat-ring">
                                <div class="seat-circle <?= $isLow ? 'bg-red-50 text-red-600 border-red-200' : 'bg-emerald-50 text-emerald-600 border-emerald-200' ?>">
                                    <?= number_format($remaining) ?>
                                </div>
                                <div class="cap-bar-wrap">
                                    <div class="cap-bar-fill" style="width:<?= $usedPct ?>%; background:<?= $barColor ?>"></div>
                                </div>
                                <div class="text-[10px] text-gray-400 font-bold"><?= $usedPct ?>%</div>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <div class="flex flex-col items-center gap-1">
                            <?php if ($isExpired): ?>
                                <span class="status-badge badge-expired">
                                    <span class="status-dot dot-expired"></span> หมดเขต
                                </span>
                            <?php else: ?>
                                <?php
                                $badgeClass = match($c['status']) {
                                    'draft' => 'badge-draft',
                                    'coming_soon' => 'badge-coming',
                                    'active' => 'badge-active',
                                    'full' => 'badge-full',
                                    'closed' => 'badge-closed',
                                    'archived' => 'badge-archived',
                                    'private' => 'badge-private',
                                    default => 'badge-inactive'
                                };
                                $statusLabel = match($c['status']) {
                                    'draft' => 'ฉบับร่าง',
                                    'coming_soon' => 'เร็วๆ นี้',
                                    'active' => 'เปิดรับสมัคร',
                                    'full' => 'เต็มแล้ว',
                                    'closed' => 'ปิดรับ',
                                    'archived' => 'เก็บถาวร',
                                    'private' => 'ลิงก์ส่วนตัว',
                                    default => 'ปิดชั่วคราว'
                                };
                                ?>
                                <span class="status-badge <?= $badgeClass ?>">
                                    <span class="status-dot <?= $c['status'] === 'active' ? 'dot-active' : 'dot-inactive' ?>"></span> 
                                    <?= $statusLabel ?>
                                </span>
                                <?php if ($c['status'] === 'active'): ?>
                                    <span class="approve-badge <?= $c['is_auto_approve'] ? 'approve-auto' : 'approve-manual' ?>">
                                        <i class="fa-solid <?= $c['is_auto_approve'] ? 'fa-bolt text-yellow-400' : 'fa-user-shield text-gray-400' ?>"></i>
                                        <?= $c['is_auto_approve'] ? 'Auto อนุมัติ' : 'แอดมินอนุมัติ' ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3 py-4 text-center sticky right-0 bg-white group-hover:bg-[#f0fdf4] z-10 transition-colors" style="box-shadow:-8px 0 20px rgba(0,0,0,0.03); border-left:1px solid #f0f4fa">
                            <div class="grid grid-cols-3 gap-2 w-max mx-auto">
                                <!-- Campaign Scanner -->
                                <a href="../staff/scan.php?campaign_id=<?= $c['id'] ?>"
                                    target="_blank"
                                    class="w-9 h-9 bg-green-50 text-[#2e9e63] rounded-xl flex items-center justify-center hover:bg-[#2e9e63] hover:text-white transition-all shadow-sm border border-green-100"
                                    title="เปิดสแกนเนอร์">
                                    <i class="fa-solid fa-camera text-xs"></i>
                                </a>

                                <!-- Campaign QR Check-in -->
                                <button type="button"
                                    onclick="showCampaignQrModal(<?= $c['id'] ?>, <?= (int)($c['qr_enabled'] ?? 0) ?>)"
                                    class="w-9 h-9 rounded-xl flex items-center justify-center transition-all shadow-sm border text-xs
                                           <?= ($c['qr_enabled'] ?? 0) ? 'bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-500 hover:text-white' : 'bg-gray-50 text-gray-400 border-gray-200 hover:bg-gray-200' ?>"
                                    title="QR เช็คอินรายวัน">
                                    <i class="fa-solid fa-qrcode"></i>
                                </button>

                                <!-- Share Link -->
                                <?php if (!empty($c['share_token'])): ?>
                                <button type="button"
                                    class="share-btn w-9 h-9 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all shadow-sm border border-emerald-100"
                                    title="คัดลอกลิงก์"
                                    data-shareurl="<?= htmlspecialchars(buildShareUrl($c['share_token'])) ?>">
                                    <i class="fa-solid fa-link pointer-events-none text-xs"></i>
                                </button>
                                <?php else: ?>
                                <form method="POST" class="m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="gen_token">
                                    <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                    <button type="submit"
                                        class="w-9 h-9 bg-gray-50 text-gray-400 rounded-xl flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-all shadow-sm border border-gray-200"
                                        title="สร้างลิงก์">
                                        <i class="fa-solid fa-link-slash text-xs"></i>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Edit -->
                                <button
                                    class="act-btn act-btn-edit edit-btn"
                                    title="แก้ไข" data-id="<?= htmlspecialchars($c['id']) ?>"
                                    data-title="<?= htmlspecialchars($c['title']) ?>"
                                    data-type="<?= htmlspecialchars($c['type']) ?>"
                                    data-capacity="<?= htmlspecialchars($c['total_capacity']) ?>"
                                    data-until="<?= htmlspecialchars($c['available_until']) ?>"
                                    data-status="<?= htmlspecialchars($c['status']) ?>"
                                    data-desc="<?= htmlspecialchars($c['description'] ?? '') ?>"
                                    data-auto="<?= htmlspecialchars($c['is_auto_approve']) ?>"
                                    data-vaccine-type-id="<?= htmlspecialchars((string)($c['vaccine_type_id'] ?? '')) ?>"
                                    data-room-id="<?= htmlspecialchars((string)($c['room_id'] ?? '')) ?>"
                                    data-from="<?= htmlspecialchars((string)($c['available_from'] ?? '')) ?>"
                                    data-audience="<?= htmlspecialchars((string)($c['target_audience'] ?? 'all')) ?>"
                                    data-max-per-user="<?= htmlspecialchars((string)($c['max_per_user'] ?? 1)) ?>"
                                    data-cancel-hours="<?= htmlspecialchars((string)($c['cancel_deadline_hours'] ?? 24)) ?>"
                                    data-phone="<?= htmlspecialchars((string)($c['contact_phone'] ?? '')) ?>"
                                    data-cover="<?= htmlspecialchars((string)($c['cover_image'] ?? '')) ?>"
                                    data-prereq="<?= htmlspecialchars((string)($c['prerequisites'] ?? '')) ?>"
                                    data-bring="<?= htmlspecialchars((string)($c['what_to_bring'] ?? '')) ?>">
                                    <i class="fa-solid fa-pen-to-square pointer-events-none text-xs"></i>
                                </button>

                                <!-- Delete -->
                                <?php if ($c['used_capacity'] == 0): ?>
                                    <form method="POST" class="m-0"
                                        onsubmit="return confirm('ยืนยันการลบแคมเปญ <?= htmlspecialchars($c['title'], ENT_QUOTES) ?>?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="act-btn act-btn-delete" title="ลบ">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="act-btn act-btn-disabled" title="มีผู้ลงทะเบียนแล้ว">
                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                    </button>
                                <?php endif; ?>

                                <!-- placeholder to fill 3rd column in last row when needed -->
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="campaignModal"
    class="fixed inset-0 z-50 bg-gray-900/70 backdrop-blur-sm hidden items-center justify-center p-3 overflow-y-auto"
    style="display:none">
    <div class="modal-glass w-full max-w-lg mx-auto my-6 overflow-hidden animate-slide-up">

        <!-- Modal Header -->
        <div class="modal-header flex justify-between items-center">
            <h3 id="modal_title">
                <span class="modal-icon"><i class="fa-solid fa-bullhorn"></i></span>
                สร้างแคมเปญใหม่
            </h3>
            <button onclick="document.getElementById('campaignModal').style.display='none'"
                class="modal-close-btn">
                <i class="fa-solid fa-times text-sm"></i>
            </button>
        </div>

        <!-- Modal Form -->
        <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4 bg-white overflow-y-auto custom-scrollbar" style="max-height:calc(100vh - 140px)">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="modal_action" value="add">
            <input type="hidden" name="campaign_id" id="modal_campaign_id">

            <!-- Title -->
            <div>
                <label class="form-label">ชื่อแคมเปญ/กิจกรรม <span class="text-red-500">*</span></label>
                <div class="relative">
                    <i class="form-input-icon fa-solid fa-heading"></i>
                    <input type="text" id="modal_title_input" name="title" required placeholder="เช่น อบรม CPR รุ่น 1"
                        class="form-input">
                </div>
            </div>

            <!-- Type -->
            <div>
                <label class="form-label">ประเภท <span class="text-red-500">*</span></label>
                <div class="flex gap-2" id="modal-type-cards">
                    <button type="button" class="modal-type-card" data-value="vaccine"
                            style="--sel-color:#2e9e63;--sel-bg:#f0fdf4;--sel-border:#86efac">
                        <i class="fa-solid fa-syringe"></i><span>ฉีดวัคซีน</span>
                    </button>
                    <button type="button" class="modal-type-card" data-value="training"
                            style="--sel-color:#7c3aed;--sel-bg:#faf5ff;--sel-border:#d8b4fe">
                        <i class="fa-solid fa-chalkboard-user"></i><span>อบรม/สัมมนา</span>
                    </button>
                    <button type="button" class="modal-type-card" data-value="health_check"
                            style="--sel-color:#059669;--sel-bg:#ecfdf5;--sel-border:#6ee7b7">
                        <i class="fa-solid fa-stethoscope"></i><span>ตรวจสุขภาพ</span>
                    </button>
                    <button type="button" class="modal-type-card" data-value="other"
                            style="--sel-color:#ea580c;--sel-bg:#fff7ed;--sel-border:#fdba74">
                        <i class="fa-solid fa-star"></i><span>อื่นๆ</span>
                    </button>
                </div>
                <input type="hidden" id="modal_type" name="type" value="vaccine">
            </div>

            <!-- Vaccine catalog linkage (shown only when type='vaccine') -->
            <div id="modal-vaccine-type-wrap">
                <label class="form-label">ประเภทวัคซีน <span class="text-slate-400 text-xs font-normal">(ไม่บังคับ · ผูกกับ catalog เพื่อ pre-fill ทุก record)</span></label>
                <div class="relative">
                    <i class="form-input-icon fa-solid fa-syringe"></i>
                    <select id="modal_vaccine_type_id" name="vaccine_type_id" class="form-input">
                        <option value="">— ไม่ผูก / กรอกเอง —</option>
                        <?php foreach ($vaccineTypes as $vt): ?>
                            <option value="<?= (int)$vt['id'] ?>" data-mfr="<?= htmlspecialchars($vt['default_manufacturer'] ?? '') ?>">
                                <?= htmlspecialchars($vt['code']) ?> · <?= htmlspecialchars($vt['name_th']) ?>
                                <?= !empty($vt['default_doses']) ? ' · ' . (int)$vt['default_doses'] . ' dose' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (empty($vaccineTypes)): ?>
                    <p class="text-[11px] text-amber-600 mt-1"><i class="fa-solid fa-circle-info"></i> ยังไม่มี vaccine catalog ใน Portal · เปิด <a href="../portal/index.php?section=vaccine_catalog" target="_blank" class="underline font-bold">ประเภทวัคซีน</a> เพื่อเพิ่ม</p>
                <?php endif; ?>
            </div>

            <!-- Capacity -->
            <div>
                <label class="form-label">โควต้า (คน) <span class="text-red-500">*</span></label>
                <div class="relative">
                    <i class="form-input-icon fa-solid fa-users"></i>
                    <input type="number" id="modal_total_capacity" name="total_capacity" required min="0"
                        class="form-input">
                </div>
            </div>

            <!-- Status + Auto-approve -->
            <div class="grid grid-cols-2 gap-3">
                <div class="form-section-card">
                    <label class="form-label">สถานะแคมเปญ</label>
                    <div class="flex flex-wrap gap-2" id="modal-status-pills">
                        <button type="button" class="modal-status-pill" data-value="draft"
                                style="--sel-color:#94a3b8;--sel-bg:#f8fafc;--sel-border:#e2e8f0">
                            <i class="fa-solid fa-file-pen"></i> ฉบับร่าง
                        </button>
                        <button type="button" class="modal-status-pill" data-value="coming_soon"
                                style="--sel-color:#7c3aed;--sel-bg:#f5f3ff;--sel-border:#ddd6fe">
                            <i class="fa-solid fa-clock"></i> เร็วๆ นี้
                        </button>
                        <button type="button" class="modal-status-pill" data-value="active"
                                style="--sel-color:#16a34a;--sel-bg:#f0fdf4;--sel-border:#86efac">
                            <i class="fa-solid fa-circle-check"></i> เปิด
                        </button>
                        <button type="button" class="modal-status-pill" data-value="private"
                                style="--sel-color:#ea580c;--sel-bg:#fff7ed;--sel-border:#ffedd5">
                            <i class="fa-solid fa-user-secret"></i> ลิงก์ส่วนตัว
                        </button>
                        <button type="button" class="modal-status-pill" data-value="full"
                                style="--sel-color:#ef4444;--sel-bg:#fef2f2;--sel-border:#fecaca">
                            <i class="fa-solid fa-users-slash"></i> เต็มแล้ว
                        </button>
                        <button type="button" class="modal-status-pill" data-value="closed"
                                style="--sel-color:#c2410c;--sel-bg:#fff7ed;--sel-border:#fed7aa"
                                title="โชว์อยู่ในระบบแต่ไม่รับการจองเพิ่ม (เช่น จบกิจกรรมแล้ว)">
                            <i class="fa-solid fa-lock"></i> ปิดรับ
                        </button>
                        <button type="button" class="modal-status-pill" data-value="inactive"
                                style="--sel-color:#6b7280;--sel-bg:#f9fafb;--sel-border:#d1d5db"
                                title="ซ่อนจากผู้ใช้ — กลับมาเปิดใหม่ได้">
                            <i class="fa-solid fa-circle-pause"></i> ปิดชั่วคราว
                        </button>
                        <button type="button" class="modal-status-pill" data-value="archived"
                                style="--sel-color:#334155;--sel-bg:#f1f5f9;--sel-border:#cbd5e1">
                            <i class="fa-solid fa-box-archive"></i> เก็บถาวร
                        </button>
                    </div>
                    <input type="hidden" id="modal_status" name="status" value="active">
                </div>
                <div class="form-section-card" style="background:#f0fdf4; border-color:#bcf0da">
                    <label class="form-label">การอนุมัติ</label>
                    <div class="flex gap-2" id="modal-approve-pills">
                        <button type="button" class="modal-approve-pill" data-value="0"
                                style="--sel-color:#16a34a;--sel-bg:#f0fdf4;--sel-border:#86efac">
                            <i class="fa-solid fa-user-shield"></i> ต้องอนุมัติ
                        </button>
                        <button type="button" class="modal-approve-pill" data-value="1"
                                style="--sel-color:#d97706;--sel-bg:#fffbeb;--sel-border:#fcd34d">
                            <i class="fa-solid fa-bolt"></i> อัตโนมัติ
                        </button>
                    </div>
                    <input type="hidden" id="modal_is_auto_approve" name="is_auto_approve" value="0">
                </div>
            </div>

            <!-- Location (clinic room) -->
            <div>
                <label class="form-label">
                    สถานที่ <span class="text-gray-400 font-normal">(ไม่บังคับ)</span>
                    <a href="../portal/index.php?section=clinic_data&cd_view=rooms" target="_blank" rel="noopener"
                       class="ml-1 text-[11px] text-blue-600 hover:underline">
                        <i class="fa-solid fa-up-right-from-square"></i> จัดการสถานที่
                    </a>
                </label>
                <div class="relative">
                    <i class="form-input-icon fa-solid fa-location-dot"></i>
                    <select id="modal_room_id" name="room_id" class="form-input" style="padding-left:38px">
                        <option value="">— ไม่ระบุ —</option>
                        <?php
                        // Group by type for readability
                        $_grouped = [];
                        foreach ($clinicRooms as $r) { $_grouped[$r['type']][] = $r; }
                        foreach ($_grouped as $_t => $_rooms): ?>
                            <optgroup label="<?= htmlspecialchars($roomTypeLabels[$_t] ?? $_t) ?>">
                                <?php foreach ($_rooms as $r): ?>
                                <option value="<?= (int)$r['id'] ?>">
                                    <?= htmlspecialchars($r['code']) ?> · <?= htmlspecialchars($r['name']) ?><?php if (!empty($r['floor'])): ?> · ชั้น <?= htmlspecialchars($r['floor']) ?><?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                        <?php if (empty($clinicRooms)): ?>
                            <option value="" disabled>ยังไม่มีสถานที่ในระบบ — เพิ่มจาก "จัดการสถานที่"</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <!-- Booking window: from / until -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">เปิดรับตั้งแต่ <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                    <div class="relative">
                        <i class="form-input-icon fa-regular fa-calendar-check"></i>
                        <input type="date" id="modal_available_from" name="available_from" class="form-input">
                    </div>
                </div>
                <div>
                    <label class="form-label">เปิดรับถึงวันที่ <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                    <div class="relative">
                        <i class="form-input-icon fa-regular fa-calendar-xmark"></i>
                        <input type="date" id="modal_available_until" name="available_until" class="form-input">
                    </div>
                </div>
            </div>

            <!-- Target audience + per-user limit + cancel deadline -->
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="form-label">กลุ่มเป้าหมาย</label>
                    <div class="relative">
                        <i class="form-input-icon fa-solid fa-user-group"></i>
                        <select id="modal_target_audience" name="target_audience" class="form-input" style="padding-left:38px">
                            <option value="all">ทุกคน</option>
                            <option value="student">นักศึกษา</option>
                            <option value="staff">บุคลากร</option>
                            <option value="other">อื่นๆ</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label">จองได้สูงสุด/คน</label>
                    <div class="relative">
                        <i class="form-input-icon fa-solid fa-ticket"></i>
                        <input type="number" id="modal_max_per_user" name="max_per_user" min="1" max="99" value="1"
                            class="form-input">
                    </div>
                </div>
                <div>
                    <label class="form-label">ยกเลิกล่วงหน้า (ชม.)</label>
                    <div class="relative">
                        <i class="form-input-icon fa-regular fa-clock"></i>
                        <input type="number" id="modal_cancel_deadline_hours" name="cancel_deadline_hours" min="0" max="720" value="24"
                            class="form-input">
                    </div>
                </div>
            </div>

            <!-- Contact phone -->
            <div>
                <label class="form-label">เบอร์ติดต่อสอบถาม <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                <div class="relative">
                    <i class="form-input-icon fa-solid fa-phone"></i>
                    <input type="text" id="modal_contact_phone" name="contact_phone" placeholder="02-xxx-xxxx หรือ 08x-xxx-xxxx"
                        class="form-input">
                </div>
            </div>

            <!-- Cover image upload -->
            <div>
                <label class="form-label">รูปหน้าปก <span class="text-gray-400 font-normal">(ไม่บังคับ · JPG/PNG/WEBP ≤ 5MB)</span></label>
                <input type="hidden" id="modal_cover_image_existing" name="cover_image_existing" value="">
                <input type="hidden" id="modal_remove_cover" name="remove_cover" value="0">
                <div id="modal_cover_dropzone" class="cover-dropzone" onclick="document.getElementById('modal_cover_file').click()">
                    <div id="modal_cover_empty" class="cover-dropzone-empty">
                        <i class="fa-regular fa-image text-3xl text-gray-300 mb-2"></i>
                        <p class="text-sm font-semibold text-gray-500">คลิกเพื่อเลือกรูป</p>
                        <p class="text-[11px] text-gray-400 mt-0.5">หรือลากไฟล์มาวางที่นี่</p>
                    </div>
                    <div id="modal_cover_preview" class="cover-dropzone-preview hidden">
                        <img id="modal_cover_preview_img" src="" alt="cover preview">
                        <button type="button" class="cover-remove-btn" onclick="event.stopPropagation(); clearCoverImage()" title="ลบรูป">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <input type="file" id="modal_cover_file" name="cover_image" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="handleCoverFileSelect(this)">
            </div>

            <!-- Prerequisites -->
            <div>
                <label class="form-label">เงื่อนไขก่อนเข้าร่วม <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                <div class="relative">
                    <i class="fa-solid fa-circle-exclamation text-gray-400 absolute top-3.5 left-3.5 text-sm pointer-events-none"></i>
                    <textarea id="modal_prerequisites" name="prerequisites" rows="2"
                        placeholder="เช่น เคยฉีดเข็มแรกแล้ว ≥ 30 วัน · ไม่มีไข้ใน 7 วันที่ผ่านมา"
                        class="form-input resize-none custom-scrollbar" style="padding-top:10px;padding-bottom:10px"></textarea>
                </div>
            </div>

            <!-- What to bring -->
            <div>
                <label class="form-label">สิ่งที่ต้องเตรียมมา <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                <div class="relative">
                    <i class="fa-solid fa-suitcase-medical text-gray-400 absolute top-3.5 left-3.5 text-sm pointer-events-none"></i>
                    <textarea id="modal_what_to_bring" name="what_to_bring" rows="2"
                        placeholder="เช่น บัตรประชาชน · สมุดวัคซีน · ใบรับรองแพทย์"
                        class="form-input resize-none custom-scrollbar" style="padding-top:10px;padding-bottom:10px"></textarea>
                </div>
            </div>

            <!-- Description -->
            <div>
                <label class="form-label">รายละเอียดเพิ่มเติม <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                <div class="relative">
                    <i class="fa-solid fa-align-left text-gray-400 absolute top-3.5 left-3.5 text-sm pointer-events-none"></i>
                    <textarea id="modal_description" name="description" rows="2"
                        placeholder="ระบุข้อมูลที่ผู้เข้าร่วมควรทราบ..."
                        class="form-input resize-none custom-scrollbar" style="padding-top:10px;padding-bottom:10px"></textarea>
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="document.getElementById('campaignModal').style.display='none'"
                    class="flex-none px-5 py-2.5 bg-white border-2 border-gray-200 text-gray-700 font-bold rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all text-sm">ยกเลิก</button>
                <button type="submit" id="modal_submit_btn"
                    class="flex-1 bg-[#2e9e63] text-white font-bold py-2.5 rounded-xl hover:shadow-lg hover:shadow-emerald-500/30 hover:-translate-y-0.5 transition-all text-sm shadow-sm flex items-center justify-center gap-2" style="background-color: #2e9e63;">
                    <i class="fa-solid fa-save"></i> <span>สร้างแคมเปญ</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ซ่อน inactive rows ตั้งแต่โหลดหน้า
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.campaign-row.is-inactive').forEach(r => r.classList.add('hidden'));
    });

    function toggleInactiveCampaigns() {
        const show = document.getElementById('toggleInactive').checked;
        document.querySelectorAll('.campaign-row.is-inactive').forEach(r => {
            r.classList.toggle('hidden', !show);
        });
        document.getElementById('toggleIcon').className = show
            ? 'fa-solid fa-eye text-[#2e9e63] text-xs'
            : 'fa-solid fa-eye-slash text-gray-400 text-xs';
        document.getElementById('toggleLabel').textContent = show
            ? 'ซ่อนแคมเปญที่ปิด/หมดเขต'
            : 'แสดงแคมเปญที่ปิด/หมดเขต';
    }

    function showModal() { document.getElementById('campaignModal').style.display = 'flex'; }
    function hideModal() { document.getElementById('campaignModal').style.display = 'none'; }

    // Close on backdrop click
    document.getElementById('campaignModal').addEventListener('click', function(e) {
        if (e.target === this) hideModal();
    });

    /* ── Icon-picker helpers ─────────────────────────────────── */
    // ── Cover image upload helpers ─────────────────────────────
    function showCoverPreview(src) {
        document.getElementById('modal_cover_empty').classList.add('hidden');
        document.getElementById('modal_cover_preview').classList.remove('hidden');
        document.getElementById('modal_cover_preview_img').src = src;
    }
    function showCoverEmpty() {
        document.getElementById('modal_cover_empty').classList.remove('hidden');
        document.getElementById('modal_cover_preview').classList.add('hidden');
        document.getElementById('modal_cover_preview_img').src = '';
    }
    function handleCoverFileSelect(input) {
        if (!input.files || !input.files[0]) return;
        const f = input.files[0];
        if (f.size > 5 * 1024 * 1024) {
            Swal.fire({ icon:'error', title:'ไฟล์ใหญ่เกิน', text:'ขนาดต้องไม่เกิน 5MB' });
            input.value = '';
            return;
        }
        if (!['image/jpeg','image/png','image/webp'].includes(f.type)) {
            Swal.fire({ icon:'error', title:'ชนิดไฟล์ไม่รองรับ', text:'รองรับ JPG, PNG, WEBP' });
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = e => showCoverPreview(e.target.result);
        reader.readAsDataURL(f);
        document.getElementById('modal_remove_cover').value = '0';
    }
    function clearCoverImage() {
        document.getElementById('modal_cover_file').value = '';
        document.getElementById('modal_remove_cover').value = '1';
        showCoverEmpty();
    }
    // Drag and drop binding
    document.addEventListener('DOMContentLoaded', function() {
        const dz = document.getElementById('modal_cover_dropzone');
        if (!dz) return;
        ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('is-dragover'); }));
        ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('is-dragover'); }));
        dz.addEventListener('drop', e => {
            const f = e.dataTransfer?.files?.[0];
            if (!f) return;
            const input = document.getElementById('modal_cover_file');
            const dt = new DataTransfer();
            dt.items.add(f);
            input.files = dt.files;
            handleCoverFileSelect(input);
        });
    });

    function pickCard(containerId, value) {
        document.querySelectorAll('#' + containerId + ' [data-value]').forEach(el => {
            el.classList.toggle('is-selected', el.dataset.value === String(value));
        });
    }

    // Toggle the vaccine-catalog dropdown visibility based on current type —
    // only meaningful when type === 'vaccine'. Called from both the type
    // picker and the edit-button code path.
    function syncVaccineTypeVisibility() {
        const wrap = document.getElementById('modal-vaccine-type-wrap');
        if (!wrap) return;
        wrap.style.display = (document.getElementById('modal_type').value === 'vaccine') ? '' : 'none';
    }

    document.querySelectorAll('#modal-type-cards [data-value]').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('modal_type').value = this.dataset.value;
            pickCard('modal-type-cards', this.dataset.value);
            syncVaccineTypeVisibility();
        });
    });
    document.querySelectorAll('#modal-status-pills [data-value]').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('modal_status').value = this.dataset.value;
            pickCard('modal-status-pills', this.dataset.value);
        });
    });
    document.querySelectorAll('#modal-approve-pills [data-value]').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('modal_is_auto_approve').value = this.dataset.value;
            pickCard('modal-approve-pills', this.dataset.value);
        });
    });

    function openAddModal() {
        document.getElementById('modal_title').innerHTML = '<span class="modal-icon"><i class="fa-solid fa-bullhorn"></i></span> สร้างแคมเปญใหม่';
        document.getElementById('modal_action').value = 'add';
        document.getElementById('modal_campaign_id').value = '';
        document.getElementById('modal_title_input').value = '';
        document.getElementById('modal_type').value = 'vaccine';
        document.getElementById('modal_total_capacity').value = '0';
        document.getElementById('modal_available_until').value = '';
        document.getElementById('modal_status').value = 'active';
        document.getElementById('modal_is_auto_approve').value = '0';
        document.getElementById('modal_description').value = '';
        document.getElementById('modal_room_id').value = '';
        document.getElementById('modal_available_from').value = '';
        document.getElementById('modal_target_audience').value = 'all';
        document.getElementById('modal_max_per_user').value = '1';
        document.getElementById('modal_cancel_deadline_hours').value = '24';
        document.getElementById('modal_contact_phone').value = '';
        document.getElementById('modal_prerequisites').value = '';
        document.getElementById('modal_what_to_bring').value = '';
        // Reset cover-image dropzone
        document.getElementById('modal_cover_file').value = '';
        document.getElementById('modal_cover_image_existing').value = '';
        document.getElementById('modal_remove_cover').value = '0';
        showCoverEmpty();

        pickCard('modal-type-cards',    'vaccine');
        pickCard('modal-status-pills',  'active');
        pickCard('modal-approve-pills', '0');

        // Reset vaccine-catalog dropdown to "no link" and reveal it (default
        // create-flow starts on type=vaccine so dropdown should be visible)
        const vtSel = document.getElementById('modal_vaccine_type_id');
        if (vtSel) vtSel.value = '';
        syncVaccineTypeVisibility();

        let btn = document.getElementById('modal_submit_btn');
        btn.innerHTML = '<i class="fa-solid fa-plus-circle"></i> <span>สร้างแคมเปญ</span>';
        btn.style.background = 'linear-gradient(135deg,#2e9e63,#34d399)';

        document.querySelector('.modal-header').style.background = '';
        showModal();
    }

    // ── Share URL copy-to-clipboard ──────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const shareBtns = document.querySelectorAll('.share-btn');
        shareBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const url = this.getAttribute('data-shareurl');
                if (!url) return;
                navigator.clipboard.writeText(url).then(function() {
                    showShareToast(url);
                }).catch(function() {
                    const ta = document.createElement('textarea');
                    ta.value = url;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showShareToast(url);
                });
            });
        });
    });

    function showShareToast(url) {
        let toast = document.getElementById('shareToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'shareToast';
            toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:12px 20px;border-radius:16px;font-size:13px;font-weight:700;z-index:9999;display:flex;flex-direction:column;align-items:center;gap:6px;max-width:360px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,0.25);transition:opacity 0.3s';
            document.body.appendChild(toast);
        }
        toast.innerHTML = '<div style="display:flex;align-items:center;gap:8px"><i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:16px"></i> คัดลอกลิงก์แล้ว!</div>'
                        + '<div style="background:#1e293b;border-radius:8px;padding:6px 10px;font-size:11px;font-family:monospace;color:#94a3b8;word-break:break-all;max-width:100%">' + url + '</div>';
        toast.style.opacity = '1';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function() {
            toast.style.opacity = '0';
        }, 3000);
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('modal_title').innerHTML = '<span class="modal-icon" style="background:rgba(255,255,255,.2)"><i class="fa-solid fa-pen-to-square"></i></span> แก้ไขแคมเปญ';

                document.getElementById('modal_action').value = 'edit';
                document.getElementById('modal_campaign_id').value = this.dataset.id;
                document.getElementById('modal_title_input').value = this.dataset.title;
                document.getElementById('modal_type').value = this.dataset.type;
                document.getElementById('modal_total_capacity').value = this.dataset.capacity;
                document.getElementById('modal_available_until').value = this.dataset.until || '';
                document.getElementById('modal_status').value = this.dataset.status;
                document.getElementById('modal_is_auto_approve').value = this.dataset.auto;

                pickCard('modal-type-cards',    this.dataset.type);
                pickCard('modal-status-pills',  this.dataset.status);
                pickCard('modal-approve-pills', this.dataset.auto);
                document.getElementById('modal_description').value = this.dataset.desc;
                document.getElementById('modal_room_id').value = this.dataset.roomId || '';
                document.getElementById('modal_available_from').value = this.dataset.from || '';
                document.getElementById('modal_target_audience').value = this.dataset.audience || 'all';
                document.getElementById('modal_max_per_user').value = this.dataset.maxPerUser || '1';
                document.getElementById('modal_cancel_deadline_hours').value = this.dataset.cancelHours ?? '24';
                document.getElementById('modal_contact_phone').value = this.dataset.phone || '';
                document.getElementById('modal_prerequisites').value = this.dataset.prereq || '';
                document.getElementById('modal_what_to_bring').value = this.dataset.bring || '';
                // Cover-image: show existing or empty state
                const existingCover = this.dataset.cover || '';
                document.getElementById('modal_cover_file').value = '';
                document.getElementById('modal_cover_image_existing').value = existingCover;
                document.getElementById('modal_remove_cover').value = '0';
                if (existingCover) {
                    showCoverPreview('../' + existingCover);
                } else {
                    showCoverEmpty();
                }
                // Restore the catalog dropdown selection + visibility based on
                // the campaign's stored type. Empty string when no linkage.
                const vtSel = document.getElementById('modal_vaccine_type_id');
                if (vtSel) vtSel.value = this.dataset.vaccineTypeId || '';
                syncVaccineTypeVisibility();

                let submitBtn = document.getElementById('modal_submit_btn');
                submitBtn.innerHTML = '<i class="fa-solid fa-save"></i> <span>บันทึกการแก้ไข</span>';
                submitBtn.style.background = 'linear-gradient(135deg,#d97706,#f59e0b)';

                // Tint the modal header to amber for edit mode
                document.querySelector('.modal-header').style.background = 'linear-gradient(135deg,#b45309,#d97706)';
                showModal();
            });
        });

        // Reset header colour when opening add modal
        document.querySelector('[onclick="openAddModal()"]')?.addEventListener('click', function() {
            document.querySelector('.modal-header').style.background = '';
        });
    });
</script>

<!-- ══ Campaign QR Modal ══════════════════════════════════════════════════════ -->
<div id="campQrOverlay"
     class="fixed inset-0 z-[200] bg-black/60 backdrop-blur-sm hidden items-center justify-center p-4"
     style="display:none">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden">

    <!-- Header -->
    <div class="flex items-center justify-between px-5 py-4"
         style="background:linear-gradient(135deg,#2e9e63,#34d399)">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
          <i class="fa-solid fa-qrcode text-white"></i>
        </div>
        <div>
          <p class="text-white font-black text-sm">QR เช็คอินรายวัน</p>
          <p class="text-white/70 text-[11px]" id="campQrTitle">—</p>
        </div>
      </div>
      <button onclick="closeCampQrModal()"
              class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-xl flex items-center justify-center transition-all">
        <i class="fa-solid fa-times text-white text-sm"></i>
      </button>
    </div>

    <!-- QR Image -->
    <div class="flex flex-col items-center px-6 pt-6 pb-4">
      <div class="w-52 h-52 bg-gray-50 rounded-2xl border-2 border-dashed border-gray-200 flex items-center justify-center overflow-hidden mb-4" id="campQrImgWrap">
        <i class="fa-solid fa-spinner fa-spin text-3xl text-gray-300"></i>
      </div>

      <!-- Toggle -->
      <button id="campQrToggleBtn" onclick="toggleCampaignQr()"
              class="w-full py-2.5 rounded-xl font-black text-sm mb-3 transition-all flex items-center justify-center gap-2">
        <i class="fa-solid fa-toggle-on"></i> <span>QR เปิดอยู่</span>
      </button>

      <!-- Copy URL -->
      <div class="w-full flex gap-2 mb-3">
        <input id="campQrCopyInput" type="text" readonly
               class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-500 font-mono overflow-hidden"
               placeholder="กำลังโหลด URL...">
        <button onclick="copyCampaignCheckinUrl()"
                class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-xl transition-all" title="คัดลอก">
          <i class="fa-solid fa-copy text-gray-500 text-sm"></i>
        </button>
      </div>

      <!-- Print -->
      <button onclick="printCampaignQr()"
              class="w-full py-2.5 bg-gray-50 hover:bg-gray-100 rounded-xl text-sm font-bold text-gray-600 transition-all flex items-center justify-center gap-2 border border-gray-200">
        <i class="fa-solid fa-print"></i> พิมพ์ QR Code
      </button>
    </div>

  </div>
</div>

<script>
const CSRF_CAMP_QR = '<?= get_csrf_token() ?>';
let _campQrCurrentId   = 0;
let _campQrEnabled     = 0;

function showCampaignQrModal(campaignId, qrEnabled) {
    _campQrCurrentId = campaignId;
    _campQrEnabled   = qrEnabled;

    // Reset UI
    const wrap = document.getElementById('campQrImgWrap');
    wrap.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-3xl text-gray-300"></i>';
    document.getElementById('campQrCopyInput').value = 'กำลังโหลด...';

    // Title
    const row = document.querySelector(`.edit-btn[data-id="${campaignId}"]`);
    document.getElementById('campQrTitle').textContent = row ? row.dataset.title : `Campaign #${campaignId}`;

    // QR image
    const img = new Image();
    img.src = `../user/api_campaign_qr.php?campaign=${campaignId}&t=${Date.now()}`;
    img.className = 'w-full h-full object-contain p-2';
    img.onload = () => { wrap.innerHTML = ''; wrap.appendChild(img); };
    img.onerror = () => { wrap.innerHTML = '<p class="text-xs text-red-400">โหลด QR ไม่ได้</p>'; };

    // Check-in URL
    fetch(`ajax/ajax_get_campaign_checkin_url.php?campaign=${campaignId}`)
        .then(r => r.json())
        .then(d => { document.getElementById('campQrCopyInput').value = d.url || ''; })
        .catch(() => { document.getElementById('campQrCopyInput').value = ''; });

    setCampQrToggleUI(_campQrEnabled);

    document.getElementById('campQrOverlay').style.display = 'flex';
}

function closeCampQrModal() {
    document.getElementById('campQrOverlay').style.display = 'none';
}

document.getElementById('campQrOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeCampQrModal();
});

function setCampQrToggleUI(enabled) {
    const btn  = document.getElementById('campQrToggleBtn');
    const icon = btn.querySelector('i');
    const txt  = btn.querySelector('span');
    if (enabled) {
        btn.style.cssText = 'background:#dcfce7;color:#16a34a;border:1.5px solid #bbf7d0';
        icon.className = 'fa-solid fa-toggle-on';
        txt.textContent  = 'QR เปิดอยู่ — กดเพื่อปิด';
    } else {
        btn.style.cssText = 'background:#f3f4f6;color:#6b7280;border:1.5px solid #e5e7eb';
        icon.className = 'fa-solid fa-toggle-off';
        txt.textContent  = 'QR ปิดอยู่ — กดเพื่อเปิด';
    }
}

function toggleCampaignQr() {
    const btn = document.getElementById('campQrToggleBtn');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('campaign_id', _campQrCurrentId);
    fd.append('csrf_token',  CSRF_CAMP_QR);

    fetch('ajax/ajax_toggle_campaign_qr.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                _campQrEnabled = d.qr_enabled;
                setCampQrToggleUI(_campQrEnabled);

                // Update button in table row
                const qrBtn = document.querySelector(`button[onclick="showCampaignQrModal(${_campQrCurrentId}, ${d.qr_enabled ? 0 : 1})"]`);
                if (qrBtn) {
                    qrBtn.setAttribute('onclick', `showCampaignQrModal(${_campQrCurrentId}, ${d.qr_enabled})`);
                    if (d.qr_enabled) {
                        qrBtn.className = qrBtn.className.replace('bg-gray-50 text-gray-400 border-gray-200 hover:bg-gray-200', 'bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-500 hover:text-white');
                    } else {
                        qrBtn.className = qrBtn.className.replace('bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-500 hover:text-white', 'bg-gray-50 text-gray-400 border-gray-200 hover:bg-gray-200');
                    }
                }
            }
        })
        .catch(() => {})
        .finally(() => { btn.disabled = false; });
}

function copyCampaignCheckinUrl() {
    const input = document.getElementById('campQrCopyInput');
    if (!input.value) return;
    navigator.clipboard.writeText(input.value).catch(() => {
        input.select();
        document.execCommand('copy');
    });
    const icon = document.querySelector('button[onclick="copyCampaignCheckinUrl()"] i');
    icon.className = 'fa-solid fa-check text-emerald-500 text-sm';
    setTimeout(() => { icon.className = 'fa-solid fa-copy text-gray-500 text-sm'; }, 1500);
}

function printCampaignQr() {
    const img = document.querySelector('#campQrImgWrap img');
    if (!img) return;
    const title = document.getElementById('campQrTitle').textContent;
    const w = window.open('', '_blank', 'width=400,height=500');
    w.document.write(`<!DOCTYPE html><html><head><title>QR Check-in</title>
    <style>body{font-family:sans-serif;text-align:center;padding:30px}img{width:260px;height:260px}h2{font-size:16px;margin-top:16px}</style>
    </head><body><img src="${img.src}"><h2>${title}</h2><p style="font-size:12px;color:#888">สแกนเพื่อเช็คอิน · RSU Medical Clinic</p>
    <script>window.onload=()=>window.print()<\/script></body></html>`);
    w.document.close();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>