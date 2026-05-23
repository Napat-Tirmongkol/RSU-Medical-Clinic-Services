<?php
/**
 * portal/monthly_report.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'monthly_report', 'title' => 'รายงานประจำเดือน']);
?>
            <div id="section-monthly_report" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_monthly_report']) || !empty($_SESSION['access_director_view'])) {
                    include __DIR__ . '/_partials/monthly_report.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_monthly_report หรือ access_director_view</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
