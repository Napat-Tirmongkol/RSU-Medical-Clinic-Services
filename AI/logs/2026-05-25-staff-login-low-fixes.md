# Staff Login — LOW Security Fixes (2026-05-25)

> Append-only security decision record (CLAUDE.md convention).
> Scope: `admin/auth/staff_login.php` + `admin/auth/login.php`
> Branch: `claude/admiring-noether-3OLSV`
> ต่อจาก `2026-05-25-staff-login-medium-fixes.md`

## Background

ต่องาน MEDIUM ที่ปิดแล้ว → ปิด 3 LOW จาก security review เดิม

| # | Finding | Status | Resolution |
|---|---|---|---|
| L1 | CSRF token ไม่ rotate หลัง login → session fixation surface | confirmed | `unset($_SESSION['csrf_token'])` หลัง `session_regenerate_id(true)` ทั้ง 2 login flow |
| L2 | Position flag override silently masks user-level revocations | confirmed (design intent ยัง by-design) | Detection + deferred audit log สำหรับ ISO trail |
| L3 | Security response headers ขาด | **false positive** | `.htaccess` มี `Header always set` ครบ (X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy, Permissions-Policy, CSP, COOP, CORP) |

## Commit (this round)

### L1 — CSRF rotation after login

**Issue:** `session_regenerate_id(true)` regenerate session ID file ใหม่
แต่ไม่ลบ session data — `$_SESSION['csrf_token']` ที่สร้างก่อน login ยัง valid.
แม้ session ID เปลี่ยน, attacker ที่ได้ token เก่ามาก่อน (XSS ที่ผ่านไป, log leak)
ยังสามารถใช้ token เดิมโจมตี CSRF ของ session ที่ login แล้วได้

**Fix:** หลัง `session_regenerate_id(true)` → `unset($_SESSION['csrf_token'])`
→ ตอน `get_csrf_token()` request ถัดไปจะสร้าง token ใหม่

ทำทั้ง 2 จุด:
- `admin/auth/staff_login.php:151-155` (staff login)
- `admin/auth/login.php:46-49` (portal admin login)

### L2 — Position flag override audit trail

**Issue:** `staff_login.php:82-93` (เดิม) loop `$staff[$flagKey] = (int)($posFlags[$flagKey] ?? 0)`
overwrite user-level flag ด้วย position flag เสมอ. ถ้า admin set
`sys_staff.access_xxx = 0` (revoke) แต่ position มี `flags.access_xxx = 1`
→ ตอน login จะกลับเป็น 1 → user-level revocation ถูก mask **อย่างเงียบๆ**
ไม่มี trail สำหรับ ISO 27001 / HIPAA audit

**Design intent (preserved):** code comment เดิม "Live link: ถ้าผูก position
อยู่ ใช้ flag จาก position แทน flag ใน sys_staff" — design intent คือ
position = live source of truth. ไม่เปลี่ยน behavior เพื่อกัน break กับ admin
ที่ใช้ position-based grant อยู่

**Fix:** Detect masking event + log `staff_position_override_masked_revoke`
ใน success branch (หลัง password verify ผ่าน) — defer log เพื่อกัน spam จาก
attacker ที่ส่ง wrong-password ซ้ำๆ ด้วย username ที่รู้

```php
$positionMaskedFlags = [];
foreach (...) {
    $userLevel = (int)($staff[$flagKey] ?? 0);
    $posLevel  = (int)($posFlags[$flagKey] ?? 0);
    if ($userLevel === 0 && $posLevel === 1) {
        $positionMaskedFlags[] = $flagKey;
    }
    $staff[$flagKey] = $posLevel;
}
// later in success branch:
if (!empty($positionMaskedFlags)) {
    log_activity('staff_position_override_masked_revoke',
        "Position override granted X,Y,Z despite user-level revoke (username='...')",
        (int)$staff['id']);
}
```

Admin จะเห็นใน Activity Log ว่ามี user ที่ revoke ไว้แต่ position grant กลับ
→ ตัดสินใจว่าจะแก้ position หรือ remove user จาก position

### L3 — Security headers (false positive)

**Investigation:** Audit `.htaccess` พบว่ามี `Header always set` ครบทุก header
ที่ security review เดิม flag:

| Header | .htaccess line |
|---|---|
| `X-Frame-Options: SAMEORIGIN` | 8 |
| `X-Content-Type-Options: nosniff` | 11 |
| `Strict-Transport-Security: max-age=31536000; includeSubDomains` | 15 |
| `X-XSS-Protection: 1; mode=block` | 18 |
| `Referrer-Policy: strict-origin-when-cross-origin` | 21 |
| `Permissions-Policy: ...` | 25 |
| `Cross-Origin-Opener-Policy: same-origin` | 28 |
| `Cross-Origin-Resource-Policy: same-site` | 29 |
| `Content-Security-Policy: ...` | 41 |

→ Apache handle ที่ webserver layer แล้ว ไม่ต้องเพิ่ม PHP-level header().
False positive ของ security review ผมที่ scan แค่ PHP files

**Caveat (out of scope):** CSP มี `'unsafe-inline'` ใน script-src — มี TODO
comment ใน `.htaccess:40` แล้วว่าจะ migrate ไป nonce-based. ไม่ใช่ login
security gap แต่เป็น future hardening

## Summary of Full Security Review Round (HIGH + MEDIUM + LOW)

### Commits ที่ branch `claude/admiring-noether-3OLSV`

| Commit | Severity | Description |
|---|---|---|
| `aa366a9` | HIGH | 3 issues: SQL injection vector, error suppression, account_status enforcement |
| `8a2cc2c` | MEDIUM (M1+M4) | Timing oracle + failed login audit log |
| `0311ef4` | MEDIUM (M3) | Proxy-aware IP helper + TRUSTED_PROXIES config |
| `a881e23` | MEDIUM (M2) | ec_role=superadmin POST tampering gap |
| (this commit) | LOW (L1+L2) | CSRF rotation + position override audit trail |

### Files Modified (cumulative)

- `admin/auth/staff_login.php` — HIGH + M1 + M2 + M4 + L1 + L2
- `admin/auth/login.php` — L1 only (sys_admins login)
- `includes/rate_limit.php` — M3
- `includes/client_ip.php` (NEW) — M3
- `config/secrets.template.php` — M3
- `portal/actions/identity_actions.php` — M2

### Out of Scope (next round)

- `admin/auth/login.php` (sys_admins) ยังไม่ apply HIGH + MEDIUM fixes ที่
  mirror จาก staff_login.php ได้ — แนะนำ apply commit ต่อไป
- CSP `'unsafe-inline'` → nonce-based migration (tracked ใน `.htaccess:40`
  TODO comment)
- Identity Governance UI: เพิ่ม warning visualizing position vs user-level
  flag mismatch (UX issue, not security)

## Verification

- `php -l` ผ่านทุกไฟล์ที่แก้
- `git diff` clean
- Behavior:
  - L1: ไม่มี behavior change visible ต่อ user (token rotation transparent)
  - L2: ไม่เปลี่ยน access decision — เพิ่ม audit log entry เฉพาะกรณี mask
    เกิดจริง
  - L3: no change (already in .htaccess)
