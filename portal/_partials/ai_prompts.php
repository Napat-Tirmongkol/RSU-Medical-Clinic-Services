<?php
/**
 * portal/_partials/ai_prompts.php — AI Prompts editor + logic flow
 * Admin แก้ prompt ของ matcher / generator ที่ใช้กับ Gemini
 * $pdo มาจาก parent scope (portal/index.php)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/ai_prompts_helper.php';
$_aip_prompts = list_ai_prompts($pdo);
?>
<style>
    #ai-prompts-section .ap-card {
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        padding: 18px 20px;
        margin-bottom: 14px;
    }
    #ai-prompts-section .ap-flow-card {
        background: #f5f3ff;
        border: 1.5px solid #ddd6fe;
    }
    #ai-prompts-section pre.ap-textarea {
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 12.5px;
        line-height: 1.55;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 14px;
        white-space: pre-wrap;
        word-wrap: break-word;
        max-height: 400px;
        overflow-y: auto;
        margin: 0;
    }
    #ai-prompts-section textarea.ap-textarea-edit {
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 12.5px;
        line-height: 1.55;
        width: 100%;
        min-height: 280px;
        max-height: 600px;
        padding: 12px 14px;
        border: 1.5px solid #93c5fd;
        border-radius: 10px;
        resize: vertical;
        outline: none;
    }
    #ai-prompts-section textarea.ap-textarea-edit:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }
    #ai-prompts-section .ap-pill {
        font-family: ui-monospace, SFMono-Regular, monospace;
        font-size: 11px;
        font-weight: 700;
        background: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
        padding: 2px 8px;
        border-radius: 6px;
    }
    #ai-prompts-section .ap-flow-step {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 8px 0;
    }
    #ai-prompts-section .ap-flow-num {
        flex-shrink: 0;
        width: 26px;
        height: 26px;
        border-radius: 8px;
        background: #7c3aed;
        color: #fff;
        font-weight: 900;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    #ai-prompts-section .ap-badge-custom {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fcd34d;
        font-size: 10px;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 999px;
    }
    #ai-prompts-section .ap-badge-default {
        background: #ecfdf5;
        color: #065f46;
        border: 1px solid #a7f3d0;
        font-size: 10px;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 999px;
    }

    /* ── Bold & Colorful — tilt-aware lift on prompt cards ── */
    #ai-prompts-section .ap-card { isolation: isolate; transition: transform .25s cubic-bezier(.16,1,.3,1), box-shadow .25s ease, border-color .25s ease; }
    #ai-prompts-section .ap-card:hover:not(.fx-tilt) { transform: translateY(-3px); box-shadow:0 18px 36px -18px rgba(99,102,241,.20); border-color:#a5b4fc; }
    #ai-prompts-section .ap-card.fx-tilt:hover { --lift: -3px; box-shadow:0 18px 36px -18px rgba(99,102,241,.30); border-color:#a5b4fc; }

    /* ── DARK MODE ──────────────────────────────────────────────── */
    body[data-theme='dark'] #ai-prompts-section .ap-card { background:#0f172a; border-color:#1e293b; box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35); }
    body[data-theme='dark'] #ai-prompts-section .ap-card:hover { border-color:#6366f1; }
    body[data-theme='dark'] #ai-prompts-section .ap-flow-card { background: rgba(168,85,247,.10); border-color: rgba(168,85,247,.30); }
    body[data-theme='dark'] #ai-prompts-section pre.ap-textarea { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
    body[data-theme='dark'] #ai-prompts-section textarea.ap-textarea-edit { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
    body[data-theme='dark'] #ai-prompts-section textarea.ap-textarea-edit:focus { background:#0f172a; border-color:#3b82f6; }
    body[data-theme='dark'] #ai-prompts-section .ap-pill { background: rgba(59,130,246,.18); color:#93c5fd; border-color: rgba(59,130,246,.35); }
    body[data-theme='dark'] #ai-prompts-section .bg-white { background:#0f172a !important; }
    body[data-theme='dark'] #ai-prompts-section .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
    body[data-theme='dark'] #ai-prompts-section .bg-purple-50 { background: rgba(168,85,247,.18) !important; }
    body[data-theme='dark'] #ai-prompts-section .bg-indigo-50 { background: rgba(99,102,241,.18) !important; }
    body[data-theme='dark'] #ai-prompts-section .bg-blue-50 { background: rgba(59,130,246,.18) !important; }
    body[data-theme='dark'] #ai-prompts-section .bg-emerald-50 { background: rgba(16,185,129,.18) !important; }
    body[data-theme='dark'] #ai-prompts-section .bg-amber-50 { background: rgba(245,158,11,.18) !important; }
    body[data-theme='dark'] #ai-prompts-section .text-slate-900 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #ai-prompts-section .text-slate-800 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #ai-prompts-section .text-slate-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #ai-prompts-section .text-slate-600 { color:#cbd5e1 !important; }
    body[data-theme='dark'] #ai-prompts-section .text-slate-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #ai-prompts-section .text-slate-400 { color:#64748b !important; }
    body[data-theme='dark'] #ai-prompts-section .border-slate-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #ai-prompts-section .border-slate-100 { border-color:#1e293b !important; }
    body[data-theme='dark'] #ai-prompts-section .border-purple-200 { border-color: rgba(168,85,247,.30) !important; }
    body[data-theme='dark'] #ai-prompts-section .border-indigo-200 { border-color: rgba(99,102,241,.30) !important; }

    @media (prefers-reduced-motion: reduce) {
        #ai-prompts-section .ap-card { transition: none !important; transform: none !important; }
    }
