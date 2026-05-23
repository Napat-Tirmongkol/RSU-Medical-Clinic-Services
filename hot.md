# hot.md — Project Snapshot

> **Startup cache สำหรับ AI agent** — อ่านไฟล์นี้เป็นไฟล์แรกของทุก session ก่อนทำงานใหม่
> เป้าหมาย: ให้ agent เข้า working mode ได้ภายในไม่กี่วินาที โดยไม่ต้อง warm-up นาน
>
> **Update คู่กับ commit สำคัญทุกครั้ง** — ถ้าเปลี่ยน phase / ปิด task ใหญ่ / decision สำคัญ → แก้ที่นี่ทันที
> ใช้ภาษาธรรมชาติ ไม่ใช่ checklist — เขียนให้คนใหม่อ่านแล้วเข้าใจสถานะภายใน 60 วินาที

---

## Project ใจความ
**RSU Medical Clinic Services** — ระบบบริหารคลินิกแพทย์ของมหาวิทยาลัยรังสิต ครอบคลุม
ประกันสุขภาพ · นัดหมายแพทย์ · ครุภัณฑ์/วัสดุสิ้นเปลือง (e_Borrow + asset + consumables) · การเงิน (Cash Book) · LINE webhook · AI assistant (Gemini) · ISO 27001 audit · EDMS

Stack: PHP 8 + MySQL (PDO singleton) · Tailwind compiled CSS (no JIT) · vanilla JS · Chart.js · SweetAlert2 · Driver.js (tour) · Font Awesome

---

## สถานะปัจจุบัน (พฤษภาคม 2026)

