<?php
// portal/_partials/email_logs.php — included by portal/index.php
// $pdo is available from parent scope

$_eml_perPage      = 50;
$_eml_page         = max(1, (int)($_GET['page'] ?? 1));
$_eml_offset       = ($_eml_page - 1) * $_eml_perPage;
$_eml_search       = trim($_GET['eml_q']      ?? '');
$_eml_typeFilter   = trim($_GET['eml_type']   ?? '');
$_eml_statusFilter = trim($_GET['eml_status'] ?? '');

$_eml_where  = [];
$_eml_params = [];
if ($_eml_search !== '') {
    $_eml_where[]       = '(recipient LIKE :q OR subject LIKE :q2)';
    $_eml_params[':q']  = "%{$_eml_search}%";
    $_eml_params[':q2'] = "%{$_eml_search}%";
}
if ($_eml_typeFilter !== '') {
    $_eml_where[]         = 'type = :type';
    $_eml_params[':type'] = $_eml_typeFilter;
}
if ($_eml_statusFilter !== '') {
    $_eml_where[]           = 'status = :status';
    $_eml_params[':status'] = $_eml_statusFilter;
}
$_eml_whereSQL = $_eml_where ? ('WHERE ' . implode(' AND ', $_eml_where)) : '';

$_eml_logs = [];
$_eml_stats = [];
$_eml_types = [];
$_eml_total = 0;
$_eml_tableExists = false;
try {
    $_eml_tableExists = $pdo->query("SHOW TABLES LIKE 'sys_email_logs'")->rowCount() > 0;
    if ($_eml_tableExists) {
        $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_email_logs {$_eml_whereSQL}");
        $sc->execute($_eml_params);
        $_eml_total = (int)$sc->fetchColumn();

        $sr = $pdo->prepare("SELECT id,recipient,subject,type,status,error_msg,sent_at FROM sys_email_logs {$_eml_whereSQL} ORDER BY sent_at DESC LIMIT :lim OFFSET :off");
        foreach ($_eml_params as $k => $v) $sr->bindValue($k, $v);
        $sr->bindValue(':lim', $_eml_perPage, PDO::PARAM_INT);
        $sr->bindValue(':off', $_eml_offset,  PDO::PARAM_INT);
        $sr->execute();
        $_eml_logs = $sr->fetchAll(PDO::FETCH_ASSOC);

        $_eml_stats = $pdo->query("SELECT COUNT(*) AS total, SUM(status='sent') AS sent, SUM(status='failed') AS failed, COUNT(DISTINCT recipient) AS unique_recipients FROM sys_email_logs")->fetch(PDO::FETCH_ASSOC);
        $_eml_types = $pdo->query("SELECT DISTINCT type FROM sys_email_logs ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $_eml_total = 0; $_eml_logs = []; $_eml_stats = []; $_eml_types = [];
}
$_eml_totalPages = $_eml_total > 0 ? (int)ceil($_eml_total / $_eml_perPage) : 1;

$_eml_typeLabels = [
    'confirmation'       => ['label'=>'จองสำเร็จ',   'color'=>'#059669','bg'=>'#d1fae5','icon'=>'fa-calendar-check'],
    'approved'           => ['label'=>'อนุมัติคิว',  'color'=>'#2563eb','bg'=>'#dbeafe','icon'=>'fa-circle-check'],
    'cancelled_by_user'  => ['label'=>'User ยกเลิก', 'color'=>'#dc2626','bg'=>'#fee2e2','icon'=>'fa-circle-xmark'],
    'cancelled_by_admin' => ['label'=>'Admin ยกเลิก','color'=>'#d97706','bg'=>'#fef3c7','icon'=>'fa-user-slash'],
    ''                   => ['label'=>'อื่นๆ',       'color'=>'#64748b','bg'=>'#f1f5f9','icon'=>'fa-envelope'],
];
?>
<style>
.eml-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:1.25rem;overflow:hidden}
.eml-stat-box{text-align:center;padding:1rem}
.eml-stat-num{font-size:1.75rem;font-weight:900}
.eml-stat-lbl{font-size:.7rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-top:2px}
.eml-row{display:grid;grid-template-columns:80px 1fr 160px 90px 110px;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid #f1f5f9;font-size:.82rem}
.eml-row:last-child{border-bottom:none}
.eml-row:hover{background:#fafafa}
.eml-type-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.65rem;font-weight:800;white-space:nowrap}
.eml-status-sent{background:#d1fae5;color:#065f46}
.eml-status-failed{background:#fee2e2;color:#991b1b}
.eml-search-input{width:100%;padding:.6rem 1rem;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:.875rem;font-size:.85rem;font-weight:500;outline:none;transition:.2s}
.eml-search-input:focus{background:#fff;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
@media(max-width:900px){.eml-row{grid-template-columns:1fr 1fr;row-gap:4px}.eml-col-id{display:none}}

/* ── Email Logs — DARK MODE ─────────────────────────────── */
body[data-theme='dark'] #section-email_logs .eml-status-sent { background:rgba(16,185,129,.18); color:#6ee7b7; }
body[data-theme='dark'] #section-email_logs .eml-status-failed { background:rgba(244,63,94,.18); color:#fb7185; }
body[data-theme='dark'] #section-email_logs .eml-search-input { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
body[data-theme='dark'] #section-email_logs .eml-search-input:focus { background:#0f172a; border-color:#6366f1; }
body[data-theme='dark'] #section-email_logs .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-email_logs .bg-gray-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-email_logs .bg-gray-100 { background: rgba(148,163,184,.14) !important; }
body[data-theme='dark'] #section-email_logs .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-email_logs .text-gray-900 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-email_logs .text-gray-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-email_logs .text-gray-500 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-email_logs .text-gray-400 { color:#64748b !important; }
body[data-theme='dark'] #section-email_logs .text-slate-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-email_logs .text-slate-500 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-email_logs .border-gray-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-email_logs .border-gray-100 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-email_logs .border-slate-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-email_logs table th { background:#1e293b !important; color:#cbd5e1 !important; }
body[data-theme='dark'] #section-email_logs table td { border-color:#1e293b !important; color:#e2e8f0; }
body[data-theme='dark'] #section-email_logs table tbody tr:hover { background:#0b1220 !important; }
</style>

<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-black text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-envelope-open-text text-indigo-500"></i> Email Logs
        </h2>
        <p class="text-xs text-gray-400 mt-1">ประวัติการส่งอีเมลทั้งหมดของระบบ</p>
    </div>

    <?php if (!$_eml_tableExists): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
        <i class="fa-solid fa-triangle-exclamation text-amber-400 text-3xl mb-3 block"></i>
        <p class="font-black text-amber-800 mb-1">ยังไม่ได้รัน Migration</p>
        <p class="text-sm text-amber-600">ต้องสร้างตาราง <code class="bg-amber-100 px-2 py-0.5 rounded">sys_email_logs</code> ก่อน</p>
    </div>
    <?php else: ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php
        $sc = [
            ['#6366f1', number_format((int)($_eml_stats['total'] ?? 0)),            'ส่งทั้งหมด'],
            ['#059669', number_format((int)($_eml_stats['sent'] ?? 0)),             'สำเร็จ'],
            ['#dc2626', number_format((int)($_eml_stats['failed'] ?? 0)),           'ล้มเหลว'],
            ['#d97706', number_format((int)($_eml_stats['unique_recipients'] ?? 0)),'ผู้รับไม่ซ้ำ'],
        ];
        foreach ($sc as [$col, $num, $lbl]): ?>
        <div class="eml-card"><div class="eml-stat-box">
            <div class="eml-stat-num" style="color:<?= $col ?>"><?= $num ?></div>
            <div class="eml-stat-lbl"><?= $lbl ?></div>
        </div></div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="eml-card mb-5 p-4">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="section" value="email_logs">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">ค้นหา</label>
                <input type="text" name="eml_q" value="<?= htmlspecialchars($_eml_search) ?>" placeholder="อีเมล, หัวข้อ..." class="eml-search-input">
            </div>
            <div>
                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">ประเภท</label>
                <select name="eml_type" class="eml-search-input" style="width:auto;min-width:150px">
                    <option value="">ทุกประเภท</option>
                    <?php foreach ($_eml_types as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $_eml_typeFilter === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($_eml_typeLabels[$t]['label'] ?? $t) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">สถานะ</label>
                <select name="eml_status" class="eml-search-input" style="width:auto;min-width:120px">
                    <option value="">ทั้งหมด</option>
                    <option value="sent"   <?= $_eml_statusFilter==='sent'   ? 'selected':'' ?>>✅ สำเร็จ</option>
                    <option value="failed" <?= $_eml_statusFilter==='failed' ? 'selected':'' ?>>❌ ล้มเหลว</option>
                </select>
            </div>
            <button type="submit" style="background:#4f46e5;color:#fff;padding:.6rem 1.25rem;border-radius:.75rem;font-weight:700;font-size:.875rem;display:flex;align-items:center;gap:.5rem;border:none;cursor:pointer;transition:background .15s" onmouseover="this.style.background='#4338ca'" onmouseout="this.style.background='#4f46e5'">
                <i class="fa-solid fa-magnifying-glass" style="font-size:.75rem"></i> ค้นหา
            </button>
            <?php if ($_eml_search || $_eml_typeFilter || $_eml_statusFilter): ?>
            <a href="?section=email_logs" class="px-5 py-2.5 border border-gray-200 bg-white text-gray-500 rounded-xl font-bold text-sm hover:bg-gray-50 flex items-center gap-2">
                <i class="fa-solid fa-xmark text-xs"></i> ล้าง
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="eml-card">
        <div class="eml-row bg-gray-50/80 font-black text-[10px] text-gray-400 uppercase tracking-wider" style="border-bottom:2px solid #f1f5f9">
            <div class="eml-col-id">#ID</div>
            <div>ผู้รับ / หัวข้อ</div>
            <div>ประเภท</div>
            <div>สถานะ</div>
            <div>วันเวลา</div>
        </div>
        <?php if (empty($_eml_logs)): ?>
        <div class="py-16 text-center text-gray-400">
            <i class="fa-regular fa-envelope text-4xl mb-3 block opacity-30"></i>
            <p class="font-bold">ไม่พบประวัติการส่งอีเมล</p>
        </div>
        <?php else: foreach ($_eml_logs as $log):
            $tInfo  = $_eml_typeLabels[$log['type']] ?? $_eml_typeLabels[''];
            $isFail = $log['status'] === 'failed';
            $dt     = new DateTime($log['sent_at']);
        ?>
        <div class="eml-row">
            <div class="eml-col-id text-gray-300 font-mono text-xs">#<?= $log['id'] ?></div>
            <div class="min-w-0">
                <div class="font-bold text-gray-900 text-[13px] truncate"><?= htmlspecialchars($log['recipient']) ?></div>
                <div class="text-gray-400 text-[11px] truncate mt-0.5"><?= htmlspecialchars($log['subject']) ?></div>
            </div>
            <div>
                <span class="eml-type-badge" style="background:<?= $tInfo['bg'] ?>;color:<?= $tInfo['color'] ?>">
                    <i class="fa-solid <?= $tInfo['icon'] ?> text-[9px]"></i> <?= $tInfo['label'] ?>
                </span>
            </div>
            <div>
                <span class="eml-type-badge <?= $isFail ? 'eml-status-failed' : 'eml-status-sent' ?>">
                    <i class="fa-solid <?= $isFail ? 'fa-xmark' : 'fa-check' ?> text-[9px]"></i>
                    <?= $isFail ? 'ล้มเหลว' : 'สำเร็จ' ?>
                </span>
                <?php if ($isFail && $log['error_msg']): ?>
                <div class="text-[10px] text-red-400 mt-0.5 truncate max-w-[120px]" title="<?= htmlspecialchars($log['error_msg']) ?>">
                    <?= htmlspecialchars(mb_substr($log['error_msg'], 0, 40)) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <div class="font-bold text-gray-700 text-[12px]"><?= $dt->format('d M Y') ?></div>
                <div class="text-gray-400 text-[11px]"><?= $dt->format('H:i:s') ?></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($_eml_totalPages > 1):
        $_eml_pgQs = http_build_query(array_filter(['section'=>'email_logs','eml_q'=>$_eml_search,'eml_type'=>$_eml_typeFilter,'eml_status'=>$_eml_statusFilter]));
    ?>
    <div class="flex items-center justify-between mt-5">
        <p class="text-sm text-gray-400"><?= number_format($_eml_offset+1) ?>–<?= number_format(min($_eml_offset+$_eml_perPage,$_eml_total)) ?> / <?= number_format($_eml_total) ?></p>
        <div class="flex gap-2">
            <a href="?<?= $_eml_pgQs ?>&page=<?= max(1,$_eml_page-1) ?>" class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-500 hover:bg-white <?= $_eml_page<=1?'opacity-40 pointer-events-none':'' ?>"><i class="fa-solid fa-chevron-left"></i></a>
            <?php for ($i=max(1,$_eml_page-2);$i<=min($_eml_totalPages,$_eml_page+2);$i++): ?>
            <a href="?<?= $_eml_pgQs ?>&page=<?= $i ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $i===$_eml_page?'bg-indigo-600 text-white':'border border-gray-200 text-gray-500 hover:bg-white' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="?<?= $_eml_pgQs ?>&page=<?= min($_eml_totalPages,$_eml_page+1) ?>" class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-500 hover:bg-white <?= $_eml_page>=$_eml_totalPages?'opacity-40 pointer-events-none':'' ?>"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // tableExists ?>
</div>
