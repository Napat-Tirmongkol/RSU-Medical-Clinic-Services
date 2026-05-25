# Staff Login — HIGH Security Fixes (2026-05-25)

> Append-only security decision record (CLAUDE.md convention).
> Scope: `admin/auth/staff_login.php` — 3 HIGH findings จาก security review
> Branch: `claude/admiring-noether-3OLSV`

## Background

Security review ของ `admin/auth/staff_login.php` พบ 3 HIGH severity issues + 4 MEDIUM + 3 LOW.
Decision: แก้ HIGH ก่อนใน commit เดียว เพื่อ ship แบบ low-risk + high-value.
MEDIUM/LOW deferred — รอ planning round ถัดไป.

## Fixes Applied

### H1 — Incomplete `account_status` gate
**Before** (line 101): `if ($staff['account_status'] === 'disabled')` — block เฉพาะค่าเดียว
**After:** whitelist `!== 'active'` (lowercase + trim + null-safe)

`sys_staff.account_status` มี 4 ค่าใน whitelist ของ `portal/actions/identity_actions.php:54`:
`['active','disabled','suspended','inactive']`. เดิม `suspended` / `inactive` ผ่าน gate ได้ → ผู้ใช้ที่ถูก revoke/พักงานยัง login ได้

Error message ลด info disclosure เป็น generic "บัญชีนี้ไม่พร้อมใช้งาน" (ไม่บอกสาเหตุละเอียด)

### H2 — Missing `cookie_secure` flag
**Before** (line 3-8): manual `ini_set` 3 บรรทัด ขาด `cookie_secure`
**After:** call `start_secure_session()` จาก `includes/session_guard.php`

`session_guard.php::start_secure_session()` set ครบ:
- `cookie_secure` → ON เมื่อ `$_SERVER['HTTPS']` (HSTS-friendly)
- `cookie_httponly`, `cookie_samesite=Lax`
- `gc_maxlifetime` = `ADMIN_SESSION_TIMEOUT` (7200)

### H3 — Missing `use_strict_mode` (session fixation)
รวมอยู่ใน fix H2 — `start_secure_session()` set `use_strict_mode=1` + `use_only_cookies=1` + `use_trans_sid=0` ครบ → PHP จะปฏิเสธ session ID ที่ client ปลูก, และไม่อ่าน PHPSESSID จาก URL/form

## Out of Scope (Deferred)

จาก security review เดียวกัน ที่ยัง **ไม่แก้** ใน commit นี้:

| Severity | Issue | Reason for deferral |
|---|---|---|
| MEDIUM | Username enumeration via timing oracle | ต้องเพิ่ม dummy `password_verify` — เปลี่ยน auth flow |
| MEDIUM | Failed login attempts ไม่ถูก log | ต้องเพิ่ม `log_activity('staff_login_failed', ...)` |
| MEDIUM | IP rate limit ใช้ `REMOTE_ADDR` เปล่า | ต้อง verify deployment (Cloudflare? reverse proxy?) ก่อนแก้ |
| MEDIUM | `admin_role` namespace collision sys_admins ↔ sys_staff | กระทบ gate ทุกที่ — ต้อง audit downstream code ก่อน |
| LOW | CSRF token ไม่ rotate หลัง login | defense-in-depth — รวมกับ MEDIUM round |
| LOW | ขาด security response headers (CSP, X-Frame-Options) | global concern — ทำที่ level ของ shared header แทน |

`admin/auth/login.php` (sys_admins login) **มีปัญหา H2 + H3 เดียวกัน** — ใช้ `session_start()` เปล่าๆ. ยังไม่แก้ใน scope นี้เพราะ user request เจาะจง staff_login.php. แนะนำให้แก้ใน commit ต่อไป (1-line edit เหมือนกัน)

## Verification

- `php -l admin/auth/staff_login.php` → No syntax errors
- Manual review diff: ไม่มี behavioral change สำหรับ user สถานะ `active` (happy path คงเดิม)
- ผู้ใช้สถานะ `suspended` / `inactive` ที่ใส่ password ถูกจะถูก block → expected new behavior
