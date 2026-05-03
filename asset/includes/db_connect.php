<?php
/**
 * asset/includes/db_connect.php
 * Thin wrapper — delegates to canonical db() in config/db_connect.php.
 */
declare(strict_types=1);

if (!function_exists('db')) {
    require_once __DIR__ . '/../../config/db_connect.php';
}

require_once __DIR__ . '/../../includes/csrf.php';
