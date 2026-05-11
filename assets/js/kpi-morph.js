/**
 * assets/js/kpi-morph.js — Cinematic KPI Override Editor
 *
 * Centered popup overlay (not in-card replacement) → grid layout never breaks.
 * View Transitions API ใช้ morph จาก KPI card → popup ถ้า browser รองรับ.
 *
 * Card markup contract:
 *   <div class="km-card" data-kpi-key="gold_total" data-kpi-label="ทั้งหมด">
 *       ... <p class="km-value" data-value="1250">1,250</p> ...
 *   </div>
 *
 * Bootstrap: KPIMorph.init({ csrf, endpoint: 'ajax_kpi_override.php', editable: true })
 *
 * Effects (overdrive):
 *  - Edit icon spring-pops in on hover
 *  - Popup scales up from card position with View Transition (or fade)
 *  - Spring stepper +/- (hold-to-repeat with acceleration)
 *  - Save: rolling-digit transition + radial particle burst + ring glow
 *  - OVERRIDE badge pulse loop when value is overridden
 *  - Honors prefers-reduced-motion (instant fade fallback)
 */
(function() {
    'use strict';

    const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const HAS_VIEW_TRANSITION = !REDUCED_MOTION && typeof document.startViewTransition === 'function';

    let CONFIG = { csrf: '', endpoint: 'ajax_kpi_override.php', editable: false };
    let activePopup = null;

    function $(s, root) { return (root || document).querySelector(s); }
    function $$(s, root) { return Array.from((root || document).querySelectorAll(s)); }
    function fmt(n) { return Number(n).toLocaleString('en-US'); }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function kmFetch(action, body) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', CONFIG.csrf);
        for (const [k, v] of Object.entries(body || {})) fd.append(k, v);
        return fetch(CONFIG.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json());
    }

    // ── Inject styles once ───────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById('km-styles')) return;
        const s = document.createElement('style');
        s.id = 'km-styles';
        s.textContent = `
            /* KPI card edit affordance (does NOT alter layout when not editable) */
            .km-card { position: relative; }
            .km-card.km-editable { cursor: pointer; }
            .km-card .km-edit-btn {
                position: absolute; top: 8px; right: 8px;
                width: 30px; height: 30px;
                border-radius: 9px; border: none;
                background: rgba(15,23,42,.85); color: #fff;
                cursor: pointer; opacity: 0; pointer-events: none;
                transition: opacity .2s, transform .25s cubic-bezier(.34,1.56,.64,1);
                transform: scale(.6) rotate(-12deg);
                display: flex; align-items: center; justify-content: center;
                font-size: 11px; z-index: 5;
            }
            .km-card.km-editable:hover .km-edit-btn { opacity: 1; pointer-events: auto; transform: scale(1) rotate(0); }
            .km-card .km-edit-btn:hover { background: #0f172a; transform: scale(1.08) rotate(4deg) !important; }

            .km-card .km-override-badge {
                position: absolute; top: 8px; left: 8px;
                padding: 2px 8px; border-radius: 99px;
                background: linear-gradient(135deg, #f59e0b, #ef4444);
                color: #fff; font-size: 9px; font-weight: 900;
                letter-spacing: .08em; text-transform: uppercase;
                box-shadow: 0 0 0 0 rgba(245,158,11,.5);
                animation: km-pulse 2s ease-out infinite;
                z-index: 4;
            }
            @keyframes km-pulse {
                0%,100% { box-shadow: 0 0 0 0 rgba(245,158,11,.4); }
                50% { box-shadow: 0 0 0 8px rgba(245,158,11,0); }
            }

            /* Backdrop */
            .km-backdrop {
                position: fixed; inset: 0; z-index: 9000;
                background: rgba(15,23,42,.45);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                opacity: 0;
                transition: opacity .35s;
            }
            .km-backdrop.km-show { opacity: 1; }

            /* Popup overlay (centered) */
            .km-popup {
                position: fixed; z-index: 9001;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%) scale(.9);
                width: min(92vw, 440px);
                background: #fff;
                border-radius: 24px;
                box-shadow: 0 30px 60px -15px rgba(0,0,0,.35),
                            0 0 0 4px rgba(245,158,11,.18);
                padding: 22px;
                opacity: 0;
                transition: opacity .35s, transform .45s cubic-bezier(.34,1.56,.64,1);
            }
            .km-popup.km-show {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }

            .km-form { display: flex; flex-direction: column; gap: 14px; }
            .km-form-head {
                display: flex; align-items: center; gap: 10px;
                padding-bottom: 12px; border-bottom: 1px dashed #e2e8f0;
            }
            .km-icon-mini {
                width: 36px; height: 36px; border-radius: 10px;
                background: linear-gradient(135deg, #fef3c7, #fde68a);
                color: #b45309;
                display: flex; align-items: center; justify-content: center;
                font-size: 14px; flex-shrink: 0;
            }
            .km-form-title {
                font-size: 13px; font-weight: 900; color: #0f172a;
                flex: 1; min-width: 0;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .km-form-close {
                width: 30px; height: 30px; border-radius: 9px;
                border: 1px solid #e2e8f0; background: #fff;
                color: #64748b; cursor: pointer; font-size: 12px;
                display: flex; align-items: center; justify-content: center;
                flex-shrink: 0;
                transition: background .15s, color .15s;
            }
            .km-form-close:hover { background: #f1f5f9; color: #0f172a; }

            .km-toggle-row {
                display: flex; gap: 6px;
                background: #f1f5f9; padding: 4px; border-radius: 12px;
            }
            .km-toggle-btn {
                flex: 1; padding: 9px 10px; border-radius: 9px;
                border: none; background: transparent; cursor: pointer;
                font-size: 11.5px; font-weight: 900; color: #64748b;
                transition: background .2s, color .2s, transform .25s cubic-bezier(.34,1.56,.64,1);
                display: flex; align-items: center; justify-content: center; gap: 6px;
            }
            .km-toggle-btn.km-active {
                background: #fff; color: #0f172a;
                box-shadow: 0 2px 8px rgba(15,23,42,.08);
            }

            .km-pane-auto {
                text-align: center; padding: 18px 12px 12px;
                background: linear-gradient(135deg, #f8fafc, #f1f5f9);
                border-radius: 14px;
            }
            .km-pane-auto-value {
                font-size: 32px; font-weight: 900; color: #0f172a;
                line-height: 1; margin-bottom: 6px; letter-spacing: -.02em;
                font-variant-numeric: tabular-nums;
            }
            .km-pane-auto-note { font-size: 11px; color: #64748b; font-weight: 800; }

            .km-stepper {
                display: flex; align-items: center; justify-content: center; gap: 10px;
            }
            .km-step-btn {
                width: 46px; height: 46px; border-radius: 13px;
                border: 1.5px solid #e2e8f0; background: #fff;
                font-size: 22px; font-weight: 900; color: #475569; cursor: pointer;
                transition: transform .15s cubic-bezier(.34,1.56,.64,1),
                            background .15s, border-color .15s, box-shadow .15s;
                display: flex; align-items: center; justify-content: center;
                user-select: none; flex-shrink: 0;
            }
            .km-step-btn:hover {
                background: #fef3c7; border-color: #fbbf24; color: #b45309;
            }
            .km-step-btn:active {
                transform: scale(.85);
                box-shadow: inset 0 4px 12px rgba(245,158,11,.15);
            }
            .km-step-input {
                flex: 1; min-width: 0; max-width: 180px;
                height: 46px; border: 1.5px solid #e2e8f0; border-radius: 13px;
                background: #f8fafc; text-align: center;
                font-size: 20px; font-weight: 900; color: #0f172a;
                font-variant-numeric: tabular-nums; outline: none;
                transition: border-color .15s, box-shadow .15s, background .15s;
            }
            .km-step-input:focus {
                border-color: #f59e0b; background: #fff;
                box-shadow: 0 0 0 4px rgba(245,158,11,.14);
            }

            .km-note {
                width: 100%; padding: 10px 13px;
                border: 1.5px solid #e2e8f0; border-radius: 11px;
                background: #fafafa; font-size: 12px;
                font-weight: 700; color: #475569; outline: none; resize: none;
                font-family: inherit;
                box-sizing: border-box;
                transition: border-color .15s, background .15s;
            }
            .km-note:focus { border-color: #f59e0b; background: #fff; }

            .km-actions { display: flex; gap: 10px; }
            .km-btn {
                height: 42px; border-radius: 12px; border: none;
                font-size: 12.5px; font-weight: 900; cursor: pointer;
                transition: transform .12s, background .15s, box-shadow .15s;
                display: flex; align-items: center; justify-content: center; gap: 6px;
            }
            .km-btn-cancel {
                flex: 1; background: #f1f5f9; color: #475569;
            }
            .km-btn-cancel:hover { background: #e2e8f0; }
            .km-btn-save {
                flex: 2;
                background: linear-gradient(135deg, #f59e0b, #ef4444);
                color: #fff; box-shadow: 0 8px 18px -4px rgba(245,158,11,.5);
            }
            .km-btn-save:hover {
                transform: translateY(-1px);
                box-shadow: 0 12px 24px -4px rgba(245,158,11,.6);
            }
            .km-btn-save:active { transform: translateY(1px); }

            /* Particle burst */
            .km-burst {
                position: fixed; pointer-events: none; z-index: 99999;
                width: 8px; height: 8px; border-radius: 50%;
            }

            /* Card glow ring after save */
            .km-card.km-just-saved {
                animation: km-saved-glow .9s cubic-bezier(.4,0,.2,1);
            }
            @keyframes km-saved-glow {
                0%   { box-shadow: 0 0 0 0 rgba(245,158,11,.5); }
                40%  { box-shadow: 0 0 0 18px rgba(245,158,11,.18); }
                100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); }
            }

            /* Rolling digit (used after save) */
            .km-value-rolling { display: inline-flex; }
            .km-digit {
                position: relative; height: 1em; overflow: hidden;
                display: inline-block; vertical-align: top;
                width: .6em; text-align: center;
            }
            .km-digit-stack {
                position: absolute; top: 0; left: 0; right: 0;
                transition: transform .65s cubic-bezier(.4,1.6,.5,1);
            }
            .km-digit-stack > span { display: block; height: 1em; }

            @media (prefers-reduced-motion: reduce) {
                .km-popup, .km-backdrop { transition-duration: 0s !important; }
                .km-card { transition: none !important; }
                .km-burst { display: none !important; }
                .km-card.km-just-saved { animation: none !important; }
                .km-override-badge { animation: none !important; }
                .km-digit-stack { transition: none !important; }
            }
        `;
        document.head.appendChild(s);
    }

    // ── Particle burst ────────────────────────────────────────────────
    function burst(x, y) {
        if (REDUCED_MOTION) return;
        const colors = ['#f59e0b', '#fbbf24', '#fb923c', '#ef4444'];
        const N = 16;
        for (let i = 0; i < N; i++) {
            const p = document.createElement('div');
            p.className = 'km-burst';
            const angle = (i / N) * Math.PI * 2 + (Math.random() * 0.4);
            const dist = 70 + Math.random() * 50;
            const dx = Math.cos(angle) * dist;
            const dy = Math.sin(angle) * dist;
            const c = colors[Math.floor(Math.random() * colors.length)];
            p.style.cssText = `left:${x}px;top:${y}px;background:${c};
                box-shadow:0 0 10px ${c};
                transition: transform .95s cubic-bezier(.2,.7,.3,1), opacity .95s;`;
            document.body.appendChild(p);
            requestAnimationFrame(() => {
                p.style.transform = `translate(${dx}px, ${dy}px) scale(0)`;
                p.style.opacity = '0';
            });
            setTimeout(() => p.remove(), 1050);
        }
    }

    // ── Spring stepper ────────────────────────────────────────────────
    function attachSpringStepper(btn, getCurrent, setCurrent, delta) {
        let timer = null;
        let interval = 280;
        let count = 0;

        const step = () => {
            const v = clamp((getCurrent() | 0) + delta, 0, 999999999);
            setCurrent(v);
            count++;
            if (count > 3) interval = Math.max(40, interval * 0.78);
            timer = setTimeout(step, interval);
        };
        const start = (e) => {
            e.preventDefault();
            count = 0; interval = 280;
            step();
        };
        const stop = () => {
            if (timer) { clearTimeout(timer); timer = null; }
            interval = 280; count = 0;
        };

        btn.addEventListener('mousedown', start);
        btn.addEventListener('touchstart', start, { passive: false });
        ['mouseup', 'mouseleave', 'touchend', 'touchcancel'].forEach(ev =>
            btn.addEventListener(ev, stop)
        );
    }

    // ── Animate value change after save (rolling effect) ─────────────
    function animateValueChange(valueEl, oldVal, newVal) {
        if (REDUCED_MOTION) {
            valueEl.textContent = fmt(newVal);
            valueEl.dataset.value = String(newVal);
            return;
        }
        // Simple count-up animation
        const start = Number(oldVal) || 0;
        const end = Number(newVal) || 0;
        const duration = 700;
        const t0 = performance.now();
        const tick = (now) => {
            const t = Math.min(1, (now - t0) / duration);
            const eased = 1 - Math.pow(1 - t, 3); // easeOutCubic
            const cur = Math.round(start + (end - start) * eased);
            valueEl.textContent = fmt(cur);
            if (t < 1) requestAnimationFrame(tick);
            else valueEl.dataset.value = String(end);
        };
        requestAnimationFrame(tick);
    }

    // ── Open / close popup ────────────────────────────────────────────
    function closePopup() {
        if (!activePopup) return;
        const { backdrop, popup, escHandler } = activePopup;
        document.removeEventListener('keydown', escHandler);

        backdrop.classList.remove('km-show');
        popup.classList.remove('km-show');
        setTimeout(() => {
            backdrop?.remove();
            popup?.remove();
        }, 400);
        activePopup = null;
    }

    function openEditor(card) {
        if (activePopup) closePopup();

        const key = card.dataset.kpiKey;
        const label = card.dataset.kpiLabel || key;

        kmFetch('get', { kpi_key: key }).then(r => {
            if (r.status !== 'ok') {
                console.error('[km] get failed:', r.message);
                return;
            }

            // ค่าจริงจาก DB (server ส่งมาแล้ว — ไม่ใช้ค่าใน data-value
            // เพราะมันคือค่าที่ override ทับไปแล้ว)
            const autoValue = (typeof r.auto_value === 'number') ? r.auto_value : 0;
            const overrideValue = r.value !== null ? r.value : autoValue;
            const isOverride = r.is_active === 1;
            const note = r.note || '';

            // Build backdrop + popup
            const backdrop = document.createElement('div');
            backdrop.className = 'km-backdrop';
            document.body.appendChild(backdrop);

            const popup = document.createElement('div');
            popup.className = 'km-popup';
            popup.setAttribute('role', 'dialog');
            popup.setAttribute('aria-modal', 'true');
            popup.innerHTML = `
                <div class="km-form">
                    <div class="km-form-head">
                        <div class="km-icon-mini"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                        <div class="km-form-title" title="${escapeHtml(label)}">${escapeHtml(label)}</div>
                        <button type="button" class="km-form-close" data-km-cancel aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <div class="km-toggle-row" role="tablist">
                        <button type="button" class="km-toggle-btn ${!isOverride ? 'km-active' : ''}" data-km-mode="auto">
                            <i class="fa-solid fa-rotate"></i> ใช้ค่าจริง
                        </button>
                        <button type="button" class="km-toggle-btn ${isOverride ? 'km-active' : ''}" data-km-mode="override">
                            <i class="fa-solid fa-pen-to-square"></i> Override
                        </button>
                    </div>

                    <div class="km-stepper" data-km-pane="override">
                        <button type="button" class="km-step-btn" data-km-step="-1">−</button>
                        <input type="text" class="km-step-input" data-km-input value="${overrideValue}" inputmode="numeric">
                        <button type="button" class="km-step-btn" data-km-step="+1">+</button>
                    </div>

                    <div class="km-pane-auto" data-km-pane="auto">
                        <div class="km-pane-auto-value">${fmt(autoValue)}</div>
                        <div class="km-pane-auto-note">ค่าจริงจากระบบ — ไม่ override</div>
                    </div>

                    <textarea class="km-note" rows="2" data-km-note placeholder="หมายเหตุ (ไม่บังคับ) — เช่น 'ตามเอกสาร พ.ค. 68'">${escapeHtml(note)}</textarea>

                    <div class="km-actions">
                        <button type="button" class="km-btn km-btn-cancel" data-km-cancel>ยกเลิก</button>
                        <button type="button" class="km-btn km-btn-save" data-km-save>
                            <i class="fa-solid fa-check"></i> บันทึก
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(popup);

            // Wire mode toggle
            let mode = isOverride ? 'override' : 'auto';
            const updateMode = () => {
                popup.querySelectorAll('[data-km-mode]').forEach(b =>
                    b.classList.toggle('km-active', b.dataset.kmMode === mode));
                popup.querySelectorAll('[data-km-pane]').forEach(p =>
                    p.style.display = p.dataset.kmPane === mode ? '' : 'none');
            };
            updateMode();
            popup.querySelectorAll('[data-km-mode]').forEach(b =>
                b.addEventListener('click', () => { mode = b.dataset.kmMode; updateMode(); }));

            // Stepper
            const input = popup.querySelector('[data-km-input]');
            const upBtn = popup.querySelector('[data-km-step="+1"]');
            const dnBtn = popup.querySelector('[data-km-step="-1"]');
            attachSpringStepper(upBtn, () => parseInt(input.value, 10) || 0, v => input.value = v, +1);
            attachSpringStepper(dnBtn, () => parseInt(input.value, 10) || 0, v => input.value = v, -1);
            input.addEventListener('focus', () => input.select());

            // Cancel buttons + backdrop
            popup.querySelectorAll('[data-km-cancel]').forEach(b =>
                b.addEventListener('click', closePopup));
            backdrop.addEventListener('click', closePopup);

            // ESC key
            const escHandler = e => { if (e.key === 'Escape') closePopup(); };
            document.addEventListener('keydown', escHandler);

            // Save
            popup.querySelector('[data-km-save]').addEventListener('click', e => {
                const saveBtn = e.currentTarget;
                const rect = saveBtn.getBoundingClientRect();
                const cx = rect.left + rect.width / 2;
                const cy = rect.top + rect.height / 2;

                if (mode === 'auto') {
                    kmFetch('clear', { kpi_key: key }).then(r2 => {
                        if (r2.status === 'ok') finishSave(card, autoValue, false, cx, cy);
                        else if (window.Swal) Swal.fire({icon:'error',title:'ผิดพลาด',text:r2.message});
                    });
                } else {
                    const newVal = clamp(parseInt(input.value, 10) || 0, 0, 999999999);
                    const noteVal = popup.querySelector('[data-km-note]').value.trim();
                    kmFetch('set', { kpi_key: key, value: newVal, note: noteVal }).then(r2 => {
                        if (r2.status === 'ok') finishSave(card, newVal, true, cx, cy);
                        else if (window.Swal) Swal.fire({icon:'error',title:'ผิดพลาด',text:r2.message});
                    });
                }
            });

            activePopup = { backdrop, popup, escHandler };

            // Animate in
            requestAnimationFrame(() => {
                backdrop.classList.add('km-show');
                popup.classList.add('km-show');
                setTimeout(() => input.focus(), 300);
            });
        });
    }

    // ── Finalize save: close popup + animate value + burst + glow ─────
    function finishSave(card, newValue, willOverride, cx, cy) {
        const valueEl = card.querySelector('.km-value');
        const oldValue = parseInt(valueEl?.dataset.value || '0', 10);

        closePopup();

        // Update displayed value with smooth count-up
        if (valueEl) {
            animateValueChange(valueEl, oldValue, newValue);
        }

        // Toggle override badge
        let badge = card.querySelector('.km-override-badge');
        if (willOverride) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'km-override-badge';
                badge.textContent = 'OVERRIDE';
                card.appendChild(badge);
            }
        } else if (badge) {
            badge.remove();
        }

        // Celebrate
        burst(cx, cy);
        card.classList.remove('km-just-saved');
        // force reflow so animation can re-trigger
        void card.offsetWidth;
        card.classList.add('km-just-saved');
        setTimeout(() => card.classList.remove('km-just-saved'), 1000);
    }

    // ── Public API ──────────────────────────────────────────────────
    const KPIMorph = {
        init(opts) {
            CONFIG = Object.assign(CONFIG, opts || {});
            injectStyles();
            this.refresh();
        },

        refresh() {
            $$('.km-card[data-kpi-key]').forEach(card => {
                if (card.dataset.kmReady) return;
                card.dataset.kmReady = '1';
                if (!CONFIG.editable) return;

                card.classList.add('km-editable');

                if (!card.querySelector('.km-edit-btn')) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'km-edit-btn';
                    btn.title = 'แก้ไขค่า KPI นี้';
                    btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
                    btn.addEventListener('click', e => {
                        e.stopPropagation();
                        e.preventDefault();
                        openEditor(card);
                    });
                    card.appendChild(btn);
                }

                // Card-wide click also opens editor (better UX)
                card.addEventListener('click', e => {
                    if (e.target.closest('.km-edit-btn, .km-popup')) return;
                    if (!CONFIG.editable) return;
                    openEditor(card);
                });
            });
        },
    };

    window.KPIMorph = KPIMorph;
})();
