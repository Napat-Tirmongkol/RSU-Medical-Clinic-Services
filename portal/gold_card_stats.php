<?php
/**
 * portal/gold_card_stats.php
 * Section page — Gold Card Monthly Statistics (สถิติบัตรทอง รายเดือน)
 * แยกจาก portal/gold_card.php — ไม่ทับ schema/operations (เก็บแค่ยอดรวม snapshot)
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'gold_card_stats', 'title' => 'สถิติบัตรทอง']);
?>
            <div id="section-gold_card_stats" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f1f5f9; overflow-y:auto; padding:20px;">
                <?php
                if ($hasGoldCardStats) {
                    include __DIR__ . '/_partials/gold_card_stats.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องเป็น superadmin หรือมีสิทธิ์ access_insurance</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
