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

    require_once __DIR__ . '/kpi_override_helper.php';

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

            // ── การเงิน (Finance) ────────────────────────────────────────
            'finance_income_total' => [
                'label'   => 'การเงิน — รายรับรวม',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'finance_expense_total' => [
                'label'   => 'การเงิน — รายจ่ายรวม',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'finance_balance' => [
                'label'   => 'การเงิน — ยอดคงเหลือสุทธิ (รายรับ − รายจ่าย)',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'finance_by_category' => [
                'label'   => 'การเงิน — แยกตามหมวดหมู่',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut', 'pie'],
            ],
            'finance_income_vs_expense_trend' => [
                'label'   => 'การเงิน — Trend รายรับ vs รายจ่าย 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],

            // ── ครุภัณฑ์ (Assets) ───────────────────────────────────────
            'asset_total' => [
                'label'   => 'ครุภัณฑ์ — ทั้งหมด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'asset_by_status' => [
                'label'   => 'ครุภัณฑ์ — แยกตามสถานะ',
                'shape'   => 'breakdown',
                'widgets' => ['donut', 'pie', 'bar'],
            ],
            'asset_by_category' => [
                'label'   => 'ครุภัณฑ์ — แยกตามหมวดหมู่',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut'],
            ],
            'asset_warranty_expiring_90d' => [
                'label'   => 'ครุภัณฑ์ — ใกล้หมดประกัน ≤90 วัน',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],

            // ── วัสดุสิ้นเปลือง (Consumables) ───────────────────────────
            'consumable_total' => [
                'label'   => 'วัสดุสิ้นเปลือง — รายการทั้งหมด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'consumable_low_stock' => [
                'label'   => 'วัสดุสิ้นเปลือง — ต่ำกว่าจุดสั่งซื้อ',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'consumable_by_category' => [
                'label'   => 'วัสดุสิ้นเปลือง — แยกตามหมวดหมู่',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut', 'pie'],
            ],
            'consumable_receive_trend' => [
                'label'   => 'วัสดุสิ้นเปลือง — Trend การรับเข้า 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],

            // ── ทุนนักศึกษา (Scholarship) ───────────────────────────────
            'scholarship_bookings_total' => [
                'label'   => 'ทุนนักศึกษา — จำนวนการจองรวม',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'scholarship_slots_open' => [
                'label'   => 'ทุนนักศึกษา — รอบที่เปิดอยู่',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'scholarship_booking_trend' => [
                'label'   => 'ทุนนักศึกษา — Trend การจอง 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],

            // ── e-Campaign & ความพึงพอใจ ────────────────────────────────
            'campaign_bookings_total' => [
                'label'   => 'e-Campaign — การจองทั้งหมด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'campaign_active' => [
                'label'   => 'e-Campaign — แคมเปญ Active',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'campaign_booking_rate' => [
                'label'   => 'e-Campaign — อัตราการจอง (%)',
                'shape'   => 'percentage',
                'widgets' => ['kpi'],
            ],
            'campaign_booking_trend' => [
                'label'   => 'e-Campaign — Trend การจอง 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],
            'satisfaction_avg_rating' => [
                'label'   => 'ความพึงพอใจ — คะแนนเฉลี่ย (จาก 5)',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'satisfaction_distribution' => [
                'label'   => 'ความพึงพอใจ — การกระจาย 1–5 ดาว',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut', 'pie'],
            ],
            'satisfaction_trend_12m' => [
                'label'   => 'ความพึงพอใจ — Trend คะแนนเฉลี่ยรายเดือน 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area'],
            ],

            // ── แคมเปญวัคซีน × LINE ─────────────────────────────────────
            'campaign_vaccine_via_line' => [
                'label'   => 'แคมเปญวัคซีน — จำนวนผู้เข้าร่วมผ่าน LINE',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'campaign_vaccine_total' => [
                'label'   => 'แคมเปญวัคซีน — ผู้เข้าร่วมทั้งหมด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'campaign_vaccine_line_vs_other' => [
                'label'   => 'แคมเปญวัคซีน — เปรียบเทียบ LINE vs ช่องทางอื่น',
                'shape'   => 'breakdown',
                'widgets' => ['donut', 'pie', 'bar'],
            ],
            'campaign_vaccine_via_line_trend' => [
                'label'   => 'แคมเปญวัคซีน — Trend ผู้เข้าร่วมผ่าน LINE 12 เดือน',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],

            // ── บันทึกอุบัติเหตุ (Accident Log) ──────────────────────────
            'accident_total' => [
                'label'   => 'อุบัติเหตุ — รวมในช่วงที่เลือก',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'accident_peak_daily' => [
                'label'   => 'อุบัติเหตุ — สูงสุดในวันใดวันหนึ่ง',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'accident_avg_per_day' => [
                'label'   => 'อุบัติเหตุ — เฉลี่ยต่อวัน (วันที่บันทึก)',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'accident_trend_12m' => [
                'label'   => 'อุบัติเหตุ — Trend 12 เดือนล่าสุด',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],
            'accident_by_month_current_year' => [
                'label'   => 'อุบัติเหตุ — แยกรายเดือนของปีนี้',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut'],
            ],

            // ── สถิติบัตรทอง (Gold Card Monthly Stats) ──────────────────
            'gold_stats_latest' => [
                'label'   => 'สถิติบัตรทอง — ยอดสมาชิกล่าสุด',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'gold_stats_peak' => [
                'label'   => 'สถิติบัตรทอง — ยอดสูงสุดที่เคยบันทึก',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'gold_stats_low' => [
                'label'   => 'สถิติบัตรทอง — ยอดต่ำสุดที่เคยบันทึก',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'gold_stats_trend_12m' => [
                'label'   => 'สถิติบัตรทอง — Trend ยอดสมาชิก 12 เดือนล่าสุด',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],
            'gold_stats_yoy_avg' => [
                'label'   => 'สถิติบัตรทอง — ค่าเฉลี่ยรายปี (YoY)',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'line'],
            ],

            // ── Productivity พยาบาล — ยอดผู้ป่วย ─────────────────────────
            'nurse_patients_total' => [
                'label'   => 'Productivity พยาบาล — ยอดผู้ป่วยรวม (ทุกหน่วยงาน)',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'nurse_patients_avg_daily' => [
                'label'   => 'Productivity พยาบาล — เฉลี่ยผู้ป่วย/วันที่บันทึก',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'nurse_patients_peak_daily' => [
                'label'   => 'Productivity พยาบาล — ผู้ป่วยสูงสุดใน 1 วัน',
                'shape'   => 'count',
                'widgets' => ['kpi'],
            ],
            'nurse_patients_trend_12m' => [
                'label'   => 'Productivity พยาบาล — Trend ยอดผู้ป่วย 12 เดือนล่าสุด',
                'shape'   => 'timeseries',
                'widgets' => ['line', 'area', 'bar'],
            ],
            'nurse_patients_by_dept' => [
                'label'   => 'Productivity พยาบาล — แยกตามหน่วยงาน',
                'shape'   => 'breakdown',
                'widgets' => ['bar', 'donut', 'pie'],
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
     *
     * @param array $filter  ['year' => int|null (CE), 'month' => int|null 1-12]
     * @return array รูปร่างขึ้นกับ shape (ดู docblock บน)
     */
    function dashboard_resolve_data(PDO $pdo, string $key, array $filter = []): array
    {
        $catalog = dashboard_data_sources_catalog();
        $year  = isset($filter['year']) && $filter['year'] ? (int)$filter['year'] : null;
        $month = isset($filter['month']) && $filter['month'] ? (int)$filter['month'] : null;
        $hasFilter = $year !== null || $month !== null;

        // Custom CSV dataset
        if (str_starts_with($key, 'custom_')) {
            $datasetKey = substr($key, 7);
            return _resolve_custom_dataset($pdo, $datasetKey);
        }

        if (!isset($catalog[$key])) {
            return ['shape' => 'unknown'];
        }

        // Helper: build date filter SQL clause
        $dateClause = function (string $col) use ($year, $month): string {
            $parts = [];
            if ($year !== null)  $parts[] = "YEAR($col) = " . $year;
            if ($month !== null) $parts[] = "MONTH($col) = " . $month;
            return $parts ? ' AND ' . implode(' AND ', $parts) : '';
        };

        switch ($key) {
            case 'mti_total_active': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM insurance_members WHERE insurance_status='Active'" . $dateClause('created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'mti_total_all': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM insurance_members WHERE 1=1" . $dateClause('created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'mti_expiring_30d': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM insurance_members
                     WHERE insurance_status='Active'
                       AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . $dateClause('coverage_end'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'mti_breakdown_type':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(NULLIF(member_status,''), 'ไม่ระบุ') AS label, COUNT(*) AS value
                     FROM insurance_members WHERE 1=1" . $dateClause('created_at') .
                    " GROUP BY member_status ORDER BY value DESC");

            case 'mti_breakdown_status':
                return _resolve_breakdown($pdo,
                    "SELECT insurance_status AS label, COUNT(*) AS value
                     FROM insurance_members WHERE 1=1" . $dateClause('created_at') .
                    " GROUP BY insurance_status ORDER BY value DESC");

            case 'mti_trend_12m':
                return _resolve_monthly_trend($pdo, 'insurance_members', 'created_at', 'ประกันอุบัติเหตุ', $year, $month);

            // ── บัตรทอง ─────────────────────────────────────────────
            case 'gold_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM gold_card_members WHERE 1=1" . $dateClause('application_date'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'gold_approved': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM gold_card_members WHERE status IN ('approved','active')" . $dateClause('application_date'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'gold_pending_docs': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM gold_card_members WHERE status IN ('pending','submitted')" . $dateClause('application_date'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

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
                    "SELECT status, COUNT(*) AS cnt FROM gold_card_members WHERE 1=1" . $dateClause('application_date') .
                    " GROUP BY status");
                $labels = []; $values = [];
                foreach ($rows as $r) {
                    $labels[] = $statusLabels[$r['status']] ?? $r['status'];
                    $values[] = (int)$r['cnt'];
                }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];

            case 'gold_by_hospital':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(NULLIF(hospital_main,''), 'ไม่ระบุ') AS label, COUNT(*) AS value
                     FROM gold_card_members WHERE 1=1" . $dateClause('application_date') .
                    " GROUP BY hospital_main ORDER BY value DESC LIMIT 10");

            case 'gold_by_type':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(NULLIF(member_type,''), 'ไม่ระบุ') AS label, COUNT(*) AS value
                     FROM gold_card_members WHERE 1=1" . $dateClause('application_date') .
                    " GROUP BY member_type ORDER BY value DESC");

            case 'gold_trend_12m':
                return _resolve_monthly_trend($pdo, 'gold_card_members', 'application_date', 'บัตรทอง', $year, $month);

            // ── Combined ────────────────────────────────────────────
            case 'coverage_total': {
                $mti  = (int)_safe_scalar($pdo,
                    "SELECT COUNT(DISTINCT citizen_id) FROM insurance_members WHERE insurance_status='Active'" . $dateClause('created_at'));
                $gold = (int)_safe_scalar($pdo,
                    "SELECT COUNT(DISTINCT citizen_id) FROM gold_card_members WHERE status IN ('approved','active')" . $dateClause('application_date'));
                $auto = $mti + $gold;
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'coverage_compare_trend':
                $mti  = _resolve_monthly_trend($pdo, 'insurance_members', 'created_at',     'ประกัน MTI', $year, $month);
                $gold = _resolve_monthly_trend($pdo, 'gold_card_members', 'application_date', 'บัตรทอง',    $year, $month);
                return [
                    'shape'  => 'timeseries',
                    'labels' => $mti['labels'] ?? [],
                    'series' => array_merge($mti['series'] ?? [], $gold['series'] ?? []),
                ];

            // ── การเงิน ──────────────────────────────────────────────────
            case 'finance_income_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COALESCE(SUM(amount),0) FROM sys_finance_transactions WHERE kind='income'" . $dateClause('txn_date'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'finance_expense_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COALESCE(SUM(amount),0) FROM sys_finance_transactions WHERE kind='expense'" . $dateClause('txn_date'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'finance_balance': {
                $income  = (float)_safe_scalar($pdo,
                    "SELECT COALESCE(SUM(amount),0) FROM sys_finance_transactions WHERE kind='income'" . $dateClause('txn_date'));
                $expense = (float)_safe_scalar($pdo,
                    "SELECT COALESCE(SUM(amount),0) FROM sys_finance_transactions WHERE kind='expense'" . $dateClause('txn_date'));
                $auto = (int)round($income - $expense);
                $val  = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'finance_by_category': {
                $rows = _safe_rows($pdo,
                    "SELECT COALESCE(c.name,'ไม่ระบุหมวด') AS label,
                            COALESCE(SUM(t.amount),0) AS value
                     FROM sys_finance_transactions t
                     LEFT JOIN sys_finance_categories c ON c.id = t.category_id
                     WHERE 1=1" . $dateClause('t.txn_date') .
                    " GROUP BY t.category_id, c.name ORDER BY value DESC");
                $labels = []; $values = [];
                foreach ($rows as $r) { $labels[] = $r['label']; $values[] = (int)$r['value']; }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
            }

            case 'finance_income_vs_expense_trend': {
                $income  = _resolve_finance_trend($pdo, 'income',  'รายรับ',  $year, $month);
                $expense = _resolve_finance_trend($pdo, 'expense', 'รายจ่าย', $year, $month);
                return [
                    'shape'  => 'timeseries',
                    'labels' => $income['labels'] ?? [],
                    'series' => array_merge($income['series'] ?? [], $expense['series'] ?? []),
                ];
            }

            // ── ครุภัณฑ์ ─────────────────────────────────────────────────
            case 'asset_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM assets WHERE 1=1" . $dateClause('created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'asset_by_status': {
                $statusLabels = [
                    'in_use'   => 'ใช้งาน',
                    'repair'   => 'ซ่อม',
                    'disposed' => 'จำหน่าย',
                    'lost'     => 'สูญหาย',
                    'reserve'  => 'สำรอง',
                ];
                $rows = _safe_rows($pdo,
                    "SELECT status, COUNT(*) AS cnt FROM assets WHERE 1=1" . $dateClause('created_at') .
                    " GROUP BY status ORDER BY cnt DESC");
                $labels = []; $values = [];
                foreach ($rows as $r) {
                    $labels[] = $statusLabels[$r['status']] ?? $r['status'];
                    $values[] = (int)$r['cnt'];
                }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
            }

            case 'asset_by_category':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(c.name,'ไม่ระบุหมวด') AS label, COUNT(*) AS value
                     FROM assets a
                     LEFT JOIN asset_categories c ON c.id = a.category_id
                     WHERE 1=1" . $dateClause('a.created_at') .
                    " GROUP BY a.category_id, c.name ORDER BY value DESC");

            case 'asset_warranty_expiring_90d': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM assets
                     WHERE warranty_until IS NOT NULL
                       AND warranty_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                       AND status NOT IN ('disposed','lost')");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            // ── วัสดุสิ้นเปลือง ──────────────────────────────────────────
            case 'consumable_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM consumables WHERE status='active'" . $dateClause('created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'consumable_low_stock': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM consumables WHERE status='active' AND qty_on_hand <= min_stock AND min_stock > 0");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'consumable_by_category':
                return _resolve_breakdown($pdo,
                    "SELECT COALESCE(c.name,'ไม่ระบุหมวด') AS label, COUNT(*) AS value
                     FROM consumables cs
                     LEFT JOIN consumable_categories c ON c.id = cs.category_id
                     WHERE cs.status='active'" . $dateClause('cs.created_at') .
                    " GROUP BY cs.category_id, c.name ORDER BY value DESC");

            case 'consumable_receive_trend':
                return _resolve_consumable_trend($pdo, $year, $month);

            // ── ทุนนักศึกษา ──────────────────────────────────────────────
            case 'scholarship_bookings_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM sys_scholarship_slot_bookings WHERE status='booked'" . $dateClause('booked_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'scholarship_slots_open': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM sys_scholarship_slots WHERE status='open' AND slot_date >= CURDATE()");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'scholarship_booking_trend':
                return _resolve_monthly_trend($pdo, 'sys_scholarship_slot_bookings', 'booked_at', 'การจอง', $year, $month);

            // ── e-Campaign ───────────────────────────────────────────────
            case 'campaign_bookings_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM camp_bookings WHERE 1=1" . $dateClause('created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'campaign_active': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(*) FROM camp_list WHERE status='active'");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'campaign_booking_rate': {
                $total = (int)_safe_scalar($pdo, "SELECT COALESCE(SUM(total_capacity),0) FROM camp_list");
                $used  = (int)_safe_scalar($pdo, "SELECT COUNT(*) FROM camp_bookings WHERE status IN ('booked','confirmed')");
                $pct   = $total > 0 ? round($used / $total * 100, 1) : 0.0;
                return ['shape' => 'percentage', 'value' => $pct, 'numerator' => $used, 'denominator' => $total];
            }

            case 'campaign_booking_trend':
                return _resolve_monthly_trend($pdo, 'camp_bookings', 'created_at', 'การจอง', $year, $month);

            // ── ความพึงพอใจ ──────────────────────────────────────────────
            case 'satisfaction_avg_rating': {
                $raw  = (float)_safe_scalar($pdo,
                    "SELECT COALESCE(AVG(rating),0) FROM satisfaction_surveys WHERE 1=1" . $dateClause('created_at'));
                $auto = round($raw, 2);
                $val  = $hasFilter ? $auto : kpi_with_override($pdo, $key, (int)round($auto * 100));
                // คืนค่าเป็น float เพื่อให้ KPI widget แสดง "4.82" แทน "482"
                return ['shape' => 'count', 'value' => $hasFilter ? $auto : round($val / 100, 2), 'auto' => $auto];
            }

            case 'satisfaction_distribution': {
                $labels = []; $values = [];
                for ($s = 1; $s <= 5; $s++) {
                    $cnt = (int)_safe_scalar($pdo,
                        "SELECT COUNT(*) FROM satisfaction_surveys WHERE rating = $s" . $dateClause('created_at'));
                    $labels[] = "$s ดาว";
                    $values[] = $cnt;
                }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
            }

            case 'satisfaction_trend_12m':
                return _resolve_satisfaction_trend($pdo, $year, $month);

            // ── แคมเปญวัคซีน × LINE ─────────────────────────────────────
            case 'campaign_vaccine_via_line': {
                // นับ user (DISTINCT) ที่มี line_user_id และจองแคมเปญ type='vaccine'
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(DISTINCT u.id)
                     FROM camp_bookings b
                     JOIN sys_users u ON b.student_id = u.id
                     JOIN camp_list c ON b.campaign_id = c.id
                     WHERE c.type = 'vaccine'
                       AND b.status IN ('booked','confirmed','completed')
                       AND (u.line_user_id IS NOT NULL OR u.line_user_id_new IS NOT NULL)"
                     . $dateClause('b.created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'campaign_vaccine_total': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COUNT(DISTINCT u.id)
                     FROM camp_bookings b
                     JOIN sys_users u ON b.student_id = u.id
                     JOIN camp_list c ON b.campaign_id = c.id
                     WHERE c.type = 'vaccine'
                       AND b.status IN ('booked','confirmed','completed')"
                     . $dateClause('b.created_at'));
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'campaign_vaccine_line_vs_other': {
                $row = _safe_rows($pdo,
                    "SELECT
                        SUM(CASE WHEN u.line_user_id IS NOT NULL OR u.line_user_id_new IS NOT NULL THEN 1 ELSE 0 END) AS via_line,
                        SUM(CASE WHEN u.line_user_id IS NULL AND u.line_user_id_new IS NULL THEN 1 ELSE 0 END) AS via_other
                     FROM camp_bookings b
                     JOIN sys_users u ON b.student_id = u.id
                     JOIN camp_list c ON b.campaign_id = c.id
                     WHERE c.type = 'vaccine'
                       AND b.status IN ('booked','confirmed','completed')"
                     . $dateClause('b.created_at'))[0] ?? ['via_line' => 0, 'via_other' => 0];
                return [
                    'shape'  => 'breakdown',
                    'labels' => ['ผ่าน LINE', 'ช่องทางอื่น'],
                    'values' => [(int)$row['via_line'], (int)$row['via_other']],
                ];
            }

            case 'campaign_vaccine_via_line_trend':
                return _resolve_vaccine_line_trend($pdo, $year, $month);

            /* ───── Accident Log ───── */
            case 'accident_total': {
                $sql = "SELECT COALESCE(SUM(accident_count),0) FROM sys_accident_daily WHERE 1=1"
                     . $dateClause('entry_date');
                $auto = (int)_safe_scalar($pdo, $sql);
                $val  = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'accident_peak_daily': {
                $sql = "SELECT COALESCE(MAX(accident_count),0) FROM sys_accident_daily WHERE 1=1"
                     . $dateClause('entry_date');
                $auto = (int)_safe_scalar($pdo, $sql);
                $val  = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'accident_avg_per_day': {
                $sql = "SELECT COALESCE(ROUND(AVG(accident_count),1),0) FROM sys_accident_daily WHERE 1=1"
                     . $dateClause('entry_date');
                $auto = (float)_safe_scalar($pdo, $sql);
                $val  = $hasFilter ? $auto : (float)kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'accident_trend_12m': {
                // 12 เดือนย้อนหลัง (รวมเดือนปัจจุบัน)
                $rows = _safe_rows($pdo,
                    "SELECT DATE_FORMAT(entry_date,'%Y-%m') AS ym, SUM(accident_count) AS total
                     FROM sys_accident_daily
                     WHERE entry_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
                     GROUP BY ym ORDER BY ym ASC");
                $thaiMo = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                $labels = []; $data = [];
                foreach ($rows as $r) {
                    [$y, $m] = explode('-', $r['ym']);
                    $labels[] = $thaiMo[(int)$m] . ' ' . substr((string)((int)$y + 543), -2);
                    $data[] = (int)$r['total'];
                }
                return ['shape' => 'timeseries', 'labels' => $labels,
                        'series' => [['name' => 'อุบัติเหตุ', 'data' => $data]]];
            }

            case 'accident_by_month_current_year': {
                $yearCe = (int)date('Y');
                $rows = _safe_rows($pdo,
                    "SELECT MONTH(entry_date) AS m, SUM(accident_count) AS total
                     FROM sys_accident_daily
                     WHERE YEAR(entry_date) = $yearCe
                     GROUP BY m ORDER BY m ASC");
                $thaiMo = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                $map = []; foreach ($rows as $r) $map[(int)$r['m']] = (int)$r['total'];
                $labels = []; $values = [];
                for ($m = 1; $m <= 12; $m++) { $labels[] = $thaiMo[$m]; $values[] = $map[$m] ?? 0; }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
            }

            /* ───── Gold Card Monthly Stats ───── */
            case 'gold_stats_latest': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT member_count FROM sys_gold_card_monthly_stats
                     ORDER BY year_be DESC, month DESC LIMIT 1");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'gold_stats_peak': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COALESCE(MAX(member_count),0) FROM sys_gold_card_monthly_stats");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'gold_stats_low': {
                $auto = (int)_safe_scalar($pdo,
                    "SELECT COALESCE(MIN(member_count),0) FROM sys_gold_card_monthly_stats");
                $val = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'gold_stats_trend_12m': {
                // เอา 12 entries ล่าสุดเรียง year+month ASC
                $rows = _safe_rows($pdo,
                    "SELECT year_be, month, member_count FROM sys_gold_card_monthly_stats
                     ORDER BY year_be DESC, month DESC LIMIT 12");
                $rows = array_reverse($rows);
                $thaiMo = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                $labels = []; $data = [];
                foreach ($rows as $r) {
                    $labels[] = $thaiMo[(int)$r['month']] . ' ' . substr((string)(int)$r['year_be'], -2);
                    $data[] = (int)$r['member_count'];
                }
                return ['shape' => 'timeseries', 'labels' => $labels,
                        'series' => [['name' => 'ยอดสมาชิก', 'data' => $data]]];
            }

            case 'gold_stats_yoy_avg': {
                $rows = _safe_rows($pdo,
                    "SELECT year_be, ROUND(AVG(member_count)) AS avg_count
                     FROM sys_gold_card_monthly_stats
                     GROUP BY year_be ORDER BY year_be ASC");
                $labels = []; $values = [];
                foreach ($rows as $r) {
                    $labels[] = 'พ.ศ. ' . (int)$r['year_be'];
                    $values[] = (int)$r['avg_count'];
                }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
            }

            /* ───── Productivity พยาบาล — ยอดผู้ป่วย ───── */
            case 'nurse_patients_total': {
                $sql = "SELECT COALESCE(SUM(patients),0) FROM sys_nurse_productivity_daily WHERE 1=1"
                     . $dateClause('entry_date');
                $auto = (int)_safe_scalar($pdo, $sql);
                $val  = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'nurse_patients_avg_daily': {
                $sql = "SELECT COALESCE(ROUND(AVG(patients),1),0) FROM sys_nurse_productivity_daily WHERE 1=1"
                     . $dateClause('entry_date');
                $auto = (float)_safe_scalar($pdo, $sql);
                $val  = $hasFilter ? $auto : (float)kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'nurse_patients_peak_daily': {
                $sql = "SELECT COALESCE(MAX(patients),0) FROM sys_nurse_productivity_daily WHERE 1=1"
                     . $dateClause('entry_date');
                $auto = (int)_safe_scalar($pdo, $sql);
                $val  = $hasFilter ? $auto : kpi_with_override($pdo, $key, $auto);
                return ['shape' => 'count', 'value' => $val, 'auto' => $auto];
            }

            case 'nurse_patients_trend_12m': {
                $rows = _safe_rows($pdo,
                    "SELECT DATE_FORMAT(entry_date,'%Y-%m') AS ym, SUM(patients) AS total
                     FROM sys_nurse_productivity_daily
                     WHERE entry_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
                     GROUP BY ym ORDER BY ym ASC");
                $thaiMo = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                $labels = []; $data = [];
                foreach ($rows as $r) {
                    [$y, $m] = explode('-', $r['ym']);
                    $labels[] = $thaiMo[(int)$m] . ' ' . substr((string)((int)$y + 543), -2);
                    $data[] = (int)$r['total'];
                }
                return ['shape' => 'timeseries', 'labels' => $labels,
                        'series' => [['name' => 'ผู้ป่วย', 'data' => $data]]];
            }

            case 'nurse_patients_by_dept': {
                // กรองด้วย date filter ถ้ามี — รวมยอดผู้ป่วยต่อ dept
                $where = "1=1" . $dateClause('d.entry_date');
                $rows = _safe_rows($pdo,
                    "SELECT COALESCE(dept.name,'ไม่ระบุ') AS dept_name,
                            SUM(d.patients) AS total
                     FROM sys_nurse_productivity_daily d
                     LEFT JOIN sys_departments dept ON dept.id = d.dept_id
                     WHERE $where
                     GROUP BY d.dept_id, dept_name
                     ORDER BY total DESC
                     LIMIT 20");
                $labels = []; $values = [];
                foreach ($rows as $r) {
                    $labels[] = (string)$r['dept_name'];
                    $values[] = (int)$r['total'];
                }
                return ['shape' => 'breakdown', 'labels' => $labels, 'values' => $values];
            }
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

    /**
     * Resolve monthly trend ตาม filter mode:
     *   - year + month  : รายวันของเดือนนั้น (1..N วัน)
     *   - year only     : ม.ค.-ธ.ค. ของปีนั้น (12 จุด)
     *   - month only    : เดือนนั้นข้ามปี (last 5 ปี)
     *   - neither       : last 12 เดือน (default)
     */
    function _resolve_monthly_trend(PDO $pdo, string $table, string $dateCol, string $seriesName, ?int $year = null, ?int $month = null): array
    {
        $bucket = [];
        $labels = [];
        $sql = '';

        if ($year !== null && $month !== null) {
            // Daily breakdown ของ year+month
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $bucket[$key] = 0;
                $labels[] = (string)$d; // วัน
            }
            $sql = "SELECT DATE_FORMAT($dateCol, '%Y-%m-%d') AS ymd, COUNT(*) AS cnt
                    FROM $table
                    WHERE YEAR($dateCol) = $year AND MONTH($dateCol) = $month
                    GROUP BY ymd";
            $bucketKey = 'ymd';
        }
        elseif ($year !== null) {
            // Jan-Dec ของ year
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%04d-%02d', $year, $m);
                $bucket[$key] = 0;
                $labels[] = $thaiMonths[$m - 1];
            }
            $sql = "SELECT DATE_FORMAT($dateCol, '%Y-%m') AS ym, COUNT(*) AS cnt
                    FROM $table
                    WHERE YEAR($dateCol) = $year
                    GROUP BY ym";
            $bucketKey = 'ym';
        }
        elseif ($month !== null) {
            // เดือน X ข้ามปี (last 5 years)
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            $thisYear = (int)date('Y');
            for ($y = $thisYear - 4; $y <= $thisYear; $y++) {
                $key = sprintf('%04d-%02d', $y, $month);
                $bucket[$key] = 0;
                $labels[] = $thaiMonths[$month - 1] . ' ' . substr((string)($y + 543), -2);
            }
            $sql = "SELECT DATE_FORMAT($dateCol, '%Y-%m') AS ym, COUNT(*) AS cnt
                    FROM $table
                    WHERE MONTH($dateCol) = $month
                      AND YEAR($dateCol) BETWEEN " . ($thisYear - 4) . " AND $thisYear
                    GROUP BY ym";
            $bucketKey = 'ym';
        }
        else {
            // Default: last 12 months
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("first day of -$i month");
                $key = date('Y-m', $ts);
                $bucket[$key] = 0;
                $labels[] = _thai_month_label($ts);
            }
            $sql = "SELECT DATE_FORMAT($dateCol, '%Y-%m') AS ym, COUNT(*) AS cnt
                    FROM $table
                    WHERE $dateCol >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
                    GROUP BY ym";
            $bucketKey = 'ym';
        }

        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $k = $r[$bucketKey] ?? '';
                if (isset($bucket[$k])) $bucket[$k] = (int)$r['cnt'];
            }
        } catch (PDOException $e) { /* table missing */ }

        return [
            'shape'  => 'timeseries',
            'labels' => $labels,
            'series' => [['name' => $seriesName, 'data' => array_values($bucket)]],
        ];
    }

    /**
     * คืน list ของปี (CE) ที่มีข้อมูลใน DB — สำหรับ populate dropdown
     */
    function dashboard_available_years(PDO $pdo): array
    {
        $years = [];
        $tables = [
            ['insurance_members',              'created_at'],
            ['gold_card_members',              'application_date'],
            ['gold_card_members',              'created_at'],
            ['sys_finance_transactions',       'txn_date'],
            ['assets',                         'created_at'],
            ['consumable_transactions',        'txn_date'],
            ['sys_scholarship_slot_bookings',  'booked_at'],
            ['camp_bookings',                  'created_at'],
            ['satisfaction_surveys',           'created_at'],
        ];
        foreach ($tables as [$t, $col]) {
            try {
                $rows = $pdo->query("SELECT DISTINCT YEAR($col) AS y FROM $t WHERE $col IS NOT NULL")
                            ->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $y) {
                    if ($y > 2000 && $y < 3000) $years[(int)$y] = true;
                }
            } catch (PDOException $e) { /* table may not exist */ }
        }
        // Always include current year
        $years[(int)date('Y')] = true;
        $list = array_keys($years);
        rsort($list);
        return $list;
    }

    /** Finance income/expense monthly trend */
    function _resolve_finance_trend(PDO $pdo, string $kind, string $seriesName, ?int $year, ?int $month): array
    {
        $bucket = []; $labels = [];

        if ($year !== null && $month !== null) {
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $bucket[$key] = 0; $labels[] = (string)$d;
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m-%d') AS ymd,
                           COALESCE(SUM(amount),0) AS total
                    FROM sys_finance_transactions
                    WHERE kind='$kind' AND YEAR(txn_date)=$year AND MONTH(txn_date)=$month
                    GROUP BY ymd";
            $bk = 'ymd';
        } elseif ($year !== null) {
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%04d-%02d', $year, $m);
                $bucket[$key] = 0; $labels[] = $thaiMonths[$m - 1];
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
                    FROM sys_finance_transactions WHERE kind='$kind' AND YEAR(txn_date)=$year GROUP BY ym";
            $bk = 'ym';
        } elseif ($month !== null) {
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            $thisYear = (int)date('Y');
            for ($y = $thisYear - 4; $y <= $thisYear; $y++) {
                $key = sprintf('%04d-%02d', $y, $month);
                $bucket[$key] = 0; $labels[] = $thaiMonths[$month - 1] . ' ' . substr((string)($y + 543), -2);
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
                    FROM sys_finance_transactions WHERE kind='$kind' AND MONTH(txn_date)=$month
                      AND YEAR(txn_date) BETWEEN " . ($thisYear - 4) . " AND $thisYear GROUP BY ym";
            $bk = 'ym';
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("first day of -$i month");
                $key = date('Y-m', $ts);
                $bucket[$key] = 0; $labels[] = _thai_month_label($ts);
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
                    FROM sys_finance_transactions WHERE kind='$kind'
                      AND txn_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
                    GROUP BY ym";
            $bk = 'ym';
        }

        try {
            foreach (_safe_rows($pdo, $sql) as $r) {
                if (isset($bucket[$r[$bk]])) $bucket[$r[$bk]] = (int)$r['total'];
            }
        } catch (PDOException $e) {}

        return ['shape' => 'timeseries', 'labels' => $labels,
                'series' => [['name' => $seriesName, 'data' => array_values($bucket)]]];
    }

    /** Consumable รับเข้า monthly trend */
    function _resolve_consumable_trend(PDO $pdo, ?int $year, ?int $month): array
    {
        $bucket = []; $labels = [];

        if ($year !== null && $month !== null) {
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $bucket[$key] = 0; $labels[] = (string)$d;
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m-%d') AS ymd, COUNT(*) AS cnt
                    FROM consumable_transactions WHERE txn_type='receive'
                      AND YEAR(txn_date)=$year AND MONTH(txn_date)=$month GROUP BY ymd";
            $bk = 'ymd';
        } elseif ($year !== null) {
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%04d-%02d', $year, $m);
                $bucket[$key] = 0; $labels[] = $thaiMonths[$m - 1];
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS ym, COUNT(*) AS cnt
                    FROM consumable_transactions WHERE txn_type='receive' AND YEAR(txn_date)=$year GROUP BY ym";
            $bk = 'ym';
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("first day of -$i month");
                $key = date('Y-m', $ts);
                $bucket[$key] = 0; $labels[] = _thai_month_label($ts);
            }
            $sql = "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS ym, COUNT(*) AS cnt
                    FROM consumable_transactions WHERE txn_type='receive'
                      AND txn_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
                    GROUP BY ym";
            $bk = 'ym';
        }

        try {
            foreach (_safe_rows($pdo, $sql) as $r) {
                if (isset($bucket[$r[$bk]])) $bucket[$r[$bk]] = (int)$r['cnt'];
            }
        } catch (PDOException $e) {}

        return ['shape' => 'timeseries', 'labels' => $labels,
                'series' => [['name' => 'รับเข้า', 'data' => array_values($bucket)]]];
    }

    /** Satisfaction คะแนนเฉลี่ยรายเดือน */
    function _resolve_satisfaction_trend(PDO $pdo, ?int $year, ?int $month): array
    {
        $bucket = []; $labels = [];

        if ($year !== null && $month !== null) {
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $bucket[$key] = null; $labels[] = (string)$d;
            }
            $sql = "SELECT DATE_FORMAT(created_at,'%Y-%m-%d') AS ymd, AVG(rating) AS avg_r
                    FROM satisfaction_surveys WHERE YEAR(created_at)=$year AND MONTH(created_at)=$month
                    GROUP BY ymd";
            $bk = 'ymd';
        } elseif ($year !== null) {
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%04d-%02d', $year, $m);
                $bucket[$key] = null; $labels[] = $thaiMonths[$m - 1];
            }
            $sql = "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, AVG(rating) AS avg_r
                    FROM satisfaction_surveys WHERE YEAR(created_at)=$year GROUP BY ym";
            $bk = 'ym';
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("first day of -$i month");
                $key = date('Y-m', $ts);
                $bucket[$key] = null; $labels[] = _thai_month_label($ts);
            }
            $sql = "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, AVG(rating) AS avg_r
                    FROM satisfaction_surveys
                    WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
                    GROUP BY ym";
            $bk = 'ym';
        }

        try {
            foreach (_safe_rows($pdo, $sql) as $r) {
                if (array_key_exists($r[$bk], $bucket))
                    $bucket[$r[$bk]] = round((float)$r['avg_r'], 2);
            }
        } catch (PDOException $e) {}

        return ['shape' => 'timeseries', 'labels' => $labels,
                'series' => [['name' => 'คะแนนเฉลี่ย', 'data' => array_values($bucket)]]];
    }

    /** Vaccine campaign bookings via LINE — monthly trend */
    function _resolve_vaccine_line_trend(PDO $pdo, ?int $year, ?int $month): array
    {
        $bucket = []; $labels = [];
        $lineWhere = "(u.line_user_id IS NOT NULL OR u.line_user_id_new IS NOT NULL)";
        $statusWhere = "b.status IN ('booked','confirmed','completed')";
        $typeWhere = "c.type = 'vaccine'";

        if ($year !== null && $month !== null) {
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $bucket[$key] = 0; $labels[] = (string)$d;
            }
            $sql = "SELECT DATE_FORMAT(b.created_at,'%Y-%m-%d') AS ymd,
                           COUNT(DISTINCT u.id) AS cnt
                    FROM camp_bookings b
                    JOIN sys_users u ON b.student_id = u.id
                    JOIN camp_list c ON b.campaign_id = c.id
                    WHERE $typeWhere AND $statusWhere AND $lineWhere
                      AND YEAR(b.created_at)=$year AND MONTH(b.created_at)=$month
                    GROUP BY ymd";
            $bk = 'ymd';
        } elseif ($year !== null) {
            $thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%04d-%02d', $year, $m);
                $bucket[$key] = 0; $labels[] = $thaiMonths[$m - 1];
            }
            $sql = "SELECT DATE_FORMAT(b.created_at,'%Y-%m') AS ym,
                           COUNT(DISTINCT u.id) AS cnt
                    FROM camp_bookings b
                    JOIN sys_users u ON b.student_id = u.id
                    JOIN camp_list c ON b.campaign_id = c.id
                    WHERE $typeWhere AND $statusWhere AND $lineWhere
                      AND YEAR(b.created_at)=$year
                    GROUP BY ym";
            $bk = 'ym';
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("first day of -$i month");
                $key = date('Y-m', $ts);
                $bucket[$key] = 0; $labels[] = _thai_month_label($ts);
            }
            $sql = "SELECT DATE_FORMAT(b.created_at,'%Y-%m') AS ym,
                           COUNT(DISTINCT u.id) AS cnt
                    FROM camp_bookings b
                    JOIN sys_users u ON b.student_id = u.id
                    JOIN camp_list c ON b.campaign_id = c.id
                    WHERE $typeWhere AND $statusWhere AND $lineWhere
                      AND b.created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
                    GROUP BY ym";
            $bk = 'ym';
        }

        try {
            foreach (_safe_rows($pdo, $sql) as $r) {
                if (isset($bucket[$r[$bk]])) $bucket[$r[$bk]] = (int)$r['cnt'];
            }
        } catch (PDOException $e) {}

        return ['shape' => 'timeseries', 'labels' => $labels,
                'series' => [['name' => 'ผ่าน LINE', 'data' => array_values($bucket)]]];
    }

    function _thai_month_label(int $ts): string
    {
        static $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        $m = (int)date('n', $ts) - 1;
        $y = (int)date('Y', $ts) + 543;
        return $months[$m] . ' ' . substr((string)$y, -2);
    }
}
