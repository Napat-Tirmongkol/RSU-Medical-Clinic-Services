<?php
// admin/time_slots.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// สร้าง column qr_enabled ถ้ายังไม่มี
try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN qr_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}

$activeCampaigns = $pdo->query("SELECT id, title, qr_enabled FROM camp_list WHERE status = 'active' ORDER BY title ASC")->fetchAll();
$allCampaigns    = $pdo->query("SELECT id, title, qr_enabled FROM camp_list ORDER BY title ASC")->fetchAll();

// map campaign_id → qr_enabled
$qrEnabledMap = [];
foreach ($allCampaigns as $ac) { $qrEnabledMap[(int)$ac['id']] = (int)$ac['qr_enabled']; }

// helper: สร้าง check-in URL ต่อ slot
function slot_checkin_url(int $slot_id): string {
    $token  = hash_hmac('sha256', "qr:slot:{$slot_id}", QR_SLOT_SECRET);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $base . '/user/checkin.php?slot=' . $slot_id . '&token=' . $token;
}

$colors = [
    ['cls' => 'slot-pal-emerald'],
    ['cls' => 'slot-pal-green'],
    ['cls' => 'slot-pal-purple'],
    ['cls' => 'slot-pal-orange'],
    ['cls' => 'slot-pal-red'],
    ['cls' => 'slot-pal-teal'],
];

$campaignColors = [];
$c_idx = 0;
foreach ($activeCampaigns as $ac) {
    $campaignColors[$ac['id']] = $colors[$c_idx % count($colors)];
    $c_idx++;
}

// ==========================================
// ส่วนจัดการ AJAX / POST (เพิ่ม/ลบ รอบเวลา)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();
    $action = $_POST['action'] ?? '';
    
    // ระบบเพิ่มรอบเวลาแบบหลายๆ วัน (Multi-Select Dates) และหลายๆ ช่วงเวลา
    if ($action === 'add_slot') {
        $campaign_id = (int)$_POST['campaign_id'];
        $selected_dates = $_POST['selected_dates'] ?? '';
        $start_times = $_POST['start_time'] ?? []; // Array
        $end_times = $_POST['end_time'] ?? [];     // Array
        $max = (int)$_POST['max_capacity'];

        if ($campaign_id > 0 && !empty($selected_dates) && !empty($start_times) && !empty($end_times) && $max >= 0) {
            
            $dates_array = explode(',', $selected_dates);
            $insertedCount = 0;
            $skippedCount = 0;
            
            // หาจำนวนช่วงเวลาที่กรอกมาแบบถูกต้อง (เพื่อนำโควต้ารวมมาหารเฉลี่ย)
            $valid_slots_count = 0;
            for ($i = 0; $i < count($start_times); $i++) {
                if (!empty($start_times[$i]) && !empty($end_times[$i])) {
                    $valid_slots_count++;
                }
            }

            if ($valid_slots_count > 0) {
                // หารเฉลี่ยที่นั่งต่อรอบ
                $base_capacity = floor($max / $valid_slots_count);
                
                $stmt = $pdo->prepare("INSERT INTO camp_slots (campaign_id, slot_date, start_time, end_time, max_capacity) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($dates_array as $date) {
                    $date = trim($date);
                    if ($date) {
                        // คำนวณเศษที่เหลือของการหาร (แจกจ่ายเพิ่มให้รอบแรกๆ ก่อน)
                        $remainder = $max % $valid_slots_count;
                        
                        // วนลูปบันทึกแต่ละช่วงเวลาที่กรอกเข้ามาในหน้าต่าง
                        for ($i = 0; $i < count($start_times); $i++) {
                            $st = $start_times[$i];
                            $et = $end_times[$i];
                            if ($st && $et) {
                                // เช็คว่ามีรอบเวลานี้ในฐานข้อมูลแล้วหรือไม่ (ป้องกันการสร้างซ้ำ)
                                $check_dup = $pdo->prepare("SELECT COUNT(*) FROM camp_slots WHERE campaign_id = ? AND slot_date = ? AND start_time = ?");
                                $check_dup->execute([$campaign_id, $date, $st]);
                                if ($check_dup->fetchColumn() > 0) {
                                    $skippedCount++;
                                    continue; // ข้ามไปหากมีรอบเวลานี้อยู่แล้ว
                                }

                                $capacity_for_this_slot = $base_capacity + ($remainder > 0 ? 1 : 0);
                                $remainder--;
                                
                                $stmt->execute([$campaign_id, $date, $st, $et, $capacity_for_this_slot]);
                                $insertedCount++;
                            }
                        }
                    }
                }
            }

            $msg = "เพิ่มข้อมูลสำเร็จ {$insertedCount} รอบเวลา";
            if ($skippedCount > 0) {
                $msg .= " (ข้าม {$skippedCount} รอบที่ซ้ำซ้อนกัน)";
            }
            log_activity('add_slot', $msg . " (Campaign ID: {$campaign_id})");
            echo json_encode(['status' => 'success', 'message' => $msg]);
            exit;
        }
    }

    // ระบบแก้ไขรอบเวลา
    if ($action === 'edit_slot') {
        $id = (int)$_POST['slot_id'];
        $campaign_id = (int)$_POST['campaign_id'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $max = (int)$_POST['max_capacity'];

        if ($id > 0 && $campaign_id > 0 && $start_time && $end_time && $max >= 0) {
            // เช็คว่าคนที่จองเกินโควต้าใหม่ไหม
            $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = ? AND status IN ('booked', 'confirmed')");
            $check->execute([$id]);
            $used = (int)$check->fetchColumn();

            if ($max < $used) {
                echo json_encode(['status' => 'error', 'message' => "ไม่สามารถแก้ไขจำนวนโควต้าให้น้อยกว่าผู้ที่จองไปแล้วได้ ({$used} คน)"]);
                exit;
            }

            $pdo->prepare("UPDATE camp_slots SET campaign_id = ?, start_time = ?, end_time = ?, max_capacity = ? WHERE id = ?")
                ->execute([$campaign_id, $start_time, $end_time, $max, $id]);

            log_activity('edit_slot', "แก้ไขรอบเวลา ID: {$id} เป็น {$start_time}-{$end_time} จุ {$max}");

            echo json_encode(['status' => 'success', 'message' => 'แก้ไขรอบเวลาเรียบร้อยแล้ว']);
            exit;
        }
    }

    if ($action === 'delete_slot') {
        $id = (int)$_POST['slot_id'];
        
        // 1. ดึงข้อมูลผู้จองทั้งหมดที่ยังไม่ถูกยกเลิก
        $stmt = $pdo->prepare("
            SELECT b.id, u.email, u.full_name, u.line_user_id,
                   c.title as campaign_title, s.slot_date, s.start_time, s.end_time
            FROM camp_bookings b
            JOIN sys_users u ON b.student_id = u.id
            JOIN camp_slots s ON b.slot_id = s.id
            JOIN camp_list c ON s.campaign_id = c.id
            WHERE b.slot_id = ? AND b.status IN ('booked', 'confirmed')
        ");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();

        if (count($rows) > 0) {
            require_once __DIR__ . '/../includes/mail_helper.php';
            
            $failedList = [];
            $successCount = 0;

            foreach ($rows as $row) {
                $emailData = [
                    'campaign_title' => $row['campaign_title'],
                    'date'           => date('j M Y', strtotime($row['slot_date'])),
                    'time'           => substr($row['start_time'], 0, 5) . '-' . substr($row['end_time'], 0, 5),
                    'full_name'      => $row['full_name']
                ];

                $emailOk = true;
                if (!empty($row['email'])) {
                    // ส่งอีเมลและเช็คผล
                    $emailOk = notify_booking_status($row['email'], 'cancelled_by_admin', $emailData);
                }

                // ส่ง LINE (ถ้ามี) - ไม่บล็อก flow ถ้าส่ง LINE ไม่ผ่าน แต่จะ log ไว้
                if (!empty($row['line_user_id'])) {
                    try {
                        // ใช้ฟังก์ชันช่วยเหลือด้านล่าง (จะเพิ่มที่ท้ายไฟล์หรือเรียกจากที่อื่น)
                        send_line_notification_simple($row['line_user_id'], $emailData);
                    } catch (Exception $e) {
                        error_log("LINE notification failed for {$row['full_name']}: " . $e->getMessage());
                    }
                }

                if ($emailOk) {
                    // อัปเดตสถานะเป็นยกเลิกโดยแอดมิน
                    $pdo->prepare("UPDATE camp_bookings SET status = 'cancelled_by_admin' WHERE id = ?")->execute([$row['id']]);
                    $successCount++;
                } else {
                    $failedList[] = $row['full_name'] . " ({$row['email']})";
                }
            }

            if (count($failedList) > 0) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'ไม่สามารถลบรอบได้ เนื่องจากส่งอีเมลแจ้งเตือนล้มเหลวสำหรับ: ' . implode(', ', $failedList) . ' (กรุณาตรวจสอบการตั้งค่า SMTP หรืออีเมลของผู้ใช้)'
                ]);
                exit;
            }
        }

        // ลบ Slot (หลังจากเคลียร์คนออกหมดแล้ว หรือไม่มีคนจอง)
        $pdo->prepare("DELETE FROM camp_slots WHERE id = ?")->execute([$id]);
        
        log_activity('delete_slot', "ลบรอบเวลา ID: {$id} (มีการยกเลิกผู้จองและส่งอีเมลแจ้งเตือนเรียบร้อยแล้ว)");
        
        echo json_encode(['status' => 'success', 'message' => 'ระบบได้ส่งอีเมลแจ้งเตือนผู้จองและลบรอบเวลาเรียบร้อยแล้ว']);
        exit;
    }

    if ($action === 'delete_multiple_slots') {
        $ids = $_POST['slot_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่มีข้อมูลที่เลือก']);
            exit;
        }

        require_once __DIR__ . '/../includes/mail_helper.php';
        $deletedCount = 0;
        $failedSlots = [];
        $totalFailedEmails = [];

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            
            // ดึงผู้จองของ Slot นี้
            $stmt = $pdo->prepare("SELECT b.id, u.email, u.full_name, u.line_user_id, c.title as campaign_title, s.slot_date, s.start_time, s.end_time FROM camp_bookings b JOIN sys_users u ON b.student_id = u.id JOIN camp_slots s ON b.slot_id = s.id JOIN camp_list c ON s.campaign_id = c.id WHERE b.slot_id = ? AND b.status IN ('booked', 'confirmed')");
            $stmt->execute([$id]);
            $bookings = $stmt->fetchAll();

            $slotEmailFail = false;
            foreach ($bookings as $b) {
                $emailData = ['campaign_title' => $b['campaign_title'], 'date' => date('j M Y', strtotime($b['slot_date'])), 'time' => substr($b['start_time'], 0, 5) . '-' . substr($b['end_time'], 0, 5), 'full_name' => $b['full_name']];
                
                $ok = true;
                if (!empty($b['email'])) {
                    $ok = notify_booking_status($b['email'], 'cancelled_by_admin', $emailData);
                }
                
                if ($ok) {
                    $pdo->prepare("UPDATE camp_bookings SET status = 'cancelled_by_admin' WHERE id = ?")->execute([$b['id']]);
                } else {
                    $slotEmailFail = true;
                    $totalFailedEmails[] = $b['full_name'];
                }
            }

            if (!$slotEmailFail) {
                $pdo->prepare("DELETE FROM camp_slots WHERE id = ?")->execute([$id]);
                log_activity('delete_slot', "ลบรอบเวลา ID: {$id} (Bulk Delete)");
                $deletedCount++;
            } else {
                $failedSlots[] = $id;
            }
        }

        if ($deletedCount > 0) {
            $msg = "ลบสำเร็จ {$deletedCount} รอบเวลา";
            if (!empty($totalFailedEmails)) {
                $msg .= "\n(มีบางรอบลบไม่ได้เนื่องจากส่งเมลหา " . implode(', ', array_unique($totalFailedEmails)) . " ล้มเหลว)";
            }
            echo json_encode(['status' => 'success', 'message' => $msg]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "ไม่สามารถลบรอบที่เลือกได้ เนื่องจากปัญหาการส่งอีเมลแจ้งเตือน"]);
        }
        exit;
    }
}

