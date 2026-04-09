<?php
// archive/e_Borrow/profile.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

// ใช้ DB กลางของ e-campaignv2 (ตาราง sys_users ตัวเดียวกัน)
require_once __DIR__ . '/../../config/db_connect.php';

$student_id = (int)$_SESSION['student_id'];
$status_msg = '';
$status_type = '';

// ---- Handle POST: บันทึกข้อมูล ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $prefix    = trim($_POST['prefix'] ?? '');
    if ($prefix === 'other') $prefix = trim($_POST['prefix_other'] ?? '');

    $first_name           = trim($_POST['first_name']           ?? '');
    $last_name            = trim($_POST['last_name']            ?? '');
    $department           = trim($_POST['department']           ?? '');
    $student_personnel_id = trim($_POST['student_personnel_id'] ?? '');
    $phone_number         = trim($_POST['phone_number']         ?? '');

    $full_name = trim(implode(' ', array_filter([$prefix, $first_name, $last_name])));

    if (!$first_name || !$last_name) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'กรุณากรอกชื่อและนามสกุลให้ครบถ้วน']);
            exit;
        }
        $status_msg  = 'กรุณากรอกชื่อและนามสกุลให้ครบถ้วน';
        $status_type = 'error';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "UPDATE sys_users
                 SET full_name = :full_name,
                     department = :dept,
                     student_personnel_id = :sid,
                     phone_number = :phone
                 WHERE id = :id"
            );
            $stmt->execute([
                ':full_name' => $full_name,
                ':dept'      => $department,
                ':sid'       => $student_personnel_id,
                ':phone'     => $phone_number,
                ':id'        => $student_id,
            ]);

            // อัปเดต Session ใหม่
            $_SESSION['student_full_name'] = $full_name;
            $_SESSION['evax_full_name']    = $full_name;

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'message' => 'บันทึกข้อมูลเรียบร้อยแล้ว!', 'full_name' => $full_name]);
                exit;
            }
            $status_msg  = 'บันทึกข้อมูลเรียบร้อยแล้ว!';
            $status_type = 'success';
        } catch (PDOException $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
                exit;
            }
            $status_msg  = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            $status_type = 'error';
        }
    }
}

// ---- ดึงข้อมูลปัจจุบัน ----
try {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $student_id]);
    $user = $stmt->fetch();
    if (!$user) { header("Location: logout.php"); exit; }
} catch (PDOException $e) {
    error_log("eborrow profile error: " . $e->getMessage()); exit("เกิดข้อผิดพลาด");
}

// ---- แยก prefix / ชื่อ / นามสกุล ----
$standard_prefixes = ['นาย', 'นางสาว', 'นาง', 'ครู', 'นพ.', 'พญ.', 'พล.ต.', 'ร.ท.'];
$curr_full = trim($user['full_name'] ?? '');
$parts     = explode(' ', $curr_full);
$db_prefix = '';
$db_firstname = '';
$db_lastname  = '';

if (count($parts) >= 2) {
    if (in_array($parts[0], $standard_prefixes)) {
        $db_prefix = array_shift($parts);
    }
    $db_lastname  = array_pop($parts);
    $db_firstname = implode(' ', $parts);
} else {
    $db_firstname = $curr_full;
}