### Phase ที่จบล่าสุด
- **EDMS SLA Module ครบ 4 Sprint (2026-05-23, branch `claude/adoring-babbage-anRYh`)** — Service Level Agreement layer สำหรับสารบรรณอิเล็กทรอนิกส์. **Sprint 1** (`c1f6d64`): 4 ตารางใหม่ (`sys_doc_sla_policies`, `sys_doc_sla_calendar`, `sys_doc_sla_events`) + ALTER `sys_doc_routings` 11 columns (policy_id, ack/resolve_deadline_at, sla_state, acknowledged_at, warned_at, breached_at, met_at, paused_at, paused_total_secs) + ALTER `sys_staff` (access_edms_sla_admin, notify_sla_via_line) + ALTER `sys_staff_positions` (is_head). Seed 16 policies (4 doc_type × 4 priority) + business_hours Mon-Fri 08:00-16:00. Backfill: routing เก่าที่ยัง open → attach policy ตาม (doc_type, priority) + mark breached ย้อนหลังถ้าเลย deadline. Helper `includes/edms_sla_helper.php` (compute_deadline business-hours-aware, attach_to_routing, acknowledge/extend/pause/resume/warned/breached/met, event_log). Hook ใน `portal/ajax_edms.php` routing:forward → sla_attach_to_routing() + routing:complete → sla_mark_met() + routing:return → cancel SLA. **Sprint 2** (`40d2ff2`): 3 partials ใหม่ (`sla_dashboard.php` KPI tiles + 12mo bar + by-dept donut + overdue list · `sla_policies.php` matrix CRUD + Portal-Escape modal · `sla_calendar.php` business_hours + holiday) + AJAX `portal/ajax_edms_sla.php` (16 endpoints entity+action) + ปรับ `myinbox.php` (KPI warning/breached, 6-tab filter, live countdown) + `detail.php` (SLA timeline + progress bar + ack/pause/resume/extend buttons + override checkbox ใน forward form) + sidebar sub-menu 3 อัน. **Sprint 3** (`c22054e`): cron `cron/edms_sla_tick.php` (1ชม. tick, advisory lock GET_LOCK, 3 phases warning→breach→cleanup) + notify helper `includes/edms_sla_notify.php` (in-app log + LINE push best-effort, escalation ไป superadmin + dept_head ตาม policy) + access flag 7-spot ครบ. Confirmed 7 decisions ใน `AI/scratch/edms-sla-spec.md` (Mon-Fri 08:00-16:00, escalation superadmin+dept_head, LINE push default on opt-out per user, cron 1h, backfill auto-attach + mark-breached, pause assignee-only, override auto + checkbox + reason required)
- **LINE Admin Chat Phase 1 MVP (2026-05-21)** — admin ↔ LINE user reply UI ใน portal · ตารางใหม่ `sys_line_chat_messages` auto-migrate · webhook hook 5 บรรทัด log inbound user message ตอน text → `line_chat_log_inbound()` (best-effort, ไม่ break flow) · helper `line_chat_send_admin_reply()` ทำ rate-limit (30/min, 500/day per admin) + anti-impersonation (line_user_id ต้องมี inbound history + format `U[0-9a-f]{32}`) + เรียก `send_line_push()` + log outbound พร้อม push_ok/error · UI partial `portal/_partials/line_chat.php` (LINE-green theme, conversation list filter ทั้งหมด/ต้องตอบ/วันนี้, 200 msgs/convo, pagination 20/page, auto-refresh 30s, dark mode, SweetAlert2 errors) · AJAX `portal/ajax_line_chat.php` entity/action (conversation:list/get + message:send_reply) · sidebar entry ใต้กลุ่ม "สื่อสาร" · gate `access_ai` (TODO ผูก dedicated flag `access_line_chat`). Pre-existing PHI-in-webhook-log issue (`line_webhook_log` ยังเขียน message_text เต็มใน sys_error_log) — flagged for Phase 2 hardening
- **AI Admin Chat Phase 1 MVP (2026-05-21)** — RAG-style chatbot สำหรับ admin · 2 ตารางใหม่ (`sys_admin_chat_threads`, `sys_admin_chat_messages`) auto-migrate ใน `includes/admin_chat_helper.php` · context ดึงจาก `dashboard_resolve_data()` 22 keys (verified) + `clinic_status_helper` · PHI scrub layer drop 25+ field names · Gemini multi-turn ผ่าน `gemini-2.5-flash` (fallback 2.0) · UI 2-column ใน partial `portal/_partials/admin_chat.php` (thread list pagination 20/page + chat messages + DOMPurify-sanitized markdown render + dark mode) · AJAX `portal/ajax_admin_chat.php` (entity/action: thread:list/get/create/archive/delete + message:send) · sidebar entry "ผู้ช่วยข้อมูล" ใน AI Suite · gate `access_ai`. Catalog ของ data source: `AI/knowledge/admin-bot-data-sources.md`. Follow-up post-MVP: soft-delete + structured audit, rate-limit, ARIA, lazy-load marked
- **Nurse position auto-flag (2026-05-21)** — `portal/actions/identity_actions.php` ตอน add/edit `sys_staff_positions` ถ้าชื่อตรงกับ 4 ชื่อพยาบาล (พยาบาลวิชาชีพ / หัวหน้าหอผู้ป่วย / รองหัวหน้าหอผู้ป่วย / พยาบาลหัวหน้าเวร) → force `flags.access_nurse_productivity = 1`. Symmetric — rename ออกจากชื่อพยาบาล → flag = 0. Fail-closed ตอน DB lookup เก่าพัง. Centralize ชื่อพยาบาลเป็น `includes/nurse_positions.php` (constants + `is_nurse_position()` helper) — refactor `nurse_productivity_import.php` + `ajax_nurse_productivity.php` ใช้ตามด้วย กัน drift. Recipe: `AI/knowledge/nurse-roles-canonical.md`
- **AI restoration ครบ Phases A + B + C (2026-05-18/19)** — บูรณะ AI auto-reply ที่ส่งคำตอบ stale ("พรุ่งนี้ที่ 17 พ.ค." ค้าง). **Phase A** (`5752680`): detect time-sensitive *question* → bypass FAQ cache → generate fresh. **Phase B** (`1166275` + polish `ff6d6ac`): per-FAQ `is_time_sensitive` flag + admin UI + RAG-first prompt (chunks ขึ้นเป็น primary, topK 5→8, FAQ KB เป็น fallback templates เท่านั้น) + scanner UI bulk "mark time-sensitive". **Phase C** (`bdc9475`): `sys_ai_answer_cache` (day-bounded, SHA256 key, auto-expire 23:59:59 Asia/Bangkok) + `sys_ai_telemetry` (event log: gemini_call/success/fail · cache_hit/miss · faq_hit · bypass · fallback_used · thumbs_up/down — privacy-safe SHA256 line user hash) + admin endpoints `health_summary` / `telemetry_recent` / `cache_purge`. Stale scanner UI + signed receipt URL ก็ landed ในช่วงเดียวกัน
- **LINE FAQ settings drift fix (2026-05-19, `ca4d2ce`)** — `save_clinic_faq_settings()` แก้เป็น patch-merge (ไม่ overwrite missing keys เป็น default) + audit table `sys_line_faq_settings_audit` (auto-create) + เลิก silent PDOException. Pattern เขียนไว้ที่ `AI/knowledge/settings-singleton-patch-merge.md` สำหรับใช้กับ singleton settings tables อื่น
- **Daily Summary (สรุปงานประจำวัน)** — single-page read-only dashboard รวมข้อมูลจาก 4 module ใน 1 หน้า (Productivity ต่อ dept · Cash Book income/expense + top categories · Stock movement + low-stock alerts · Other: gold card / insurance / asset / docs / schedule วันนี้) · date navigator (‹ › วันนี้) · period delta vs เมื่อวาน · A4 print · 7-spot flag `access_daily_summary` ครบ
- **Nurse Productivity (OPD)** — multi-tenant per `sys_departments` · 3-tab UI (Dashboard/Entry/Settings) · daily/monthly/yearly views · period delta · cross-dept comparison · Excel I/O (PhpSpreadsheet) · A4 print · auto-derive RN/หัวหน้า จาก `sys_nurse_schedule_monthly` เมื่อช่องว่าง · 7-spot access flag `access_nurse_productivity` ครบ
- **Context Persistence** — `hot.md` + `AI/` folder (logs/knowledge/scratch)
- **Finance / Cash Book** ครบ 3 phase (A: search/quick-dates/CSV/bulk · B: chart/donut/period delta · C: recurring/attachments/audit log) + dark mode pass สมบูรณ์
- **Bold & Colorful redesign** — sidebar section accents, rainbow header strip, hover micro-interactions (`--lift` var pattern), `RsuFx` library (counters/tilt/skeleton)
- **e_Borrow shell skin** — portal-matching glassmorphism + finance bridge สำหรับค่าปรับ → Cash Book
- **Inventory shared locations** — `asset/admin/manage_locations.php` ใช้ร่วมกันระหว่าง asset + consumables

