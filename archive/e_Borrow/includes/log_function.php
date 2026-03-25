<?php
// includes/log_function.php

if (!function_exists('log_action')) {
    /**
     * ฟังก์ชันสำหรับบันทึกการกระทำของ Admin/Staff ลงในตาราง sys_activity_logs
     *
     * @param PDO $pdo 
     * @param int $user_id (ID ของ Admin/Staff ที่กำลัง Log in (จาก $_SESSION['user_id']))
     * @param string $action ประเภทการกระทำ (เช่น 'create_equipment', 'delete_user')
     * @param string $description รายละเอียด (เช่น "Admin 'napat' ได้ลบอุปกรณ์ 'Wheelchair (WC-009)'")
     */
    function log_action($pdo, $user_id, $action, $description) {
        try {
            $sql = "INSERT INTO sys_activity_logs (user_id, action, description) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $action, $description]);
        } catch (PDOException $e) {
            // (หากการบันทึก Log ล้มเหลว ก็ไม่เป็นไร อย่าให้หน้าเว็บหลักล่ม)
            error_log("Failed to write to sys_activity_logs: " . $e->getMessage()); // ◀️ (แก้ไข)
        }
    }
}
?>
