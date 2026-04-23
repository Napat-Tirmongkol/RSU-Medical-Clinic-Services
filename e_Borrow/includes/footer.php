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
    <a href="admin/report_borrowed.php" class="<?php echo ($current_page == 'report') ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i>
        <span>รายงาน</span>
    </a>
    <?php endif; ?>

    <!-- เมนูจัดการโปรไฟล์ (แสดงเป็น Pop-up Card) -->
    <a href="javascript:void(0)" onclick="openProfileCard()">
        <i class="fas fa-user-circle"></i>
        <span>โปรไฟล์</span>
    </a>
</nav>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // --- Profile Card Logic ---
    function openProfileCard() {
        // ดึงข้อมูลโปรไฟล์ปัจจุบันก่อน
        Swal.fire({
            title: 'กำลังโหลดข้อมูล...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('admin/ajax_profile.php')
            .then(response => response.json())
            .then(res => {
                if(res.status === 'success') {
                    const user = res.data;
                    Swal.fire({
                        title: '<div class="text-xl font-black text-indigo-600 mb-2"><i class="fas fa-user-cog"></i> จัดการโปรไฟล์</div>',
                        html: `
                            <div class="text-left font-prompt px-2">
                                <div class="mb-4">
                                    <label class="block text-sm font-bold text-slate-700 mb-1">ชื่อผู้ใช้งาน (Username)</label>
                                    <input type="text" value="${user.username}" class="w-full p-3 bg-slate-100 border border-slate-200 rounded-xl text-slate-500" disabled>
                                </div>
                                <div class="mb-4">
                                    <label class="block text-sm font-bold text-slate-700 mb-1">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                                    <input type="text" id="prof_name" value="${user.full_name}" class="w-full p-3 bg-white border border-slate-300 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all">
                                </div>
                                <div class="mt-6 mb-2 border-b border-slate-100 pb-2">
                                    <span class="text-sm font-bold text-slate-800"><i class="fas fa-lock text-slate-400 mr-2"></i>เปลี่ยนรหัสผ่าน (เว้นว่างได้)</span>
                                </div>
                                <div class="mb-4">
                                    <input type="password" id="prof_pass1" placeholder="รหัสผ่านใหม่" class="w-full p-3 bg-white border border-slate-300 rounded-xl mb-3 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all">
                                    <input type="password" id="prof_pass2" placeholder="ยืนยันรหัสผ่านใหม่" class="w-full p-3 bg-white border border-slate-300 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all">
                                </div>
                            </div>
                        `,
                        showCancelButton: true,
                        confirmButtonText: '<i class="fas fa-save"></i> บันทึกข้อมูล',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: '#4f46e5',
                        cancelButtonColor: '#94a3b8',
                        customClass: {
                            popup: 'rounded-3xl',
                            confirmButton: 'rounded-xl px-6 py-3 font-bold',
                            cancelButton: 'rounded-xl px-6 py-3 font-bold'
                        },
                        preConfirm: () => {
                            const fname = document.getElementById('prof_name').value;
                            const p1 = document.getElementById('prof_pass1').value;
                            const p2 = document.getElementById('prof_pass2').value;

                            if(!fname) {
                                Swal.showValidationMessage('กรุณากรอกชื่อ-นามสกุล');
                                return false;
                            }
                            if(p1 || p2) {
                                if(p1 !== p2) {
                                    Swal.showValidationMessage('รหัสผ่านไม่ตรงกัน');
                                    return false;
                                }
                            }

                            const formData = new FormData();
                            formData.append('full_name', fname);
                            formData.append('new_password', p1);
                            formData.append('confirm_password', p2);

                            return fetch('admin/ajax_profile.php', {
                                method: 'POST',
                                body: formData
                            }).then(response => response.json())
                              .then(res => {
                                  if(res.status !== 'success') {
                                      throw new Error(res.message);
                                  }
                                  return res;
                              }).catch(error => {
                                  Swal.showValidationMessage(error.message);
                              });
                        }
                    }).then((result) => {
                        if(result.isConfirmed) {
                            Swal.fire({
                                icon: 'success',
                                title: 'บันทึกสำเร็จ!',
                                text: 'อัปเดตข้อมูลโปรไฟล์ของคุณเรียบร้อยแล้ว',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        }
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', res.message, 'error');
                }
            }).catch(() => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
    }
</script>

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