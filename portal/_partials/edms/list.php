<?php
/**
 * portal/_partials/edms/list.php
 * รายการเอกสาร + Compose/Edit modal
 *
 * Query string:
 *   ?section=edms&edms_view=list&type=incoming|outgoing|internal|circular
 *   &p=<page>&s=<search>&status=<status>&priority=<id>&from=<date>&to=<date>
 */
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$pdo = db();

// โหลดประเภทเอกสารจาก DB (รวมที่ปิดใช้งานด้วย เผื่อมีเอกสารเดิมอยู่)
$validTypes = edms_get_doc_type_map($pdo, false);

$tonePalette = [
    'sky'     => ['bg' => 'bg-sky-50',     'text' => 'text-sky-600',     'border' => 'border-sky-100',     'btn' => 'bg-sky-500 hover:bg-sky-600'],
    'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'border' => 'border-emerald-100', 'btn' => 'bg-emerald-500 hover:bg-emerald-600'],
    'violet'  => ['bg' => 'bg-purple-50',  'text' => 'text-purple-600',  'border' => 'border-purple-100',  'btn' => 'bg-purple-500 hover:bg-purple-600'],
    'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'border' => 'border-amber-100',   'btn' => 'bg-amber-500 hover:bg-amber-600'],
    'rose'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-600',    'border' => 'border-rose-100',    'btn' => 'bg-rose-500 hover:bg-rose-600'],
    'cyan'    => ['bg' => 'bg-cyan-50',    'text' => 'text-cyan-600',    'border' => 'border-cyan-100',    'btn' => 'bg-cyan-500 hover:bg-cyan-600'],
    'slate'   => ['bg' => 'bg-slate-50',   'text' => 'text-slate-600',   'border' => 'border-slate-100',   'btn' => 'bg-slate-500 hover:bg-slate-600'],
    'teal'    => ['bg' => 'bg-teal-50',    'text' => 'text-teal-600',    'border' => 'border-teal-100',    'btn' => 'bg-teal-500 hover:bg-teal-600'],
    'indigo'  => ['bg' => 'bg-indigo-50',  'text' => 'text-indigo-600',  'border' => 'border-indigo-100',  'btn' => 'bg-indigo-500 hover:bg-indigo-600'],
    'orange'  => ['bg' => 'bg-orange-50',  'text' => 'text-orange-600',  'border' => 'border-orange-100',  'btn' => 'bg-orange-500 hover:bg-orange-600'],
];

$type = $_GET['type'] ?? 'incoming';
if (!isset($validTypes[$type])) {
    // fallback: ใช้ตัวแรกที่ active
    $firstActive = array_key_first(edms_get_doc_type_map($pdo, true));
    $type = $firstActive ?: 'incoming';
}

$row = $validTypes[$type] ?? null;
$meta = [
    'title'  => $row['name']        ?? 'เอกสาร',
    'icon'   => $row['icon']        ?? 'fa-file',
    'tone'   => $row['tone']        ?? 'slate',
    'prefix' => $row['short_label'] ?? '',
];
$tone = $tonePalette[$meta['tone']] ?? $tonePalette['slate'];

// Filters
$search   = trim($_GET['s'] ?? '');
$page     = max(1, (int)($_GET['p'] ?? 1));
$limit    = 20;
$offset   = ($page - 1) * $limit;
$status   = $_GET['status'] ?? '';
$priority = (int)($_GET['priority'] ?? 0);
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to'] ?? '');

$where  = 'WHERE d.doc_type = ?';
$params = [$type];

if ($search !== '') {
    $where .= ' AND (d.subject LIKE ? OR d.doc_number LIKE ? OR d.sender LIKE ? OR d.recipient LIKE ?)';
    $kw = "%$search%";
    array_push($params, $kw, $kw, $kw, $kw);
}
if ($status !== '' && in_array($status, ['draft','registered','routing','in_progress','completed','archived','cancelled'], true)) {
    $where .= ' AND d.status = ?';
    $params[] = $status;
}
if ($priority > 0) {
    $where .= ' AND d.priority_id = ?';
    $params[] = $priority;
}
if ($from !== '') {
    $where .= ' AND d.doc_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where .= ' AND d.doc_date <= ?';
    $params[] = $to;
}

