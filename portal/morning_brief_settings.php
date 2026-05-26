<?php
/**
 * portal/morning_brief_settings.php
 * Section page wrapper — loads _partials/morning_brief_settings.php under portal layout.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'morning_brief_settings', 'title' => 'ตั้งค่า Morning Brief']);
?>
<div class="portal-section" style="width:100%; min-height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
    <?php include __DIR__ . '/_partials/morning_brief_settings.php'; ?>
</div>
<?php layout_end(); ?>
