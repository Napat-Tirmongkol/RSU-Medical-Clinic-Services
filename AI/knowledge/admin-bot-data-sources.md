# Admin Bot — Available Data Sources

> Catalog of read-only data sources usable by a RAG-style admin AI chatbot for the
> RSU Medical Clinic portal. Each entry is annotated with PHI risk, access gating,
> and recommended exposure tier (Phase 1/2/3 or Excluded).

PHI/sensitivity legend:
- 🟢 **Safe aggregate** — counts, percentages, status breakdowns, trends
- 🟡 **Financial / mildly sensitive** — totals/aggregates of money, individual txn rows
- 🔴 **PHI / PII** — patient name, citizen_id, HN, diagnosis, ราย-person fields

---

## Overview

The portal has **two distinct aggregation layers** the bot can call:

1. **Resolver catalog** (`includes/dashboard_data_sources.php`) — 41 predefined keys
   (`mti_*`, `gold_*`, `finance_*`, `asset_*`, `consumable_*`, `scholarship_*`,
   `campaign_*`, `satisfaction_*`) all returning normalized shapes
   (`count` / `breakdown` / `timeseries` / `percentage`). Single function entry-
   point: `dashboard_resolve_data(PDO, key, filter)`. **This is the cleanest
   interface for the bot — wire to this first.**

2. **Module ajax endpoints** (`portal/ajax_*.php`) — richer, per-domain summaries
   (daily roll-ups, period delta, per-row details). Best for "what happened today"
   / "show me this department" follow-up questions.

Plus two specialized helpers:
- `includes/clinic_status_helper.php` — clinic open/close, doctor roster (already
  used by LINE webhook). Safe and high-value.
- `portal/ajax_daily_summary.php` — single-call daily aggregate
  (productivity + finance + stock + schedule).

**Recommendation**: expose Phase 1 sources behind a single tool
`get_admin_metric(key, filter)` that delegates to `dashboard_resolve_data()` —
that gives the bot 41 safe aggregates day one with zero PHI surface.

---

## By Domain

### Clinic Status & Schedule

#### `get_clinic_current_status(PDO, ?DateTimeImmutable)`
- file: `includes/clinic_status_helper.php`
- returns: `{is_open_now, state(open_now|before_open|after_close|closed_today), today_open, today_close, minutes_until_close, minutes_until_open, next_open_date, next_open_time, next_open_label, today_note}`
- access: pure function — no gating (already used by public LINE webhook)
- PHI: 🟢 safe
- notes: ideal for "วันนี้คลินิกเปิดไหม / เปิดถึงกี่โมง"

#### `get_clinic_hours_for_date(PDO, date)`
- file: `includes/clinic_status_helper.php`
- params: `date='YYYY-MM-DD'`
- returns: `{closed:bool, open_time, close_time, note, source:holiday|special|regular|no_regular}`
- access: pure function
- PHI: 🟢 safe

#### `get_clinic_doctors_for_date(PDO, date, respectClosure=true)`
- file: `includes/clinic_status_helper.php`
- returns: list of `{id, staff_id, doc_title, doc_name, role, room_name, room_code, start_time, end_time, service_type, notes}`
- access: pure function
- PHI: 🟡 staff names (not patients) — internal employee directory; safe for admin bot
- notes: respects holidays/closures by default. Use for "พรุ่งนี้มีหมอท่านไหนออกตรวจ"

#### `get_clinic_profile_brief(PDO)`
- file: `includes/clinic_status_helper.php`
- returns: `{name, phone}`
- access: pure function
- PHI: 🟢 safe (clinic-level info)

---

### Daily Summary (one-call aggregator)

