<?php
/**
 * portal/_partials/edms/detail.php
 * รายละเอียดเอกสาร 1 ฉบับ + ไฟล์แนบ + viewer (PDF.js / image)
 *
 * Query: ?section=edms&edms_view=detail&id=<id>
 */
declare(strict_types=1);

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo '<div class="max-w-3xl mx-auto px-4 py-12 text-center">';
    echo '  <i class="fa-solid fa-circle-exclamation text-rose-400 text-4xl mb-3"></i>';
    echo '  <p class="font-black text-slate-700">ไม่พบรหัสเอกสาร</p>';
    echo '  <a href="?section=edms" class="inline-flex mt-4 text-sm font-black text-sky-600 hover:underline">← กลับหน้าหลัก EDMS</a>';
    echo '</div>';
    return;
}

$doc = null;
$attachments = [];
$logs = [];
$routings = [];
$activeStaff = [];
$myPendingRouteId = null;
$currentUserId = (int)($_SESSION['admin_id'] ?? 0);

try {
    $stmt = $pdo->prepare("
        SELECT d.*,
               cat.name AS priority_name,
               cat.color AS priority_color,
               s.full_name AS created_by_name,
               u.full_name AS updated_by_name
        FROM sys_doc_documents d
        LEFT JOIN sys_doc_categories cat ON cat.id = d.priority_id
        LEFT JOIN sys_staff s ON s.id = d.created_by
        LEFT JOIN sys_staff u ON u.id = d.updated_by
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
        // Show only the current version of each chain. version_no + total versions
        // are computed so the row badge can read "v3" + "(3 เวอร์ชัน)".
        $att = $pdo->prepare("SELECT a.id, a.file_name, a.stored_path, a.mime_type, a.file_size,
                                     a.uploaded_at, a.uploaded_by, a.root_id, a.version_no,
                                     COALESCE(a.role, 'supporting') AS role,
                                     s.full_name AS uploader_name,
                                     (SELECT COUNT(*) FROM sys_doc_attachments x
                                      WHERE x.id = COALESCE(a.root_id, a.id)
                                         OR x.root_id = COALESCE(a.root_id, a.id)) AS total_versions
            FROM sys_doc_attachments a
            LEFT JOIN sys_staff s ON s.id = a.uploaded_by
            WHERE a.doc_id = ? AND a.is_current = 1
            ORDER BY (a.role = 'primary') DESC, a.id ASC");
        $att->execute([$id]);
        $attachments = $att->fetchAll(PDO::FETCH_ASSOC);
        $primaryAttachments    = array_values(array_filter($attachments, fn($a) => ($a['role'] ?? '') === 'primary'));
        $supportingAttachments = array_values(array_filter($attachments, fn($a) => ($a['role'] ?? '') !== 'primary'));

        $lg = $pdo->prepare("
            SELECT l.action, l.detail, l.created_at, s.full_name AS user_name
            FROM sys_doc_logs l
            LEFT JOIN sys_staff s ON s.id = l.user_id
            WHERE l.doc_id = ?
            ORDER BY l.created_at DESC
            LIMIT 30
        ");
        $lg->execute([$id]);
        $logs = $lg->fetchAll(PDO::FETCH_ASSOC);

        $rt = $pdo->prepare("
            SELECT r.*,
                   sf.full_name AS from_name,
                   st.full_name AS to_name
            FROM sys_doc_routings r
            LEFT JOIN sys_staff sf ON sf.id = r.from_user_id
            LEFT JOIN sys_staff st ON st.id = r.to_user_id
            WHERE r.doc_id = ?
            ORDER BY r.created_at ASC
        ");
        $rt->execute([$id]);
        $routings = $rt->fetchAll(PDO::FETCH_ASSOC);

        // หา routing ที่ปัจจุบัน user ต้องดำเนินการ
        foreach ($routings as $r) {
            if ((int)$r['to_user_id'] === $currentUserId && in_array($r['status'], ['pending','acknowledged'], true)) {
                $myPendingRouteId = (int)$r['id'];
                break;
            }
        }

        // Active staff สำหรับ dropdown — exclude ตัวเองออก
        $st2 = $pdo->prepare("SELECT id, full_name, username FROM sys_staff WHERE account_status = 'active' AND id != ? ORDER BY full_name ASC");
        $st2->execute([$currentUserId ?: 0]);
        $activeStaff = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // ignore
}

if (!$doc) {
    echo '<div class="max-w-3xl mx-auto px-4 py-12 text-center">';
    echo '  <i class="fa-solid fa-circle-question text-slate-400 text-4xl mb-3"></i>';
    echo '  <p class="font-black text-slate-700">ไม่พบเอกสาร</p>';
    echo '  <a href="?section=edms" class="inline-flex mt-4 text-sm font-black text-sky-600 hover:underline">← กลับหน้าหลัก EDMS</a>';
    echo '</div>';
    return;
}

$typeMap = [
    'incoming' => ['title' => 'หนังสือรับ',     'icon' => 'fa-inbox',       'tone' => 'sky'],
    'outgoing' => ['title' => 'หนังสือส่ง',     'icon' => 'fa-paper-plane', 'tone' => 'emerald'],
    'internal' => ['title' => 'บันทึกข้อความ',  'icon' => 'fa-file-lines',  'tone' => 'violet'],
    'circular' => ['title' => 'หนังสือเวียน',   'icon' => 'fa-bullhorn',    'tone' => 'amber'],
];
$tonePalette = [
    'sky'     => ['bg' => 'bg-sky-50',     'text' => 'text-sky-600',     'border' => 'border-sky-100',     'btn' => 'bg-sky-500 hover:bg-sky-600'],
    'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'border' => 'border-emerald-100', 'btn' => 'bg-emerald-500 hover:bg-emerald-600'],
    'violet'  => ['bg' => 'bg-purple-50',  'text' => 'text-purple-600',  'border' => 'border-purple-100',  'btn' => 'bg-purple-500 hover:bg-purple-600'],
    'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'border' => 'border-amber-100',   'btn' => 'bg-amber-500 hover:bg-amber-600'],
];

$meta = $typeMap[$doc['doc_type']] ?? $typeMap['incoming'];
$tone = $tonePalette[$meta['tone']];

$statusLabels = [
    'draft'       => ['label' => 'ฉบับร่าง',       'tone' => 'bg-slate-100 text-slate-600 border-slate-200'],
    'registered'  => ['label' => 'ลงทะเบียนแล้ว',  'tone' => 'bg-sky-50 text-sky-700 border-sky-200'],
    'routing'     => ['label' => 'อยู่ระหว่างโอน', 'tone' => 'bg-purple-50 text-purple-700 border-purple-200'],
    'in_progress' => ['label' => 'ดำเนินการ',      'tone' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'completed'   => ['label' => 'เสร็จสิ้น',       'tone' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'archived'    => ['label' => 'เก็บแฟ้ม',       'tone' => 'bg-slate-50 text-slate-500 border-slate-200'],
    'cancelled'   => ['label' => 'ยกเลิก',          'tone' => 'bg-rose-50 text-rose-600 border-rose-200'],
];
$confLabels = [
    'normal'        => 'ปกติ',
    'confidential'  => 'ลับ',
    'secret'        => 'ลับมาก',
    'top_secret'    => 'ลับที่สุด',
];

$st = $statusLabels[$doc['status']] ?? ['label' => $doc['status'], 'tone' => 'bg-slate-100 text-slate-600 border-slate-200'];

function edms_format_bytes(int $b): string
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1024 * 1024) return number_format($b / 1024, 1) . ' KB';
    return number_format($b / 1024 / 1024, 1) . ' MB';
}

function edms_log_label(string $action): string
{
    return match($action) {
        'create'      => 'สร้างเอกสาร',
        'update'      => 'แก้ไข',
        'attach'      => 'เพิ่มไฟล์แนบ',
        'detach'      => 'ลบไฟล์แนบ',
        'archive'     => 'เก็บเข้าแฟ้ม',
        'cancel'      => 'ยกเลิก',
        'cancelled'   => 'ยกเลิก',
        'archived'    => 'เก็บเข้าแฟ้ม',
        'complete'    => 'ปิดเรื่อง',
        'route'       => 'โอนเอกสาร',
        'route_ack'   => 'รับทราบเอกสาร',
        'route_done'  => 'ดำเนินการเสร็จสิ้น',
        'route_return'=> 'ตีกลับเอกสาร',
        default       => $action,
    };
}

$routingActionLabels = [
    'forward' => ['label' => 'ส่งต่อ',     'icon' => 'fa-share',         'tone' => 'sky'],
    'assign'  => ['label' => 'มอบหมาย',     'icon' => 'fa-user-plus',     'tone' => 'violet'],
    'approve' => ['label' => 'อนุมัติ',     'icon' => 'fa-circle-check',  'tone' => 'emerald'],
    'sign'    => ['label' => 'ลงนาม',       'icon' => 'fa-signature',     'tone' => 'amber'],
    'return'  => ['label' => 'ตีกลับ',     'icon' => 'fa-rotate-left',   'tone' => 'rose'],
    'note'    => ['label' => 'บันทึก',     'icon' => 'fa-note-sticky',   'tone' => 'slate'],
    'close'   => ['label' => 'ปิดเรื่อง',  'icon' => 'fa-flag-checkered','tone' => 'slate'],
];

$routingStatusLabels = [
    'pending'      => ['label' => 'รอดำเนินการ',   'tone' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'acknowledged' => ['label' => 'รับทราบแล้ว',   'tone' => 'bg-sky-50 text-sky-700 border-sky-200'],
    'done'         => ['label' => 'เสร็จสิ้น',     'tone' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'returned'     => ['label' => 'ตีกลับ',        'tone' => 'bg-rose-50 text-rose-700 border-rose-200'],
];
?>
<style>
#edmsViewerModal { z-index: 200; }
#edmsViewerBox { max-height: 92vh; }
#edmsViewerFrame { min-height: 0; }
#edmsRoutingModal { z-index: 200; }
#edmsRoutingBox { max-height: 90vh; }
.edms-input { display:block; width:100%; padding: 10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-weight: 600; color:#1e293b; outline: none; transition: all .15s; }
.edms-input:focus { border-color: #8b5cf6; background:#fff; box-shadow: 0 0 0 3px rgba(139,92,246,.12); }
.edms-label { display:block; font-size: 11px; font-weight: 800; color:#64748b; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
</style>

<div class="max-w-5xl mx-auto px-4 md:px-6 py-6">
    <a href="?section=edms&edms_view=list&type=<?= urlencode($doc['doc_type']) ?>" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ <?= htmlspecialchars($meta['title']) ?>
    </a>

    <!-- Header card -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-5">
        <div class="px-6 py-5 border-b border-slate-100 flex items-start gap-4">
            <div class="w-12 h-12 <?= $tone['bg'] ?> rounded-2xl border <?= $tone['border'] ?> flex items-center justify-center <?= $tone['text'] ?> text-xl shrink-0">
                <i class="fa-solid <?= $meta['icon'] ?>"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                    <span class="text-[11px] font-black uppercase tracking-widest <?= $tone['text'] ?>"><?= htmlspecialchars($meta['title']) ?></span>
                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $st['tone'] ?>"><?= htmlspecialchars($st['label']) ?></span>
                    <?php if (!empty($doc['priority_name'])): $pc = $doc['priority_color'] ?: 'slate'; ?>
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-<?= $pc ?>-50 text-<?= $pc ?>-700 border border-<?= $pc ?>-100 text-[10px] font-black"><?= htmlspecialchars($doc['priority_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($doc['confidentiality'] !== 'normal'): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-100 text-[10px] font-black">
                            <i class="fa-solid fa-lock text-[8px]"></i> <?= htmlspecialchars($confLabels[$doc['confidentiality']] ?? $doc['confidentiality']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <h1 class="text-xl md:text-2xl font-black text-slate-800 leading-tight"><?= htmlspecialchars($doc['subject']) ?></h1>
                <p class="text-sm font-bold text-slate-500 mt-1">
                    <?php if ($doc['doc_number']): ?>
                        เลขที่ <span class="text-slate-700"><?= htmlspecialchars($doc['doc_number']) ?></span>
                    <?php else: ?>
                        <span class="text-amber-600">— ฉบับร่าง (ยังไม่ได้ลงทะเบียน)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 flex-wrap justify-end">
                <?php if (in_array($doc['status'], ['registered','routing','in_progress'], true)): ?>
                    <button onclick="edmsOpenRouting()" title="โอน/มอบหมาย" class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 shadow-sm transition-colors">
                        <i class="fa-solid fa-share"></i> โอน/มอบหมาย
                    </button>
                <?php endif; ?>
                <?php if (in_array($doc['status'], ['routing','in_progress'], true)): ?>
                    <button onclick="edmsCompleteDoc(<?= $id ?>)" title="ปิดเรื่อง" class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 shadow-sm transition-colors">
                        <i class="fa-solid fa-flag-checkered"></i> ปิดเรื่อง
                    </button>
                <?php endif; ?>
                <?php if (!in_array($doc['status'], ['archived','cancelled'], true)): ?>
                    <button onclick="edmsArchive(<?= $id ?>)" title="เก็บเข้าแฟ้ม" class="text-slate-500 hover:bg-slate-100 px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-box-archive"></i> เก็บแฟ้ม
                    </button>
                <?php endif; ?>
                <a href="?section=edms&edms_view=list&type=<?= urlencode($doc['doc_type']) ?>&_edit=<?= $id ?>"
                   id="edmsEditLink"
                   class="<?= $tone['btn'] ?> text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-pen"></i> แก้ไข
                </a>
            </div>
        </div>

        <!-- Metadata grid -->
        <div class="px-6 py-5 grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">ลงวันที่</p>
                <p class="text-sm font-black text-slate-700"><?= $doc['doc_date'] ? date('d/m/Y', strtotime($doc['doc_date'])) : '-' ?></p>
            </div>
            <?php if ($doc['doc_type'] === 'incoming'): ?>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">วันที่รับ</p>
                    <p class="text-sm font-black text-slate-700"><?= $doc['received_date'] ? date('d/m/Y', strtotime($doc['received_date'])) : '-' ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($doc['sender'])): ?>
                <div class="col-span-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">จาก</p>
                    <p class="text-sm font-black text-slate-700"><?= htmlspecialchars($doc['sender']) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($doc['recipient'])): ?>
                <div class="col-span-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">เรียน</p>
                    <p class="text-sm font-black text-slate-700"><?= htmlspecialchars($doc['recipient']) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($doc['summary'])): ?>
                <div class="col-span-2 md:col-span-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">สรุปย่อ</p>
                    <p class="text-sm font-bold text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($doc['summary'])) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($doc['body'])): ?>
                <div class="col-span-2 md:col-span-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">เนื้อหา</p>
                    <div class="text-sm font-medium text-slate-700 leading-relaxed bg-slate-50 rounded-2xl p-4 whitespace-pre-wrap"><?= htmlspecialchars($doc['body']) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer meta -->
        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 text-[11px] font-bold text-slate-400 flex items-center gap-3 flex-wrap">
            <span><i class="fa-regular fa-user mr-1"></i>สร้างโดย <?= htmlspecialchars($doc['created_by_name'] ?: '-') ?></span>
            <span class="text-slate-300">•</span>
            <span><i class="fa-regular fa-clock mr-1"></i><?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?></span>
            <?php if ($doc['updated_at'] && $doc['updated_at'] !== $doc['created_at']): ?>
                <span class="text-slate-300">•</span>
                <span>แก้ไขล่าสุด <?= date('d/m/Y H:i', strtotime($doc['updated_at'])) ?> โดย <?= htmlspecialchars($doc['updated_by_name'] ?: '-') ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($myPendingRouteId): ?>
        <!-- My Pending Action -->
        <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-3xl border-2 border-amber-200 shadow-sm p-5 mb-5 flex items-center gap-4 flex-wrap">
            <div class="w-12 h-12 bg-amber-500 text-white rounded-2xl shadow-sm flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-bell"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-black uppercase tracking-widest text-amber-700">รอดำเนินการ</p>
                <p class="text-sm font-black text-slate-800 mt-0.5">เอกสารนี้ถูกมอบหมายให้คุณ — กรุณาดำเนินการ</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button onclick="edmsRouteAck(<?= $myPendingRouteId ?>)" class="bg-sky-500 hover:bg-sky-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-eye"></i> รับทราบ
                </button>
                <button onclick="edmsRouteComplete(<?= $myPendingRouteId ?>)" class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-circle-check"></i> เสร็จสิ้น
                </button>
                <button onclick="edmsRouteReturn(<?= $myPendingRouteId ?>)" class="bg-rose-500 hover:bg-rose-600 text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-rotate-left"></i> ตีกลับ
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Routing Timeline (เต็มกว้าง) -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 mb-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-share-nodes text-purple-500"></i> เส้นทางการโอน
                <span class="text-xs font-bold text-slate-400">(<?= count($routings) ?>)</span>
            </h3>
            <?php if (in_array($doc['status'], ['registered','routing','in_progress'], true)): ?>
                <button onclick="edmsOpenRouting()" class="bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 px-3 py-1.5 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-plus"></i> โอนต่อ
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($routings)): ?>
            <div class="text-center py-10 px-4">
                <i class="fa-solid fa-share-nodes text-slate-200 text-4xl mb-3 block"></i>
                <p class="text-sm font-black text-slate-500 mb-1">ยังไม่มีการโอนเอกสาร</p>
                <p class="text-xs font-bold text-slate-400">กดปุ่ม "โอน/มอบหมาย" เพื่อส่งต่อให้ผู้รับผิดชอบ</p>
            </div>
        <?php else: ?>
            <div class="relative pl-8">
                <div class="absolute left-3 top-2 bottom-2 w-px bg-slate-200"></div>
                <ul class="space-y-4">
                    <?php foreach ($routings as $r):
                        $rAct = $routingActionLabels[$r['action']] ?? ['label' => $r['action'], 'icon' => 'fa-share', 'tone' => 'slate'];
                        $rSt  = $routingStatusLabels[$r['status']] ?? ['label' => $r['status'], 'tone' => 'bg-slate-50 text-slate-600 border-slate-200'];
                        $isOverdue = $r['due_date'] && in_array($r['status'], ['pending','acknowledged'], true) && $r['due_date'] < date('Y-m-d');
                    ?>
                        <li class="relative">
                            <div class="absolute -left-8 top-1 w-7 h-7 rounded-full bg-<?= $rAct['tone'] ?>-100 text-<?= $rAct['tone'] ?>-600 border-2 border-white shadow-sm flex items-center justify-center text-xs">
                                <i class="fa-solid <?= $rAct['icon'] ?>"></i>
                            </div>
                            <div class="bg-slate-50 rounded-2xl border border-slate-100 p-4">
                                <div class="flex items-start justify-between gap-3 flex-wrap mb-2">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-<?= $rAct['tone'] ?>-50 text-<?= $rAct['tone'] ?>-700 border border-<?= $rAct['tone'] ?>-100 text-[10px] font-black">
                                            <?= htmlspecialchars($rAct['label']) ?>
                                        </span>
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-black border <?= $rSt['tone'] ?>"><?= htmlspecialchars($rSt['label']) ?></span>
                                        <?php if ($isOverdue): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 border border-rose-200 text-[10px] font-black">
                                                <i class="fa-solid fa-circle-exclamation text-[8px]"></i> เกินกำหนด
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[10px] font-bold text-slate-400">
                                        <i class="fa-regular fa-clock text-[8px] mr-0.5"></i>
                                        <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                                    </span>
                                </div>
                                <p class="text-sm font-black text-slate-700">
                                    <span class="text-slate-500 font-bold">จาก</span> <?= htmlspecialchars($r['from_name'] ?: 'ระบบ') ?>
                                    <i class="fa-solid fa-arrow-right text-[10px] text-slate-300 mx-1.5"></i>
                                    <span class="text-slate-500 font-bold">ถึง</span> <?= htmlspecialchars($r['to_name'] ?: ($r['to_dept'] ?: '-')) ?>
                                </p>
                                <?php if (!empty($r['comment'])): ?>
                                    <p class="text-xs font-medium text-slate-600 mt-2 leading-relaxed bg-white border border-slate-100 rounded-xl px-3 py-2">
                                        <i class="fa-solid fa-quote-left text-[10px] text-slate-300 mr-1"></i><?= nl2br(htmlspecialchars($r['comment'])) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($r['due_date'])): ?>
                                    <p class="text-[11px] font-black text-<?= $isOverdue ? 'rose' : 'slate' ?>-500 mt-2">
                                        <i class="fa-solid fa-flag mr-1"></i>กำหนด: <?= date('d/m/Y', strtotime($r['due_date'])) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($r['completed_at'])): ?>
                                    <p class="text-[11px] font-bold text-slate-400 mt-1">
                                        <i class="fa-solid fa-check mr-1"></i>ปิดเมื่อ <?= date('d/m/Y H:i', strtotime($r['completed_at'])) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <!-- Attachments -->
        <div class="md:col-span-2 bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-paperclip <?= $tone['text'] ?>"></i> ไฟล์แนบ
                    <span class="text-xs font-bold text-slate-400">(<?= count($attachments) ?>)</span>
                </h3>
                <div class="flex items-center gap-2">
                    <input type="file" id="edmsAddFiles" multiple class="hidden"
                        accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt">
                    <button type="button" onclick="document.getElementById('edmsAddFiles').click()"
                        class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1.5 rounded-xl text-xs font-black inline-flex items-center gap-1.5 shadow-sm transition-colors">
                        <i class="fa-solid fa-cloud-arrow-up"></i> อัปโหลดไฟล์เพิ่ม
                    </button>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 font-medium mb-3 leading-relaxed">
                <i class="fa-solid fa-circle-info text-slate-300"></i>
                <strong>เอกสารหลัก</strong> = ไฟล์ที่ใช้แทนเอกสารฉบับนั้น (1 ตัวต่อเอกสาร) อัปโหลดเวอร์ชันใหม่เพื่อแทนที่ ·
                <strong>ไฟล์ประกอบ</strong> = รูปถ่าย หลักฐาน เอกสารอ้างอิง ฯลฯ ใส่ได้หลายไฟล์
            </p>
            <?php
            // Shared row renderer so primary + supporting sections look identical
            $renderAttachmentRow = function(array $a) {
                $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['png','jpg','jpeg','gif','webp'], true);
                $isPdf   = ($ext === 'pdf');
                $iconClass = $isPdf ? 'fa-file-pdf text-rose-500' : ($isImage ? 'fa-file-image text-purple-500' : 'fa-file text-slate-400');
                $uploader = trim((string)($a['uploader_name'] ?? '')) ?: 'ระบบ';
                $vNum = (int)$a['version_no'];
                $vTotal = (int)$a['total_versions'];
                $isPrimary = ($a['role'] ?? '') === 'primary';
                $rowBg = $isPrimary ? 'bg-amber-50/60 hover:bg-amber-50 border-amber-200' : 'bg-slate-50 hover:bg-slate-100 border-slate-100';
                $canDelete = (int)$a['uploaded_by'] === (int)($_SESSION['admin_id'] ?? 0) || ($_SESSION['admin_role'] ?? '') === 'superadmin';
                $jsName = htmlspecialchars(addslashes($a['file_name']), ENT_QUOTES);
                ?>
                <div class="flex items-center gap-3 px-3 py-2.5 <?= $rowBg ?> rounded-2xl border transition-colors">
                    <i class="fa-solid <?= $iconClass ?> text-lg"></i>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <p class="text-sm font-black text-slate-700 truncate"><?= htmlspecialchars($a['file_name']) ?></p>
                            <?php if ($vNum > 1 || $vTotal > 1): ?>
                                <span class="inline-flex items-center text-[9px] font-black px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 border border-amber-200" title="<?= $vTotal ?> เวอร์ชัน">v<?= $vNum ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 truncate">
                            <?= edms_format_bytes((int)$a['file_size']) ?>
                            · <?= date('d/m/Y H:i', strtotime($a['uploaded_at'])) ?>
                            · โดย <span class="text-slate-500"><?= htmlspecialchars($uploader) ?></span>
                        </p>
                    </div>
                    <button onclick="edmsToggleRole(<?= (int)$a['id'] ?>, '<?= $isPrimary ? 'supporting' : 'primary' ?>')"
                        class="<?= $isPrimary ? 'text-amber-500 hover:bg-amber-100' : 'text-slate-300 hover:text-amber-500 hover:bg-amber-50' ?> px-2.5 py-1 rounded-lg text-xs font-black"
                        title="<?= $isPrimary ? 'ปลดออกจากเอกสารหลัก' : 'ตั้งเป็นเอกสารหลัก (มีได้ 1 ตัวต่อเอกสาร)' ?>">
                        <i class="fa-solid fa-star"></i>
                    </button>
                    <?php if ($vTotal > 1): ?>
                        <button onclick="edmsShowVersions(<?= (int)$a['id'] ?>, '<?= $jsName ?>')"
                            class="text-amber-600 hover:bg-amber-100 px-2.5 py-1 rounded-lg text-xs font-black"
                            title="ดูประวัติเวอร์ชัน (<?= $vTotal ?> เวอร์ชัน)">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </button>
                    <?php endif; ?>
                    <button onclick="edmsUploadVersion(<?= (int)$a['id'] ?>, '<?= $jsName ?>')"
                        class="text-purple-500 hover:bg-purple-100 px-2.5 py-1 rounded-lg text-xs font-black"
                        title="อัปโหลดเวอร์ชันใหม่ (แทนไฟล์เดิม)">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>
                    <?php if ($isPdf || $isImage): ?>
                        <button onclick="edmsViewer(<?= (int)$a['id'] ?>, '<?= $jsName ?>', '<?= $isPdf ? 'pdf' : 'image' ?>')"
                            class="text-sky-500 hover:bg-sky-100 px-2.5 py-1 rounded-lg text-xs font-black"
                            title="ดูในเบราว์เซอร์">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    <?php endif; ?>
                    <a href="edms_file.php?id=<?= (int)$a['id'] ?>&disposition=attachment"
                       class="text-emerald-500 hover:bg-emerald-100 px-2.5 py-1 rounded-lg text-xs font-black"
                       title="ดาวน์โหลด">
                        <i class="fa-solid fa-download"></i>
                    </a>
                    <?php if ($canDelete): ?>
                    <button onclick="edmsDeleteAttachment(<?= (int)$a['id'] ?>, '<?= $jsName ?>')"
                        class="text-rose-400 hover:bg-rose-100 px-2.5 py-1 rounded-lg text-xs font-black"
                        title="ลบไฟล์ (เฉพาะที่ฉันอัปโหลดเอง)">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php
            };
            ?>

            <?php if (empty($attachments)): ?>
                <p class="text-center text-sm font-bold text-slate-400 py-6">— ยังไม่มีไฟล์แนบ —</p>
            <?php else: ?>
                <!-- Primary section -->
                <div class="mb-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-700 mb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-star text-[9px]"></i> เอกสารหลัก
                        <span class="text-slate-300 font-bold normal-case tracking-normal">·
                            <?= empty($primaryAttachments) ? 'ยังไม่ได้กำหนด' : '1 ไฟล์' ?></span>
                    </p>
                    <?php if (empty($primaryAttachments)): ?>
                        <div class="text-[11px] font-bold text-slate-400 italic px-3 py-3 bg-amber-50/40 border border-dashed border-amber-200 rounded-2xl text-center">
                            กดดาว <i class="fa-solid fa-star text-amber-400"></i> ที่ไฟล์ประกอบเพื่อเลือกเป็นเอกสารหลัก
                        </div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($primaryAttachments as $a) $renderAttachmentRow($a); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Supporting section -->
                <?php if (!empty($supportingAttachments)): ?>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-paperclip text-[9px]"></i> ไฟล์ประกอบ
                        <span class="text-slate-300 font-bold normal-case tracking-normal">· <?= count($supportingAttachments) ?> ไฟล์</span>
                    </p>
                    <div class="space-y-2">
                        <?php foreach ($supportingAttachments as $a) $renderAttachmentRow($a); ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Activity log -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-black text-slate-800 mb-3 flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-slate-500"></i> ประวัติ
            </h3>
            <?php if (empty($logs)): ?>
                <p class="text-center text-sm font-bold text-slate-400 py-4">— ไม่มีประวัติ —</p>
            <?php else: ?>
                <div class="relative">
                    <div class="absolute left-2.5 top-2 bottom-2 w-px bg-slate-200"></div>
                    <ul class="space-y-3.5">
                        <?php foreach ($logs as $log): ?>
                            <li class="relative pl-8">
                                <div class="absolute left-1 top-1 w-3 h-3 rounded-full bg-white border-2 <?= $tone['border'] ?> shadow-sm"></div>
                                <p class="text-xs font-black text-slate-700"><?= htmlspecialchars(edms_log_label($log['action'])) ?></p>
                                <p class="text-[10px] font-bold text-slate-400 mt-0.5">
                                    <?= htmlspecialchars($log['user_name'] ?: 'ระบบ') ?> · <?= date('d/m H:i', strtotime($log['created_at'])) ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ════════════ ROUTING MODAL ════════════ -->
<div id="edmsRoutingModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
    <div id="edmsRoutingBox" class="bg-white rounded-3xl shadow-2xl w-full max-w-xl flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 bg-purple-50 rounded-xl border border-purple-100 flex items-center justify-center text-purple-600">
                <i class="fa-solid fa-share-nodes"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-lg font-black text-slate-800">โอน / มอบหมายเอกสาร</h3>
                <p class="text-[11px] font-bold text-slate-400">เลือกผู้รับและประเภทการดำเนินการ</p>
            </div>
            <button onclick="edmsCloseRouting()" class="text-slate-400 hover:text-rose-500 w-8 h-8 rounded-lg hover:bg-slate-50 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="px-6 py-5 overflow-y-auto">
            <div class="mb-4">
                <label class="edms-label">การดำเนินการ <span class="text-rose-500">*</span></label>
                <select id="edmsRouteAction" class="edms-input">
                    <option value="forward">ส่งต่อ (forward)</option>
                    <option value="assign">มอบหมาย (assign)</option>
                    <option value="approve">เพื่ออนุมัติ (approve)</option>
                    <option value="sign">เพื่อลงนาม (sign)</option>
                    <option value="note">เพื่อทราบ (note)</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="edms-label">ผู้รับ <span class="text-rose-500">*</span></label>
                <select id="edmsRouteToUser" class="edms-input">
                    <option value="">— เลือกผู้รับ —</option>
                    <?php foreach ($activeStaff as $st): ?>
                        <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?> <span class="text-slate-400">(@<?= htmlspecialchars($st['username']) ?>)</span></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div>
                    <label class="edms-label">หรือฝ่าย/หน่วยงาน</label>
                    <input type="text" id="edmsRouteToDept" class="edms-input" placeholder="เช่น ฝ่ายการเงิน">
                </div>
                <div>
                    <label class="edms-label">กำหนดส่ง</label>
                    <input type="date" id="edmsRouteDue" class="edms-input">
                </div>
            </div>
            <div class="mb-2">
                <label class="edms-label">บันทึก / สั่งการ</label>
                <textarea id="edmsRouteComment" rows="3" class="edms-input" placeholder="ตัวอย่าง: โปรดดำเนินการตามที่เสนอ"></textarea>
            </div>
        </div>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center gap-2">
            <button type="button" onclick="edmsCloseRouting()"
                class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-600 text-sm font-black hover:bg-slate-50">
                ยกเลิก
            </button>
            <div class="flex-1"></div>
            <button type="button" onclick="edmsSubmitRouting()"
                class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2.5 rounded-xl text-sm font-black flex items-center gap-2 shadow-sm transition-colors">
                <i class="fa-solid fa-share"></i> โอนเอกสาร
            </button>
        </div>
    </div>
</div>

<!-- Hidden picker dedicated to "upload new version of <attachment X>" -->
<input type="file" id="edmsVersionFile" class="hidden"
    accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt">

<!-- ════════════ VERSION HISTORY MODAL ════════════ -->
<div id="edmsVersionsModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4" style="z-index:310">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl flex flex-col overflow-hidden" style="max-height:88vh">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3 shrink-0">
            <i class="fa-solid fa-clock-rotate-left text-amber-500 text-lg"></i>
            <p id="edmsVersionsName" class="flex-1 min-w-0 text-sm font-black text-slate-700 truncate">—</p>
            <button onclick="edmsCloseVersions()" class="text-slate-400 hover:text-rose-500 w-8 h-8 rounded-lg hover:bg-slate-50 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="edmsVersionsBody" class="flex-1 overflow-y-auto p-5 space-y-2" style="min-height:0">
            <p class="text-center text-sm text-slate-400 py-6">กำลังโหลด...</p>
        </div>
    </div>
</div>

<!-- ════════════ ATTACHMENT VIEWER MODAL ════════════ -->
<div id="edmsViewerModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4" style="z-index:300">
    <div id="edmsViewerBox" class="bg-white rounded-3xl shadow-2xl w-full max-w-7xl flex flex-col overflow-hidden" style="height:92vh;max-height:92vh">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3 shrink-0">
            <i id="edmsViewerIcon" class="fa-solid fa-file text-slate-400 text-lg"></i>
            <p id="edmsViewerName" class="flex-1 min-w-0 text-sm font-black text-slate-700 truncate">—</p>
            <a id="edmsViewerDownload" href="#" class="text-emerald-500 hover:bg-emerald-50 px-3 py-1.5 rounded-lg text-xs font-black inline-flex items-center gap-1.5">
                <i class="fa-solid fa-download"></i> ดาวน์โหลด
            </a>
            <button onclick="edmsCloseViewer()" class="text-slate-400 hover:text-rose-500 w-8 h-8 rounded-lg hover:bg-slate-50 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="edmsViewerFrame" class="flex-1 overflow-auto bg-slate-100" style="min-height:0">
            <iframe id="edmsViewerIframe" class="hidden w-full h-full bg-white" frameborder="0"></iframe>
            <img id="edmsViewerImg" class="hidden mx-auto max-w-full max-h-full object-contain" alt="">
        </div>
    </div>
</div>

<script>
window.edmsViewer = function(id, name, kind) {
    const modal = document.getElementById('edmsViewerModal');
    const iframe = document.getElementById('edmsViewerIframe');
    const img = document.getElementById('edmsViewerImg');
    const icon = document.getElementById('edmsViewerIcon');
    const nameEl = document.getElementById('edmsViewerName');
    const dl = document.getElementById('edmsViewerDownload');

    nameEl.textContent = name;
    dl.href = `edms_file.php?id=${id}&disposition=attachment`;

    iframe.classList.add('hidden'); iframe.src = '';
    img.classList.add('hidden'); img.src = '';

    if (kind === 'pdf') {
        icon.className = 'fa-solid fa-file-pdf text-rose-500 text-lg';
        // #toolbar=1 keeps the PDF toolbar; zoom=page-width fits page to iframe width by
        // default so short / non-A4 pages don't render as a tiny strip in a sea of black.
        iframe.src = `edms_file.php?id=${id}&disposition=inline#toolbar=1&zoom=page-width&view=FitH`;
        iframe.classList.remove('hidden');
    } else if (kind === 'image') {
        icon.className = 'fa-solid fa-file-image text-purple-500 text-lg';
        img.src = `edms_file.php?id=${id}&disposition=inline`;
        img.classList.remove('hidden');
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
};

window.edmsCloseViewer = function() {
    const modal = document.getElementById('edmsViewerModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('edmsViewerIframe').src = '';
    document.getElementById('edmsViewerImg').src = '';
};

document.getElementById('edmsViewerModal').addEventListener('click', e => {
    if (e.target.id === 'edmsViewerModal') edmsCloseViewer();
});

window.edmsArchive = async function(id) {
    const c = await Swal.fire({
        title: 'เก็บเข้าแฟ้ม?',
        text: 'เอกสารจะถูกย้ายไปสถานะ archived',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'เก็บแฟ้ม',
        cancelButtonText: 'ยกเลิก',
    });
    if (!c.isConfirmed) return;

    const fd = new FormData();
    fd.append('entity', 'document');
    fd.append('action', 'archive');
    fd.append('id', id);
    fd.append('csrf_token', portal_CSRF);
    const res = await (await fetch('ajax_edms.php', { method: 'POST', body: fd })).json();
    if (res.ok) {
        await Swal.fire({ icon: 'success', title: res.message || 'เก็บแฟ้มแล้ว', timer: 1000, showConfirmButton: false });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'เก็บแฟ้มไม่สำเร็จ', text: res.message || '' });
    }
};

// Edit button — เปิด list view แล้วเรียก edmsEdit ผ่าน sessionStorage
(function() {
    const link = document.getElementById('edmsEditLink');
    if (link) {
        link.addEventListener('click', e => {
            e.preventDefault();
            sessionStorage.setItem('edmsAutoEdit', '<?= $id ?>');
            window.location.href = link.href.split('&_edit=')[0];
        });
    }
})();

// ════════════ ATTACHMENT UPLOAD (เพิ่มไฟล์เพิ่มเติม) ════════════
const EDMS_DETAIL_DOC_ID = <?= $id ?>;

document.getElementById('edmsAddFiles')?.addEventListener('change', async e => {
    const files = e.target.files;
    if (!files || !files.length) return;

    // Quick client-side guard — backend re-checks (limit per file = 20MB)
    const tooBig = Array.from(files).filter(f => f.size > 20 * 1024 * 1024);
    if (tooBig.length) {
        Swal.fire({
            icon: 'warning',
            title: 'ไฟล์ใหญ่เกินไป',
            text: `ไฟล์ต่อไปนี้เกิน 20MB: ${tooBig.map(f => f.name).join(', ')}`,
        });
        e.target.value = '';
        return;
    }

    const fd = new FormData();
    fd.append('entity', 'attachment');
    fd.append('action', 'upload');
    fd.append('doc_id', EDMS_DETAIL_DOC_ID);
    fd.append('csrf_token', portal_CSRF);
    for (const f of files) fd.append('files[]', f);

    Swal.fire({
        title: 'กำลังอัปโหลด...',
        html: `กำลังส่ง ${files.length} ไฟล์`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
    });

    try {
        const res = await (await fetch('ajax_edms.php', { method: 'POST', body: fd })).json();
        if (res.ok) {
            await Swal.fire({
                icon: 'success',
                title: res.message || 'อัปโหลดสำเร็จ',
                timer: 1200,
                showConfirmButton: false,
            });
            window.location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'อัปโหลดไม่สำเร็จ', text: res.message || '' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'เครือข่ายขัดข้อง', text: 'อัปโหลดไม่สำเร็จ' });
    } finally {
        e.target.value = '';
    }
});

// ════════════ ATTACHMENT VERSIONING ════════════

let edmsVersionParentId = null; // remembers which attachment we're versioning

window.edmsUploadVersion = function(parentId, parentName) {
    edmsVersionParentId = parentId;
    const picker = document.getElementById('edmsVersionFile');
    picker.value = '';
    // Stash the friendly name so the success toast can mention it
    picker.dataset.parentName = parentName || '';
    picker.click();
};

document.getElementById('edmsVersionFile')?.addEventListener('change', async e => {
    const file = e.target.files?.[0];
    const parentId = edmsVersionParentId;
    edmsVersionParentId = null;
    if (!file || !parentId) return;

    if (file.size > 20 * 1024 * 1024) {
        Swal.fire({ icon: 'warning', title: 'ไฟล์ใหญ่เกิน 20MB' });
        e.target.value = '';
        return;
    }

    const fd = new FormData();
    fd.append('entity', 'attachment');
    fd.append('action', 'upload_version');
    fd.append('parent_id', parentId);
    fd.append('csrf_token', portal_CSRF);
    fd.append('file', file);

    Swal.fire({
        title: 'กำลังอัปโหลดเวอร์ชันใหม่...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
    });

    try {
        const res = await (await fetch('ajax_edms.php', { method: 'POST', body: fd })).json();
        if (res.ok) {
            await Swal.fire({
                icon: 'success',
                title: res.message || 'อัปโหลดเวอร์ชันใหม่แล้ว',
                timer: 1200,
                showConfirmButton: false,
            });
            window.location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'อัปโหลดไม่สำเร็จ', text: res.message || '' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'เครือข่ายขัดข้อง', text: 'อัปโหลดไม่สำเร็จ' });
    } finally {
        e.target.value = '';
    }
});

window.edmsShowVersions = async function(attId, attName) {
    document.getElementById('edmsVersionsName').textContent = attName;
    const body = document.getElementById('edmsVersionsBody');
    body.innerHTML = '<p class="text-center text-sm text-slate-400 py-6">กำลังโหลด...</p>';
    const m = document.getElementById('edmsVersionsModal');
    m.classList.remove('hidden'); m.classList.add('flex');

    const fd = new FormData();
    fd.append('entity', 'attachment');
    fd.append('action', 'list_versions');
    fd.append('id', attId);
    fd.append('csrf_token', portal_CSRF);

    try {
        const res = await (await fetch('ajax_edms.php', { method: 'POST', body: fd })).json();
        if (!res.ok) {
            body.innerHTML = `<p class="text-center text-sm text-rose-500 py-6">${escEdms(res.message || 'โหลดไม่สำเร็จ')}</p>`;
            return;
        }
        if (!res.versions || res.versions.length === 0) {
            body.innerHTML = '<p class="text-center text-sm text-slate-400 py-6">ไม่มีประวัติเวอร์ชัน</p>';
            return;
        }
        body.innerHTML = res.versions.map(v => {
            const isCur = parseInt(v.is_current, 10) === 1;
            const ext = String(v.file_name).split('.').pop().toLowerCase();
            const isPdf = ext === 'pdf';
            const isImg = ['png','jpg','jpeg','gif','webp'].includes(ext);
            const icon = isPdf ? 'fa-file-pdf text-rose-500'
                       : isImg ? 'fa-file-image text-purple-500'
                       : 'fa-file text-slate-400';
            const sizeKb = (parseInt(v.file_size, 10) / 1024).toFixed(1) + ' KB';
            const when = v.uploaded_at ? new Date(String(v.uploaded_at).replace(' ', 'T')).toLocaleString('th-TH') : '-';
            const uploader = v.uploader_name || 'ระบบ';
            const previewBtn = (isPdf || isImg)
                ? `<button onclick="edmsViewer(${v.id}, ${JSON.stringify(v.file_name)}, '${isPdf ? 'pdf' : 'image'}')"
                    class="text-sky-500 hover:bg-sky-100 px-2 py-1 rounded text-xs font-black" title="ดู"><i class="fa-solid fa-eye"></i></button>`
                : '';
            const supersededLine = (!isCur && v.superseded_at)
                ? `<span class="text-slate-400">· แทนที่: ${escEdms(new Date(String(v.superseded_at).replace(' ', 'T')).toLocaleString('th-TH'))}</span>`
                : '';
            return `
                <div class="flex items-center gap-3 px-3 py-2.5 ${isCur ? 'bg-emerald-50 border-emerald-200' : 'bg-slate-50 border-slate-100'} border rounded-2xl">
                    <span class="inline-flex items-center text-[10px] font-black px-2 py-0.5 rounded ${isCur ? 'bg-emerald-100 text-emerald-700 border border-emerald-200' : 'bg-amber-100 text-amber-700 border border-amber-200'} shrink-0">v${v.version_no}${isCur ? ' · ปัจจุบัน' : ''}</span>
                    <i class="fa-solid ${icon} text-lg"></i>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-black text-slate-700 truncate">${escEdms(v.file_name)}</p>
                        <p class="text-[10px] font-bold text-slate-400 truncate">
                            ${escEdms(sizeKb)} · ${escEdms(when)} · โดย ${escEdms(uploader)} ${supersededLine}
                        </p>
                    </div>
                    ${previewBtn}
                    <a href="edms_file.php?id=${v.id}&disposition=attachment"
                       class="text-emerald-500 hover:bg-emerald-100 px-2 py-1 rounded text-xs font-black" title="ดาวน์โหลด"><i class="fa-solid fa-download"></i></a>
                </div>
            `;
        }).join('');
    } catch (err) {
        body.innerHTML = '<p class="text-center text-sm text-rose-500 py-6">เครือข่ายขัดข้อง</p>';
    }
};

window.edmsCloseVersions = function() {
    const m = document.getElementById('edmsVersionsModal');
    m.classList.add('hidden'); m.classList.remove('flex');
};

window.edmsToggleRole = async function(attId, newRole) {
    if (newRole === 'primary') {
        const c = await Swal.fire({
            title: 'ตั้งเป็นเอกสารหลัก?',
            text: 'ถ้ามีเอกสารหลักอยู่แล้ว ตัวเดิมจะถูกย้ายไปเป็นไฟล์ประกอบ (ข้อมูล/เวอร์ชันยังอยู่ครบ)',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'ตั้งเป็นหลัก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f59e0b',
        });
        if (!c.isConfirmed) return;
    }

    const fd = new FormData();
    fd.append('entity', 'attachment');
    fd.append('action', 'set_role');
    fd.append('id', attId);
    fd.append('role', newRole);
    fd.append('csrf_token', portal_CSRF);

    try {
        const res = await (await fetch('ajax_edms.php', { method: 'POST', body: fd })).json();
        if (res.ok) {
            await Swal.fire({ icon: 'success', title: res.message || 'อัปเดตแล้ว', timer: 900, showConfirmButton: false });
            window.location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'เครือข่ายขัดข้อง' });
    }
};

