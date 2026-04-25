<?php
// portal/_partials/kpi_dashboard.php
// Variables available from portal/index.php:
//   $sv_kpi, $sv_comments, $sv_page, $sv_pages, $sv_total_rows, $kpis, $pdo

// e_Borrow stats
$_br = ['total'=>0,'active'=>0,'overdue'=>0];
try {
    $r = $pdo->query("SELECT COUNT(*) total,
        SUM(status IN ('borrowed','approved')) active,
        SUM(status='borrowed' AND due_date < CURDATE()) overdue
        FROM borrow_records")->fetch();
    if ($r) $_br = array_map('intval', $r);
} catch (PDOException) {}

// Campaign completion rate
$_ca_done = 0; $_ca_comp = 0; $_ca_comp_rate = 0;
try {
    $r = $pdo->query("SELECT SUM(status='completed') completed, SUM(status='cancelled') cancelled FROM camp_bookings")->fetch();
    if ($r) {
        $_ca_done = (int)$r['completed'] + (int)$r['cancelled'];
        $_ca_comp = (int)$r['completed'];
        $_ca_comp_rate = $_ca_done > 0 ? round($_ca_comp / $_ca_done * 100) : 0;
    }
} catch (PDOException) {}

// User growth
$_us_month = 0; $_us_last = 0;
try {
    $r = $pdo->query("SELECT
        SUM(YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())) this_month,
        SUM(YEAR(created_at)=YEAR(NOW()-INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(NOW()-INTERVAL 1 MONTH)) last_month
        FROM sys_users")->fetch();
    if ($r) { $_us_month = (int)$r['this_month']; $_us_last = (int)$r['last_month']; }
} catch (PDOException) {}

$_sv_dist_max = max(1, max($sv_kpi['dist']));

function _kpi_stars(float $avg): string {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($avg >= $i)           $html .= "<i class='fa-solid fa-star' style='color:#F59E0B;font-size:11px'></i>";
        elseif ($avg >= $i - 0.5) $html .= "<i class='fa-solid fa-star-half-stroke' style='color:#F59E0B;font-size:11px'></i>";
        else                      $html .= "<i class='fa-regular fa-star' style='color:#e2e8f0;font-size:11px'></i>";
    }
    return $html;
}
function _kpi_pager(string $param, int $p): string {
    $q = $_GET; $q[$param] = $p; return '?' . http_build_query($q);
}
?>

<div class="p-6">

    <!-- Header -->
    <div class="mb-8 flex items-center gap-4">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-sm"
             style="background:#FEF3C7;color:#D97706">
            <i class="fa-solid fa-gauge-high"></i>
        </div>
        <div>
            <h2 class="text-2xl font-black text-gray-900">KPI Dashboard</h2>
            <p class="text-slate-500 text-sm font-medium mt-0.5">
                ตัวชี้วัดประสิทธิภาพระบบ · อัปเดต ณ <?= date('d/m/Y H:i') ?> น.
            </p>
        </div>
    </div>

    <!-- ══ SATISFACTION SURVEY ══════════════════════════════════ -->
    <div class="flex items-center gap-2 mb-4">
        <div class="w-2.5 h-2.5 rounded-full" style="background:#F59E0B"></div>
        <span class="text-[10px] font-black uppercase tracking-[.18em] text-slate-400">ความพึงพอใจ (Satisfaction Survey)</span>
    </div>

    <!-- 4 Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        <!-- Avg Rating -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#F59E0B,#D97706)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">คะแนนเฉลี่ย</p>
            <div class="text-3xl font-black text-gray-900 mb-1.5">
                <?= $sv_kpi['avg'] !== null ? number_format($sv_kpi['avg'], 1) : '—' ?>
                <span class="text-sm font-semibold text-gray-400">/ 5</span>
            </div>
            <div class="flex gap-0.5">
                <?php if ($sv_kpi['avg'] !== null): ?>
                    <?= _kpi_stars($sv_kpi['avg']) ?>
                <?php else: ?>
                    <span class="text-xs text-gray-300">ยังไม่มีข้อมูล</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Total -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#8B5CF6,#6D28D9)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">ผลประเมินทั้งหมด</p>
            <div class="text-3xl font-black text-gray-900 mb-1.5"><?= number_format($sv_kpi['total']) ?></div>
            <span class="text-[10px] text-gray-400">
                <i class="fa-solid fa-comment-dots mr-1" style="color:#8B5CF6"></i>
                มีความคิดเห็น <?= number_format($sv_total_rows) ?> รายการ
            </span>
        </div>

        <!-- This Week -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#2563EB,#1D4ED8)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">สัปดาห์นี้</p>
            <div class="text-3xl font-black text-gray-900 mb-1.5"><?= number_format($sv_kpi['this_week']) ?></div>
            <?php
            $tw = $sv_kpi['this_week']; $lw = $sv_kpi['last_week'];
            if ($lw > 0):
                $pct = round(($tw-$lw)/$lw*100); $up = $pct >= 0;
            ?>
            <span class="text-[10px] font-black px-2 py-0.5 rounded-full <?= $up?'text-emerald-700 bg-emerald-50':'text-red-600 bg-red-50' ?>">
                <i class="fa-solid <?= $up?'fa-arrow-trend-up':'fa-arrow-trend-down' ?> mr-0.5"></i>
                <?= ($up?'+':'').$pct ?>% vs สัปดาห์ก่อน
            </span>
            <?php elseif ($sw_kpi['last_week'] ?? 0 === 0): ?>
            <span class="text-[10px] text-gray-300">ไม่มีข้อมูลสัปดาห์ก่อน</span>
            <?php endif; ?>
        </div>

        <!-- Satisfaction Rate -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#2e9e63,#16a34a)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">Satisfaction Rate</p>
            <div class="text-3xl font-black mb-1.5" style="color:#2e9e63"><?= $sv_kpi['sat_rate'] ?>%</div>
            <span class="text-[10px] text-gray-400">
                <i class="fa-solid fa-face-smile mr-1 text-amber-400"></i>
                ให้คะแนน 4–5 ดาว
            </span>
        </div>

    </div>

    <!-- Distribution + Comments -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-10">

        <!-- Star Bars (2/5) -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <p class="text-xs font-black uppercase tracking-wider text-gray-400 mb-4">การกระจายคะแนน</p>
            <?php for ($s = 5; $s >= 1; $s--):
                $cnt  = $sv_kpi['dist'][$s];
                $pct  = $sv_kpi['total'] > 0 ? round($cnt / $sv_kpi['total'] * 100) : 0;
                $barW = round($cnt / $_sv_dist_max * 100);
                $clr  = $s >= 4 ? '#F59E0B' : ($s == 3 ? '#fb923c' : '#ef4444');
            ?>
            <div class="flex items-center gap-3 mb-3 last:mb-0">
                <div class="flex items-center gap-1 w-12 shrink-0">
                    <i class="fa-solid fa-star text-[9px]" style="color:<?= $clr ?>"></i>
                    <span class="text-xs font-black text-gray-600"><?= $s ?></span>
                </div>
                <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-700"
                         style="width:<?= $barW ?>%;background:<?= $clr ?>"></div>
                </div>
                <div class="w-16 text-right shrink-0 text-xs font-bold text-gray-700">
                    <?= number_format($cnt) ?>
                    <span class="text-gray-400 font-normal">(<?= $pct ?>%)</span>
                </div>
            </div>
            <?php endfor; ?>
            <?php if ($sv_kpi['total'] === 0): ?>
            <p class="text-center text-sm text-gray-300 py-4">ยังไม่มีผลประเมิน</p>
            <?php endif; ?>
        </div>

        <!-- Comments Table (3/5) -->
        <div class="lg:col-span-3 bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex flex-col">
            <div class="flex items-center justify-between mb-4">
                <p class="text-xs font-black uppercase tracking-wider text-gray-400">ความคิดเห็นล่าสุด</p>
                <?php if ($sv_total_rows > 0): ?>
                <span class="text-[10px] text-gray-400">
                    หน้า <?= $sv_page ?>/<?= $sv_pages ?> · <?= number_format($sv_total_rows) ?> รายการ
                </span>
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
                            <th class="text-right text-[10px] font-black uppercase tracking-wider text-gray-400 pb-2 w-16">วันที่</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                    <?php foreach ($sv_comments as $c): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-2.5 pr-3">
                                <div class="flex gap-0.5"><?= _kpi_stars((float)$c['rating']) ?></div>
                            </td>
                            <td class="py-2.5 pr-3 text-xs text-gray-700">
                                <?= htmlspecialchars(mb_substr($c['comment'], 0, 80)) ?>
                                <?= mb_strlen($c['comment']) > 80 ? '…' : '' ?>
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
            <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-center gap-1 flex-wrap">
                <input type="hidden" name="section" value="kpi_dashboard">
                <a href="<?= _kpi_pager('cp',1) ?>&section=kpi_dashboard"
                   class="px-2 py-1 text-xs rounded-lg <?= $sv_page===1?'text-gray-300 pointer-events-none':'text-gray-500 hover:bg-gray-100' ?>">«</a>
                <a href="<?= _kpi_pager('cp',max(1,$sv_page-1)) ?>&section=kpi_dashboard"
                   class="px-2 py-1 text-xs rounded-lg <?= $sv_page===1?'text-gray-300 pointer-events-none':'text-gray-500 hover:bg-gray-100' ?>">‹</a>
                <?php for ($p = max(1,$sv_page-2); $p <= min($sv_pages,$sv_page+2); $p++): ?>
                <a href="<?= _kpi_pager('cp',$p) ?>&section=kpi_dashboard"
                   class="px-2.5 py-1 text-xs rounded-lg font-semibold <?= $p===$sv_page?'text-white':'text-gray-500 hover:bg-gray-100' ?>"
                   style="<?= $p===$sv_page?'background:#2e9e63':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="<?= _kpi_pager('cp',min($sv_pages,$sv_page+1)) ?>&section=kpi_dashboard"
                   class="px-2 py-1 text-xs rounded-lg <?= $sv_page===$sv_pages?'text-gray-300 pointer-events-none':'text-gray-500 hover:bg-gray-100' ?>">›</a>
                <a href="<?= _kpi_pager('cp',$sv_pages) ?>&section=kpi_dashboard"
                   class="px-2 py-1 text-xs rounded-lg <?= $sv_page===$sv_pages?'text-gray-300 pointer-events-none':'text-gray-500 hover:bg-gray-100' ?>">»</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- ══ CAMPAIGN ══════════════════════════════════════════════ -->
    <div class="flex items-center gap-2 mb-4">
        <div class="w-2.5 h-2.5 rounded-full" style="background:#2e9e63"></div>
        <span class="text-[10px] font-black uppercase tracking-[.18em] text-slate-400">แคมเปญ & การจอง</span>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-10">

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#2e9e63,#16a34a)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">แคมเปญเปิดอยู่</p>
            <div class="text-3xl font-black mb-2" style="color:#2e9e63"><?= number_format($kpis['camps']) ?></div>
            <span class="text-[10px] font-black px-2 py-0.5 rounded-full text-emerald-700 bg-emerald-50">
                <i class="fa-solid fa-circle-check mr-0.5"></i> Active
            </span>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#3B82F6,#1D4ED8)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">อัตราการจอง</p>
            <div class="text-3xl font-black text-gray-900 mb-2"><?= $kpis['booking_rate'] ?>%</div>
            <div class="w-full bg-gray-100 rounded-full h-1.5">
                <div class="h-full rounded-full" style="width:<?= $kpis['booking_rate'] ?>%;background:#3B82F6"></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#10B981,#059669)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">Completion Rate</p>
            <div class="text-3xl font-black text-gray-900 mb-2"><?= $_ca_comp_rate ?>%</div>
            <div class="w-full bg-gray-100 rounded-full h-1.5">
                <div class="h-full rounded-full" style="width:<?= $_ca_comp_rate ?>%;background:#10B981"></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden hover:-translate-y-0.5 transition-transform">
            <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#F97316,#EA580C)"></div>
            <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 mb-2">ที่นั่งทั้งหมด</p>
            <div class="text-3xl font-black text-gray-900 mb-2">
                <?= number_format($kpis['used_quota']) ?>
                <span class="text-sm font-semibold text-gray-400">/ <?= number_format($kpis['total_quota']) ?></span>
            </div>
            <span class="text-[10px] text-gray-400">ที่นั่งถูกใช้งาน</span>
        </div>

    </div>

    <!-- ══ USERS + e_BORROW ══════════════════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Users -->
        <div>
            <div class="flex items-center gap-2 mb-4">
                <div class="w-2.5 h-2.5 rounded-full" style="background:#8B5CF6"></div>
                <span class="text-[10px] font-black uppercase tracking-[.18em] text-slate-400">ผู้ใช้งาน</span>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden col-span-1">
                    <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#8B5CF6,#6D28D9)"></div>
                    <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1.5">ทั้งหมด</p>
                    <div class="text-2xl font-black text-gray-900"><?= number_format($kpis['users']) ?></div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden col-span-1">
                    <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#EC4899,#BE185D)"></div>
                    <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1.5">เดือนนี้</p>
                    <div class="text-2xl font-black text-gray-900"><?= number_format($_us_month) ?></div>
                    <?php if ($_us_last > 0):
                        $g = round(($_us_month-$_us_last)/$_us_last*100); ?>
                    <span class="text-[9px] font-black <?= $g>=0?'text-emerald-600':'text-red-500' ?>">
                        <?= ($g>=0?'+':'').$g ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden col-span-1">
                    <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#06B6D4,#0891B2)"></div>
                    <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1.5">เดือนก่อน</p>
                    <div class="text-2xl font-black text-gray-900"><?= number_format($_us_last) ?></div>
                </div>
            </div>
        </div>

        <!-- e_Borrow -->
        <div>
            <div class="flex items-center gap-2 mb-4">
                <div class="w-2.5 h-2.5 rounded-full" style="background:#F97316"></div>
                <span class="text-[10px] font-black uppercase tracking-[.18em] text-slate-400">ระบบยืมอุปกรณ์ (e_Borrow)</span>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden">
                    <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#F97316,#EA580C)"></div>
                    <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1.5">ทั้งหมด</p>
                    <div class="text-2xl font-black text-gray-900"><?= number_format($_br['total']) ?></div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden">
                    <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#2e9e63,#16a34a)"></div>
                    <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1.5">กำลังยืม</p>
                    <div class="text-2xl font-black" style="color:#2e9e63"><?= number_format($_br['active']) ?></div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative overflow-hidden">
                    <div class="absolute right-0 top-0 w-1 h-full rounded-r-2xl" style="background:linear-gradient(180deg,#EF4444,#B91C1C)"></div>
                    <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1.5">เกินกำหนด</p>
                    <div class="text-2xl font-black <?= $_br['overdue']>0?'text-red-500':'text-gray-900' ?>">
                        <?= number_format($_br['overdue']) ?>
                    </div>
                    <?php if ($_br['overdue'] > 0): ?>
                    <span class="text-[9px] font-black text-red-500">⚠ ต้องติดตาม</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

</div>
