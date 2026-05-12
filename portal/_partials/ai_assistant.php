<?php
// portal/_partials/ai_assistant.php — Native AI Assistant UI
$apiKeySet = defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY);
?>

<!-- marked.js for Markdown rendering -->
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>

<div class="ai-assistant-container flex flex-col h-full bg-slate-50/50">
    
    <?php if (!$apiKeySet): ?>
    <!-- API KEY WARNING -->
    <div class="m-6 p-6 bg-amber-50 border border-amber-200 rounded-3xl flex items-start gap-4 animate-in fade-in slide-in-from-top-4">
        <div class="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center text-amber-600 text-xl flex-shrink-0">
            <i class="fa-solid fa-key"></i>
        </div>
        <div>
            <h3 class="text-base font-black text-amber-900 leading-tight">ยังไม่ได้ตั้งค่า API Key</h3>
            <p class="text-sm text-amber-700 mt-1 font-medium">กรุณาไปที่หน้า <a href="javascript:switchSection('settings')" class="font-black underline decoration-2 underline-offset-2">Settings</a> เพื่อกรอก Gemini API Key ก่อนใช้งานครับ</p>
            <div class="mt-4 flex gap-3">
                <a href="https://aistudio.google.com/app/apikey" target="_blank" class="px-4 py-2 bg-amber-600 text-white rounded-xl text-xs font-black shadow-lg shadow-amber-200">รับ API Key ฟรี</a>
                <button onclick="switchSection('settings')" class="px-4 py-2 bg-white border border-amber-200 text-amber-700 rounded-xl text-xs font-black">ไปที่หน้าตั้งค่า</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Standardized Section Header -->
    <div class="px-5 md:px-8 py-5 border-b border-slate-100 bg-white/80 backdrop-blur-md flex items-center justify-between shrink-0 au">
        <div>
            <div class="sec-title" style="margin-bottom:2px">
                <div class="w-8 h-8 rounded-lg bg-purple-600 flex items-center justify-center text-white shadow-lg shadow-purple-200 mr-1" style="font-size:12px">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </div>
                AI Data Assistant
            </div>
            <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider ml-11">Powered by Gemini AI</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-600 rounded-xl border border-emerald-100">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-[10px] font-black uppercase tracking-widest">System Active</span>
            </div>
        </div>
    </div>

    <!-- Messages Area -->
    <div id="aiChatMessages" class="flex-1 overflow-y-auto p-6 space-y-6 scroll-smooth">
        <!-- AI Welcome -->
        <div class="flex gap-4 group">
            <div class="w-9 h-9 rounded-xl bg-slate-800 flex items-center justify-center text-white flex-shrink-0">
                <i class="fa-solid fa-robot text-sm"></i>
            </div>
            <div class="space-y-2 max-w-[85%]">
                <div class="bg-white border border-slate-200 p-4 rounded-2xl rounded-tl-none shadow-sm text-sm text-slate-700 leading-relaxed">
                    <strong>สวัสดีครับ! ผม AI Assistant</strong> 👋<br>
                    ผมสามารถช่วยคุณวิเคราะห์ข้อมูลแคมเปญ สรุปยอดจอง หรือตรวจสอบปัญหาต่างๆ ในระบบได้แบบเรียลไทม์<br><br>
                    ลองถามผมดูนะครับ เช่น <em>"สรุปแคมเปญ 5 อันดับแรกที่มีคนจองเยอะที่สุด"</em> หรือ <em>"วิเคราะห์ Error Logs ล่าสุดให้หน่อย"</em>
                </div>
                <div class="text-[10px] text-slate-400 font-bold ml-1">SYSTEM ASSISTANT</div>
            </div>
        </div>
    </div>

    <!-- Suggestions/Chips -->
    <div class="px-6 py-3 bg-slate-50 border-t border-slate-200 overflow-x-auto no-scrollbar flex gap-2" id="aiSuggestions">
        <button onclick="aiSend('สรุปแคมเปญยอดนิยม')" class="whitespace-nowrap px-4 py-1.5 bg-white border border-slate-200 rounded-full text-[12px] font-bold text-slate-600 hover:border-purple-400 hover:text-purple-600 transition-all shadow-sm">
            <i class="fa-solid fa-chart-line mr-1 text-purple-500"></i> สรุปแคมเปญยอดนิยม
        </button>
        <button onclick="aiSend('วิเคราะห์การยกเลิกจอง')" class="whitespace-nowrap px-4 py-1.5 bg-white border border-slate-200 rounded-full text-[12px] font-bold text-slate-600 hover:border-purple-400 hover:text-purple-600 transition-all shadow-sm">
            <i class="fa-solid fa-user-minus mr-1 text-purple-500"></i> วิเคราะห์การยกเลิก
        </button>
        <button onclick="aiSend('ตรวจสอบ Error Logs')" class="whitespace-nowrap px-4 py-1.5 bg-white border border-slate-200 rounded-full text-[12px] font-bold text-slate-600 hover:border-purple-400 hover:text-purple-600 transition-all shadow-sm">
            <i class="fa-solid fa-bug mr-1 text-purple-500"></i> ตรวจสอบ Error
        </button>
    </div>

    <!-- Input Area -->
    <div class="p-4 bg-white border-t border-slate-200">
        <div class="max-w-4xl mx-auto flex gap-3 items-end">
            <div class="flex-1 relative">
                <textarea id="aiChatInput" rows="1" 
                    placeholder="<?= $apiKeySet ? 'พิมพ์คำถามของคุณที่นี่...' : 'กรุณาตั้งค่า API Key ก่อนใช้งาน' ?>"
                    class="w-full pl-5 pr-12 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-[14px] font-medium text-slate-800 outline-none focus:bg-white focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10 transition-all resize-none max-h-40"
                    onkeydown="if(event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); aiSendMessage(); }"
                    oninput="this.style.height = ''; this.style.height = Math.min(this.scrollHeight, 160) + 'px'"
                    <?= !$apiKeySet ? 'disabled' : '' ?>></textarea>
            </div>
            <button onclick="aiSendMessage()" id="aiSendBtn" <?= !$apiKeySet ? 'disabled' : '' ?>
                class="w-12 h-12 bg-purple-600 hover:bg-purple-700 text-white rounded-2xl shadow-lg shadow-purple-200 transition-all flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>

