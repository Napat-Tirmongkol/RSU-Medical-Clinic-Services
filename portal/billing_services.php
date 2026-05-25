<?php
/**
 * portal/billing_services.php
 * Patient Billing — Service Catalog admin page
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'billing_services', 'title' => 'บริการคลินิก (Service Catalog)']);
?>
            <div id="section-billing_services" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance'])) {
                    include __DIR__ . '/_partials/billing_services.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626">'
                       . '<i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br>'
                       . '<span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_finance หรือ role: admin/superadmin</span>'
                       . '</div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
