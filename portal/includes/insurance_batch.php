<?php
/**
 * portal/includes/insurance_batch.php
 *
 * Helpers for insurance batch workflow tracking.
 * Used by upload (insurance_sync) and partner export/import flows.
 */
declare(strict_types=1);

if (!function_exists('ins_batch_status_labels')) {
    function ins_batch_status_labels(): array
    {
        return [
            'uploaded'        => ['อัพโหลดแล้ว',         '#06b6d4', 'cloud-arrow-up'],
            'pending_review'  => ['รอคลินิกตรวจสอบ',    '#f59e0b', 'hourglass-half'],
            'approved'        => ['อนุมัติแล้ว',         '#10b981', 'circle-check'],
            'rejected'        => ['ตีกลับ',              '#ef4444', 'circle-xmark'],
            'downloaded'      => ['ส่งให้ประกันแล้ว',    '#3b82f6', 'paper-plane'],
            'in_progress'     => ['กำลังออกกรมธรรม์',   '#8b5cf6', 'gears'],
            'partial'         => ['ออกกรมธรรม์บางส่วน', '#a855f7', 'chart-pie'],
            'completed'       => ['เสร็จสิ้น',           '#059669', 'circle-check-double'],
            'cancelled'       => ['ยกเลิก',              '#64748b', 'ban'],
        ];
    }
}

/**
 * Stages ที่แสดงบน stepper (ตาม diagram)
 */
if (!function_exists('ins_batch_stepper_stages')) {
    function ins_batch_stepper_stages(): array
    {
        return [
            'uploaded'      => ['อัพโหลด',               'cloud-arrow-up'],
            'pending_review'=> ['รอคลินิกตรวจ',         'magnifying-glass'],
            'approved'      => ['อนุมัติ',               'stamp'],
            'downloaded'    => ['ส่งให้ประกัน',          'paper-plane'],
            'in_progress'   => ['ออกกรมธรรม์',           'gears'],
            'completed'     => ['เสร็จสิ้น',             'circle-check'],
        ];
    }
}

/**
 * Determine which stage index (0-based) the batch is at
 */
if (!function_exists('ins_batch_stage_index')) {
    function ins_batch_stage_index(string $status): int
    {
        $order = [
            'uploaded'       => 0,
            'pending_review' => 1,
            'approved'       => 2,
            'rejected'       => 2,    // shown at "approved" position but red
            'downloaded'     => 3,
            'in_progress'    => 4,
            'partial'        => 4,
            'completed'      => 5,
            'cancelled'      => -1,
        ];
        return $order[$status] ?? 0;
    }
}

/**
 * Generate human-readable batch code: BATCH-YYYYMMDD-NNN
 *
 * Uses MAX(suffix) instead of COUNT(*) so deleted batches don't cause collisions.
 * Caller MUST handle UNIQUE constraint violation (SQLSTATE 23000) by retrying —
 * concurrent uploads on the same day can still race between SELECT and INSERT.
 */
if (!function_exists('ins_batch_generate_code')) {
    function ins_batch_generate_code(PDO $pdo): string
    {
        $today  = date('Ymd');
        $prefix = "BATCH-{$today}-";
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(batch_code, :len) AS UNSIGNED)), 0)
            FROM insurance_batch
            WHERE batch_code LIKE :p
        ");
        $stmt->execute([':len' => strlen($prefix) + 1, ':p' => $prefix . '%']);
        $n = ((int)$stmt->fetchColumn()) + 1;
        return sprintf('BATCH-%s-%03d', $today, $n);
    }
}

/**
 * Append an event to the batch timeline
 */
if (!function_exists('ins_batch_log_event')) {
    function ins_batch_log_event(
        PDO $pdo,
        int $batchId,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        string $actorType = 'system',
        ?int $actorId = null,
        ?string $actorName = null,
        ?string $details = null
    ): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO insurance_batch_event
                    (batch_id, event_type, from_status, to_status, actor_type,
                     actor_id, actor_name, details, ip_address)
                VALUES
                    (:bid, :et, :fs, :ts, :at, :aid, :an, :d, :ip)
            ");
            $stmt->execute([
                ':bid' => $batchId,
                ':et'  => $eventType,
                ':fs'  => $fromStatus,
                ':ts'  => $toStatus,
                ':at'  => $actorType,
                ':aid' => $actorId,
                ':an'  => $actorName,
                ':d'   => $details,
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log('ins_batch_log_event: ' . $e->getMessage());
        }
    }
}

/**
 * Recalculate cached counts (members_with_policy, members_inserted, etc.)
 * and auto-promote status when fully completed.
 */
if (!function_exists('ins_batch_recalc')) {
    function ins_batch_recalc(PDO $pdo, int $batchId): void
    {
        $batch = $pdo->prepare("SELECT * FROM insurance_batch WHERE id = :id");
        $batch->execute([':id' => $batchId]);
        $b = $batch->fetch(PDO::FETCH_ASSOC);
        if (!$b) return;

        $sid = (int)$b['sync_id'];

        // Total members in this batch (any change_type)
        $totalStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT member_id) FROM insurance_member_history WHERE sync_id = :sid
        ");
        $totalStmt->execute([':sid' => $sid]);
        $total = (int)$totalStmt->fetchColumn();

        // Members in this batch that already have policy_number
        $withPolicy = $pdo->prepare("
            SELECT COUNT(*) FROM insurance_members m
            WHERE m.member_id IN (
                SELECT DISTINCT member_id FROM insurance_member_history WHERE sync_id = :sid
            )
            AND m.policy_number IS NOT NULL AND m.policy_number <> ''
        ");
        $withPolicy->execute([':sid' => $sid]);
        $withPolicyCount = (int)$withPolicy->fetchColumn();

        // Promote status
        $newStatus = $b['status'];
        $now = date('Y-m-d H:i:s');
        $extraSet = '';
        if (in_array($b['status'], ['downloaded', 'in_progress', 'partial', 'approved'], true)
            && $total > 0 && $withPolicyCount > 0) {
            if ($withPolicyCount >= $total) {
                $newStatus = 'completed';
                $extraSet = ", completed_at = COALESCE(completed_at, '$now')";
            } else {
                $newStatus = 'partial';
            }
        }

        $pdo->prepare("
            UPDATE insurance_batch
            SET total_members = :total,
                members_with_policy = :wp,
                status = :st
                $extraSet
            WHERE id = :id
        ")->execute([
            ':total' => $total,
            ':wp'    => $withPolicyCount,
            ':st'    => $newStatus,
            ':id'    => $batchId,
        ]);

        if ($newStatus !== $b['status']) {
            ins_batch_log_event($pdo, $batchId, 'status_auto_change', $b['status'], $newStatus,
                'system', null, null,
                "auto-promoted: {$withPolicyCount}/{$total} have policy");
        }
    }
}

/**
 * ดึง batch_id จาก member_id (ใช้ตอน partner import policy)
 * เลือก batch ที่ approve/downloaded ล่าสุดของ company นั้น
 */
if (!function_exists('ins_batch_find_for_member')) {
    function ins_batch_find_for_member(PDO $pdo, string $memberId, string $companyCode): ?int
    {
        $stmt = $pdo->prepare("
            SELECT b.id
            FROM insurance_batch b
            JOIN insurance_member_history h ON h.sync_id = b.sync_id
            WHERE h.member_id = :mid
              AND b.insurance_company = :cc
              AND b.status IN ('approved', 'downloaded', 'in_progress', 'partial')
            ORDER BY b.uploaded_at DESC
            LIMIT 1
        ");
        $stmt->execute([':mid' => $memberId, ':cc' => $companyCode]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }
}
