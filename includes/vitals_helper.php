<?php
/**
 * includes/vitals_helper.php
 *
 * Blood pressure helpers — schema ensure + classification + list/CRUD support.
 *
 * Classification: AHA 2017 guidelines (Whelton et al.). Crisis takes
 * precedence over Stage 2, Stage 2 over Stage 1, etc.
 */
declare(strict_types=1);

const VITALS_BP_CLASSIFICATIONS = [
    'normal'   => 'ปกติ',
    'elevated' => 'สูงกว่าปกติ',
    'stage1'   => 'ความดันสูงระยะ 1',
    'stage2'   => 'ความดันสูงระยะ 2',
    'crisis'   => 'วิกฤต (Hypertensive Crisis)',
];

const VITALS_BP_COLORS = [
    'normal'   => ['bg' => '#dcfce7', 'fg' => '#15803d'],
    'elevated' => ['bg' => '#fef9c3', 'fg' => '#a16207'],
    'stage1'   => ['bg' => '#fed7aa', 'fg' => '#c2410c'],
    'stage2'   => ['bg' => '#fee2e2', 'fg' => '#b91c1c'],
    'crisis'   => ['bg' => '#7f1d1d', 'fg' => '#fff'],
];

const VITALS_POSITIONS = [
    'sitting'  => 'นั่ง',
    'standing' => 'ยืน',
    'lying'    => 'นอน',
];
const VITALS_ARMS = [
    'left'  => 'แขนซ้าย',
    'right' => 'แขนขวา',
];

function vitals_bp_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_vitals_bp (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            patient_id INT UNSIGNED NOT NULL,
            systolic  SMALLINT UNSIGNED NOT NULL,
            diastolic SMALLINT UNSIGNED NOT NULL,
            pulse_rate SMALLINT UNSIGNED NULL,
            measured_at DATETIME NOT NULL,
            position ENUM('sitting','standing','lying') NULL DEFAULT 'sitting',
            arm ENUM('left','right') NULL,
            notes VARCHAR(500) NULL,
            classification ENUM('normal','elevated','stage1','stage2','crisis') NULL,
            source ENUM('staff','self') NOT NULL DEFAULT 'staff',
            recorded_by INT UNSIGNED NULL,
            recorded_by_name VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_patient_date (patient_id, measured_at DESC),
            KEY idx_measured_at (measured_at DESC),
            KEY idx_classification (classification, measured_at),
            KEY idx_source_date (source, measured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Idempotent column add for installs that already have the table.
        // Wrapped in try/catch — MySQL ≥8.0.29 supports IF NOT EXISTS but
        // older versions reject it. Catch the error and ignore if the column
        // already exists.
        try { $pdo->exec("ALTER TABLE sys_vitals_bp
                          ADD COLUMN source ENUM('staff','self') NOT NULL DEFAULT 'staff' AFTER classification"); }
        catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_vitals_bp
                          ADD INDEX idx_source_date (source, measured_at)"); }
        catch (PDOException) {}
        $done = true;
    } catch (Throwable $e) {
        error_log('[vitals_bp_ensure_schema] ' . $e->getMessage());
    }
}

/**
 * Classify a BP reading per AHA 2017. Crisis wins over Stage 2 etc.
 */
function vitals_bp_classify(int $systolic, int $diastolic): string
{
    if ($systolic >= 180 || $diastolic >= 120) return 'crisis';
    if ($systolic >= 140 || $diastolic >= 90)  return 'stage2';
    if ($systolic >= 130 || $diastolic >= 80)  return 'stage1';
    if ($systolic >= 120)                       return 'elevated';
    return 'normal';
}

/**
 * Validate + normalize a BP payload from form input. Returns
 * ['ok' => bool, 'data'|'errors' => ...].
 */
function vitals_bp_validate(array $p): array
{
    $errors = [];
    $patient    = (int)($p['patient_id'] ?? 0);
    $systolic   = (int)($p['systolic']   ?? 0);
    $diastolic  = (int)($p['diastolic']  ?? 0);
    $pulse      = isset($p['pulse_rate']) && $p['pulse_rate'] !== ''
                    ? (int)$p['pulse_rate'] : null;
    $measuredAt = trim((string)($p['measured_at'] ?? ''));
    $position   = trim((string)($p['position'] ?? 'sitting'));
    $arm        = trim((string)($p['arm']      ?? ''));
    $notes      = trim((string)($p['notes']    ?? ''));

    if ($patient <= 0)                           $errors['patient_id'] = 'ระบุผู้ป่วย';
    if ($systolic  < 60 || $systolic  > 260)     $errors['systolic']   = 'ค่า SBP อยู่นอกช่วงปกติ (60-260)';
    if ($diastolic < 30 || $diastolic > 180)     $errors['diastolic']  = 'ค่า DBP อยู่นอกช่วงปกติ (30-180)';
    if ($systolic <= $diastolic)                 $errors['diastolic']  = 'DBP ต้องน้อยกว่า SBP';
    if ($pulse !== null && ($pulse < 30 || $pulse > 220))
                                                  $errors['pulse_rate'] = 'ชีพจรอยู่นอกช่วงปกติ (30-220)';
    if ($measuredAt === '')                      $errors['measured_at'] = 'ระบุวัน-เวลาที่วัด';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $measuredAt))
                                                  $errors['measured_at'] = 'รูปแบบวัน-เวลาไม่ถูกต้อง';
    if ($position !== '' && !isset(VITALS_POSITIONS[$position]))
                                                  $errors['position']   = 'ท่าวัดไม่ถูกต้อง';
    if ($arm !== '' && !isset(VITALS_ARMS[$arm])) $errors['arm']        = 'แขนที่วัดไม่ถูกต้อง';
    if (mb_strlen($notes) > 500)                  $errors['notes']      = 'หมายเหตุยาวเกินไป';

    if ($errors) return ['ok' => false, 'errors' => $errors];

    // Normalize datetime to "YYYY-MM-DD HH:MM:SS"
    $measuredAt = str_replace('T', ' ', $measuredAt);
    if (strlen($measuredAt) === 16) $measuredAt .= ':00';

    return ['ok' => true, 'data' => [
        'patient_id'  => $patient,
        'systolic'    => $systolic,
        'diastolic'   => $diastolic,
        'pulse_rate'  => $pulse,
        'measured_at' => $measuredAt,
        'position'    => $position !== '' ? $position : null,
        'arm'         => $arm !== '' ? $arm : null,
        'notes'       => $notes !== '' ? $notes : null,
        'classification' => vitals_bp_classify($systolic, $diastolic),
    ]];
}

