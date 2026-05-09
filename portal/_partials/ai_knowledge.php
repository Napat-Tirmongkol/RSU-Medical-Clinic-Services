<?php
/**
 * portal/_partials/ai_knowledge.php — AI Knowledge dashboard
 * แสดง preview ของ context + quick links + จัดการ custom notes
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/ai_knowledge_helper.php';
$_aik_notes = list_clinic_notes($pdo);

$_aik_sources = [
    [
        'icon'   => 'fa-id-card',
        'color'  => '#0ea5e9',
        'title'  => 'ข้อมูลทั่วไปของคลินิก',
        'desc'   => 'ชื่อ, เบอร์โทร — AI ใช้แทรกในคำตอบ',
        'url'    => '?section=clinic_data&cd_view=profile',
        'status' => 'มีหน้าจัดการแล้ว',
    ],
    [
        'icon'   => 'fa-clock',
        'color'  => '#10b981',
        'title'  => 'เวลาเปิด-ปิด (31 วันข้างหน้า)',
        'desc'   => 'AI ใช้ตอบ "วันนี้/พรุ่งนี้/วันที่ X เปิดไหม", "เปิดกี่โมง"',
        'url'    => '?section=clinic_data&cd_view=calendar',
        'status' => 'มีหน้าจัดการแล้ว',
    ],
    [
        'icon'   => 'fa-user-doctor',
        'color'  => '#f59e0b',
        'title'  => 'ตารางหมอออกตรวจ',
        'desc'   => 'AI ใช้ตอบ "หมอใครออกตรวจวัน X", "วัน Y มีหมอไหม"',
        'url'    => '?section=clinic_data&cd_view=schedule',
        'status' => 'มีหน้าจัดการแล้ว',
    ],
    [
        'icon'   => 'fa-flask-vial',
        'color'  => '#a855f7',
        'title'  => 'FAQ Knowledge Base',
        'desc'   => 'คำถาม-คำตอบที่ admin curate — matcher ใช้ตรง',
        'url'    => '?section=ai_qa_lab',
        'status' => 'จัดการที่ AI QA Lab → FAQ tab',
    ],
];
?>
<style>
    #ai-knowledge-section .aik-card {
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 18px;
        margin-bottom: 12px;
    }
    #ai-knowledge-section .aik-source-card {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        transition: all 0.15s;
    }
    #ai-knowledge-section .aik-source-card:hover {
        border-color: #93c5fd;
        background: #f8fafc;
    }
    #ai-knowledge-section .aik-source-icon {
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: #fff;
    }
    #ai-knowledge-section pre.aik-preview {
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 12px;
        line-height: 1.55;
        background: #0f172a;
        color: #e2e8f0;
        border: 1.5px solid #1e293b;
        border-radius: 10px;
        padding: 14px 16px;
        white-space: pre-wrap;
        word-wrap: break-word;
        max-height: 480px;
        overflow-y: auto;
        margin: 0;
    }
    #ai-knowledge-section textarea.aik-textarea {
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 13px;
        line-height: 1.55;
        width: 100%;
        min-height: 140px;
        padding: 10px 12px;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        resize: vertical;
        outline: none;
    }
    #ai-knowledge-section textarea.aik-textarea:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }
    #ai-knowledge-section .aik-toggle {
        position: relative;
        display: inline-block;
        width: 38px;
        height: 22px;
    }
    #ai-knowledge-section .aik-toggle input { display: none; }
    #ai-knowledge-section .aik-toggle-slider {
        position: absolute;
        inset: 0;
        background: #cbd5e1;
        border-radius: 999px;
        transition: 0.2s;
    }
    #ai-knowledge-section .aik-toggle-slider::before {
        content: '';
        position: absolute;
        height: 16px; width: 16px;
        left: 3px; top: 3px;
        background: #fff;
        border-radius: 50%;
        transition: 0.2s;
    }
    #ai-knowledge-section .aik-toggle input:checked + .aik-toggle-slider {
        background: #10b981;
    }
    #ai-knowledge-section .aik-toggle input:checked + .aik-toggle-slider::before {
        transform: translateX(16px);
    }
</style>

<div id="ai-knowledge-section" class="p-5 md:p-6 max-w-5xl mx-auto">
    <div class="flex items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-database text-emerald-600"></i>
                AI Knowledge
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                ข้อมูลที่ AI ใช้อ้างอิงตอนตอบคำถาม — preview, links ไปจัดการ, custom notes
            </p>
        </div>
        <div class="flex gap-2">
            <button id="aik-diagnose" class="px-3 py-2 bg-amber-50 text-amber-700 text-xs font-bold rounded-lg border border-amber-300 hover:bg-amber-100">
                <i class="fa-solid fa-stethoscope"></i> ตรวจสอบข้อมูลตารางหมอ
            </button>
            <button id="aik-refresh" class="px-3 py-2 bg-white text-gray-700 text-xs font-bold rounded-lg border border-gray-300 hover:bg-gray-50">
                <i class="fa-solid fa-rotate"></i> Refresh preview
            </button>
        </div>
    </div>

    <!-- Preview -->
    <div class="aik-card">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-base font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-eye text-cyan-600"></i>
                ตัวอย่าง context ที่ AI จะเห็น
            </h2>
            <span id="aik-preview-meta" class="text-[11px] text-gray-500"></span>
        </div>
        <pre id="aik-preview" class="aik-preview">กำลังโหลด...</pre>
    </div>

    <!-- Quick links to data sources -->
    <div class="aik-card">
        <h2 class="text-base font-black text-gray-900 flex items-center gap-2 mb-3">
            <i class="fa-solid fa-link text-blue-600"></i>
            แหล่งข้อมูล (จัดการต่อในหน้าอื่น)
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($_aik_sources as $s): ?>
            <a href="<?= htmlspecialchars($s['url']) ?>" class="aik-source-card text-left">
                <div class="aik-source-icon" style="background: <?= htmlspecialchars($s['color']) ?>">
                    <i class="fa-solid <?= htmlspecialchars($s['icon']) ?>"></i>
                </div>
                <div class="flex-1">
                    <div class="font-black text-sm text-gray-900"><?= htmlspecialchars($s['title']) ?></div>
                    <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($s['desc']) ?></div>
                    <div class="text-[10px] text-emerald-600 font-bold mt-1.5">
                        <i class="fa-solid fa-arrow-right"></i> <?= htmlspecialchars($s['status']) ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Custom notes -->
    <div class="aik-card">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="text-base font-black text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-note-sticky text-amber-600"></i>
                    Custom Notes
                </h2>
                <p class="text-xs text-gray-500 mt-0.5">
                    ข้อมูลฟรี-ฟอร์ม เช่น services, pricing, นโยบาย — ที่ active จะถูกฉีดเข้า context
                </p>
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
                    <div class="text-xs mt-1">เพิ่ม note แรก เช่น "บริการที่ให้", "ราคาตรวจสุขภาพ", "นโยบายการนัด"</div>
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
                            <span class="text-[10px] text-gray-400">#<?= (int)$n['sort_order'] ?></span>
                        </div>
                        <pre class="text-xs text-gray-600 whitespace-pre-wrap break-words font-sans" style="margin:0"><?= htmlspecialchars(mb_substr((string)$n['content'], 0, 240)) ?><?= mb_strlen((string)$n['content']) > 240 ? '...' : '' ?></pre>
                    </div>
                    <div class="flex flex-col gap-1 shrink-0">
                        <button type="button" class="aik-edit-btn px-2.5 py-1 bg-white text-gray-700 text-[11px] font-bold rounded border border-gray-300 hover:bg-gray-50">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button type="button" class="aik-del-btn px-2.5 py-1 bg-white text-rose-600 text-[11px] font-bold rounded border border-rose-200 hover:bg-rose-50">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Diagnostic modal -->
    <div id="aik-diag-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold text-amber-700 uppercase">🔍 Diagnostic</div>
                    <h3 class="text-lg font-black text-gray-900">ตรวจสอบข้อมูลตารางหมอ</h3>
                </div>
                <button id="aik-diag-close" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-4 overflow-y-auto flex-1">
                <pre id="aik-diag-output" class="aik-preview" style="background:#1e293b;max-height:none">กำลังโหลด...</pre>
            </div>
        </div>
    </div>

    <!-- Edit/Create modal -->
    <div id="aik-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col">
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
                    <textarea id="aik-input-content" class="aik-textarea" placeholder="อธิบายรายละเอียดที่อยากให้ AI รู้ — ใส่หลายบรรทัดได้"></textarea>
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-700 block mb-1">ลำดับ (sort order)</label>
                    <input id="aik-input-sort" type="number" min="0" max="999" value="0"
                        class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <span class="text-[11px] text-gray-500 ml-2">เลขน้อยขึ้นก่อน</span>
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
</div>

<script>
(function () {
    const CSRF = '<?= get_csrf_token() ?>';
    const PREVIEW = document.getElementById('aik-preview');
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
        } catch (e) {
            PREVIEW.textContent = 'Error: ' + e.message;
        }
    }
    document.getElementById('aik-refresh').addEventListener('click', loadPreview);
    loadPreview();

    // ── Diagnostic ────────────────────────────────────────────────────────
    const DIAG_MODAL = document.getElementById('aik-diag-modal');
    const DIAG_OUT   = document.getElementById('aik-diag-output');

    document.getElementById('aik-diagnose').addEventListener('click', async () => {
        DIAG_OUT.textContent = 'กำลังโหลด...';
        DIAG_MODAL.classList.remove('hidden');
        DIAG_MODAL.classList.add('flex');
        try {
            const r = await fetch('ajax_ai_knowledge.php?action=diagnose');
            const j = await r.json();
            DIAG_OUT.textContent = JSON.stringify(j, null, 2);
        } catch (e) {
            DIAG_OUT.textContent = 'Error: ' + e.message;
        }
    });
    document.getElementById('aik-diag-close').addEventListener('click', () => {
        DIAG_MODAL.classList.add('hidden');
        DIAG_MODAL.classList.remove('flex');
    });
    DIAG_MODAL.addEventListener('click', (e) => {
        if (e.target === DIAG_MODAL) {
            DIAG_MODAL.classList.add('hidden');
            DIAG_MODAL.classList.remove('flex');
        }
    });

    // ── Modal ─────────────────────────────────────────────────────────────
    const MODAL = document.getElementById('aik-modal');
    const TITLE = document.getElementById('aik-modal-title');
    const I_LABEL = document.getElementById('aik-input-label');
    const I_CONTENT = document.getElementById('aik-input-content');
    const I_SORT = document.getElementById('aik-input-sort');
    let editId = null;

    function openModal(note) {
        editId = note ? note.id : null;
        TITLE.textContent = note ? 'แก้ไข note' : 'เพิ่ม note ใหม่';
        I_LABEL.value = note ? note.label : '';
        I_CONTENT.value = note ? note.content : '';
        I_SORT.value = note ? note.sort_order : 0;
        MODAL.classList.remove('hidden');
        MODAL.classList.add('flex');
    }
    function closeModal() {
        MODAL.classList.add('hidden');
        MODAL.classList.remove('flex');
        editId = null;
    }

    document.getElementById('aik-add-btn').addEventListener('click', () => openModal(null));
    document.getElementById('aik-modal-close').addEventListener('click', closeModal);
    document.getElementById('aik-modal-cancel').addEventListener('click', closeModal);
    MODAL.addEventListener('click', (e) => { if (e.target === MODAL) closeModal(); });

    document.getElementById('aik-modal-save').addEventListener('click', async () => {
        const label = I_LABEL.value.trim();
        const content = I_CONTENT.value.trim();
        const sortOrder = parseInt(I_SORT.value || '0', 10);
        if (!label || !content) {
            Swal.fire({ icon: 'warning', title: 'กรอกหัวข้อ + เนื้อหา' });
            return;
        }
        const fd = new FormData();
        fd.append('action', editId ? 'update' : 'create');
        if (editId) fd.append('id', editId);
        fd.append('label', label);
        fd.append('content', content);
        fd.append('sort_order', String(sortOrder));
        fd.append('csrf_token', CSRF);
        try {
            const r = await fetch('ajax_ai_knowledge.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                Swal.fire({ icon: 'success', title: j.message || 'บันทึกแล้ว', timer: 1200, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: j.error || j.message || '' });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
        }
    });

    // ── Edit / Toggle / Delete on each note row ───────────────────────────
    document.querySelectorAll('#aik-notes-list [data-id]').forEach(row => {
        const id = parseInt(row.dataset.id, 10);

        row.querySelector('.aik-edit-btn')?.addEventListener('click', async () => {
            // re-fetch the row from server (เผื่อมีการแก้ใน window อื่น)
            const r = await fetch('ajax_ai_knowledge.php?action=list');
            const j = await r.json();
            const found = (j.notes || []).find(n => parseInt(n.id, 10) === id);
            if (found) openModal(found);
        });

        row.querySelector('.aik-toggle-input')?.addEventListener('change', async (ev) => {
            const fd = new FormData();
            fd.append('action', 'toggle');
            fd.append('id', id);
            fd.append('is_active', ev.target.checked ? '1' : '0');
            fd.append('csrf_token', CSRF);
            try {
                const r = await fetch('ajax_ai_knowledge.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (!j.ok) {
                    ev.target.checked = !ev.target.checked;
                    Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: j.error || '' });
                } else {
                    loadPreview();   // refresh preview เพราะ context เปลี่ยน
                }
            } catch (e) {
                ev.target.checked = !ev.target.checked;
                Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
            }
        });

        row.querySelector('.aik-del-btn')?.addEventListener('click', async () => {
            const { isConfirmed } = await Swal.fire({
                icon: 'warning',
                title: 'ลบ note นี้?',
                text: 'จะลบถาวร — ไม่สามารถกู้คืนได้',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
            });
            if (!isConfirmed) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fd.append('csrf_token', CSRF);
            try {
                const r = await fetch('ajax_ai_knowledge.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    Swal.fire({ icon: 'success', title: 'ลบแล้ว', timer: 1000, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: j.error || '' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: e.message });
            }
        });
    });
})();
</script>
