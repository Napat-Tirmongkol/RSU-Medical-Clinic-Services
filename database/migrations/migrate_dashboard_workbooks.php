<?php
/**
 * database/migrations/migrate_dashboard_workbooks.php
 *
 * เพิ่มความสามารถ multi-workbook (Tableau-style)
 *  - ins_dashboard_workbooks       : ทะเบียน workbook
 *  - ins_dashboard_widgets.workbook_id : FK เพื่อ scope widget แต่ละตัวให้ workbook
 *  - Seed 'Default' workbook + ย้าย widget เดิมเข้า Default
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── ins_dashboard_workbooks ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ins_dashboard_workbooks (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug          VARCHAR(80)   NOT NULL,
        name          VARCHAR(150)  NOT NULL,
        description   VARCHAR(500)  NOT NULL DEFAULT '',
        icon          VARCHAR(60)   NOT NULL DEFAULT 'fa-chart-pie',
        color         VARCHAR(20)   NOT NULL DEFAULT 'blue',
        is_public     TINYINT(1)    NOT NULL DEFAULT 0,
        is_default    TINYINT(1)    NOT NULL DEFAULT 0,
        sort_order    INT UNSIGNED  NOT NULL DEFAULT 0,
        created_by    INT UNSIGNED  NULL,
        created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slug (slug),
        INDEX idx_public  (is_public),
        INDEX idx_default (is_default),
        INDEX idx_sort    (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ ins_dashboard_workbooks — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ ins_dashboard_workbooks: ' . $e->getMessage();
}

// ── Seed 'Default' workbook ───────────────────────────────────────────────────
$defaultId = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM ins_dashboard_workbooks WHERE slug = 'default' LIMIT 1");
    $stmt->execute();
    $defaultId = (int)$stmt->fetchColumn();

    if (!$defaultId) {
        $pdo->prepare("INSERT INTO ins_dashboard_workbooks
            (slug, name, description, icon, color, is_public, is_default, sort_order)
            VALUES ('default', 'ภาพรวม', 'Workbook หลัก — ภาพรวมประกัน + บัตรทอง',
                    'fa-chart-pie', 'blue', 1, 1, 1)")
            ->execute();
        $defaultId = (int)$pdo->lastInsertId();
        $results[] = "✅ สร้าง Default workbook (id=$defaultId)";
    } else {
        $results[] = "ℹ️ Default workbook มีอยู่แล้ว (id=$defaultId)";
    }
} catch (PDOException $e) {
    $results[] = '❌ Seed default: ' . $e->getMessage();
}

// ── เพิ่ม column workbook_id ใน ins_dashboard_widgets ────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM ins_dashboard_widgets")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('workbook_id', $cols, true)) {
        $pdo->exec("ALTER TABLE ins_dashboard_widgets
                    ADD COLUMN workbook_id INT UNSIGNED NULL AFTER id,
                    ADD INDEX idx_workbook (workbook_id)");
        $results[] = '✅ เพิ่ม column workbook_id';

        // ย้าย widget เดิมทั้งหมดเข้า Default workbook
        if ($defaultId) {
            $n = $pdo->prepare("UPDATE ins_dashboard_widgets SET workbook_id = ? WHERE workbook_id IS NULL");
            $n->execute([$defaultId]);
            $results[] = '✅ ย้าย widget ' . $n->rowCount() . ' ตัวเข้า Default workbook';
        }
    } else {
        $results[] = 'ℹ️ column workbook_id มีอยู่แล้ว';
        // ตรวจ widget ที่ยัง NULL → ใส่เข้า Default
        if ($defaultId) {
            $n = $pdo->prepare("UPDATE ins_dashboard_widgets SET workbook_id = ? WHERE workbook_id IS NULL");
            $n->execute([$defaultId]);
            if ($n->rowCount() > 0) $results[] = '✅ ย้าย widget orphan ' . $n->rowCount() . ' ตัวเข้า Default';
        }
    }
} catch (PDOException $e) {
    $results[] = '❌ ALTER widgets: ' . $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Dashboard Workbooks</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — ลบไฟล์นี้หลังรันสำเร็จ</p>";
