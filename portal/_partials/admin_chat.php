<?php
// portal/_partials/admin_chat.php — AI Admin Chat (thread-based)
// Gate: superadmin | admin role | access_ai flag
$apiKeySet = defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY);
$csrfToken = function_exists('get_csrf_token') ? get_csrf_token() : '';
?>

<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.11/dist/purify.min.js"></script>

<div class="ac-shell flex flex-col h-full bg-slate-50/50">

    <?php if (!$apiKeySet): ?>
    <div class="m-4 p-4 bg-amber-50 border border-amber-200 rounded-2xl flex items-start gap-3">
        <i class="fa-solid fa-key text-amber-600 text-lg mt-0.5"></i>
        <div>
            <div class="text-sm font-black text-amber-900">ยังไม่ได้ตั้งค่า Gemini API Key</div>
            <div class="text-xs text-amber-700 mt-0.5">ตั้งค่าที่หน้า Settings ก่อนใช้งาน</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="px-5 md:px-7 py-4 border-b border-slate-100 bg-white/80 backdrop-blur-md flex items-center justify-between shrink-0">
        <div>
            <div class="sec-title" style="margin-bottom:2px">
                <div class="w-8 h-8 rounded-lg bg-purple-600 flex items-center justify-center text-white shadow-lg shadow-purple-200 mr-1" style="font-size:12px">
                    <i class="fa-solid fa-comments"></i>
                </div>
                ผู้ช่วยข้อมูล (AI Admin Chat)
            </div>
            <p class="ac-subtitle text-slate-500 font-bold uppercase tracking-wider ml-11">ถามตอบจากข้อมูลคลินิก · เก็บประวัติ</p>
        </div>
        <button onclick="acNewThread()" class="ds-btn ds-btn-primary text-xs">
            <i class="fa-solid fa-plus mr-1"></i> เริ่มแชทใหม่
        </button>
    </div>

    <!-- Two-column body -->
    <div class="ac-body flex flex-1 overflow-hidden">

        <!-- Thread list (left) -->
        <aside class="ac-side w-72 bg-white border-r border-slate-100 flex flex-col">
            <div class="p-3 border-b border-slate-100">
                <input id="acThreadFilter" type="search" placeholder="ค้นหาหัวข้อ..."
                    class="ds-input text-sm w-full" oninput="acRenderThreads()">
            </div>
            <div id="acThreadList" class="flex-1 overflow-y-auto px-2 py-2 space-y-1">
                <div class="text-center text-slate-400 text-xs py-8">กำลังโหลด...</div>
            </div>
            <div id="acThreadPager" class="border-t border-slate-100 p-2 text-xs text-slate-500 flex items-center justify-between">
                <span id="acThreadSummary">—</span>
                <div class="flex gap-0.5">
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="acThreadPage(1)" title="หน้าแรก">«</button>
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="acThreadPage(acState.page-1)" title="ก่อนหน้า">‹</button>
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="acThreadPage(acState.page+1)" title="ถัดไป">›</button>
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="acThreadPage(acState.pages)" title="สุดท้าย">»</button>
                </div>
            </div>
        </aside>

        <!-- Chat (right) -->
        <section class="ac-main flex-1 flex flex-col overflow-hidden">

            <!-- Thread header -->
            <div id="acChatHeader" class="px-5 py-3 border-b border-slate-100 bg-white flex items-center justify-between shrink-0">
                <div class="min-w-0">
                    <div id="acThreadTitle" class="text-sm font-black text-slate-700 truncate">เลือกแชทจากด้านซ้าย</div>
                    <div id="acThreadMeta" class="ac-subtitle text-slate-400 font-bold mt-0.5">หรือกด "เริ่มแชทใหม่"</div>
                </div>
                <div id="acThreadActions" class="flex items-center gap-1 hidden">
                    <button onclick="acExportThread()" class="btn-solid bg-slate-100 text-slate-600 text-xs" title="ดาวน์โหลด .txt">
                        <i class="fa-solid fa-download"></i>
                    </button>
                    <button onclick="acArchiveThread()" class="btn-solid bg-amber-100 text-amber-700 text-xs" title="เก็บถาวร">
                        <i class="fa-solid fa-box-archive"></i>
                    </button>
                    <button onclick="acDeleteThread()" class="btn-solid bg-rose-100 text-rose-700 text-xs" title="ลบ">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <div id="acMessages" class="flex-1 overflow-y-auto p-5 space-y-4 scroll-smooth">
                <div class="ac-empty text-center py-16 text-slate-400">
                    <i class="fa-solid fa-comments text-4xl mb-3 opacity-30"></i>
                    <div class="text-sm font-bold">ยังไม่มีแชท</div>
                    <div class="text-xs mt-1">เริ่มแชทใหม่หรือเลือกแชทเก่าจากด้านซ้าย</div>
                </div>
            </div>

            <!-- Input -->
            <div class="ac-input-bar p-3 bg-white border-t border-slate-100 shrink-0">
                <div class="flex gap-2 items-end max-w-4xl mx-auto">
                    <textarea id="acInput" rows="1" disabled maxlength="4000"
                        placeholder="<?= $apiKeySet ? 'พิมพ์คำถาม... (Enter=ส่ง · Shift+Enter=ขึ้นบรรทัด)' : 'ตั้งค่า API Key ก่อนใช้งาน' ?>"
                        class="ds-input flex-1 text-sm resize-none max-h-40"
                        oninput="this.style.height=''; this.style.height=Math.min(this.scrollHeight,160)+'px'"
                        onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); acSend();}"></textarea>
                    <button id="acSendBtn" onclick="acSend()" disabled class="ds-btn ds-btn-primary shrink-0">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
                <div class="ac-disclaimer mt-2 text-center max-w-4xl mx-auto text-slate-400">
                    <i class="fa-solid fa-shield-halved mr-1"></i>
                    ห้ามใส่ข้อมูลส่วนบุคคลของผู้ป่วย (ชื่อ-สกุล/HN/เลขบัตร) · AI อาจตอบผิด · ทุกบทสนทนาบันทึกเพื่อ audit
                </div>
            </div>

        </section>
    </div>
