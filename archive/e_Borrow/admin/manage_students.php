<?php
// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php"); // (อันนี้ถูกต้อง เพราะอยู่ใน admin/ เหมือนกัน)
    exit;
}

// 3. (Query ที่ 1) ดึงข้อมูลผู้ใช้งาน (sys_users)
try {
    $sql_students = "SELECT 
                s.*, 
                u.id as linked_user_id 
            FROM sys_users s
            LEFT JOIN sys_staff u ON s.line_user_id = u.linked_line_user_id
            ORDER BY s.full_name ASC";
    $stmt_students = $pdo->prepare($sql_students);
    $stmt_students->execute();
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $student_error = "เกิดข้อผิดพลาดในการดึงข้อมูลผู้ใช้งาน: " . $e->getMessage();
    $students = [];
}

// 4. (Query ที่ 2) ดึงข้อมูลพนักงาน (sys_staff)
try {
    $sql_staff = "SELECT 
                u.id, u.username, u.full_name, u.role, u.linked_line_user_id, u.account_status,
                s.full_name as linked_student_name
              FROM sys_staff u
                  LEFT JOIN sys_users s ON u.linked_line_user_id = s.line_user_id
                  ORDER BY u.role ASC, u.username ASC";
    $stmt_staff = $pdo->prepare($sql_staff);
    $stmt_staff->execute();
    $staff_accounts = $stmt_staff->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $staff_error = "เกิดข้อผิดพลาดในการดึงข้อมูลพนักงาน: " . $e->getMessage();
    $staff_accounts = [];
}

$status_counts = [
    'student' => 0,
    'teacher' => 0,
    'staff'   => 0,
    'other'   => 0,
];

foreach ($students as $student) {
    $status = $student['status']; // e.g., 'student', 'teacher', etc.
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}

// 5. (โค้ดเช็ค $_GET)
$message = '';
$message_type = '';
if (isset($_GET['add']) && $_GET['add'] == 'success') {
    $message = 'เพิ่มผู้ใช้งานใหม่สำเร็จ!';
    $message_type = 'success';
} elseif (isset($_GET['edit']) && $_GET['edit'] == 'success') {
    $message = 'แก้ไขข้อมูลผู้ใช้งานสำเร็จ!';
    $message_type = 'success';
} elseif (isset($_GET['delete']) && $_GET['delete'] == 'success') {
    $message = 'ลบข้อมูลผู้ใช้งานสำเร็จ!';
    $message_type = 'success';
} elseif (isset($_GET['promote']) && $_GET['promote'] == 'success') {
    $message = 'เลื่อนขั้นผู้ใช้งานเป็นพนักงานสำเร็จ!';
    $message_type = 'success';
} elseif (isset($_GET['staff_op']) && $_GET['staff_op'] == 'success') {
    $message = 'ดำเนินการกับบัญชีพนักงานสำเร็จ!';
    $message_type = 'success';
} elseif (isset($_GET['error'])) {
    $message_type = 'error';
    if ($_GET['error'] == 'fk_constraint') {
        $message = 'ไม่สามารถลบผู้ใช้งานได้ เนื่องจากมีประวัติการทำรายการค้างอยู่!';
    } else {
        $message = 'เกิดข้อผิดพลาด: ' . htmlspecialchars($_GET['error']);
    }
}


// 6. ตั้งค่าตัวแปรสำหรับ Header
$page_title = "จัดการผู้ใช้งาน";
$current_page = "manage_user";
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/header.php');
?>

<?php if ($message): ?>
    <div style="padding: 15px; margin-bottom: 20px; border-radius: 4px; color: #fff; background-color: <?php echo ($message_type == 'success') ? 'var(--color-success)' : 'var(--color-danger)'; ?>;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="section-card" style="margin-bottom: 1.5rem;">
    <h2 class="section-title">ภาพรวมสัดส่วนผู้ใช้งาน</h2>
    <div style="width: 100%; max-width: 400px; margin: 0 auto;">
        <canvas id="userRoleChart"></canvas>
    </div>
