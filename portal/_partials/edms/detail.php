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
        $att = $pdo->prepare("SELECT id, file_name, stored_path, mime_type, file_size, uploaded_at FROM sys_doc_attachments WHERE doc_id = ? ORDER BY id ASC");
        $att->execute([$id]);
        $attachments = $att->fetchAll(PDO::FETCH_ASSOC);

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
    'violet'  => ['bg' => 'bg-violet-50',  'text' => 'text-violet-600',  'border' => 'border-violet-100',  'btn' => 'bg-violet-500 hover:bg-violet-600'],
    'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'border' => 'border-amber-100',   'btn' => 'bg-amber-500 hover:bg-amber-600'],
];

$meta = $typeMap[$doc['doc_type']] ?? $typeMap['incoming'];
$tone = $tonePalette[$meta['tone']];

$statusLabels = [
    'draft'       => ['label' => 'ฉบับร่าง',       'tone' => 'bg-slate-100 text-slate-600 border-slate-200'],
    'registered'  => ['label' => 'ลงทะเบียนแล้ว',  'tone' => 'bg-sky-50 text-sky-700 border-sky-200'],
    'routing'     => ['label' => 'อยู่ระหว่างโอน', 'tone' => 'bg-violet-50 text-violet-700 border-violet-200'],
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
        'create'    => 'สร้างเอกสาร',
        'update'    => 'แก้ไข',
        'attach'    => 'เพิ่มไฟล์แนบ',
        'detach'    => 'ลบไฟล์แนบ',
        'archive'   => 'เก็บเข้าแฟ้ม',
        'cancel'    => 'ยกเลิก',
        'cancelled' => 'ยกเลิก',
        'archived'  => 'เก็บเข้าแฟ้ม',
        default     => $action,
    };
}
?>
<style>
#edmsViewerModal { z-index: 200; }
#edmsViewerBox { max-height: 92vh; }
#edmsViewerFrame { min-height: 0; }
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
            <div class="flex items-center gap-2 shrink-0">
                <?php if (!in_array($doc['status'], ['archived','cancelled'], true)): ?>
                    <button onclick="edmsArchive(<?= $id ?>)" title="เก็บเข้าแฟ้ม" class="text-slate-500 hover:bg-slate-100 px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-box-archive"></i> เก็บแฟ้ม
                    </button>
                <?php endif; ?>
                <a href="?section=edms&edms_view=list&type=<?= urlencode($doc['doc_type']) ?>&_edit=<?= $id ?>"
                   id="edmsEditLink"
                   class="<?= $tone['btn'] ?> text-white px-3 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5">
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <!-- Attachments -->
        <div class="md:col-span-2 bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-black text-slate-800 mb-3 flex items-center gap-2">
                <i class="fa-solid fa-paperclip <?= $tone['text'] ?>"></i> ไฟล์แนบ
                <span class="text-xs font-bold text-slate-400">(<?= count($attachments) ?>)</span>
            </h3>
            <?php if (empty($attachments)): ?>
                <p class="text-center text-sm font-bold text-slate-400 py-6">— ยังไม่มีไฟล์แนบ —</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($attachments as $a):
                        $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION));
                        $isImage = in_array($ext, ['png','jpg','jpeg','gif','webp'], true);
                        $isPdf   = ($ext === 'pdf');
                        $iconClass = $isPdf ? 'fa-file-pdf text-rose-500' : ($isImage ? 'fa-file-image text-violet-500' : 'fa-file text-slate-400');
                    ?>
                        <div class="flex items-center gap-3 px-3 py-2.5 bg-slate-50 hover:bg-slate-100 rounded-2xl border border-slate-100 transition-colors">
                            <i class="fa-solid <?= $iconClass ?> text-lg"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-black text-slate-700 truncate"><?= htmlspecialchars($a['file_name']) ?></p>
                                <p class="text-[10px] font-bold text-slate-400"><?= edms_format_bytes((int)$a['file_size']) ?> · <?= date('d/m/Y H:i', strtotime($a['uploaded_at'])) ?></p>
                            </div>
                            <?php if ($isPdf || $isImage): ?>
                                <button onclick="edmsViewer(<?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes($a['file_name']), ENT_QUOTES) ?>', '<?= $isPdf ? 'pdf' : 'image' ?>')"
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
                        </div>
                    <?php endforeach; ?>
                </div>
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

<!-- ════════════ ATTACHMENT VIEWER MODAL ════════════ -->
<div id="edmsViewerModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4">
    <div id="edmsViewerBox" class="bg-white rounded-3xl shadow-2xl w-full max-w-5xl flex flex-col overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3">
            <i id="edmsViewerIcon" class="fa-solid fa-file text-slate-400 text-lg"></i>
            <p id="edmsViewerName" class="flex-1 min-w-0 text-sm font-black text-slate-700 truncate">—</p>
            <a id="edmsViewerDownload" href="#" class="text-emerald-500 hover:bg-emerald-50 px-3 py-1.5 rounded-lg text-xs font-black inline-flex items-center gap-1.5">
                <i class="fa-solid fa-download"></i> ดาวน์โหลด
            </a>
            <button onclick="edmsCloseViewer()" class="text-slate-400 hover:text-rose-500 w-8 h-8 rounded-lg hover:bg-slate-50 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="edmsViewerFrame" class="flex-1 overflow-hidden bg-slate-100" style="height: 75vh;">
            <iframe id="edmsViewerIframe" class="hidden w-full h-full bg-white" frameborder="0"></iframe>
            <img id="edmsViewerImg" class="hidden mx-auto max-h-full" alt="">
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
        iframe.src = `edms_file.php?id=${id}&disposition=inline`;
        iframe.classList.remove('hidden');
    } else if (kind === 'image') {
        icon.className = 'fa-solid fa-file-image text-violet-500 text-lg';
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
</script>
