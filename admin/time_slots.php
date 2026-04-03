<?php
// admin/time_slots.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

$activeCampaigns = $pdo->query("SELECT id, title FROM camp_list WHERE status = 'active' ORDER BY title ASC")->fetchAll();
$allCampaigns    = $pdo->query("SELECT id, title FROM camp_list ORDER BY title ASC")->fetchAll();

$colors = [
    ['bg' => 'bg-blue-50', 'border' => 'border-blue-100', 'text' => 'text-blue-700', 'badge' => 'text-blue-500'],
    ['bg' => 'bg-green-50', 'border' => 'border-green-100', 'text' => 'text-green-700', 'badge' => 'text-green-500'],
    ['bg' => 'bg-purple-50', 'border' => 'border-purple-100', 'text' => 'text-purple-700', 'badge' => 'text-purple-500'],
    ['bg' => 'bg-orange-50', 'border' => 'border-orange-100', 'text' => 'text-orange-700', 'badge' => 'text-orange-500'],
    ['bg' => 'bg-red-50', 'border' => 'border-red-100', 'text' => 'text-red-700', 'badge' => 'text-red-500'],
    ['bg' => 'bg-teal-50', 'border' => 'border-teal-100', 'text' => 'text-teal-700', 'badge' => 'text-teal-500'],
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
    
    // 🌟 ระบบเพิ่มรอบเวลาแบบหลายๆ วัน (Multi-Select Dates) และหลายๆ ช่วงเวลา
    if ($action === 'add_slot') {
        $campaign_id = (int)$_POST['campaign_id'];
        $selected_dates = $_POST['selected_dates'] ?? '';
        $start_times = $_POST['start_time'] ?? []; // Array
        $end_times = $_POST['end_time'] ?? [];     // Array
        $max = (int)$_POST['max_capacity'];

        if ($campaign_id > 0 && !empty($selected_dates) && !empty($start_times) && !empty($end_times) && $max >= 0) {
            
            $dates_array = explode(',', $selected_dates);
            $insertedCount = 0;
            
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
                                $capacity_for_this_slot = $base_capacity + ($remainder > 0 ? 1 : 0);
                                $remainder--;
                                
                                $stmt->execute([$campaign_id, $date, $st, $et, $capacity_for_this_slot]);
                                $insertedCount++;
                            }
                        }
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => "เพิ่มข้อมูลเรียบร้อยแล้วทั้งหมด {$insertedCount} รอบเวลา"]);
            exit;
        }
    }

    // 🌟 ระบบแก้ไขรอบเวลา
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

            echo json_encode(['status' => 'success', 'message' => 'แก้ไขรอบเวลาเรียบร้อยแล้ว']);
            exit;
        }
    }

    if ($action === 'delete_slot') {
        $id = (int)$_POST['slot_id'];
        // เช็คว่ามีคนจองรอบนี้หรือยัง
        $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = ? AND status != 'cancelled'");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถลบได้ เนื่องจากมีคนลงทะเบียนในรอบนี้แล้ว']);
        } else {
            $pdo->prepare("DELETE FROM camp_slots WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success']);
        }
        exit;
    }

    if ($action === 'delete_multiple_slots') {
        $ids = $_POST['slot_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่มีข้อมูลที่เลือก']);
            exit;
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            
            $check = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = ? AND status != 'cancelled'");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                $failedCount++;
            } else {
                $pdo->prepare("DELETE FROM camp_slots WHERE id = ?")->execute([$id]);
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $msg = "ลบสำเร็จ {$deletedCount} รายการ";
            if ($failedCount > 0) {
                $msg .= "\n(ข้าม {$failedCount} รายการที่มีผู้ลงทะเบียนแล้ว)";
            }
            echo json_encode(['status' => 'success', 'message' => $msg]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "ไม่สามารถลบได้ (มีผู้ลงทะเบียนในรอบที่เลือกหมดแล้ว)"]);
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
<button id="deleteMultiBtn" onclick="deleteSelectedSlots()" style="display: none;" class="bg-red-500 text-white px-4 py-2 rounded-xl font-prompt text-sm font-bold shadow-sm hover:bg-red-600 transition-colors items-center gap-2">
    <i class="fa-solid fa-trash-can"></i> ลบที่เลือก (<span id="selectedSlotCount">0</span>)
