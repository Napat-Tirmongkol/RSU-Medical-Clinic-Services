<?php
/**
 * Migration: Create user vaccination records table.
 * Run once from CLI:
 * php database/migrations/migrate_user_vaccination_records.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';

$pdo = db();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_vaccination_records (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            campaign_booking_id INT(11) NULL DEFAULT NULL,
            legacy_appointment_id INT(11) NULL DEFAULT NULL,
            vaccine_id INT(11) NULL DEFAULT NULL,
            vaccine_name VARCHAR(255) NOT NULL,
            dose_number TINYINT UNSIGNED NULL DEFAULT NULL,
            lot_number VARCHAR(100) NULL DEFAULT NULL,
            manufacturer VARCHAR(150) NULL DEFAULT NULL,
            vaccinated_at DATETIME NOT NULL,
            injection_site VARCHAR(100) NULL DEFAULT NULL,
            provider_name VARCHAR(255) NULL DEFAULT NULL,
            location VARCHAR(255) NULL DEFAULT NULL,
            next_due_date DATE NULL DEFAULT NULL,
            certificate_no VARCHAR(100) NULL DEFAULT NULL,
            certificate_file VARCHAR(500) NULL DEFAULT NULL,
            status ENUM('completed','cancelled','entered_in_error') NOT NULL DEFAULT 'completed',
            notes TEXT NULL DEFAULT NULL,
            recorded_by INT(11) NULL DEFAULT NULL,
            updated_by INT(11) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_vaccinated_at (user_id, vaccinated_at),
            INDEX idx_vaccine_id (vaccine_id),
            INDEX idx_campaign_booking (campaign_booking_id),
            INDEX idx_legacy_appointment (legacy_appointment_id),
            INDEX idx_status (status),
            INDEX idx_next_due_date (next_due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    echo "Migration complete: user_vaccination_records table is ready.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