// Tiny HTML-escape helper used inside the version history renderer
function escEdms(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
}

window.edmsDeleteAttachment = async function(attId, fileName) {
    const c = await Swal.fire({
        title: 'ลบไฟล์นี้?',
        text: fileName,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e11d48',
    });
    if (!c.isConfirmed) return;

    const fd = new FormData();
    fd.append('entity', 'attachment');
    fd.append('action', 'delete');
    fd.append('id', attId);
    fd.append('csrf_token', portal_CSRF);
    try {
        const res = await (await fetch('ajax_edms.php', { method: 'POST', body: fd })).json();
        if (res.ok) {
            await Swal.fire({ icon: 'success', title: 'ลบไฟล์แล้ว', timer: 800, showConfirmButton: false });
            window.location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'เครือข่ายขัดข้อง', text: '' });
    }
};

// ════════════ ROUTING ACTIONS ════════════
const EDMS_DOC_ID = <?= $id ?>;

async function edmsAjax(entity, action, data) {
    const fd = new FormData();
    fd.append('entity', entity);
    fd.append('action', action);
    fd.append('csrf_token', portal_CSRF);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v ?? ''));
    const res = await fetch('ajax_edms.php', { method: 'POST', body: fd });
    return res.json();
}

window.edmsOpenRouting = function() {
    const m = document.getElementById('edmsRoutingModal');
    document.getElementById('edmsRouteAction').value = 'forward';
    document.getElementById('edmsRouteToUser').value = '';
    document.getElementById('edmsRouteToDept').value = '';
    document.getElementById('edmsRouteDue').value = '';
    document.getElementById('edmsRouteComment').value = '';
    m.classList.remove('hidden');
    m.classList.add('flex');
};

