<?php
/**
 * portal/accident_log.php
 * Section page — Accident Log (บันทึกอุบัติเหตุรายวัน)
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'accident_log', 'title' => 'บันทึกอุบัติเหตุ']);
?>
            <div id="section-accident_log" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($hasAccidentLog) {
                    include __DIR__ . '/_partials/accident_log.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องเป็น admin หรือมีสิทธิ์ access_nurse_productivity</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