### กำลังทำอยู่
- รอ migration `database/migrations/migrate_edms_sla.php` รันใน production (จะ auto-backfill routing เก่า) · user test SLA dashboard + verify cron tick · ตั้ง crontab `0 * * * * curl -s "URL/cron/edms_sla_tick.php?token=YOUR_TOKEN"` หรือ env `EDMS_SLA_CRON_TOKEN`
- รอ migration `database/migrations/migrate_nurse_productivity.php` รันใน production · user test Nurse Productivity module

### Decision ล่าสุดที่ต้องจำ
- **EDMS SLA architecture**: clock เริ่มจาก `sys_doc_routings.created_at` ตอน forward → policy lookup ตาม `(doc_type, priority_id)` → คำนวณ deadline ผ่าน business hours (skip weekends + holidays). `sla_state` enum 7 ค่า: on_track / warning / breached / met / paused / cancelled / none. **Cron 1h interval** กำหนด minimum ack_hours = 2h ใน seed (จะได้ warning window ≥ 24min ก่อน cron tick). **Escalation = ส่งให้ superadmin + dept_head พร้อมกัน** — dept_head lookup จาก `sys_staff_positions.is_head=1` + `sys_staff.department_id`. **Notification = LINE push default on** (toggle ผ่าน `sys_staff.notify_sla_via_line`) + in-app log ผ่าน `sys_doc_logs` (action='sla_warning/breached/escalated'). **Override pattern**: forward modal มี checkbox "กำหนดเวลาเอง" → ปลด lock input ack/resolve datetime + reason required → log event `override` ใน `sys_doc_sla_events`. Pause/resume gate = assignee หรือ superadmin (resume ชดเชย deadline ด้วย elapsed pause time). Spec ครบ: `AI/scratch/edms-sla-spec.md` · Recipe: `AI/knowledge/edms-sla.md`
- **AI auto-reply architecture**: stored FAQ **answers** จะ stale เร็ว (เพราะมี "พรุ่งนี้/วันที่ NN" ฝังเข้าไป) → ระบบ bypass FAQ cache 2 ชั้น (a) detect time-sensitive *question* keyword (Phase A) (b) admin mark FAQ row `is_time_sensitive=1` (Phase B). ทั้งสองชั้น call `ai_qa_generate_answer()` ตรงเพื่อ generate fresh กับ clinic context ตอนนั้น. **กฎเหล็ก**: เก็บ knowledge (facts) ไม่เก็บ answer ที่มี time reference. ดู `AI/logs/2026-05-19-faq-settings-drift-fix.md` + `includes/ai_qa_helper.php::ai_qa_is_time_sensitive_question()` (PHP) และ mirror ใน `portal/_partials/ai_qa_lab.php::faqIsTimeSensitiveQuestion()` (JS) — sync patterns ทั้ง 2 ฝั่งเสมอเมื่อเพิ่ม keyword
- **Singleton settings tables ต้องใช้ patch-merge**: save handler ห้าม overwrite missing keys เป็น default (จะ silent-clobber). อ่าน row ปัจจุบันก่อน → patch onto. ทุก write ต้อง audit + ห้าม silent `catch (PDOException)`. Recipe เต็ม: `AI/knowledge/settings-singleton-patch-merge.md`
- **Nurse Productivity → Schedule integration**: ตอน save daily ถ้าช่อง RN/หัวหน้าว่าง → derive จาก `sys_nurse_schedule_monthly` + `sys_nurse_schedule_global.nurses_json` แล้ว stamp `rn_source='schedule'`/`head_source='schedule'`. Position mapping: `พยาบาลวิชาชีพ` → RN, `หัวหน้าหอผู้ป่วย`/`รองหัวหน้าหอผู้ป่วย`/`พยาบาลหัวหน้าเวร` → head. Schedule เป็น singleton ใช้ร่วมทุก dept (ตามการตัดสินใจ)
- **Gemini model** ใช้ `gemini-2.5-flash` (primary) เท่านั้น — 1.5/2.0 ใกล้ deprecate
- **Modal pattern** ใช้ Portal-Escape (teleport ไป `<body>` + z-index ≥ 9000) ทุกครั้งที่ modal อาจติด ancestor stacking context — reference: `portal/_partials/gold_card.php`
- **Cross-module finance sync** ห้ามเรียก `portal/ajax_finance.php` ตรงจาก sub-module → ใช้ bridge `e_Borrow/admin/ajax_finance_sync.php` pattern เสมอ
- **Dark mode** — surface ที่ใช้ raw `background:#fff` ต้องมี override ที่ `body[data-theme='dark']` (portal) หรือ `body.dark-mode` (e_Borrow); utility class `.bg-white` มี global override แล้ว

