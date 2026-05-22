<?php
// portal/services/ai/DataAssistant.php
declare(strict_types=1);

class DataAssistant {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Translate human-friendly period code → ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']
     */
    private function resolvePeriod(string $period): array {
        $today = date('Y-m-d');
        switch ($period) {
            case 'today':       return ['from' => $today, 'to' => $today];
            case 'yesterday':   $y = date('Y-m-d', strtotime('-1 day')); return ['from' => $y, 'to' => $y];
            case 'last_7_days': return ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today];
            case 'last_30_days':return ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today];
            case 'this_month':  return ['from' => date('Y-m-01'), 'to' => $today];
            case 'last_month':
                $first = date('Y-m-01', strtotime('first day of last month'));
                $last  = date('Y-m-t', strtotime($first));
                return ['from' => $first, 'to' => $last];
            case 'this_year':   return ['from' => date('Y-01-01'), 'to' => $today];
            case 'last_year':
                $y = (int)date('Y') - 1;
                return ['from' => "$y-01-01", 'to' => "$y-12-31"];
            default:            return ['from' => date('Y-m-01'), 'to' => $today]; // fallback: this month
        }
    }

    /**
     * Define the tools (function declarations) that the AI can call
     */
    public function getToolDefinitions(): array {
        return [[
            'function_declarations' => [
                // ──────────────── Campaigns / Bookings (เดิม) ────────────────
                [
                    'name'        => 'get_system_overview',
                    'description' => 'ดึงภาพรวมแคมเปญและการจอง (จำนวนแคมเปญ, โควต้ารวม, การจองทั้งหมด, ยืนยัน/รอ/ยกเลิก)',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
                ],
                [
                    'name'        => 'get_all_campaigns',
                    'description' => 'ดึงรายชื่อและสถิติการจองของแคมเปญทั้งหมด สามารถกรองตามสถานะได้',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'status' => [
                                'type'        => 'string',
                                'description' => 'กรองตามสถานะ: active=เปิดรับจอง, inactive=ปิด, all=ทั้งหมด',
                                'enum'        => ['active', 'inactive', 'all'],
                            ],
                        ],
                    ],
                ],
                [
                    'name'        => 'get_booking_trend',
                    'description' => 'ดึงแนวโน้มจำนวนการจองรายวัน ใช้วิเคราะห์ทิศทางและความนิยม',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'days' => ['type' => 'integer', 'description' => 'จำนวนวันย้อนหลัง (7/14/30)'],
                        ],
                        'required' => ['days'],
                    ],
                ],
                [
                    'name'        => 'get_cancellation_analysis',
                    'description' => 'ดึงอัตราการยกเลิกการจองแยกตามแคมเปญ เรียงจากอัตราสูงสุด',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
                ],
                [
                    'name'        => 'get_recent_errors',
                    'description' => 'ดึงรายการ Error Logs ล่าสุดของระบบ เพื่อวิเคราะห์สาเหตุของปัญหา',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'จำนวนรายการ (สูงสุด 100)'],
                        ],
                    ],
                ],

                // ──────────────── Finance / Cash Book ────────────────
                [
                    'name'        => 'get_finance_summary',
                    'description' => 'ดึงสรุปรายรับ-รายจ่ายของคลินิกตามช่วงเวลา (Cash Book) — รายรับรวม, รายจ่ายรวม, สุทธิ',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'period' => [
                                'type'        => 'string',
                                'description' => 'ช่วงเวลา',
                                'enum'        => ['today','yesterday','last_7_days','last_30_days','this_month','last_month','this_year','last_year'],
                            ],
                        ],
                    ],
                ],
                [
                    'name'        => 'get_finance_top_categories',
                    'description' => 'ดึงหมวดรายรับหรือรายจ่ายที่มียอดสูงสุด เรียงลำดับ',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'kind'   => ['type' => 'string', 'enum' => ['income','expense'], 'description' => 'income=รายรับ, expense=รายจ่าย'],
                            'period' => ['type' => 'string', 'enum' => ['this_month','last_month','this_year','last_30_days']],
                            'limit'  => ['type' => 'integer', 'description' => 'จำนวนหมวดที่จะแสดง (default 10)'],
                        ],
                        'required' => ['kind'],
                    ],
                ],
                [
                    'name'        => 'get_finance_recent_transactions',
                    'description' => 'ดึงรายการเดินบัญชีล่าสุด (รายรับ/รายจ่าย)',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'จำนวนรายการ (default 15, สูงสุด 50)'],
                            'kind'  => ['type' => 'string', 'enum' => ['income','expense','all'], 'description' => 'ประเภท'],
                        ],
                    ],
                ],

                // ──────────────── Doctor Schedule ────────────────
                [
                    'name'        => 'get_doctor_schedule_today',
                    'description' => 'ดึงตารางหมอที่ออกตรวจในวันที่กำหนด (default = วันนี้) — รวม override + recurring',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'รูปแบบ YYYY-MM-DD (ว่าง=วันนี้)'],
                        ],
                    ],
                ],
                [
                    'name'        => 'get_doctor_schedule_week',
                    'description' => 'สรุปจำนวนเวรของหมอแต่ละคนในสัปดาห์นี้/สัปดาห์หน้า',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'week' => ['type' => 'string', 'enum' => ['this','next'], 'description' => 'this=สัปดาห์นี้, next=สัปดาห์หน้า'],
                        ],
                    ],
                ],

                // ──────────────── Inventory ────────────────
                [
                    'name'        => 'get_low_stock_consumables',
                    'description' => 'ดึงวัสดุสิ้นเปลืองที่คงเหลือต่ำกว่าจุดสั่งซื้อ (qty_on_hand ≤ min_stock) — ใช้เตือนสั่งซื้อ',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'จำนวนรายการ (default 20)'],
                        ],
                    ],
                ],
                [
                    'name'        => 'get_asset_summary',
                    'description' => 'สรุปครุภัณฑ์: จำนวนรวม, มูลค่ารวม, ที่ใกล้หมดประกัน (≤90 วัน), ที่หมดประกันแล้ว',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
                ],

                // ──────────────── Users / Activity ────────────────
                [
                    'name'        => 'get_user_stats',
                    'description' => 'สถิติผู้ใช้งานระบบ: จำนวน user ทั้งหมด, ที่ผูก LINE, ที่ login ใน 30 วันล่าสุด',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
                ],
                [
                    'name'        => 'get_recent_activities',
                    'description' => 'ดึง activity log ล่าสุดของระบบ (admin actions, logins, edits)',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit'  => ['type' => 'integer', 'description' => 'จำนวนรายการ (default 15, สูงสุด 100)'],
                            'action' => ['type' => 'string', 'description' => 'กรอง action เช่น LOGIN, UPDATE, DELETE (optional)'],
                        ],
                    ],
                ],

                // ──────────────── System-wide overview ────────────────
                [
                    'name'        => 'get_module_overview',
                    'description' => 'ภาพรวมทุกโมดูล: จำนวน user/แคมเปญ/transaction/ครุภัณฑ์/วัสดุ/หมอ/ห้องตรวจ/error/activity log',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
                ],
            ]
        ]];
    }

    /**
     * Execute a tool call
     */
    public function executeTool(string $name, array $args): array {
        try {
            switch ($name) {
                // ─────────── Campaigns (เดิม) ───────────
                case 'get_system_overview':
                    return $this->pdo->query("
                        SELECT
                            COUNT(DISTINCT c.id) AS แคมเปญทั้งหมด,
                            COUNT(DISTINCT CASE WHEN c.status='active' THEN c.id END) AS แคมเปญที่เปิดอยู่,
                            COALESCE(SUM(c.total_capacity), 0) AS โควต้ารวมทุกแคมเปญ,
                            COUNT(b.id) AS การจองทั้งหมด,
                            COALESCE(SUM(b.status='confirmed'), 0) AS ยืนยันแล้ว,
                            COALESCE(SUM(b.status='booked'), 0) AS รอยืนยัน,
                            COALESCE(SUM(b.status LIKE 'cancelled%'), 0) AS ยกเลิก
                        FROM camp_list c
                        LEFT JOIN camp_bookings b ON b.campaign_id = c.id
                    ")->fetch(PDO::FETCH_ASSOC) ?: [];

                case 'get_all_campaigns':
                    $status = $args['status'] ?? 'all';
                    $where  = $status === 'active' ? "WHERE c.status = 'active'"
                            : ($status === 'inactive' ? "WHERE c.status = 'inactive'" : "");
                    return $this->pdo->query("
                        SELECT
                            c.id AS campaign_id,
                            c.title AS ชื่อแคมเปญ,
                            c.status AS สถานะ,
                            c.total_capacity AS โควต้า,
                            COUNT(b.id) AS จองทั้งหมด,
                            SUM(b.status='confirmed') AS ยืนยันแล้ว,
                            ROUND(COUNT(b.id) / NULLIF(c.total_capacity, 0) * 100, 1) AS อัตราเติมโควต้า_pct
                        FROM camp_list c
                        LEFT JOIN camp_bookings b ON b.campaign_id = c.id
                        $where
                        GROUP BY c.id
                        ORDER BY จองทั้งหมด DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);

                case 'get_booking_trend':
                    $days = max(1, min(365, (int)($args['days'] ?? 7)));
                    $stmt = $this->pdo->prepare("
                        SELECT DATE(created_at) AS วันที่, COUNT(*) AS จำนวนการจอง
                        FROM camp_bookings
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
                        GROUP BY DATE(created_at)
                        ORDER BY วันที่ ASC
                    ");
                    $stmt->execute([':d' => $days]);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);

                case 'get_recent_errors':
                    $limit = max(1, min(100, (int)($args['limit'] ?? 10)));
                    $stmt = $this->pdo->prepare("
                        SELECT level, source, message, created_at
                        FROM sys_error_logs
                        ORDER BY created_at DESC LIMIT :l
                    ");
                    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);

                case 'get_cancellation_analysis':
                    return $this->pdo->query("
                        SELECT
                            c.title AS ชื่อแคมเปญ,
                            COUNT(b.id) AS จองทั้งหมด,
                            SUM(b.status LIKE 'cancelled%') AS ยกเลิก,
                            ROUND(SUM(b.status LIKE 'cancelled%') / NULLIF(COUNT(b.id), 0) * 100, 1) AS อัตราการยกเลิก_pct
                        FROM camp_list c
                        LEFT JOIN camp_bookings b ON b.campaign_id = c.id
                        GROUP BY c.id
                        HAVING จองทั้งหมด > 0
                        ORDER BY อัตราการยกเลิก_pct DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);

                // ─────────── Finance ───────────
                case 'get_finance_summary': {
                    $p = $this->resolvePeriod($args['period'] ?? 'this_month');
                    $stmt = $this->pdo->prepare("
                        SELECT
                            COALESCE(SUM(CASE WHEN kind='income'  THEN amount END), 0) AS รายรับรวม,
                            COALESCE(SUM(CASE WHEN kind='expense' THEN amount END), 0) AS รายจ่ายรวม,
                            COALESCE(SUM(CASE WHEN kind='income'  THEN amount END), 0)
                          - COALESCE(SUM(CASE WHEN kind='expense' THEN amount END), 0) AS สุทธิ,
                            COUNT(CASE WHEN kind='income'  THEN 1 END) AS จำนวนรายการรายรับ,
                            COUNT(CASE WHEN kind='expense' THEN 1 END) AS จำนวนรายการรายจ่าย
                        FROM sys_finance_transactions
                        WHERE txn_date BETWEEN :f AND :t
                    ");
                    $stmt->execute([':f' => $p['from'], ':t' => $p['to']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $row['ช่วงเวลา'] = "{$p['from']} ถึง {$p['to']}";
                    return $row;
                }

                case 'get_finance_top_categories': {
                    $kind   = ($args['kind'] ?? 'expense') === 'income' ? 'income' : 'expense';
                    $p      = $this->resolvePeriod($args['period'] ?? 'this_month');
                    $limit  = max(1, min(30, (int)($args['limit'] ?? 10)));
                    $stmt   = $this->pdo->prepare("
                        SELECT
                            COALESCE(cat.name, '(ไม่ระบุหมวด)') AS หมวด,
                            COUNT(t.id) AS จำนวนรายการ,
                            ROUND(SUM(t.amount), 2) AS ยอดรวม
                        FROM sys_finance_transactions t
                        LEFT JOIN sys_finance_categories cat ON cat.id = t.category_id
                        WHERE t.kind = :k AND t.txn_date BETWEEN :f AND :t
                        GROUP BY cat.id, cat.name
                        ORDER BY ยอดรวม DESC
                        LIMIT :l
                    ");
                    $stmt->bindValue(':k', $kind);
                    $stmt->bindValue(':f', $p['from']);
                    $stmt->bindValue(':t', $p['to']);
                    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                case 'get_finance_recent_transactions': {
                    $limit = max(1, min(50, (int)($args['limit'] ?? 15)));
                    $kind  = $args['kind'] ?? 'all';
                    $kindWhere = in_array($kind, ['income','expense'], true) ? "WHERE t.kind = :k" : "";
                    $sql = "
                        SELECT
                            t.txn_date AS วันที่,
                            t.kind AS ประเภท,
                            COALESCE(cat.name, '(ไม่ระบุ)') AS หมวด,
                            t.amount AS จำนวนเงิน,
                            t.description AS รายละเอียด,
                            t.reference AS อ้างอิง
                        FROM sys_finance_transactions t
                        LEFT JOIN sys_finance_categories cat ON cat.id = t.category_id
                        $kindWhere
                        ORDER BY t.txn_date DESC, t.id DESC
                        LIMIT :l
                    ";
                    $stmt = $this->pdo->prepare($sql);
                    if ($kindWhere) $stmt->bindValue(':k', $kind);
                    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                // ─────────── Doctor Schedule ───────────
                case 'get_doctor_schedule_today': {
                    $date = !empty($args['date']) ? (string)$args['date'] : date('Y-m-d');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
                    $wd = (int)date('w', strtotime($date)); // 0=Sun..6=Sat

                    // Overrides + off ของวันนั้น
                    $stmt = $this->pdo->prepare("
                        SELECT ms.full_name AS หมอ, s.start_time AS เริ่ม, s.end_time AS สิ้นสุด,
                               s.type AS ประเภท, cr.name AS ห้อง, s.note AS หมายเหตุ
                        FROM sys_doctor_schedule s
                        LEFT JOIN sys_medical_staff ms ON ms.id = s.staff_id
                        LEFT JOIN sys_clinic_rooms  cr ON cr.id = s.room_id
                        WHERE s.is_active = 1 AND s.specific_date = :d
                        ORDER BY s.start_time ASC
                    ");
                    $stmt->execute([':d' => $date]);
                    $specifics = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Recurring ของวัน weekday นั้น (ตัดออกถ้ามี override ในวันเดียวกัน)
                    $stmt = $this->pdo->prepare("
                        SELECT ms.full_name AS หมอ, s.start_time AS เริ่ม, s.end_time AS สิ้นสุด,
                               s.type AS ประเภท, cr.name AS ห้อง, s.note AS หมายเหตุ
                        FROM sys_doctor_schedule s
                        LEFT JOIN sys_medical_staff ms ON ms.id = s.staff_id
                        LEFT JOIN sys_clinic_rooms  cr ON cr.id = s.room_id
                        WHERE s.is_active = 1
                          AND s.type = 'regular'
                          AND s.weekday = :w
                          AND (s.recur_end_date IS NULL OR s.recur_end_date >= :d)
                          AND s.staff_id NOT IN (
                              SELECT IFNULL(staff_id,0) FROM sys_doctor_schedule
                              WHERE is_active = 1 AND specific_date = :d2
                          )
                        ORDER BY s.start_time ASC
                    ");
                    $stmt->execute([':w' => $wd, ':d' => $date, ':d2' => $date]);
                    $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    return [
                        'วันที่' => $date,
                        'รายการรวม' => count($specifics) + count($recurring),
                        'เวรเฉพาะวันนั้น' => $specifics,
                        'เวรประจำสัปดาห์' => $recurring,
                    ];
                }

                case 'get_doctor_schedule_week': {
                    $week  = ($args['week'] ?? 'this') === 'next' ? 'next' : 'this';
                    $start = $week === 'next'
                        ? date('Y-m-d', strtotime('next monday'))
                        : date('Y-m-d', strtotime('monday this week'));
                    $end   = date('Y-m-d', strtotime("$start +6 days"));
                    $stmt = $this->pdo->prepare("
                        SELECT ms.full_name AS หมอ,
                               COUNT(*) AS จำนวนเวร,
                               GROUP_CONCAT(DISTINCT s.type) AS ประเภทเวร
                        FROM sys_doctor_schedule s
                        LEFT JOIN sys_medical_staff ms ON ms.id = s.staff_id
                        WHERE s.is_active = 1
                          AND (
                              (s.specific_date BETWEEN :f AND :t)
                              OR (s.type = 'regular' AND (s.recur_end_date IS NULL OR s.recur_end_date >= :f2))
                          )
                        GROUP BY ms.id, ms.full_name
                        ORDER BY จำนวนเวร DESC
                    ");
                    $stmt->execute([':f' => $start, ':t' => $end, ':f2' => $start]);
                    return [
                        'สัปดาห์' => "$start ถึง $end",
                        'รายชื่อหมอ' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                    ];
                }

                // ─────────── Inventory ───────────
                case 'get_low_stock_consumables': {
                    $limit = max(1, min(100, (int)($args['limit'] ?? 20)));
                    $stmt = $this->pdo->prepare("
                        SELECT code AS รหัส, name AS ชื่อ, brand AS ยี่ห้อ,
                               qty_on_hand AS คงเหลือ, min_stock AS จุดสั่งซื้อ, unit_piece AS หน่วย,
                               (min_stock - qty_on_hand) AS ขาดอีก
                        FROM consumables
                        WHERE status = 'active' AND qty_on_hand <= min_stock
                        ORDER BY (min_stock - qty_on_hand) DESC
                        LIMIT :l
                    ");
                    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    return [
                        'จำนวนวัสดุที่ต่ำกว่า_min_stock' => count($rows),
                        'รายการ' => $rows,
                    ];
                }

                case 'get_asset_summary': {
                    $today = date('Y-m-d');
                    $in90  = date('Y-m-d', strtotime('+90 days'));
                    $stmt = $this->pdo->prepare("
                        SELECT
                            COUNT(*) AS จำนวนรายการ,
                            COALESCE(SUM(quantity), 0) AS จำนวนรวม,
                            COALESCE(SUM(price * quantity), 0) AS มูลค่ารวม,
                            SUM(CASE WHEN warranty_until IS NOT NULL AND warranty_until BETWEEN :t AND :n90 THEN 1 ELSE 0 END) AS ใกล้หมดประกัน_90วัน,
                            SUM(CASE WHEN warranty_until IS NOT NULL AND warranty_until <  :t2 THEN 1 ELSE 0 END) AS หมดประกันแล้ว
                        FROM assets
                    ");
                    $stmt->execute([':t' => $today, ':n90' => $in90, ':t2' => $today]);
                    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                }

                // ─────────── Users / Activity ───────────
                case 'get_user_stats': {
                    $stmt = $this->pdo->query("
                        SELECT
                            COUNT(*) AS ผู้ใช้ทั้งหมด,
                            SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' THEN 1 ELSE 0 END) AS ผูก_LINE_แล้ว,
                            SUM(CASE WHEN line_user_id_new IS NOT NULL AND line_user_id_new <> '' THEN 1 ELSE 0 END) AS ผูก_LINE_channel_ใหม่
                        FROM sys_users
                    ");
                    $base = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                    // Active (login) 30 days — อ่านจาก activity log
                    try {
                        $stmt = $this->pdo->query("
                            SELECT COUNT(DISTINCT user_id) AS active_30d
                            FROM sys_activity_logs
                            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                              AND action LIKE '%LOGIN%'
                        ");
                        $active = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $base['Active_user_30วัน'] = (int)($active['active_30d'] ?? 0);
                    } catch (Throwable $e) { /* table may not exist */ }

                    return $base;
                }

                case 'get_recent_activities': {
                    $limit  = max(1, min(100, (int)($args['limit'] ?? 15)));
                    $action = trim((string)($args['action'] ?? ''));
                    $where  = $action ? "WHERE action LIKE :a" : "";
                    $sql = "
                        SELECT timestamp AS เวลา, user_name AS ผู้ใช้, action AS การกระทำ,
                               module AS โมดูล, target_type AS ประเภทเป้าหมาย, description AS รายละเอียด
                        FROM sys_activity_logs
                        $where
                        ORDER BY timestamp DESC
                        LIMIT :l
                    ";
                    $stmt = $this->pdo->prepare($sql);
                    if ($action) $stmt->bindValue(':a', "%$action%");
                    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                // ─────────── System overview ───────────
                case 'get_module_overview': {
                    $count = function (string $sql) {
                        try { return (int)$this->pdo->query($sql)->fetchColumn(); }
                        catch (Throwable $e) { return null; }
                    };
                    return [
                        'ผู้ใช้งานระบบ'      => $count("SELECT COUNT(*) FROM sys_users"),
                        'แคมเปญทั้งหมด'      => $count("SELECT COUNT(*) FROM camp_list"),
                        'การจองทั้งหมด'      => $count("SELECT COUNT(*) FROM camp_bookings"),
                        'รายการเดินบัญชี'    => $count("SELECT COUNT(*) FROM sys_finance_transactions"),
                        'ครุภัณฑ์'           => $count("SELECT COUNT(*) FROM assets"),
                        'วัสดุสิ้นเปลือง'     => $count("SELECT COUNT(*) FROM consumables WHERE status='active'"),
                        'หมอ_บุคลากรการแพทย์' => $count("SELECT COUNT(*) FROM sys_medical_staff WHERE is_active = 1"),
                        'ห้องตรวจ'           => $count("SELECT COUNT(*) FROM sys_clinic_rooms WHERE is_active = 1"),
                        'เวรแพทย์_active'    => $count("SELECT COUNT(*) FROM sys_doctor_schedule WHERE is_active = 1"),
                        'Error_logs'         => $count("SELECT COUNT(*) FROM sys_error_logs"),
                        'Activity_logs_30วัน' => $count("SELECT COUNT(*) FROM sys_activity_logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
                    ];
                }

                default:
                    return ['error' => "Unknown tool: $name"];
            }
        } catch (Throwable $e) {
            // Graceful fallback — แจ้ง AI ว่า tool fail ทำไม
            return [
                'error'   => $e->getMessage(),
                'hint'    => 'อาจเป็นเพราะตารางที่ tool ต้องใช้ยังไม่ติดตั้งในระบบนี้ — แจ้งผู้ใช้ว่าโมดูลที่เกี่ยวข้องยังไม่พร้อมใช้งาน',
                'tool'    => $name,
            ];
        }
    }
}