$page_title  = 'ตั้งค่าโปรไฟล์';
$active_page = 'settings';
include('includes/student_header.php');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
.profile-page { padding: 16px; max-width: 600px; margin: 0 auto; }
.profile-avatar {
    width: 80px; height: 80px; border-radius: 20px;
    background: linear-gradient(135deg, #0B6623, #1a8c35);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: #fff; font-weight: 700;
    margin: 0 auto 8px; box-shadow: 0 4px 14px rgba(11,102,35,.25);
}
.profile-name { text-align: center; font-size: 1.1rem; font-weight: 700; margin-bottom: 4px; }
.profile-id   { text-align: center; font-size: .85rem; color: #888; margin-bottom: 20px; }

.qr-card {
    background: linear-gradient(135deg, #0B6623, #084C1A);
    border-radius: 14px; padding: 16px; display: flex;
    align-items: center; gap: 14px; margin-bottom: 20px;
    cursor: pointer; transition: transform .2s, box-shadow .2s;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.qr-card:active { transform: scale(.97); }
.qr-card i { font-size: 2.2rem; color: #fff; }
.qr-card-text h4 { color: #fff; font-size: 1rem; margin: 0 0 2px; }
.qr-card-text p  { color: rgba(255,255,255,.7); font-size: .8rem; margin: 0; }

.form-card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
body.dark-mode .form-card { background: #1e2d25; }
.form-section-title {
    font-size: .7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .08em; color: #0B6623;
    margin: 20px 0 10px; padding-bottom: 6px;
    border-bottom: 1px solid rgba(11,102,35,.15);
}
body.dark-mode .form-section-title { color: #4ade80; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-row.triple { grid-template-columns: 100px 1fr 1fr; }
@media (max-width: 520px) {
    .form-row       { grid-template-columns: 1fr; }
    .form-row.triple { grid-template-columns: 1fr; }
}
.form-group label { display: block; font-size: .78rem; font-weight: 600; color: #333; margin-bottom: 5px; }
body.dark-mode .form-group label { color: #e2e8f0; }
.form-group label .req { color: #e53e3e; }
.form-group input, .form-group select {
    width: 100%; padding: 10px 12px;
    border: 1px solid #dde2e8; border-radius: 10px;
    font-size: .9rem; font-family: inherit;
    background: #f9fbff;
    color: #333;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
body.dark-mode .form-group input, body.dark-mode .form-group select {
    background: #162112; border-color: #334155; color: #e2e8f0;
}
.form-group input:focus, .form-group select:focus {
    outline: none; border-color: #0B6623;
    box-shadow: 0 0 0 3px rgba(11,102,35,.12);
}

.alert {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: 10px; margin-bottom: 16px;
    font-size: .88rem; font-weight: 600;
}
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.btn-save {
    width: 100%; padding: 13px; margin-top: 20px;
    background: linear-gradient(135deg, #0B6623, #1a8c35);
    color: #fff; font-size: 1rem; font-weight: 700;
    border: none; border-radius: 12px; cursor: pointer;
    transition: opacity .2s, transform .15s;
    box-shadow: 0 4px 12px rgba(11,102,35,.25);
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-save:hover { opacity: .9; }
.btn-save:active { transform: scale(.97); }
</style>

<div class="profile-page">

    <?php if ($status_msg): ?>
    <div class="alert alert-<?= $status_type ?>">
        <i class="fas fa-<?= $status_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
        <?= htmlspecialchars($status_msg) ?>
    </div>
    <?php endif; ?>

    <!-- Avatar + ชื่อ -->
    <div class="profile-avatar">
        <?= mb_substr($user['full_name'] ?? '?', 0, 1) ?>
    </div>
    <p class="profile-name"><?= htmlspecialchars($user['full_name'] ?: 'ยังไม่ระบุชื่อ') ?></p>
    <p class="profile-id">
        <?= !empty($user['student_personnel_id']) ? 'รหัส: ' . htmlspecialchars($user['student_personnel_id']) : 'ยังไม่ระบุรหัสนักศึกษา' ?>
    </p>

    <!-- QR Code Card -->
    <?php if (!empty($user['student_personnel_id'])): ?>
    <div class="qr-card" onclick="showMyQRCode()">
        <i class="fas fa-qrcode"></i>
        <div class="qr-card-text">
            <h4>บัตรประจำตัวดิจิทัล</h4>
            <p>กดเพื่อแสดง QR Code สำหรับเจ้าหน้าที่สแกน</p>
        </div>
        <i class="fas fa-chevron-right" style="color:rgba(255,255,255,.5); margin-left:auto;"></i>
    </div>
    <?php endif; ?>

    <!-- ฟอร์มข้อมูล -->
    <div class="form-card">
        <form method="POST" action="profile.php" id="profileForm">

            <p class="form-section-title"><i class="fas fa-user" style="margin-right:6px;"></i>ข้อมูลส่วนบุคคล</p>

            <!-- ชื่อ-นามสกุล -->
            <div class="form-row triple">
                <div class="form-group">
                    <label>คำนำหน้า <span class="req">*</span></label>
                    <select name="prefix" id="prefix" required onchange="togglePrefixOther(this.value)">
                        <option value="">เลือก...</option>
                        <?php foreach (['นาย','นางสาว','นาง','ครู','นพ.','พญ.'] as $p): ?>
                        <option value="<?= $p ?>" <?= $db_prefix === $p ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                        <option value="other" <?= (!in_array($db_prefix, $standard_prefixes) && $db_prefix !== '') ? 'selected' : '' ?>>อื่นๆ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>ชื่อจริง <span class="req">*</span></label>
                    <input type="text" name="first_name" id="first_name" placeholder="ชื่อจริง"
                           value="<?= htmlspecialchars($db_firstname) ?>" required>
                </div>
                <div class="form-group">
                    <label>นามสกุล <span class="req">*</span></label>
                    <input type="text" name="last_name" id="last_name" placeholder="นามสกุล"
                           value="<?= htmlspecialchars($db_lastname) ?>" required>
                </div>
            </div>

            <!-- prefix_other -->
            <div class="form-group" id="prefix_other_wrap" style="display:<?= (!in_array($db_prefix, $standard_prefixes) && $db_prefix !== '') ? 'block' : 'none' ?>; margin-top:-4px;">
                <label>ระบุคำนำหน้า</label>
                <input type="text" name="prefix_other" id="prefix_other"
                       placeholder="เช่น ผศ., ดร., ว่าที่ ร.ต."
                       value="<?= (!in_array($db_prefix, $standard_prefixes)) ? htmlspecialchars($db_prefix) : '' ?>">
            </div>

            <p class="form-section-title"><i class="fas fa-id-card" style="margin-right:6px;"></i>ข้อมูลสำหรับการยืนยันตัวตน</p>

            <div class="form-row">
                <div class="form-group">
                    <label>รหัสนักศึกษา / บุคลากร <span class="req">*</span></label>
                    <input type="text" name="student_personnel_id"
                           placeholder="เช่น 6604012345"
                           value="<?= htmlspecialchars($user['student_personnel_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>เบอร์โทรศัพท์</label>
                    <input type="tel" name="phone_number" placeholder="0812345678" maxlength="10"
                           value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>คณะ / หน่วยงาน</label>
                <input type="text" name="department" placeholder="เช่น วิทยาลัยแพทยศาสตร์"
                       value="<?= htmlspecialchars($user['department'] ?? '') ?>">
            </div>

            <button type="submit" class="btn-save">
                <i class="fas fa-save"></i> บันทึกการเปลี่ยนแปลง
            </button>
        </form>
    </div>
</div>

<script>
function togglePrefixOther(val) {
    const wrap  = document.getElementById('prefix_other_wrap');
    const input = document.getElementById('prefix_other');
    if (val === 'other') {
        wrap.style.display  = 'block';
        input.required      = true;
        input.focus();
    } else {
        wrap.style.display  = 'none';
        input.required      = false;
        input.value         = '';
    }
}

// ===== AJAX Form Submit =====
document.getElementById('profileForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn  = this.querySelector('.btn-save');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';
    btn.disabled  = true;

    try {
        const res  = await fetch('profile.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this)
        });
        const data = await res.json();

        if (data.ok) {
            if (data.full_name) {
                const nameEl   = document.querySelector('.profile-name');
                const avatarEl = document.querySelector('.profile-avatar');
                if (nameEl)   nameEl.textContent   = data.full_name;
                if (avatarEl) avatarEl.textContent  = data.full_name.charAt(0);
            }
            Swal.fire({
                toast: true,
                position: 'top',
                icon: 'success',
                title: data.message,
                showConfirmButton: false,
                timer: 2500,
                timerProgressBar: true
            });
        } else {
            Swal.fire({
                toast: true,
                position: 'top',
                icon: 'error',
                title: data.message,
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true
            });
        }
    } catch (err) {
        Swal.fire({ toast: true, position: 'top', icon: 'error', title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ', showConfirmButton: false, timer: 3000 });
    } finally {
        btn.innerHTML = orig;
        btn.disabled  = false;
    }
});

function showMyQRCode() {
    const studentCode = "<?= htmlspecialchars($user['student_personnel_id'] ?? '', ENT_QUOTES) ?>";
    const studentName = "<?= htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES) ?>";
    const studentDbId = "<?= $student_id ?>";
    const qrData = "MEDLOAN_STUDENT:" + studentCode + ":" + studentDbId;

    Swal.fire({
        title: 'บัตรประจำตัวดิจิทัล',
        html: `
            <div style="display:flex;justify-content:center;padding:16px 0;">
                <div id="qrcode-container"></div>
            </div>
            <h3 style="margin-bottom:4px;">${studentCode}</h3>
            <p style="color:#666;font-size:.9rem;">${studentName}</p>
            <p style="font-size:.8rem;color:#16a34a;background:#f0fdf4;padding:10px;border-radius:10px;margin-top:12px;">
                <i class="fas fa-info-circle"></i> ยื่นรหัสนี้ให้เจ้าหน้าที่สแกนเพื่อดำเนินการยืมอุปกรณ์
            </p>`,
        didOpen: () => {
            new QRCode(document.getElementById("qrcode-container"), {
                text: qrData, width: 220, height: 220,
                correctLevel: QRCode.CorrectLevel.H
            });
        },
        confirmButtonText: 'ปิด',
        confirmButtonColor: '#0B6623'
    });
}
</script>

<?php include('includes/student_footer.php'); ?>
