<?php // asset/includes/footer.php ?>
    </main>

    <footer class="mt-12 py-6 text-center text-xs text-slate-400">
        <i class="fa-solid fa-heart text-[#2e9e63]/60"></i>
        ระบบครุภัณฑ์สำนักงาน · RSU Medical Clinic Services · v1.0
    </footer>
</div>

<!-- Mobile scan FAB (hidden on desktop via CSS, shown by /asset/admin/scan.php route) -->
<?php if (($current_page ?? '') !== 'scan'): ?>
    <a href="admin/scan.php" class="scan-fab" aria-label="สแกน QR ครุภัณฑ์" title="สแกน QR">
        <i class="fa-solid fa-qrcode"></i>
    </a>
<?php endif; ?>

<!-- PWA install prompt button (hidden by default, shown when browser fires beforeinstallprompt) -->
<button id="asset-install-btn" class="install-prompt" type="button" aria-label="ติดตั้งเป็นแอป">
    <i class="fa-solid fa-download"></i>
    <span>ติดตั้งเป็นแอป</span>
</button>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/asset.js?v=1.0"></script>

<script>
// ── Sidebar toggle (desktop) ───────────────────────────────────────────────
window.assetToggleSidebar = function () {
    const sb   = document.getElementById('portal-sidebar');
    const icon = document.getElementById('asset-sb-icon');
    sb.classList.toggle('collapsed');
    const collapsed = sb.classList.contains('collapsed');
    if (icon) icon.style.transform = collapsed ? 'rotate(180deg)' : 'rotate(0deg)';
    document.cookie = 'asset_sidebar_collapsed=' + (collapsed ? '1' : '0') + ';path=/;max-age=' + (60*60*24*365);
    try { localStorage.setItem('asset-sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
};
(function () {
    const sb = document.getElementById('portal-sidebar');
    if (sb && sb.classList.contains('collapsed')) {
        const icon = document.getElementById('asset-sb-icon');
        if (icon) icon.style.transform = 'rotate(180deg)';
    }
})();

// ── Mobile menu (< 768px) ──────────────────────────────────────────────────
window.assetMobileMenu = function () {
    const sb = document.getElementById('portal-sidebar');
    sb.classList.toggle('mobile-open');
};
function assetUpdateMobileLayout() {
    const isMobile = window.innerWidth < 768;
    const bar      = document.getElementById('asset-mobile-bar');
    const shell    = document.getElementById('app-shell');
    if (bar)   bar.style.display   = isMobile ? 'flex' : 'none';
    if (shell) shell.style.paddingTop = isMobile ? '60px' : '';
}
window.addEventListener('resize', assetUpdateMobileLayout);
assetUpdateMobileLayout();

// ── PWA: Service Worker + Install prompt ───────────────────────────────────
(function () {
    if (!('serviceWorker' in navigator)) return;

    // Register on load (so it doesn't compete with critical rendering)
    window.addEventListener('load', () => {
        // Detect mount path dynamically (works for /asset/ AND /rsu-clinic/asset/ etc.)
        const m = window.location.pathname.match(/^(.*\/asset\/)/);
        if (!m) return;
        const scopePath = m[1];               // e.g. "/rsu-clinic/asset/"
        const swPath    = scopePath + 'sw.js';
        navigator.serviceWorker.register(swPath, { scope: scopePath })
            .then(reg => {
                // Listen for new versions
                reg.addEventListener('updatefound', () => {
                    const nw = reg.installing;
                    if (!nw) return;
                    nw.addEventListener('statechange', () => {
                        if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                            // New version waiting — could prompt user
                            console.info('[asset] New version installed; refresh to activate');
                        }
                    });
                });
            })
            .catch(err => console.warn('[asset] SW register failed:', err));
    });

    // Install prompt (Chrome/Edge/Android)
    let deferredPrompt = null;
    const btn = document.getElementById('asset-install-btn');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        if (btn) btn.classList.add('show');
    });

    if (btn) {
        btn.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            btn.classList.remove('show');
            deferredPrompt.prompt();
            const choice = await deferredPrompt.userChoice;
            deferredPrompt = null;
            if (choice.outcome === 'accepted') {
                console.info('[asset] User installed PWA');
            }
        });
    }

    // Hide install button if already installed
    window.addEventListener('appinstalled', () => {
        if (btn) btn.classList.remove('show');
        deferredPrompt = null;
    });
})();
</script>
</body>
</html>
