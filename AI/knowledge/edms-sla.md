# EDMS SLA Module — Recipe & Reference

> Created: 2026-05-23 · Branch: `claude/adoring-babbage-anRYh`
> Scope: ทำความเข้าใจ + ขยาย/แก้ไขระบบ SLA สำหรับสารบรรณอิเล็กทรอนิกส์
> Related: `AI/scratch/edms-sla-spec.md` (full design spec)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│  EDMS Document (sys_doc_documents)                          │
│    ├─ doc_type (incoming/outgoing/internal/circular)        │
│    └─ priority_id → sys_doc_categories (ปกติ/ด่วน/...)      │
└────────────┬────────────────────────────────────────────────┘
             │ forward
             ▼
┌─────────────────────────────────────────────────────────────┐
│  Routing (sys_doc_routings)  ← attach SLA on INSERT         │
│    ├─ policy_id → sys_doc_sla_policies                      │
│    ├─ ack_deadline_at  (DATETIME คำนวณ business hours)      │
│    ├─ resolve_deadline_at                                    │
│    ├─ sla_state (on_track/warning/breached/met/paused/...)  │
│    ├─ acknowledged_at, warned_at, breached_at, met_at       │
│    └─ paused_at, paused_total_secs (compensation)           │
└────────────┬────────────────────────────────────────────────┘
             │ events
             ▼
┌─────────────────────────────────────────────────────────────┐
│  Audit (sys_doc_sla_events) — append-only timeline          │
│    event_type: started/warned/paused/resumed/acknowledged/  │
│                breached/met/extended/shortened/cancelled/   │
│                escalated/backfilled/override                │
└─────────────────────────────────────────────────────────────┘

           Cron (1h) → sla_tick.php
           ├─ Warning: ≤ warn_at_pct% time left → notify assignee
           ├─ Breach: deadline passed → notify + escalate
           └─ Cleanup: status=done → auto mark met
```

---

## 2. Database Schema

### Tables
| Table | Purpose |
|---|---|
| `sys_doc_sla_policies` | matrix (doc_type × priority_id) — ack_hours, resolve_hours, warn_at_pct, business_hours_only, escalate_to_role |
| `sys_doc_sla_calendar` | business_hours (weekday + start/end_time) + holidays (specific_date) |
| `sys_doc_sla_events` | audit trail event ทุก SLA action (started/warned/breached/met/...) |
| `sys_doc_routings` | ADD policy_id + 11 SLA-related columns |
| `sys_staff` | ADD access_edms_sla_admin (TINYINT) + notify_sla_via_line (TINYINT default 1) |
| `sys_staff_positions` | ADD is_head (TINYINT) — สำหรับ escalation ไป "หัวหน้าฝ่าย" |

### Critical index
```sql
ALTER TABLE sys_doc_routings ADD INDEX idx_sla_state (sla_state, resolve_deadline_at);
```
รองรับ cron query ที่ filter ตาม state + deadline (range scan).

### Migration file
`database/migrations/migrate_edms_sla.php` — idempotent, รันซ้ำได้ บรรจุ backfill logic ที่จะคำนวณ deadline ของ routing เก่าและ mark breached ย้อนหลังถ้าเลยไปแล้ว.

---

## 3. Default Seed (16 policies, business hours)

| doc_type | ปกติ | ด่วน | ด่วนมาก | ด่วนที่สุด |
|---|---|---|---|---|
| incoming | 8/72 | 4/24 | 2/8 | 2/4 |
| outgoing | 8/72 | 4/48 | 2/16 | 2/8 |
| internal | 6/48 | 4/24 | 2/12 | 2/6 |
| circular | 24/168 | 8/48 | 4/24 | 2/8 |

format: `ack/resolve` (business hours; 8h = 1 วันทำการเต็ม)

**กฎเหล็ก ของ minimum ack_hours = 2h**: รองรับ cron 1h tick — warning จะถูก detect ใน window ≥ 20% × 2h = 24min ก่อน cron round-trip 60 min. ถ้าจะลด ack ต่ำกว่า 2h ต้องลด cron interval ด้วย (เช่น 15min)

---

## 4. Business Hours Calculation

### Helper: `sla_compute_deadline($pdo, $start, $hours, $businessOnly)`
- ถ้า `$businessOnly = false` → return `$start + $hours hours` ตรงๆ (wall-clock)
- ถ้า `$businessOnly = true` → เดิน business time ทีละช่วง จนสะสมครบ:
  - ถ้า cursor อยู่ใน holiday หรือ off-day (weekend) → กระโดดไปต้นวันถัดไป
  - ถ้าก่อนเวลาเปิด → fast-forward ไป start_time
  - ถ้าหลังเวลาปิด → กระโดดไปวันถัดไป
  - กิน business time ของวันนี้แล้ว subtract จาก secondsNeeded
- Hard cap: 365 วันกัน infinite loop

### Calendar lookup (cached per-request)
```php
$cal = sla_get_business_calendar($pdo);
// $cal['hours'][weekday] = ['start' => 'HH:MM:SS', 'end' => 'HH:MM:SS']
// $cal['holidays']['Y-m-d'] = true
```

### Fallback safety
ถ้า `sys_doc_sla_calendar` ว่างเปล่า → helper จะ default เป็น Mon-Fri 08:00-16:00 (กัน config corruption ทำให้ระบบ pang)

---

## 5. State Machine

```
   ┌──────┐
   │ none │ ← routing เก่าที่ไม่มี policy attached
   └──────┘

   created → started → [on_track] ─warned_at─→ [warning] ─breached_at─→ [breached]
                          │                       │                          │
                          │                       │                          │
                          ▼                       ▼                          ▼
                       [paused]                [paused]                   [escalated]
                          │                       │
                       (resume) ──┘
                          │
                          ▼
                       resolve done → [met] (or stay breached if late)
