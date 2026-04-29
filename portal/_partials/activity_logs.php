<?php
// portal/_partials/activity_logs.php — included by portal/index.php
// $pdo is available from parent scope

$_al_page   = max(1, (int)($_GET['page'] ?? 1));
$_al_limit  = 20;
$_al_offset = ($_al_page - 1) * $_al_limit;
$_al_search = trim($_GET['al_q'] ?? '');
$_al_type   = strtolower(trim($_GET['al_type'] ?? ''));
if (!in_array($_al_type, ['admin', 'staff', 'user', 'system'], true)) $_al_type = '';

// Filter by specific user
$_al_user_id = isset($_GET['al_user_id']) ? (int)$_GET['al_user_id'] : 0;
$_al_role    = strtolower(trim($_GET['al_role'] ?? ''));
if (!in_array($_al_role, ['admin', 'staff', 'user'], true)) $_al_role = '';

// Look up the subject (the specific user we're filtering by)
$_al_subject = null;
if ($_al_user_id > 0 && $_al_role !== '') {
    try {
        if ($_al_role === 'admin') {
            $st = $pdo->prepare("SELECT id, full_name, username, 'admin' AS role FROM sys_admins WHERE id = ? LIMIT 1");
        } elseif ($_al_role === 'staff') {
            $st = $pdo->prepare("SELECT id, full_name, username, 'staff' AS role FROM sys_staff WHERE id = ? LIMIT 1");
        } else {
            $st = $pdo->prepare("SELECT id, full_name, student_personnel_id AS username, 'user' AS role FROM sys_users WHERE id = ? LIMIT 1");
        }
        $st->execute([$_al_user_id]);
        $_al_subject = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $_al_subject = null;
    }
}

$_al_where  = 'WHERE 1=1';
$_al_params = [];

if ($_al_search !== '') {
    $_al_where   .= ' AND (l.action LIKE ? OR l.description LIKE ?)';
    $_al_params[] = "%$_al_search%";
    $_al_params[] = "%$_al_search%";
}

// Filter by role type (any user with this role)
if ($_al_type === 'admin')  $_al_where .= " AND l.user_id IN (SELECT id FROM sys_admins)";
if ($_al_type === 'staff')  $_al_where .= " AND l.user_id IN (SELECT id FROM sys_staff)";
if ($_al_type === 'user')   $_al_where .= " AND l.user_id IN (SELECT id FROM sys_users)";
if ($_al_type === 'system') $_al_where .= " AND l.user_id IS NULL";

// Filter by specific user (id + role to disambiguate cross-table id collisions)
if ($_al_subject) {
    if ($_al_role === 'admin')      $_al_where .= " AND l.user_id = ? AND l.user_id IN (SELECT id FROM sys_admins)";
    elseif ($_al_role === 'staff')  $_al_where .= " AND l.user_id = ? AND l.user_id IN (SELECT id FROM sys_staff)";
    else                             $_al_where .= " AND l.user_id = ? AND l.user_id IN (SELECT id FROM sys_users)";
    $_al_params[] = $_al_user_id;
}

$_al_logs = [];
$_al_total = 0;
$_al_total_pages = 1;
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_activity_logs l $_al_where");
    $sc->execute($_al_params);
    $_al_total = (int)$sc->fetchColumn();
    $_al_total_pages = max(1, (int)ceil($_al_total / $_al_limit));
    if ($_al_page > $_al_total_pages) {
        $_al_page = $_al_total_pages;
        $_al_offset = ($_al_page - 1) * $_al_limit;
    }

    $sr = $pdo->prepare("
        SELECT l.*,
               COALESCE(a.full_name, s.full_name, u.full_name, 'System Activity') AS actor_name,
               COALESCE(a.username, s.username, u.student_personnel_id, 'system') AS actor_username,
               CASE
                   WHEN l.user_id IS NULL THEN 'system'
                   WHEN EXISTS (SELECT 1 FROM sys_admins WHERE id = l.user_id) THEN 'admin'
                   WHEN EXISTS (SELECT 1 FROM sys_staff  WHERE id = l.user_id) THEN 'staff'
                   WHEN EXISTS (SELECT 1 FROM sys_users  WHERE id = l.user_id) THEN 'user'
                   ELSE 'user'
               END AS actor_type
        FROM sys_activity_logs l
        LEFT JOIN sys_admins a ON l.user_id = a.id
        LEFT JOIN sys_staff s ON l.user_id = s.id
        LEFT JOIN sys_users u ON l.user_id = u.id
        $_al_where
        ORDER BY l.timestamp DESC
        LIMIT $_al_limit OFFSET $_al_offset
    ");
    $sr->execute($_al_params);
    $_al_logs = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_al_db_error = $e->getMessage();
}

