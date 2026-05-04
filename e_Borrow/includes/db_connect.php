<?php
/*
 * e_Borrow/includes/db_connect.php
 *
 * Thin wrapper — delegates to the canonical db() in config/db_connect.php.
 * All 62 files inside e_Borrow include this file; none need to change.
 *
 * - If config.php was already loaded (normal flow), db() is already defined → skip.
 * - If loaded standalone (e.g. direct AJAX call), load config/db_connect.php directly.
 *
 * Legacy callers expect a global $pdo to be in scope after the require, so we
 * expose one here. Newer callers using db() directly are unaffected.
 */
declare(strict_types=1);

if (!function_exists('db')) {
    require_once __DIR__ . '/../../config/db_connect.php';
}

// Legacy compatibility: expose $pdo to the including file's scope.
// Required by the dozens of e_Borrow process/ajax scripts that reference
// $pdo->prepare() directly without calling db() themselves.
$pdo = db();

defined('FINE_RATE_PER_DAY') || define('FINE_RATE_PER_DAY', 10.00);

require_once __DIR__ . '/../../includes/error_logger.php';
