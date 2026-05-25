<?php
// portal/_partials/line_chat.php — LINE Admin Chat UI
// Gate: superadmin | admin role | access_ai flag
require_once __DIR__ . '/../../includes/line_chat_helper.php';
$csrfToken = function_exists('get_csrf_token') ? get_csrf_token() : '';
$lineTokenSet = line_chat_load_access_token() !== '';
?>

<div class="lc-shell flex flex-col h-full bg-slate-50/50">

    <?php if (!$lineTokenSet): ?>
    <div class="m-4 p-4 bg-amber-50 border border-amber-200 rounded-2xl flex items-start gap-3">
        <i class="fa-solid fa-key text-amber-600 text-lg mt-0.5"></i>
        <div>
            <div class="text-sm font-black text-amber-900">ยังไม่ได้ตั้งค่า LINE Channel Access Token</div>
            <div class="text-xs text-amber-700 mt-0.5">ตั้งค่าใน config ก่อนใช้งาน · ไม่ตั้งจะ log ได้ แต่ส่ง push ไม่ได้</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="px-5 md:px-7 py-4 border-b border-slate-100 bg-white/80 backdrop-blur-md flex items-center justify-between shrink-0">
        <div class="flex-1 min-w-0">
            <div class="sec-title" style="margin-bottom:2px">
                <div class="w-8 h-8 rounded-lg bg-emerald-500 flex items-center justify-center text-white shadow-lg shadow-emerald-200 mr-1" style="font-size:12px">
                    <i class="fa-brands fa-line"></i>
                </div>
                LINE Chat (ตอบกลับผู้ใช้ LINE)
            </div>
            <p class="lc-subtitle text-slate-500 font-bold uppercase tracking-wider ml-11">รายการบทสนทนา · admin ตอบกลับ · บันทึก audit</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button onclick="lcOpenTemplatesModal()" class="btn-solid bg-slate-100 text-slate-600 text-xs" title="จัดการ Quick Reply Templates">
                <i class="fa-solid fa-bookmark"></i>
                <span class="hidden md:inline ml-1">Templates</span>
            </button>
            <button onclick="lcReload()" class="btn-solid bg-slate-100 text-slate-600 text-xs" title="รีโหลด">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>
    </div>

    <!-- Body -->
    <div class="lc-body flex flex-1 overflow-hidden">

        <!-- Conversation list sidebar -->
        <aside class="lc-side bg-white border-r border-slate-100 flex flex-col">
            <!-- Sidebar header: search + filter chips -->
            <div class="lc-side-header shrink-0">
                <div class="lc-search-wrap">
                    <i class="fa-solid fa-magnifying-glass lc-search-icon"></i>
                    <input type="search" id="lcSearchInput" class="lc-search-input" placeholder="ค้นหา ชื่อ · uid · ข้อความ · tag">
                    <button id="lcSearchClear" class="lc-search-clear hidden" onclick="lcClearSearch()" title="ล้าง">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="lc-filter-row">
                    <button class="lc-chip is-active" data-filter="all" onclick="lcSetFilter('all', this)">ทั้งหมด</button>
                    <button class="lc-chip" data-filter="needs_reply" onclick="lcSetFilter('needs_reply', this)">ต้องตอบ</button>
                    <button class="lc-chip" data-filter="today" onclick="lcSetFilter('today', this)">วันนี้</button>
                    <button class="lc-chip" data-filter="resolved" onclick="lcSetFilter('resolved', this)">ปิดแล้ว</button>
                </div>
            </div>
            <div id="lcConvoList" class="flex-1 overflow-y-auto px-1.5 py-1.5">
                <div class="text-center text-slate-400 text-xs py-8">กำลังโหลด...</div>
            </div>
            <div id="lcPager" class="lc-pager shrink-0">
                <span id="lcPagerSummary">—</span>
                <div class="flex gap-0.5">
                    <button class="lc-pager-btn" onclick="lcPage(1)" title="หน้าแรก">«</button>
                    <button class="lc-pager-btn" onclick="lcPage(lcState.page-1)" title="ก่อนหน้า">‹</button>
                    <button class="lc-pager-btn" onclick="lcPage(lcState.page+1)" title="ถัดไป">›</button>
                    <button class="lc-pager-btn" onclick="lcPage(lcState.pages)" title="สุดท้าย">»</button>
                </div>
            </div>
        </aside>

        <!-- Conversation view -->
        <section id="lcMain" class="lc-main flex-1 flex flex-col overflow-hidden lc-no-convo">

            <!-- Convo header (hidden until conversation opened) -->
            <div id="lcConvoHeader" class="lc-convo-header shrink-0 hidden">
                <div class="lc-header-row">
                    <div id="lcHeaderPic" class="lc-header-pic">
                        <i class="fa-brands fa-line text-emerald-500"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div id="lcConvoTitle" class="lc-convo-title truncate">—</div>
                        <div id="lcConvoMeta" class="lc-convo-meta truncate">—</div>
                        <div id="lcConvoBadges" class="mt-1 flex items-center gap-1 flex-wrap"></div>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button id="lcResolveBtn" onclick="lcToggleResolved()" class="lc-action-btn" title="ปิดเคส / เปิดอีกครั้ง">
                        <i class="fa-solid fa-circle-check"></i>
                        <span id="lcResolveLabel" class="ml-1 hidden sm:inline">ปิดเคส</span>
                    </button>
                    <button id="lcSidePanelBtn" onclick="lcToggleSidePanel()" class="lc-action-btn" title="แสดง/ซ่อนแถบบันทึก & แท็ก">
                        <i class="fa-solid fa-note-sticky"></i>
                    </button>
                </div>
            </div>

            <!-- Main content row: messages + right side panel -->
            <div class="lc-main-row flex flex-1 overflow-hidden">
                <!-- Messages -->
                <div id="lcMessages" class="flex-1 overflow-y-auto p-5 space-y-3 scroll-smooth">
                    <div class="lc-empty">
                        <div class="lc-empty-icon">
                            <i class="fa-brands fa-line"></i>
                        </div>
                        <div class="lc-empty-title">เลือกบทสนทนาเพื่อเริ่ม</div>
                        <div class="lc-empty-sub">เลือกผู้ใช้จากรายการด้านซ้าย — มีตัวกรองและช่องค้นหาช่วยให้หาเร็วขึ้น</div>
                        <div class="lc-empty-tips">
                            <div class="lc-empty-tip"><i class="fa-solid fa-magnifying-glass"></i> ค้นหาด้วยชื่อ · uid · ข้อความ · แท็ก</div>
                            <div class="lc-empty-tip"><i class="fa-solid fa-wand-magic-sparkles" style="color:#a855f7"></i> ใช้ <strong>AI ช่วยร่าง</strong> เมื่อเปิดบทสนทนาแล้ว</div>
                            <div class="lc-empty-tip"><i class="fa-solid fa-bookmark text-emerald-500"></i> ตั้ง <strong>Template</strong> คำตอบสำเร็จรูปไว้ใช้ซ้ำ</div>
                        </div>
                    </div>
                </div>

                <!-- Side panel: tags + notes + activity -->
                <aside id="lcSidePanel" class="lc-side-panel hidden">
                    <div class="lc-side-section">
                        <div class="lc-side-title">
                            <i class="fa-solid fa-tags text-emerald-500 mr-1.5"></i>
                            แท็ก
                        </div>
                        <div id="lcTagsView" class="lc-tags-view"></div>
                        <div class="lc-tag-input-row">
                            <input type="text" id="lcTagInput" placeholder="+ เพิ่มแท็ก (Enter)" maxlength="30" class="lc-side-input">
                        </div>
                        <div class="lc-tag-presets">
                            <span class="lc-side-hint">แท็กที่ใช้บ่อย — กดเพิ่ม:</span>
                            <button class="lc-tag-preset" onclick="lcAddTag('นัดหมาย')">นัดหมาย</button>
                            <button class="lc-tag-preset" onclick="lcAddTag('ประกัน')">ประกัน</button>
                            <button class="lc-tag-preset" onclick="lcAddTag('สอบถามทั่วไป')">สอบถามทั่วไป</button>
                            <button class="lc-tag-preset" onclick="lcAddTag('ร้องเรียน')">ร้องเรียน</button>
                            <button class="lc-tag-preset" onclick="lcAddTag('VIP')">VIP</button>
                            <button class="lc-tag-preset" onclick="lcAddTag('ติดตามผล')">ติดตามผล</button>
                        </div>
                    </div>
                    <div class="lc-side-section">
                        <div class="lc-side-title">
                            <i class="fa-solid fa-note-sticky text-amber-500 mr-1.5"></i>
                            บันทึกภายใน
                            <span class="lc-side-hint" style="font-weight:600">— ไม่ส่งให้ user</span>
                        </div>
                        <textarea id="lcNoteInput" rows="5" placeholder="บันทึกเพื่อแอดมินคนอื่น เช่น เคยขอผ่อน 3 งวด, แพ้ยา X, เบอร์ติดต่อสำรอง..." class="lc-side-textarea" maxlength="5000"></textarea>
                        <div class="lc-side-meta">
                            <span id="lcNoteMeta" class="text-slate-400"></span>
                            <button id="lcNoteSaveBtn" class="lc-side-save-btn" onclick="lcSaveNote()" disabled>บันทึก</button>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- Reply input -->
            <div class="lc-input-bar p-3 bg-white border-t border-slate-100 shrink-0">
                <div class="lc-input-toolbar">
                    <button id="lcTemplateBtn" onclick="lcShowTemplateMenu()" disabled class="lc-mini-btn" title="แทรก Quick Reply Template">
                        <i class="fa-solid fa-bookmark"></i>
                        <span>Templates</span>
                    </button>
                    <button id="lcAiBtn" onclick="lcAiSuggest()" disabled class="lc-mini-btn lc-ai-btn" title="ให้ AI ช่วยร่างข้อความตอบ — ตรวจก่อนกดส่งทุกครั้ง (AI อาจสร้างข้อความที่ไม่ถูกต้อง)">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>AI ช่วยร่าง</span>
                    </button>
                    <span class="lc-counter ml-auto" id="lcCharCounter">0 / 4000</span>
                </div>
                <div class="flex gap-2 items-end max-w-4xl mx-auto">
                    <textarea id="lcReplyInput" rows="1" disabled maxlength="4000"
                        placeholder="<?= $lineTokenSet ? 'พิมพ์ข้อความตอบกลับ LINE user...' : 'ตั้งค่า LINE token ก่อนใช้งาน' ?>"
                        class="ds-input flex-1 text-sm resize-none max-h-40"
                        oninput="lcOnInput()"
                        onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); lcSendReply();}"></textarea>
                    <button id="lcSendBtn" onclick="lcSendReply()" disabled class="ds-btn lc-send-btn shrink-0">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
                <div class="lc-disclaimer mt-2 text-center max-w-4xl mx-auto text-slate-400">
                    <i class="fa-solid fa-shield-halved mr-1"></i>
                    ข้อความนี้ส่งไปยัง LINE user ทันที · จะถูกบันทึก audit พร้อม admin id และ timestamp
                </div>
            </div>

        </section>
    </div>

    <!-- Template quick-pick popover (anchored to button) -->
    <div id="lcTemplatePopover" class="lc-template-pop hidden">
        <div class="lc-template-pop-head">
            <i class="fa-solid fa-bookmark text-emerald-500"></i>
            <span>เลือก Template</span>
            <button class="ml-auto text-slate-400 hover:text-slate-600" onclick="lcHideTemplateMenu()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="search" id="lcTemplateSearch" placeholder="ค้นหา..." class="lc-template-pop-search">
        <div id="lcTemplatePopList" class="lc-template-pop-list">
            <div class="text-center text-slate-400 text-xs py-6">กำลังโหลด...</div>
        </div>
        <div class="lc-template-pop-foot">
            <button onclick="lcOpenTemplatesModal()" class="text-emerald-600 hover:text-emerald-700 text-xs font-bold">
                <i class="fa-solid fa-gear mr-1"></i>จัดการ Templates
            </button>
        </div>
    </div>

    <!-- Templates manager modal -->
    <div id="lcTemplatesModal" class="lc-modal hidden">
        <div class="lc-modal-box">
            <div class="lc-modal-head">
                <div>
                    <h3 class="lc-modal-title"><i class="fa-solid fa-bookmark text-emerald-500 mr-2"></i>Quick Reply Templates</h3>
                    <p class="text-xs text-slate-500 mt-0.5">คำตอบสำเร็จรูปสำหรับแอดมิน · ใช้บ่อยจะเด้งขึ้นบน</p>
                </div>
                <button onclick="lcCloseTemplatesModal()" class="lc-modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="lc-modal-body">
                <button onclick="lcEditTemplate(null)" class="lc-add-btn">
                    <i class="fa-solid fa-plus mr-1"></i> เพิ่ม Template ใหม่
                </button>
                <div id="lcTemplatesList" class="lc-templates-list mt-3">
                    <div class="text-center text-slate-400 text-sm py-12">กำลังโหลด...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Template editor modal (over the manager) -->
    <div id="lcTemplateEditor" class="lc-modal hidden">
        <div class="lc-modal-box" style="max-width:560px">
            <div class="lc-modal-head">
                <h3 class="lc-modal-title" id="lcTemplateEditorTitle">เพิ่ม Template</h3>
                <button onclick="lcCloseTemplateEditor()" class="lc-modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="lc-modal-body space-y-3">
                <input type="hidden" id="lcTemplateId" value="">
                <div>
                    <label class="lc-field-label">ชื่อ <span class="text-rose-500">*</span></label>
                    <input type="text" id="lcTemplateTitle" maxlength="120" class="lc-side-input" placeholder="เช่น: แจ้งเวลาเปิด-ปิด">
                </div>
                <div>
                    <label class="lc-field-label">หมวด</label>
                    <input type="text" id="lcTemplateCategory" maxlength="60" class="lc-side-input" placeholder="ทั่วไป / นัดหมาย / ประกัน...">
                </div>
                <div>
                    <label class="lc-field-label">เนื้อหา <span class="text-rose-500">*</span></label>
                    <textarea id="lcTemplateBody" rows="6" maxlength="4000" class="lc-side-textarea" placeholder="พิมพ์ข้อความที่จะใช้บ่อย..."></textarea>
                </div>
            </div>
            <div class="lc-modal-foot">
                <button onclick="lcCloseTemplateEditor()" class="btn-solid bg-slate-100 text-slate-600">ยกเลิก</button>
                <button onclick="lcSaveTemplate()" class="btn-solid bg-emerald-500 text-white"><i class="fa-solid fa-floppy-disk mr-1"></i>บันทึก</button>
            </div>
        </div>
    </div>

