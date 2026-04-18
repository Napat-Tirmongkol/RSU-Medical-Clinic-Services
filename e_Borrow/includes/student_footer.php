<?php
// includes/student_footer.php

$active_page = $active_page ?? ''; 
?>

</main> 
<nav class="footer-nav">
    <a href="index.php" class="<?php echo ($active_page == 'home') ? 'active' : ''; ?>">
        <i class="fas fa-hand-holding-medical"></i>
        ยืมอยู่
    </a>
    <a href="borrow.php" class="<?php echo ($active_page == 'borrow') ? 'active' : ''; ?>">
        <i class="fas fa-boxes-stacked"></i>
        ยืมอุปกรณ์
    </a>
    <a href="history.php" class="<?php echo ($active_page == 'history') ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        ประวัติ
    </a>
    <a href="profile.php" class="<?php echo ($active_page == 'settings') ? 'active' : ''; ?>">
        <i class="fas fa-user-cog"></i>
        ตั้งค่า
    </a>
</nav>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/student_app.js?v=<?php echo time(); ?>"></script>

<script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
<script>
    // --- ตั้งค่า Auto Logout (JavaScript) ---
    // 30 นาที = 30 * 60 * 1000 = 1,800,000 ms
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
            icon: 'warning',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false
        }).then(() => {
            window.location.href = 'logout.php?reason=timeout'; 
        });
    }

    // ดักจับเหตุการณ์การขยับเมาส์ หรือการสัมผัสจอ
    window.onload = resetInactivityTimer;
    document.onmousemove = resetInactivityTimer;
    document.onkeypress = resetInactivityTimer;
    document.ontouchstart = resetInactivityTimer; 
    document.onclick = resetInactivityTimer;
    document.onscroll = resetInactivityTimer;

    // --- Smooth Page Transition (Fade Out ก่อนเปลี่ยนหน้า) ---
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href) {
            const url = new URL(link.href);
            const isLocal = url.origin === window.location.origin;
            const isAnchor = url.pathname === window.location.pathname && url.hash !== '';
            
            if (isLocal && !isAnchor && link.target !== '_blank' && link.getAttribute('href') !== '#') {
                e.preventDefault(); 
                document.body.classList.add('page-transitioning'); 
                setTimeout(() => {
                    window.location.href = link.href; 
                }, 200); 
            }
        }
    });
</script>
</body>
</html>