</style>

<div id="ai-prompts-section" class="p-5 md:p-6 max-w-5xl mx-auto">
    <div class="flex items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-code text-purple-600"></i>
                AI Prompts
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                แก้ prompt ที่ AI ใช้ตอบคำถาม &mdash; เปลี่ยนแล้วมีผลทันที (cache ภายใน request เดียวเท่านั้น)
            </p>
        </div>
    </div>

    <!-- Logic flow card -->
    <div class="ap-card ap-flow-card">
        <div class="flex items-center gap-2 mb-3">
            <i class="fa-solid fa-diagram-project text-purple-700"></i>
            <h2 class="text-base font-black text-purple-900">Logic flow ของ AI Q&amp;A</h2>
        </div>
        <div class="text-sm text-purple-900">
            <div class="ap-flow-step">
                <div class="ap-flow-num">1</div>
                <div>
                    <div class="font-bold">User ส่งข้อความใน LINE</div>
                    <div class="text-xs text-purple-700 mt-0.5">webhook รับ event &mdash; เช็ค blocklist, insurance, enabled, only_when_closed, rate limit</div>
                </div>
            </div>
            <div class="ap-flow-step">
                <div class="ap-flow-num">2</div>
                <div>
                    <div class="font-bold">Phase 1 &mdash; Exact match (ฟรี ไม่เรียก AI)</div>
                    <div class="text-xs text-purple-700 mt-0.5">SQL: <code>sys_ai_faq.canonical</code> &rarr; <code>sys_ai_faq_variants</code> &rarr; <code>sys_ai_qa_log</code> status=approved</div>
                </div>
            </div>
            <div class="ap-flow-step">
                <div class="ap-flow-num">3</div>
                <div>
                    <div class="font-bold">Phase 2 &mdash; Gemini matcher (ใช้ <span class="ap-pill">prompt: matcher</span>)</div>
                    <div class="text-xs text-purple-700 mt-0.5">ส่งคำถาม + รายการคำถามที่ approve แล้ว ให้ Gemini เลือก best match (confidence &ge; 0.7)</div>
                </div>
            </div>
            <div class="ap-flow-step">
                <div class="ap-flow-num">4</div>
                <div>
                    <div class="font-bold">Match? &rarr; ตอบ Flex bubble จาก approved answer</div>
                    <div class="text-xs text-purple-700 mt-0.5">ถ้าไม่ match &rarr; fallthrough &rarr; default reply (ถ้าเปิด)</div>
                </div>
            </div>
            <hr class="my-3 border-purple-200">
            <div class="ap-flow-step">
                <div class="ap-flow-num" style="background:#059669">A</div>
                <div>
                    <div class="font-bold">Admin กด Generate ใน AI QA Lab (ใช้ <span class="ap-pill">prompt: generator</span>)</div>
                    <div class="text-xs text-purple-700 mt-0.5">AI ดึง clinic context (เวลาเปิด-ปิด/ตารางหมอ/FAQ) มาประกอบ &rarr; ร่างคำตอบ + จัดหมวดหมู่ + ให้ confidence</div>
                </div>
            </div>
            <div class="ap-flow-step">
                <div class="ap-flow-num" style="background:#059669">B</div>
                <div>
                    <div class="font-bold">Admin review &rarr; approve &rarr; เข้า pool ให้ matcher ใช้</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Each prompt -->
    <?php foreach ($_aip_prompts as $p): ?>
    <div class="ap-card fx-tilt fx-tilt-light" data-tilt="3" data-key="<?= htmlspecialchars($p['key']) ?>">
        <div class="flex items-start justify-between gap-3 mb-2">
            <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                    <span class="ap-pill"><?= htmlspecialchars($p['key']) ?></span>
                    <?php if ($p['is_custom']): ?>
                        <span class="ap-badge-custom">CUSTOM (แก้ไขแล้ว)</span>
                    <?php else: ?>
                        <span class="ap-badge-default">DEFAULT</span>
                    <?php endif; ?>
                </div>
                <h3 class="text-base font-black text-gray-900"><?= htmlspecialchars($p['label']) ?></h3>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($p['description']) ?></p>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
                <button type="button" class="ap-test-btn px-3 py-1.5 bg-cyan-600 text-white text-xs font-bold rounded-lg hover:bg-cyan-700">
                    <i class="fa-solid fa-flask"></i> ทดสอบ
                </button>
                <button type="button" class="ap-edit-btn px-3 py-1.5 bg-purple-600 text-white text-xs font-bold rounded-lg hover:bg-purple-700">
                    <i class="fa-solid fa-pen"></i> แก้ไข
                </button>
                <button type="button" class="ap-history-btn px-3 py-1.5 bg-white text-slate-700 text-xs font-bold rounded-lg border border-slate-300 hover:bg-slate-50">
                    <i class="fa-solid fa-clock-rotate-left"></i> ประวัติ
                </button>
                <?php if ($p['is_custom']): ?>
                <button type="button" class="ap-reset-btn px-3 py-1.5 bg-white text-gray-600 text-xs font-bold rounded-lg border border-gray-300 hover:bg-gray-50">
                    <i class="fa-solid fa-rotate-left"></i> รีเซ็ต
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-2 mb-2">
            <div class="text-xs font-bold text-gray-500 mb-1.5">Placeholders ที่ใช้ได้</div>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($p['placeholders'] as $name => $desc): ?>
                    <span class="ap-pill" title="<?= htmlspecialchars($desc) ?>" style="cursor:help">
                        {<?= htmlspecialchars($name) ?>}
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <details class="mt-2">
            <summary class="text-xs font-bold text-gray-600 cursor-pointer hover:text-purple-700 select-none">
                <i class="fa-solid fa-eye mr-1"></i> ดู prompt content (<?= mb_strlen($p['content']) ?> chars)
            </summary>
            <pre class="ap-textarea mt-2"><?= htmlspecialchars($p['content']) ?></pre>
        </details>

        <?php if ($p['updated_at']): ?>
        <div class="text-[11px] text-gray-400 mt-2">
            แก้ไขล่าสุด: <?= htmlspecialchars($p['updated_at']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Test modal (hidden by default) -->
    <div id="ap-test-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[92vh] flex flex-col">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold text-cyan-600 uppercase">🧪 Test sandbox</div>
                    <h3 id="ap-test-title" class="text-lg font-black text-gray-900"></h3>
                </div>
                <button id="ap-test-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-4 overflow-y-auto flex-1">
                <!-- Variables section -->
                <div class="mb-4">
                    <div class="text-xs font-bold text-gray-700 mb-2">
                        <i class="fa-solid fa-code"></i> ตัวแปร (placeholders) — แก้ค่าตัวอย่างได้ตามใจ
                    </div>
                    <div id="ap-test-vars" class="space-y-2"></div>
                </div>

                <!-- Optional prompt content override -->
                <details class="mb-4">
                    <summary class="text-xs font-bold text-gray-600 cursor-pointer hover:text-cyan-700 select-none">
                        <i class="fa-solid fa-pen-to-square mr-1"></i> ทดสอบกับ prompt ที่แก้ใน sandbox นี้ (ไม่ save)
                    </summary>
                    <textarea id="ap-test-content" class="ap-textarea-edit mt-2" style="min-height:180px"></textarea>
                </details>

                <button id="ap-test-run" class="w-full px-4 py-3 bg-cyan-600 text-white text-sm font-bold rounded-lg hover:bg-cyan-700 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-play"></i>
                    Run test (ยิง Gemini จริง · ไม่บันทึก)
                </button>

                <!-- Result section -->
                <div id="ap-test-result" class="mt-4 hidden">
                    <div class="text-xs font-bold text-gray-700 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-vial"></i> ผลลัพธ์
                        <span id="ap-test-meta" class="ml-auto text-[10px] font-normal text-gray-500"></span>
                    </div>

                    <div id="ap-test-result-error" class="hidden bg-rose-50 border border-rose-200 rounded-lg px-4 py-3 mb-3">
                        <div class="text-sm font-bold text-rose-700"><i class="fa-solid fa-circle-xmark"></i> Error</div>
                        <pre id="ap-test-error-text" class="text-xs text-rose-700 mt-1 whitespace-pre-wrap"></pre>
                    </div>

                    <details class="mb-3">
                        <summary class="text-xs font-bold text-gray-600 cursor-pointer hover:text-cyan-700 select-none">
                            <i class="fa-solid fa-eye mr-1"></i> ดู prompt ที่ resolve แล้ว (ส่งจริงให้ Gemini)
                        </summary>
                        <pre id="ap-test-resolved" class="ap-textarea mt-2" style="max-height:240px"></pre>
                    </details>

                    <div class="mb-3">
                        <div class="text-xs font-bold text-gray-700 mb-1">Response (text)</div>
                        <pre id="ap-test-response" class="ap-textarea" style="max-height:280px;background:#0f172a;color:#e2e8f0"></pre>
                    </div>

                    <div id="ap-test-parsed-wrap" class="mb-3 hidden">
                        <div class="text-xs font-bold text-gray-700 mb-1">Parsed JSON</div>
                        <pre id="ap-test-parsed" class="ap-textarea" style="background:#ecfdf5;color:#065f46;border-color:#a7f3d0"></pre>
                    </div>
                </div>
            </div>
            <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
                <button id="ap-test-done" class="px-4 py-2 bg-white text-gray-700 text-sm font-bold rounded-lg border border-gray-300 hover:bg-gray-50">
                    ปิด
                </button>
            </div>
        </div>
    </div>

    <!-- History modal (hidden by default) -->
    <div id="ap-history-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold text-slate-600 uppercase">📜 ประวัติการแก้</div>
                    <h3 id="ap-history-title" class="text-lg font-black text-gray-900"></h3>
                </div>
                <button id="ap-history-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-4 overflow-y-auto flex-1">
                <div id="ap-history-empty" class="hidden text-center py-12 text-gray-400">
                    <i class="fa-solid fa-clock-rotate-left text-4xl mb-3 block"></i>
                    <div class="text-sm font-bold">ยังไม่มีประวัติ</div>
                    <div class="text-xs mt-1">ประวัติจะเก็บอัตโนมัติเมื่อ admin save prompt ครั้งถัดไป (เก็บล่าสุด 50 versions)</div>
                </div>
                <div id="ap-history-list" class="space-y-2"></div>
            </div>
        </div>
    </div>

    <!-- Edit modal (hidden by default) -->
    <div id="ap-edit-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold text-purple-600 uppercase">แก้ไข Prompt</div>
                    <h3 id="ap-modal-title" class="text-lg font-black text-gray-900"></h3>
                </div>
                <button id="ap-modal-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-4 overflow-y-auto flex-1">
                <div class="text-xs text-gray-500 mb-2">
                    Placeholders ใช้ได้: <span id="ap-modal-placeholders" class="font-mono"></span>
                </div>
                <textarea id="ap-modal-textarea" class="ap-textarea-edit"></textarea>
                <div class="text-[11px] text-amber-700 mt-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <strong>คำเตือน:</strong> prompt ที่บันทึกแล้วมีผลทันทีกับ AI ตอนถัดไป &mdash; ถ้าผิด format อาจทำให้ AI ไม่ตอบ ลอง preview/ดู default ก่อน
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-2">
                <button id="ap-modal-cancel" class="px-4 py-2 bg-white text-gray-700 text-sm font-bold rounded-lg border border-gray-300 hover:bg-gray-50">
                    ยกเลิก
                </button>
                <button id="ap-modal-save" class="px-4 py-2 bg-purple-600 text-white text-sm font-bold rounded-lg hover:bg-purple-700">
                    <i class="fa-solid fa-save mr-1"></i> บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const PROMPTS = <?= json_encode(array_column($_aip_prompts, null, 'key'), JSON_UNESCAPED_UNICODE) ?>;
    const CSRF = '<?= get_csrf_token() ?>';
    const MODAL = document.getElementById('ap-edit-modal');
    const TITLE = document.getElementById('ap-modal-title');
    const PLACEHOLDERS_EL = document.getElementById('ap-modal-placeholders');
    const TEXTAREA = document.getElementById('ap-modal-textarea');
    let currentKey = null;

    function openEdit(key) {
        const p = PROMPTS[key];
        if (!p) return;
        currentKey = key;
        TITLE.textContent = p.label;
        const phs = Object.keys(p.placeholders || {}).map(n => '{' + n + '}').join(', ');
        PLACEHOLDERS_EL.textContent = phs || '(ไม่มี)';
        TEXTAREA.value = p.content;
        MODAL.classList.remove('hidden');
        MODAL.classList.add('flex');
    }
    function closeEdit() {
        MODAL.classList.add('hidden');
        MODAL.classList.remove('flex');
        currentKey = null;
    }

    document.querySelectorAll('.ap-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const key = btn.closest('[data-key]').dataset.key;
            openEdit(key);
        });
    });

    // ── Test sandbox ──────────────────────────────────────────────────────
    const TEST_MODAL = document.getElementById('ap-test-modal');
    const TEST_TITLE = document.getElementById('ap-test-title');
    const TEST_VARS  = document.getElementById('ap-test-vars');
    const TEST_CONTENT = document.getElementById('ap-test-content');
    const TEST_RESULT = document.getElementById('ap-test-result');
    const TEST_META = document.getElementById('ap-test-meta');
    const TEST_ERR = document.getElementById('ap-test-result-error');
    const TEST_ERR_TEXT = document.getElementById('ap-test-error-text');
    const TEST_RESOLVED = document.getElementById('ap-test-resolved');
    const TEST_RESPONSE = document.getElementById('ap-test-response');
    const TEST_PARSED_WRAP = document.getElementById('ap-test-parsed-wrap');
    const TEST_PARSED = document.getElementById('ap-test-parsed');
    let testKey = null;

    function openTest(key) {
        const p = PROMPTS[key];
        if (!p) return;
        testKey = key;
        TEST_TITLE.textContent = p.label;
        TEST_CONTENT.value = p.content;
        TEST_RESULT.classList.add('hidden');

        // Build variable inputs from placeholders + samples
        TEST_VARS.innerHTML = '';
        const samples = p.samples || {};
        Object.entries(p.placeholders || {}).forEach(([name, desc]) => {
            const wrap = document.createElement('div');
            wrap.innerHTML = `
                <label class="text-[11px] font-bold text-gray-600 flex items-center gap-1.5">
                    <span class="ap-pill">{${name}}</span>
                    <span class="font-normal text-gray-500">${desc}</span>
                </label>
                <textarea class="ap-textarea-edit ap-test-var" data-name="${name}" rows="3"
                    style="min-height:60px;font-size:12px;margin-top:4px"></textarea>
            `;
            TEST_VARS.appendChild(wrap);
            wrap.querySelector('textarea').value = samples[name] || '';
        });

        TEST_MODAL.classList.remove('hidden');
        TEST_MODAL.classList.add('flex');
    }
    function closeTest() {
        TEST_MODAL.classList.add('hidden');
        TEST_MODAL.classList.remove('flex');
        testKey = null;
    }

    document.querySelectorAll('.ap-test-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const key = btn.closest('[data-key]').dataset.key;
            openTest(key);
        });
    });

    document.getElementById('ap-test-close').addEventListener('click', closeTest);
    document.getElementById('ap-test-done').addEventListener('click', closeTest);
    TEST_MODAL.addEventListener('click', (e) => { if (e.target === TEST_MODAL) closeTest(); });

    // ── History ───────────────────────────────────────────────────────────
    const HIST_MODAL = document.getElementById('ap-history-modal');
    const HIST_TITLE = document.getElementById('ap-history-title');
    const HIST_LIST  = document.getElementById('ap-history-list');
    const HIST_EMPTY = document.getElementById('ap-history-empty');

    function fmtTime(t) {
        if (!t) return '';
        try {
            const d = new Date(t.replace(' ', 'T') + 'Z');
            return d.toLocaleString('th-TH', { hour12: false });
        } catch { return t; }
    }

    async function openHistory(key) {
        const p = PROMPTS[key];
        if (!p) return;
        HIST_TITLE.textContent = p.label;
        HIST_LIST.innerHTML = '<div class="text-center text-gray-400 py-8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>';
        HIST_EMPTY.classList.add('hidden');
        HIST_MODAL.classList.remove('hidden');
        HIST_MODAL.classList.add('flex');

        try {
            const r = await fetch('ajax_ai_prompts.php?action=history&key=' + encodeURIComponent(key));
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'load failed');
            renderHistory(j.history || []);
        } catch (e) {
            HIST_LIST.innerHTML = `<div class="text-center text-rose-600 py-8"><i class="fa-solid fa-circle-xmark"></i> โหลดไม่สำเร็จ: ${e.message}</div>`;
        }
    }

    function renderHistory(items) {
        HIST_LIST.innerHTML = '';
        if (!items.length) {
            HIST_EMPTY.classList.remove('hidden');
            return;
        }
        HIST_EMPTY.classList.add('hidden');
        items.forEach(item => {
            const len = (item.content || '').length;
            const who = item.saved_by_name || (item.saved_by ? `admin#${item.saved_by}` : '—');
            const div = document.createElement('div');
            div.className = 'border border-slate-200 rounded-lg overflow-hidden';
            div.innerHTML = `
                <div class="flex items-center justify-between px-4 py-3 bg-slate-50">
                    <div>
                        <div class="text-xs font-bold text-slate-700">${fmtTime(item.saved_at)}</div>
                        <div class="text-[10px] text-slate-500 mt-0.5">โดย ${who} · ${len} chars</div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="ap-hist-view px-3 py-1.5 bg-white text-slate-700 text-[11px] font-bold rounded border border-slate-300 hover:bg-slate-100">
                            <i class="fa-solid fa-eye"></i> ดู
                        </button>
                        <button type="button" class="ap-hist-rollback px-3 py-1.5 bg-amber-600 text-white text-[11px] font-bold rounded hover:bg-amber-700" data-id="${item.id}">
                            <i class="fa-solid fa-rotate-left"></i> Rollback
                        </button>
                    </div>
                </div>
                <pre class="ap-textarea hidden" style="margin:10px 14px 14px;border-color:#e2e8f0;max-height:280px"></pre>
            `;
            const pre = div.querySelector('pre');
            pre.textContent = item.content || '';

            div.querySelector('.ap-hist-view').addEventListener('click', () => {
                pre.classList.toggle('hidden');
            });
            div.querySelector('.ap-hist-rollback').addEventListener('click', async (ev) => {
                const id = ev.currentTarget.dataset.id;
                const { isConfirmed } = await Swal.fire({
                    icon: 'warning',
                    title: 'Rollback ไป version นี้?',
                    text: 'ค่าปัจจุบันจะถูกบันทึกลง history ก่อน — สามารถ rollback กลับได้อีก',
                    showCancelButton: true,
                    confirmButtonText: 'Rollback',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#d97706',
                });
                if (!isConfirmed) return;
                const fd = new FormData();
                fd.append('action', 'rollback');
                fd.append('history_id', id);
                fd.append('csrf_token', CSRF);
                try {
                    const r = await fetch('ajax_ai_prompts.php', { method: 'POST', body: fd });
                    const j = await r.json();
                    if (j.ok) {
                        Swal.fire({ icon: 'success', title: 'Rollback แล้ว', timer: 1200, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: j.error || j.message || '' });
                    }
                } catch (e) {
                    Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
                }
            });

            HIST_LIST.appendChild(div);
        });
    }

    document.querySelectorAll('.ap-history-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const key = btn.closest('[data-key]').dataset.key;
            openHistory(key);
        });
    });

    document.getElementById('ap-history-close').addEventListener('click', () => {
        HIST_MODAL.classList.add('hidden');
        HIST_MODAL.classList.remove('flex');
    });
    HIST_MODAL.addEventListener('click', (e) => {
        if (e.target === HIST_MODAL) {
            HIST_MODAL.classList.add('hidden');
            HIST_MODAL.classList.remove('flex');
        }
    });

    document.getElementById('ap-test-run').addEventListener('click', async () => {
        if (!testKey) return;
        const vars = {};
        document.querySelectorAll('.ap-test-var').forEach(t => {
            vars[t.dataset.name] = t.value;
        });
        const content = TEST_CONTENT.value.trim();
        if (content === '') {
            Swal.fire({ icon: 'warning', title: 'Content ว่าง' });
            return;
        }

        const btn = document.getElementById('ap-test-run');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังรัน...';

        const fd = new FormData();
        fd.append('action', 'test');
        fd.append('key', testKey);
        fd.append('content', content);
        fd.append('vars', JSON.stringify(vars));
        fd.append('csrf_token', CSRF);

        try {
            const r = await fetch('ajax_ai_prompts.php', { method: 'POST', body: fd });
            const j = await r.json();
            TEST_RESULT.classList.remove('hidden');
            TEST_RESOLVED.textContent = j.resolved_prompt || '(ไม่มี)';

            if (!j.ok) {
                TEST_ERR.classList.remove('hidden');
                TEST_ERR_TEXT.textContent = (j.error || '') + (j.response_raw ? '\n\n' + j.response_raw : '');
                TEST_RESPONSE.textContent = '';
                TEST_PARSED_WRAP.classList.add('hidden');
                TEST_META.textContent = (j.elapsed_ms !== undefined ? `${j.elapsed_ms}ms` : '');
            } else {
                TEST_ERR.classList.add('hidden');
                TEST_RESPONSE.textContent = j.response_text || '(empty)';
                if (j.parsed) {
                    TEST_PARSED_WRAP.classList.remove('hidden');
                    TEST_PARSED.textContent = JSON.stringify(j.parsed, null, 2);
                } else {
                    TEST_PARSED_WRAP.classList.add('hidden');
                }
                const u = j.usage || {};
                TEST_META.textContent =
                    `${j.model || ''} · ${j.elapsed_ms || 0}ms` +
                    (j.finish_reason ? ` · ${j.finish_reason}` : '') +
                    (u.totalTokenCount ? ` · ${u.totalTokenCount} tokens` : '');
            }
        } catch (e) {
            TEST_RESULT.classList.remove('hidden');
            TEST_ERR.classList.remove('hidden');
            TEST_ERR_TEXT.textContent = 'เครือข่ายผิดพลาด: ' + e.message;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-play"></i> Run test (ยิง Gemini จริง · ไม่บันทึก)';
        }
    });

    document.querySelectorAll('.ap-reset-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const key = btn.closest('[data-key]').dataset.key;
            const { isConfirmed } = await Swal.fire({
                icon: 'question',
                title: 'รีเซ็ตเป็น default?',
                text: 'การแก้ไข prompt ปัจจุบันจะหายไป — กลับไปใช้ prompt มาตรฐาน',
                showCancelButton: true,
                confirmButtonText: 'รีเซ็ต',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
            });
            if (!isConfirmed) return;
            const fd = new FormData();
            fd.append('action', 'reset');
            fd.append('key', key);
            fd.append('csrf_token', CSRF);
            try {
                const r = await fetch('ajax_ai_prompts.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    Swal.fire({ icon: 'success', title: 'รีเซ็ตแล้ว', timer: 1200, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: j.error || j.message || '' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
            }
        });
    });

    document.getElementById('ap-modal-close').addEventListener('click', closeEdit);
    document.getElementById('ap-modal-cancel').addEventListener('click', closeEdit);
    MODAL.addEventListener('click', (e) => { if (e.target === MODAL) closeEdit(); });

    document.getElementById('ap-modal-save').addEventListener('click', async () => {
        if (!currentKey) return;
        const content = TEXTAREA.value.trim();
        if (content === '') {
            Swal.fire({ icon: 'warning', title: 'Content ว่าง', text: 'ใส่ prompt content ก่อนบันทึก' });
            return;
        }
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('key', currentKey);
        fd.append('content', content);
        fd.append('csrf_token', CSRF);
        try {
            const r = await fetch('ajax_ai_prompts.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: j.error || j.message || '' });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
        }
    });
})();
</script>