```

**Mutually exclusive end states**: `met`, `breached`, `cancelled`
- ตีกลับ (return) routing → `cancelled`
- complete (done) ในเวลา → `met`
- complete (done) หลัง breached_at → stay `breached` แต่ stamp `met_at` (สำหรับ TAT คำนวณ)

---

## 6. Pause / Resume Logic

### Pause
- Permission: assignee (to_user_id) หรือ superadmin
- Side effect: `paused_at = NOW()`, `sla_state = 'paused'`
- Log event `paused` พร้อม reason

### Resume
- Side effect:
  1. คำนวณ `pause_secs = NOW() - paused_at`
  2. ชดเชย deadline ทั้ง `ack_deadline_at` + `resolve_deadline_at` ด้วย `+pause_secs`
  3. accumulate `paused_total_secs += pause_secs`
  4. clear `paused_at`, `warned_at` (จะ re-evaluate ใน cron ถัดไป)
  5. กลับเป็น `sla_state = 'on_track'`
- Log event `resumed` พร้อม metadata (pause_secs, new deadlines)

### Edge case
- ถ้า pause นานเกินไป (เช่น > 7 วัน) — ใน Phase 2 อาจ auto-resume + alert. Phase 1 ปัจจุบันไม่ jam time-out

---

## 7. Cron Job — `cron/edms_sla_tick.php`

### Setup
```cron
# crontab (Asia/Bangkok server)
0 * * * * curl -s "https://yourdomain.com/cron/edms_sla_tick.php?token=YOUR_TOKEN" > /dev/null 2>&1
```
หรือ CLI: `php cron/edms_sla_tick.php --token=YOUR_TOKEN`

### Token
- Default: `'rsu_sla_tick_8a7f3k2m'` — **ต้องเปลี่ยนใน production**
- Override ผ่าน env: `EDMS_SLA_CRON_TOKEN=xxx`

### Concurrency
- ใช้ MySQL advisory lock: `GET_LOCK('edms_sla_tick', 0)` — ถ้า lock ไม่ได้ exit ทันที
- กัน 2 cron instance ทำงานพร้อมกัน

### 3 Phases per tick
1. **Warning detection** — JOIN policies + filter `TIMESTAMPDIFF(SECOND, NOW(), resolve_deadline_at) <= ROUND(total_secs * warn_at_pct / 100)` AND `> 0`
2. **Breach detection** — `resolve_deadline_at < NOW()` AND status IN ('pending', 'acknowledged')
3. **Auto-met cleanup** — status='done' AND met_at IS NULL → mark met

แต่ละ phase wrap try/catch แยก — phase หนึ่ง fail ไม่กระทบ phase อื่น

---

## 8. Notification System

### Channels
1. **In-app log** (`sys_doc_logs` action='sla_warning'/'sla_breached'/'sla_escalated') — EDMS detail timeline render อยู่แล้ว
2. **LINE push** (best-effort) — ผ่าน `send_line_push()` ถ้า user link LINE + `notify_sla_via_line=1`

### LINE token
โหลดจาก `config/secrets.php` key `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` (same as `line_chat_helper.php`)

### Escalation logic (policy.escalate_to_role)
- `'superadmin'` → notify ทุก `sys_admins WHERE role='superadmin'`
- `'dept_head'` → notify staff ใน department เดียวกับ assignee + position มี `is_head=1`
- `'superadmin+dept_head'` (default) → ทั้ง 2 ฝ่าย (de-dup by user id)

### Fallback
ถ้า dept_head ไม่พบ (เช่น dept ไม่มีหัวหน้าหรือ assignee.department_id IS NULL) → log warning ใน `sys_doc_sla_events.metadata_json` แต่ยัง escalate ไป superadmin ได้ปกติ

---

## 9. UI Patterns

### Dashboard (`portal/_partials/edms/sla_dashboard.php`)
- 4 KPI tiles ที่ `fx-tilt` (on-time%, warning, breached, avg TAT) — period delta vs prior 30 days
- 2 charts: bar 12 เดือน (met vs breached) + donut by dept (90 วัน top 10)
- Top 10 overdue table — เรียงตาม deadline ใกล้สุด

### Policy admin (`portal/_partials/edms/sla_policies.php`)
- Matrix table (doc_type + priority) → 16 rows hover-friendly
- Modal Add/Edit ใช้ **Portal-Escape pattern** (teleport ไป body + z-index 9000) — ดู gold_card.php
- Toggle is_active inline + delete มี constraint check (block ถ้ามี routing อ้างอิง)

### Calendar admin (`portal/_partials/edms/sla_calendar.php`)
- Grid 2-col สำหรับเวลาทำการรายวัน (Mon-Fri)
- Table list สำหรับวันหยุดพร้อม pagination
- Modal เดียวรองรับทั้ง 2 kinds (business_hours / holiday)

### Detail SLA timeline (`portal/_partials/edms/detail.php`)
- ทุก routing row มี SLA progress bar (เขียว → เหลือง → แดง → น้ำเงิน) แสดง elapsed%
- Badge state + deadline text (รับทราบ X · deadline Y)
- 4 ปุ่ม action (รับทราบ/ขอข้อมูลเพิ่ม/เริ่มใหม่/ขอยืดเวลา) — แสดงเฉพาะ assignee/superadmin
- Forward modal มี checkbox "กำหนดเวลาเอง" + 2 datetime inputs + reason field — ถ้าไม่ติ๊ก ระบบใช้ policy auto

### Inbox countdown (`portal/_partials/edms/myinbox.php`)
- KPI ใหม่ 4 ตัว (open/warning/breached/done)
- Filter tabs 6 ตัว (open/warning/breached/paused/done/all)
- Column "เวลาเหลือ" คำนวณจาก `TIMESTAMPDIFF(MINUTE, NOW(), resolve_deadline_at)` ใน SQL
- Sort: `FIELD(sla_state, 'breached','warning','on_track','paused','met')` ASC

---

## 10. AJAX Endpoint (`portal/ajax_edms_sla.php`)

Pattern entity+action:

| entity:action | Permission | Purpose |
|---|---|---|
| `policy:list` | access_edms | list policies |
| `policy:upsert` | sla_admin | create/update |
| `policy:toggle` | sla_admin | toggle is_active |
| `policy:delete` | sla_admin | block ถ้ามี routing ref |
| `calendar:list/upsert/delete` | sla_admin (write) | business_hours + holiday |
| `routing:acknowledge` | assignee/superadmin | กดรับทราบ |
| `routing:extend` | assignee/sla_admin/super | ต้องใส่ reason 3+ ตัวอักษร |
| `routing:pause` | assignee/superadmin | + reason optional |
| `routing:resume` | assignee/superadmin | ชดเชยเวลา |
| `event:list` | access_edms | timeline ของ routing |
| `dashboard:kpi` | access_edms | KPI + delta |
| `dashboard:trend` | access_edms | 12 เดือน bar data |
| `dashboard:by_dept` | access_edms | donut by department |
| `dashboard:overdue_list` | access_edms | top 10 overdue |

ทุก endpoint: `validate_csrf_or_die()` + POST only + permission gate

---

## 11. Access Flag — `access_edms_sla_admin`

ตามมาตรฐาน 7-spot ของโปรเจกต์:

| Step | File | Note |
|---|---|---|
| 1 | `database/migrations/migrate_edms_sla.php` | ALTER sys_staff |
| 2 | `portal/queries/identity_queries.php` | auto-migrate + SELECT |
| 3 | `admin/auth/staff_login.php` | SELECT + position flag merge + SESSION |
| 4 | `portal/actions/identity_actions.php` | POST handler INSERT/UPDATE |
| 5 | `portal/index.php` | Identity Governance modal checkbox + load/reset JS + table icon + position matrix |
| 6 | `portal/_partials/profile.php` | $accessLabels + SELECT |
| 7 | `portal/_partials/edms/sla_policies.php` + `sla_calendar.php` | Section gate |

Read access (`access_edms`) ให้เห็น dashboard + ปุ่มกระทำกับ routing ตัวเอง
Admin access (`access_edms_sla_admin`) จัด policy + calendar + override ใดๆ ก็ได้

---

## 12. Gotchas / Bug Patterns

### 1. Routing.created_at timestamp drift
`sla_attach_to_routing()` ใช้ `created_at` เป็นจุดเริ่มต้น → ต้อง call **หลัง** INSERT (เพื่อให้ DB stamp NOW() แล้ว) **และ outside transaction** (ป้องกัน rollback ทำลาย SLA attach)

### 2. Modal stacking context (Portal-Escape)
`portal-header` มี `backdrop-filter: blur(12px)` → trap `position:fixed` ของ modal ใน partials. ต้องใช้ teleport pattern เหมือน gold_card.php — ทุก SLA modal ในโปรเจกต์ใช้ pattern นี้แล้ว

### 3. Dark mode บน partials ใหม่
ใช้ `.bg-white` (Tailwind utility) → covered โดย global override ใน `edms.php` shared style. ห้ามใช้ raw `background:#fff`

