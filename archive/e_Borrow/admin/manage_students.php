<?php
// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php"); // (เธญเธฑเธเธเธตเนเธ–เธนเธเธ•เนเธญเธ เน€เธเธฃเธฒเธฐเธญเธขเธนเนเนเธ admin/ เน€เธซเธกเธทเธญเธเธเธฑเธ)
    exit;
}

// 3. (Query เธ—เธตเน 1) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธ (sys_users)
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
    $student_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธ: " . $e->getMessage();
    $students = [];
}

// 4. (Query เธ—เธตเน 2) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธเธฑเธเธเธฒเธ (sys_staff)
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
    $staff_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธเธฑเธเธเธฒเธ: " . $e->getMessage();
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

// 5. (เนเธเนเธ”เน€เธเนเธ $_GET)
$message = '';
$message_type = '';
if (isset($_GET['add']) && $_GET['add'] == 'success') {
    $message = 'เน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธเนเธซเธกเนเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} elseif (isset($_GET['edit']) && $_GET['edit'] == 'success') {
    $message = 'เนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} elseif (isset($_GET['delete']) && $_GET['delete'] == 'success') {
    $message = 'เธฅเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} elseif (isset($_GET['promote']) && $_GET['promote'] == 'success') {
    $message = 'เน€เธฅเธทเนเธญเธเธเธฑเนเธเธเธนเนเนเธเนเธเธฒเธเน€เธเนเธเธเธเธฑเธเธเธฒเธเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} elseif (isset($_GET['staff_op']) && $_GET['staff_op'] == 'success') {
    $message = 'เธ”เธณเน€เธเธดเธเธเธฒเธฃเธเธฑเธเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} elseif (isset($_GET['error'])) {
    $message_type = 'error';
    if ($_GET['error'] == 'fk_constraint') {
        $message = 'เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธเธเธนเนเนเธเนเธเธฒเธเนเธ”เน เน€เธเธทเนเธญเธเธเธฒเธเธกเธตเธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธ—เธณเธฃเธฒเธขเธเธฒเธฃเธเนเธฒเธเธญเธขเธนเน!';
    } else {
        $message = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ' . htmlspecialchars($_GET['error']);
    }
}


// 6. เธ•เธฑเนเธเธเนเธฒเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธ Header
$page_title = "เธเธฑเธ”เธเธฒเธฃเธเธนเนเนเธเนเธเธฒเธ";
$current_page = "manage_user";
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/header.php');
?>

<?php if ($message): ?>
    <div style="padding: 15px; margin-bottom: 20px; border-radius: 4px; color: #fff; background-color: <?php echo ($message_type == 'success') ? 'var(--color-success)' : 'var(--color-danger)'; ?>;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="section-card" style="margin-bottom: 1.5rem;">
    <h2 class="section-title">เธ เธฒเธเธฃเธงเธกเธชเธฑเธ”เธชเนเธงเธเธเธนเนเนเธเนเธเธฒเธ</h2>
    <div style="width: 100%; max-width: 400px; margin: 0 auto;">
        <canvas id="userRoleChart"></canvas>
    </div>
</div>

<div class="header-row" data-target="#userSectionContent">
    <h2><i class="fas fa-users"></i> ๐‘ฅ เธเธฑเธ”เธเธฒเธฃเธเธนเนเนเธเนเธเธฒเธ (User)</h2>
    <button type="button" class="collapse-toggle-btn">
        <i class="fas fa-chevron-down"></i>
        <i class="fas fa-chevron-up"></i>
    </button>
</div>

