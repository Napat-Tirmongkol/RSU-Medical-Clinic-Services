<?php
/**
 * portal/_partials/edms/reports.php
 * รายงานสถิติเอกสาร — KPI / กราฟรายเดือน / สถานะ / ความเร่งด่วน / Top users
 *
 * Query: ?section=edms&edms_view=reports&from=YYYY-MM-DD&to=YYYY-MM-DD
 */
declare(strict_types=1);

$pdo = db();

// Date range — default ย้อนหลัง 90 วัน
$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));

// Validate (basic)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-90 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$range = [$from, $to . ' 23:59:59'];

// ── KPI ─────────────────────────────────────────────────────────────
$kpi = [
    'total'       => 0,
    'this_month'  => 0,
    'completed'   => 0,
    'pending'     => 0,
    'overdue'     => 0,
    'avg_days'    => null,
];
try {
    $kpi['total'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_documents")->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents WHERE created_at >= ?");
    $st->execute([date('Y-m-01 00:00:00')]);
    $kpi['this_month'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents WHERE status IN ('completed','archived') AND created_at BETWEEN ? AND ?");
    $st->execute($range);
    $kpi['completed'] = (int)$st->fetchColumn();

    $kpi['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_documents WHERE status IN ('routing','in_progress')")->fetchColumn();

    $kpi['overdue'] = (int)$pdo->query("
        SELECT COUNT(DISTINCT doc_id) FROM sys_doc_routings
        WHERE status IN ('pending','acknowledged') AND due_date IS NOT NULL AND due_date < CURDATE()
    ")->fetchColumn();

    $st = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at) / 24.0)
        FROM sys_doc_documents
        WHERE status IN ('completed','archived') AND created_at BETWEEN ? AND ?
    ");
    $st->execute($range);
    $avg = $st->fetchColumn();
    $kpi['avg_days'] = $avg !== null && $avg !== false ? round((float)$avg, 1) : null;
} catch (PDOException) {}

// ── Monthly chart (12 เดือนย้อนหลัง) ───────────────────────────────
$monthly = [];
try {
    $rows = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, doc_type, COUNT(*) AS cnt
        FROM sys_doc_documents
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY ym, doc_type
        ORDER BY ym ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Initialize 12 months — เริ่มต้น 0 ทุกประเภทที่มีใน DB
    $allTypes = array_keys(edms_get_doc_type_map($pdo, false));
    $blank = array_fill_keys($allTypes, 0);
    $blank['total'] = 0;
    for ($i = 11; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} months"));
        $monthly[$key] = $blank;
    }
    foreach ($rows as $r) {
        if (!isset($monthly[$r['ym']])) continue;
        $monthly[$r['ym']][$r['doc_type']] = (int)$r['cnt'];
        $monthly[$r['ym']]['total'] += (int)$r['cnt'];
    }
} catch (PDOException) {}

$monthlyMax = 1;
foreach ($monthly as $m) {
    if ($m['total'] > $monthlyMax) $monthlyMax = $m['total'];
}

// ── Status breakdown ───────────────────────────────────────────────
$statusBreakdown = [];
try {
    $st = $pdo->prepare("
        SELECT status, COUNT(*) AS cnt FROM sys_doc_documents
        WHERE created_at BETWEEN ? AND ?
        GROUP BY status
    ");
    $st->execute($range);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $statusBreakdown[$r['status']] = (int)$r['cnt'];
    }
} catch (PDOException) {}

$statusLabels = [
    'draft'       => ['label' => 'ฉบับร่าง',       'color' => 'slate'],
    'registered'  => ['label' => 'ลงทะเบียนแล้ว',  'color' => 'sky'],
    'routing'     => ['label' => 'อยู่ระหว่างโอน', 'color' => 'violet'],
    'in_progress' => ['label' => 'ดำเนินการ',      'color' => 'amber'],
    'completed'   => ['label' => 'เสร็จสิ้น',       'color' => 'emerald'],
    'archived'    => ['label' => 'เก็บแฟ้ม',       'color' => 'slate'],
    'cancelled'   => ['label' => 'ยกเลิก',          'color' => 'rose'],
];

$statusTotal = array_sum($statusBreakdown) ?: 1;

