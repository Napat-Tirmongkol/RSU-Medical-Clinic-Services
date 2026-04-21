<?php
// save_profile.php
// บันทึกข้อมูลโปรไฟล์ลง DB

session_start();
require_once('../includes/line_config.php');
require_once(__DIR__ . '/../includes/db_connect.php'); //

if (!isset($_POST['terms_agree']) || $_POST['terms_agree'] != 'yes') {
    // (ถ้าไม่ยอมรับ ให้เด้งกลับไปหน้าเดิมพร้อม Error)
    $_SESSION['form_error'] = 'กรุณากดยอมรับข้อตกลงการใช้งานก่อนดำเนินการต่อ';
    header("Location: ../create_profile.php");
    exit;
}
// 1. "ยามเฝ้าประตู"
//    ต้องมี line_id_to_register ใน Session เท่านั้น
if (!isset($_SESSION['line_id_to_register']) || $_SERVER["REQUEST_METHOD"] != "POST") {
    // ถ้าไม่มี Session หรือไม่ได้ส่งแบบ POST มา ให้เด้งกลับ
    header("Location: ../login.php");
    exit;
}

// 2. ดึง line_user_id ที่เก็บไว้ใน Session
$line_user_id = $_SESSION['line_id_to_register'];

// 3. รับข้อมูลจากฟอร์ม
$full_name = trim($_POST['full_name']);
$department = trim($_POST['department']);
$status = trim($_POST['status']);
$status_other = ($status == 'other') ? trim($_POST['status_other']) : null;
$student_personnel_id = trim($_POST['student_personnel_id']);
$phone_number = trim($_POST['phone_number']);

// 4. ตรวจสอบข้อมูลบังคับ
if (empty($full_name) || empty($status) || ($status == 'other' && empty($status_other))) {
    die("ข้อมูลบังคับ (ชื่อ-สกุล, สถานภาพ) ไม่ครบถ้วน <a href='create_profile.php'>กลับไปกรอกใหม่</a>");
}

// 5. บันทึกลงฐานข้อมูล (sys_users)
try {
    $sql = "INSERT INTO sys_users 
                (line_user_id, full_name, department, status, status_other, student_personnel_id, phone_number) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $line_user_id,
        $full_name,
        $department,
        $status,
        $status_other,
        $student_personnel_id,
        $phone_number
    ]);

    // 6. บันทึกสำเร็จ!
    //    ดึง ID ที่เพิ่งสร้างขึ้นมา
    $new_student_id = $pdo->lastInsertId();

    // 7. สร้าง Session จริงสำหรับผู้ใช้งาน
    $_SESSION['student_id'] = $new_student_id; // (ห้ามเปลี่ยนชื่อตัวแปรนี้)
    $_SESSION['student_line_id'] = $line_user_id;
    $_SESSION['student_full_name'] = $full_name;
    $_SESSION['user_role'] = $status; // (อันนี้คือสถานภาพ เช่น student, teacher ไม่ใช่ role employee)

    // 8. ล้าง Session ชั่วคราว
    unset($_SESSION['line_id_to_register']);
    unset($_SESSION['line_name_to_register']);

    // 9. ส่งไปหน้า Dashboard ของผู้ใช้งาน
    header("Location: ../index.php"); 
    exit;

} catch (PDOException $e) {
    // (กรณีพยายามลงทะเบียนซ้ำด้วย LINE ID เดียวกัน)
    if ($e->getCode() == '23000') {
         die("เกิดข้อผิดพลาด: LINE User ID นี้ถูกลงทะเบียนในระบบแล้ว <a href='../login.php'>กลับไปหน้า Login</a>");
    } else {
         error_log("eborrow save_profile error: " . $e->getMessage()); exit("เกิดข้อผิดพลาด กรุณาลองใหม่");
    }
}
?>
