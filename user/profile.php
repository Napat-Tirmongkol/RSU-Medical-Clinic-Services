<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();

// 1. ตรวจสอบว่ามี Line ID ใน Session หรือไม่ (ถ้าไม่มีให้กลับไปหน้าแรกเพื่อ Login)
$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
  header('Location: index.php', true, 303);
  exit;
}

// 2. ดึงข้อมูลเดิมจากฐานข้อมูล (ถ้ามี) มาแสดงในฟอร์ม
$userData = [
  'prefix'     => '',
  'first_name' => '',
  'last_name'  => '',
  'full_name'  => '',
  'id_number'  => '',
  'citizen_id' => '',
  'phone'      => '',
  'status'     => '',
  'email'      => '',
  'gender'     => '',
];

try {
  $pdo = db();
  // migration อัตโนมัติ
  try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS prefix     VARCHAR(20)  NOT NULL DEFAULT ''"); } catch (PDOException) {}
  try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS gender     VARCHAR(20)  NOT NULL DEFAULT ''"); } catch (PDOException) {}
  try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NOT NULL DEFAULT ''"); } catch (PDOException) {}
  try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100) NOT NULL DEFAULT ''"); } catch (PDOException) {}

  try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS department VARCHAR(150) NOT NULL DEFAULT ''"); } catch (PDOException) {}
  $stmt = $pdo->prepare("SELECT prefix, first_name, last_name, full_name, student_personnel_id, citizen_id, phone_number, status, email, gender, department FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
  $stmt->execute([':line_id' => $lineUserId]);
  $user = $stmt->fetch();

  if ($user) {
    $userData['prefix']     = $user['prefix']               ?? '';
    $userData['first_name'] = $user['first_name']           ?? '';
    $userData['last_name']  = $user['last_name']            ?? '';
    $userData['full_name']  = $user['full_name']            ?? '';
    $userData['id_number']  = $user['student_personnel_id'] ?? '';
    $userData['citizen_id'] = $user['citizen_id']           ?? '';
    $userData['phone']      = $user['phone_number']         ?? '';
    $userData['status']     = $user['status']               ?? '';
    $userData['email']      = $user['email']                ?? '';
    $userData['gender']     = $user['gender']               ?? '';
    $userData['department'] = $user['department']           ?? '';
  }
} catch (PDOException $e) {
  // กรณี Error ให้ปล่อยผ่านไปกรอกใหม่
}

// ── Auto-split full_name → first_name / last_name สำหรับ user เดิม ─────────
$_nameNeedsReview = false;
if ($userData['full_name'] !== '' && $userData['first_name'] === '' && $userData['last_name'] === '') {
    $parts = explode(' ', trim($userData['full_name']), 2);
    $userData['first_name'] = $parts[0] ?? '';
    $userData['last_name']  = $parts[1] ?? '';
    $_nameNeedsReview = true; // แสดง banner ให้ user ตรวจสอบ
}

// ตรวจสอบว่าเป็นการแก้ไขหรือลงทะเบียนใหม่
$isEditing = !empty($userData['full_name']);

// ── Profile completeness (แสดงเฉพาะตอนแก้ไข) ────────────────────────────────
$completenessItems   = [];
$completenessPercent = 0;
if ($isEditing) {
    $completenessItems = [
        ['label' => 'คำนำหน้า',    'done' => !empty($userData['prefix'])],
        ['label' => 'ชื่อ',        'done' => !empty($userData['first_name'])],
        ['label' => 'นามสกุล',     'done' => !empty($userData['last_name'])],
        ['label' => 'เบอร์โทรศัพท์','done' => !empty($userData['phone'])],
        ['label' => 'เพศ',         'done' => !empty($userData['gender'])],
        ['label' => 'เลขประจำตัว', 'done' => !empty($userData['citizen_id'])],
    ];
    if ($userData['status'] !== 'other' && $userData['status'] !== '') {
        $completenessItems[] = ['label' => 'รหัสนักศึกษา/บุคลากร', 'done' => !empty($userData['id_number'])];
    }
    $completenessTotal   = count($completenessItems);
    $completenessDone    = count(array_filter(array_column($completenessItems, 'done')));
    $completenessPercent = $completenessTotal > 0 ? (int)round($completenessDone / $completenessTotal * 100) : 0;
}

