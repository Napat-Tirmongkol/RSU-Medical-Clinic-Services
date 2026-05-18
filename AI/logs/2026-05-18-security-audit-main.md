# Security Audit — main branch (2026-05-18)

> **Append-only audit record** (CLAUDE.md convention).
> Branch reviewed: `claude/mobile-health-data-access-OZtn4` (= main + 1 commit เพิ่ม agent defs)
> Scope: ทั้ง repo (1,370 PHP files · 82 AJAX endpoints · 30 upload paths · 4 public API)
> Methodology: 8 specialized agents (security/healthcare/db reviewers) ขนานกัน, จัด phase ตาม attack-surface priority.
> Aggregate: **43 Critical · 74 High · 87+ Medium · ~36 Low ≈ 240 findings**

---

## Executive Summary — สิ่งที่ต้องทำก่อน next deploy

ผลรวมระบุว่า **production deploy ต้อง BLOCK** จนกว่า Critical tier จะถูกแก้ มี attack chain ที่นำไปสู่:
1. **Pre-auth RCE + DB takeover** — `welfarecard_old/*` (raw SQLi + plaintext credentials in git + arbitrary file upload + no auth)
2. **Authenticated RCE chain** — e_Borrow payment slip / equipment image uploads (ไม่มี MIME check + ไม่มี `.htaccess` deny ใน `uploads/slips/`)
3. **Financial fraud** — e_Borrow `record_payment_process.php` / `direct_payment_process.php` รับ amount จาก client ตรงๆ + finance_sync double-credit race
4. **Mass PHI exfiltration** — `ajax_identity_users.php` ไม่มี role gate (editor ดึง citizen_id ทุกราย); `ajax_insurance_export.php` CSV ไม่มี audit, ไม่ watermark
5. **Backdoor in production** — `user/force_login.php` testing file ยังอยู่
6. **Cross-border PHI transfer without DPIA** — Gemini AI Review ส่ง member rows ออกนอกประเทศ ไม่ pseudonymize ไม่มี consent

---

## Tier 1 — CATASTROPHIC (ต้องลบ/แก้ใน 24 ชม.)

