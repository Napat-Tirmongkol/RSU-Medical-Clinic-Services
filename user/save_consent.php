<?php
// user/save_consent.php — Re-consent handler (legacy users only)
//   This is the narrow counterpart to save_profile.php: it ONLY stamps
//   the consent_* columns for an existing user. It doesn't accept any
//   other profile fields, so a legacy user can't accidentally overwrite
//   their own data by hitting this endpoint.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pdpa_reconsent.php', true, 303);
    exit;
}
validate_csrf_or_die();

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$consentGeneral   = !empty($_POST['consent_general'])   && (string)$_POST['consent_general']   === '1';
$consentSensitive = !empty($_POST['consent_sensitive']) && (string)$_POST['consent_sensitive'] === '1';
if (!$consentGeneral || !$consentSensitive) {
    // Bounce back to the form; the page itself will keep the submit
    // button disabled until both boxes are ticked, so this fallback
    // only fires on a hand-crafted POST or a stale form
    header('Location: pdpa_reconsent.php?error=incomplete', true, 303);
    exit;
}

$pdpaVersion = trim((string)($_POST['pdpa_version'] ?? ''));
// Same regex whitelist as save_profile — refuse anything else, default to current
if (!preg_match('/^pdpa_v\d+_\d{4}-\d{2}$/', $pdpaVersion)) {
    $pdpaVersion = 'pdpa_v2_2025-05';
}
$returnUrl = (string)($_POST['return'] ?? 'hub.php');
if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+(\?[^\s]*)?$/', $returnUrl)) $returnUrl = 'hub.php';

$consentIp        = $_SERVER['REMOTE_ADDR'] ?? '';
$consentUserAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$consentTextHash  = hash('sha256', $pdpaVersion);

try {
    $pdo = db();

    // Self-heal in case this endpoint is hit before save_profile has
    // ever run on this install
    foreach ([
        'consent_general_accepted_at'    => "DATETIME NULL DEFAULT NULL",
        'consent_general_version'        => "VARCHAR(50)  NULL DEFAULT NULL",
        'consent_general_text_hash'      => "VARCHAR(64)  NULL DEFAULT NULL",
        'consent_sensitive_accepted_at'  => "DATETIME NULL DEFAULT NULL",
        'consent_sensitive_version'      => "VARCHAR(50)  NULL DEFAULT NULL",
        'consent_sensitive_text_hash'    => "VARCHAR(64)  NULL DEFAULT NULL",
        'consent_ip'                     => "VARCHAR(45)  NULL DEFAULT NULL",
        'consent_user_agent'             => "VARCHAR(500) NULL DEFAULT NULL",
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS {$col} {$def}"); } catch (PDOException) {}
    }

    // COALESCE so we don't reset an earlier timestamp if a user somehow
    // re-consents twice — the original stamp is the legally relevant one
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE sys_users SET
        consent_general_accepted_at   = COALESCE(consent_general_accepted_at,   :now1),
        consent_general_version       = COALESCE(consent_general_version,       :ver1),
        consent_general_text_hash     = COALESCE(consent_general_text_hash,     :hash1),
        consent_sensitive_accepted_at = COALESCE(consent_sensitive_accepted_at, :now2),
        consent_sensitive_version     = COALESCE(consent_sensitive_version,     :ver2),
        consent_sensitive_text_hash   = COALESCE(consent_sensitive_text_hash,   :hash2),
        consent_ip                    = COALESCE(consent_ip, :ip),
        consent_user_agent            = COALESCE(consent_user_agent, :ua)
        WHERE line_user_id = :line_id");
    $stmt->execute([
        ':now1'    => $now,
        ':ver1'    => $pdpaVersion,
        ':hash1'   => $consentTextHash,
        ':now2'    => $now,
        ':ver2'    => $pdpaVersion,
        ':hash2'   => $consentTextHash,
        ':ip'      => $consentIp,
        ':ua'      => $consentUserAgent,
        ':line_id' => $lineUserId,
    ]);

    // Audit log so the admin can see a "user X re-consented at version Y"
    // row alongside their original registration
    try {
        $stmtUser = $pdo->prepare("SELECT id, full_name FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
        $stmtUser->execute([':line_id' => $lineUserId]);
        $u = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            log_activity('PDPA Reconsent', "Legacy user re-consented to {$pdpaVersion} '{$u['full_name']}'", (int)$u['id']);
        }
    } catch (Throwable $e) {
        error_log('[save_consent] audit log: ' . $e->getMessage());
    }

    header('Location: ' . $returnUrl);
    exit;
} catch (Throwable $e) {
    error_log('[save_consent] error: ' . $e->getMessage());
    http_response_code(500);
    exit('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
}
