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

require_once __DIR__ . '/edms/_helpers.php';

$_view = $_GET['edms_view'] ?? '';
$_validViews = ['list', 'detail', 'myinbox', 'reports', 'categories', 'doctypes',
                'sla_dashboard', 'sla_policies'];

// Shared style block — emit before any early return so sub-views inherit it
?>
<style id="edms-shared-style">
/* ── EDMS — Bold & Colorful + DARK MODE (applies to landing + sub-views) ─── */
body[data-theme='dark'] #section-edms .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-edms .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-edms .bg-slate-100 { background: rgba(148,163,184,.14) !important; }
body[data-theme='dark'] #section-edms .bg-gray-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-edms .bg-gray-100 { background: rgba(148,163,184,.14) !important; }
body[data-theme='dark'] #section-edms .bg-sky-50    { background: rgba(14,165,233,.18) !important; }
body[data-theme='dark'] #section-edms .bg-emerald-50 { background: rgba(16,185,129,.18) !important; }
body[data-theme='dark'] #section-edms .bg-purple-50 { background: rgba(168,85,247,.18) !important; }
body[data-theme='dark'] #section-edms .bg-amber-50  { background: rgba(245,158,11,.18) !important; }
body[data-theme='dark'] #section-edms .bg-amber-100 { background: rgba(245,158,11,.22) !important; }
body[data-theme='dark'] #section-edms .bg-amber-500 { background: #f59e0b !important; }
body[data-theme='dark'] #section-edms .bg-rose-50   { background: rgba(244,63,94,.18) !important; }
body[data-theme='dark'] #section-edms .bg-rose-100  { background: rgba(244,63,94,.22) !important; }
body[data-theme='dark'] #section-edms .bg-cyan-50   { background: rgba(6,182,212,.18) !important; }
body[data-theme='dark'] #section-edms .bg-teal-50   { background: rgba(20,184,166,.18) !important; }
body[data-theme='dark'] #section-edms .bg-indigo-50 { background: rgba(99,102,241,.18) !important; }
body[data-theme='dark'] #section-edms .bg-orange-50 { background: rgba(249,115,22,.18) !important; }
body[data-theme='dark'] #section-edms .bg-blue-50   { background: rgba(59,130,246,.18) !important; }
body[data-theme='dark'] #section-edms .text-slate-900 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-edms .text-slate-800 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-edms .text-slate-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-edms .text-slate-600 { color:#cbd5e1 !important; }
body[data-theme='dark'] #section-edms .text-slate-500 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-edms .text-slate-400 { color:#64748b !important; }
body[data-theme='dark'] #section-edms .text-slate-300 { color:#475569 !important; }
body[data-theme='dark'] #section-edms .border-slate-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-edms .border-slate-100 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-edms .border-sky-100 { border-color: rgba(14,165,233,.30) !important; }
body[data-theme='dark'] #section-edms .border-sky-200 { border-color: rgba(14,165,233,.30) !important; }
body[data-theme='dark'] #section-edms .border-amber-100 { border-color: rgba(245,158,11,.30) !important; }
body[data-theme='dark'] #section-edms .border-amber-200 { border-color: rgba(245,158,11,.30) !important; }
body[data-theme='dark'] #section-edms .border-rose-100 { border-color: rgba(244,63,94,.30) !important; }
body[data-theme='dark'] #section-edms .border-rose-200 { border-color: rgba(244,63,94,.30) !important; }
body[data-theme='dark'] #section-edms .border-emerald-100 { border-color: rgba(16,185,129,.30) !important; }
body[data-theme='dark'] #section-edms .border-purple-100 { border-color: rgba(168,85,247,.30) !important; }
body[data-theme='dark'] #section-edms .border-cyan-100 { border-color: rgba(6,182,212,.30) !important; }
body[data-theme='dark'] #section-edms .border-teal-100 { border-color: rgba(20,184,166,.30) !important; }
body[data-theme='dark'] #section-edms .border-indigo-100 { border-color: rgba(99,102,241,.30) !important; }
body[data-theme='dark'] #section-edms .border-orange-100 { border-color: rgba(249,115,22,.30) !important; }
body[data-theme='dark'] #section-edms .bg-gradient-to-br.from-amber-50 {
    background: linear-gradient(135deg, rgba(245,158,11,.10), rgba(249,115,22,.10), rgba(244,63,94,.10)) !important;
}
body[data-theme='dark'] #section-edms input,
body[data-theme='dark'] #section-edms select,
body[data-theme='dark'] #section-edms textarea {
    background:#0b1220 !important; border-color:#1e293b !important; color:#e2e8f0 !important;
}

/* tilt-aware lift on type cards */
#section-edms a.group { transition: transform .25s cubic-bezier(.16,1,.3,1), box-shadow .25s ease, border-color .25s ease; }
</style>
<?php

if (in_array($_view, $_validViews, true)) {
    include __DIR__ . '/edms/' . $_view . '.php';
    return;
}

$pdo = db();
$_currentUserId = (int)($_SESSION['admin_id'] ?? 0);

// โหลดประเภทเอกสารจาก DB (ผู้ใช้เพิ่ม/แก้ไขได้ผ่านหน้า ?edms_view=doctypes)
$_docTypes = edms_get_doc_types($pdo, true);

// Quick stats: นับตาม doc_type แบบ dynamic + pending
$stats = ['pending' => 0];
foreach ($_docTypes as $t) $stats[$t['code']] = 0;

