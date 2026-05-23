<?php
/**
 * portal/edms.php
 * EDMS section — uses ?view= to route between sub-views.
 *   ?view=list&type=task    → task list
 *   ?view=myinbox           → my inbox
 *   ?view=sla_dashboard     → SLA dashboard
 *   ?view=sla_policies      → SLA policies CRUD
 *   (no view)               → EDMS landing page
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

// Compat shim: the EDMS partial router reads $_GET['edms_view'].
// New URLs use ?view=X — alias it so the partial keeps working.
if (!isset($_GET['edms_view']) && isset($_GET['view'])) {
    $_GET['edms_view'] = $_GET['view'];
}

layout_start(['section' => 'edms', 'title' => 'สารบรรณอิเล็กทรอนิกส์']);
?>
            <div class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php
                if ($adminRole === 'superadmin' || !empty($_SESSION['access_edms'])) {
                    include __DIR__ . '/_partials/edms.php';
                } else {
                    echo '<div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_edms</span></div>';
                }
                ?>
            </div>
<?php layout_end(); ?>
