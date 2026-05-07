<?php
// user/post_checkin_survey.php — แบบสอบถามบังคับหลังเช็คอินสำเร็จ
// User เข้ามาหน้านี้หลัง check-in (slot/campaign) → ตอบคำถาม → set survey_done_at → กลับหน้า hub
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/survey_helper.php';

$pdo = db();
ensure_survey_schema($pdo);

// ── Auth: must be logged in via LINE ──
$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') { header('Location: index.php'); exit; }

$user = null;
try {
    $st = $pdo->prepare("SELECT id, full_name FROM sys_users
        WHERE line_user_id = :lid OR line_user_id_new = :lid2 LIMIT 1");
    $st->execute([':lid' => $lineUserId, ':lid2' => $lineUserId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
} catch (PDOException) {}
if (!$user) { header('Location: index.php'); exit; }

// ── Resolve booking: ?booking= or auto-detect pending ──
$bookingId = (int)($_GET['booking'] ?? $_POST['booking_id'] ?? 0);
$booking   = null;

if ($bookingId > 0) {
    try {
        $st = $pdo->prepare("SELECT b.id, b.student_id, b.campaign_id, b.attended_at, b.survey_done_at,
                                    c.title AS campaign_title
            FROM camp_bookings b
            LEFT JOIN camp_list c ON b.campaign_id = c.id
            WHERE b.id = :id LIMIT 1");
        $st->execute([':id' => $bookingId]);
        $booking = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {}
}

// Auto-detect: ถ้าไม่ได้ระบุ booking → หา pending ของ user คนนี้
if (!$booking) {
    $pending = find_pending_survey_booking($pdo, (int)$user['id']);
    if ($pending) {
        $booking = array_merge($pending, ['student_id' => (int)$user['id'], 'survey_done_at' => null]);
        $bookingId = (int)$pending['id'];
    }
}

// ── Guard rails ──
if (!$booking) {
    // ไม่มี booking ที่รอ survey → กลับหน้า hub
    header('Location: hub.php');
    exit;
}
if ((int)$booking['student_id'] !== (int)$user['id']) {
    // ป้องกัน user A เข้า survey ของ user B
    header('Location: hub.php');
    exit;
}
if (empty($booking['attended_at'])) {
    // ยังไม่เช็คอินจริง → ไม่มีสิทธิ์ทำ survey
    header('Location: hub.php');
    exit;
}
if (!empty($booking['survey_done_at'])) {
    // ทำไปแล้ว → กลับหน้า hub
    header('Location: hub.php');
    exit;
}

// ── Load active questions ──
$questions = get_survey_questions($pdo, 'post_checkin');
if (empty($questions)) {
    // ไม่มีคำถาม active → mark ว่าเสร็จเลย แล้วกลับ hub
    $pdo->prepare("UPDATE camp_bookings SET survey_done_at = NOW() WHERE id = :id")->execute([':id' => $bookingId]);
    header('Location: hub.php?survey=skipped');
    exit;
}

// ── POST = submit answers ──
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token หมดอายุ — กรุณาโหลดหน้าใหม่';
    } else {
        $answers = []; // [question_id => ['rating'=>n,'text'=>s]]
        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $key = "q_$qid";
            $val = $_POST[$key] ?? '';
            $isRequired = (int)$q['is_required'] === 1;

            if ($q['answer_type'] === 'rating') {
                $r = (int)$val;
                if ($isRequired && ($r < 1 || $r > 5)) {
                    $errors[] = 'กรุณาให้คะแนนข้อ "' . htmlspecialchars($q['question_text']) . '"';
                    continue;
                }
                if ($r >= 1 && $r <= 5) $answers[$qid] = ['rating' => $r];
            } elseif ($q['answer_type'] === 'single_choice') {
                $opts = $q['options_json'] ? (json_decode($q['options_json'], true) ?: []) : [];
                if ($isRequired && !in_array($val, $opts, true)) {
                    $errors[] = 'กรุณาเลือกคำตอบข้อ "' . htmlspecialchars($q['question_text']) . '"';
                    continue;
                }
                if (in_array($val, $opts, true)) $answers[$qid] = ['text' => $val];
            } else { // text
                $t = trim((string)$val);
                if ($isRequired && $t === '') {
                    $errors[] = 'กรุณาตอบข้อ "' . htmlspecialchars($q['question_text']) . '"';
                    continue;
                }
                if ($t !== '') $answers[$qid] = ['text' => mb_substr($t, 0, 1000)];
            }
        }

        if (empty($errors)) {
            // คำนวณ rating รวม (เฉลี่ยจากข้อ rating ทั้งหมด) เพื่อใส่คอลัมน์ rating เดิมของ satisfaction_surveys
            $ratings = array_column(array_filter($answers, fn($a) => isset($a['rating'])), 'rating');
            $avgRating = $ratings ? (int)round(array_sum($ratings) / count($ratings)) : 0;

            // หา comment จากข้อ text (ตัวสุดท้าย)
            $comments = array_filter(array_column($answers, 'text'));
            $combinedComment = $comments ? implode(' | ', $comments) : null;

            try {
                $pdo->beginTransaction();

                $ins = $pdo->prepare("INSERT INTO satisfaction_surveys
                    (booking_id, survey_type, student_id, rating, comment, page_context, ip_hash)
                    VALUES (:bid, 'post_checkin', :sid, :r, :c, 'post_checkin', :ip)");
                $ins->execute([
                    ':bid' => $bookingId,
                    ':sid' => (int)$user['id'],
                    ':r'   => $avgRating ?: 0,
                    ':c'   => $combinedComment ? mb_substr($combinedComment, 0, 1000) : null,
                    ':ip'  => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
                ]);
                $surveyId = (int)$pdo->lastInsertId();

                $insA = $pdo->prepare("INSERT INTO sys_survey_answers
                    (survey_id, question_id, value_text, value_rating)
                    VALUES (:sid, :qid, :t, :r)");
                foreach ($answers as $qid => $a) {
                    $insA->execute([
                        ':sid' => $surveyId,
                        ':qid' => $qid,
                        ':t'   => $a['text']   ?? null,
                        ':r'   => $a['rating'] ?? null,
                    ]);
                }

                $pdo->prepare("UPDATE camp_bookings SET survey_done_at = NOW() WHERE id = :id")
                    ->execute([':id' => $bookingId]);

                $pdo->commit();
                header('Location: hub.php?survey=done');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('post_checkin_survey save failed: ' . $e->getMessage());
                $errors[] = 'บันทึกไม่สำเร็จ — กรุณาลองใหม่';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>แบบสอบถาม — <?= htmlspecialchars($booking['campaign_title'] ?? 'หลังเช็คอิน') ?></title>
<link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8') : '../favicon.ico?v=' . APP_VERSION ?>">
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<style>
    * { font-family: 'Sarabun', sans-serif; }
    body { background: linear-gradient(135deg, #fdf2f8 0%, #fff 50%, #fce7f3 100%); min-height: 100vh; }
    .pcs-star { width: 44px; height: 44px; cursor: pointer; transition: transform .15s, color .15s; color: #cbd5e1; }
    .pcs-star:hover { color: #f59e0b; transform: scale(1.1); }
    .pcs-star.selected { color: #f59e0b; }
    .pcs-star.selected ~ .pcs-star { color: #cbd5e1; }
    .pcs-choice {
        display: block; padding: 14px 16px; border: 2px solid #e2e8f0; border-radius: 14px;
        font-weight: 700; cursor: pointer; transition: all .15s; background: #fff;
    }
    .pcs-choice:hover { border-color: #f9a8d4; background: #fdf2f8; }
    .pcs-choice input { display: none; }
    .pcs-choice.selected { border-color: #db2777; background: #fce7f3; color: #831843; }
</style>
</head>
<body class="p-4">

<div class="max-w-md mx-auto pt-6 pb-12">

    <!-- Header -->
    <div class="text-center mb-6">
        <div class="inline-flex w-16 h-16 mb-3 rounded-2xl bg-pink-100 text-pink-600 items-center justify-center text-3xl">
            <i class="fa-solid fa-clipboard-list"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-900">แบบสอบถามความพึงพอใจ</h1>
        <p class="text-sm text-slate-500 font-bold mt-1">ขอบคุณที่เช็คอินเข้าร่วม<br><strong class="text-pink-700"><?= htmlspecialchars($booking['campaign_title'] ?? 'กิจกรรม') ?></strong></p>
        <div class="inline-flex items-center gap-1.5 mt-3 px-3 py-1 rounded-full bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-black">
            <i class="fa-solid fa-circle-exclamation text-[10px]"></i>
            กรุณาตอบทุกข้อก่อนปิดหน้านี้
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4 mb-4">
        <?php foreach ($errors as $e): ?>
        <div class="flex items-start gap-2 text-sm text-rose-800 font-bold">
            <i class="fa-solid fa-circle-xmark text-rose-500 mt-0.5"></i>
            <span><?= htmlspecialchars($e) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="pcs-form" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
        <input type="hidden" name="booking_id" value="<?= (int)$bookingId ?>">

        <?php foreach ($questions as $i => $q):
            $qid  = (int)$q['id'];
            $req  = (int)$q['is_required'] === 1;
            $prev = $_POST["q_$qid"] ?? '';
            $opts = $q['options_json'] ? (json_decode($q['options_json'], true) ?: []) : [];
        ?>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-sm font-black text-slate-800 mb-3">
                <span class="text-pink-600 mr-1"><?= $i + 1 ?>.</span>
                <?= htmlspecialchars($q['question_text']) ?>
                <?php if ($req): ?><span class="text-rose-500">*</span><?php endif; ?>
            </p>

            <?php if ($q['answer_type'] === 'rating'): ?>
                <div class="flex items-center justify-center gap-1.5" data-rating="<?= $qid ?>">
                    <input type="hidden" name="q_<?= $qid ?>" value="<?= htmlspecialchars((string)$prev) ?>" data-rating-input>
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="fa-solid fa-star pcs-star <?= ((int)$prev >= $s) ? 'selected' : '' ?>" data-star="<?= $s ?>"></i>
                    <?php endfor; ?>
                </div>
                <div class="flex justify-between mt-1.5 px-1 text-[10px] font-bold text-slate-400">
                    <span>น้อยที่สุด</span><span>มากที่สุด</span>
                </div>

            <?php elseif ($q['answer_type'] === 'single_choice'): ?>
                <div class="space-y-2">
                    <?php foreach ($opts as $opt): ?>
                    <label class="pcs-choice <?= $prev === $opt ? 'selected' : '' ?>">
                        <input type="radio" name="q_<?= $qid ?>" value="<?= htmlspecialchars($opt) ?>" <?= $prev === $opt ? 'checked' : '' ?>>
                        <?= htmlspecialchars($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>

            <?php else: // text ?>
                <textarea name="q_<?= $qid ?>" rows="3" maxlength="1000"
                    placeholder="<?= $req ? 'กรุณาตอบ' : 'ตอบเพิ่มเติมได้ตามต้องการ' ?>"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-pink-400 resize-none"><?= htmlspecialchars((string)$prev) ?></textarea>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button type="submit" id="pcs-submit"
            class="w-full py-4 bg-pink-600 text-white rounded-2xl text-base font-black hover:bg-pink-700 active:scale-[.98] transition-all shadow-lg shadow-pink-200 flex items-center justify-center gap-2">
            <i class="fa-solid fa-paper-plane"></i>
            ส่งแบบสอบถาม
        </button>

        <p class="text-center text-[11px] text-slate-400 font-bold pt-2">
            <i class="fa-solid fa-shield-halved mr-1"></i>
            คำตอบของคุณจะถูกใช้เพื่อปรับปรุงบริการเท่านั้น
        </p>
    </form>
</div>

<script>
let pcsSubmitting = false;

// ── Star ratings ──
document.querySelectorAll('[data-rating]').forEach(group => {
    const input = group.querySelector('[data-rating-input]');
    const stars = group.querySelectorAll('.pcs-star');
    stars.forEach(star => {
        star.addEventListener('click', () => {
            const v = parseInt(star.dataset.star, 10);
            input.value = String(v);
            stars.forEach(s => s.classList.toggle('selected', parseInt(s.dataset.star, 10) <= v));
        });
    });
});

// ── Choice cards ──
document.querySelectorAll('.pcs-choice').forEach(label => {
    label.addEventListener('click', () => {
        const radio = label.querySelector('input[type=radio]');
        if (!radio) return;
        document.querySelectorAll(`.pcs-choice input[name="${radio.name}"]`).forEach(r => {
            r.closest('.pcs-choice').classList.remove('selected');
        });
        label.classList.add('selected');
    });
});

// ── Force completion: warn before unload, disable double submit ──
window.addEventListener('beforeunload', e => {
    if (pcsSubmitting) return;
    e.preventDefault();
    e.returnValue = 'แบบสอบถามยังไม่ถูกส่ง — แน่ใจว่าจะออก?';
    return e.returnValue;
});

document.getElementById('pcs-form').addEventListener('submit', e => {
    pcsSubmitting = true;
    const btn = document.getElementById('pcs-submit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังส่ง...';
});
</script>

</body>
</html>
