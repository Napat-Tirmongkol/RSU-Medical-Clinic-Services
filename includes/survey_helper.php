<?php
// includes/survey_helper.php — schema + helpers สำหรับระบบแบบสอบถาม (post-checkin + system)
declare(strict_types=1);

/**
 * สร้าง/ปรับ schema ของระบบ survey ให้พร้อมใช้งาน — เรียกได้บ่อย, idempotent
 *
 * - satisfaction_surveys: เพิ่ม booking_id, survey_type
 * - camp_bookings: เพิ่ม survey_done_at
 * - sys_survey_questions: คำถาม configurable (rating/text/single_choice)
 * - sys_survey_answers: คำตอบรายข้อ (1 survey → N answers)
 *
 * เสริม: seed คำถามตั้งต้นสำหรับ survey_type='post_checkin' ถ้ายังไม่มีเลย
 */
function ensure_survey_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS satisfaction_surveys (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rating       TINYINT      NOT NULL,
            comment      TEXT,
            page_context VARCHAR(100) DEFAULT NULL,
            ip_hash      VARCHAR(64)  DEFAULT NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_rating  (rating)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException) {}

    // เพิ่มคอลัมน์ใหม่ในตารางเดิม — แต่ละ ALTER แยก try/catch กันชนกรณี column มีแล้ว
    try { $pdo->exec("ALTER TABLE satisfaction_surveys ADD COLUMN booking_id INT NULL AFTER id"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE satisfaction_surveys ADD COLUMN survey_type VARCHAR(40) NOT NULL DEFAULT 'system' AFTER booking_id"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE satisfaction_surveys ADD COLUMN student_id INT NULL AFTER survey_type"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE satisfaction_surveys ADD INDEX idx_booking (booking_id)"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE satisfaction_surveys ADD INDEX idx_survey_type (survey_type)"); } catch (PDOException) {}

    try { $pdo->exec("ALTER TABLE camp_bookings ADD COLUMN survey_done_at DATETIME NULL AFTER attended_at"); } catch (PDOException) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_survey_questions (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            survey_type   VARCHAR(40) NOT NULL DEFAULT 'post_checkin',
            question_text VARCHAR(255) NOT NULL,
            answer_type   ENUM('rating','text','single_choice') NOT NULL DEFAULT 'rating',
            options_json  TEXT NULL,
            is_required   TINYINT(1) NOT NULL DEFAULT 1,
            sort_order    INT NOT NULL DEFAULT 0,
            is_active     TINYINT(1) NOT NULL DEFAULT 1,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type_active (survey_type, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_survey_answers (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            survey_id     INT UNSIGNED NOT NULL,
            question_id   INT NOT NULL,
            value_text    TEXT NULL,
            value_rating  TINYINT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_survey (survey_id),
            INDEX idx_question (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}

    // Seed คำถามตั้งต้นสำหรับ post_checkin ถ้ายังไม่มี
    try {
        $hasAny = (int)$pdo->query("SELECT COUNT(*) FROM sys_survey_questions WHERE survey_type = 'post_checkin'")->fetchColumn();
        if ($hasAny === 0) {
            $defaults = [
                ['ความพึงพอใจโดยรวมต่อบริการครั้งนี้', 'rating', null, 1, 1],
                ['การให้บริการของเจ้าหน้าที่/แพทย์', 'rating', null, 1, 2],
                ['เวลาที่ใช้รอ', 'single_choice', json_encode(['เร็วกว่าที่คาด','พอดี','รอนาน'], JSON_UNESCAPED_UNICODE), 1, 3],
                ['ข้อเสนอแนะเพิ่มเติม', 'text', null, 0, 4],
            ];
            $ins = $pdo->prepare("INSERT INTO sys_survey_questions
                (survey_type, question_text, answer_type, options_json, is_required, sort_order)
                VALUES ('post_checkin', :q, :t, :o, :r, :s)");
            foreach ($defaults as [$q, $t, $o, $r, $s]) {
                $ins->execute([':q'=>$q, ':t'=>$t, ':o'=>$o, ':r'=>$r, ':s'=>$s]);
            }
        }
    } catch (PDOException) {}
}

/**
 * ดึงคำถามทั้งหมดสำหรับ survey_type ที่ระบุ (เฉพาะ active เรียงตาม sort_order)
 *
 * @return list<array<string,mixed>>
 */
function get_survey_questions(PDO $pdo, string $surveyType = 'post_checkin'): array
{
    try {
        $stmt = $pdo->prepare("SELECT id, question_text, answer_type, options_json, is_required, sort_order
            FROM sys_survey_questions
            WHERE survey_type = :t AND is_active = 1
            ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':t' => $surveyType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException) {
        return [];
    }
}

/**
 * เช็คว่า booking ID นี้ทำ post-checkin survey แล้วหรือยัง
 */
function booking_has_pending_survey(PDO $pdo, int $bookingId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT attended_at, survey_done_at FROM camp_bookings WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return !empty($row['attended_at']) && empty($row['survey_done_at']);
    } catch (PDOException) {
        return false;
    }
}

/**
 * ส่ง LINE flex bubble เตือนให้ user ทำแบบสอบถามหลังเช็คอิน
 * (ใช้กรณี staff scan หรือ admin manual check-in — user ไม่ได้อยู่หน้าจอ self-checkin)
 *
 * Best-effort: คืน true ถ้ายิงสำเร็จ, false ถ้าผิดพลาด (ไม่ throw — ไม่บล็อกการเช็คอิน)
 */
function send_post_checkin_survey_reminder(PDO $pdo, int $bookingId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT u.line_user_id, u.line_user_id_new, c.title AS campaign_title
            FROM camp_bookings b
            JOIN sys_users u ON b.student_id = u.id
            LEFT JOIN camp_list c ON b.campaign_id = c.id
            WHERE b.id = :id LIMIT 1");
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $lineUid = $row['line_user_id_new'] ?: $row['line_user_id'] ?: '';
        if ($lineUid === '') return false;
    } catch (PDOException) {
        return false;
    }

    // โหลด token + LIFF URL
    $secrets = file_exists(__DIR__ . '/../config/secrets.php')
        ? require __DIR__ . '/../config/secrets.php'
        : [];
    $token = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN']
          ?? $secrets['EBORROW_LINE_MESSAGE_TOKEN']
          ?? '';
    if ($token === '') return false;

    // สร้าง URL ของหน้า survey
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'healthycampus.rsu.ac.th';
    // จาก SCRIPT_NAME ตัด /admin หรือ /staff หรือ /api หรือ /portal ทิ้ง เพื่อให้ได้ app root
    $dir   = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base  = preg_replace('#/(admin|staff|api|portal)(/.*)?$#', '', rtrim($dir, '/')) ?: '';
    $surveyUrl = $proto . '://' . $host . $base . '/user/post_checkin_survey.php?booking=' . $bookingId;

    $campaign = (string)($row['campaign_title'] ?? 'กิจกรรม');

    $messages = [[
        'type'    => 'flex',
        'altText' => 'กรุณาทำแบบสอบถามหลังเช็คอิน · ' . $campaign,
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical',
                'backgroundColor' => '#EC4899',
                'contents' => [[
                    'type' => 'text', 'text' => 'แบบสอบถามหลังเช็คอิน',
                    'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'md', 'align' => 'center',
                ]],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => 'ขอบคุณที่เข้าร่วม', 'size' => 'sm', 'color' => '#475569'],
                    ['type' => 'text', 'text' => $campaign, 'size' => 'md', 'weight' => 'bold', 'wrap' => true, 'color' => '#0F172A'],
                    ['type' => 'separator', 'margin' => 'md'],
                    ['type' => 'text', 'text' => 'กรุณาสละเวลาสักครู่เพื่อตอบแบบสอบถาม จะช่วยให้เราพัฒนาบริการได้ดียิ่งขึ้น', 'size' => 'sm', 'wrap' => true, 'color' => '#64748B', 'margin' => 'md'],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [[
                    'type' => 'button', 'style' => 'primary', 'color' => '#DB2777', 'height' => 'sm',
                    'action' => ['type' => 'uri', 'label' => 'ทำแบบสอบถามตอนนี้', 'uri' => $surveyUrl],
                ]],
            ],
        ],
    ]];

    if (!function_exists('send_line_push')) {
        require_once __DIR__ . '/line_helper.php';
    }
    return send_line_push($lineUid, $messages, $token);
}

/**
 * ดึง booking ที่ student คนนี้เช็คอินแล้วแต่ยังไม่ทำ survey (ใช้สำหรับ hub lock + LINE follow-up)
 *
 * @return array<string,mixed>|null
 */
function find_pending_survey_booking(PDO $pdo, int $studentId): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT b.id, b.campaign_id, b.attended_at, c.title AS campaign_name
            FROM camp_bookings b
            LEFT JOIN camp_list c ON b.campaign_id = c.id
            WHERE b.student_id = :sid
              AND b.attended_at IS NOT NULL
              AND b.survey_done_at IS NULL
            ORDER BY b.attended_at DESC
            LIMIT 1");
        $stmt->execute([':sid' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException) {
        return null;
    }
}
