<?php
/**
 * Shared booking row renderer — used by bookings.php and ajax_load_more_bookings.php
 * Call: render_booking_rows(array $bookings): string
 */
if (!function_exists('render_booking_rows')) {
    function render_booking_rows(array $bookings): string
    {
        ob_start();
        foreach ($bookings as $b):
?>
<tr class="booking-row group transition-all hover:bg-gray-50/50"
    data-status="<?= $b['status'] ?>"
    data-search="<?= strtolower(($b['full_name'] ?? '') . ' ' . ($b['student_personnel_id'] ?? '') . ' ' . ($b['campaign_title'] ?? '')) ?>"
    data-id="<?= $b['booking_id'] ?>"
    data-details='<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>'>
    <td class="px-3 py-2 text-center">
        <input type="checkbox"
            class="row-checkbox w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
            onchange="updateActionBar()">
    </td>
    <td class="px-3 py-2">
        <div class="font-semibold text-gray-900 text-sm leading-tight"><?= date('d M Y', strtotime($b['slot_date'])) ?></div>
        <div class="text-[11px] text-blue-600 font-bold uppercase tracking-tight">
            <?= substr($b['start_time'], 0, 5) ?>–<?= substr($b['end_time'], 0, 5) ?></div>
    </td>
    <td class="px-3 py-2 cursor-pointer" onclick='openDrawer(this.closest("tr").dataset.details)'>
        <div class="font-bold text-gray-900 group-hover:text-blue-600 tracking-tight transition-colors text-sm leading-tight">
            <?= htmlspecialchars($b['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wide">
            <?= htmlspecialchars($b['student_personnel_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
    </td>
    <td class="px-3 py-2">
        <div class="text-xs font-semibold text-gray-700 max-w-[220px] truncate leading-tight">
            <?= htmlspecialchars($b['campaign_title'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="text-[10px] text-gray-400 font-medium">#<?= $b['campaign_id'] ?></div>
    </td>
    <td class="px-3 py-2 text-center">
        <?php if ($b['status'] === 'booked'): ?>
            <span class="px-2.5 py-1 bg-amber-50 text-amber-600 text-[10px] font-black uppercase rounded-full border border-amber-100 tracking-wider animate-pulse">Pending</span>
        <?php elseif ($b['status'] === 'confirmed'): ?>
            <span class="px-2.5 py-1 bg-emerald-50 text-emerald-600 text-[10px] font-black uppercase rounded-full border border-emerald-100 tracking-wider">Confirmed</span>
        <?php elseif ($b['status'] === 'completed'): ?>
            <span class="px-2.5 py-1 bg-teal-50 text-teal-600 text-[10px] font-black uppercase rounded-full border border-teal-100 tracking-wider">เข้าร่วมแล้ว</span>
        <?php elseif ($b['status'] === 'expired'): ?>
            <span class="px-2.5 py-1 bg-slate-100 text-slate-500 text-[10px] font-black uppercase rounded-full border border-slate-200 tracking-wider" title="ไม่มาตามนัด — ถูกยกเลิกอัตโนมัติ">ไม่มาตามนัด</span>
        <?php elseif (in_array($b['status'], ['cancelled','cancelled_by_admin'], true)): ?>
            <span class="px-2.5 py-1 bg-red-50 text-red-500 text-[10px] font-black uppercase rounded-full border border-red-100 tracking-wider">ยกเลิกแล้ว</span>
        <?php else: ?>
            <span class="px-2.5 py-1 bg-gray-50 text-gray-400 text-[10px] font-black uppercase rounded-full tracking-wider"><?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </td>
    <td class="px-3 py-2 text-center">
        <div class="flex items-center justify-center gap-1.5">
            <?php if ($b['status'] === 'booked'): ?>
                <button onclick="approveOne(<?= $b['booking_id'] ?>)"
                    class="w-8 h-8 bg-blue-600 text-white rounded-lg flex items-center justify-center hover:scale-110 active:scale-95 transition-all shadow-sm shadow-blue-200 text-xs"
                    title="Approve"><i class="fa-solid fa-check"></i></button>
                <button onclick="rejectOne(<?= $b['booking_id'] ?>)"
                    class="w-8 h-8 bg-white border border-gray-200 text-red-500 rounded-lg flex items-center justify-center hover:bg-red-50 hover:text-red-600 hover:scale-110 active:scale-95 transition-all text-xs"
                    title="Reject"><i class="fa-solid fa-xmark"></i></button>
            <?php elseif ($b['status'] === 'confirmed'): ?>
                <button onclick="checkinOne(<?= $b['booking_id'] ?>)"
                    class="px-2.5 py-1.5 bg-[#0052CC] text-white rounded-lg text-[11px] font-black flex items-center gap-1 hover:brightness-110 active:scale-95 transition-all shadow-sm shadow-blue-200 whitespace-nowrap"
                    title="รับเข้าร่วม">
                    <i class="fa-solid fa-user-check"></i> รับเข้าร่วม</button>
                <button onclick="rescheduleOne(<?= $b['booking_id'] ?>)"
                    class="w-8 h-8 bg-orange-50 text-orange-600 border border-orange-100 rounded-lg flex items-center justify-center hover:bg-orange-500 hover:text-white hover:scale-110 active:scale-95 transition-all text-xs"
                    title="แจ้งเลื่อนคิว"><i class="fa-solid fa-clock-rotate-left"></i></button>
                <button onclick='openDrawer(this.closest("tr").dataset.details)'
                    class="text-gray-400 hover:text-blue-600 text-base transition-colors"><i class="fa-solid fa-circle-info"></i></button>
            <?php elseif ($b['status'] === 'completed'): ?>
                <button onclick="cancelAttendanceOne(<?= $b['booking_id'] ?>)"
                    class="px-2.5 py-1.5 bg-rose-50 text-rose-600 border border-rose-100 rounded-lg text-[11px] font-black flex items-center gap-1 hover:bg-rose-500 hover:text-white hover:border-rose-500 active:scale-95 transition-all whitespace-nowrap"
                    title="ยกเลิกการเข้าร่วม">
                    <i class="fa-solid fa-rotate-left"></i> ยกเลิก</button>
                <button onclick='openDrawer(this.closest("tr").dataset.details)'
                    class="text-gray-400 hover:text-blue-600 text-base transition-colors"><i class="fa-solid fa-circle-info"></i></button>
            <?php else: ?>
                <button onclick='openDrawer(this.closest("tr").dataset.details)'
                    class="text-gray-400 hover:text-blue-600 text-base transition-colors"><i class="fa-solid fa-circle-info"></i></button>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php
        endforeach;
        return ob_get_clean();
    }
}
