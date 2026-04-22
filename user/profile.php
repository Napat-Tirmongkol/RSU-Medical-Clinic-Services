<?php
// user/profile.php — จัดการข้อมูลส่วนตัว (Original Version)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$userData = [
    'prefix' => '', 'first_name' => '', 'last_name' => '', 'full_name' => '',
    'id_number' => '', 'citizen_id' => '', 'phone' => '', 'status' => '',
    'email' => '', 'gender' => '', 'department' => '',
];

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if ($user) {
        $userData = [
            'prefix' => $user['prefix'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'full_name' => $user['full_name'] ?? '',
            'id_number' => $user['student_personnel_id'] ?? '',
            'citizen_id' => $user['citizen_id'] ?? '',
            'phone' => $user['phone_number'] ?? '',
            'status' => $user['status'] ?? '',
            'email' => $user['email'] ?? '',
            'gender' => $user['gender'] ?? '',
            'department' => $user['department'] ?? '',
        ];
    }
} catch (PDOException $e) {
    error_log("Profile fetch error: " . $e->getMessage());
}

if ($userData['full_name'] !== '' && $userData['first_name'] === '' && $userData['last_name'] === '') {
    $parts = explode(' ', trim($userData['full_name']), 2);
    $userData['first_name'] = $parts[0] ?? '';
    $userData['last_name'] = $parts[1] ?? '';
}

$isEditing = !empty($userData['full_name']);
$redirectBack = $_GET['redirect_back'] ?? 'hub.php';
$citizenIdValue = $userData['citizen_id'];
$isPassport = ($citizenIdValue !== '' && (!ctype_digit($citizenIdValue) || strlen($citizenIdValue) > 13));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';
render_header(__('profile.heading_edit'));
?>

