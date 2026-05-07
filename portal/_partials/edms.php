<?php
/**
 * portal/_partials/edms.php
 * ระบบสารบรรณอิเล็กทรอนิกส์ (Electronic Document Management System)
 *
 * Router:
 *   no edms_view → landing page (KPI + cards 4 ประเภท)
 *   edms_view=list → รายการเอกสารตาม ?type= (รับ/ส่ง/ภายใน/เวียน)
 *   edms_view=detail → รายละเอียดเอกสาร 1 ฉบับ ?id=
 *
 * เข้าถึงได้เมื่อ: superadmin หรือมี $_SESSION['access_edms'] = 1
 */
declare(strict_types=1);

$_view = $_GET['edms_view'] ?? '';
$_validViews = ['list', 'detail', 'myinbox', 'reports', 'categories'];

if (in_array($_view, $_validViews, true)) {
    include __DIR__ . '/edms/' . $_view . '.php';
    return;
}

$pdo = db();
$_currentUserId = (int)($_SESSION['admin_id'] ?? 0);

// Quick stats (จะเริ่มมีค่าเมื่อสร้างเอกสาร) — ป้องกันถ้าตารางยังไม่มี
$stats = ['incoming' => 0, 'outgoing' => 0, 'internal' => 0, 'circular' => 0, 'pending' => 0];
$myInboxCount = 0;
$myOverdueCount = 0;
try {
    $row = $pdo->query("
        SELECT
            SUM(CASE WHEN doc_type='incoming' THEN 1 ELSE 0 END) AS incoming,
            SUM(CASE WHEN doc_type='outgoing' THEN 1 ELSE 0 END) AS outgoing,
            SUM(CASE WHEN doc_type='internal' THEN 1 ELSE 0 END) AS internal_,
            SUM(CASE WHEN doc_type='circular' THEN 1 ELSE 0 END) AS circular,
            SUM(CASE WHEN status IN ('routing','in_progress') THEN 1 ELSE 0 END) AS pending
        FROM sys_doc_documents
    ")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats['incoming'] = (int)($row['incoming'] ?? 0);
        $stats['outgoing'] = (int)($row['outgoing'] ?? 0);
        $stats['internal'] = (int)($row['internal_'] ?? 0);
        $stats['circular'] = (int)($row['circular'] ?? 0);
        $stats['pending']  = (int)($row['pending'] ?? 0);
    }

    if ($_currentUserId > 0) {
        $mi = $pdo->prepare("SELECT
                SUM(CASE WHEN status IN ('pending','acknowledged') THEN 1 ELSE 0 END) AS open_cnt,
                SUM(CASE WHEN status IN ('pending','acknowledged') AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_cnt
            FROM sys_doc_routings WHERE to_user_id = ?");
        $mi->execute([$_currentUserId]);
        $mr = $mi->fetch(PDO::FETCH_ASSOC);
        if ($mr) {
            $myInboxCount   = (int)($mr['open_cnt'] ?? 0);
            $myOverdueCount = (int)($mr['overdue_cnt'] ?? 0);
        }
    }
} catch (PDOException $e) {
    // ตารางยังไม่ถูกสร้าง — ผู้ใช้ต้องรัน migration ก่อน
}

$cards = [
    [
        'key'   => 'incoming',
        'title' => 'หนังสือรับ',
        'desc'  => 'รับเข้าจากหน่วยงานภายนอก/ภายใน',
        'icon'  => 'fa-inbox',
        'tone'  => 'sky',
    ],
    [
        'key'   => 'outgoing',
        'title' => 'หนังสือส่ง',
        'desc'  => 'ออกจากคลินิกไปยังหน่วยงานอื่น',
        'icon'  => 'fa-paper-plane',
        'tone'  => 'emerald',
    ],
    [
        'key'   => 'internal',
        'title' => 'บันทึกข้อความ',
        'desc'  => 'หนังสือภายในระหว่างฝ่าย',
        'icon'  => 'fa-file-lines',
        'tone'  => 'violet',
    ],
    [
        'key'   => 'circular',
        'title' => 'หนังสือเวียน',
        'desc'  => 'ประกาศ/แจ้งเวียนหลายฝ่าย',
        'icon'  => 'fa-bullhorn',
        'tone'  => 'amber',
    ],
];

$tonePalette = [
    'sky'     => ['bg' => 'bg-sky-50',     'text' => 'text-sky-600',     'border' => 'border-sky-100'],
    'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'border' => 'border-emerald-100'],
    'violet'  => ['bg' => 'bg-purple-50',  'text' => 'text-purple-600',  'border' => 'border-purple-100'],
    'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'border' => 'border-amber-100'],
];
?>
<div class="max-w-4xl mx-auto px-4 md:px-8 py-8">
    <!-- Header -->
    <div class="mb-6 flex items-center gap-4">
        <div class="w-12 h-12 bg-sky-50 rounded-2xl shadow-sm border border-sky-100 flex items-center justify-center text-sky-600 text-xl">
            <i class="fa-solid fa-folder-open"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">สารบรรณอิเล็กทรอนิกส์</h2>
            <p class="text-slate-500 text-sm font-medium">Electronic Document Management System (EDMS)</p>
        </div>
        <?php if (!empty($_SESSION['access_edms']) || ($_SESSION['admin_role'] ?? '') === 'superadmin'): ?>
            <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-sky-50 border border-sky-100 text-sky-700 text-xs font-black uppercase tracking-widest">
                <i class="fa-solid fa-circle-check"></i> Authorized
            </span>
        <?php endif; ?>
    </div>

    <!-- KPI bar -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">รับเข้า</p>
            <p class="text-2xl font-black text-sky-600 mt-1"><?= number_format($stats['incoming']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">ส่งออก</p>
            <p class="text-2xl font-black text-emerald-600 mt-1"><?= number_format($stats['outgoing']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">ภายใน</p>
            <p class="text-2xl font-black text-purple-600 mt-1"><?= number_format($stats['internal']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">เวียน</p>
            <p class="text-2xl font-black text-amber-600 mt-1"><?= number_format($stats['circular']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-rose-100 p-4 shadow-sm col-span-2 md:col-span-1">
            <p class="text-[10px] font-black uppercase tracking-widest text-rose-400">รอดำเนินการ</p>
            <p class="text-2xl font-black text-rose-600 mt-1"><?= number_format($stats['pending']) ?></p>
        </div>
    </div>

    <!-- Inbox ของฉัน -->
    <a href="?section=edms&edms_view=myinbox" class="group block bg-gradient-to-br from-amber-50 via-orange-50 to-rose-50 rounded-3xl border-2 border-amber-200 shadow-sm p-5 mb-6 flex items-center gap-4 hover:shadow-md hover:-translate-y-0.5 transition-all">
        <div class="w-12 h-12 bg-amber-500 text-white rounded-2xl shadow-md flex items-center justify-center text-xl shrink-0">
            <i class="fa-solid fa-bell"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-[10px] font-black uppercase tracking-widest text-amber-600">Inbox ของฉัน</p>
            <p class="text-base font-black text-slate-800 mt-0.5">
                <?php if ($myInboxCount > 0): ?>
                    คุณมี <span class="text-amber-600"><?= number_format($myInboxCount) ?></span> เอกสารรอดำเนินการ
                    <?php if ($myOverdueCount > 0): ?>
                        <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 border border-rose-200 text-[10px] font-black">
                            <i class="fa-solid fa-circle-exclamation text-[8px]"></i> เกินกำหนด <?= $myOverdueCount ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    ไม่มีเอกสารรอดำเนินการในตอนนี้
                <?php endif; ?>
            </p>
        </div>
        <i class="fa-solid fa-arrow-right text-amber-500 group-hover:translate-x-1 transition-transform"></i>
    </a>

    <!-- Type cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <?php foreach ($cards as $card):
            $t = $tonePalette[$card['tone']];
            $count = (int)$stats[$card['key']];
            $href = '?section=edms&edms_view=list&type=' . urlencode($card['key']);
        ?>
            <a href="<?= $href ?>" class="group bg-white rounded-3xl border border-slate-200 shadow-sm p-6 flex items-start gap-4 hover:shadow-md hover:-translate-y-0.5 hover:border-sky-200 transition-all">
                <div class="w-12 h-12 <?= $t['bg'] ?> rounded-2xl border <?= $t['border'] ?> flex items-center justify-center <?= $t['text'] ?> text-xl shrink-0">
                    <i class="fa-solid <?= $card['icon'] ?>"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <h3 class="text-lg font-black text-slate-800"><?= htmlspecialchars($card['title']) ?></h3>
                        <span class="text-2xl font-black <?= $t['text'] ?> leading-none"><?= number_format($count) ?></span>
                    </div>
                    <p class="text-sm text-slate-500 font-medium"><?= htmlspecialchars($card['desc']) ?></p>
                    <p class="text-xs font-black text-slate-400 mt-3 inline-flex items-center gap-1.5">
                        เปิดรายการ <i class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-1 transition-transform"></i>
                    </p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Tools row -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <a href="?section=edms&edms_view=reports" class="group bg-white rounded-3xl border border-slate-200 shadow-sm p-4 flex items-center gap-3 hover:shadow-md hover:border-purple-200 transition-all">
            <div class="w-10 h-10 bg-purple-50 text-purple-600 rounded-xl border border-purple-100 flex items-center justify-center">
                <i class="fa-solid fa-chart-column"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-black text-slate-800">รายงานสถิติ</p>
                <p class="text-[11px] font-bold text-slate-500">KPI · กราฟรายเดือน · Export Excel</p>
            </div>
            <i class="fa-solid fa-arrow-right text-slate-300 group-hover:text-purple-500 group-hover:translate-x-1 transition-all"></i>
        </a>

        <?php if (($_SESSION['admin_role'] ?? '') === 'superadmin' || !empty($_SESSION['access_edms'])): ?>
            <a href="?section=edms&edms_view=categories" class="group bg-white rounded-3xl border border-slate-200 shadow-sm p-4 flex items-center gap-3 hover:shadow-md hover:border-amber-200 transition-all">
                <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-xl border border-amber-100 flex items-center justify-center">
                    <i class="fa-solid fa-tags"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-black text-slate-800">หมวดหมู่</p>
                    <p class="text-[11px] font-bold text-slate-500">ความเร่งด่วน · ชั้นความลับ · หมวดทั่วไป</p>
                </div>
                <i class="fa-solid fa-arrow-right text-slate-300 group-hover:text-amber-500 group-hover:translate-x-1 transition-all"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
