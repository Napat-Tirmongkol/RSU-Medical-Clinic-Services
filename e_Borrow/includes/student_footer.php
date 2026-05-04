<?php
// e_Borrow/includes/student_footer.php — closes shell, renders unified bottom nav
$active_page = $active_page ?? '';
?>
    </main>

    <?php $__navActive = ''; $__navBase = '../user/'; require __DIR__ . '/../../includes/user_bottom_nav.php'; ?>
</div>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/student_app.js?v=<?= time() ?>"></script>
<script>
    // Auto logout — 30 minutes idle
    const INACTIVITY_LIMIT = 1800000;
    let inactivityTimer;
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(doLogout, INACTIVITY_LIMIT);
    }
    function doLogout() {
        Swal.fire({
            title: 'หมดเวลาการใช้งาน',
            text: 'คุณไม่มีการใช้งานนานเกินไป ระบบจะออกจากระบบอัตโนมัติ',
            icon: 'warning', timer: 3000, timerProgressBar: true,
            showConfirmButton: false, allowOutsideClick: false
        }).then(() => { window.location.href = 'logout.php?reason=timeout'; });
    }
    ['load','mousemove','keypress','touchstart','click','scroll'].forEach(evt =>
        window.addEventListener(evt, resetInactivityTimer, { passive: true }));

    // Smooth page transition for internal links
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link || !link.href) return;
        const url = new URL(link.href);
        const isLocal = url.origin === window.location.origin;
        const isAnchor = url.pathname === window.location.pathname && url.hash !== '';
        if (isLocal && !isAnchor && link.target !== '_blank' && link.getAttribute('href') !== '#') {
            e.preventDefault();
            document.body.classList.add('page-transitioning');
            setTimeout(() => { window.location.href = link.href; }, 200);
        }
    });
</script>
</body>
</html>
