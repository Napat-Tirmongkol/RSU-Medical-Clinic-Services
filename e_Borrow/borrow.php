<?php
// e_Borrow/borrow.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

require_once __DIR__ . '/includes/db_connect.php';

$student_id = (int)$_SESSION['student_id'];

try {
    $pdo = db();
    $sql = "SELECT id, name, description, image_url, available_quantity
            FROM borrow_categories
            WHERE available_quantity > 0
            ORDER BY name ASC";
    $stmt_equip = $pdo->query($sql);
    $equipment_types = $stmt_equip->fetchAll();
} catch (PDOException $e) {
    $equipment_types = [];
    $equip_error = "เกิดข้อผิดพลาด: " . $e->getMessage();
}

$page_title  = "ยืมอุปกรณ์";
$active_page = 'borrow';
include('includes/student_header.php');
?>

<!-- ── Search ── -->
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm flex items-center gap-3 px-5 h-14 mb-5">
    <i class="fa-solid fa-magnifying-glass text-slate-400"></i>
    <input type="text" id="liveSearchInput" placeholder="ค้นหาชื่ออุปกรณ์..."
        class="flex-1 bg-transparent border-0 outline-none text-sm font-bold text-slate-700 placeholder:text-slate-300">
    <button type="button" id="clearSearchBtn" class="hidden w-7 h-7 rounded-full bg-slate-100 text-slate-400 items-center justify-center text-xs">
        <i class="fa-solid fa-xmark"></i>
    </button>
</div>

<?php if (!empty($equip_error)): ?>
<div class="rounded-2xl bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 text-sm font-bold mb-4 flex items-center gap-2">
    <i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($equip_error) ?>
</div>
<?php endif; ?>

<?php if (empty($equipment_types)): ?>
<div class="bg-white rounded-[2rem] p-10 border border-slate-100 shadow-sm text-center">
    <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-slate-50 text-slate-400 flex items-center justify-center text-2xl">
        <i class="fa-solid fa-box-open"></i>
    </div>
    <p class="text-sm font-black text-slate-700">ไม่มีอุปกรณ์ว่างในขณะนี้</p>
    <p class="text-[11px] font-bold text-slate-400 mt-1">โปรดกลับมาตรวจสอบใหม่ภายหลัง</p>
</div>
<?php else: ?>
<div id="equipment-grid-container" class="grid grid-cols-2 gap-3">
    <?php foreach ($equipment_types as $item): ?>
    <div class="equip-card bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex flex-col" data-name="<?= htmlspecialchars(strtolower($item['name'])) ?>">
        <div class="aspect-[4/3] bg-slate-50 relative flex items-center justify-center overflow-hidden">
            <?php if (!empty($item['image_url'])): ?>
                <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" class="w-full h-full object-cover"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="absolute inset-0 hidden items-center justify-center text-slate-300 text-3xl">
                    <i class="fa-solid fa-image"></i>
                </div>
            <?php else: ?>
                <div class="text-slate-300 text-3xl"><i class="fa-solid fa-camera"></i></div>
            <?php endif; ?>
            <span class="absolute top-2 right-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-white/90 backdrop-blur-sm text-[#2e9e63] text-[10px] font-black shadow-sm">
                <i class="fa-solid fa-check-circle text-[8px]"></i> ว่าง <?= (int)$item['available_quantity'] ?>
            </span>
        </div>
        <div class="p-3 flex-1 flex flex-col">
            <h3 class="text-[13px] font-black text-slate-900 leading-tight line-clamp-2 mb-1"><?= htmlspecialchars($item['name']) ?></h3>
            <p class="text-[11px] font-bold text-slate-400 leading-snug line-clamp-2 mb-3 flex-1"><?= htmlspecialchars($item['description'] ?: 'ไม่มีรายละเอียด') ?></p>
            <button class="h-10 rounded-xl bg-emerald-50 text-[#2e9e63] text-[12px] font-black active:scale-95 transition-all flex items-center justify-center gap-1.5"
                onclick="openRequestPopup(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')">
                <i class="fa-solid fa-hand-holding-medical"></i> ขอยืม
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div id="empty-search-msg" class="hidden bg-white rounded-2xl p-8 border border-slate-100 shadow-sm text-center mt-4">
    <i class="fa-solid fa-magnifying-glass text-slate-300 text-2xl mb-2"></i>
    <p class="text-sm font-bold text-slate-400">ไม่พบอุปกรณ์ที่ตรงกับการค้นหา</p>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('liveSearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    const cards = document.querySelectorAll('.equip-card');
    const emptyMsg = document.getElementById('empty-search-msg');

    function filterCards() {
        const query = input.value.trim().toLowerCase();
        let found = 0;
        clearBtn.classList.toggle('hidden', query.length === 0);
        clearBtn.classList.toggle('flex', query.length > 0);
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const match = name.includes(query);
            card.style.display = match ? 'flex' : 'none';
            if (match) found++;
        });
        if (emptyMsg) emptyMsg.classList.toggle('hidden', found > 0);
    }
    input.addEventListener('input', filterCards);
    clearBtn.addEventListener('click', () => { input.value = ''; filterCards(); input.focus(); });
});
</script>

<?php include('includes/student_footer.php'); ?>
