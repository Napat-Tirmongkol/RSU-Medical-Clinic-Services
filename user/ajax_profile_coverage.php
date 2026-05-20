<?php
// user/ajax_profile_coverage.php
// คืน HTML ของ section "ความคุ้มครอง" (insurance) + "บัตรทอง" (gold_card)
// แยกออกจาก profile.php เพื่อไม่ block page render
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    echo json_encode(['ok' => false, 'insurance_html' => '', 'gold_card_html' => '']);
    exit;
}

$pdo = db();

// Load minimal user fields
$user = null;
try {
    $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id
        FROM sys_users WHERE line_user_id = :lid LIMIT 1");
    $stmt->execute([':lid' => $lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) { error_log('[coverage] user load: ' . $e->getMessage()); }

if (!$user) {
    echo json_encode(['ok' => false, 'insurance_html' => '', 'gold_card_html' => '']);
    exit;
}

$fullName    = trim((string)($user['full_name'] ?? ''));
$citizenId   = trim((string)($user['citizen_id'] ?? ''));
$idNumber    = trim((string)($user['student_personnel_id'] ?? ''));
$userId      = (int)$user['id'];

// ── Insurance lookup ──
$insurance = null;
if (defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE && $idNumber !== '') {
    try {
        $insStmt = $pdo->prepare("SELECT policy_number, insurance_status, coverage_start,
            coverage_end, member_status FROM insurance_members WHERE member_id = :mid LIMIT 1");
        $insStmt->execute([':mid' => $idNumber]);
        $insurance = $insStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { /* table may not exist */ }
}

// ── Gold Card lookup — ใช้ UNION แยกตาม index ที่ตรงกัน (เร็วกว่า OR + TRIM) ──
// trim full_name ใน PHP เพื่อให้ MySQL ใช้ index ได้
$goldCard = null;
try {
    // First try by linked_user_id (most reliable, indexed)
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, status, hospital_main, hospital_sub,
            coverage_start, coverage_end FROM gold_card_members
            WHERE linked_user_id = :uid AND deleted_at IS NULL
            ORDER BY (status = 'active') DESC, (status = 'approved') DESC, id DESC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $goldCard = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    // Then by citizen_id
    if (!$goldCard && $citizenId !== '') {
        $stmt = $pdo->prepare("SELECT id, full_name, status, hospital_main, hospital_sub,
            coverage_start, coverage_end FROM gold_card_members
            WHERE citizen_id = :cid AND deleted_at IS NULL
            ORDER BY (status = 'active') DESC, (status = 'approved') DESC, id DESC LIMIT 1");
        $stmt->execute([':cid' => $citizenId]);
        $goldCard = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    // Last by full_name (lower priority, no TRIM)
    if (!$goldCard && $fullName !== '') {
        $stmt = $pdo->prepare("SELECT id, full_name, status, hospital_main, hospital_sub,
            coverage_start, coverage_end FROM gold_card_members
            WHERE full_name = :fn AND deleted_at IS NULL
            ORDER BY (status = 'active') DESC, (status = 'approved') DESC, id DESC LIMIT 1");
        $stmt->execute([':fn' => $fullName]);
        $goldCard = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $e) { /* table may not exist */ }

// ── Helpers ──
function _vh(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function _thaiShort($d): string {
    if (!$d) return '—';
    $ts = strtotime((string)$d);
    if (!$ts) return '—';
    return date('d/m/', $ts) . substr((string)((int)date('Y', $ts) + 543), -2);
}

// ── Render Insurance ──
ob_start();
if (defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE):
?>
<div class="bg-white rounded-[2.5rem] p-6 border border-slate-50 shadow-sm mb-6">
    <div class="flex items-center gap-2 mb-4">
        <span class="h-5 w-1 rounded-full bg-rose-500"></span>
        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700">ความคุ้มครอง</h3>
        <span class="ml-auto text-[10px] font-black text-slate-400 uppercase tracking-widest">Medical Coverage</span>
    </div>
    <?php if ($insurance === null): ?>
        <div class="rounded-2xl bg-slate-50 border border-slate-100 p-5 flex flex-col items-center text-center gap-2">
            <div class="w-12 h-12 rounded-2xl bg-white border border-slate-100 text-slate-400 flex items-center justify-center text-xl">
                <i class="fa-solid fa-shield-xmark"></i>
            </div>
            <p class="text-sm font-black text-slate-700">ไม่พบข้อมูลประกัน</p>
            <p class="text-[11px] font-bold text-slate-400">กรุณาติดต่อเจ้าหน้าที่ห้องพยาบาล</p>
        </div>
    <?php else:
        $insActive   = ($insurance['insurance_status'] ?? '') === 'Active';
        $policyNo    = $insurance['policy_number'] ?? '';
        $memberType  = $insurance['member_status'] ?? '';
    ?>
        <?php if (!$insActive): ?>
            <div class="rounded-2xl bg-amber-50 border border-amber-100 p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">Inactive</p>
                        <p class="text-sm font-black text-slate-900">สิทธิ์ประกันไม่พร้อมใช้งาน</p>
                    </div>
                </div>
                <p class="text-[12px] font-bold text-amber-700 leading-relaxed">
                    ข้อมูลประกันของคุณอยู่ในสถานะ Inactive กรุณาติดต่อห้องพยาบาลเพื่อตรวจสอบสิทธิ์
                </p>
            </div>
        <?php else: ?>
            <div class="rounded-2xl bg-gradient-to-br from-rose-50 to-rose-100/60 border border-rose-100 p-5">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-rose-600">Personal Accident</p>
                        <h4 class="text-base font-black text-slate-900 leading-tight mt-0.5">บัตรประกันอุบัติเหตุ</h4>
                    </div>
                    <span class="inline-flex px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-widest">Active</span>
                </div>
                <dl class="space-y-3 text-[13px]">
                    <div class="grid grid-cols-[100px_1fr] gap-2">
                        <dt class="text-slate-500 font-bold">เลขที่กรมธรรม์</dt>
                        <dd class="text-slate-900 font-black tracking-wider truncate"><?= $policyNo !== '' ? _vh($policyNo) : '—' ?></dd>
                    </div>
                    <div class="grid grid-cols-[100px_1fr] gap-2">
                        <dt class="text-slate-500 font-bold">ผู้เอาประกัน</dt>
                        <dd class="text-slate-900 font-black truncate"><?= _vh($fullName ?: '—') ?></dd>
                    </div>
                    <div class="grid grid-cols-[100px_1fr] gap-2">
                        <dt class="text-slate-500 font-bold">เริ่มคุ้มครอง</dt>
                        <dd class="text-slate-900 font-black tracking-wider"><?= _vh(_thaiShort($insurance['coverage_start'] ?? null)) ?></dd>
                    </div>
                    <div class="grid grid-cols-[100px_1fr] gap-2">
                        <dt class="text-slate-500 font-bold">สิ้นสุด</dt>
                        <dd class="text-slate-900 font-black tracking-wider"><?= _vh(_thaiShort($insurance['coverage_end'] ?? null)) ?></dd>
                    </div>
                    <div class="grid grid-cols-[100px_1fr] gap-2 pt-2 border-t border-rose-100">
                        <dt class="text-slate-500 font-bold">วงเงินรักษา</dt>
                        <dd class="text-rose-600 font-black text-lg leading-none">฿40,000<span class="text-[11px] text-slate-500 font-bold ml-1">/ ครั้ง</span></dd>
                    </div>
                    <?php if ($memberType): ?>
                    <div class="grid grid-cols-[100px_1fr] gap-2">
                        <dt class="text-slate-500 font-bold">ประเภท</dt>
                        <dd class="text-slate-700 font-bold uppercase tracking-wide"><?= _vh($memberType) ?></dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
endif;
$insuranceHtml = (string)ob_get_clean();

// ── Render Gold Card ──
ob_start();
if ($goldCard !== null):
    $gcActive = in_array($goldCard['status'] ?? '', ['approved', 'active'], true);
    $gcHospMain = $goldCard['hospital_main'] ?? '';
    $gcHospSub  = $goldCard['hospital_sub'] ?? '';
    $gcCovStart = $goldCard['coverage_start'] ?? null;
    $gcCovEnd   = $goldCard['coverage_end'] ?? null;
    $gcStatusLabel = [
        'pending'=>'รอเอกสาร','submitted'=>'ส่งแล้ว','approved'=>'อนุมัติ',
        'active'=>'ใช้งาน','rejected'=>'ไม่ผ่าน','expired'=>'หมดอายุ'
    ][$goldCard['status'] ?? 'pending'] ?? '—';
?>
<div class="bg-white rounded-[2.5rem] p-6 border border-slate-50 shadow-sm mb-6">
    <div class="flex items-center gap-2 mb-4">
        <span class="h-5 w-1 rounded-full bg-amber-500"></span>
        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700">บัตรทอง</h3>
        <span class="ml-auto text-[10px] font-black text-slate-400 uppercase tracking-widest">Universal Coverage</span>
    </div>
    <?php if ($gcActive): ?>
        <div class="rounded-2xl bg-gradient-to-br from-amber-50 to-orange-100/60 border border-amber-200 p-5">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">Universal Coverage</p>
                    <h4 class="text-base font-black text-slate-900 leading-tight mt-0.5">บัตรทอง (สิทธิหลักประกันสุขภาพ)</h4>
                </div>
                <span class="inline-flex px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-widest">Active</span>
            </div>
            <dl class="space-y-3 text-[13px]">
                <div class="grid grid-cols-[100px_1fr] gap-2">
                    <dt class="text-slate-500 font-bold">ผู้ถือสิทธิ์</dt>
                    <dd class="text-slate-900 font-black truncate"><?= _vh($fullName ?: ($goldCard['full_name'] ?? '—')) ?></dd>
                </div>
                <?php if ($gcHospMain): ?>
                <div class="grid grid-cols-[100px_1fr] gap-2">
                    <dt class="text-slate-500 font-bold">รพ.หลัก</dt>
                    <dd class="text-slate-900 font-black"><?= _vh($gcHospMain) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($gcHospSub): ?>
                <div class="grid grid-cols-[100px_1fr] gap-2">
                    <dt class="text-slate-500 font-bold">รพ.รอง</dt>
                    <dd class="text-slate-700 font-bold"><?= _vh($gcHospSub) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($gcCovStart || $gcCovEnd): ?>
                <div class="grid grid-cols-[100px_1fr] gap-2">
                    <dt class="text-slate-500 font-bold">คุ้มครอง</dt>
                    <dd class="text-slate-900 font-black tracking-wider"><?= _vh(_thaiShort($gcCovStart)) ?> — <?= _vh(_thaiShort($gcCovEnd)) ?></dd>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-[100px_1fr] gap-2 pt-2 border-t border-amber-200">
                    <dt class="text-slate-500 font-bold">สิทธิประโยชน์</dt>
                    <dd class="text-amber-700 font-black">รักษาฟรีตามสิทธิ์ <span class="text-[11px] text-slate-500 font-bold ml-1">@ รพ.ที่ขึ้นทะเบียน</span></dd>
                </div>
            </dl>
        </div>
    <?php else: ?>
        <div class="rounded-2xl bg-slate-50 border border-slate-100 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-700"><?= _vh($gcStatusLabel) ?></p>
                    <p class="text-sm font-black text-slate-900">บัตรทองยังไม่พร้อมใช้งาน</p>
                </div>
            </div>
            <p class="text-[12px] font-bold text-slate-600 leading-relaxed">
                สิทธิ์บัตรทองของคุณอยู่ในสถานะ <b><?= _vh($gcStatusLabel) ?></b> กรุณาติดต่อห้องพยาบาลเพื่อตรวจสอบ
            </p>
        </div>
    <?php endif; ?>
</div>
<?php
endif;
$goldCardHtml = (string)ob_get_clean();

echo json_encode([
    'ok' => true,
    'insurance_html'  => $insuranceHtml,
    'gold_card_html'  => $goldCardHtml,
], JSON_UNESCAPED_UNICODE);
