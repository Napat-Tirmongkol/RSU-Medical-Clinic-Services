<?php
/**
 * portal/payroll_periods.php
 * Payroll — monthly periods + entries management
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'payroll_periods', 'title' => 'งวดเงินเดือน (Payroll Periods)']);
?>
            <div id="section-payroll_periods" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                $canPayroll = $isSuper || $adminRole === 'admin'
                            || !empty($_SESSION['access_finance'])
                            || !empty($_SESSION['access_payroll']);
                if ($canPayroll) {
                    include __DIR__ . '/_partials/payroll_periods.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626">'
                       . '<i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED'
                       . '</div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
