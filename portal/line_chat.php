<?php
/**
 * portal/line_chat.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'line_chat', 'title' => 'LINE Chat']);
?>
            <div id="section-line_chat" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); overflow:hidden;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/line_chat.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
            </div>
<?php layout_end(); ?>
