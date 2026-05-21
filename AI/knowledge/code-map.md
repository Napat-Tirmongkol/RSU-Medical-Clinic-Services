# Code Map — RSU Medical Clinic Services

> **เป้าหมาย**: ทำให้ AI agent (และมนุษย์ใหม่) เข้าใจ wiring ของระบบใน <60 วินาที
> โดยไม่ต้อง grep/explore ทั้ง repo
>
> เปรียบเหมือน "Google Maps ของ codebase" — รู้ว่าจาก URL ใด → เข้าไฟล์ไหน → เรียก AJAX อันไหน → ใช้ helper ใด
>
> **Updated**: 2026-05-21 · **Scope**: PHP files 534 · top-level modules 8 · AJAX endpoints 76 · helpers 32

---

## TL;DR — ถ้าอยากแก้ X ไปที่ไหน?

| ต้องการแก้ | ไฟล์ที่ควรเปิดก่อน |
|---|---|
| หน้า admin section ใหม่/เก่า | `portal/_partials/<section>.php` (39 partials) |
| AJAX ของ section นั้น | `portal/ajax_<topic>.php` (49 ไฟล์, root portal/) |
| Helper ที่ใช้ข้าม module | `includes/<topic>_helper.php` (32 ไฟล์) |
| Sidebar / routing portal | `portal/index.php` (section IDs + include map) |
| Database schema | `database/migrations/migrate_<feature>.php` |
| LINE webhook / FAQ logic | `api/line_webhook.php` + `includes/line_helper.php` |
| Cron job (รายวัน/ชั่วโมง) | `cron/<job>.php` (9 jobs) |
| Cross-module finance sync | `includes/finance_sync_helper.php::finance_sync_upsert()` |
| User-facing booking/profile | `user/<page>.php` (45 ไฟล์) |
| e_Borrow ยืม-คืน | `e_Borrow/` (own shell — base href trap!) |

---

## 1. Entry Points (URL → File)

```
/                            → index.php (landing)
/portal/                     → portal/index.php (admin hub, requires login)
/admin/                      → admin/index.php (LEGACY e-campaign — deprecate?)
/user/hub.php                → user-facing dashboard
/e_Borrow/                   → e_Borrow/index.php (sub-module)
/asset/                      → asset/index.php (ครุภัณฑ์)
/consumables/                → consumables/index.php (วัสดุสิ้นเปลือง)
/insurance_partner/          → external partner upload
/api/line_webhook.php        → LINE Messaging API webhook
/api/sentry_webhook.php      → Sentry error tracking webhook
/api/dashboard_public.php    → public KPI display
/cron/<job>.php              → CLI-only cron entries
```

**Auth gate by entry**:
- `portal/index.php` — `check_admin_session()` (sys_admins)
- `e_Borrow/`, `asset/`, `consumables/` — `includes/check_session.php` (SSO sync portal↔staff)
- `user/*.php` — LINE login session (`$_SESSION['line_user_id']`)
- `admin/` (legacy) — own auth (Google + staff_login.php)

---

## 2. Portal Section Map

Portal uses **single-page shell** (`portal/index.php`) + `?section=<key>` switching.
แต่ละ section = `id="section-<key>"` + `include _partials/<key>.php`.

### Sections + AJAX endpoint mapping