/** List BP readings with optional filters + pagination. */
function vitals_bp_list(PDO $pdo, array $opts = []): array
{
    vitals_bp_ensure_schema($pdo);

    $q          = trim((string)($opts['q']          ?? ''));
    $patient    = (int)($opts['patient_id'] ?? 0);
    $classif    = trim((string)($opts['classification'] ?? ''));
    $dateFrom   = trim((string)($opts['date_from'] ?? ''));
    $dateTo     = trim((string)($opts['date_to']   ?? ''));
    $page       = max(1, (int)($opts['page']     ?? 1));
    $perPage    = max(1, min(200, (int)($opts['per_page'] ?? 20)));

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]      = '(u.full_name LIKE :q OR u.student_personnel_id LIKE :q OR u.phone_number LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($patient > 0) {
        $where[]               = 'b.patient_id = :patient';
        $params[':patient']    = $patient;
    }
    if ($classif !== '' && isset(VITALS_BP_CLASSIFICATIONS[$classif])) {
        $where[]                = 'b.classification = :classif';
        $params[':classif']     = $classif;
    }
    if ($dateFrom !== '') {
        $where[]              = 'b.measured_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[]              = 'b.measured_at <= :date_to';
        $params[':date_to']   = $dateTo . ' 23:59:59';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_vitals_bp b
                          LEFT JOIN sys_users u ON u.id = b.patient_id {$whereSql}");
    foreach ($params as $k => $v) $cnt->bindValue($k, $v);
    $cnt->execute();
    $total = (int)$cnt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT b.*,
                   u.full_name AS patient_name,
                   u.student_personnel_id AS patient_code,
                   u.phone_number AS patient_phone
            FROM sys_vitals_bp b
            LEFT JOIN sys_users u ON u.id = b.patient_id
            {$whereSql}
            ORDER BY b.measured_at DESC, b.id DESC
            LIMIT :limit OFFSET :offset";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $st->execute();

    return [
        'rows'     => $st->fetchAll(PDO::FETCH_ASSOC),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => max(1, (int)ceil($total / $perPage)),
    ];
}

/**
 * Per-patient summary: latest reading, last-30-days stats, trend points.
 * Used by the patient drill-down chart.
 */
function vitals_bp_patient_summary(PDO $pdo, int $patientId, int $trendLimit = 30): ?array
{
    vitals_bp_ensure_schema($pdo);

    $patSt = $pdo->prepare("SELECT id, full_name, student_personnel_id, phone_number,
                                   gender, date_of_birth
                            FROM sys_users WHERE id = :id");
    $patSt->execute([':id' => $patientId]);
    $patient = $patSt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) return null;

    // Latest reading
    $latest = $pdo->prepare("SELECT * FROM sys_vitals_bp
                             WHERE patient_id = :id
                             ORDER BY measured_at DESC, id DESC LIMIT 1");
    $latest->execute([':id' => $patientId]);
    $latestRow = $latest->fetch(PDO::FETCH_ASSOC) ?: null;

    // Aggregate stats for last 30 days
    $agg = $pdo->prepare("SELECT
                            COUNT(*) AS count,
                            ROUND(AVG(systolic), 0)  AS avg_systolic,
                            ROUND(AVG(diastolic), 0) AS avg_diastolic,
                            MIN(systolic)  AS min_systolic,
                            MAX(systolic)  AS max_systolic,
                            MIN(diastolic) AS min_diastolic,
                            MAX(diastolic) AS max_diastolic,
                            SUM(classification IN ('stage2','crisis')) AS high_count
                          FROM sys_vitals_bp
                          WHERE patient_id = :id
                            AND measured_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $agg->execute([':id' => $patientId]);
    $stats = $agg->fetch(PDO::FETCH_ASSOC) ?: [];

    // Trend points (newest first → reverse for chart)
    $trend = $pdo->prepare("SELECT id, systolic, diastolic, pulse_rate, measured_at, classification
                            FROM sys_vitals_bp
                            WHERE patient_id = :id
                            ORDER BY measured_at DESC, id DESC
                            LIMIT :lim");
    $trend->bindValue(':id', $patientId, PDO::PARAM_INT);
    $trend->bindValue(':lim', $trendLimit, PDO::PARAM_INT);
    $trend->execute();
    $points = array_reverse($trend->fetchAll(PDO::FETCH_ASSOC));

    return [
        'patient'      => $patient,
        'latest'       => $latestRow,
        'stats_30d'    => $stats,
        'trend_points' => $points,
    ];
}
