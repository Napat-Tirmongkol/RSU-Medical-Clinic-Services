<?php
/**
 * portal/_partials/edms/myinbox.php
 * Inbox ของฉัน — เอกสารที่ถูกมอบหมายให้ผู้ใช้ปัจจุบัน
 *
 * Query: ?section=edms&edms_view=myinbox
 *        &p=<page>&filter=open|all|done
 */
declare(strict_types=1);

$pdo = db();
$currentUserId = (int)($_SESSION['admin_id'] ?? 0);

if ($currentUserId <= 0) {
    echo '<div class="max-w-3xl mx-auto px-4 py-12 text-center">';
    echo '  <p class="font-black text-rose-600">ไม่พบ session ผู้ใช้</p>';
    echo '</div>';
    return;
}

$filter = $_GET['filter'] ?? 'open';
$validFilters = ['open', 'all', 'done', 'warning', 'breached', 'paused'];
if (!in_array($filter, $validFilters, true)) $filter = 'open';

$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$statusWhere = match($filter) {
    'open'     => "AND r.status IN ('pending','acknowledged')",
    'done'     => "AND r.status IN ('done','returned')",
    'warning'  => "AND r.sla_state = 'warning'",
    'breached' => "AND r.sla_state = 'breached'",
    'paused'   => "AND r.sla_state = 'paused'",
    default    => '',
};

$total = 0;
$rows = [];
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_routings r WHERE r.to_user_id = ? $statusWhere");
    $sc->execute([$currentUserId]);
    $total = (int)$sc->fetchColumn();

    $sql = "SELECT r.id AS routing_id, r.action AS r_action, r.status AS r_status,
                   r.due_date, r.comment, r.created_at AS routed_at,
                   r.sla_state, r.ack_deadline_at, r.resolve_deadline_at, r.acknowledged_at,
                   TIMESTAMPDIFF(MINUTE, NOW(), r.resolve_deadline_at) AS minutes_left,
                   d.id AS doc_id, d.doc_type, d.doc_number, d.subject, d.status AS doc_status,
                   d.priority_id, cat.name AS priority_name, cat.color AS priority_color,
                   sf.full_name AS from_name
            FROM sys_doc_routings r
            JOIN sys_doc_documents d ON d.id = r.doc_id
            LEFT JOIN sys_doc_categories cat ON cat.id = d.priority_id
            LEFT JOIN sys_staff sf ON sf.id = r.from_user_id
            WHERE r.to_user_id = ?
            $statusWhere
            ORDER BY FIELD(r.sla_state, 'breached','warning','on_track','paused','met') ASC,
                     (r.status = 'pending') DESC, r.resolve_deadline_at ASC, r.created_at DESC
            LIMIT $limit OFFSET $offset";
    $sr = $pdo->prepare($sql);
    $sr->execute([$currentUserId]);
    $rows = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

$totalPages = max(1, (int)ceil($total / $limit));