### 4. Cron tick missing fast SLA
ถ้า policy มี ack_hours = 1h, warning window = 12 min < cron 60min → จะ skip warning ทันทีไป breach. แก้: ไม่ allow ack < 2h ใน seed (validate ใน UI: input min="0.5" แต่ seed = 2)

### 5. Backfill on production
Migration จะ backfill ทุก routing ที่ status pending/acknowledged → mark breached ถ้าเลย deadline. ระวัง: ถ้ามี routing เก่าจำนวนมาก (10K+) อาจช้า. ใช้ `LIMIT 5000` ใน migration เป็น safety. รันซ้ำได้ปลอดภัย (idempotent: ตรวจ `policy_id IS NULL`)

### 6. Cross-timezone trap
PHP + MySQL server ต้องใน Asia/Bangkok เดียวกัน — ถ้า DB UTC แต่ PHP BKK → deadline จะคลาดเคลื่อน 7 ชั่วโมง. Set MySQL session: `SET time_zone = '+07:00'` หรือ server config

### 7. is_head flag ยังไม่ติ๊ก
หลัง migrate สำเร็จ — `is_head` ทุก position = 0. ต้อง superadmin ไป Identity Governance → แก้ position แต่ละตัว ติ๊ก is_head (TODO Phase 2: เพิ่ม UI ใน position modal)