window.edmsCloseRouting = function() {
    const m = document.getElementById('edmsRoutingModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
};

document.getElementById('edmsRoutingModal').addEventListener('click', e => {
    if (e.target.id === 'edmsRoutingModal') edmsCloseRouting();
});

window.edmsSubmitRouting = async function() {
    const toUser = document.getElementById('edmsRouteToUser').value;
    const toDept = document.getElementById('edmsRouteToDept').value.trim();
    if (!toUser && !toDept) {
        Swal.fire({ icon: 'warning', title: 'กรุณาเลือกผู้รับ', text: 'ระบุผู้รับหรือฝ่ายปลายทางอย่างน้อย 1 อย่าง' });
        return;
    }
    const res = await edmsAjax('routing', 'forward', {
        doc_id: EDMS_DOC_ID,
        to_user_id: toUser,
        to_dept: toDept,
        r_action: document.getElementById('edmsRouteAction').value,
        comment: document.getElementById('edmsRouteComment').value,
        due_date: document.getElementById('edmsRouteDue').value,
    });
    if (res.ok) {
        await Swal.fire({ icon: 'success', title: res.message || 'โอนแล้ว', timer: 1100, showConfirmButton: false });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'โอนไม่สำเร็จ', text: res.message || '' });
    }
};

window.edmsRouteAck = async function(routeId) {
    const res = await edmsAjax('routing', 'acknowledge', { routing_id: routeId });
    if (res.ok) {
        await Swal.fire({ icon: 'success', title: res.message || 'รับทราบแล้ว', timer: 900, showConfirmButton: false });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
    }
};