#### `portal/ajax_daily_summary.php?action=summary:get&date=YYYY-MM-DD`
- file: `portal/ajax_daily_summary.php`
- access: `superadmin || admin || access_daily_summary`
- PHI: 🟢 mostly safe — nurse names appear in `other.schedule[].name` (🟡 employee directory, no patient data)
- returns: bundle of sections
  - `headline: {date, dateBE, dayName, isWeekend, isToday, totals:{visits, revenue, expense, alerts}}`
  - `productivity: {totalVisits, avgProd, deptCount, list:[{deptId, deptName, patients, rn, head, prod, status, rnSource, headSource}], prevVisits, visitsDelta}`
  - `finance: {income, expense, net, txnCount, topCategories:[{name, kind, icon, color, count, total}], incomeDelta, expenseDelta}`
  - `stock: {qtyIn, qtyOut, itemsTouched, txnCount, topIssued:[{id, name, unit, qty, onHand, min}], lowStock:[{id, name, unit, onHand, min}]}`
  - `other: {goldCard, insurance, assetEvents, docs:{in,out}, schedule:[{name, position, shift}]}`
- notes: **highest signal-per-call source**. Bot can answer "สรุปวันนี้" with one query.

---

### Resolver Catalog (`dashboard_resolve_data`)

Single dispatcher in `includes/dashboard_data_sources.php` — call as
`dashboard_resolve_data($pdo, $key, ['year'=>$y, 'month'=>$m])`.
**Access for all keys**: any logged-in admin can read (catalog is also exposed via
`ajax_dashboard_admin.php?entity=catalog&action=get`, which gates by
`superadmin || access_dashboard_admin`, but the resolver itself has no internal
gate — just don't call it from non-admin contexts).

PHI for resolver keys is 🟢 **across the board** — all queries are
`COUNT/SUM/GROUP BY`, never individual rows.

#### Insurance (MTI)
| Key | Shape | Description |
|---|---|---|
| `mti_total_active` | count | active members `insurance_status='Active'` |
| `mti_total_all` | count | all members |
| `mti_breakdown_type` | breakdown | by `member_status` (บุคลากร/นศ.) |
| `mti_breakdown_status` | breakdown | by `insurance_status` |
| `mti_trend_12m` | timeseries | last 12 months created |
| `mti_expiring_30d` | count | coverage ending within 30 days |

#### Gold Card (บัตรทอง)
| Key | Shape | Description |
|---|---|---|
| `gold_total` | count | all members |
| `gold_approved` | count | status in (approved, active) |
| `gold_pending_docs` | count | status in (pending, submitted) |
| `gold_by_status` | breakdown | mapped status labels |
| `gold_by_hospital` | breakdown | top 10 `hospital_main` |
| `gold_by_type` | breakdown | by `member_type` |
| `gold_trend_12m` | timeseries | by `application_date` |

#### Combined Coverage
| Key | Shape | Description |
|---|---|---|
| `coverage_total` | count | `DISTINCT citizen_id` from MTI active + gold approved/active |
| `coverage_compare_trend` | timeseries | 2 series (MTI vs gold) |

#### Finance
| Key | Shape | Description |
|---|---|---|
| `finance_income_total` | count | sum `kind='income'` |
| `finance_expense_total` | count | sum `kind='expense'` |
| `finance_balance` | count | income − expense |
| `finance_by_category` | breakdown | grouped by `category_id` |
| `finance_income_vs_expense_trend` | timeseries | 12-month trailing |

#### Assets (Equipment)
| Key | Shape | Description |
|---|---|---|
| `asset_total` | count | all assets |
| `asset_by_status` | breakdown | in_use/repair/disposed/lost/reserve |
| `asset_by_category` | breakdown | by `asset_categories` |
| `asset_warranty_expiring_90d` | count | warranty within 90d, not disposed/lost |

#### Consumables
| Key | Shape | Description |
|---|---|---|
| `consumable_total` | count | active items |
| `consumable_low_stock` | count | `qty_on_hand <= min_stock` |
| `consumable_by_category` | breakdown | by `consumable_categories` |
| `consumable_receive_trend` | timeseries | `txn_type='receive'` 12m |

#### Scholarship (นักศึกษาทุน)
| Key | Shape | Description |
|---|---|---|
| `scholarship_bookings_total` | count | bookings with `status='booked'` |
| `scholarship_slots_open` | count | future slots open |
| `scholarship_booking_trend` | timeseries | 12m booking trend |

#### Campaigns / Satisfaction
| Key | Shape | Description |
|---|---|---|
| `campaign_bookings_total` | count | all camp_bookings |
| `campaign_active` | count | active campaigns |
| `campaign_booking_rate` | percentage | used / total_capacity |
| `campaign_booking_trend` | timeseries | 12m |
| `satisfaction_avg_rating` | count (float) | AVG rating |
| `satisfaction_distribution` | breakdown | 1-5 stars |
| `satisfaction_trend_12m` | timeseries | avg per month |

#### Vaccine × LINE
| Key | Shape | Description |
|---|---|---|
| `campaign_vaccine_via_line` | count | distinct users w/ line_user_id |
| `campaign_vaccine_total` | count | distinct vaccine campaign users |
| `campaign_vaccine_line_vs_other` | breakdown | LINE vs other channels |
| `campaign_vaccine_via_line_trend` | timeseries | 12m |

#### Helper functions
- `dashboard_data_sources_catalog()` → array of all keys + label/shape/widgets
- `dashboard_custom_datasets(PDO)` → user-uploaded CSV datasets (`custom_*`)
- `dashboard_available_years(PDO)` → list of years with data (for dropdowns)

---

### Finance (deeper drill-down)

#### `portal/ajax_finance.php?action=analytics`
- params: `from, to, kind, category_id, q`
- access: `superadmin || admin || access_finance`
- PHI: 🟡 — aggregates only, but reaches per-category totals
- returns:
  - `monthly: [{month, income, expense, net, count}]` (12-month trailing)
  - `categories: [{category_id, kind, name, icon, color, total, count}]` (filter-aware)
  - `delta: {prev_from, prev_to, income_prev, expense_prev, net_prev}` (same-length prior period)

#### `portal/ajax_finance.php?action=list`
- params: `from, to, kind, category_id, q, page`
- access: `superadmin || admin || access_finance`
- PHI: 🔴 — each row has description/reference text that may contain person names; for bot use prefer `summary` only
- returns:
  - `rows[]` (per-txn — **DO NOT expose to bot without filtering**)
  - `summary: {income, expense, net, count}` — **safe summary, expose this**
  - `total, page, per_page` — pagination
  - `categories: [{id, name, kind, icon, color}]` — for picker
  - `filters: {from, to, kind, category_id}`

#### `portal/ajax_finance.php?action=recurring:list`
- access: `superadmin || admin || access_finance`
- PHI: 🟡 — template names + amounts; no person data
- returns: `rows[]` of recurring rule definitions (`name, kind, amount, day_of_month, active, last_generated_ym`)

#### `portal/ajax_finance.php?action=audit:list&txn_id=N`
- access: `superadmin || admin || access_finance`
- PHI: 🟡 — actor names + before/after diffs
- returns: `rows[]` of `{action, changes_json, performed_by, performed_by_name, performed_at, ip_addr}`

---

### Activity / Audit Logs

#### `portal/ajax_activity_dashboard.php?action=snapshot`
- access: **superadmin only**
- PHI: 🟡 — admin actor names, IP addresses, action descriptions
- returns:
  - `kpi: {today, today_delta_pct, yesterday, active_admins, total, peak_hour, peak_count}`
  - `hourly: [{label, count}]` (24-h rolling)
  - `top_admins: [{user_id, name, c}]` (7d)
  - `categories: [{key, label, color, icon, count}]` (auth/identity/booking/campaign/insurance/announce/system/other)
  - `heatmap: {data:[7][24], max}` (30d DOW × hour)
  - `recent: [{id, actor, action, desc, ip, timestamp, cat, cat_*}]` (last 15)
  - `latest_id` — for delta polling
- notes: useful for security/governance questions ("วันนี้ใครเข้าระบบบ้าง")

#### `portal/ajax_activity_dashboard.php?action=tick&since_id=N`
- delta poll; up to 30 newer events. Same shape as `recent`.

#### `portal/ajax_pdpa_audit.php?action=stats`
- access: `superadmin || access_identity`
- PHI: 🟢 aggregate only
- returns: `{stats:{total, full, partial, legacy, general_only}, versions:[{v,n}], recent:[{d,n}]}`
- notes: PDPA consent coverage stats; great for governance dashboard.

#### `portal/ajax_pdpa_audit.php?action=list`
- access: `superadmin || access_identity`
- PHI: 🔴 — `full_name, citizen_id (masked), line_user_id, consent_ip, consent_user_agent` — **mask before exposing to bot**
- returns: paginated table; bot should prefer `stats` over `list`.

---

### Nurse Productivity

#### `portal/ajax_nurse_productivity.php?action=analytics:summary`
- params: `dept_id, from, to`
- access: `superadmin || admin || access_nurse_productivity`
- PHI: 🟢 dept-level aggregates only
- returns:
  - `summary: {count, totalVisits, totalNeeded, totalAvailable, avgProd, optimal, under, over}`
  - `settings: {hpv, thresholds, hospital_name, ...}`
  - `labels[], prods[]` — chart data
  - `dowAvg[7]` — by day-of-week
  - `delta: {prevFrom, prevTo, avgProd%, totalVisits%, totalNeeded%}`

#### `?action=analytics:rollup_monthly` / `rollup_yearly`
- params: `dept_id`
- returns: `months[]` or `years[]` with `{ym|year, visits, needed, available, avgProd, days}`

#### `?action=analytics:cross_dept`
- params: `from, to`
- access: `superadmin || admin || access_nurse_productivity`
- returns: `depts: [{deptId, deptName, visits, days, avgProd}]` — cross-dept compare

#### `?action=depts:list`
- returns: `[{id, name, sort_order}]`

#### `?action=settings:get&dept_id=N`
- returns: dept productivity settings (`hpv, threshold_low, threshold_high, hospital_name, level, beds, ...`)

---

### Gold Card

#### `portal/ajax_gold_card.php?entity=member&action=stats` (POST)
- access: `superadmin || access_insurance`
- PHI: 🟢 aggregates only
- returns: `stats: {total, approved, pending, rejected, expiring, staff, student}`

#### `?entity=folder&action=tree` (POST)
- returns: `folders:[{year, month, label, full_label, count, approved, pending}], no_date_count`

#### `?entity=chart&action=overview` (POST)
- returns: `{trend: <gold_trend_12m payload>}` — convenience wrapper

#### `?entity=member&action=list` (POST)
- access: same
- PHI: 🔴 — `full_name, citizen_id, phone, hospital_main, member_type, status, application_date` per row
- notes: **do not expose row data to bot** — only counts via `member:stats`

---

### Insurance Batches

#### `portal/ajax_insurance_batches.php?action=stats` (POST)
- access: `superadmin || access_insurance` (or `access_registry` for partner self-scoped view)
- PHI: 🟢 aggregate
- returns: `by_status: {pending_review: N, approved: N, ...}` keyed by status

#### `?action=list/detail` — 🔴 per-batch + per-member rows. Skip for bot.

---

### Scholarship Dashboard

#### `portal/ajax_scholarship.php?entity=dashboard&action=get`
- access: scholarship admin (gated upstream by partial inclusion)
- PHI: 🟡 — `top[]` and `today_list[]` include student `full_name, student_code, faculty`
- returns:
  - `kpis: {active_students, pending, today_shifts, month_hours, month_paid, month_pay, pay_rate}` — 🟢 safe
  - `daily[30]: {date, label, hours, paid}` — 🟢
  - `top[5]: {student_id, hours_scholarship, hours_paid, total, full_name, student_code, faculty}` — 🔴
  - `today_list[]: {student_id, student_name, student_code, shift_date, start_time, end_time, arrival_status}` — 🔴
  - `recent[10]: {action, status, event_at, student_name, ...}` — 🔴
- notes: expose KPIs + daily aggregates only; redact names in lists.

---

### KPI Override

#### `portal/ajax_kpi_override.php?action=list`
- access: depends — used for manual overrides on dashboard KPIs
- returns: list of `{metric_key, value_override, note, updated_by, updated_at}` rows
- PHI: 🟢 — these are aggregate metric values, no PII
- notes: bot can use this to show which KPIs have manual overrides set.

#### `?action=get&key=X` — single metric override
#### `?action=set / clear` — POST mutations (out of scope for read-only bot)

---

### LINE Operations

#### `portal/ajax_line_stats.php?action=quota`
- access: any logged-in admin (calls LINE Messaging API)
- PHI: 🟢 — message quota numbers
- returns: `{quota: <LINE API response>, consumption: <LINE API response>}`

#### `portal/ajax_line_stats.php?action=delivery&date=YYYYMMDD`
- returns: `{date, data: <LINE delivery stats>}`

---

### Site / Schema Metadata

#### `portal/ajax_db_schema.php?action=graph`
- access: superadmin (in portal section gate)
- PHI: 🟢 schema metadata only
- returns: `{nodes:[{id,label,domain,color,rows}], edges:[...], stats:{tables,fk,heuristic}, legend:[...]}`
- notes: useful for "ในระบบมีตารางอะไรบ้าง" / database overview

#### `portal/ajax_db_schema.php?action=table&name=X`
- returns: columns + row count + recent rows of a single table — handle with care.

---

### Realtime Stats (header polling)

#### `portal/ajax_stats.php` (GET)
- access: any logged-in admin
- PHI: 🟢 aggregate
- returns: `{users, camps, borrows, total_quota, used_quota, booking_rate, activity:[5]}`
- notes: lightweight; suitable for "ตอนนี้ระบบมีผู้ใช้กี่คน / แคมเปญที่ active กี่ตัว"

---

### Sentry / Error Logs

#### `portal/ajax_sentry_events.php?action=list`
- access: **superadmin only**
- PHI: 🟡 — stack traces, URLs, user IDs in `culprit/title`
- returns: paginated event list `{id, sentry_id, resource, action, level, title, culprit, environment, url, github_issue_url, received_at}`
- notes: helpful for "วันนี้มี error อะไรบ้าง"

---

### EDMS / Document Library

`portal/ajax_edms.php` — only CRUD (no aggregation actions). Skip for Phase 1.

---

### Asset / Consumables admin reports

`asset/admin/reports.php` and `consumables/admin/reports.php` are HTML pages with
Excel export, not JSON endpoints. For bot integration use the resolver keys
(`asset_*`, `consumable_*`) above instead.

---

## Recommended Phase 1 (safe + high-value)

**Wire these first** — all 🟢 with proven access pattern, single function call:

1. **`dashboard_resolve_data(pdo, key, filter)`** — surface 41 resolver keys as a single bot tool. Whitelist these keys:
   - All `mti_*`, `gold_*` (insurance status, expiring, breakdowns)
   - All `finance_*` (totals, by category, trend)
   - All `asset_*`, `consumable_*` (counts, low stock, warranty alerts)
   - All `scholarship_*`, `campaign_*`, `satisfaction_*`, `coverage_*`
2. **`get_clinic_current_status(pdo)`** + **`get_clinic_hours_for_date()`** — "เปิดไหม / เปิดถึงกี่โมง" parity with LINE FAQ.
3. **`portal/ajax_daily_summary.php?action=summary:get&date=`** — "สรุปวันนี้/วันที่ X" (headline + productivity + finance + stock + other).
4. **`portal/ajax_finance.php?action=analytics`** — monthly trend + period delta (use `monthly`, `categories`, `delta`; **strip `rows[]` from `list`**).
5. **`portal/ajax_nurse_productivity.php?action=analytics:cross_dept`** + `analytics:summary` — productivity-by-department questions.
6. **`portal/ajax_gold_card.php?entity=member&action=stats`** + `folder:tree` — gold card status overview.
7. **`portal/ajax_pdpa_audit.php?action=stats`** — consent coverage governance.
8. **`portal/ajax_stats.php`** — quick system pulse (users/camps/booking_rate).

## Recommended Phase 2/3

After Phase 1 ships, layer these in for richer follow-up:

- **`portal/ajax_activity_dashboard.php?action=snapshot`** (🟡 superadmin-only) — "ใครเข้าระบบบ่อยที่สุดสัปดาห์นี้" / "วันนี้ใครทำอะไรบ้าง" — wrap with role check.
- **`get_clinic_doctors_for_date()`** — doctor roster for arbitrary date (already used by LINE bot, low risk).
- **`portal/ajax_nurse_productivity.php?action=analytics:rollup_monthly/yearly`** — long-horizon productivity trends.
- **`portal/ajax_scholarship.php?entity=dashboard&action=get`** — KPIs only (`kpis`, `daily[30]`); **redact `top[]`, `today_list[]`, `recent[]` names**.
- **`portal/ajax_sentry_events.php?action=list`** (superadmin) — recent errors summary.
- **`portal/ajax_line_stats.php`** — LINE quota / delivery health.
- **`portal/ajax_kpi_override.php?action=list`** — show which KPIs are manually overridden.
- **`portal/ajax_db_schema.php?action=graph`** — for meta questions about the system itself.

## Excluded (do not expose)

These contain PHI / per-person data with no aggregate-only alternative — keep
out of the bot context to limit blast radius:

- **`portal/ajax_finance.php?action=list` → `rows[]`** — description/reference can name patients/staff. Use `summary` only.
- **`portal/ajax_gold_card.php?entity=member&action=list/get`** — full_name, citizen_id, phone, hospital_main. Use `member:stats` + `folder:tree`.
- **`portal/ajax_pdpa_audit.php?action=list/detail`** — masked citizen_id is still pseudo-PII; consent_ip + UA are tracking data.
- **`portal/ajax_insurance_batches.php?action=list/detail/members`** — member-level records.
- **`portal/ajax_scholarship.php` `top[]` / `today_list[]` / `recent[]`** — student names + faculty. Aggregate-only views are fine; individual rows are not.
- **`portal/ajax_clinic_master.php?action=staff:*` / `rooms:*`** — CRUD endpoints, not aggregation. Use catalog metadata in `dashboard_data_sources_catalog()` if needed.
- **`portal/ajax_edms.php`** — pure CRUD, document attachments may contain sensitive scans.
- **`portal/ajax_db_schema.php?action=table&name=X`** — exposes recent rows of arbitrary table; superadmin-only and not bot-safe.
- **`portal/ajax_identity_users.php`** / `ajax_privilege_inventory.php` — identity governance is mutation-only and superadmin-gated; nothing to read here that helps a bot user.

## Notes on tool wrapping

- **Single tool with key whitelist** is safer than 41 individual tools — the LLM
  can't invent a new key, and you can centrally PHI-scrub return values.
- **`year/month` filter**: pass through to `dashboard_resolve_data` so the bot
  can answer "เดือนที่แล้ว / ปีนี้" without extra plumbing.
- **Currency formatting**: resolver returns raw `int`/`float` baht — wrap with
  `number_format()` + ` บาท` before showing to user.
- **Empty results**: every resolver wraps DB errors in `_safe_scalar/_safe_rows`
  and returns `0` / `[]` — bot will never see a stack trace.
- **PHI scrub layer**: even Phase 1 endpoints can leak names in
  `daily_summary.other.schedule` (nurse names) and finance `topCategories.name`.
  Add a final pass that drops keys matching `/(_name|full_name|student_code|citizen_id|phone)$/i`
  unless explicitly whitelisted for the calling role.
