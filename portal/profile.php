<?php
/**
 * portal/profile.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'profile', 'title' => 'โปรไฟล์ของฉัน']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($isStaff) {
                    include __DIR__ . '/_partials/profile.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">หน้าโปรไฟล์ใช้ได้เฉพาะบัญชีเจ้าหน้าที่ (e-Campaign Staff)</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
