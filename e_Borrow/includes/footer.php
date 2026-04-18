<?php
// includes/footer.php
$current_page = $current_page ?? 'index'; 
$user_role = $_SESSION['role'] ?? 'employee'; 
?>

</main> 
<nav class="footer-nav">
    
    <a href="admin/index.php" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>">
        <i class="fas fa-tachometer-alt"></i>
        <span>ภาพรวม</span>
    </a>
    
    <a href="admin/return_dashboard.php" class="<?php echo ($current_page == 'return') ? 'active' : ''; ?>">
        <i class="fas fa-undo-alt"></i>
        <span>คืนอุปกรณ์</span>
    </a>
    
    <?php if (in_array($user_role, ['admin', 'editor'])): ?>
    <a href="admin/manage_equipment.php" class="<?php echo ($current_page == 'manage_equip') ? 'active' : ''; ?>">
        <i class="fas fa-tools"></i>
        <span>จัดการอุปกรณ์</span>
    </a>
    
    <a href="admin/manage_fines.php" class="<?php echo ($current_page == 'manage_fines') ? 'active' : ''; ?>">
        <i class="fas fa-file-invoice-dollar"></i>
        <span>จัดการค่าปรับ</span>
    </a>
    <?php endif; ?>

    <?php if ($user_role == 'admin'): ?>
    <a href="admin/manage_students.php" class="<?php echo ($current_page == 'manage_user') ? 'active' : ''; ?>">
        <i class="fas fa-users-cog"></i>
        <span>จัดการผู้ใช้งาน</span>
    </a>
    
    <a href="admin/report_borrowed.php" class="<?php echo ($current_page == 'report') ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i>
        <span>รายงาน</span>
    </a>
    <?php endif; ?>
</nav>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="assets/js/theme.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/admin_app.js?v=<?php echo time(); ?>"></script>

<script>
    // --- Auto Logout Logic ---
    const INACTIVITY_LIMIT = 18000000; // 5 ชั่วโมง
    let inactivityTimer;

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(doLogout, INACTIVITY_LIMIT);
    }

    function doLogout() {
        Swal.fire({
            title: 'เซสชันหมดอายุ',
            text: 'คุณไม่ได้ใช้งานระบบนานเกินไป ระบบจะออกจากระบบเพื่อความปลอดภัย',
            icon: 'warning',
            timer: 3000,
            showConfirmButton: false
        }).then(() => {
            window.location.href = 'admin/logout.php?reason=timeout'; 
        });
    }

    window.onload = resetInactivityTimer;
    document.onmousemove = resetInactivityTimer;
    document.onkeypress = resetInactivityTimer;
</script>
</body>
</html>