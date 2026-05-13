<?php
/**
 * includes/user_modals/announcement_carousel.php
 *
 * Full-screen announcement overlay shown to users with unread announcements.
 * Carousel with prev/next/swipe/keyboard nav. Each "รับทราบ" marks the
 * announcement read via /portal/ajax_announcements.php (action=mark_read).
 *
 * Requires (from caller scope):
 *   - $announcements  array  Unread announcement rows from sys_announcements
 *
 * Caller must guard with `if (!empty($announcements))` before include.
 *
 * Extracted from user/hub.php (was inline at end of file).
 */
?>
<!-- ── Announcement Popup ─────────────────────────────────────────────────── -->
<style>
    #ann-overlay {
        position: fixed; inset: 0; z-index: 9000;
        background: rgba(15,23,42,0.55);
        backdrop-filter: blur(6px);
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
        animation: annFadeIn .3s ease;
    }
    @keyframes annFadeIn { from { opacity:0 } to { opacity:1 } }
    #ann-box {
        background: #fff;
        border-radius: 2.25rem;
        width: 100%; max-width: 360px;
        overflow: hidden;
        box-shadow: 0 30px 60px -10px rgba(0,0,0,.2);
        animation: annSlideUp .35s cubic-bezier(.16,1,.3,1);
        position: relative;
        touch-action: pan-y;
    }
    @keyframes annSlideUp { from { transform:translateY(30px);opacity:0 } to { transform:none;opacity:1 } }
    .ann-header-info   { background: linear-gradient(135deg,#0052CC,#0066ff); }
    .ann-header-warning{ background: linear-gradient(135deg,#d97706,#f59e0b); }
    .ann-header-success{ background: linear-gradient(135deg,#059669,#10b981); }
    .ann-header-urgent { background: linear-gradient(135deg,#dc2626,#ef4444); }
    .ann-urgent-pulse  { animation: urgentPulse 1.4s ease-in-out infinite; }
    @keyframes urgentPulse { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)} 60%{box-shadow:0 0 0 12px rgba(239,68,68,0)} }
    #ann-img { width:100%; max-height:200px; object-fit:cover; display:block; }
    .ann-dot { width:7px; height:7px; border-radius:50%; background:#e2e8f0; transition:all .2s; cursor:pointer; border:none; padding:0; }
    .ann-dot.active { background:#0052CC; transform:scale(1.3); }
    .ann-dot:focus-visible { outline: 2px solid #0052CC; outline-offset: 2px; }
    #ann-btn-dismiss { transition: transform .1s; }
    #ann-btn-dismiss:active { transform: scale(.95); }
    /* Carousel a11y — prev/next arrows + swipe affordance */
    .ann-nav-btn {
        position: absolute; top: 50%; transform: translateY(-50%);
        width: 36px; height: 36px; border-radius: 50%;
        background: rgba(255,255,255,0.92);
        box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        border: 0; cursor: pointer; z-index: 5;
        display: flex; align-items: center; justify-content: center;
        color: #0f172a; font-size: 14px;
        transition: transform .15s, opacity .15s;
    }
    .ann-nav-btn:hover  { transform: translateY(-50%) scale(1.08); }
    .ann-nav-btn:active { transform: translateY(-50%) scale(0.92); }
    .ann-nav-btn:focus-visible { outline: 2px solid #0052CC; outline-offset: 2px; }
    .ann-nav-btn[disabled] { opacity: 0; pointer-events: none; }
    #ann-prev { left: -12px; }
    #ann-next { right: -12px; }
</style>

<div id="ann-overlay" role="dialog" aria-modal="true" aria-label="ประกาศจากคลินิก">
    <div id="ann-box" tabindex="-1">

        <!-- Carousel navigation (hidden when only 1 announcement) -->
        <button type="button" id="ann-prev" class="ann-nav-btn" onclick="annPrev()" aria-label="ประกาศก่อนหน้า">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <button type="button" id="ann-next" class="ann-nav-btn" onclick="annNext()" aria-label="ประกาศถัดไป">
            <i class="fa-solid fa-chevron-right"></i>
        </button>

        <!-- ── Dynamic Header ── -->
        <div id="ann-header" class="ann-header-info relative overflow-hidden">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full"></div>
            <div class="absolute -left-4 -bottom-4 w-24 h-24 bg-white/5 rounded-full"></div>
            <div class="relative z-10 px-7 pt-8 pb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div id="ann-icon-wrap" class="w-11 h-11 bg-white/20 rounded-2xl flex items-center justify-center">
                        <i id="ann-icon" class="fa-solid fa-bullhorn text-white text-xl"></i>
                    </div>
                    <div>
                        <p class="text-white/60 text-[10px] font-black uppercase tracking-[.2em]">ประกาศจากคลินิก</p>
                        <p id="ann-type-label" class="text-white text-[11px] font-black">ข้อมูลทั่วไป</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button id="ann-lang-toggle" onclick="toggleLang()" class="hidden px-2.5 py-1 rounded-lg bg-white/20 hover:bg-white/30 text-white text-[10px] font-black transition-all">
                        EN
                    </button>
                    <button onclick="annClose()" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition-colors" aria-label="ปิด">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Image (conditional) ── -->
        <div id="ann-img-wrap" class="hidden">
            <img id="ann-img" src="" alt="ภาพประกอบ">
        </div>

        <!-- ── Body ── -->
        <div class="px-7 pt-6 pb-4">
            <h3 id="ann-title" class="text-slate-900 font-black text-xl leading-tight mb-3"></h3>
            <p id="ann-content" class="text-slate-500 text-[14px] leading-relaxed font-medium"></p>
        </div>

        <!-- ── Dots ── -->
        <div id="ann-dots" class="flex justify-center gap-2 px-7 pb-4"></div>

        <!-- ── Footer Buttons ── -->
        <div class="px-7 pb-8 flex items-center gap-3">
            <button onclick="annSkipAll()"
                class="flex-none text-slate-400 text-[12px] font-black hover:text-slate-600 transition-colors py-2 px-3">
                ข้ามทั้งหมด
            </button>
            <button id="ann-btn-dismiss" onclick="annDismiss()"
                class="flex-1 bg-[#0052CC] hover:bg-blue-700 text-white font-black text-[14px] py-4 rounded-2xl shadow-lg shadow-blue-200 flex items-center justify-center gap-2">
                รับทราบ <i class="fa-solid fa-arrow-right text-[11px]"></i>
            </button>
        </div>

        <!-- ── Counter ── -->
        <p id="ann-counter" class="text-center text-[11px] text-slate-300 font-bold pb-4 -mt-2"></p>

    </div>
</div>

<script>
(function() {
    // ── ข้อมูลประกาศ (PHP → JS) ─────────────────────────────────────────────
    const announcements = <?= json_encode(array_values($announcements), JSON_UNESCAPED_UNICODE) ?>;

    let currentIndex = 0;
    let isDismissing = false;
    let currentLang  = 'th';

    const typeConfig = {
        info:    { cls: 'ann-header-info',    icon: 'fa-bullhorn',         label: 'ข้อมูลทั่วไป',    btn: '#0052CC' },
        warning: { cls: 'ann-header-warning',  icon: 'fa-triangle-exclamation', label: 'แจ้งเตือน',  btn: '#d97706' },
        success: { cls: 'ann-header-success',  icon: 'fa-circle-check',    label: 'ข่าวดี',           btn: '#059669' },
        urgent:  { cls: 'ann-header-urgent',   icon: 'fa-siren-on',        label: 'ด่วน!',            btn: '#dc2626' },
    };

    // ── render ประกาศ ─────────────────────────────────────────────────────────
    function render(idx) {
        const ann = announcements[idx];
        const cfg = typeConfig[ann.type] || typeConfig.info;

        // Header
        const header = document.getElementById('ann-header');
        header.className = cfg.cls + ' relative overflow-hidden';
        if (ann.type === 'urgent') header.classList.add('ann-urgent-pulse');

        document.getElementById('ann-icon').className = 'fa-solid ' + cfg.icon + ' text-white text-xl';
        document.getElementById('ann-type-label').textContent = cfg.label;

        // Language Toggle
        const langToggle = document.getElementById('ann-lang-toggle');
        if (ann.title_en || ann.content_en) {
            langToggle.classList.remove('hidden');
            langToggle.textContent = currentLang === 'th' ? 'EN' : 'TH';
        } else {
            langToggle.classList.add('hidden');
            currentLang = 'th';
        }

        // Image
        const imgWrap = document.getElementById('ann-img-wrap');
        const img     = document.getElementById('ann-img');
        if (ann.image_url) {
            img.src = ann.image_url;
            imgWrap.classList.remove('hidden');
        } else {
            imgWrap.classList.add('hidden');
        }

        // Body
        if (currentLang === 'en' && (ann.title_en || ann.content_en)) {
            document.getElementById('ann-title').textContent   = ann.title_en || ann.title;
            document.getElementById('ann-content').textContent = ann.content_en || ann.content;
        } else {
            document.getElementById('ann-title').textContent   = ann.title;
            document.getElementById('ann-content').textContent = ann.content;
        }

        // Dismiss button color
        const btn = document.getElementById('ann-btn-dismiss');
        btn.style.background  = cfg.btn;
        btn.style.boxShadow   = '';

        // Counter + dots + nav buttons
        updateDots(idx);
        updateNavButtons();
        document.getElementById('ann-counter').textContent =
            announcements.length > 1 ? (idx + 1) + ' / ' + announcements.length : '';
    }

    window.toggleLang = function() {
        currentLang = currentLang === 'th' ? 'en' : 'th';
        render(currentIndex);
    };

    function updateDots(activeIdx) {
        const container = document.getElementById('ann-dots');
        container.innerHTML = '';
        if (announcements.length <= 1) return;
        announcements.forEach((_, i) => {
            const d = document.createElement('button');
            d.className = 'ann-dot' + (i === activeIdx ? ' active' : '');
            d.onclick = () => jumpTo(i);
            container.appendChild(d);
        });
    }

    function jumpTo(idx) {
        currentIndex = idx;
        render(currentIndex);
    }

    // ── Carousel navigation (prev/next + keyboard + swipe) ────────────────────
    window.annPrev = function() {
        if (announcements.length <= 1) return;
        currentIndex = (currentIndex - 1 + announcements.length) % announcements.length;
        render(currentIndex);
    };
    window.annNext = function() {
        if (announcements.length <= 1) return;
        currentIndex = (currentIndex + 1) % announcements.length;
        render(currentIndex);
    };

    function updateNavButtons() {
        const prev = document.getElementById('ann-prev');
        const next = document.getElementById('ann-next');
        if (!prev || !next) return;
        const hide = announcements.length <= 1;
        prev.toggleAttribute('disabled', hide);
        next.toggleAttribute('disabled', hide);
    }

    // Keyboard: ← prev, → next, Esc/Enter dismiss
    document.addEventListener('keydown', function(e) {
        const overlay = document.getElementById('ann-overlay');
        if (!overlay) return;
        if (e.key === 'ArrowLeft')  { e.preventDefault(); annPrev(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); annNext(); }
        if (e.key === 'Escape')     { e.preventDefault(); annSkipAll(); }
        if (e.key === 'Enter' && (e.target.id === 'ann-box' || e.target.closest('#ann-box'))) {
            if (!e.target.matches('button, [role="button"]')) {
                e.preventDefault(); annDismiss();
            }
        }
    });

    // Touch swipe (left/right)
    (function setupSwipe() {
        const box = document.getElementById('ann-box');
        if (!box) return;
        let startX = 0, startY = 0, dx = 0, dy = 0, tracking = false;
        const THRESHOLD = 40; // px
        box.addEventListener('touchstart', function(e) {
            if (announcements.length <= 1) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            dx = 0; dy = 0; tracking = true;
        }, { passive: true });
        box.addEventListener('touchmove', function(e) {
            if (!tracking) return;
            dx = e.touches[0].clientX - startX;
            dy = e.touches[0].clientY - startY;
            if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10) tracking = false;
        }, { passive: true });
        box.addEventListener('touchend', function() {
            if (!tracking) return;
            tracking = false;
            if (Math.abs(dx) >= THRESHOLD) {
                dx < 0 ? annNext() : annPrev();
            }
            dx = 0; dy = 0;
        });
    })();

    // Focus management — keep focus inside dialog
    document.getElementById('ann-box')?.focus({ preventScroll: true });

    // ── กด รับทราบ ─────────────────────────────────────────────────────────────
    window.annDismiss = function() {
        if (isDismissing) return;
        isDismissing = true;

        const ann = announcements[currentIndex];
        const fd  = new FormData();
        fd.append('action', 'mark_read');
        fd.append('ann_id', ann.id);

        fetch('../portal/ajax_announcements.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(() => ({ status: 'ok' }))
            .then(() => {
                announcements.splice(currentIndex, 1);
                isDismissing = false;

                if (announcements.length === 0) {
                    annClose();
                } else {
                    currentIndex = Math.min(currentIndex, announcements.length - 1);
                    render(currentIndex);
                }
            });
    };

    window.annSkipAll = function() { annClose(); };

    window.annClose = function() {
        const overlay = document.getElementById('ann-overlay');
        if (overlay) {
            overlay.style.transition = 'opacity .25s';
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 260);
        }
    };

    document.getElementById('ann-overlay').addEventListener('click', function(e) {
        if (e.target === this) annClose();
    });

    render(0);
})();
</script>
