<?php
// portal/services/ai/DataAssistant.php
declare(strict_types=1);

class DataAssistant {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Define the tools (function declarations) that the AI can call
     */
    public function getToolDefinitions(): array {
        return [[
            'function_declarations' => [
                [
                    'name'        => 'get_system_overview',
                    'description' => 'ดึงภาพรวมตัวเลขสรุปของระบบทั้งหมด (จำนวนแคมเปญ, การจอง, สถานะ)',
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
                            'days' => [
                                'type'        => 'integer',
                                'description' => 'จำนวนวันย้อนหลังที่ต้องการ เช่น 7, 14, 30',
                            ],
                        ],
                        'required' => ['days'],
                    ],
                ],
                [
                    'name'        => 'get_recent_errors',
                    'description' => 'ดึงรายการข้อผิดพลาดล่าสุด (Error Logs) จากระบบ เพื่อใช้ในการวิเคราะห์สาเหตุของปัญหา',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => [
                                'type'        => 'integer',
                                'description' => 'จำนวนรายการที่ต้องการดึง (สูงสุด 100)',
                            ],
                        ],
                    ],
                ],
                [
                    'name'        => 'get_cancellation_analysis',
                    'description' => 'ดึงอัตราการยกเลิกการจองแยกตามแคมเปญ เรียงจากอัตราสูงสุด',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
                ]
            ]
        ]];
    }

    /**
     * Execute a tool call
     */
    public function executeTool(string $name, array $args): array {
        switch ($name) {
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
                $where = $status === 'active' ? "WHERE c.status = 'active'" : ($status === 'inactive' ? "WHERE c.status = 'inactive'" : "");
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
                $days = (int)($args['days'] ?? 7);
                return $this->pdo->query("
                    SELECT DATE(created_at) AS วันที่, COUNT(*) AS จำนวนการจอง
                    FROM camp_bookings
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY วันที่ ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

            case 'get_recent_errors':
                $limit = (int)($args['limit'] ?? 10);
                return $this->pdo->query("
                    SELECT level, source, message, created_at 
                    FROM sys_error_logs 
                    ORDER BY created_at DESC LIMIT $limit
                ")->fetchAll(PDO::FETCH_ASSOC);

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

            default:
                return ['error' => "Unknown tool: $name"];
        }
    }
}
