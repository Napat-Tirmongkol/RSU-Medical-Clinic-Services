<?php
/**
 * portal/vaccinations.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'vaccinations', 'title' => 'บันทึกการฉีดวัคซีน']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_identity'])) {
                    include __DIR__ . '/_partials/vaccinations.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_identity หรือ role: admin/superadmin</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