| Section ID | Partial file | AJAX endpoint(s) | Helper(s) |
|---|---|---|---|
| `dashboard` | (inline ใน index.php) | `ajax_dashboard_admin.php`, `ajax_stats.php` | `dashboard_data_sources.php` |
| `finance` | `finance.php` | `ajax_finance.php` | `finance_sync_helper.php`, `finance_receipt_helper.php` |
| `ai_assistant` | `ai_assistant.php` | `ajax_ai.php`, `ajax_ai_sandbox.php` | `ai_qa_helper.php`, `chat_helper.php` |
| `ai_prompts` | `ai_prompts.php` | `ajax_ai_prompts.php` | `ai_prompts_helper.php` |
| `ai_knowledge` | `ai_knowledge.php` | `ajax_ai_knowledge.php`, `ajax_ai_chunks.php` | `ai_knowledge_helper.php`, `ai_chunk_helper.php` |
| `ai_qa_lab` | `ai_qa_lab.php` | `ajax_ai_qa.php`, `ajax_ai_feedback.php` | `ai_qa_helper.php`, `ai_cache_helper.php`, `ai_telemetry_helper.php`, `ai_feedback_helper.php` |
| `identity` | (inline + actions) | `ajax_identity_users.php`, `ajax_privilege_inventory.php` | `auth_helper.php`, `portal/queries/identity_queries.php`, `portal/actions/identity_actions.php` |
| `pdpa_audit` | `pdpa_audit.php` | `ajax_pdpa_audit.php` | — |
| `gold_card` | `gold_card.php` | `ajax_gold_card.php` | — |
| `gold_card_pending` | `gold_card_pending.php` | `ajax_gold_card.php` | — |
| `insurance_sync` | `insurance_sync.php` | `ajax_insurance_sync.php`, `ajax_insurance_batches.php`, `ajax_insurance_export.php` | — |
| `insurance_dashboard` | `insurance_dashboard.php` | `ajax_insurance_batches.php` | — |
| `manage_insurance_partners` | `manage_insurance_partners.php` | `ajax_insurance_partners.php` | — |
| `registry_upload` | `registry_upload.php` | `ajax_insurance_sync.php` | — |
| `batch_status` | `batch_status.php` | `ajax_insurance_batches.php` | — |
| `clinic_data` | `clinic_data.php` | `ajax_clinic_data.php`, `ajax_clinic_master.php` | `clinic_status_helper.php` |
| `nurse_schedule` | (in clinic_data/) | `ajax_nurse_schedule.php`, `ajax_nurse_register.php`, `ajax_schedule_import.php` | — |
| `scholarship` | `scholarship.php` | `ajax_scholarship.php` | `scholarship_helper.php` |
| `announcements` | (inline) | `ajax_announcements.php` | — |
| `edms` | `edms.php` (+ `edms/` subfolder) | `ajax_edms.php` | — |
| `vaccinations` | `vaccinations.php` | `ajax_vaccinations.php` | `vaccination_helper.php` |
| `vaccine_catalog` | `vaccine_catalog.php` | `ajax_vaccine_catalog.php` | `vaccination_helper.php` |
| `activity_dashboard` | `activity_dashboard.php` | `ajax_activity_dashboard.php` | — |
| `activity_logs` | `activity_logs.php` | — | — |
| `error_logs` | `error_logs.php` | `ajax_error_logs.php` | `error_logger.php` |
| `sentry_events` | `sentry_events.php` | `ajax_sentry_events.php` | — |
| `monthly_report` | `monthly_report.php` | `ajax_monthly_report.php`, `ajax_kpi_override.php` | `kpi_override_helper.php` |
| `daily_summary` | `daily_summary.php` | `ajax_daily_summary.php` | `dashboard_data_sources.php` |
| `nurse_productivity` | `nurse_productivity.php` | `ajax_nurse_productivity.php` | — |
| `db_schema` | `db_schema.php` | `ajax_db_schema.php` | — |
| `sql_console` | `sql_console.php` | `ajax_sql_console.php` | — |
| `settings` | `settings.php` | `ajax_site_settings.php`, `ajax_maintenance.php` | `maintenance_helper.php` |
| `smtp_settings` | `smtp_settings.php` | `ajax_test_smtp.php` | `mail_helper.php` |
| `line_settings` | `line_settings.php` | `ajax_line_faq.php`, `ajax_line_groups.php`, `ajax_line_richmenu.php`, `ajax_line_stats.php`, `ajax_test_line.php` | `line_helper.php` |
| `email_logs` | `email_logs.php` | — | `mail_helper.php` |
| `profile` | `profile.php` | — | — |
| `documents` | `documents.php` | — | (registry inline) |
| `privilege_inventory` | (inline) | `ajax_privilege_inventory.php` | — |
| `sentry_test` | `sentry_test.php` | — | — |
| `apps_launcher` | `apps_launcher.php` | — | — |

### Pattern: เพิ่ม section ใหม่ใน Portal
1. สร้าง `portal/_partials/<new_section>.php`
2. ใน `portal/index.php` — เพิ่ม:
   - Sidebar menu (ใน `psb-group` ที่ตรงกลุ่ม — ดู CLAUDE.md "Portal Sidebar")
   - `<div id="section-<key>" class="portal-section" style="<?= $activeSection==='<key>'?'':'display:none;' ?>">`
   - `<?php include __DIR__ . '/_partials/<new_section>.php'; ?></div>`
3. (ถ้ามี AJAX) สร้าง `portal/ajax_<topic>.php` — ตามแม่แบบ section 5
4. ถ้ามี access flag → ทำ 7-spot checklist (CLAUDE.md "Permissions / Access Flags")