<div class="min-h-screen bg-gray-50 pb-24">
  <div class="max-w-xl mx-auto px-4 pt-8">
    
    <div class="flex items-center justify-between mb-8">
      <h1 class="text-2xl font-bold text-gray-900 font-prompt"><?= __('profile.heading_edit') ?></h1>
      <a href="<?= htmlspecialchars($redirectBack) ?>" class="text-sm font-medium text-blue-600 hover:text-blue-500 font-prompt">
        <i class="fa-solid fa-arrow-left mr-1"></i> <?= __('profile.back_btn') ?>
      </a>
    </div>

    <form id="profileForm" action="save_profile.php" method="POST" class="space-y-6">
      <?php csrf_field(); ?>
      <input type="hidden" name="redirect_back" value="<?= htmlspecialchars($redirectBack) ?>">

      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">
        
        <!-- คำนำหน้าชื่อ -->
        <div class="space-y-1.5">
          <label for="name_title" class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_prefix') ?> <span class="text-red-500">*</span></label>
          <?php
          $_stdPrefixes = ['นาย', 'นาง', 'นางสาว', 'นพ.', 'พญ.', 'ทพ.', 'ทญ.', 'ภก.', 'ภญ.', 'พย.', 'ดร.', 'อ.', 'ผศ.', 'รศ.', 'ศ.'];
          $_isCustomPrefix = ($userData['prefix'] !== '' && !in_array($userData['prefix'], $_stdPrefixes, true));
          $_selectVal = $_isCustomPrefix ? 'other' : $userData['prefix'];
          $_customVal = $_isCustomPrefix ? $userData['prefix'] : '';
          ?>
          <select name="name_title" id="name_title" onchange="toggleCustomTitle()" required
            class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition-all font-prompt">
            <option value="" disabled <?= $_selectVal === '' ? 'selected' : '' ?>><?= __('profile.ph_prefix') ?></option>
            <option value="นาย" <?= $_selectVal === 'นาย' ? 'selected' : '' ?>>นาย (Mr.)</option>
            <option value="นาง" <?= $_selectVal === 'นาง' ? 'selected' : '' ?>>นาง (Mrs.)</option>
            <option value="นางสาว" <?= $_selectVal === 'นางสาว' ? 'selected' : '' ?>>นางสาว (Ms.)</option>
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
              <option value="ดร." <?= $_selectVal === 'ดร.' ? 'selected' : '' ?>>ดร.</option>
              <option value="อ." <?= $_selectVal === 'อ.' ? 'selected' : '' ?>>อ.</option>
              <option value="ผศ." <?= $_selectVal === 'ผศ.' ? 'selected' : '' ?>>ผศ.</option>
              <option value="รศ." <?= $_selectVal === 'รศ.' ? 'selected' : '' ?>>รศ.</option>
              <option value="ศ." <?= $_selectVal === 'ศ.' ? 'selected' : '' ?>>ศ.</option>
            </optgroup>
            <option value="other" <?= $_selectVal === 'other' ? 'selected' : '' ?>><?= __('profile.gender_other') ?> (<?= __('profile.msg_specify') ?>)...</option>
          </select>
          <div id="custom_title_container" class="<?= $_isCustomPrefix ? '' : 'hidden' ?> mt-2">
            <input type="text" id="custom_title" name="custom_title" value="<?= htmlspecialchars($_customVal) ?>" 
              placeholder="<?= __('profile.msg_specify_prefix') ?>" 
              class="w-full h-11 px-4 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-1.5">
            <label for="first_name" class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_first_name') ?> <span class="text-red-500">*</span></label>
            <input type="text" name="first_name" id="first_name" required value="<?= htmlspecialchars($userData['first_name']) ?>"
              class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt">
          </div>
          <div class="space-y-1.5">
            <label for="last_name" class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_last_name') ?> <span class="text-red-500">*</span></label>
            <input type="text" name="last_name" id="last_name" required value="<?= htmlspecialchars($userData['last_name']) ?>"
              class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt">
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_gender') ?> <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex-1 flex items-center justify-center h-12 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-100 transition-all has-[:checked]:bg-blue-50 has-[:checked]:border-blue-200 has-[:checked]:text-blue-600 font-prompt">
              <input type="radio" name="gender" value="male" required class="hidden" <?= $userData['gender'] === 'male' ? 'checked' : '' ?>>
              <span><?= __('profile.gender_male') ?></span>
            </label>
            <label class="flex-1 flex items-center justify-center h-12 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-100 transition-all has-[:checked]:bg-blue-50 has-[:checked]:border-blue-200 has-[:checked]:text-blue-600 font-prompt">
              <input type="radio" name="gender" value="female" required class="hidden" <?= $userData['gender'] === 'female' ? 'checked' : '' ?>>
              <span><?= __('profile.gender_female') ?></span>
            </label>
            <label class="flex-1 flex items-center justify-center h-12 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-100 transition-all has-[:checked]:bg-blue-50 has-[:checked]:border-blue-200 has-[:checked]:text-blue-600 font-prompt">
              <input type="radio" name="gender" value="other" required class="hidden" <?= $userData['gender'] === 'other' ? 'checked' : '' ?>>
              <span><?= __('profile.gender_other') ?></span>
            </label>
          </div>
        </div>

      </div>

      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">
        
        <div class="space-y-1.5">
          <label class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_user_type') ?> <span class="text-red-500">*</span></label>
          <div class="grid grid-cols-3 gap-3">
            <label class="flex items-center justify-center h-12 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-100 transition-all has-[:checked]:bg-emerald-50 has-[:checked]:border-emerald-200 has-[:checked]:text-emerald-600 font-prompt">
              <input type="radio" name="status" value="student" required class="hidden" <?= $userData['status'] === 'student' ? 'checked' : '' ?>>
              <span class="text-sm"><?= __('profile.type_student') ?></span>
            </label>
            <label class="flex items-center justify-center h-12 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-100 transition-all has-[:checked]:bg-emerald-50 has-[:checked]:border-emerald-200 has-[:checked]:text-emerald-600 font-prompt">
              <input type="radio" name="status" value="staff" required class="hidden" <?= $userData['status'] === 'staff' ? 'checked' : '' ?>>
              <span class="text-sm"><?= __('profile.type_staff') ?></span>
            </label>
            <label class="flex items-center justify-center h-12 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer hover:bg-gray-100 transition-all has-[:checked]:bg-emerald-50 has-[:checked]:border-emerald-200 has-[:checked]:text-emerald-600 font-prompt">
              <input type="radio" name="status" value="other" required class="hidden" <?= $userData['status'] === 'other' ? 'checked' : '' ?>>
              <span class="text-sm"><?= __('profile.type_other') ?></span>
            </label>
          </div>
        </div>

        <div id="id_section" class="space-y-4">
          <div class="space-y-1.5">
            <label class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_citizen_id') ?> <span class="text-red-500">*</span></label>
            <div class="flex gap-2 mb-2">
                <label class="flex-1">
                    <input type="radio" name="id_type" value="citizen" class="sr-only peer" <?= !$isPassport ? 'checked' : '' ?>>
                    <div class="py-2 text-center text-xs font-medium border border-gray-100 rounded-lg bg-gray-50 peer-checked:bg-blue-50 peer-checked:border-blue-200 peer-checked:text-blue-600 cursor-pointer transition-all font-prompt">
                        <?= __('profile.lbl_identity_th') ?>
                    </div>
                </label>
                <label class="flex-1">
                    <input type="radio" name="id_type" value="passport" class="sr-only peer" <?= $isPassport ? 'checked' : '' ?>>
                    <div class="py-2 text-center text-xs font-medium border border-gray-100 rounded-lg bg-gray-50 peer-checked:bg-blue-50 peer-checked:border-blue-200 peer-checked:text-blue-600 cursor-pointer transition-all font-prompt">
                        Passport
                    </div>
                </label>
            </div>
            <input type="text" id="citizen_id" name="citizen_id" required value="<?= htmlspecialchars($userData['citizen_id']) ?>"
              class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt" 
              placeholder="<?= __('profile.lbl_citizen_id') ?>">
          </div>

          <div id="student_id_container" class="space-y-1.5">
            <label for="id_number" class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_id') ?> <span class="text-red-500">*</span></label>
            <input type="text" id="id_number" name="id_number" value="<?= htmlspecialchars($userData['id_number']) ?>"
              class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt"
              placeholder="<?= __('profile.id_placeholder') ?>">
          </div>
        </div>

        <div class="space-y-1.5">
          <label for="department" class="text-sm font-semibold text-gray-700 font-prompt">คณะ / หน่วยงาน <span class="text-red-500">*</span></label>
          <div class="relative">
            <?php
            $_facultyList = [];
            try {
                $_pdo2 = db();
                $_facultyList = $_pdo2->query("SELECT name_th FROM sys_faculties ORDER BY name_th")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {}
            ?>
            <input type="text" id="department" name="department" list="faculty-datalist" value="<?= htmlspecialchars($userData['department']) ?>"
              class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt"
              placeholder="<?= __('profile.dept_placeholder') ?>">
            <datalist id="faculty-datalist">
              <?php foreach ($_facultyList as $_f): ?>
                <option value="<?= htmlspecialchars($_f['name_th']) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          
          <!-- AI Hint Container -->
          <div id="dept-ai-hint" class="hidden items-center gap-1.5 text-teal-700 font-prompt mt-1">
            <i class="fa-solid fa-wand-magic-sparkles text-teal-500 text-[10px]"></i>
            <span id="dept-ai-hint-text"></span>
            <button type="button" id="dept-ai-accept"
                class="ml-1 px-1.5 py-0.5 bg-teal-100 hover:bg-teal-200 rounded text-[10px] font-bold text-teal-800 transition-colors">
                <?= __('profile.btn_use_ai') ?>
            </button>
            <button type="button" id="dept-ai-dismiss" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fa-solid fa-xmark text-[10px]"></i>
            </button>
          </div>
        </div>

      </div>

      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">
        
        <div class="space-y-1.5">
          <label for="phone_number" class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_phone') ?> <span class="text-red-500">*</span></label>
          <input type="tel" name="phone_number" id="phone_number" required value="<?= htmlspecialchars($userData['phone']) ?>"
            class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt"
            placeholder="<?= __('profile.ph_phone') ?>">
        </div>

        <div class="space-y-1.5">
          <label for="email" class="text-sm font-semibold text-gray-700 font-prompt"><?= __('profile.lbl_email') ?> (Optional)</label>
          <input type="email" id="email" name="email" value="<?= htmlspecialchars($userData['email']) ?>"
            class="w-full h-12 px-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none font-prompt"
            placeholder="<?= __('profile.ph_email') ?>">
          <div class="flex items-center gap-1.5 text-[11px] text-amber-600 font-medium px-1 pt-1 font-prompt">
            <i class="fa-solid fa-circle-info"></i>
            <span><?= __('profile.msg_email_benefit') ?></span>
          </div>
        </div>

      </div>

      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
        <h3 class="text-sm font-bold text-gray-900 font-prompt uppercase tracking-wider"><?= __('profile.pdpa_title') ?></h3>
        <div class="bg-gray-50 p-4 rounded-xl text-[12px] text-gray-500 leading-relaxed max-h-40 overflow-y-auto font-prompt">
          <?= __('profile.pdpa_intro') ?>
        </div>
        <label class="flex items-center gap-3 cursor-pointer group">
          <div class="relative flex items-center">
            <input type="checkbox" name="agreed" value="1" required <?= $isEditing ? 'checked' : '' ?>
              class="w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 transition-all cursor-pointer">
          </div>
          <span class="text-xs text-gray-600 font-medium font-prompt group-hover:text-gray-900 transition-colors">
            <?= __('profile.lbl_agree') ?>
          </span>
        </label>
      </div>

      <div class="flex gap-4">
        <button type="button" onclick="window.history.back()"
          class="flex-1 h-14 bg-white border border-gray-200 text-gray-700 font-bold rounded-2xl hover:bg-gray-50 transition-all font-prompt">
          <?= __('profile.back_btn') ?>
        </button>
        <button type="submit"
          class="flex-[2] h-14 bg-blue-600 text-white font-bold rounded-2xl shadow-lg shadow-blue-200 hover:bg-blue-700 active:scale-95 transition-all font-prompt">
          <?= __('profile.save_btn') ?>
        </button>
      </div>

    </form>
  </div>
</div>

<script>
function toggleCustomTitle() {
  const select = document.getElementById('name_title');
  const container = document.getElementById('custom_title_container');
  const input = document.getElementById('custom_title');
  if (select.value === 'other') {
    container.classList.remove('hidden');
    input.focus();
  } else {
    container.classList.add('hidden');
    input.value = '';
  }
}

document.addEventListener('DOMContentLoaded', function () {
    // ID Type Logic
    const idTypeInputs = document.querySelectorAll('input[name="id_type"]');
    const citizenIdInput = document.getElementById('citizen_id');

    function applyIdType(type) {
        if (type === 'passport') {
            citizenIdInput.setAttribute('placeholder', 'Passport Number');
            citizenIdInput.removeAttribute('maxlength');
        } else {
            citizenIdInput.setAttribute('placeholder', '<?= __('profile.lbl_citizen_id') ?>');
            citizenIdInput.setAttribute('maxlength', '13');
        }
    }
    idTypeInputs.forEach(input => {
        input.addEventListener('change', (e) => applyIdType(e.target.value));
    });

    // Status Field Logic
    const statusInputs = document.querySelectorAll('input[name="status"]');
    const studentIdContainer = document.getElementById('student_id_container');
    const studentIdInput = document.getElementById('id_number');

    function toggleStatusFields() {
        const checkedStatus = document.querySelector('input[name="status"]:checked');
        if (checkedStatus && checkedStatus.value === 'other') {
            studentIdContainer.classList.add('hidden');
            studentIdInput.removeAttribute('required');
        } else {
            studentIdContainer.classList.remove('hidden');
            studentIdInput.setAttribute('required', 'required');
        }
    }
    statusInputs.forEach(input => {
        input.addEventListener('change', toggleStatusFields);
    });
    toggleStatusFields();

    // AI Department Suggester
    const deptInput    = document.getElementById('department');
    const deptHint     = document.getElementById('dept-ai-hint');
    const deptHintText = document.getElementById('dept-ai-hint-text');
    const deptAccept   = document.getElementById('dept-ai-accept');
    const deptDismiss  = document.getElementById('dept-ai-dismiss');
    let _deptSuggested = null;

    const _validFaculties = <?= json_encode(array_column($_facultyList, 'name_th')) ?>;

    function isExactMatch(val) {
        return _validFaculties.some(f => f.trim().toLowerCase() === val.trim().toLowerCase());
    }

    async function normalizeDepartment(val) {
        if (!val || isExactMatch(val)) { hideHint(); return; }
        try {
            const formData = new FormData();
            formData.append('input', val);
            const response = await fetch('api_faculty_suggest.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.status === 'ok' && data.matched && data.matched !== val) {
                _deptSuggested = data.matched;
                deptHintText.textContent = '<?= __('profile.msg_ai_suggest') ?>: ' + data.matched;
                deptHint.classList.remove('hidden');
                deptHint.classList.add('flex');
            } else { hideHint(); }
        } catch (e) { hideHint(); }
    }

    function hideHint() {
        deptHint.classList.add('hidden');
        deptHint.classList.remove('flex');
        _deptSuggested = null;
    }

    deptInput.addEventListener('blur', function () {
        normalizeDepartment(this.value.trim());
    });

    deptInput.addEventListener('input', function () {
        hideHint();
    });

    deptAccept.addEventListener('click', function () {
        if (_deptSuggested) {
            deptInput.value = _deptSuggested;
        }
        hideHint();
    });

    deptDismiss.addEventListener('click', hideHint);

});
</script>

<?php
render_footer();
?>