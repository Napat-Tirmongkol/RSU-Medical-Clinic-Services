<?php
// Sub-view: Clinic Rooms / Locations
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(150) NOT NULL,
        type ENUM('exam','vaccination','lab','consult','other') NOT NULL DEFAULT 'exam',
        capacity INT NOT NULL DEFAULT 1,
        floor VARCHAR(20) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_code (code), INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

$search = trim($_GET['s'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$where  = 'WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (name LIKE ? OR code LIKE ?)';
    $params = ["%$search%", "%$search%"];
}

$total = 0; $rows = [];
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_clinic_rooms $where");
    $sc->execute($params);
    $total = (int)$sc->fetchColumn();
    $sr = $pdo->prepare("SELECT * FROM sys_clinic_rooms $where ORDER BY type, code ASC LIMIT $limit OFFSET $offset");
    $sr->execute($params);
    $rows = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$totalPages = max(1, (int)ceil($total / $limit));
$totalAll = (int)$pdo->query("SELECT COUNT(*) FROM sys_clinic_rooms")->fetchColumn();

$typeLabels = ['exam'=>'ห้องตรวจ','vaccination'=>'จุดฉีดวัคซีน','lab'=>'ห้องแล็บ','consult'=>'ห้องปรึกษา','other'=>'อื่นๆ'];
$typeColors = ['exam'=>'emerald','vaccination'=>'blue','lab'=>'purple','consult'=>'amber','other'=>'slate'];
?>
<div class="max-w-[1100px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-amber-50 rounded-xl shadow-sm border border-amber-100 flex items-center justify-center text-amber-600 text-xl">
            <i class="fa-solid fa-door-open"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ห้อง / พื้นที่</h2>
            <p class="text-slate-500 text-sm font-medium">ห้องตรวจ จุดฉีดวัคซีน ห้องแล็บ — ใช้กำหนด slot การจอง</p>
        </div>
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-50 border border-amber-100 text-amber-700 text-[10px] font-black uppercase tracking-widest">
            <?= $totalAll ?> ห้อง
        </span>
    </div>

    <form id="rm-add" onsubmit="rmAdd(event)" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 mb-6">
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-4">เพิ่มห้องใหม่</p>
        <div class="grid grid-cols-2 md:grid-cols-12 gap-3 mb-3">
            <input type="text" name="code" placeholder="รหัส *" required
                class="md:col-span-2 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-amber-400 focus:bg-white">
            <input type="text" name="name" placeholder="ชื่อห้อง *" required
                class="md:col-span-4 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-amber-400 focus:bg-white">
            <select name="type" class="md:col-span-2 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-amber-400 focus:bg-white">
                <?php foreach ($typeLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
            </select>
            <input type="number" name="capacity" min="1" value="1" placeholder="ความจุ" title="ความจุ (คน)"
                class="md:col-span-1 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-amber-400 focus:bg-white">
            <input type="text" name="floor" placeholder="ชั้น" title="ชั้น (ไม่บังคับ)"
                class="md:col-span-1 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-amber-400 focus:bg-white">
            <button type="submit" class="md:col-span-2 px-4 py-2.5 bg-amber-500 text-white rounded-xl text-sm font-black hover:bg-amber-600 transition-all flex items-center justify-center gap-2">
                <i class="fa-solid fa-plus"></i>เพิ่ม
            </button>
        </div>
        <textarea name="notes" rows="2" placeholder="รายละเอียดเพิ่มเติม (ไม่บังคับ) — เช่น อุปกรณ์ที่มี · ตำแหน่งที่ตั้ง · เบอร์โทรภายใน"
            class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium text-slate-700 outline-none focus:border-amber-400 focus:bg-white resize-none"></textarea>
    </form>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center gap-3">
            <form method="GET" class="flex-1 flex items-center gap-2">
                <input type="hidden" name="section" value="clinic_data">
                <input type="hidden" name="cd_view" value="rooms">
                <i class="fa-solid fa-magnifying-glass text-slate-400 text-sm"></i>
                <input type="search" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อ / รหัสห้อง"
                    class="flex-1 bg-transparent text-sm font-bold text-slate-700 outline-none placeholder:text-slate-300">
                <?php if ($search !== ''): ?><a href="?section=clinic_data&cd_view=rooms" class="text-xs text-slate-400"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
            </form>
            <p class="text-[11px] font-black text-slate-400">หน้า <?= $page ?>/<?= $totalPages ?> · รวม <?= $total ?></p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left">รหัส</th>
                        <th class="px-5 py-3 text-left">ชื่อ / รายละเอียด</th>
                        <th class="px-5 py-3 text-left">ประเภท</th>
                        <th class="px-5 py-3 text-center">ความจุ</th>
                        <th class="px-5 py-3 text-left">ชั้น</th>
                        <th class="px-5 py-3 text-center">สถานะ</th>
                        <th class="px-5 py-3 text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400 font-bold text-sm">— ยังไม่มีข้อมูล —</td></tr>
                    <?php else: foreach ($rows as $r):
                        $color = $typeColors[$r['type']] ?? 'slate';
                        $rowJson = htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                    ?>
                        <tr class="hover:bg-slate-50/60" id="room-row-<?= (int)$r['id'] ?>">
                            <td class="px-5 py-3 font-mono font-black text-slate-700 text-xs align-top"><?= htmlspecialchars($r['code']) ?></td>
                            <td class="px-5 py-3 align-top">
                                <div class="font-black text-slate-800"><?= htmlspecialchars($r['name']) ?></div>
                                <?php if (!empty($r['notes'])): ?>
                                <div class="mt-1 text-[11px] text-slate-500 font-medium line-clamp-2 max-w-md" title="<?= htmlspecialchars($r['notes']) ?>">
                                    <i class="fa-regular fa-note-sticky text-slate-300 mr-1"></i><?= htmlspecialchars($r['notes']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 align-top"><span class="inline-flex px-2.5 py-0.5 rounded-full bg-<?= $color ?>-50 text-<?= $color ?>-700 border border-<?= $color ?>-100 text-[10px] font-black"><?= htmlspecialchars($typeLabels[$r['type']] ?? $r['type']) ?></span></td>
                            <td class="px-5 py-3 text-center font-black text-slate-700 align-top"><?= (int)$r['capacity'] ?></td>
                            <td class="px-5 py-3 text-xs font-bold text-slate-500 align-top"><?= htmlspecialchars($r['floor'] ?: '-') ?></td>
                            <td class="px-5 py-3 text-center align-top">
                                <button onclick="rmToggle(<?= (int)$r['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $r['is_active'] ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $r['is_active'] ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                    <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </td>
                            <td class="px-5 py-3 text-right align-top whitespace-nowrap">
                                <button onclick='rmEdit(<?= $rowJson ?>)'
                                    class="text-blue-500 hover:bg-blue-50 px-2 py-1 rounded text-xs" title="แก้ไข"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button onclick="rmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>')"
                                    class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="px-4 py-3 border-t border-slate-100 flex justify-center gap-1">
            <?php
            $btn = function($label, $target, $active=false, $disabled=false) use ($search) {
                $base = 'min-w-9 h-8 px-3 rounded-lg text-xs font-black flex items-center justify-center transition-all';
                if ($active) return "<span class='$base bg-amber-500 text-white'>$label</span>";
                if ($disabled) return "<span class='$base bg-slate-50 text-slate-300'>$label</span>";
                $qs = http_build_query(['section'=>'clinic_data','cd_view'=>'rooms','p'=>$target,'s'=>$search]);
                return "<a href='?$qs' class='$base bg-white border border-slate-200 text-slate-500 hover:border-amber-500 hover:text-amber-500'>$label</a>";
            };
            echo $btn('&laquo;',1,false,$page===1);
            echo $btn('&lsaquo;',max(1,$page-1),false,$page===1);
            for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++) echo $btn((string)$i,$i,$i===$page);
            echo $btn('&rsaquo;',min($totalPages,$page+1),false,$page===$totalPages);
            echo $btn('&raquo;',$totalPages,false,$page===$totalPages);
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal — teleported to body via rmEdit() to escape stacking context -->
<div id="rmEditModal" class="hidden fixed inset-0 items-center justify-center" style="background:rgba(15,23,42,.55);backdrop-filter:blur(6px);z-index:9000">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl mx-4 overflow-hidden" style="max-height:90vh;display:flex;flex-direction:column">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600">
                <i class="fa-solid fa-pen-to-square"></i>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-black text-slate-800">แก้ไขห้อง / พื้นที่</h3>
                <p class="text-[11px] text-slate-400 font-medium">เปลี่ยนแปลงจะมีผลทันทีต่อแคมเปญและรอบเวลาที่อ้างถึง</p>
            </div>
            <button type="button" onclick="rmCloseEdit()" class="w-9 h-9 rounded-full hover:bg-slate-100 text-slate-400 text-sm flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="rm-edit-form" onsubmit="rmUpdate(event)" class="p-6 space-y-4 overflow-y-auto" style="min-height:0">
            <input type="hidden" name="id" id="rm-edit-id">
            <div class="grid grid-cols-2 md:grid-cols-12 gap-3">
                <div class="md:col-span-3">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">รหัส *</label>
                    <input type="text" name="code" id="rm-edit-code" required
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-blue-400 focus:bg-white">
                </div>
                <div class="md:col-span-9">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">ชื่อห้อง *</label>
                    <input type="text" name="name" id="rm-edit-name" required
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-blue-400 focus:bg-white">
                </div>
                <div class="md:col-span-6">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">ประเภท</label>
                    <select name="type" id="rm-edit-type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-blue-400 focus:bg-white">
                        <?php foreach ($typeLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">ความจุ (คน)</label>
                    <input type="number" name="capacity" id="rm-edit-capacity" min="1" value="1"
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-blue-400 focus:bg-white">
                </div>
                <div class="md:col-span-3">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">ชั้น</label>
                    <input type="text" name="floor" id="rm-edit-floor" placeholder="เช่น 2"
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-blue-400 focus:bg-white">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-1.5">รายละเอียดเพิ่มเติม</label>
                <textarea name="notes" id="rm-edit-notes" rows="4"
                    placeholder="อุปกรณ์ที่มี · ตำแหน่งที่ตั้ง · เบอร์โทรภายใน · เวลาให้บริการ (ถ้าต่างจากคลินิคหลัก)"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium text-slate-700 outline-none focus:border-blue-400 focus:bg-white resize-none"></textarea>
            </div>
        </form>
        <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-2">
            <button type="button" onclick="rmCloseEdit()" class="px-4 py-2 bg-white border border-slate-200 text-slate-600 rounded-xl text-sm font-bold hover:bg-slate-50">ยกเลิก</button>
            <button type="button" onclick="document.getElementById('rm-edit-form').requestSubmit()" class="px-5 py-2 bg-blue-600 text-white rounded-xl text-sm font-black hover:bg-blue-700 flex items-center gap-2">
                <i class="fa-solid fa-save"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<script>
function cdReload(view) {
    const url = new URL(window.location.origin + window.location.pathname + window.location.search);
    url.searchParams.set('section', 'clinic_data');
    url.searchParams.set('cd_view', view);
    window.location.assign(url.toString());
}

async function rmPost(action, data) {
    const fd = new FormData();
    fd.append('entity','rooms'); fd.append('action',action); fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    const r = await fetch('ajax_clinic_master.php', {method:'POST',body:fd});
    return r.json();
}
async function rmAdd(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await rmPost('add', Object.fromEntries(fd.entries()));
    if (res.ok) { showPortalToast(res.message, 'success'); setTimeout(()=>cdReload('rooms'),600); }
    else Swal.fire('Error', res.message, 'error');
}
async function rmDelete(id, name) {
    const c = await Swal.fire({title:'ยืนยันการลบ?', text:name, icon:'warning', showCancelButton:true, confirmButtonColor:'#e11d48'});
    if (!c.isConfirmed) return;
    const res = await rmPost('delete', {id});
    if (res.ok) { document.getElementById('room-row-'+id)?.remove(); showPortalToast('ลบแล้ว','success'); }
    else Swal.fire('Error', res.message, 'error');
}
async function rmToggle(id) {
    const res = await rmPost('toggle', {id});
    if (res.ok) cdReload('rooms');
}

// ── Edit modal — teleport to body to escape stacking context ──────
function rmEditTeleport() {
    const el = document.getElementById('rmEditModal');
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
    return el;
}
function rmEdit(row) {
    const m = rmEditTeleport();
    document.getElementById('rm-edit-id').value       = row.id;
    document.getElementById('rm-edit-code').value     = row.code     || '';
    document.getElementById('rm-edit-name').value     = row.name     || '';
    document.getElementById('rm-edit-type').value     = row.type     || 'exam';
    document.getElementById('rm-edit-capacity').value = row.capacity || 1;
    document.getElementById('rm-edit-floor').value    = row.floor    || '';
    document.getElementById('rm-edit-notes').value    = row.notes    || '';
    m.classList.remove('hidden');
    m.classList.add('flex');
}
function rmCloseEdit() {
    const m = document.getElementById('rmEditModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
async function rmUpdate(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await rmPost('update', Object.fromEntries(fd.entries()));
    if (res.ok) { showPortalToast(res.message, 'success'); setTimeout(()=>cdReload('rooms'),600); }
    else Swal.fire('Error', res.message, 'error');
}
// Click backdrop or Escape to close
document.addEventListener('click', e => {
    if (e.target.id === 'rmEditModal') rmCloseEdit();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') rmCloseEdit();
});
</script>
