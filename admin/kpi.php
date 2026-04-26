<?php
// admin/kpi.php — KPI Dashboard
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ── Auto-create satisfaction_surveys ──────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS satisfaction_surveys (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rating       TINYINT      NOT NULL,
        comment      TEXT,
        page_context VARCHAR(100) DEFAULT NULL,
        ip_hash      VARCHAR(64)  DEFAULT NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_rating  (rating)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException) {}

// ── Satisfaction KPIs ─────────────────────────────────────────────────────
$sv = ['avg' => null, 'total' => 0, 'this_week' => 0, 'last_week' => 0,
       'dist' => [1=>0,2=>0,3=>0,4=>0,5=>0]];
$sv_comments = []; $sv_total_rows = 0;

try {
    $r_stmt = $pdo->prepare("
        SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS total,
               SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS this_week,
               SUM(created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                   AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS last_week
        FROM satisfaction_surveys
        WHERE clinic_id = :clinic_id
    ");
    $r_stmt->execute([':clinic_id' => clinic_id()]);
    $r = $r_stmt->fetch();
    if ($r && (int)$r['total'] > 0) {
        $sv['avg']       = round((float)$r['avg_r'], 1);
        $sv['total']     = (int)$r['total'];
        $sv['this_week'] = (int)$r['this_week'];
        $sv['last_week'] = (int)$r['last_week'];
    }
    $dist_stmt = $pdo->prepare("SELECT rating, COUNT(*) cnt FROM satisfaction_surveys WHERE clinic_id = :clinic_id GROUP BY rating");
    $dist_stmt->execute([':clinic_id' => clinic_id()]);
    foreach ($dist_stmt->fetchAll() as $d) {
        $sv['dist'][(int)$d['rating']] = (int)$d['cnt'];
    }

    // Comments — paginated (CLAUDE.md: 20/page default)
    $sv_page  = max(1, (int)($_GET['cp'] ?? 1));
    $sv_per   = 20;
    $sv_off   = ($sv_page - 1) * $sv_per;
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM satisfaction_surveys WHERE TRIM(IFNULL(comment,'')) != '' AND clinic_id = :clinic_id");
    $cnt_stmt->execute([':clinic_id' => clinic_id()]);
    $sv_total_rows = (int)$cnt_stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT rating, comment, created_at FROM satisfaction_surveys
                           WHERE TRIM(IFNULL(comment,'')) != '' AND clinic_id = :clinic_id
                           ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    $stmt->bindValue(':clinic_id', clinic_id(), PDO::PARAM_INT);
    $stmt->bindValue(':lim', $sv_per, PDO::PARAM_INT);
    $stmt->bindValue(':off', $sv_off, PDO::PARAM_INT);
    $stmt->execute();
    $sv_comments = $stmt->fetchAll();
    $sv_pages = max(1, (int)ceil($sv_total_rows / $sv_per));
} catch (PDOException) { $sv_page = 1; $sv_pages = 1; }

$sv_sat_count = ($sv['dist'][4] + $sv['dist'][5]);
$sv_sat_rate  = $sv['total'] > 0 ? round($sv_sat_count / $sv['total'] * 100) : 0;
$sv_dist_max  = max(1, max($sv['dist']));

// ── Campaign KPIs ─────────────────────────────────────────────────────────
$ca = ['active'=>0,'total'=>0,'completed'=>0,'cancelled'=>0,'pending'=>0,'comp_rate'=>0,'month'=>0];
try {
    $r = $pdo->query("
        SELECT (SELECT COUNT(*) FROM camp_list WHERE status='active') AS active,
               COUNT(*) AS total,
               SUM(status='completed')                AS completed,
               SUM(status='cancelled')                AS cancelled,
               SUM(status IN ('booked','confirmed'))  AS pending,
               SUM(MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())) AS month
        FROM camp_bookings
    ")->fetch();
    if ($r) {
        $ca = array_map('intval', $r);
        $done = $ca['completed'] + $ca['cancelled'];
        $ca['comp_rate'] = $done > 0 ? round($ca['completed'] / $done * 100) : 0;
    }
} catch (PDOException) {}

// ── User KPIs ─────────────────────────────────────────────────────────────
$us = ['total'=>0,'this_month'=>0,'last_month'=>0,'growth'=>null];
try {
    $r = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(YEAR(created_at)=YEAR(NOW())  AND MONTH(created_at)=MONTH(NOW()))  AS this_month,
               SUM(YEAR(created_at)=YEAR(NOW()-INTERVAL 1 MONTH)
                   AND MONTH(created_at)=MONTH(NOW()-INTERVAL 1 MONTH))              AS last_month
        FROM sys_users
    ")->fetch();
    if ($r) {
        $us['total']      = (int)$r['total'];
        $us['this_month'] = (int)$r['this_month'];
        $us['last_month'] = (int)$r['last_month'];
        if ($r['last_month'] > 0)
            $us['growth'] = round(($r['this_month'] - $r['last_month']) / $r['last_month'] * 100);
    }
} catch (PDOException) {}

// ── e_Borrow KPIs ─────────────────────────────────────────────────────────
$br = ['total'=>0,'active'=>0,'overdue'=>0];
try {
    $r = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(status IN ('borrowed','approved')) AS active,
               SUM(status='borrowed' AND due_date < CURDATE()) AS overdue
        FROM borrow_records
    ")->fetch();
    if ($r) $br = array_map('intval', $r);
} catch (PDOException) {}

// ── Helpers ───────────────────────────────────────────────────────────────
function kpi_trend(int $curr, int $prev): string {
    if ($prev === 0) return '';
    $pct = round(($curr - $prev) / $prev * 100);
    $dir = $pct >= 0;
    $color = $dir ? 'text-emerald-600 bg-emerald-50' : 'text-red-500 bg-red-50';
    $icon  = $dir ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
    return "<span class='text-[10px] font-black px-2 py-0.5 rounded-full $color'>"
         . "<i class='fa-solid $icon mr-0.5'></i>" . ($dir?'+':'') . "{$pct}% vs เดือนก่อน"
         . "</span>";
}
function kpi_week_trend(int $curr, int $prev): string {
    if ($prev === 0 && $curr === 0) return "<span class='text-[10px] text-gray-400'>ไม่มีข้อมูลสัปดาห์ก่อน</span>";
    if ($prev === 0) return "<span class='text-[10px] font-black px-2 py-0.5 rounded-full text-emerald-600 bg-emerald-50'><i class='fa-solid fa-arrow-trend-up mr-0.5'></i>ใหม่สัปดาห์นี้</span>";
    $pct = round(($curr - $prev) / $prev * 100);
    $dir = $pct >= 0;
    $color = $dir ? 'text-emerald-600 bg-emerald-50' : 'text-red-500 bg-red-50';
    $icon  = $dir ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
    return "<span class='text-[10px] font-black px-2 py-0.5 rounded-full $color'>"
         . "<i class='fa-solid $icon mr-0.5'></i>" . ($dir?'+':'') . "{$pct}% vs สัปดาห์ก่อน"
         . "</span>";
}
function star_html(float $avg, string $size='text-sm'): string {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($avg >= $i)      $html .= "<i class='fa-solid fa-star $size' style='color:#F59E0B'></i>";
        elseif ($avg >= $i - 0.5) $html .= "<i class='fa-solid fa-star-half-stroke $size' style='color:#F59E0B'></i>";
        else                 $html .= "<i class='fa-regular fa-star $size text-gray-200'></i>";
    }
    return $html;
}
function pager_url(string $param, int $page): string {
    $q = $_GET; $q[$param] = $page; return '?' . http_build_query($q);
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.kpi-section-label {
    display:flex; align-items:center; gap:8px;
    margin-bottom:14px; margin-top:32px;
}
.kpi-section-label:first-of-type { margin-top:0; }
.kpi-section-label span {
    font-size:10px; font-weight:900; text-transform:uppercase;
    letter-spacing:.18em; color:#64748b;
}
.kpi-card {
    background:#fff; border-radius:20px;
    box-shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);
    border:1.5px solid #f1f5f9;
    position:relative; overflow:hidden;
    transition:box-shadow .2s,transform .2s;
}
.kpi-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
.kpi-bar-track { background:#f1f5f9; border-radius:99px; height:8px; overflow:hidden; }
.kpi-bar-fill  { height:100%; border-radius:99px; transition:width .6s cubic-bezier(.16,1,.3,1); }
@keyframes kpiSlide { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
.kpi-animate { animation:kpiSlide .45s cubic-bezier(.16,1,.3,1) both; }
.kpi-d1{animation-delay:.05s} .kpi-d2{animation-delay:.1s}
.kpi-d3{animation-delay:.15s} .kpi-d4{animation-delay:.2s}
</style>

<?php renderPageHeader(
    'KPI Dashboard',
    'ตัวชี้วัดประสิทธิภาพระบบ · อัปเดต ณ ' . date('d M Y, H:i') . ' น.'
); ?>

<!-- ══════════════════ SATISFACTION SURVEY ══════════════════ -->
<div class="kpi-section-label">
    <div class="w-3 h-3 rounded-full" style="background:#F59E0B;box-shadow:0 0 6px rgba(245,158,11,.5)"></div>
    <span>ความพึงพอใจ (Satisfaction Survey)</span>
</div>

<!-- 4 Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-5">

    <!-- Avg Rating -->
    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d1">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#F59E0B,#D97706)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">คะแนนเฉลี่ย</p>
        <div class="flex items-end gap-2 mb-2">
            <span class="text-3xl sm:text-4xl font-black text-gray-900">
                <?= $sv['avg'] !== null ? number_format($sv['avg'], 1) : '—' ?>
            </span>
            <span class="text-sm text-gray-400 mb-1">/ 5</span>
        </div>
        <div class="flex gap-0.5">
            <?php if ($sv['avg'] !== null): ?>
                <?= star_html($sv['avg'], 'text-xs') ?>
            <?php else: ?>
                <span class="text-xs text-gray-300">ยังไม่มีข้อมูล</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Total Responses -->
    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d2">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#8B5CF6,#6D28D9)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">ผลประเมินทั้งหมด</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($sv['total']) ?></div>
        <div class="text-[10px] text-gray-400">
            <i class="fa-solid fa-comment-dots mr-1" style="color:#8B5CF6"></i>
            มีความคิดเห็น <?= number_format($sv_total_rows) ?> รายการ
        </div>
    </div>

    <!-- This Week -->
    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d3">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#2563EB,#1D4ED8)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">สัปดาห์นี้</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($sv['this_week']) ?></div>
        <?= kpi_week_trend($sv['this_week'], $sv['last_week']) ?>
    </div>

    <!-- Satisfaction Rate -->
    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d4">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#2e9e63,#16a34a)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">Satisfaction Rate</p>
        <div class="flex items-end gap-1 mb-2">
            <span class="text-3xl sm:text-4xl font-black" style="color:#2e9e63"><?= $sv_sat_rate ?>%</span>
        </div>
        <div class="text-[10px] text-gray-400">
            <i class="fa-solid fa-face-smile mr-1 text-amber-400"></i>
            ให้คะแนน 4–5 ดาว (<?= number_format($sv_sat_count) ?> คน)
        </div>
    </div>

</div>

<!-- Star Distribution + Comments -->
<div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-2">

    <!-- Star Distribution Bars (2/5) -->
    <div class="lg:col-span-2 kpi-card p-5 kpi-animate" style="animation-delay:.25s">
        <p class="text-xs font-black uppercase tracking-wider text-gray-400 mb-4">การกระจายคะแนน</p>
        <?php for ($s = 5; $s >= 1; $s--):
            $cnt  = $sv['dist'][$s];
            $pct  = $sv['total'] > 0 ? round($cnt / $sv['total'] * 100) : 0;
            $barW = $sv_dist_max > 0 ? round($cnt / $sv_dist_max * 100) : 0;
            $color = $s >= 4 ? '#F59E0B' : ($s == 3 ? '#fb923c' : '#ef4444');
        ?>
        <div class="flex items-center gap-3 mb-3 last:mb-0">
            <div class="flex items-center gap-1 w-14 shrink-0">
                <i class="fa-solid fa-star text-[10px]" style="color:<?= $color ?>"></i>
                <span class="text-xs font-black text-gray-600"><?= $s ?></span>
            </div>
            <div class="flex-1 kpi-bar-track">
                <div class="kpi-bar-fill" style="width:<?= $barW ?>%;background:<?= $color ?>"></div>
            </div>
            <div class="w-16 text-right shrink-0">
                <span class="text-xs font-bold text-gray-700"><?= number_format($cnt) ?></span>
                <span class="text-[10px] text-gray-400 ml-1">(<?= $pct ?>%)</span>
            </div>
        </div>
        <?php endfor; ?>

        <?php if ($sv['total'] === 0): ?>
        <div class="text-center py-4 text-sm text-gray-300">
            <i class="fa-regular fa-star text-2xl mb-2 block"></i>
            ยังไม่มีผลประเมิน
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Comments Table (3/5) -->
    <div class="lg:col-span-3 kpi-card p-5 flex flex-col kpi-animate" style="animation-delay:.3s">
        <div class="flex items-center justify-between mb-4">
            <p class="text-xs font-black uppercase tracking-wider text-gray-400">ความคิดเห็นล่าสุด</p>
            <?php if ($sv_total_rows > 0): ?>
            <span class="text-[10px] text-gray-400">หน้า <?= $sv_page ?>/<?= $sv_pages ?> · <?= number_format($sv_total_rows) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (empty($sv_comments)): ?>
        <div class="flex-1 flex flex-col items-center justify-center py-8 text-gray-300">
            <i class="fa-regular fa-comment-dots text-3xl mb-2"></i>
            <p class="text-sm">ยังไม่มีความคิดเห็น</p>
        </div>
        <?php else: ?>
        <div class="flex-1 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="text-left text-[10px] font-black uppercase tracking-wider text-gray-400 pb-2 w-16">คะแนน</th>
                        <th class="text-left text-[10px] font-black uppercase tracking-wider text-gray-400 pb-2">ความคิดเห็น</th>
                        <th class="text-right text-[10px] font-black uppercase tracking-wider text-gray-400 pb-2 w-20">วันที่</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                <?php foreach ($sv_comments as $c): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-2.5 pr-3">
                            <span class="flex gap-0.5"><?= star_html((float)$c['rating'], 'text-[9px]') ?></span>
                        </td>
                        <td class="py-2.5 pr-3">
                            <p class="text-xs text-gray-700 line-clamp-2"><?= htmlspecialchars($c['comment']) ?></p>
                        </td>
                        <td class="py-2.5 text-right text-[10px] text-gray-400 whitespace-nowrap">
                            <?= date('d/m/y', strtotime($c['created_at'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($sv_pages > 1): ?>
        <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-center gap-1">
            <?php
            $window = 2;
            $show_first = $sv_page > $window + 1;
            $show_last  = $sv_page < $sv_pages - $window;
            ?>
            <a href="<?= pager_url('cp',1) ?>" class="px-2 py-1 text-xs rounded-lg <?= $sv_page===1?'pointer-events-none text-gray-300':'text-gray-500 hover:bg-gray-100' ?>">«</a>
            <a href="<?= pager_url('cp', max(1,$sv_page-1)) ?>" class="px-2 py-1 text-xs rounded-lg <?= $sv_page===1?'pointer-events-none text-gray-300':'text-gray-500 hover:bg-gray-100' ?>">‹</a>
            <?php if ($show_first): ?>
                <a href="<?= pager_url('cp',1) ?>" class="px-2.5 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-100">1</a>
                <span class="text-gray-300 text-xs">…</span>
            <?php endif; ?>
            <?php for ($p = max(1,$sv_page-$window); $p <= min($sv_pages,$sv_page+$window); $p++): ?>
                <a href="<?= pager_url('cp',$p) ?>"
                   class="px-2.5 py-1 text-xs rounded-lg font-semibold <?= $p===$sv_page ? 'text-white' : 'text-gray-500 hover:bg-gray-100' ?>"
                   style="<?= $p===$sv_page ? 'background:#2e9e63' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($show_last): ?>
                <span class="text-gray-300 text-xs">…</span>
                <a href="<?= pager_url('cp',$sv_pages) ?>" class="px-2.5 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-100"><?= $sv_pages ?></a>
            <?php endif; ?>
            <a href="<?= pager_url('cp', min($sv_pages,$sv_page+1)) ?>" class="px-2 py-1 text-xs rounded-lg <?= $sv_page===$sv_pages?'pointer-events-none text-gray-300':'text-gray-500 hover:bg-gray-100' ?>">›</a>
            <a href="<?= pager_url('cp',$sv_pages) ?>" class="px-2 py-1 text-xs rounded-lg <?= $sv_page===$sv_pages?'pointer-events-none text-gray-300':'text-gray-500 hover:bg-gray-100' ?>">»</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- ══════════════════ CAMPAIGN KPIs ══════════════════ -->
<div class="kpi-section-label">
    <div class="w-3 h-3 rounded-full" style="background:#2e9e63;box-shadow:0 0 6px rgba(46,158,99,.5)"></div>
    <span>แคมเปญ & การจอง</span>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-2">

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d1">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#2e9e63,#16a34a)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">แคมเปญเปิดอยู่</p>
        <div class="text-3xl sm:text-4xl font-black mb-2" style="color:#2e9e63"><?= number_format($ca['active']) ?></div>
        <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-emerald-700 bg-emerald-50">
            <i class="fa-solid fa-circle-check mr-0.5"></i> Active
        </span>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d2">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#3B82F6,#1D4ED8)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">การจองทั้งหมด</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($ca['total']) ?></div>
        <div class="text-[10px] text-gray-400">
            <i class="fa-solid fa-calendar-plus mr-1 text-blue-400"></i>
            เดือนนี้ <?= number_format($ca['month']) ?> รายการ
        </div>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d3">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#10B981,#059669)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">Completion Rate</p>
        <div class="flex items-end gap-1 mb-2">
            <span class="text-3xl sm:text-4xl font-black text-gray-900"><?= $ca['comp_rate'] ?>%</span>
        </div>
        <div class="kpi-bar-track mt-1">
            <div class="kpi-bar-fill" style="width:<?= $ca['comp_rate'] ?>%;background:#10B981"></div>
        </div>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d4">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#F97316,#EA580C)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">รออนุมัติ</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($ca['pending']) ?></div>
        <div class="text-[10px] text-gray-400">
            <i class="fa-solid fa-clock-rotate-left mr-1 text-orange-400"></i>
            ยกเลิก <?= number_format($ca['cancelled']) ?> · เสร็จสิ้น <?= number_format($ca['completed']) ?>
        </div>
    </div>

</div>

<!-- ══════════════════ USER KPIs ══════════════════ -->
<div class="kpi-section-label">
    <div class="w-3 h-3 rounded-full" style="background:#8B5CF6;box-shadow:0 0 6px rgba(139,92,246,.5)"></div>
    <span>ผู้ใช้งาน</span>
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 mb-2">

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d1">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#8B5CF6,#6D28D9)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">ผู้ใช้งานทั้งหมด</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($us['total']) ?></div>
        <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-violet-600 bg-violet-50">
            <i class="fa-solid fa-users mr-0.5"></i> ลงทะเบียนแล้ว
        </span>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d2">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#EC4899,#BE185D)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">ผู้ใช้ใหม่เดือนนี้</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($us['this_month']) ?></div>
        <?= kpi_trend($us['this_month'], $us['last_month']) ?>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d3 col-span-2 lg:col-span-1">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#06B6D4,#0891B2)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">เดือนก่อนหน้า</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($us['last_month']) ?></div>
        <div class="text-[10px] text-gray-400">
            <i class="fa-solid fa-calendar mr-1 text-cyan-400"></i>
            <?= date('F Y', strtotime('first day of last month')) ?>
        </div>
    </div>

</div>

<!-- ══════════════════ e_BORROW KPIs ══════════════════ -->
<div class="kpi-section-label">
    <div class="w-3 h-3 rounded-full" style="background:#F97316;box-shadow:0 0 6px rgba(249,115,22,.5)"></div>
    <span>ระบบยืมอุปกรณ์ (e_Borrow)</span>
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 mb-8">

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d1">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#F97316,#EA580C)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">ยืมทั้งหมด</p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2"><?= number_format($br['total']) ?></div>
        <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-orange-600 bg-orange-50">
            <i class="fa-solid fa-box-open mr-0.5"></i> รายการทั้งหมด
        </span>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d2">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#2e9e63,#16a34a)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">กำลังยืมอยู่</p>
        <div class="text-3xl sm:text-4xl font-black mb-2" style="color:#2e9e63"><?= number_format($br['active']) ?></div>
        <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-emerald-700 bg-emerald-50">
            <i class="fa-solid fa-circle-dot mr-0.5"></i> Active
        </span>
    </div>

    <div class="kpi-card p-4 sm:p-5 kpi-animate kpi-d3 col-span-2 lg:col-span-1">
        <div class="absolute right-0 top-0 w-1.5 h-full rounded-r-xl" style="background:linear-gradient(180deg,#EF4444,#B91C1C)"></div>
        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">เกินกำหนดคืน</p>
        <div class="text-3xl sm:text-4xl font-black mb-2 <?= $br['overdue']>0?'text-red-500':'text-gray-900' ?>"><?= number_format($br['overdue']) ?></div>
        <?php if ($br['overdue'] > 0): ?>
        <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-red-600 bg-red-50">
            <i class="fa-solid fa-triangle-exclamation mr-0.5"></i> ต้องติดตาม
        </span>
        <?php else: ?>
        <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-emerald-600 bg-emerald-50">
            <i class="fa-solid fa-circle-check mr-0.5"></i> ไม่มีค้างคืน
        </span>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