| # | File | Issue | Action |
|---|---|---|---|
| C1 | `welfarecard_old/lib.php` | Plaintext DB password `'rsu','Dodeep4321;'` + LINE channel access token committed to git | **Delete folder + rotate both credentials immediately** |
| C2 | `welfarecard_old/base64insert.php`, `welfaredelete.php`, `welfarecarddelete.php`, `welfareconnect.php` | Raw SQL injection in 4 files, no auth, no CSRF, arbitrary upload as `<pid>.jpg` | Delete folder OR drop `.htaccess Require all denied` |
| C3 | `user/force_login.php` | Backdoor — any request grants session as a real user. File header literally says "TESTING ON STAGING ONLY" | **Delete file** |
| C4 | `e_Borrow/process/direct_payment_process.php:56-62` + `record_payment_process.php:44-50` | Slip upload extension จาก `pathinfo()` raw, ไม่มี MIME check, `uploads/slips/` ไม่มี `.htaccess` → RCE | Whitelist ext `[jpg,jpeg,png,pdf,webp]` + `finfo_file()` + drop deny `.htaccess` |
| C5 | `e_Borrow/process/{direct,record}_payment_process.php` | `$amount`/`$amount_paid` จาก `$_POST` โดยตรง ไม่ verify จาก DB → admin/editor จ่าย 1฿ บนค่าปรับ 5000฿ ได้ + mark `paid` | Re-read `borrow_fines.amount WHERE id=?` ใน server, reject ถ้า `$amount_paid < dbAmount` |
| C6 | `e_Borrow/process/add_equipment_type_process.php:66-74` + `edit_equipment_type_process.php` | `getimagesize()` ผ่าน polyglot ได้ + ext จาก user → `uploads/equipment_images/evil.php` | เหมือน C4 |
| C7 | `e_Borrow/process/send_reminders.php:8-14` | Cron secret hardcoded `"E-Borrow-Cron-Key-987654321"` + ส่งผ่าน `$_GET` → leak ใน access log/referrer/history | Move to `config/secrets.php` + `HTTP_X_API_KEY` header + IP allowlist |
| C8 | `portal/ajax_identity_users.php:14-19,81-85` | ไม่มี role gate — `editor` role pull ทั้ง `citizen_id, dob, phone, email` + raw `$e->getMessage()` leak | `if (!($adminRole==='superadmin' \|\| $adminRole==='admin' \|\| !empty($_SESSION['access_registry'])))` + mask citizen_id |
| C9 | `portal/ajax_finance.php:702-714` (attachment:delete) | `@unlink(__DIR__ . '/../' . $att['stored_name'])` ไม่มี realpath confine — poisoned stored_name unlink anywhere | `realpath()` ต้อง prefix `uploads/finance/` |
| C10 | `portal/ajax_finance.php:550-572` + `portal/finance_receipt.php:28-40` | Receipt-no generation: `SELECT MAX()+1` แล้ว `UPDATE` ไม่มี FOR UPDATE / transaction → 2 receipts ชนเลขกัน | Counter table + `SELECT ... FOR UPDATE` (ดู `portal/ajax_edms.php:91` เป็น reference) |
| C11 | `includes/finance_sync_helper.php:108-126` | Idempotency check ไม่มี transaction + `(source_module, source_id)` เป็น KEY ไม่ใช่ UNIQUE KEY → double-credit Cash Book | Dedupe → `ADD UNIQUE KEY uniq_source` → `INSERT ... ON DUPLICATE KEY UPDATE` |
| C12 | `portal/ajax_announcements.php` | ไม่มี CSRF, ไม่มี session check, raw `$e->getMessage()` ออกไป client | เพิ่ม `validate_csrf_or_die()` + session guard |
| C13 | `portal/ajax_error_logs.php` | ไม่มี CSRF บน mutation; role gate ไม่ตรวจ `superadmin` ทั้งๆ ที่อ่าน system logs | CSRF + `superadmin` only |
| C14 | `portal/ajax_site_settings.php` | ไม่มี CSRF บน mutation ที่ write Gemini API key + logo; trust `$_FILES['type']`; ยอม SVG → stored XSS / RCE | CSRF + `finfo_file()` + reject SVG หรือ sanitize + extension whitelist |
| C15 | `portal/ajax_gold_card.php:923, :577` + `ajax_insurance_partners.php:206-213` | **Hard DELETE** ของข้อมูลสุขภาพ + partner users — ทำลาย audit trail (FK becomes orphan) + ขัด PDPA right-to-erasure + ขัด CLAUDE.md soft-delete rule | เพิ่ม `deleted_at, deleted_by, deletion_reason`; เปลี่ยน DELETE → UPDATE; physical purge เป็น scheduled job หลัง retention |
| C16 | `user/ajax_gold_card_apply.php` | **ไม่บันทึก consent** เลย — submission รับ PHI ครบ (citizen_id, dob, biometric signature, ID photo) แต่ไม่มี acknowledgement of PDPA notice — ขาด lawful basis (PDPA Art. 24) | เก็บ `consent_version, consent_accepted_at, hash_of_notice_text, ip, ua` ใน table แยก; reject ถ้าไม่มี |
| C17 | `portal/ajax_insurance_export.php:49-56,99-113` | CSV stream ของ citizen_id 13 หลัก + DOB + policy_number — ไม่มี audit log, ไม่ watermark, ไม่ cap row, accept `editor` role | (a) role → `access_insurance` only (b) `log_activity('insurance_export', ...)` (c) watermark row (d) max row cap |
| C18 | `portal/ajax_insurance_sync.php:1187` (`insurance_ai_review`) | ส่ง member sample (อาจมี citizen_id, dob) → Gemini cross-border ไม่ pseudonymize + ไม่มี DPIA + ไม่มี subject consent | Strip PHI ก่อน send + DPIA note + log payload size + field-mask version |
| C19 | `portal/ajax_gold_card.php:986-1006` (`document:download`) | GET endpoint bypass CSRF + ไม่มี per-access audit + ไม่มี realpath check บน `$row['stored_path']` → download national ID copy ของใคร ก็ได้ ไม่มี trace | (a) realpath confine (b) audit row (c) POST+CSRF สำหรับ non-image |
| C20 | `includes/auth_helper.php:41-43` (`requestPasswordReset` base URL) | **Host header injection** — `$baseUrl` build จาก `$_SERVER['HTTP_HOST']` → reset link ไป `evil.com`, ผู้ใช้คลิก → token leak | Hardcode `APP_BASE_URL` constant; ห้าม trust `HTTP_HOST` |
| C21 | `admin/auth/forgot_password.php:21-30` | User enumeration — message ต่างกันถ้า email มี/ไม่มี + ไม่มี rate limit | Neutral message + IP-based rate limit |
| C22 | `admin/auth/reset_password.php:11,113,144` | Reflected XSS ใน `?type=` (echo unescaped); cross-table token confusion ถ้า `$type` ผ่านเข้า helper โดยไม่ whitelist | `htmlspecialchars()` + whitelist `['admin','staff']` |
| C23 | `admin/google_callback.php:73-82` | Role assigned จาก `$admin['role']` ตรงๆ ไม่ whitelist — ถ้า DB row มี role แปลก → propagate | Whitelist `['admin','editor','superadmin']` |
| C24 | `admin/google_callback.php:42-48` | OAuth token exchange ไม่มี `CURLOPT_TIMEOUT`, ไม่ check HTTP code → DoS + parse error body | เพิ่ม timeout + status check |
| C25 | `portal/actions/identity_actions.php:267` | `delete_admin` ใช้ loose comparison (`!=`) — `$adminId='1abc'` กับ session `1` → match → MySQL cast แล้วลบ id=1 | Strict cast `(int)` + `!==` |
| C26 | `e_Borrow/process/borrow_process.php` + `admin_direct_borrow_process.php` | ไม่มี role gate; staff fallback "pick LIMIT 1" เมื่อ staff_id invalid → auth bypass | Reject when staff not found + role whitelist |
| C27 | `e_Borrow/process/delete_staff_process.php` | Admin ลบ superadmin ได้ — ไม่ check target role | Block ถ้า target.role='superadmin' unless caller=superadmin |
| C28 | 5 directories ไม่มี `.htaccess` deny | `config/`, `uploads/` (root), `AI/`, `vendor/`, `database/` — direct URL access ได้ | Drop `Require all denied` ทั้ง 5 จุด |