// ตรวจสอบว่า citizen_id เป็น passport หรือเลขบัตร (passport = มีตัวอักษรหรือความยาว > 13)
$citizenIdValue = $userData['citizen_id'];
$isPassport = ($citizenIdValue !== '' && (!ctype_digit($citizenIdValue) || strlen($citizenIdValue) > 13));

// รับ redirect_back เพื่อรู้ว่ามาจากหน้าไหน
$redirectBack = $_GET['redirect_back'] ?? '';

$error_param = $_GET['error'] ?? '';
render_header('ข้อมูลส่วนตัว');
?>

<div class="p-5 pb-28 flex flex-col min-h-screen animate-in fade-in slide-in-from-right-4 duration-500">

  <?php if ($isEditing && $_nameNeedsReview): ?>
  <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800 font-prompt flex items-start gap-3">
    <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0 text-amber-500"></i>
    <span>ระบบได้แยกชื่อและนามสกุลจากข้อมูลเดิมให้อัตโนมัติ กรุณาตรวจสอบและบันทึกใหม่อีกครั้ง</span>
  </div>
  <?php endif; ?>

  <?php if ($error_param !== ''): ?>
  <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700 font-prompt flex items-start gap-3">
    <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
    <span>
      <?php if ($error_param === 'no_prefix'): ?>
        กรุณาเลือกคำนำหน้าชื่อ
      <?php elseif ($error_param === 'no_status'): ?>
        กรุณาเลือกประเภทผู้ใช้งาน (นักศึกษา / บุคลากร / บุคคลทั่วไป)
      <?php elseif ($error_param === 'no_gender'): ?>
        กรุณาเลือกเพศ
      <?php elseif ($error_param === 'empty_student'): ?>
        กรุณากรอกรหัสนักศึกษา / บุคลากร
      <?php else: ?>
        กรุณากรอกข้อมูลให้ครบถ้วนทุกช่อง
      <?php endif; ?>
    </span>
  </div>
  <?php endif; ?>

  <form id="profileForm" class="flex-1 flex flex-col" method="post" action="save_profile.php">
    <?php csrf_field(); ?>
    <input type="hidden" name="redirect_back" value="<?= htmlspecialchars($redirectBack) ?>">

    <div class="flex-1 space-y-6">

      <!-- Header -->
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold text-gray-900 font-prompt">
            <?= $isEditing ? 'แก้ไขข้อมูลส่วนตัว' : 'ข้อมูลส่วนตัว' ?>
          </h2>
          <p class="text-sm text-gray-500 mt-1 font-prompt">
            <?= $isEditing ? 'แก้ไขข้อมูลของคุณได้ตามต้องการ' : 'กรุณากรอกข้อมูลของคุณเพื่อใช้ในการจองคิว' ?>
          </p>
        </div>
        <?php if ($isEditing): ?>
        <a href="<?= $redirectBack !== '' ? htmlspecialchars($redirectBack) : 'my_bookings.php' ?>"
           class="shrink-0 flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 font-prompt font-semibold border border-gray-200 rounded-xl px-3 py-2 transition-all hover:bg-gray-50">
          <i class="fa-solid fa-arrow-left text-xs"></i> กลับ
        </a>
        <?php endif; ?>
      </div>

      <?php if ($isEditing): ?>
      <!-- Profile Completeness Badge -->
      <div class="bg-white border <?= $completenessPercent === 100 ? 'border-green-100' : 'border-blue-100' ?> rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between mb-2.5">
          <div class="flex items-center gap-2">
            <i class="fa-solid fa-chart-simple text-sm <?= $completenessPercent === 100 ? 'text-green-500' : 'text-[#0052CC]' ?>"></i>
            <span class="text-sm font-bold text-gray-800 font-prompt">ความครบถ้วนของโปรไฟล์</span>
          </div>
          <span class="text-sm font-black font-prompt <?= $completenessPercent === 100 ? 'text-green-600' : 'text-[#0052CC]' ?>"><?= $completenessPercent ?>%</span>
        </div>
        <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden mb-3">
          <div class="h-full rounded-full transition-all duration-700 <?= $completenessPercent === 100 ? 'bg-green-500' : 'bg-[#0052CC]' ?>"
               style="width:<?= $completenessPercent ?>%"></div>
        </div>
        <div class="grid grid-cols-2 gap-x-3 gap-y-1.5">
          <?php foreach ($completenessItems as $item): ?>
          <div class="flex items-center gap-1.5 text-xs font-prompt <?= $item['done'] ? 'text-gray-700' : 'text-gray-400' ?>">
            <i class="fa-solid <?= $item['done'] ? 'fa-circle-check text-green-500' : 'fa-circle-xmark text-red-400' ?> text-[11px] flex-shrink-0"></i>
            <?= htmlspecialchars($item['label']) ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="space-y-5">

        <?php
        // ตรวจว่า prefix ที่เก็บไว้เป็น custom หรือ standard
        $_stdPrefixes = ['นาย','นาง','นางสาว','นพ.','พญ.','ทพ.','ทญ.','ภก.','ภญ.','พย.','ดร.','อ.','ผศ.','รศ.','ศ.'];
        $_isCustomPrefix = ($userData['prefix'] !== '' && !in_array($userData['prefix'], $_stdPrefixes, true));
        $_selectVal = $_isCustomPrefix ? 'other' : $userData['prefix'];
        $_customVal = $_isCustomPrefix ? $userData['prefix'] : '';
        ?>

        <!-- คำนำหน้าชื่อ -->
        <div class="space-y-1.5">
          <label for="name_title" class="text-sm font-semibold text-gray-700 font-prompt">คำนำหน้าชื่อ <span class="text-red-500">*</span></label>
          <select name="name_title" id="name_title" onchange="toggleCustomTitle()" required
            class="w-full px-4 py-3.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all text-sm bg-white font-prompt">
            <option value="" disabled <?= $_selectVal === '' ? 'selected' : '' ?>>-- กรุณาเลือก --</option>
            <option value="นาย"    <?= $_selectVal === 'นาย'    ? 'selected' : '' ?>>นาย</option>
            <option value="นาง"    <?= $_selectVal === 'นาง'    ? 'selected' : '' ?>>นาง</option>
            <option value="นางสาว" <?= $_selectVal === 'นางสาว' ? 'selected' : '' ?>>นางสาว</option>
            <optgroup label="บุคลากรทางการแพทย์">
              <option value="นพ." <?= $_selectVal === 'นพ.' ? 'selected' : '' ?>>นพ.</option>
              <option value="พญ." <?= $_selectVal === 'พญ.' ? 'selected' : '' ?>>พญ.</option>
              <option value="ทพ." <?= $_selectVal === 'ทพ.' ? 'selected' : '' ?>>ทพ.</option>
              <option value="ทญ." <?= $_selectVal === 'ทญ.' ? 'selected' : '' ?>>ทญ.</option>
              <option value="ภก." <?= $_selectVal === 'ภก.' ? 'selected' : '' ?>>ภก.</option>
              <option value="ภญ." <?= $_selectVal === 'ภญ.' ? 'selected' : '' ?>>ภญ.</option>
              <option value="พย." <?= $_selectVal === 'พย.' ? 'selected' : '' ?>>พย.</option>
            </optgroup>
            <optgroup label="สายวิชาการ">
              <option value="ดร."  <?= $_selectVal === 'ดร.'  ? 'selected' : '' ?>>ดร.</option>
              <option value="อ."   <?= $_selectVal === 'อ.'   ? 'selected' : '' ?>>อ.</option>
              <option value="ผศ."  <?= $_selectVal === 'ผศ.'  ? 'selected' : '' ?>>ผศ.</option>
              <option value="รศ."  <?= $_selectVal === 'รศ.'  ? 'selected' : '' ?>>รศ.</option>
              <option value="ศ."   <?= $_selectVal === 'ศ.'   ? 'selected' : '' ?>>ศ.</option>
            </optgroup>
            <option value="other" <?= $_selectVal === 'other' ? 'selected' : '' ?>>อื่นๆ (โปรดระบุ)...</option>
          </select>
          <div id="custom_title_container" class="<?= $_isCustomPrefix ? '' : 'hidden' ?>">
            <input type="text" id="custom_title" name="custom_title"
              value="<?= htmlspecialchars($_customVal) ?>"
              placeholder="พิมพ์คำนำหน้าชื่อของคุณ..."
              <?= $_isCustomPrefix ? 'required' : '' ?>
              class="w-full px-4 py-3.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all text-sm bg-gray-50 font-prompt mt-2" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-semibold text-gray-700 font-prompt" for="first_name">ชื่อ <span class="text-red-500">*</span></label>
            <input id="first_name" name="first_name" type="text" required
              value="<?= htmlspecialchars($userData['first_name']) ?>" placeholder="เช่น สมชาย"
              class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-semibold text-gray-700 font-prompt" for="last_name">นามสกุล <span class="text-red-500">*</span></label>
            <input id="last_name" name="last_name" type="text" required
              value="<?= htmlspecialchars($userData['last_name']) ?>" placeholder="เช่น ใจดี"
              class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
          </div>
        </div>

        <div class="space-y-2">
          <label class="text-sm font-semibold text-gray-700 font-prompt">ประเภทผู้ใช้งาน <span
              class="text-red-500">*</span></label>
          <div class="grid grid-cols-3 gap-2">
            <label class="cursor-pointer">
              <input type="radio" name="status" value="student" required class="peer hidden"
                <?= $userData['status'] === 'student' ? 'checked' : '' ?>>
              <div
                class="py-3 px-1 text-center border border-gray-200 rounded-xl peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC] font-prompt text-[11px] font-bold transition-all h-full flex items-center justify-center">
                นักศึกษา</div>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="status" value="staff" required class="peer hidden"
                <?= $userData['status'] === 'staff' ? 'checked' : '' ?>>
              <div
                class="py-3 px-1 text-center border border-gray-200 rounded-xl peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC] font-prompt text-[11px] font-bold transition-all h-full flex items-center justify-center">
                บุคลากร/อาจารย์</div>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="status" value="other" required class="peer hidden"
                <?= $userData['status'] === 'other' ? 'checked' : '' ?>>
              <div
                class="py-3 px-1 text-center border border-gray-200 rounded-xl peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC] font-prompt text-[11px] font-bold transition-all h-full flex items-center justify-center">
                บุคคลทั่วไป</div>
            </label>
          </div>
        </div>

        <!-- เพศ -->
        <div class="space-y-2">
          <label class="text-sm font-semibold text-gray-700 font-prompt">เพศ <span class="text-red-500">*</span></label>
          <div class="grid grid-cols-3 gap-2">
            <label class="cursor-pointer">
              <input type="radio" name="gender" value="male" required class="peer hidden"
                <?= $userData['gender'] === 'male' ? 'checked' : '' ?>>
              <div class="py-3 px-1 text-center border border-gray-200 rounded-xl peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC] font-prompt text-[11px] font-bold transition-all h-full flex items-center justify-center gap-1.5">
                <i class="fa-solid fa-mars text-xs"></i> ชาย
              </div>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="gender" value="female" required class="peer hidden"
                <?= $userData['gender'] === 'female' ? 'checked' : '' ?>>
              <div class="py-3 px-1 text-center border border-gray-200 rounded-xl peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC] font-prompt text-[11px] font-bold transition-all h-full flex items-center justify-center gap-1.5">
                <i class="fa-solid fa-venus text-xs"></i> หญิง
              </div>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="gender" value="other" required class="peer hidden"
                <?= $userData['gender'] === 'other' ? 'checked' : '' ?>>
              <div class="py-3 px-1 text-center border border-gray-200 rounded-xl peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC] font-prompt text-[11px] font-bold transition-all h-full flex items-center justify-center">
                ไม่ระบุ
              </div>
            </label>
          </div>
        </div>

        <!-- เลขประจำตัว: บัตรประชาชน / Passport -->
        <div class="space-y-2">
          <label class="text-sm font-semibold text-gray-700 font-prompt">เลขประจำตัว <span class="text-red-500">*</span></label>

          <!-- ปุ่มเลือกประเภท -->
          <div class="flex gap-2 mb-2">
            <label class="flex-1 cursor-pointer">
              <input type="radio" name="id_type" value="citizen" class="peer hidden"
                <?= !$isPassport ? 'checked' : '' ?>>
              <div class="flex items-center justify-center gap-1.5 py-2 px-3 border border-gray-200 rounded-xl
                          peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC]
                          font-prompt text-[11px] font-bold transition-all text-gray-500">
                <i class="fa-solid fa-id-card text-xs"></i>
                บัตรประชาชน (TH)
              </div>
            </label>
            <label class="flex-1 cursor-pointer">
              <input type="radio" name="id_type" value="passport" class="peer hidden"
                <?= $isPassport ? 'checked' : '' ?>>
              <div class="flex items-center justify-center gap-1.5 py-2 px-3 border border-gray-200 rounded-xl
                          peer-checked:bg-[#E6F0FF] peer-checked:border-[#0052CC] peer-checked:text-[#0052CC]
                          font-prompt text-[11px] font-bold transition-all text-gray-500">
                <i class="fa-solid fa-passport text-xs"></i>
                Passport
              </div>
            </label>
          </div>

          <input id="citizen_id" name="citizen_id" type="text" required
            value="<?= htmlspecialchars($citizenIdValue) ?>"
            placeholder="<?= $isPassport ? 'เช่น A1234567 (Passport No.)' : 'กรอกเลขบัตรประชาชน 13 หลัก' ?>"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
          <p id="citizen_id_hint" class="text-xs text-gray-400 font-prompt">
            <?= $isPassport ? 'กรอกเลขหนังสือเดินทาง (ตัวอักษรและตัวเลข)' : 'กรอกเลขบัตรประชาชน 13 หลัก ไม่มีขีด' ?>
          </p>
        </div>

        <div class="space-y-1.5" id="student_id_container">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="id_number">รหัสนักศึกษา / บุคลากร <span
              class="text-red-500">*</span></label>
          <input id="id_number" name="id_number" type="text" maxlength="7"
            value="<?= htmlspecialchars($userData['id_number']) ?>" placeholder="กรอกรหัสตัวเลข 7 หลัก"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="department">
            คณะ / หน่วยงาน
            <span class="text-gray-400 font-normal text-xs ml-1">(ไม่บังคับ)</span>
          </label>
          <input id="department" name="department" type="text"
            value="<?= htmlspecialchars($userData['department']) ?>"
            placeholder="เช่น วิทยาลัยแพทยศาสตร์"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="email">
            อีเมล
            <span class="text-gray-400 font-normal text-xs ml-1">(ไม่บังคับ)</span>
          </label>
          <input id="email" name="email" type="email" value="<?= htmlspecialchars($userData['email']) ?>"
            placeholder="example@email.com"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
          <p class="text-xs text-amber-600 flex items-start gap-1.5 mt-1 font-prompt">
            <i class="fa-solid fa-triangle-exclamation shrink-0 mt-0.5"></i>
            หากไม่กรอกอีเมล คุณจะไม่ได้รับการแจ้งเตือนยืนยันการจองและผลอนุมัติ
          </p>
        </div>
        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="phone_number">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label>
          <input id="phone_number" name="phone_number" type="tel" required
            value="<?= htmlspecialchars($userData['phone']) ?>" placeholder="08X-XXX-XXXX"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
        </div>
      </div>

      <!-- PDPA Section -->
      <div class="space-y-4 pt-4 border-t border-gray-100">
        <div class="flex items-center gap-2 text-[#0052CC] mb-2 text-sm font-bold">
          <i class="fa-solid fa-shield-halved"></i>
          <span>ข้อตกลงและเงื่อนไข (PDPA)</span>
        </div>
        <div
          class="bg-gray-50 border border-gray-100 p-4 rounded-xl text-[12px] text-gray-500 font-prompt leading-relaxed h-44 overflow-y-auto custom-scrollbar">
          <p class="font-bold text-gray-800 mb-2">ยินดีต้อนรับเข้าสู่ระบบ E-Campaign (RSU Medical Clinic Services)</p>
          <p class="mb-2">มหาวิทยาลัยรังสิต ขอขอบพระคุณในความไว้วางใจใช้บริการ ข้อมูลส่วนบุคคลที่ท่านกรอก (ชื่อ-นามสกุล,
            เลขบัตรประชาชน, รหัสนักศึกษา/บุคลากร, และเบอร์โทรศัพท์) รวมถึง LINE User ID
            จะถูกประมวลผลภายใต้เงื่อนไขดังนี้:</p>
          <ul class="list-disc pl-4 space-y-1 mb-2">
            <li><strong>เพื่อการยืนยันตัวตน:</strong> ตรวจสอบสิทธิ์ในการรับบริการตามเงื่อนไขของแต่ละโครงการ</li>
            <li><strong>เพื่อการบริหารจัดการ:</strong> จัดลำดับคิวและอำนวยความสะดวกในวันนัดหมาย</li>
            <li><strong>เพื่อการแจ้งเตือน:</strong> ส่งข้อความยืนยันการจองและแจ้งเตือนผ่าน LINE Notify/Message</li>
            <li><strong>เพื่อความปลอดภัย:</strong> ปฏิบัติตามมาตรฐานการระบุตัวตนในระบบบริการสุขภาพ</li>
          </ul>
          <p>เราขอรับรองว่าข้อมูลของท่านจะถูกเก็บเป็นความลับสูงสุดตามมาตรฐาน PDPA
            และจะไม่ถูกนำไปเผยแพร่หรือขายข้อมูลให้แก่บุคคลภายนอกโดยไม่ได้รับอนุญาต</p>
        </div>

        <label
          class="flex items-center gap-4 p-4 bg-white rounded-2xl border border-gray-100 shadow-sm cursor-pointer hover:bg-gray-50 transition-all active:scale-[0.98] select-none">
          <input type="checkbox" id="pdpa_agreed" name="agreed" value="1"
            <?= $isEditing ? 'checked' : 'required' ?>
            class="shrink-0 w-6 h-6 rounded-lg border-gray-300 text-[#0052CC] focus:ring-[#0052CC] transition-all" />
          <span class="text-xs text-gray-600 font-bold leading-tight font-prompt">ฉันได้อ่าน
            และยอมรับข้อตกลงนโยบายความเป็นส่วนตัว</span>
        </label>
      </div>
    </div>

    <div
      class="fixed bottom-0 left-0 right-0 max-w-md mx-auto p-4 bg-white border-t border-gray-100 z-20 shadow-[0_-4px_20px_-10px_rgba(0,0,0,0.1)]">
      <?php if ($isEditing): ?>
      <div class="flex gap-3">
        <a href="<?= $redirectBack !== '' ? htmlspecialchars($redirectBack) : 'my_bookings.php' ?>"
           class="flex-none flex items-center justify-center px-5 py-4 border border-gray-200 rounded-xl text-gray-600 font-bold font-prompt transition-all hover:bg-gray-50 active:scale-[0.98]">
          ยกเลิก
        </a>
        <button type="submit"
          class="flex-1 bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-all shadow-sm active:scale-[0.98] font-prompt">
          บันทึกการเปลี่ยนแปลง
        </button>
      </div>
      <?php else: ?>
      <button type="submit"
        class="w-full bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-all shadow-sm active:scale-[0.98] font-prompt">
        บันทึกและดำเนินการต่อ
      </button>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
  function toggleCustomTitle() {
    const sel = document.getElementById('name_title');
    const container = document.getElementById('custom_title_container');
    const input = document.getElementById('custom_title');
    if (sel.value === 'other') {
      container.classList.remove('hidden');
      input.setAttribute('required', 'required');
      input.focus();
    } else {
      container.classList.add('hidden');
      input.removeAttribute('required');
      input.value = '';
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const statusInputs = document.querySelectorAll('input[name="status"]');
    const studentIdBtn = document.getElementById('student_id_container');
    const studentIdInput = document.getElementById('id_number');
    const statusSection = document.querySelector('.grid.grid-cols-3');

    // ── ID type toggle (citizen / passport) ──────────────────────
    const idTypeInputs = document.querySelectorAll('input[name="id_type"]');
    const citizenIdInput = document.getElementById('citizen_id');
    const citizenIdHint = document.getElementById('citizen_id_hint');

    function applyIdType(type) {
      if (type === 'passport') {
        citizenIdInput.removeAttribute('pattern');
        citizenIdInput.removeAttribute('maxlength');
        citizenIdInput.setAttribute('placeholder', 'เช่น A1234567 (Passport No.)');
        citizenIdInput.setAttribute('autocomplete', 'off');
        citizenIdHint.textContent = 'กรอกเลขหนังสือเดินทาง (ตัวอักษรและตัวเลข)';
      } else {
        citizenIdInput.setAttribute('pattern', '\\d{13}');
        citizenIdInput.setAttribute('maxlength', '13');
        citizenIdInput.setAttribute('placeholder', 'กรอกเลขบัตรประชาชน 13 หลัก');
        citizenIdHint.textContent = 'กรอกเลขบัตรประชาชน 13 หลัก ไม่มีขีด';
      }
    }

    idTypeInputs.forEach(function(input) {
      input.addEventListener('change', function() { applyIdType(this.value); });
    });

    // ตั้งค่าเริ่มต้นตามที่ PHP ส่งมา
    const initialType = document.querySelector('input[name="id_type"]:checked');
    if (initialType) applyIdType(initialType.value);

    // ── Status toggle (แสดง/ซ่อน รหัสนักศึกษา) ──────────────────
    function toggleFields() {
      const rad = document.querySelector('input[name="status"]:checked');
      const selectedStatus = rad ? rad.value : '';
      if (selectedStatus === 'other') {
        if (studentIdBtn) studentIdBtn.classList.add('hidden');
        if (studentIdInput) studentIdInput.removeAttribute('required');
      } else {
        if (studentIdBtn) studentIdBtn.classList.remove('hidden');
        if (studentIdInput) studentIdInput.setAttribute('required', 'required');
      }
      if (statusSection) statusSection.classList.remove('ring-2', 'ring-red-400', 'rounded-xl');
    }

    statusInputs.forEach(input => {
      input.addEventListener('change', toggleFields);
    });
    toggleFields();

    // Form submit validation
    document.getElementById('profileForm').addEventListener('submit', function (e) {
      const selected = document.querySelector('input[name="status"]:checked');
      if (!selected) {
        e.preventDefault();
        if (statusSection) {
          statusSection.classList.add('ring-2', 'ring-red-400', 'rounded-xl');
          statusSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        let errEl = document.getElementById('status-error');
        if (!errEl) {
          errEl = document.createElement('p');
          errEl.id = 'status-error';
          errEl.className = 'text-xs text-red-500 mt-1 font-prompt';
          errEl.textContent = 'กรุณาเลือกประเภทผู้ใช้งาน';
          if (statusSection) statusSection.insertAdjacentElement('afterend', errEl);
        }
      }
    });
  });
</script>

<?php render_footer(); ?>