---

## 3. AJAX Endpoint Catalog (76 ไฟล์)

### Portal AJAX (49 ไฟล์ ที่ `portal/ajax_*.php`)

**Categories**:

- **AI**: `ajax_ai.php`, `ajax_ai_chunks.php`, `ajax_ai_feedback.php`, `ajax_ai_knowledge.php`, `ajax_ai_prompts.php`, `ajax_ai_qa.php`, `ajax_ai_sandbox.php`
- **Insurance**: `ajax_insurance_batches.php`, `ajax_insurance_export.php`, `ajax_insurance_partners.php`, `ajax_insurance_sync.php`
- **LINE**: `ajax_line_faq.php`, `ajax_line_groups.php`, `ajax_line_richmenu.php`, `ajax_line_stats.php`, `ajax_test_line.php`
- **Finance**: `ajax_finance.php` (entity+action pattern — ดู `txn:create/update/delete`, `category:*`, `recurring:*`, `attachment:*`, `audit:*`)
- **Clinic/Master**: `ajax_clinic_data.php`, `ajax_clinic_master.php` (universal entity+action pattern), `ajax_schedule_import.php`, `ajax_nurse_schedule.php`, `ajax_nurse_register.php`
- **Identity/Access**: `ajax_identity_users.php`, `ajax_privilege_inventory.php`, `ajax_pdpa_audit.php`
- **Activity/Logs**: `ajax_activity_dashboard.php`, `ajax_activity_logs.php`, `ajax_error_logs.php`, `ajax_sentry_events.php`, `ajax_log_404.php`
- **Dashboard/Reports**: `ajax_dashboard_admin.php`, `ajax_stats.php`, `ajax_daily_summary.php`, `ajax_monthly_report.php`, `ajax_nurse_productivity.php`, `ajax_kpi_override.php`
- **Gold Card**: `ajax_gold_card.php`
- **Scholarship**: `ajax_scholarship.php`
- **EDMS**: `ajax_edms.php`
- **Announcements**: `ajax_announcements.php`
- **Vaccinations**: `ajax_vaccinations.php`, `ajax_vaccine_catalog.php`
- **DB/SQL**: `ajax_db_schema.php`, `ajax_sql_console.php`
- **Settings/Maintenance**: `ajax_site_settings.php`, `ajax_maintenance.php`, `ajax_test_smtp.php`
- **Misc**: `ajax_pins.php` (pin/unpin sidebar items), `ajax_support_chat.php`

### Sub-module AJAX

- **e_Borrow**: `e_Borrow/ajax/*` (16 files — equipment lookup, borrow form data, approve/return flows) + `e_Borrow/admin/ajax_finance_sync.php` (**bridge ไปยัง Cash Book** — ใช้ pattern นี้ทุกครั้งจาก sub-module → portal finance)
- **Asset**: `asset/ajax/*` (7 files — scan_lookup, scan_mark, stock_take, upload_attachment)
- **Consumables**: `consumables/ajax/*` (2 files — delete, stock_take_count)
- **User**: `user/ajax_*.php` (11 files — borrow_data, chat, scholarship_booking, notify_counts, profile_coverage, vaccination_records)

### Standard AJAX Header (ที่ต้องมีทุกไฟล์)

```php
<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// 1. POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 2. CSRF (28/49 portal AJAX ใช้ pattern นี้แล้ว)
validate_csrf_or_die();

// 3. Auth check (เลือกตาม endpoint)
require_admin_session(); // หรือ require_role('superadmin')

// 4. ดึง action
$action = $_POST['action'] ?? '';
// switch ($action) ...
```

---

## 4. Helpers ใน `includes/` (32 ไฟล์)

### Auth & Security
- **`csrf.php`** — `generate_csrf_token()`, `validate_csrf_or_die()`, `csrf_token()` (per-session token)
- **`auth_helper.php`** — `check_admin_session()`, `require_admin_session()`, `require_role()`, `get_admin_id()`
- **`session_guard.php`** — session hardening + regen on privilege change
- **`rate_limit.php`** — file-backed rate limiter (`storage/rl/<key>.lock`)
- **`access_denied_page.php`** — render 403 page (ใช้ใน partial gate)

