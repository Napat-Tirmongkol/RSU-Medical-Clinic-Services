/* ══════════════════════════════════════════════════════════════
   rsu-fx.js — Visual flourishes for the RSU portal
   - CountUp: animate numbers when they scroll into view
   - Tilt + Glow follow: 3D tilt and cursor-following glow on cards
   - Skeleton helper: insert/remove shimmer placeholders

   All effects respect prefers-reduced-motion. Auto-initializes
   on DOMContentLoaded. Re-call window.RsuFx.refresh() after
   dynamically inserting new markup that should be animated.
   ══════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── 1. CountUp ─────────────────────────────────────────── */
    const countedSet = new WeakSet();

    function runCountUp(el) {
        if (countedSet.has(el)) return;
        countedSet.add(el);
        const target = parseFloat(el.dataset.counter) || 0;
        const decimals = parseInt(el.dataset.counterDecimals, 10) || 0;
        if (reduced || target === 0) {
            el.textContent = target.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
            return;
        }
        const duration = parseInt(el.dataset.counterDuration, 10) || 1200;
        const start = performance.now();
        const easeOut = t => 1 - Math.pow(1 - t, 3);
        const fmt = v => v.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        function tick(now) {
            const p = Math.min((now - start) / duration, 1);
            const current = easeOut(p) * target;
            el.textContent = fmt(decimals ? current : Math.floor(current));
            if (p < 1) requestAnimationFrame(tick);
            else el.textContent = fmt(target);
        }
        requestAnimationFrame(tick);
    }

    let countObserver = null;
    function initCountUp(root) {
        const scope = root || document;
        const els = scope.querySelectorAll('[data-counter]:not([data-counter-init])');
        if (!els.length) return;
        els.forEach(el => el.setAttribute('data-counter-init', '1'));

        if (!('IntersectionObserver' in window) || reduced) {
            els.forEach(runCountUp);
            return;
        }
        if (!countObserver) {
            countObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        runCountUp(entry.target);
                        countObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.35, rootMargin: '0px 0px -10% 0px' });
        }
        els.forEach(el => countObserver.observe(el));
    }

    /* ── 2. Tilt + Glow follow ──────────────────────────────── */
    function initTilt(root) {
        if (reduced) return;
        // Skip on touch devices — tilt is awkward without precise pointer
        if (window.matchMedia('(hover: none)').matches) return;

        const scope = root || document;
        const els = scope.querySelectorAll('.fx-tilt:not([data-fx-tilt-init])');
        els.forEach(el => {
            el.setAttribute('data-fx-tilt-init', '1');
            const maxTilt = parseFloat(el.dataset.tilt) || 6; // degrees

            function onMove(e) {
                const r = el.getBoundingClientRect();
                const x = (e.clientX - r.left) / r.width;
                const y = (e.clientY - r.top) / r.height;
                const ry = (x - 0.5) * maxTilt * 2;        // rotateY
                const rx = (0.5 - y) * maxTilt * 2;        // rotateX (inverted)
                el.style.setProperty('--rx', rx.toFixed(2) + 'deg');
                el.style.setProperty('--ry', ry.toFixed(2) + 'deg');
                el.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
                el.style.setProperty('--my', (y * 100).toFixed(1) + '%');
            }
            function onEnter() { el.classList.add('is-hovering'); }
            function onLeave() {
                el.classList.remove('is-hovering');
                el.style.setProperty('--rx', '0deg');
                el.style.setProperty('--ry', '0deg');
            }

            el.addEventListener('pointermove', onMove);
            el.addEventListener('pointerenter', onEnter);
            el.addEventListener('pointerleave', onLeave);
        });
    }

    /* ── 3. Skeleton helper ─────────────────────────────────── */
    function skeleton(target, opts) {
        opts = opts || {};
        const el = typeof target === 'string' ? document.querySelector(target) : target;
        if (!el) return null;
        const rows = opts.rows || 3;
        const variant = opts.variant || 'rows';   // 'rows' | 'cards' | 'table'
        el.dataset.skelOriginal = el.innerHTML;
        let html = '';
        if (variant === 'cards') {
            html = '<div class="skel-cards">';
            for (let i = 0; i < rows; i++) {
                html += '<div class="skel skel-card"><div class="skel skel-line w-40"></div><div class="skel skel-line"></div><div class="skel skel-line w-60"></div></div>';
            }
            html += '</div>';
        } else if (variant === 'table') {
            html = '<div class="skel-table">';
            for (let i = 0; i < rows; i++) {
                html += '<div class="skel-table-row">' +
                    '<div class="skel skel-circle"></div>' +
                    '<div class="skel skel-line flex-1"></div>' +
                    '<div class="skel skel-line w-30"></div>' +
                    '<div class="skel skel-line w-20"></div>' +
                '</div>';
            }
            html += '</div>';
        } else {
            html = '<div class="skel-rows">';
            for (let i = 0; i < rows; i++) {
                const w = i === rows - 1 ? 'w-60' : (i % 2 ? 'w-80' : '');
                html += '<div class="skel skel-line ' + w + '"></div>';
            }
            html += '</div>';
        }
        el.innerHTML = html;
        el.classList.add('skel-host');
        return el;
    }
    function unskeleton(target, newHtml) {
        const el = typeof target === 'string' ? document.querySelector(target) : target;
        if (!el) return;
        if (typeof newHtml === 'string') {
            el.innerHTML = newHtml;
        } else if (el.dataset.skelOriginal) {
            el.innerHTML = el.dataset.skelOriginal;
            delete el.dataset.skelOriginal;
        }
        el.classList.remove('skel-host');
    }

    /* ── 4. Public API + auto-init ──────────────────────────── */
    function refresh(root) {
        initCountUp(root);
        initTilt(root);
    }
    window.RsuFx = { refresh, countUp: initCountUp, tilt: initTilt, skeleton, unskeleton };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => refresh());
    } else {
        refresh();
    }
})();
