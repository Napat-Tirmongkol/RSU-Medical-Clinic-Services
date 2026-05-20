<?php
// user/pdpa_reconsent.php — Forced re-consent for legacy users
//   Legacy users (registered before PDPA v2) get redirected here from
//   hub.php and any other entry that calls the gate. They cannot
//   continue without ticking both granular consent boxes — exactly
//   the same audit flow as a fresh registration.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, full_name, consent_general_accepted_at, consent_sensitive_accepted_at
                           FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Schema not migrated yet — treat as needing consent
    $user = null;
}

if (!$user) {
    header('Location: index.php');
    exit;
}

// Already consented? Skip back to wherever they came from
if (!empty($user['consent_general_accepted_at']) && !empty($user['consent_sensitive_accepted_at'])) {
    $returnUrl = (string)($_GET['return'] ?? 'hub.php');
    // Whitelist — only allow same-app paths so an attacker can't redirect off-domain
    if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+(\?[^\s]*)?$/', $returnUrl)) $returnUrl = 'hub.php';
    header('Location: ' . $returnUrl);
    exit;
}

$returnUrl = (string)($_GET['return'] ?? 'hub.php');
if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+(\?[^\s]*)?$/', $returnUrl)) $returnUrl = 'hub.php';
$pdpaVersion = 'pdpa_v2_2025-05';
$displayName = htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>ทบทวนนโยบายความเป็นส่วนตัว · RSU Medical</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8') : '../favicon.ico?v=' . APP_VERSION ?>">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background: linear-gradient(135deg,#f0fdf4 0%,#eff6ff 100%); min-height: 100vh; }
        .reconsent-wrap { max-width: 720px; margin: 0 auto; padding: 24px 16px 60px; }
        .banner { background: linear-gradient(135deg,#7c3aed,#2e9e63); color: #fff; border-radius: 24px; padding: 22px 24px; margin-bottom: 18px; box-shadow: 0 10px 30px -10px rgba(124,58,237,0.3); }
        .banner h1 { font-size: 20px; font-weight: 900; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
        .banner p { font-size: 13px; font-weight: 600; opacity: 0.95; margin: 0; line-height: 1.55; }
        .pdpa-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 18px; padding: 18px; max-height: 360px; overflow-y: auto; font-size: 12px; line-height: 1.7; color: #475569; }
        .pdpa-box .h-section { font-weight: 900; color: #0f172a; margin: 8px 0 4px; font-size: 13px; }
        .pdpa-box .h-section-em { font-weight: 900; font-size: 14px; padding-top: 8px; margin-top: 12px; border-top: 1px solid #e2e8f0; }
        .pdpa-box .h-section-em.green { color: #15803d; }
        .pdpa-box .h-section-em.rose  { color: #be123c; }
        .pdpa-box .h-sub { font-weight: 800; color: #334155; }
        .agree-card { background: #fff; border-radius: 18px; padding: 16px 18px; border: 1.5px solid; transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s; cursor: pointer; display: flex; align-items: flex-start; gap: 14px; }
        .agree-card.disabled { opacity: 0.5; pointer-events: none; }
        .agree-card[data-tone="general"]   { border-color: #bbf7d0; background: #f0fdf4; }
        .agree-card[data-tone="general"]:has(input:checked)   { border-color: #15803d; background: #dcfce7; box-shadow: 0 4px 12px -4px rgba(21,128,61,0.25); }
        .agree-card[data-tone="sensitive"] { border-color: #fecdd3; background: #fff1f2; }
        .agree-card[data-tone="sensitive"]:has(input:checked) { border-color: #be123c; background: #ffe4e6; box-shadow: 0 4px 12px -4px rgba(190,18,60,0.25); }
        .agree-card input[type="checkbox"] { width: 24px; height: 24px; margin-top: 2px; cursor: pointer; flex-shrink: 0; }
        .agree-card .lbl { font-size: 13px; font-weight: 700; color: #0f172a; line-height: 1.55; }
        .agree-card .meta { font-size: 11px; font-weight: 600; color: #64748b; margin-top: 2px; }
        .submit-btn { width: 100%; padding: 16px; border-radius: 16px; font-size: 15px; font-weight: 900; cursor: pointer; transition: filter 0.15s, transform 0.18s; border: 0; background: linear-gradient(135deg,#7c3aed,#2e9e63); color: #fff; box-shadow: 0 12px 28px -10px rgba(46,158,99,0.5); }
        .submit-btn:hover:not(:disabled) { filter: brightness(1.05); transform: translateY(-1px); }
        .submit-btn:disabled { opacity: 0.4; cursor: not-allowed; background: #cbd5e1; box-shadow: none; }
        .secondary-btn { width: 100%; padding: 12px; margin-top: 10px; border-radius: 14px; font-size: 13px; font-weight: 800; color: #64748b; background: transparent; border: 1.5px solid #cbd5e1; cursor: pointer; transition: background 0.15s; }
        .secondary-btn:hover { background: #f8fafc; }
        .scroll-hint { font-size: 11px; font-weight: 800; color: #b45309; text-align: center; margin: 6px 0 10px; display: flex; align-items: center; justify-content: center; gap: 4px; }
        .scroll-hint.hidden { display: none; }
    </style>
</head>
<body>
    <div class="reconsent-wrap">
        <div class="banner">
            <h1><i class="fa-solid fa-shield-halved"></i> ทบทวนนโยบายความเป็นส่วนตัว</h1>
            <p>สวัสดีคุณ <b><?= $displayName ?></b> · เราได้อัพเดตนโยบายความเป็นส่วนตัวเพื่อให้สอดคล้องกับ พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล (PDPA 2562) — กรุณาทบทวนและยืนยันความยินยอมเพื่อใช้งานต่อ</p>
        </div>

        <form id="reconsentForm" action="save_consent.php" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
            <input type="hidden" name="pdpa_version" value="<?= htmlspecialchars($pdpaVersion) ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl) ?>">

            <div id="pdpa-box" class="pdpa-box mb-3">
                <div class="text-slate-900 font-black"><?= __('profile.pdpa_welcome') ?></div>

                <div class="h-section"><?= __('profile.pdpa_controller_title') ?></div>
                <p><?= __('profile.pdpa_controller_desc') ?></p>

                <div class="h-section"><?= __('profile.pdpa_legal_basis_title') ?></div>
                <p><?= __('profile.pdpa_legal_basis_desc') ?></p>

                <p class="mt-2"><?= __('profile.pdpa_intro') ?></p>

                <div class="h-section-em green"><?= __('profile.pdpa_section_general') ?></div>
                <div class="h-sub mt-1"><?= __('profile.pdpa_general_cats_title') ?></div>
                <p><?= __('profile.pdpa_general_cats_desc') ?></p>
                <div class="h-sub mt-2"><?= __('profile.pdpa_purposes_title') ?></div>
                <?php foreach ([1,2,3,4] as $i): ?>
                    <p><b><?= __("profile.pdpa_item{$i}_title") ?></b> <?= __("profile.pdpa_item{$i}_desc") ?></p>
                <?php endforeach; ?>
                <div class="h-sub mt-2"><?= __('profile.pdpa_third_party_title') ?></div>
                <p><?= __('profile.pdpa_third_party_desc') ?></p>
                <div class="h-sub mt-2"><?= __('profile.pdpa_retention_title') ?></div>
                <p><?= __('profile.pdpa_retention_desc') ?></p>

                <div class="h-section-em rose"><?= __('profile.pdpa_section_sensitive') ?></div>
                <p class="text-rose-700 font-bold"><?= __('profile.pdpa_sensitive_intro') ?></p>
                <div class="h-sub mt-2"><?= __('profile.pdpa_sensitive_cats_title') ?></div>
                <p><?= __('profile.pdpa_sensitive_cats_desc') ?></p>
                <div class="h-sub mt-2"><?= __('profile.pdpa_sensitive_purpose_title') ?></div>
                <p><?= __('profile.pdpa_sensitive_purpose_desc') ?></p>

                <div class="h-section mt-3"><?= __('profile.pdpa_rights_title') ?></div>
                <p><?= __('profile.pdpa_rights_desc') ?></p>
                <div class="h-section"><?= __('profile.pdpa_withdrawal_title') ?></div>
                <p><?= __('profile.pdpa_withdrawal_desc') ?></p>
                <div class="h-section"><?= __('profile.pdpa_refusal_title') ?></div>
                <p><?= __('profile.pdpa_refusal_desc') ?></p>
                <div class="h-section"><?= __('profile.pdpa_complaint_title') ?></div>
                <p><?= __('profile.pdpa_complaint_desc') ?></p>
            </div>

            <p id="scroll-hint" class="scroll-hint"><i class="fa-solid fa-arrow-down"></i> <?= __('profile.pdpa_scroll_hint') ?></p>

            <div class="space-y-3">
                <label id="agree-general-wrap" class="agree-card disabled" data-tone="general">
                    <input type="checkbox" id="agree-general" name="consent_general" value="1" required disabled>
                    <div>
                        <div class="lbl"><?= __('profile.lbl_agree_general') ?></div>
                        <div class="meta">มาตรา 24 — ฐานความยินยอม / สัญญาบริการ / ภาระตามกฎหมาย</div>
                    </div>
                </label>
                <label id="agree-sensitive-wrap" class="agree-card disabled" data-tone="sensitive">
                    <input type="checkbox" id="agree-sensitive" name="consent_sensitive" value="1" required disabled>
                    <div>
                        <div class="lbl"><?= __('profile.lbl_agree_sensitive') ?></div>
                        <div class="meta">มาตรา 26 — Sensitive Personal Data (สุขภาพ + รูปถ่าย + ลายเซ็น)</div>
                    </div>
                </label>
            </div>

            <button type="submit" id="submit-btn" class="submit-btn mt-5" disabled>
                <i class="fa-solid fa-shield-halved mr-2"></i> ยืนยันความยินยอมและใช้งานต่อ
            </button>
            <button type="button" class="secondary-btn" onclick="rcLogout()">
                <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> ออกจากระบบ (ปฏิเสธ)
            </button>
        </form>
    </div>

<script>
(function() {
    const box = document.getElementById('pdpa-box');
    const hint = document.getElementById('scroll-hint');
    const wrapG = document.getElementById('agree-general-wrap');
    const wrapS = document.getElementById('agree-sensitive-wrap');
    const cbG = document.getElementById('agree-general');
    const cbS = document.getElementById('agree-sensitive');
    const submit = document.getElementById('submit-btn');

    // Unlock both checkboxes once the user scrolls to within 10px of the
    // bottom — the same gate the registration flow uses for first-time
    // consent. Refusing to scroll keeps the checkboxes inert.
    function unlock() {
        wrapG.classList.remove('disabled');
        wrapS.classList.remove('disabled');
        cbG.disabled = false; cbS.disabled = false;
        hint.classList.add('hidden');
        box.removeEventListener('scroll', onScroll);
    }
    function onScroll() {
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - 10) unlock();
    }
    box.addEventListener('scroll', onScroll);
    // If the policy fits in the viewport without scrolling at all, unlock immediately
    if (box.scrollHeight <= box.clientHeight + 4) unlock();

    function refreshSubmit() {
        submit.disabled = !(cbG.checked && cbS.checked);
    }
    cbG.addEventListener('change', refreshSubmit);
    cbS.addEventListener('change', refreshSubmit);

    window.rcLogout = async function() {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning',
            title: 'ปฏิเสธความยินยอม?',
            html: 'หากปฏิเสธ คุณจะออกจากระบบและไม่สามารถใช้งานแอปได้<br>ยังเลือกยินยอมทีหลังได้โดยล็อกอินใหม่',
            showCancelButton: true,
            confirmButtonText: 'ออกจากระบบ',
            cancelButtonText: 'กลับไปทบทวน',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
        });
        if (isConfirmed) window.location.href = 'logout.php';
    };
})();
</script>
</body>
</html>