### AI Stack (7 helpers)
- **`ai_qa_helper.php`** — `ai_qa_generate_answer()` (Gemini call + RAG) + `ai_qa_is_time_sensitive_question()` (PHP — sync กับ JS counterpart!)
- **`ai_cache_helper.php`** — day-bounded answer cache (SHA256 key, auto-expire 23:59:59 Asia/Bangkok)
- **`ai_chunk_helper.php`** — RAG chunk store + retrieval (topK 8 default)
- **`ai_knowledge_helper.php`** — knowledge base CRUD
- **`ai_prompts_helper.php`** — prompt template registry
- **`ai_feedback_helper.php`** — thumbs up/down per response
- **`ai_telemetry_helper.php`** — event log (gemini_call/success/fail · cache_hit/miss · faq_hit · bypass · fallback_used)

### LINE & Communication
- **`line_helper.php`** — `line_push_message()`, `line_reply()`, signature validation
- **`mail_helper.php`** — PHPMailer wrapper + queue + audit
- **`chat_helper.php`** — support chat (admin↔user via LINE)

### Finance
- **`finance_sync_helper.php`** — `finance_sync_upsert($module, $sourceId, $payload)` (idempotent insert/update เข้า Cash Book) + `finance_sync_ensure_schema()`
- **`finance_receipt_helper.php`** — receipt PDF generator + `bahtText()` for Thai amount in words

### Domain helpers
- **`clinic_status_helper.php`** — `get_clinic_current_status()`, `get_clinic_hours_for_date()`, `build_clinic_test_flex()`
- **`vaccination_helper.php`** — dose number derivation + next_due_date with series awareness
- **`scholarship_helper.php`** — student scholarship calculations
- **`survey_helper.php`** — satisfaction survey logic
- **`kpi_override_helper.php`** — KPI manual override CRUD
- **`maintenance_helper.php`** — site maintenance toggle + banner
- **`dashboard_data_sources.php`** — shared queries for dashboard/daily_summary

### Logging / Tracking
- **`error_logger.php`** — structured error logging (file + DB)
- **`github_issue_helper.php`** — auto-create GitHub issue on critical errors

### Shared UI
- **`header.php`** — generic header include
- **`footer.php`** — generic footer
- **`user_bottom_nav.php`** — bottom nav shared across user pages (ห้าม copy markup!)
- **`org_chart_renderer.php`** — org chart HTML/SVG renderer
- **`ajax_helpers.php`** — `json_response()`, `json_error()`, parse helpers
- **`lang.php`** — i18n string lookup

### Modal partials
- **`user_modals/`** — modal templates for user side (booking, profile edit, ฯลฯ)

---

## 5. Cross-module Bridges (สำคัญมาก)

### Finance sync bridge — `finance_sync_upsert()`

โมดูลอื่นส่งยอดเข้า Cash Book → **idempotent** ด้วยคีย์ `(source_module, source_id)`

| source_module | source_id format | trigger |
|---|---|---|
| `nurse_schedule` | `yearBE*100+month` | OT รายเดือน |
| `scholarship` | `yearBE*100+month` | ค่าตอบแทนนักศึกษาทุนรายเดือน |
| `asset` | `asset.id` | จัดซื้อครุภัณฑ์ต่อชิ้น |
| `consumables_txn` | transaction id | รับเข้าวัสดุต่อ transaction |
| `eborrow_payment` | payment id | ค่าปรับยืมเกินกำหนด |
| `finance_recurring` | `YYYY-MM` | auto-generate รายเดือน |

**กฎเหล็ก**: sub-module **ห้ามเรียก `portal/ajax_finance.php` ตรง** (จะโดน redirect HTML)
→ ใช้ bridge endpoint ของ sub-module เอง เช่น `e_Borrow/admin/ajax_finance_sync.php`
   ที่ทำ session check ของ sub-module แล้ว delegate ไป `finance_sync_helper.php::finance_sync_upsert()`

### Shared location management (asset + consumables)

`asset/admin/manage_locations.php` — ใช้ร่วมกันระหว่าง 2 modules
→ consumables sidebar มีเมนู "จุดจัดเก็บ" ลิงก์ตรงไปไฟล์นี้ผ่าน `<base href>` resolution

---

## 6. Cron Jobs (9 jobs, `cron/`)

