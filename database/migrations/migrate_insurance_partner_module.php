<?php
/**
 * database/migrations/migrate_insurance_partner_module.php
 *
 * Module: Insurance Partner Portal
 * - insurance_companies: ทะเบียนบริษัทประกัน (รองรับหลายเจ้า)
 * - insurance_partner_users: บัญชี login ของเจ้าหน้าที่บริษัทประกัน
 * - insurance_partner_activity_log: audit log แยกฝั่ง partner (ISO 27001)
 * - insurance_members: เพิ่ม column insurance_company สำหรับ scope ข้อมูล
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── insurance_companies ───────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_companies (
        company_code   VARCHAR(20)              NOT NULL,
        company_name   VARCHAR(150)             NOT NULL,
        contact_name   VARCHAR(150)             NOT NULL DEFAULT '',
        contact_email  VARCHAR(150)             NOT NULL DEFAULT '',
        contact_phone  VARCHAR(50)              NOT NULL DEFAULT '',
        status         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at     DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (company_code),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed บริษัทเริ่มต้น (เมืองไทยประกันภัย)
    $exists = $pdo->query("SELECT COUNT(*) FROM insurance_companies WHERE company_code = 'MTI'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("INSERT INTO insurance_companies (company_code, company_name) VALUES
            ('MTI', 'เมืองไทยประกันภัย')");
    }

    $results[] = '✅ insurance_companies — สร้าง/seed เรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ insurance_companies: ' . $e->getMessage();
}

// ── insurance_partner_users ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_partner_users (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username        VARCHAR(50)              NOT NULL,
        password_hash   VARCHAR(255)             NOT NULL,
        full_name       VARCHAR(150)             NOT NULL,
        email           VARCHAR(150)             NOT NULL DEFAULT '',
        company_code    VARCHAR(20)              NOT NULL,
        account_status  ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
        failed_logins   TINYINT UNSIGNED         NOT NULL DEFAULT 0,
        locked_until    DATETIME                 NULL,
        last_login_at   DATETIME                 NULL,
        last_login_ip   VARCHAR(45)              NULL,
        created_by      INT UNSIGNED             NULL,
        created_at      DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_username (username),
        INDEX idx_company (company_code),
        INDEX idx_status (account_status),
        CONSTRAINT fk_partner_company
            FOREIGN KEY (company_code) REFERENCES insurance_companies(company_code)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ insurance_partner_users — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ insurance_partner_users: ' . $e->getMessage();
}

// ── insurance_partner_activity_log ────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_partner_activity_log (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        partner_user_id INT UNSIGNED             NULL,
        company_code    VARCHAR(20)              NULL,
        username        VARCHAR(50)              NULL,
        action          VARCHAR(50)              NOT NULL,
        details         TEXT                     NULL,
        ip_address      VARCHAR(45)              NULL,
        user_agent      VARCHAR(255)             NULL,
        created_at      DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_partner (partner_user_id),
        INDEX idx_company (company_code),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ insurance_partner_activity_log — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ insurance_partner_activity_log: ' . $e->getMessage();
}

// ── insurance_members: เพิ่ม column insurance_company ─────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM insurance_members")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('insurance_company', $cols, true)) {
        $pdo->exec("ALTER TABLE insurance_members
                    ADD COLUMN insurance_company VARCHAR(20) NOT NULL DEFAULT 'MTI' AFTER policy_number,
                    ADD INDEX idx_insurance_company (insurance_company)");
        $results[] = '✅ insurance_members — เพิ่ม column insurance_company';
    } else {
        $results[] = 'ℹ️ insurance_members.insurance_company มีอยู่แล้ว';
    }
} catch (PDOException $e) {
    $results[] = '❌ insurance_members ALTER: ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Insurance Partner Module</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>";
