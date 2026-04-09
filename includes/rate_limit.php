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
        // Still in lockout window
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
