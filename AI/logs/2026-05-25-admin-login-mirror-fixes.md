# Admin Login (sys_admins) — Mirror Security Fixes (2026-05-25)

> Append-only security decision record (CLAUDE.md convention).
> Scope: `admin/auth/login.php` (sys_admins portal admin login)
> Branch: `claude/admiring-noether-3OLSV`
> ต่อจาก `2026-05-25-staff-login-low-fixes.md`

## Background

หลังปิด HIGH + MEDIUM + LOW ของ `staff_login.php` (sys_staff) ครบแล้ว
→ apply pattern เดียวกันกับ `admin/auth/login.php` (sys_admins portal admin)
เพราะใช้ codepath เกือบเหมือนกันแต่ก่อนหน้านี้ไม่ได้รับ fix รอบนี้

## Gap Analysis

| Severity | Issue | สถานะก่อนแก้ |
|---|---|---|
| HIGH | Rate limit session-based — bypass ได้โดย rotate cookie | `rate_limit_check('admin_login', ...)` |
| HIGH | PDOException swallowed silently — ไม่ surface ใน Sentry/log | catch + ส่งกลับ generic error เปล่า |
| MEDIUM (M1) | Username enumeration timing oracle | `if ($admin && password_verify(...))` short-circuit |
| MEDIUM (M4) | Failed login attempts ไม่ถูก log | else branch ไม่มี `log_activity()` |

**Not applicable:**
- M2 (ec_role POST tampering) — sys_admins ไม่มี ec_role field
- M3 (proxy IP) — `rate_limit_ip_addr()` ใช้ helper อัตโนมัติแล้วผ่าน lazy-load
- L1 (CSRF rotation) — apply ไปแล้วใน commit `de219ae`
- L2 (position override) — sys_admins ไม่มี position_id field
- account_status — sys_admins ไม่มี column นี้ (ต่างจาก sys_staff). ถ้าต้องการ
  enforcement ต้องเพิ่ม migration + UI ก่อน (out of scope)

## Fix (this commit)

### 1. Rate limit migration — session-based → IP-based

```diff
- rate_limit_check('admin_login', 5, 300, 'login.php');
+ rate_limit_ip_check_or_redirect('admin_login', 5, 300);
```

```diff
- rate_limit_clear('admin_login');     // success
+ rate_limit_ip_clear('admin_login');

- rate_limit_hit('admin_login', 5, 300);  // failure
+ rate_limit_ip_hit('admin_login', 5, 300);
```

IP-based rate limit ใช้ file-backed storage ผ่าน `get_real_client_ip()` →
bypass-resistant. Attacker ไม่สามารถ rotate cookie หนีจาก lockout ได้

### 2. Timing oracle protection (M1)

```php
$dummyHash = '$2y$12$IVQJwsWr.gGbN7/sS3Z9..5ie9lN55IVDUWV.sr70DjTvB0jRbVWu';
$passwordOk = password_verify($password, $admin ? $admin['password'] : $dummyHash);
```

Always-run `password_verify` กับ dummy hash เมื่อ user ไม่พบ → response time
constant ไม่ leak existence ของ username

### 3. Failed login audit log (M4)

```php
log_activity('admin_login_failed',
    "Failed login attempt: username='...' from IP {$ipForLog}",
    null);
```

ISO 27001 + HIPAA require explicit trail สำหรับ unsuccessful authentication.
ใช้ key `admin_login_failed` (แยกจาก `staff_login_failed`) เพื่อให้ SIEM
แยก correlation ระหว่าง portal admin vs e-Campaign staff

### 4. PDOException logging (HIGH)

```diff
} catch (PDOException $e) {
+   error_log('[admin_login] ' . $e->getMessage());
    $error = 'ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ในภายหลัง';
}
```

DB exception ตอน login ก่อนนี้ถูก swallowed → debug impossible + Sentry
ไม่จับ. `error_log()` เขียนไป default PHP error log → Sentry breadcrumb
catches it. User-facing message ยังคงเป็น generic (ไม่ leak schema)

## Verification

- `php -l` ผ่าน
- Diff สะอาด 4 hunks (rate_limit, timing oracle, fail log, error_log)
- Behavior:
  - Success path: identical (clear rate limit, set session, redirect)
  - Failure path: now logs + uses IP-based rate limit
  - DB error: now logged to PHP error log (no user-facing change)

## Out of Scope (next round)

- `sys_admins.account_status` — ถ้าต้องการ disable/suspend admin ต้อง
  เพิ่ม column + Identity Governance UI checkbox ก่อน. Pattern เดียวกับ
  `sys_staff.account_status` ที่มีอยู่
- CSP `'unsafe-inline'` migration (tracked ใน `.htaccess:40`)
- Sentry breadcrumb context (admin_id, ip) ใน error_log call

## Full Security Review Summary

ทั้งรอบบน `claude/admiring-noether-3OLSV`:

| Commit | Severity | Scope |
|---|---|---|
| `aa366a9` | HIGH ×3 | staff_login.php — SQL, error suppression, account_status |
| `8a2cc2c` | MEDIUM | staff_login.php — timing oracle, failed log |
| `0311ef4` | MEDIUM | rate_limit.php + client_ip.php — proxy-aware IP helper |
| `a881e23` | MEDIUM | identity_actions.php + staff_login.php — ec_role POST gap |
| `de219ae` | LOW | staff_login.php + login.php — CSRF rotation + position override audit |
| (this) | HIGH+MEDIUM mirror | login.php — apply pattern จาก staff_login.php |
