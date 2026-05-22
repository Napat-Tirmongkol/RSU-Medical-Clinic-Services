# EDMS SLA System — Design Spec

> Draft · 2026-05-22
> Scope: เพิ่ม Service Level Agreement layer ให้กับ EDMS (สารบรรณอิเล็กทรอนิกส์)
> Status: รอ user review ก่อน implement

---

## 1. ปัญหาที่จะแก้ (Why)

ปัจจุบัน EDMS มีกลไก **due date** ในตาราง `sys_doc_routings.due_date` แล้ว และมี KPI "overdue" ใน `myinbox.php` + `reports.php` — แต่ยังขาด:

1. **SLA policy ที่ define ไว้ล่วงหน้า** — เจ้าหน้าที่ต้องกรอก due_date เองทุกครั้ง → ไม่สม่ำเสมอ
2. **คำนวณ deadline จาก business hours** — ตอนนี้ใช้ DATE ตรงๆ ไม่นับวันหยุด/นอกเวลาทำการ
3. **Acknowledge SLA vs Resolution SLA** — แยกการ "รับทราบ" กับ "ดำเนินการเสร็จ" ไม่ได้
4. **Pause/resume** — เมื่อรอข้อมูลเพิ่ม (returned/รอเอกสาร) นาฬิกาควรหยุด
5. **Escalation + warning** — ก่อนหมดเวลา X% ส่งแจ้งเตือน, breach แล้ว escalate ขึ้นหัวหน้า
6. **Compliance dashboard** — % เอกสารตรง SLA, mean TAT, breach rate by dept/priority/doctype
7. **Audit trail** — เก็บ history ของ SLA events (started, paused, resumed, breached, met)

---

## 2. Functional Requirements

### F1 — SLA Policy Catalog (admin-configurable)
- ตั้ง policy แยกตาม `(doc_type, priority)` matrix
- 1 policy ระบุ:
  - **ack_hours** — ภายในกี่ชั่วโมงต้องกด "รับทราบ" (acknowledge)
  - **resolve_hours** — ภายในกี่ชั่วโมงต้องดำเนินการเสร็จ
  - **warn_at_pct** — ส่ง warning ตอนเหลือ N% ของเวลา (default 20%)
  - **business_hours_only** — TINYINT(1) คิดเฉพาะเวลาทำการหรือ 24/7
  - **escalate_to_role** — role ที่จะ escalate ไปเมื่อ breach (เช่น `superadmin` หรือ position code)

### F2 — Business Calendar
- เก็บเวลาทำการ default (Mon-Fri 08:30-16:30) แยกได้ตามวันเสาร์-อาทิตย์
- ตาราง holidays (วันหยุดราชการ + วันหยุดของคลินิก)
- Helper: `sla_add_business_hours($from, $hours)` คืน DATETIME deadline จริง

### F3 — Auto-attach SLA เมื่อสร้าง routing
- ตอน admin กด "ส่งต่อ/มอบหมาย" ใน `sys_doc_routings`:
  - ระบบ lookup policy จาก (doc_type, priority) → คำนวณ `ack_deadline_at` + `resolve_deadline_at` (DATETIME)
  - ถ้า user override `due_date` เอง → ใช้ของ user (priority manual)
- INSERT record ใน `sys_doc_sla_events` (`event_type='started'`)

### F4 — Clock Pause/Resume
- เมื่อ routing.status = `returned` หรือ doc.status = `awaiting_info` → log `event_type='paused'`
- เมื่อ resume → log `event_type='resumed'` แล้วชดเชย elapsed pause time เข้า deadline
- Helper: `sla_effective_remaining_seconds($routing_id)` คำนวณจาก deadline + pause history

### F5 — Warning + Breach detection (cron-driven)
- Cron `cron/edms_sla_tick.php` รันทุก 15 นาที:
  - หา routing ที่ใกล้ breach (< warn threshold) แต่ยังไม่ถูก notify → mark `warned_at` + push LINE/email
  - หา routing ที่ breach แล้ว แต่ยังไม่ mark → set `breached_at` + log event + trigger escalation
- Escalation: สร้าง routing ใหม่ไป `escalate_to_role` พร้อม comment "auto-escalated"

### F6 — Dashboard + Reports
- KPI tiles: on-time %, warning count, breach count, avg TAT (ทั้งหมด/ตาม dept)
- Chart: trend ของ breach rate รายเดือน (Chart.js, theme-aware)
- Table: top 10 overdue routings + responsible person
- Filter: doc_type, priority, dept, date range

