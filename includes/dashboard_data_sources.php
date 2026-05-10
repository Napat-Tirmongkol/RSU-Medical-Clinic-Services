<?php
/**
 * includes/dashboard_data_sources.php
 *
 * Predefined catalog ของ data source ที่ admin เลือกได้ตอนสร้าง widget
 * แต่ละ source มี:
 *   - label  : ชื่อที่แสดงใน UI
 *   - shape  : รูปร่างผลลัพธ์ ('count' | 'breakdown' | 'timeseries' | 'percentage')
 *   - widgets: widget types ที่เหมาะกับ shape นี้
 *   - resolver: callable(PDO) → array (data ที่จะ render)
 *
 * Custom dataset (CSV) จะถูกเพิ่มแบบ dynamic เป็น 'custom_<dataset_id>'
 *
 * รูปร่างผลลัพธ์:
 *   count       → ['value' => int, 'sparkline' => [int...]?]
 *   percentage  → ['value' => float, 'denominator' => int, 'numerator' => int]
 *   breakdown   → ['labels' => [...], 'values' => [...]]
 *   timeseries  → ['labels' => [...], 'series' => [['name'=>..., 'data'=>[...]]]]
 */
declare(strict_types=1);

if (!function_exists('dashboard_data_sources_catalog')) {

    /**
     * คืน catalog ของ predefined sources (ไม่รวม custom datasets)
     * @return array<string, array{label:string, shape:string, widgets:array<string>}>
     */
    function dashboard_data_sources_catalog(): array
    {
        return [
            // ── ประกัน MTI ──────────────────────────────────────────────
            'mti_total_active' => [
                'label'   => 'ประกันอุบัติเหตุ — Active',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'mti_total_all' => [
                'label'   => 'ประกันอุบัติเหตุ — ทั้งหมด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'mti_breakdown_type' => [
                'label'   => 'ประกันอุบัติเหตุ — แยกตามประเภท (บุคลากร/นศ.)',
                'shape'   => 'breakdown',
                'widgets' => ['donut', 'pie', 'bar'],
            ],
            'mti_breakdown_status' => [
                'label'   => 'ประกันอุบัติเหตุ — แยกตามสถานะ (Active/Inactive)',
                'shape'   => 'breakdown',
                'widgets' => ['donut', 'pie', 'bar'],
            ],
            'mti_trend_12m' => [
                'label'   => 'ประกันอุบัติเหตุ — Trend 12 เดือนล่าสุด',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],
            'mti_expiring_30d' => [
                'label'   => 'ประกันอุบัติเหตุ — ใกล้หมดอายุ ≤30 วัน',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],

            // ── บัตรทอง ─────────────────────────────────────────────────
            'gold_total' => [
                'label'   => 'บัตรทอง — ทั้งหมด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'gold_approved' => [
                'label'   => 'บัตรทอง — อนุมัติแล้ว',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'gold_pending_docs' => [
                'label'   => 'บัตรทอง — รอเอกสาร/รออนุมัติ',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'gold_by_status' => [
                'label'   => 'บัตรทอง — แยกตามสถานะ',
                'shape'   => 'breakdown',
                'widgets' => ['donut', 'pie', 'bar'],
            ],
            'gold_by_hospital' => [
                'label'   => 'บัตรทอง — Top รพ.หลัก',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut'],
            ],
            'gold_by_type' => [
                'label'   => 'บัตรทอง — แยกตามประเภทผู้สมัคร',
                'shape'   => 'breakdown',
                'widgets' => ['donut', 'pie', 'bar'],
            ],
            'gold_trend_12m' => [
                'label'   => 'บัตรทอง — Trend 12 เดือนล่าสุด',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],

            // ── Combined ────────────────────────────────────────────────
            'coverage_total' => [
                'label'   => 'ความครอบคลุมรวม (MTI + บัตรทอง)',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'coverage_compare_trend' => [
                'label'   => 'เปรียบเทียบ Trend MTI vs บัตรทอง 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],
        ];
    }

    /**
     * คืน custom datasets (จาก CSV upload) ในรูป catalog เดียวกัน
     * @return array<string, array{label:string, shape:string, widgets:array<string>, dataset_id:int}>
     */
    function dashboard_custom_datasets(PDO $pdo): array
    {
        $out = [];
        try {
            $rows = $pdo->query("SELECT id, dataset_key, dataset_name FROM ins_dashboard_datasets ORDER BY uploaded_at DESC")
                        ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $key = 'custom_' . $r['dataset_key'];
                $out[$key] = [
                    'label'      => 'Custom: ' . $r['dataset_name'],
                    'shape'      => 'breakdown',
                    'widgets'    => ['bar', 'donut', 'pie', 'line', 'area'],
                    'dataset_id' => (int)$r['id'],
                ];
            }
        } catch (PDOException $e) { /* table may not exist yet */ }
        return $out;
    }

    /**
     * Resolve data source key → ผลลัพธ์ตาม shape
     * @return array รูปร่างขึ้นกับ shape (ดู docblock บน)
     */
    function dashboard_resolve_data(PDO $pdo, string $key): array
    {
        $catalog = dashboard_data_sources_catalog();

        // Custom CSV dataset
        if (str_starts_with($key, 'custom_')) {
            $datasetKey = substr($key, 7);
            return _resolve_custom_dataset($pdo, $datasetKey);
        }

        if (!isset($catalog[$key])) {
            return ['shape' => 'unknown'];
        }

        switch ($key) {
            case 'mti_total_active':
                return [
                    'shape' => 'count',
                    'value' => (int)_safe_scalar($pdo, "SELECT COUNT(*) FROM insurance_members WHERE insurance_status='Active'"),
                ];

            case 'mti_total_all':
                return [
                    'shape' => 'count',
                    'value' => (int)_safe_scalar($pdo, "SELECT COUNT(*) FROM insurance_members"),
                ];

            case 'mti_expiring_30d':
                return [
                    'shape' => 'count',
                    'value' => (int)_safe_scalar($pdo,
                        "SELECT COUNT(*) FROM insurance_members
                         WHERE insurance_status='Active'
                           AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"),
                ];

            case 'mti_breakdown_type':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(NULLIF(member_status,''), 'ไม่ระบุ') AS label, COUNT(*) AS value
                     FROM insurance_members GROUP BY member_status ORDER BY value DESC");

            case 'mti_breakdown_status':
                return _resolve_breakdown($pdo,
                    "SELECT insurance_status AS label, COUNT(*) AS value
                     FROM insurance_members GROUP BY insurance_status ORDER BY value DESC");

            case 'mti_trend_12m':
                return _resolve_monthly_trend($pdo, 'insurance_members', 'created_at', 'ประกันอุบัติเหตุ');

            // ── บัตรทอง ─────────────────────────────────────────────
            case 'gold_total':
                return [
                    'shape' => 'count',
                    'value' => (int)_safe_scalar($pdo, "SELECT COUNT(*) FROM gold_card_members"),
                ];

            case 'gold_approved':
                return [
                    'shape' => 'count',
                    'value' => (int)_safe_scalar($pdo,
                        "SELECT COUNT(*) FROM gold_card_members WHERE status IN ('approved','active')"),
                ];

            case 'gold_pending_docs':
                return [
                    'shape' => 'count',
                    'value' => (int)_safe_scalar($pdo,
                        "SELECT COUNT(*) FROM gold_card_members WHERE status IN ('pending','submitted')"),
                ];

            case 'gold_by_status':
                $statusLabels = [
                    'pending'   => 'รอเอกสาร',
                    'submitted' => 'ส่งแล้ว',
                    'approved'  => 'อนุมัติ',
                    'active'    => 'ใช้งาน',
                    'rejected'  => 'ไม่ผ่าน',
                    'expired'   => 'หมดอายุ',
                ];
                $rows = _safe_rows($pdo,
                    "SELECT status, COUNT(*) AS cnt FROM gold_card_members GROUP BY status");
                $labels = []; $values = [];
                foreach ($rows as $r) {
                    $labels[] = $statusLabels[$r['status']] ?? $r['status'];
                    $values[] = (int)$r['cnt'];
                }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];

            case 'gold_by_hospital':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(NULLIF(hospital_main,''), 'ไม่ระบุ') AS label, COUNT(*) AS value
                     FROM gold_card_members GROUP BY hospital_main ORDER BY value DESC LIMIT 10");

            case 'gold_by_type':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(NULLIF(member_type,''), 'ไม่ระบุ') AS label, COUNT(*) AS value
                     FROM gold_card_members GROUP BY member_type ORDER BY value DESC");

            case 'gold_trend_12m':
                return _resolve_monthly_trend($pdo, 'gold_card_members', 'created_at', 'บัตรทอง');

            // ── Combined ────────────────────────────────────────────
            case 'coverage_total':
                $mti  = (int)_safe_scalar($pdo,
                    "SELECT COUNT(DISTINCT citizen_id) FROM insurance_members WHERE insurance_status='Active'");
                $gold = (int)_safe_scalar($pdo,
                    "SELECT COUNT(DISTINCT citizen_id) FROM gold_card_members WHERE status IN ('approved','active')");
                return ['shape' => 'count', 'value' => $mti + $gold];

            case 'coverage_compare_trend':
                $mti  = _resolve_monthly_trend($pdo, 'insurance_members', 'created_at', 'ประกัน MTI');
                $gold = _resolve_monthly_trend($pdo, 'gold_card_members', 'created_at', 'บัตรทอง');
                return [
                    'shape'  => 'timeseries',
                    'labels' => $mti['labels'] ?? [],
                    'series' => array_merge($mti['series'] ?? [], $gold['series'] ?? []),
                ];
        }

        return ['shape' => 'unknown'];
    }

    /** Resolve custom dataset (CSV upload) → breakdown shape */
    function _resolve_custom_dataset(PDO $pdo, string $datasetKey): array
    {
        try {
            $stmt = $pdo->prepare("SELECT label_column, value_column, rows_json
                                   FROM ins_dashboard_datasets WHERE dataset_key = ? LIMIT 1");
            $stmt->execute([$datasetKey]);
            $ds = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ds) return ['shape' => 'unknown'];

            $rows = json_decode($ds['rows_json'] ?: '[]', true) ?: [];
            $labelCol = $ds['label_column'];
            $valueCol = $ds['value_column'];
            $labels = []; $values = [];
            foreach ($rows as $row) {
                $labels[] = (string)($row[$labelCol] ?? '');
                $values[] = is_numeric($row[$valueCol] ?? null) ? (float)$row[$valueCol] : 0;
            }
            return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
        } catch (PDOException $e) {
            return ['shape' => 'unknown'];
        }
    }

    /** Helper: SELECT ที่อาจ fail ถ้า table ยังไม่ถูกสร้าง */
    function _safe_scalar(PDO $pdo, string $sql)
    {
        try { return $pdo->query($sql)->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    function _safe_rows(PDO $pdo, string $sql): array
    {
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (PDOException $e) { return []; }
    }

    function _resolve_breakdown(PDO $pdo, string $sql): array
    {
        $rows = _safe_rows($pdo, $sql);
        $labels = []; $values = [];
        foreach ($rows as $r) {
            $labels[] = (string)($r['label'] ?? '');
            $values[] = (int)($r['value'] ?? 0);
        }
        return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
    }

    function _resolve_monthly_trend(PDO $pdo, string $table, string $dateCol, string $seriesName): array
    {
        $labels = []; $counts = [];
        $bucket = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime("first day of -$i month");
            $key = date('Y-m', $ts);
            $labels[] = _thai_month_label($ts);
            $bucket[$key] = 0;
        }
        try {
            $rows = $pdo->query("
                SELECT DATE_FORMAT($dateCol, '%Y-%m') AS ym, COUNT(*) AS cnt
                FROM $table
                WHERE $dateCol >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
                GROUP BY ym
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                if (isset($bucket[$r['ym']])) $bucket[$r['ym']] = (int)$r['cnt'];
            }
        } catch (PDOException $e) { /* table missing */ }
        $counts = array_values($bucket);
        return [
            'shape'  => 'timeseries',
            'labels' => $labels,
            'series' => [['name' => $seriesName, 'data' => $counts]],
        ];
    }

    function _thai_month_label(int $ts): string
    {
        static $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        $m = (int)date('n', $ts) - 1;
        $y = (int)date('Y', $ts) + 543;
        return $months[$m] . ' ' . substr((string)$y, -2);
    }
}
