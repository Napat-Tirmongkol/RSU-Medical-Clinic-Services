<?php
// admin/reports.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// 1. ดึงรายชื่อแคมเปญทั้งหมดมาทำ Dropdown ให้เลือก
$camp_list = $pdo->query("SELECT id, title FROM camp_list ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : (count($camp_list) > 0 ? $camp_list[0]['id'] : 0);

$stats = ['total' => 0, 'attended' => 0, 'absent' => 0, 'upcoming' => 0, 'cancelled' => 0];
$participants = [];
$selectedCampaignTitle = '';

// 2. ถ้ามีการเลือกแคมเปญ ให้ดึงข้อมูลสถิติและรายชื่อ
if ($campaignId > 0) {
    // หาชื่อแคมเปญ
    foreach ($camp_list as $c) {
        if ($c['id'] == $campaignId) {
            $selectedCampaignTitle = $c['title'];
            break;
        }
    }

    $today = date('Y-m-d');

    // ดึงข้อมูลผู้เข้าร่วมทั้งหมดของแคมเปญนี้
    $sql = "
        SELECT 
            a.id AS appointment_id, 
            a.status, 
            a.attended_at,
            s.full_name, 
            s.student_personnel_id, 
            s.phone_number,
            t.slot_date, 
            t.start_time, 
            t.end_time
        FROM camp_bookings a
        JOIN sys_users s ON a.student_id = s.id
        JOIN camp_slots t ON a.slot_id = t.id
        WHERE a.campaign_id = :cid
        ORDER BY t.slot_date DESC, t.start_time DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $campaignId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // คำนวณสถิติ
    $stats['total'] = count($participants);
    foreach ($participants as &$p) {
        $isAttended = !empty($p['attended_at']);
        $isCancelled = ($p['status'] === 'cancelled');
        $isPast = ($p['slot_date'] < $today); // เลยวันไปแล้ว

        if ($isCancelled) {
            $p['calc_status'] = 'cancelled';
            $stats['cancelled']++;
        } elseif ($isAttended) {
            $p['calc_status'] = 'attended';
            $stats['attended']++;
        } elseif ($isPast) {
            $p['calc_status'] = 'absent';
            $stats['absent']++;
        } else {
            $p['calc_status'] = 'upcoming';
            $stats['upcoming']++;
        }
    }
    unset($p); // clear reference
}

// ==========================================
// ส่วนจัดการ Export Excel
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $campaignId > 0) {
    $filename = "report_campaign_{$campaignId}_" . date('Ymd') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // รองรับภาษาไทยใน Excel
    
    fputcsv($output, ['รหัสนักศึกษา/บุคลากร', 'ชื่อ-นามสกุล', 'เบอร์โทรศัพท์', 'วันที่จัดงาน', 'เวลา', 'สถานะ', 'เวลาเช็คอิน']);
    
    foreach ($participants as $p) {
        $dateLabel = date('d/m/Y', strtotime($p['slot_date']));
        $timeLabel = substr($p['start_time'], 0, 5) . '-' . substr($p['end_time'], 0, 5);
        $checkinTime = $p['attended_at'] ? date('d/m/Y H:i', strtotime($p['attended_at'])) : '-';
        
        $statusText = '';
        switch ($p['calc_status']) {
            case 'attended': $statusText = 'มาเข้าร่วมแล้ว'; break;
            case 'absent': $statusText = 'ขาด/ไม่มาตามนัด'; break;
            case 'upcoming': $statusText = 'รอเข้าร่วม (ยังไม่ถึงวัน)'; break;
            case 'cancelled': $statusText = 'ยกเลิกคิว'; break;
        }
        
        fputcsv($output, [
            $p['student_personnel_id'], 
            $p['full_name'], 
            $p['phone_number'], 
            $dateLabel, 
            $timeLabel, 
            $statusText,
            $checkinTime
        ]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<?php renderPageHeader("รายงานและสถิติ", "ดูยอดผู้เข้าร่วมและผู้ที่ขาดกิจกรรมของแต่ละแคมเปญ"); ?>

<div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 mb-6">
    <form method="GET" class="flex flex-col md:flex-row gap-3 items-end">
        <div class="flex-1 w-full">
            <label class="block text-sm font-semibold text-gray-700 mb-2">เลือกกิจกรรมที่ต้องการดูรายงาน</label>
            <div class="relative">
                <select name="campaign_id" onchange="this.form.submit()" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] outline-none font-prompt text-gray-700 appearance-none bg-white font-medium cursor-pointer">
                    <?php if (count($camp_list) === 0): ?>
                        <option value="0">-- ไม่มีกิจกรรมในระบบ --</option>
                    <?php else: ?>
                        <?php foreach ($camp_list as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $campaignId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500"><i class="fa-solid fa-chevron-down"></i></div>
            </div>
        </div>
        <?php if ($campaignId > 0 && count($participants) > 0): ?>
        <a href="?campaign_id=<?= $campaignId ?>&export=excel" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2.5 rounded-xl font-medium transition-colors text-sm shadow-sm flex items-center justify-center gap-2 h-[46px] whitespace-nowrap">
            <i class="fa-solid fa-file-excel text-lg"></i> Export Excel
        </a>
        <?php endif; ?>
    </form>
</div>

<?php if ($campaignId > 0): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex flex-col">
            <div class="text-gray-500 text-xs font-bold uppercase tracking-widest mb-1"><i class="fa-solid fa-users mr-1"></i> จองทั้งหมด</div>
            <div class="text-3xl font-bold text-gray-900 mt-auto"><?= number_format($stats['total']) ?> <span class="text-sm font-medium text-gray-500">คน</span></div>
        </div>
        <div class="bg-blue-50 p-5 rounded-2xl shadow-sm border border-blue-100 flex flex-col">
            <div class="text-blue-600 text-xs font-bold uppercase tracking-widest mb-1"><i class="fa-solid fa-check-double mr-1"></i> มาเข้าร่วมแล้ว</div>
            <div class="text-3xl font-bold text-blue-700 mt-auto"><?= number_format($stats['attended']) ?> <span class="text-sm font-medium text-blue-500">คน</span></div>
        </div>
        <div class="bg-red-50 p-5 rounded-2xl shadow-sm border border-red-100 flex flex-col">
            <div class="text-red-600 text-xs font-bold uppercase tracking-widest mb-1"><i class="fa-solid fa-user-xmark mr-1"></i> ขาด / ไม่มาตามนัด</div>
            <div class="text-3xl font-bold text-red-700 mt-auto"><?= number_format($stats['absent']) ?> <span class="text-sm font-medium text-red-500">คน</span></div>
        </div>
        <div class="bg-gray-50 p-5 rounded-2xl shadow-sm border border-gray-200 flex flex-col">
            <div class="text-gray-600 text-xs font-bold uppercase tracking-widest mb-1"><i class="fa-regular fa-clock mr-1"></i> รอดำเนินการ / ยกเลิก</div>
            <div class="text-xl font-bold text-gray-700 mt-auto">
                <span title="ยังไม่ถึงวันจัดงาน" class="cursor-help"><i class="fa-solid fa-hourglass-half text-yellow-500 text-sm"></i> <?= number_format($stats['upcoming']) ?></span> 
                <span class="mx-2 text-gray-300">|</span> 
                <span title="ยกเลิกคิวแล้ว" class="cursor-help"><i class="fa-solid fa-ban text-gray-400 text-sm"></i> <?= number_format($stats['cancelled']) ?></span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-5 border-b border-gray-50 bg-gray-50/50 flex justify-between items-center">
            <h3 class="font-bold text-gray-800">รายชื่อผู้ที่จองคิวในกิจกรรมนี้</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="bg-gray-50 text-gray-600 font-semibold border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">ชื่อ-นามสกุล / รหัส</th>
                        <th class="px-6 py-4">เบอร์โทรศัพท์</th>
                        <th class="px-6 py-4">วันที่ / รอบเวลา</th>
                        <th class="px-6 py-4">เวลาเช็คอิน</th>
                        <th class="px-6 py-4">สถานะการเข้าร่วม</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (count($participants) === 0): ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">ยังไม่มีผู้ลงทะเบียนในกิจกรรมนี้</td></tr>
                    <?php else: ?>
                        <?php foreach ($participants as $p): 
                            $dateStr = date('d/m/Y', strtotime($p['slot_date']));
                            $timeStr = substr($p['start_time'], 0, 5) . ' - ' . substr($p['end_time'], 0, 5);
                            $checkinStr = $p['attended_at'] ? date('H:i น.', strtotime($p['attended_at'])) : '-';
                            
                            $statusBadge = '';
                            if ($p['calc_status'] === 'attended') {
                                $statusBadge = '<span class="px-3 py-1 bg-blue-50 text-blue-600 rounded-full text-xs font-bold border border-blue-100"><i class="fa-solid fa-check-double mr-1"></i> มาเข้าร่วมแล้ว</span>';
                            } elseif ($p['calc_status'] === 'absent') {
                                $statusBadge = '<span class="px-3 py-1 bg-red-50 text-red-600 rounded-full text-xs font-bold border border-red-100"><i class="fa-solid fa-xmark mr-1"></i> ขาด/ไม่มาตามนัด</span>';
                            } elseif ($p['calc_status'] === 'cancelled') {
                                $statusBadge = '<span class="px-3 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-bold"><i class="fa-solid fa-ban mr-1"></i> ยกเลิก</span>';
                            } else {
                                $statusBadge = '<span class="px-3 py-1 bg-yellow-50 text-yellow-600 rounded-full text-xs font-bold border border-yellow-100"><i class="fa-solid fa-hourglass-half mr-1"></i> รอเข้าร่วม</span>';
                            }
                        ?>
                            <tr class="hover:bg-gray-50 transition-colors <?= $p['calc_status'] === 'cancelled' ? 'opacity-50' : '' ?>">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-900"><?= htmlspecialchars($p['full_name']) ?></div>
                                    <div class="text-xs text-[#0052CC] font-medium mt-0.5"><?= htmlspecialchars($p['student_personnel_id'] ?? '—') ?></div>
                                </td>
                                <td class="px-6 py-4 text-gray-600"><?= htmlspecialchars($p['phone_number']) ?></td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-800"><?= $dateStr ?></div>
                                    <div class="text-xs text-gray-500 mt-0.5"><?= $timeStr ?></div>
                                </td>
                                <td class="px-6 py-4 font-medium text-blue-600"><?= $checkinStr ?></td>
                                <td class="px-6 py-4"><?= $statusBadge ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
