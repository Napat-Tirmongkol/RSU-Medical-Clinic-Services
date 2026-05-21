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
        <div>
            <div class="sec-title" style="margin-bottom:2px">
                <div class="w-8 h-8 rounded-lg bg-emerald-500 flex items-center justify-center text-white shadow-lg shadow-emerald-200 mr-1" style="font-size:12px">
                    <i class="fa-brands fa-line"></i>
                </div>
                LINE Chat (ตอบกลับผู้ใช้ LINE)
            </div>
            <p class="lc-subtitle text-slate-500 font-bold uppercase tracking-wider ml-11">รายการบทสนทนา · admin ตอบกลับ · บันทึก audit</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="lcReload()" class="btn-solid bg-slate-100 text-slate-600 text-xs" title="รีโหลด">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>
    </div>

    <!-- Filter chips -->
    <div class="px-5 py-3 border-b border-slate-100 bg-white flex items-center gap-2 overflow-x-auto no-scrollbar shrink-0">
        <button class="lc-chip is-active" data-filter="all" onclick="lcSetFilter('all', this)">ทั้งหมด</button>
        <button class="lc-chip" data-filter="needs_reply" onclick="lcSetFilter('needs_reply', this)">ต้องตอบ</button>
        <button class="lc-chip" data-filter="today" onclick="lcSetFilter('today', this)">วันนี้</button>
    </div>

    <!-- Body -->
    <div class="lc-body flex flex-1 overflow-hidden">

        <!-- Conversation list -->
        <aside class="lc-side w-80 bg-white border-r border-slate-100 flex flex-col">
            <div id="lcConvoList" class="flex-1 overflow-y-auto px-2 py-2 space-y-1">
                <div class="text-center text-slate-400 text-xs py-8">กำลังโหลด...</div>
            </div>
            <div id="lcPager" class="border-t border-slate-100 p-2 text-xs text-slate-500 flex items-center justify-between">
                <span id="lcPagerSummary">—</span>
                <div class="flex gap-0.5">
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="lcPage(1)" title="หน้าแรก">«</button>
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="lcPage(lcState.page-1)" title="ก่อนหน้า">‹</button>
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="lcPage(lcState.page+1)" title="ถัดไป">›</button>
                    <button class="btn-solid bg-slate-100 text-slate-600 text-xs px-2 py-0.5" onclick="lcPage(lcState.pages)" title="สุดท้าย">»</button>
                </div>
            </div>
        </aside>

        <!-- Conversation view -->
        <section class="lc-main flex-1 flex flex-col overflow-hidden">

            <!-- Convo header -->
            <div id="lcConvoHeader" class="px-5 py-3 border-b border-slate-100 bg-white flex items-center justify-between shrink-0">
                <div class="min-w-0">
                    <div id="lcConvoTitle" class="text-sm font-black text-slate-700 truncate">เลือกบทสนทนา</div>
                    <div id="lcConvoMeta" class="lc-subtitle text-slate-400 font-bold mt-0.5">หรือคลิก "รีโหลด" ดูบทสนทนาใหม่</div>
                </div>
            </div>

            <!-- Messages -->
            <div id="lcMessages" class="flex-1 overflow-y-auto p-5 space-y-3 scroll-smooth">
                <div class="lc-empty text-center py-16 text-slate-400">
                    <i class="fa-brands fa-line text-5xl mb-3 text-emerald-300"></i>
                    <div class="text-sm font-bold">ยังไม่ได้เลือกบทสนทนา</div>
                    <div class="text-xs mt-1">เลือกผู้ใช้จากด้านซ้ายเพื่อดูข้อความ</div>
                </div>
            </div>

            <!-- Reply input -->
            <div class="lc-input-bar p-3 bg-white border-t border-slate-100 shrink-0">
                <div class="flex gap-2 items-end max-w-4xl mx-auto">
                    <textarea id="lcReplyInput" rows="1" disabled maxlength="4000"
                        placeholder="<?= $lineTokenSet ? 'พิมพ์ข้อความตอบกลับ LINE user...' : 'ตั้งค่า LINE token ก่อนใช้งาน' ?>"
                        class="ds-input flex-1 text-sm resize-none max-h-40"
                        oninput="this.style.height=''; this.style.height=Math.min(this.scrollHeight,160)+'px'"
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
</div>

