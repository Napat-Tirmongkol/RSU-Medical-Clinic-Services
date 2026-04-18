<?php
// admin/manage_students.php (แก้ไข V3.4 - กู้ชีพหน้าผู้ใช้งานและพนักงาน V2)
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../config/db_connect.php');

$pdo = db();

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// 1. ดึงข้อมูลผู้ใช้งาน (sys_users) - ตัด status ออก
try {
    $sql_students = "SELECT s.* FROM sys_users s ORDER BY s.full_name ASC";
    $stmt_students = $pdo->prepare($sql_students);
    $stmt_students->execute();
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $student_error = "เกิดข้อผิดพลาดในการดึงข้อมูลผู้ใช้งาน: " . $e->getMessage();
    $students = [];
}

// 2. ดึงข้อมูลพนักงาน (sys_staff)
try {
    $sql_staff = "SELECT u.id, u.username, u.full_name, u.role FROM sys_staff u ORDER BY u.role ASC, u.username ASC";
    $stmt_staff = $pdo->prepare($sql_staff);
    $stmt_staff->execute();
    $staff_accounts = $stmt_staff->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $staff_error = "เกิดข้อผิดพลาดในการดึงข้อมูลพนักงาน: " . $e->getMessage();
    $staff_accounts = [];
}

$page_title = "จัดการผู้ใช้งาน";
$current_page = "manage_user";
include('../includes/header.php');
?>

<div class="admin-wrap" style="padding:20px;">
    <!-- ส่วนผู้ใช้ทั่วไป -->
    <div class="header-row" style="margin-bottom:10px;">
        <h2><i class="fas fa-users"></i> ผู้ใช้งานทั้งหมดในระบบ (Portal)</h2>
    </div>

    <div class="table-container mb-4">
        <table>
            <thead>
                <tr>
                    <th style="padding:15px;">ชื่อ-นามสกุล</th>
                    <th>รหัสประจำตัว</th>
                    <th>เบอร์โทรศัพท์</th>
                    <th>เชื่อมต่อ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="5" style="text-align: center; padding:40px;" class="text-muted">ไม่พบข้อมูลผู้ใช้งาน</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td style="padding:15px;"><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($student['student_personnel_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($student['phone_number'] ?? '-'); ?></td>
                            <td>
                                <?php echo ($student['line_user_id']) 
                                    ? '<span class="badge status-badge borrowed-ok"><i class="fab fa-line"></i> LINE</span>' 
                                    : '<span class="badge status-badge grey">System</span>'; ?>
                            </td>
                            <td>
                                <button onclick="openEditStudentPopup(<?php echo $student['id']; ?>)" class="btn btn-secondary btn-sm">แก้ไข</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ส่วนบัญชีพนักงาน -->
    <div class="header-row" style="margin:40px 0 10px 0;">
        <h2><i class="fas fa-user-shield"></i> บัญชีพนักงาน (Admin/Staff)</h2>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="padding:15px;">Username</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ระดับสิทธิ์</th>
                    <th style="width:100px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff_accounts)): ?>
                    <tr><td colspan="4" style="text-align: center; padding:40px;" class="text-muted">ไม่พบข้อมูลบัญชีพนักงาน</td></tr>
                <?php else: ?>
                    <?php foreach ($staff_accounts as $staff): ?>
                        <tr>
                            <td style="padding:15px;"><code><?php echo htmlspecialchars($staff['username']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($staff['full_name']); ?></strong></td>
                            <td>
                                <?php
                                    $role_badge = ($staff['role'] == 'admin') ? 'red' : (($staff['role'] == 'editor') ? 'blue' : 'borrowed-ok');
                                    echo '<span class="badge status-badge '.$role_badge.'">'.strtoupper($staff['role']).'</span>';
                                ?>
                            </td>
                            <td>
                                <button onclick="openEditStaffPopup(<?php echo $staff['id']; ?>, <?php echo htmlspecialchars(json_encode($staff), ENT_QUOTES); ?>)"
                                        class="btn btn-secondary btn-sm">แก้ไข</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function openEditStudentPopup(id) {
    Swal.fire({
        title: 'แก้ไขข้อมูลผู้ใช้งาน',
        text: 'ฟังก์ชันแก้ไขข้อมูลส่วนบุคคลกำลังปรับปรุงให้เชื่อมโยงกับโปรไฟล์พอร์ทัล',
        icon: 'info'
    });
}

function openEditStaffPopup(id, staff) {
    const isLineLinked = !!staff.linked_line_user_id;
    const roleOptions = ['admin', 'employee', 'editor']
        .map(r => `<option value="${r}" ${staff.role === r ? 'selected' : ''}>${r.charAt(0).toUpperCase() + r.slice(1)}</option>`)
        .join('');

    Swal.fire({
        title: '<i class="fas fa-user-edit"></i> แก้ไขบัญชีพนักงาน',
        html: `
            <div style="text-align:left; font-size:14px;">
                <label style="font-weight:600; display:block; margin-bottom:4px;">Username</label>
                <input id="swal-username" class="swal2-input" style="margin:0 0 12px 0;"
                       value="${staff.username}" placeholder="Username">

                <label style="font-weight:600; display:block; margin-bottom:4px;">
                    ชื่อ-นามสกุล ${isLineLinked ? '<span style="color:#aaa;font-weight:400;">(ผูก LINE — แก้ไขไม่ได้)</span>' : ''}
                </label>
                <input id="swal-fullname" class="swal2-input" style="margin:0 0 12px 0;"
                       value="${staff.full_name ?? ''}" placeholder="ชื่อ-นามสกุล" ${isLineLinked ? 'disabled' : ''}>

                <label style="font-weight:600; display:block; margin-bottom:4px;">
                    ระดับสิทธิ์ ${isLineLinked ? '<span style="color:#aaa;font-weight:400;">(ผูก LINE — แก้ไขไม่ได้)</span>' : ''}
                </label>
                <select id="swal-role" class="swal2-select" style="margin:0 0 12px 0; width:100%;" ${isLineLinked ? 'disabled' : ''}>
                    ${roleOptions}
                </select>

                <label style="font-weight:600; display:block; margin-bottom:4px;">รหัสผ่านใหม่ <span style="color:#aaa;font-weight:400;">(เว้นว่างถ้าไม่เปลี่ยน)</span></label>
                <input id="swal-password" class="swal2-input" style="margin:0;" type="password" placeholder="รหัสผ่านใหม่">
            </div>`,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#3085d6',
        focusConfirm: false,
        preConfirm: () => {
            const username = document.getElementById('swal-username').value.trim();
            if (!username) {
                Swal.showValidationMessage('กรุณากรอก Username');
                return false;
            }
            const formData = new FormData();
            formData.append('user_id',      id);
            formData.append('username',     username);
            formData.append('full_name',    document.getElementById('swal-fullname').value.trim());
            formData.append('role',         document.getElementById('swal-role').value);
            formData.append('new_password', document.getElementById('swal-password').value.trim());

            return fetch('../process/edit_staff_process.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') Swal.showValidationMessage(data.message);
                    return data;
                })
                .catch(() => Swal.showValidationMessage('เกิดข้อผิดพลาดในการเชื่อมต่อ'));
        }
    }).then(result => {
        if (result.isConfirmed && result.value?.status === 'success') {
            Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลบัญชีพนักงานเรียบร้อย', 'success')
                .then(() => location.reload());
        }
    });
}
</script>

<?php include('../includes/footer.php'); ?>
