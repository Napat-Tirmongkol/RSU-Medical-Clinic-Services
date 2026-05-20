<?php
declare(strict_types=1);

/**
 * Compute the smart next_due_date for a vaccination event.
 *
 * Returns Y-m-d string or null. Rule set, tightest first:
 *   - interval_days NULL or <= 0  → null (no recurrence defined)
 *   - default_doses == 1          → vaccinated_at + interval_days
 *                                   (recurring shot like seasonal flu)
 *   - default_doses > 1:
 *       - dose_number < default_doses → vaccinated_at + interval_days
 *       - dose_number >= default_doses → null (series complete)
 *
 * Called from record_vaccination_from_booking() for new check-ins and from
 * the Backfill button's Step 4 sweep for existing records.
 */
function compute_vaccine_next_due(string $vaccinatedAt, ?int $intervalDays, ?int $defaultDoses, ?int $doseNumber): ?string
{
    if ($intervalDays === null || $intervalDays <= 0) return null;
    $doses = ($defaultDoses === null || $defaultDoses < 1) ? 1 : $defaultDoses;
    if ($doses > 1 && $doseNumber !== null && $doseNumber >= $doses) return null;

    try {
        $d = new DateTime($vaccinatedAt);
        $d->modify('+' . (int)$intervalDays . ' days');
        return $d->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

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
        // Pull the booking + campaign + (optionally) the catalog row the
        // campaign is linked to. LEFT JOIN sys_vaccine_types so legacy
        // campaigns without a vaccine_type_id still work — we just won't
        // pre-fill catalog-derived fields.
        $stmt = $pdo->prepare("
            SELECT b.id, b.student_id, b.campaign_id, b.attended_at, b.status,
                   c.title, c.type, c.vaccine_type_id,
                   t.default_manufacturer, t.default_doses, t.interval_days
            FROM camp_bookings b
            JOIN camp_list c ON b.campaign_id = c.id
            LEFT JOIN sys_vaccine_types t ON t.id = c.vaccine_type_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $bookingId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$b) return false;
        if (($b['type'] ?? '') !== 'vaccine') return false;
        // Originally fired only when status === 'completed'. In production the
        // check-in flow stamps attended_at but never flips status off
        // 'confirmed' (audited: 562/575 attendees stayed at confirmed with
        // attended_at set; only 13/575 were ever marked completed). Accept
        // either signal so the auto-record fires on real-world flows.
        $isAttended  = !empty($b['attended_at']);
        $isCompleted = (($b['status'] ?? '') === 'completed');
        if (!$isAttended && !$isCompleted) return false;

        $check = $pdo->prepare("
            SELECT id FROM user_vaccination_records
            WHERE campaign_booking_id = :bid
            LIMIT 1
        ");
        $check->execute([':bid' => $bookingId]);
        if ($check->fetch()) return false;

        $vaccinatedAt = !empty($b['attended_at']) ? $b['attended_at'] : date('Y-m-d H:i:s');

        // Pre-fill catalog-derived fields when the campaign is linked. NULLs
        // are fine — admin can fill them in later via the dashboard edit modal
        // or the bulk-apply action.
        $vaccineTypeId = !empty($b['vaccine_type_id']) ? (int)$b['vaccine_type_id'] : null;
        $manufacturer  = !empty($b['default_manufacturer']) ? (string)$b['default_manufacturer'] : null;
        $defaultDoses  = isset($b['default_doses']) ? (int)$b['default_doses'] : null;
        $intervalDays  = isset($b['interval_days']) ? (int)$b['interval_days'] : null;

        // Dose number = 1 + how many existing completed records this user has
        // for this vaccine type. Only meaningful when vaccine_type_id is set.
        $doseNumber = null;
        if ($vaccineTypeId !== null) {
            $cnt = $pdo->prepare("
                SELECT COUNT(*) FROM user_vaccination_records
                WHERE user_id = :uid AND vaccine_type_id = :vtid AND status = 'completed'
            ");
            $cnt->execute([':uid' => (int)$b['student_id'], ':vtid' => $vaccineTypeId]);
            $doseNumber = ((int)$cnt->fetchColumn()) + 1;
        }

        $nextDue = compute_vaccine_next_due($vaccinatedAt, $intervalDays, $defaultDoses, $doseNumber);

        $ins = $pdo->prepare("
            INSERT INTO user_vaccination_records
                (user_id, campaign_booking_id, vaccine_type_id, vaccine_name,
                 manufacturer, dose_number, next_due_date,
                 vaccinated_at, status, created_at, updated_at)
            VALUES
                (:uid, :bid, :vtid, :name, :mfr, :dose, :due,
                 :at, 'completed', NOW(), NOW())
        ");
        $ins->execute([
            ':uid'  => (int)$b['student_id'],
            ':bid'  => (int)$b['id'],
            ':vtid' => $vaccineTypeId,
            ':name' => (string)$b['title'],
            ':mfr'  => $manufacturer,
            ':dose' => $doseNumber,
            ':due'  => $nextDue,
            ':at'   => $vaccinatedAt,
        ]);

        return true;
    } catch (PDOException $e) {
        error_log('record_vaccination_from_booking error: ' . $e->getMessage());
        return false;
    }
}