<style>
/* Feedback bar */
.ai-feedback-bar { display:flex; align-items:center; gap:6px; margin-top:4px; margin-left:4px; }
.ai-fb-btn { display:inline-flex; align-items:center; gap:3px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; border:1.5px solid #e2e8f0; background:#fff; cursor:pointer; color:#64748b; transition:all .15s; }
.ai-fb-btn:hover       { border-color:#94a3b8; background:#f8fafc; }
.ai-fb-btn.fb-up.selected   { background:#dcfce7; border-color:#86efac; color:#16a34a; }
.ai-fb-btn.fb-down.selected { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }
.ai-fb-label { font-size:10px; color:#94a3b8; font-weight:600; }
.ai-fb-comment-wrap { margin-top:6px; margin-left:4px; display:none; }
.ai-fb-comment-wrap.show { display:flex; gap:6px; align-items:center; }
.ai-fb-comment-input { flex:1; padding:4px 10px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:12px; background:#fff; outline:none; }
.ai-fb-comment-input:focus { border-color:#9333ea; }
.ai-fb-done { font-size:10px; color:#64748b; font-weight:600; display:flex; align-items:center; gap:4px; }

/* Markdown styles within bubbles */
.ai-bubble-content h1, .ai-bubble-content h2 { font-weight: 800; margin: 10px 0 5px; }
.ai-bubble-content p { margin: 5px 0; }
.ai-bubble-content ul, .ai-bubble-content ol { padding-left: 20px; margin: 5px 0; }
.ai-bubble-content table { width: 100%; border-collapse: collapse; margin: 10px 0; border-radius: 8px; overflow: hidden; font-size: 12px; }
.ai-bubble-content th, .ai-bubble-content td { border: 1px solid #e2e8f0; padding: 8px 12px; }
.ai-bubble-content th { background: #f8fafc; font-weight: 800; color: #475569; }
.ai-bubble-content code { background: #f1f5f9; padding: 2px 4px; border-radius: 4px; font-family: monospace; font-size: 11px; color: #ef4444; }

.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

.typing-dot { width: 6px; height: 6px; background: #8b5cf6; border-radius: 50%; animation: bounce 0.5s infinite alternate; }
.typing-dot:nth-child(2) { animation-delay: 0.1s; }
.typing-dot:nth-child(3) { animation-delay: 0.2s; }
@keyframes bounce { from { transform: translateY(0); opacity: 0.4; } to { transform: translateY(-5px); opacity: 1; } }
</style>

<script>
const aiMsgContainer = document.getElementById('aiChatMessages');
const aiInput = document.getElementById('aiChatInput');
const aiSendBtn = document.getElementById('aiSendBtn');

marked.setOptions({ breaks: true, gfm: true });

function aiScrollToBottom() {
    aiMsgContainer.scrollTop = aiMsgContainer.scrollHeight;
}

// pendingQuestion เก็บ question ล่าสุดเพื่อแนบกับ feedback
let _pendingQuestion = '';

function aiAppendMessage(role, content, msgId) {
    const div = document.createElement('div');
    div.className = `flex gap-4 ${role === 'user' ? 'flex-row-reverse' : ''} group animate-in slide-in-from-bottom-2 duration-300`;

    const iconClass = role === 'user' ? 'bg-purple-100 text-purple-600' : 'bg-slate-800 text-white';
    const icon = role === 'user' ? '<i class="fa-solid fa-user text-sm"></i>' : '<i class="fa-solid fa-robot text-sm"></i>';
    const bubbleClass = role === 'user'
        ? 'bg-purple-600 text-white rounded-tr-none shadow-purple-100'
        : 'bg-white border border-slate-200 text-slate-700 rounded-tl-none';

    const label = role === 'user' ? 'YOU' : 'AI ASSISTANT';
    const feedbackBar = role === 'ai' ? `
        <div class="ai-feedback-bar" data-msg-id="${msgId||''}" data-answer="">
            <span class="ai-fb-label">คำตอบนี้เป็นประโยชน์ไหม?</span>
            <button type="button" class="ai-fb-btn fb-up" title="มีประโยชน์">
                <i class="fa-regular fa-thumbs-up"></i>
            </button>
            <button type="button" class="ai-fb-btn fb-down" title="ไม่มีประโยชน์">
                <i class="fa-regular fa-thumbs-down"></i>
            </button>
        </div>
        <div class="ai-fb-comment-wrap">
            <input type="text" class="ai-fb-comment-input" placeholder="บอกเราได้ว่าผิดตรงไหน (ไม่บังคับ)" maxlength="200">
            <button type="button" class="ai-fb-send px-3 py-1 bg-slate-700 text-white text-xs font-bold rounded-lg hover:bg-slate-900">ส่ง</button>
        </div>` : '';

    div.innerHTML = `
        <div class="w-9 h-9 rounded-xl ${iconClass} flex items-center justify-center flex-shrink-0">
            ${icon}
        </div>
        <div class="space-y-1 max-w-[85%] ${role === 'user' ? 'text-right' : ''}">
            <div class="${bubbleClass} p-4 rounded-2xl shadow-sm text-sm leading-relaxed ai-bubble-content ${role === 'user' ? 'text-left' : ''}">
                ${role === 'user' ? content : marked.parse(content)}
            </div>
            <div class="text-[10px] text-slate-400 font-bold ml-1">${label}</div>
            ${feedbackBar}
        </div>
    `;

    aiMsgContainer.appendChild(div);
    aiScrollToBottom();

    // bind feedback buttons
    if (role === 'ai') {
        const bar      = div.querySelector('.ai-feedback-bar');
        const cmtWrap  = div.querySelector('.ai-fb-comment-wrap');
        const cmtInput = div.querySelector('.ai-fb-comment-input');
        const sendBtn  = div.querySelector('.ai-fb-send');
        const upBtn    = div.querySelector('.ai-fb-btn.fb-up');
        const downBtn  = div.querySelector('.ai-fb-btn.fb-down');

        // store raw answer text for submission
        bar.dataset.answer = content;

        function setRating(rating) {
            upBtn.classList.toggle('selected',   rating ===  1);
            downBtn.classList.toggle('selected', rating === -1);
            bar.dataset.rating = String(rating);
            if (rating === -1) {
                cmtWrap.classList.add('show');
            } else {
                cmtWrap.classList.remove('show');
                // submit immediately on 👍
                submitFeedback(rating, '');
            }
        }

        upBtn.addEventListener('click',   () => setRating( 1));
        downBtn.addEventListener('click', () => setRating(-1));

        sendBtn.addEventListener('click', () => {
            const r = parseInt(bar.dataset.rating || '0', 10);
            if (!r) return;
            submitFeedback(r, cmtInput.value.trim());
        });
        cmtInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); sendBtn.click(); }
        });

        async function submitFeedback(rating, comment) {
            const csrf = document.getElementById('global_csrf_token')?.value || '';
            const fd   = new FormData();
            fd.append('action',   'save_rating');
            fd.append('rating',   String(rating));
            fd.append('msg_id',   bar.dataset.msgId || '');
            fd.append('question', _pendingQuestion);
            fd.append('answer',   bar.dataset.answer);
            fd.append('comment',  comment);
            fd.append('source',   'portal_chat');
            fd.append('csrf_token', csrf);
            try {
                await fetch('ajax_ai_feedback.php', { method: 'POST', body: fd });
            } catch (_) {}
            // replace feedback bar with "ขอบคุณ" note
            cmtWrap.classList.remove('show');
            bar.innerHTML = `<span class="ai-fb-done"><i class="fa-solid fa-check-circle text-emerald-500"></i> ขอบคุณสำหรับ feedback ครับ</span>`;
        }
    }
}

function aiShowTyping() {
    const div = document.createElement('div');
    div.id = 'aiTyping';
    div.className = 'flex gap-4 group';
    div.innerHTML = `
        <div class="w-9 h-9 rounded-xl bg-slate-800 flex items-center justify-center text-white flex-shrink-0">
            <i class="fa-solid fa-robot text-sm"></i>
        </div>
        <div class="bg-white border border-slate-200 px-5 py-3 rounded-2xl rounded-tl-none flex items-center gap-2 shadow-sm">
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
            <span class="text-[11px] font-bold text-slate-400 ml-2 uppercase tracking-widest">Gemini Thinking</span>
        </div>
    `;
    aiMsgContainer.appendChild(div);
    aiScrollToBottom();
}

function aiHideTyping() {
    const typing = document.getElementById('aiTyping');
    if (typing) typing.remove();
}

async function aiSendMessage() {
    const text = aiInput.value.trim();
    if (!text || aiSendBtn.disabled) return;

    _pendingQuestion = text;
    aiInput.value = '';
    aiInput.style.height = '';
    aiSendBtn.disabled = true;

    aiAppendMessage('user', text.replace(/\n/g, '<br>'));
    aiShowTyping();

    try {
        const formData = new FormData();
        formData.append('m', text);

        // Get CSRF token from global input
        const csrfToken = document.getElementById('global_csrf_token')?.value || '';
        formData.append('csrf_token', csrfToken);

        const response = await fetch('helper_service.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        aiHideTyping();

        if (data.ok) {
            aiAppendMessage('ai', data.reply, data.msg_id || '');
        } else {
            aiAppendMessage('ai', `❌ เกิดข้อผิดพลาด: ${data.error}`);
        }
    } catch (error) {
        aiHideTyping();
        aiAppendMessage('ai', '❌ ขออภัย ไม่สามารถเชื่อมต่อกับ AI ได้ในขณะนี้');
    } finally {
        aiSendBtn.disabled = false;
        aiInput.focus();
    }
}

function aiSend(text) {
    aiInput.value = text;
    aiSendMessage();
}
</script>
