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
ensure_ai_faq_schema($pdo);

$_qa_tab = (string)($_GET['qa_tab'] ?? 'captured');
if (!in_array($_qa_tab, ['captured', 'faq'], true)) $_qa_tab = 'captured';

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

// ── FAQ Knowledge Base data (only when on FAQ tab to save queries) ──────────
$_faq_search   = trim((string)($_GET['faq_search']   ?? ''));
$_faq_category = (string)($_GET['faq_category'] ?? '');
$_faq_total      = 0;
$_faq_totalPages = 1;
$_faq_list       = [];
$_faq_totalAll   = 0;
$_faq_statCategory = [];

if ($_qa_tab === 'faq') {
    $_faq_where  = 'WHERE 1=1';
    $_faq_params = [];
    if ($_faq_search !== '') {
        $_faq_where .= ' AND (canonical_question LIKE ? OR answer LIKE ?)';
        $_faq_params[] = "%$_faq_search%";
        $_faq_params[] = "%$_faq_search%";
    }
    if ($_faq_category !== '' && in_array($_faq_category, AI_QA_CATEGORIES, true)) {
        $_faq_where  .= ' AND category = ?';
        $_faq_params[] = $_faq_category;
    }

    try {
        $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_ai_faq $_faq_where");
        $sc->execute($_faq_params);
        $_faq_total = (int)$sc->fetchColumn();
        $_faq_totalPages = max(1, (int)ceil($_faq_total / $_qa_perPage));
        $_qa_page = min($_qa_page, $_faq_totalPages);
        $offset = ($_qa_page - 1) * $_qa_perPage;

        $sr = $pdo->prepare("
            SELECT f.id, f.category, f.canonical_question, f.answer,
                   f.created_at, f.updated_at,
                   (SELECT COUNT(*) FROM sys_ai_faq_variants v WHERE v.faq_id = f.id) AS variant_count
              FROM sys_ai_faq f
              $_faq_where
              ORDER BY f.updated_at DESC
              LIMIT $_qa_perPage OFFSET $offset
        ");
        $sr->execute($_faq_params);
        $_faq_list = $sr->fetchAll(PDO::FETCH_ASSOC);

        $_faq_totalAll     = (int)$pdo->query("SELECT COUNT(*) FROM sys_ai_faq")->fetchColumn();
        $_faq_statCategory = $pdo->query("SELECT category, COUNT(*) FROM sys_ai_faq GROUP BY category ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $_qa_dbError = $e->getMessage();
    }
}

$_faq_filterQs = http_build_query(array_filter([
    'faq_search'   => $_faq_search,
    'faq_category' => $_faq_category,
]));
$_faq_pgQs = $_faq_filterQs ? '&'.$_faq_filterQs : '';

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
    #ai-qa-modal, #ai-faq-modal { z-index: 200; }
    #ai-qa-modal-box, #ai-faq-modal-box { max-height: 90vh; }
    #ai-qa-modal-body, #ai-faq-modal-body { min-height: 0; }
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

    .qa-tab {
        padding: .75rem 1.25rem;
        font-size: .875rem; font-weight: 700;
        color: #6b7280;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;
        transition: color .15s, border-color .15s;
    }
    .qa-tab:hover { color: #1f2937; }
    .qa-tab.qa-tab-active--captured { color: #7c3aed; border-bottom-color: #9333ea; }
    .qa-tab.qa-tab-active--faq { color: #047857; border-bottom-color: #059669; }

    #vchecks { max-height: 24rem; overflow-y: auto; }
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
        <?php if ($_qa_tab === 'captured'): ?>
            <button id="btn-bulk-generate"
                class="px-4 py-2 bg-purple-600 text-white rounded-xl text-sm font-bold shadow hover:bg-purple-700 transition flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                สร้างคำตอบจาก AI (batch)
            </button>
        <?php else: ?>
            <button id="btn-faq-create"
                class="px-4 py-2 bg-emerald-600 text-white rounded-xl text-sm font-bold shadow hover:bg-emerald-700 transition flex items-center gap-2">
                <i class="fa-solid fa-plus"></i>
                สร้าง FAQ ใหม่
            </button>
        <?php endif; ?>
    </div>

    <!-- Tab switcher -->
    <div class="mb-6 border-b border-gray-200 flex gap-1">
        <a href="?section=ai_qa_lab&qa_tab=captured"
           class="qa-tab <?= $_qa_tab === 'captured' ? 'qa-tab-active--captured' : '' ?>">
            <i class="fa-solid fa-inbox mr-1.5"></i> Captured Questions
        </a>
        <a href="?section=ai_qa_lab&qa_tab=faq"
           class="qa-tab <?= $_qa_tab === 'faq' ? 'qa-tab-active--faq' : '' ?>">
            <i class="fa-solid fa-book-bookmark mr-1.5"></i> FAQ Knowledge Base
        </a>
    </div>

    <?php if ($_qa_tab === 'captured'): ?>
    <!-- ════════════ TAB: CAPTURED QUESTIONS ════════════ -->

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
                            <button class="qa-act qa-promote p-1.5 text-emerald-600 hover:bg-emerald-50 rounded-lg" data-id="<?= (int)$r['id'] ?>" title="ทำเป็น FAQ">
                                <i class="fa-solid fa-bookmark text-xs"></i>
                            </button>
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

    <?php else: /* ════════════ TAB: FAQ KNOWLEDGE BASE ════════════ */ ?>

    <!-- FAQ Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <div class="text-xs text-gray-500 font-bold uppercase tracking-wider">FAQ ทั้งหมด</div>
            <div class="text-2xl font-black text-gray-900 mt-1"><?= number_format($_faq_totalAll) ?></div>
        </div>
        <?php
        $topThree = array_slice($_faq_statCategory, 0, 3, true);
        foreach ($topThree as $cat => $cnt): ?>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <div class="text-xs text-gray-500 font-bold uppercase tracking-wider truncate"><?= htmlspecialchars((string)$cat) ?></div>
                <div class="text-2xl font-black text-emerald-600 mt-1"><?= number_format((int)$cnt) ?></div>
            </div>
        <?php endforeach;
        for ($i = count($topThree); $i < 3; $i++): ?>
            <div class="bg-white rounded-2xl border border-gray-200 p-4 opacity-50">
                <div class="text-xs text-gray-400 font-bold uppercase tracking-wider">—</div>
                <div class="text-2xl font-black text-gray-300 mt-1">0</div>
            </div>
        <?php endfor; ?>
    </div>

    <!-- FAQ Filter -->
    <form method="get" class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
        <input type="hidden" name="section" value="ai_qa_lab">
        <input type="hidden" name="qa_tab"  value="faq">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="md:col-span-2">
                <input type="text" name="faq_search" value="<?= htmlspecialchars($_faq_search) ?>"
                    placeholder="ค้นหาคำถาม / คำตอบ" class="qa-input">
            </div>
            <select name="faq_category" class="qa-input">
                <option value="">ทุกหมวด</option>
                <?php foreach (AI_QA_CATEGORIES as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $_faq_category === $cat ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="flex gap-2 justify-end items-center">
                <a href="?section=ai_qa_lab&qa_tab=faq" class="px-4 py-2 text-sm font-bold text-gray-600 hover:bg-gray-100 rounded-xl">ล้าง</a>
                <button type="submit" class="px-5 py-2 bg-gray-900 text-white text-sm font-bold rounded-xl hover:bg-gray-800">
                    <i class="fa-solid fa-filter mr-1"></i> กรอง
                </button>
            </div>
        </div>
    </form>

    <!-- FAQ Table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200">
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">หมวด</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">คำถาม / คำตอบ</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">Variants</th>
                        <th class="px-4 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">อัปเดต</th>
                        <th class="px-4 py-3 text-right text-xs font-black text-gray-500 uppercase tracking-wider">การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($_faq_list)): ?>
                        <tr><td colspan="5" class="px-4 py-12 text-center text-gray-400">
                            <i class="fa-solid fa-book text-3xl mb-2 block"></i>
                            ยังไม่มี FAQ — กดปุ่ม <b>"สร้าง FAQ ใหม่"</b> เพื่อเริ่ม หรือเปิดแท็บ Captured Questions
                            แล้วใช้ปุ่ม <i class="fa-solid fa-bookmark text-emerald-600"></i> เพื่อ promote คำถามจริงเป็น FAQ
                        </td></tr>
                    <?php else: foreach ($_faq_list as $f): ?>
                        <tr class="qa-row border-b border-gray-100">
                            <td class="px-4 py-3"><span class="qa-chip bg-emerald-50 text-emerald-700 border border-emerald-200"><?= htmlspecialchars((string)$f['category']) ?></span></td>
                            <td class="px-4 py-3 max-w-md">
                                <div class="text-gray-900 font-bold line-clamp-2"><?= htmlspecialchars(mb_substr((string)$f['canonical_question'], 0, 200)) ?></div>
                                <div class="text-xs text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars(mb_substr((string)$f['answer'], 0, 200)) ?></div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <?php $vc = (int)($f['variant_count'] ?? 0); ?>
                                <?php if ($vc > 0): ?>
                                    <span class="qa-chip bg-purple-50 text-purple-700 border border-purple-200"><i class="fa-solid fa-shuffle"></i> <?= $vc ?> รูปแบบ</span>
                                <?php else: ?>
                                    <span class="text-gray-400">ยังไม่มี</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap"><?= date('d/m/Y H:i', strtotime((string)$f['updated_at'])) ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button class="faq-edit px-3 py-1.5 bg-gray-900 text-white text-xs font-bold rounded-lg hover:bg-gray-800" data-id="<?= (int)$f['id'] ?>">
                                    <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                                </button>
                                <button class="faq-delete p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg" data-id="<?= (int)$f['id'] ?>" title="ลบ">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($_faq_total > 0): ?>
        <div class="px-5 py-4 border-t border-gray-100 flex items-center justify-between flex-wrap gap-3">
            <div class="text-xs text-gray-500">
                หน้า <?= $_qa_page ?> / <?= $_faq_totalPages ?> · รวม <?= number_format($_faq_total) ?> รายการ
            </div>
            <div class="flex items-center gap-1">
                <?php
                $base = '?section=ai_qa_lab&qa_tab=faq' . $_faq_pgQs;
                $disF = $_qa_page <= 1 ? 'pointer-events:none;opacity:.4' : '';
                $disL = $_qa_page >= $_faq_totalPages ? 'pointer-events:none;opacity:.4' : '';
                ?>
                <a href="<?= $base ?>&page=1" style="<?= $disF ?>" class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">«</a>
                <a href="<?= $base ?>&page=<?= max(1, $_qa_page - 1) ?>" style="<?= $disF ?>" class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">‹</a>
                <?php for ($i = max(1, $_qa_page - 2); $i <= min($_faq_totalPages, $_qa_page + 2); $i++): ?>
                    <a href="<?= $base ?>&page=<?= $i ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs <?= $i === $_qa_page ? 'bg-emerald-600 text-white font-bold' : 'border border-gray-200 text-gray-500 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <a href="<?= $base ?>&page=<?= min($_faq_totalPages, $_qa_page + 1) ?>" style="<?= $disL ?>" class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">›</a>
                <a href="<?= $base ?>&page=<?= $_faq_totalPages ?>" style="<?= $disL ?>" class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">»</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; /* end tab branch */ ?>
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

<!-- FAQ Edit/Create Modal -->
<div id="ai-faq-modal" class="hidden fixed inset-0 bg-black/40 items-center justify-center p-4">
    <div id="ai-faq-modal-box" class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 id="faq-mod-title" class="text-lg font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-book-bookmark text-emerald-500"></i> FAQ
            </h3>
            <button onclick="faqCloseModal()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="ai-faq-modal-body" class="p-6 overflow-y-auto flex-1 space-y-4">
            <input type="hidden" id="faq-mod-id" value="">
            <input type="hidden" id="faq-mod-source-qa-id" value="">
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">หมวดหมู่</label>
                <select id="faq-mod-category" class="qa-input">
                    <?php foreach (AI_QA_CATEGORIES as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">คำถามต้นฉบับ <span class="text-rose-500">*</span></label>
                <textarea id="faq-mod-question" rows="2" class="qa-input" placeholder="เช่น เปิดทำการกี่โมง"></textarea>
            </div>
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-wider mb-2">คำตอบ <span class="text-rose-500">*</span></label>
                <textarea id="faq-mod-answer" rows="5" class="qa-input" placeholder="คำตอบที่จะใช้ตอบเมื่อ user ถามคำถามนี้"></textarea>
            </div>

            <!-- Variants section (only when editing existing FAQ) -->
            <div id="faq-variants-section" class="hidden border-t border-gray-200 pt-4">
                <div class="flex items-center justify-between mb-3">
                    <label class="block text-xs font-black text-gray-500 uppercase tracking-wider">
                        <i class="fa-solid fa-shuffle mr-1"></i> คำถามรูปแบบใกล้เคียง (Variants)
                    </label>
                    <button id="faq-gen-variants-btn"
                        class="px-3 py-1.5 bg-purple-600 text-white text-xs font-bold rounded-lg hover:bg-purple-700">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> ให้ AI เจน 5 รูปแบบ
                    </button>
                </div>
                <div id="faq-variants-list" class="space-y-2"></div>

                <!-- Manual add -->
                <div class="mt-3 flex gap-2">
                    <input id="faq-variant-add-input" class="qa-input flex-1" placeholder="พิมพ์ variant เพิ่มเอง แล้วกด Enter">
                    <button id="faq-variant-add-btn" class="px-4 py-2 bg-gray-900 text-white text-xs font-bold rounded-xl hover:bg-gray-800">เพิ่ม</button>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-2">
            <button onclick="faqCloseModal()" class="px-4 py-2 text-sm font-bold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
            <button onclick="faqSave()" class="px-5 py-2 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl">
                <i class="fa-solid fa-check mr-1"></i> บันทึก
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

    document.getElementById('btn-bulk-generate')?.addEventListener('click', async () => {
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

    // ─── Promote captured question → FAQ (in Captured tab) ───────────────
    document.querySelectorAll('.qa-promote').forEach(btn => {
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            faqOpenModal({
                source_qa_id: tr.dataset.id,
                question: tr.dataset.question,
                answer: tr.dataset.answer || '',
                category: tr.dataset.category || 'อื่นๆ',
                isNew: true,
            });
        });
    });

    // ─── FAQ tab buttons ─────────────────────────────────────────────────
    document.getElementById('btn-faq-create')?.addEventListener('click', () => {
        faqOpenModal({ isNew: true });
    });

    document.querySelectorAll('.faq-edit').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const res = await api('faq_get', { id });
            if (!res.ok) {
                Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: res.message || '' });
                return;
            }
            faqOpenModal({
                id: res.faq.id,
                question: res.faq.canonical_question,
                answer: res.faq.answer,
                category: res.faq.category,
                variants: res.variants || [],
                isNew: false,
            });
        });
    });

    document.querySelectorAll('.faq-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            const { isConfirmed } = await Swal.fire({
                icon: 'warning',
                title: 'ลบ FAQ นี้?',
                text: 'จะลบทั้ง FAQ และ variants ทั้งหมด',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#e11d48',
            });
            if (!isConfirmed) return;
            const res = await api('faq_delete', { id: btn.dataset.id });
            if (res.ok) location.reload();
            else Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
        });
    });

    // ─── FAQ Modal logic ─────────────────────────────────────────────────
    function faqOpenModal(opts) {
        document.getElementById('faq-mod-id').value = opts.id || '';
        document.getElementById('faq-mod-source-qa-id').value = opts.source_qa_id || '';
        document.getElementById('faq-mod-category').value = opts.category || 'อื่นๆ';
        document.getElementById('faq-mod-question').value = opts.question || '';
        document.getElementById('faq-mod-answer').value = opts.answer || '';
        document.getElementById('faq-mod-title').innerHTML =
            (opts.isNew
                ? '<i class="fa-solid fa-plus text-emerald-500"></i> สร้าง FAQ ใหม่'
                : '<i class="fa-solid fa-pen-to-square text-emerald-500"></i> แก้ไข FAQ');

        const variantsSec = document.getElementById('faq-variants-section');
        if (opts.id) {
            variantsSec.classList.remove('hidden');
            renderVariants(opts.variants || []);
        } else {
            variantsSec.classList.add('hidden');
        }

        const m = document.getElementById('ai-faq-modal');
        m.classList.remove('hidden');
        m.style.display = 'flex';
    }

    window.faqCloseModal = function() {
        const m = document.getElementById('ai-faq-modal');
        m.classList.add('hidden');
        m.style.display = 'none';
    };

    function renderVariants(list) {
        const box = document.getElementById('faq-variants-list');
        if (!list.length) {
            box.innerHTML = '<div class="text-xs text-gray-400 italic py-2">ยังไม่มี variant — กดให้ AI เจน หรือพิมพ์เพิ่มด้านล่าง</div>';
            return;
        }
        box.innerHTML = list.map(v => `
            <div class="flex items-center gap-2 p-2 bg-gray-50 rounded-xl border border-gray-200" data-vid="${v.id}">
                <span class="qa-chip ${v.source === 'ai_generated' ? 'bg-purple-50 text-purple-700 border border-purple-200' : 'bg-gray-100 text-gray-600 border border-gray-200'}">
                    ${v.source === 'ai_generated' ? 'AI' : 'manual'}
                </span>
                <span class="flex-1 text-sm text-gray-800">${escapeHtml(v.variant_question)}</span>
                <button class="faq-variant-remove p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg" data-vid="${v.id}">
                    <i class="fa-solid fa-trash text-xs"></i>
                </button>
            </div>
        `).join('');
        box.querySelectorAll('.faq-variant-remove').forEach(b => {
            b.addEventListener('click', async () => {
                const vid = b.dataset.vid;
                const res = await api('faq_delete_variant', { id: vid });
                if (res.ok) b.closest('[data-vid]').remove();
            });
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    document.getElementById('faq-gen-variants-btn')?.addEventListener('click', async () => {
        const q = document.getElementById('faq-mod-question').value.trim();
        const fid = document.getElementById('faq-mod-id').value;
        if (!q) {
            Swal.fire({ icon: 'warning', title: 'พิมพ์คำถามก่อน' });
            return;
        }
        if (!fid) {
            Swal.fire({ icon: 'info', title: 'บันทึก FAQ ก่อน', text: 'ต้องบันทึก FAQ ก่อนถึงจะเจน variants ได้' });
            return;
        }
        Swal.fire({ title: 'กำลังเจน…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const gen = await api('faq_generate_variants', { question: q });
        if (!gen.ok) {
            Swal.fire({ icon: 'error', title: 'เจนไม่สำเร็จ', text: gen.message || '' });
            return;
        }
        // ให้ admin เห็นรายการ variant แล้วเลือก keep
        const checks = gen.variants.map((v, i) =>
            `<label class="flex items-start gap-2 p-2 hover:bg-gray-50 rounded-lg cursor-pointer text-left">
                <input type="checkbox" class="mt-1" value="${i}" checked>
                <span class="text-sm text-gray-800">${escapeHtml(v)}</span>
            </label>`
        ).join('');
        const { isConfirmed, value: chosen } = await Swal.fire({
            title: 'เลือก variants ที่ต้องการเก็บ',
            html: `<div class="text-left" id="vchecks">${checks}</div>`,
            showCancelButton: true,
            confirmButtonText: 'บันทึกที่เลือก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#7c3aed',
            preConfirm: () => {
                const idxs = Array.from(document.querySelectorAll('#vchecks input:checked')).map(c => parseInt(c.value, 10));
                return idxs.map(i => gen.variants[i]);
            },
        });
        if (!isConfirmed) return;
        const list = chosen || [];
        if (!list.length) return;
        const save = await api('faq_save_variants', {
            faq_id: fid,
            variants: JSON.stringify(list),
            source: 'ai_generated',
        });
        if (save.ok) {
            // reload variants in modal
            const refreshed = await api('faq_get', { id: fid });
            if (refreshed.ok) renderVariants(refreshed.variants || []);
            Swal.fire({ icon: 'success', title: `บันทึก ${save.saved} variant`, timer: 900, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: save.message || '' });
        }
    });

    document.getElementById('faq-variant-add-btn')?.addEventListener('click', addManualVariant);
    document.getElementById('faq-variant-add-input')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addManualVariant(); }
    });
    async function addManualVariant() {
        const inp = document.getElementById('faq-variant-add-input');
        const v = inp.value.trim();
        const fid = document.getElementById('faq-mod-id').value;
        if (!v || !fid) return;
        const res = await api('faq_save_variants', {
            faq_id: fid,
            variants: JSON.stringify([v]),
            source: 'manual',
        });
        if (res.ok) {
            inp.value = '';
            const r = await api('faq_get', { id: fid });
            if (r.ok) renderVariants(r.variants || []);
        } else {
            Swal.fire({ icon: 'error', title: 'เพิ่มไม่สำเร็จ', text: res.message || '' });
        }
    }

    window.faqSave = async function() {
        const id = document.getElementById('faq-mod-id').value;
        const payload = {
            question: document.getElementById('faq-mod-question').value,
            answer: document.getElementById('faq-mod-answer').value,
            category: document.getElementById('faq-mod-category').value,
        };
        if (!payload.question.trim() || !payload.answer.trim()) {
            Swal.fire({ icon: 'warning', title: 'กรอกคำถามและคำตอบให้ครบ' });
            return;
        }

        let res;
        if (id) {
            res = await api('faq_update', { ...payload, id });
        } else {
            const srcQa = document.getElementById('faq-mod-source-qa-id').value;
            res = await api('faq_create', { ...payload, source_qa_id: srcQa || '' });
        }
        if (!res.ok) {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: res.message || '' });
            return;
        }
        // ถ้าเพิ่งสร้างใหม่ → ใส่ id แล้วเปิด variants section ให้กด generate ได้เลย
        if (!id && res.id) {
            document.getElementById('faq-mod-id').value = res.id;
            document.getElementById('faq-variants-section').classList.remove('hidden');
            renderVariants([]);
            Swal.fire({
                icon: 'success',
                title: 'สร้าง FAQ แล้ว',
                text: 'กด "ให้ AI เจน 5 รูปแบบ" เพื่อเพิ่ม variant คำถาม หรือปิด modal เพื่อกลับ',
                timer: 2500,
            });
        } else {
            Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 900, showConfirmButton: false })
                .then(() => location.reload());
        }
    };
})();
</script>
