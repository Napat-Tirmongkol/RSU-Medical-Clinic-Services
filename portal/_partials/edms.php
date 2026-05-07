<?php
/**
 * portal/_partials/edms.php
 * ระบบสารบรรณอิเล็กทรอนิกส์ (Electronic Document Management System)
 *
 * Phase 1: Foundation only — แสดง landing page + cards 4 ประเภท
 * Phase 2 จะเพิ่ม inbox/outbox/internal/circular/compose/detail
 *
 * เข้าถึงได้เมื่อ: superadmin หรือมี $_SESSION['access_edms'] = 1
 */
declare(strict_types=1);

$pdo = db();

// Quick stats (จะเริ่มมีค่าเมื่อสร้างเอกสาร) — ป้องกันถ้าตารางยังไม่มี
$stats = ['incoming' => 0, 'outgoing' => 0, 'internal' => 0, 'circular' => 0, 'pending' => 0];
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
    'violet'  => ['bg' => 'bg-violet-50',  'text' => 'text-violet-600',  'border' => 'border-violet-100'],
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
            <p class="text-2xl font-black text-violet-600 mt-1"><?= number_format($stats['internal']) ?></p>
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

    <!-- Type cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <?php foreach ($cards as $card):
            $t = $tonePalette[$card['tone']];
        ?>
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 flex items-start gap-4 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 <?= $t['bg'] ?> rounded-2xl border <?= $t['border'] ?> flex items-center justify-center <?= $t['text'] ?> text-xl shrink-0">
                    <i class="fa-solid <?= $card['icon'] ?>"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-black text-slate-800"><?= htmlspecialchars($card['title']) ?></h3>
                    <p class="text-sm text-slate-500 font-medium mt-0.5"><?= htmlspecialchars($card['desc']) ?></p>
                    <p class="text-xs text-slate-400 mt-3 font-bold">เร็ว ๆ นี้ใน Phase 2</p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Phase 1 status notice -->
    <div class="bg-amber-50 border border-amber-200 rounded-3xl p-5 flex items-start gap-3">
        <i class="fa-solid fa-circle-info text-amber-600 text-lg mt-0.5"></i>
        <div class="flex-1 text-sm">
            <p class="font-black text-amber-800 mb-1">Phase 1 — Foundation Ready</p>
            <p class="text-amber-700 font-medium leading-relaxed">
                โครงสร้างฐานข้อมูล (sys_doc_*) และระบบสิทธิ์ <code class="px-1.5 py-0.5 bg-amber-100 rounded font-mono text-xs">access_edms</code> พร้อมใช้งานแล้ว<br>
                <span class="text-xs text-amber-600 font-bold">ขั้นต่อไป (Phase 2):</span>
                หน้ารายการ 4 ประเภท + Compose modal + ไฟล์แนบ + Detail viewer
            </p>
        </div>
    </div>
</div>
