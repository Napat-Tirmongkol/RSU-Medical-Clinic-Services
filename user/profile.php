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
  'full_name' => '',
  'id_number' => '',
  'citizen_id' => '',
  'phone' => '',
  'status' => '',
  'email' => ''
];

try {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT full_name, student_personnel_id, citizen_id, phone_number, status, email FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
  $stmt->execute([':line_id' => $lineUserId]);
  $user = $stmt->fetch();

  if ($user) {
    $userData['full_name'] = $user['full_name'] ?? '';
    $userData['id_number'] = $user['student_personnel_id'] ?? '';
    $userData['citizen_id'] = $user['citizen_id'] ?? '';
    $userData['phone'] = $user['phone_number'] ?? '';
    $userData['status'] = $user['status'] ?? '';
    $userData['email'] = $user['email'] ?? '';
  }
} catch (PDOException $e) {
  // กรณี Error ให้ปล่อยผ่านไปกรอกใหม่
}

$error_param = $_GET['error'] ?? '';
render_header('ข้อมูลส่วนตัว');
?>

<div class="p-5 pb-28 flex flex-col min-h-screen animate-in fade-in slide-in-from-right-4 duration-500">

  <?php if ($error_param !== ''): ?>
  <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700 font-prompt flex items-start gap-3">
    <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
    <span>
      <?php if ($error_param === 'no_status'): ?>
        กรุณาเลือกประเภทผู้ใช้งาน (นักศึกษา / บุคลากร / บุคคลทั่วไป)
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
    <div class="flex-1 space-y-6">
      <div>
        <h2 class="text-2xl font-bold text-gray-900 font-prompt">ข้อมูลส่วนตัว</h2>
        <p class="text-sm text-gray-500 mt-1 font-prompt">กรุณากรอกข้อมูลของคุณเพื่อใช้ในการจองคิววัคซีน</p>
      </div>

      <div class="space-y-5">
        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="full_name">ชื่อ-นามสกุล</label>
          <input id="full_name" name="full_name" type="text" required
            value="<?= htmlspecialchars($userData['full_name']) ?>" placeholder="เช่น นายสมชาย ใจดี"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
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

        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="citizen_id">เลขบัตรประชาชน <span
              class="text-red-500">*</span></label>
          <input id="citizen_id" name="citizen_id" type="text" required maxlength="13" pattern="\d{13}"
            value="<?= htmlspecialchars($userData['citizen_id']) ?>" placeholder="กรอกเลขบัตรประชาชน 13 หลัก"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
        </div>

        <div class="space-y-1.5" id="student_id_container">
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="id_number">รหัสนักศึกษา / บุคลากร <span
              class="text-red-500">*</span></label>
          <input id="id_number" name="id_number" type="text" maxlength="7"
            value="<?= htmlspecialchars($userData['id_number']) ?>" placeholder="กรอกรหัสตัวเลข 7 หลัก"
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
          <label class="text-sm font-semibold text-gray-700 font-prompt" for="phone_number">เบอร์โทรศัพท์</label>
          <input id="phone_number" name="phone_number" type="tel" required
            value="<?= htmlspecialchars($userData['phone']) ?>" placeholder="08X-XXX-XXXX"
            class="w-full p-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#0052CC] focus:border-transparent outline-none transition-all placeholder:text-gray-400 font-prompt" />
        </div>
      </div>

      <!-- 🛡️ PDPA Section -->
      <div class="space-y-4 pt-4 border-t border-gray-100">
        <div class="flex items-center gap-2 text-[#0052CC] mb-2 text-sm font-bold">
          <i class="fa-solid fa-shield-halved"></i>
          <span>ข้อตกลงและเงื่อนไข (PDPA)</span>
        </div>
        <div
          class="bg-gray-50 border border-gray-100 p-4 rounded-xl text-[12px] text-gray-500 font-prompt leading-relaxed h-44 overflow-y-auto custom-scrollbar">
          <p class="font-bold text-gray-800 mb-2">ยินดีต้อนรับเข้าสู่ระบบ E-Campaign (RSU Healthcare Services)</p>
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
          <input type="checkbox" required name="agreed" value="1"
            class="shrink-0 w-6 h-6 rounded-lg border-gray-300 text-[#0052CC] focus:ring-[#0052CC] transition-all" />
          <span class="text-xs text-gray-600 font-bold leading-tight font-prompt">ฉันได้อ่าน
            และยอมรับข้อตกลงนโยบายความเป็นส่วนตัว</span>
        </label>
      </div>
    </div>

    <div
      class="fixed bottom-0 left-0 right-0 max-w-md mx-auto p-4 bg-white border-t border-gray-100 z-20 shadow-[0_-4px_20px_-10px_rgba(0,0,0,0.1)]">
      <button type="submit"
        class="w-full bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-all shadow-sm active:scale-[0.98] font-prompt">
        บันทึกและดำเนินการต่อ
      </button>
    </div>
  </form>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const statusInputs = document.querySelectorAll('input[name="status"]');
    const studentIdBtn = document.getElementById('student_id_container');
    const studentIdInput = document.getElementById('id_number');
    const statusSection = document.querySelector('.grid.grid-cols-3');

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
      // ลบ error highlight เมื่อเลือกแล้ว
      if (statusSection) statusSection.classList.remove('ring-2', 'ring-red-400', 'rounded-xl');
    }

    statusInputs.forEach(input => {
      input.addEventListener('change', toggleFields);
    });

    toggleFields();

    // JS validation ก่อน submit (สำหรับ hidden radio ที่ browser อาจไม่ validate)
    document.getElementById('profileForm').addEventListener('submit', function (e) {
      const selected = document.querySelector('input[name="status"]:checked');
      if (!selected) {
        e.preventDefault();
        if (statusSection) {
          statusSection.classList.add('ring-2', 'ring-red-400', 'rounded-xl');
          statusSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        // แสดง inline error
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