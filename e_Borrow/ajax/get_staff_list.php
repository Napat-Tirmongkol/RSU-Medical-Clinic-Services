<?php
/**
 * archive/e_Borrow/ajax/get_staff_list.php
 * ดึงรายชื่อเจ้าหน้าที่ (Admin/Employee) จากตาราง sys_staff
 */
declare(strict_types=1);
@session_start();

// 1. ตรวจสอบการอนุญาต (Session Check)
// หากคุณต้องการให้เฉพาะนักศึกษาที่ล็อกอินแล้วเรียกได้ ให้เปิดบรรทัดนี้ครับ:
// include('../includes/check_student_session_ajax.php'); 

// 2. ตั้งค่า Header สำหรับ JSON และ UTF-8
header('Content-Type: application/json; charset=utf-8');

// 3. เชื่อมต่อฐานข้อมูล (พาธกระโดดออกไป 3 ชั้นจาก ajax/ ไปถึง root)
$dbPath = __DIR__ . '/../../config/db_connect.php';

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration Error: Database connector not found.']);
    exit;
}

require_once $dbPath;

try {
    // 4. เริ่มต้นการเชื่อมต่อฐานข้อมูล
    $pdo = db();

    /**
     * 5. ดึงข้อมูลจากตาราง sys_staff
     * - คอลัมน์ id, full_name
     * - เงื่อนไข account_status = 'active'
     */
    $sql = "SELECT id, full_name FROM sys_staff WHERE account_status = 'active' ORDER BY full_name ASC";
    $stmt = $pdo->query($sql);
    $staffList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /**
     * 6. ส่งข้อมูลกลับไปยัง Frontend
     * (หมายเหตุ: ใส่ Key ทั้ง 'staff' และ 'data' เพื่อรองรับทั้ง student_app.js เวอร์ชั่นเก่าและใหม่)
     */
    echo json_encode([
        'status'  => 'success',
        'success' => true,
        'staff'   => $staffList,
        'data'    => $staffList
    ]);

} catch (PDOException $e) {
    // 7. จัดการข้อผิดพลาด (Exception Handling)
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'success' => false,
        'message' => 'Database Error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'success' => false,
        'message' => 'System Error: ' . $e->getMessage()
    ]);
}
exit;
?>