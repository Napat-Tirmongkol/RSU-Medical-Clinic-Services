<?php
/**
 * portal/apps.php
 * App Launcher — grid of all clinic modules.
 * Requires _portal_data.php for $projects, $userPins, $categoryMap (the
 * apps_launcher partial early-returns if $projects is unset).
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_portal_data.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'apps', 'title' => 'App Launcher']);
?>
            <div id="section-apps" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); overflow-y:auto;">
                <?php include __DIR__ . '/_partials/apps_launcher.php'; ?>
            </div><!-- /section-apps -->
<?php layout_end(); ?>