### F7 — Audit log
- ทุก SLA event เก็บใน `sys_doc_sla_events` (started/warned/paused/resumed/breached/met/cancelled)
- View ผ่าน detail.php → SLA timeline ของแต่ละ routing

### F8 — Manual override + approval
- admin มี permission `access_edms_sla_override` กดยืดเวลาได้
- ต้องใส่ reason → log + ส่ง notification ให้ superadmin

---

## 3. Database Schema (proposed)

### 3.1 `sys_doc_sla_policies` (config catalog)
```sql
CREATE TABLE sys_doc_sla_policies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_type        VARCHAR(30) NOT NULL COMMENT 'FK logical -> sys_doc_types.code',
    priority_id     INT UNSIGNED NULL COMMENT 'FK -> sys_doc_categories.id (kind=priority); NULL = match any priority',
    name            VARCHAR(120) NOT NULL,
    ack_hours       DECIMAL(6,2) NOT NULL DEFAULT 4.00,
    resolve_hours   DECIMAL(6,2) NOT NULL DEFAULT 48.00,
    warn_at_pct     TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT '0-100',
    business_hours_only TINYINT(1) NOT NULL DEFAULT 1,
    escalate_to_role VARCHAR(60) NULL COMMENT 'superadmin / position code / staff id',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doctype_priority (doc_type, priority_id),
    INDEX idx_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 `sys_doc_sla_calendar` (business hours + holidays)
```sql
CREATE TABLE sys_doc_sla_calendar (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind            ENUM('business_hours','holiday') NOT NULL,
    weekday         TINYINT NULL COMMENT '0=อาทิตย์...6=เสาร์ (สำหรับ business_hours)',
    specific_date   DATE NULL COMMENT 'สำหรับ holiday',
    start_time      TIME NULL,
    end_time        TIME NULL,
    name            VARCHAR(120) NULL COMMENT 'ชื่อวันหยุด',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_kind (kind),
    INDEX idx_specific_date (specific_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 `sys_doc_routings` — เพิ่มคอลัมน์ (ALTER, retain backward compat)
```sql
ALTER TABLE sys_doc_routings
    ADD COLUMN policy_id          INT UNSIGNED NULL AFTER due_date,
    ADD COLUMN ack_deadline_at    DATETIME NULL AFTER policy_id,
    ADD COLUMN resolve_deadline_at DATETIME NULL AFTER ack_deadline_at,
    ADD COLUMN sla_state          ENUM('on_track','warning','breached','met','paused','cancelled')
                                  NOT NULL DEFAULT 'on_track' AFTER resolve_deadline_at,
    ADD COLUMN acknowledged_at    DATETIME NULL AFTER sla_state,
    ADD COLUMN warned_at          DATETIME NULL AFTER acknowledged_at,
    ADD COLUMN breached_at        DATETIME NULL AFTER warned_at,
    ADD COLUMN paused_at          DATETIME NULL AFTER breached_at,
    ADD COLUMN paused_total_secs  INT UNSIGNED NOT NULL DEFAULT 0 AFTER paused_at,
    ADD INDEX idx_sla_state (sla_state, resolve_deadline_at);
```

### 3.4 `sys_doc_sla_events` (audit trail)
```sql
CREATE TABLE sys_doc_sla_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    routing_id      INT UNSIGNED NOT NULL,
    doc_id          INT UNSIGNED NOT NULL,
    event_type      ENUM('started','warned','paused','resumed','acknowledged','breached','met','extended','cancelled','escalated')
                    NOT NULL,
    actor_user_id   INT UNSIGNED NULL COMMENT 'NULL = system/cron',
    reason          VARCHAR(255) NULL,
    metadata_json   JSON NULL COMMENT 'extension data: extended_by_hours, escalated_to, etc.',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_routing (routing_id, created_at),
    INDEX idx_doc (doc_id, created_at),
    INDEX idx_event_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.5 Seed (default policies)
Bootstrap matrix 4 doc_types × 4 priority levels = 16 policies เริ่มต้น (ปรับได้ทีหลัง):

| doc_type | priority | ack | resolve |
|---|---|---|---|
| incoming | ปกติ | 8h | 72h |
| incoming | ด่วน | 4h | 24h |
| incoming | ด่วนมาก | 2h | 8h |
| incoming | ด่วนที่สุด | 0.5h | 4h |
| outgoing | (ทุก priority) | 4h | 48h |
| internal | (ทุก priority) | 6h | 48h |
| circular | (ทุก priority) | 24h | 168h |

---

## 4. UI / UX Plan

### 4.1 Partial ใหม่: `portal/_partials/edms/sla_policies.php`
- เข้าถึงผ่าน `?section=edms&edms_view=sla_policies`
- ตาราง matrix doc_type × priority พร้อมปุ่ม edit policy แต่ละช่อง
- modal สำหรับแก้ ack/resolve hours, warn %, escalate target
- toggle business_hours_only
- Pagination 20/หน้า (ตามกฎโปรเจกต์ แม้จะมี policy ไม่กี่ตัวก็ตาม)

### 4.2 Partial: `portal/_partials/edms/sla_calendar.php`
- 2 sections: เวลาทำการประจำสัปดาห์ + ปฏิทินวันหยุด
- import วันหยุดราชการ (อาจ seed จาก JSON)

### 4.3 ปรับ `detail.php` (เอกสาร 1 ฉบับ)
- เพิ่ม **SLA Timeline** ในแต่ละ routing row:
  - countdown / progress bar (เขียว → เหลือง → แดง)
  - badge "on track / warning / breached / met / paused"
  - แสดง `ack_deadline_at` + `resolve_deadline_at` พร้อม Bangkok timezone
  - timeline events จาก `sys_doc_sla_events` (collapsible)
  - ปุ่ม **"รับทราบ"** (set acknowledged_at + log event)
  - ปุ่ม **"ขอยืดเวลา"** (modal เลือกชั่วโมง + reason → log extended event)
  - ปุ่ม **"ขอข้อมูลเพิ่ม"** (toggle pause + log paused event)

### 4.4 ปรับ `myinbox.php`
- เพิ่ม column "เวลาเหลือ" (live countdown)
- filter: on_track / warning / breached / paused
- sort: ใกล้ breach ขึ้นก่อน

### 4.5 Partial ใหม่: `portal/_partials/edms/sla_dashboard.php`
- KPI 4 ตัว (period delta vs same-length prior period):
  - **On-time %** — (met + on_track) / total
  - **Warning** — กำลังจะ breach
  - **Breached** — ละเมิดแล้ว
  - **Avg TAT (h)** — เฉลี่ยเวลา resolve
- Chart 1: bar 12 เดือน — on-time vs breached count
- Chart 2: donut — breach by dept (top 10)
- Table: top 10 overdue (with ETA + responsible)

### 4.6 Sidebar
เพิ่ม **3 sub-menu** ใต้กลุ่ม **สื่อสาร** > EDMS:
- "ดัชนี SLA" (sla_dashboard)
- "นโยบาย SLA" (sla_policies) — gate `superadmin || access_edms_sla_admin`
- "ปฏิทินเวลาทำการ" (sla_calendar) — gate เดียวกัน

---

## 5. AJAX Endpoint Plan

ใช้ pattern entity+action ตามมาตรฐานโปรเจกต์ — สร้าง `portal/ajax_edms_sla.php`:

| entity:action | desc |
|---|---|
| `policy:list` | คืน list policies (filter active) |
| `policy:upsert` | สร้าง/แก้ policy |
| `policy:toggle` | เปิด/ปิด |
| `policy:delete` | ลบ (block ถ้ามี routing อ้างอิงอยู่) |
| `calendar:list` | คืน business hours + holidays |
| `calendar:upsert` | แก้เวลาทำการ/เพิ่มวันหยุด |
| `calendar:delete` | ลบ entry |
| `routing:acknowledge` | กดรับทราบ → set acknowledged_at + event |
| `routing:extend` | ขอยืด deadline (reason required) |
| `routing:pause` | toggle pause |
| `routing:resume` | resume + ชดเชย elapsed time |
| `event:list` | คืน events ของ routing |
| `dashboard:kpi` | KPI tiles + delta |
| `dashboard:trend` | data รายเดือน (bar chart) |
| `dashboard:by_dept` | data donut |
| `dashboard:overdue_list` | top 10 overdue |

ทุก endpoint:
- `validate_csrf_or_die()`
- รับเฉพาะ POST
- session check (access_edms || superadmin)
- write actions ต้อง log ลง `sys_doc_sla_events` + `sys_doc_logs`

---

## 6. Cron Job

`cron/edms_sla_tick.php` — รันทุก 15 นาที (crontab):

```php
// pseudo:
$pdo = db();
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));

