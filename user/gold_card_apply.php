<?php
/**
 * user/gold_card_apply.php
 * หน้าสมัครสิทธิหลักประกันสุขภาพ (บัตรทอง) สำหรับ user
 *
 * Auth: LINE LIFF — ต้อง login ก่อน
 * Flow:
 *  1. ตรวจ session line_user_id
 *  2. ดึง user data จาก sys_users (prefill name, phone, citizen_id)
 *  3. ตรวจว่ามี gold_card_members อยู่แล้วไหม → block / show status
 *  4. แสดง form (signature canvas + photo upload)
 *  5. Submit ผ่าน AJAX → ajax_gold_card_apply.php
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');
check_maintenance('gold_card_apply');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$pdo = db();

// ── Load user from sys_users ────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
$stmt->execute([':line_id' => $lineUserId]);
$user = $stmt->fetch();
if (!$user) {
    header('Location: index.php');
    exit;
}
$_SESSION['user_id'] = (int)$user['id'];

// ── Check existing gold card application ────────────────────────────────────
$existing = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, status, full_name, citizen_id, application_date, created_at
        FROM gold_card_members
        WHERE (linked_user_id = :uid
           OR (citizen_id IS NOT NULL AND citizen_id = :cid))
          AND deleted_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([
        ':uid' => (int)$user['id'],
        ':cid' => $user['citizen_id'] ?? '',
    ]);
    $existing = $stmt->fetch() ?: null;
} catch (PDOException $e) { /* table may not exist yet */ }

// Determine if user can apply
$canApply = true;
$blockMessage = '';
$isStudent = (($user['status'] ?? '') === 'student');

// บัตรทองเปิดเฉพาะนักศึกษา
if (!$isStudent) {
    $canApply = false;
    $blockMessage = 'ระบบบัตรทองเปิดให้สมัครเฉพาะนักศึกษาเท่านั้น หากเป็นบุคลากรหรือบุคคลทั่วไป กรุณาติดต่อเจ้าหน้าที่คลินิกโดยตรง';
} elseif ($existing) {
    $st = $existing['status'] ?? '';
    if (in_array($st, ['pending', 'submitted', 'approved', 'active'], true)) {
        $canApply = false;
        $statusLabel = [
            'pending'  => 'กำลังรอเอกสาร',
            'submitted'=> 'ส่งใบสมัครแล้ว — รอเจ้าหน้าที่ตรวจสอบ',
            'approved' => 'อนุมัติแล้ว',
            'active'   => 'บัตรพร้อมใช้งาน',
        ][$st] ?? $st;
        $blockMessage = "คุณมีใบสมัครอยู่ในสถานะ \"$statusLabel\" แล้ว";
    }
}

// Pre-fill values
$prefillName    = trim((string)($user['full_name'] ?? ''));
$prefillPhone   = trim((string)($user['phone_number'] ?? ''));
$prefillCitizen = trim((string)($user['citizen_id'] ?? ''));
$prefillGender  = trim((string)($user['gender'] ?? ''));
$prefillDob     = trim((string)($user['date_of_birth'] ?? ''));