// helper: build pagination URL preserving all current filters
$_al_buildUrl = function (int $p, bool $keep_search = true) use ($_al_search, $_al_type, $_al_user_id, $_al_role) {
    $qs = ['section' => 'activity_logs', 'page' => $p];
    if ($keep_search && $_al_search !== '') $qs['al_q']      = $_al_search;
    if ($_al_type !== '')                   $qs['al_type']   = $_al_type;
    if ($_al_user_id > 0)                   $qs['al_user_id'] = $_al_user_id;
    if ($_al_role !== '')                   $qs['al_role']    = $_al_role;
    return '?' . http_build_query($qs);
};
?>

<div class="p-6">
    <!-- Header -->
    <div class="mb-8 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
            <h2 class="text-2xl font-black text-gray-900 flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                Activity Logs
            </h2>
            <p class="text-slate-500 text-sm font-medium mt-1">
                <?= $_al_subject
                    ? 'กำลังแสดงบันทึกกิจกรรมเฉพาะของ ' . htmlspecialchars($_al_subject['full_name'] ?? '-')
                    : 'ติดตามทุกการเคลื่อนไหวและการเข้าถึงระบบอย่างละเอียด' ?>
            </p>
        </div>

        <form method="GET" class="w-full lg:w-auto max-w-2xl">
            <input type="hidden" name="section" value="activity_logs">
            <?php if ($_al_user_id): ?>
                <input type="hidden" name="al_user_id" value="<?= (int)$_al_user_id ?>">
                <input type="hidden" name="al_role" value="<?= htmlspecialchars($_al_role) ?>">
            <?php endif; ?>
            <div class="flex flex-wrap lg:flex-nowrap items-center gap-2 bg-white p-1.5 rounded-2xl border border-slate-100 shadow-sm">
                <!-- Role Filter (hidden when filtering specific user) -->
                <?php if (!$_al_subject): ?>
                <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-xl border border-slate-100 shrink-0">
                    <i class="fa-solid fa-filter text-slate-400 text-[9px]"></i>
                    <select name="al_type" onchange="this.form.submit()" class="bg-transparent text-[11px] font-black text-slate-700 outline-none cursor-pointer">
                        <option value="">ทุกบทบาท</option>
                        <option value="admin" <?= $_al_type === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="staff" <?= $_al_type === 'staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="user"  <?= $_al_type === 'user'  ? 'selected' : '' ?>>User</option>
                        <option value="system" <?= $_al_type === 'system' ? 'selected' : '' ?>>System</option>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Search Input -->
                <div class="relative flex-1 group min-w-[180px]">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] transition-colors group-focus-within:text-blue-500"></i>
                    <input type="text" name="al_q" value="<?= htmlspecialchars($_al_search) ?>"
                        placeholder="ค้นหากิจกรรม, รายละเอียด..."
                        class="w-full pl-8 pr-3 py-2 bg-slate-50 border border-slate-100 rounded-xl text-[11px] font-bold outline-none focus:ring-2 focus:ring-blue-50 transition-all">
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="submit"
                            style="background-color: #000 !important; color: #fff !important; border: 1px solid #000 !important;"
                            class="px-5 py-2 rounded-xl text-[11px] font-black hover:opacity-90 transition-all shadow-sm flex items-center justify-center gap-2">
                        <i class="fa-solid fa-search text-[10px]"></i> ค้นหา
                    </button>

                    <?php if ($_al_search || $_al_type): ?>
                    <a href="<?= htmlspecialchars($_al_buildUrl(1, false)) ?>&al_type="
                       class="w-9 h-9 flex items-center justify-center bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-100 transition-all shadow-sm"
                       title="ล้างตัวกรอง">
                        <i class="fa-solid fa-xmark text-xs"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Subject card (when filtering by specific user) -->
    <?php if ($_al_subject):
        $_al_subjRoleClass = [
            'admin' => 'bg-purple-600 text-white',
            'staff' => 'bg-amber-500 text-white',
            'user'  => 'bg-blue-500 text-white',
        ][$_al_subject['role']] ?? 'bg-slate-400 text-white';
    ?>
    <div class="mb-6 bg-white rounded-2xl border border-slate-100 shadow-sm p-5 flex flex-col md:flex-row md:items-center gap-5">
        <div class="flex-shrink-0 w-14 h-14 rounded-2xl bg-gradient-to-br from-[#0052CC] to-[#0070f3] flex items-center justify-center shadow-md">
            <span class="text-white text-xl font-bold">
                <?= htmlspecialchars(mb_substr($_al_subject['full_name'] ?? '?', 0, 1)) ?>
            </span>
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <h3 class="text-lg font-bold text-slate-900 truncate"><?= htmlspecialchars($_al_subject['full_name'] ?: 'ไม่ระบุชื่อ') ?></h3>
                <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider <?= $_al_subjRoleClass ?>">
                    <?= htmlspecialchars(strtoupper($_al_subject['role'])) ?>
                </span>
            </div>
            <div class="flex flex-wrap gap-4 mt-1 text-sm text-slate-500">
                <span class="font-mono text-xs">@<?= htmlspecialchars($_al_subject['username'] ?: '-') ?></span>
                <span><i class="fa-solid fa-list-check text-xs mr-1 text-slate-400"></i><?= number_format($_al_total) ?> กิจกรรม</span>
            </div>
        </div>
        <a href="?section=activity_logs" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-[#0052CC] transition-colors">
            <i class="fa-solid fa-xmark"></i> ล้างฟิลเตอร์
        </a>
    </div>
    <?php endif; ?>

    <?php if (isset($_al_db_error)): ?>
    <div class="mb-6 p-4 bg-rose-50 border border-rose-100 rounded-2xl text-rose-600 text-xs font-bold flex items-center gap-3">
        <i class="fa-solid fa-triangle-exclamation text-base"></i>
        <span>ระบบฐานข้อมูลขัดข้อง: <?= htmlspecialchars($_al_db_error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-48">วัน-เวลา</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-56">ผู้ดำเนินการ</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-40">กิจกรรม</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em]">รายละเอียด</th>
                        <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] w-32 text-right">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($_al_logs)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-24 text-center">
                            <div class="flex flex-col items-center gap-4">
                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center text-slate-200 text-3xl shadow-inner">
                                    <i class="fa-solid fa-folder-open"></i>
                                </div>
                                <div>
                                    <p class="text-slate-800 font-black text-sm">ไม่พบประวัติกิจกรรม</p>
                                    <p class="text-slate-400 text-xs font-medium mt-1">ลองเปลี่ยนคำค้นหาหรือตัวกรองใหม่</p>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php else: foreach ($_al_logs as $log):
                        // Action Color Logic
                        $ac = 'bg-slate-100 text-slate-600';
                        if (str_contains($log['action'], 'login'))    $ac = 'bg-emerald-50 text-emerald-600 border border-emerald-100';
                        if (str_contains($log['action'], 'delete'))   $ac = 'bg-rose-50 text-rose-600 border border-rose-100';
                        if (str_contains($log['action'], 'update'))   $ac = 'bg-sky-50 text-sky-600 border border-sky-100';
                        if (str_contains($log['action'], 'campaign')) $ac = 'bg-violet-50 text-violet-600 border border-violet-100';

                        // Actor Type Logic
                        $typeLabel = '';
                        $typeClass = '';
                        switch($log['actor_type']) {
                            case 'admin':  $typeLabel = 'ADMIN';   $typeClass = 'bg-purple-600 text-white shadow-purple-100'; break;
                            case 'staff':  $typeLabel = 'STAFF';   $typeClass = 'bg-amber-500 text-white shadow-amber-100'; break;
                            case 'user':   $typeLabel = 'USER';    $typeClass = 'bg-blue-500 text-white shadow-blue-100'; break;
                            default:       $typeLabel = 'SYSTEM';  $typeClass = 'bg-slate-400 text-white shadow-slate-100'; break;
                        }
                    ?>
                    <tr class="hover:bg-slate-50/80 transition-all group">
                        <td class="px-6 py-5">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-slate-50 flex items-center justify-center text-slate-300 group-hover:text-blue-500 transition-colors">
                                    <i class="fa-regular fa-calendar-check text-xs"></i>
                                </div>
                                <div>
                                    <div class="text-[11px] font-black text-slate-800 leading-tight">
                                        <?= date('d M Y', strtotime($log['timestamp'])) ?>
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400">
                                        <?= date('H:i:s', strtotime($log['timestamp'])) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5">
                            <div class="flex items-center gap-3">
                                <div class="relative">
                                    <div class="w-9 h-9 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-sm font-black border-2 border-white shadow-sm overflow-hidden">
                                        <?= strtoupper(substr($log['actor_name'], 0, 1)) ?>
                                    </div>
                                    <span class="absolute -top-1 -right-1 w-3 h-3 border-2 border-white rounded-full <?= $log['actor_type'] === 'system' ? 'bg-slate-400' : 'bg-emerald-500' ?>"></span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <div class="text-sm font-black text-slate-800"><?= htmlspecialchars($log['actor_name']) ?></div>
                                        <span class="text-[9px] font-black px-1.5 py-0.5 rounded shadow-sm <?= $typeClass ?>"><?= $typeLabel ?></span>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-bold mt-0.5">@<?= htmlspecialchars($log['actor_username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5">
                            <span class="inline-block px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider <?= $ac ?>">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-5">
                            <div class="text-[13px] font-medium text-slate-600 line-clamp-2 max-w-xl group-hover:text-slate-900 transition-colors">
                                <?= htmlspecialchars($log['description']) ?>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-right">
                            <code class="text-[10px] bg-slate-50 border border-slate-100 px-2 py-1 rounded-lg text-slate-500 font-mono font-bold group-hover:bg-white transition-all">
                                <?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?>
                            </code>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <?php if ($_al_total > 0): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-col md:flex-row items-center justify-between gap-3">
            <div class="text-xs text-gray-500">
                หน้า <span class="font-bold text-gray-700"><?= $_al_page ?></span> / <?= $_al_total_pages ?>
                · รวม <span class="font-bold text-gray-700"><?= number_format($_al_total) ?></span> รายการ
                · แสดง <?= number_format($_al_offset + 1) ?>–<?= number_format(min($_al_offset + $_al_limit, $_al_total)) ?>
            </div>
            <?php if ($_al_total_pages > 1): ?>
            <div class="flex gap-1 flex-wrap justify-center">
                <a href="<?= htmlspecialchars($_al_buildUrl(1)) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $_al_page === 1 ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="หน้าแรก" aria-label="หน้าแรก">
                    <i class="fa-solid fa-angles-left text-[10px]"></i>
                </a>
                <a href="<?= htmlspecialchars($_al_buildUrl(max(1, $_al_page - 1))) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $_al_page === 1 ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="ก่อนหน้า" aria-label="ก่อนหน้า">
                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                </a>
                <?php for ($i = max(1, $_al_page - 2); $i <= min($_al_total_pages, $_al_page + 2); $i++): ?>
                <a href="<?= htmlspecialchars($_al_buildUrl($i)) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-sm transition-all <?= $i === $_al_page ? 'bg-[#0052CC] text-white font-bold shadow-md' : 'border border-gray-200 hover:bg-white text-gray-500' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                <a href="<?= htmlspecialchars($_al_buildUrl(min($_al_total_pages, $_al_page + 1))) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $_al_page === $_al_total_pages ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="ถัดไป" aria-label="ถัดไป">
                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                </a>
                <a href="<?= htmlspecialchars($_al_buildUrl($_al_total_pages)) ?>"
                   class="w-9 h-9 flex items-center justify-center rounded-lg text-xs transition-all <?= $_al_page === $_al_total_pages ? 'border border-gray-100 text-gray-300 pointer-events-none' : 'border border-gray-200 hover:bg-white text-gray-500' ?>"
                   title="หน้าสุดท้าย" aria-label="หน้าสุดท้าย">
                    <i class="fa-solid fa-angles-right text-[10px]"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
