// RSU Medical Clinic — Guided tour helper
// Wraps Driver.js (loaded via CDN before this file) and adds:
//   - localStorage gating (tour_done_<area>)
//   - DOM-existence filter (skips steps whose element selector misses)
//   - Thai default labels
//   - Auto-start with debounce for SPA-ish renders
(function () {
    'use strict';

    const STORAGE_PREFIX = 'tour_done_';

    function getDriverApi() {
        // Driver.js v1.x exposes window.driver.js.driver
        if (window.driver && window.driver.js && typeof window.driver.js.driver === 'function') {
            return window.driver.js.driver;
        }
        return null;
    }

    function filterExistingSteps(steps) {
        return steps.filter(function (s) {
            if (!s.element) return true; // headless step (centered modal)
            try { return document.querySelector(s.element) !== null; }
            catch (e) { return false; }
        });
    }

    function isDone(areaKey) {
        try { return localStorage.getItem(STORAGE_PREFIX + areaKey) === '1'; }
        catch (e) { return false; }
    }

    function markDone(areaKey) {
        try { localStorage.setItem(STORAGE_PREFIX + areaKey, '1'); } catch (e) {}
    }

    function start(steps, areaKey, opts) {
        opts = opts || {};
        const driverApi = getDriverApi();
        if (!driverApi) { console.warn('[RsuTour] Driver.js not loaded'); return; }

        const filtered = filterExistingSteps(steps);
        if (filtered.length === 0) return;

        const tour = driverApi({
            showProgress: true,
            allowClose: true,
            overlayOpacity: 0.55,
            stagePadding: 6,
            stageRadius: 10,
            nextBtnText: opts.nextBtnText || 'ถัดไป →',
            prevBtnText: opts.prevBtnText || '← ก่อนหน้า',
            doneBtnText: opts.doneBtnText || 'เสร็จสิ้น',
            progressText: '{{current}} / {{total}}',
            onDestroyed: function () { if (areaKey) markDone(areaKey); },
            steps: filtered,
        });
        tour.drive();
    }

    function maybeAutoStart(areaKey, steps, opts) {
        if (isDone(areaKey)) return;
        // Wait until DOM is fully laid out (sidebar/cards rendered)
        const run = function () { setTimeout(function () { start(steps, areaKey, opts); }, 500); };
        if (document.readyState === 'complete') run();
        else window.addEventListener('load', run);
    }

    function reset(areaKey) {
        try { localStorage.removeItem(STORAGE_PREFIX + areaKey); } catch (e) {}
    }

    window.RsuTour = { start: start, maybeAutoStart: maybeAutoStart, reset: reset };
})();
