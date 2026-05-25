# Staff Login — MEDIUM Security Fixes (2026-05-25)

> Append-only security decision record (CLAUDE.md convention).
> Scope: `admin/auth/staff_login.php` + ขยายไปยัง `includes/rate_limit.php`,
> `includes/client_ip.php`, `portal/actions/identity_actions.php`
> Branch: `claude/admiring-noether-3OLSV`
> ต่อจาก `2026-05-25-staff-login-high-fixes.md`

## Background

หลัง HIGH 3 ตัวปิดแล้ว → ขยายไปแก้ MEDIUM 4 ตัวจาก security review:

| # | ใน Review เดิม | สถานะหลัง audit | Resolution |
|---|---|---|---|
| M1 | Username enumeration timing oracle | confirmed | Patched |
| M2 | admin_role namespace collision (80 files) | **rescoped** — เจอ POST tampering gap | Patched |
| M3 | REMOTE_ADDR proxy issue | needs deployment context | Helper + opt-in |
| M4 | Failed login attempts ไม่ถูก log | confirmed | Patched |

## Commits

### `8a2cc2c` — M1 + M4 (timing + audit log)

**M1 — Timing oracle:**
เดิม `if ($staff && password_verify(...))` short-circuit ที่ `$staff` null
→ user ไม่มี = ไม่เรียก bcrypt = response เร็ว = enumeration ได้
Fix: always-run password_verify กับ dummy hash (`$2y$12$IVQ...`) เมื่อ user ไม่พบ.
Cost factor ต้องตรงกับ production hashes (PHP 8.x default = 12)

**M4 — Failed login logging:**
เพิ่ม `log_activity()` ที่ 3 failure paths:
- `staff_login_blocked` (account_status ≠ active)
- `staff_login_blocked` (no access flags) — revoked-user detection
- `staff_login_failed` (wrong creds OR unknown user)

ISO 27001 + HIPAA gap ที่เคยขาด — SIEM correlation มี trail แล้ว

### `0311ef4` — M3 (proxy-aware client IP)

**Survey ก่อนแก้:** `.htaccess`, codebase grep, `secrets.template.php`
→ ระบบไม่เคย handle proxy header. Production deployment ปัจจุบันน่าจะใช้
REMOTE_ADDR เปล่า (work อยู่). แต่ทำ defensive helper เผื่อ deploy ใหม่หลัง proxy

**Files:**
- `includes/client_ip.php` (NEW) — `get_real_client_ip()`, `ip_in_cidr()`,
  `ip_in_trusted_cidrs()` รองรับทั้ง IPv4 + IPv6 CIDR
- `includes/rate_limit.php` — `rate_limit_ip_addr()` lazy-load helper ผ่าน
  `function_exists` check (ไม่ hard-dependency)
- `config/secrets.template.php` — เพิ่ม `TRUSTED_PROXIES => []` พร้อม comment
  + warning "อย่าใส่ 0.0.0.0/0 เด็ดขาด"

**Anti-spoof:** REMOTE_ADDR ต้องอยู่ใน trusted CIDR list ก่อน → ถึงจะอ่าน
forwarded headers. ถ้าไม่ตั้ง config → ignore headers ทั้งหมด → REMOTE_ADDR
เปล่า (safe default, no behavior change)

**Tested:** 12 cases (default, CIDR IPv4/IPv6, spoof attempts, CF priority,
XFF chain, malformed XFF) — ผ่านทั้งหมด

### (this commit) — M2 (POST tampering gap)

**Audit finding (revised from review):** Original review flagged "namespace
collision = 80 files refactor". After audit พบว่า design intent คือ
`role='superadmin'` ของทั้ง 2 ฝั่ง = root admin peer (intentional).
`is_ecampaign_staff` flag ใช้แค่ logout redirect + self-profile edit ใน 14
ตำแหน่ง — ไม่ใช่ privilege gate

**แต่:** UI dropdown `ec_role` ใน `portal/identity.php:827-830` มีแค่
`editor` / `admin` (ไม่มี `superadmin`) — staff superadmin ไม่ควรมีจริง

**Gap:** `portal/actions/identity_actions.php:134` มี whitelist
`['superadmin', 'admin', 'editor']` → POST `ec_role=superadmin` ตรงๆ
ผ่านได้ → privilege escalation ของ Identity Governance admin

**Fix:**
1. `identity_actions.php:134` — ลบ `'superadmin'` ออกจาก `$allowedEcRoles`
   → INSERT/UPDATE rejected ที่ backend แม้ส่ง POST ตรง
2. `staff_login.php:106-110` — ลบ `'superadmin'` ออกจาก whitelist + graceful
   downgrade legacy data:
   - ถ้ามี staff record ที่ `ecampaign_role='superadmin'` ใน DB อยู่แล้ว
   - login ยังทำได้ แต่ downgrade → `admin_role='admin'` (เสีย root access)
   - log `staff_role_downgraded` พร้อม guidance ให้ admin ไปแก้ที่ Identity UI

**Migration path สำหรับ legacy staff superadmin:**
- ตัวเลือก A: ลบ user ที่ Identity Governance แล้วสร้างใหม่เป็น
  `target_type='admin'` (sys_admins record) → ได้ root access แบบที่ตั้งใจ
- ตัวเลือก B: เปลี่ยน `ecampaign_role` เป็น `'admin'` + เปิด access_* flags ทุกอัน
  → ได้ broad access แต่ไม่ใช่ root

## Out of Scope (Future Round)

จาก security review เดิมที่ยังไม่แก้:

| Severity | Issue | Reason |
|---|---|---|
| LOW | CSRF token ไม่ rotate หลัง login | defense-in-depth — รวมกับ next round |
| LOW | Position flag override silently masks user-level revocations | design decision needed |
| LOW | Security response headers ขาด (CSP, X-Frame-Options ที่ระดับ PHP) | already handled at .htaccess (header always set) |

`admin/auth/login.php` (sys_admins login) ยังไม่ได้ apply HIGH + MEDIUM fixes
เหมือนกัน — แนะนำ apply ใน commit ต่อไป (mirror pattern จาก staff_login.php)

## Verification

- `php -l` ผ่านทุกไฟล์
- helper logic ทดสอบ 12 cases ครอบคลุม anti-spoof + CIDR matching
- diff สะอาด ไม่มี behavioral change บน production ปัจจุบัน (no proxy, no legacy
  superadmin staff)
