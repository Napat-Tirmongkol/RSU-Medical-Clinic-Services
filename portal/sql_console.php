<?php
/**
 * portal/sql_console.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'sql_console', 'title' => 'SQL Console']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($isSuper) {
                    include __DIR__ . '/_partials/sql_console.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">SQL Console ใช้ได้เฉพาะ superadmin</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