<style>
.lc-shell { min-height: 0; }
.lc-side { flex-shrink: 0; }
.lc-subtitle { font-size: 11px; }
.lc-disclaimer { font-size: 10px; }
.lc-send-btn { background: #06c755 !important; color: white !important; }
.lc-send-btn:hover:not(:disabled) { background: #05a847 !important; }
.lc-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.lc-chip {
    padding: 5px 14px; border-radius: 999px;
    background: #f1f5f9; color: #475569;
    font-size: 12px; font-weight: 700;
    border: 1.5px solid transparent;
    cursor: pointer; transition: all 0.15s;
    white-space: nowrap;
}
.lc-chip:hover { background: #e2e8f0; }
.lc-chip.is-active {
    background: linear-gradient(135deg, #06c755, #00b900);
    color: white;
    box-shadow: 0 2px 8px rgba(6,199,85,.25);
}

.lc-convo-item {
    padding: 10px 12px; border-radius: 10px; cursor: pointer;
    border: 1.5px solid transparent;
    transition: background 0.15s, border-color 0.15s;
    position: relative;
}
.lc-convo-item:hover { background: #f1f5f9; }
.lc-convo-item.active { background: rgba(6,199,85,.08); border-color: rgba(6,199,85,.30); }
.lc-convo-item .c-name { font-size: 13px; font-weight: 800; color: #334155; display: flex; align-items: center; gap: 6px; }
.lc-convo-item .c-uid  { font-size: 10px; color: #94a3b8; font-family: ui-monospace, monospace; font-weight: 600; }
.lc-convo-item .c-preview { font-size: 12px; color: #64748b; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; }
.lc-convo-item .c-meta { font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 4px; display: flex; justify-content: space-between; }
.lc-convo-item .c-badge { background: #ef4444; color: white; font-size: 9px; font-weight: 900; padding: 1px 6px; border-radius: 999px; }

.lc-msg-row { display: flex; gap: 10px; align-items: flex-end; }
.lc-msg-row.is-outbound { flex-direction: row-reverse; }
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

.lc-time-divider { text-align: center; padding: 10px 0; }
.lc-time-divider span { background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 700; padding: 3px 12px; border-radius: 999px; }

@media (max-width: 768px) {
    .lc-side { width: 100%; max-height: 220px; border-right: none; border-bottom: 1.5px solid #e2e8f0; }
    .lc-body { flex-direction: column; }
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
</style>

<script>
const LC_CSRF = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
const lcState = { conversations: [], page: 1, perPage: 20, pages: 1, total: 0, filter: 'all', currentUid: '', currentConvo: null, sending: false };

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

async function lcLoadConvos() {
    try {
        const res = await lcAjax('conversation', 'list', null, {
            filter: lcState.filter, page: lcState.page, per_page: lcState.perPage,
        });
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

function lcRenderConvos() {
    const box = document.getElementById('lcConvoList');
    const list = lcState.conversations;
    if (list.length === 0) {
        box.innerHTML = '<div class="text-center text-slate-400 text-xs py-8">ไม่พบบทสนทนา</div>';
    } else {
        box.innerHTML = list.map(c => {
            const uid = String(c.line_user_id || '');
            const active = (uid === lcState.currentUid) ? ' active' : '';
            const uidShort = lcEsc(uid.slice(0, 12) + '…');
            const name = lcEsc(c.line_display_name || 'LINE User');
            const lastMsg = lcEsc((c.last_msg_text || '').slice(0, 80));
            const dirIcon = c.last_msg_direction === 'inbound'
                ? '<i class="fa-solid fa-arrow-down text-slate-400 mr-1"></i>'
                : '<i class="fa-solid fa-arrow-up text-emerald-500 mr-1"></i>';
            const time = lcEsc((c.last_msg_at || '').slice(5, 16).replace('T', ' '));
            const badge = parseInt(c.needs_reply, 10) ? '<span class="c-badge">ต้องตอบ</span>' : '';
            return `<div class="lc-convo-item${active}" data-uid="${lcEsc(uid)}">
                <div class="c-name"><i class="fa-brands fa-line text-emerald-500"></i>${name} ${badge}</div>
                <div class="c-uid">${uidShort}</div>
                <div class="c-preview">${dirIcon}${lastMsg}</div>
                <div class="c-meta"><span>${time}</span><span>${parseInt(c.total_msgs, 10) || 0} ข้อความ</span></div>
            </div>`;
        }).join('');
    }
    document.getElementById('lcPagerSummary').textContent =
        `หน้า ${lcState.page}/${lcState.pages} · ${lcState.total} บทสนทนา`;
}

async function lcOpenConvo(lineUserId) {
    lcState.currentUid = lineUserId;
    lcRenderConvos();
    document.getElementById('lcReplyInput').disabled = false;
    document.getElementById('lcSendBtn').disabled = false;
    document.getElementById('lcReplyInput').focus();

    try {
        const res = await lcAjax('conversation', 'get', null, { line_user_id: lineUserId, limit: 200 });
        if (!res.ok) throw new Error(res.message);
        lcState.currentConvo = res.data;
        const name = res.data.line_display_name || 'LINE User';
        document.getElementById('lcConvoTitle').textContent = name;
        document.getElementById('lcConvoMeta').textContent = lineUserId;
        lcRenderMessages(res.data.messages || []);
    } catch (e) {
        document.getElementById('lcMessages').innerHTML =
            `<div class="text-rose-500 text-sm p-4">โหลดบทสนทนาไม่สำเร็จ: ${lcEsc(e.message)}</div>`;
    }
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
    const content = `<pre>${lcEsc(m.message_text || '')}</pre>`;
    const meta = [];
    const senderLabel = { user: 'User', admin: 'Admin', ai: 'AI', system: 'System' }[bubbleClass];
    if (senderLabel) meta.push(senderLabel);
    meta.push(time);
    if (isOutbound && m.push_ok !== null && parseInt(m.push_ok, 10) === 0) {
        meta.push('<span class="lc-fail">⚠ ส่งไม่สำเร็จ</span>');
    }
    return `<div class="lc-msg-row ${isOutbound ? 'is-outbound' : ''}">
        <div class="lc-avatar ${bubbleClass}">${avatarIcon}</div>
        <div class="min-w-0">
            <div class="lc-bubble ${bubbleClass}">${content}</div>
            <div class="lc-bubble-meta ${isOutbound ? 'text-right' : ''}">${meta.join(' · ')}</div>
        </div>
    </div>`;
}

async function lcSendReply() {
    if (lcState.sending) return;
    if (!lcState.currentUid) return;
    const input = document.getElementById('lcReplyInput');
    const text = input.value.trim();
    if (!text) return;

    lcState.sending = true;
    input.value = ''; input.style.height = '';
    document.getElementById('lcSendBtn').disabled = true;

    // Optimistic UI
    const msgs = document.getElementById('lcMessages');
    msgs.insertAdjacentHTML('beforeend', lcMsgHtml({
        direction: 'outbound', sender_type: 'admin', message_text: text,
        created_at: new Date().toISOString(),
    }));
    msgs.scrollTop = msgs.scrollHeight;

    try {
        const res = await lcAjax('message', 'send_reply', {
            line_user_id: lcState.currentUid, message: text,
        });
        if (!res.ok) throw new Error(res.message);
        // Refresh conversation + list to reflect push_ok status from DB
        await lcOpenConvo(lcState.currentUid);
        await lcLoadConvos();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: e.message });
        // Refresh anyway to show the failed push attempt log
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

// Init on section visible
function lcInit() {
    if (window.__lcInit) return;
    window.__lcInit = true;
    // Delegated click on convo list (data-uid avoids onclick string interpolation risk)
    document.getElementById('lcConvoList').addEventListener('click', (ev) => {
        const item = ev.target.closest('.lc-convo-item');
        if (item && item.dataset.uid) lcOpenConvo(item.dataset.uid);
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