</div>

<div class="header-row" data-target="#userSectionContent">
    <h2><i class="fas fa-users"></i> 👥 จัดการผู้ใช้งาน (User)</h2>
    <button type="button" class="collapse-toggle-btn">
        <i class="fas fa-chevron-down"></i>
        <i class="fas fa-chevron-up"></i>
    </button>
</div>

<div id="userSectionContent" class="collapsible-content">

    <div class="add-user-button-wrapper">
        <button class="add-btn" onclick="openAddStudentPopup()" style="background-color: var(--color-info);">
            <i class="fas fa-plus"></i> เพิ่มผู้ใช้งาน (โดย Admin)
        </button>
    </div>

    <div class="table-container desktop-only" style="margin-bottom: 2rem;">
        <?php if (isset($student_error)) echo "<p style='color: red; padding: 15px;'>$student_error</p>"; ?>
        <table>
            <thead>
                <tr>
                    <th>ชื่อ-สกุล</th>
                    <th style="width: 20%;">รหัสผู้ใช้งาน/บุคลากร</th>
                    <th>สถานะภาพ</th>
                    <th>เบอร์โทร</th>
                    <th>ลงทะเบียนโดย</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
          <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="6" style="text-align: center;">ยังไม่มีข้อมูลผู้ใช้งานในระบบ</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="truncate-text" title="<?php echo htmlspecialchars($student['full_name']); ?>"><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['student_personnel_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($student['status']) . ($student['status'] == 'other' ? ' (' . htmlspecialchars($student['status_other']) . ')' : ''); ?></td>
                            <td><?php echo htmlspecialchars($student['phone_number'] ?? '-'); ?></td>
                            <td><?php echo ($student['line_user_id']) ? '<span style="color: #00B900; font-weight: bold;">LINE</span>' : '<span style="color: #6c757d;">Admin</span>'; ?></td>
                            <td class="action-buttons">
                                <button type="button" class="btn btn-manage" onclick="openEditStudentPopup(<?php echo $student['id']; ?>)">ดู/แก้ไข</button>
                                <?php if ($student['linked_user_id']): ?>
                                    <button type="button" class="btn btn-danger" onclick="confirmDemote(<?php echo $student['linked_user_id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')"><i class="fas fa-user-minus"></i> ลดสิทธิ์</button>
                                <?php else: ?>
                                    <?php if (!empty($student['line_user_id'])): ?>
                                        <button type="button" class="btn btn-sm" style="background-color: #06c755; color: white;" onclick="openSendLinePopup(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')"><i class="fab fa-line"></i> ส่งข้อความ</button>
                                        <button type="button" class="btn" style="background-color: #ffc107; color: #333;" onclick="openPromotePopup(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($student['line_user_id'])); ?>')"><i class="fas fa-user-shield"></i> เลื่อนขั้น</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-danger" style="margin-left: 5px;" onclick="confirmDeleteStudent(event, <?php echo $student['id']; ?>)">ลบ</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="student-card-list">
        <?php if (isset($student_error)) echo "<p style='color: red; padding: 15px;'>$student_error</p>"; ?>

        <?php if (empty($students)): ?>
            <div class="history-card">
                <p style="text-align: center; width: 100%;">ยังไม่มีข้อมูลผู้ใช้งานในระบบ</p>
            </div>
        <?php else: ?>
            <?php foreach ($students as $student): ?>
                <div class="history-card">
                    <div class="history-card-icon">
                        <?php if ($student['line_user_id']): ?>
                            <span class="status-badge green" title="ลงทะเบียนผ่าน LINE">
                                <i class="fab fa-line" style="font-size: 1.5rem;"></i>
                            </span>
                        <?php else: ?>
                            <span class="status-badge grey" title="Admin เพิ่มเอง">
                                <i class="fas fa-user-shield"></i>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="history-card-info">
                        <h4 class="truncate-text" title="<?php echo htmlspecialchars($student['full_name']); ?>">
                            <?php echo htmlspecialchars($student['full_name']); ?>
                        </h4>
                        <p>
                            รหัส: <?php echo htmlspecialchars($student['student_personnel_id'] ?? '-'); ?>
                        </p>
                        <p style="font-size: 0.9em;">
                            สถานะภาพ: <?php
                                        echo htmlspecialchars($student['status']);
                                        if ($student['status'] == 'other') {
                                            echo ' (' . htmlspecialchars($student['status_other']) . ')';
                                        }
                                        ?>
                        </p>
                    </div>

                    <div class="pending-card-actions">
                        <button type="button"
                            class="btn btn-manage"
                            onclick="openEditStudentPopup(<?php echo $student['id']; ?>)">
                            <i class="fas fa-search"></i> ดู/แก้ไข
                        </button>

                        <?php if (!$student['linked_user_id']): ?>
                            <button type="button"
                                class="btn btn-danger"
                                onclick="confirmDeleteStudent(event, <?php echo $student['id']; ?>)">
                                <i class="fas fa-trash"></i> ลบ
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="header-row" data-target="#staffSectionContent">
    <h2><i class="fas fa-user-shield"></i> 🛡️ จัดการบัญชีพนักงาน (Admin/Employee)</h2>
    <button type="button" class="collapse-toggle-btn">
        <i class="fas fa-chevron-down"></i>
        <i class="fas fa-chevron-up"></i>
    </button>
</div>

<div id="staffSectionContent" class="collapsible-content">

    <div class="add-user-button-wrapper">
        <button class="add-btn" onclick="openAddStaffPopup()">
            <i class="fas fa-plus"></i> เพิ่มบัญชีพนักงาน
        </button>
    </div>

    <div class="table-container">
        <?php if (isset($staff_error)) echo "<p style='color: red; padding: 15px;'>$staff_error</p>"; ?>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>ชื่อ-สกุล</th>
                    <th>สิทธิ์ (Role)</th>
                    <th>สถานะ</th>
                    <th>บัญชีที่เชื่อมโยง (LINE)</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff_accounts)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center;">ไม่มีข้อมูลบัญชีพนักงาน</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staff_accounts as $staff): ?>
                        <tr class="<?php if ($staff['id'] == $_SESSION['user_id']) echo 'current-user-row'; ?>">
                            <td>
                                <?php echo htmlspecialchars($staff['username']); ?>
                                <?php if ($staff['id'] == $_SESSION['user_id']) echo ' <strong>(คุณ)</strong>'; ?>
                            </td>
                            <td class="truncate-text" title="<?php echo htmlspecialchars($staff['full_name']); ?>">
                                <?php echo htmlspecialchars($staff['full_name']); ?>
                            </td>
                            <td>
                                <?php if ($staff['role'] == 'admin'): ?>
                                    <span style="color: var(--color-danger); font-weight: bold;">Admin <i class="fa-solid fa-crown"></i></span>
                                <?php else: ?>
                                    <span style="color: var(--color-primary);">Employee</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($staff['account_status'] == 'active'): ?>
                                    <span class="status-badge available">Active</span>
                                <?php else: ?>
                                    <span class="status-badge disabled">Disabled</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($staff['linked_line_user_id']): ?>
                                    <span style="color: #00B900;" title="ผูกกับ LINE ID: <?php echo htmlspecialchars($staff['linked_line_user_id']); ?>">
                                        <i class="fas fa-link"></i> <?php echo htmlspecialchars($staff['linked_student_name'] ?? 'N/A'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">(ไม่มี)</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <button type="button"
                                    class="btn btn-manage"
                                    onclick="openEditStaffPopup(<?php echo $staff['id']; ?>)">ดู/แก้ไข</button>

                                <?php if ($staff['id'] != $_SESSION['user_id']): // (ป้องกันการกระทำกับตัวเอง) 
                                ?>

                                    <?php if ($staff['account_status'] == 'active'): ?>
                                        <button type="button"
                                            class="btn btn-disable"
                                            onclick="confirmToggleStaffStatus(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>', 'disabled')">
                                            <i class="fas fa-user-lock"></i> ระงับ
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            class="btn btn-borrow"
                                            onclick="confirmToggleStaffStatus(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>', 'active')">
                                            <i class="fas fa-user-check"></i> เปิด
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($staff['linked_line_user_id']): ?>
                                        <button type="button"
                                            class="btn btn-danger"
                                            onclick="confirmDemote(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>')">
                                            <i class="fas fa-user-minus"></i> ลดสิทธิ์
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            class="btn btn-danger"
                                            onclick="confirmDeleteStaff(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>')">
                                            <i class="fas fa-trash"></i> ลบบัญชี
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // ✅ (แก้ไข) แก้ไข Path ของ fetch ทั้งหมด (ลบ ../ ออก) ◀️

    function confirmDeleteStudent(event, id) {
        event.preventDefault();
        Swal.fire({
            title: "คุณแน่ใจหรือไม่?",
            text: "คุณกำลังจะลบผู้ใช้งานนี้ (เฉพาะที่ Admin เพิ่มเอง)",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "ใช่, ลบเลย",
            cancelButtonText: "ยกเลิก"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('id', id);
                
                fetch('process/delete_student_process.php', { // ✅
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('ลบสำเร็จ!', 'ผู้ใช้งานถูกลบเรียบร้อยแล้ว', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error');
                });
            }
        });
    }

    function openAddStudentPopup() {
        Swal.fire({
            title: '➕ เพิ่มผู้ใช้งาน (โดย Admin)',
            html: `
            <form id="swalAddForm" style="text-align: left; margin-top: 20px;">
                <p>ผู้ใช้งานที่เพิ่มโดย Admin จะไม่มี LINE ID เชื่อมโยง</p>
                <div style="margin-bottom: 15px;">
                    <label for="swal_full_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_full_name" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_phone_number" style="font-weight: bold; display: block; margin-bottom: 5px;">เบอร์โทร:</label>
                    <input type="text" name="phone_number" id="swal_phone_number" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                </form>`,
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: 'var(--color-success, #28a745)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalAddForm');
                const fullName = form.querySelector('#swal_full_name').value;
                if (!fullName) {
                    Swal.showValidationMessage('กรุณากรอก ชื่อ-สกุล ผู้ใช้งาน');
                    return false;
                }
                
                return fetch('process/add_student_process.php', { // ✅
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('บันทึกสำเร็จ!', 'เพิ่มผู้ใช้งานใหม่เรียบร้อยแล้ว', 'success').then(() => location.href = 'admin/manage_students.php?add=success');
            }
        });
    }

    // (ฟังก์ชัน Helper ใหม่ สำหรับ Popup "แก้ไข" เพื่อซ่อน/แสดง ช่อง "อื่นๆ")
    function checkOtherStatusPopup(value) {
        var otherGroup = document.getElementById('other_status_group_popup');
        var otherInput = document.getElementById('swal_edit_status_other');
        if (value === 'other') {
            otherGroup.style.display = 'block';
            otherInput.required = true;
        } else {
            otherGroup.style.display = 'none';
            otherInput.required = false;
        }
    }

    function openEditStudentPopup(studentId) {
        Swal.fire({
            title: 'กำลังโหลดข้อมูล...',
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        fetch(`ajax/get_student_data.php?id=${studentId}`) // ✅
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                const student = data.student;

                const otherStatusDisplay = (student.status === 'other') ? 'block' : 'none';

                const formHtml = `
                <form id="swalEditStudentForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="student_id" value="${student.id}">
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_full_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                        <input type="text" name="full_name" id="swal_edit_full_name" value="${student.full_name}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_department" style="font-weight: bold; display: block; margin-bottom: 5px;">คณะ/หน่วยงาน:</label>
                        <input type="text" name="department" id="swal_edit_department" value="${student.department || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_status" style="font-weight: bold; display: block; margin-bottom: 5px;">สถานภาพ: <span style="color:red;">*</span></label>
                        <select name="status" id="swal_edit_status" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;" onchange="checkOtherStatusPopup(this.value)">
                            <option value="student" ${student.status === 'student' ? 'selected' : ''}>นักศึกษา</option>
                            <option value="teacher" ${student.status === 'teacher' ? 'selected' : ''}>อาจารย์</option>
                            <option value="staff" ${student.status === 'staff' ? 'selected' : ''}>เจ้าหน้าที่</option>
                            <option value="other" ${student.status === 'other' ? 'selected' : ''}>อื่นๆ</option>
                        </select>
                    </div>

                    <div class="form-group" id="other_status_group_popup" style="display: ${otherStatusDisplay}; margin-bottom: 15px;">
                        <label for="swal_edit_status_other" style="font-weight: bold; display: block; margin-bottom: 5px;">โปรดระบุ "อื่นๆ":</label>
                        <input type="text" name="status_other" id="swal_edit_status_other" value="${student.status_other || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_student_id" style="font-weight: bold; display: block; margin-bottom: 5px;">รหัสผู้ใช้งาน/บุคลากร:</label>
                        <input type="text" name="student_personnel_id" id="swal_edit_student_id" value="${student.student_personnel_id || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_phone_number" style="font-weight: bold; display: block; margin-bottom: 5px;">เบอร์โทร:</label>
                        <input type="text" name="phone_number" id="swal_edit_phone_number" value="${student.phone_number || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                </form>`;

                Swal.fire({
                    title: '🔧 แก้ไขข้อมูลผู้ใช้งาน',
                    html: formHtml,
                    showCancelButton: true,
                    confirmButtonText: 'บันทึกการเปลี่ยนแปลง',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: 'var(--color-primary, #0B6623)',
                    focusConfirm: false,
                    preConfirm: () => {
                        const form = document.getElementById('swalEditStudentForm');
                        const fullName = form.querySelector('#swal_edit_full_name').value;
						if (/[<>]/.test(fullName)) {
        Swal.showValidationMessage('ไม่อนุญาตให้ใช้อักขระพิเศษ เช่น < หรือ > ในชื่อ');
        return false;
    }
                        const status = form.querySelector('#swal_edit_status').value;
                        const statusOther = form.querySelector('#swal_edit_status_other').value;

                        if (!fullName || !status) {
                            Swal.showValidationMessage('กรุณากรอกช่องที่มีเครื่องหมาย * ให้ครบถ้วน');
                            return false;
                        }
                        if (status === 'other' && !statusOther) {
                            Swal.showValidationMessage('กรุณาระบุสถานภาพ "อื่นๆ"');
                            return false;
                        }
                        
                        return fetch('process/edit_student_process.php', { // ✅
                                method: 'POST',
                                body: new FormData(form)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status !== 'success') throw new Error(data.message);
                                return data;
                            })
                            .catch(error => {
                                Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                            });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลผู้ใช้งานเรียบร้อย', 'success').then(() => location.href = 'admin/manage_students.php?edit=success');
                    }
                });
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
            });
    }

    function openPromotePopup(studentId, studentName, lineId) {
        Swal.fire({
            title: 'เลื่อนขั้นผู้ใช้งาน',
            html: `
            <p style="text-align: left;">คุณกำลังจะเลื่อนขั้น <strong>${studentName}</strong> (ที่มี LINE ID) ให้เป็น "พนักงาน"</p>
            <p style="text-align: left;">กรุณาสร้างบัญชีสำหรับ Login (เผื่อกรณีที่ไม่ได้เข้าผ่าน LINE):</p>
            
            <form id="swalPromoteForm" style="text-align: left; margin-top: 20px;">
                <input type="hidden" name="student_id_to_promote" value="${studentId}">
                <input type="hidden" name="line_user_id_to_link" value="${lineId}">
                
                <div style="margin-bottom: 15px;">
                    <label for="swal_username" style="font-weight: bold; display: block; margin-bottom: 5px;">1. Username (สำหรับ Login): <span style="color:red;">*</span></label>
                    <input type="text" name="new_username" id="swal_username" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_password" style="font-weight: bold; display: block; margin-bottom: 5px;">2. Password (ชั่วคราว): <span style="color:red;">*</span></label>
                    <input type="text" name="new_password" id="swal_password" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_role" style="font-weight: bold; display: block; margin-bottom: 5px;">3. สิทธิ์ (Role): <span style="color:red;">*</span></label>
                    <select name="new_role" id="swal_role" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="employee">พนักงาน (Employee)</option>
                        <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
            </form>`,
            showCancelButton: true,
            confirmButtonText: 'ยืนยันการเลื่อนขั้น',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: 'var(--color-warning, #ffc107)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalPromoteForm');
                const username = form.querySelector('#swal_username').value;
                const password = form.querySelector('#swal_password').value;
                if (!username || !password) {
                    Swal.showValidationMessage('กรุณากรอก Username และ Password');
                    return false;
                }
                
                return fetch('process/promote_student_process.php', { // ✅
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('เลื่อนขั้นสำเร็จ!', 'ผู้ใช้งานนี้กลายเป็นพนักงานแล้ว', 'success').then(() => location.href = 'admin/manage_students.php?promote=success');
            }
        });
    }

    function confirmDemote(userId, staffName) {
        Swal.fire({
            title: `คุณแน่ใจหรือไม่?`,
            text: `คุณกำลังจะลดสิทธิ์ ${staffName} กลับไปเป็น "ผู้ใช้งาน" บัญชีพนักงานจะถูกลบ (แต่ยัง Login LINE ได้)`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "ใช่, ลดสิทธิ์เลย",
            cancelButtonText: "ยกเลิก"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('user_id_to_demote', userId);
                
                fetch('process/demote_staff_process.php', { // ✅
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire('ลดสิทธิ์สำเร็จ!', data.message, 'success')
                                .then(() => location.href = 'admin/manage_students.php?staff_op=success');
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error');
                    });
            }
        });
    }

    function confirmDeleteStaff(userId, staffName) {
        Swal.fire({
            title: `คุณแน่ใจหรือไม่?`,
            text: `คุณกำลังจะลบบัญชีพนักงาน [${staffName}] ออกจากระบบอย่างถาวร (จะลบได้ต่อเมื่อไม่มีประวัติการทำรายการค้างอยู่)`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "ใช่, ลบบัญชี",
            cancelButtonText: "ยกเลิก"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('user_id_to_delete', userId);
                
                fetch('process/delete_staff_process.php', { // ✅
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire('ลบสำเร็จ!', data.message, 'success')
                                .then(() => location.href = 'admin/manage_students.php?staff_op=success');
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error');
                    });
            }
        });
    }

    function openAddStaffPopup() {
        Swal.fire({
            title: '➕ เพิ่มบัญชีพนักงานใหม่',
            html: `
            <p style="text-align: left;">บัญชีนี้จะใช้สำหรับ Login ในหน้า Admin/Employee (จะไม่ถูกผูกกับ LINE)</p>
            <form id="swalAddStaffForm" style="text-align: left; margin-top: 20px;">
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_username" style="font-weight: bold; display: block; margin-bottom: 5px;">Username: <span style="color:red;">*</span></label>
                    <input type="text" name="username" id="swal_s_username" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_password" style="font-weight: bold; display: block; margin-bottom: 5px;">Password: <span style="color:red;">*</span></label>
                    <input type="text" name="password" id="swal_s_password" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_fullname" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_s_fullname" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_role" style="font-weight: bold; display: block; margin-bottom: 5px;">สิทธิ์ (Role): <span style="color:red;">*</span></label>
                    <select name="role" id="swal_s_role" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="employee">พนักงาน (Employee)</option>
                        <option value="editor">พนักงาน (Editor - จัดการอุปกรณ์)</option>
                        <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
            </form>`,
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: 'var(--color-success, #28a745)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalAddStaffForm');
                if (!form.checkValidity()) {
                    Swal.showValidationMessage('กรุณากรอกข้อมูล * ให้ครบถ้วน');
                    return false;
                }
                
                return fetch('process/add_staff_process.php', { // ✅
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('บันทึกสำเร็จ!', 'เพิ่มบัญชีพนักงานใหม่เรียบร้อย', 'success').then(() => location.href = 'admin/manage_students.php?staff_op=success');
            }
        });
    }

    function openEditStaffPopup(userId) {
        Swal.fire({
            title: 'กำลังโหลดข้อมูล...',
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch(`ajax/get_staff_data.php?id=${userId}`) // ✅
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                const staff = data.staff;

                const is_linked = staff.linked_line_user_id ? true : false;
                const disabled_attr = is_linked ? 'disabled' : '';
                const linked_warning = is_linked ? '<p style="color: #00B900; text-align: left;">(บัญชีนี้ผูกกับ LINE จึงไม่สามารถแก้ไขชื่อและสิทธิ์ได้จากหน้านี้)</p>' : '';

                const formHtml = `
                <form id="swalEditStaffForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="user_id" value="${staff.id}">
                    ${linked_warning}
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_username" style="font-weight: bold; display: block; margin-bottom: 5px;">Username: <span style="color:red;">*</span></label>
                        <input type="text" name="username" id="swal_e_username" value="${staff.username}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_fullname" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                        <input type="text" name="full_name" id="swal_e_fullname" value="${staff.full_name}" required ${disabled_attr} style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; background-color: ${is_linked ? '#f4f4f4' : '#fff'};">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_role" style="font-weight: bold; display: block; margin-bottom: 5px;">สิทธิ์ (Role): <span style="color:red;">*</span></label>
                        <select name="role" id="swal_e_role" required ${disabled_attr} style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; background-color: ${is_linked ? '#f4f4f4' : '#fff'};">
                            <option value="employee" ${staff.role == 'employee' ? 'selected' : ''}>พนักงาน (Employee)</option>
                            <option value="editor" ${staff.role == 'editor' ? 'selected' : ''}>พนักงาน (Editor - จัดการอุปกรณ์)</option>
                            <option value="admin" ${staff.role == 'admin' ? 'selected' : ''}>ผู้ดูแลระบบ (Admin)</option>
                        </select>
                    </div>
                    <hr style="margin: 20px 0;">
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_password" style="font-weight: bold; display: block; margin-bottom: 5px;">Reset รหัสผ่าน (กรอกเฉพาะเมื่อต้องการเปลี่ยน):</label>
                        <input type="text" name="new_password" id="swal_e_password" placeholder="กรอกรหัสผ่านใหม่" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                </form>`;

                Swal.fire({
                    title: '🔧 แก้ไขบัญชีพนักงาน',
                    html: formHtml,
                    showCancelButton: true,
                    confirmButtonText: 'บันทึกการเปลี่ยนแปลง',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: 'var(--color-primary, #0B6623)',
                    focusConfirm: false,
                    preConfirm: () => {
                        const form = document.getElementById('swalEditStaffForm');
                        if (!form.checkValidity()) {
                            Swal.showValidationMessage('กรุณากรอกข้อมูล * ให้ครบถ้วน');
                            return false;
                        }
                        
                        return fetch('process/edit_staff_process.php', { // ✅
                                method: 'POST',
                                body: new FormData(form)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status !== 'success') throw new Error(data.message);
                                return data;
                            })
                            .catch(error => {
                                Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                            });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลบัญชีเรียบร้อย', 'success').then(() => location.href = 'admin/manage_students.php?staff_op=success');
                    }
                });
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
            });
    }

    function confirmToggleStaffStatus(userId, staffName, newStatus) {
        const actionText = (newStatus === 'disabled') ? 'ระงับบัญชี' : 'เปิดใช้งาน';
        const actionIcon = (newStatus === 'disabled') ? 'warning' : 'info';
        const actionConfirmColor = (newStatus === 'disabled') ? '#dc3545' : '#17a2b8';

        Swal.fire({
            title: `ยืนยันการ${actionText}?`,
            text: `คุณกำลังจะ${actionText}บัญชีของ ${staffName}`,
            icon: actionIcon,
            showCancelButton: true,
            confirmButtonColor: actionConfirmColor,
            cancelButtonColor: "#3085d6",
            confirmButtonText: `ใช่, ${actionText}`,
            cancelButtonText: "ยกเลิก"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('user_id', userId);
                formData.append('new_status', newStatus);

                fetch('process/toggle_staff_status.php', { // ✅
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire('สำเร็จ!', data.message, 'success')
                                .then(() => location.href = 'admin/manage_students.php?staff_op=success');
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error');
                    });
            }
        });
    }
    document.addEventListener("DOMContentLoaded", function() {

        const ctx = document.getElementById('userRoleChart').getContext('2d');

        const statusData = <?php echo json_encode($status_counts); ?>;

        const isDarkMode = document.body.classList.contains('dark-mode');
        const chartTextColor = isDarkMode ? '#E5E7EB' : '#6C757D';

        const userRoleChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    'นักศึกษา (student)',
                    'อาจารย์ (teacher)',
                    'เจ้าหน้าที่ (staff)',
                    'อื่นๆ (other)'
                ],
                datasets: [{
                    label: 'จำนวน (คน)',
                    data: [
                        statusData.student,
                        statusData.teacher,
                        statusData.staff,
                        statusData.other
                    ],
                    backgroundColor: [
                        'rgba(22, 163, 74, 0.7)', /* เขียว */
                        'rgba(59, 130, 246, 0.7)', /* น้ำเงิน */
                        'rgba(249, 115, 22, 0.7)', /* ส้ม */
                        'rgba(107, 114, 128, 0.7)' /* เทา */
                    ],
                    borderColor: [
                        'rgba(22, 163, 74, 1)',
                        'rgba(37, 99, 235, 1)',
                        'rgba(217, 70, 24, 1)',
                        'rgba(75, 85, 99, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: chartTextColor
                        }
                    }
                }
            }
        });

        try {
            const themeToggleBtn = document.getElementById('theme-toggle-btn');
            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', function() {
                    setTimeout(() => {
                        const isDarkMode = document.body.classList.contains('dark-mode');
                        const newColor = isDarkMode ? '#E5E7EB' : '#6C757D';

                        if (userRoleChart) {
                            userRoleChart.options.plugins.legend.labels.color = newColor;
                            userRoleChart.update();
                        }
                    }, 10);
                });
            }
        } catch (e) {
            console.error('Chart theme toggle error:', e);
        }
    });
	
	function openSendLinePopup(studentId, studentName) {
        Swal.fire({
            title: 'ส่งข้อความ LINE',
            html: `<p style="text-align:left; margin-bottom:10px;">ถึง: <strong>${studentName}</strong></p><textarea id="line_msg_text" class="swal2-textarea" placeholder="พิมพ์ข้อความที่นี่..." style="margin: 0; width: 100%; height: 100px;"></textarea>`,
            showCancelButton: true, confirmButtonText: '<i class="fas fa-paper-plane"></i> ส่งเลย', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#06c755',
            preConfirm: () => {
                const message = document.getElementById('line_msg_text').value;
                if (!message) { Swal.showValidationMessage('กรุณาพิมพ์ข้อความ'); return false; }
                const formData = new FormData();
                formData.append('student_id', studentId);
                formData.append('message', message);
                return fetch('process/admin_send_line_process.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => { if (d.status !== 'success') throw new Error(d.message); return d; }).catch(e => { Swal.showValidationMessage(e.message); });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ icon: 'success', title: 'ส่งเรียบร้อย!', text: 'ข้อความถูกส่งไปยังผู้ใช้แล้ว', timer: 1500, showConfirmButton: false });
            }
        });
    }
</script>

<?php
// 7. เรียกใช้ Footer
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/footer.php');
?>
