/**
 * assets/js/kpi-morph.js — Cinematic KPI Morph
 *
 * Apply override-edit capability to any KPI card with [data-kpi-key].
 *
 * Card markup contract:
 *   <div class="km-card" data-kpi-key="gold_total" data-kpi-label="ทั้งหมด">
 *       <div class="km-icon">...</div>
 *       <div class="km-body">
 *           <p class="km-label">...</p>
 *           <p class="km-value" data-value="1250">1,250</p>
 *       </div>
 *   </div>
 *
 * Bootstrap with: KPIMorph.init({ csrf, endpoint: 'ajax_kpi_override.php', editable: true })
 *
 * Effects (overdrive):
 *  - Hover: edit icon spring-pops in
 *  - Click: View Transitions API morphs card → edit form (same view-transition-name)
 *  - Stepper: spring physics; hold-down auto-repeats with acceleration
 *  - Save: rolling-digit transition + radial particle burst + ring glow
 *  - Override badge pulse if value is currently overridden
 *  - Honors prefers-reduced-motion (instant fade fallback)
 */
(function() {
    'use strict';

    const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const HAS_VIEW_TRANSITION = !REDUCED_MOTION && typeof document.startViewTransition === 'function';

    let CONFIG = { csrf: '', endpoint: 'ajax_kpi_override.php', editable: false };

    // ── Helpers ──────────────────────────────────────────────────────
    function $(s, root) { return (root || document).querySelector(s); }
    function $$(s, root) { return Array.from((root || document).querySelectorAll(s)); }
    function fmt(n) { return Number(n).toLocaleString('en-US'); }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

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
            .km-card { position: relative; }
            .km-card.km-editable { cursor: pointer; transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s; }
            .km-card.km-editable:hover { transform: translateY(-2px); }
            .km-card .km-edit-btn {
                position: absolute; top: 10px; right: 10px;
                width: 32px; height: 32px;
                border-radius: 10px; border: none;
                background: rgba(15,23,42,.85); color: #fff;
                cursor: pointer; opacity: 0; pointer-events: none;
                transition: opacity .2s, transform .25s cubic-bezier(.34,1.56,.64,1);
                transform: scale(.6) rotate(-12deg);
                display: flex; align-items: center; justify-content: center;
                font-size: 12px; z-index: 5;
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

            /* Edit Form (when card is replaced via View Transition) */
            .km-form { display: flex; flex-direction: column; gap: 14px; }
            .km-form-head {
                display: flex; align-items: center; gap: 10px;
                padding-bottom: 10px; border-bottom: 1px dashed #e2e8f0;
            }
            .km-form-head .km-icon-mini {
                width: 30px; height: 30px; border-radius: 8px;
                background: #fef3c7; color: #b45309;
                display: flex; align-items: center; justify-content: center; font-size: 12px;
            }
            .km-form-title { font-size: 11px; font-weight: 900; color: #475569;
                text-transform: uppercase; letter-spacing: .1em; flex: 1; min-width: 0; }
            .km-form-close {
                width: 26px; height: 26px; border-radius: 8px; border: 1px solid #e2e8f0;
                background: #fff; color: #64748b; cursor: pointer; font-size: 11px;
                display: flex; align-items: center; justify-content: center;
            }

            /* Rolling digits */
            .km-digits {
                display: flex; align-items: center; justify-content: center;
                font-weight: 900; line-height: 1; letter-spacing: -.02em;
                font-size: 2.5rem; color: #0f172a;
                font-variant-numeric: tabular-nums;
                user-select: none;
            }
            .km-digit {
                position: relative; height: 1em; width: .65em; overflow: hidden;
            }
            .km-digit-stack {
                position: absolute; top: 0; left: 0; right: 0;
                transition: transform .55s cubic-bezier(.4,1.6,.5,1);
            }
            .km-digit-stack > span { display: block; height: 1em; text-align: center; }
            .km-digit-comma { width: .25em; text-align: center; }

            /* Stepper (spring physics) */
            .km-stepper { display: flex; align-items: center; justify-content: center; gap: 12px; }
            .km-step-btn {
                width: 48px; height: 48px; border-radius: 14px;
                border: 1.5px solid #e2e8f0; background: #fff;
                font-size: 20px; font-weight: 900; color: #475569; cursor: pointer;
                transition: transform .15s cubic-bezier(.34,1.56,.64,1),
                            background .15s, border-color .15s;
                display: flex; align-items: center; justify-content: center;
                user-select: none;
            }
            .km-step-btn:hover { background: #f1f5f9; border-color: #cbd5e1; }
            .km-step-btn:active { transform: scale(.88); background: #e2e8f0; }
            .km-step-input {
                width: 110px; height: 48px;
                border: 1.5px solid #e2e8f0; border-radius: 14px;
                background: #f8fafc; text-align: center;
                font-size: 18px; font-weight: 900; color: #0f172a;
                font-variant-numeric: tabular-nums; outline: none;
                transition: border-color .15s, box-shadow .15s, background .15s;
            }
            .km-step-input:focus { border-color: #f59e0b; background: #fff;
                box-shadow: 0 0 0 4px rgba(245,158,11,.12); }

            /* Toggle */
            .km-toggle-row {
                display: flex; gap: 8px;
                background: #f1f5f9; padding: 4px; border-radius: 12px;
            }
            .km-toggle-btn {
                flex: 1; padding: 8px 10px; border-radius: 9px;
                border: none; background: transparent; cursor: pointer;
                font-size: 11px; font-weight: 900; color: #64748b;
                transition: background .2s, color .2s, transform .25s cubic-bezier(.34,1.56,.64,1);
            }
            .km-toggle-btn.km-active {
                background: #fff; color: #0f172a;
                box-shadow: 0 2px 8px rgba(15,23,42,.08);
            }

            /* Note */
            .km-note {
                width: 100%; padding: 9px 12px; border: 1.5px solid #e2e8f0;
                border-radius: 10px; background: #fafafa; font-size: 12px;
                font-weight: 700; color: #475569; outline: none; resize: none;
                font-family: inherit;
            }
            .km-note:focus { border-color: #f59e0b; background: #fff; }

            /* Action buttons */
            .km-actions { display: flex; gap: 8px; margin-top: 4px; }
            .km-btn {
                flex: 1; height: 38px; border-radius: 11px; border: none;
                font-size: 12px; font-weight: 900; cursor: pointer;
                transition: transform .12s, background .15s, box-shadow .15s;
            }
            .km-btn-cancel { background: #f1f5f9; color: #475569; }
            .km-btn-cancel:hover { background: #e2e8f0; }
            .km-btn-save {
                background: linear-gradient(135deg, #f59e0b, #ef4444);
                color: #fff; box-shadow: 0 6px 14px -3px rgba(245,158,11,.4);
                flex: 2;
            }
            .km-btn-save:hover { transform: translateY(-1px); box-shadow: 0 8px 18px -3px rgba(245,158,11,.55); }
            .km-btn-save:active { transform: translateY(1px); }

            /* Save success burst (overdrive) */
            .km-burst {
                position: fixed; pointer-events: none; z-index: 9999;
                width: 8px; height: 8px; border-radius: 50%;
            }
            .km-ring {
                position: absolute; pointer-events: none;
                border-radius: inherit; opacity: 0;
                box-shadow: 0 0 0 0 rgba(245,158,11,.7);
                animation: km-ring 1s cubic-bezier(.4,0,.2,1) forwards;
            }
            @keyframes km-ring {
                0% { box-shadow: 0 0 0 0 rgba(245,158,11,.7); opacity: .8; }
                100% { box-shadow: 0 0 0 30px rgba(245,158,11,0); opacity: 0; }
            }

            /* View Transition naming */
            .km-card[data-vt-name] { view-transition-name: var(--km-vt); }

            ::view-transition-old(.km-vt-card),
            ::view-transition-new(.km-vt-card) {
                animation-duration: 480ms;
                animation-timing-function: cubic-bezier(.34,1.56,.64,1);
            }

            /* Backdrop dim during edit */
            .km-backdrop {
                position: fixed; inset: 0; z-index: 90;
                background: rgba(15,23,42,.32); backdrop-filter: blur(3px);
                opacity: 0; transition: opacity .35s;
            }
            .km-backdrop.km-show { opacity: 1; }

            .km-card.km-editing {
                position: relative; z-index: 100;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,.35),
                            0 0 0 4px rgba(245,158,11,.12);
                transform: translateY(-2px);
            }

            @media (prefers-reduced-motion: reduce) {
                .km-digit-stack { transition: none !important; }
                .km-card { transition: none !important; }
                .km-burst, .km-ring { animation: none !important; display: none !important; }
                .km-override-badge { animation: none !important; }
            }
        `;
        document.head.appendChild(s);
    }

    // ── Rolling digit renderer ──────────────────────────────────────
    function renderDigits(value) {
        const str = fmt(value);
        return Array.from(str).map(ch => {
            if (ch === ',') return `<span class="km-digit-comma">,</span>`;
            if (!/\d/.test(ch)) return `<span class="km-digit-comma">${ch}</span>`;
            const stack = Array.from({ length: 10 }, (_, i) => `<span>${i}</span>`).join('');
            const offset = -parseInt(ch, 10);
            return `<span class="km-digit"><span class="km-digit-stack" style="transform: translateY(${offset}em)">${stack}</span></span>`;
        }).join('');
    }

    function animateDigitsTo(container, newValue) {
        // Re-render with new digit count if needed; otherwise just shift stacks
        const oldStr = container.dataset.currentValue || '0';
        const newStr = fmt(newValue);
        if (oldStr.length !== newStr.length) {
            container.innerHTML = renderDigits(newValue);
            container.dataset.currentValue = newStr;
            return;
        }
        const stacks = $$('.km-digit-stack', container);
        let stackIdx = 0;
        Array.from(newStr).forEach(ch => {
            if (/\d/.test(ch)) {
                const offset = -parseInt(ch, 10);
                if (stacks[stackIdx]) stacks[stackIdx].style.transform = `translateY(${offset}em)`;
                stackIdx++;
            }
        });
        container.dataset.currentValue = newStr;
    }

    // ── Particle burst (overdrive) ──────────────────────────────────
    function burst(x, y) {
        if (REDUCED_MOTION) return;
        const colors = ['#f59e0b', '#fbbf24', '#fb923c', '#ef4444'];
        const N = 14;
        for (let i = 0; i < N; i++) {
            const p = document.createElement('div');
            p.className = 'km-burst';
            const angle = (i / N) * Math.PI * 2 + (Math.random() * 0.4);
            const dist = 60 + Math.random() * 40;
            const dx = Math.cos(angle) * dist;
            const dy = Math.sin(angle) * dist;
            const c = colors[Math.floor(Math.random() * colors.length)];
            p.style.cssText = `left:${x}px;top:${y}px;background:${c};
                box-shadow:0 0 8px ${c};
                transition: transform .9s cubic-bezier(.2,.7,.3,1), opacity .9s;`;
            document.body.appendChild(p);
            requestAnimationFrame(() => {
                p.style.transform = `translate(${dx}px, ${dy}px) scale(0)`;
                p.style.opacity = '0';
            });
            setTimeout(() => p.remove(), 1000);
        }
    }

    function ringGlow(card) {
        if (REDUCED_MOTION) return;
        const r = document.createElement('div');
        r.className = 'km-ring';
        r.style.cssText = `inset:0;border-radius:inherit`;
        card.appendChild(r);
        setTimeout(() => r.remove(), 1100);
    }

    // ── Spring stepper (hold-to-repeat with acceleration) ──────────
    function attachSpringStepper(btn, getCurrent, setCurrent, delta) {
        let timer = null;
        let interval = 280;
        let count = 0;

        const step = () => {
            const v = clamp((getCurrent() | 0) + delta, 0, 999999999);
            setCurrent(v);
            count++;
            // accelerate
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

    // ── Active editor (only one at a time) ─────────────────────────
    let activeEditor = null;

    function closeActive(restore = true) {
        if (!activeEditor) return;
        const { card, originalHTML, backdrop } = activeEditor;

        const finalize = () => {
            if (restore) card.innerHTML = originalHTML;
            card.classList.remove('km-editing');
            card.style.removeProperty('--km-vt');
            card.style.removeProperty('view-transition-name');
            card.removeAttribute('data-vt-name');
            backdrop?.classList.remove('km-show');
            setTimeout(() => backdrop?.remove(), 350);
            activeEditor = null;
        };

        if (HAS_VIEW_TRANSITION && restore) {
            const vt = document.startViewTransition(finalize);
            vt.finished.catch(() => {});
        } else {
            finalize();
        }
    }

    // ── Open editor on a card ──────────────────────────────────────
    function openEditor(card) {
        if (activeEditor) closeActive(true);

        const key = card.dataset.kpiKey;
        const label = card.dataset.kpiLabel || key;

        // Show loading state briefly
        card.style.pointerEvents = 'none';

        kmFetch('get', { kpi_key: key }).then(r => {
            card.style.pointerEvents = '';
            if (r.status !== 'ok') {
                console.error('[km] get failed:', r.message);
                return;
            }

            const valueEl = card.querySelector('.km-value');
            const autoValue = parseInt(valueEl?.dataset.value || '0', 10);
            const overrideValue = r.value !== null ? r.value : autoValue;
            const isOverride = r.is_active === 1;
            const note = r.note || '';

            const originalHTML = card.innerHTML;
            const vtName = `kpi-${key}-${Date.now()}`;

            // Backdrop
            const backdrop = document.createElement('div');
            backdrop.className = 'km-backdrop';
            document.body.appendChild(backdrop);
            backdrop.addEventListener('click', () => closeActive(true));
            requestAnimationFrame(() => backdrop.classList.add('km-show'));

            const renderEdit = () => {
                card.classList.add('km-editing');
                card.innerHTML = `
                    <div class="km-form">
                        <div class="km-form-head">
                            <div class="km-icon-mini"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                            <div class="km-form-title">${escapeHtml(label)}</div>
                            <button type="button" class="km-form-close" data-km-cancel><i class="fa-solid fa-xmark"></i></button>
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

                        <div data-km-pane="auto" style="text-align:center; padding: 12px; font-size: 12px; color: #64748b; font-weight: 800;">
                            <p style="font-size: 24px; font-weight: 900; color: #0f172a; margin-bottom: 4px;">${fmt(autoValue)}</p>
                            <p>ค่าจริงจากระบบ — ไม่ override</p>
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

                // Wire events
                let mode = isOverride ? 'override' : 'auto';
                const updateMode = () => {
                    card.querySelectorAll('[data-km-mode]').forEach(b =>
                        b.classList.toggle('km-active', b.dataset.kmMode === mode));
                    card.querySelectorAll('[data-km-pane]').forEach(p =>
                        p.style.display = p.dataset.kmPane === mode ? '' : 'none');
                };
                updateMode();
                card.querySelectorAll('[data-km-mode]').forEach(b =>
                    b.addEventListener('click', () => { mode = b.dataset.kmMode; updateMode(); }));

                const input = card.querySelector('[data-km-input]');
                const upBtn = card.querySelector('[data-km-step="+1"]');
                const dnBtn = card.querySelector('[data-km-step="-1"]');

                attachSpringStepper(upBtn, () => parseInt(input.value, 10) || 0, v => input.value = v, +1);
                attachSpringStepper(dnBtn, () => parseInt(input.value, 10) || 0, v => input.value = v, -1);
                input.addEventListener('focus', () => input.select());

                card.querySelectorAll('[data-km-cancel]').forEach(b =>
                    b.addEventListener('click', () => closeActive(true)));

                card.querySelector('[data-km-save]').addEventListener('click', e => {
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
                        const noteVal = card.querySelector('[data-km-note]').value.trim();
                        kmFetch('set', { kpi_key: key, value: newVal, note: noteVal }).then(r2 => {
                            if (r2.status === 'ok') finishSave(card, newVal, true, cx, cy);
                            else if (window.Swal) Swal.fire({icon:'error',title:'ผิดพลาด',text:r2.message});
                        });
                    }
                });

                // ESC to close
                const onKey = e => { if (e.key === 'Escape') { closeActive(true); document.removeEventListener('keydown', onKey); }};
                document.addEventListener('keydown', onKey);

                input.focus();
            };

            // View Transition morph
            if (HAS_VIEW_TRANSITION) {
                card.style.setProperty('view-transition-name', vtName);
                card.dataset.vtName = vtName;
                const vt = document.startViewTransition(renderEdit);
                vt.finished.catch(() => {});
            } else {
                renderEdit();
            }

            activeEditor = { card, originalHTML, backdrop, key };
        });
    }

    // ── Finalize save: reverse morph + animate digit + burst + ring ─
    function finishSave(card, newValue, willOverride, cx, cy) {
        const { originalHTML, backdrop } = activeEditor;
        const finalize = () => {
            card.innerHTML = originalHTML;
            card.classList.remove('km-editing');
            card.style.removeProperty('--km-vt');
            card.style.removeProperty('view-transition-name');
            card.removeAttribute('data-vt-name');
            backdrop?.classList.remove('km-show');
            setTimeout(() => backdrop?.remove(), 350);

            // Update displayed value
            const valueEl = card.querySelector('.km-value');
            if (valueEl) {
                valueEl.dataset.value = String(newValue);
                valueEl.textContent = fmt(newValue);
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
            } else {
                if (badge) badge.remove();
            }

            // Celebrate
            burst(cx, cy);
            ringGlow(card);

            activeEditor = null;
        };

        if (HAS_VIEW_TRANSITION) {
            const vt = document.startViewTransition(finalize);
            vt.finished.catch(() => {});
        } else {
            finalize();
        }
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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

                // Inject edit button if not present
                if (!card.querySelector('.km-edit-btn')) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'km-edit-btn';
                    btn.title = 'แก้ไขค่า KPI นี้';
                    btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
                    btn.addEventListener('click', e => {
                        e.stopPropagation();
                        openEditor(card);
                    });
                    card.appendChild(btn);
                }
            });
        },
    };

    window.KPIMorph = KPIMorph;
})();
