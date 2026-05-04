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
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            <input type="text" name="code" placeholder="รหัส *" required
                class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <input type="text" name="name" placeholder="ชื่อห้อง *" required
                class="md:col-span-2 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <select name="type" class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                <?php foreach ($typeLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
            </select>
            <input type="number" name="capacity" min="1" value="1" placeholder="ความจุ"
                class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <button type="submit" class="px-4 py-2.5 bg-amber-500 text-white rounded-xl text-sm font-black hover:bg-amber-600 transition-all flex items-center justify-center gap-2">
                <i class="fa-solid fa-plus"></i>เพิ่ม
            </button>
        </div>
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
                        <th class="px-5 py-3 text-left">ชื่อห้อง</th>
                        <th class="px-5 py-3 text-left">ประเภท</th>
                        <th class="px-5 py-3 text-center">ความจุ</th>
                        <th class="px-5 py-3 text-left">ชั้น</th>
                        <th class="px-5 py-3 text-center">สถานะ</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400 font-bold text-sm">— ยังไม่มีข้อมูล —</td></tr>
                    <?php else: foreach ($rows as $r):
                        $color = $typeColors[$r['type']] ?? 'slate';
                    ?>
                        <tr class="hover:bg-slate-50/60" id="room-row-<?= (int)$r['id'] ?>">
                            <td class="px-5 py-3 font-mono font-black text-slate-700 text-xs"><?= htmlspecialchars($r['code']) ?></td>
                            <td class="px-5 py-3 font-black text-slate-800"><?= htmlspecialchars($r['name']) ?></td>
                            <td class="px-5 py-3"><span class="inline-flex px-2.5 py-0.5 rounded-full bg-<?= $color ?>-50 text-<?= $color ?>-700 border border-<?= $color ?>-100 text-[10px] font-black"><?= htmlspecialchars($typeLabels[$r['type']] ?? $r['type']) ?></span></td>
                            <td class="px-5 py-3 text-center font-black text-slate-700"><?= (int)$r['capacity'] ?></td>
                            <td class="px-5 py-3 text-xs font-bold text-slate-500"><?= htmlspecialchars($r['floor'] ?: '-') ?></td>
                            <td class="px-5 py-3 text-center">
                                <button onclick="rmToggle(<?= (int)$r['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $r['is_active'] ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $r['is_active'] ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                    <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button onclick="rmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>')"
                                    class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs"><i class="fa-solid fa-trash"></i></button>
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

<script>
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
    if (res.ok) { showPortalToast(res.message, 'success'); setTimeout(()=>window.location.href = window.location.href,600); }
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
    if (res.ok) window.location.href = window.location.href;
}
</script>
