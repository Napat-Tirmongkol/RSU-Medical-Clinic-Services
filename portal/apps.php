<?php
/**
 * portal/apps.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'apps', 'title' => 'App Launcher']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); overflow-y:auto;">
                <?php include __DIR__ . '/_partials/apps_launcher.php'; ?>
            </div><!-- /section-apps -->
<?php layout_end(); ?>