window.edmsRouteComplete = async function(routeId) {
    const c = await Swal.fire({
        title: 'ยืนยันดำเนินการเสร็จสิ้น?',
        input: 'textarea',
        inputLabel: 'หมายเหตุ (optional)',
        inputPlaceholder: 'บันทึกผลการดำเนินงาน',
        showCancelButton: true,
        confirmButtonText: 'เสร็จสิ้น',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
    });
    if (!c.isConfirmed) return;
    const res = await edmsAjax('routing', 'complete', { routing_id: routeId, comment: c.value || '' });
    if (res.ok) {
        await Swal.fire({ icon: 'success', title: res.message || 'เสร็จสิ้น', timer: 1000, showConfirmButton: false });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
    }
};

window.edmsRouteReturn = async function(routeId) {
    const c = await Swal.fire({
        title: 'ตีกลับเอกสาร?',
        text: 'ระบบจะส่งกลับให้ผู้โอนเดิม',
        input: 'textarea',
        inputLabel: 'เหตุผล / สิ่งที่ต้องแก้ไข',
        inputPlaceholder: 'อธิบายเหตุผล',
        inputValidator: v => !v ? 'กรุณาระบุเหตุผล' : null,
        showCancelButton: true,
        confirmButtonText: 'ตีกลับ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e11d48',
    });
    if (!c.isConfirmed) return;
    const res = await edmsAjax('routing', 'return', { routing_id: routeId, comment: c.value });
    if (res.ok) {
        await Swal.fire({ icon: 'success', title: res.message || 'ตีกลับแล้ว', timer: 1000, showConfirmButton: false });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
    }
};

window.edmsCompleteDoc = async function(id) {
    const c = await Swal.fire({
        title: 'ปิดเรื่องเอกสารนี้?',
        text: 'เปลี่ยนสถานะเป็น "เสร็จสิ้น"',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'ปิดเรื่อง',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
    });
    if (!c.isConfirmed) return;
    const res = await edmsAjax('document', 'complete', { id });
    if (res.ok) {
        await Swal.fire({ icon: 'success', title: res.message || 'ปิดเรื่องแล้ว', timer: 1000, showConfirmButton: false });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
    }
};
</script>