// 1) Warning detection
$warns = $pdo->query("
    SELECT id, doc_id, resolve_deadline_at, paused_total_secs
    FROM sys_doc_routings
    WHERE sla_state = 'on_track'
      AND warned_at IS NULL
      AND resolve_deadline_at IS NOT NULL
      AND TIMESTAMPDIFF(SECOND, NOW(), resolve_deadline_at) <= ROUND(
            TIMESTAMPDIFF(SECOND, created_at, resolve_deadline_at) * 0.20
          )
");
foreach ($warns as $r) {
    sla_mark_warned($r['id']);
    sla_notify_assignee($r['id'], 'warning');
}

// 2) Breach detection
$breaches = $pdo->query("
    SELECT id, doc_id FROM sys_doc_routings
    WHERE sla_state IN ('on_track','warning')
      AND resolve_deadline_at < NOW()
      AND status IN ('pending','acknowledged')
");
foreach ($breaches as $r) {
    sla_mark_breached($r['id']);
    sla_escalate($r['id']);
}

// 3) Auto-mark met (status changed to done)
$pdo->exec("
    UPDATE sys_doc_routings
    SET sla_state = 'met', met_at = NOW()
    WHERE status = 'done' AND sla_state NOT IN ('met','breached','cancelled')
");
```

**กัน double-tick**: ใช้ advisory lock `SELECT GET_LOCK('edms_sla_tick', 0)` — ถ้า lock ไม่ได้ exit ทันที (กัน cron overlap)

---

## 7. Notification Channels

ในเวอร์ชันแรกใช้:
- **In-app notification** — โดย insert row ใน `sys_notifications` (existing) targeting `to_user_id`
- **LINE push** (ถ้า user มี LINE linked) — ใช้ helper `send_line_push()` ที่มีอยู่
- **Email** — เผื่อ Phase 2 (ผ่าน `sys_smtp_settings` + `mail_helper.php`)

Notification template (Thai):
```
[SLA Warning] เอกสาร {doc_number} "{subject}" จะหมดเวลา {minutes} นาที
[SLA Breached] เอกสาร {doc_number} เลย deadline แล้ว — กรุณาดำเนินการด่วน
```

---

## 8. Access Flags + Permission

เพิ่ม 1 flag ใหม่ใน `sys_staff`:
- `access_edms_sla_admin` TINYINT(1) DEFAULT 0 — สิทธิ์แก้ policy + calendar + override deadline

ใช้ pattern 7-spot ของ CLAUDE.md:
1. Migration: `database/migrations/migrate_edms_sla.php`
2. `portal/queries/identity_queries.php` — auto-migrate + SELECT
3. `admin/auth/staff_login.php` — SELECT + `$_SESSION`
4. `portal/actions/identity_actions.php` — POST handler
5. `portal/index.php` Identity Governance modal — checkbox
6. `portal/_partials/profile.php` `$accessLabels`
7. Section gate ใน `sla_policies.php` + `sla_calendar.php`

---

## 9. Migration File Structure

```
database/migrations/migrate_edms_sla.php
├── 1. CREATE sys_doc_sla_policies + seed 7-16 policies เริ่มต้น
├── 2. CREATE sys_doc_sla_calendar + seed business_hours Mon-Fri 08:30-16:30
├── 3. ALTER sys_doc_routings ADD COLUMN (policy_id, deadlines, sla_state, ...)
├── 4. CREATE sys_doc_sla_events
├── 5. ALTER sys_staff ADD COLUMN access_edms_sla_admin
└── 6. Backfill: เอกสารเก่าที่มี due_date → set resolve_deadline_at = due_date 23:59:59
```

ทุก statement idempotent (IF NOT EXISTS / check column ก่อน ALTER) — รันซ้ำได้

---

## 10. Helper Files

```
includes/edms_sla_helper.php
├── sla_get_policy(doc_type, priority_id): ?array
├── sla_compute_deadline(start_dt, hours, business_hours_only): DateTime
├── sla_is_business_time(dt): bool
├── sla_attach_to_routing(routing_id): void  // คำนวณ + INSERT event
├── sla_acknowledge(routing_id, user_id): void
├── sla_extend(routing_id, hours, reason, user_id): void
├── sla_pause(routing_id, user_id, reason): void
├── sla_resume(routing_id, user_id): void
├── sla_mark_warned(routing_id): void
├── sla_mark_breached(routing_id): void
├── sla_mark_met(routing_id): void
├── sla_remaining_seconds(routing_id): int
├── sla_state_label(state): string  // i18n Thai
└── sla_event_log(routing_id, event_type, actor_id, reason, metadata): void
```

---

## 11. Edge Cases / Risks

1. **Timezone**: ทุกที่ใช้ `Asia/Bangkok` — ตั้งใน MySQL session + PHP `date_default_timezone_set()`
2. **Daylight saving**: ประเทศไทยไม่มี — safe
3. **Policy ที่ user ลบ แต่มี routing อ้างอิง** — block delete, ให้ deactivate แทน
4. **Routing ถูกสร้างก่อน SLA module ติดตั้ง** — backfill จาก due_date (ถ้ามี) หรือ skip
5. **Cron overlap** — advisory lock
6. **Pause นานเกินไป** — set max pause = 7 วัน → auto-resume + log
7. **เอกสารปิดเรื่อง (close action)** — auto-mark met ถ้ายังไม่ breach
8. **Routing ที่ assign ไป to_dept (ไม่ระบุคน)** — escalate ตามหัวหน้า dept (lookup จาก sys_staff.dept + position role)
9. **Performance**: index `(sla_state, resolve_deadline_at)` รองรับ cron query
10. **Dark mode**: ทุก surface ใหม่ต้องตาม pattern CLAUDE.md (override `body[data-theme='dark']`)

---

## 12. Out of Scope (Phase 2+)

- Email notification (ใช้ in-app + LINE ก่อน)
- SLA สำหรับ doc.status (workflow ทั้งเอกสาร) — เน้นที่ routing เป็นหลัก
- Multi-language (ภาษาไทยอย่างเดียว)
- Holiday auto-import from API ของกระทรวงแรงงาน
- Custom escalation chain (level 1 → level 2 → level 3)
- SLA template per requester (เช่นลูกค้า VIP)
- SLA budget tracking (cost ของ breach)

---

## 13. Implementation Order

**Sprint 1 — Foundation (1-2 วัน)**
1. Migration + schema
2. `edms_sla_helper.php` core functions (compute, attach, state machine)
3. Auto-attach SLA ตอน create routing ใน `ajax_edms.php`
4. Backfill เอกสารเก่า

**Sprint 2 — UI (2-3 วัน)**
5. Policy admin partial + AJAX
6. Calendar admin partial + AJAX
7. Detail.php SLA timeline + countdown + ack button
8. Myinbox.php enhance (live countdown + filter)

**Sprint 3 — Dashboard + Automation (1-2 วัน)**
9. SLA dashboard partial (KPI + 2 charts + top overdue)
10. Cron `edms_sla_tick.php` + notification helpers
11. Access flag 7-spot

**Sprint 4 — Polish (1 วัน)**
12. Dark mode pass
13. Mobile responsive
14. Documentation (`AI/knowledge/edms-sla.md`)
15. Tour steps ใน `rsu-tour.js`

**Total estimate**: 5-8 วันทำงาน

---

## 14. Decisions ที่ต้อง confirm กับ user ก่อน implement

1. **Business hours default** ใช้ Mon-Fri 08:30-16:30 หรือ 24/7?
2. **Escalation target** — superadmin คนเดียว, หรือ "หัวหน้าฝ่าย" ที่ผูกกับ sys_staff_positions?
3. **Notification channel** — LINE push เป็น default หรือเปิด/ปิดต่อ user?
4. **Cron frequency** — 15 นาทีพอ หรือต้องเรียลไทม์กว่า?
5. **Backfill strategy** — เอกสารเก่าที่ไม่มี due_date จะให้ assign policy default หรือ skip?
6. **Pause permission** — anyone in routing chain สามารถ pause ได้ หรือเฉพาะ assignee?
7. **Manual deadline override** — ให้ user เลือก policy (จาก dropdown) หรือกรอก due_date เอง?

---

## 15. References

- ตารางเดิม: `database/migrations/migrate_edms_module.php`
- Existing partials: `portal/_partials/edms/{list,detail,myinbox,reports}.php`
- AJAX: `portal/ajax_edms.php`
- Doctor schedule (mirror สำหรับ business hours pattern): `portal/nurse_schedule.php`
- Audit log pattern (mirror): `portal/_partials/finance.php` + `fin_audit_log()`
- Cron pattern (mirror): `cron/finance_recurring.php` (ถ้ามี) หรือ existing cron jobs
- Notification helper: `includes/notification_helper.php` (verify exists)
- LINE push helper: `includes/line_push_helper.php` (verify)
