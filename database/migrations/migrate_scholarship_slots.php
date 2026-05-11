<?php
/**
 * database/migrations/migrate_scholarship_slots.php
 * เพิ่มระบบ "เปิดรอบงานให้นักศึกษาทุนจอง" คล้าย Campaign Time Slots
 *
 *   sys_scholarship_slots         — รอบที่ admin เปิดให้จอง (slot_date + start/end + max_capacity)
 *   sys_scholarship_slot_bookings — การจองของนักศึกษา (slot_id + student_id, UNIQUE)
 *
 * เพิ่ม column cancel_cutoff_hours ใน sys_scholarship_settings (default 24)
 * เพิ่ม column slot_id ใน sys_scholarship_shifts (FK กลับไป slot ตอน auto-create shift)
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_slots (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slot_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        max_capacity INT UNSIGNED NOT NULL DEFAULT 1,
        comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours',
        notes VARCHAR(255) NOT NULL DEFAULT '',
        status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_date (slot_date),
        KEY idx_status (status),
        KEY idx_date_status (slot_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ [OK] sys_scholarship_slots\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_slot_bookings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slot_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        shift_id INT UNSIGNED NULL,
        status ENUM('booked','cancelled') NOT NULL DEFAULT 'booked',
        booked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cancelled_at DATETIME NULL,
        cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_slot_student (slot_id, student_id),
        KEY idx_student (student_id),
        KEY idx_status (status),
        CONSTRAINT fk_slot_booking_slot FOREIGN KEY (slot_id)
            REFERENCES sys_scholarship_slots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ [OK] sys_scholarship_slot_bookings\n";

    // เพิ่ม cancel_cutoff_hours ใน settings (default 24 ชม.)
    $cols = $pdo->query("DESCRIBE sys_scholarship_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cancel_cutoff_hours', $cols, true)) {
        $pdo->exec("ALTER TABLE sys_scholarship_settings
            ADD COLUMN cancel_cutoff_hours INT UNSIGNED NOT NULL DEFAULT 24");
        echo "✅ [Added] cancel_cutoff_hours (default 24)\n";
    } else {
        echo "ℹ️  [Skip] cancel_cutoff_hours มีอยู่แล้ว\n";
    }

    // เพิ่ม slot_id ใน sys_scholarship_shifts (FK กลับไปยัง slot ที่เป็นแหล่งที่มา)
    $shiftCols = $pdo->query("DESCRIBE sys_scholarship_shifts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('slot_id', $shiftCols, true)) {
        $pdo->exec("ALTER TABLE sys_scholarship_shifts
            ADD COLUMN slot_id INT UNSIGNED NULL AFTER student_id,
            ADD KEY idx_slot (slot_id)");
        echo "✅ [Added] sys_scholarship_shifts.slot_id\n";
    } else {
        echo "ℹ️  [Skip] sys_scholarship_shifts.slot_id มีอยู่แล้ว\n";
    }

    echo "\n✨ Migration เสร็จเรียบร้อย\n";
} catch (PDOException $e) {
    fwrite(STDERR, "❌ [Error] " . $e->getMessage() . "\n");
    exit(1);
}