| Job | Schedule (แนะนำ) | หน้าที่ |
|---|---|---|
| `auto_cancel_expired_bookings.php` | every 5 min | ยกเลิก booking ที่หมดเวลา |
| `daily_report_to_line.php` | daily 18:00 | ส่งรายงานสรุปวันเข้า LINE group |
| `send_appointment_reminders.php` | every hour | LINE push reminder 24h ก่อนนัด |
| `purge_ai_telemetry.php` | weekly | ลบ telemetry log เก่า |
| `purge_error_logs.php` | weekly | ลบ error log เก่ากว่า 90 วัน |
| `send_error_digest.php` | daily 09:00 | ส่ง summary error 24h ผ่าน mail |
| `sync_richmenu.php` | on-demand | sync LINE rich menu ID |
| `run_backup.php` | daily 02:00 | trigger backup_db.sh |
| `backup_db.sh` | called by run_backup | mysqldump + gzip → `storage/backups/` |

---

## 7. Database Schema (Migration Index)

ทุก feature มี migration ของตัวเองใน `database/migrations/migrate_<feature>.php`
รันได้แบบ idempotent (ทุก migration ตรวจว่า table/column มีอยู่แล้วก่อนสร้าง)

**Key migrations**:
- `migrate_finance_module.php` — `sys_finance_categories/transactions/recurring/attachments/audit`
- `migrate_nurse_productivity.php` — productivity tracking (multi-tenant per dept)
- `migrate_nurse_schedule.php` — `sys_doctor_schedule` + `sys_nurse_schedule_monthly`
- `migrate_asset_module.php` + `migrate_asset_stock_take.php`
- `migrate_consumable_module.php` + `migrate_consumable_stock_take.php`
- `migrate_gold_card_module.php` + `migrate_gold_card_bulk_import.php` + `migrate_gold_card_processing_status.php`
- `migrate_insurance_partner_module.php` + `migrate_insurance_batch_workflow.php`
- `migrate_edms_module.php` + `migrate_edms_doc_types.php`
- `migrate_ai_knowledge_chunks.php` — chunk store for RAG
- `migrate_access_identity.php` + `migrate_access_flags_extra.php` + `migrate_access_scholarship.php` — access flags column adds
- `migrate_announcements.php`, `migrate_org_chart.php`, `migrate_kpi_overrides.php`, `migrate_privilege_table.php`, `migrate_monthly_report_module.php`, `migrate_dashboard_builder.php`, `migrate_dashboard_workbooks.php`
- `migrate_line_user_id_new.php` — LINE user ID migration (legacy → new format)
- `backfill_vaccination_records.php` — one-time backfill
- `fix_staff_schema.php` — schema repair

---

## 8. Common Patterns (Quick Reference)

### CSRF token — JS side
```js
const csrf = document.querySelector('meta[name="csrf-token"]').content;
fetch('ajax_finance.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
    body: new FormData(form)
});
```

### Modal Portal-Escape (กัน modal ติดใน content area)
ดู CLAUDE.md "Bug pattern — modal ติดอยู่ใน content area" + reference `portal/_partials/gold_card.php::gcTeleport()`

### Dark mode override
- Portal: `body[data-theme='dark'] .my-class { ... }`
- e_Borrow: `body.dark-mode .my-class { ... }`
- ห้ามใช้ inline `style="background:#fff"` — global dark override จับเฉพาะ `.bg-white` class

### Tailwind compile pitfalls (no JIT)
`assets/css/tailwind.min.css` pre-built — arbitrary values (`z-[200]`, `max-h-[90vh]`) ไม่ compile → ใส่ใน `<style>` block แทน

### Pagination (มาตรฐานทุกตาราง)
- 20 รายการ/หน้า · ปุ่ม `« ‹ … › »` · แสดง "หน้า X / Y · รวม N รายการ"
- ใช้ `LIMIT` + `OFFSET` ใน SQL

### SweetAlert2 (ห้ามใช้ alert/confirm/prompt)
โหลดอยู่แล้วที่ `portal/index.php` line ~540 — `await Swal.fire({ ... })` ทันที

### AI auto-reply (Gemini)
- Model: `gemini-2.5-flash` (primary) เท่านั้น
- Time-sensitive question → bypass FAQ cache 2 ชั้น: (a) keyword detect (Phase A) (b) admin mark `is_time_sensitive=1` (Phase B)
- Sync keyword logic 2 ฝั่ง: PHP `includes/ai_qa_helper.php::ai_qa_is_time_sensitive_question()` + JS `portal/_partials/ai_qa_lab.php::faqIsTimeSensitiveQuestion()`

---

## 9. Path Gotchas

### e_Borrow `<base href>` trap
`e_Borrow/includes/header.php` มี `<base href="/.../e_Borrow/">` →
relative path **นับจาก `e_Borrow/`** ไม่ใช่จากไฟล์ปัจจุบัน
- ✓ `../portal/index.php` (จาก `e_Borrow/admin/manage_fines.php`)
- ✗ `../../portal/index.php` (resolve นอก deploy root)

