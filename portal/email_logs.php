<?php
/**
 * portal/email_logs.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'email_logs', 'title' => 'Email Logs']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php 
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_system_logs'])) {
                    include __DIR__ . '/_partials/email_logs.php'; 
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED</div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
