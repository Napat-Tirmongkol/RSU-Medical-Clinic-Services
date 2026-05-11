<?php
/**
 * includes/kpi_override_helper.php
 *
 * ใช้ใน partial/widget เพื่อให้ admin กรอกยอดเองทับค่าจริงได้
 *
 *   $val = kpi_with_override($pdo, 'gold_total', $autoCount);
 *
 * หรือ apply กับ array ของ stats พร้อมกัน:
 *   $stats = kpi_apply_overrides($pdo, [
 *       'gold_total'   => $stats['total'],
 *       'gold_pending' => $stats['pending'],
 *   ]);
 *
 * Catalog ของ KPI ที่ override ได้: kpi_override_catalog()
 */
declare(strict_types=1);

if (!function_exists('kpi_with_override')) {

    /** Apply override (ถ้ามี is_active=1) ให้ค่า KPI ตัวเดียว */
    function kpi_with_override(PDO $pdo, string $key, int $autoValue): int
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $rows = $pdo->query("SELECT kpi_key, override_value FROM ins_kpi_overrides WHERE is_active = 1")
                            ->fetchAll(PDO::FETCH_KEY_PAIR);
                $cache = $rows ?: [];
            } catch (PDOException $e) { /* table not ready */ }
        }
        return isset($cache[$key]) ? (int)$cache[$key] : $autoValue;
    }

    /**
     * Apply overrides กับ array ของค่า KPI พร้อมกัน
     * @param array<string,int> $kpis  key => autoValue
     * @return array<string,int>       key => effective value
     */
    function kpi_apply_overrides(PDO $pdo, array $kpis): array
    {
        $out = $kpis;
        foreach ($kpis as $k => $v) {
            $out[$k] = kpi_with_override($pdo, $k, (int)$v);
        }
        return $out;
    }

    /**
     * คืน array ของ override status (สำหรับแสดง badge "OVERRIDE" ใน UI)
     * @return array<string,bool>
     */
    function kpi_override_status(PDO $pdo): array
    {
        try {
            $rows = $pdo->query("SELECT kpi_key, is_active FROM ins_kpi_overrides")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
            $out = [];
            foreach ($rows ?: [] as $k => $v) $out[$k] = (int)$v === 1;
            return $out;
        } catch (PDOException $e) { return []; }
    }

    /**
     * Catalog ของ KPI ที่อนุญาตให้ admin override
     * @return array<string, array{label:string, group:string}>
     */
    function kpi_override_catalog(): array
    {
        return [
            // บัตรทอง (ใช้ key เดียวกับ dashboard data sources)
            'gold_total'         => ['label' => 'บัตรทอง — ทั้งหมด',          'group' => 'gold'],
            'gold_approved'      => ['label' => 'บัตรทอง — อนุมัติ',          'group' => 'gold'],
            'gold_pending_docs'  => ['label' => 'บัตรทอง — รอเอกสาร',         'group' => 'gold'],
            'gold_rejected'      => ['label' => 'บัตรทอง — ไม่ผ่าน',          'group' => 'gold'],
            'gold_expiring_30d'  => ['label' => 'บัตรทอง — ใกล้หมด ≤30 วัน',  'group' => 'gold'],
            // ประกัน MTI
            'mti_total_active'   => ['label' => 'ประกัน MTI — Active',         'group' => 'mti'],
            'mti_total_all'      => ['label' => 'ประกัน MTI — ทั้งหมด',         'group' => 'mti'],
            'mti_staff'          => ['label' => 'ประกัน MTI — บุคลากร',         'group' => 'mti'],
            'mti_student'        => ['label' => 'ประกัน MTI — นักศึกษา',        'group' => 'mti'],
            'mti_manual_override'=> ['label' => 'ประกัน MTI — Manual Override', 'group' => 'mti'],
            'mti_expiring_30d'   => ['label' => 'ประกัน MTI — ใกล้หมด ≤30 วัน', 'group' => 'mti'],
            // รวม
            'coverage_total'     => ['label' => 'ความครอบคลุมรวม',             'group' => 'combined'],
        ];
    }
}