### `$adminRole` scope
ตัวแปรนี้มีเฉพาะใน scope ของ `portal/index.php` — สำหรับ partial ที่อาจถูกเข้าตรงๆ (เช่น `portal/nurse_schedule.php`) ให้คำนวณ local:
```php
$_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? '';
```

### Section gate vs sub-module gate
- **Portal partial**: gate ใน `portal/index.php` ก่อน include (เร็ว)
- **Sub-module** (consumables/asset): gate ใน `includes/check_session.php` หลัง SSO sync (รองรับทั้ง portal admin + sys_staff)

---

## 10. Diagnostic Recipes (เจอ symptom → ไปที่ไหน)

| Symptom | ดูที่ |
|---|---|
| AJAX ตอบ HTML แทน JSON | endpoint ไม่ได้ตั้ง `header('Content-Type: application/json')` หรือ session ถูก redirect → ตรวจ auth check + เปิด via Network tab |
| CSRF fail | token expire (session timeout) หรือ JS ไม่ส่ง `X-CSRF-Token` header → ตรวจ `validate_csrf_or_die()` + JS fetch |
| Modal ติดที่ content area (ไม่ centered viewport) | ancestor มี `transform/filter/backdrop-filter` → ใช้ Portal-Escape pattern (teleport ไป `<body>` + z-index ≥ 9000) — ดู CLAUDE.md |
| Settings ค่าหายตอน save | save handler overwrite missing keys เป็น default → ใช้ patch-merge pattern — `AI/knowledge/settings-singleton-patch-merge.md` |
| AI ตอบ "พรุ่งนี้ที่ NN พ.ค." ค้าง | FAQ cache มี answer ฝัง date → mark FAQ row `is_time_sensitive=1` หรือเพิ่ม keyword ใน `ai_qa_is_time_sensitive_question()` (sync 2 ฝั่ง!) |
| Finance receipt running number ซ้ำ | atomic transaction ใน `portal/finance_receipt.php` พลาด → ตรวจ lock pattern |
| Cron ไม่รัน | ตรวจ permission + log ที่ `errors/` + `ai_telemetry` ถ้าเกี่ยว AI |
| Dark mode surface ขาวค้าง | raw `background:#fff` หรือ inline style → เปลี่ยนเป็น `.bg-white` utility หรือเพิ่ม `data-tone` attribute + dark override |

---

## 11. Token-saving tips สำหรับ AI agent

- **อย่า read `portal/index.php` ทั้งไฟล์** (3900+ บรรทัด) — read แค่ section ที่เกี่ยวข้อง โดยใช้ grep หา `id="section-<X>"` ก่อน
- **อย่า read `CLAUDE.md` ซ้ำ** — auto-injected ใน system context อยู่แล้ว
- **AJAX endpoint แต่ละไฟล์มี action dispatcher** — read แค่ switch case ที่เกี่ยวข้องด้วย offset/limit
- **Helpers ใน `includes/`** — public functions อยู่ top of file มักจะมี docblock — read แค่ 50 บรรทัดแรกก็พอจะรู้ surface

---

## 12. ไม่ครอบคลุม (out-of-scope ของไฟล์นี้)

- **Component reference** — ดู `assets/STYLE_GUIDE.md` + Tailwind extended tokens ใน CLAUDE.md
- **Visual system / Bold & Colorful** — ดู CLAUDE.md "Visual System"
- **Sidebar grouping rules** — ดู CLAUDE.md "Portal Sidebar"
- **Access flag matrix** — ดู CLAUDE.md "Permissions / Access Flags" (7-spot checklist)
- **JS FX library** — ดู CLAUDE.md "FX Library" (`window.RsuFx`)
- **Guided tour** — ดู CLAUDE.md "Guided Tour"
- **Specific feature decisions** — ดู `AI/logs/YYYY-MM-DD-<topic>.md`

---

**Maintenance**: เมื่อเพิ่ม AJAX endpoint / helper / section ใหม่ — อัพเดต table ใน section 2 หรือ 3 หรือ 4
เมื่อเพิ่ม cross-module integration — อัพเดต section 5
ไม่ต้อง update ทุกรอบ — update เมื่อ structure เปลี่ยนจริง (ไม่ใช่แค่เพิ่ม column ใน table)