</button>
<div class="relative" id="multiSelectContainer">
    <button type="button" onclick="toggleMultiSelect(event)" class="px-4 py-2 border border-gray-200 rounded-xl bg-white font-prompt text-sm shadow-sm hover:bg-gray-50 text-gray-700 flex items-center justify-between w-56 transition-colors gap-2">
        <span id="multiSelectLabel" class="truncate font-semibold text-[#0052CC]">แสดงทุกแคมเปญ</span>
        <i class="fa-solid fa-chevron-down text-[10px] text-gray-400"></i>
    </button>
    <div id="multiSelectDropdown" class="w-64 bg-white rounded-xl shadow-xl border border-gray-100 flex-col overflow-hidden" style="display:none;position:fixed;z-index:9999" onclick="event.stopPropagation()">
        <div class="p-2 border-b border-gray-100 bg-gray-50">
            <div class="relative">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                <input type="text" id="multiSelectSearch" onkeyup="searchCampaigns(this.value)" placeholder="ค้นหาแคมเปญ..." class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-[#0052CC] font-prompt transition-shadow">
            </div>
        </div>
        <div class="max-h-60 overflow-y-auto p-2 space-y-0.5" id="multiSelectList">
            <label class="flex items-center gap-3 px-2 py-2 hover:bg-blue-50/50 rounded-lg cursor-pointer transition-colors group">
                <input type="checkbox" id="selectAllCamps" checked onchange="toggleAllCampaigns(this)" class="w-4 h-4 text-[#0052CC] rounded border-gray-300 focus:ring-[#0052CC] transition-colors cursor-pointer">
                <span class="text-sm font-bold text-gray-800 group-hover:text-[#0052CC] transition-colors">เลือกทั้งหมด</span>
            </label>
            <div class="h-px bg-gray-100 my-1"></div>';
            foreach ($activeCampaigns as $ac) {
                $header_actions .= '
                <label class="camp-label-item flex items-center gap-3 px-2 py-2 hover:bg-gray-50 rounded-lg cursor-pointer transition-colors group" data-title="' . htmlspecialchars(strtolower($ac['title'])) . '">
                    <input type="checkbox" value="' . $ac['id'] . '" checked onchange="updateMultiSelectFilter()" class="camp-checkbox w-4 h-4 text-[#0052CC] rounded border-gray-300 focus:ring-[#0052CC] transition-colors cursor-pointer">
                    <span class="text-sm text-gray-600 line-clamp-1 break-all group-hover:text-gray-900 transition-colors">' . htmlspecialchars($ac['title']) . '</span>
                </label>';
            }
            $header_actions .= '
        </div>
    </div>
</div>
<div class="flex items-center bg-gray-100 p-1 rounded-xl">
    <button onclick="switchView(\'calendar\')" id="btnViewCalendar" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#0052CC] transition-all" title="มุมมองปฏิทิน">
        <i class="fa-solid fa-calendar-alt"></i>
    </button>
    <button onclick="switchView(\'table\')" id="btnViewTable" class="px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all" title="มุมมองตาราง">
        <i class="fa-solid fa-list-ul"></i>
    </button>
</div>
<button onclick="openAddSlotModal(\'' . date('Y-m-d') . '\')" class="bg-gradient-to-r from-blue-600 to-[#0052CC] text-white px-5 py-2.5 rounded-xl font-prompt text-sm font-bold shadow-md hover:shadow-blue-500/30 hover:-translate-y-0.5 transition-all flex items-center gap-2">
    <i class="fa-solid fa-plus-circle"></i> สร้างรอบเวลา
</button>
<select onchange="location.href=\'?month=\'+this.value.split(\'-\')[1]+\'&year=\'+this.value.split(\'-\')[0]" class="px-5 py-2.5 border border-gray-200 rounded-xl bg-white font-prompt text-sm font-bold text-gray-700 outline-none shadow-sm cursor-pointer hover:bg-gray-50">';
    for($i = -3; $i <= 6; $i++) {
        $d = date('Y-m', strtotime("$i months"));
        $selected = ($d == "$year-".str_pad($month, 2, '0', STR_PAD_LEFT)) ? 'selected' : '';
        $header_actions .= "<option value='$d' $selected>".date('M Y', strtotime("$i months"))."</option>";
    }
$header_actions .= '</select>';

renderPageHeader("Campaign Time Slots", "กำหนดช่วงเวลารับคิวต่อรอบ (เลือกสร้างพร้อมกันได้หลายวัน)", $header_actions);
?>

<style>
@keyframes slideUpFade {
    0%   { opacity: 0; transform: translateY(12px); }
    100% { opacity: 1; transform: translateY(0); }
}
.animate-slide-up  { animation: slideUpFade .45s cubic-bezier(.16,1,.3,1) both; }
.delay-100         { animation-delay: .08s; }

/* ── Scrollbar ───────────────────────────────── */
::-webkit-scrollbar       { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.scrollbar-hide { scrollbar-width: none; }
.scrollbar-hide::-webkit-scrollbar { display: none; }

/* ── Calendar container ──────────────────────── */
.cal-wrap {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
    border: 1px solid #f0f2f5;
    overflow: hidden;
}

/* ── Day-header row ──────────────────────────── */
.cal-head {
    background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
    color: #fff;
    padding: 10px 0;
    text-align: center;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}
.cal-head.sunday { color: #fca5a5; }

/* ── Day cell ────────────────────────────────── */
.cal-cell {
    background: #fff;
    min-height: 130px;
    padding: 8px;
    border-top: 1px solid #f0f2f5;
    border-right: 1px solid #f0f2f5;
    display: flex;
    flex-direction: column;
    transition: background .15s;
    position: relative;
}
.cal-cell:hover { background: #f8faff; }
.cal-cell.empty { background: #fafbfc; }

/* ── Date number ─────────────────────────────── */
.cal-date {
    width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; border-radius: 50%;
    color: #374151;
    flex-shrink: 0;
}
.cal-date.today {
    background: linear-gradient(135deg, #0052CC, #0070f3);
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,82,204,.35);
}

/* ── Add-slot hover button ───────────────────── */
.cal-add-btn {
    opacity: 0; transition: opacity .15s;
    width: 24px; height: 24px;
    background: #e8f0fe; color: #0052CC;
    border-radius: 7px; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
}
.cal-cell:hover .cal-add-btn { opacity: 1; }

/* ── Slot card inside cell ───────────────────── */
.slot-card {
    border-radius: 8px;
    padding: 5px 7px;
    margin-bottom: 4px;
    border: 1px solid transparent;
    position: relative;
    transition: box-shadow .15s, transform .15s;
    cursor: default;
}
.slot-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,.1);
    transform: translateY(-1px);
    z-index: 1;
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

/* ── Capacity bar ────────────────────────────── */
.cap-bar { height: 3px; border-radius: 99px; background: rgba(0,0,0,.08); overflow: hidden; margin: 3px 0; }
.cap-bar-fill { height: 100%; border-radius: 99px; transition: width .3s; }

/* ── Table ───────────────────────────────────── */
.slots-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.slots-table thead th {
    background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
    color: rgba(255,255,255,.85);
    font-size: 11px; font-weight: 800;
    letter-spacing: .08em; text-transform: uppercase;
    padding: 14px 20px;
}
.slots-table thead th:first-child { border-radius: 14px 0 0 0; }
.slots-table thead th:last-child  { border-radius: 0 14px 0 0; }
.slots-table tbody tr { transition: background .12s; }
.slots-table tbody tr:hover td { background: #f5f8ff; }
.slots-table tbody td {
    padding: 14px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 13.5px;
    background: #fff;
}

/* ── Status badge ────────────────────────────── */
.stat-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 99px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}

/* ── Modal glass ─────────────────────────────── */
.glass-modal {
    background: #fff;
    border: 1px solid rgba(255,255,255,.3);
    box-shadow: 0 24px 48px -8px rgba(0,0,0,.2);
}

/* ── Modal header strips ─────────────────────── */
.modal-hdr-blue   { background: linear-gradient(135deg,#0052CC,#0070f3); }
.modal-hdr-amber  { background: linear-gradient(135deg,#f59e0b,#d97706); }
</style>

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
                        <span class="cal-date <?= $isToday ? 'today' : '' ?> <?= isset($calendarData[$currentDate]) ? 'cursor-pointer hover:ring-2 hover:ring-[#0052CC]/40' : '' ?>"
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
                            $cc    = $campaignColors[$cId] ?? ['bg'=>'bg-gray-50','border'=>'border-gray-100','text'=>'text-gray-600','badge'=>'text-gray-500'];
                            $booked  = (int)($s['booked_count'] ?? 0);
                            $max     = (int)$s['max_capacity'];
                            $percent = $max > 0 ? ($booked / $max) * 100 : 0;

                            if ($percent >= 100) {
                                $badgeCls = 'background:#fee2e2;color:#b91c1c';
                                $barClr   = '#ef4444';
                            } elseif ($percent >= 80) {
                                $badgeCls = 'background:#fef9c3;color:#a16207';
                                $barClr   = '#facc15';
                            } else {
                                $badgeCls = 'background:#dcfce7;color:#15803d';
                                $barClr   = '#22c55e';
                            }
                        ?>
                        <div class="slot-item slot-card filter-camp-<?= $cId ?> <?= $cc['bg'] ?> <?= $cc['text'] ?>"
                             style="border-color: rgba(0,0,0,.06)">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-1.5">
                                    <input type="checkbox" value="<?= $s['id'] ?>"
                                        class="slot-select-cb calendar-slot-cb w-3 h-3 text-red-500 rounded border-gray-300 focus:ring-red-500 cursor-pointer opacity-40 hover:opacity-100 checked:opacity-100 transition-opacity flex-shrink-0"
                                        onchange="toggleSlotSelection(this)">
                                    <span class="text-[11px] font-black <?= $percent >= 100 ? 'line-through opacity-50' : '' ?>"><?= substr($s['start_time'], 0, 5) ?></span>
                                </div>
                                <span class="text-[10px] font-bold rounded-full px-1.5 py-0.5" style="<?= $badgeCls ?>" title="<?= $booked ?>/<?= $max ?>">
                                    +<?= $booked ?>/<?= $max ?>
                                </span>
                            </div>
                            <div class="cap-bar mt-1">
                                <div class="cap-bar-fill" style="width:<?= min($percent,100) ?>%;background:<?= $barClr ?>"></div>
                            </div>
                            <div class="truncate text-[9px] font-semibold opacity-70 mt-0.5" title="<?= htmlspecialchars($s['campaign_title']) ?>">
                                <?= htmlspecialchars($s['campaign_title']) ?>
                            </div>
                            <div class="slot-actions">
                                <button onclick="openEditSlotModal(<?= $s['id'] ?>,<?= $cId ?>,'<?= substr($s['start_time'],0,5) ?>','<?= substr($s['end_time'],0,5) ?>',<?= $max ?>)"
                                    class="slot-act-btn" style="background:#fef3c7;color:#d97706" title="แก้ไข">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button onclick="deleteSlot(<?= $s['id'] ?>)"
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

<div id="tableViewContainer" class="hidden animate-slide-up delay-100 mb-10 overflow-hidden" style="background:#fff;border-radius:20px;box-shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);border:1px solid #f0f2f5">
    <div class="overflow-x-auto">
        <table id="slotsTable" class="slots-table">
            <thead>
                <tr>
                    <th class="text-center w-10" data-sortable="false">
                        <input type="checkbox" id="selectAllTable" class="w-4 h-4 rounded border-white/40 cursor-pointer" onchange="toggleAllTableSlots(this)">
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
                    if ($percent >= 100)     { $badgeSt = 'background:#fee2e2;color:#b91c1c'; }
                    elseif ($percent >= 80)  { $badgeSt = 'background:#fef9c3;color:#a16207'; }
                    else                     { $badgeSt = 'background:#dcfce7;color:#15803d'; }
                    $dateObj = new DateTime($s['slot_date']);
                ?>
                <tr data-camp-id="<?= $s['campaign_id'] ?>">
                    <td class="text-center">
                        <input type="checkbox" value="<?= $s['id'] ?>" class="slot-select-cb table-slot-cb w-4 h-4 text-[#0052CC] rounded border-gray-300 focus:ring-[#0052CC] cursor-pointer opacity-50 hover:opacity-100 checked:opacity-100 transition-opacity" onchange="toggleSlotSelection(this)">
                    </td>
                    <td data-sort="<?= $s['slot_date'] ?>">
                        <span class="font-bold text-gray-800"><?= $dateObj->format('d/m/Y') ?></span>
                    </td>
                    <td>
                        <span class="font-black text-[#0052CC] bg-blue-50 px-2.5 py-1 rounded-lg text-[12px]"><?= substr($s['start_time'],0,5) ?> – <?= substr($s['end_time'],0,5) ?></span>
                    </td>
                    <td class="text-gray-600 font-medium max-w-[220px] truncate"><?= htmlspecialchars($s['campaign_title']) ?></td>
                    <td class="text-center" data-sort="<?= $percent ?>">
                        <span class="stat-badge" style="<?= $badgeSt ?>"><?= $booked ?> / <?= $max ?></span>
                    </td>
                    <td class="text-center">
                        <div class="flex items-center justify-center gap-2">
                            <button onclick="openEditSlotModal(<?= $s['id'] ?>,<?= $s['campaign_id'] ?>,'<?= substr($s['start_time'],0,5) ?>','<?= substr($s['end_time'],0,5) ?>',<?= $max ?>)"
                                class="w-8 h-8 rounded-xl text-amber-500 bg-amber-50 border border-amber-100 hover:bg-amber-500 hover:text-white transition-all shadow-sm" title="แก้ไข">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>
                            <button onclick="deleteSlot(<?= $s['id'] ?>)"
                                class="w-8 h-8 rounded-xl text-red-500 bg-red-50 border border-red-100 hover:bg-red-500 hover:text-white transition-all shadow-sm" title="ลบ">
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

<div id="slotModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-modal rounded-[24px] w-full max-w-lg flex flex-col max-h-[90vh] overflow-hidden animate-slide-up border border-white/50">
        <div class="modal-hdr-blue p-5 flex justify-between items-center shrink-0">
            <h3 class="text-lg font-black text-white flex items-center gap-3">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center"><i class="fa-solid fa-calendar-plus"></i></div>
                สร้างรอบเวลาแคมเปญ
            </h3>
            <button type="button" onclick="document.getElementById('slotModal').classList.add('hidden')" class="w-8 h-8 flex items-center justify-center bg-white/20 text-white rounded-full hover:bg-white/30 transition-colors focus:outline-none"><i class="fa-solid fa-times"></i></button>
        </div>
        <form id="slotForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="add_slot">
            <?php csrf_field(); ?>
            
            <div class="p-5 space-y-4 overflow-y-auto flex-1 scrollbar-hide">
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">เลือกแคมเปญ <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select name="campaign_id" required class="w-full px-4 py-2 border border-gray-200 rounded-xl font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC] bg-white appearance-none">
                        <option value="" disabled selected>-- เลือกกิจกรรม --</option>
                        <?php foreach ($activeCampaigns as $ac): ?>
                            <option value="<?= $ac['id'] ?>"><?= htmlspecialchars($ac['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500"><i class="fa-solid fa-chevron-down text-xs"></i></div>
                </div>
            </div>

            <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                <label class="block text-[11px] uppercase tracking-wider font-bold text-gray-500 mb-1">เลือกวันที่ต้องการจัดกิจกรรม (เลือกได้หลายวัน) <span class="text-red-500">*</span></label>
                <input type="text" name="selected_dates" id="modal_selected_dates" placeholder="คลิกเพื่อเลือกจากปฏิทิน..." required class="w-full px-3 py-2 border border-blue-200 bg-white rounded-lg font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC] cursor-pointer shadow-inner">
            </div>

            <!-- โซนสร้างช่วงเวลาอัตโนมัติ -->
            <div class="bg-blue-50/50 p-3 rounded-xl border border-blue-100">
                <div class="flex justify-between items-center cursor-pointer" onclick="document.getElementById('autoGenBody').classList.toggle('hidden'); document.getElementById('autoGenIcon').classList.toggle('fa-chevron-down'); document.getElementById('autoGenIcon').classList.toggle('fa-chevron-up');">
                    <label class="text-[13px] font-bold text-[#0052CC] cursor-pointer flex items-center gap-2 m-0">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> สร้างช่วงเวลาย่อยอัตโนมัติ
                    </label>
                    <i id="autoGenIcon" class="fa-solid fa-chevron-down text-blue-400 text-xs"></i>
                </div>
                
                <div id="autoGenBody" class="hidden space-y-3 pt-3 border-t border-blue-100 mt-2">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 mb-1">เริ่มงาน</label>
                            <input type="time" id="auto_start" value="09:00" class="w-full px-3 py-2 border border-blue-200 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-[#0052CC]">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 mb-1">เลิกงาน</label>
                            <input type="time" id="auto_end" value="16:00" class="w-full px-3 py-2 border border-blue-200 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-[#0052CC]">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 mb-1">เวลาย่อยต่อรอบ (นาที)</label>
                            <input type="number" id="auto_duration" value="60" min="5" step="5" class="w-full px-3 py-2 border border-blue-200 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-[#0052CC]">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 mb-1">พักเบรก (ถ้ามี)</label>
                            <div class="flex items-center gap-1 bg-white border border-blue-200 rounded-lg overflow-hidden pr-2 focus-within:ring-2 focus-within:ring-[#0052CC]">
                                <input type="time" id="auto_break_start" value="12:00" class="w-full px-1 py-2 text-sm border-none outline-none">
                                <span class="text-gray-400 text-xs">-</span>
                                <input type="time" id="auto_break_end" value="13:00" class="w-full px-1 py-2 text-sm border-none outline-none">
                            </div>
                        </div>
                    </div>
                    <button type="button" onclick="generateTimeSlots()" class="w-full py-2 bg-blue-100/80 text-[#0052CC] font-bold rounded-lg hover:bg-blue-200 transition-colors text-[13px] border border-blue-200">
                        <i class="fa-solid fa-bolt mr-1"></i> เลื่อนลงช่องด้านล่างอัตโนมัติ
                    </button>
                </div>
            </div>

            <div class="space-y-3" id="time_slots_container">
                <div class="time-slot-row flex items-end gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100 relative group overflow-hidden">
                    <div class="flex-1">
                        <label class="block text-[11px] uppercase tracking-wider font-bold text-gray-500 mb-1">เวลาเริ่ม <span class="text-red-500">*</span></label>
                        <input type="time" name="start_time[]" required class="w-full px-3 py-2 border border-gray-200 rounded-lg font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC] bg-white">
                    </div>
                    <div class="flex-1">
                        <label class="block text-[11px] uppercase tracking-wider font-bold text-gray-500 mb-1">เวลาสิ้นสุด <span class="text-red-500">*</span></label>
                        <input type="time" name="end_time[]" required class="w-full px-3 py-2 border border-gray-200 rounded-lg font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC] bg-white">
                    </div>
                    <button type="button" onclick="removeTimeSlot(this)" class="remove-time-btn hidden w-10 h-[38px] min-w-[40px] bg-white border border-gray-200 text-red-500 hover:text-white hover:bg-red-500 hover:border-red-500 rounded-lg flex items-center justify-center transition-colors shadow-sm">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
            <button type="button" onclick="addTimeSlot()" class="w-full py-2 border border-dashed border-[#0052CC] text-[#0052CC] font-bold rounded-xl hover:bg-blue-50 transition-colors text-sm flex items-center justify-center gap-2">
                <i class="fa-solid fa-plus-circle"></i> เพิ่มช่วงเวลาอีก
            </button>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">จำนวนรับรวมต่อวัน (ระบบจะหารเฉลี่ยให้ทุกรอบเวลา) <span class="text-red-500">*</span></label>
                <input type="number" name="max_capacity" value="50" min="1" required class="w-full px-4 py-2 border border-gray-200 rounded-xl font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC]">
            </div>

            </div>
            
            <div class="p-5 border-t border-gray-100 bg-gray-50/50 shrink-0 flex gap-3">
                <button type="button" onclick="document.getElementById('slotModal').classList.add('hidden')" class="w-1/3 bg-white border-2 border-gray-200 text-gray-700 font-bold py-3.5 rounded-2xl hover:bg-gray-50 hover:border-gray-300 transition-colors shadow-sm">ยกเลิก</button>
                <button type="submit" class="w-2/3 bg-gradient-to-r from-blue-600 to-[#0052CC] text-white font-bold py-3.5 rounded-2xl hover:shadow-lg hover:shadow-blue-500/30 hover:-translate-y-0.5 transition-all text-lg tracking-wide shadow-sm flex items-center justify-center gap-2"><i class="fa-solid fa-save"></i> บันทึกรอบเวลา</button>
            </div>
        </form>
    </div>
</div>

<div id="editSlotModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-modal rounded-[24px] w-full max-w-lg flex flex-col max-h-[90vh] overflow-hidden animate-slide-up border border-white/50">
        <div class="modal-hdr-amber p-5 flex justify-between items-center shrink-0">
            <h3 class="text-lg font-black text-white flex items-center gap-3">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center"><i class="fa-solid fa-pen-to-square"></i></div>
                แก้ไขข้อมูลรอบเวลา
            </h3>
            <button type="button" onclick="document.getElementById('editSlotModal').classList.add('hidden')" class="w-8 h-8 flex items-center justify-center bg-white/20 text-white rounded-full hover:bg-white/30 transition-colors focus:outline-none"><i class="fa-solid fa-times"></i></button>
        </div>
        <form id="editSlotForm" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="edit_slot">
            <input type="hidden" name="slot_id" id="edit_slot_id">
            <?php csrf_field(); ?>
            
            <div class="p-5 space-y-4 overflow-y-auto flex-1 scrollbar-hide">
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">แคมเปญ <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select name="campaign_id" id="edit_campaign_id" required class="w-full px-4 py-2 border border-gray-200 rounded-xl font-prompt text-sm outline-none focus:ring-2 focus:ring-yellow-500 bg-gray-50 appearance-none pointer-events-none text-gray-500">
                        <?php foreach ($allCampaigns as $ac): ?>
                            <option value="<?= $ac['id'] ?>"><?= htmlspecialchars($ac['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500"><i class="fa-solid fa-lock text-xs"></i></div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">เวลาเริ่ม <span class="text-red-500">*</span></label>
                    <input type="time" name="start_time" id="edit_start_time" required class="w-full px-4 py-2 border border-gray-200 rounded-xl font-prompt text-sm outline-none focus:ring-2 focus:ring-yellow-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">เวลาสิ้นสุด <span class="text-red-500">*</span></label>
                    <input type="time" name="end_time" id="edit_end_time" required class="w-full px-4 py-2 border border-gray-200 rounded-xl font-prompt text-sm outline-none focus:ring-2 focus:ring-yellow-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">จำนวนรับ (ที่นั่ง) <span class="text-red-500">*</span></label>
                <input type="number" name="max_capacity" id="edit_max_capacity" min="1" required class="w-full px-4 py-2 border border-gray-200 rounded-xl font-prompt text-sm outline-none focus:ring-2 focus:ring-yellow-500">
            </div>

            </div>

            <div class="p-5 border-t border-gray-100 bg-gray-50/50 shrink-0 flex gap-3">
                <button type="button" onclick="document.getElementById('editSlotModal').classList.add('hidden')" class="w-1/3 bg-white border-2 border-gray-200 text-gray-700 font-bold py-3.5 rounded-2xl hover:bg-gray-50 hover:border-gray-300 transition-colors shadow-sm">ยกเลิก</button>
                <button type="submit" class="w-2/3 bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold py-3.5 rounded-2xl hover:shadow-lg hover:shadow-amber-500/30 hover:-translate-y-0.5 transition-all text-lg tracking-wide shadow-sm flex items-center justify-center gap-2"><i class="fa-solid fa-save"></i> บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>
<!-- โหลด Flatpickr สำหรับ Date Picker แบบเลือกหลายวัน -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/th.js"></script>

<link href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css" rel="stylesheet" type="text/css">
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest" type="text/javascript"></script>

<style>
.dataTable-wrapper .dataTable-container {
    border-bottom: 1px solid #f3f4f6;
    font-family: inherit;
}
.dataTable-table > thead > tr > th {
    border-bottom: 1px solid #e5e7eb;
}
.dataTable-input, .dataTable-selector {
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.35rem 0.5rem;
    font-size: 0.875rem;
    outline: none;
    font-family: inherit;
}
.dataTable-input:focus, .dataTable-selector:focus {
    border-color: #0052CC;
    box-shadow: 0 0 0 2px rgba(0, 82, 204, 0.2);
}
.dataTable-info, .dataTable-bottom {
    font-size: 0.875rem;
    color: #6B7280;
    margin-top: 0.5rem;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        
        document.getElementById('btnViewCalendar').className = "px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#0052CC] transition-all";
        document.getElementById('btnViewTable').className = "px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all";
    } else {
        document.getElementById('calendarViewContainer').classList.add('hidden');
        document.getElementById('tableViewContainer').classList.remove('hidden');
        
        document.getElementById('btnViewTable').className = "px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#0052CC] transition-all";
        document.getElementById('btnViewCalendar').className = "px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all";
    }
}

// ฟังก์ชันปิด/เปิด Dropdown และ Multi-Select Logic
function toggleMultiSelect(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById('multiSelectDropdown');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        const btn = document.querySelector('#multiSelectContainer button');
        const rect = btn.getBoundingClientRect();
        dropdown.style.top  = (rect.bottom + 8) + 'px';
        dropdown.style.right = (window.innerWidth - rect.right) + 'px';
        dropdown.style.display = 'flex';
    } else {
        dropdown.style.display = 'none';
    }
}

// ปิด dropdown เมื่อกดคลิกที่อื่น
document.addEventListener('click', function(event) {
    const container = document.getElementById('multiSelectContainer');
    if (container && !container.contains(event.target)) {
        document.getElementById('multiSelectDropdown').style.display = 'none';
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

    document.getElementById('slotModal').classList.remove('hidden');
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
    document.getElementById('editSlotModal').classList.remove('hidden');
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
            Swal.fire({
                title: 'สำเร็จ!',
                text: data.message,
                icon: 'success',
                confirmButtonColor: '#0052CC',
                customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    })
    .catch(err => {
        Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
    });
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
            Swal.fire({ title: 'สำเร็จ!', text: data.message, icon: 'success', confirmButtonColor: '#0052CC', customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
            }).then(() => location.reload());
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    }).catch(err => Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error'));
});

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
                    location.reload();
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
        row.className = "time-slot-row flex items-end gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100 relative group overflow-hidden";
        row.innerHTML = `
            <div class="flex-1">
                <label class="block text-[11px] uppercase tracking-wider font-bold text-gray-500 mb-1">เวลาเริ่ม <span class="text-red-500">*</span></label>
                <input type="time" name="start_time[]" value="${slot.st}" required class="w-full px-3 py-2 border border-gray-200 rounded-lg font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC] bg-white">
            </div>
            <div class="flex-1">
                <label class="block text-[11px] uppercase tracking-wider font-bold text-gray-500 mb-1">เวลาสิ้นสุด <span class="text-red-500">*</span></label>
                <input type="time" name="end_time[]" value="${slot.et}" required class="w-full px-3 py-2 border border-gray-200 rounded-lg font-prompt text-sm outline-none focus:ring-2 focus:ring-[#0052CC] bg-white">
            </div>
            <button type="button" onclick="removeTimeSlot(this)" class="remove-time-btn w-10 h-[38px] min-w-[40px] bg-white border border-gray-200 text-red-500 hover:text-white hover:bg-red-500 hover:border-red-500 rounded-lg flex items-center justify-center transition-colors shadow-sm">
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
                        confirmButtonColor: '#0052CC',
                        customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl' }
                    }).then(() => {
                        location.reload();
                    });
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
<div id="dailyModal" class="fixed inset-0 flex items-center justify-center p-4" style="display:none;z-index:9999">
    <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeDailyModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden animate-slide-up">

        <!-- Header -->
        <div class="modal-hdr-blue px-6 py-4 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-calendar-day text-white"></i>
                </div>
                <div>
                    <h3 class="text-white font-black text-lg leading-none" id="dailyModalTitle">รอบเวลาประจำวัน</h3>
                    <p class="text-blue-100 text-xs mt-0.5" id="dailyModalSub"></p>
                </div>
            </div>
            <button onclick="closeDailyModal()" class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center text-white transition-colors">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>

        <!-- Body -->
        <div class="overflow-y-auto flex-1 p-5" id="dailyModalBody">
            <div class="flex items-center justify-center py-12 text-gray-400">
                <i class="fa-solid fa-spinner fa-spin text-2xl mr-3 text-[#0052CC]"></i>
                <span class="font-prompt">กำลังโหลด...</span>
            </div>
        </div>
    </div>
</div>

<script>
let _dailyDate = '';

function openDailyModal(date) {
    _dailyDate = date;
    const modal = document.getElementById('dailyModal');
    modal.style.display = 'flex';

    // Format date for display
    const d = new Date(date + 'T00:00:00');
    const opts = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
    document.getElementById('dailyModalTitle').textContent = 'รอบเวลาประจำวัน';
    document.getElementById('dailyModalSub').textContent   = d.toLocaleDateString('th-TH', opts);

    loadDailySlots(date);
}

function closeDailyModal() {
    document.getElementById('dailyModal').style.display = 'none';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDailyModal(); });

function loadDailySlots(date) {
    document.getElementById('dailyModalBody').innerHTML = `
        <div class="flex items-center justify-center py-12 text-gray-400">
            <i class="fa-solid fa-spinner fa-spin text-2xl mr-3 text-[#0052CC]"></i>
            <span class="font-prompt">กำลังโหลด...</span>
        </div>`;

    const fd = new FormData();
    fd.append('action', 'get');
    fd.append('date', date);
    fd.append('csrf_token', '<?= get_csrf_token() ?>');

    fetch('ajax_get_daily_slots.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success') {
            document.getElementById('dailyModalBody').innerHTML =
                `<p class="text-center text-red-500 py-8 font-prompt">${data.message}</p>`;
            return;
        }
        renderDailySlots(data.slots, date);
    })
    .catch(() => {
        document.getElementById('dailyModalBody').innerHTML =
            '<p class="text-center text-red-500 py-8 font-prompt">ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้</p>';
    });
}

function renderDailySlots(slots, date) {
    if (!slots.length) {
        document.getElementById('dailyModalBody').innerHTML = `
            <div class="text-center py-12">
                <i class="fa-solid fa-calendar-xmark text-4xl text-gray-300 mb-3 block"></i>
                <p class="text-gray-400 font-prompt">ไม่มีรอบเวลาในวันนี้</p>
                <button onclick="openAddSlotModal('${date}'); closeDailyModal();"
                    class="mt-4 px-5 py-2 bg-[#0052CC] text-white rounded-xl text-sm font-bold font-prompt hover:bg-blue-700 transition-colors">
                    <i class="fa-solid fa-plus mr-1"></i> สร้างรอบเวลา
                </button>
            </div>`;
        return;
    }

    let rows = slots.map(s => {
        const pct     = s.max_capacity > 0 ? (s.booked_count / s.max_capacity) * 100 : 0;
        const badgeSt = pct >= 100 ? 'background:#fee2e2;color:#b91c1c'
                      : pct >= 80  ? 'background:#fef9c3;color:#a16207'
                      :              'background:#dcfce7;color:#15803d';
        const barClr  = pct >= 100 ? '#ef4444' : pct >= 80 ? '#facc15' : '#22c55e';

        return `
        <tr id="drow-${s.id}" class="border-b border-gray-50 hover:bg-gray-50/80 transition-colors">
            <td class="px-4 py-3">
                <span class="font-semibold text-gray-800 text-sm">${escHtml(s.campaign_title)}</span>
            </td>
            <td class="px-4 py-3" style="white-space:nowrap">
                <span class="font-black text-[#0052CC] bg-blue-50 px-2.5 py-1 rounded-lg text-xs" style="white-space:nowrap;display:inline-block">
                    ${s.start_time.slice(0,5)} – ${s.end_time.slice(0,5)}
                </span>
            </td>
            <td class="px-4 py-3" style="white-space:nowrap">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:nowrap">
                    <span class="text-xs font-bold rounded-full px-2.5 py-1" style="${badgeSt};white-space:nowrap;display:inline-block">
                        ${s.booked_count} / ${s.max_capacity}
                    </span>
                    <div style="width:60px;min-width:60px;height:4px;background:#e5e7eb;border-radius:99px;overflow:hidden">
                        <div style="width:${Math.min(pct,100)}%;height:100%;background:${barClr};border-radius:99px"></div>
                    </div>
                </div>
            </td>
            <td class="px-4 py-3" style="white-space:nowrap">
                <div style="display:flex;gap:4px;justify-content:flex-end;align-items:center">
                    <button onclick="dailyEditRow(${s.id},'${s.start_time.slice(0,5)}','${s.end_time.slice(0,5)}',${s.max_capacity})"
                        class="w-8 h-8 rounded-lg bg-amber-50 border border-amber-100 text-amber-500 hover:bg-amber-500 hover:text-white transition-all" title="แก้ไข" style="flex-shrink:0">
                        <i class="fa-solid fa-pen text-xs"></i>
                    </button>
                    <button onclick="dailyDeleteSlot(${s.id},'${date}')"
                        class="w-8 h-8 rounded-lg bg-red-50 border border-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-all" title="ลบ" style="flex-shrink:0">
                        <i class="fa-solid fa-trash text-xs"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    document.getElementById('dailyModalBody').innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <p class="text-sm text-gray-500 font-prompt">พบ <b class="text-gray-800">${slots.length}</b> รอบเวลา</p>
            <button onclick="openAddSlotModal('${date}'); closeDailyModal();"
                class="px-4 py-1.5 bg-[#0052CC] text-white rounded-xl text-xs font-bold font-prompt hover:bg-blue-700 transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-plus"></i> สร้างรอบเวลา
            </button>
        </div>
        <div class="overflow-x-auto rounded-xl border border-gray-100">
            <table class="w-full text-left">
                <thead>
                    <tr style="background:linear-gradient(135deg,#0052CC,#0070f3)">
                        <th class="px-4 py-3 text-xs font-bold text-white/80 uppercase tracking-wider">แคมเปญ</th>
                        <th class="px-4 py-3 text-xs font-bold text-white/80 uppercase tracking-wider" style="white-space:nowrap;width:120px">เวลา</th>
                        <th class="px-4 py-3 text-xs font-bold text-white/80 uppercase tracking-wider" style="white-space:nowrap;width:160px">ยอดจอง</th>
                        <th class="px-4 py-3 text-xs font-bold text-white/80 uppercase tracking-wider text-right" style="white-space:nowrap;width:90px">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="bg-white">${rows}</tbody>
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
        <td class="px-4 py-2" colspan="2">
            <div class="flex gap-2 items-center flex-wrap">
                <input type="time" id="de_start_${id}" value="${start}"
                    class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-[#0052CC]">
                <span class="text-gray-400 text-sm">–</span>
                <input type="time" id="de_end_${id}" value="${end}"
                    class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-[#0052CC]">
                <input type="number" id="de_cap_${id}" value="${cap}" min="1"
                    class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm outline-none focus:ring-2 focus:ring-[#0052CC] w-20"
                    placeholder="ที่นั่ง">
            </div>
        </td>
        <td class="px-4 py-2" colspan="2">
            <div class="flex gap-2 justify-end">
                <button onclick="dailySaveEdit(${id})"
                    class="px-3 py-1.5 bg-[#0052CC] text-white rounded-lg text-xs font-bold hover:bg-blue-700 transition-colors font-prompt">
                    <i class="fa-solid fa-save mr-1"></i>บันทึก
                </button>
                <button onclick="loadDailySlots('${_dailyDate}')"
                    class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-xs font-bold hover:bg-gray-200 transition-colors font-prompt">
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
        Swal.fire({ icon:'warning', title:'กรอกข้อมูลให้ครบ', confirmButtonColor:'#0052CC', customClass:{title:'font-prompt'} });
        return;
    }

    const fd = new FormData();
    fd.append('action', 'edit');
    fd.append('slot_id', id);
    fd.append('start_time', start);
    fd.append('end_time', end);
    fd.append('max_capacity', cap);
    fd.append('csrf_token', '<?= get_csrf_token() ?>');

    fetch('ajax_get_daily_slots.php', { method:'POST', body:fd })
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

        fetch('ajax_get_daily_slots.php', { method:'POST', body:fd })
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
