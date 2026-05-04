<?php
// portal/ajax_clinic_master.php — unified AJAX for clinic_data master entities
// Entities: profile (singleton), staff, rooms, hours
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}
validate_csrf_or_die();

$pdo = db();

// ── Auto-migrate all clinic master tables ────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_profile (
        id INT PRIMARY KEY DEFAULT 1,
        name_th VARCHAR(255) NOT NULL DEFAULT '',
        name_en VARCHAR(255) NOT NULL DEFAULT '',
        address_th TEXT NULL,
        address_en TEXT NULL,
        phone VARCHAR(50) NOT NULL DEFAULT '',
        email VARCHAR(150) NOT NULL DEFAULT '',
        line_id VARCHAR(100) NOT NULL DEFAULT '',
        facebook VARCHAR(255) NOT NULL DEFAULT '',
        license_no VARCHAR(100) NOT NULL DEFAULT '',
        operating_hours TEXT NULL,
        notes TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_medical_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(50) NOT NULL DEFAULT '',
        full_name VARCHAR(255) NOT NULL,
        license_no VARCHAR(100) NULL,
        role ENUM('doctor','nurse','pharmacist','dentist','other') NOT NULL DEFAULT 'doctor',
        department VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role (role), INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(150) NOT NULL,
        type ENUM('exam','vaccination','lab','consult','other') NOT NULL DEFAULT 'exam',
        capacity INT NOT NULL DEFAULT 1,
        floor VARCHAR(20) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_code (code), INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('regular','holiday','special') NOT NULL DEFAULT 'regular',
        weekday TINYINT NULL COMMENT '0=Sun..6=Sat',
        specific_date DATE NULL,
        open_time TIME NULL,
        close_time TIME NULL,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type), INDEX idx_date (specific_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'message' => 'Migration failed: ' . $e->getMessage()]);
    exit;
}

$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';

try {
    switch ("$entity:$action") {
        // ── Clinic Profile ────────────────────────────────────────────────
        case 'profile:save':
            // VALUES(col) so each named placeholder appears only once —
            // PDO with EMULATE_PREPARES=false rejects reused names.
            $stmt = $pdo->prepare("INSERT INTO sys_clinic_profile
                (id, name_th, name_en, address_th, address_en, phone, email, line_id, facebook, license_no, operating_hours, notes)
                VALUES (1, :nt, :ne, :at, :ae, :ph, :em, :lid, :fb, :lic, :oh, :no)
                ON DUPLICATE KEY UPDATE
                    name_th = VALUES(name_th),
                    name_en = VALUES(name_en),
                    address_th = VALUES(address_th),
                    address_en = VALUES(address_en),
                    phone = VALUES(phone),
                    email = VALUES(email),
                    line_id = VALUES(line_id),
                    facebook = VALUES(facebook),
                    license_no = VALUES(license_no),
                    operating_hours = VALUES(operating_hours),
                    notes = VALUES(notes)");
            $stmt->execute([
                ':nt'  => trim((string)($_POST['name_th'] ?? '')),
                ':ne'  => trim((string)($_POST['name_en'] ?? '')),
                ':at'  => trim((string)($_POST['address_th'] ?? '')) ?: null,
                ':ae'  => trim((string)($_POST['address_en'] ?? '')) ?: null,
                ':ph'  => trim((string)($_POST['phone'] ?? '')),
                ':em'  => trim((string)($_POST['email'] ?? '')),
                ':lid' => trim((string)($_POST['line_id'] ?? '')),
                ':fb'  => trim((string)($_POST['facebook'] ?? '')),
                ':lic' => trim((string)($_POST['license_no'] ?? '')),
                ':oh'  => trim((string)($_POST['operating_hours'] ?? '')) ?: null,
                ':no'  => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'message' => 'บันทึกข้อมูลคลินิกแล้ว']);
            return;

        // ── Medical Staff ────────────────────────────────────────────────
        case 'staff:add':
            $stmt = $pdo->prepare("INSERT INTO sys_medical_staff (title, full_name, license_no, role, department, phone, email)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim((string)($_POST['title'] ?? '')),
                trim((string)($_POST['full_name'] ?? '')),
                trim((string)($_POST['license_no'] ?? '')) ?: null,
                $_POST['role'] ?? 'doctor',
                trim((string)($_POST['department'] ?? '')) ?: null,
                trim((string)($_POST['phone'] ?? '')) ?: null,
                trim((string)($_POST['email'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่มบุคลากรแล้ว']);
            return;

        case 'staff:delete':
            $pdo->prepare("DELETE FROM sys_medical_staff WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        case 'staff:toggle':
            $pdo->prepare("UPDATE sys_medical_staff SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true]);
            return;

        // ── Rooms ────────────────────────────────────────────────────────
        case 'rooms:add':
            $stmt = $pdo->prepare("INSERT INTO sys_clinic_rooms (code, name, type, capacity, floor, notes)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim((string)($_POST['code'] ?? '')),
                trim((string)($_POST['name'] ?? '')),
                $_POST['type'] ?? 'exam',
                max(1, (int)($_POST['capacity'] ?? 1)),
                trim((string)($_POST['floor'] ?? '')) ?: null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'message' => 'เพิ่มห้องแล้ว']);
            return;

        case 'rooms:delete':
            $pdo->prepare("DELETE FROM sys_clinic_rooms WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        case 'rooms:toggle':
            $pdo->prepare("UPDATE sys_clinic_rooms SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true]);
            return;

        // ── Hours / Holidays ─────────────────────────────────────────────
        case 'hours:add':
            $type = $_POST['type'] ?? 'regular';
            $stmt = $pdo->prepare("INSERT INTO sys_clinic_hours (type, weekday, specific_date, open_time, close_time, is_closed, note)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $type,
                $type === 'regular' ? (int)($_POST['weekday'] ?? 0) : null,
                $type !== 'regular' ? ($_POST['specific_date'] ?? null) : null,
                ($_POST['is_closed'] ?? '0') === '1' ? null : ($_POST['open_time'] ?? null),
                ($_POST['is_closed'] ?? '0') === '1' ? null : ($_POST['close_time'] ?? null),
                ($_POST['is_closed'] ?? '0') === '1' ? 1 : 0,
                trim((string)($_POST['note'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'message' => 'เพิ่มแล้ว']);
            return;

        case 'hours:delete':
            $pdo->prepare("DELETE FROM sys_clinic_hours WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        default:
            echo json_encode(['ok' => false, 'message' => "Unknown action: $entity:$action"]);
            return;
    }
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
