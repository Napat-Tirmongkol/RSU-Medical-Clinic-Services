# Nurse Productivity Module — 2026-05-16

## Context
ต้องการระบบคำนวณ Productivity พยาบาล OPD ตามมาตรฐานสภาการพยาบาล (HPV 0.24 ชม. × visits / available staff hours)
อ้างอิงจาก mock HTML standalone ของ ดร.ณัฐวุฒิ สุริยะ (วิทยาลัยพยาบาลศรีมหาสารคาม)
ต้องการ port เข้า Portal เป็นโมดูลถาวรพร้อม integrate กับระบบที่มีอยู่

## Decisions
1. **Multi-tenant per `sys_departments.id`** — แต่ละหน่วยงาน/แผนกบันทึกแยก ใช้ FK ที่มีอยู่แล้ว
2. **Sidebar**: ใต้กลุ่ม "รายงาน" ต่อจาก "รายงานประจำเดือน"
3. **Excel I/O**: server-side PhpSpreadsheet (composer dep อยู่แล้ว) — รองรับ .xlsx, accept .xls/.csv
4. **Access flag**: สร้างใหม่ `access_nurse_productivity` ตาม 7-spot checklist
5. **Schedule integration**: singleton — `sys_nurse_schedule_global.nurses_json` + `sys_nurse_schedule_monthly.schedule_json` ใช้ร่วมทุก dept (จะ extend เป็น per-dept ทีหลังถ้าจำเป็น)
6. **Auto-fill**: ถ้า rn/head ว่างตอน save → derive อัตโนมัติ + stamp `rn_source='schedule'` / `head_source='schedule'`
7. **Roll-up**: รายเดือน 12 เดือน + รายปี + period delta vs ย้อนหลัง + cross-dept comparison — ครบทั้ง 4

## Position → role mapping (schedule → productivity)
| Position ใน nurse_schedule | นับใน productivity |
|---|---|
| `พยาบาลวิชาชีพ` | `rn_count` |
| `หัวหน้าหอผู้ป่วย` + `รองหัวหน้าหอผู้ป่วย` + `พยาบาลหัวหน้าเวร` | `head_count` |

Shift code `O`/empty = off · อื่นๆ = ทำงาน

## Files

### สร้างใหม่ (9)
- `database/migrations/migrate_nurse_productivity.php` — 3 tables + access flag
- `portal/ajax_nurse_productivity.php` — AJAX endpoint
- `portal/_partials/nurse_productivity.php` — UI 3-tab
- `portal/nurse_productivity_export.php` — Excel export
- `portal/nurse_productivity_template.php` — Excel template
- `portal/nurse_productivity_import.php` — Excel import
- `portal/nurse_productivity_print.php` — A4 print
- `AI/logs/2026-05-16-nurse-productivity.md` — ไฟล์นี้

### Modified (5)
- `portal/index.php` — sidebar menu + section gate + section map + identity modal (checkbox/load/reset/save flag) + access label
- `portal/queries/identity_queries.php` — auto-migrate column + SELECT
- `admin/auth/staff_login.php` — SELECT + session set + no-access check + position flags whitelist
- `portal/actions/identity_actions.php` — parse POST + auto-migrate + INSERT/UPDATE + position flags whitelist
- `portal/_partials/profile.php` — SELECT + `$accessLabels`
- `hot.md` — Phase update + decision

## Schema
```sql
sys_nurse_productivity_settings  -- PK dept_id, FK sys_departments
  hospital_name, level ENUM, beds, province, director, moph_code, period_label,
  hpv DECIMAL(4,2), shift_hours, threshold_low, threshold_high

sys_nurse_productivity_daily
  id, dept_id FK, entry_date, patients, rn_count, head_count, shift_hours, note,
  rn_source ENUM('manual','schedule'), head_source ENUM('manual','schedule'),
  UNIQUE(dept_id, entry_date)

sys_nurse_productivity_audit  -- append-only
  dept_id, action, target_id, changes_json, performed_by_name, ip_addr
```

## AJAX actions
- `depts:list`, `settings:get/save`, `daily:list/create/update/delete/bulk_delete/delete_all`
- `analytics:summary` (daily + period delta), `analytics:rollup_monthly` (12mo), `analytics:rollup_yearly`, `analytics:cross_dept`
- `schedule:derive` (preview helper)

## Consequences
- Migration ต้องรันก่อนใช้งาน — `/database/migrations/migrate_nurse_productivity.php` ผ่าน browser
- Default depts seeded จาก migrate_monthly_report_module.php มี 2 หน่วยงาน — เพิ่มใหม่ผ่าน clinic_data partial
- Settings สร้างอัตโนมัติเมื่อ user เข้า dept แรก (lazy ensure)
- เมื่อ user ลบ row + กรอกใหม่ → rn_source rest "manual" จนกว่าจะกรอก '' แล้ว save อีกครั้ง

## ของที่ยังไม่ทำ (intentional)
- Backup/restore JSON (ใช้ Excel export พอ)
- Cross-dept view สำหรับ monthly/yearly view (มีแค่ daily) — เพิ่มได้ในเฟสถัดไป
- Schedule per-dept (ตอนนี้ singleton)
- Charts integration ใน print view (มีแค่ตาราง)
