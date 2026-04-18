<?php
// includes/log_function.php

if (!function_exists('log_action')) {
    /**
     * �ѧ��ѹ����Ѻ�ѹ�֡��á�зӢͧ Admin/Staff ŧ㹵��ҧ sys_activity_logs
     *
     * @param PDO $pdo 
     * @param int $user_id (ID �ͧ Admin/Staff �����ѧ Log in (�ҡ $_SESSION['user_id']))
     * @param string $action ��������á�з� (�� 'create_equipment', 'delete_user')
     * @param string $description ��������´ (�� "Admin 'napat' ��ź�ػ�ó� 'Wheelchair (WC-009)'")
     */
    function log_action($pdo, $user_id, $action, $description) {
        try {
            $sql = "INSERT INTO sys_activity_logs (user_id, action, description) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $action, $description]);
        } catch (PDOException $e) {
            // (�ҡ��úѹ�֡ Log ������� ��������� �������˹�������ѡ���)
            error_log("Failed to write to sys_activity_logs: " . $e->getMessage()); // ?? (���)
        }
    }
}
?>
