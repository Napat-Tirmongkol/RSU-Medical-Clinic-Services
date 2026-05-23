<?php
/**
 * portal/ai_assistant.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'ai_assistant', 'title' => 'AI Assistant']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); overflow:hidden;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/ai_assistant.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
            </div>
<?php layout_end(); ?>