// KPI counts (เพิ่ม warning + breached count แยก)
$kpis = ['open' => 0, 'warning' => 0, 'breached' => 0, 'done' => 0];
try {
    $kpis['open']     = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = $currentUserId AND status IN ('pending','acknowledged')")->fetchColumn();
    $kpis['warning']  = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = $currentUserId AND sla_state = 'warning' AND status IN ('pending','acknowledged')")->fetchColumn();
    $kpis['breached'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = $currentUserId AND sla_state = 'breached' AND status IN ('pending','acknowledged')")->fetchColumn();
    $kpis['done']     = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = $currentUserId AND status IN ('done','returned')")->fetchColumn();
} catch (PDOException) {}

require_once __DIR__ . '/_helpers.php';
$typeMap = [];
foreach (edms_get_doc_type_map($pdo, false) as $code => $row) {
    $typeMap[$code] = [
        'title' => $row['short_label'] ?: $row['name'],
        'icon'  => $row['icon']        ?: 'fa-file',
        'tone'  => $row['tone']        ?: 'slate',
    ];
}

$routingActionLabels = [
    'forward' => 'ส่งต่อ',
    'assign'  => 'มอบหมาย',
    'approve' => 'เพื่ออนุมัติ',
    'sign'    => 'เพื่อลงนาม',
    'return'  => 'ตีกลับ',
    'note'    => 'เพื่อทราบ',
    'close'   => 'ปิดเรื่อง',
];

$routingStatusLabels = [
    'pending'      => ['label' => 'รอดำเนินการ',   'tone' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'acknowledged' => ['label' => 'รับทราบแล้ว',   'tone' => 'bg-sky-50 text-sky-700 border-sky-200'],
    'done'         => ['label' => 'เสร็จสิ้น',     'tone' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'returned'     => ['label' => 'ตีกลับ',        'tone' => 'bg-rose-50 text-rose-700 border-rose-200'],
];
?>
<div class="max-w-4xl mx-auto px-4 md:px-6 py-6">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <!-- Header -->
    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl border border-amber-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-bell"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold text-slate-800">กล่องของฉัน</h2>
            <p class="text-slate-500 text-sm">เอกสาร · งาน · ที่มอบหมายให้คุณดำเนินการ</p>
        </div>
        <button type="button" id="mi-help-btn" onclick="window._miHelp && window._miHelp()"
            class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold text-slate-600 bg-slate-50 border border-slate-200 hover:bg-slate-100 hover:text-slate-800 transition-colors"
            title="ดูคำอธิบายแต่ละส่วน">
            <i class="fa-solid fa-circle-question"></i> ช่วยเหลือ
        </button>
    </div>

    <!-- KPI — calm with semantic accent stripe -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-amber-500 p-4">
            <p class="text-xs font-semibold text-amber-700">รอทำ</p>
            <p class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($kpis['open']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-orange-500 p-4">
            <p class="text-xs font-semibold text-orange-700">ใกล้หมดเวลา</p>
            <p class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($kpis['warning']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-rose-500 p-4" title="เอกสาร/งาน ที่เลยเวลาที่ต้องเสร็จแล้ว — รีบทำให้เสร็จด่วน">
            <p class="text-xs font-semibold text-rose-700">เลยกำหนด</p>
            <p class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($kpis['breached']) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-emerald-500 p-4" title="เอกสาร/งานที่ปิดเรื่องแล้ว">
            <p class="text-xs font-semibold text-emerald-700">เสร็จแล้ว</p>
            <p class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($kpis['done']) ?></p>
        </div>
    </div>

    <!-- Filter tabs — เรียง: ทั้งหมด ก่อน, แล้วเรียงตามเร่งด่วน -->
    <div class="bg-white rounded-2xl border border-slate-200 p-2 mb-5 inline-flex gap-1 flex-wrap">
        <?php
        $tabs = [
            'all'      => ['label' => 'ทั้งหมด',       'tone' => 'sky'],
            'open'     => ['label' => 'รอทำ',          'tone' => 'amber'],
            'warning'  => ['label' => 'ใกล้หมดเวลา',   'tone' => 'orange'],
            'breached' => ['label' => 'เลยกำหนด',      'tone' => 'rose'],
            'paused'   => ['label' => 'หยุดรอข้อมูล',  'tone' => 'slate'],
            'done'     => ['label' => 'เสร็จแล้ว',     'tone' => 'emerald'],
        ];
        foreach ($tabs as $k => $cfg):
            $isActive = ($k === $filter);
            $tone = $cfg['tone'];
        ?>
            <a href="?section=edms&edms_view=myinbox&filter=<?= urlencode($k) ?>"
               class="px-4 py-2 rounded-xl text-xs font-semibold transition-all
                      <?= $isActive ? "bg-{$tone}-50 text-{$tone}-700 border border-{$tone}-100" : 'text-slate-500 hover:bg-slate-50' ?>">
                <?= htmlspecialchars($cfg['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-3xl border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
            <p class="text-xs text-slate-500">หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= $total ?> รายการ</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-left">ประเภท</th>
                        <th class="px-4 py-3 text-left">เอกสาร</th>
                        <th class="px-4 py-3 text-left">การดำเนินการ</th>
                        <th class="px-4 py-3 text-left">จาก</th>
                        <th class="px-4 py-3 text-left" title="วันเวลาที่ต้องทำให้เสร็จ">ต้องเสร็จก่อน</th>
                        <th class="px-4 py-3 text-center" title="เวลาที่เหลือก่อนจะเลยกำหนด">เหลือเวลา</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)):
                        // ── Empty state ที่บอกขั้นถัดไป ────────────────────
                        $_emptyConfig = match($filter) {
                            'open'     => ['icon' => 'fa-mug-hot',          'color' => 'emerald', 'title' => 'ทำงานครบหมดแล้ว!', 'desc' => 'ไม่มีเอกสาร/งานรอทำในกล่องของคุณ — ดูประวัติได้ที่แท็บ "ทั้งหมด"'],
                            'warning'  => ['icon' => 'fa-circle-check',     'color' => 'emerald', 'title' => 'ไม่มีอะไรใกล้หมดเวลา',        'desc' => 'ทุกชิ้นยังมีเวลาเหลือมากพอ — กลับไปแท็บ "รอทำ" เพื่อดูทั้งหมด'],
                            'breached' => ['icon' => 'fa-shield-check',     'color' => 'emerald', 'title' => 'ไม่มีรายการเลยกำหนด',          'desc' => 'ดีมาก! ไม่มีอะไรที่ทำไม่ทันเวลา'],
                            'paused'   => ['icon' => 'fa-play',             'color' => 'sky',     'title' => 'ไม่มีรายการที่หยุดรอข้อมูล',  'desc' => 'ถ้าต้องรอข้อมูลเพิ่มจากใคร — กดปุ่ม "หยุดรอข้อมูล" ในรายการนั้น'],
                            'done'     => ['icon' => 'fa-folder-plus',      'color' => 'sky',     'title' => 'ยังไม่มีรายการที่ปิดเสร็จ',     'desc' => 'รายการที่ทำเสร็จแล้วจะมาแสดงที่นี่'],
                            default    => ['icon' => 'fa-inbox',            'color' => 'slate',   'title' => 'ไม่มีรายการ',                  'desc' => ''],
                        };
                        $_ec = $_emptyConfig;
                    ?>
                        <tr><td colspan="8" class="px-4 py-16 text-center">
                            <i class="fa-solid <?= $_ec['icon'] ?> text-4xl mb-3 block text-<?= $_ec['color'] ?>-300"></i>
                            <p class="text-<?= $_ec['color'] ?>-700 text-base font-black mb-1"><?= htmlspecialchars($_ec['title']) ?></p>
                            <?php if ($_ec['desc']): ?>
                                <p class="text-slate-400 text-xs font-bold mb-4 max-w-md mx-auto"><?= htmlspecialchars($_ec['desc']) ?></p>
                            <?php endif; ?>
                            <?php if ($filter !== 'open' && $filter !== 'all'): ?>
                                <a href="?section=edms&edms_view=myinbox&filter=open"
                                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-50 text-amber-700 border border-amber-200 text-xs font-black hover:bg-amber-100">
                                    <i class="fa-solid fa-list"></i> ดูรายการที่รอทำ
                                </a>
                            <?php elseif ($filter === 'open'): ?>
                                <a href="?section=edms&edms_view=list&type=task"
                                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-cyan-50 text-cyan-700 border border-cyan-200 text-xs font-black hover:bg-cyan-100">
                                    <i class="fa-solid fa-plus"></i> สร้างงานใหม่
                                </a>
                            <?php endif; ?>
                        </td></tr>
                    <?php else: foreach ($rows as $r):
                        $tm = $typeMap[$r['doc_type']] ?? $typeMap['incoming'];
                        $rs = $routingStatusLabels[$r['r_status']] ?? ['label' => $r['r_status'], 'tone' => 'bg-slate-100 text-slate-600 border-slate-200'];

                        // SLA state + countdown
                        $slaState = $r['sla_state'] ?? 'none';
                        $minsLeft = $r['minutes_left'] !== null ? (int)$r['minutes_left'] : null;
                        $slaToneMap = [
                            'on_track'  => ['label' => 'อยู่ในเวลา',  'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                            'warning'   => ['label' => 'ใกล้หมดเวลา', 'badge' => 'bg-amber-50 text-amber-700 border-amber-200'],
                            'breached'  => ['label' => 'เลยกำหนด',    'badge' => 'bg-rose-50 text-rose-700 border-rose-200'],
                            'met'       => ['label' => 'เสร็จทันเวลา','badge' => 'bg-sky-50 text-sky-700 border-sky-200'],
                            'paused'    => ['label' => 'หยุดรอข้อมูล','badge' => 'bg-slate-100 text-slate-700 border-slate-200'],
                            'cancelled' => ['label' => 'ยกเลิก',      'badge' => 'bg-slate-50 text-slate-500 border-slate-100'],
                            'none'      => ['label' => 'ไม่มีเวลานับ','badge' => 'bg-slate-50 text-slate-400 border-slate-100'],
                        ];
                        $slaInfo = $slaToneMap[$slaState] ?? $slaToneMap['none'];
                        $isOverdue = ($slaState === 'breached');
                        $rowBg = $isOverdue ? 'bg-rose-50/30' : ($slaState === 'warning' ? 'bg-amber-50/20' : '');

                        $fmtRem = function(?int $m) {
                            if ($m === null) return ['—', 'text-slate-400'];
                            $abs = abs($m);
                            $h = intdiv($abs, 60);
                            $mm = $abs % 60;
                            $parts = [];
                            if ($h > 0) $parts[] = "{$h} ชม.";
                            if ($mm > 0 || $h === 0) $parts[] = "{$mm} นาที";
                            $str = implode(' ', $parts);
                            $cls = $m < 0 ? 'text-rose-600' : ($m < 120 ? 'text-amber-600' : 'text-slate-600');
                            return [($m < 0 ? "เลยมา {$str}" : "เหลือ {$str}"), $cls];
                        };
                        [$remText, $remCls] = $fmtRem($minsLeft);
                    ?>
                        <tr class="hover:bg-slate-50/60 transition-colors <?= $rowBg ?>">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-<?= $tm['tone'] ?>-50 text-<?= $tm['tone'] ?>-700 border border-<?= $tm['tone'] ?>-100 text-[10px] font-black">
                                    <i class="fa-solid <?= $tm['icon'] ?> text-[9px]"></i> <?= htmlspecialchars($tm['title']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <a href="?section=edms&edms_view=detail&id=<?= (int)$r['doc_id'] ?>" class="block group">
                                    <div class="font-black text-slate-800 group-hover:text-sky-600 transition-colors line-clamp-1"><?= htmlspecialchars($r['subject']) ?></div>
                                    <?php if ($r['doc_number']): ?>
                                        <div class="text-[10px] font-bold text-slate-400 mt-0.5"><?= htmlspecialchars($r['doc_number']) ?></div>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-black text-slate-700"><?= htmlspecialchars($routingActionLabels[$r['r_action']] ?? $r['r_action']) ?></span>
                                <?php if (!empty($r['comment'])): ?>
                                    <div class="text-[10px] font-medium text-slate-500 mt-0.5 line-clamp-1" title="<?= htmlspecialchars($r['comment']) ?>"><?= htmlspecialchars($r['comment']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600"><?= htmlspecialchars($r['from_name'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-[11px] font-bold whitespace-nowrap text-slate-600">
                                <?php if ($r['resolve_deadline_at']): ?>
                                    <?= date('d/m H:i', strtotime($r['resolve_deadline_at'])) ?>
                                    <?php if ($r['ack_deadline_at'] && empty($r['acknowledged_at'])): ?>
                                        <div class="text-[9px] text-amber-600 mt-0.5" title="กดรับทราบก่อนเวลานี้">กดรับทราบก่อน: <?= date('d/m H:i', strtotime($r['ack_deadline_at'])) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center text-xs font-black whitespace-nowrap <?= $remCls ?>"><?= $remText ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $slaInfo['badge'] ?>"><?= htmlspecialchars($slaInfo['label']) ?></span>
                                <div class="text-[9px] font-bold text-slate-400 mt-0.5"><?= htmlspecialchars($rs['label']) ?></div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="?section=edms&edms_view=detail&id=<?= (int)$r['doc_id'] ?>"
                                   class="bg-sky-500 hover:bg-sky-600 text-white px-3 py-1.5 rounded-lg text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                                    <i class="fa-solid fa-arrow-right"></i> ดำเนินการ
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="px-4 py-3 border-t border-slate-100 flex justify-center gap-1">
            <?php
            $btn = function($label, $target, $active=false, $disabled=false) use ($filter) {
                $base = 'min-w-9 h-8 px-3 rounded-lg text-xs font-black flex items-center justify-center transition-all';
                if ($active) return "<span class='$base bg-amber-500 text-white'>$label</span>";
                if ($disabled) return "<span class='$base bg-slate-50 text-slate-300'>$label</span>";
                $qs = http_build_query(['section'=>'edms','edms_view'=>'myinbox','filter'=>$filter,'p'=>$target]);
                return "<a href='?$qs' class='$base bg-white border border-slate-200 text-slate-500 hover:border-amber-500 hover:text-amber-500'>$label</a>";
            };
            echo $btn('&laquo;', 1, false, $page === 1);
            echo $btn('&lsaquo;', max(1, $page - 1), false, $page === 1);
            for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) echo $btn((string)$i, $i, $i === $page);
            echo $btn('&rsaquo;', min($totalPages, $page + 1), false, $page === $totalPages);
            echo $btn('&raquo;', $totalPages, false, $page === $totalPages);
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    const miSteps = [
        { popover: { title: 'นี่คือกล่องของคุณ', description: 'รวมเอกสารและงานทุกชิ้นที่มอบหมายให้คุณดำเนินการ — ไม่ใช่ของคนอื่น' } },
        { element: '.grid.grid-cols-2', popover: { title: '4 KPI ด้านบน', description: 'นับจำนวนแต่ละสถานะอย่างรวดเร็ว · สีแดง = เลยกำหนด ควรทำก่อน', side: 'bottom' } },
        { element: 'a[href*="filter=breached"]', popover: { title: 'แท็บกรอง', description: 'คลิกเพื่อดูเฉพาะเอกสารที่อยู่ในสถานะนั้น เช่น "เลยกำหนด" หรือ "ใกล้หมดเวลา"', side: 'bottom' } },
        { popover: { title: 'แต่ละแถวคืออะไร?', description: 'คลิกที่ชื่อเอกสารเพื่อเปิดดู — มีปุ่ม "รับทราบ" / "หยุดรอข้อมูล" / "ขอเลื่อนเวลา" ในหน้าเอกสาร' } },
        { popover: { title: 'จบทัวร์', description: 'กด <i class="fa-solid fa-circle-question"></i> ช่วยเหลือ มุมบนขวาเพื่อดูทัวร์อีกครั้งได้ตลอด' } },
    ];
    window._miHelp = function(){ window.RsuTour && RsuTour.start(miSteps, 'myinbox'); };
    window.RsuTour && RsuTour.maybeAutoStart('myinbox', miSteps);
})();
</script>
