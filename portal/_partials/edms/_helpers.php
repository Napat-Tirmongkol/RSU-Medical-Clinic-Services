<?php
/**
 * portal/_partials/edms/_helpers.php
 * EDMS shared helpers — โหลด/cache รายการประเภทเอกสาร (sys_doc_types)
 */
declare(strict_types=1);

if (!function_exists('edms_ensure_doc_types_schema')) {
    /**
     * Self-heal: สร้างตาราง sys_doc_types + seed 4 ประเภทเริ่มต้น + แปลง ENUM → VARCHAR
     * (เผื่อกรณียังไม่ได้รัน migration migrate_edms_doc_types.php)
     */
    function edms_ensure_doc_types_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_types (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code         VARCHAR(30) NOT NULL UNIQUE,
                name         VARCHAR(120) NOT NULL,
                short_label  VARCHAR(20)  NULL,
                description  VARCHAR(255) NULL,
                icon         VARCHAR(60)  NULL,
                tone         VARCHAR(20)  NULL,
                sort_order   SMALLINT NOT NULL DEFAULT 0,
                is_active    TINYINT(1) NOT NULL DEFAULT 1,
                is_system    TINYINT(1) NOT NULL DEFAULT 0,
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active_sort (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Seed 4 ประเภทเริ่มต้น ถ้ายังไม่มี
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_types")->fetchColumn();
            if ($cnt === 0) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO sys_doc_types
                    (code, name, short_label, description, icon, tone, sort_order, is_system, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)");
                $stmt->execute(['incoming', 'หนังสือรับ',   'รับ',    'รับเข้าจากหน่วยงานภายนอก/ภายใน',  'fa-inbox',       'sky',     10]);
                $stmt->execute(['outgoing', 'หนังสือส่ง',    'ส่ง',    'ออกจากคลินิกไปยังหน่วยงานอื่น',     'fa-paper-plane', 'emerald', 20]);
                $stmt->execute(['internal', 'บันทึกข้อความ', 'บันทึก', 'หนังสือภายในระหว่างฝ่าย',           'fa-file-lines',  'violet',  30]);
                $stmt->execute(['circular', 'หนังสือเวียน',  'เวียน',  'ประกาศ/แจ้งเวียนหลายฝ่าย',         'fa-bullhorn',    'amber',   40]);
                $stmt->execute(['task',     'งาน/Task',     'งาน',    'งานที่มอบหมาย ไม่ผูกกับเอกสารทางการ', 'fa-list-check',  'cyan',    50]);
            }

            // Backfill: ถ้าตารางมีอยู่แล้ว แต่ยังไม่มี task → INSERT IGNORE
            try {
                $pdo->prepare("INSERT IGNORE INTO sys_doc_types (code, name, short_label, description, icon, tone, sort_order, is_system, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)")
                    ->execute(['task', 'งาน/Task', 'งาน', 'งานที่มอบหมาย ไม่ผูกกับเอกสารทางการ', 'fa-list-check', 'cyan', 50]);
            } catch (PDOException) { /* ignore */ }

            // ENUM → VARCHAR (idempotent)
            foreach (['sys_doc_documents', 'sys_doc_counters'] as $t) {
                try {
                    $col = $pdo->query("SHOW COLUMNS FROM {$t} LIKE 'doc_type'")->fetch(PDO::FETCH_ASSOC);
                    if ($col && stripos((string)$col['Type'], 'enum') === 0) {
                        $pdo->exec("ALTER TABLE {$t} MODIFY COLUMN doc_type VARCHAR(30) NOT NULL");
                    }
                } catch (PDOException) { /* table may not exist yet */ }
            }
        } catch (PDOException $e) {
            error_log('[edms_ensure_doc_types_schema] ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('edms_get_doc_types')) {
    /**
     * คืน array ของประเภทเอกสารจาก DB (active เท่านั้น) — เรียงตาม sort_order
     * Cache ภายใน request เดียว
     *
     * @return array<int, array{id:int, code:string, name:string, short_label:string, description:string, icon:string, tone:string, sort_order:int, is_active:int, is_system:int}>
     */
    function edms_get_doc_types(PDO $pdo, bool $activeOnly = true): array
    {
        static $cache = [];
        $key = $activeOnly ? 'active' : 'all';
        if (isset($cache[$key])) return $cache[$key];

        edms_ensure_doc_types_schema($pdo);

        $sql = "SELECT id, code, name, short_label, description, icon, tone, sort_order, is_active, is_system
                FROM sys_doc_types";
        if ($activeOnly) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY sort_order ASC, id ASC";

        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('[edms_get_doc_types] ' . $e->getMessage());
            $rows = [];
        }
        $cache[$key] = $rows;
        return $rows;
    }
}

if (!function_exists('edms_get_doc_type_map')) {
    /**
     * คืน map code → row (ใช้แทน hardcoded $typeMap)
     *
     * @return array<string, array>
     */
    function edms_get_doc_type_map(PDO $pdo, bool $activeOnly = true): array
    {
        $rows = edms_get_doc_types($pdo, $activeOnly);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['code']] = $r;
        }
        return $map;
    }
}

if (!function_exists('edms_valid_doc_type')) {
    /**
     * ตรวจว่าค่า doc_type ที่รับมา ตรงกับประเภทใน DB หรือไม่ (เปิดใช้งานอยู่)
     */
    function edms_valid_doc_type(PDO $pdo, string $code): bool
    {
        if ($code === '') return false;
        return isset(edms_get_doc_type_map($pdo, true)[$code]);
    }
}
