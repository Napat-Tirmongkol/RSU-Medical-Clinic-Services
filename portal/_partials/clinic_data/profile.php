<?php
// Sub-view: Clinic Profile (singleton)
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_profile (
        id INT PRIMARY KEY DEFAULT 1,
        name_th VARCHAR(255) NOT NULL DEFAULT '',
        name_en VARCHAR(255) NOT NULL DEFAULT '',
        address_th TEXT NULL,
        address_en TEXT NULL,
        phone VARCHAR(50) NOT NULL DEFAULT '',
        email VARCHAR(150) NOT NULL DEFAULT '',
        line_id VARCHAR(100) NOT NULL DEFAULT '',
        facebook VARCHAR(255) NOT NULL DEFAULT '',
        license_no VARCHAR(100) NOT NULL DEFAULT '',
        operating_hours TEXT NULL,
        notes TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

$row = $pdo->query("SELECT * FROM sys_clinic_profile WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$v = fn(string $k, string $default = '') => htmlspecialchars((string)($row[$k] ?? $default));
?>
<div class="max-w-[900px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-emerald-50 rounded-xl shadow-sm border border-emerald-100 flex items-center justify-center text-emerald-600 text-xl">
            <i class="fa-solid fa-hospital"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ข้อมูลคลินิก</h2>
            <p class="text-slate-500 text-sm font-medium">ที่อยู่ ช่องทางติดต่อ ใบอนุญาต — แสดงในระบบทุกจุดที่อ้างอิงข้อมูลคลินิก</p>
        </div>
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-widest">
            <i class="fa-solid fa-share-nodes text-[8px]"></i>Used by 6 modules
        </span>
    </div>

    <form id="cp-form" onsubmit="cpSave(event)" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 space-y-5">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">ชื่อคลินิก (TH) *</span>
                <input type="text" name="name_th" value="<?= $v('name_th') ?>" required
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Clinic Name (EN)</span>
                <input type="text" name="name_en" value="<?= $v('name_en') ?>"
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">ที่อยู่ (TH)</span>
                <textarea name="address_th" rows="3" class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 resize-none"><?= $v('address_th') ?></textarea>
            </label>
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Address (EN)</span>
                <textarea name="address_en" rows="3" class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 resize-none"><?= $v('address_en') ?></textarea>
            </label>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400"><i class="fa-solid fa-phone mr-1"></i>เบอร์โทร</span>
                <input type="tel" name="phone" value="<?= $v('phone') ?>"
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400"><i class="fa-solid fa-envelope mr-1"></i>อีเมล</span>
                <input type="email" name="email" value="<?= $v('email') ?>"
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400"><i class="fa-brands fa-line text-green-500 mr-1"></i>LINE ID</span>
                <input type="text" name="line_id" value="<?= $v('line_id') ?>"
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400"><i class="fa-brands fa-facebook text-blue-500 mr-1"></i>Facebook URL</span>
                <input type="url" name="facebook" value="<?= $v('facebook') ?>" placeholder="https://facebook.com/..."
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
            <label class="block">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">เลขที่ใบอนุญาต</span>
                <input type="text" name="license_no" value="<?= $v('license_no') ?>"
                    class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            </label>
        </div>

        <label class="block">
            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">เวลาทำการ (สรุปสั้น)</span>
            <input type="text" name="operating_hours" value="<?= $v('operating_hours') ?>" placeholder="จันทร์-ศุกร์ 08:00-17:00 น."
                class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
            <span class="text-[10px] text-slate-400 font-medium mt-1 block">รายละเอียดวันหยุด/ชั่วโมงทำการ จัดการได้ในหน้า "วันหยุด/ชั่วโมงทำการ"</span>
        </label>

        <label class="block">
            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">บันทึกเพิ่มเติม</span>
            <textarea name="notes" rows="2" class="mt-1 w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 resize-none"><?= $v('notes') ?></textarea>
        </label>

        <div class="flex justify-between items-center pt-2 border-t border-slate-100">
            <span class="text-[10px] font-bold text-slate-400">
                <?php if (!empty($row['updated_at'])): ?>
                    อัปเดตล่าสุด: <?= date('d M Y H:i', strtotime($row['updated_at'])) ?>
                <?php else: ?>
                    ยังไม่เคยบันทึก
                <?php endif; ?>
            </span>
            <button type="submit" class="px-6 py-2.5 bg-emerald-500 text-white rounded-xl text-sm font-black hover:bg-emerald-600 shadow-lg shadow-emerald-100 transition-all flex items-center gap-2">
                <i class="fa-solid fa-save"></i>บันทึกข้อมูล
            </button>
        </div>
    </form>
</div>

<script>
async function cpSave(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('entity', 'profile');
    fd.append('action', 'save');
    fd.append('csrf_token', portal_CSRF);
    try {
        const res = await fetch('ajax_clinic_master.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) showPortalToast(data.message, 'success');
        else Swal.fire('Error', data.message || 'บันทึกไม่สำเร็จ', 'error');
    } catch (err) {
        Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
    }
}
</script>
