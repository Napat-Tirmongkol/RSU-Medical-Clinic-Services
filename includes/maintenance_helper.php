<?php
/**
 * includes/maintenance_helper.php
 *
 * เก็บสถานะ maintenance ทั้งหมด (gold_card_apply / e_campaign / e_borrow /
 * announcement / whitelist) ลง DB เพื่อกัน git pull เขียนทับค่าใน
 * config/maintenance.json
 *
 * Pattern:
 *   - Read:  load file + load DB (DB ทับ file) → ถ้า DB มีค่าที่ต่าง file
 *            จะ self-heal เขียน file ใหม่ทันที (กัน git pull ในรอบหน้า)
 *   - Write: เขียนทั้ง file + DB เสมอ — file = cache ที่อ่านเร็ว
 *            DB = source of truth ที่ git ไม่กระทบ
 *
 * Keys เก็บใน sys_site_settings โดย prefix "maint." (เช่น maint.gold_card_apply)
 * เพื่อแยกจาก site settings อื่น
 */
declare(strict_types=1);

if (!function_exists('maint_file_path')) {
    function maint_file_path(): string
    {
        return dirname(__DIR__) . '/config/maintenance.json';
    }
}

if (!function_exists('maint_load')) {
    /**
     * โหลด maintenance state แบบรวม (file + DB) — DB ทับ file
     * ถ้า DB มีค่าที่ต่าง file → เขียน file ใหม่ทันที (self-heal)
     */
    function maint_load(): array
    {
        $file = maint_file_path();
        $fileData = [];
        if (file_exists($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) $fileData = $decoded;
        }

        try {
            $pdo = db();
            $rows = $pdo->query("SELECT setting_key, setting_value FROM sys_site_settings
                                 WHERE setting_key LIKE 'maint.%'")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $dbData = [];
            foreach ($rows as $k => $v) {
                if (!str_starts_with((string)$k, 'maint.')) continue;
                $key = substr((string)$k, 6); // strip prefix
                // ค่าใน DB อาจเป็น JSON (สำหรับ bool/array) หรือ string ปกติ
                $decoded = json_decode((string)$v, true);
                $dbData[$key] = ($decoded !== null || $v === 'null') ? $decoded : $v;
            }
            if (!$dbData) return $fileData;

            // DB ทับ file
            $merged = array_merge($fileData, $dbData);

            // Self-heal: ถ้า file ต่างจาก merged (เช่นถูก git pull เขียนทับ) → sync file
            if ($merged !== $fileData) {
                @file_put_contents($file, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            return $merged;
        } catch (Throwable $e) {
            error_log('[maint_load] ' . $e->getMessage());
            return $fileData;
        }
    }
}

if (!function_exists('maint_save')) {
    /**
     * บันทึก maintenance state ทั้ง file + DB
     * - file: เขียน array ทั้งก้อน (overwrite)
     * - DB: upsert ทีละ key ด้วย prefix "maint."
     */
    function maint_save(array $data): bool
    {
        $file = maint_file_path();
        $jsonOk = @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;

        try {
            $pdo = db();
            // เผื่อ sys_site_settings ยังไม่ถูกสร้าง (config.php สร้างให้แล้วแต่กันเหนียว)
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_site_settings (
                setting_key   VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $pdo->prepare("INSERT INTO sys_site_settings (setting_key, setting_value)
                                   VALUES (:k, :v)
                                   ON DUPLICATE KEY UPDATE setting_value = :v2");
            foreach ($data as $k => $v) {
                $encoded = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
                $stmt->execute([':k' => "maint.$k", ':v' => $encoded, ':v2' => $encoded]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('[maint_save] ' . $e->getMessage());
            return $jsonOk;
        }
    }
}
