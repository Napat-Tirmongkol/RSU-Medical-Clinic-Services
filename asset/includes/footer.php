<?php // asset/includes/footer.php ?>
    </main>

    <footer class="mt-12 py-6 text-center text-xs text-slate-400">
        <i class="fa-solid fa-heart text-[#2e9e63]/60"></i>
        ระบบครุภัณฑ์สำนักงาน · RSU Medical Clinic Services · v1.0
    </footer>
</div>

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
</script>
</body>
</html>
