<?php // consumables/includes/footer.php ?>
    </main>

    <footer class="mt-12 py-6 text-center text-xs text-slate-400">
        <i class="fa-solid fa-heart text-[#2e9e63]/60"></i>
        ระบบวัสดุสิ้นเปลือง · RSU Medical Clinic Services · v1.0
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── Sidebar toggle (desktop) ──────────────────────────────────────────────
window.csmToggleSidebar = function () {
    const sb   = document.getElementById('portal-sidebar');
    const icon = document.getElementById('csm-sb-icon');
    sb.classList.toggle('collapsed');
    const collapsed = sb.classList.contains('collapsed');
    if (icon) icon.style.transform = collapsed ? 'rotate(180deg)' : 'rotate(0deg)';
    document.cookie = 'csm_sidebar_collapsed=' + (collapsed ? '1' : '0') + ';path=/;max-age=' + (60*60*24*365);
    try { localStorage.setItem('csm-sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
};
(function () {
    const sb = document.getElementById('portal-sidebar');
    if (sb && sb.classList.contains('collapsed')) {
        const icon = document.getElementById('csm-sb-icon');
        if (icon) icon.style.transform = 'rotate(180deg)';
    }
})();

// ── Mobile menu (< 768px) ─────────────────────────────────────────────────
window.csmMobileMenu = function () {
    const sb = document.getElementById('portal-sidebar');
    sb.classList.toggle('mobile-open');
};
function csmUpdateMobileLayout() {
    const isMobile = window.innerWidth < 768;
    const bar      = document.getElementById('csm-mobile-bar');
    const shell    = document.getElementById('app-shell');
    if (bar)   bar.style.display      = isMobile ? 'flex' : 'none';
    if (shell) shell.style.paddingTop = isMobile ? '60px' : '';
}
window.addEventListener('resize', csmUpdateMobileLayout);
csmUpdateMobileLayout();

// ── Confirm delete helper ─────────────────────────────────────────────────
window.csmConfirmDelete = function (url, name) {
    Swal.fire({
        title: 'ยืนยันการลบ',
        html: 'ลบรายการ <strong>' + name + '</strong>?<br><span class="text-rose-500 text-sm">การลบจะลบประวัติการเคลื่อนไหวทั้งหมดด้วย</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
    }).then((r) => {
        if (r.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            const t = document.createElement('input');
            t.type = 'hidden'; t.name = 'csrf_token';
            t.value = document.querySelector('meta[name=csrf-token]')?.content || '';
            form.appendChild(t);
            document.body.appendChild(form);
            form.submit();
        }
    });
};
</script>
</body>
</html>
