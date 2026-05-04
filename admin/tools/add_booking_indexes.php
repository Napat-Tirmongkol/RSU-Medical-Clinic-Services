<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = db();

$indexes = [
    ['camp_bookings', 'idx_cb_status',      'ALTER TABLE camp_bookings ADD INDEX idx_cb_status (status)'],
    ['camp_bookings', 'idx_cb_slot_status', 'ALTER TABLE camp_bookings ADD INDEX idx_cb_slot_status (slot_id, status)'],
    ['camp_slots',    'idx_cs_slot_date',   'ALTER TABLE camp_slots    ADD INDEX idx_cs_slot_date (slot_date)'],
];

$results = [];
foreach ($indexes as [$table, $name, $sql]) {
    $exists = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$name}'")->rowCount() > 0;
    if ($exists) {
        $results[] = ['status' => 'skip', 'name' => $name, 'msg' => 'Already exists'];
    } else {
        try {
            $pdo->exec($sql);
            $results[] = ['status' => 'ok', 'name' => $name, 'msg' => 'Added successfully'];
        } catch (PDOException $e) {
            $results[] = ['status' => 'error', 'name' => $name, 'msg' => $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head><meta charset="UTF-8"><title>Add Booking Indexes</title>
<link rel="stylesheet" href="../../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>"></head>
<body class="bg-gray-50 p-10">
<div class="max-w-xl mx-auto bg-white rounded-2xl shadow p-8 space-y-4">
    <h1 class="text-xl font-bold text-gray-800">Booking Index Setup</h1>
    <?php foreach ($results as $r): ?>
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl <?= $r['status'] === 'ok' ? 'bg-emerald-50 text-emerald-700' : ($r['status'] === 'skip' ? 'bg-gray-50 text-gray-500' : 'bg-red-50 text-red-600') ?>">
            <span class="font-mono text-sm font-bold"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="text-sm">— <?= htmlspecialchars($r['msg'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endforeach; ?>
    <a href="../bookings.php" class="inline-block mt-4 bg-[#0052CC] text-white px-6 py-2 rounded-xl text-sm font-bold">กลับหน้า Bookings</a>
</div>
</body>
</html>