---

## 13. Future Enhancements (Phase 2+)

- Email notification ผ่าน `mail_helper.php` (เพิ่ม channel ที่ 3)
- Holiday auto-import จาก API ของ Bank of Thailand หรือ Ministry of Labor
- SLA breach budget tracking (cost ของ breach แต่ละครั้ง)
- Multi-level escalation chain (level 1 → 2 → 3 ตาม minutes after breach)
- Custom SLA template per sender/recipient (เช่น VIP partner)
- Auto-resume กรณี pause นานเกิน X วัน
- SLA ระดับ doc_status (ทั้งเอกสาร) ไม่ใช่แค่ routing
- Mobile-first responsive ใน sla_dashboard.php
- Tour steps ใน `rsu-tour.js` สำหรับหน้า SLA dashboard

---

## 14. Files Reference

```
database/migrations/migrate_edms_sla.php   ← schema + seed + backfill (idempotent)
includes/edms_sla_helper.php               ← core functions (compute/attach/state)
includes/edms_sla_notify.php               ← notification + escalation
cron/edms_sla_tick.php                     ← 1h cron job (token-gated)
portal/ajax_edms_sla.php                   ← AJAX endpoints (16 actions)
portal/_partials/edms/sla_dashboard.php    ← KPI + charts
portal/_partials/edms/sla_policies.php     ← policy matrix admin
portal/_partials/edms/sla_calendar.php     ← business hours + holidays
portal/_partials/edms/detail.php           ← SLA timeline (modified)
portal/_partials/edms/myinbox.php          ← live countdown (modified)
portal/_partials/edms.php                  ← router (added 3 views)
portal/_partials/profile.php               ← access label
portal/index.php                           ← sidebar + Identity modal (modified)
portal/actions/identity_actions.php        ← POST handler (modified)
portal/queries/identity_queries.php        ← auto-migrate + SELECT (modified)
admin/auth/staff_login.php                 ← SESSION (modified)

AI/scratch/edms-sla-spec.md                ← full design spec (decisions log)
AI/knowledge/edms-sla.md                   ← THIS FILE (recipe + reference)
```

