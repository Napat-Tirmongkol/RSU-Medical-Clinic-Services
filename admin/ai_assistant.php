<?php
// admin/ai_assistant.php — Gemini AI Campaign Analyst
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

// Quick check: API key configured?
$apiKeySet = defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY);

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == 1;

if (!$isEmbed) {
    require_once __DIR__ . '/includes/header.php';
    renderPageHeader(
        '<i class="fa-solid fa-robot" style="color:#8b5cf6"></i> AI Campaign Analyst',
        'วิเคราะห์ข้อมูลแคมเปญด้วย Gemini AI · ถามอะไรก็ได้เกี่ยวกับข้อมูลในระบบ'
    );
} else {
    // เมื่อ Embed ให้โหลด FontAwesome และ Font ที่จำเป็นด้วย
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap">';
    $themeVal = (isset($_GET['theme']) && $_GET['theme'] === 'dark') ? 'dark' : 'light';
    echo '<style>body { font-family: "Prompt", sans-serif; }</style>';
    echo "<script>document.documentElement.setAttribute('data-theme', '{$themeVal}');</script>";
}
?>

<style>
/* ── Chat layout ─────────────────────────────────── */
.chat-wrap {
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 20px;
    border: 1.5px solid #ede9fe;
    box-shadow: 0 2px 16px rgba(139,92,246,.08);
    overflow: hidden;
    min-height: 520px;
}
/* ── Message bubbles ─────────────────────────────── */
.msg { display: flex; gap: 12px; align-items: flex-start; }
.msg.user  { flex-direction: row-reverse; }
.msg-avatar {
    width: 36px; height: 36px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; flex-shrink: 0;
}
.msg.user  .msg-avatar { background: linear-gradient(135deg,#8b5cf6,#7c3aed); color:#fff; }
.msg.ai    .msg-avatar { background: linear-gradient(135deg,#1a73e8,#0d47a1); color:#fff; }
.msg-bubble {
    max-width: 82%;
    padding: 12px 16px;
    border-radius: 16px;
    font-size: .875rem;
    line-height: 1.65;
}
.msg.user  .msg-bubble {
    background: linear-gradient(135deg,#8b5cf6,#7c3aed);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.msg.ai    .msg-bubble {
    background: #f5f3ff;
    color: #1e1b4b;
    border-bottom-left-radius: 4px;
    border: 1px solid #ede9fe;
}
/* Markdown rendered content */
.msg-bubble h1,.msg-bubble h2,.msg-bubble h3 { font-weight:800; margin:8px 0 4px; }
.msg-bubble h1 { font-size:1.1rem; }
.msg-bubble h2 { font-size:1rem; }
.msg-bubble h3 { font-size:.9rem; }
.msg-bubble p  { margin:4px 0; }
.msg-bubble ul,.msg-bubble ol { padding-left:1.2rem; margin:4px 0; }
.msg-bubble li { margin:2px 0; }
.msg-bubble strong { font-weight:700; }
.msg-bubble table { border-collapse:collapse; width:100%; margin:8px 0; font-size:.8rem; }
.msg-bubble th,.msg-bubble td { border:1px solid #c4b5fd; padding:5px 10px; }
.msg-bubble th { background:#ede9fe; font-weight:700; }
.msg.user .msg-bubble ul,.msg.user .msg-bubble ol { color:rgba(255,255,255,.9); }
.msg.user .msg-bubble th,.msg.user .msg-bubble td { border-color:rgba(255,255,255,.3); }
.msg.user .msg-bubble th { background:rgba(255,255,255,.15); }

/* ── Typing indicator ────────────────────────────── */
.typing-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #8b5cf6;
    animation: typingBounce .9s infinite ease-in-out;
}
.typing-dot:nth-child(2) { animation-delay:.15s; }
.typing-dot:nth-child(3) { animation-delay:.30s; }
@keyframes typingBounce {
    0%,80%,100% { transform:translateY(0); opacity:.4; }
    40%         { transform:translateY(-6px); opacity:1; }
}

/* ── Quick prompt chips ──────────────────────────── */
.prompt-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    background: #f5f3ff; color: #7c3aed;
    border: 1.5px solid #ede9fe;
    border-radius: 99px;
    font-size: .78rem; font-weight: 700;
    cursor: pointer;
    transition: all .18s;
    white-space: nowrap;
}
.prompt-chip:hover { background:#ede9fe; border-color:#c4b5fd; transform:translateY(-1px); }

/* ── Chip skeleton shimmer ───────────────────────────── */
.chip-skeleton {
    height: 34px; border-radius: 99px; display: inline-block;
    background: linear-gradient(90deg,#f3f4f6 25%,#e5e7eb 50%,#f3f4f6 75%);
    background-size: 200% 100%;
    animation: chipShimmer 1.3s infinite linear;
}
@keyframes chipShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ── Scrollable messages ─────────────────────────── */
#chatMessages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    scrollbar-width: thin;
    scrollbar-color: #c4b5fd transparent;
}
#chatMessages::-webkit-scrollbar { width: 4px; }
#chatMessages::-webkit-scrollbar-thumb { background: #c4b5fd; border-radius: 99px; }

/* ── Rate limit bar ──────────────────────────────── */
.rate-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; padding: 6px 16px;
    background: #faf8ff; border-top: 1px solid #ede9fe;
    font-size: .72rem; color: #6b7280; flex-wrap: wrap;
}
.rate-bar-dots { display: flex; gap: 3px; }
.rate-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #e5e7eb; transition: background .3s;
}
.rate-dot.used   { background: #8b5cf6; }
.rate-dot.danger { background: #ef4444; }
#cooldownBadge {
    display: none; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 99px;
    background: #fef3c7; color: #92400e;
    font-weight: 700; font-size: .7rem;
}
#cooldownBadge.show { display: flex; }
#rateLimitWarn {
    display: none; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 99px;
    background: #fee2e2; color: #991b1b;
    font-weight: 700; font-size: .7rem;
}
#rateLimitWarn.show { display: flex; }

/* ── Input area ──────────────────────────────────── */
.chat-input-area {
    border-top: 1.5px solid #ede9fe;
    padding: 14px 16px;
    display: flex;
    gap: 10px;
    align-items: flex-end;
    background: #faf8ff;
}
#chatInput {
    flex: 1;
    resize: none;
    border: 1.5px solid #ddd6fe;
    border-radius: 14px;
    padding: 10px 14px;
    font-size: .875rem;
    font-family: 'Prompt', sans-serif;
    outline: none;
    max-height: 120px;
    line-height: 1.5;
    transition: border-color .2s;
    background: #fff;
}
#chatInput:focus { border-color: #8b5cf6; }
#sendBtn {
    width: 42px; height: 42px;
    background: linear-gradient(135deg,#8b5cf6,#7c3aed);
    border: none; border-radius: 12px;
    color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; flex-shrink: 0;
    transition: all .18s;
    box-shadow: 0 4px 12px rgba(139,92,246,.35);
}
#sendBtn:hover:not(:disabled) { filter:brightness(1.1); transform:translateY(-1px); }
#sendBtn:disabled { opacity:.5; cursor:not-allowed; transform:none; }

/* ── API warning banner ─────────────────────────── */
.api-warn {
    background:#fef3c7; border:1.5px solid #fde68a;
    border-radius:14px; padding:14px 18px;
    display:flex; align-items:flex-start; gap:12px;
    font-size:.875rem; color:#92400e;
}

@media (max-width:639px) {
    .msg-bubble { max-width:90%; font-size:.82rem; }
    .prompt-chip { font-size:.73rem; padding:6px 11px; }
    #chatMessages { padding:14px; gap:12px; }
}

/* ── Dark Mode Overrides ────────────────────────── */
[data-theme='dark'] body { background: #0f172a; color: #cbd5e1; }
[data-theme='dark'] .chat-wrap { background: #1e293b; border-color: #334155; box-shadow: 0 4px 20px rgba(0,0,0,.3); }
[data-theme='dark'] .msg.ai .msg-bubble { background: #334155; color: #f1f5f9; border-color: #475569; }
[data-theme='dark'] .msg.ai .msg-bubble th { background: #1e293b; color: #fff; }
[data-theme='dark'] .msg.ai .msg-bubble td { border-color: #475569; }
[data-theme='dark'] .prompt-chip { background: #334155; color: #a78bfa; border-color: #475569; }
[data-theme='dark'] .prompt-chip:hover { background: #475569; border-color: #8b5cf6; color: #fff; }
[data-theme='dark'] #regenChipsBtn { background: #334155; border-color: #475569; color: #a78bfa; }
[data-theme='dark'] #chatInput { background: #0f172a; border-color: #334155; color: #fff; }
[data-theme='dark'] .chat-input-area { background: #1e293b; border-top-color: #334155; }
[data-theme='dark'] .rate-bar { background: #1e293b; border-top-color: #334155; color: #94a3b8; }
[data-theme='dark'] .api-warn { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3); color: #fbbf24; }
[data-theme='dark'] .chip-skeleton { background: linear-gradient(90deg,#1e293b 25%,#334155 50%,#1e293b 75%); }
</style>

<?php if (!$apiKeySet): ?>
<div class="api-warn mb-6 fade-up">
    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-xl flex-shrink-0 mt-0.5"></i>
    <div>
        <div class="font-bold mb-1">ยังไม่ได้ตั้งค่า Gemini API Key</div>
        <div>กรุณาไปที่เมนู <a href="../portal/index.php?section=settings" class="font-bold underline">Settings</a> ในหน้า Portal เพื่อกรอก API Key ของ Gemini ก่อนใช้งานระบบผู้ช่วย AI ครับ<br>
        รับ API Key ฟรีได้ที่ <a href="https://aistudio.google.com/app/apikey" target="_blank" class="underline font-semibold">Google AI Studio</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Prompts (AI-generated) -->
<div class="mb-4 fade-up" style="animation-delay:.05s">
    <div class="text-xs font-black uppercase tracking-widest text-gray-400 mb-3"
         style="display:flex;align-items:center;justify-content:space-between;gap:8px">
        <span>
            <i class="fa-solid fa-bolt mr-1"></i> คำถามด่วน
            <span id="chipAiBadge"
                style="display:inline-flex;align-items:center;gap:4px;margin-left:6px;
                       padding:2px 8px;border-radius:99px;font-size:.65rem;font-weight:800;
                       background:linear-gradient(135deg,#8b5cf6,#7c3aed);color:#fff;
                       opacity:0;transition:opacity .4s">
                <i class="fa-solid fa-robot" style="font-size:.6rem"></i> AI Generated
            </span>
        </span>
        <button id="regenChipsBtn" onclick="loadSuggestions(true)" title="สร้างคำถามใหม่"
            style="background:#f5f3ff;border:1.5px solid #ede9fe;border-radius:8px;
                   cursor:pointer;color:#8b5cf6;font-size:.75rem;padding:4px 10px;
                   display:flex;align-items:center;gap:5px;font-weight:700;
                   transition:all .18s;white-space:nowrap">
            <i class="fa-solid fa-rotate-right" id="regenIcon"></i> สร้างใหม่
        </button>
    </div>
    <div id="chipArea" class="flex flex-wrap gap-2">
        <!-- Skeleton placeholders while loading -->
        <div class="chip-skeleton" style="width:120px"></div>
        <div class="chip-skeleton" style="width:90px"></div>
        <div class="chip-skeleton" style="width:140px"></div>
        <div class="chip-skeleton" style="width:100px"></div>
        <div class="chip-skeleton" style="width:110px"></div>
    </div>
</div>

<!-- Chat Box -->
<div class="chat-wrap fade-up" style="animation-delay:.1s">

    <!-- Messages -->
    <div id="chatMessages">
        <!-- Welcome message -->
        <div class="msg ai" id="welcomeMsg">
            <div class="msg-avatar"><i class="fa-solid fa-robot"></i></div>
            <div class="msg-bubble">
                <strong>สวัสดีครับ! ผม AI Campaign Analyst</strong> <i class="fa-solid fa-robot"></i><br>
                ผมสามารถวิเคราะห์ข้อมูลแคมเปญ RSU Medical Clinic ได้แบบเรียลไทม์<br><br>
                ลองกดปุ่ม <strong>คำถามด่วน</strong> ด้านบน หรือพิมพ์คำถามของคุณได้เลย เช่น<br>
                <ul style="margin-top:6px">
                    <li><em>"สรุปข้อมูลแคมเปญ 10 อันดับแรกที่มีคนจองเยอะที่สุด"</em></li>
                    <li><em>"แคมเปญไหนควรเพิ่มโควต้า?"</em></li>
                    <li><em>"ภาพรวมการจองเดือนนี้เป็นอย่างไร?"</em></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Rate limit bar -->
    <div class="rate-bar">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <div class="rate-bar-dots" id="rateDots"></div>
            <span id="rateText" style="color:#9ca3af">เหลือ <b id="remainingCount">10</b>/10 ครั้งในนาทีนี้</span>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
            <span id="cooldownBadge"><i class="fa-solid fa-clock"></i> รอ <b id="cdSec">0</b>s</span>
            <span id="rateLimitWarn"><i class="fa-solid fa-ban"></i> ถึงลิมิต — รีเซ็ตใน <b id="resetSec">60</b>s</span>
        </div>
    </div>

    <!-- Input -->
    <div class="chat-input-area">
        <textarea id="chatInput" rows="1"
            placeholder="พิมพ์คำถามเกี่ยวกับข้อมูลแคมเปญ..."
            onkeydown="handleKey(event)"
            oninput="autoResize(this)"
            <?= !$apiKeySet ? 'disabled' : '' ?>></textarea>
        <button id="sendBtn" onclick="sendMessage()" <?= !$apiKeySet ? 'disabled' : '' ?> title="ส่ง (Enter)">
            <i class="fa-solid fa-paper-plane"></i>
        </button>
    </div>
</div>

<!-- marked.js for Markdown rendering -->
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
<script>
marked.setOptions({ breaks: true, gfm: true });

const chatEl  = document.getElementById('chatMessages');
const inputEl = document.getElementById('chatInput');
const sendEl  = document.getElementById('sendBtn');

// ── Auto-resize textarea ──────────────────────────────────────────────────────
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

// ── Keyboard: Enter = send, Shift+Enter = newline ─────────────────────────────
function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

// ── Append message bubble ─────────────────────────────────────────────────────
function appendMsg(role, html) {
    const div = document.createElement('div');
    div.className = 'msg ' + role;
    const icon  = role === 'user' ? 'fa-user' : 'fa-robot';
    div.innerHTML = `
        <div class="msg-avatar"><i class="fa-solid ${icon}"></i></div>
        <div class="msg-bubble">${html}</div>`;
    chatEl.appendChild(div);
    chatEl.scrollTop = chatEl.scrollHeight;
    return div;
}

// ── Typing indicator ──────────────────────────────────────────────────────────
function showTyping() {
    const div = document.createElement('div');
    div.className = 'msg ai';
    div.id = 'typingIndicator';
    div.innerHTML = `
        <div class="msg-avatar"><i class="fa-solid fa-robot"></i></div>
        <div class="msg-bubble" style="padding:14px 18px">
            <div style="display:flex;gap:5px;align-items:center">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <span style="font-size:.75rem;color:#7c3aed;margin-left:4px;font-weight:600">Gemini กำลังคิด…</span>
            </div>
        </div>`;
    chatEl.appendChild(div);
    chatEl.scrollTop = chatEl.scrollHeight;
}
function hideTyping() {
    document.getElementById('typingIndicator')?.remove();
}

// ── AI-generated quick suggestions ───────────────────────────────────────────
const CHIP_ICONS = [
    'fa-ranking-star','fa-chart-pie','fa-lightbulb',
    'fa-arrow-trend-up','fa-ban','fa-magnifying-glass-chart',
    'fa-star','fa-calendar-check','fa-fire','fa-circle-question'
];
const DEFAULT_CHIPS = [
    {icon:'fa-ranking-star', q:'สรุป 10 อันดับแรกที่คนจองเยอะที่สุด พร้อมบอกอัตราการเติมโควต้า'},
    {icon:'fa-chart-pie',    q:'วิเคราะห์ภาพรวมของระบบ ว่ามีแคมเปญ การจอง สถานะอะไรบ้าง ระบุจุดที่น่าเป็นห่วง'},
    {icon:'fa-lightbulb',   q:'แคมเปญไหนที่โควต้าใกล้เต็มหรือเต็มแล้ว แนะนำว่าควรโปรโมตแคมเปญไหน'},
    {icon:'fa-arrow-trend-up', q:'วิเคราะห์แนวโน้มการจอง 7 วันล่าสุด มีทิศทางอย่างไร'},
    {icon:'fa-ban',          q:'อัตราการยกเลิก (cancellation rate) โดยรวมเป็นเท่าไร มีข้อเสนอแนะอะไรไหม'},
    {icon:'fa-bug',          q:'วิเคราะห์ Error Logs ล่าสุดให้หน่อย ว่ามีปัญหาอะไรที่ต้องรีบแก้ไหม'},
];

function renderChips(items, isAI = false) {
    const area    = document.getElementById('chipArea');
    const badge   = document.getElementById('chipAiBadge');
    area.innerHTML = '';
    items.forEach((item, i) => {
        const q    = typeof item === 'string' ? item : item.q;
        const icon = typeof item === 'string' ? CHIP_ICONS[i % CHIP_ICONS.length] : item.icon;
        const btn  = document.createElement('button');
        btn.className = 'prompt-chip';
        btn.onclick   = () => sendPrompt(q);
        btn.title     = q;
        // Truncate label for display
        const label = q.length > 28 ? q.substring(0, 26) + '…' : q;
        btn.innerHTML = `<i class="fa-solid ${icon}"></i> ${escHtml(label)}`;
        btn.style.animation = `adminSlideUp .35s cubic-bezier(.16,1,.3,1) ${i * 0.06}s both`;
        area.appendChild(btn);
    });
    if (badge) badge.style.opacity = isAI ? '1' : '0';
}

function showChipSkeleton() {
    const widths = [120, 90, 145, 100, 115];
    document.getElementById('chipArea').innerHTML =
        widths.map(w => `<div class="chip-skeleton" style="width:${w}px"></div>`).join('');
}

async function loadSuggestions(force = false) {
    const regenBtn  = document.getElementById('regenChipsBtn');
    const regenIcon = document.getElementById('regenIcon');

    showChipSkeleton();
    regenBtn.disabled = true;
    regenIcon.classList.add('fa-spin');

    try {
        const fd = new FormData();
        fd.append('mode',       'suggestions');
        fd.append('csrf_token', getCsrf());
        if (force) fd.append('force', '1');

        const res  = await fetch('ajax/ajax_ai.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (data.ok && Array.isArray(data.suggestions) && data.suggestions.length) {
            renderChips(data.suggestions, true);
        } else {
            renderChips(DEFAULT_CHIPS, false);
        }
    } catch (e) {
        renderChips(DEFAULT_CHIPS, false);
    } finally {
        regenBtn.disabled = false;
        regenIcon.classList.remove('fa-spin');
    }
}

// ── Rate limit & cooldown state ───────────────────────────────────────────────
let RATE_LIMIT = 10;
let COOLDOWN   = 4;
let cdInterval = null;
let rlInterval = null;

function initDots(limit) {
    const el = document.getElementById('rateDots');
    el.innerHTML = '';
    for (let i = 0; i < limit; i++) {
        const d = document.createElement('div');
        d.className = 'rate-dot';
        d.id = 'dot-' + i;
        el.appendChild(d);
    }
}

function updateRateUI(remaining, limit) {
    RATE_LIMIT = limit || RATE_LIMIT;
    const used = RATE_LIMIT - remaining;
    document.getElementById('remainingCount').textContent = remaining;
    document.getElementById('rateText').innerHTML =
        `เหลือ <b id="remainingCount">${remaining}</b>/${RATE_LIMIT} ครั้งในนาทีนี้`;
    // update dots
    for (let i = 0; i < RATE_LIMIT; i++) {
        const d = document.getElementById('dot-' + i);
        if (!d) continue;
        d.className = 'rate-dot' + (i < used ? (used >= RATE_LIMIT - 2 ? ' danger' : ' used') : '');
    }
}

function startCooldown(seconds) {
    clearInterval(cdInterval);
    sendEl.disabled = true;
    let left = seconds;
    const badge  = document.getElementById('cooldownBadge');
    const cdSecEl = document.getElementById('cdSec');
    badge.classList.add('show');

    function tick() {
        cdSecEl.textContent = left;
        sendEl.innerHTML = `<span style="font-size:.72rem;font-weight:800;letter-spacing:-.5px">${left}s</span>`;
        if (left <= 0) {
            clearInterval(cdInterval);
            sendEl.disabled = false;
            sendEl.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
            badge.classList.remove('show');
            inputEl.focus();
        }
        left--;
    }
    tick();
    cdInterval = setInterval(tick, 1000);
}

function showRateLimited(resetIn) {
    clearInterval(rlInterval);
    sendEl.disabled = true;
    let left = resetIn;
    const warn   = document.getElementById('rateLimitWarn');
    const resetEl = document.getElementById('resetSec');
    warn.classList.add('show');
    document.getElementById('cooldownBadge').classList.remove('show');

    function tick() {
        resetEl.textContent = left;
        sendEl.innerHTML = `<span style="font-size:.65rem;font-weight:800"><i class='fa-solid fa-ban'></i> ${left}s</span>`;
        if (left <= 0) {
            clearInterval(rlInterval);
            sendEl.disabled = false;
            sendEl.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
            warn.classList.remove('show');
            updateRateUI(RATE_LIMIT, RATE_LIMIT); // reset display
            inputEl.focus();
        }
        left--;
    }
    tick();
    rlInterval = setInterval(tick, 1000);
}

// ── Core send ─────────────────────────────────────────────────────────────────
async function sendMessage() {
    const query = inputEl.value.trim();
    if (!query || sendEl.disabled) return;

    appendMsg('user', escHtml(query).replace(/\n/g, '<br>'));
    inputEl.value = '';
    autoResize(inputEl);
    sendEl.disabled = true;
    showTyping();

    try {
        const fd = new FormData();
        fd.append('query', query);
        fd.append('csrf_token', getCsrf());

        const res  = await fetch('ajax/ajax_ai.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        hideTyping();

        if (data.ok) {
            appendMsg('ai', marked.parse(data.reply));
            // Update rate UI from server response
            if (data.remaining !== undefined) updateRateUI(data.remaining, data.limit);
            startCooldown(data.cooldown || COOLDOWN);
        } else if (data.cooldown) {
            // Server enforced cooldown
            appendMsg('ai', `<span style="color:#b45309"><i class="fa-solid fa-clock mr-1"></i>${escHtml(data.error)}</span>`);
            startCooldown(data.cooldown);
        } else if (data.rate_limited) {
            // Hit per-minute limit
            appendMsg('ai', `<span style="color:#dc2626"><i class="fa-solid fa-ban mr-1"></i>${escHtml(data.error)}</span>`);
            updateRateUI(0, RATE_LIMIT);
            showRateLimited(data.reset_in || 60);
        } else {
            appendMsg('ai', `<span style="color:#dc2626"><i class="fa-solid fa-circle-exclamation mr-1"></i>${escHtml(data.error)}</span>`);
            sendEl.disabled = false;
        }
    } catch (err) {
        hideTyping();
        appendMsg('ai', '<span style="color:#dc2626"><i class="fa-solid fa-circle-exclamation mr-1"></i>เกิดข้อผิดพลาด กรุณาลองใหม่</span>');
        sendEl.disabled = false;
    } finally {
        inputEl.focus();
    }
}

// Init rate dots on page load
initDots(RATE_LIMIT);
updateRateUI(RATE_LIMIT, RATE_LIMIT);

// Load AI-generated suggestions after page is ready
document.addEventListener('DOMContentLoaded', () => loadSuggestions(false));

// ── Quick prompt chips ────────────────────────────────────────────────────────
function sendPrompt(text) {
    inputEl.value = text;
    autoResize(inputEl);
    sendMessage();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function getCsrf() {
    // ดึง CSRF token จาก hidden field ที่อาจอยู่ในหน้า หรือสร้าง meta tag
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.content;
    // Fallback: ดึงจาก cookie
    const match = document.cookie.match(/csrf_token=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
</script>

<?php
// Inject CSRF token as meta tag เพื่อให้ JS ดึงได้
echo '<script>document.head.insertAdjacentHTML("beforeend",\'<meta name="csrf-token" content="' . htmlspecialchars(get_csrf_token()) . '">\');</script>';
?>

<?php if (!$isEmbed) require_once __DIR__ . '/includes/footer.php'; ?>