---

## Tier 2 — HIGH (ภายใน 7 วัน)

### Authentication & Session
- **Session-based rate limit bypass** — `admin/auth/login.php` + `staff_login.php` + `api/log_js_error.php` ทั้งหมดใช้ session-bucket → rotate cookie แล้ว bypass. แก้: IP-based bucket
- **`staff_login.php` ไม่มี rate limit เลย** — brute force `sys_staff` unlimited
- **Session cookie flags ไม่ครบ** — `staff_login.php` set HttpOnly+SameSite แต่ไม่ Secure; `login.php` ไม่ตั้งเลย
- **`session.use_strict_mode`, `use_only_cookies` ไม่ตั้งใน `session_guard.php`** → session fixation
- **`.user.ini` ไม่บังคับ `display_errors=Off`, `expose_php=Off`** → stack trace + PHP version leak
- **Sentry webhook ไม่มี replay protection** — `Sentry-Hook-Timestamp` ignored

### CSRF / Authorization
- **`ajax_insurance_sync.php:36-39`** — CSRF bypass บน same-origin (drop origin escape hatch)
- **`ajax_line_groups.php`** ไม่ narrow role — admin ใดก็เปลี่ยน LINE broadcast scope
- **`ajax_insurance_export.php`** ยอม `editor` role
- **`ajax_insurance_sync.set_visibility`** — editor toggle public clinic settings
- **`check_student_session_ajax.php`** ไม่ enforce CSRF — state-changing student endpoints (request_borrow, cancel_request) ไม่ป้องกัน
- **`e_Borrow/admin/ajax_profile.php`** — POST password change ไม่มี CSRF → CSRF เปลี่ยนรหัสผ่าน admin ได้
- **`e_Borrow/admin/ajax_finance_sync.php`** ยอม `librarian` write finance — ขัด CLAUDE.md

### File Upload
- **`portal/ajax_edms.php:155`** — mime_type จาก `$_FILES['type']` (client) → download response มี Content-Type ที่ attacker เลือก → XSS
- **`portal/ajax_gold_card.php:50-83`** — extension-only whitelist, ไม่มี MIME check, `uploads/gold_card/` ไม่มี `.htaccess`
- **`portal/ajax_edms.php:802-855` + `ajax_finance.php:709-710`** — `@unlink` ไม่มี realpath confinement → path traversal delete ถ้า DB row tampered
- **`user/ajax_gold_card_apply.php:99`** — signature base64 check แค่ `strlen >= 200` ไม่ verify PNG magic
- **`asset/uploads/`, `consumables/uploads/`, `assets/images/`, `e_Borrow/uploads/attachments/`** — ไม่มี `.htaccess`