// ── Top priorities ─────────────────────────────────────────────────
$topPriorities = [];
try {
    $st = $pdo->prepare("
        SELECT cat.name, cat.color, COUNT(*) AS cnt
        FROM sys_doc_documents d
        LEFT JOIN sys_doc_categories cat ON cat.id = d.priority_id
        WHERE d.priority_id IS NOT NULL AND d.created_at BETWEEN ? AND ?
        GROUP BY cat.id, cat.name, cat.color
        ORDER BY cnt DESC
        LIMIT 8
    ");
    $st->execute($range);
    $topPriorities = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

// ── Top creators ──────────────────────────────────────────────────
$topCreators = [];
try {
    $st = $pdo->prepare("
        SELECT s.full_name, COUNT(*) AS cnt
        FROM sys_doc_documents d
        LEFT JOIN sys_staff s ON s.id = d.created_by
        WHERE d.created_at BETWEEN ? AND ? AND d.created_by IS NOT NULL
        GROUP BY d.created_by, s.full_name
        ORDER BY cnt DESC
        LIMIT 5
    ");
    $st->execute($range);
    $topCreators = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

require_once __DIR__ . '/_helpers.php';
$toneToHex = [
    'sky' => '#0ea5e9', 'emerald' => '#10b981', 'violet' => '#8b5cf6', 'amber' => '#f59e0b',
    'rose' => '#f43f5e', 'cyan' => '#06b6d4', 'slate' => '#64748b', 'teal' => '#14b8a6',
    'indigo' => '#6366f1', 'orange' => '#f97316',
];
$typeMeta = [];
foreach (edms_get_doc_type_map($pdo, false) as $code => $row) {
    $tone = $row['tone'] ?: 'slate';
    $typeMeta[$code] = [
        'title' => $row['short_label'] ?: $row['name'],
        'tone'  => $tone,
        'bg'    => $toneToHex[$tone] ?? '#64748b',
    ];
}

$thMonths = ['', 'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

$exportQs = http_build_query(['from' => $from, 'to' => $to, 'format' => 'reports']);
?>
<div class="max-w-5xl mx-auto px-4 md:px-6 py-6">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <div class="mb-5 flex items-center gap-4 flex-wrap">
        <div class="w-12 h-12 bg-purple-50 text-purple-600 rounded-2xl border border-purple-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-chart-column"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">รายงานสถิติ</h2>
            <p class="text-slate-500 text-sm font-medium">วิเคราะห์ปริมาณเอกสารและประสิทธิภาพการดำเนินงาน</p>
        </div>
        <form method="GET" class="flex items-center gap-2 flex-wrap">
            <input type="hidden" name="section" value="edms">
            <input type="hidden" name="edms_view" value="reports">
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:border-purple-400">
            <span class="text-slate-400 text-xs">ถึง</span>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:border-purple-400">
            <button type="submit" class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-filter"></i> กรอง
            </button>
            <a href="edms_export.php?<?= htmlspecialchars($exportQs) ?>" class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors" title="Export CSV">
                <i class="fa-solid fa-file-csv"></i> Export
            </a>
        </form>
    </div>

    <!-- KPI -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">เอกสารทั้งหมด</p>
            <p class="text-2xl font-black text-slate-700 mt-1"><?= number_format($kpi['total']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-sky-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-sky-500">เดือนนี้</p>
            <p class="text-2xl font-black text-sky-600 mt-1"><?= number_format($kpi['this_month']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-emerald-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-emerald-500">เสร็จสิ้น (ในช่วง)</p>
            <p class="text-2xl font-black text-emerald-600 mt-1"><?= number_format($kpi['completed']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-amber-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-amber-500">รอดำเนินการ</p>
            <p class="text-2xl font-black text-amber-600 mt-1"><?= number_format($kpi['pending']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-rose-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-rose-500">เกินกำหนด</p>
            <p class="text-2xl font-black text-rose-600 mt-1"><?= number_format($kpi['overdue']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-purple-200 p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-purple-500">เฉลี่ยปิด (วัน)</p>
            <p class="text-2xl font-black text-purple-600 mt-1"><?= $kpi['avg_days'] !== null ? number_format($kpi['avg_days'], 1) : '-' ?></p>
        </div>
    </div>

    <!-- Monthly Chart -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 mb-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-chart-column text-purple-500"></i> ปริมาณเอกสารรายเดือน (12 เดือน)
            </h3>
            <div class="flex items-center gap-3 text-[10px] font-black text-slate-500 flex-wrap">
                <?php foreach ($typeMeta as $t): ?>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:<?= $t['bg'] ?>"></span>
                        <?= htmlspecialchars($t['title']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="overflow-x-auto">
            <div class="flex items-end gap-2 min-w-[600px] h-48 border-b border-slate-100 pb-2">
                <?php foreach ($monthly as $key => $m):
                    $parts = explode('-', $key);
                    $monthLabel = $thMonths[(int)$parts[1]] . ' ' . substr((string)((int)$parts[0] + 543), -2);
                ?>
                    <div class="flex-1 flex flex-col items-center gap-1 min-w-[40px]">
                        <div class="w-full flex flex-col-reverse gap-px h-44 items-stretch justify-end" title="<?= htmlspecialchars($key) ?>: <?= $m['total'] ?>">
                            <?php foreach ($typeMeta as $tk => $t):
                                $h = $monthlyMax > 0 ? ($m[$tk] / $monthlyMax) * 100 : 0;
                                if ($h <= 0) continue;
                            ?>
                                <div style="height: <?= $h ?>%; background: <?= $t['bg'] ?>; min-height: 2px;" class="rounded-sm transition-opacity hover:opacity-80" title="<?= htmlspecialchars($t['title']) ?>: <?= $m[$tk] ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <span class="text-[9px] font-black text-slate-400 whitespace-nowrap"><?= htmlspecialchars($monthLabel) ?></span>
                        <span class="text-[10px] font-black text-slate-700"><?= $m['total'] ?: '' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
        <!-- Status breakdown -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-black text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-chart-pie text-sky-500"></i> สถานะเอกสาร (ในช่วง)
            </h3>
            <?php if (empty($statusBreakdown)): ?>
                <p class="text-center text-sm font-bold text-slate-400 py-6">— ไม่มีข้อมูล —</p>
            <?php else: ?>
                <ul class="space-y-2.5">
                    <?php foreach ($statusLabels as $k => $sl):
                        $cnt = $statusBreakdown[$k] ?? 0;
                        if ($cnt === 0) continue;
                        $pct = $statusTotal > 0 ? round($cnt / $statusTotal * 100, 1) : 0;
                    ?>
                        <li>
                            <div class="flex items-center justify-between text-xs font-black text-slate-700 mb-1">
                                <span><?= htmlspecialchars($sl['label']) ?></span>
                                <span class="text-slate-500 font-bold"><?= number_format($cnt) ?> · <?= $pct ?>%</span>
                            </div>
                            <div class="w-full bg-slate-50 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-<?= $sl['color'] ?>-500 h-full rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Top priorities -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-black text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-flag text-amber-500"></i> ความเร่งด่วนยอดใช้
            </h3>
            <?php if (empty($topPriorities)): ?>
                <p class="text-center text-sm font-bold text-slate-400 py-6">— ไม่มีข้อมูล —</p>
            <?php else:
                $maxP = max(array_column($topPriorities, 'cnt'));
            ?>
                <ul class="space-y-2.5">
                    <?php foreach ($topPriorities as $p):
                        $pct = $maxP > 0 ? round((int)$p['cnt'] / $maxP * 100, 1) : 0;
                        $color = $p['color'] ?: 'slate';
                    ?>
                        <li>
                            <div class="flex items-center justify-between text-xs font-black text-slate-700 mb-1">
                                <span><?= htmlspecialchars($p['name'] ?: '— ไม่ระบุ —') ?></span>
                                <span class="text-slate-500 font-bold"><?= number_format((int)$p['cnt']) ?></span>
                            </div>
                            <div class="w-full bg-slate-50 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-<?= $color ?>-500 h-full rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top creators -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
        <h3 class="text-sm font-black text-slate-800 mb-4 flex items-center gap-2">
            <i class="fa-solid fa-trophy text-emerald-500"></i> ผู้สร้างเอกสารสูงสุด (ในช่วง)
        </h3>
        <?php if (empty($topCreators)): ?>
            <p class="text-center text-sm font-bold text-slate-400 py-6">— ไม่มีข้อมูล —</p>
        <?php else:
            $maxC = max(array_column($topCreators, 'cnt'));
            $rank = 0;
        ?>
            <ul class="space-y-2.5">
                <?php foreach ($topCreators as $c):
                    $rank++;
                    $pct = $maxC > 0 ? round((int)$c['cnt'] / $maxC * 100, 1) : 0;
                ?>
                    <li class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 flex items-center justify-center text-xs font-black shrink-0">
                            #<?= $rank ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between text-xs font-black text-slate-700 mb-1">
                                <span class="truncate"><?= htmlspecialchars($c['full_name'] ?: '— ไม่ทราบ —') ?></span>
                                <span class="text-slate-500 font-bold ml-2"><?= number_format((int)$c['cnt']) ?> ฉบับ</span>
                            </div>
                            <div class="w-full bg-slate-50 rounded-full h-2 overflow-hidden">
                                <div class="bg-emerald-500 h-full rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
