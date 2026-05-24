<?php
/**
 * portal/documents.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'documents', 'title' => 'คลังเอกสาร']);
?>
            <div id="section-documents" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || $adminRole === 'admin') {
                    include __DIR__ . '/_partials/documents.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">คลังเอกสารใช้ได้เฉพาะ superadmin / admin</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
