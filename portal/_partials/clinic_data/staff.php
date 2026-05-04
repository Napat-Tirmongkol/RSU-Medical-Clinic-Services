<?php
// Sub-view: Medical Staff list + add + delete + toggle
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_medical_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(50) NOT NULL DEFAULT '',
        full_name VARCHAR(255) NOT NULL,
        license_no VARCHAR(100) NULL,
        role ENUM('doctor','nurse','pharmacist','dentist','other') NOT NULL DEFAULT 'doctor',
        department VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role (role), INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

$search = trim($_GET['s'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$where  = 'WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (full_name LIKE ? OR department LIKE ? OR license_no LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$total = 0; $rows = [];
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_medical_staff $where");
    $sc->execute($params);
    $total = (int)$sc->fetchColumn();
    $sr = $pdo->prepare("SELECT * FROM sys_medical_staff $where ORDER BY is_active DESC, full_name ASC LIMIT $limit OFFSET $offset");
    $sr->execute($params);
    $rows = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$totalPages = max(1, (int)ceil($total / $limit));
$totalAll   = (int)$pdo->query("SELECT COUNT(*) FROM sys_medical_staff")->fetchColumn();
$activeCnt  = (int)$pdo->query("SELECT COUNT(*) FROM sys_medical_staff WHERE is_active = 1")->fetchColumn();

$roleLabels = ['doctor'=>'แพทย์', 'nurse'=>'พยาบาล', 'pharmacist'=>'เภสัชกร', 'dentist'=>'ทันตแพทย์', 'other'=>'อื่นๆ'];
$titles     = ['นพ.','พญ.','ทพ.','ทญ.','ภก.','ภญ.','พย.','คุณ'];
?>
<div class="max-w-[1100px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-blue-50 rounded-xl shadow-sm border border-blue-100 flex items-center justify-center text-blue-600 text-xl">
            <i class="fa-solid fa-user-doctor"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">บุคลากรการแพทย์</h2>
            <p class="text-slate-500 text-sm font-medium">แพทย์ พยาบาล เภสัชกร ที่ให้บริการในคลินิก</p>
        </div>
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black uppercase tracking-widest">
            <?= $activeCnt ?>/<?= $totalAll ?> active
        </span>
    </div>

    <!-- Add form -->
    <form id="ms-add" onsubmit="msAdd(event)" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 mb-6">
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-4">เพิ่มบุคลากรใหม่</p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
            <select name="title" class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                <option value="">— คำนำหน้า —</option>
                <?php foreach ($titles as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="full_name" placeholder="ชื่อ-นามสกุล *" required
                class="md:col-span-2 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <select name="role" class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                <?php foreach ($roleLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <input type="text" name="license_no" placeholder="เลขใบอนุญาต"
                class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <input type="text" name="department" placeholder="แผนก/หน่วยงาน"
                class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <input type="tel" name="phone" placeholder="เบอร์โทร"
                class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            <button type="submit" class="px-4 py-2.5 bg-blue-500 text-white rounded-xl text-sm font-black hover:bg-blue-600 transition-all flex items-center justify-center gap-2 shadow-sm">
                <i class="fa-solid fa-plus"></i>เพิ่ม
            </button>
        </div>
    </form>

    <!-- Search + List -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center gap-3">
            <form method="GET" class="flex-1 flex items-center gap-2">
                <input type="hidden" name="section" value="clinic_data">
                <input type="hidden" name="cd_view" value="staff">
                <i class="fa-solid fa-magnifying-glass text-slate-400 text-sm"></i>
                <input type="search" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อ / แผนก / เลขใบอนุญาต"
                    class="flex-1 bg-transparent text-sm font-bold text-slate-700 outline-none placeholder:text-slate-300">
                <?php if ($search !== ''): ?>
                    <a href="?section=clinic_data&cd_view=staff" class="text-xs font-black text-slate-400 hover:text-rose-500"><i class="fa-solid fa-xmark"></i></a>
                <?php endif; ?>
            </form>
            <p class="text-[11px] font-black text-slate-400">หน้า <?= $page ?>/<?= $totalPages ?> · รวม <?= $total ?></p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left">ชื่อ-นามสกุล</th>
                        <th class="px-5 py-3 text-left">บทบาท</th>
                        <th class="px-5 py-3 text-left">แผนก</th>
                        <th class="px-5 py-3 text-left">ติดต่อ</th>
                        <th class="px-5 py-3 text-center">สถานะ</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="px-5 py-12 text-center text-slate-400 font-bold text-sm">— ยังไม่มีข้อมูล —</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr class="hover:bg-slate-50/60" id="staff-row-<?= (int)$r['id'] ?>">
                            <td class="px-5 py-3">
                                <div class="font-black text-slate-800"><?= htmlspecialchars(trim($r['title'].' '.$r['full_name'])) ?></div>
                                <?php if ($r['license_no']): ?><div class="text-[10px] font-bold text-slate-400">License: <?= htmlspecialchars($r['license_no']) ?></div><?php endif; ?>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100 text-[10px] font-black"><?= htmlspecialchars($roleLabels[$r['role']] ?? $r['role']) ?></span>
                            </td>
                            <td class="px-5 py-3 text-xs font-bold text-slate-600"><?= htmlspecialchars($r['department'] ?: '-') ?></td>
                            <td class="px-5 py-3 text-[11px] font-bold text-slate-500">
                                <?php if ($r['phone']): ?><div><i class="fa-solid fa-phone text-[8px] mr-1"></i><?= htmlspecialchars($r['phone']) ?></div><?php endif; ?>
                                <?php if ($r['email']): ?><div class="truncate max-w-[180px]"><i class="fa-solid fa-envelope text-[8px] mr-1"></i><?= htmlspecialchars($r['email']) ?></div><?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <button onclick="msToggle(<?= (int)$r['id'] ?>, this)"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $r['is_active'] ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $r['is_active'] ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                    <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button onclick="msDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['full_name']), ENT_QUOTES) ?>')"
                                    class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs font-black"><i class="fa-solid fa-trash"></i></button>
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
                if ($active) return "<span class='$base bg-emerald-500 text-white'>$label</span>";
                if ($disabled) return "<span class='$base bg-slate-50 text-slate-300'>$label</span>";
                $qs = http_build_query(['section'=>'clinic_data','cd_view'=>'staff','p'=>$target,'s'=>$search]);
                return "<a href='?$qs' class='$base bg-white border border-slate-200 text-slate-500 hover:border-emerald-500 hover:text-emerald-500'>$label</a>";
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
function cdReload(view) {
    const url = new URL(window.location.origin + window.location.pathname + window.location.search);
    url.searchParams.set('section', 'clinic_data');
    url.searchParams.set('cd_view', view);
    window.location.assign(url.toString());
}

async function msPost(action, data) {
    const fd = new FormData();
    fd.append('entity', 'staff');
    fd.append('action', action);
    fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    const res = await fetch('ajax_clinic_master.php', { method: 'POST', body: fd });
    return res.json();
}
async function msAdd(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd.entries());
    const res = await msPost('add', data);
    if (res.ok) { showPortalToast(res.message, 'success'); setTimeout(() => cdReload('staff'), 600); }
    else Swal.fire('Error', res.message || 'เพิ่มไม่สำเร็จ', 'error');
}
async function msDelete(id, name) {
    const c = await Swal.fire({title:'ยืนยันการลบ?', text:name, icon:'warning', showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#e11d48'});
    if (!c.isConfirmed) return;
    const res = await msPost('delete', {id});
    if (res.ok) { document.getElementById('staff-row-'+id)?.remove(); showPortalToast('ลบแล้ว', 'success'); }
    else Swal.fire('Error', res.message, 'error');
}
async function msToggle(id, btn) {
    const res = await msPost('toggle', {id});
    if (res.ok) cdReload('staff');
    else Swal.fire('Error', res.message, 'error');
}
</script>
