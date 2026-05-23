<?php
/**
 * portal/ai_qa_lab.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'ai_qa_lab', 'title' => 'AI QA Lab']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($hasAi) {
                    include __DIR__ . '/_partials/ai_qa_lab.php';
                } else {
                    echo $aiDeniedHtml;
                }
                ?>
            </div>
<?php layout_end(); ?>
