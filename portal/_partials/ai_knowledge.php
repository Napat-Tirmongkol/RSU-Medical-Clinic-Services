<?php
/**
 * portal/_partials/ai_knowledge.php
 * AI Knowledge — Custom Notes + Knowledge Chunks (RAG)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/ai_knowledge_helper.php';
require_once __DIR__ . '/../../includes/ai_chunk_helper.php';

$_aik_notes = list_clinic_notes($pdo);
ensure_chunks_schema($pdo);

$_aik_sources = [
    ['icon'=>'fa-id-card',    'color'=>'#0ea5e9','title'=>'ข้อมูลทั่วไปของคลินิก','desc'=>'ชื่อ, เบอร์โทร — AI ใช้แทรกในคำตอบ','href'=>'?section=clinic_data&cd_view=profile','status'=>'มีหน้าจัดการแล้ว'],
    ['icon'=>'fa-clock',      'color'=>'#10b981','title'=>'เวลาเปิด-ปิด (31 วันข้างหน้า)','desc'=>'AI ใช้ตอบ "วันนี้/พรุ่งนี้/วันที่ X เปิดไหม"','href'=>'?section=clinic_data&cd_view=calendar','status'=>'มีหน้าจัดการแล้ว'],
    ['icon'=>'fa-user-doctor','color'=>'#f59e0b','title'=>'ตารางหมอออกตรวจ','desc'=>'AI ใช้ตอบ "หมอใครออกตรวจวัน X"','href'=>'?section=clinic_data&cd_view=schedule','status'=>'มีหน้าจัดการแล้ว'],
    ['icon'=>'fa-flask-vial', 'color'=>'#a855f7','title'=>'FAQ Knowledge Base','desc'=>'คำถาม-คำตอบที่ admin curate — matcher ใช้ตรง','href'=>'?section=ai_qa_lab','status'=>'จัดการที่ AI QA Lab → FAQ tab'],
];
?>
<style>
    /* ── shared ─────────────────────────────────────── */
    #ai-knowledge-section .aik-card {
        background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;
        padding:16px 18px; margin-bottom:12px;
    }
    #ai-knowledge-section .aik-source-card {
        display:flex; align-items:flex-start; gap:12px; padding:14px 16px;
        border:1.5px solid #e2e8f0; border-radius:12px; background:#fff; transition:all .15s;
    }
    #ai-knowledge-section .aik-source-card:hover { border-color:#93c5fd; background:#f8fafc; }
    #ai-knowledge-section .aik-source-icon {
        flex-shrink:0; width:40px; height:40px; border-radius:10px;
        display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff;
    }
    #ai-knowledge-section pre.aik-preview {
        font-family:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace;
        font-size:12px; line-height:1.55; background:#0f172a; color:#e2e8f0;
        border:1.5px solid #1e293b; border-radius:10px; padding:14px 16px;
        white-space:pre-wrap; word-wrap:break-word; max-height:480px; overflow-y:auto; margin:0;
    }
    #ai-knowledge-section textarea.aik-textarea {
        font-family:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace;
        font-size:13px; line-height:1.55; width:100%; min-height:140px;
        padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px;
        resize:vertical; outline:none;
    }
    #ai-knowledge-section textarea.aik-textarea:focus {
        border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15);
    }
    #ai-knowledge-section .aik-toggle { position:relative; display:inline-block; width:38px; height:22px; }
    #ai-knowledge-section .aik-toggle input { display:none; }
    #ai-knowledge-section .aik-toggle-slider {
        position:absolute; inset:0; background:#cbd5e1; border-radius:999px; transition:.2s;
    }
    #ai-knowledge-section .aik-toggle-slider::before {
        content:''; position:absolute; height:16px; width:16px; left:3px; top:3px;
        background:#fff; border-radius:50%; transition:.2s;
    }
    #ai-knowledge-section .aik-toggle input:checked + .aik-toggle-slider { background:#10b981; }
    #ai-knowledge-section .aik-toggle input:checked + .aik-toggle-slider::before { transform:translateX(16px); }

    /* ── tabs ────────────────────────────────────────── */
    #aik-tab-notes, #aik-tab-chunks { display:none; }
    #aik-tab-notes.aik-active, #aik-tab-chunks.aik-active { display:block; }

    /* ── chunks table ────────────────────────────────── */
    #aik-chunks-table th { background:#f8fafc; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; padding:8px 10px; border-bottom:2px solid #e2e8f0; }
    #aik-chunks-table td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; font-size:13px; }
    #aik-chunks-table tr:last-child td { border-bottom:none; }
    #aik-chunks-table tr:hover td { background:#fafafa; }
    .aik-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; }
    .aik-emb-yes  { background:#dcfce7; color:#16a34a; }
    .aik-emb-no   { background:#fef9c3; color:#92400e; }
    .aik-src-badge{ background:#e0e7ff; color:#3730a3; }

    /* ── search test panel ───────────────────────────── */
    #aik-search-panel { border:1.5px solid #c7d2fe; border-radius:14px; background:#eef2ff; padding:16px; margin-bottom:16px; }
    #aik-search-panel .result-row { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-top:8px; }
    #aik-search-panel .result-score { font-size:11px; font-weight:700; background:#ddd6fe; color:#4f46e5; padding:2px 7px; border-radius:999px; }

    /* ── modal z-index ───────────────────────────────── */
    #aik-chunk-modal  { z-index:210; }
    #aik-diag-modal   { z-index:220; }
    #aik-modal        { z-index:200; }
    #aik-chunk-modal .aik-modal-box { max-height:92vh; }
</style>

<div id="ai-knowledge-section" class="p-5 md:p-6 max-w-5xl mx-auto">

    <div class="flex items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-database text-emerald-600"></i>
                AI Knowledge
            </h1>
            <p class="text-sm text-gray-500 mt-1">ข้อมูลที่ AI ใช้อ้างอิงตอนตอบคำถาม</p>
        </div>
    </div>

    <!-- Tab strip -->
    <div class="flex gap-1 border-b border-gray-200 mb-4">
        <button type="button" id="aik-tab-btn-notes"
            class="px-4 py-2.5 text-sm font-bold text-emerald-700 border-b-2 border-emerald-500 -mb-px"
            onclick="aikSwitchTab('notes')">
            <i class="fa-solid fa-note-sticky mr-1.5"></i>Custom Notes
        </button>
        <button type="button" id="aik-tab-btn-chunks"
            class="px-4 py-2.5 text-sm font-bold text-gray-500 border-b-2 border-transparent -mb-px hover:text-gray-700"
            onclick="aikSwitchTab('chunks')">
            <i class="fa-solid fa-cubes mr-1.5"></i>Knowledge Chunks
            <span id="aik-chunk-count-badge" class="ml-1 text-[10px] bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded-full font-bold"></span>
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!--  TAB: CUSTOM NOTES                                                 -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <div id="aik-tab-notes" class="aik-active">

        <!-- buttons -->
        <div class="flex justify-end gap-2 mb-3">
            <button id="aik-diagnose" class="px-3 py-2 bg-amber-50 text-amber-700 text-xs font-bold rounded-lg border border-amber-300 hover:bg-amber-100">
                <i class="fa-solid fa-stethoscope"></i> ตรวจสอบข้อมูลตารางหมอ
            </button>
            <button id="aik-refresh" class="px-3 py-2 bg-white text-gray-700 text-xs font-bold rounded-lg border border-gray-300 hover:bg-gray-50">
                <i class="fa-solid fa-rotate"></i> Refresh preview
            </button>
        </div>

        <!-- Preview -->
        <div class="aik-card">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-base font-black text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-eye text-cyan-600"></i>ตัวอย่าง context ที่ AI จะเห็น
                </h2>
                <span id="aik-preview-meta" class="text-gray-500" style="font-size:11px"></span>
            </div>
            <pre id="aik-preview" class="aik-preview">กำลังโหลด...</pre>
        </div>

        <!-- Quick links -->
        <div class="aik-card">
            <h2 class="text-base font-black text-gray-900 flex items-center gap-2 mb-3">
                <i class="fa-solid fa-link text-blue-600"></i>แหล่งข้อมูล (จัดการต่อในหน้าอื่น)
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <?php foreach ($_aik_sources as $s): ?>
                <a href="<?= htmlspecialchars($s['href']) ?>" class="aik-source-card text-left no-underline">
                    <div class="aik-source-icon" style="background:<?= htmlspecialchars($s['color']) ?>">
                        <i class="fa-solid <?= htmlspecialchars($s['icon']) ?>"></i>
                    </div>
                    <div class="flex-1">
                        <div class="font-black text-sm text-gray-900"><?= htmlspecialchars($s['title']) ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($s['desc']) ?></div>
                        <div class="text-emerald-600 font-bold mt-1.5" style="font-size:10px">
                            <i class="fa-solid fa-arrow-right"></i> <?= htmlspecialchars($s['status']) ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Custom notes list -->
        <div class="aik-card">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="text-base font-black text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-note-sticky text-amber-600"></i>Custom Notes
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">ข้อมูลฟรี-ฟอร์ม — ที่ active จะถูกฉีดเข้า context</p>
                </div>
                <button id="aik-add-btn" class="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-lg hover:bg-emerald-700">
                    <i class="fa-solid fa-plus"></i> เพิ่ม note
                </button>
            </div>

            <div id="aik-notes-list" class="space-y-2">
                <?php if (empty($_aik_notes)): ?>
                <div class="text-center text-gray-400 py-10 border border-dashed border-gray-300 rounded-lg">
                    <i class="fa-solid fa-note-sticky text-3xl mb-2 block"></i>
                    <div class="text-sm font-bold">ยังไม่มี notes</div>
                    <div class="text-xs mt-1">เพิ่ม note แรก เช่น "บริการที่ให้", "ราคาตรวจสุขภาพ"</div>
                </div>
                <?php else: ?>
                    <?php foreach ($_aik_notes as $n): ?>
                    <div class="border border-slate-200 rounded-lg p-3 flex items-start gap-3" data-id="<?= (int)$n['id'] ?>">
                        <label class="aik-toggle mt-1">
                            <input type="checkbox" class="aik-toggle-input" <?= (int)$n['is_active'] ? 'checked' : '' ?>>
                            <span class="aik-toggle-slider"></span>
                        </label>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-black text-sm text-gray-900"><?= htmlspecialchars($n['label']) ?></span>
                                <span class="text-gray-400" style="font-size:10px">#<?= (int)$n['sort_order'] ?></span>
                            </div>
                            <pre class="text-xs text-gray-600 whitespace-pre-wrap break-words font-sans" style="margin:0"><?= htmlspecialchars(mb_substr((string)$n['content'], 0, 240)) ?><?= mb_strlen((string)$n['content']) > 240 ? '...' : '' ?></pre>
                        </div>
                        <div class="flex flex-col gap-1 shrink-0">
                            <button type="button" class="aik-edit-btn px-2.5 py-1 bg-white text-gray-700 text-xs font-bold rounded border border-gray-300 hover:bg-gray-50">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <button type="button" class="aik-del-btn px-2.5 py-1 bg-white text-rose-600 text-xs font-bold rounded border border-rose-200 hover:bg-rose-50">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /tab notes -->

    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!--  TAB: KNOWLEDGE CHUNKS                                             -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <div id="aik-tab-chunks">

        <!-- Semantic search test panel -->
        <div id="aik-search-panel">
            <div class="flex items-center gap-2 mb-2">
                <i class="fa-solid fa-magnifying-glass-chart text-indigo-600"></i>
                <span class="text-sm font-black text-indigo-900">ทดสอบ Semantic Search</span>
                <span class="text-xs text-indigo-500">(ต้องมี embedding ก่อน)</span>
            </div>
            <div class="flex gap-2">
                <input id="aik-search-query" type="text" placeholder="พิมพ์คำถามเพื่อดู chunks ที่ใกล้เคียงที่สุด..."
                    class="flex-1 px-3 py-2 text-sm border border-indigo-300 rounded-lg focus:outline-none focus:border-indigo-500 bg-white">
                <select id="aik-search-topk" class="px-3 py-2 text-sm border border-indigo-300 rounded-lg bg-white">
                    <option value="3">Top 3</option>
                    <option value="5" selected>Top 5</option>
                    <option value="10">Top 10</option>
                </select>
                <button id="aik-search-btn" class="px-4 py-2 bg-indigo-600 text-white text-sm font-bold rounded-lg hover:bg-indigo-700">
                    <i class="fa-solid fa-search"></i> ค้นหา
                </button>
            </div>
            <div id="aik-search-results" class="mt-2 hidden"></div>
        </div>

        <!-- Toolbar -->
        <div class="flex items-center gap-2 mb-3 flex-wrap">
            <input id="aik-chunk-q" type="text" placeholder="ค้นหาหัวข้อ / เนื้อหา / tags..."
                class="flex-1 min-w-0 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:border-emerald-500">
            <select id="aik-chunk-source-filter" class="px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white">
                <option value="">ทุกแหล่ง</option>
                <option value="manual">manual</option>
                <option value="policy">policy</option>
                <option value="service">service</option>
                <option value="faq">faq</option>
                <option value="other">other</option>
            </select>
            <button id="aik-chunk-search-btn" class="px-3 py-2 bg-white text-gray-700 text-sm font-bold rounded-lg border border-gray-300 hover:bg-gray-50">
                <i class="fa-solid fa-search"></i>
            </button>
            <button id="aik-chunk-embed-all-btn" class="px-3 py-2 bg-violet-600 text-white text-sm font-bold rounded-lg hover:bg-violet-700">
                <i class="fa-solid fa-microchip"></i> Embed ทั้งหมด
            </button>
            <button id="aik-chunk-add-btn" class="px-4 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700">
                <i class="fa-solid fa-plus"></i> เพิ่ม Chunk
            </button>
        </div>

        <!-- Stats bar -->
        <div id="aik-chunk-stats" class="text-xs text-gray-500 mb-2"></div>

        <!-- Table -->
        <div class="aik-card" style="padding:0;overflow:hidden">
            <div class="overflow-x-auto">
                <table id="aik-chunks-table" class="w-full">
                    <thead>
                        <tr>
                            <th class="text-left">หัวข้อ</th>
                            <th class="text-left">แหล่ง / Tags</th>
                            <th class="text-center">Token</th>
                            <th class="text-center">Embedding</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="aik-chunks-tbody">
                        <tr><td colspan="6" class="text-center text-gray-400 py-8">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div id="aik-chunk-pagination" class="flex items-center justify-between mt-3 text-sm text-gray-500"></div>
    </div><!-- /tab chunks -->

</div><!-- /ai-knowledge-section -->

<!-- ── Diagnostic modal ────────────────────────────────────────────────── -->
<div id="aik-diag-modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl flex flex-col" style="max-height:92vh">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <div class="text-xs font-bold text-amber-700 uppercase">Diagnostic</div>
                <h3 class="text-lg font-black text-gray-900">ตรวจสอบข้อมูลตารางหมอ</h3>
            </div>
            <button id="aik-diag-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-4 overflow-y-auto flex-1">
            <pre id="aik-diag-output" class="aik-preview" style="background:#1e293b;max-height:none">กำลังโหลด...</pre>
        </div>
    </div>
</div>

<!-- ── Note edit/create modal ──────────────────────────────────────────── -->
<div id="aik-modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col" style="max-height:92vh">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 id="aik-modal-title" class="text-lg font-black text-gray-900"></h3>
            <button id="aik-modal-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-4 overflow-y-auto flex-1 space-y-3">
            <div>
                <label class="text-xs font-bold text-gray-700 block mb-1">หัวข้อ (label)</label>
                <input id="aik-input-label" type="text" maxlength="160"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-emerald-500 focus:outline-none"
                    placeholder="เช่น บริการตรวจสุขภาพประจำปี">
            </div>
            <div>
                <label class="text-xs font-bold text-gray-700 block mb-1">เนื้อหา</label>
                <textarea id="aik-input-content" class="aik-textarea" placeholder="อธิบายรายละเอียดที่อยากให้ AI รู้"></textarea>
            </div>
            <div>
                <label class="text-xs font-bold text-gray-700 block mb-1">ลำดับ</label>
                <input id="aik-input-sort" type="number" min="0" max="999" value="0"
                    class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
            <button id="aik-modal-cancel" class="px-4 py-2 bg-white text-gray-700 text-sm font-bold rounded-lg border border-gray-300 hover:bg-gray-50">ยกเลิก</button>
            <button id="aik-modal-save" class="px-4 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700">
                <i class="fa-solid fa-save"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<!-- ── Chunk edit/create modal ─────────────────────────────────────────── -->
<div id="aik-chunk-modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
    <div class="aik-modal-box bg-white rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between shrink-0">
            <h3 id="aik-chunk-modal-title" class="text-lg font-black text-gray-900">เพิ่ม Chunk</h3>
            <button id="aik-chunk-modal-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4" style="min-height:0">
            <div>
                <label class="text-xs font-bold text-gray-700 block mb-1">หัวข้อ <span class="text-rose-500">*</span></label>
                <input id="aik-chunk-title" type="text" maxlength="200"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-indigo-500 focus:outline-none"
                    placeholder="เช่น ขั้นตอนการนัดหมายตรวจสุขภาพ">
            </div>
            <div>
                <label class="text-xs font-bold text-gray-700 block mb-1">เนื้อหา <span class="text-rose-500">*</span></label>
                <textarea id="aik-chunk-content" class="aik-textarea" style="min-height:160px"
                    placeholder="เนื้อหาที่ครบถ้วน — ควรอยู่ในช่วง 300-800 คำ ต่อ chunk&#10;ยิ่งเนื้อหาเฉพาะเจาะจงต่อหัวข้อเดียว ยิ่งค้นหาได้แม่นยำ"></textarea>
                <div id="aik-chunk-token-est" class="text-xs text-gray-400 mt-1 text-right"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-bold text-gray-700 block mb-1">แหล่งที่มา</label>
                    <select id="aik-chunk-source" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:border-indigo-500">
                        <option value="manual">manual (กรอกเอง)</option>
                        <option value="policy">policy (นโยบาย)</option>
                        <option value="service">service (บริการ)</option>
                        <option value="faq">faq (คำถามที่พบบ่อย)</option>
                        <option value="other">other</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-700 block mb-1">Tags <span class="text-gray-400 font-normal">(คั่นด้วย ,)</span></label>
                    <input id="aik-chunk-tags" type="text" maxlength="500"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-indigo-500 focus:outline-none"
                        placeholder="นัดหมาย, ตรวจสุขภาพ, นักศึกษา">
                </div>
            </div>
            <div>
                <label class="text-xs font-bold text-gray-700 block mb-1">ลำดับ (sort order)</label>
                <input id="aik-chunk-sort" type="number" min="0" max="999" value="0"
                    class="w-28 px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div id="aik-chunk-emb-status" class="hidden text-xs rounded-lg px-3 py-2 font-medium"></div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex items-center justify-between gap-2 shrink-0">
            <button id="aik-chunk-embed-one-btn" class="hidden px-3 py-2 bg-violet-600 text-white text-xs font-bold rounded-lg hover:bg-violet-700">
                <i class="fa-solid fa-microchip"></i> สร้าง Embedding
            </button>
            <div class="flex gap-2 ml-auto">
                <button id="aik-chunk-modal-cancel" class="px-4 py-2 bg-white text-gray-700 text-sm font-bold rounded-lg border border-gray-300 hover:bg-gray-50">ยกเลิก</button>
                <button id="aik-chunk-modal-save" class="px-4 py-2 bg-indigo-600 text-white text-sm font-bold rounded-lg hover:bg-indigo-700">
                    <i class="fa-solid fa-save"></i> บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
'use strict';
const CSRF = '<?= get_csrf_token() ?>';

// ── Tab switching ─────────────────────────────────────────────────────────
window.aikSwitchTab = function(tab) {
    ['notes','chunks'].forEach(t => {
        document.getElementById('aik-tab-' + t).classList.toggle('aik-active', t === tab);
        const btn = document.getElementById('aik-tab-btn-' + t);
        if (t === tab) {
            btn.classList.add('text-emerald-700','border-emerald-500');
            btn.classList.remove('text-gray-500','border-transparent');
        } else {
            btn.classList.remove('text-emerald-700','border-emerald-500');
            btn.classList.add('text-gray-500','border-transparent');
        }
    });
    if (tab === 'chunks' && _chunkPage === null) chunksLoad(1);
};

// ══════════════════════════════════════════════════════════════════════════
//  NOTES TAB
// ══════════════════════════════════════════════════════════════════════════
const PREVIEW      = document.getElementById('aik-preview');
const PREVIEW_META = document.getElementById('aik-preview-meta');

async function loadPreview() {
    PREVIEW.textContent = 'กำลังโหลด...';
    try {
        const r = await fetch('ajax_ai_knowledge.php?action=preview');
        const j = await r.json();
        if (j.ok) {
            PREVIEW.textContent = j.context || '(empty)';
            PREVIEW_META.textContent = `${j.length || 0} chars`;
        } else {
            PREVIEW.textContent = 'Error: ' + (j.error || '');
        }
    } catch (e) { PREVIEW.textContent = 'Error: ' + e.message; }
}
document.getElementById('aik-refresh').addEventListener('click', loadPreview);
loadPreview();

// diagnostic
const DIAG_MODAL = document.getElementById('aik-diag-modal');
const DIAG_OUT   = document.getElementById('aik-diag-output');
document.getElementById('aik-diagnose').addEventListener('click', async () => {
    DIAG_OUT.textContent = 'กำลังโหลด...';
    DIAG_MODAL.classList.remove('hidden'); DIAG_MODAL.classList.add('flex');
    try {
        const r = await fetch('ajax_ai_knowledge.php?action=diagnose');
        const j = await r.json();
        DIAG_OUT.textContent = JSON.stringify(j, null, 2);
    } catch (e) { DIAG_OUT.textContent = 'Error: ' + e.message; }
});
document.getElementById('aik-diag-close').addEventListener('click', () => {
    DIAG_MODAL.classList.add('hidden'); DIAG_MODAL.classList.remove('flex');
});
DIAG_MODAL.addEventListener('click', e => { if (e.target === DIAG_MODAL) { DIAG_MODAL.classList.add('hidden'); DIAG_MODAL.classList.remove('flex'); } });

// Notes CRUD modal
const MODAL   = document.getElementById('aik-modal');
const I_LABEL = document.getElementById('aik-input-label');
const I_CONT  = document.getElementById('aik-input-content');
const I_SORT  = document.getElementById('aik-input-sort');
let noteEditId = null;

function openNoteModal(note) {
    noteEditId = note ? note.id : null;
    document.getElementById('aik-modal-title').textContent = note ? 'แก้ไข note' : 'เพิ่ม note ใหม่';
    I_LABEL.value = note ? note.label   : '';
    I_CONT.value  = note ? note.content : '';
    I_SORT.value  = note ? note.sort_order : 0;
    MODAL.classList.remove('hidden'); MODAL.classList.add('flex');
}
function closeNoteModal() { MODAL.classList.add('hidden'); MODAL.classList.remove('flex'); noteEditId = null; }
document.getElementById('aik-add-btn').addEventListener('click', () => openNoteModal(null));
document.getElementById('aik-modal-close').addEventListener('click', closeNoteModal);
document.getElementById('aik-modal-cancel').addEventListener('click', closeNoteModal);
MODAL.addEventListener('click', e => { if (e.target === MODAL) closeNoteModal(); });

document.getElementById('aik-modal-save').addEventListener('click', async () => {
    const label = I_LABEL.value.trim(), content = I_CONT.value.trim();
    const sortOrder = parseInt(I_SORT.value || '0', 10);
    if (!label || !content) { Swal.fire({ icon:'warning', title:'กรอกหัวข้อ + เนื้อหา' }); return; }
    const fd = new FormData();
    fd.append('action', noteEditId ? 'update' : 'create');
    if (noteEditId) fd.append('id', noteEditId);
    fd.append('label', label); fd.append('content', content);
    fd.append('sort_order', String(sortOrder)); fd.append('csrf_token', CSRF);
    try {
        const r = await fetch('ajax_ai_knowledge.php', { method:'POST', body:fd });
        const j = await r.json();
        if (j.ok) { Swal.fire({ icon:'success', title:j.message||'บันทึกแล้ว', timer:1200, showConfirmButton:false }).then(()=>location.reload()); }
        else      { Swal.fire({ icon:'error', title:'ไม่สำเร็จ', text:j.error||'' }); }
    } catch (e) { Swal.fire({ icon:'error', title:'เครือข่ายผิดพลาด', text:e.message }); }
});

document.querySelectorAll('#aik-notes-list [data-id]').forEach(row => {
    const id = parseInt(row.dataset.id, 10);
    row.querySelector('.aik-edit-btn')?.addEventListener('click', async () => {
        const r = await fetch('ajax_ai_knowledge.php?action=list');
        const j = await r.json();
        const found = (j.notes||[]).find(n => parseInt(n.id,10) === id);
        if (found) openNoteModal(found);
    });
    row.querySelector('.aik-toggle-input')?.addEventListener('change', async ev => {
        const fd = new FormData();
        fd.append('action','toggle'); fd.append('id',id);
        fd.append('is_active', ev.target.checked ? '1':'0'); fd.append('csrf_token',CSRF);
        try {
            const r = await fetch('ajax_ai_knowledge.php', {method:'POST',body:fd});
            const j = await r.json();
            if (!j.ok) { ev.target.checked = !ev.target.checked; Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:j.error||''}); }
            else        { loadPreview(); }
        } catch (e) { ev.target.checked = !ev.target.checked; Swal.fire({icon:'error',title:'เครือข่ายผิดพลาด',text:e.message}); }
    });
    row.querySelector('.aik-del-btn')?.addEventListener('click', async () => {
        const { isConfirmed } = await Swal.fire({ icon:'warning', title:'ลบ note นี้?', text:'จะลบถาวร', showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#dc2626' });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('action','delete'); fd.append('id',id); fd.append('csrf_token',CSRF);
        try {
            const r = await fetch('ajax_ai_knowledge.php', {method:'POST',body:fd});
            const j = await r.json();
            if (j.ok) { Swal.fire({icon:'success',title:'ลบแล้ว',timer:1000,showConfirmButton:false}).then(()=>location.reload()); }
            else       { Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:j.error||''}); }
        } catch (e) { Swal.fire({icon:'error',title:'เครือข่ายผิดพลาด',text:e.message}); }
    });
});

// ══════════════════════════════════════════════════════════════════════════
//  CHUNKS TAB
// ══════════════════════════════════════════════════════════════════════════
let _chunkPage   = null;   // null = ยังไม่โหลด
let _chunkTotal  = 0;
let _chunkPages  = 1;
const CHUNK_LIMIT = 20;

async function chunksLoad(page) {
    _chunkPage = page;
    const q      = document.getElementById('aik-chunk-q').value.trim();
    const source = document.getElementById('aik-chunk-source-filter').value;
    const tbody  = document.getElementById('aik-chunks-tbody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-gray-400 py-8"><i class="fa-solid fa-spinner fa-spin mr-2"></i>โหลด...</td></tr>';

    try {
        const params = new URLSearchParams({ action:'list', page, limit:CHUNK_LIMIT, q, source });
        const r = await fetch('ajax_ai_chunks.php?' + params.toString());
        const j = await r.json();
        if (!j.ok) { tbody.innerHTML = `<tr><td colspan="6" class="text-center text-rose-500 py-6">${j.error||'error'}</td></tr>`; return; }

        _chunkTotal = j.total;
        _chunkPages = j.pages;
        renderChunksTable(j.chunks);
        renderChunkPagination();
        updateChunkStats();

        const badge = document.getElementById('aik-chunk-count-badge');
        if (badge) badge.textContent = j.total;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-rose-500 py-6">${e.message}</td></tr>`;
    }
}

function renderChunksTable(chunks) {
    const tbody = document.getElementById('aik-chunks-tbody');
    if (!chunks.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-gray-400 py-12"><i class="fa-solid fa-cubes text-3xl block mb-2 opacity-30"></i>ยังไม่มี chunk — กดเพิ่ม Chunk เพื่อเริ่ม</td></tr>';
        return;
    }
    tbody.innerHTML = chunks.map(c => {
        const hasEmb  = c.has_embedding == 1 || c.has_embedding === true;
        const embBadge = hasEmb
            ? '<span class="aik-badge aik-emb-yes"><i class="fa-solid fa-check"></i> embedded</span>'
            : '<span class="aik-badge aik-emb-no"><i class="fa-solid fa-clock"></i> pending</span>';
        const tags = c.tags ? c.tags.split(',').filter(Boolean).map(t => `<span class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded mr-1">${escHtml(t.trim())}</span>`).join('') : '';
        return `<tr>
            <td>
                <div class="font-bold text-gray-900 text-sm">${escHtml(c.title)}</div>
                <div class="text-xs text-gray-400 mt-0.5 line-clamp-1">${escHtml((c.content_preview||'').substring(0,80))}...</div>
            </td>
            <td>
                <span class="aik-badge aik-src-badge mb-1">${escHtml(c.source_label)}</span>
                <div class="mt-1">${tags}</div>
            </td>
            <td class="text-center text-xs text-gray-600">${c.token_count||'-'}</td>
            <td class="text-center">${embBadge}</td>
            <td class="text-center">
                <label class="aik-toggle">
                    <input type="checkbox" class="chunk-toggle-input" data-id="${c.id}" ${c.is_active==1?'checked':''}>
                    <span class="aik-toggle-slider"></span>
                </label>
            </td>
            <td class="text-center">
                <div class="flex items-center justify-center gap-1">
                    <button type="button" class="chunk-edit-btn px-2 py-1 bg-white text-indigo-600 text-xs font-bold rounded border border-indigo-200 hover:bg-indigo-50" data-id="${c.id}" title="แก้ไข">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <button type="button" class="chunk-embed-btn px-2 py-1 bg-white text-violet-600 text-xs font-bold rounded border border-violet-200 hover:bg-violet-50" data-id="${c.id}" title="สร้าง Embedding">
                        <i class="fa-solid fa-microchip"></i>
                    </button>
                    <button type="button" class="chunk-del-btn px-2 py-1 bg-white text-rose-500 text-xs font-bold rounded border border-rose-200 hover:bg-rose-50" data-id="${c.id}" title="ลบ">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    // bind events on new rows
    tbody.querySelectorAll('.chunk-toggle-input').forEach(cb => {
        cb.addEventListener('change', async ev => {
            const id = ev.target.dataset.id;
            const fd = new FormData();
            fd.append('action','toggle'); fd.append('id',id);
            fd.append('is_active', ev.target.checked?'1':'0'); fd.append('csrf_token',CSRF);
            try {
                const r = await fetch('ajax_ai_chunks.php',{method:'POST',body:fd});
                const j = await r.json();
                if (!j.ok) { ev.target.checked=!ev.target.checked; Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:j.error||''}); }
            } catch(e) { ev.target.checked=!ev.target.checked; }
        });
    });

    tbody.querySelectorAll('.chunk-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => openChunkModal(parseInt(btn.dataset.id,10)));
    });

    tbody.querySelectorAll('.chunk-embed-btn').forEach(btn => {
        btn.addEventListener('click', () => embedChunk(parseInt(btn.dataset.id,10), btn));
    });

    tbody.querySelectorAll('.chunk-del-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = parseInt(btn.dataset.id,10);
            const { isConfirmed } = await Swal.fire({ icon:'warning', title:'ลบ chunk นี้?', text:'จะลบถาวร', showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#dc2626' });
            if (!isConfirmed) return;
            const fd = new FormData();
            fd.append('action','delete'); fd.append('id',id); fd.append('csrf_token',CSRF);
            const r = await fetch('ajax_ai_chunks.php',{method:'POST',body:fd});
            const j = await r.json();
            if (j.ok) chunksLoad(_chunkPage);
            else Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:j.error||''});
        });
    });
}

function renderChunkPagination() {
    const el = document.getElementById('aik-chunk-pagination');
    if (_chunkPages <= 1) { el.innerHTML = ''; return; }
    let btns = '';
    const p = _chunkPage;
    if (p > 1) btns += `<button type="button" onclick="chunksLoad(1)" class="px-2 py-1 rounded border text-xs hover:bg-gray-50">«</button><button type="button" onclick="chunksLoad(${p-1})" class="px-2 py-1 rounded border text-xs hover:bg-gray-50">‹</button>`;
    for (let i = Math.max(1,p-2); i <= Math.min(_chunkPages,p+2); i++) {
        btns += `<button type="button" onclick="chunksLoad(${i})" class="px-2.5 py-1 rounded border text-xs ${i===p?'bg-indigo-600 text-white border-indigo-600':'hover:bg-gray-50'}">${i}</button>`;
    }
    if (p < _chunkPages) btns += `<button type="button" onclick="chunksLoad(${p+1})" class="px-2 py-1 rounded border text-xs hover:bg-gray-50">›</button><button type="button" onclick="chunksLoad(${_chunkPages})" class="px-2 py-1 rounded border text-xs hover:bg-gray-50">»</button>`;
    el.innerHTML = `<span class="text-xs text-gray-400">หน้า ${p} / ${_chunkPages} · รวม ${_chunkTotal} รายการ</span><div class="flex gap-1">${btns}</div>`;
}

function updateChunkStats() {
    document.getElementById('aik-chunk-stats').textContent =
        `รวม ${_chunkTotal} chunks · หน้า ${_chunkPage} / ${_chunkPages}`;
}

// ── Embed ─────────────────────────────────────────────────────────────────
async function embedChunk(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }
    const fd = new FormData();
    fd.append('action','embed'); fd.append('id',id); fd.append('csrf_token',CSRF);
    try {
        const r = await fetch('ajax_ai_chunks.php',{method:'POST',body:fd});
        const j = await r.json();
        if (j.ok) {
            Swal.fire({icon:'success',title:'Embedding สำเร็จ',timer:1200,showConfirmButton:false});
            chunksLoad(_chunkPage||1);
        } else {
            Swal.fire({icon:'error',title:'Embedding ไม่สำเร็จ',text:j.error||''});
            if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-microchip"></i>'; }
        }
    } catch(e) {
        Swal.fire({icon:'error',title:'เครือข่ายผิดพลาด',text:e.message});
        if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-microchip"></i>'; }
    }
}

document.getElementById('aik-chunk-embed-all-btn').addEventListener('click', async () => {
    const { isConfirmed } = await Swal.fire({
        icon:'question', title:'Embed ทั้งหมด?',
        text:'จะสร้าง embedding สำหรับ chunks ที่ยังไม่มี (สูงสุด 20 ชิ้น ต่อครั้ง)',
        showCancelButton:true, confirmButtonText:'ดำเนินการ', cancelButtonText:'ยกเลิก',
    });
    if (!isConfirmed) return;
    const btn = document.getElementById('aik-chunk-embed-all-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลัง embed...';
    const fd = new FormData();
    fd.append('action','embed_all'); fd.append('csrf_token',CSRF);
    try {
        const r = await fetch('ajax_ai_chunks.php',{method:'POST',body:fd});
        const j = await r.json();
        if (j.ok) { Swal.fire({icon:'success',title:`Embed สำเร็จ ${j.embedded} chunks`,timer:2000,showConfirmButton:false}); chunksLoad(_chunkPage||1); }
        else       { Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:j.error||''}); }
    } catch(e) { Swal.fire({icon:'error',title:'เครือข่ายผิดพลาด',text:e.message}); }
    finally { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-microchip"></i> Embed ทั้งหมด'; }
});

// ── Chunk Modal ───────────────────────────────────────────────────────────
const CMODAL = document.getElementById('aik-chunk-modal');
let chunkEditId = null;

function openChunkModal(id) {
    chunkEditId = id || null;
    document.getElementById('aik-chunk-modal-title').textContent = id ? 'แก้ไข Chunk' : 'เพิ่ม Chunk';
    document.getElementById('aik-chunk-title').value   = '';
    document.getElementById('aik-chunk-content').value = '';
    document.getElementById('aik-chunk-tags').value    = '';
    document.getElementById('aik-chunk-source').value  = 'manual';
    document.getElementById('aik-chunk-sort').value    = 0;
    document.getElementById('aik-chunk-token-est').textContent = '';
    document.getElementById('aik-chunk-embed-one-btn').classList.add('hidden');
    document.getElementById('aik-chunk-emb-status').classList.add('hidden');

    if (id) {
        fetch('ajax_ai_chunks.php?action=get&id=' + id)
            .then(r => r.json()).then(j => {
                if (!j.ok) return;
                const c = j.chunk;
                document.getElementById('aik-chunk-title').value   = c.title||'';
                document.getElementById('aik-chunk-content').value = c.content||'';
                document.getElementById('aik-chunk-tags').value    = c.tags||'';
                document.getElementById('aik-chunk-source').value  = c.source_label||'manual';
                document.getElementById('aik-chunk-sort').value    = c.sort_order||0;
                updateTokenEst();

                // show embed button + status
                const embBtn = document.getElementById('aik-chunk-embed-one-btn');
                embBtn.classList.remove('hidden');
                embBtn.onclick = async () => {
                    embBtn.disabled = true; embBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    await embedChunk(id, null);
                    embBtn.disabled = false; embBtn.innerHTML = '<i class="fa-solid fa-microchip"></i> สร้าง Embedding';
                };

                const embStat = document.getElementById('aik-chunk-emb-status');
                embStat.classList.remove('hidden');
                if (c.embedding_model) {
                    embStat.className = 'text-xs rounded-lg px-3 py-2 font-medium bg-emerald-50 text-emerald-700 border border-emerald-200';
                    embStat.innerHTML = `<i class="fa-solid fa-check-circle mr-1"></i>มี embedding แล้ว (${c.embedding_model})`;
                } else {
                    embStat.className = 'text-xs rounded-lg px-3 py-2 font-medium bg-amber-50 text-amber-700 border border-amber-200';
                    embStat.innerHTML = `<i class="fa-solid fa-clock mr-1"></i>ยังไม่มี embedding — กด "สร้าง Embedding" หลังบันทึก`;
                }
            });
    }

    CMODAL.classList.remove('hidden'); CMODAL.classList.add('flex');
}

function closeChunkModal() { CMODAL.classList.add('hidden'); CMODAL.classList.remove('flex'); chunkEditId=null; }
document.getElementById('aik-chunk-modal-close').addEventListener('click', closeChunkModal);
document.getElementById('aik-chunk-modal-cancel').addEventListener('click', closeChunkModal);
CMODAL.addEventListener('click', e => { if(e.target===CMODAL) closeChunkModal(); });

document.getElementById('aik-chunk-add-btn').addEventListener('click', () => openChunkModal(null));

// token estimate
function updateTokenEst() {
    const len = document.getElementById('aik-chunk-content').value.length;
    const tok = Math.ceil(len/3.5);
    const el  = document.getElementById('aik-chunk-token-est');
    const color = tok < 100 ? 'text-amber-500' : tok > 1200 ? 'text-rose-500' : 'text-emerald-600';
    el.className = `text-xs ${color} mt-1 text-right`;
    el.textContent = `~${tok} tokens (แนะนำ 80–300 tokens ต่อ chunk)`;
}
document.getElementById('aik-chunk-content').addEventListener('input', updateTokenEst);

document.getElementById('aik-chunk-modal-save').addEventListener('click', async () => {
    const title   = document.getElementById('aik-chunk-title').value.trim();
    const content = document.getElementById('aik-chunk-content').value.trim();
    const tags    = document.getElementById('aik-chunk-tags').value.trim();
    const source  = document.getElementById('aik-chunk-source').value;
    const sort    = parseInt(document.getElementById('aik-chunk-sort').value||'0',10);

    if (!title || !content) { Swal.fire({icon:'warning',title:'กรอกหัวข้อ + เนื้อหา'}); return; }

    const fd = new FormData();
    fd.append('action', chunkEditId ? 'update':'create');
    if (chunkEditId) fd.append('id', chunkEditId);
    fd.append('title',        title);
    fd.append('content',      content);
    fd.append('tags',         tags);
    fd.append('source_label', source);
    fd.append('sort_order',   String(sort));
    fd.append('csrf_token',   CSRF);

    const saveBtn = document.getElementById('aik-chunk-modal-save');
    saveBtn.disabled = true;
    try {
        const r = await fetch('ajax_ai_chunks.php',{method:'POST',body:fd});
        const j = await r.json();
        if (j.ok) {
            const savedId = j.id || chunkEditId;
            const { isConfirmed: doEmbed } = await Swal.fire({
                icon: 'success',
                title: j.message||'บันทึกแล้ว',
                text: 'ต้องการสร้าง Embedding ให้ chunk นี้เลยไหม?',
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-microchip"></i> สร้าง Embedding',
                cancelButtonText:  'ข้ามไปก่อน',
                confirmButtonColor: '#7c3aed',
            });
            closeChunkModal();
            if (doEmbed && savedId) {
                Swal.fire({ title:'กำลัง embed...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
                await embedChunk(savedId, null);
                Swal.close();
            }
            chunksLoad(_chunkPage||1);
        } else {
            Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:j.error||''});
        }
    } catch(e) { Swal.fire({icon:'error',title:'เครือข่ายผิดพลาด',text:e.message}); }
    finally { saveBtn.disabled=false; }
});

// ── Search bar ────────────────────────────────────────────────────────────
document.getElementById('aik-chunk-search-btn').addEventListener('click', () => chunksLoad(1));
document.getElementById('aik-chunk-q').addEventListener('keydown', e => { if(e.key==='Enter') chunksLoad(1); });
document.getElementById('aik-chunk-source-filter').addEventListener('change', () => chunksLoad(1));

// ── Semantic Search test ──────────────────────────────────────────────────
document.getElementById('aik-search-btn').addEventListener('click', async () => {
    const query = document.getElementById('aik-search-query').value.trim();
    const topK  = parseInt(document.getElementById('aik-search-topk').value,10);
    if (!query) { Swal.fire({icon:'warning',title:'กรอก query ก่อน'}); return; }

    const btn = document.getElementById('aik-search-btn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> ค้นหา...';
    const resDiv = document.getElementById('aik-search-results');
    resDiv.classList.remove('hidden'); resDiv.innerHTML='<p class="text-xs text-indigo-400">กำลังค้นหา...</p>';

    const fd = new FormData();
    fd.append('action','search'); fd.append('query',query); fd.append('top_k',String(topK)); fd.append('csrf_token',CSRF);
    try {
        const r = await fetch('ajax_ai_chunks.php',{method:'POST',body:fd});
        const j = await r.json();
        if (!j.ok) { resDiv.innerHTML=`<p class="text-xs text-rose-500">${j.error||'error'}</p>`; return; }
        if (!j.results.length) { resDiv.innerHTML='<p class="text-xs text-indigo-400">ไม่พบ chunk ที่ตรงกัน (อาจยังไม่มี embedding)</p>'; return; }
        resDiv.innerHTML = j.results.map((r,i) =>
            `<div class="result-row">
                <div class="flex items-start justify-between gap-2">
                    <span class="font-bold text-sm text-gray-900">${i+1}. ${escHtml(r.title)}</span>
                    <span class="result-score shrink-0">${(r.score*100).toFixed(1)}%</span>
                </div>
                <div class="text-xs text-gray-500 mt-1">${escHtml(r.content_preview.substring(0,200))}...</div>
                <div class="text-xs text-indigo-400 mt-1"><i class="fa-solid fa-tag mr-1"></i>${escHtml(r.source_label)}${r.tags?' · '+escHtml(r.tags):''}</div>
            </div>`
        ).join('');
    } catch(e) { resDiv.innerHTML=`<p class="text-xs text-rose-500">${e.message}</p>`; }
    finally { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-search"></i> ค้นหา'; }
});

// ── util ──────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

})();
</script>
