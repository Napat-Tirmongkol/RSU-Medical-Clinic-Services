<?php
/**
 * database/migrations/migrate_vitals_bp.php
 *
 * Blood pressure logbook — single table for staff-recorded BP readings
 * linked back to sys_users so we can plot per-patient trend.
 *
 * Classification follows the AHA 2017 guidelines:
 *   normal           SBP <120 AND DBP <80
 *   elevated         SBP 120-129 AND DBP <80
 *   stage1           SBP 130-139 OR DBP 80-89
 *   stage2           SBP 140-179 OR DBP 90-119
 *   crisis           SBP >=180 OR DBP >=120
 *
 * Idempotent. Run via:  php database/migrations/migrate_vitals_bp.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$pdo = db();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_vitals_bp (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        systolic  SMALLINT UNSIGNED NOT NULL COMMENT 'mmHg, top number',
        diastolic SMALLINT UNSIGNED NOT NULL COMMENT 'mmHg, bottom number',
        pulse_rate SMALLINT UNSIGNED NULL COMMENT 'bpm (optional)',
        measured_at DATETIME NOT NULL,
        position ENUM('sitting','standing','lying') NULL DEFAULT 'sitting',
        arm ENUM('left','right') NULL,
        notes VARCHAR(500) NULL,
        classification ENUM('normal','elevated','stage1','stage2','crisis') NULL,
        recorded_by INT UNSIGNED NULL,
        recorded_by_name VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_patient_date (patient_id, measured_at DESC),
        KEY idx_measured_at (measured_at DESC),
        KEY idx_classification (classification, measured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ sys_vitals_bp\n\nDone.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
