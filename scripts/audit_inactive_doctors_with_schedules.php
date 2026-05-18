<?php
/**
 * scripts/audit_inactive_doctors_with_schedules.php
 * --------------------------------------------------
 * One-shot diagnostic — list doctors flagged inactive in sys_medical_staff
 * but who still have active rows in sys_doctor_schedule.
 *
 * The user-facing calendar at user/clinic_schedule.php previously filtered by
 * ms.is_active = 1, so these schedules silently disappeared from the user
 * view while remaining visible in the admin view (reported drift). The user
 * side has been switched to LEFT JOIN without the is_active filter, matching
 * admin schedule:list + clinic_status_helper.php. Run this script to
 * understand WHY the staff were flagged inactive — was it intentional
 * (resignation / suspension) or data drift (someone toggled the wrong row).
 *
 * USAGE
 *   $ php scripts/audit_inactive_doctors_with_schedules.php
 *
 * SAFE: read-only — no INSERT/UPDATE/DELETE. Run from CLI as the same OS user
 *       that owns config/secrets.php.
 *
 * REMEDIATION (operator-driven)
 *   - If staff are legitimately retired/resigned:
 *       UPDATE sys_doctor_schedule SET is_active = 0 WHERE staff_id = <id>;
 *     (Hides their schedule from BOTH admin + user views.)
 *   - If staff should still be active (drift):
 *       UPDATE sys_medical_staff SET is_active = 1 WHERE id = <id>;
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';

$pdo = db();

echo "Doctors marked inactive in sys_medical_staff but having active schedules\n";
echo str_repeat('=', 76) . "\n\n";

$stmt = $pdo->query("
    SELECT ms.id, ms.title, ms.full_name, ms.is_active,
           COUNT(s.id)  AS active_schedule_count,
           MIN(s.created_at) AS first_schedule_at,
           MAX(s.updated_at) AS last_schedule_update
    FROM sys_medical_staff ms
    INNER JOIN sys_doctor_schedule s ON s.staff_id = ms.id AND s.is_active = 1
    WHERE ms.is_active = 0
    GROUP BY ms.id, ms.title, ms.full_name, ms.is_active
    ORDER BY active_schedule_count DESC, ms.full_name ASC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "No drift detected — every staff with active schedules is also active.\n";
    exit(0);
}

printf("%-6s | %-10s | %-30s | %-7s | %-6s | %s\n",
       'id', 'title', 'full_name', 'active', 'shifts', 'last_update');
echo str_repeat('-', 96) . "\n";
foreach ($rows as $r) {
    printf("%-6s | %-10s | %-30s | %-7s | %-6s | %s\n",
        $r['id'],
        mb_substr((string)$r['title'], 0, 10),
        mb_substr((string)$r['full_name'], 0, 30),
        $r['is_active'] ? 'YES' : 'NO',
        $r['active_schedule_count'],
        $r['last_schedule_update']
    );
}

echo "\n" . count($rows) . " staff with drift.\n";
echo "\nRemediation (review each row, run ONE of these per staff_id):\n";
echo "  -- staff legitimately gone → also deactivate their schedules:\n";
echo "  UPDATE sys_doctor_schedule SET is_active = 0 WHERE staff_id = <id>;\n";
echo "\n  -- staff should be active again → flip the flag:\n";
echo "  UPDATE sys_medical_staff   SET is_active = 1 WHERE id = <id>;\n";
