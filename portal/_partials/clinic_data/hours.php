<?php
// Sub-view: Operating hours & holidays
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('regular','holiday','special') NOT NULL DEFAULT 'regular',
        weekday TINYINT NULL COMMENT '0=Sun..6=Sat',
        specific_date DATE NULL,
        open_time TIME NULL,
        close_time TIME NULL,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type), INDEX idx_date (specific_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

$today = date('Y-m-d');

$regular = [];
$holidays = [];
try {
    $regular  = $pdo->query("SELECT * FROM sys_clinic_hours WHERE type='regular' ORDER BY weekday ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt = $pdo->prepare("SELECT * FROM sys_clinic_hours WHERE type IN ('holiday','special') AND (specific_date IS NULL OR specific_date >= :today) ORDER BY specific_date ASC LIMIT 50");
    $stmt->execute([':today' => $today]);
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException) {}

$weekdayNames = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];

// Build by-weekday lookup
$regularMap = [];
foreach ($regular as $r) {
    $regularMap[(int)$r['weekday']][] = $r;
}
?>
<div class="max-w-[1100px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-purple-50 rounded-xl shadow-sm border border-purple-100 flex items-center justify-center text-purple-600 text-xl">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">วันหยุด / ชั่วโมงทำการ</h2>
            <p class="text-slate-500 text-sm font-medium">กำหนดเวลาเปิด-ปิดประจำสัปดาห์ และวันหยุดพิเศษ — ใช้ตรวจสอบเวลาจอง</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Regular weekly hours -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider"><i class="fa-solid fa-repeat text-purple-500 mr-2"></i>เวลาทำการประจำสัปดาห์</h3>
                <span class="text-[10px] font-bold text-slate-400"><?= count($regular) ?> รายการ</span>
            </div>
            <div class="p-5">
                <form id="hr-add-reg" onsubmit="hrAdd(event,'regular')" class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4 pb-4 border-b border-slate-100">
                    <input type="hidden" name="type" value="regular">
                    <select name="weekday" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                        <?php foreach ($weekdayNames as $i=>$n): ?><option value="<?= $i ?>"><?= $n ?></option><?php endforeach; ?>
                    </select>
                    <input type="time" name="open_time" value="08:00" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <input type="time" name="close_time" value="17:00" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <input type="text" name="note" placeholder="หมายเหตุ" class="md:col-span-1 px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <button type="submit" class="px-3 py-2 bg-purple-500 text-white rounded-lg text-xs font-black hover:bg-purple-600 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-plus"></i>เพิ่ม
                    </button>
                </form>

                <?php for ($i = 0; $i < 7; $i++): ?>
                    <div class="flex items-center gap-3 py-2 <?= $i < 6 ? 'border-b border-slate-50' : '' ?>">
                        <span class="w-20 text-sm font-black text-slate-700"><?= $weekdayNames[$i] ?></span>
                        <?php if (empty($regularMap[$i])): ?>
                            <span class="text-xs font-bold text-slate-300 italic">ไม่ได้ตั้งค่า</span>
                        <?php else: foreach ($regularMap[$i] as $r): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 text-[11px] font-black">
                                <i class="fa-solid fa-clock text-[8px]"></i>
                                <?= substr($r['open_time'],0,5) ?>–<?= substr($r['close_time'],0,5) ?>
                                <?php if ($r['note']): ?> · <?= htmlspecialchars($r['note']) ?><?php endif; ?>
                                <button onclick="hrDelete(<?= (int)$r['id'] ?>)" class="text-rose-500 ml-1 hover:bg-rose-50 rounded px-1"><i class="fa-solid fa-xmark text-[8px]"></i></button>
                            </span>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Holidays / special dates -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider"><i class="fa-solid fa-calendar-xmark text-rose-500 mr-2"></i>วันหยุดพิเศษ</h3>
                <span class="text-[10px] font-bold text-slate-400"><?= count($holidays) ?> รายการที่จะถึง</span>
            </div>
            <div class="p-5">
                <form id="hr-add-hol" onsubmit="hrAdd(event,'holiday')" class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4 pb-4 border-b border-slate-100">
                    <input type="hidden" name="type" value="holiday">
                    <input type="hidden" name="is_closed" value="1">
                    <input type="date" name="specific_date" required min="<?= $today ?>" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <input type="text" name="note" placeholder="ชื่อวันหยุด เช่น สงกรานต์" required class="md:col-span-3 px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <button type="submit" class="px-3 py-2 bg-rose-500 text-white rounded-lg text-xs font-black hover:bg-rose-600 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-plus"></i>เพิ่ม
                    </button>
                </form>

                <?php if (empty($holidays)): ?>
                    <p class="py-8 text-center text-xs font-bold text-slate-300 italic">ยังไม่มีวันหยุดที่จะถึง</p>
                <?php else: foreach ($holidays as $h): ?>
                    <div class="flex items-center justify-between py-2.5 border-b border-slate-50 last:border-0">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs font-black shrink-0">
                                <?= date('d', strtotime($h['specific_date'])) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-black text-slate-800 truncate"><?= htmlspecialchars($h['note'] ?: '—') ?></p>
                                <p class="text-[10px] font-bold text-slate-400">
                                    <?= date('d M Y', strtotime($h['specific_date'])) ?>
                                    <?php if (!$h['is_closed']): ?>
                                        · <?= substr($h['open_time'],0,5) ?>–<?= substr($h['close_time'],0,5) ?>
                                    <?php else: ?> · ปิดทั้งวัน
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <button onclick="hrDelete(<?= (int)$h['id'] ?>)" class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs"><i class="fa-solid fa-trash"></i></button>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
async function hrPost(action, data) {
    const fd = new FormData();
    fd.append('entity','hours'); fd.append('action',action); fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    const r = await fetch('ajax_clinic_master.php', {method:'POST',body:fd});
    return r.json();
}
async function hrAdd(e, type) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await hrPost('add', Object.fromEntries(fd.entries()));
    if (res.ok) { showPortalToast(res.message, 'success'); setTimeout(()=>window.location.href = window.location.href,500); }
    else Swal.fire('Error', res.message, 'error');
}
async function hrDelete(id) {
    const c = await Swal.fire({title:'ลบรายการนี้?', icon:'warning', showCancelButton:true, confirmButtonColor:'#e11d48'});
    if (!c.isConfirmed) return;
    const res = await hrPost('delete', {id});
    if (res.ok) window.location.href = window.location.href;
    else Swal.fire('Error', res.message, 'error');
}
</script>
