<?php
/**
 * includes/rate_limit.php
 * Session-based rate limiting for login forms.
 * No extra DB table needed.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/rate_limit.php';
 *   rate_limit_check('admin_login', 5, 300);   // max 5 attempts per 5 minutes
 *   rate_limit_hit('admin_login');              // call on failed attempt
 *   rate_limit_clear('admin_login');            // call on success
 */

/**
 * Check if the caller is currently rate-limited.
 * Redirects with ?error=too_many_attempts if limit exceeded.
 *
 * @param string $key      Unique identifier (e.g. 'admin_login')
 * @param int    $maxTries Max failed attempts before lockout
 * @param int    $window   Lockout window in seconds
 * @param string $redirect URL to redirect when locked out (defaults to current page)
 */
function rate_limit_check(string $key, int $maxTries = 5, int $window = 300, string $redirect = ''): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $data = $_SESSION['_rl'][$key] ?? ['count' => 0, 'until' => 0];

    if ($data['until'] > time()) {
        // Already on the lockout-notice URL? Don't redirect again — caller will
        // render the wait message from $_GET['error']/$_GET['wait'] inline.
        // Prevents infinite redirect loop (browser ends up at chrome-error://
        // after MAX_REDIRECTS).
        if (($_GET['error'] ?? '') === 'too_many_attempts') {
            return;
        }
        // Still in lockout window — bounce caller to the lockout-notice URL.
        $wait = $data['until'] - time();
        $back = $redirect ?: (strtok($_SERVER['REQUEST_URI'], '?'));
        header("Location: {$back}?error=too_many_attempts&wait={$wait}");
        exit;
    }

    // If window expired, reset counter
    if (isset($data['reset_at']) && $data['reset_at'] < time()) {
        unset($_SESSION['_rl'][$key]);
    }
}

/**
 * Record a failed attempt. Locks out after $maxTries within $window seconds.
 */
function rate_limit_hit(string $key, int $maxTries = 5, int $window = 300): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $data = $_SESSION['_rl'][$key] ?? ['count' => 0, 'until' => 0, 'reset_at' => time() + $window];
    $data['count']++;

    if ($data['count'] >= $maxTries) {
        $data['until'] = time() + $window;
    }

    $_SESSION['_rl'][$key] = $data;
}

/**
 * Clear rate limit counter on successful login.
 */
function rate_limit_clear(string $key): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['_rl'][$key]);
}

// ─────────────────────────────────────────────────────────────────────────────
// IP-based rate limiting (file-backed) — for endpoints where session-based
// throttling is bypassable by rotating the session cookie (login forms, public
// APIs). See AI/logs/2026-05-18-security-audit-main.md Phase 1+2 High items.
// ─────────────────────────────────────────────────────────────────────────────

function rate_limit_ip_dir(): string {
    $dir = __DIR__ . '/../storage/rl';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function rate_limit_ip_addr(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rate_limit_ip_key(string $key, ?string $ip = null): string {
    return md5($key . '|' . ($ip ?? rate_limit_ip_addr()));
}

/** True if the (key, IP) tuple is not currently locked out. */
function rate_limit_ip_check(string $key, int $maxTries = 5, int $window = 300, ?string $ip = null): bool {
    $file = rate_limit_ip_dir() . '/' . rate_limit_ip_key($key, $ip) . '.json';
    if (!is_file($file)) return true;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return true;
    return ($data['until'] ?? 0) <= time();
}

/** AJAX/public-API guard: emit HTTP 429 + JSON and exit if locked out. */
function rate_limit_ip_check_or_429(string $key, int $maxTries = 5, int $window = 300): void {
    if (!rate_limit_ip_check($key, $maxTries, $window)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $window);
        echo json_encode(['ok' => false, 'message' => 'Too many requests. กรุณารอสักครู่.']);
        exit;
    }
    if (random_int(1, 100) === 1) rate_limit_ip_cleanup();
}

/** HTML-form guard: redirect to ?error=too_many_attempts if locked out. */
function rate_limit_ip_check_or_redirect(string $key, int $maxTries = 5, int $window = 300, string $redirect = ''): void {
    if (!rate_limit_ip_check($key, $maxTries, $window)) {
        // Already on the lockout-notice URL? Don't redirect again — caller
        // renders the wait message inline. Prevents infinite redirect loop.
        if (($_GET['error'] ?? '') === 'too_many_attempts') {
            return;
        }
        $back = $redirect ?: strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        header("Location: {$back}?error=too_many_attempts&wait={$window}");
        exit;
    }
    if (random_int(1, 100) === 1) rate_limit_ip_cleanup();
}

/** Record a failed attempt. Lockout window starts when count reaches $maxTries. */
function rate_limit_ip_hit(string $key, int $maxTries = 5, int $window = 300, ?string $ip = null): void {
    $file = rate_limit_ip_dir() . '/' . rate_limit_ip_key($key, $ip) . '.json';
    $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($data) || ($data['reset_at'] ?? 0) < time()) {
        $data = ['count' => 0, 'until' => 0, 'reset_at' => time() + $window];
    }
    $data['count']++;
    if ($data['count'] >= $maxTries) $data['until'] = time() + $window;
    @file_put_contents($file, json_encode($data));
}

/** Clear the per-IP counter (call on successful login). */
function rate_limit_ip_clear(string $key, ?string $ip = null): void {
    $file = rate_limit_ip_dir() . '/' . rate_limit_ip_key($key, $ip) . '.json';
    if (is_file($file)) @unlink($file);
}

/** Garbage-collect stale bucket files (called ~1% of the time by check helpers). */
function rate_limit_ip_cleanup(): void {
    $cutoff = time() - 3600;
    foreach (glob(rate_limit_ip_dir() . '/*.json') ?: [] as $f) {
        if (@filemtime($f) < $cutoff) @unlink($f);
    }
}