</div>

<style>
.lc-shell { min-height: 0; }
.lc-side { flex-shrink: 0; width: 320px; }
.lc-subtitle { font-size: 11px; }
.lc-disclaimer { font-size: 10px; }
.lc-send-btn { background: #06c755 !important; color: white !important; }
.lc-send-btn:hover:not(:disabled) { background: #05a847 !important; box-shadow: 0 4px 12px rgba(6,199,85,.30); }
.lc-send-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* Sidebar header (search + filter chips) */
.lc-side-header {
    padding: 10px 12px 8px;
    background: #fff;
    border-bottom: 1px solid #f1f5f9;
}
.lc-filter-row {
    display: flex; gap: 5px; margin-top: 8px;
    overflow-x: auto; padding-bottom: 2px;
}
.lc-filter-row::-webkit-scrollbar { height: 0; }
.lc-chip {
    padding: 5px 11px; border-radius: 999px;
    background: #f1f5f9; color: #475569;
    font-size: 11.5px; font-weight: 700;
    border: 1.5px solid transparent;
    cursor: pointer; transition: all 0.15s;
    white-space: nowrap; flex-shrink: 0;
}
.lc-chip:hover { background: #e2e8f0; }
.lc-chip.is-active {
    background: linear-gradient(135deg, #06c755, #00b900);
    color: white;
    box-shadow: 0 2px 6px rgba(6,199,85,.30);
}

/* Search input — sidebar variant */
.lc-search-wrap { position: relative; width: 100%; }
.lc-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; pointer-events: none; }
.lc-search-input {
    width: 100%; padding: 8px 32px 8px 32px;
    border-radius: 999px; border: 1.5px solid #e2e8f0; background: #f8fafc;
    font-size: 12.5px; font-weight: 600; color: #334155;
    transition: border-color .15s, background .15s, box-shadow .15s;
}
.lc-search-input::placeholder { color: #94a3b8; font-weight: 600; }
.lc-search-input:focus { outline: none; border-color: #06c755; background: #fff; box-shadow: 0 0 0 3px rgba(6,199,85,.12); }
.lc-search-clear {
    position: absolute; right: 7px; top: 50%; transform: translateY(-50%);
    background: #e2e8f0; color: #64748b; border: none;
    width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 10px;
}
.lc-search-clear:hover { background: #cbd5e1; color: #334155; }

/* Compact convo items (left rail) */
.lc-convo-item {
    padding: 9px 10px 9px 13px; border-radius: 10px; cursor: pointer;
    border: 1.5px solid transparent;
    transition: background 0.15s, border-color 0.15s, transform 0.15s;
    position: relative;
    display: flex; gap: 10px; align-items: flex-start;
    margin-bottom: 2px;
}
.lc-convo-item:hover { background: #f8fafc; }
.lc-convo-item.active {
    background: rgba(6,199,85,.08); border-color: rgba(6,199,85,.35);
}
.lc-convo-item.active::before {
    content: ''; position: absolute; left: 0; top: 10px; bottom: 10px;
    width: 3px; background: #06c755; border-radius: 0 3px 3px 0;
}
.lc-convo-item.needs-reply::after {
    content: ''; position: absolute; top: 14px; right: 12px;
    width: 8px; height: 8px; border-radius: 50%; background: #ef4444;
    box-shadow: 0 0 0 3px rgba(239,68,68,.20);
}
.lc-convo-item.is-resolved { opacity: 0.55; }
.lc-convo-item.is-resolved .c-name::before { content: '✓ '; color: #10b981; font-weight: 900; }
.lc-convo-pic {
    width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    display: flex; align-items: center; justify-content: center;
    color: #94a3b8; overflow: hidden;
    box-shadow: 0 1px 3px rgba(15,23,42,.08);
}
.lc-convo-pic img { width: 100%; height: 100%; object-fit: cover; }
.lc-convo-body { flex: 1; min-width: 0; }
.lc-convo-item .c-row1 {
    display: flex; align-items: center; gap: 6px;
}
.lc-convo-item .c-name {
    font-size: 13px; font-weight: 800; color: #1e293b;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1; min-width: 0;
}
.lc-convo-item .c-time {
    font-size: 10px; color: #94a3b8; font-weight: 700; flex-shrink: 0;
    padding-right: 14px; /* room for needs-reply dot */
}
.lc-convo-item.needs-reply .c-time { padding-right: 18px; }
.lc-convo-item:not(.needs-reply) .c-time { padding-right: 0; }
.lc-convo-item .c-preview {
    font-size: 12px; color: #64748b; margin-top: 3px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    line-height: 1.4;
}
.lc-convo-item .c-preview .c-dir { color: #94a3b8; margin-right: 4px; }
.lc-convo-item .c-preview .c-dir.outbound { color: #06c755; }
.lc-convo-item .c-meta-row {
    display: flex; align-items: center; gap: 5px; margin-top: 4px; flex-wrap: wrap;
}
.lc-convo-item .c-tag-pill {
    background: rgba(6,199,85,.12); color: #047857;
    font-size: 9.5px; font-weight: 800; padding: 1px 6px; border-radius: 999px;
}
.lc-convo-item .c-msg-count {
    font-size: 9.5px; color: #94a3b8; font-weight: 700; margin-left: auto;
}

/* Pager (sidebar bottom) */
.lc-pager {
    border-top: 1px solid #f1f5f9; padding: 8px 10px;
    font-size: 10.5px; color: #64748b; font-weight: 700;
    display: flex; align-items: center; justify-content: space-between;
    background: #fafbfc;
}
.lc-pager-btn {
    background: white; color: #475569; border: 1px solid #e2e8f0;
    border-radius: 6px; padding: 2px 7px; font-size: 11px; font-weight: 800;
    cursor: pointer; transition: all .12s;
}
.lc-pager-btn:hover { background: #06c755; color: white; border-color: #06c755; }
.lc-pager-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* Convo header (right side, top) */
.lc-convo-header {
    padding: 12px 18px;
    border-bottom: 1px solid #e2e8f0;
    background: #fff;
    display: flex; align-items: center; justify-content: space-between;
}
.lc-convo-title { font-size: 14.5px; font-weight: 900; color: #1e293b; }
.lc-convo-meta { font-size: 10.5px; color: #94a3b8; font-weight: 600; font-family: ui-monospace, monospace; margin-top: 1px; }
.lc-action-btn {
    background: #f1f5f9; color: #475569; border: none;
    border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 700;
    cursor: pointer; transition: all .15s;
    display: inline-flex; align-items: center; gap: 4px;
}
.lc-action-btn:hover { background: #e2e8f0; color: #1e293b; }
.lc-action-btn.is-active {
    background: linear-gradient(135deg, #06c755, #00b900); color: white;
    box-shadow: 0 2px 8px rgba(6,199,85,.30);
}

/* When no convo selected — hide input + side panel button */
.lc-main.lc-no-convo .lc-input-bar { display: none; }

/* Status pills — student/faculty/staff/other */
.lc-status { font-size: 9.5px; font-weight: 800; padding: 1.5px 7px; border-radius: 999px; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.lc-status.tone-info   { background: rgba(59,130,246,.12); color: #2563eb; }
.lc-status.tone-accent { background: rgba(168,85,247,.12); color: #9333ea; }
.lc-status.tone-amber  { background: rgba(245,158,11,.15); color: #b45309; }
.lc-status.tone-slate  { background: rgba(100,116,139,.15); color: #475569; }
.lc-status.tone-emerald { background: rgba(16,185,129,.15); color: #047857; }

/* Empty state — when no convo selected */
.lc-empty {
    max-width: 480px; margin: 60px auto; padding: 0 24px;
    text-align: center; color: #64748b;
}
.lc-empty-icon {
    width: 88px; height: 88px; border-radius: 50%;
    background: linear-gradient(135deg, rgba(6,199,85,.10), rgba(6,199,85,.05));
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 18px; color: #06c755; font-size: 38px;
    box-shadow: 0 8px 24px -8px rgba(6,199,85,.40);
}
.lc-empty-title { font-size: 18px; font-weight: 900; color: #1e293b; margin-bottom: 6px; letter-spacing: -0.01em; }
.lc-empty-sub { font-size: 13px; color: #64748b; line-height: 1.6; margin-bottom: 22px; }
.lc-empty-tips {
    display: flex; flex-direction: column; gap: 9px;
    text-align: left; max-width: 380px; margin: 0 auto;
}
.lc-empty-tip {
    padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 10px; font-size: 12.5px; color: #475569;
    display: flex; align-items: center; gap: 10px;
}
.lc-empty-tip i { color: #64748b; width: 16px; text-align: center; }
.lc-empty-tip strong { color: #1e293b; font-weight: 800; }

/* Convo header avatar (right panel top) */
.lc-header-pic { width: 44px; height: 44px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8; }
.lc-header-pic img { width: 100%; height: 100%; object-fit: cover; }
.lc-header-row { display: flex; gap: 12px; align-items: center; min-width: 0; flex: 1; }

.lc-msg-row { display: flex; gap: 10px; align-items: flex-end; }
.lc-msg-row.is-outbound { flex-direction: row-reverse; }
.lc-msg-body { flex: 1 1 0; min-width: 0; }
.lc-msg-row.is-outbound .lc-msg-body { display: flex; flex-direction: column; align-items: flex-end; }
.lc-avatar { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 12px; }
.lc-avatar.user { background: #f1f5f9; color: #64748b; }
.lc-avatar.admin { background: #06c755; color: white; }
.lc-avatar.ai { background: #a855f7; color: white; }
.lc-avatar.system { background: #f59e0b; color: white; }
.lc-bubble { max-width: 70%; padding: 10px 14px; border-radius: 16px; font-size: 14px; line-height: 1.5; }
.lc-bubble.user { background: white; border: 1.5px solid #e2e8f0; color: #334155; border-bottom-left-radius: 4px; }
.lc-bubble.admin { background: #06c755; color: white; border-bottom-right-radius: 4px; }
.lc-bubble.ai { background: #a855f7; color: white; border-bottom-right-radius: 4px; opacity: 0.92; }
.lc-bubble.system { background: #fef3c7; color: #92400e; border-bottom-right-radius: 4px; font-size: 12px; }
.lc-bubble pre { white-space: pre-wrap; word-wrap: break-word; font-family: inherit; margin: 0; }
.lc-bubble-meta { font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 3px; padding: 0 4px; }
.lc-bubble-meta .lc-fail { color: #ef4444; font-weight: 700; }

/* Rich content bubbles (sticker / image / location / file) */
.lc-bubble.media { padding: 6px; background: transparent !important; border: none; }
.lc-bubble.media.user { background: transparent !important; }
.lc-bubble-image { max-width: 240px; max-height: 320px; border-radius: 12px; cursor: zoom-in; display: block; }
.lc-bubble-image-link:hover { opacity: 0.92; }
.lc-bubble-sticker { width: 120px; height: 120px; object-fit: contain; display: block; }
.lc-bubble-location {
    background: white; border: 1.5px solid #e2e8f0; padding: 12px 14px; border-radius: 12px;
    max-width: 260px; min-width: 200px;
}
.lc-bubble-location .lc-loc-title { font-weight: 800; color: #334155; font-size: 13px; margin-bottom: 4px; }
.lc-bubble-location .lc-loc-addr { font-size: 11px; color: #64748b; line-height: 1.4; }
.lc-bubble-location .lc-loc-link {
    margin-top: 8px; padding-top: 8px; border-top: 1px solid #e2e8f0;
    font-size: 11px; font-weight: 700; color: #06c755; display: flex; align-items: center; gap: 4px;
}
.lc-bubble-file {
    background: white; border: 1.5px solid #e2e8f0; padding: 12px 14px; border-radius: 12px;
    display: flex; align-items: center; gap: 12px; min-width: 200px;
}
.lc-bubble-file .fi-icon {
    width: 36px; height: 36px; border-radius: 8px; background: rgba(6,199,85,.12);
    display: flex; align-items: center; justify-content: center; color: #06c755; flex-shrink: 0;
}
.lc-bubble-file .fi-name { font-weight: 800; color: #334155; font-size: 13px; word-break: break-word; }
.lc-bubble-file .fi-size { font-size: 11px; color: #94a3b8; margin-top: 2px; }

.lc-time-divider { text-align: center; padding: 10px 0; }
.lc-time-divider span { background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 700; padding: 3px 12px; border-radius: 999px; }

/* Input toolbar (above textarea) */
.lc-input-toolbar {
    display: flex; gap: 6px; align-items: center;
    max-width: 56rem; margin: 0 auto 8px; padding: 0 4px;
}
.lc-mini-btn {
    padding: 5px 10px; border-radius: 999px;
    background: #f1f5f9; color: #475569;
    font-size: 11px; font-weight: 700;
    border: 1.5px solid transparent;
    cursor: pointer; transition: all 0.15s;
    display: inline-flex; align-items: center; gap: 5px;
}
.lc-mini-btn:hover:not(:disabled) { background: #e2e8f0; color: #06c755; }
.lc-mini-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.lc-mini-btn.lc-ai-btn { color: #7c3aed; }
.lc-mini-btn.lc-ai-btn:hover:not(:disabled) { background: rgba(168,85,247,.10); color: #6d28d9; }
.lc-mini-btn.is-loading { pointer-events: none; opacity: 0.7; }
.lc-mini-btn.is-loading i.fa-wand-magic-sparkles { animation: lcSpin 0.8s linear infinite; }
@keyframes lcSpin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
.lc-counter { font-size: 10px; color: #94a3b8; font-weight: 700; font-family: ui-monospace, monospace; }
.lc-counter.lc-warn { color: #d97706; }

/* Right-side panel */
.lc-main-row { min-height: 0; }
.lc-side-panel {
    width: 290px; flex-shrink: 0; background: #fafbfc;
    border-left: 1.5px solid #e2e8f0; overflow-y: auto;
    transition: width 0.25s, opacity 0.18s;
}
.lc-side-section { padding: 14px 14px 10px; border-bottom: 1px solid #e2e8f0; }
.lc-side-section:last-child { border-bottom: none; }
.lc-side-title { font-size: 12px; font-weight: 900; color: #334155; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
.lc-side-hint { font-size: 10px; color: #94a3b8; font-weight: 700; }
.lc-side-input {
    width: 100%; padding: 7px 12px; border-radius: 8px;
    border: 1.5px solid #e2e8f0; background: #fff;
    font-size: 13px; color: #334155;
    transition: border-color .15s, box-shadow .15s;
}
.lc-side-input:focus { outline: none; border-color: #06c755; box-shadow: 0 0 0 3px rgba(6,199,85,.12); }
.lc-side-textarea {
    width: 100%; padding: 8px 12px; border-radius: 8px;
    border: 1.5px solid #e2e8f0; background: #fff;
    font-size: 13px; color: #334155; resize: vertical;
    line-height: 1.5;
}
.lc-side-textarea:focus { outline: none; border-color: #06c755; box-shadow: 0 0 0 3px rgba(6,199,85,.12); }
.lc-side-meta { display: flex; align-items: center; justify-content: space-between; margin-top: 6px; font-size: 11px; }
.lc-side-save-btn {
    padding: 5px 14px; border-radius: 999px;
    background: #06c755; color: white;
    font-size: 11px; font-weight: 800;
    border: none; cursor: pointer; transition: all 0.15s;
}
.lc-side-save-btn:hover:not(:disabled) { background: #05a847; }
.lc-side-save-btn:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
.lc-tags-view { display: flex; flex-wrap: wrap; gap: 6px; min-height: 24px; margin-bottom: 8px; }
.lc-tags-view:empty::before { content: 'ยังไม่มีแท็ก'; font-size: 11px; color: #94a3b8; font-weight: 700; padding: 4px 0; }
.lc-tag-chip {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(6,199,85,.15); color: #047857;
    font-size: 11px; font-weight: 800; padding: 3px 8px 3px 10px;
    border-radius: 999px;
}
.lc-tag-chip .x { cursor: pointer; opacity: 0.6; transition: opacity .15s; }
.lc-tag-chip .x:hover { opacity: 1; color: #ef4444; }
.lc-tag-input-row { margin-bottom: 8px; }
.lc-tag-presets { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.lc-tag-preset {
    background: white; color: #475569;
    font-size: 10px; font-weight: 700; padding: 3px 9px;
    border: 1px solid #e2e8f0; border-radius: 999px;
    cursor: pointer; transition: all 0.15s;
}
.lc-tag-preset:hover { background: rgba(6,199,85,.10); color: #047857; border-color: rgba(6,199,85,.35); }
.lc-field-label { font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: .04em; display: block; margin-bottom: 4px; }

/* Template popover */
.lc-template-pop {
    position: fixed; z-index: 9100;
    width: 360px; max-height: 480px;
    background: white; border: 1.5px solid #e2e8f0;
    border-radius: 16px; box-shadow: 0 20px 50px -10px rgba(15,23,42,.30);
    overflow: hidden; display: flex; flex-direction: column;
}
.lc-template-pop-head { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; font-weight: 800; color: #334155; font-size: 13px; }
.lc-template-pop-head .ml-auto { background: transparent; border: none; cursor: pointer; }
.lc-template-pop-search { margin: 8px 12px 4px; padding: 7px 12px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: 12px; }
.lc-template-pop-search:focus { outline: none; border-color: #06c755; }
.lc-template-pop-list { flex: 1; overflow-y: auto; padding: 8px 8px 0; }
.lc-template-pop-foot { padding: 8px 12px; border-top: 1px solid #e2e8f0; background: #fafbfc; }
.lc-tpl-cat-head { font-size: 9px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: .12em; padding: 6px 8px 2px; }
.lc-tpl-item { padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: background .12s; }
.lc-tpl-item:hover { background: rgba(6,199,85,.08); }
.lc-tpl-item .tpl-title { font-weight: 800; font-size: 12px; color: #334155; }
.lc-tpl-item .tpl-body { font-size: 11px; color: #64748b; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

/* Modals — Portal-Escape pattern (teleport to body) */
.lc-modal {
    position: fixed !important; inset: 0;
    background: rgba(15,23,42,.55) !important;
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    z-index: 9000 !important;
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.lc-modal.hidden { display: none !important; }
/* Override custom display:flex on these classes — Tailwind .hidden must win */
.lc-template-pop.hidden,
.lc-convo-header.hidden { display: none !important; }
.lc-modal-box {
    width: 100%; max-width: 760px;
    max-height: 90vh;
    background: white; border-radius: 18px;
    box-shadow: 0 25px 60px -15px rgba(15,23,42,.45);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.lc-modal-head { padding: 16px 20px; border-bottom: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
.lc-modal-title { font-size: 16px; font-weight: 900; color: #334155; margin: 0; }
.lc-modal-close { width: 32px; height: 32px; border-radius: 8px; background: #f1f5f9; color: #64748b; border: none; cursor: pointer; font-size: 14px; }
.lc-modal-close:hover { background: #e2e8f0; color: #ef4444; }
.lc-modal-body { flex: 1; padding: 16px 20px; overflow-y: auto; }
.lc-modal-foot { padding: 12px 20px; border-top: 1.5px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px; background: #fafbfc; }
.lc-add-btn { width: 100%; padding: 10px; border-radius: 10px; background: rgba(6,199,85,.12); color: #047857; border: 1.5px dashed rgba(6,199,85,.50); font-weight: 800; font-size: 13px; cursor: pointer; transition: all .15s; }
.lc-add-btn:hover { background: rgba(6,199,85,.18); }
.lc-templates-list { display: flex; flex-direction: column; gap: 8px; }
.lc-tpl-row { padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 12px; background: white; }
.lc-tpl-row.is-inactive { opacity: 0.55; background: #fafbfc; }
.lc-tpl-row-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.lc-tpl-row .tpl-title { font-weight: 800; color: #334155; font-size: 14px; }
.lc-tpl-row .tpl-cat { font-size: 10px; font-weight: 800; background: rgba(6,199,85,.12); color: #047857; padding: 2px 9px; border-radius: 999px; }
.lc-tpl-row .tpl-body { font-size: 12px; color: #64748b; margin-top: 6px; white-space: pre-wrap; line-height: 1.5; }
.lc-tpl-row-actions { display: flex; gap: 4px; }
.lc-tpl-row-actions button { background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 4px 8px; border-radius: 6px; font-size: 12px; transition: all .15s; }
.lc-tpl-row-actions button:hover { background: #f1f5f9; color: #334155; }
.lc-tpl-row-actions button.danger:hover { background: rgba(239,68,68,.12); color: #ef4444; }
.lc-tpl-row .tpl-use { font-size: 10px; color: #94a3b8; font-weight: 700; }

@media (max-width: 768px) {
    .lc-side { width: 100%; max-height: 220px; border-right: none; border-bottom: 1.5px solid #e2e8f0; }
    .lc-body { flex-direction: column; }
    .lc-side-panel { width: 100%; max-height: 300px; border-left: none; border-top: 1.5px solid #e2e8f0; }
    .lc-search-wrap { flex-basis: 100%; }
}

/* DARK MODE */
body[data-theme='dark'] #section-line_chat .lc-shell { background: rgba(15,23,42,.55); }
body[data-theme='dark'] #section-line_chat .bg-white\/80 { background: rgba(15,23,42,.65) !important; }
body[data-theme='dark'] #section-line_chat .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-line_chat .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-line_chat .bg-slate-50\/50 { background: rgba(148,163,184,.04) !important; }
body[data-theme='dark'] #section-line_chat .bg-slate-100 { background: rgba(148,163,184,.14) !important; color:#cbd5e1 !important; }
body[data-theme='dark'] #section-line_chat .border-slate-100 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-line_chat .text-slate-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-line_chat .text-slate-600 { color:#cbd5e1 !important; }
body[data-theme='dark'] #section-line_chat .text-slate-500,
body[data-theme='dark'] #section-line_chat .text-slate-400 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-line_chat .lc-chip { background: rgba(148,163,184,.14); color:#cbd5e1; }
body[data-theme='dark'] #section-line_chat .lc-convo-item:hover { background: rgba(148,163,184,.10); }
body[data-theme='dark'] #section-line_chat .lc-bubble.user { background:#0f172a !important; border-color:#1e293b !important; color:#e2e8f0 !important; }
body[data-theme='dark'] #section-line_chat .lc-time-divider span { background: rgba(148,163,184,.14); color:#cbd5e1; }
body[data-theme='dark'] #section-line_chat .bg-amber-50 { background: rgba(245,158,11,.18) !important; }
body[data-theme='dark'] #section-line_chat .text-amber-700,
body[data-theme='dark'] #section-line_chat .text-amber-900 { color:#fcd34d !important; }
body[data-theme='dark'] #section-line_chat .lc-bubble.system { background: rgba(245,158,11,.22) !important; color:#fde68a !important; }
body[data-theme='dark'] #section-line_chat .lc-convo-pic,
body[data-theme='dark'] #section-line_chat .lc-header-pic { background: rgba(148,163,184,.14) !important; color:#94a3b8; }
body[data-theme='dark'] #section-line_chat .lc-status.tone-info   { background: rgba(59,130,246,.20) !important; color:#60a5fa !important; }
body[data-theme='dark'] #section-line_chat .lc-status.tone-accent { background: rgba(168,85,247,.20) !important; color:#c084fc !important; }
body[data-theme='dark'] #section-line_chat .lc-status.tone-amber  { background: rgba(245,158,11,.20) !important; color:#fbbf24 !important; }
body[data-theme='dark'] #section-line_chat .lc-status.tone-slate  { background: rgba(148,163,184,.18) !important; color:#cbd5e1 !important; }
body[data-theme='dark'] #section-line_chat .lc-status.tone-emerald { background: rgba(16,185,129,.20) !important; color:#34d399 !important; }
body[data-theme='dark'] #section-line_chat .lc-search-input { background:#0f172a; border-color:#1e293b; color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-search-clear { background: rgba(148,163,184,.18); color:#cbd5e1; }
body[data-theme='dark'] .lc-side-panel { background: rgba(148,163,184,.04); border-left-color:#1e293b; }
body[data-theme='dark'] .lc-side-section { border-bottom-color:#1e293b; }
body[data-theme='dark'] .lc-side-input,
body[data-theme='dark'] .lc-side-textarea { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
body[data-theme='dark'] .lc-tag-preset { background: rgba(148,163,184,.10); border-color:#1e293b; color:#cbd5e1; }
body[data-theme='dark'] .lc-tag-preset:hover { background: rgba(6,199,85,.15); color: #34d399; }
body[data-theme='dark'] .lc-mini-btn { background: rgba(148,163,184,.14); color:#cbd5e1; }
body[data-theme='dark'] .lc-mini-btn:hover:not(:disabled) { background: rgba(148,163,184,.22); color:#34d399; }
body[data-theme='dark'] .lc-modal-box { background:#0f172a; }
body[data-theme='dark'] .lc-modal-head { border-bottom-color:#1e293b; }
body[data-theme='dark'] .lc-modal-foot { background: rgba(148,163,184,.04); border-top-color:#1e293b; }
body[data-theme='dark'] .lc-modal-title { color:#e2e8f0; }
body[data-theme='dark'] .lc-modal-close { background: rgba(148,163,184,.14); color:#cbd5e1; }
body[data-theme='dark'] .lc-tpl-row { background:#0b1220; border-color:#1e293b; }
body[data-theme='dark'] .lc-tpl-row .tpl-title { color:#e2e8f0; }
body[data-theme='dark'] .lc-tpl-row .tpl-body { color:#cbd5e1; }
body[data-theme='dark'] .lc-template-pop { background:#0f172a; border-color:#1e293b; }
body[data-theme='dark'] .lc-template-pop-search { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
body[data-theme='dark'] .lc-tpl-item:hover { background: rgba(6,199,85,.15); }
body[data-theme='dark'] .lc-tpl-item .tpl-title { color:#e2e8f0; }
body[data-theme='dark'] .lc-tpl-item .tpl-body { color:#cbd5e1; }
body[data-theme='dark'] #section-line_chat .lc-bubble-location,
body[data-theme='dark'] #section-line_chat .lc-bubble-file { background:#0f172a !important; border-color:#1e293b !important; }
body[data-theme='dark'] #section-line_chat .lc-bubble-location .lc-loc-title,
body[data-theme='dark'] #section-line_chat .lc-bubble-file .fi-name { color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-bubble-location .lc-loc-link { border-top-color:#1e293b; }
body[data-theme='dark'] #section-line_chat .lc-convo-item .c-tag-pill { background: rgba(6,199,85,.20); color:#34d399; }
body[data-theme='dark'] .lc-tpl-row .tpl-cat { background: rgba(6,199,85,.20); color:#34d399; }
/* New polish classes — dark mode */
body[data-theme='dark'] #section-line_chat .lc-side-header { background:#0f172a; border-bottom-color:#1e293b; }
body[data-theme='dark'] #section-line_chat .lc-convo-item:hover { background: rgba(148,163,184,.06); }
body[data-theme='dark'] #section-line_chat .lc-convo-item.active { background: rgba(6,199,85,.14); border-color: rgba(6,199,85,.40); }
body[data-theme='dark'] #section-line_chat .lc-convo-item .c-name { color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-convo-item .c-time,
body[data-theme='dark'] #section-line_chat .lc-convo-item .c-msg-count { color:#64748b; }
body[data-theme='dark'] #section-line_chat .lc-convo-item .c-preview { color:#94a3b8; }
body[data-theme='dark'] #section-line_chat .lc-convo-pic { background: linear-gradient(135deg, rgba(148,163,184,.14), rgba(148,163,184,.08)); }
body[data-theme='dark'] #section-line_chat .lc-pager { background: rgba(148,163,184,.04); border-top-color:#1e293b; color:#94a3b8; }
body[data-theme='dark'] #section-line_chat .lc-pager-btn { background:#0b1220; border-color:#1e293b; color:#cbd5e1; }
body[data-theme='dark'] #section-line_chat .lc-pager-btn:hover { background:#06c755; color:white; border-color:#06c755; }
body[data-theme='dark'] #section-line_chat .lc-convo-header { background: rgba(15,23,42,.65); border-bottom-color:#1e293b; }
body[data-theme='dark'] #section-line_chat .lc-convo-title { color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-convo-meta { color:#64748b; }
body[data-theme='dark'] #section-line_chat .lc-action-btn { background: rgba(148,163,184,.14); color:#cbd5e1; }
body[data-theme='dark'] #section-line_chat .lc-action-btn:hover { background: rgba(148,163,184,.22); color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-empty-title { color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-empty-sub { color:#94a3b8; }
body[data-theme='dark'] #section-line_chat .lc-empty-tip { background: rgba(148,163,184,.06); border-color:#1e293b; color:#cbd5e1; }
body[data-theme='dark'] #section-line_chat .lc-empty-tip i { color:#94a3b8; }
body[data-theme='dark'] #section-line_chat .lc-empty-tip strong { color:#e2e8f0; }
body[data-theme='dark'] #section-line_chat .lc-search-input { background:#0b1220; }
body[data-theme='dark'] #section-line_chat .lc-search-input:focus { background:#0f172a; }
</style>

<script>
const LC_CSRF = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
const lcState = {
    conversations: [], page: 1, perPage: 20, pages: 1, total: 0,
    filter: 'all', search: '',
    currentUid: '', currentConvo: null, currentState: null,
    sending: false, aiBusy: false,
    templates: [], templateMenuOpen: false,
};

const lcEsc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => (
    { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

function lcAjax(entity, action, payload = null, queryParams = null) {
    const qs = new URLSearchParams({ entity, action });
    if (queryParams) for (const k in queryParams) qs.set(k, queryParams[k]);
    const url = `ajax_line_chat.php?${qs.toString()}`;
    const opts = { method: payload ? 'POST' : 'GET' };
    if (payload) {
        const fd = new FormData();
        fd.append('csrf_token', LC_CSRF);
        for (const k in payload) fd.append(k, payload[k]);
        opts.body = fd;
    }
    return fetch(url, opts).then(r => r.json());
}

// Teleport modals to body to escape any containing-block trap (per CLAUDE.md modal pattern)
function lcTeleport(id) {
    const el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
    return el;
}

// Relative time formatter — "5 นาทีที่แล้ว" (long form)
function lcRelTime(iso) {
    if (!iso) return '';
    const t = new Date(iso.replace(' ', 'T'));
    if (isNaN(t)) return iso;
    const diff = (Date.now() - t.getTime()) / 1000;
    if (diff < 60) return 'เมื่อสักครู่';
    if (diff < 3600) return Math.floor(diff/60) + ' นาทีที่แล้ว';
    if (diff < 86400) return Math.floor(diff/3600) + ' ชั่วโมงที่แล้ว';
    if (diff < 604800) return Math.floor(diff/86400) + ' วันที่แล้ว';
    return iso.slice(5, 16).replace('T', ' ');
}

// Compact relative time — "5น", "3ชม", "2วัน" (for cramped sidebar)
function lcRelTimeShort(iso) {
    if (!iso) return '';
    const t = new Date(iso.replace(' ', 'T'));
    if (isNaN(t)) return iso;
    const diff = (Date.now() - t.getTime()) / 1000;
    if (diff < 60) return 'เมื่อกี้';
    if (diff < 3600) return Math.floor(diff/60) + 'น';
    if (diff < 86400) return Math.floor(diff/3600) + 'ชม';
    if (diff < 604800) return Math.floor(diff/86400) + 'วัน';
    return iso.slice(5, 10); // MM-DD
}

async function lcLoadConvos() {
    try {
        const params = {
            filter: lcState.filter, page: lcState.page, per_page: lcState.perPage,
        };
        if (lcState.search) params.q = lcState.search;
        const res = await lcAjax('conversation', 'list', null, params);
        if (!res.ok) throw new Error(res.message || 'list failed');
        lcState.conversations = res.data.rows || [];
        lcState.pages = res.data.pages || 1;
        lcState.total = res.data.total || 0;
        lcRenderConvos();
    } catch (e) {
        document.getElementById('lcConvoList').innerHTML =
            `<div class="text-rose-500 text-xs p-3">โหลดไม่สำเร็จ: ${lcEsc(e.message)}</div>`;
    }
}

function lcPicHtml(pictureUrl, fallbackLine = true) {
    if (pictureUrl) {
        return `<img src="${lcEsc(pictureUrl)}" alt="" referrerpolicy="no-referrer" onerror="this.replaceWith(Object.assign(document.createElement('i'),{className:'fa-brands fa-line text-emerald-500'}))">`;
    }
    return fallbackLine ? '<i class="fa-brands fa-line text-emerald-500"></i>' : '<i class="fa-solid fa-user"></i>';
}

function lcStatusBadgeHtml(sysUser) {
    if (!sysUser) return '<span class="lc-status tone-slate"><i class="fa-solid fa-circle-question"></i>ยังไม่ลงทะเบียน</span>';
    const tone = lcEsc(sysUser.status_tone || 'slate');
    const label = lcEsc(sysUser.status_label || 'ไม่ระบุ');
    return `<span class="lc-status tone-${tone}">${label}</span>`;
}

function lcDisplayName(c) {
    if (c.system_user && c.system_user.full_name) {
        const prefix = c.system_user.prefix ? c.system_user.prefix + ' ' : '';
        return prefix + c.system_user.full_name;
    }
    return c.profile_display_name || c.line_display_name || 'LINE User';
}

// Preview text for non-text messages
function lcPreviewText(c) {
    const mtype = c.last_msg_type || 'text';
    if (mtype === 'text') return (c.last_msg_text || '').slice(0, 80);
    const labels = {
        sticker: '🌟 [สติกเกอร์]',
        image: '🖼 [รูปภาพ]',
        location: '📍 [ตำแหน่ง]',
        file: '📎 [ไฟล์]',
        audio: '🎵 [เสียง]',
        video: '🎬 [วิดีโอ]',
    };
    return labels[mtype] || '[' + mtype + ']';
}

function lcRenderConvos() {
    const box = document.getElementById('lcConvoList');
    const list = lcState.conversations;
    if (list.length === 0) {
        const msg = lcState.search
            ? `<i class="fa-solid fa-magnifying-glass text-2xl mb-2 block text-slate-300"></i>ไม่พบบทสนทนาที่ตรง<br><span class="text-slate-400" style="font-size:11px">ลองคำค้นอื่น</span>`
            : `<i class="fa-brands fa-line text-2xl mb-2 block text-emerald-300"></i>ยังไม่มีบทสนทนา`;
        box.innerHTML = `<div class="text-center text-slate-400 text-xs py-12">${msg}</div>`;
    } else {
        box.innerHTML = list.map(c => {
            const uid = String(c.line_user_id || '');
            const isResolved = parseInt(c.is_resolved, 10);
            const needsReply = parseInt(c.needs_reply, 10) && !isResolved;
            const classes = ['lc-convo-item'];
            if (uid === lcState.currentUid) classes.push('active');
            if (isResolved) classes.push('is-resolved');
            if (needsReply) classes.push('needs-reply');
            const name = lcEsc(lcDisplayName(c));
            const lastMsg = lcEsc(lcPreviewText(c));
            const dirCls = c.last_msg_direction === 'outbound' ? 'outbound' : '';
            const dirIcon = c.last_msg_direction === 'inbound'
                ? '<i class="fa-solid fa-arrow-down c-dir"></i>'
                : `<i class="fa-solid fa-reply c-dir ${dirCls}"></i>`;
            const time = lcEsc(lcRelTimeShort(c.last_msg_at));
            const statusBadge = lcStatusBadgeHtml(c.system_user);
            const pic = lcPicHtml(c.profile_picture_url, true);
            const tags = Array.isArray(c.tags_list) && c.tags_list.length
                ? c.tags_list.slice(0, 3).map(t => `<span class="c-tag-pill">${lcEsc(t)}</span>`).join('')
                : '';
            const msgCount = parseInt(c.total_msgs, 10) || 0;
            return `<div class="${classes.join(' ')}" data-uid="${lcEsc(uid)}" title="${lcEsc(uid)}">
                <div class="lc-convo-pic">${pic}</div>
                <div class="lc-convo-body">
                    <div class="c-row1">
                        <span class="c-name">${name}</span>
                        <span class="c-time">${time}</span>
                    </div>
                    <div class="c-preview">${dirIcon}${lastMsg}</div>
                    <div class="c-meta-row">
                        ${statusBadge}
                        ${tags}
                        <span class="c-msg-count">${msgCount} ข้อความ</span>
                    </div>
                </div>
            </div>`;
        }).join('');
    }
    document.getElementById('lcPagerSummary').textContent =
        `${lcState.total} บทสนทนา · หน้า ${lcState.page}/${lcState.pages}`;
}

async function lcOpenConvo(lineUserId) {
    lcState.currentUid = lineUserId;
    lcRenderConvos();
    document.getElementById('lcMain').classList.remove('lc-no-convo');
    document.getElementById('lcConvoHeader').classList.remove('hidden');
    document.getElementById('lcReplyInput').disabled = false;
    document.getElementById('lcSendBtn').disabled = false;
    document.getElementById('lcTemplateBtn').disabled = false;
    document.getElementById('lcAiBtn').disabled = false;
    document.getElementById('lcReplyInput').focus();

    try {
        const res = await lcAjax('conversation', 'get', null, { line_user_id: lineUserId, limit: 200 });
        if (!res.ok) throw new Error(res.message);
        lcState.currentConvo = res.data;
        lcState.currentState = res.data.state || null;

        const sys = res.data.system_user;
        let titleName;
        if (sys && sys.full_name) {
            titleName = (sys.prefix ? sys.prefix + ' ' : '') + sys.full_name;
        } else {
            titleName = res.data.line_display_name || 'LINE User';
        }
        document.getElementById('lcConvoTitle').textContent = titleName;
        document.getElementById('lcConvoMeta').textContent = lineUserId;
        document.getElementById('lcHeaderPic').innerHTML = lcPicHtml(res.data.line_picture_url, true);

        const badges = [];
        badges.push(lcStatusBadgeHtml(sys));
        if (sys && sys.student_personnel_id) {
            badges.push(`<span class="lc-status tone-slate"><i class="fa-solid fa-id-badge"></i>${lcEsc(sys.student_personnel_id)}</span>`);
        }
        if (res.data.line_display_name && (!sys || res.data.line_display_name !== sys.full_name)) {
            badges.push(`<span class="lc-status tone-slate"><i class="fa-brands fa-line text-emerald-500"></i>${lcEsc(res.data.line_display_name)}</span>`);
        }
        if (lcState.currentState && lcState.currentState.is_resolved) {
            badges.push('<span class="lc-status tone-emerald"><i class="fa-solid fa-circle-check"></i>ปิดเคสแล้ว</span>');
        }
        document.getElementById('lcConvoBadges').innerHTML = badges.join('');

        lcUpdateResolveBtn();
        lcRenderSidePanel();
        lcRenderMessages(res.data.messages || []);
    } catch (e) {
        document.getElementById('lcMessages').innerHTML =
            `<div class="text-rose-500 text-sm p-4">โหลดบทสนทนาไม่สำเร็จ: ${lcEsc(e.message)}</div>`;
    }
}

function lcUpdateResolveBtn() {
    const btn = document.getElementById('lcResolveBtn');
    const lbl = document.getElementById('lcResolveLabel');
    const isResolved = lcState.currentState && lcState.currentState.is_resolved;
    btn.classList.toggle('is-active', !!isResolved);
    lbl.textContent = isResolved ? 'เปิดเคสอีกครั้ง' : 'ปิดเคส';
}

function lcRenderMessages(messages) {
    const box = document.getElementById('lcMessages');
    if (!messages || messages.length === 0) {
        box.innerHTML = '<div class="text-center text-slate-400 text-sm py-12">ยังไม่มีข้อความในบทสนทนานี้</div>';
        return;
    }
    let lastDate = '';
    const html = messages.map(m => {
        let result = '';
        const dateStr = (m.created_at || '').slice(0, 10);
        if (dateStr && dateStr !== lastDate) {
            result += `<div class="lc-time-divider"><span>${lcEsc(dateStr)}</span></div>`;
            lastDate = dateStr;
        }
        result += lcMsgHtml(m);
        return result;
    }).join('');
    box.innerHTML = html;
    box.scrollTop = box.scrollHeight;
}

// Try to parse JSON metadata stored in message_text for non-text inbound messages
function lcParseMeta(text) {
    if (!text || typeof text !== 'string') return null;
    if (text[0] !== '{') return null;
    try { return JSON.parse(text); } catch { return null; }
}

function lcMediaBubbleHtml(m) {
    const mtype = m.message_type || 'text';
    const meta = lcParseMeta(m.message_text) || {};
    if (mtype === 'sticker' && meta.sticker_id) {
        const url = `https://stickershop.line-scdn.net/stickershop/v1/sticker/${encodeURIComponent(meta.sticker_id)}/iPhone/sticker.png`;
        return `<img class="lc-bubble-sticker" src="${lcEsc(url)}" alt="sticker" referrerpolicy="no-referrer" onerror="this.replaceWith(Object.assign(document.createElement('span'),{textContent:'🌟 [สติกเกอร์]'}))">`;
    }
    if (mtype === 'image' && meta.line_msg_id) {
        const url = `line_media_proxy.php?msg_id=${encodeURIComponent(meta.line_msg_id)}`;
        return `<a class="lc-bubble-image-link" href="${lcEsc(url)}" target="_blank" rel="noopener"><img class="lc-bubble-image" src="${lcEsc(url)}" alt="image" loading="lazy"></a>`;
    }
    if (mtype === 'location' && (meta.latitude || meta.longitude)) {
        const lat = parseFloat(meta.latitude) || 0;
        const lng = parseFloat(meta.longitude) || 0;
        const mapUrl = `https://www.google.com/maps?q=${lat},${lng}`;
        return `<div class="lc-bubble-location">
            <div class="lc-loc-title"><i class="fa-solid fa-location-dot text-rose-500 mr-1"></i>${lcEsc(meta.title || 'ตำแหน่งที่ส่ง')}</div>
            ${meta.address ? `<div class="lc-loc-addr">${lcEsc(meta.address)}</div>` : ''}
            <a href="${lcEsc(mapUrl)}" target="_blank" rel="noopener" class="lc-loc-link">
                <i class="fa-solid fa-up-right-from-square"></i>เปิดใน Google Maps
            </a>
        </div>`;
    }
    if (mtype === 'file' && meta.file_name) {
        const sizeKb = meta.file_size ? Math.round(meta.file_size / 1024) + ' KB' : '';
        const dl = meta.line_msg_id ? `line_media_proxy.php?msg_id=${encodeURIComponent(meta.line_msg_id)}` : '';
        return `<div class="lc-bubble-file">
            <div class="fi-icon"><i class="fa-solid fa-file"></i></div>
            <div style="flex:1; min-width:0">
                <div class="fi-name">${lcEsc(meta.file_name)}</div>
                <div class="fi-size">${lcEsc(sizeKb)}</div>
            </div>
            ${dl ? `<a href="${lcEsc(dl)}" target="_blank" rel="noopener" class="text-emerald-600" title="ดาวน์โหลด"><i class="fa-solid fa-download"></i></a>` : ''}
        </div>`;
    }
    if (mtype === 'audio' || mtype === 'video') {
        const dl = meta.line_msg_id ? `line_media_proxy.php?msg_id=${encodeURIComponent(meta.line_msg_id)}` : '';
        const ic = mtype === 'audio' ? 'fa-music' : 'fa-film';
        const label = mtype === 'audio' ? 'ข้อความเสียง' : 'วิดีโอ';
        return `<div class="lc-bubble-file">
            <div class="fi-icon"><i class="fa-solid ${ic}"></i></div>
            <div style="flex:1; min-width:0"><div class="fi-name">${label}</div></div>
            ${dl ? `<a href="${lcEsc(dl)}" target="_blank" rel="noopener" class="text-emerald-600" title="ดาวน์โหลด"><i class="fa-solid fa-download"></i></a>` : ''}
        </div>`;
    }
    // Fallback for unsupported media
    return `<span class="text-slate-500 italic">[${lcEsc(mtype)}]</span>`;
}

function lcMsgHtml(m) {
    const isOutbound = m.direction === 'outbound';
    const sender = m.sender_type || (isOutbound ? 'system' : 'user');
    const time = lcEsc((m.created_at || '').slice(11, 16));
    const bubbleClass = sender === 'user' ? 'user' : (sender === 'ai' ? 'ai' : (sender === 'admin' ? 'admin' : 'system'));
    const avatarIcon = {
        user: '<i class="fa-solid fa-user"></i>',
        admin: '<i class="fa-solid fa-user-shield"></i>',
        ai: '<i class="fa-solid fa-robot"></i>',
        system: '<i class="fa-solid fa-circle-info"></i>',
    }[bubbleClass] || '<i class="fa-solid fa-user"></i>';

    const mtype = m.message_type || 'text';
    const isMedia = mtype !== 'text' && mtype !== '';
    const content = isMedia ? lcMediaBubbleHtml(m) : `<pre>${lcEsc(m.message_text || '')}</pre>`;
    const bubbleExtra = isMedia ? ' media' : '';

    const meta = [];
    const senderLabel = { user: 'User', admin: 'Admin', ai: 'AI', system: 'System' }[bubbleClass];
    if (senderLabel) meta.push(senderLabel);
    meta.push(time);
    if (isOutbound && m.push_ok !== null && parseInt(m.push_ok, 10) === 0) {
        meta.push('<span class="lc-fail">⚠ ส่งไม่สำเร็จ</span>');
    }
    return `<div class="lc-msg-row ${isOutbound ? 'is-outbound' : ''}">
        <div class="lc-avatar ${bubbleClass}">${avatarIcon}</div>
        <div class="lc-msg-body">
            <div class="lc-bubble ${bubbleClass}${bubbleExtra}">${content}</div>
            <div class="lc-bubble-meta">${meta.join(' · ')}</div>
        </div>
    </div>`;
}

function lcOnInput() {
    const ta = document.getElementById('lcReplyInput');
    ta.style.height = '';
    ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
    const len = ta.value.length;
    const counter = document.getElementById('lcCharCounter');
    counter.textContent = `${len} / 4000`;
    counter.classList.toggle('lc-warn', len > 3500);
}

async function lcSendReply() {
    if (lcState.sending) return;
    if (!lcState.currentUid) return;
    const input = document.getElementById('lcReplyInput');
    const text = input.value.trim();
    if (!text) return;

    lcState.sending = true;
    const usedTemplateId = parseInt(input.dataset.templateId || '0', 10) || 0;
    input.value = ''; input.style.height = ''; delete input.dataset.templateId;
    lcOnInput();
    document.getElementById('lcSendBtn').disabled = true;

    // Optimistic UI
    const msgs = document.getElementById('lcMessages');
    msgs.insertAdjacentHTML('beforeend', lcMsgHtml({
        direction: 'outbound', sender_type: 'admin', message_text: text, message_type: 'text',
        created_at: new Date().toISOString(),
    }));
    msgs.scrollTop = msgs.scrollHeight;

    try {
        const res = await lcAjax('message', 'send_reply', {
            line_user_id: lcState.currentUid, message: text,
            template_id: usedTemplateId,
        });
        if (!res.ok) throw new Error(res.message);
        await lcOpenConvo(lcState.currentUid);
        await lcLoadConvos();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: e.message });
        await lcOpenConvo(lcState.currentUid);
    } finally {
        lcState.sending = false;
        document.getElementById('lcSendBtn').disabled = false;
        input.focus();
    }
}

function lcSetFilter(filter, btn) {
    lcState.filter = filter; lcState.page = 1;
    document.querySelectorAll('.lc-chip').forEach(el => el.classList.remove('is-active'));
    if (btn) btn.classList.add('is-active');
    lcLoadConvos();
}

function lcPage(p) {
    p = Math.max(1, Math.min(lcState.pages, p));
    if (p === lcState.page) return;
    lcState.page = p;
    lcLoadConvos();
}

function lcReload() { lcLoadConvos(); if (lcState.currentUid) lcOpenConvo(lcState.currentUid); }

// Search — debounce 350ms
let lcSearchTimer = null;
function lcOnSearchInput() {
    const v = document.getElementById('lcSearchInput').value.trim();
    document.getElementById('lcSearchClear').classList.toggle('hidden', v === '');
    clearTimeout(lcSearchTimer);
    lcSearchTimer = setTimeout(() => {
        if (v === lcState.search) return;
        lcState.search = v; lcState.page = 1;
        lcLoadConvos();
    }, 350);
}
function lcClearSearch() {
    document.getElementById('lcSearchInput').value = '';
    document.getElementById('lcSearchClear').classList.add('hidden');
    lcState.search = ''; lcState.page = 1;
    lcLoadConvos();
}

// ── Resolved toggle ───────────────────────────────────────────
async function lcToggleResolved() {
    if (!lcState.currentUid) return;
    const next = !(lcState.currentState && lcState.currentState.is_resolved);
    try {
        const res = await lcAjax('conversation', 'set_resolved', {
            line_user_id: lcState.currentUid,
            resolved: next ? '1' : '0',
        });
        if (!res.ok) throw new Error(res.message || 'failed');
        if (!lcState.currentState) lcState.currentState = {};
        lcState.currentState.is_resolved = next ? 1 : 0;
        lcUpdateResolveBtn();
        await lcLoadConvos();
        // Refresh badges in header
        if (lcState.currentUid) await lcOpenConvo(lcState.currentUid);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'อัพเดตไม่สำเร็จ', text: e.message });
    }
}

// ── Side panel (tags + note) ──────────────────────────────────
function lcToggleSidePanel() {
    const p = document.getElementById('lcSidePanel');
    p.classList.toggle('hidden');
}
function lcRenderSidePanel() {
    const state = lcState.currentState || {};
    // Tags
    const tags = state.tags_list || [];
    document.getElementById('lcTagsView').innerHTML = tags.map(t =>
        `<span class="lc-tag-chip">${lcEsc(t)}<span class="x" data-tag="${lcEsc(t)}" title="ลบ"><i class="fa-solid fa-xmark"></i></span></span>`
    ).join('');
    // Note
    const noteInput = document.getElementById('lcNoteInput');
    noteInput.value = state.internal_note || '';
    noteInput.dataset.savedValue = state.internal_note || '';
    document.getElementById('lcNoteSaveBtn').disabled = true;
    if (state.note_updated_at) {
        const who = state.note_updated_by_name ? ' โดย ' + state.note_updated_by_name : '';
        document.getElementById('lcNoteMeta').textContent = 'อัพเดต ' + lcRelTime(state.note_updated_at) + who;
    } else {
        document.getElementById('lcNoteMeta').textContent = 'ยังไม่มีบันทึก';
    }
}
async function lcSetTagsToServer(tagsArr) {
    if (!lcState.currentUid) return;
    try {
        const res = await lcAjax('conversation', 'set_tags', {
            line_user_id: lcState.currentUid,
            tags: tagsArr.join(','),
        });
        if (!res.ok) throw new Error(res.message || 'failed');
        if (!lcState.currentState) lcState.currentState = {};
        lcState.currentState.tags_list = res.data.tags || [];
        lcState.currentState.tags = (res.data.tags || []).join(',');
        lcRenderSidePanel();
        await lcLoadConvos();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'บันทึก tag ไม่สำเร็จ', text: e.message });
    }
}
async function lcAddTag(tag) {
    tag = String(tag || '').trim();
    if (!tag) return;
    const cur = (lcState.currentState && lcState.currentState.tags_list) || [];
    if (cur.includes(tag)) return;
    if (cur.length >= 10) {
        Swal.fire({ icon: 'warning', title: 'แท็กเต็มแล้ว', text: 'แท็กต่อบทสนทนาสูงสุด 10 รายการ' });
        return;
    }
    await lcSetTagsToServer([...cur, tag]);
}
async function lcRemoveTag(tag) {
    const cur = (lcState.currentState && lcState.currentState.tags_list) || [];
    await lcSetTagsToServer(cur.filter(t => t !== tag));
}
async function lcSaveNote() {
    if (!lcState.currentUid) return;
    const input = document.getElementById('lcNoteInput');
    const val = input.value.trim();
    try {
        const res = await lcAjax('conversation', 'set_note', {
            line_user_id: lcState.currentUid, note: val,
        });
        if (!res.ok) throw new Error(res.message || 'failed');
        lcState.currentState = res.data;
        lcRenderSidePanel();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'บันทึก note ไม่สำเร็จ', text: e.message });
    }
}

// ── AI suggested reply (Gemini) ───────────────────────────────
async function lcAiSuggest() {
    if (lcState.aiBusy || !lcState.currentUid) return;
    lcState.aiBusy = true;
    const btn = document.getElementById('lcAiBtn');
    btn.classList.add('is-loading');
    btn.disabled = true;
    try {
        const hint = document.getElementById('lcReplyInput').value.trim();
        const res = await lcAjax('ai', 'suggest_reply', {
            line_user_id: lcState.currentUid,
            hint: hint,
        });
        if (!res.ok) throw new Error(res.message || 'AI ไม่ตอบ');
        const input = document.getElementById('lcReplyInput');
        input.value = res.data.answer || '';
        lcOnInput();
        input.focus();
        // Toast reminder — AI output must be reviewed before sending
        if (window.Swal) {
            Swal.fire({
                toast: true, position: 'top-end', timer: 3500, showConfirmButton: false,
                icon: 'info',
                title: 'AI ร่างแล้ว — โปรดตรวจก่อนกดส่ง',
            });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'AI ร่างไม่สำเร็จ', text: e.message });
    } finally {
        lcState.aiBusy = false;
        btn.classList.remove('is-loading');
        btn.disabled = false;
    }
}

// ── Templates ─────────────────────────────────────────────────
async function lcLoadTemplates(activeOnly = true) {
    try {
        const res = await lcAjax('template', 'list', null, { active_only: activeOnly ? 1 : 0 });
        if (!res.ok) throw new Error(res.message || 'failed');
        lcState.templates = res.data.items || [];
        return lcState.templates;
    } catch (e) {
        console.error('lcLoadTemplates', e);
        return [];
    }
}

async function lcShowTemplateMenu() {
    if (lcState.templateMenuOpen) { lcHideTemplateMenu(); return; }
    const pop = lcTeleport('lcTemplatePopover');
    const btn = document.getElementById('lcTemplateBtn');
    const r = btn.getBoundingClientRect();
    const popWidth = 360;
    const popMaxH = 480;
    const gap = 8;
    const vpW = window.innerWidth, vpH = window.innerHeight;

    // Horizontal: try align-left with button, clamp to viewport
    const left = Math.max(8, Math.min(r.left, vpW - popWidth - 8));
    pop.style.left = left + 'px';
    pop.style.right = '';

    // Vertical: prefer above button (popover bottom = btn top - gap).
    // If there isn't enough room above, flip below button.
    const spaceAbove = r.top - 8;
    const spaceBelow = vpH - r.bottom - 8;
    if (spaceAbove >= 220 || spaceAbove >= spaceBelow) {
        // Place above — anchor top so we never overflow viewport top
        const desiredTop = Math.max(8, r.top - gap - popMaxH);
        pop.style.top = desiredTop + 'px';
        pop.style.bottom = '';
        pop.style.maxHeight = Math.min(popMaxH, r.top - gap - 8) + 'px';
    } else {
        // Place below
        pop.style.top = (r.bottom + gap) + 'px';
        pop.style.bottom = '';
        pop.style.maxHeight = Math.min(popMaxH, vpH - r.bottom - gap - 8) + 'px';
    }

    pop.classList.remove('hidden');
    lcState.templateMenuOpen = true;

    const tpls = await lcLoadTemplates(true);
    document.getElementById('lcTemplateSearch').value = '';
    lcRenderTemplatePopList(tpls, '');
    setTimeout(() => {
        document.addEventListener('mousedown', lcMaybeCloseTemplateMenu, true);
    }, 50);
}
function lcMaybeCloseTemplateMenu(ev) {
    const pop = document.getElementById('lcTemplatePopover');
    const btn = document.getElementById('lcTemplateBtn');
    if (pop && !pop.contains(ev.target) && btn && !btn.contains(ev.target)) {
        lcHideTemplateMenu();
    }
}
function lcHideTemplateMenu() {
    document.getElementById('lcTemplatePopover').classList.add('hidden');
    lcState.templateMenuOpen = false;
    document.removeEventListener('mousedown', lcMaybeCloseTemplateMenu, true);
}
function lcRenderTemplatePopList(tpls, q) {
    const box = document.getElementById('lcTemplatePopList');
    const filtered = q
        ? tpls.filter(t => (t.title + ' ' + t.body + ' ' + (t.category || '')).toLowerCase().includes(q.toLowerCase()))
        : tpls;
    if (filtered.length === 0) {
        box.innerHTML = '<div class="text-center text-slate-400 text-xs py-6">ไม่พบ template</div>';
        return;
    }
    // Group by category
    const groups = {};
    for (const t of filtered) {
        const cat = t.category || 'ทั่วไป';
        (groups[cat] = groups[cat] || []).push(t);
    }
    box.innerHTML = Object.entries(groups).map(([cat, items]) => `
        <div class="lc-tpl-cat-head">${lcEsc(cat)}</div>
        ${items.map(t => `
            <div class="lc-tpl-item" data-tpl-id="${parseInt(t.id, 10)}">
                <div class="tpl-title">${lcEsc(t.title)}</div>
                <div class="tpl-body">${lcEsc((t.body || '').slice(0, 140))}</div>
            </div>
        `).join('')}
    `).join('');
}

function lcUseTemplate(id) {
    const t = lcState.templates.find(x => parseInt(x.id, 10) === parseInt(id, 10));
    if (!t) return;
    const input = document.getElementById('lcReplyInput');
    const cur = input.value;
    input.value = cur ? (cur + '\n' + t.body) : t.body;
    input.dataset.templateId = String(t.id);
    lcOnInput();
    lcHideTemplateMenu();
    input.focus();
}

// ── Templates manager modal ──────────────────────────────────
async function lcOpenTemplatesModal() {
    lcTeleport('lcTemplatesModal');
    document.getElementById('lcTemplatesModal').classList.remove('hidden');
    document.getElementById('lcTemplatesList').innerHTML = '<div class="text-center text-slate-400 text-sm py-12">กำลังโหลด...</div>';
    const tpls = await lcLoadTemplates(false);
    lcRenderTemplatesManagerList(tpls);
}
function lcCloseTemplatesModal() { document.getElementById('lcTemplatesModal').classList.add('hidden'); }

function lcRenderTemplatesManagerList(tpls) {
    const box = document.getElementById('lcTemplatesList');
    if (!tpls.length) {
        box.innerHTML = '<div class="text-center text-slate-400 text-sm py-12">ยังไม่มี template — กดปุ่ม "เพิ่ม" ด้านบน</div>';
        return;
    }
    box.innerHTML = tpls.map(t => {
        const inactive = parseInt(t.is_active, 10) ? '' : ' is-inactive';
        return `<div class="lc-tpl-row${inactive}">
            <div class="lc-tpl-row-head">
                <div>
                    <span class="tpl-cat">${lcEsc(t.category || 'ทั่วไป')}</span>
                    <span class="tpl-title ml-2">${lcEsc(t.title)}</span>
                </div>
                <div class="lc-tpl-row-actions">
                    <button onclick="lcEditTemplate(${parseInt(t.id, 10)})" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                    <button onclick="lcToggleTemplate(${parseInt(t.id, 10)})" title="${parseInt(t.is_active, 10) ? 'ปิดใช้งาน' : 'เปิดใช้งาน'}"><i class="fa-solid fa-${parseInt(t.is_active, 10) ? 'toggle-on text-emerald-500' : 'toggle-off'}"></i></button>
                    <button class="danger" onclick="lcDeleteTemplate(${parseInt(t.id, 10)})" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
            <div class="tpl-body">${lcEsc(t.body)}</div>
            <div class="tpl-use mt-1">ใช้แล้ว ${parseInt(t.use_count, 10) || 0} ครั้ง</div>
        </div>`;
    }).join('');
}

function lcEditTemplate(id) {
    lcTeleport('lcTemplateEditor');
    const editor = document.getElementById('lcTemplateEditor');
    document.getElementById('lcTemplateId').value = id || '';
    if (id) {
        const t = lcState.templates.find(x => parseInt(x.id, 10) === parseInt(id, 10));
        if (!t) return;
        document.getElementById('lcTemplateEditorTitle').textContent = 'แก้ไข Template';
        document.getElementById('lcTemplateTitle').value = t.title || '';
        document.getElementById('lcTemplateCategory').value = t.category || '';
        document.getElementById('lcTemplateBody').value = t.body || '';
    } else {
        document.getElementById('lcTemplateEditorTitle').textContent = 'เพิ่ม Template';
        document.getElementById('lcTemplateTitle').value = '';
        document.getElementById('lcTemplateCategory').value = 'ทั่วไป';
        document.getElementById('lcTemplateBody').value = '';
    }
    editor.classList.remove('hidden');
    document.getElementById('lcTemplateTitle').focus();
}
function lcCloseTemplateEditor() { document.getElementById('lcTemplateEditor').classList.add('hidden'); }

async function lcSaveTemplate() {
    const id = parseInt(document.getElementById('lcTemplateId').value, 10) || 0;
    const title = document.getElementById('lcTemplateTitle').value.trim();
    const category = document.getElementById('lcTemplateCategory').value.trim() || 'ทั่วไป';
    const body = document.getElementById('lcTemplateBody').value.trim();
    if (!title || !body) {
        Swal.fire({ icon: 'warning', title: 'กรอกชื่อและเนื้อหา' });
        return;
    }
    try {
        const payload = { title, body, category };
        const action = id ? 'update' : 'create';
        if (id) payload.id = id;
        const res = await lcAjax('template', action, payload);
        if (!res.ok) throw new Error(res.message || 'failed');
        lcCloseTemplateEditor();
        const tpls = await lcLoadTemplates(false);
        lcRenderTemplatesManagerList(tpls);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: e.message });
    }
}

async function lcToggleTemplate(id) {
    try {
        const res = await lcAjax('template', 'toggle', { id });
        if (!res.ok) throw new Error(res.message || 'failed');
        const tpls = await lcLoadTemplates(false);
        lcRenderTemplatesManagerList(tpls);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: e.message });
    }
}

async function lcDeleteTemplate(id) {
    const { isConfirmed } = await Swal.fire({
        icon: 'warning', title: 'ลบ template?', text: 'ลบแล้วกู้คืนไม่ได้',
        showCancelButton: true, confirmButtonText: 'ลบ', confirmButtonColor: '#ef4444',
        cancelButtonText: 'ยกเลิก',
    });
    if (!isConfirmed) return;
    try {
        const res = await lcAjax('template', 'delete', { id });
        if (!res.ok) throw new Error(res.message || 'failed');
        const tpls = await lcLoadTemplates(false);
        lcRenderTemplatesManagerList(tpls);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: e.message });
    }
}

// ── Init ──────────────────────────────────────────────────────
function lcInit() {
    if (window.__lcInit) return;
    window.__lcInit = true;

    // Delegated click on convo list (data-uid avoids onclick string interpolation risk)
    document.getElementById('lcConvoList').addEventListener('click', (ev) => {
        const item = ev.target.closest('.lc-convo-item');
        if (item && item.dataset.uid) lcOpenConvo(item.dataset.uid);
    });

    // Search input
    document.getElementById('lcSearchInput').addEventListener('input', lcOnSearchInput);

    // Tag input — Enter to add
    document.getElementById('lcTagInput').addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            const v = ev.target.value.trim();
            if (v) { lcAddTag(v); ev.target.value = ''; }
        }
    });

    // Tag remove (delegated)
    document.getElementById('lcTagsView').addEventListener('click', (ev) => {
        const x = ev.target.closest('.x[data-tag]');
        if (x) lcRemoveTag(x.dataset.tag);
    });

    // Note save button enable on edit
    document.getElementById('lcNoteInput').addEventListener('input', (ev) => {
        const saved = ev.target.dataset.savedValue || '';
        document.getElementById('lcNoteSaveBtn').disabled = (ev.target.value === saved);
    });

    // Template popover — delegated click + search
    document.getElementById('lcTemplatePopList').addEventListener('click', (ev) => {
        const item = ev.target.closest('.lc-tpl-item[data-tpl-id]');
        if (item) lcUseTemplate(item.dataset.tplId);
    });
    document.getElementById('lcTemplateSearch').addEventListener('input', (ev) => {
        lcRenderTemplatePopList(lcState.templates, ev.target.value.trim());
    });

    lcLoadConvos();

    // Auto-refresh every 30s while section is active
    setInterval(() => {
        const sec = document.getElementById('section-line_chat');
        if (sec && sec.style.display !== 'none' && !lcState.sending) lcLoadConvos();
    }, 30000);
}
(function() {
    const sec = document.getElementById('section-line_chat');
    if (!sec) return;
    const obs = new MutationObserver(() => {
        if (sec.style.display !== 'none' && sec.offsetParent !== null) lcInit();
    });
    obs.observe(sec, { attributes: true, attributeFilter: ['style'] });
    if (sec.style.display !== 'none') lcInit();
})();
</script>