### SQL / Race conditions
- **LIKE wildcards ไม่ escape** — 7+ search endpoints รับ `%`/`_` ที่ user ใส่
- **`$id` interpolated หลัง `(int)` cast** — 9 จุด (ajax_finance, edms, dashboard_admin, insurance_sync, consumables) — เปราะบาง ลืม cast = ระเบิด
- **Auto-migration ALTER TABLE on every request** — user/profile.php, save_profile.php, edms/_helpers.php — DoS-able

### Error / Info disclosure
- **`$e->getMessage()` leak ใน JSON response** — 14+ endpoint (clinic_data, clinic_master, dashboard_admin, kpi_override, line_richmenu, ai_sandbox, activity_dashboard, daily_summary, gold_card_apply, scholarship_clock, identity_users, finance, insurance_sync, e_Borrow ajax_finance_sync, get_student_by_code, error_logs, stock_take_count)
- **`portal/activity_logs.php`** ไม่มี role gate — admin ใดก็เห็น log ของ admin อื่น (มี member_id ใน description)
- **`admin/user_history.php`** — render student_personnel_id, phone, line_user_id ไม่ mask + ไม่ role-gated

### PDPA / Healthcare
- **No read-audit log** — `ajax_insurance_batches.detail/members`, `ajax_insurance_sync.member:list/get` — PHI reads ไม่ trace (PDPA Art. 39 ต้องการทั้ง access + modification)
- **LINE userId stored raw** ใน `gold_card_history.new_value`; `line_mask_uid` helper มีอยู่แต่ไม่ใช้
- **`mask_citizen_id` keep 5 digits cleartext** — `api/line_webhook.php:78-84` (3 หน้า + 2 หลัง) — ควรเหลือ last-4 เท่านั้น
- **`session.cookie_samesite='Lax'`** — สำหรับ portal PHI ควร `Strict`

### Headers / Hardening
- **CSP มี `'unsafe-inline'`** บน script-src + `img-src https:` wildcard → XSS protection แทบไม่มีผล
- **Permissions-Policy, COOP, CORP ไม่มี**
- **HSTS ไม่มี `preload`**
- **`X-Powered-By`** อาจ leak PHP version (no `expose_php=Off`)
- **404.php โหลด config.php ทุก request** → DoS amplification

---

## Tier 3 — MEDIUM (ภายใน 30 วัน)

ลด context — เก็บไว้ในรายงานนี้แต่จะไม่ขยายความ. รวม ~87 รายการ:
- Display_errors กระจาย, JSON error pattern ไม่ consistent, mkdir 0777, no EXIF strip, no AV scan, no rate limit บน LINE broadcast/Gemini API, mime จาก client ในหลายจุด, dynamic query patterns กับ int cast (เปราะบาง), `composer.json` platform pin `8.1` (ต้อง bump), `.gitignore` ขาด `.idea/.vscode/.DS_Store`, audit table failure ถูก swallow แบบ silent (non-repudiation gap)

---

## Quick-Win Patches (เริ่มทำได้เลย)

### Patch Set 1 — 24 ชั่วโมงแรก (Catastrophic clean-up)
```bash
# 1. ลบ legacy folder
rm -rf welfarecard_old/

# 2. ลบ backdoor
rm user/force_login.php

# 3. Rotate ทันที (ถ้า welfarecard_old เคย deploy)
#    - DB password 'Dodeep4321;'
#    - LINE channel access token in welfarecard_old/lib.php

# 4. Drop .htaccess deny — สร้าง 5 ไฟล์ พร้อม "Require all denied"
for d in config uploads AI vendor database; do
  echo 'Require all denied' > $d/.htaccess
done

# 5. e_Borrow/uploads/{slips,equipment_images,attachments}/.htaccess
#    (mirror uploads/edms/.htaccess deny script ext)
```

### Patch Set 2 — Auth/CSRF (3 วัน)
- เพิ่ม `validate_csrf_or_die()` ใน 3 endpoints: `ajax_announcements.php`, `ajax_error_logs.php`, `ajax_site_settings.php`
- เพิ่ม role gate ใน `ajax_identity_users.php`
- Whitelist OAuth role ใน `admin/google_callback.php`
- เพิ่ม IP-based rate limit ใน `staff_login.php`, `forgot_password.php`, `log_js_error.php`
- Hardcode `APP_BASE_URL` แทน `$_SERVER['HTTP_HOST']` ใน `auth_helper.php`
- Strict comparison + cast ใน `identity_actions.php:267`

