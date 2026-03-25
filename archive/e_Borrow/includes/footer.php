<?php
// includes/footer.php

// (��Ǩ�ͺ��� $current_page �������� ����� 'index')
$current_page = $current_page ?? 'index'; 
$user_role = $_SESSION['role'] ?? 'employee'; // (�֧ Role �Ѩ�غѹ)
?>

</main> 
<nav class="footer-nav">
    
    <a href="admin/index.php" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>">
        <i class="fas fa-tachometer-alt"></i>
        �Ҿ���
    </a>
    
    <a href="admin/return_dashboard.php" class="<?php echo ($current_page == 'return') ? 'active' : ''; ?>">
        <i class="fas fa-undo-alt"></i>
        �׹�ػ�ó�
    </a>
    
    <?php // (��������Ѻ Admin ��� Editor) ?>
    <?php if (in_array($user_role, ['admin', 'editor'])): ?>
    <a href="admin/manage_equipment.php" class="<?php echo ($current_page == 'manage_equip') ? 'active' : ''; ?>">
        <i class="fas fa-tools"></i>
        �Ѵ����ػ�ó�
    </a>
    
    <a href="admin/manage_fines.php" class="<?php echo ($current_page == 'manage_fines') ? 'active' : ''; ?>">
        <i class="fas fa-file-invoice-dollar"></i>
        �Ѵ��ä�һ�Ѻ
    </a>
    <?php endif; // (�� Admin/Editor) ?>


    <?php 
    // (���ٷ������� ���ʴ�੾�� Admin ��ҹ��)
    if ($user_role == 'admin'): 
    ?>
    
    <a href="admin/manage_students.php" class="<?php echo ($current_page == 'manage_user') ? 'active' : ''; ?>">
        <i class="fas fa-users-cog"></i>
        �Ѵ��ü����
    </a>
    
    <a href="admin/report_borrowed.php" class="<?php echo ($current_page == 'report') ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i>
        ��§ҹ
    </a>
    
    <a href="admin/admin_log.php" class="<?php echo ($current_page == 'admin_log') ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        Log Admin
    </a>

    <?php endif; // (������� Admin) ?>
</nav>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/admin_app.js?v=<?php echo time(); ?>"></script>

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
            window.location.href = 'admin/logout.php?reason=timeout'; 
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
        const link = e.target.closest('a');
        if (link && link.href) {
            const url = new URL(link.href);
            const isLocal = url.origin === window.location.origin;
            const isAnchor = url.pathname === window.location.pathname && url.hash !== '';
            
            // ¡��� modal toggles ���� data-* attributes ��� Bootstrap ��
            const isBootstrap = link.hasAttribute('data-bs-toggle') || link.hasAttribute('data-bs-target');
            // ¡��鹻���ź/confirm ����Ҩ���ա�õ�� onclick ���
            const hasOnclick = link.hasAttribute('onclick');

            if (isLocal && !isAnchor && !isBootstrap && !hasOnclick && link.target !== '_blank' && link.getAttribute('href') !== '#') {
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