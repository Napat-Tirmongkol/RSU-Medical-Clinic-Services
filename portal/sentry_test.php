<?php
/**
 * portal/sentry_test.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'sentry_test', 'title' => 'Sentry Test']);
?>
            <div class="portal-section"
                style="background:#f8fafc; overflow-y:auto;">
                <?php
                if ($isSuper) {
                    include __DIR__ . '/_partials/sentry_test.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">Superadmin only.</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