### Patch Set 3 — Financial integrity (5 วัน)
- e_Borrow payment: server-derive `$amount` จาก DB
- `UNIQUE KEY uniq_source (source_module, source_id)` + `INSERT ... ON DUPLICATE KEY UPDATE` ใน `finance_sync_helper.php`
- Counter table + `FOR UPDATE` สำหรับ receipt_no (mirror `ajax_edms.php:91`)
- Whitelist extension + finfo + `.htaccess` deny ทุก upload dir
- realpath confine บน attachment:delete + document:download

### Patch Set 4 — PDPA compliance (10 วัน)
- Soft-delete columns บน `gold_card_members`, `gold_card_documents`, `insurance_partner_users`
- Consent capture row ใน `user/ajax_gold_card_apply.php` + table `gold_card_consents`
- Strip PHI ก่อนส่ง Gemini ใน `insurance_ai_review` + DPIA note
- `log_activity` บน read paths ของ insurance + gold_card endpoints
- Watermark + row cap + audit ใน `ajax_insurance_export.php`
- Reduce `mask_citizen_id` เป็น last-4 เท่านั้น
- Use `line_mask_uid` ในทุก log call

### Patch Set 5 — Headers + hardening (3 วัน)
- `.user.ini`: `display_errors=Off`, `display_startup_errors=Off`, `expose_php=Off`, `log_errors=On`
- `.htaccess`: เพิ่ม Permissions-Policy + COOP `same-origin` + CORP `same-site` + `Header unset X-Powered-By`
- HSTS: append `; preload` หลังยืนยัน domain
- Plan CSP nonce migration (ลบ `'unsafe-inline'`)
- Scrub `$e->getMessage()` จาก 14+ JSON endpoint → log only

---

## Phase Reports (รายละเอียดเต็มต่อ phase)

แต่ละ phase report เก็บเป็น raw output ใน agent transcript. หากต้อง drill-down เฉพาะ phase ดูที่ section ด้านล่าง:

| Phase | Scope | C/H/M/L | Verdict |
|---|---|---|---|
| 1+2 | Public surface + AuthN | 6/9/10/7 | NEEDS FIXES |
| 3A | Finance + PHI AJAX | 5/10/9/4 | NEEDS FIXES |
| 3B | Other portal AJAX | 4/10/17/7 | NEEDS FIXES |
| 3C | Sub-module AJAX (e_Borrow/asset/consumables/user) | 8/16/21/5 | **BLOCK** |
| 4 | SQL injection + DB safety | 5/3/6+/— | **BLOCK** |
| 5 | File upload + storage | 6/10/9/4 | **BLOCK** |
| 6 | PHI / PDPA compliance | 4/9/8/4 | **BLOCK** |
| 7 | Config / secrets / deps | 5/7/7/~5 | NEEDS FIXES |

Composer audit: **clean** ✓
Git history check `config/secrets.php` + `.env`: **never committed** ✓

---

## Recommendations — process

1. **เปิด GitHub Issue ต่อ Critical แต่ละข้อ** ใน repo (28 issues) แล้ว label `security: critical`
2. **เพิ่ม CI step `composer audit`** ใน PR ที่ touch composer.lock
3. **CSP nonce migration** — แยกเป็น tracked epic (เพราะกระทบทุก inline script)
4. **PDPA Compliance Sprint** — รวม Tier 1 #C15-C19 + Tier 2 PHI items เป็น sprint แยก (มี legal/compliance approval)
5. **Quarterly re-audit** — รัน script นี้ทุก 3 เดือน (8 agents ขนาน ใช้เวลา ~5 นาที)

---

## References

- CLAUDE.md → "Permissions / Access Flags", "Finance Module", "Cross-module integration"
- `assets/STYLE_GUIDE.md` — design system
- ISO 27001 A.9.4 (access control), A.12.4 (logging), A.14.2 (secure development)
- PDPA Thailand Art. 19 (lawful basis), 23 (notice), 24 (consent), 27 (lawful processing), 32 (security), 37 (data integrity), 39 (record-keeping)