$myInboxCount = 0;
$myOverdueCount = 0;
try {
    $rows = $pdo->query("
        SELECT doc_type, COUNT(*) AS cnt
        FROM sys_doc_documents
        GROUP BY doc_type
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $stats[$r['doc_type']] = (int)$r['cnt'];
    }
    $row = $pdo->query("
        SELECT SUM(CASE WHEN status IN ('routing','in_progress') THEN 1 ELSE 0 END) AS pending
        FROM sys_doc_documents
    ")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats['pending'] = (int)($row['pending'] ?? 0);
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

$cards = [];
foreach ($_docTypes as $t) {
    $cards[] = [
        'key'   => $t['code'],
        'title' => $t['name'],
        'desc'  => $t['description'] ?: '',
        'icon'  => $t['icon'] ?: 'fa-file',
        'tone'  => $t['tone'] ?: 'slate',
    ];
}

$tonePalette = [
    'sky'     => ['bg' => 'bg-sky-50',     'text' => 'text-sky-600',     'border' => 'border-sky-100'],
    'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'border' => 'border-emerald-100'],
    'violet'  => ['bg' => 'bg-purple-50',  'text' => 'text-purple-600',  'border' => 'border-purple-100'],
    'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'border' => 'border-amber-100'],
    'rose'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-600',    'border' => 'border-rose-100'],
    'cyan'    => ['bg' => 'bg-cyan-50',    'text' => 'text-cyan-600',    'border' => 'border-cyan-100'],
    'slate'   => ['bg' => 'bg-slate-50',   'text' => 'text-slate-600',   'border' => 'border-slate-100'],
    'teal'    => ['bg' => 'bg-teal-50',    'text' => 'text-teal-600',    'border' => 'border-teal-100'],
    'indigo'  => ['bg' => 'bg-indigo-50',  'text' => 'text-indigo-600',  'border' => 'border-indigo-100'],
    'orange'  => ['bg' => 'bg-orange-50',  'text' => 'text-orange-600',  'border' => 'border-orange-100'],
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
            <p class="text-slate-500 text-sm font-medium">รับ-ส่งเอกสาร · บันทึกข้อความ · มอบหมายงาน — พร้อมติดตามเวลาดำเนินการ</p>
        </div>
        <?php if (!empty($_SESSION['access_edms']) || ($_SESSION['admin_role'] ?? '') === 'superadmin'): ?>
            <a href="?section=edms&edms_view=doctypes"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-sky-50 border border-sky-200 text-sky-700 hover:bg-sky-100 text-xs font-black transition-colors"
                title="เพิ่ม/แก้ไขประเภทเอกสาร">
                <i class="fa-solid fa-folder-tree"></i><span class="hidden sm:inline">ประเภทเอกสาร</span>
            </a>
            <a href="?section=edms&edms_view=categories&kind=custom"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 hover:bg-amber-100 text-xs font-black transition-colors"
                title="เพิ่ม/แก้ไขหมวดหมู่เอกสาร">
                <i class="fa-solid fa-tags"></i><span class="hidden sm:inline">หมวดหมู่</span>
            </a>
            <span class="hidden lg:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-sky-50 border border-sky-100 text-sky-700 text-xs font-black uppercase tracking-widest">
                <i class="fa-solid fa-circle-check"></i> Authorized
            </span>
        <?php endif; ?>
    </div>

    <!-- KPI bar — render dynamic ตามจำนวนประเภทที่มี + pending -->
    <?php
    $kpiCount = count($_docTypes) + 1; // +1 สำหรับช่องรอดำเนินการ
    // จำกัด columns ไม่ให้กว้างเกินไป — 5 ช่องบน desktop เป็นสูงสุดที่ดูได้ดี
    $cols = min($kpiCount, 5);
    $colClass = [2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4', 5 => 'md:grid-cols-5'][$cols] ?? 'md:grid-cols-5';
    ?>
    <div class="grid grid-cols-2 <?= $colClass ?> gap-3 mb-8">
        <?php foreach ($_docTypes as $t):
            $tone = $tonePalette[$t['tone'] ?? 'slate'] ?? $tonePalette['slate'];
            $cnt  = (int)($stats[$t['code']] ?? 0);
        ?>
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400"><?= htmlspecialchars($t['name']) ?></p>
                <p class="text-2xl font-black <?= $tone['text'] ?> mt-1"><?= number_format($cnt) ?></p>
            </div>
        <?php endforeach; ?>
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
    <?php $canManage = (($_SESSION['admin_role'] ?? '') === 'superadmin' || !empty($_SESSION['access_edms'])); ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
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

        <?php if ($canManage): ?>
            <a href="?section=edms&edms_view=doctypes" class="group bg-white rounded-3xl border border-slate-200 shadow-sm p-4 flex items-center gap-3 hover:shadow-md hover:border-sky-200 transition-all">
                <div class="w-10 h-10 bg-sky-50 text-sky-600 rounded-xl border border-sky-100 flex items-center justify-center">
                    <i class="fa-solid fa-folder-tree"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-black text-slate-800">ประเภทเอกสาร</p>
                    <p class="text-[11px] font-bold text-slate-500">เพิ่ม/แก้ไข/ซ่อนประเภทเอกสาร</p>
                </div>
                <i class="fa-solid fa-arrow-right text-slate-300 group-hover:text-sky-500 group-hover:translate-x-1 transition-all"></i>
            </a>

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