// ==========================================
// ส่วนดึงข้อมูลเพื่อแสดงผล (Calendar Data)
// ==========================================
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$stmt = $pdo->prepare("
    SELECT ts.*, c.title as campaign_title,
           (SELECT COUNT(*) FROM camp_bookings a WHERE a.slot_id = ts.id AND a.status IN ('booked', 'confirmed')) as booked_count
    FROM camp_slots ts 
    JOIN camp_list c ON ts.campaign_id = c.id 
    WHERE MONTH(ts.slot_date) = ? AND YEAR(ts.slot_date) = ?
    ORDER BY ts.slot_date, ts.start_time
");
$stmt->execute([$month, $year]);
$slots = $stmt->fetchAll();

$calendarData = [];
foreach ($slots as $s) {
    $calendarData[$s['slot_date']][] = $s;
}

require_once __DIR__ . '/includes/header.php';

$header_actions = '
<button id="deleteMultiBtn" onclick="deleteSelectedSlots()" style="display: none;" class="ts-danger-btn">
    <i class="fa-solid fa-trash-can"></i> ลบที่เลือก (<span id="selectedSlotCount">0</span>)
</button>
<div class="relative" id="multiSelectContainer">
    <button type="button" onclick="toggleMultiSelect(event)" class="ts-multi-trigger">
        <span id="multiSelectLabel" class="truncate">แสดงทุกแคมเปญ</span>
        <i class="fa-solid fa-chevron-down text-[10px]" style="color:var(--ec-ink-4); flex-shrink:0;"></i>
    </button>
    <div id="multiSelectDropdown" class="ts-multi-dropdown" style="display:none;position:fixed;z-index:9001;flex-direction:column;" onclick="event.stopPropagation()">
        <div class="ts-multi-header">
            <div style="position:relative;">
                <i class="fa-solid fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--ec-ink-4);font-size:11px;"></i>
                <input type="text" id="multiSelectSearch" onkeyup="searchCampaigns(this.value)" placeholder="ค้นหาแคมเปญ..." class="ts-multi-search" style="padding-left:28px;">
            </div>
        </div>
        <div class="ts-multi-list" id="multiSelectList">
            <label class="ts-multi-item">
                <input type="checkbox" id="selectAllCamps" checked onchange="toggleAllCampaigns(this)" class="w-4 h-4 cursor-pointer" style="accent-color:var(--ec-brand-500);">
                <span style="font-weight:700;color:var(--ec-ink-1);">เลือกทั้งหมด</span>
            </label>
            <div style="height:1px; background:var(--ec-border-soft); margin:4px 0;"></div>';
            foreach ($activeCampaigns as $ac) {
                $header_actions .= '
                <label class="camp-label-item ts-multi-item" data-title="' . htmlspecialchars(strtolower($ac['title'])) . '">
                    <input type="checkbox" value="' . (int)$ac['id'] . '" checked onchange="updateMultiSelectFilter()" class="camp-checkbox w-4 h-4 cursor-pointer" style="accent-color:var(--ec-brand-500);">
                    <span>' . htmlspecialchars($ac['title']) . '</span>
                </label>';
            }
            $header_actions .= '
        </div>
    </div>
</div>
<div class="ts-view-toggle">
    <button onclick="switchView(\'calendar\')" id="btnViewCalendar" class="is-active" title="มุมมองปฏิทิน">
        <i class="fa-solid fa-calendar-alt"></i>
    </button>
    <button onclick="switchView(\'table\')" id="btnViewTable" title="มุมมองตาราง">
        <i class="fa-solid fa-list-ul"></i>
    </button>
</div>
<button id="addSlotBtn" onclick="openAddSlotModal(\'' . date('Y-m-d') . '\')" class="ts-cta">
    <i class="fa-solid fa-plus-circle"></i><span class="btn-add-text">สร้างรอบเวลา</span>
</button>
<select id="monthSelect" onchange="location.href=\'?month=\'+this.value.split(\'-\')[1]+\'&year=\'+this.value.split(\'-\')[0]" class="ts-month-select">';
    for ($i = -3; $i <= 6; $i++) {
        $d = date('Y-m', strtotime("$i months"));
        $selected = ($d == "$year-".str_pad($month, 2, '0', STR_PAD_LEFT)) ? 'selected' : '';
        $header_actions .= "<option value='{$d}' {$selected}>".date('M Y', strtotime("$i months"))."</option>";
    }
$header_actions .= '</select>';

renderPageHeader("รอบเวลาแคมเปญ", "เลือกวันและเวลาเปิดรับจอง — สร้างพร้อมกันหลายวันได้", $header_actions);
?>

<style>
/* ── Time Slots — uses CSS vars from shell, dark-mode aware ─── */
@keyframes slideUpFade {
    0%   { opacity: 0; transform: translateY(12px); }
    100% { opacity: 1; transform: translateY(0); }
}
.animate-slide-up { animation: slideUpFade .45s cubic-bezier(.16,1,.3,1) both; }
.delay-100        { animation-delay: .08s; }
@media (prefers-reduced-motion: reduce) {
    .animate-slide-up { animation: none; }
}

/* ── Scrollbar ───────────────────────────────── */
::-webkit-scrollbar       { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--ec-border); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: var(--ec-ink-4); }
.scrollbar-hide { scrollbar-width: none; }
.scrollbar-hide::-webkit-scrollbar { display: none; }

/* ── Toolbar mini-controls (header actions) ──── */
.ts-mini-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    border-radius: 12px;
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    font-size: 13px;
    font-weight: 700;
    color: var(--ec-ink-2);
    transition: all .15s;
    cursor: pointer;
}
.ts-mini-btn:hover {
    border-color: var(--ec-brand-200);
    color: var(--ec-brand-700);
    transform: translateY(-1px);
    box-shadow: var(--ec-shadow-md);
}
body[data-theme='dark'] .ts-mini-btn:hover {
    color: var(--ec-brand-400);
    border-color: rgba(46,158,99,.3);
}
.ts-cta {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px;
    border-radius: 12px;
    color: #fff;
    background: linear-gradient(135deg, #1f7a4d, #2e9e63 60%, #34d399);
    font-size: 13px;
    font-weight: 800;
    border: 0;
    cursor: pointer;
    box-shadow: 0 8px 20px -6px rgba(46,158,99,.45);
    transition: all .15s;
}
.ts-cta:hover { transform: translateY(-1px); box-shadow: 0 14px 28px -6px rgba(46,158,99,.55); }
.ts-view-toggle {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px;
    border-radius: 12px;
    background: var(--ec-surface-2);
    border: 1px solid var(--ec-border);
}
.ts-view-toggle button {
    padding: 6px 10px;
    border-radius: 9px;
    background: transparent;
    border: 0;
    cursor: pointer;
    color: var(--ec-ink-3);
    font-size: 13px;
    transition: all .15s;
}
.ts-view-toggle button.is-active {
    background: var(--ec-surface);
    color: var(--ec-brand-700);
    box-shadow: 0 1px 3px rgba(15,23,42,.08);
}
body[data-theme='dark'] .ts-view-toggle button.is-active {
    background: rgba(46,158,99,.18);
    color: var(--ec-brand-400);
    box-shadow: none;
}
.ts-month-select {
    padding: 8px 14px;
    border-radius: 12px;
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    font-size: 13px;
    font-weight: 700;
    color: var(--ec-ink-1);
    cursor: pointer;
    outline: none;
    transition: border-color .15s;
}
.ts-month-select:hover { border-color: var(--ec-brand-200); }
.ts-month-select:focus { border-color: var(--ec-brand-500); box-shadow: 0 0 0 3px rgba(46,158,99,.15); }

.ts-danger-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    border-radius: 12px;
    background: linear-gradient(135deg, #dc2626, #ef4444);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    box-shadow: 0 6px 16px -4px rgba(239,68,68,.4);
    transition: all .15s;
}
.ts-danger-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 24px -6px rgba(239,68,68,.5); }