</div>

<style>
.ac-shell { min-height: 0; }
.ac-side { flex-shrink: 0; }
.ac-subtitle { font-size: 11px; }
.ac-disclaimer { font-size: 10px; }

.ac-thread-item {
    padding: 8px 10px; border-radius: 10px; cursor: pointer;
    border: 1.5px solid transparent;
    transition: background 0.15s, border-color 0.15s;
}
.ac-thread-item:hover { background: #f1f5f9; }
.ac-thread-item.active { background: rgba(168,85,247,.10); border-color: rgba(168,85,247,.35); }
.ac-thread-item .t-title { font-size: 13px; font-weight: 700; color: #334155; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.ac-thread-item .t-meta { font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 3px; display: flex; justify-content: space-between; }

.ac-msg-row { display: flex; gap: 12px; }
.ac-msg-row.is-admin { flex-direction: row-reverse; }
.ac-avatar { width: 32px; height: 32px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ac-avatar.bot { background: #1e293b; color: white; }
.ac-avatar.admin { background: #a855f7; color: white; }
.ac-bubble { max-width: 75%; padding: 12px 16px; border-radius: 18px; font-size: 14px; line-height: 1.55; }
.ac-bubble.bot { background: white; border: 1.5px solid #e2e8f0; color: #334155; border-top-left-radius: 4px; }
.ac-bubble.admin { background: #a855f7; color: white; border-top-right-radius: 4px; }
.ac-bubble-meta { font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 4px; padding: 0 4px; }
.ac-bubble-content > *:first-child { margin-top: 0; }
.ac-bubble-content > *:last-child { margin-bottom: 0; }
.ac-bubble-content h1, .ac-bubble-content h2, .ac-bubble-content h3 { font-weight: 800; margin: 10px 0 5px; }
.ac-bubble-content p { margin: 6px 0; }
.ac-bubble-content ul, .ac-bubble-content ol { padding-left: 22px; margin: 6px 0; }
.ac-bubble-content table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 13px; }
.ac-bubble-content th, .ac-bubble-content td { border: 1px solid #e2e8f0; padding: 6px 10px; }
.ac-bubble-content th { background: #f8fafc; font-weight: 800; }
.ac-bubble-content code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; font-family: ui-monospace, monospace; font-size: 12px; color: #ef4444; }

.ac-typing { display: inline-flex; gap: 4px; align-items: center; padding: 12px 16px; }
.ac-typing-dot { width: 7px; height: 7px; background: #a855f7; border-radius: 50%; animation: acBounce 0.6s infinite alternate; }
.ac-typing-dot:nth-child(2) { animation-delay: 0.15s; }
.ac-typing-dot:nth-child(3) { animation-delay: 0.3s; }
@keyframes acBounce { from { transform: translateY(0); opacity: 0.4; } to { transform: translateY(-6px); opacity: 1; } }

/* Mobile: stack columns */
@media (max-width: 768px) {
    .ac-side { width: 100%; max-height: 220px; border-right: none; border-bottom: 1.5px solid #e2e8f0; }
    .ac-body { flex-direction: column; }
}

/* DARK MODE — body[data-theme='dark'] */
body[data-theme='dark'] #section-admin_chat .ac-shell { background: rgba(15,23,42,.55); }
body[data-theme='dark'] #section-admin_chat .bg-white\/80 { background: rgba(15,23,42,.65) !important; }
body[data-theme='dark'] #section-admin_chat .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-admin_chat .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-admin_chat .bg-slate-50\/50 { background: rgba(148,163,184,.04) !important; }
body[data-theme='dark'] #section-admin_chat .bg-slate-100 { background: rgba(148,163,184,.14) !important; color:#cbd5e1 !important; }
body[data-theme='dark'] #section-admin_chat .border-slate-100 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-admin_chat .text-slate-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-admin_chat .text-slate-600 { color:#cbd5e1 !important; }
body[data-theme='dark'] #section-admin_chat .text-slate-500,
body[data-theme='dark'] #section-admin_chat .text-slate-400 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-admin_chat .ac-thread-item:hover { background: rgba(148,163,184,.10); }
body[data-theme='dark'] #section-admin_chat .ac-bubble.bot { background:#0f172a !important; border-color:#1e293b !important; color:#e2e8f0 !important; }
body[data-theme='dark'] #section-admin_chat .ac-bubble-content th { background: rgba(148,163,184,.14) !important; color:#e2e8f0; }
body[data-theme='dark'] #section-admin_chat .ac-bubble-content td,
body[data-theme='dark'] #section-admin_chat .ac-bubble-content th { border-color:#1e293b !important; }
body[data-theme='dark'] #section-admin_chat .ac-bubble-content code { background: rgba(148,163,184,.14) !important; color:#f87171 !important; }
body[data-theme='dark'] #section-admin_chat .bg-amber-50 { background: rgba(245,158,11,.18) !important; }
body[data-theme='dark'] #section-admin_chat .bg-amber-100 { background: rgba(245,158,11,.22) !important; }
body[data-theme='dark'] #section-admin_chat .bg-rose-100 { background: rgba(244,63,94,.22) !important; }
body[data-theme='dark'] #section-admin_chat .text-amber-700,
body[data-theme='dark'] #section-admin_chat .text-amber-900 { color:#fcd34d !important; }
body[data-theme='dark'] #section-admin_chat .text-rose-700 { color:#fda4af !important; }
</style>

<script>
const AC_CSRF = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
const acState = { threads: [], filtered: [], page: 1, perPage: 20, pages: 1, total: 0, currentId: 0, currentThread: null, sending: false };

const acEsc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => (
    { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

const acRenderMarkdown = src => {
    if (!window.marked || !window.DOMPurify) return acEsc(src).replace(/\n/g, '<br>');
    return DOMPurify.sanitize(marked.parse(String(src || '')));
};

function acAjax(entity, action, payload = null, queryParams = null) {
    const qs = new URLSearchParams({ entity, action });
    if (queryParams) for (const k in queryParams) qs.set(k, queryParams[k]);
    const url = `ajax_admin_chat.php?${qs.toString()}`;
    const opts = { method: payload ? 'POST' : 'GET' };
    if (payload) {
        const fd = new FormData();
        fd.append('csrf_token', AC_CSRF);
        for (const k in payload) fd.append(k, payload[k]);
        opts.body = fd;
    }
    return fetch(url, opts).then(r => r.json());
}

async function acLoadThreads() {
    try {
        const res = await acAjax('thread', 'list', null, { page: acState.page, per_page: acState.perPage });
        if (!res.ok) throw new Error(res.message || 'list failed');
        acState.threads = res.data.rows || [];
        acState.pages = res.data.pages || 1;
        acState.total = res.data.total || 0;
        acRenderThreads();
    } catch (e) {
        document.getElementById('acThreadList').innerHTML = `<div class="text-rose-500 text-xs p-3">โหลดไม่สำเร็จ: ${acEsc(e.message)}</div>`;
    }
}

function acRenderThreads() {
    const filter = (document.getElementById('acThreadFilter').value || '').toLowerCase();
    let list = acState.threads;
    if (filter) list = list.filter(t => ((t.title || '').toLowerCase().includes(filter)));
    acState.filtered = list;

    const box = document.getElementById('acThreadList');
    if (list.length === 0) {
        box.innerHTML = '<div class="text-center text-slate-400 text-xs py-8">ไม่มีแชท</div>';
    } else {
        box.innerHTML = list.map(t => {
            const active = (t.id == acState.currentId) ? ' active' : '';
            const updated = acEsc((t.updated_at || '').slice(5, 16).replace('T', ' '));
            const title = acEsc(t.title || 'แชทใหม่');
            const count = (parseInt(t.message_count, 10) || 0);
            return `<div class="ac-thread-item${active}" onclick="acOpenThread(${parseInt(t.id, 10)})">
                <div class="t-title">${title}</div>
                <div class="t-meta"><span>${updated}</span><span>${count} ข้อความ</span></div>
            </div>`;
        }).join('');
    }
    document.getElementById('acThreadSummary').textContent =
        `หน้า ${acState.page}/${acState.pages} · ${acState.total} แชท`;
}

function acThreadPage(p) {
    p = Math.max(1, Math.min(acState.pages, p));
    if (p === acState.page) return;
    acState.page = p;
    acLoadThreads();
}

async function acNewThread() {
    try {
        const res = await acAjax('thread', 'create', { title: '' });
        if (!res.ok) throw new Error(res.message);
        await acLoadThreads();
        acOpenThread(res.thread_id);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'สร้างแชทใหม่ไม่สำเร็จ', text: e.message });
    }
}

async function acOpenThread(threadId) {
    acState.currentId = threadId;
    acRenderThreads();
    document.getElementById('acThreadActions').classList.remove('hidden');
    document.getElementById('acInput').disabled = false;
    document.getElementById('acSendBtn').disabled = false;
    document.getElementById('acInput').focus();

    try {
        const r = await acAjax('thread', 'get', null, { thread_id: threadId });
        if (!r.ok) throw new Error(r.message);
        acState.currentThread = r.data;
        document.getElementById('acThreadTitle').textContent = r.data.title || 'แชทใหม่';
        document.getElementById('acThreadMeta').textContent =
            `${r.data.message_count} ข้อความ · เริ่ม ${(r.data.created_at || '').slice(0, 16).replace('T', ' ')}`;
        acRenderMessages(r.data.messages || []);
    } catch (e) {
        document.getElementById('acMessages').innerHTML =
            `<div class="text-rose-500 text-sm p-4">เปิด thread ไม่สำเร็จ: ${acEsc(e.message)}</div>`;
    }
}

function acRenderMessages(messages) {
    const box = document.getElementById('acMessages');
    if (!messages || messages.length === 0) {
        box.innerHTML = `<div class="ac-empty text-center py-12 text-slate-400">
            <i class="fa-solid fa-robot text-3xl mb-2 opacity-40"></i>
            <div class="text-sm font-bold">เริ่มถามได้เลย</div>
            <div class="text-xs mt-1">ลองถาม: "สรุปรายรับเดือนนี้" หรือ "วันนี้คลินิกเปิดไหม"</div>
        </div>`;
        return;
    }
    box.innerHTML = messages.map(m => acMsgHtml(m)).join('');
    box.scrollTop = box.scrollHeight;
}

function acMsgHtml(m) {
    const isAdmin = m.role === 'admin';
    const time = acEsc((m.created_at || '').slice(11, 16));
    const rawContent = (m.content || '').toString();
    const content = isAdmin
        ? `<div class="ac-bubble-content">${acEsc(rawContent).replace(/\n/g, '<br>')}</div>`
        : `<div class="ac-bubble-content">${acRenderMarkdown(rawContent)}</div>`;
    const model = acEsc(m.model || 'AI');
    const elapsed = (parseInt(m.elapsed_ms, 10) || 0);
    const meta = isAdmin
        ? `<div class="ac-bubble-meta text-right">${time}</div>`
        : `<div class="ac-bubble-meta">${model} · ${elapsed ? elapsed + ' ms · ' : ''}${time}</div>`;
    const avatar = isAdmin
        ? `<div class="ac-avatar admin"><i class="fa-solid fa-user"></i></div>`
        : `<div class="ac-avatar bot"><i class="fa-solid fa-robot"></i></div>`;
    return `<div class="ac-msg-row ${isAdmin ? 'is-admin' : ''}">
        ${avatar}
        <div class="min-w-0">
            <div class="ac-bubble ${isAdmin ? 'admin' : 'bot'}">${content}</div>
            ${meta}
        </div>
    </div>`;
}

async function acSend() {
    if (acState.sending) return;
    const input = document.getElementById('acInput');
    const text = input.value.trim();
    if (!text) return;
    if (acState.currentId <= 0) { await acNewThread(); }

    acState.sending = true;
    input.value = ''; input.style.height = '';
    document.getElementById('acSendBtn').disabled = true;

    // Optimistic admin message + typing indicator
    const msgs = document.getElementById('acMessages');
    if (msgs.querySelector('.ac-empty')) msgs.innerHTML = '';
    msgs.insertAdjacentHTML('beforeend', acMsgHtml({ role: 'admin', content: text, created_at: new Date().toISOString() }));
    msgs.insertAdjacentHTML('beforeend', `<div class="ac-msg-row" id="acTypingRow">
        <div class="ac-avatar bot"><i class="fa-solid fa-robot"></i></div>
        <div><div class="ac-bubble bot"><div class="ac-typing"><div class="ac-typing-dot"></div><div class="ac-typing-dot"></div><div class="ac-typing-dot"></div></div></div></div>
    </div>`);
    msgs.scrollTop = msgs.scrollHeight;

    try {
        const res = await acAjax('message', 'send', { thread_id: acState.currentId, message: text });
        document.getElementById('acTypingRow')?.remove();
        if (!res.ok) throw new Error(res.message);
        // Append assistant message directly from response (no refetch flash)
        msgs.insertAdjacentHTML('beforeend', acMsgHtml({
            role: 'assistant',
            content: res.data.answer,
            model: res.data.model,
            elapsed_ms: res.data.elapsed_ms,
            created_at: new Date().toISOString(),
        }));
        msgs.scrollTop = msgs.scrollHeight;
        // Refresh thread list to bump updated_at + title
        await acLoadThreads();
    } catch (e) {
        document.getElementById('acTypingRow')?.remove();
        msgs.insertAdjacentHTML('beforeend',
            `<div class="text-rose-500 text-xs p-3 text-center">เกิดข้อผิดพลาด: ${acEsc(e.message)}</div>`);
    } finally {
        acState.sending = false;
        document.getElementById('acSendBtn').disabled = false;
        input.focus();
    }
}

async function acArchiveThread() {
    if (!acState.currentId) return;
    const { isConfirmed } = await Swal.fire({
        title: 'เก็บถาวรแชทนี้?',
        text: 'แชทจะถูกซ่อนจากรายการหลัก แต่ยังเก็บประวัติไว้',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'เก็บถาวร',
        cancelButtonText: 'ยกเลิก'
    });
    if (!isConfirmed) return;
    const res = await acAjax('thread', 'archive', { thread_id: acState.currentId, archive: 1 });
    if (res.ok) { acState.currentId = 0; await acLoadThreads(); acResetView(); }
    else Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message });
}

async function acDeleteThread() {
    if (!acState.currentId) return;
    const { isConfirmed } = await Swal.fire({
        title: 'ลบแชทนี้ถาวร?',
        text: 'ข้อความทั้งหมดในแชทนี้จะหายไป — กู้คืนไม่ได้',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e11d48'
    });
    if (!isConfirmed) return;
    const res = await acAjax('thread', 'delete', { thread_id: acState.currentId });
    if (res.ok) { acState.currentId = 0; await acLoadThreads(); acResetView(); }
    else Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message });
}

function acExportThread() {
    if (!acState.currentThread) return;
    const t = acState.currentThread;
    const lines = [`# ${t.title || 'แชท'}`, `สร้าง: ${t.created_at}`, ''];
    for (const m of (t.messages || [])) {
        lines.push(`[${m.created_at}] ${m.role === 'admin' ? 'ADMIN' : 'AI'}: ${m.content}`);
        lines.push('');
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `chat-${t.id}-${(t.created_at || '').slice(0, 10)}.txt`;
    a.click();
    URL.revokeObjectURL(a.href);
}

function acResetView() {
    document.getElementById('acThreadTitle').textContent = 'เลือกแชทจากด้านซ้าย';
    document.getElementById('acThreadMeta').textContent = 'หรือกด "เริ่มแชทใหม่"';
    document.getElementById('acThreadActions').classList.add('hidden');
    document.getElementById('acInput').disabled = true;
    document.getElementById('acSendBtn').disabled = true;
    document.getElementById('acMessages').innerHTML = `<div class="ac-empty text-center py-16 text-slate-400">
        <i class="fa-solid fa-comments text-4xl mb-3 opacity-30"></i>
        <div class="text-sm font-bold">ยังไม่มีแชท</div>
        <div class="text-xs mt-1">เริ่มแชทใหม่หรือเลือกแชทเก่าจากด้านซ้าย</div>
    </div>`;
}

// Init when section becomes visible
function acInit() {
    if (window.__acInit) return;
    window.__acInit = true;
    acLoadThreads();
}

// Observe section visibility (portal switchSection sets display)
(function() {
    const sec = document.getElementById('section-admin_chat');
    if (!sec) return;
    const obs = new MutationObserver(() => {
        if (sec.style.display !== 'none' && sec.offsetParent !== null) acInit();
    });
    obs.observe(sec, { attributes: true, attributeFilter: ['style'] });
    if (sec.style.display !== 'none') acInit();
})();
</script>