// Helper for HTML escape
if (!function_exists('vh')) {
    function vh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
$csrfToken = get_csrf_token();
$__navActive = 'services';
?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>สมัครสิทธิหลักประกันสุขภาพ (บัตรทอง)</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <style>
        body { font-family: 'RSU_Regular', 'Sarabun', -apple-system, sans-serif; background: #f8fafc; }
        .field-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; }
        .form-input { width: 100%; padding: 12px 14px; border: 1.5px solid #e2e8f0; border-radius: 14px; font-size: 14px; font-weight: 600; background: white; transition: all 0.2s; }
        .form-input:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1); }
        .form-input:invalid:not(:placeholder-shown) { border-color: #f87171; }
        #signature-canvas { background: white; border: 2px dashed #cbd5e1; border-radius: 14px; touch-action: none; cursor: crosshair; width: 100%; }
        #signature-canvas.has-signature { border-color: #10b981; border-style: solid; }
        .photo-preview { width: 100%; max-height: 280px; object-fit: cover; border-radius: 14px; border: 2px solid #e2e8f0; }
        .upload-zone { border: 2px dashed #cbd5e1; border-radius: 14px; padding: 32px 16px; text-align: center; transition: all 0.2s; cursor: pointer; background: white; }
        .upload-zone:hover { border-color: #f59e0b; background: #fffbeb; }
        .upload-zone.has-file { border-style: solid; border-color: #10b981; background: #f0fdf4; padding: 12px; }
        .submit-btn { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 16px; border-radius: 18px; font-weight: 800; font-size: 15px; width: 100%; border: none; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.3); transition: all 0.2s; }
        .submit-btn:active { transform: scale(0.98); }
        .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .clear-btn { background: #fee2e2; color: #b91c1c; padding: 10px 16px; border-radius: 12px; font-weight: 700; font-size: 13px; border: none; transition: all 0.2s; }
        .clear-btn:active { transform: scale(0.95); }

        /* In-page camera stage (getUserMedia) */
        #cam-stage { z-index: 9999; }
        #cam-stage.is-open { display: flex; }
        #cam-stage video { transform: scaleX(-1); transition: transform 0.2s; }
        #cam-stage video.no-mirror { transform: none; }
        #cam-stage .cam-frame { width: 78%; height: 56%; border: 2px dashed rgba(255,255,255,0.55); border-radius: 1.5rem; }
        #cam-snap-btn:active { transform: scale(0.92); }
        #cam-snap-btn::after { content: ''; position: absolute; inset: 6px; border-radius: 9999px; background: #f59e0b; }
        #cam-stage button { -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="pb-32">
    <!-- Header -->
    <div class="bg-gradient-to-br from-amber-500 to-orange-600 text-white px-6 pt-12 pb-20 relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-48 h-48 bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute -left-8 bottom-0 w-32 h-32 bg-white/5 rounded-full blur-2xl"></div>
        <div class="flex items-center justify-between mb-4">
            <button onclick="history.back()" class="text-white/80 hover:text-white text-sm font-bold active:scale-95 transition-all">
                <i class="fa-solid fa-arrow-left mr-2"></i> กลับ
            </button>
            <a href="../gold_card_help.php" target="_blank" rel="noopener"
               class="text-white/80 hover:text-white text-sm font-bold active:scale-95 transition-all inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/15 backdrop-blur-sm border border-white/20"
               title="ดูคู่มือ">
                <i class="fa-solid fa-book-open"></i> คู่มือ
            </a>
        </div>
        <div class="flex items-center gap-3 mb-2">
            <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center border border-white/30">
                <i class="fa-solid fa-shield-heart text-2xl"></i>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-white/70">Universal Coverage</p>
                <h1 class="text-xl font-black leading-tight">สมัครสิทธิหลักประกันสุขภาพ</h1>
            </div>
        </div>
        <p class="text-[12px] font-bold text-white/80 leading-relaxed mt-2">
            กรอกข้อมูลและส่งเอกสารเพื่อยื่นสมัครบัตรทอง — เจ้าหน้าที่จะตรวจสอบและแจ้งผลภายใน 3-5 วันทำการ
        </p>
    </div>

    <div class="max-w-md mx-auto px-4 -mt-12 relative z-10">
        <?php if (!$canApply): ?>
            <!-- Block state — เฉพาะนักศึกษา / มีใบสมัครอยู่แล้ว -->
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-amber-100">
                <div class="text-center py-6">
                    <div class="w-20 h-20 mx-auto mb-4 rounded-full <?= $isStudent ? 'bg-amber-100' : 'bg-slate-100' ?> flex items-center justify-center">
                        <i class="fa-solid <?= $isStudent ? 'fa-circle-check text-amber-600' : 'fa-user-graduate text-slate-500' ?> text-4xl"></i>
                    </div>
                    <h2 class="text-lg font-black text-slate-900 mb-2">
                        <?= $isStudent ? 'มีใบสมัครอยู่แล้ว' : 'สมัครได้เฉพาะนักศึกษา' ?>
                    </h2>
                    <p class="text-[13px] font-bold text-slate-600 leading-relaxed mb-6"><?= vh($blockMessage) ?></p>
                    <a href="profile.php" class="inline-block px-6 py-3 rounded-2xl bg-amber-500 text-white font-black text-sm shadow-lg active:scale-95 transition-all">
                        <i class="fa-solid <?= $isStudent ? 'fa-id-card' : 'fa-arrow-left' ?> mr-2"></i>
                        <?= $isStudent ? 'ดูสถานะที่หน้า Profile' : 'กลับไปหน้า Profile' ?>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Application form -->
            <form id="applyForm" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= vh($csrfToken) ?>">

                <!-- Personal info card -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 space-y-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="h-5 w-1 rounded-full bg-amber-500"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700">ข้อมูลส่วนตัว</h3>
                    </div>

                    <div>
                        <label class="field-label">รหัสบัตรประชาชน <span class="text-rose-500">*</span></label>
                        <input type="text" name="citizen_id" id="citizen_id" class="form-input mt-2"
                            value="<?= vh($prefillCitizen) ?>" maxlength="13" pattern="[0-9]{13}"
                            placeholder="13 หลัก" required inputmode="numeric">
                        <p class="text-[11px] text-slate-400 mt-1.5 font-semibold" id="cid-hint">กรอกเฉพาะตัวเลข 13 หลัก</p>
                    </div>

                    <div>
                        <label class="field-label">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                        <input type="text" name="full_name" class="form-input mt-2"
                            value="<?= vh($prefillName) ?>" maxlength="200" required>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="field-label">วันเกิด <span class="text-rose-500">*</span></label>
                            <input type="date" name="date_of_birth" class="form-input mt-2"
                                value="<?= vh($prefillDob !== '0000-00-00' ? $prefillDob : '') ?>"
                                max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label class="field-label">เพศ <span class="text-rose-500">*</span></label>
                            <select name="gender" class="form-input mt-2" required>
                                <option value="">เลือก</option>
                                <option value="male" <?= $prefillGender === 'male' ? 'selected' : '' ?>>ชาย</option>
                                <option value="female" <?= $prefillGender === 'female' ? 'selected' : '' ?>>หญิง</option>
                                <option value="other" <?= $prefillGender === 'other' ? 'selected' : '' ?>>อื่นๆ</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="field-label">เบอร์โทรศัพท์</label>
                        <input type="tel" name="phone" class="form-input mt-2"
                            value="<?= vh($prefillPhone) ?>" maxlength="10" pattern="0[0-9]{9}"
                            placeholder="0X-XXXX-XXXX" inputmode="numeric">
                    </div>
                </div>

                <!-- Photo upload card -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="h-5 w-1 rounded-full bg-amber-500"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700">รูปถ่ายคู่บัตรประชาชน <span class="text-rose-500">*</span></h3>
                    </div>
                    <p class="text-[11px] font-bold text-slate-500 leading-relaxed mb-3">
                        ถ่ายรูปตัวเองคู่กับบัตรประชาชนให้เห็นใบหน้าและข้อมูลในบัตรชัดเจน
                    </p>
                    <ul class="text-[11px] font-semibold text-slate-600 space-y-1 mb-3 pl-4 list-disc">
                        <li>หน้าตรง ไม่ใส่หมวก/แว่นตาดำ/ที่คาดผม</li>
                        <li>แสงสว่างเพียงพอ ไม่เบลอ</li>
                        <li>เห็นข้อมูลในบัตรประชาชนชัดเจน</li>
                    </ul>
                    <div class="bg-blue-50 border border-blue-100 rounded-xl px-3 py-2 mb-4 flex items-start gap-2 text-[10px] font-bold text-blue-700 leading-relaxed">
                        <i class="fa-solid fa-shield-halved mt-0.5"></i>
                        <span>รูปจะถูกส่งให้ Google Gemini AI ตรวจสอบคุณภาพอัตโนมัติ (ใส่แว่น/แมส/หมวก) ก่อนส่งใบสมัคร — ข้อมูล meta เช่น GPS จะถูกลบก่อนส่งทุกครั้ง</span>
                    </div>

                    <!-- Hidden file inputs — used as fallback if getUserMedia isn't available -->
                    <input type="file" id="photo-input" name="photo" accept="image/*" capture="environment" class="hidden">
                    <input type="file" id="photo-gallery" accept="image/*" class="hidden">
                    <div id="photo-zone" class="upload-zone block" onclick="if(!compressedPhotoBlob) openCamera()">
                        <div id="photo-empty">
                            <i class="fa-solid fa-camera text-3xl text-slate-300 mb-3"></i>
                            <p class="text-[13px] font-black text-slate-700">แตะเพื่อเปิดกล้อง</p>
                            <p class="text-[10px] font-semibold text-slate-400 mt-1">JPG, PNG • สูงสุด 10MB</p>
                            <button type="button" onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('photo-gallery').click();"
                                class="mt-3 text-[11px] font-bold text-amber-600 underline decoration-dotted">หรือเลือกรูปจากเครื่อง</button>
                        </div>
                        <div id="photo-preview-wrap" class="hidden">
                            <img id="photo-preview" class="photo-preview" alt="Preview">
                            <button type="button" onclick="event.preventDefault(); event.stopPropagation(); clearPhoto()" class="mt-3 clear-btn">
                                <i class="fa-solid fa-rotate mr-1"></i> ถ่ายใหม่
                            </button>
                        </div>
                    </div>

                    <!-- AI vision check result card (hidden until photo is checked) -->
                    <div id="photo-check" class="hidden mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed">
                        <div id="photo-check-loading" class="hidden flex items-center gap-2 text-slate-600 font-bold">
                            <i class="fa-solid fa-spinner fa-spin text-amber-500"></i>
                            <span>กำลังตรวจสอบรูปด้วย AI…</span>
                        </div>
                        <div id="photo-check-result" class="hidden"></div>
                    </div>
                </div>

                <!-- Signature card -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="h-5 w-1 rounded-full bg-amber-500"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700">ลายมือชื่อ <span class="text-rose-500">*</span></h3>
                    </div>
                    <p class="text-[11px] font-bold text-slate-500 mb-3">เซ็นชื่อในกรอบสี่เหลี่ยมด้านล่าง</p>

                    <canvas id="signature-canvas" height="200"></canvas>

                    <div class="flex gap-2 mt-3">
                        <button type="button" onclick="clearSignature()" class="clear-btn flex-1">
                            <i class="fa-solid fa-eraser mr-1"></i> ล้างลายเซ็น
                        </button>
                        <button type="button" onclick="undoSignature()" class="clear-btn flex-1" style="background:#fef3c7; color:#b45309;">
                            <i class="fa-solid fa-rotate-left mr-1"></i> ย้อนกลับ 1 เส้น
                        </button>
                    </div>
                </div>

                <!-- Consent + Submit -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" id="consent" required class="mt-1 w-5 h-5 accent-amber-500">
                        <span class="text-[12px] font-bold text-slate-700 leading-relaxed">
                            ข้าพเจ้ายืนยันว่าข้อมูลและเอกสารทั้งหมดเป็นความจริง และยินยอมให้คลินิกใช้ข้อมูลเพื่อตรวจสอบและออกบัตรทอง
                        </span>
                    </label>

                    <button type="submit" id="submit-btn" class="submit-btn mt-5">
                        <i class="fa-solid fa-paper-plane mr-2"></i> ยืนยันส่งใบสมัคร
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/user_bottom_nav.php'; ?>

    <script>
    // Citizen ID Mod-11 validation
    function validateCitizenId(id) {
        if (!/^\d{13}$/.test(id)) return false;
        let sum = 0;
        for (let i = 0; i < 12; i++) sum += parseInt(id[i]) * (13 - i);
        return ((11 - (sum % 11)) % 10) === parseInt(id[12]);
    }

    const cidInput = document.getElementById('citizen_id');
    const cidHint = document.getElementById('cid-hint');
    if (cidInput) {
        cidInput.addEventListener('input', () => {
            cidInput.value = cidInput.value.replace(/\D/g, '').slice(0, 13);
            if (cidInput.value.length === 13) {
                if (validateCitizenId(cidInput.value)) {
                    cidHint.textContent = '✓ รหัสบัตรประชาชนถูกต้อง';
                    cidHint.className = 'text-[11px] text-emerald-600 mt-1.5 font-semibold';
                } else {
                    cidHint.textContent = '✗ รหัสบัตรประชาชนไม่ถูกต้อง';
                    cidHint.className = 'text-[11px] text-rose-500 mt-1.5 font-semibold';
                }
            } else {
                cidHint.textContent = `กรอก ${cidInput.value.length}/13 หลัก`;
                cidHint.className = 'text-[11px] text-slate-400 mt-1.5 font-semibold';
            }
        });
    }

    // Photo handling
    const photoInput = document.getElementById('photo-input');         // capture=environment fallback
    const photoGallery = document.getElementById('photo-gallery');     // pure gallery picker
    const photoZone = document.getElementById('photo-zone');
    const photoEmpty = document.getElementById('photo-empty');
    const photoPreviewWrap = document.getElementById('photo-preview-wrap');
    const photoPreview = document.getElementById('photo-preview');
    const photoCheckBox = document.getElementById('photo-check');
    const photoCheckLoading = document.getElementById('photo-check-loading');
    const photoCheckResult = document.getElementById('photo-check-result');
    window.compressedPhotoBlob = null;
    // Vision-check state: null = not yet checked, false = checking,
    // {passed, blockers, check} once finished, 'skipped' if AI not configured,
    // 'error' if check call failed (treat as advisory).
    let photoCheckState = null;

    // Compress + downscale anything that comes from a file picker; canvas
    // snapshots from the in-page camera already arrive sized correctly so
    // they go straight to setPhotoBlob().
    async function compressFile(file) {
        if (file.size > 10 * 1024 * 1024) {
            Swal.fire({icon:'error', title:'ไฟล์ใหญ่เกินไป', text:'กรุณาเลือกไฟล์ขนาดไม่เกิน 10MB'});
            return null;
        }
        return new Promise((resolve, reject) => {
            const img = new Image();
            const reader = new FileReader();
            reader.onload = (ev) => { img.src = ev.target.result; };
            reader.onerror = () => reject(new Error('อ่านไฟล์ไม่ได้'));
            img.onload = () => {
                const MAX_W = 1200;
                const scale = Math.min(1, MAX_W / img.width);
                const canvas = document.createElement('canvas');
                canvas.width = img.width * scale;
                canvas.height = img.height * scale;
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('compress fail')), 'image/jpeg', 0.85);
            };
            img.onerror = () => reject(new Error('โหลดรูปไม่ได้'));
            reader.readAsDataURL(file);
        });
    }

    function setPhotoBlob(blob) {
        window.compressedPhotoBlob = blob;
        photoPreview.src = URL.createObjectURL(blob);
        photoEmpty.classList.add('hidden');
        photoPreviewWrap.classList.remove('hidden');
        photoZone.classList.add('has-file');
        runPhotoCheck(blob);
    }

    async function handlePickedFile(file) {
        if (!file) return;
        try {
            const blob = await compressFile(file);
            if (blob) setPhotoBlob(blob);
        } catch (e) {
            Swal.fire({icon:'error', title:'เปิดรูปไม่ได้', text: e.message || 'กรุณาลองใหม่'});
        }
    }

    photoInput?.addEventListener('change',   (e) => handlePickedFile(e.target.files[0]));
    photoGallery?.addEventListener('change', (e) => handlePickedFile(e.target.files[0]));

    function clearPhoto() {
        window.compressedPhotoBlob = null;
        photoCheckState = null;
        if (photoInput) photoInput.value = '';
        if (photoGallery) photoGallery.value = '';
        photoEmpty.classList.remove('hidden');
        photoPreviewWrap.classList.add('hidden');
        photoZone.classList.remove('has-file');
        photoCheckBox.classList.add('hidden');
        photoCheckResult.classList.add('hidden');
        photoCheckLoading.classList.add('hidden');
    }

    // ─── In-page camera (getUserMedia) ─────────────────────────────────────────
    const camStage = document.getElementById('cam-stage');
    const camVideo = document.getElementById('cam-video');
    const camError = document.getElementById('cam-error');
    let cameraStream = null;
    let cameraFacing = 'user';   // selfie+ID by default — user can swap to back

    async function openCamera() {
        // Browsers without getUserMedia → fall back to the capture file input
        if (!navigator.mediaDevices?.getUserMedia) {
            photoInput.click();
            return;
        }
        camError.classList.add('hidden');
        camStage.classList.remove('hidden');
        camStage.classList.add('is-open');
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: cameraFacing, width: {ideal: 1280}, height: {ideal: 720} },
                audio: false,
            });
        } catch (err) {
            // Retry without facingMode constraint (laptops with single cam, etc.)
            if (err.name === 'OverconstrainedError' || err.name === 'NotFoundError') {
                try {
                    cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                } catch (e2) {
                    return cameraFailed(e2);
                }
            } else {
                return cameraFailed(err);
            }
        }
        camVideo.srcObject = cameraStream;
        updateMirror();
    }

    function cameraFailed(err) {
        closeCamera();
        const msg = err?.name === 'NotAllowedError'
            ? 'คุณยังไม่ได้อนุญาตให้ใช้กล้อง — กรุณาเลือกรูปจากเครื่องแทน'
            : 'เปิดกล้องไม่ได้ — กรุณาเลือกรูปจากเครื่องแทน';
        Swal.fire({icon:'warning', title:'เปิดกล้องไม่สำเร็จ', text: msg, confirmButtonColor:'#f59e0b'})
            .then(() => photoGallery.click());
    }

    function closeCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(t => t.stop());
            cameraStream = null;
        }
        camVideo.srcObject = null;
        camStage.classList.add('hidden');
        camStage.classList.remove('is-open');
    }

    async function swapCamera() {
        cameraFacing = cameraFacing === 'user' ? 'environment' : 'user';
        if (cameraStream) {
            cameraStream.getTracks().forEach(t => t.stop());
            cameraStream = null;
        }
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: cameraFacing, width: {ideal: 1280}, height: {ideal: 720} },
                audio: false,
            });
            camVideo.srcObject = cameraStream;
            updateMirror();
        } catch (err) {
            camError.textContent = 'สลับกล้องไม่สำเร็จ — อุปกรณ์อาจมีกล้องเดียว';
            camError.classList.remove('hidden');
            setTimeout(() => camError.classList.add('hidden'), 2500);
            // restore previous
            cameraFacing = cameraFacing === 'user' ? 'environment' : 'user';
            openCamera();
        }
    }

    function updateMirror() {
        // Mirror preview for front camera so the user sees themselves naturally;
        // the captured canvas is drawn from the raw (un-mirrored) video stream,
        // so the saved photo keeps the ID card text readable.
        if (cameraFacing === 'user') camVideo.classList.remove('no-mirror');
        else camVideo.classList.add('no-mirror');
    }

    function snapPhoto() {
        if (!cameraStream || !camVideo.videoWidth) return;
        const canvas = document.createElement('canvas');
        canvas.width  = camVideo.videoWidth;
        canvas.height = camVideo.videoHeight;
        canvas.getContext('2d').drawImage(camVideo, 0, 0);
        canvas.toBlob((blob) => {
            if (!blob) return;
            closeCamera();
            setPhotoBlob(blob);
        }, 'image/jpeg', 0.88);
    }

    function gotoGallery() {
        closeCamera();
        photoGallery.click();
    }
    // Expose camera fns globally so inline onclick handlers can reach them
    window.openCamera = openCamera;
    window.closeCamera = closeCamera;
    window.swapCamera = swapCamera;
    window.snapPhoto = snapPhoto;
    window.gotoGallery = gotoGallery;
    window.clearPhoto = clearPhoto;

    async function runPhotoCheck(blob) {
        photoCheckState = false; // checking
        photoCheckBox.classList.remove('hidden');
        photoCheckBox.className = 'mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed bg-slate-50 border-slate-200';
        photoCheckLoading.classList.remove('hidden');
        photoCheckLoading.classList.add('flex');
        photoCheckResult.classList.add('hidden');

        const fd = new FormData();
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        fd.append('photo', blob, 'check.jpg');

        try {
            const res = await fetch('ajax_check_photo.php', {
                method: 'POST', body: fd, credentials: 'same-origin',
            });
            const json = await res.json();
            photoCheckLoading.classList.add('hidden');
            photoCheckLoading.classList.remove('flex');
            photoCheckResult.classList.remove('hidden');

            if (json.skipped) {
                photoCheckState = 'skipped';
                photoCheckBox.className = 'mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed bg-slate-50 border-slate-200';
                photoCheckResult.innerHTML = `<div class="flex items-start gap-2 text-slate-600"><i class="fa-solid fa-circle-info mt-0.5"></i><div><b>ตรวจสอบด้วยตนเอง</b> — ระบบ AI ยังไม่พร้อม กรุณาตรวจรูปก่อนส่ง</div></div>`;
                return;
            }
            if (json.status === 'error' || json.ok === false) {
                photoCheckState = 'error';
                photoCheckBox.className = 'mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed bg-slate-50 border-slate-200';
                photoCheckResult.innerHTML = `<div class="flex items-start gap-2 text-slate-600"><i class="fa-solid fa-triangle-exclamation mt-0.5 text-amber-500"></i><div><b>ตรวจสอบไม่สำเร็จ</b> — ${escapeHtml(json.message || 'ลองอีกครั้งภายหลัง')} (สามารถส่งต่อไปได้)</div></div>`;
                return;
            }

            photoCheckState = json;
            if (json.passed) {
                photoCheckBox.className = 'mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed bg-emerald-50 border-emerald-200';
                photoCheckResult.innerHTML = `
                    <div class="flex items-start gap-2 text-emerald-800">
                        <i class="fa-solid fa-circle-check mt-0.5 text-emerald-600 text-base"></i>
                        <div>
                            <p class="font-black">รูปผ่านการตรวจสอบ</p>
                            <p class="text-emerald-700 mt-0.5">${escapeHtml(json.check?.summary || 'เห็นใบหน้าและบัตรประชาชนชัดเจน')}</p>
                            ${json.check?.wearing_glasses && !json.check?.dark_glasses
                                ? '<p class="text-[11px] text-slate-500 mt-1.5"><i class="fa-solid fa-glasses mr-1"></i> ตรวจพบว่าใส่แว่น (เลนส์ใส) — ส่งได้ปกติ</p>'
                                : ''}
                        </div>
                    </div>`;
            } else {
                photoCheckBox.className = 'mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed bg-rose-50 border-rose-200';
                const issuesHtml = (json.blockers || []).map(b =>
                    `<li class="flex items-start gap-1.5"><i class="fa-solid fa-circle-xmark text-rose-500 text-[10px] mt-1"></i><span>${escapeHtml(b)}</span></li>`
                ).join('');
                photoCheckResult.innerHTML = `
                    <div class="flex items-start gap-2 text-rose-800">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5 text-rose-600 text-base"></i>
                        <div class="flex-1">
                            <p class="font-black">พบปัญหาในรูป (${(json.blockers || []).length})</p>
                            <ul class="mt-2 space-y-1 text-rose-700">${issuesHtml}</ul>
                            <button type="button" onclick="clearPhoto(); openCamera();"
                                class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-rose-200 text-rose-700 font-black text-[11px] hover:bg-rose-100 transition">
                                <i class="fa-solid fa-camera-rotate"></i> ถ่ายใหม่
                            </button>
                        </div>
                    </div>`;
            }
        } catch (err) {
            photoCheckLoading.classList.add('hidden');
            photoCheckLoading.classList.remove('flex');
            photoCheckResult.classList.remove('hidden');
            photoCheckState = 'error';
            photoCheckBox.className = 'mt-4 rounded-2xl border p-4 text-[12px] leading-relaxed bg-slate-50 border-slate-200';
            photoCheckResult.innerHTML = `<div class="flex items-start gap-2 text-slate-600"><i class="fa-solid fa-wifi mt-0.5 text-amber-500"></i><div><b>ตรวจสอบไม่สำเร็จ</b> — เน็ตขัดข้อง สามารถส่งใบสมัครต่อไปได้</div></div>`;
        }
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // Signature canvas
    let signaturePad = null;
    const canvas = document.getElementById('signature-canvas');
    if (canvas) {
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            if (signaturePad) signaturePad.clear();
        }
        signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgb(255,255,255)',
            penColor: 'rgb(15, 23, 42)',
            minWidth: 1.2,
            maxWidth: 2.5,
        });
        signaturePad.addEventListener('endStroke', () => {
            canvas.classList.toggle('has-signature', !signaturePad.isEmpty());
        });
        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();
    }

    function clearSignature() {
        if (!signaturePad) return;
        signaturePad.clear();
        canvas.classList.remove('has-signature');
    }
    function undoSignature() {
        if (!signaturePad) return;
        const data = signaturePad.toData();
        if (data && data.length) {
            data.pop();
            signaturePad.fromData(data);
            canvas.classList.toggle('has-signature', !signaturePad.isEmpty());
        }
    }

    // Form submission
    const form = document.getElementById('applyForm');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Validate
            const cid = cidInput.value.trim();
            if (!validateCitizenId(cid)) {
                return Swal.fire({icon:'error', title:'รหัสบัตรประชาชนไม่ถูกต้อง', text:'กรุณาตรวจสอบให้ถูกต้อง 13 หลัก'});
            }
            if (!compressedPhotoBlob) {
                return Swal.fire({icon:'warning', title:'กรุณาแนบรูปถ่าย', text:'ถ่ายรูปคู่กับบัตรประชาชนเพื่อยืนยันตัวตน'});
            }
            if (photoCheckState === false) {
                return Swal.fire({icon:'info', title:'กำลังตรวจสอบรูป', text:'กรุณารอ AI ตรวจสอบรูปสักครู่ แล้วลองส่งอีกครั้ง'});
            }
            // If AI flagged issues, require explicit confirmation before submitting
            if (photoCheckState && typeof photoCheckState === 'object' && photoCheckState.passed === false) {
                const blockerList = (photoCheckState.blockers || []).map(b => `<li>• ${b}</li>`).join('');
                const { isConfirmed } = await Swal.fire({
                    icon: 'warning',
                    title: 'รูปยังไม่ผ่าน AI',
                    html: `<div class="text-left text-sm">
                        <p class="mb-2">ระบบตรวจพบ:</p>
                        <ul class="text-rose-600 font-bold space-y-1 mb-3">${blockerList}</ul>
                        <p class="text-xs text-slate-500">ถ้ายืนยันว่ารูปใช้ได้ ส่งต่อไปได้ — แต่เจ้าหน้าที่อาจขอให้ถ่ายใหม่</p>
                    </div>`,
                    showCancelButton: true,
                    confirmButtonText: 'ส่งต่อไป',
                    cancelButtonText: 'ถ่ายใหม่',
                    reverseButtons: true,
                    confirmButtonColor: '#f59e0b',
                });
                if (!isConfirmed) {
                    clearPhoto();
                    openCamera();
                    return;
                }
            }
            if (!signaturePad || signaturePad.isEmpty()) {
                return Swal.fire({icon:'warning', title:'กรุณาเซ็นชื่อ', text:'เซ็นลายมือชื่อในกรอบสี่เหลี่ยม'});
            }
            if (!document.getElementById('consent').checked) {
                return Swal.fire({icon:'warning', title:'กรุณายืนยัน', text:'โปรดติ๊กยืนยันว่าข้อมูลเป็นความจริง'});
            }

            const submitBtn = document.getElementById('submit-btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> กำลังส่ง...';

            // Build FormData
            const fd = new FormData();
            fd.append('csrf_token', form.csrf_token.value);
            fd.append('citizen_id', cid);
            fd.append('full_name', form.full_name.value.trim());
            fd.append('date_of_birth', form.date_of_birth.value);
            fd.append('gender', form.gender.value);
            fd.append('phone', form.phone.value.trim());
            fd.append('photo', compressedPhotoBlob, `selfie_${cid}.jpg`);
            fd.append('signature_base64', signaturePad.toDataURL('image/png'));

            try {
                const res = await fetch('ajax_gold_card_apply.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                });
                const json = await res.json();
                if (json.status === 'ok') {
                    await Swal.fire({
                        icon: 'success',
                        title: 'ส่งใบสมัครเรียบร้อย!',
                        html: 'เจ้าหน้าที่จะตรวจสอบและแจ้งผลภายใน <b>3-5 วันทำการ</b><br>คุณสามารถเช็คสถานะได้ที่หน้า Profile',
                        confirmButtonText: 'กลับหน้าหลัก',
                        confirmButtonColor: '#f59e0b',
                    });
                    window.location.href = 'profile.php';
                } else {
                    throw new Error(json.message || 'ส่งใบสมัครไม่สำเร็จ');
                }
            } catch (err) {
                Swal.fire({icon:'error', title:'เกิดข้อผิดพลาด', text: err.message || 'กรุณาลองใหม่อีกครั้ง'});
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane mr-2"></i> ยืนยันส่งใบสมัคร';
            }
        });
    }
    </script>

    <!-- ════════════ IN-PAGE CAMERA STAGE (getUserMedia) ════════════ -->
    <div id="cam-stage" class="fixed inset-0 hidden bg-black flex-col">
        <div class="flex-1 relative overflow-hidden">
            <video id="cam-video" autoplay playsinline muted class="w-full h-full object-cover"></video>
            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div class="cam-frame"></div>
            </div>
            <div class="absolute top-5 left-5 px-3 py-1.5 rounded-full bg-black/60 text-white text-[11px] font-black tracking-wide">
                <i class="fa-solid fa-id-card mr-1"></i> ถ่ายให้เห็นหน้า + บัตรประชาชน
            </div>
            <button type="button" onclick="closeCamera()" class="absolute top-5 right-5 w-11 h-11 rounded-full bg-black/60 text-white flex items-center justify-center text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div id="cam-error" class="hidden absolute inset-x-5 bottom-5 bg-rose-500/95 text-white text-[12px] font-bold rounded-2xl px-4 py-3"></div>
        </div>
        <div class="px-6 py-5 bg-black flex items-center justify-around">
            <button type="button" onclick="gotoGallery()" class="w-12 h-12 rounded-full bg-white/10 text-white flex items-center justify-center" title="เลือกจากเครื่องแทน">
                <i class="fa-solid fa-images"></i>
            </button>
            <button type="button" id="cam-snap-btn" onclick="snapPhoto()" class="relative w-20 h-20 rounded-full bg-white shadow-xl border-4 border-white/40"></button>
            <button type="button" onclick="swapCamera()" class="w-12 h-12 rounded-full bg-white/10 text-white flex items-center justify-center" title="สลับกล้องหน้า/หลัง">
                <i class="fa-solid fa-camera-rotate"></i>
            </button>
        </div>
    </div>
</body>
</html>