/* ── Multi-select campaign dropdown ─────────── */
.ts-multi-trigger {
    display: inline-flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 8px 14px;
    border-radius: 12px;
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    font-size: 13px;
    font-weight: 700;
    color: var(--ec-brand-700);
    cursor: pointer;
    transition: border-color .15s;
    max-width: 224px; min-width: 0;
}
.ts-multi-trigger:hover { border-color: var(--ec-brand-200); }
body[data-theme='dark'] .ts-multi-trigger { color: var(--ec-brand-400); }
.ts-multi-dropdown {
    width: 256px;
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    border-radius: 14px;
    box-shadow: 0 14px 40px -10px rgba(15,23,42,.18);
    overflow: hidden;
}
body[data-theme='dark'] .ts-multi-dropdown {
    box-shadow: 0 14px 40px -10px rgba(0,0,0,.6);
}
.ts-multi-header {
    padding: 8px;
    background: var(--ec-surface-2);
    border-bottom: 1px solid var(--ec-border-soft);
}
.ts-multi-search {
    width: 100%;
    padding: 6px 30px 6px 28px;
    font-size: 13px;
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    border-radius: 9px;
    color: var(--ec-ink-1);
    outline: none;
}
.ts-multi-search:focus { border-color: var(--ec-brand-500); box-shadow: 0 0 0 3px rgba(46,158,99,.15); }
.ts-multi-list { max-height: 240px; overflow-y: auto; padding: 6px; }
.ts-multi-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px;
    border-radius: 9px;
    cursor: pointer;
    transition: background .12s;
}
.ts-multi-item:hover { background: var(--ec-surface-2); }
.ts-multi-item span {
    font-size: 13px;
    color: var(--ec-ink-2);
    flex: 1;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ── Calendar container ──────────────────────── */
.cal-wrap {
    background: var(--ec-surface);
    border-radius: 20px;
    box-shadow: var(--ec-shadow-md);
    border: 1px solid var(--ec-border);
    overflow: hidden;
}

/* ── Day-header row ──────────────────────────── */
.cal-head {
    background: linear-gradient(135deg, #2e9e63 0%, #10b981 100%);
    color: #fff;
    padding: 10px 0;
    text-align: center;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.cal-head.sunday { color: #fecaca; }

/* ── Day cell ────────────────────────────────── */
.cal-cell {
    background: var(--ec-surface);
    min-height: 130px;
    padding: 8px;
    border-top: 1px solid var(--ec-border-soft);
    border-right: 1px solid var(--ec-border-soft);
    display: flex;
    flex-direction: column;
    transition: background .15s;
    position: relative;
}
.cal-cell:hover { background: var(--ec-surface-2); }
.cal-cell.empty {
    background: var(--ec-surface-2);
    opacity: .6;
}
body[data-theme='dark'] .cal-cell:hover {
    background: rgba(255,255,255,.04);
}

/* ── Date number ─────────────────────────────── */
.cal-date {
    width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; border-radius: 50%;
    color: var(--ec-ink-2);
    flex-shrink: 0;
}
.cal-date.today {
    background: linear-gradient(135deg, #2e9e63, #34d399);
    color: #fff;
    box-shadow: 0 3px 10px rgba(46,158,99,.4);
}

/* ── Add-slot hover button ───────────────────── */
.cal-add-btn {
    opacity: 0; transition: opacity .15s, transform .15s;
    width: 24px; height: 24px;
    background: var(--ec-brand-50);
    color: var(--ec-brand-700);
    border-radius: 8px; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
}
.cal-cell:hover .cal-add-btn { opacity: 1; }
.cal-add-btn:hover { transform: scale(1.08); }
body[data-theme='dark'] .cal-add-btn {
    background: rgba(46,158,99,.18);
    color: var(--ec-brand-400);
}

/* ── Slot card inside cell ───────────────────── */
.slot-card {
    border-radius: 8px;
    padding: 5px 7px;
    margin-bottom: 4px;
    border: 1px solid var(--ec-border-soft);
    position: relative;
    transition: box-shadow .15s, transform .15s;
    cursor: default;
}
.slot-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
    transform: translateY(-1px);
    z-index: 1;
}
body[data-theme='dark'] .slot-card:hover {
    box-shadow: 0 6px 18px rgba(0,0,0,.5);
}
.slot-card .slot-actions {
    position: absolute; top: 4px; right: 4px;
    display: none; gap: 3px;
}
.slot-card:hover .slot-actions { display: flex; }
.slot-act-btn {
    width: 18px; height: 18px;
    border-radius: 5px; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px; transition: all .15s;
}
.slot-act-btn:hover { transform: scale(1.12); }

/* ── Capacity bar ────────────────────────────── */
.cap-bar {
    height: 3px;
    border-radius: 99px;
    background: rgba(0,0,0,.08);
    overflow: hidden;
    margin: 3px 0;
}
body[data-theme='dark'] .cap-bar { background: rgba(255,255,255,.1); }
.cap-bar-fill { height: 100%; border-radius: 99px; transition: width .3s; }

/* ── Table ───────────────────────────────────── */
.slots-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.slots-table thead th {
    background: linear-gradient(135deg, #2e9e63 0%, #10b981 100%);
    color: rgba(255,255,255,.92);
    font-size: 11px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    padding: 14px 20px;
}
.slots-table thead th:first-child { border-radius: 14px 0 0 0; }
.slots-table thead th:last-child  { border-radius: 0 14px 0 0; }
.slots-table tbody tr { transition: background .12s; }
.slots-table tbody tr:hover td { background: var(--ec-surface-2); }
.slots-table tbody td {
    padding: 14px 20px;
    border-bottom: 1px solid var(--ec-border-soft);
    font-size: 13.5px;
    background: var(--ec-surface);
    color: var(--ec-ink-2);
}

/* ── Status badge ────────────────────────────── */
.stat-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 99px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}

/* ── Slot tones (used by both calendar + table + modals) ── */
.tone-full  { background: #fee2e2; color: #b91c1c; }
.tone-near  { background: #fef9c3; color: #a16207; }
.tone-ok    { background: #dcfce7; color: #15803d; }
body[data-theme='dark'] .tone-full { background: rgba(239,68,68,.2); color: #fca5a5; }
body[data-theme='dark'] .tone-near { background: rgba(245,158,11,.2); color: #fcd34d; }
body[data-theme='dark'] .tone-ok   { background: rgba(34,197,94,.2);  color: #86efac; }

/* Slot card color rotation (campaign-driven) — dark-mode aware */
.slot-pal-emerald { background: #ecfdf5; color: #047857; }
.slot-pal-green   { background: #f0fdf4; color: #15803d; }
.slot-pal-purple  { background: #faf5ff; color: #6b21a8; }
.slot-pal-orange  { background: #fff7ed; color: #c2410c; }
.slot-pal-red     { background: #fef2f2; color: #b91c1c; }
.slot-pal-teal    { background: #f0fdfa; color: #0f766e; }
body[data-theme='dark'] .slot-pal-emerald { background: rgba(16,185,129,.15); color: #6ee7b7; }
body[data-theme='dark'] .slot-pal-green   { background: rgba(34,197,94,.15);  color: #86efac; }
body[data-theme='dark'] .slot-pal-purple  { background: rgba(168,85,247,.15); color: #d8b4fe; }
body[data-theme='dark'] .slot-pal-orange  { background: rgba(249,115,22,.15); color: #fdba74; }
body[data-theme='dark'] .slot-pal-red     { background: rgba(239,68,68,.15);  color: #fca5a5; }
body[data-theme='dark'] .slot-pal-teal    { background: rgba(20,184,166,.15); color: #5eead4; }

/* ── Modal — Portal-Escape pattern (z-index 9000+) ─── */
.ts-modal {
    position: fixed;
    inset: 0;
    z-index: 9000 !important;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15,23,42,.6);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}
.ts-modal-box {
    background: var(--ec-surface);
    border-radius: 22px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--ec-border);
    box-shadow: 0 28px 60px -10px rgba(0,0,0,.35);
    animation: slideUpFade .35s cubic-bezier(.16,1,.3,1) both;
}
.ts-modal-box.lg { max-width: 760px; }
.ts-modal-header {
    padding: 18px 22px;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
    color: #fff;
}
.ts-modal-header.brand  { background: linear-gradient(135deg, #1f7a4d, #2e9e63 60%, #34d399); }
.ts-modal-header.amber  { background: linear-gradient(135deg, #c2410c, #f59e0b 60%, #fbbf24); }
.ts-modal-header.brand-soft {
    background: linear-gradient(135deg, var(--ec-brand-50), #f0fdf4);
    color: var(--ec-ink-1);
    border-bottom: 1px solid var(--ec-border-soft);
}
body[data-theme='dark'] .ts-modal-header.brand-soft {
    background: linear-gradient(135deg, rgba(46,158,99,.2), rgba(46,158,99,.08));
    color: var(--ec-ink-1);
}
.ts-modal-title {
    display: flex; align-items: center; gap: 12px;
    font-size: 16px; font-weight: 800;
    margin: 0;
}
.ts-modal-icon {
    width: 36px; height: 36px;
    background: rgba(255,255,255,.22);
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
}
.ts-modal-header.brand-soft .ts-modal-icon {
    background: linear-gradient(135deg, #2e9e63, #34d399);
    color: #fff;
}
.ts-modal-close {
    width: 32px; height: 32px;
    background: rgba(255,255,255,.2);
    color: #fff;
    border-radius: 99px;
    border: 0; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.ts-modal-close:hover { background: rgba(255,255,255,.32); }
.ts-modal-header.brand-soft .ts-modal-close {
    background: var(--ec-surface-2); color: var(--ec-ink-3);
}
.ts-modal-header.brand-soft .ts-modal-close:hover { background: var(--ec-border); color: var(--ec-ink-1); }
.ts-modal-body {
    padding: 18px 22px;
    overflow-y: auto;
    flex: 1;
    color: var(--ec-ink-2);
}
.ts-modal-footer {
    padding: 14px 22px;
    border-top: 1px solid var(--ec-border-soft);
    background: var(--ec-surface-2);
    flex-shrink: 0;
    display: flex; gap: 10px;
}

/* Modal inputs (dark-mode aware) */
.ts-input, .ts-select {
    width: 100%;
    padding: 9px 14px;
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    border-radius: 12px;
    font-size: 13px;
    color: var(--ec-ink-1);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.ts-input:focus, .ts-select:focus {
    border-color: var(--ec-brand-500);
    box-shadow: 0 0 0 3px rgba(46,158,99,.15);
}
.ts-input[type="time"], .ts-input[type="number"] {
    font-variant-numeric: tabular-nums;
}
.ts-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--ec-ink-2);
    margin-bottom: 6px;
}
.ts-label-eyebrow {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: var(--ec-ink-3);
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 6px;
}
.ts-field-card {
    background: var(--ec-surface-2);
    padding: 12px;
    border-radius: 14px;
    border: 1px solid var(--ec-border-soft);
}
.ts-field-card.brand {
    background: var(--ec-brand-50);
    border-color: var(--ec-brand-200);
}
body[data-theme='dark'] .ts-field-card.brand {
    background: rgba(46,158,99,.1);
    border-color: rgba(46,158,99,.25);
}

.ts-btn-primary {
    padding: 12px 20px;
    background: linear-gradient(135deg, #1f7a4d, #2e9e63 60%, #34d399);
    color: #fff;
    border: 0; border-radius: 14px;
    font-size: 14px; font-weight: 800;
    cursor: pointer;
    transition: all .15s;
    box-shadow: 0 8px 20px -6px rgba(46,158,99,.45);
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
}
.ts-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 14px 28px -6px rgba(46,158,99,.55); }
.ts-btn-amber {
    padding: 12px 20px;
    background: linear-gradient(135deg, #c2410c, #f59e0b 60%, #fbbf24);
    color: #fff;
    border: 0; border-radius: 14px;
    font-size: 14px; font-weight: 800;
    cursor: pointer;
    transition: all .15s;
    box-shadow: 0 8px 20px -6px rgba(245,158,11,.45);
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
}
.ts-btn-amber:hover { transform: translateY(-1px); box-shadow: 0 14px 28px -6px rgba(245,158,11,.55); }
.ts-btn-ghost {
    padding: 12px 20px;
    background: var(--ec-surface);
    color: var(--ec-ink-2);
    border: 1px solid var(--ec-border);
    border-radius: 14px;
    font-size: 14px; font-weight: 700;
    cursor: pointer;
    transition: all .15s;
}
.ts-btn-ghost:hover {
    background: var(--ec-surface-2);
    border-color: var(--ec-ink-4);
}

/* ── Action button row in table ──────────────── */
.ts-row-btn {
    width: 32px; height: 32px;
    border-radius: 10px;
    border: 1px solid;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all .15s;
    background: transparent;
}
.ts-row-btn:hover { transform: translateY(-1px); }
.ts-row-btn.qr     { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.ts-row-btn.qr:hover { background: #10b981; color: #fff; border-color: #10b981; }
.ts-row-btn.edit   { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.ts-row-btn.edit:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.ts-row-btn.del    { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.ts-row-btn.del:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
body[data-theme='dark'] .ts-row-btn.qr   { background: rgba(16,185,129,.15);  color: #6ee7b7; border-color: rgba(16,185,129,.3); }
body[data-theme='dark'] .ts-row-btn.edit { background: rgba(245,158,11,.15);  color: #fcd34d; border-color: rgba(245,158,11,.3); }
body[data-theme='dark'] .ts-row-btn.del  { background: rgba(239,68,68,.15);   color: #fca5a5; border-color: rgba(239,68,68,.3); }

/* ── Empty state ─────────────────────────────── */
.ts-empty {
    background: var(--ec-surface);
    border: 1px dashed var(--ec-border);
    border-radius: 22px;
    padding: 40px 20px;
    text-align: center;
    color: var(--ec-ink-3);
}
.ts-empty-icon {
    width: 72px; height: 72px;
    background: var(--ec-surface-2);
    color: var(--ec-ink-4);
    border-radius: 999px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-size: 32px;
}
.ts-empty h3 {
    font-size: 17px;
    font-weight: 700;
    color: var(--ec-ink-1);
    margin: 0 0 6px;
}
.ts-empty p {
    font-size: 13px;
    color: var(--ec-ink-3);
    margin: 0;
    max-width: 360px;
    margin: 0 auto;
}

/* ── Mobile responsive ───────────────────────── */
@media (max-width: 639px) {
    .cal-cell    { min-height: 70px; padding: 4px 3px; }
    .cal-head    { font-size: 9px; padding: 6px 0; }
    .cal-date    { width: 20px; height: 20px; font-size: 11px; }
    .slot-card   { padding: 3px 5px; margin-bottom: 3px; }
    .btn-add-text { display: none; }
    .ts-cta      { padding: 8px 12px; }
    .ts-month-select { padding: 8px 10px; font-size: 12px; }
    .ts-multi-trigger { max-width: 160px; }
    .ts-modal-box { max-height: 96vh; }
    .ts-modal-header, .ts-modal-body, .ts-modal-footer { padding-left: 16px; padding-right: 16px; }
}

/* ── DataTable (simple-datatables) — dark mode + brand focus ── */
.dataTable-wrapper .dataTable-container {
    border-bottom: 1px solid var(--ec-border-soft);
    font-family: inherit;
}
.dataTable-table > thead > tr > th {
    border-bottom: 1px solid var(--ec-border);
}
.dataTable-input, .dataTable-selector {
    background: var(--ec-surface);
    border: 1px solid var(--ec-border);
    border-radius: 10px;
    padding: .4rem .6rem;
    font-size: 13px;
    outline: none;
    color: var(--ec-ink-1);
    font-family: inherit;
}
.dataTable-input:focus, .dataTable-selector:focus {
    border-color: var(--ec-brand-500);
    box-shadow: 0 0 0 3px rgba(46,158,99,.15);
}
.dataTable-info, .dataTable-bottom {
    font-size: 13px;
    color: var(--ec-ink-3);
    margin-top: .5rem;
}
.dataTable-pagination a {
    color: var(--ec-ink-2);
    border-radius: 8px;
    padding: 4px 9px;
}
.dataTable-pagination .active a, .dataTable-pagination a:hover {
    background: var(--ec-brand-500);
    color: #fff;
}

/* Flatpickr dark mode tweaks */
body[data-theme='dark'] .flatpickr-calendar {
    background: var(--ec-surface);
    border-color: var(--ec-border);
    box-shadow: 0 14px 40px -10px rgba(0,0,0,.6);
}
body[data-theme='dark'] .flatpickr-day {
    color: var(--ec-ink-2);
}
body[data-theme='dark'] .flatpickr-day:hover {
    background: rgba(46,158,99,.18);
}
body[data-theme='dark'] .flatpickr-day.selected {
    background: var(--ec-brand-500);
    color: #fff;
    border-color: var(--ec-brand-500);
}
body[data-theme='dark'] .flatpickr-months,
body[data-theme='dark'] .flatpickr-weekdays {
    background: var(--ec-surface);
    color: var(--ec-ink-1);
}
body[data-theme='dark'] .flatpickr-weekday { color: var(--ec-ink-3); }
body[data-theme='dark'] .flatpickr-current-month .cur-month,
body[data-theme='dark'] .flatpickr-current-month input.cur-year {
    color: var(--ec-ink-1);
}
body[data-theme='dark'] .flatpickr-month .flatpickr-prev-month svg,
body[data-theme='dark'] .flatpickr-month .flatpickr-next-month svg {
    fill: var(--ec-ink-2);
}
</style>

<?php if (count($slots) === 0): ?>
<div class="ts-empty animate-slide-up" style="margin-bottom:24px;">
    <div class="ts-empty-icon">
        <i class="fa-regular fa-calendar-times"></i>
    </div>
    <h3>ยังไม่มีรอบเวลาในเดือนนี้</h3>
    <p>เลือกเดือน/ปีจากแถบเครื่องมือด้านบน แล้วกด "สร้างรอบเวลา" เพื่อเริ่มเปิดรับจอง</p>
</div>
<?php endif; ?>

<div id="calendarViewContainer" class="animate-slide-up delay-100 mb-10">
    <div class="cal-wrap">
        <div class="grid grid-cols-7">
        <?php
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $firstDay = date('N', strtotime("$year-$month-01"));
        $weekdays = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];

        foreach ($weekdays as $index => $day) {
            $sunday = ($index == 6) ? 'sunday' : '';
            echo "<div class='cal-head {$sunday}'>$day</div>";
        }

        for ($i = 1; $i < $firstDay; $i++) echo "<div class='cal-cell empty'></div>";

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
            $isToday = $currentDate == date('Y-m-d');
            ?>
            <div class="cal-cell">
                <div class="flex justify-between items-center mb-2">
                    <div class="flex items-center gap-1.5">
                        <input type="checkbox" class="day-select-cb w-3.5 h-3.5 text-red-500 rounded border-gray-300 focus:ring-red-500 cursor-pointer opacity-40 hover:opacity-100 checked:opacity-100 transition-opacity" onchange="toggleDaySlots(this)" title="เลือกทั้งหมดในวันนี้">
                        <span class="cal-date <?= $isToday ? 'today' : '' ?> <?= isset($calendarData[$currentDate]) ? 'cursor-pointer hover:ring-2 hover:ring-[#2e9e63]/40' : '' ?>"
                              <?= isset($calendarData[$currentDate]) ? "onclick=\"openDailyModal('{$currentDate}')\" title=\"ดูรอบวันนี้\"" : '' ?>><?= $day ?></span>
                    </div>
                    <button onclick="openAddSlotModal('<?= $currentDate ?>')" class="cal-add-btn" title="เพิ่มรอบ">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                </div>

                <div class="overflow-y-auto scrollbar-hide" style="max-height:110px">
                    <?php if (isset($calendarData[$currentDate])): ?>
                        <?php foreach ($calendarData[$currentDate] as $s):
                            $cId   = $s['campaign_id'] ?? 0;
                            $cc    = $campaignColors[$cId] ?? ['cls' => 'slot-pal-emerald'];
                            $booked  = (int)($s['booked_count'] ?? 0);
                            $max     = (int)$s['max_capacity'];
                            $percent = $max > 0 ? ($booked / $max) * 100 : 0;

                            if ($percent >= 100) {
                                $toneCls = 'tone-full'; $barClr = '#ef4444';
                            } elseif ($percent >= 80) {
                                $toneCls = 'tone-near'; $barClr = '#f59e0b';
                            } else {
                                $toneCls = 'tone-ok';   $barClr = '#22c55e';
                            }
                        ?>
                        <div class="slot-item slot-card filter-camp-<?= (int)$cId ?> <?= $cc['cls'] ?>">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-1.5">
                                    <input type="checkbox" value="<?= (int)$s['id'] ?>"
                                        class="slot-select-cb calendar-slot-cb w-3 h-3 rounded cursor-pointer opacity-40 hover:opacity-100 checked:opacity-100 transition-opacity flex-shrink-0"
                                        style="accent-color:#ef4444;"
                                        onchange="toggleSlotSelection(this)">
                                    <span class="text-[11px] font-bold <?= $percent >= 100 ? 'line-through opacity-50' : '' ?>"><?= substr($s['start_time'], 0, 5) ?></span>
                                </div>
                                <span class="stat-badge <?= $toneCls ?>" style="padding:2px 7px; font-size:10px;" title="<?= (int)$booked ?>/<?= (int)$max ?>">
                                    <?= (int)$booked ?>/<?= (int)$max ?>
                                </span>
                            </div>
                            <div class="cap-bar mt-1">
                                <div class="cap-bar-fill" style="width:<?= min($percent,100) ?>%;background:<?= $barClr ?>"></div>
                            </div>
                            <div class="truncate text-[9px] font-semibold opacity-75 mt-0.5" title="<?= htmlspecialchars($s['campaign_title']) ?>">
                                <?= htmlspecialchars($s['campaign_title']) ?>
                            </div>
                            <div class="slot-actions">
                                <button onclick="showQrModal(<?= (int)$s['id'] ?>,<?= (int)$s['campaign_id'] ?>)"
                                    class="slot-act-btn" style="background:#dcfce7;color:#16a34a" title="QR Check-in">
                                    <i class="fa-solid fa-qrcode"></i>
                                </button>
                                <button onclick="openEditSlotModal(<?= (int)$s['id'] ?>,<?= (int)$cId ?>,'<?= substr($s['start_time'],0,5) ?>','<?= substr($s['end_time'],0,5) ?>',<?= (int)$max ?>)"
                                    class="slot-act-btn" style="background:#fef3c7;color:#d97706" title="แก้ไข">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <?php if ($booked > 0): ?>
                                <button onclick="bulkCancelSlot(<?= (int)$s['id'] ?>,<?= json_encode($s['campaign_title']) ?>,'<?= htmlspecialchars($s['slot_date']) ?>','<?= substr($s['start_time'],0,5) ?>-<?= substr($s['end_time'],0,5) ?>',<?= (int)$booked ?>)"
                                    class="slot-act-btn" style="background:#dbeafe;color:#0284c7" title="ยกเลิกการจองทั้งหมด">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="deleteSlot(<?= (int)$s['id'] ?>)"
                                    class="slot-act-btn" style="background:#fee2e2;color:#dc2626" title="ลบ">
                                    <i class="fa-solid fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
        ?>
        </div>
    </div>
</div>

<div id="tableViewContainer" class="hidden animate-slide-up delay-100 mb-10 overflow-hidden" style="background:var(--ec-surface);border-radius:20px;box-shadow:var(--ec-shadow-md);border:1px solid var(--ec-border);">
    <div class="overflow-x-auto">
        <table id="slotsTable" class="slots-table">
            <thead>
                <tr>
                    <th class="text-center w-10" data-sortable="false">
                        <input type="checkbox" id="selectAllTable" class="w-4 h-4 rounded cursor-pointer" style="accent-color:#fff;" onchange="toggleAllTableSlots(this)">
                    </th>
                    <th><i class="fa-regular fa-calendar mr-1.5 opacity-70"></i>วันที่</th>
                    <th><i class="fa-regular fa-clock mr-1.5 opacity-70"></i>เวลา</th>
                    <th><i class="fa-solid fa-bookmark mr-1.5 opacity-70"></i>แคมเปญ</th>
                    <th class="text-center"><i class="fa-solid fa-users mr-1.5 opacity-70"></i>ยอดจอง</th>
                    <th class="text-center"><i class="fa-solid fa-gear mr-1.5 opacity-70"></i>จัดการ</th>
                </tr>
            </thead>
            <tbody class="text-sm">
                <?php foreach ($slots as $s):
                    $booked  = (int)($s['booked_count'] ?? 0);
                    $max     = (int)$s['max_capacity'];
                    $percent = $max > 0 ? ($booked / $max) * 100 : 0;
                    if ($percent >= 100)    { $toneCls = 'tone-full'; }
                    elseif ($percent >= 80) { $toneCls = 'tone-near'; }
                    else                    { $toneCls = 'tone-ok'; }
                    $dateObj = new DateTime($s['slot_date']);
                ?>
                <tr data-camp-id="<?= (int)$s['campaign_id'] ?>">
                    <td class="text-center">
                        <input type="checkbox" value="<?= (int)$s['id'] ?>" class="slot-select-cb table-slot-cb w-4 h-4 rounded cursor-pointer opacity-50 hover:opacity-100 checked:opacity-100 transition-opacity" style="accent-color:var(--ec-brand-500);" onchange="toggleSlotSelection(this)">
                    </td>
                    <td data-sort="<?= htmlspecialchars($s['slot_date']) ?>">
                        <span style="font-weight:700; color:var(--ec-ink-1);"><?= $dateObj->format('d/m/Y') ?></span>
                    </td>
                    <td>
                        <span style="font-weight:800; color:var(--ec-brand-700); background:var(--ec-brand-50); padding:4px 10px; border-radius:9px; font-size:12px;"><?= substr($s['start_time'],0,5) ?> – <?= substr($s['end_time'],0,5) ?></span>
                    </td>
                    <td style="color:var(--ec-ink-2); font-weight:500; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($s['campaign_title']) ?></td>
                    <td class="text-center" data-sort="<?= $percent ?>">
                        <span class="stat-badge <?= $toneCls ?>"><?= (int)$booked ?> / <?= (int)$max ?></span>
                    </td>
                    <td class="text-center">
                        <div class="flex items-center justify-center gap-2">
                            <button onclick="showQrModal(<?= (int)$s['id'] ?>,<?= (int)$s['campaign_id'] ?>)" class="ts-row-btn qr" title="QR Check-in">
                                <i class="fa-solid fa-qrcode text-xs"></i>
                            </button>
                            <button onclick="openEditSlotModal(<?= (int)$s['id'] ?>,<?= (int)$s['campaign_id'] ?>,'<?= substr($s['start_time'],0,5) ?>','<?= substr($s['end_time'],0,5) ?>',<?= (int)$max ?>)" class="ts-row-btn edit" title="แก้ไข">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>
                            <button onclick="deleteSlot(<?= (int)$s['id'] ?>)" class="ts-row-btn del" title="ลบ">
                                <i class="fa-solid fa-trash text-xs"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="slotModal" class="ts-modal hidden">
    <div class="ts-modal-box">
        <div class="ts-modal-header brand">
            <h3 class="ts-modal-title">
                <div class="ts-modal-icon"><i class="fa-solid fa-calendar-plus"></i></div>
                สร้างรอบเวลาแคมเปญ
            </h3>
            <button type="button" onclick="closeTsModal('slotModal')" class="ts-modal-close" aria-label="ปิด"><i class="fa-solid fa-times"></i></button>
        </div>
        <form id="slotForm" style="display:flex; flex-direction:column; flex:1; overflow:hidden;">
            <input type="hidden" name="action" value="add_slot">
            <?php csrf_field(); ?>

            <div class="ts-modal-body scrollbar-hide" style="display:flex; flex-direction:column; gap:14px;">

            <div>
                <label class="ts-label">เลือกแคมเปญ <span style="color:#ef4444;">*</span></label>
                <div style="position:relative;">
                    <select name="campaign_id" required class="ts-select" style="appearance:none; padding-right:36px;">
                        <option value="" disabled selected>-- เลือกกิจกรรม --</option>
                        <?php foreach ($activeCampaigns as $ac): ?>
                            <option value="<?= (int)$ac['id'] ?>"><?= htmlspecialchars($ac['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-chevron-down" style="position:absolute; top:50%; right:14px; transform:translateY(-50%); color:var(--ec-ink-4); font-size:11px; pointer-events:none;"></i>
                </div>
            </div>

            <div class="ts-field-card">
                <label class="ts-label-eyebrow">เลือกวันที่ต้องการจัดกิจกรรม (เลือกได้หลายวัน) *</label>
                <input type="text" name="selected_dates" id="modal_selected_dates" placeholder="คลิกเพื่อเลือกจากปฏิทิน..." required class="ts-input" style="cursor:pointer;">
            </div>

            <!-- โซนสร้างช่วงเวลาอัตโนมัติ -->
            <div class="ts-field-card brand">
                <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer;" onclick="document.getElementById('autoGenBody').classList.toggle('hidden'); document.getElementById('autoGenIcon').classList.toggle('fa-chevron-down'); document.getElementById('autoGenIcon').classList.toggle('fa-chevron-up');">
                    <label style="font-size:13px; font-weight:700; color:var(--ec-brand-700); cursor:pointer; display:flex; align-items:center; gap:8px; margin:0;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> สร้างช่วงเวลาย่อยอัตโนมัติ
                    </label>
                    <i id="autoGenIcon" class="fa-solid fa-chevron-down" style="color:var(--ec-brand-500); font-size:11px;"></i>
                </div>

                <div id="autoGenBody" class="hidden" style="padding-top:12px; border-top:1px solid var(--ec-brand-200); margin-top:10px; display:flex; flex-direction:column; gap:10px;">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="ts-label-eyebrow">เริ่มงาน</label>
                            <input type="time" id="auto_start" value="09:00" class="ts-input" style="padding:7px 12px;">
                        </div>
                        <div>
                            <label class="ts-label-eyebrow">เลิกงาน</label>
                            <input type="time" id="auto_end" value="16:00" class="ts-input" style="padding:7px 12px;">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="ts-label-eyebrow">เวลาย่อยต่อรอบ (นาที)</label>
                            <input type="number" id="auto_duration" value="60" min="5" step="5" class="ts-input" style="padding:7px 12px;">
                        </div>
                        <div>
                            <label class="ts-label-eyebrow">พักเบรก (ถ้ามี)</label>
                            <div style="display:flex; align-items:center; gap:4px; background:var(--ec-surface); border:1px solid var(--ec-border); border-radius:12px; overflow:hidden; padding-right:8px;">
                                <input type="time" id="auto_break_start" value="12:00" style="width:100%; padding:6px 8px; font-size:13px; border:0; background:transparent; outline:none; color:var(--ec-ink-1);">
                                <span style="color:var(--ec-ink-4); font-size:11px;">–</span>
                                <input type="time" id="auto_break_end" value="13:00" style="width:100%; padding:6px 8px; font-size:13px; border:0; background:transparent; outline:none; color:var(--ec-ink-1);">
                            </div>
                        </div>
                    </div>
                    <button type="button" onclick="generateTimeSlots()" style="width:100%; padding:9px 14px; background:var(--ec-brand-100); color:var(--ec-brand-700); font-weight:700; border-radius:10px; transition:background .15s; font-size:13px; border:1px solid var(--ec-brand-200); cursor:pointer;">
                        <i class="fa-solid fa-bolt mr-1"></i> เติมช่วงเวลาด้านล่างอัตโนมัติ
                    </button>
                </div>
            </div>

            <div id="time_slots_container" style="display:flex; flex-direction:column; gap:10px;">
                <div class="time-slot-row" style="display:flex; align-items:flex-end; gap:10px; padding:12px; background:var(--ec-surface-2); border-radius:12px; border:1px solid var(--ec-border-soft); position:relative;">
                    <div class="flex-1">
                        <label class="ts-label-eyebrow">เวลาเริ่ม *</label>
                        <input type="time" name="start_time[]" required class="ts-input" style="padding:8px 12px;">
                    </div>
                    <div class="flex-1">
                        <label class="ts-label-eyebrow">เวลาสิ้นสุด *</label>
                        <input type="time" name="end_time[]" required class="ts-input" style="padding:8px 12px;">
                    </div>
                    <button type="button" onclick="removeTimeSlot(this)" class="remove-time-btn hidden" style="min-width:40px; height:38px; background:var(--ec-surface); border:1px solid var(--ec-border); color:#ef4444; border-radius:10px; cursor:pointer; transition:all .15s;">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
            <button type="button" onclick="addTimeSlot()" style="width:100%; padding:10px 14px; border:1.5px dashed var(--ec-brand-500); color:var(--ec-brand-700); font-weight:700; border-radius:14px; background:transparent; cursor:pointer; transition:background .15s; font-size:13px; display:flex; align-items:center; justify-content:center; gap:8px;">
                <i class="fa-solid fa-plus-circle"></i> เพิ่มช่วงเวลาอีก
            </button>

            <div>
                <label class="ts-label">จำนวนรับรวมต่อวัน (ระบบจะหารเฉลี่ยให้ทุกรอบเวลา) <span style="color:#ef4444;">*</span></label>
                <input type="number" name="max_capacity" value="50" min="1" required class="ts-input">
            </div>

            </div>

            <div class="ts-modal-footer">
                <button type="button" onclick="closeTsModal('slotModal')" class="ts-btn-ghost" style="flex:1;">ยกเลิก</button>
                <button type="submit" class="ts-btn-primary" style="flex:2;"><i class="fa-solid fa-save"></i> บันทึกรอบเวลา</button>
            </div>
        </form>
    </div>
</div>

<div id="editSlotModal" class="ts-modal hidden">
    <div class="ts-modal-box">
        <div class="ts-modal-header amber">
            <h3 class="ts-modal-title">
                <div class="ts-modal-icon"><i class="fa-solid fa-pen-to-square"></i></div>
                แก้ไขข้อมูลรอบเวลา
            </h3>
            <button type="button" onclick="closeTsModal('editSlotModal')" class="ts-modal-close" aria-label="ปิด"><i class="fa-solid fa-times"></i></button>
        </div>
        <form id="editSlotForm" style="display:flex; flex-direction:column; flex:1; overflow:hidden;">
            <input type="hidden" name="action" value="edit_slot">
            <input type="hidden" name="slot_id" id="edit_slot_id">
            <?php csrf_field(); ?>

            <div class="ts-modal-body scrollbar-hide" style="display:flex; flex-direction:column; gap:14px;">

            <div>
                <label class="ts-label">แคมเปญ <span style="color:#ef4444;">*</span></label>
                <div style="position:relative;">
                    <select name="campaign_id" id="edit_campaign_id" required class="ts-select" style="appearance:none; padding-right:36px; background:var(--ec-surface-2); pointer-events:none; color:var(--ec-ink-3);">
                        <?php foreach ($allCampaigns as $ac): ?>
                            <option value="<?= (int)$ac['id'] ?>"><?= htmlspecialchars($ac['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-lock" style="position:absolute; top:50%; right:14px; transform:translateY(-50%); color:var(--ec-ink-4); font-size:11px; pointer-events:none;"></i>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="ts-label">เวลาเริ่ม <span style="color:#ef4444;">*</span></label>
                    <input type="time" name="start_time" id="edit_start_time" required class="ts-input">
                </div>
                <div>
                    <label class="ts-label">เวลาสิ้นสุด <span style="color:#ef4444;">*</span></label>
                    <input type="time" name="end_time" id="edit_end_time" required class="ts-input">
                </div>
            </div>

            <div>
                <label class="ts-label">จำนวนรับ (ที่นั่ง) <span style="color:#ef4444;">*</span></label>
                <input type="number" name="max_capacity" id="edit_max_capacity" min="1" required class="ts-input">
            </div>

            </div>

            <div class="ts-modal-footer">
                <button type="button" onclick="closeTsModal('editSlotModal')" class="ts-btn-ghost" style="flex:1;">ยกเลิก</button>
                <button type="submit" class="ts-btn-amber" style="flex:2;"><i class="fa-solid fa-save"></i> บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>
<!-- Flatpickr for multi-date picker -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/th.js"></script>

<!-- simple-datatables for table view sorting + pagination -->
<link href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css" rel="stylesheet" type="text/css">
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest" type="text/javascript"></script>

<script>
// ── Portal-Escape modal helpers (teleport to <body>) ─────────
function openTsModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.parentElement !== document.body) document.body.appendChild(el);
    el.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeTsModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.add('hidden');
    // restore body scroll if no other modal open
    var openModals = document.querySelectorAll('.ts-modal:not(.hidden)');
    if (openModals.length === 0) document.body.style.overflow = '';
}
// Close any open modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.ts-modal:not(.hidden)').forEach(function(m){ m.classList.add('hidden'); });
    document.body.style.overflow = '';
});
// Click outside ts-modal box to close
document.addEventListener('click', function(e) {
    if (e.target.classList && e.target.classList.contains('ts-modal')) {
        e.target.classList.add('hidden');
        document.body.style.overflow = '';
    }
});
</script>

<script>
let fp;
let tableInst = null;
let initialTableTbodyHTML = ''; // เก็บ HTML ต้นฉบับของตารางไว้สำหรับ filter ใหม่
let globalSelectedSlots = new Set(); // เก็บ ID รายการที่ถูกเลือกไว้เพื่อให้ Sync ตรงกันทุกมุมมอง

document.addEventListener('DOMContentLoaded', function() {
    fp = flatpickr("#modal_selected_dates", {
        mode: "multiple",
        dateFormat: "Y-m-d",
        locale: "th",
    });

    if (document.getElementById("slotsTable")) {
        initialTableTbodyHTML = document.querySelector("#slotsTable tbody").innerHTML;
        tableInst = new simpleDatatables.DataTable("#slotsTable", {
            searchable: true,
            fixedHeight: false,
            perPage: 15,
            labels: {
                placeholder: "ค้นหา...",
                perPage: "รายการต่อหน้า",
                noRows: "ไม่พบข้อมูล",
                info: "แสดง {start} ถึง {end} จาก {rows} รายการ",
            }
        });
        
        // ผูก Event เมื่อมีการเปลี่ยนหน้าผลลัพธ์ เรียงลำดับ หรือค้นหา ให้รีเฟรช Checkbox state
        tableInst.on('datatable.page', syncTableCheckboxes);
        tableInst.on('datatable.sort', syncTableCheckboxes);
        tableInst.on('datatable.search', syncTableCheckboxes);
    }
});

function switchView(view) {
    if (view === 'calendar') {
        document.getElementById('calendarViewContainer').classList.remove('hidden');
        document.getElementById('tableViewContainer').classList.add('hidden');
        
        document.getElementById('btnViewCalendar').className = "px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#2e9e63] transition-all";
        document.getElementById('btnViewTable').className = "px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all";
    } else {
        document.getElementById('calendarViewContainer').classList.add('hidden');
        document.getElementById('tableViewContainer').classList.remove('hidden');
        
        document.getElementById('btnViewTable').className = "px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#2e9e63] transition-all";
        document.getElementById('btnViewCalendar').className = "px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all";
    }
}

// ฟังก์ชันปิด/เปิด Dropdown และ Multi-Select Logic
function toggleMultiSelect(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById('multiSelectDropdown');
    const btn = document.querySelector('#multiSelectContainer button');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        // ย้ายไปยัง <body> เพื่อหนีจาก transformed ancestor
        if (dropdown.parentElement !== document.body) {
            document.body.appendChild(dropdown);
        }
        const rect = btn.getBoundingClientRect();
        const dw   = Math.min(256, window.innerWidth - 8); // dropdown width, ไม่กว้างกว่าจอ
        const gap  = 6; // ระยะขอบขั้นต่ำจากขอบจอ

        // right-align กับขอบขวาของปุ่ม แล้ว clamp ไม่ให้ล้นซ้าย/ขวา
        let left = rect.right - dw;
        if (left < gap) left = gap;
        if (left + dw > window.innerWidth - gap) left = window.innerWidth - dw - gap;

        dropdown.style.width    = dw + 'px';
        dropdown.style.position = 'fixed';
        dropdown.style.zIndex   = '1000';
        dropdown.style.top      = (rect.bottom + 8) + 'px';
        dropdown.style.left     = left + 'px';
        dropdown.style.right    = 'auto';
        dropdown.style.display  = 'flex';
    } else {
        dropdown.style.display = 'none';
    }
}

// ปิด dropdown เมื่อกดคลิกที่อื่น
// ตรวจสอบทั้ง container และ dropdown (dropdown อาจถูกย้ายไปอยู่ใน body แล้ว)
document.addEventListener('click', function(event) {
    const container = document.getElementById('multiSelectContainer');
    const dropdown  = document.getElementById('multiSelectDropdown');
    if (container && !container.contains(event.target) &&
        dropdown  && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

// ค้นหา List แคมเปญ (Text Search)
function searchCampaigns(val) {
    const term = val.toLowerCase().trim();
    const items = document.querySelectorAll('.camp-label-item');
    items.forEach(item => {
        const title = item.getAttribute('data-title');
        if (title.includes(term)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// เช็ค/อันเช็ค ทุกแคมเปญ
function toggleAllCampaigns(el) {
    const isChecked = el.checked;
    const checkboxes = document.querySelectorAll('.camp-checkbox');
    checkboxes.forEach(cb => cb.checked = isChecked);
    updateMultiSelectFilter();
}

// อัปเดตการกรองหลังจากติ๊ก/ไม่ติ๊ก checkbox ใดๆ
function updateMultiSelectFilter() {
    const checkboxes = document.querySelectorAll('.camp-checkbox');
    const selectAllCb = document.getElementById('selectAllCamps');
    
    let checkedIds = [];
    checkboxes.forEach(cb => {
        if (cb.checked) checkedIds.push(cb.value);
    });

    // ตรวจสอบเช็ค 'เลือกทั้งหมด' อัตโนมัติถ้าย่อยถูกเช็คหมด
    selectAllCb.checked = (checkedIds.length === checkboxes.length);

    // อัปเดตข้อความบนปุ่ม Label 
    const label = document.getElementById('multiSelectLabel');
    if (checkedIds.length === checkboxes.length) {
        label.innerText = 'แสดงทุกแคมเปญ';
    } else if (checkedIds.length === 0) {
        label.innerText = 'ไม่ได้เลือกแคมเปญเลย';
    } else {
        label.innerText = `เลือกไว้ (${checkedIds.length}/${checkboxes.length})`;
    }

    // ทำการซ่อน/แสดง .slot-item ในปฏิทินแบบเรียลไทม์
    const slots = document.querySelectorAll('.slot-item');
    slots.forEach(slot => {
        let isMatch = false;
        checkedIds.forEach(cId => {
            if (slot.classList.contains('filter-camp-' + cId)) {
                isMatch = true;
            }
        });
        
        if (isMatch) {
            slot.style.display = 'block';
        } else {
            slot.style.display = 'none';
        }
    });
}

// เมื่อกดปุ่มบวกในปฏิทิน ให้เซ็ตวันที่เข้าไป
function openAddSlotModal(date) {
    if (fp) fp.setDate([date]);
    
    // รีเซ็ตช่วงเวลาให้เหลือแค่อันเดียว คลีนๆ
    const container = document.getElementById('time_slots_container');
    const rows = container.querySelectorAll('.time-slot-row');
    for (let i = 1; i < rows.length; i++) {
        rows[i].remove();
    }
    container.querySelectorAll('input[type="time"]').forEach(input => input.value = '');
    updateRemoveButtons();

    openTsModal('slotModal');
}

// ฟังก์ชันเพิ่มช่วงเวลาใหม่ในฟอร์ม Add Slot แบบ Dynamic
function addTimeSlot() {
    const container = document.getElementById('time_slots_container');
    const firstRow = container.querySelector('.time-slot-row').cloneNode(true);
    firstRow.querySelectorAll('input').forEach(input => input.value = ''); // เคลียร์ค่า input
    container.appendChild(firstRow);
    updateRemoveButtons();
}

// ลบช่วงเวลา
function removeTimeSlot(btn) {
    const container = document.getElementById('time_slots_container');
    if (container.children.length > 1) {
        btn.closest('.time-slot-row').remove();
    }
    updateRemoveButtons();
}

// เปิดปิดซ่อนปุ่มลบ (ถ้ามีแค่อันเดียวไม่ให้ลบ)
function updateRemoveButtons() {
    const container = document.getElementById('time_slots_container');
    const btns = container.querySelectorAll('.remove-time-btn');
    if (container.children.length > 1) {
        btns.forEach(btn => btn.classList.remove('hidden'));
    } else {
        btns.forEach(btn => btn.classList.add('hidden'));
    }
}

// ฟังก์ชันเปิด Modal สำหรับแก้ไข
function openEditSlotModal(slotId, campaignId, startTime, endTime, maxCap) {
    document.getElementById('edit_slot_id').value = slotId;
    document.getElementById('edit_campaign_id').value = campaignId;
    document.getElementById('edit_start_time').value = startTime;
    document.getElementById('edit_end_time').value = endTime;
    document.getElementById('edit_max_capacity').value = maxCap;
    openTsModal('editSlotModal');
}

// Refresh calendar + table in-place without full page reload
async function refreshCalendar() {
    const url = new URL(window.location.href);
    const month = url.searchParams.get('month') || '<?= $month ?>';
    const year  = url.searchParams.get('year')  || '<?= $year ?>';

    const calEl = document.getElementById('calendarViewContainer');
    const tblEl = document.getElementById('tableViewContainer');
    const isTable = tblEl && !tblEl.classList.contains('hidden');

    try {
        const res  = await fetch(`time_slots.php?month=${month}&year=${year}`);
        const html = await res.text();
        const doc  = new DOMParser().parseFromString(html, 'text/html');

        const newCal = doc.getElementById('calendarViewContainer');
        const newTbl = doc.getElementById('tableViewContainer');
        if (newCal) calEl.innerHTML = newCal.innerHTML;
        if (newTbl) tblEl.innerHTML = newTbl.innerHTML;

        // Restore view mode
        if (isTable) {
            calEl.classList.add('hidden');
            tblEl.classList.remove('hidden');
        } else {
            calEl.classList.remove('hidden');
            tblEl.classList.add('hidden');
        }

        globalSelectedSlots.clear();
        updateMultiDeleteBtn();
    } catch (e) {
        console.error('refreshCalendar failed:', e);
    }
}

// ใช้ Fetch API เพื่อบันทึกข้อมูล Add Slot
document.getElementById('slotForm').addEventListener('submit', function(e) {
    e.preventDefault();

    Swal.fire({ title: 'กำลังบันทึกข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

    const formData = new FormData(this);
    fetch('time_slots.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            closeTsModal('slotModal');
            document.getElementById('slotForm').reset();
            Swal.fire({
                title: 'บันทึกสำเร็จ!',
                text: data.message,
                icon: 'success',
                timer: 1800,
                showConfirmButton: false,
                customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
            });
            refreshCalendar();
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    })
    .catch(() => Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error'));
});

// ใช้ Fetch API เพื่อบันทึกข้อมูล Edit Slot
document.getElementById('editSlotForm').addEventListener('submit', function(e) {
    e.preventDefault();
    Swal.fire({ title: 'กำลังแก้ไขข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

    const formData = new FormData(this);
    fetch('time_slots.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            closeTsModal('editSlotModal');
            Swal.fire({
                title: 'แก้ไขสำเร็จ!',
                text: data.message,
                icon: 'success',
                timer: 1800,
                showConfirmButton: false,
                customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
            });
            refreshCalendar();
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    }).catch(() => Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error'));
});

function bulkCancelSlot(slotId, campaign, date, time, count) {
    Swal.fire({
        icon: 'warning',
        title: 'ยกเลิกการจองทั้งหมด?',
        html: `<p class="font-prompt text-sm text-gray-700">${campaign}</p>
               <p class="font-prompt text-sm text-gray-600">${date} เวลา ${time}</p>
               <p class="font-prompt text-base font-bold text-red-600 mt-3">จะยกเลิก ${count} รายการ</p>
               <div class="mt-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                 <p class="font-prompt text-sm font-semibold text-orange-700">📧 ระบบจะส่งอีเมลแจ้งเตือนให้กับผู้ใช้ทั้งหมดที่ถูกยกเลิก</p>
               </div>`,
        confirmButtonColor: '#0284c7',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ยกเลิกเลย',
        cancelButtonText: 'ยกเลิก',
        customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt', cancelButton:'font-prompt' }
    }).then(r => {
        if (!r.isConfirmed) return;

        Swal.fire({
            title: 'กำลังดำเนินการ...',
            text: 'ระบบกำลังส่งอีเมลแจ้งเตือนผู้จองทุกคน กรุณารอสักครู่ (ห้ามปิดหน้านี้)',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const fd = new FormData();
        fd.append('slot_id', slotId);
        fd.append('campaign', campaign);
        fd.append('csrf_token', '<?= get_csrf_token() ?>');

        fetch('ajax/ajax_bulk_cancel_bookings.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                Swal.fire({
                    icon: 'success',
                    title: 'ยกเลิกเรียบร้อย',
                    html: `<p class="font-prompt">${data.message}</p>
                           ${data.failed_count > 0 ? '<p class="font-prompt text-sm text-orange-600 mt-2">⚠️ ' + data.failed_count + ' รายการล้มเหลว</p>' : ''}`,
                    timer: 2000,
                    showConfirmButton: false,
                    customClass: { title:'font-prompt', htmlContainer:'font-prompt' }
                });
                refreshCalendar();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'ยกเลิกไม่ได้',
                    text: data.error,
                    confirmButtonColor: '#ef4444',
                    customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt' }
                });
            }
        })
        .catch(err => {
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์',
                confirmButtonColor: '#ef4444',
                customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt' }
            });
        });
    });
}

function deleteSlot(id) {
    Swal.fire({
        title: 'ยืนยันการลบรอบเวลา?',
        text: "คุณต้องการลบรอบเวลานี้ใช่หรือไม่?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#f1f5f9',
        confirmButtonText: '<i class="fa-solid fa-trash-can"></i> ลบข้อมูลเป้าหมาย',
        cancelButtonText: '<span class="text-gray-600 font-bold">ยกเลิก</span>',
        customClass: { title: 'font-prompt font-bold text-xl', popup: 'font-prompt rounded-3xl', confirmButton: 'rounded-xl shadow-lg shadow-red-500/30 font-bold', cancelButton: 'rounded-xl text-gray-700 font-bold' }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_slot');
            formData.append('slot_id', id);
            formData.append('csrf_token', '<?= get_csrf_token() ?>');
            
            fetch('time_slots.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        title: 'ลบสำเร็จ!',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false,
                        customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
                    });
                    refreshCalendar();
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            });
        }
    });
}

// ==========================================
// ส่วนของการเลือกลบหลายรายการ (Multiple Delete)
// ==========================================

// เมื่อหน้าเว็บโหลด ให้เคลียร์ checkbox ทุกอัน ก่อนเริ่ม
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.slot-select-cb').forEach(cb => cb.checked = false);
    document.querySelectorAll('.day-select-cb').forEach(cb => cb.checked = false);
    globalSelectedSlots.clear();
});

// ใช้สำหรับซิงค์ตัว Checkbox ของ Table เวลาเปลี่ยนหน้า (Datatable redraws)
function syncTableCheckboxes() {
    document.querySelectorAll('.table-slot-cb').forEach(cb => {
        cb.checked = globalSelectedSlots.has(cb.value);
    });
    
    // อัปเดต state ของช่อง select All บนหัวตาราง
    let allVisible = document.querySelectorAll('.table-slot-cb');
    let allChecked = document.querySelectorAll('.table-slot-cb:checked');
    let masterCb = document.getElementById('selectAllTable');
    if (masterCb) {
        masterCb.checked = (allVisible.length > 0 && allVisible.length === allChecked.length);
    }
}

// เลือกลบจากช่องย่อย (แชร์ทั้งบนปฏิทินและบนตาราง)
function toggleSlotSelection(cb) {
    const val = cb.value;
    if (cb.checked) {
        globalSelectedSlots.add(val);
    } else {
        globalSelectedSlots.delete(val);
    }
    
    // อัปเดตติ๊กถูกในหน้าจอให้อยู่ใน state เดียวกัน
    document.querySelectorAll(`.slot-select-cb[value="${val}"]`).forEach(el => {
        el.checked = cb.checked;
    });

    updateMultiDeleteBtn();
}

// เลือกลบทั้งหมดในตาราง (หน้าที่กำลังแสดงผลอยู่)
function toggleAllTableSlots(masterCb) {
    const isChecked = masterCb.checked;
    document.querySelectorAll('.table-slot-cb').forEach(cb => {
        cb.checked = isChecked;
        if (isChecked) globalSelectedSlots.add(cb.value);
        else globalSelectedSlots.delete(cb.value);
        
        // ควบคุมตัวปฏิทินให้ติ๊กตาม
        document.querySelectorAll(`.calendar-slot-cb[value="${cb.value}"]`).forEach(el => {
            el.checked = isChecked;
        });
    });
    updateMultiDeleteBtn();
}

// เลือกลบทั้งวัน (จากมุมมองปฏิทิน)
function toggleDaySlots(dayCheckbox) {
    const dayContainer = dayCheckbox.closest('.cal-cell');
    const slotsCb = dayContainer.querySelectorAll('.calendar-slot-cb');
    const isChecked = dayCheckbox.checked;

    slotsCb.forEach(slotCb => {
        // ตรวจสอบว่า slot นี้นั้นแสดงอยู่หรือไม่
        const slotItem = slotCb.closest('.slot-item');
        if (slotItem && slotItem.style.display !== 'none') {
            slotCb.checked = isChecked;
            if (isChecked) globalSelectedSlots.add(slotCb.value);
            else globalSelectedSlots.delete(slotCb.value);
            
            // ให้ table check ตาม
            document.querySelectorAll(`.table-slot-cb[value="${slotCb.value}"]`).forEach(el => {
                el.checked = isChecked;
            });
        }
    });

    updateMultiDeleteBtn();
}

// อัปเดตสถานะการแสดงของปุ่ม ลบหลายรายการ
function updateMultiDeleteBtn() {
    const count = globalSelectedSlots.size;
    const btn = document.getElementById('deleteMultiBtn');
    const countSpan = document.getElementById('selectedSlotCount');
    
    if (count > 0) {
        btn.style.display = 'flex';
        countSpan.textContent = count;
    } else {
        btn.style.display = 'none';
        // Uncheck all master checkboxes just in case
        document.querySelectorAll('.day-select-cb:checked').forEach(cb => cb.checked = false);
        let masterCb = document.getElementById('selectAllTable');
        if(masterCb) masterCb.checked = false;
    }
    syncTableCheckboxes();
}

// ฟังก์ชันสำหรับ สร้างช่วงเวลาอัตโนมัติ (Auto Generate)
function parseTimeStr(t) {
    if(!t) return null;
    let [h, m] = t.split(':');
    let d = new Date();
    d.setHours(parseInt(h), parseInt(m), 0, 0);
    return d;
}

function generateTimeSlots() {
    const startStr = document.getElementById('auto_start').value;
    const endStr = document.getElementById('auto_end').value;
    const duration = parseInt(document.getElementById('auto_duration').value);
    
    const breakStartStr = document.getElementById('auto_break_start').value;
    const breakEndStr = document.getElementById('auto_break_end').value;

    if (!startStr || !endStr || !duration || duration <= 0) {
        Swal.fire('ข้อมูลไม่ครบ', 'กรุณากรอกเวลาเริ่ม, เวลาสิ้นสุด และระยะเวลาให้ครบถ้วน', 'warning');
        return;
    }

    let startObj = parseTimeStr(startStr);
    let endObj = parseTimeStr(endStr);
    let breakStartObj = parseTimeStr(breakStartStr);
    let breakEndObj = parseTimeStr(breakEndStr);

    if (endObj <= startObj) {
        Swal.fire('ข้อผิดพลาด', 'เวลาสิ้นสุดต้องมากกว่าเวลาเริ่ม', 'error');
        return;
    }

    const slots = [];
    let current = startObj;

    while (current < endObj) {
        let slotEnd = new Date(current.getTime() + duration * 60000);
        
        if (slotEnd > endObj) {
            break; 
        }

        if (breakStartObj && breakEndObj) {
            // ถ้ารอบนี้คาบเกี่ยวหรือเริ่มในเวลาพักเบรก ให้ข้ามไปเริ่มหลังเบรก
            if (current < breakEndObj && slotEnd > breakStartObj) {
                current = new Date(breakEndObj.getTime());
                continue;
            }
        }

        let stH = current.getHours().toString().padStart(2, '0');
        let stM = current.getMinutes().toString().padStart(2, '0');
        let etH = slotEnd.getHours().toString().padStart(2, '0');
        let etM = slotEnd.getMinutes().toString().padStart(2, '0');

        slots.push({ st: `${stH}:${stM}`, et: `${etH}:${etM}` });
        current = slotEnd;
    }

    if (slots.length === 0) {
        Swal.fire('เกิดข้อผิดพลาด', 'การตั้งค่าทำให้ไม่สามารถสร้างช่วงเวลาได้เลย', 'warning');
        return;
    }

    const container = document.getElementById('time_slots_container');
    container.innerHTML = ''; // ลบของเดิมทิ้ง

    slots.forEach(slot => {
        const row = document.createElement('div');
        row.className = "time-slot-row";
        row.style.cssText = "display:flex; align-items:flex-end; gap:10px; padding:12px; background:var(--ec-surface-2); border-radius:12px; border:1px solid var(--ec-border-soft); position:relative;";
        row.innerHTML = `
            <div class="flex-1">
                <label class="ts-label-eyebrow">เวลาเริ่ม *</label>
                <input type="time" name="start_time[]" value="${slot.st}" required class="ts-input" style="padding:8px 12px;">
            </div>
            <div class="flex-1">
                <label class="ts-label-eyebrow">เวลาสิ้นสุด *</label>
                <input type="time" name="end_time[]" value="${slot.et}" required class="ts-input" style="padding:8px 12px;">
            </div>
            <button type="button" onclick="removeTimeSlot(this)" class="remove-time-btn" style="min-width:40px; height:38px; background:var(--ec-surface); border:1px solid var(--ec-border); color:#ef4444; border-radius:10px; cursor:pointer; transition:all .15s;">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;
        container.appendChild(row);
    });

    updateRemoveButtons();
    // ปิดแท็บ auto gen พร้อมแจ้งเตือน
    document.getElementById('autoGenBody').classList.add('hidden');
    document.getElementById('autoGenIcon').classList.replace('fa-chevron-up', 'fa-chevron-down');
    
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: `สร้างเปรียบจำนวน ${slots.length} รอบเวลา เรียบร้อย`,
        showConfirmButton: false,
        timer: 2000,
        customClass: { title: 'font-prompt text-sm' }
    });
}

// อัปเดตเมื่อมีการใช้ filter ย่อย (Multi-select filter) บนหัวเว็บ
const originalUpdateMultiSelectFilter = updateMultiSelectFilter;
updateMultiSelectFilter = function() {
    originalUpdateMultiSelectFilter();
    
    // ดึง ID ของแคมเปญทั้งหมดที่โดนติ๊กเลือกไว้จาก dropdown filter
    const checkedIds = Array.from(document.querySelectorAll('.camp-checkbox:checked')).map(cb => cb.value);

    // ยกเลิกการเลือกอันที่ถูกซ่อนไปในหน้าปฏิทิน
    const slotsCalendar = document.querySelectorAll('.slot-item');
    let hasHiddenChecked = false;
    slotsCalendar.forEach(slot => {
        if (slot.style.display === 'none') {
            const cb = slot.querySelector('.calendar-slot-cb');
            if(cb && globalSelectedSlots.has(cb.value)) {
                globalSelectedSlots.delete(cb.value);
                cb.checked = false;
                hasHiddenChecked = true;
            }
        }
    });

    // ส่วนของตาราง: ทำลาย, กรองโครงสร้างใหม่ และสร้าง Datatable กลับขึ้นมา
    if (tableInst) {
        tableInst.destroy();
        
        let tbody = document.querySelector('#slotsTable tbody');
        if (tbody) {
            tbody.innerHTML = initialTableTbodyHTML;
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                let rowCampId = row.getAttribute('data-camp-id');
                // ถ้า row นี้ไม่ได้อยู่ใน filter ให้ลบแถวออกจาก DOM ชั่วคราว
                if (rowCampId && !checkedIds.includes(rowCampId)) {
                    // แต่ถ้าตารางนี้ดันโดนเลือกลบอยู่ เราต้องเคลียร์ state ทิ้งด้วย
                    const input = row.querySelector('.table-slot-cb');
                    if (input && globalSelectedSlots.has(input.value)) {
                        globalSelectedSlots.delete(input.value);
                        hasHiddenChecked = true;
                    }
                    row.remove();
                }
            });
            
            tableInst = new simpleDatatables.DataTable("#slotsTable", {
                searchable: true,
                fixedHeight: false,
                perPage: 15,
                labels: {
                    placeholder: "ค้นหา...",
                    perPage: "รายการต่อหน้า",
                    noRows: "ไม่พบข้อมูล",
                    info: "แสดง {start} ถึง {end} จาก {rows} รายการ",
                }
            });
            
            tableInst.on('datatable.page', syncTableCheckboxes);
            tableInst.on('datatable.sort', syncTableCheckboxes);
            tableInst.on('datatable.search', syncTableCheckboxes);
        }
    }

    if(hasHiddenChecked) {
        updateMultiDeleteBtn();
    } else {
        syncTableCheckboxes();
    }
}

function deleteSelectedSlots() {
    if (globalSelectedSlots.size === 0) return;

    let ids = Array.from(globalSelectedSlots);

    Swal.fire({
        title: 'ยืนยันการลบแบบกลุ่ม?',
        text: `คุณกำลังจะลบรอบเวลาจำนวน ${ids.length} รายการ (ระบบจะข้ามรายการที่มีคนลงทะเบียนแล้ว)`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#f1f5f9',
        confirmButtonText: '<i class="fa-solid fa-trash-can"></i> ลบทั้งหมด!',
        cancelButtonText: '<span class="text-gray-600 font-bold">ยกเลิก</span>',
        customClass: { title: 'font-prompt font-bold text-xl text-red-600', popup: 'font-prompt rounded-3xl', confirmButton: 'rounded-xl shadow-lg shadow-red-500/30 font-bold', cancelButton: 'rounded-xl font-bold' }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'กำลังลบข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

            const formData = new FormData();
            formData.append('action', 'delete_multiple_slots');
            ids.forEach(id => formData.append('slot_ids[]', id));
            formData.append('csrf_token', '<?= get_csrf_token() ?>');
            
            fetch('time_slots.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        title: 'ดำเนินการเสร็จสิ้น',
                        text: data.message,
                        icon: 'success',
                        timer: 1800,
                        showConfirmButton: false,
                        customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
                    });
                    refreshCalendar();
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
        }
    });
}
</script>

<!-- ========================================================
     DAILY SLOTS MODAL
     ======================================================== -->
<div id="dailyModal" class="ts-modal hidden">
    <div class="ts-modal-box lg">
        <div class="ts-modal-header brand">
            <h3 class="ts-modal-title">
                <div class="ts-modal-icon"><i class="fa-solid fa-calendar-day"></i></div>
                <div>
                    <div id="dailyModalTitle" style="line-height:1.1;">รอบเวลาประจำวัน</div>
                    <p id="dailyModalSub" style="font-size:11px; font-weight:500; opacity:.85; margin:2px 0 0;"></p>
                </div>
            </h3>
            <button onclick="closeDailyModal()" class="ts-modal-close" aria-label="ปิด">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
        <div id="dailyModalBody" class="ts-modal-body">
            <div style="display:flex; align-items:center; justify-content:center; padding:48px 0; color:var(--ec-ink-3);">
                <i class="fa-solid fa-spinner fa-spin text-2xl mr-3" style="color:var(--ec-brand-500);"></i>
                <span>กำลังโหลด...</span>
            </div>
        </div>
    </div>
</div>

<script>
let _dailyDate = '';

function openDailyModal(date) {
    _dailyDate = date;
    openTsModal('dailyModal');

    // Format date for display
    const d = new Date(date + 'T00:00:00');
    const opts = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
    document.getElementById('dailyModalTitle').textContent = 'รอบเวลาประจำวัน';
    document.getElementById('dailyModalSub').textContent   = d.toLocaleDateString('th-TH', opts);

    loadDailySlots(date);
}

function closeDailyModal() {
    closeTsModal('dailyModal');
}

function loadDailySlots(date) {
    document.getElementById('dailyModalBody').innerHTML = `
        <div style="display:flex; align-items:center; justify-content:center; padding:48px 0; color:var(--ec-ink-3);">
            <i class="fa-solid fa-spinner fa-spin text-2xl mr-3" style="color:var(--ec-brand-500);"></i>
            <span>กำลังโหลด...</span>
        </div>`;

    const fd = new FormData();
    fd.append('action', 'get');
    fd.append('date', date);
    fd.append('csrf_token', '<?= get_csrf_token() ?>');

    fetch('ajax/ajax_get_daily_slots.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success') {
            document.getElementById('dailyModalBody').innerHTML =
                `<p style="text-align:center; color:#ef4444; padding:32px 0;">${escHtml(data.message || 'ไม่สามารถโหลดข้อมูลได้')}</p>`;
            return;
        }
        renderDailySlots(data.slots, date);
    })
    .catch(() => {
        document.getElementById('dailyModalBody').innerHTML =
            '<p style="text-align:center; color:#ef4444; padding:32px 0;">ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้</p>';
    });
}

function renderDailySlots(slots, date) {
    if (!slots.length) {
        document.getElementById('dailyModalBody').innerHTML = `
            <div class="ts-empty" style="background:transparent; border:0; padding:32px 16px;">
                <div class="ts-empty-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                <h3>ไม่มีรอบเวลาในวันนี้</h3>
                <p style="margin-bottom:16px;">สร้างรอบเวลาแรกของวันนี้เพื่อเปิดรับจอง</p>
                <button onclick="openAddSlotModal('${date}'); closeDailyModal();" class="ts-cta" style="margin-top:14px;">
                    <i class="fa-solid fa-plus"></i> สร้างรอบเวลา
                </button>
            </div>`;
        return;
    }

    let rows = slots.map(s => {
        const pct      = s.max_capacity > 0 ? (s.booked_count / s.max_capacity) * 100 : 0;
        const toneCls  = pct >= 100 ? 'tone-full' : pct >= 80 ? 'tone-near' : 'tone-ok';
        const barClr   = pct >= 100 ? '#ef4444' : pct >= 80 ? '#f59e0b' : '#22c55e';

        return `
        <tr id="drow-${s.id}" style="border-bottom:1px solid var(--ec-border-soft); transition: background .12s;">
            <td style="padding:12px 16px;">
                <span style="font-weight:600; color:var(--ec-ink-1); font-size:13px;">${escHtml(s.campaign_title)}</span>
            </td>
            <td style="padding:12px 16px; white-space:nowrap;">
                <span style="font-weight:800; color:var(--ec-brand-700); background:var(--ec-brand-50); padding:4px 10px; border-radius:9px; font-size:12px; display:inline-block;">
                    ${s.start_time.slice(0,5)} – ${s.end_time.slice(0,5)}
                </span>
            </td>
            <td style="padding:12px 16px; white-space:nowrap;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="stat-badge ${toneCls}" style="white-space:nowrap;">
                        ${s.booked_count} / ${s.max_capacity}
                    </span>
                    <div style="width:60px; min-width:60px; height:4px; background:var(--ec-border); border-radius:99px; overflow:hidden;">
                        <div style="width:${Math.min(pct,100)}%; height:100%; background:${barClr}; border-radius:99px;"></div>
                    </div>
                </div>
            </td>
            <td style="padding:12px 16px; white-space:nowrap;">
                <div style="display:flex; gap:6px; justify-content:flex-end;">
                    <button onclick="dailyEditRow(${s.id},'${s.start_time.slice(0,5)}','${s.end_time.slice(0,5)}',${s.max_capacity})" class="ts-row-btn edit" title="แก้ไข">
                        <i class="fa-solid fa-pen text-xs"></i>
                    </button>
                    <button onclick="dailyDeleteSlot(${s.id},'${date}')" class="ts-row-btn del" title="ลบ">
                        <i class="fa-solid fa-trash text-xs"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    document.getElementById('dailyModalBody').innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <p style="font-size:13px; color:var(--ec-ink-3); margin:0;">พบ <b style="color:var(--ec-ink-1);">${slots.length}</b> รอบเวลา</p>
            <button onclick="openAddSlotModal('${date}'); closeDailyModal();" class="ts-cta" style="padding:7px 14px; font-size:12px;">
                <i class="fa-solid fa-plus"></i> สร้างรอบเวลา
            </button>
        </div>
        <div style="overflow-x:auto; border-radius:14px; border:1px solid var(--ec-border-soft);">
            <table style="width:100%; border-collapse:separate; border-spacing:0;">
                <thead>
                    <tr style="background:linear-gradient(135deg,#2e9e63,#10b981);">
                        <th style="padding:12px 16px; font-size:11px; font-weight:700; color:rgba(255,255,255,.92); text-transform:uppercase; letter-spacing:.06em; text-align:left;">แคมเปญ</th>
                        <th style="padding:12px 16px; font-size:11px; font-weight:700; color:rgba(255,255,255,.92); text-transform:uppercase; letter-spacing:.06em; text-align:left; white-space:nowrap; width:140px;">เวลา</th>
                        <th style="padding:12px 16px; font-size:11px; font-weight:700; color:rgba(255,255,255,.92); text-transform:uppercase; letter-spacing:.06em; text-align:left; white-space:nowrap; width:170px;">ยอดจอง</th>
                        <th style="padding:12px 16px; font-size:11px; font-weight:700; color:rgba(255,255,255,.92); text-transform:uppercase; letter-spacing:.06em; text-align:right; white-space:nowrap; width:90px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody style="background:var(--ec-surface);">${rows}</tbody>
            </table>
        </div>`;
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ---- Inline edit row ------------------------------------------------
function dailyEditRow(id, start, end, cap) {
    const row = document.getElementById('drow-' + id);
    if (!row) return;
    row.innerHTML = `
        <td style="padding:10px 16px;" colspan="2">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="time" id="de_start_${id}" value="${start}" class="ts-input" style="padding:6px 10px; width:auto;">
                <span style="color:var(--ec-ink-4); font-size:13px;">–</span>
                <input type="time" id="de_end_${id}" value="${end}" class="ts-input" style="padding:6px 10px; width:auto;">
                <input type="number" id="de_cap_${id}" value="${cap}" min="1" class="ts-input" style="padding:6px 10px; width:80px;" placeholder="ที่นั่ง">
            </div>
        </td>
        <td style="padding:10px 16px;" colspan="2">
            <div style="display:flex; gap:8px; justify-content:flex-end;">
                <button onclick="dailySaveEdit(${id})" style="padding:6px 12px; background:linear-gradient(135deg,#2e9e63,#34d399); color:#fff; border-radius:9px; font-size:12px; font-weight:700; border:0; cursor:pointer;">
                    <i class="fa-solid fa-save mr-1"></i>บันทึก
                </button>
                <button onclick="loadDailySlots('${_dailyDate}')" style="padding:6px 12px; background:var(--ec-surface-2); color:var(--ec-ink-2); border-radius:9px; font-size:12px; font-weight:700; border:1px solid var(--ec-border); cursor:pointer;">
                    ยกเลิก
                </button>
            </div>
        </td>`;
}

function dailySaveEdit(id) {
    const start = document.getElementById('de_start_' + id)?.value;
    const end   = document.getElementById('de_end_'   + id)?.value;
    const cap   = document.getElementById('de_cap_'   + id)?.value;

    if (!start || !end || !cap) {
        Swal.fire({ icon:'warning', title:'กรอกข้อมูลให้ครบ', confirmButtonColor:'#2e9e63', customClass:{title:'font-prompt'} });
        return;
    }

    const fd = new FormData();
    fd.append('action', 'edit');
    fd.append('slot_id', id);
    fd.append('start_time', start);
    fd.append('end_time', end);
    fd.append('max_capacity', cap);
    fd.append('csrf_token', '<?= get_csrf_token() ?>');

    fetch('ajax/ajax_get_daily_slots.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            loadDailySlots(_dailyDate);
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text:data.message, confirmButtonColor:'#ef4444', customClass:{title:'font-prompt',htmlContainer:'font-prompt'} });
        }
    });
}

function dailyDeleteSlot(id, date) {
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการลบ?',
        text: 'รอบเวลานี้จะถูกลบถาวร (เฉพาะรอบที่ไม่มีผู้จอง)',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ลบเลย',
        cancelButtonText: 'ยกเลิก',
        customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt', cancelButton:'font-prompt' }
    }).then(r => {
        if (!r.isConfirmed) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('slot_id', id);
        fd.append('csrf_token', '<?= get_csrf_token() ?>');

        fetch('ajax/ajax_get_daily_slots.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                loadDailySlots(date);
            } else {
                Swal.fire({ icon:'error', title:'ลบไม่ได้', text:data.message, confirmButtonColor:'#ef4444', customClass:{title:'font-prompt',htmlContainer:'font-prompt'} });
            }
        });
    });
}

// ══════════════════════════════════════════════════════════
// QR CHECK-IN SYSTEM
// ══════════════════════════════════════════════════════════
const QR_ENABLED_MAP = <?= json_encode($qrEnabledMap) ?>;
const CSRF_QR = '<?= get_csrf_token() ?>';

function showQrModal(slotId, campaignId) {
    const qrEnabled = !!QR_ENABLED_MAP[campaignId];
    document.getElementById('qrImg').src = `../user/api_slot_qr.php?slot=${slotId}`;
    document.getElementById('qrCampaignId').value = campaignId;
    setQrToggleUI(document.getElementById('qrToggleBtn'), qrEnabled);

    // โหลด check-in URL
    const copyInput = document.getElementById('qrCopyUrl');
    copyInput.value = 'กำลังโหลด...';
    fetch(`ajax/ajax_get_slot_checkin_url.php?slot=${slotId}`)
        .then(r => r.json())
        .then(d => { copyInput.value = d.url || ''; })
        .catch(() => { copyInput.value = ''; });

    openTsModal('qrModal');
}

function setQrToggleUI(btn, enabled) {
    if (enabled) {
        btn.innerHTML = '<i class="fa-solid fa-toggle-on text-lg"></i> QR เปิดใช้งาน';
        btn.style.cssText = 'background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;';
    } else {
        btn.innerHTML = '<i class="fa-solid fa-toggle-off text-lg"></i> QR ปิดอยู่';
        btn.style.cssText = 'background:var(--ec-surface-2);color:var(--ec-ink-3);border:1px solid var(--ec-border);';
    }
}

function toggleCampaignQr() {
    const campaignId = parseInt(document.getElementById('qrCampaignId').value);
    const btn = document.getElementById('qrToggleBtn');
    fetch('ajax/ajax_toggle_campaign_qr.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `campaign_id=${campaignId}&csrf_token=${CSRF_QR}`,
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            QR_ENABLED_MAP[campaignId] = d.qr_enabled;
            setQrToggleUI(btn, !!d.qr_enabled);
            Swal.fire({ icon: d.qr_enabled ? 'success' : 'info',
                title: d.message, timer: 1600, showConfirmButton: false,
                customClass: { popup: 'font-prompt rounded-2xl' } });
        } else {
            Swal.fire({ icon: 'error', title: d.message || 'เกิดข้อผิดพลาด',
                customClass: { popup: 'font-prompt rounded-2xl' } });
        }
    });
}

function copyCheckinUrl() {
    const val = document.getElementById('qrCopyUrl').value;
    if (!val || val === 'กำลังโหลด...') return;
    navigator.clipboard.writeText(val).then(() => {
        const btn = document.getElementById('qrCopyBtn');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check" style="color:#16a34a"></i>';
        setTimeout(() => { btn.innerHTML = orig; }, 1500);
    });
}

function printQr() {
    const imgSrc = document.getElementById('qrImg').src;
    const w = window.open('', '_blank', 'width=400,height=520');
    w.document.write(`<!DOCTYPE html><html><body style="text-align:center;padding:24px;font-family:sans-serif">
        <h2 style="margin-bottom:6px;font-size:20px">QR Check-in</h2>
        <p style="color:#666;font-size:13px;margin-bottom:16px">สแกนเพื่อเช็คอินกิจกรรม</p>
        <img src="${imgSrc}" style="width:280px;height:280px;display:block;margin:0 auto">
        <p style="margin-top:16px;font-size:11px;color:#aaa">RSU Medical Clinic</p>
        <script>window.onload=()=>{ window.focus(); window.print(); }<\/script>
    </body></html>`);
    w.document.close();
}
</script>

<!-- ── QR Modal ──────────────────────────────────────────────────── -->
<div id="qrModal" class="ts-modal hidden">
  <div class="ts-modal-box" style="max-width:400px;">
    <div class="ts-modal-header brand-soft">
      <h3 class="ts-modal-title">
        <div class="ts-modal-icon"><i class="fa-solid fa-qrcode text-sm"></i></div>
        QR Check-in
      </h3>
      <button onclick="closeTsModal('qrModal')" class="ts-modal-close" aria-label="ปิด">
        <i class="fa-solid fa-xmark text-xs"></i>
      </button>
    </div>

    <div class="ts-modal-body" style="display:flex; flex-direction:column; align-items:center; gap:14px; padding:24px;">
      <div style="padding:12px; background:#fff; border-radius:16px; box-shadow:inset 0 1px 3px rgba(0,0,0,.06); border:1px solid var(--ec-border-soft);">
        <img id="qrImg" src="" alt="QR Code" style="width:208px; height:208px; object-fit:contain; display:block;" onerror="this.alt='โหลด QR ไม่ได้'">
      </div>

      <input type="hidden" id="qrCampaignId" value="0">
      <button id="qrToggleBtn" onclick="toggleCampaignQr()" style="width:100%; display:flex; align-items:center; justify-content:center; gap:8px; padding:10px 16px; border-radius:12px; font-weight:700; font-size:13px; border:1px solid var(--ec-border); cursor:pointer; transition:all .15s;"></button>

      <div style="width:100%; display:flex; align-items:center; gap:8px;">
        <input id="qrCopyUrl" type="text" readonly class="ts-input" style="padding:8px 12px; font-size:11px; font-family:monospace; color:var(--ec-ink-3);" placeholder="กำลังโหลด URL...">
        <button id="qrCopyBtn" onclick="copyCheckinUrl()" style="width:36px; height:36px; background:var(--ec-surface-2); border:1px solid var(--ec-border); border-radius:11px; color:var(--ec-ink-2); cursor:pointer; flex-shrink:0; transition:all .15s;" title="คัดลอก URL">
          <i class="fa-solid fa-copy text-xs"></i>
        </button>
      </div>

      <button onclick="printQr()" class="ts-btn-ghost" style="width:100%; padding:10px 16px;">
        <i class="fa-solid fa-print"></i> พิมพ์ QR Code
      </button>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
