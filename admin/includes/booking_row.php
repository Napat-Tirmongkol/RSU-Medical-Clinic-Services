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
    <td class="p-6 text-center">
        <input type="checkbox"
            class="row-checkbox w-5 h-5 rounded-md border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
            onchange="updateActionBar()">
    </td>
    <td class="p-6">
        <div class="font-bold text-gray-900"><?= date('d F Y', strtotime($b['slot_date'])) ?></div>
        <div class="text-xs text-blue-600 font-extrabold uppercase mt-1 tracking-tighter">
            <?= substr($b['start_time'], 0, 5) ?> - <?= substr($b['end_time'], 0, 5) ?></div>
    </td>
    <td class="p-6 cursor-pointer" onclick='openDrawer(this.closest("tr").dataset.details)'>
        <div class="font-black text-gray-900 group-hover:text-blue-600 tracking-tight transition-colors">
            <?= htmlspecialchars($b['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">
            <?= htmlspecialchars($b['student_personnel_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
    </td>
    <td class="p-6">
        <div class="text-sm font-bold text-gray-700 max-w-[200px] truncate">
            <?= htmlspecialchars($b['campaign_title'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="text-[10px] text-gray-400 font-medium">CAMPAIGN #<?= $b['campaign_id'] ?></div>
    </td>
    <td class="p-6 text-center">
        <?php if ($b['status'] === 'booked'): ?>
            <span class="px-4 py-1.5 bg-amber-50 text-amber-600 text-[10px] font-black uppercase rounded-full border border-amber-100 tracking-widest animate-pulse">Pending</span>
        <?php elseif ($b['status'] === 'confirmed'): ?>
            <span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 text-[10px] font-black uppercase rounded-full border border-emerald-100 tracking-widest">Confirmed</span>
        <?php elseif ($b['status'] === 'completed'): ?>
            <span class="px-4 py-1.5 bg-teal-50 text-teal-600 text-[10px] font-black uppercase rounded-full border border-teal-100 tracking-widest">เข้าร่วมแล้ว</span>
        <?php else: ?>
            <span class="px-4 py-1.5 bg-gray-50 text-gray-400 text-[10px] font-black uppercase rounded-full tracking-widest"><?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </td>
    <td class="p-6 text-center">
        <div class="flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
            <?php if ($b['status'] === 'booked'): ?>
                <button onclick="approveOne(<?= $b['booking_id'] ?>)"
                    class="w-9 h-9 bg-blue-600 text-white rounded-xl flex items-center justify-center hover:scale-110 active:scale-95 transition-all shadow-md shadow-blue-200"
                    title="Approve"><i class="fa-solid fa-check"></i></button>
                <button onclick="rejectOne(<?= $b['booking_id'] ?>)"
                    class="w-9 h-9 bg-white border border-gray-100 text-red-500 rounded-xl flex items-center justify-center hover:bg-red-50 hover:text-red-600 hover:scale-110 active:scale-95 transition-all"
                    title="Reject"><i class="fa-solid fa-xmark"></i></button>
            <?php elseif ($b['status'] === 'confirmed'): ?>
                <button onclick="checkinOne(<?= $b['booking_id'] ?>)"
                    class="px-3 py-1.5 bg-[#0052CC] text-white rounded-xl text-[11px] font-black flex items-center gap-1.5 hover:brightness-110 active:scale-95 transition-all shadow-md shadow-blue-200 whitespace-nowrap">
                    <i class="fa-solid fa-user-check"></i> รับเข้าร่วม</button>
                <button onclick="rescheduleOne(<?= $b['booking_id'] ?>)"
                    class="w-9 h-9 bg-orange-50 text-orange-600 border border-orange-100 rounded-xl flex items-center justify-center hover:bg-orange-500 hover:text-white hover:scale-110 active:scale-95 transition-all shadow-sm"
                    title="แจ้งเลื่อนคิว"><i class="fa-solid fa-clock-rotate-left"></i></button>
                <button onclick='openDrawer(this.closest("tr").dataset.details)'
                    class="text-gray-400 hover:text-blue-600 text-lg transition-colors ml-1"><i class="fa-solid fa-circle-info"></i></button>
            <?php else: ?>
                <button onclick='openDrawer(this.closest("tr").dataset.details)'
                    class="text-gray-400 hover:text-blue-600 text-lg transition-colors"><i class="fa-solid fa-circle-info"></i></button>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php
        endforeach;
        return ob_get_clean();
    }
}
