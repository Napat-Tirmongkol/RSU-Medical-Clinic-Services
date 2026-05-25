<?php
/**
 * portal/payroll_employees.php
 * Payroll — manage employee payroll profiles
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'payroll_employees', 'title' => 'ตั้งค่าพนักงาน (Payroll)']);
?>
            <div id="section-payroll_employees" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                $canPayroll = $isSuper || $adminRole === 'admin'
                            || !empty($_SESSION['access_finance'])
                            || !empty($_SESSION['access_payroll']);
                if ($canPayroll) {
                    include __DIR__ . '/_partials/payroll_employees.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626">'
                       . '<i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br>'
                       . '<span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_finance / access_payroll หรือ role: admin/superadmin</span>'
                       . '</div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
