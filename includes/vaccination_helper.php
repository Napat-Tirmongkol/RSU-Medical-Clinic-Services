<?php
declare(strict_types=1);

/**
 * Auto-create user_vaccination_records entry from a completed vaccine campaign booking.
 *
 * Idempotent — relies on campaign_booking_id; will not insert if a record for this
 * booking already exists.
 *
 * Returns true if a new record was created; false otherwise (not a vaccine, already
 * recorded, or error). Errors are logged but never thrown.
 */
function record_vaccination_from_booking(PDO $pdo, int $bookingId): bool
{
    if ($bookingId <= 0) return false;

    try {
        $stmt = $pdo->prepare("
            SELECT b.id, b.student_id, b.campaign_id, b.attended_at, b.status,
                   c.title, c.type
            FROM camp_bookings b
            JOIN camp_list c ON b.campaign_id = c.id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $bookingId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$b) return false;
        if (($b['type'] ?? '') !== 'vaccine') return false;
        if (($b['status'] ?? '') !== 'completed') return false;

        $check = $pdo->prepare("
            SELECT id FROM user_vaccination_records
            WHERE campaign_booking_id = :bid
            LIMIT 1
        ");
        $check->execute([':bid' => $bookingId]);
        if ($check->fetch()) return false;

        $vaccinatedAt = !empty($b['attended_at']) ? $b['attended_at'] : date('Y-m-d H:i:s');

        $ins = $pdo->prepare("
            INSERT INTO user_vaccination_records
                (user_id, campaign_booking_id, vaccine_name, vaccinated_at, status, created_at, updated_at)
            VALUES
                (:uid, :bid, :name, :at, 'completed', NOW(), NOW())
        ");
        $ins->execute([
            ':uid'  => (int)$b['student_id'],
            ':bid'  => (int)$b['id'],
            ':name' => (string)$b['title'],
            ':at'   => $vaccinatedAt,
        ]);

        return true;
    } catch (PDOException $e) {
        error_log('record_vaccination_from_booking error: ' . $e->getMessage());
        return false;
    }
}