<div id="userSectionContent" class="collapsible-content">

    <div class="add-user-button-wrapper">
        <button class="add-btn" onclick="openAddStudentPopup()" style="background-color: var(--color-info);">
            <i class="fas fa-plus"></i> เน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธ (เนเธ”เธข Admin)
        </button>
    </div>

    <div class="table-container desktop-only" style="margin-bottom: 2rem;">
        <?php if (isset($student_error)) echo "<p style='color: red; padding: 15px;'>$student_error</p>"; ?>
        <table>
            <thead>
                <tr>
                    <th>เธเธทเนเธญ-เธชเธเธธเธฅ</th>
                    <th style="width: 20%;">เธฃเธซเธฑเธชเธเธนเนเนเธเนเธเธฒเธ/เธเธธเธเธฅเธฒเธเธฃ</th>
                    <th>เธชเธ–เธฒเธเธฐเธ เธฒเธ</th>
                    <th>เน€เธเธญเธฃเนเนเธ—เธฃ</th>
                    <th>เธฅเธเธ—เธฐเน€เธเธตเธขเธเนเธ”เธข</th>
                    <th>เธเธฑเธ”เธเธฒเธฃ</th>
                </tr>
            </thead>
          <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="6" style="text-align: center;">เธขเธฑเธเนเธกเนเธกเธตเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธเนเธเธฃเธฐเธเธ</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="truncate-text" title="<?php echo htmlspecialchars($student['full_name']); ?>"><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['student_personnel_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($student['status']) . ($student['status'] == 'other' ? ' (' . htmlspecialchars($student['status_other']) . ')' : ''); ?></td>
                            <td><?php echo htmlspecialchars($student['phone_number'] ?? '-'); ?></td>
                            <td><?php echo ($student['line_user_id']) ? '<span style="color: #00B900; font-weight: bold;">LINE</span>' : '<span style="color: #6c757d;">Admin</span>'; ?></td>
                            <td class="action-buttons">
                                <button type="button" class="btn btn-manage" onclick="openEditStudentPopup(<?php echo $student['id']; ?>)">เธ”เธน/เนเธเนเนเธ</button>
                                <?php if ($student['linked_user_id']): ?>
                                    <button type="button" class="btn btn-danger" onclick="confirmDemote(<?php echo $student['linked_user_id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')"><i class="fas fa-user-minus"></i> เธฅเธ”เธชเธดเธ—เธเธดเน</button>
                                <?php else: ?>
                                    <?php if (!empty($student['line_user_id'])): ?>
                                        <button type="button" class="btn btn-sm" style="background-color: #06c755; color: white;" onclick="openSendLinePopup(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')"><i class="fab fa-line"></i> เธชเนเธเธเนเธญเธเธงเธฒเธก</button>
                                        <button type="button" class="btn" style="background-color: #ffc107; color: #333;" onclick="openPromotePopup(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($student['line_user_id'])); ?>')"><i class="fas fa-user-shield"></i> เน€เธฅเธทเนเธญเธเธเธฑเนเธ</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-danger" style="margin-left: 5px;" onclick="confirmDeleteStudent(event, <?php echo $student['id']; ?>)">เธฅเธ</button>
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
                <p style="text-align: center; width: 100%;">เธขเธฑเธเนเธกเนเธกเธตเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธเนเธเธฃเธฐเธเธ</p>
            </div>
        <?php else: ?>
            <?php foreach ($students as $student): ?>
                <div class="history-card">
                    <div class="history-card-icon">
                        <?php if ($student['line_user_id']): ?>
                            <span class="status-badge green" title="เธฅเธเธ—เธฐเน€เธเธตเธขเธเธเนเธฒเธ LINE">
                                <i class="fab fa-line" style="font-size: 1.5rem;"></i>
                            </span>
                        <?php else: ?>
                            <span class="status-badge grey" title="Admin เน€เธเธดเนเธกเน€เธญเธ">
                                <i class="fas fa-user-shield"></i>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="history-card-info">
                        <h4 class="truncate-text" title="<?php echo htmlspecialchars($student['full_name']); ?>">
                            <?php echo htmlspecialchars($student['full_name']); ?>
                        </h4>
                        <p>
                            เธฃเธซเธฑเธช: <?php echo htmlspecialchars($student['student_personnel_id'] ?? '-'); ?>
                        </p>
                        <p style="font-size: 0.9em;">
                            เธชเธ–เธฒเธเธฐเธ เธฒเธ: <?php
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
                            <i class="fas fa-search"></i> เธ”เธน/เนเธเนเนเธ
                        </button>

                        <?php if (!$student['linked_user_id']): ?>
                            <button type="button"
                                class="btn btn-danger"
                                onclick="confirmDeleteStudent(event, <?php echo $student['id']; ?>)">
                                <i class="fas fa-trash"></i> เธฅเธ
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="header-row" data-target="#staffSectionContent">
    <h2><i class="fas fa-user-shield"></i> ๐ก๏ธ เธเธฑเธ”เธเธฒเธฃเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ (Admin/Employee)</h2>
    <button type="button" class="collapse-toggle-btn">
        <i class="fas fa-chevron-down"></i>
        <i class="fas fa-chevron-up"></i>
    </button>
</div>

<div id="staffSectionContent" class="collapsible-content">

    <div class="add-user-button-wrapper">
        <button class="add-btn" onclick="openAddStaffPopup()">
            <i class="fas fa-plus"></i> เน€เธเธดเนเธกเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ
        </button>
    </div>

    <div class="table-container">
        <?php if (isset($staff_error)) echo "<p style='color: red; padding: 15px;'>$staff_error</p>"; ?>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>เธเธทเนเธญ-เธชเธเธธเธฅ</th>
                    <th>เธชเธดเธ—เธเธดเน (Role)</th>
                    <th>เธชเธ–เธฒเธเธฐ</th>
                    <th>เธเธฑเธเธเธตเธ—เธตเนเน€เธเธทเนเธญเธกเนเธขเธ (LINE)</th>
                    <th>เธเธฑเธ”เธเธฒเธฃ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff_accounts)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center;">เนเธกเนเธกเธตเธเนเธญเธกเธนเธฅเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staff_accounts as $staff): ?>
                        <tr class="<?php if ($staff['id'] == $_SESSION['user_id']) echo 'current-user-row'; ?>">
                            <td>
                                <?php echo htmlspecialchars($staff['username']); ?>
                                <?php if ($staff['id'] == $_SESSION['user_id']) echo ' <strong>(เธเธธเธ“)</strong>'; ?>
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
                                    <span style="color: #00B900;" title="เธเธนเธเธเธฑเธ LINE ID: <?php echo htmlspecialchars($staff['linked_line_user_id']); ?>">
                                        <i class="fas fa-link"></i> <?php echo htmlspecialchars($staff['linked_student_name'] ?? 'N/A'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">(เนเธกเนเธกเธต)</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <button type="button"
                                    class="btn btn-manage"
                                    onclick="openEditStaffPopup(<?php echo $staff['id']; ?>)">เธ”เธน/เนเธเนเนเธ</button>

                                <?php if ($staff['id'] != $_SESSION['user_id']): // (เธเนเธญเธเธเธฑเธเธเธฒเธฃเธเธฃเธฐเธ—เธณเธเธฑเธเธ•เธฑเธงเน€เธญเธ) 
                                ?>

                                    <?php if ($staff['account_status'] == 'active'): ?>
                                        <button type="button"
                                            class="btn btn-disable"
                                            onclick="confirmToggleStaffStatus(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>', 'disabled')">
                                            <i class="fas fa-user-lock"></i> เธฃเธฐเธเธฑเธ
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            class="btn btn-borrow"
                                            onclick="confirmToggleStaffStatus(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>', 'active')">
                                            <i class="fas fa-user-check"></i> เน€เธเธดเธ”
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($staff['linked_line_user_id']): ?>
                                        <button type="button"
                                            class="btn btn-danger"
                                            onclick="confirmDemote(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>')">
                                            <i class="fas fa-user-minus"></i> เธฅเธ”เธชเธดเธ—เธเธดเน
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            class="btn btn-danger"
                                            onclick="confirmDeleteStaff(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>')">
                                            <i class="fas fa-trash"></i> เธฅเธเธเธฑเธเธเธต
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
    // โ… (เนเธเนเนเธ) เนเธเนเนเธ Path เธเธญเธ fetch เธ—เธฑเนเธเธซเธกเธ” (เธฅเธ ../ เธญเธญเธ) โ—€๏ธ

    function confirmDeleteStudent(event, id) {
        event.preventDefault();
        Swal.fire({
            title: "เธเธธเธ“เนเธเนเนเธเธซเธฃเธทเธญเนเธกเน?",
            text: "เธเธธเธ“เธเธณเธฅเธฑเธเธเธฐเธฅเธเธเธนเนเนเธเนเธเธฒเธเธเธตเน (เน€เธเธเธฒเธฐเธ—เธตเน Admin เน€เธเธดเนเธกเน€เธญเธ)",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "เนเธเน, เธฅเธเน€เธฅเธข",
            cancelButtonText: "เธขเธเน€เธฅเธดเธ"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('id', id);
                
                fetch('process/delete_student_process.php', { // โ…
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('เธฅเธเธชเธณเน€เธฃเนเธ!', 'เธเธนเนเนเธเนเธเธฒเธเธ–เธนเธเธฅเธเน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเนเธง', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”!', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” AJAX', error.message, 'error');
                });
            }
        });
    }

    function openAddStudentPopup() {
        Swal.fire({
            title: 'โ• เน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธ (เนเธ”เธข Admin)',
            html: `
            <form id="swalAddForm" style="text-align: left; margin-top: 20px;">
                <p>เธเธนเนเนเธเนเธเธฒเธเธ—เธตเนเน€เธเธดเนเธกเนเธ”เธข Admin เธเธฐเนเธกเนเธกเธต LINE ID เน€เธเธทเนเธญเธกเนเธขเธ</p>
                <div style="margin-bottom: 15px;">
                    <label for="swal_full_name" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธทเนเธญ-เธชเธเธธเธฅ: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_full_name" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_phone_number" style="font-weight: bold; display: block; margin-bottom: 5px;">เน€เธเธญเธฃเนเนเธ—เธฃ:</label>
                    <input type="text" name="phone_number" id="swal_phone_number" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                </form>`,
            showCancelButton: true,
            confirmButtonText: 'เธเธฑเธเธ—เธถเธ',
            cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
            confirmButtonColor: 'var(--color-success, #28a745)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalAddForm');
                const fullName = form.querySelector('#swal_full_name').value;
                if (!fullName) {
                    Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธ เธเธทเนเธญ-เธชเธเธธเธฅ เธเธนเนเนเธเนเธเธฒเธ');
                    return false;
                }
                
                return fetch('process/add_student_process.php', { // โ…
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ!', 'เน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธเนเธซเธกเนเน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเนเธง', 'success').then(() => location.href = 'admin/manage_students.php?add=success');
            }
        });
    }

    // (เธเธฑเธเธเนเธเธฑเธ Helper เนเธซเธกเน เธชเธณเธซเธฃเธฑเธ Popup "เนเธเนเนเธ" เน€เธเธทเนเธญเธเนเธญเธ/เนเธชเธ”เธ เธเนเธญเธ "เธญเธทเนเธเน")
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
            title: 'เธเธณเธฅเธฑเธเนเธซเธฅเธ”เธเนเธญเธกเธนเธฅ...',
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        fetch(`ajax/get_student_data.php?id=${studentId}`) // โ…
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                const student = data.student;

                const otherStatusDisplay = (student.status === 'other') ? 'block' : 'none';

                const formHtml = `
                <form id="swalEditStudentForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="student_id" value="${student.id}">
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_full_name" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธทเนเธญ-เธชเธเธธเธฅ: <span style="color:red;">*</span></label>
                        <input type="text" name="full_name" id="swal_edit_full_name" value="${student.full_name}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_department" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธ“เธฐ/เธซเธเนเธงเธขเธเธฒเธ:</label>
                        <input type="text" name="department" id="swal_edit_department" value="${student.department || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_status" style="font-weight: bold; display: block; margin-bottom: 5px;">เธชเธ–เธฒเธเธ เธฒเธ: <span style="color:red;">*</span></label>
                        <select name="status" id="swal_edit_status" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;" onchange="checkOtherStatusPopup(this.value)">
                            <option value="student" ${student.status === 'student' ? 'selected' : ''}>เธเธฑเธเธจเธถเธเธฉเธฒ</option>
                            <option value="teacher" ${student.status === 'teacher' ? 'selected' : ''}>เธญเธฒเธเธฒเธฃเธขเน</option>
                            <option value="staff" ${student.status === 'staff' ? 'selected' : ''}>เน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน</option>
                            <option value="other" ${student.status === 'other' ? 'selected' : ''}>เธญเธทเนเธเน</option>
                        </select>
                    </div>

                    <div class="form-group" id="other_status_group_popup" style="display: ${otherStatusDisplay}; margin-bottom: 15px;">
                        <label for="swal_edit_status_other" style="font-weight: bold; display: block; margin-bottom: 5px;">เนเธเธฃเธ”เธฃเธฐเธเธธ "เธญเธทเนเธเน":</label>
                        <input type="text" name="status_other" id="swal_edit_status_other" value="${student.status_other || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_student_id" style="font-weight: bold; display: block; margin-bottom: 5px;">เธฃเธซเธฑเธชเธเธนเนเนเธเนเธเธฒเธ/เธเธธเธเธฅเธฒเธเธฃ:</label>
                        <input type="text" name="student_personnel_id" id="swal_edit_student_id" value="${student.student_personnel_id || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="swal_edit_phone_number" style="font-weight: bold; display: block; margin-bottom: 5px;">เน€เธเธญเธฃเนเนเธ—เธฃ:</label>
                        <input type="text" name="phone_number" id="swal_edit_phone_number" value="${student.phone_number || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                </form>`;

                Swal.fire({
                    title: '๐”ง เนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธ',
                    html: formHtml,
                    showCancelButton: true,
                    confirmButtonText: 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธ',
                    cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
                    confirmButtonColor: 'var(--color-primary, #0B6623)',
                    focusConfirm: false,
                    preConfirm: () => {
                        const form = document.getElementById('swalEditStudentForm');
                        const fullName = form.querySelector('#swal_edit_full_name').value;
						if (/[<>]/.test(fullName)) {
        Swal.showValidationMessage('เนเธกเนเธญเธเธธเธเธฒเธ•เนเธซเนเนเธเนเธญเธฑเธเธเธฃเธฐเธเธดเน€เธจเธฉ เน€เธเนเธ < เธซเธฃเธทเธญ > เนเธเธเธทเนเธญ');
        return false;
    }
                        const status = form.querySelector('#swal_edit_status').value;
                        const statusOther = form.querySelector('#swal_edit_status_other').value;

                        if (!fullName || !status) {
                            Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธเธ—เธตเนเธกเธตเน€เธเธฃเธทเนเธญเธเธซเธกเธฒเธข * เนเธซเนเธเธฃเธเธ–เนเธงเธ');
                            return false;
                        }
                        if (status === 'other' && !statusOther) {
                            Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธฃเธฐเธเธธเธชเธ–เธฒเธเธ เธฒเธ "เธญเธทเนเธเน"');
                            return false;
                        }
                        
                        return fetch('process/edit_student_process.php', { // โ…
                                method: 'POST',
                                body: new FormData(form)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status !== 'success') throw new Error(data.message);
                                return data;
                            })
                            .catch(error => {
                                Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`);
                            });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire('เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ!', 'เนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธเน€เธฃเธตเธขเธเธฃเนเธญเธข', 'success').then(() => location.href = 'admin/manage_students.php?edit=success');
                    }
                });
            })
            .catch(error => {
                Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”', error.message, 'error');
            });
    }

    function openPromotePopup(studentId, studentName, lineId) {
        Swal.fire({
            title: 'เน€เธฅเธทเนเธญเธเธเธฑเนเธเธเธนเนเนเธเนเธเธฒเธ',
            html: `
            <p style="text-align: left;">เธเธธเธ“เธเธณเธฅเธฑเธเธเธฐเน€เธฅเธทเนเธญเธเธเธฑเนเธ <strong>${studentName}</strong> (เธ—เธตเนเธกเธต LINE ID) เนเธซเนเน€เธเนเธ "เธเธเธฑเธเธเธฒเธ"</p>
            <p style="text-align: left;">เธเธฃเธธเธ“เธฒเธชเธฃเนเธฒเธเธเธฑเธเธเธตเธชเธณเธซเธฃเธฑเธ Login (เน€เธเธทเนเธญเธเธฃเธ“เธตเธ—เธตเนเนเธกเนเนเธ”เนเน€เธเนเธฒเธเนเธฒเธ LINE):</p>
            
            <form id="swalPromoteForm" style="text-align: left; margin-top: 20px;">
                <input type="hidden" name="student_id_to_promote" value="${studentId}">
                <input type="hidden" name="line_user_id_to_link" value="${lineId}">
                
                <div style="margin-bottom: 15px;">
                    <label for="swal_username" style="font-weight: bold; display: block; margin-bottom: 5px;">1. Username (เธชเธณเธซเธฃเธฑเธ Login): <span style="color:red;">*</span></label>
                    <input type="text" name="new_username" id="swal_username" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_password" style="font-weight: bold; display: block; margin-bottom: 5px;">2. Password (เธเธฑเนเธงเธเธฃเธฒเธง): <span style="color:red;">*</span></label>
                    <input type="text" name="new_password" id="swal_password" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_role" style="font-weight: bold; display: block; margin-bottom: 5px;">3. เธชเธดเธ—เธเธดเน (Role): <span style="color:red;">*</span></label>
                    <select name="new_role" id="swal_role" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="employee">เธเธเธฑเธเธเธฒเธ (Employee)</option>
                        <option value="admin">เธเธนเนเธ”เธนเนเธฅเธฃเธฐเธเธ (Admin)</option>
                    </select>
                </div>
            </form>`,
            showCancelButton: true,
            confirmButtonText: 'เธขเธทเธเธขเธฑเธเธเธฒเธฃเน€เธฅเธทเนเธญเธเธเธฑเนเธ',
            cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
            confirmButtonColor: 'var(--color-warning, #ffc107)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalPromoteForm');
                const username = form.querySelector('#swal_username').value;
                const password = form.querySelector('#swal_password').value;
                if (!username || !password) {
                    Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธ Username เนเธฅเธฐ Password');
                    return false;
                }
                
                return fetch('process/promote_student_process.php', { // โ…
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('เน€เธฅเธทเนเธญเธเธเธฑเนเธเธชเธณเน€เธฃเนเธ!', 'เธเธนเนเนเธเนเธเธฒเธเธเธตเนเธเธฅเธฒเธขเน€เธเนเธเธเธเธฑเธเธเธฒเธเนเธฅเนเธง', 'success').then(() => location.href = 'admin/manage_students.php?promote=success');
            }
        });
    }

    function confirmDemote(userId, staffName) {
        Swal.fire({
            title: `เธเธธเธ“เนเธเนเนเธเธซเธฃเธทเธญเนเธกเน?`,
            text: `เธเธธเธ“เธเธณเธฅเธฑเธเธเธฐเธฅเธ”เธชเธดเธ—เธเธดเน ${staffName} เธเธฅเธฑเธเนเธเน€เธเนเธ "เธเธนเนเนเธเนเธเธฒเธ" เธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเธเธฐเธ–เธนเธเธฅเธ (เนเธ•เนเธขเธฑเธ Login LINE เนเธ”เน)`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "เนเธเน, เธฅเธ”เธชเธดเธ—เธเธดเนเน€เธฅเธข",
            cancelButtonText: "เธขเธเน€เธฅเธดเธ"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('user_id_to_demote', userId);
                
                fetch('process/demote_staff_process.php', { // โ…
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire('เธฅเธ”เธชเธดเธ—เธเธดเนเธชเธณเน€เธฃเนเธ!', data.message, 'success')
                                .then(() => location.href = 'admin/manage_students.php?staff_op=success');
                        } else {
                            Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”!', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” AJAX', error.message, 'error');
                    });
            }
        });
    }

    function confirmDeleteStaff(userId, staffName) {
        Swal.fire({
            title: `เธเธธเธ“เนเธเนเนเธเธซเธฃเธทเธญเนเธกเน?`,
            text: `เธเธธเธ“เธเธณเธฅเธฑเธเธเธฐเธฅเธเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ [${staffName}] เธญเธญเธเธเธฒเธเธฃเธฐเธเธเธญเธขเนเธฒเธเธ–เธฒเธงเธฃ (เธเธฐเธฅเธเนเธ”เนเธ•เนเธญเน€เธกเธทเนเธญเนเธกเนเธกเธตเธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธ—เธณเธฃเธฒเธขเธเธฒเธฃเธเนเธฒเธเธญเธขเธนเน)`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "เนเธเน, เธฅเธเธเธฑเธเธเธต",
            cancelButtonText: "เธขเธเน€เธฅเธดเธ"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('user_id_to_delete', userId);
                
                fetch('process/delete_staff_process.php', { // โ…
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire('เธฅเธเธชเธณเน€เธฃเนเธ!', data.message, 'success')
                                .then(() => location.href = 'admin/manage_students.php?staff_op=success');
                        } else {
                            Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”!', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” AJAX', error.message, 'error');
                    });
            }
        });
    }

    function openAddStaffPopup() {
        Swal.fire({
            title: 'โ• เน€เธเธดเนเธกเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเนเธซเธกเน',
            html: `
            <p style="text-align: left;">เธเธฑเธเธเธตเธเธตเนเธเธฐเนเธเนเธชเธณเธซเธฃเธฑเธ Login เนเธเธซเธเนเธฒ Admin/Employee (เธเธฐเนเธกเนเธ–เธนเธเธเธนเธเธเธฑเธ LINE)</p>
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
                    <label for="swal_s_fullname" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธทเนเธญ-เธชเธเธธเธฅ: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_s_fullname" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_role" style="font-weight: bold; display: block; margin-bottom: 5px;">เธชเธดเธ—เธเธดเน (Role): <span style="color:red;">*</span></label>
                    <select name="role" id="swal_s_role" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="employee">เธเธเธฑเธเธเธฒเธ (Employee)</option>
                        <option value="editor">เธเธเธฑเธเธเธฒเธ (Editor - เธเธฑเธ”เธเธฒเธฃเธญเธธเธเธเธฃเธ“เน)</option>
                        <option value="admin">เธเธนเนเธ”เธนเนเธฅเธฃเธฐเธเธ (Admin)</option>
                    </select>
                </div>
            </form>`,
            showCancelButton: true,
            confirmButtonText: 'เธเธฑเธเธ—เธถเธ',
            cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
            confirmButtonColor: 'var(--color-success, #28a745)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalAddStaffForm');
                if (!form.checkValidity()) {
                    Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ * เนเธซเนเธเธฃเธเธ–เนเธงเธ');
                    return false;
                }
                
                return fetch('process/add_staff_process.php', { // โ…
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ!', 'เน€เธเธดเนเธกเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเนเธซเธกเนเน€เธฃเธตเธขเธเธฃเนเธญเธข', 'success').then(() => location.href = 'admin/manage_students.php?staff_op=success');
            }
        });
    }

    function openEditStaffPopup(userId) {
        Swal.fire({
            title: 'เธเธณเธฅเธฑเธเนเธซเธฅเธ”เธเนเธญเธกเธนเธฅ...',
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch(`ajax/get_staff_data.php?id=${userId}`) // โ…
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                const staff = data.staff;

                const is_linked = staff.linked_line_user_id ? true : false;
                const disabled_attr = is_linked ? 'disabled' : '';
                const linked_warning = is_linked ? '<p style="color: #00B900; text-align: left;">(เธเธฑเธเธเธตเธเธตเนเธเธนเธเธเธฑเธ LINE เธเธถเธเนเธกเนเธชเธฒเธกเธฒเธฃเธ–เนเธเนเนเธเธเธทเนเธญเนเธฅเธฐเธชเธดเธ—เธเธดเนเนเธ”เนเธเธฒเธเธซเธเนเธฒเธเธตเน)</p>' : '';

                const formHtml = `
                <form id="swalEditStaffForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="user_id" value="${staff.id}">
                    ${linked_warning}
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_username" style="font-weight: bold; display: block; margin-bottom: 5px;">Username: <span style="color:red;">*</span></label>
                        <input type="text" name="username" id="swal_e_username" value="${staff.username}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_fullname" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธทเนเธญ-เธชเธเธธเธฅ: <span style="color:red;">*</span></label>
                        <input type="text" name="full_name" id="swal_e_fullname" value="${staff.full_name}" required ${disabled_attr} style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; background-color: ${is_linked ? '#f4f4f4' : '#fff'};">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_role" style="font-weight: bold; display: block; margin-bottom: 5px;">เธชเธดเธ—เธเธดเน (Role): <span style="color:red;">*</span></label>
                        <select name="role" id="swal_e_role" required ${disabled_attr} style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; background-color: ${is_linked ? '#f4f4f4' : '#fff'};">
                            <option value="employee" ${staff.role == 'employee' ? 'selected' : ''}>เธเธเธฑเธเธเธฒเธ (Employee)</option>
                            <option value="editor" ${staff.role == 'editor' ? 'selected' : ''}>เธเธเธฑเธเธเธฒเธ (Editor - เธเธฑเธ”เธเธฒเธฃเธญเธธเธเธเธฃเธ“เน)</option>
                            <option value="admin" ${staff.role == 'admin' ? 'selected' : ''}>เธเธนเนเธ”เธนเนเธฅเธฃเธฐเธเธ (Admin)</option>
                        </select>
                    </div>
                    <hr style="margin: 20px 0;">
                    <div style="margin-bottom: 15px;">
                        <label for="swal_e_password" style="font-weight: bold; display: block; margin-bottom: 5px;">Reset เธฃเธซเธฑเธชเธเนเธฒเธ (เธเธฃเธญเธเน€เธเธเธฒเธฐเน€เธกเธทเนเธญเธ•เนเธญเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธ):</label>
                        <input type="text" name="new_password" id="swal_e_password" placeholder="เธเธฃเธญเธเธฃเธซเธฑเธชเธเนเธฒเธเนเธซเธกเน" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                </form>`;

                Swal.fire({
                    title: '๐”ง เนเธเนเนเธเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ',
                    html: formHtml,
                    showCancelButton: true,
                    confirmButtonText: 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธ',
                    cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
                    confirmButtonColor: 'var(--color-primary, #0B6623)',
                    focusConfirm: false,
                    preConfirm: () => {
                        const form = document.getElementById('swalEditStaffForm');
                        if (!form.checkValidity()) {
                            Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ * เนเธซเนเธเธฃเธเธ–เนเธงเธ');
                            return false;
                        }
                        
                        return fetch('process/edit_staff_process.php', { // โ…
                                method: 'POST',
                                body: new FormData(form)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status !== 'success') throw new Error(data.message);
                                return data;
                            })
                            .catch(error => {
                                Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`);
                            });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire('เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ!', 'เนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธฑเธเธเธตเน€เธฃเธตเธขเธเธฃเนเธญเธข', 'success').then(() => location.href = 'admin/manage_students.php?staff_op=success');
                    }
                });
            })
            .catch(error => {
                Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”', error.message, 'error');
            });
    }

    function confirmToggleStaffStatus(userId, staffName, newStatus) {
        const actionText = (newStatus === 'disabled') ? 'เธฃเธฐเธเธฑเธเธเธฑเธเธเธต' : 'เน€เธเธดเธ”เนเธเนเธเธฒเธ';
        const actionIcon = (newStatus === 'disabled') ? 'warning' : 'info';
        const actionConfirmColor = (newStatus === 'disabled') ? '#dc3545' : '#17a2b8';

        Swal.fire({
            title: `เธขเธทเธเธขเธฑเธเธเธฒเธฃ${actionText}?`,
            text: `เธเธธเธ“เธเธณเธฅเธฑเธเธเธฐ${actionText}เธเธฑเธเธเธตเธเธญเธ ${staffName}`,
            icon: actionIcon,
            showCancelButton: true,
            confirmButtonColor: actionConfirmColor,
            cancelButtonColor: "#3085d6",
            confirmButtonText: `เนเธเน, ${actionText}`,
            cancelButtonText: "เธขเธเน€เธฅเธดเธ"
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('user_id', userId);
                formData.append('new_status', newStatus);

                fetch('process/toggle_staff_status.php', { // โ…
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire('เธชเธณเน€เธฃเนเธ!', data.message, 'success')
                                .then(() => location.href = 'admin/manage_students.php?staff_op=success');
                        } else {
                            Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”!', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” AJAX', error.message, 'error');
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
                    'เธเธฑเธเธจเธถเธเธฉเธฒ (student)',
                    'เธญเธฒเธเธฒเธฃเธขเน (teacher)',
                    'เน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน (staff)',
                    'เธญเธทเนเธเน (other)'
                ],
                datasets: [{
                    label: 'เธเธณเธเธงเธ (เธเธ)',
                    data: [
                        statusData.student,
                        statusData.teacher,
                        statusData.staff,
                        statusData.other
                    ],
                    backgroundColor: [
                        'rgba(22, 163, 74, 0.7)', /* เน€เธเธตเธขเธง */
                        'rgba(59, 130, 246, 0.7)', /* เธเนเธณเน€เธเธดเธ */
                        'rgba(249, 115, 22, 0.7)', /* เธชเนเธก */
                        'rgba(107, 114, 128, 0.7)' /* เน€เธ—เธฒ */
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
            title: 'เธชเนเธเธเนเธญเธเธงเธฒเธก LINE',
            html: `<p style="text-align:left; margin-bottom:10px;">เธ–เธถเธ: <strong>${studentName}</strong></p><textarea id="line_msg_text" class="swal2-textarea" placeholder="เธเธดเธกเธเนเธเนเธญเธเธงเธฒเธกเธ—เธตเนเธเธตเน..." style="margin: 0; width: 100%; height: 100px;"></textarea>`,
            showCancelButton: true, confirmButtonText: '<i class="fas fa-paper-plane"></i> เธชเนเธเน€เธฅเธข', cancelButtonText: 'เธขเธเน€เธฅเธดเธ', confirmButtonColor: '#06c755',
            preConfirm: () => {
                const message = document.getElementById('line_msg_text').value;
                if (!message) { Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธดเธกเธเนเธเนเธญเธเธงเธฒเธก'); return false; }
                const formData = new FormData();
                formData.append('student_id', studentId);
                formData.append('message', message);
                return fetch('process/admin_send_line_process.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => { if (d.status !== 'success') throw new Error(d.message); return d; }).catch(e => { Swal.showValidationMessage(e.message); });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ icon: 'success', title: 'เธชเนเธเน€เธฃเธตเธขเธเธฃเนเธญเธข!', text: 'เธเนเธญเธเธงเธฒเธกเธ–เธนเธเธชเนเธเนเธเธขเธฑเธเธเธนเนเนเธเนเนเธฅเนเธง', timer: 1500, showConfirmButton: false });
            }
        });
    }
</script>

<?php
// 7. เน€เธฃเธตเธขเธเนเธเน Footer
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/footer.php');
?>