$total = 0;
$rows  = [];
try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents d $where");
    $sc->execute($params);
    $total = (int)$sc->fetchColumn();

    $sql = "SELECT d.id, d.doc_number, d.subject, d.status, d.confidentiality,
                   d.doc_date, d.received_date, d.sender, d.recipient,
                   d.priority_id, cat.name AS priority_name, cat.color AS priority_color,
                   d.created_at, d.updated_at,
                   (SELECT COUNT(*) FROM sys_doc_attachments a WHERE a.doc_id = d.id) AS att_count
            FROM sys_doc_documents d
            LEFT JOIN sys_doc_categories cat ON cat.id = d.priority_id
            $where
            ORDER BY d.created_at DESC
            LIMIT $limit OFFSET $offset";
    $sr = $pdo->prepare($sql);
    $sr->execute($params);
    $rows = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

$totalPages = max(1, (int)ceil($total / $limit));

// Categories for filter + compose modal — LEFT JOIN SLA policy ของ doc_type ปัจจุบัน
// เพื่อแสดง ack/resolve hours ใน dropdown
$priorities = [];
try {
    $st = $pdo->prepare("
        SELECT c.id, c.code, c.name, c.color,
               p.ack_hours, p.resolve_hours, p.business_hours_only
        FROM sys_doc_categories c
        LEFT JOIN sys_doc_sla_policies p
               ON p.priority_id = c.id AND p.doc_type = ? AND p.is_active = 1
        WHERE c.kind = 'priority' AND c.is_active = 1
        ORDER BY c.sort_order ASC, c.id ASC
    ");
    $st->execute([$type]);
    $priorities = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException) {
    // Fallback: ถ้า sys_doc_sla_policies ยังไม่มี (migration ยังไม่รัน)
    try {
        $priorities = $pdo->query("SELECT id, code, name, color, NULL AS ack_hours, NULL AS resolve_hours, NULL AS business_hours_only
            FROM sys_doc_categories WHERE kind='priority' AND is_active=1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException) {}
}

// Helper: format SLA suffix สำหรับ dropdown option
// "· รับใน 4 ชม. · เสร็จใน 16 ชม. (เวลาทำการ)"
$_fmtSlaHours = function(?float $h): string {
    if ($h === null || $h <= 0) return '';
    if ($h < 1) return rtrim(rtrim(number_format($h, 2), '0'), '.') . ' ชม.';
    if ($h < 24) return rtrim(rtrim(number_format($h, 1), '0'), '.') . ' ชม.';
    $days = $h / 8;  // 1 business day = 8h
    if ($days <= 7) return rtrim(rtrim(number_format($days, 1), '0'), '.') . ' วันทำการ';
    return rtrim(rtrim(number_format($days, 0), '0'), '.') . ' วันทำการ';
};
$_fmtSlaSuffix = function(array $p) use ($_fmtSlaHours): string {
    $ack = isset($p['ack_hours']) ? (float)$p['ack_hours'] : null;
    $res = isset($p['resolve_hours']) ? (float)$p['resolve_hours'] : null;
    if ($ack === null && $res === null) return '';
    $parts = [];
    if ($ack !== null && $ack > 0) $parts[] = 'รับ ' . $_fmtSlaHours($ack);
    if ($res !== null && $res > 0) $parts[] = 'เสร็จ ' . $_fmtSlaHours($res);
    if (empty($parts)) return '';
    $bh = !empty($p['business_hours_only']) ? ' · เวลาทำการ' : '';
    return ' · ' . implode(' · ', $parts) . $bh;
};

$statusLabels = [
    'draft'       => ['label' => 'ฉบับร่าง',       'tone' => 'bg-slate-100 text-slate-600 border-slate-200'],
    'registered'  => ['label' => 'ลงทะเบียนแล้ว',  'tone' => 'bg-sky-50 text-sky-700 border-sky-200'],
    'routing'     => ['label' => 'อยู่ระหว่างโอน', 'tone' => 'bg-purple-50 text-purple-700 border-purple-200'],
    'in_progress' => ['label' => 'ดำเนินการ',      'tone' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'completed'   => ['label' => 'เสร็จสิ้น',       'tone' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'archived'    => ['label' => 'เก็บแฟ้ม',       'tone' => 'bg-slate-50 text-slate-500 border-slate-200'],
    'cancelled'   => ['label' => 'ยกเลิก',          'tone' => 'bg-rose-50 text-rose-600 border-rose-200'],
];

$confidentialityLabels = [
    'normal'        => 'ปกติ',
    'confidential'  => 'ลับ',
    'secret'        => 'ลับมาก',
    'top_secret'    => 'ลับที่สุด',
];
?>
<style>
#edmsComposeModal { z-index: 200; }
#edmsComposeBox { max-height: 92vh; }
#edmsComposeBody { min-height: 0; }
.edms-input { display:block; width:100%; padding: 10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-weight: 600; color:#1e293b; outline: none; transition: all .15s; }
.edms-input:focus { border-color: #0ea5e9; background:#fff; box-shadow: 0 0 0 3px rgba(14,165,233,.12); }
.edms-label { display:block; font-size: 11px; font-weight: 800; color:#64748b; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
</style>

<div class="max-w-4xl mx-auto px-4 md:px-6 py-6">
    <!-- Breadcrumb / Tabs -->
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <!-- Tabs (4 types) -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-2 mb-5 inline-flex gap-1 flex-wrap">
        <?php foreach ($validTypes as $k => $m):
            $tt = $tonePalette[$m['tone'] ?? 'slate'] ?? $tonePalette['slate'];
            $isActive = ($k === $type);
            $mTitle   = (string)($m['name'] ?? $m['short_label'] ?? $k);
            $mIcon    = (string)($m['icon'] ?? 'fa-file');
        ?>
            <a href="?section=edms&edms_view=list&type=<?= urlencode($k) ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all
                      <?= $isActive ? "{$tt['bg']} {$tt['text']} {$tt['border']} border" : 'text-slate-500 hover:bg-slate-50' ?>">
                <i class="fa-solid <?= htmlspecialchars($mIcon) ?>"></i>
                <?= htmlspecialchars($mTitle) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Header -->
    <div class="mb-5 flex items-center gap-3 flex-wrap">
        <div class="w-12 h-12 <?= $tone['bg'] ?> rounded-2xl border <?= $tone['border'] ?> flex items-center justify-center <?= $tone['text'] ?> text-xl">
            <i class="fa-solid <?= $meta['icon'] ?>"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800"><?= htmlspecialchars($meta['title']) ?></h2>
            <p class="text-slate-500 text-sm font-medium">รวม <?= number_format($total) ?> รายการ</p>
        </div>
        <?php
        $exportQs = http_build_query([
            'format'=>'list','type'=>$type,'s'=>$search,'status'=>$status,
            'priority'=>$priority,'from'=>$from,'to'=>$to,
        ]);
        ?>
        <a href="edms_export.php?<?= htmlspecialchars($exportQs) ?>"
           class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2.5 rounded-2xl text-sm font-black flex items-center gap-2 shadow-sm transition-colors"
           title="Export CSV (ตามตัวกรองปัจจุบัน)">
            <i class="fa-solid fa-file-csv"></i> Export
        </a>
        <button onclick="edmsOpenCompose()" class="<?= $tone['btn'] ?> text-white px-4 py-2.5 rounded-2xl text-sm font-black flex items-center gap-2 shadow-sm transition-colors">
            <i class="fa-solid fa-plus"></i> สร้างใหม่
        </button>
    </div>

    <!-- Filter / Search bar -->
    <form method="GET" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-4 mb-5">
        <input type="hidden" name="section" value="edms">
        <input type="hidden" name="edms_view" value="list">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
        <div class="grid grid-cols-2 md:grid-cols-6 gap-2.5">
            <input type="search" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหา เรื่อง / เลขที่ / ผู้ส่ง"
                   class="md:col-span-2 edms-input">
            <select name="status" class="edms-input">
                <option value="">ทุกสถานะ</option>
                <?php foreach ($statusLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= htmlspecialchars($v['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="edms-input">
                <option value="0">ทุกความเร่งด่วน</option>
                <?php foreach ($priorities as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $priority === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="edms-input" title="ลงวันที่ตั้งแต่">
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="edms-input" title="ลงวันที่ถึง">
        </div>
        <div class="flex items-center justify-between gap-2 mt-3">
            <p class="text-[11px] font-black text-slate-400">หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= $total ?> รายการ</p>
            <div class="flex items-center gap-2">
                <?php if ($search !== '' || $status !== '' || $priority > 0 || $from !== '' || $to !== ''): ?>
                    <a href="?section=edms&edms_view=list&type=<?= urlencode($type) ?>" class="text-xs font-black text-slate-400 hover:text-rose-500 inline-flex items-center gap-1">
                        <i class="fa-solid fa-xmark"></i> ล้างตัวกรอง
                    </a>
                <?php endif; ?>
                <button type="submit" class="<?= $tone['btn'] ?> text-white px-4 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-magnifying-glass text-[11px]"></i> ค้นหา
                </button>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-4 py-3 text-left">เลขที่</th>
                        <th class="px-4 py-3 text-left">เรื่อง / ผู้ส่ง</th>
                        <th class="px-4 py-3 text-left">ลงวันที่</th>
                        <th class="px-4 py-3 text-left">ความเร่งด่วน</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                        <th class="px-4 py-3 text-center">ไฟล์</th>
                        <th class="px-4 py-3 text-right">การกระทำ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="px-4 py-16 text-center text-slate-400 font-bold text-sm">
                            <i class="fa-solid fa-inbox text-3xl mb-3 block text-slate-200"></i>
                            ยังไม่มีเอกสารในหมวดนี้ — กด "สร้างใหม่" เพื่อเพิ่มรายการแรก
                        </td></tr>
                    <?php else: foreach ($rows as $r):
                        $st = $statusLabels[$r['status']] ?? ['label' => $r['status'], 'tone' => 'bg-slate-100 text-slate-600 border-slate-200'];
                    ?>
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="font-black text-slate-800 text-sm"><?= htmlspecialchars($r['doc_number'] ?: '— ฉบับร่าง —') ?></span>
                            </td>
                            <td class="px-4 py-3">
                                <a href="?section=edms&edms_view=detail&id=<?= (int)$r['id'] ?>" class="block group">
                                    <div class="font-bold text-slate-800 group-hover:text-sky-600 transition-colors line-clamp-1"><?= htmlspecialchars($r['subject']) ?></div>
                                    <?php if (!empty($r['sender'])): ?>
                                        <div class="text-[11px] font-bold text-slate-400 mt-0.5"><i class="fa-solid fa-arrow-right-from-bracket text-[8px] mr-1"></i><?= htmlspecialchars($r['sender']) ?></div>
                                    <?php elseif (!empty($r['recipient'])): ?>
                                        <div class="text-[11px] font-bold text-slate-400 mt-0.5 line-clamp-1"><i class="fa-solid fa-arrow-right-to-bracket text-[8px] mr-1"></i><?= htmlspecialchars($r['recipient']) ?></div>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600 whitespace-nowrap">
                                <?= $r['doc_date'] ? date('d/m/Y', strtotime($r['doc_date'])) : '-' ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if (!empty($r['priority_name'])):
                                    $color = $r['priority_color'] ?: 'slate';
                                ?>
                                    <span class="inline-flex px-2 py-0.5 rounded-full bg-<?= $color ?>-50 text-<?= $color ?>-700 border border-<?= $color ?>-100 text-[10px] font-black">
                                        <?= htmlspecialchars($r['priority_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $st['tone'] ?>">
                                    <?= htmlspecialchars($st['label']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ((int)$r['att_count'] > 0): ?>
                                    <span class="inline-flex items-center gap-1 text-xs font-black text-slate-500">
                                        <i class="fa-solid fa-paperclip"></i> <?= (int)$r['att_count'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-200 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="?section=edms&edms_view=detail&id=<?= (int)$r['id'] ?>"
                                   title="ดูรายละเอียด"
                                   class="text-sky-500 hover:bg-sky-50 px-2 py-1 rounded text-xs font-black"><i class="fa-solid fa-eye"></i></a>
                                <button onclick="edmsEdit(<?= (int)$r['id'] ?>)" title="แก้ไข"
                                    class="text-blue-500 hover:bg-blue-50 px-2 py-1 rounded text-xs font-black"><i class="fa-solid fa-pen"></i></button>
                                <button onclick="edmsDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['subject']), ENT_QUOTES) ?>')"
                                    title="ลบ"
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
            $btn = function($label, $target, $active=false, $disabled=false) use ($search, $status, $priority, $from, $to, $type) {
                $base = 'min-w-9 h-8 px-3 rounded-lg text-xs font-black flex items-center justify-center transition-all';
                if ($active) return "<span class='$base bg-sky-500 text-white'>$label</span>";
                if ($disabled) return "<span class='$base bg-slate-50 text-slate-300'>$label</span>";
                $qs = http_build_query([
                    'section'=>'edms','edms_view'=>'list','type'=>$type,'p'=>$target,
                    's'=>$search,'status'=>$status,'priority'=>$priority,'from'=>$from,'to'=>$to,
                ]);
                return "<a href='?$qs' class='$base bg-white border border-slate-200 text-slate-500 hover:border-sky-500 hover:text-sky-500'>$label</a>";
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

<!-- ════════════════ COMPOSE MODAL ════════════════ -->
<div id="edmsComposeModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
    <div id="edmsComposeBox" class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 <?= $tone['bg'] ?> rounded-xl border <?= $tone['border'] ?> flex items-center justify-center <?= $tone['text'] ?>">
                <i class="fa-solid <?= $meta['icon'] ?>"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h3 id="edmsComposeTitle" class="text-lg font-black text-slate-800">สร้าง <?= htmlspecialchars($meta['title']) ?>ใหม่</h3>
                <p class="text-[11px] font-bold text-slate-400">กรอกข้อมูลแล้วเลือกบันทึกเป็นร่าง หรือลงทะเบียนทันที</p>
            </div>
            <button onclick="edmsCloseCompose()" class="text-slate-400 hover:text-rose-500 w-8 h-8 rounded-lg hover:bg-slate-50 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body (scrollable) -->
        <div id="edmsComposeBody" class="px-6 py-5 flex-1 overflow-y-auto">
            <form id="edmsComposeForm" onsubmit="return false;">
                <input type="hidden" name="id" id="edmsId" value="">
                <input type="hidden" name="doc_type" value="<?= htmlspecialchars($type) ?>">

                <?php
                // คำนวณ flags ก่อน render — ใช้ใน label/placeholder ต่างๆ
                $_systemTypes = ['incoming','outgoing','internal','circular','task'];
                $_isCustomType = !in_array($type, $_systemTypes, true);
                $_isTask       = ($type === 'task');
                // Task ไม่ใช้ฟิลด์ทางการของจดหมาย — แสดง task-style แทน
                $_showReceived = !$_isTask && ($type === 'incoming' || $_isCustomType);
                $_showSender   = !$_isTask && (in_array($type, ['incoming','internal'], true) || $_isCustomType);
                $_showRecip    = !$_isTask && (in_array($type, ['outgoing','internal','circular'], true) || $_isCustomType);
                ?>

                <!-- Subject -->
                <div class="mb-4">
                    <label class="edms-label"><?= $_isTask ? 'ชื่องาน' : 'เรื่อง' ?> <span class="text-rose-500">*</span></label>
                    <input type="text" name="subject" id="edmsSubject" required class="edms-input"
                        placeholder="<?= $_isTask ? 'เช่น จัดเตรียมรายงานประจำเดือน / ตรวจสอบสต็อกยา' : 'เช่น ขอเชิญประชุม / ขออนุมัติงบประมาณ' ?>">
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="edms-label"><?= $_isTask ? 'วันที่สร้าง' : 'ลงวันที่' ?></label>
                        <input type="date" name="doc_date" id="edmsDocDate" class="edms-input" value="<?= date('Y-m-d') ?>">
                    </div>
                    <?php if ($_showReceived): ?>
                        <div>
                            <label class="edms-label">วันที่รับ</label>
                            <input type="date" name="received_date" id="edmsReceivedDate" class="edms-input" value="<?= date('Y-m-d') ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                    <?php if ($_showSender): ?>
                        <div>
                            <label class="edms-label">จาก (ผู้ส่ง/หน่วยงาน)</label>
                            <input type="text" name="sender" id="edmsSender" class="edms-input" placeholder="ชื่อหน่วยงาน/บุคคล">
                        </div>
                    <?php endif; ?>
                    <?php if ($_showRecip): ?>
                        <div class="<?= $type === 'circular' ? 'md:col-span-2' : '' ?>">
                            <label class="edms-label">เรียน (ผู้รับ)</label>
                            <input type="text" name="recipient" id="edmsRecipient" class="edms-input" placeholder="<?= $type === 'circular' ? 'หลายฝ่าย/บุคคล (คั่นด้วย ,)' : 'ชื่อหน่วยงาน/บุคคล' ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="edms-label">ความเร่งด่วน</label>
                        <select name="priority_id" id="edmsPriority" class="edms-input">
                            <option value="">— เลือก —</option>
                            <?php foreach ($priorities as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name'] . $_fmtSlaSuffix($p)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] font-bold text-slate-400 mt-1">
                            <i class="fa-solid fa-stopwatch text-[9px]"></i>
                            <?php
                                $_anyHasSla = false;
                                foreach ($priorities as $_pp) { if (!empty($_pp['ack_hours'])) { $_anyHasSla = true; break; } }
                                if ($_anyHasSla):
                            ?>
                                เวลา SLA อ้างอิงจาก<a href="?section=edms&edms_view=sla_policies" class="text-purple-600 hover:underline">นโยบาย SLA</a>
                            <?php else: ?>
                                ยังไม่มีนโยบาย SLA สำหรับประเภท <?= htmlspecialchars($type) ?> — <a href="?section=edms&edms_view=sla_policies" class="text-purple-600 hover:underline">ตั้งค่า</a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <label class="edms-label">ชั้นความลับ</label>
                        <select name="confidentiality" id="edmsConfidentiality" class="edms-input">
                            <?php foreach ($confidentialityLabels as $k => $v): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="edms-label"><?= $_isTask ? 'รายละเอียดงาน' : 'สรุปย่อ' ?></label>
                    <textarea name="summary" id="edmsSummary" rows="<?= $_isTask ? 3 : 2 ?>" class="edms-input"
                        placeholder="<?= $_isTask ? 'อธิบายงานที่ต้องทำ ขอบเขต ผลลัพธ์ที่ต้องการ' : 'สรุปสั้น ๆ ใช้แสดงในรายการ' ?>"></textarea>
                </div>

                <div class="mb-4">
                    <label class="edms-label">เนื้อหา</label>
                    <textarea name="body" id="edmsBody" rows="5" class="edms-input" placeholder="เนื้อหาเอกสารแบบเต็ม (ไม่บังคับ)"></textarea>
                </div>

                <!-- Attachments -->
                <div class="mb-4">
                    <label class="edms-label">ไฟล์แนบ <span class="text-slate-400 font-bold normal-case">(PDF, รูป, Office, สูงสุด 20MB/ไฟล์)</span></label>
                    <input type="file" name="files[]" id="edmsFiles" multiple
                           accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip"
                           class="edms-input">
                    <p id="edmsFilesHint" class="text-[11px] text-slate-400 font-bold mt-1.5 hidden"></p>
                    <div id="edmsExistingAttachments" class="mt-3 space-y-1.5"></div>
                </div>
            </form>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center gap-2 flex-wrap">
            <button type="button" onclick="edmsCloseCompose()"
                class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-600 text-sm font-black hover:bg-slate-50">
                ยกเลิก
            </button>
            <div class="flex-1"></div>
            <button type="button" onclick="edmsSave('draft')"
                class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-sm font-black hover:bg-slate-200 flex items-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i> บันทึกฉบับร่าง
            </button>
            <button type="button" onclick="edmsSave('registered')"
                class="<?= $tone['btn'] ?> text-white px-4 py-2.5 rounded-xl text-sm font-black flex items-center gap-2 shadow-sm transition-colors">
                <i class="fa-solid fa-stamp"></i> ลงทะเบียน
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const DOC_TYPE = <?= json_encode($type) ?>;
    const modal = document.getElementById('edmsComposeModal');
    const titleEl = document.getElementById('edmsComposeTitle');

    window.edmsOpenCompose = function(prefill = null) {
        document.getElementById('edmsId').value = prefill?.id || '';
        document.getElementById('edmsSubject').value = prefill?.subject || '';
        document.getElementById('edmsDocDate').value = prefill?.doc_date || '<?= date('Y-m-d') ?>';
        const recvEl = document.getElementById('edmsReceivedDate');
        if (recvEl) recvEl.value = prefill?.received_date || '<?= date('Y-m-d') ?>';
        const sndEl = document.getElementById('edmsSender');
        if (sndEl) sndEl.value = prefill?.sender || '';
        const rcptEl = document.getElementById('edmsRecipient');
        if (rcptEl) rcptEl.value = prefill?.recipient || '';
        document.getElementById('edmsPriority').value = prefill?.priority_id || '';
        document.getElementById('edmsConfidentiality').value = prefill?.confidentiality || 'normal';
        document.getElementById('edmsSummary').value = prefill?.summary || '';
        document.getElementById('edmsBody').value = prefill?.body || '';
        document.getElementById('edmsFiles').value = '';

        const existingBox = document.getElementById('edmsExistingAttachments');
        existingBox.innerHTML = '';
        if (prefill?.attachments?.length) {
            prefill.attachments.forEach(a => {
                const row = document.createElement('div');
                row.className = 'flex items-center gap-2 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-600';
                row.innerHTML = `
                    <i class="fa-solid fa-paperclip text-slate-400"></i>
                    <span class="flex-1 truncate">${escapeHtml(a.file_name)}</span>
                    <span class="text-[10px] text-slate-400">${formatSize(a.file_size)}</span>
                    <a href="edms_file.php?id=${a.id}" target="_blank" class="text-sky-500 hover:bg-sky-50 px-2 py-1 rounded"><i class="fa-solid fa-eye"></i></a>
                    <button type="button" onclick="edmsDeleteAttachment(${a.id}, this)" class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded"><i class="fa-solid fa-trash"></i></button>
                `;
                existingBox.appendChild(row);
            });
        }

        titleEl.textContent = prefill?.id
            ? `แก้ไข: ${prefill.doc_number || 'ฉบับร่าง'}`
            : 'สร้าง <?= addslashes($meta['title']) ?>ใหม่';

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    window.edmsCloseCompose = function() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function formatSize(bytes) {
        bytes = parseInt(bytes || 0, 10);
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    async function edmsPost(entity, action, data, isMultipart = false) {
        let body;
        if (isMultipart) {
            body = data;
            body.append('entity', entity);
            body.append('action', action);
            body.append('csrf_token', portal_CSRF);
        } else {
            body = new FormData();
            body.append('entity', entity);
            body.append('action', action);
            body.append('csrf_token', portal_CSRF);
            Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
        }
        const res = await fetch('ajax_edms.php', { method: 'POST', body });
        return res.json();
    }
    window.edmsPost = edmsPost; // expose สำหรับ detail.php

    window.edmsSave = async function(status) {
        const id = document.getElementById('edmsId').value;
        const subject = document.getElementById('edmsSubject').value.trim();
        if (!subject) {
            Swal.fire({ icon: 'warning', title: 'กรุณาระบุเรื่อง', confirmButtonText: 'ตกลง' });
            return;
        }

        const data = {
            doc_type: DOC_TYPE,
            subject,
            doc_date: document.getElementById('edmsDocDate').value,
            received_date: document.getElementById('edmsReceivedDate')?.value || '',
            sender: document.getElementById('edmsSender')?.value || '',
            recipient: document.getElementById('edmsRecipient')?.value || '',
            priority_id: document.getElementById('edmsPriority').value,
            confidentiality: document.getElementById('edmsConfidentiality').value,
            summary: document.getElementById('edmsSummary').value,
            body: document.getElementById('edmsBody').value,
            status,
        };
        if (id) data.id = id;

        const res = await edmsPost('document', id ? 'update' : 'create', data);
        if (!res.ok) {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: res.message || '' });
            return;
        }

        const docId = id || res.id;

        // Upload new files (ถ้ามี)
        const fileInput = document.getElementById('edmsFiles');
        if (fileInput.files && fileInput.files.length > 0) {
            const fd = new FormData();
            fd.append('doc_id', docId);
            for (const f of fileInput.files) fd.append('files[]', f);
            const upRes = await edmsPost('attachment', 'upload', fd, true);
            if (!upRes.ok) {
                await Swal.fire({ icon: 'warning', title: 'อัปโหลดไฟล์บางส่วนไม่สำเร็จ', text: upRes.message || '' });
            }
        }

        await Swal.fire({
            icon: 'success',
            title: res.message || 'บันทึกแล้ว',
            timer: 1200,
            showConfirmButton: false,
        });
        window.location.reload();
    };

    window.edmsEdit = async function(id) {
        const res = await edmsPost('document', 'get', { id });
        if (!res.ok) {
            Swal.fire({ icon: 'error', title: 'โหลดข้อมูลไม่สำเร็จ', text: res.message || '' });
            return;
        }
        edmsOpenCompose(res.data);
    };

    window.edmsDelete = async function(id, subject) {
        const c = await Swal.fire({
            title: 'ยืนยันการลบเอกสาร?',
            text: subject,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#e11d48',
        });
        if (!c.isConfirmed) return;
        const res = await edmsPost('document', 'delete', { id });
        if (res.ok) {
            await Swal.fire({ icon: 'success', title: 'ลบแล้ว', timer: 1000, showConfirmButton: false });
            window.location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
        }
    };

    window.edmsDeleteAttachment = async function(id, btn) {
        const c = await Swal.fire({
            title: 'ลบไฟล์แนบ?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#e11d48',
        });
        if (!c.isConfirmed) return;
        const res = await edmsPost('attachment', 'delete', { id });
        if (res.ok) {
            btn.closest('.flex.items-center').remove();
        } else {
            Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
        }
    };

    // Click-outside to close
    modal.addEventListener('click', e => { if (e.target === modal) edmsCloseCompose(); });

    // Auto-open edit modal when redirected from detail page
    const autoEditId = sessionStorage.getItem('edmsAutoEdit');
    if (autoEditId) {
        sessionStorage.removeItem('edmsAutoEdit');
        edmsEdit(parseInt(autoEditId, 10));
    }
})();
</script>