---

## 15. Validation Checklist (Production Deploy)

- [ ] รัน `migrate_edms_sla.php` → ตรวจ output ว่าทุก step สำเร็จ
- [ ] เช็คว่า backfill ทำงาน — `SELECT COUNT(*) FROM sys_doc_routings WHERE policy_id IS NOT NULL`
- [ ] ตั้ง crontab `0 * * * *` ชี้ไป `cron/edms_sla_tick.php` พร้อม secret token
- [ ] ลบ default `SLA_TICK_TOKEN` ใน cron file หรือ set env `EDMS_SLA_CRON_TOKEN`
- [ ] ตรวจ time_zone ทั้ง PHP + MySQL = Asia/Bangkok
- [ ] Superadmin ไป Identity Governance → ติ๊ก `is_head=1` สำหรับตำแหน่งหัวหน้าฝ่าย
- [ ] Superadmin grant `access_edms_sla_admin` ให้คนที่ดูแล policy/calendar
- [ ] User test: forward เอกสาร 1 ฉบับ → verify deadline ถูก attach + countdown ทำงาน
- [ ] User test: cron rune manually (`curl -s "URL?token=..."`) → verify warning/breach detection
- [ ] Verify LINE push: link LINE 1 user → forward เอกสาร → wait until warning → ดูว่า push เข้ามั้ย
