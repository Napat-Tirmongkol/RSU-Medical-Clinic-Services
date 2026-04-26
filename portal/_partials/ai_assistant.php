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
    <!-- Header -->
    <div class="px-6 py-4 border-b border-slate-200 bg-white flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-purple-600 flex items-center justify-center text-white shadow-lg shadow-purple-200">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
            <div>
                <h2 class="text-base font-black text-slate-800 leading-tight">AI Data Assistant</h2>
                <p class="text-[11px] text-slate-500 font-bold uppercase tracking-wider">Powered by Gemini AI</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-2 px-3 py-1 bg-emerald-50 text-emerald-600 rounded-full border border-emerald-100">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-[10px] font-black uppercase tracking-widest">Active</span>
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

function aiAppendMessage(role, content) {
    const div = document.createElement('div');
    div.className = `flex gap-4 ${role === 'user' ? 'flex-row-reverse' : ''} group animate-in slide-in-from-bottom-2 duration-300`;
    
    const iconClass = role === 'user' ? 'bg-purple-100 text-purple-600' : 'bg-slate-800 text-white';
    const icon = role === 'user' ? '<i class="fa-solid fa-user text-sm"></i>' : '<i class="fa-solid fa-robot text-sm"></i>';
    const bubbleClass = role === 'user' 
        ? 'bg-purple-600 text-white rounded-tr-none shadow-purple-100' 
        : 'bg-white border border-slate-200 text-slate-700 rounded-tl-none';
    
    const label = role === 'user' ? 'YOU' : 'AI ASSISTANT';

    div.innerHTML = `
        <div class="w-9 h-9 rounded-xl ${iconClass} flex items-center justify-center flex-shrink-0">
            ${icon}
        </div>
        <div class="space-y-2 max-w-[85%] ${role === 'user' ? 'text-right' : ''}">
            <div class="${bubbleClass} p-4 rounded-2xl shadow-sm text-sm leading-relaxed ai-bubble-content ${role === 'user' ? 'text-left' : ''}">
                ${role === 'user' ? content : marked.parse(content)}
            </div>
            <div class="text-[10px] text-slate-400 font-bold ml-1">${label}</div>
        </div>
    `;
    
    aiMsgContainer.appendChild(div);
    aiScrollToBottom();
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
            aiAppendMessage('ai', data.reply);
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