### ข้อห้าม / ของอันตราย
- ห้ามใช้ arbitrary Tailwind values (`z-[200]`, `max-h-[90vh]`) — ไม่ compile · ใช้ `<style>` block แทน
- ห้ามใช้ `alert()/confirm()/prompt()` — ใช้ `Swal.fire()` (SweetAlert2 โหลดอยู่ที่ `portal/index.php` line ~540)
- ห้ามใส่เมนูลอยใน portal sidebar — ต้องอยู่ใน 12 section ที่กำหนด (ดู CLAUDE.md "Portal Sidebar")
- ห้าม commit secrets — `config/secrets.php` อยู่ใน `.gitignore`
- ตารางข้อมูลทุกตัวต้องมี pagination 20/หน้า + ปุ่ม `« ‹ … › »`

---

## Quick Reference (paths ที่เปิดบ่อย)

| ต้องการ | ดูที่ |
|---|---|
| Portal shell + section routing | `portal/index.php` |
| Portal partial ใหม่ | `portal/_partials/<name>.php` |
| Portal AJAX กลาง | `portal/ajax_clinic_master.php` (entity+action) |
| Finance AJAX | `portal/ajax_finance.php` |
| Cross-module finance sync | `includes/finance_sync_helper.php::finance_sync_upsert()` |
| Design tokens / components | `assets/STYLE_GUIDE.md` + `tailwind.config.js` |
| Tailwind source (rebuild!) | `assets/css/tailwind.src.css` → `npm run build:css` |
| FX library (counter/tilt/skeleton) | `assets/js/rsu-fx.js` (`window.RsuFx`) |
| Guided tour | `assets/js/rsu-tour.js` (`RsuTour.maybeAutoStart(key, steps)`) |
| Access flags / roles | CLAUDE.md → "Permissions / Access Flags" (7-spot checklist) |
| LINE webhook | `api/line_webhook.php` |
| Doctor schedule | `sys_doctor_schedule` (regular/override/off) |
| Asset locations (shared) | `asset/admin/manage_locations.php` |
| e_Borrow shell | `e_Borrow/includes/header.php` + `e_Borrow/assets/css/eb-skin.css` |

---

## Workflow recap
1. อ่าน `hot.md` (ไฟล์นี้) + `CLAUDE.md` ทุก session
2. ก่อนสร้างของใหม่ — เช็คว่ามี token / component / helper เดิมรองรับอยู่ไหม
3. หลัง commit ใหญ่ → อัพเดต `hot.md` (Phase / Decision / กำลังทำ) + drop note ใน `AI/logs/YYYY-MM-DD-<topic>.md` ถ้า decision สำคัญ
4. ของชั่วคราว / draft → `AI/scratch/` (จะถูก clean เป็นระยะ)
5. Distilled knowledge ถาวร → `AI/knowledge/` (เช่น schema notes, troubleshooting recipes)

ดู `AI/README.md` สำหรับ convention เต็ม
