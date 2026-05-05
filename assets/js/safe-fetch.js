/**
 * assets/js/safe-fetch.js
 *
 * Drop-in replacement for `fetch().then(r => r.json())` that:
 *   - Detects HTTP errors (404, 500, …) before parsing JSON
 *   - Detects HTML/non-JSON responses (e.g. when an endpoint silently
 *     redirected to a login page or 404 page)
 *   - Reports the failure to the server via /portal/ajax_log_404.php
 *   - Shows a SweetAlert2 toast/dialog so the user gets feedback instead
 *     of a silent broken page
 *
 * Usage:
 *   const data = await safeFetch('ajax_foo.php?action=bar');
 *   if (!data) return;                    // request failed — already alerted
 *   if (data.status !== 'ok') { ... }     // server-level error
 *
 *   // POST:
 *   const data = await safeFetch('ajax_foo.php', { method: 'POST', body: fd });
 */
(function (global) {
    'use strict';

    // ── Resolve the absolute URL of ajax_log_404.php once on load ────────────
    // The script may be loaded from any depth (portal/, user/, …) so we
    // walk up to the deployment root and append the portal path.
    const LOG_ENDPOINT = (function () {
        const here = document.currentScript ? document.currentScript.src : '';
        if (here) {
            // safe-fetch.js lives at /<root>/assets/js/safe-fetch.js
            // → log endpoint at /<root>/portal/ajax_log_404.php
            try {
                const u = new URL(here, window.location.href);
                return u.pathname.replace(/\/assets\/js\/safe-fetch\.js.*$/, '') + '/portal/ajax_log_404.php';
            } catch (e) { /* fall through */ }
        }
        return '/portal/ajax_log_404.php';
    })();

    // ── Throttle duplicate reports (same URL+status within 30s) ──────────────
    const reportCache = new Map();
    function shouldReport(url, status) {
        const key = url + '|' + status;
        const now = Date.now();
        const last = reportCache.get(key) || 0;
        if (now - last < 30000) return false;
        reportCache.set(key, now);
        return true;
    }

    function reportError(url, status, message, extra) {
        if (!shouldReport(url, status)) return;
        try {
            const payload = JSON.stringify({
                url: url,
                status: status,
                referrer: window.location.href,
                message: message || ('HTTP ' + status),
                context: extra || null,
            });
            // Prefer sendBeacon so the report survives page-unload races;
            // fall back to fetch keepalive when the API is unavailable.
            if (navigator.sendBeacon) {
                navigator.sendBeacon(LOG_ENDPOINT, new Blob([payload], { type: 'application/json' }));
            } else {
                fetch(LOG_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload,
                    keepalive: true,
                }).catch(() => { /* swallow — logging must never throw */ });
            }
        } catch (_) { /* never break the caller because logging failed */ }
    }

    function showAlert(opts) {
        if (typeof Swal === 'undefined') {
            console.error('[safeFetch]', opts.title, opts.text);
            return;
        }
        Swal.fire(Object.assign({
            icon: 'error',
            toast: false,
            confirmButtonColor: '#0f766e',
        }, opts));
    }

    /**
     * @param {string} url
     * @param {RequestInit} [options]
     * @param {{ silent?: boolean, expectJson?: boolean }} [config]
     *   silent     — don't show SweetAlert2 (caller will handle UI)
     *   expectJson — set false for fetches that intentionally return text/blob
     * @returns {Promise<object|null>}  parsed JSON, or null on failure
     */
    async function safeFetch(url, options, config) {
        options = options || {};
        config  = config  || {};
        const expectJson = config.expectJson !== false;
        const silent     = config.silent === true;

        let response;
        try {
            response = await fetch(url, options);
        } catch (err) {
            if (!silent) {
                showAlert({
                    icon: 'error',
                    title: 'เชื่อมต่อไม่ได้',
                    text: 'ตรวจสอบอินเทอร์เน็ตแล้วลองอีกครั้ง',
                });
            }
            reportError(url, 0, 'network error: ' + (err && err.message ? err.message : 'unknown'));
            return null;
        }

        // ── HTTP-level errors (4xx, 5xx) ─────────────────────────────────────
        if (!response.ok) {
            const status = response.status;
            reportError(url, status, response.statusText || ('HTTP ' + status));
            if (!silent) {
                if (status === 404) {
                    showAlert({
                        icon: 'warning',
                        title: '404 ไม่พบหน้า',
                        html: 'ไม่พบไฟล์ปลายทาง — อาจถูกย้าย ลบออก หรือ deploy ยังไม่ครบ<br><code style="font-size:.78rem; word-break:break-all;">' + escapeHtml(url) + '</code>',
                    });
                } else if (status === 403) {
                    showAlert({
                        icon: 'warning',
                        title: 'ไม่มีสิทธิ์เข้าถึง (403)',
                        text: 'บัญชีของคุณไม่ได้รับอนุญาตให้ทำรายการนี้',
                    });
                } else if (status === 401) {
                    showAlert({
                        icon: 'warning',
                        title: 'หมดอายุการล็อกอิน (401)',
                        text: 'กรุณาล็อกอินใหม่อีกครั้ง',
                    });
                } else if (status >= 500) {
                    showAlert({
                        icon: 'error',
                        title: 'เซิร์ฟเวอร์ผิดพลาด (' + status + ')',
                        text: 'ระบบบันทึก error แล้ว — ลองอีกครั้งหรือแจ้งผู้ดูแล',
                    });
                } else {
                    showAlert({
                        icon: 'error',
                        title: 'HTTP ' + status,
                        text: response.statusText || 'request failed',
                    });
                }
            }
            return null;
        }

        if (!expectJson) return response;

        // ── Body-level checks: ensure JSON, not an HTML error page ──────────
        const ctype = (response.headers.get('Content-Type') || '').toLowerCase();
        const text  = await response.text();

        if (ctype.indexOf('application/json') === -1) {
            // Common case: silent redirect to login page returns 200 + HTML.
            // Treat as auth failure so user knows what happened.
            const looksLikeHtml = /^\s*<(!doctype|html)/i.test(text);
            reportError(url, response.status, 'non-JSON response (content-type=' + ctype + ')', {
                snippet: text.substring(0, 300),
            });
            if (!silent) {
                showAlert({
                    icon: 'warning',
                    title: looksLikeHtml ? 'เซสชันหมดอายุ?' : 'รูปแบบข้อมูลผิด',
                    text: looksLikeHtml
                        ? 'เซิร์ฟเวอร์ส่ง HTML แทน JSON — มักเกิดเมื่อล็อกอินหมดอายุ กรุณารีเฟรชหน้า'
                        : 'เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON',
                });
            }
            return null;
        }

        try {
            return JSON.parse(text);
        } catch (err) {
            reportError(url, response.status, 'JSON parse error: ' + err.message, {
                snippet: text.substring(0, 300),
            });
            if (!silent) {
                showAlert({
                    icon: 'error',
                    title: 'อ่านข้อมูลไม่ได้',
                    text: 'JSON ผิดรูป — ระบบบันทึก error แล้ว',
                });
            }
            return null;
        }
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, m => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
        ));
    }

    global.safeFetch = safeFetch;
    global.safeFetch.reportError = reportError;
})(window);
