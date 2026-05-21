<?php
/**
 * includes/nurse_positions.php
 * Single source of truth สำหรับชื่อตำแหน่งพยาบาล
 *
 * ใช้คู่กับ:
 *   - portal/actions/identity_actions.php  → auto-flag access_nurse_productivity บน sys_staff_positions
 *   - portal/nurse_productivity_import.php → นับ RN/head จาก Excel + schedule
 *   - portal/ajax_nurse_productivity.php   → derive RN/head count จาก schedule_json
 *
 * Mirror ใน JS:
 *   - portal/nurse_schedule.php (const POSITIONS = {...}) — sync ด้วยมือเมื่อแก้
 */

if (!defined('NURSE_RN_POSITION')) {
    define('NURSE_RN_POSITION', 'พยาบาลวิชาชีพ');
    define('NURSE_HEAD_POSITIONS', ['หัวหน้าหอผู้ป่วย', 'รองหัวหน้าหอผู้ป่วย', 'พยาบาลหัวหน้าเวร']);
    define('NURSE_POSITION_NAMES', ['พยาบาลวิชาชีพ', 'หัวหน้าหอผู้ป่วย', 'รองหัวหน้าหอผู้ป่วย', 'พยาบาลหัวหน้าเวร']);
}

if (!function_exists('is_nurse_position')) {
    function is_nurse_position(string $name): bool
    {
        return in_array($name, NURSE_POSITION_NAMES, true);
    }
}
