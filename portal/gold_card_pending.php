<?php
/**
 * portal/gold_card_pending.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'gold_card_pending', 'title' => 'ย้ายสิทธิ์บัตรทอง']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_insurance'])) {
                    include __DIR__ . '/_partials/gold_card_pending.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_insurance</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
