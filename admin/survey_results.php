<?php
// admin/survey_results.php — สรุปผลแบบสอบถามหลังเช็คอิน (รายแคมเปญ + raw answers)
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/survey_helper.php';

$pdo = db();
ensure_survey_schema($pdo);

// Filters
$campaignId = (int)($_GET['campaign_id'] ?? 0);
$page       = max(1, (int)($_GET['p'] ?? 1));
$limit      = 20;
$offset     = ($page - 1) * $limit;

$camp_list = [];
try {
    $camp_list = $pdo->query("SELECT id, title FROM camp_list ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

// ── Aggregate stats per campaign ──
$where  = "WHERE s.survey_type = 'post_checkin'";
$params = [];
if ($campaignId > 0) {
    $where .= " AND b.campaign_id = :cid";
    $params[':cid'] = $campaignId;
}

$summary = ['total' => 0, 'avg_rating' => null, 'attended' => 0, 'response_rate' => 0];
try {
    $sql = "SELECT COUNT(DISTINCT s.id) AS total, AVG(s.rating) AS avg_rating
            FROM satisfaction_surveys s
            LEFT JOIN camp_bookings b ON s.booking_id = b.id
            $where";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $summary['total']      = (int)($r['total'] ?? 0);
    $summary['avg_rating'] = $r['avg_rating'] !== null ? round((float)$r['avg_rating'], 2) : null;

    if ($campaignId > 0) {
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = :cid AND attended_at IS NOT NULL");
        $st2->execute([':cid' => $campaignId]);
    } else {
        $st2 = $pdo->query("SELECT COUNT(*) FROM camp_bookings WHERE attended_at IS NOT NULL");
    }
    $summary['attended'] = (int)$st2->fetchColumn();
    if ($summary['attended'] > 0) {
        $summary['response_rate'] = round($summary['total'] / $summary['attended'] * 100, 1);
    }
} catch (PDOException) {}

// ── Per-question breakdown ──
$questions = get_survey_questions($pdo, 'post_checkin');
$qStats    = [];
foreach ($questions as $q) {
    $qid = (int)$q['id'];
    $qStats[$qid] = [
        'question'    => $q,
        'avg'         => null,
        'count'       => 0,
        'distribution'=> [],
    ];
    try {
        $w = "WHERE a.question_id = :qid AND s.survey_type = 'post_checkin'";
        $p = [':qid' => $qid];
        if ($campaignId > 0) {
            $w .= " AND b.campaign_id = :cid";
            $p[':cid'] = $campaignId;
        }
        if ($q['answer_type'] === 'rating') {
            $st = $pdo->prepare("SELECT AVG(a.value_rating) AS avg_r, COUNT(a.id) AS cnt,
                a.value_rating AS r, COUNT(*) AS c
                FROM sys_survey_answers a
                JOIN satisfaction_surveys s ON a.survey_id = s.id
                LEFT JOIN camp_bookings b ON s.booking_id = b.id
                $w
                GROUP BY a.value_rating WITH ROLLUP");
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if ($row['r'] === null) {
                    $qStats[$qid]['avg']   = $row['avg_r'] !== null ? round((float)$row['avg_r'], 2) : null;
                    $qStats[$qid]['count'] = (int)$row['cnt'];
                } else {
                    $qStats[$qid]['distribution'][(int)$row['r']] = (int)$row['c'];
                }
            }
        } else {
            $st = $pdo->prepare("SELECT COALESCE(a.value_text,'') AS v, COUNT(*) AS c
                FROM sys_survey_answers a
                JOIN satisfaction_surveys s ON a.survey_id = s.id
                LEFT JOIN camp_bookings b ON s.booking_id = b.id
                $w
                GROUP BY a.value_text
                ORDER BY c DESC LIMIT 30");
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $qStats[$qid]['count'] = (int)array_sum(array_column($rows, 'c'));
            $qStats[$qid]['distribution'] = $rows;
        }
    } catch (PDOException) {}
}

// ── Recent responses (paginated) ──
$total = 0; $rows = [];
try {
    $countSql = "SELECT COUNT(DISTINCT s.id) FROM satisfaction_surveys s
        LEFT JOIN camp_bookings b ON s.booking_id = b.id
        $where";
    $cs = $pdo->prepare($countSql);
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $listSql = "SELECT s.id, s.rating, s.comment, s.created_at, s.booking_id,
                       b.campaign_id, c.title AS campaign_title,
                       u.full_name AS student_name
        FROM satisfaction_surveys s
        LEFT JOIN camp_bookings b ON s.booking_id = b.id
        LEFT JOIN camp_list c ON b.campaign_id = c.id
        LEFT JOIN sys_users u ON b.student_id = u.id
        $where
        ORDER BY s.created_at DESC
        LIMIT $limit OFFSET $offset";
    $ls = $pdo->prepare($listSql);
    $ls->execute($params);
    $rows = $ls->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$totalPages = max(1, (int)ceil($total / $limit));

require_once __DIR__ . '/includes/header.php';
?>

<?php renderPageHeader("ผลแบบสอบถามหลังเช็คอิน", "สรุปคะแนนความพึงพอใจของผู้เข้าร่วมหลังเช็คอินสำเร็จ"); ?>

<!-- Filter -->
<div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 mb-6">
    <form method="GET" class="flex flex-col md:flex-row gap-3 items-end">
        <div class="flex-1 w-full">
            <label class="block text-sm font-semibold text-gray-700 mb-2">เลือกแคมเปญ</label>
            <select name="campaign_id" onchange="this.form.submit()" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-pink-500 outline-none font-prompt text-gray-700 bg-white font-medium cursor-pointer">
                <option value="0">— ทุกแคมเปญ —</option>
                <?php foreach ($camp_list as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $campaignId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="?campaign_id=0" class="px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-bold text-sm">รีเซ็ต</a>
    </form>
</div>

<!-- Summary cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
        <p class="text-[11px] font-black uppercase tracking-widest text-gray-400 mb-1">ตอบแบบสอบถาม</p>
        <p class="text-3xl font-black text-pink-600"><?= number_format($summary['total']) ?></p>
        <p class="text-xs text-gray-500 mt-1 font-bold">จาก <?= number_format($summary['attended']) ?> ที่เช็คอิน</p>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
        <p class="text-[11px] font-black uppercase tracking-widest text-gray-400 mb-1">อัตราการตอบ</p>
        <p class="text-3xl font-black text-emerald-600"><?= $summary['response_rate'] ?>%</p>
        <p class="text-xs text-gray-500 mt-1 font-bold">response rate</p>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
        <p class="text-[11px] font-black uppercase tracking-widest text-gray-400 mb-1">คะแนนเฉลี่ยรวม</p>
        <p class="text-3xl font-black text-amber-500">
            <?= $summary['avg_rating'] !== null ? number_format($summary['avg_rating'], 2) : '—' ?>
            <?php if ($summary['avg_rating']): ?><span class="text-base text-gray-400">/5</span><?php endif; ?>
        </p>
        <p class="text-xs text-gray-500 mt-1 font-bold">เฉลี่ยทุกข้อ rating</p>
    </div>
    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
        <p class="text-[11px] font-black uppercase tracking-widest text-gray-400 mb-1">คำถาม Active</p>
        <p class="text-3xl font-black text-slate-700"><?= count($questions) ?></p>
        <p class="text-xs text-gray-500 mt-1 font-bold">
            <a href="../portal/index.php?section=clinic_data&cd_view=survey" class="text-pink-600 hover:underline font-black">
                จัดการคำถาม <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
            </a>
        </p>
    </div>
</div>

<!-- Per-question breakdown -->
<?php if (!empty($questions)): ?>
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 mb-6">
    <h3 class="text-base font-black text-slate-800 mb-4">สรุปแยกรายคำถาม</h3>
    <div class="space-y-5">
        <?php foreach ($questions as $i => $q):
            $qid = (int)$q['id'];
            $st  = $qStats[$qid] ?? null;
            if (!$st) continue;
        ?>
        <div class="border-b border-slate-100 pb-5 last:border-b-0 last:pb-0">
            <div class="flex items-start justify-between mb-2">
                <p class="font-black text-slate-800 text-sm">
                    <span class="text-pink-600 mr-1"><?= $i + 1 ?>.</span><?= htmlspecialchars($q['question_text']) ?>
                </p>
                <span class="ml-2 text-[10px] font-black text-slate-400 whitespace-nowrap">ตอบแล้ว <?= number_format($st['count']) ?></span>
            </div>

            <?php if ($q['answer_type'] === 'rating'): ?>
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-2xl font-black text-amber-500">
                        <?= $st['avg'] !== null ? number_format($st['avg'], 2) : '—' ?>
                    </span>
                    <span class="text-xs text-slate-400 font-bold">/5</span>
                </div>
                <div class="space-y-1.5">
                    <?php for ($s = 5; $s >= 1; $s--):
                        $count = (int)($st['distribution'][$s] ?? 0);
                        $pct = $st['count'] > 0 ? round($count / $st['count'] * 100, 1) : 0;
                    ?>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="w-12 font-black text-slate-600 flex items-center gap-0.5">
                            <?= $s ?> <i class="fa-solid fa-star text-amber-400 text-[9px]"></i>
                        </span>
                        <div class="flex-1 h-3 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-amber-400" style="width: <?= $pct ?>%"></div>
                        </div>
                        <span class="w-20 text-right font-bold text-slate-500 text-[11px]"><?= $count ?> · <?= $pct ?>%</span>
                    </div>
                    <?php endfor; ?>
                </div>

            <?php elseif ($q['answer_type'] === 'single_choice'):
                $tot = max(1, $st['count']);
            ?>
                <div class="space-y-1.5">
                    <?php foreach ((array)$st['distribution'] as $row):
                        $v = (string)($row['v'] ?? '');
                        if ($v === '') continue;
                        $c = (int)($row['c'] ?? 0);
                        $pct = round($c / $tot * 100, 1);
                    ?>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="w-32 font-bold text-slate-700 truncate" title="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></span>
                        <div class="flex-1 h-3 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-pink-400" style="width: <?= $pct ?>%"></div>
                        </div>
                        <span class="w-20 text-right font-bold text-slate-500 text-[11px]"><?= $c ?> · <?= $pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php else: // text — show top recurring answers ?>
                <?php if (empty($st['distribution'])): ?>
                <p class="text-xs text-slate-400 italic">ยังไม่มีคำตอบ</p>
                <?php else: ?>
                <div class="text-xs text-slate-600 space-y-1 max-h-40 overflow-y-auto pr-2">
                    <?php foreach ((array)$st['distribution'] as $row):
                        $v = trim((string)($row['v'] ?? ''));
                        if ($v === '') continue;
                    ?>
                    <div class="flex items-start gap-2 py-1 border-b border-slate-50">
                        <i class="fa-solid fa-quote-left text-slate-300 mt-0.5 text-[9px]"></i>
                        <span class="flex-1"><?= htmlspecialchars($v) ?></span>
                        <span class="text-[10px] font-black text-slate-400 whitespace-nowrap">×<?= (int)$row['c'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent responses -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-black text-slate-800">รายการล่าสุด</h3>
        <span class="text-xs font-black text-slate-400">หน้า <?= $page ?>/<?= $totalPages ?> · รวม <?= number_format($total) ?></span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="p-10 text-center">
        <i class="fa-solid fa-clipboard-list text-5xl text-slate-200 mb-3"></i>
        <p class="font-black text-slate-500">ยังไม่มีการตอบแบบสอบถาม</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                <tr>
                    <th class="px-4 py-3 text-left">เวลา</th>
                    <th class="px-4 py-3 text-left">ผู้ตอบ</th>
                    <th class="px-4 py-3 text-left">แคมเปญ</th>
                    <th class="px-4 py-3 text-center">คะแนน</th>
                    <th class="px-4 py-3 text-left">ความเห็น</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr class="border-t border-slate-100 hover:bg-slate-50/50">
                    <td class="px-4 py-3 text-xs font-bold text-slate-500 whitespace-nowrap"><?= htmlspecialchars($r['created_at']) ?></td>
                    <td class="px-4 py-3 font-bold text-slate-700"><?= htmlspecialchars($r['student_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-slate-600"><?= htmlspecialchars($r['campaign_title'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$r['rating'] > 0): ?>
                            <span class="inline-flex items-center gap-0.5 text-amber-500 font-black">
                                <?= (int)$r['rating'] ?> <i class="fa-solid fa-star text-[10px]"></i>
                            </span>
                        <?php else: ?>
                            <span class="text-slate-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600 max-w-md">
                        <?php if (!empty($r['comment'])): ?>
                            <span class="line-clamp-2"><?= htmlspecialchars($r['comment']) ?></span>
                        <?php else: ?>
                            <span class="text-slate-300 italic">ไม่มีความเห็น</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="p-4 border-t border-slate-100 flex items-center justify-center gap-1 text-xs">
        <?php
        $qsBuild = function (int $p) use ($campaignId) {
            $q = ['p' => $p];
            if ($campaignId > 0) $q['campaign_id'] = $campaignId;
            return '?' . http_build_query($q);
        };
        $btn = function (string $href, string $label, bool $active = false, bool $disabled = false) {
            $cls = $active ? 'bg-pink-600 text-white' : ($disabled ? 'bg-slate-50 text-slate-300 pointer-events-none' : 'bg-white text-slate-600 hover:bg-pink-50 border border-slate-200');
            return "<a href=\"$href\" class=\"px-2.5 py-1.5 rounded-lg font-black $cls\">$label</a>";
        };
        echo $btn($qsBuild(1), '«', false, $page === 1);
        echo $btn($qsBuild(max(1, $page - 1)), '‹', false, $page === 1);
        for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++) {
            echo $btn($qsBuild($p), (string)$p, $p === $page);
        }
        echo $btn($qsBuild(min($totalPages, $page + 1)), '›', false, $page === $totalPages);
        echo $btn($qsBuild($totalPages), '»', false, $page === $totalPages);
        ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
