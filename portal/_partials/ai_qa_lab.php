<?php
/**
 * portal/_partials/ai_qa_lab.php — AI QA Lab (sandbox)
 * เก็บคำถามจาก in-app chat + LINE webhook → admin trigger AI ร่างคำตอบ + จัดหมวด
 * AI ไม่ส่งกลับผู้ใช้โดยตรง — ใช้สำหรับ test/training
 *
 * $pdo, $adminRole มาจาก parent scope (portal/index.php)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/ai_qa_helper.php';
ensure_ai_qa_schema($pdo);

// ── Filters ──────────────────────────────────────────────────────────────────
$_qa_page     = max(1, (int)($_GET['page'] ?? 1));
$_qa_perPage  = 20;
$_qa_offset   = ($_qa_page - 1) * $_qa_perPage;
$_qa_search   = trim((string)($_GET['qa_search']   ?? ''));
$_qa_source   = (string)($_GET['qa_source']   ?? '');
$_qa_category = (string)($_GET['qa_category'] ?? '');
$_qa_status   = (string)($_GET['qa_status']   ?? '');
$_qa_date     = (string)($_GET['qa_date']     ?? '');

$_qa_where  = 'WHERE 1=1';
$_qa_params = [];
if ($_qa_search !== '') {
    $_qa_where   .= ' AND (question LIKE ? OR ai_answer LIKE ?)';
    $_qa_params[] = "%$_qa_search%";
    $_qa_params[] = "%$_qa_search%";
}
if (in_array($_qa_source, ['chat', 'line'], true)) {
    $_qa_where   .= ' AND source = ?';
    $_qa_params[] = $_qa_source;
}
if ($_qa_category !== '' && in_array($_qa_category, AI_QA_CATEGORIES, true)) {
    $_qa_where   .= ' AND category = ?';
    $_qa_params[] = $_qa_category;
}
if (in_array($_qa_status, AI_QA_STATUSES, true)) {
    $_qa_where   .= ' AND status = ?';
    $_qa_params[] = $_qa_status;
}
if ($_qa_date !== '') {
    $_qa_where   .= ' AND DATE(created_at) = ?';
    $_qa_params[] = $_qa_date;
}

$_qa_total      = 0;
$_qa_totalPages = 0;
$_qa_logs       = [];
$_qa_statSource = [];
$_qa_statStatus = [];
$_qa_statCategory = [];

try {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_ai_qa_log $_qa_where");
    $sc->execute($_qa_params);
    $_qa_total      = (int)$sc->fetchColumn();
    $_qa_totalPages = max(1, (int)ceil($_qa_total / $_qa_perPage));
    if ($_qa_page > $_qa_totalPages) $_qa_page = $_qa_totalPages;
    $_qa_offset = ($_qa_page - 1) * $_qa_perPage;

    $sr = $pdo->prepare("
        SELECT id, source, source_ref_id, user_id, line_user_id, question,
               category, ai_answer, ai_model, ai_confidence, status,
               reviewer_note, reviewed_by, reviewed_at, created_at
          FROM sys_ai_qa_log
          $_qa_where
          ORDER BY created_at DESC
          LIMIT $_qa_perPage OFFSET $_qa_offset
    ");
    $sr->execute($_qa_params);
    $_qa_logs = $sr->fetchAll(PDO::FETCH_ASSOC);

    $_qa_statSource   = $pdo->query("SELECT source,   COUNT(*) FROM sys_ai_qa_log GROUP BY source")->fetchAll(PDO::FETCH_KEY_PAIR);
    $_qa_statStatus   = $pdo->query("SELECT status,   COUNT(*) FROM sys_ai_qa_log GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $_qa_statCategory = $pdo->query("SELECT category, COUNT(*) FROM sys_ai_qa_log WHERE category IS NOT NULL GROUP BY category ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $_qa_dbError = $e->getMessage();
}

$_qa_filterQs = http_build_query(array_filter([
    'qa_search'   => $_qa_search,
    'qa_source'   => $_qa_source,
    'qa_category' => $_qa_category,
    'qa_status'   => $_qa_status,
    'qa_date'     => $_qa_date,
]));
$_qa_pgQs = $_qa_filterQs ? '&'.$_qa_filterQs : '';

function _qa_status_badge(string $s): string {
    return match($s) {
        'pending'     => 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b',
        'generated'   => 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8',
        'approved'    => 'background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d',
        'rejected'    => 'background:#fff1f2;border:1px solid #fecaca;color:#be123c',
        'needs_edit'  => 'background:#fffbeb;border:1px solid #fde68a;color:#92400e',
        default       => 'background:#f8fafc;border:1px solid #e2e8f0;color:#64748b',
    };
}
function _qa_status_label(string $s): string {
    return match($s) {
        'pending'    => 'รอประมวลผล',
        'generated'  => 'AI ร่างแล้ว',
        'approved'   => 'อนุมัติ',
        'rejected'   => 'ปฏิเสธ',
        'needs_edit' => 'ต้องแก้ไข',
        default      => $s,
    };
}
function _qa_source_badge(string $s): string {
    return $s === 'line'
        ? 'background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490'
        : 'background:#f5f3ff;border:1px solid #ddd6fe;color:#6d28d9';
}
?>
<style>
    #ai-qa-modal { z-index: 200; }
    #ai-qa-modal-box { max-height: 90vh; }
    #ai-qa-modal-body { min-height: 0; }
    .qa-input {
        width:100%; padding:.6rem .9rem;
        background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:.75rem;
        font-size:.875rem; color:#111827; outline:none; transition: all .15s;
    }
    .qa-input:focus { background:#fff; border-color:#8b5cf6; box-shadow:0 0 0 3px rgba(139,92,246,.12); }
    .qa-chip {
        display:inline-flex; align-items:center; gap:6px;
        padding:4px 10px; border-radius:99px; font-size:11px; font-weight:700;
    }
    .qa-row:hover { background:#fafafa; }
    .qa-confidence-bar { height:4px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
    .qa-confidence-fill { height:100%; background:linear-gradient(90deg,#8b5cf6,#06b6d4); border-radius:99px; }
</style>

<div class="p-6 max-w-7xl mx-auto">

    <!-- Header -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-flask-vial text-purple-600"></i>
                AI QA Lab
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Sandbox สำหรับทดสอบคำตอบของ AI — เก็บคำถามจริงจาก chat &amp; LINE
                แล้วให้ AI ร่างคำตอบ ก่อน approve เพื่อใช้เป็นฐาน FAQ
                (AI ไม่ตอบกลับ user โดยตรง)
            </p>
        </div>
        <button id="btn-bulk-generate"
            class="px-4 py-2 bg-purple-600 text-white rounded-xl text-sm font-bold shadow hover:bg-purple-700 transition flex items-center gap-2">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            สร้างคำตอบจาก AI (batch)
        </button>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="text-xs text-gray-500 font-bold uppercase tracking-wider">ทั้งหมด</div>
            <div class="text-2xl font-black text-gray-900 mt-1"><?= number_format($_qa_total) ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="text-xs text-gray-500 font-bold uppercase tracking-wider">รอประมวลผล</div>
            <div class="text-2xl font-black text-slate-600 mt-1"><?= number_format((int)($_qa_statStatus['pending'] ?? 0)) ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="text-xs text-gray-500 font-bold uppercase tracking-wider">AI ร่างแล้ว</div>
            <div class="text-2xl font-black text-blue-600 mt-1"><?= number_format((int)($_qa_statStatus['generated'] ?? 0)) ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="text-xs text-gray-500 font-bold uppercase tracking-wider">อนุมัติแล้ว</div>
            <div class="text-2xl font-black text-emerald-600 mt-1"><?= number_format((int)($_qa_statStatus['approved'] ?? 0)) ?></div>
        </div>
    </div>

    <!-- Top categories -->
    <?php if (!empty($_qa_statCategory)): ?>
    <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
        <div class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-3">หมวดหมู่ยอดนิยม</div>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($_qa_statCategory as $cat => $cnt): ?>
                <a href="?section=ai_qa_lab&qa_category=<?= urlencode((string)$cat) ?>"
                   class="qa-chip bg-purple-50 text-purple-700 border border-purple-200 hover:bg-purple-100">
                    <span><?= htmlspecialchars((string)$cat) ?></span>
                    <span class="bg-purple-500 text-white px-2 py-0.5 rounded-full text-xs font-bold"><?= number_format((int)$cnt) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="get" class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
        <input type="hidden" name="section" value="ai_qa_lab">
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
            <div class="md:col-span-2">
                <input type="text" name="qa_search" value="<?= htmlspecialchars($_qa_search) ?>"
                    placeholder="ค้นหาคำถาม / คำตอบ" class="qa-input">
            </div>
            <select name="qa_source" class="qa-input">
                <option value="">ทุกช่องทาง</option>
                <option value="chat" <?= $_qa_source === 'chat' ? 'selected' : '' ?>>In-app Chat</option>
                <option value="line" <?= $_qa_source === 'line' ? 'selected' : '' ?>>LINE</option>
            </select>
            <select name="qa_category" class="qa-input">
                <option value="">ทุกหมวด</option>
                <?php foreach (AI_QA_CATEGORIES as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $_qa_category === $cat ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="qa_status" class="qa-input">
                <option value="">ทุกสถานะ</option>
                <?php foreach (AI_QA_STATUSES as $st): ?>
                    <option value="<?= htmlspecialchars($st) ?>" <?= $_qa_status === $st ? 'selected' : '' ?>>
                        <?= htmlspecialchars(_qa_status_label($st)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="qa_date" value="<?= htmlspecialchars($_qa_date) ?>" class="qa-input">
        </div>
        <div class="mt-3 flex gap-2 justify-end">
            <a href="?section=ai_qa_lab" class="px-4 py-2 text-sm font-bold text-gray-600 hover:bg-gray-100 rounded-xl">ล้าง</a>
            <button type="submit" class="px-5 py-2 bg-gray-900 text-white text-sm font-bold rounded-xl hover:bg-gray-800">
                <i class="fa-solid fa-filter mr-1"></i> กรอง
            </button>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200">
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">เวลา</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">ช่องทาง</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">คำถาม</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">หมวด</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">AI Confidence</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">สถานะ</th>
                        <th class="px-4 py-3 text-right text-xs font-black text-gray-500 uppercase tracking-wider">การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($_qa_logs)): ?>
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">
                        <i class="fa-solid fa-inbox text-3xl mb-2 block"></i>
                        ยังไม่มีคำถามที่เข้าเงื่อนไขการกรอง
                    </td></tr>
                <?php else: foreach ($_qa_logs as $r): ?>
                    <tr class="qa-row border-b border-gray-100"
                        data-id="<?= (int)$r['id'] ?>"
                        data-question="<?= htmlspecialchars((string)$r['question'], ENT_QUOTES) ?>"
                        data-answer="<?= htmlspecialchars((string)($r['ai_answer'] ?? ''), ENT_QUOTES) ?>"
                        data-category="<?= htmlspecialchars((string)($r['category'] ?? ''), ENT_QUOTES) ?>"
                        data-status="<?= htmlspecialchars((string)$r['status'], ENT_QUOTES) ?>"
                        data-note="<?= htmlspecialchars((string)($r['reviewer_note'] ?? ''), ENT_QUOTES) ?>">
                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap"><?= date('d/m H:i', strtotime((string)$r['created_at'])) ?></td>
                        <td class="px-4 py-3"><span class="qa-chip" style="<?= _qa_source_badge((string)$r['source']) ?>"><?= strtoupper((string)$r['source']) ?></span></td>
                        <td class="px-4 py-3 max-w-md">
                            <div class="text-gray-900 line-clamp-2"><?= htmlspecialchars(mb_substr((string)$r['question'], 0, 200)) ?></div>
                            <?php if (!empty($r['ai_answer'])): ?>
                                <div class="text-xs text-gray-500 mt-1 line-clamp-1"><i class="fa-solid fa-robot mr-1"></i><?= htmlspecialchars(mb_substr((string)$r['ai_answer'], 0, 120)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-xs"><?= $r['category'] ? '<span class="qa-chip bg-purple-50 text-purple-700 border border-purple-200">'.htmlspecialchars((string)$r['category']).'</span>' : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="px-4 py-3" style="min-width:100px">
                            <?php if ($r['ai_confidence'] !== null): ?>
                                <div class="qa-confidence-bar"><div class="qa-confidence-fill" style="width:<?= (float)$r['ai_confidence']*100 ?>%"></div></div>
                                <div class="text-xs text-gray-500 mt-1"><?= number_format((float)$r['ai_confidence']*100, 0) ?>%</div>
                            <?php else: ?>
                                <span class="text-gray-300 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><span class="qa-chip" style="<?= _qa_status_badge((string)$r['status']) ?>"><?= htmlspecialchars(_qa_status_label((string)$r['status'])) ?></span></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <?php if ($r['status'] === 'pending'): ?>
                                <button class="qa-act qa-generate px-3 py-1.5 bg-purple-600 text-white text-xs font-bold rounded-lg hover:bg-purple-700" data-id="<?= (int)$r['id'] ?>">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
                                </button>
                            <?php else: ?>
                                <button class="qa-act qa-review px-3 py-1.5 bg-gray-900 text-white text-xs font-bold rounded-lg hover:bg-gray-800" data-id="<?= (int)$r['id'] ?>">
                                    <i class="fa-solid fa-pen-to-square"></i> Review
                                </button>
                            <?php endif; ?>
                            <button class="qa-act qa-delete p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg" data-id="<?= (int)$r['id'] ?>" title="ลบ">
                                <i class="fa-solid fa-trash text-xs"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($_qa_total > 0): ?>
        <div class="px-5 py-4 border-t border-gray-100 flex items-center justify-between flex-wrap gap-3">
            <div class="text-xs text-gray-500">
                หน้า <?= $_qa_page ?> / <?= $_qa_totalPages ?> · รวม <?= number_format($_qa_total) ?> รายการ
            </div>
            <div class="flex items-center gap-1">
                <?php
                $base = '?section=ai_qa_lab' . $_qa_pgQs;
                $disabledFirst = $_qa_page <= 1 ? 'pointer-events:none;opacity:.4' : '';
                $disabledLast  = $_qa_page >= $_qa_totalPages ? 'pointer-events:none;opacity:.4' : '';
                ?>
                <a href="<?= $base ?>&page=1" style="<?= $disabledFirst ?>"
                   class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">«</a>
                <a href="<?= $base ?>&page=<?= max(1, $_qa_page - 1) ?>" style="<?= $disabledFirst ?>"
                   class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">‹</a>
                <?php for ($i = max(1, $_qa_page - 2); $i <= min($_qa_totalPages, $_qa_page + 2); $i++): ?>
                    <a href="<?= $base ?>&page=<?= $i ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs <?= $i === $_qa_page ? 'bg-purple-600 text-white font-bold' : 'border border-gray-200 text-gray-500 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <a href="<?= $base ?>&page=<?= min($_qa_totalPages, $_qa_page + 1) ?>" style="<?= $disabledLast ?>"
                   class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">›</a>
                <a href="<?= $base ?>&page=<?= $_qa_totalPages ?>" style="<?= $disabledLast ?>"
                   class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">»</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Review Modal -->
<div id="ai-qa-modal" class="hidden fixed inset-0 bg-black/40 items-center justify-center p-4">
    <div id="ai-qa-modal-box" class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-lg font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-purple-500"></i> Review AI Answer
            </h3>
            <button onclick="qaCloseModal()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="ai-qa-modal-body" class="p-6 overflow-y-auto flex-1 space-y-4">
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">คำถามจาก user</label>
                <div id="qa-mod-question" class="p-3 bg-gray-50 rounded-xl text-sm text-gray-800 whitespace-pre-wrap"></div>
            </div>
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">หมวดหมู่</label>
                <select id="qa-mod-category" class="qa-input">
                    <?php foreach (AI_QA_CATEGORIES as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">คำตอบ AI (แก้ไขได้)</label>
                <textarea id="qa-mod-answer" rows="6" class="qa-input"></textarea>
            </div>
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">หมายเหตุของผู้ตรวจ</label>
                <textarea id="qa-mod-note" rows="2" class="qa-input" placeholder="เช่น คำตอบดีแต่ขอเพิ่มเบอร์ติดต่อ"></textarea>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex flex-wrap items-center justify-end gap-2">
            <button onclick="qaSubmit('rejected')" class="px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 rounded-xl">
                <i class="fa-solid fa-xmark mr-1"></i> Reject
            </button>
            <button onclick="qaSubmit('needs_edit')" class="px-4 py-2 text-sm font-bold text-amber-600 hover:bg-amber-50 rounded-xl">
                <i class="fa-solid fa-pen mr-1"></i> Mark Needs Edit
            </button>
            <button onclick="qaSubmit('approved')" class="px-5 py-2 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl">
                <i class="fa-solid fa-check mr-1"></i> Approve
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const csrfToken = '<?= get_csrf_token() ?>';
    let currentId = null;

    function api(action, payload) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', csrfToken);
        Object.entries(payload || {}).forEach(([k, v]) => fd.append(k, v));
        return fetch('ajax_ai_qa.php', { method: 'POST', body: fd })
            .then(r => r.json());
    }

    document.querySelectorAll('.qa-generate').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            try {
                const res = await api('generate', { id });
                if (res.ok) {
                    Swal.fire({ icon: 'success', title: 'AI ร่างคำตอบเรียบร้อย', timer: 1200, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || 'unknown error' });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate';
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate';
            }
        });
    });

    document.querySelectorAll('.qa-review').forEach(btn => {
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            currentId = tr.dataset.id;
            document.getElementById('qa-mod-question').textContent = tr.dataset.question;
            document.getElementById('qa-mod-answer').value = tr.dataset.answer;
            document.getElementById('qa-mod-category').value = tr.dataset.category || 'อื่นๆ';
            document.getElementById('qa-mod-note').value = tr.dataset.note;
            const m = document.getElementById('ai-qa-modal');
            m.classList.remove('hidden');
            m.style.display = 'flex';
        });
    });

    document.querySelectorAll('.qa-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            const { isConfirmed } = await Swal.fire({
                icon: 'warning',
                title: 'ลบรายการนี้?',
                text: 'การลบไม่สามารถย้อนกลับได้',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#e11d48',
            });
            if (!isConfirmed) return;
            const res = await api('delete', { id: btn.dataset.id });
            if (res.ok) location.reload();
            else Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
        });
    });

    document.getElementById('btn-bulk-generate').addEventListener('click', async () => {
        const { isConfirmed, value } = await Swal.fire({
            icon: 'question',
            title: 'สร้างคำตอบจาก AI (batch)',
            input: 'number',
            inputLabel: 'จำนวนรายการที่จะประมวลผล (สูงสุด 20)',
            inputValue: 10,
            inputAttributes: { min: 1, max: 20, step: 1 },
            showCancelButton: true,
            confirmButtonText: 'เริ่ม',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#7c3aed',
        });
        if (!isConfirmed) return;
        Swal.fire({
            title: 'กำลังประมวลผล…',
            html: 'อาจใช้เวลาสักครู่',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });
        const res = await api('bulk_generate', { limit: value || 10 });
        if (res.ok) {
            Swal.fire({
                icon: 'success',
                title: 'เสร็จแล้ว',
                html: `ประมวลผล <b>${res.processed}</b> รายการ<br>สำเร็จ <b>${res.success}</b> · ล้มเหลว <b>${res.failed}</b>`,
            }).then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
        }
    });

    window.qaCloseModal = function() {
        const m = document.getElementById('ai-qa-modal');
        m.classList.add('hidden');
        m.style.display = 'none';
        currentId = null;
    };

    window.qaSubmit = async function(status) {
        if (!currentId) return;
        const payload = {
            id: currentId,
            status: status,
            category: document.getElementById('qa-mod-category').value,
            answer: document.getElementById('qa-mod-answer').value,
            reviewer_note: document.getElementById('qa-mod-note').value,
        };
        const res = await api('update', payload);
        if (res.ok) {
            Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 900, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: res.message || '' });
        }
    };
})();
</script>
