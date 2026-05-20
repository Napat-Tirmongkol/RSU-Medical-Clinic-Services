<?php
/**
 * Backfill: Create user_vaccination_records entries for previously-completed
 * vaccine campaign bookings that don't have a record yet.
 *
 * Idempotent — safe to run multiple times.
 *
 * Run: php database/migrations/backfill_vaccination_records.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/vaccination_helper.php';

$pdo = db();

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_vaccination_records'");
    if (!$tableCheck || !$tableCheck->fetch()) {
        fwrite(STDERR, "Error: user_vaccination_records table is missing. Run migrate_user_vaccination_records.php first.\n");
        exit(1);
    }

    // Match the relaxed helper condition: backfill for any vaccine booking
    // that either reached the formal `completed` status OR has attended_at
    // stamped (which is what the real check-in flow actually does).
    $stmt = $pdo->query("
        SELECT b.id
        FROM camp_bookings b
        JOIN camp_list c ON b.campaign_id = c.id
        LEFT JOIN user_vaccination_records v ON v.campaign_booking_id = b.id
        WHERE c.type = 'vaccine'
          AND (b.status = 'completed' OR b.attended_at IS NOT NULL)
          AND v.id IS NULL
        ORDER BY b.id ASC
    ");
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $created = 0;
    foreach ($ids as $bid) {
        if (record_vaccination_from_booking($pdo, $bid)) {
            $created++;
        }
    }

    echo "Backfill complete. Candidates: " . count($ids) . " · Inserted: {$created}\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}
