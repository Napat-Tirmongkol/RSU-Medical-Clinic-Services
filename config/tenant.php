<?php
/**
 * config/tenant.php — Tenant Resolver
 *
 * กำหนด CLINIC_ID constant โดย resolve จาก:
 *   1. Session (เพื่อประสิทธิภาพ — ไม่ query DB ทุก request)
 *   2. Subdomain  →  medical.rsu.ac.th  ⟹  slug = 'medical'
 *   3. Fallback   →  clinic_id = 1 (Medical Clinic เดิม)
 *
 * รวมอยู่ใน config.php ก่อน site settings ถูก load
 */
declare(strict_types=1);

if (!function_exists('resolve_tenant_id')) {
    function resolve_tenant_id(): int
    {
        // ── Fast path: session มี clinic_id แล้ว ────────────────────────────
        if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['clinic_id'])) {
            return (int) $_SESSION['clinic_id'];
        }

        // ── Resolve จาก subdomain ────────────────────────────────────────────
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // ตัด port ออก (เช่น localhost:8080)
        $host = strtolower(explode(':', $host)[0]);
        // เอา segment แรก เช่น 'medical' จาก 'medical.rsu.ac.th'
        $slug = explode('.', $host)[0];

        // ไม่ resolve ถ้า slug เป็น IP, 'localhost', หรือสั้นเกินไป
        if ($slug !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP) && strlen($slug) >= 3) {
            try {
                $pdo  = db();
                $stmt = $pdo->prepare(
                    "SELECT id FROM sys_clinics WHERE slug = ? AND is_active = 1 LIMIT 1"
                );
                $stmt->execute([$slug]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return (int) $row['id'];
                }
            } catch (Exception $e) {
                // DB ยังไม่พร้อม (migration ยังไม่รัน) → fallback
            }
        }

        // ── Fallback: clinic_id = 1 (default clinic) ─────────────────────────
        return 1;
    }
}

if (!defined('CLINIC_ID')) {
    define('CLINIC_ID', resolve_tenant_id());
}
