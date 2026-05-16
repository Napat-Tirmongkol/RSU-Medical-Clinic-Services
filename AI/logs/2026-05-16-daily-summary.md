# Daily Summary Module — 2026-05-16

## Context
หลังจากผมอธิบายว่า "คลินิกสรุปงานประจำวันจะดูอะไรบ้าง" user ขอให้สร้างหน้า dashboard
ที่ดึงตัวเลขจาก module ที่มีอยู่แล้ว — มาแสดงรวมในหน้าเดียวสำหรับใช้ทบทวน + พิมพ์ส่งหัวหน้า

## Decisions
1. **Read-only aggregator** — ไม่มี CRUD, ไม่มี table ใหม่ (audit ใช้ของ source modules)
2. **Data sources**: 4 กลุ่ม (ตามที่ user เลือกทั้งหมด)
   - Productivity พยาบาล — `sys_nurse_productivity_daily` + settings (คำนวณ inline)
   - Cash Book — `sys_finance_transactions` filter by `txn_date`
   - Stock — `consumable_transactions` (in/out per day) + `consumables` (low-stock alerts)
   - Other — gold_card_history, insurance_batch, asset_movements, sys_doc_documents, nurse_schedule
3. **Sidebar**: "รายงาน" group (ก่อน Productivity พยาบาล + รายงานประจำเดือน)
4. **Access flag**: `access_daily_summary` ใหม่ (7-spot ครบ)
5. **Period delta**: เทียบกับเมื่อวาน (visits + income + expense)
6. **Print**: A4 standalone (`daily_summary_print.php`)

## Files

### สร้างใหม่ (4)
- `portal/ajax_daily_summary.php` — single `summary:get` action รวม 4 กลุ่มใน 1 response
- `portal/_partials/daily_summary.php` — UI dashboard
- `portal/daily_summary_print.php` — A4 print view (replicate queries; ไม่ดึงผ่าน AJAX)
- `AI/logs/2026-05-16-daily-summary.md` — log นี้

### Modified (6)
- `portal/index.php` — sidebar (เพิ่ม "สรุปงานประจำวัน" ใต้กลุ่มรายงาน, ก่อน Productivity) + section gate + section map + identity modal checkbox/load/reset/save + access label + position whitelist
- `portal/queries/identity_queries.php` — auto-migrate + SELECT
- `admin/auth/staff_login.php` — SELECT + session + no-access check + position whitelist
- `portal/actions/identity_actions.php` — POST parse + auto-migrate + INSERT/UPDATE + position whitelist
- `portal/_partials/profile.php` — SELECT + accessLabels
- `hot.md` — เพิ่ม phase entry

## AJAX response shape
```json
{
  "ok": true,
  "headline": { "date", "dateBE", "dayName", "isWeekend", "isToday", "totals" },
  "productivity": { "totalVisits", "avgProd", "deptCount", "list[], prevVisits, visitsDelta" },
  "finance": { "income", "expense", "net", "txnCount", "topCategories[], incomeDelta, expenseDelta" },
  "stock": { "qtyIn", "qtyOut", "itemsTouched", "txnCount", "topIssued[]", "lowStock[]" },
  "other": { "goldCard", "insurance", "assetEvents", "docs:{in,out}", "schedule[]" }
}
```

## Resilience pattern
ทุก query ของ "Other" section wrap ด้วย `try/catch (Throwable)` — ถ้า table ไม่มีใน install นั้น
(เช่น insurance_batch ยังไม่ได้ migrate, asset module ไม่ติด) ก็ silent fallback เป็น 0
ไม่ทำให้ทั้ง endpoint พัง — เหมาะกับเป็น aggregator ที่ใช้ในระบบหลายขนาด

## Schedule integration
ใช้ pattern เดียวกับ `ajax_nurse_productivity.php::np_derive_from_schedule()`:
- parse `sys_nurse_schedule_monthly` (year_be, month) + `sys_nurse_schedule_global.nurses_json`
- คนที่ shift_code != 'O' = ทำงานวันนั้น
- แสดง name + position + shift label (เช้า/บ่าย/ดึก) เรียงตาม order ของ nurses_json

## Consequences
- Migration ใหม่: ไม่จำเป็น (access flag auto-migrate ผ่าน identity_queries + identity_actions เหมือนเดิม)
- ครั้งแรกที่ super admin เปิด Identity & Governance → column `access_daily_summary` ถูกเพิ่มอัตโนมัติ
- Print view ไม่ผ่าน AJAX — replicate query เพื่อให้พิมพ์ได้ทันทีไม่ต้องโหลด JS
- Period delta คำนวณจาก raw query (ไม่ cache) — เร็วพอเพราะมี index บน txn_date / entry_date

## ของที่ยังไม่ทำ (intentional)
- Export Excel (มี print A4 พอ — user สามารถ export จาก module เดิมแต่ละตัวได้)
- ย้อนหลังหลายวัน comparison (มีแค่ vs เมื่อวาน — ใช้ Cash Book/Productivity rollup ดูยาวๆ)
- Sign-off / approval workflow (read-only dashboard — ไม่ใช่เอกสารทางการ)
- Top 5 อันดับโรค (ไม่มี ICD-10 / diagnosis ใน schema ปัจจุบัน)
- Incident / complaint log (ยังไม่มี table)
