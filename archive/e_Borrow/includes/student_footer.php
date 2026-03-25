<?php
// [������: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/includes/student_footer.php]
// includes/student_footer.php

$active_page = $active_page ?? ''; 
?>

</main> 
<nav class="footer-nav">
    <a href="index.php" class="<?php echo ($active_page == 'home') ? 'active' : ''; ?>">
        <i class="fas fa-hand-holding-medical"></i>
        ����������
    </a>
    <a href="borrow.php" class="<?php echo ($active_page == 'borrow') ? 'active' : ''; ?>">
        <i class="fas fa-boxes-stacked"></i>
        ����ػ�ó�
    </a>
    <a href="history.php" class="<?php echo ($active_page == 'history') ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        ����ѵ�
    </a>
    <a href="profile.php" class="<?php echo ($active_page == 'settings') ? 'active' : ''; ?>">
        <i class="fas fa-user-cog"></i>
        ��駤��
    </a>
</nav>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/student_app.js?v=<?php echo time(); ?>"></script>

<script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
<script>
    // --- ? ��駤�� Auto Logout (JavaScript) ---
    // ����������ç���͹��¡��� PHP �Դ˹��� (˹����� Milliseconds)
    // 30 �ҷ� = 30 * 60 * 1000 = 1,800,000 ms
    const INACTIVITY_LIMIT = 1800000; 
    let inactivityTimer;

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        // ������Ѻ�����ѧ����
        inactivityTimer = setTimeout(doLogout, INACTIVITY_LIMIT);
    }

    function doLogout() {
        // ����͹��͹�մ�͡ (Optional) ���ʹմ��¡���
        Swal.fire({
            title: '������ҡ����ҹ',
            text: '�س��������¡�������ҹҹ �к����͡�ҡ�к��ѵ��ѵ�',
            icon: 'warning',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false
        }).then(() => {
            // ��� Redirect ���� Logout
            // (��Ǩ�ͺ Path ���١������ logout.php �����˹)
            window.location.href = 'logout.php?reason=timeout'; 
        });
    }

    // �ѡ�Ѻ�˵ء�ó�������͹��Ǣͧ����� ���� Reset ����
    window.onload = resetInactivityTimer;
    document.onmousemove = resetInactivityTimer;
    document.onkeypress = resetInactivityTimer;
    document.ontouchstart = resetInactivityTimer; // ����Ѻ��Ͷ��
    document.onclick = resetInactivityTimer;
    document.onscroll = resetInactivityTimer;

    // --- ?? Smooth Page Transition (Fade Out ��͹����¹˹��) ---
    document.addEventListener('click', function(e) {
        // ������� <a> ������� (����͹�� icon ��ҧ� <a> ����)
        const link = e.target.closest('a');
        if (link && link.href) {
            // ������ԧ��������������ǡѹ ���˹�����ǡѹ���
            const url = new URL(link.href);
            const isLocal = url.origin === window.location.origin;
            const isAnchor = url.pathname === window.location.pathname && url.hash !== '';
            
            // ������ԧ�� local �Դ������ ��������������͹ anchor (#) �����ԧ������ href="#"
            if (isLocal && !isAnchor && link.target !== '_blank' && link.getAttribute('href') !== '#') {
                e.preventDefault(); // ��ش�������¹˹�ҷѹ��
                document.body.classList.add('page-transitioning'); // �����ҧ
                setTimeout(() => {
                    window.location.href = link.href; // �˹������
                }, 200); // ���������͡Ѻ���� CSS (0.2s)
            }
        }
    });
</script>
</body>
</html>