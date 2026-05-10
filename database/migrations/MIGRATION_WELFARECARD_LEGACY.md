# 📦 Migration: Welfarecard Legacy → Gold Card

คู่มือ migrate ข้อมูลจากระบบ `welfarecard` (เก่า) → `gold_card_members` (ใหม่)
จำนวนข้อมูลคาดการณ์: **5,628 สมาชิก + 9,604 audit logs + ~2,693 selfie photos**

---

## 🎯 Overview

| Source (เก่า) | Target (ใหม่) | จำนวน |
|---|---|---|
| `welfarecard` table | `gold_card_members` + `gold_card_documents` (signature) | ~5,628 rows |
| `welfarecard.signature` (base64) | `gold_card_documents` (doc_type='signature') + file | inline |
| `welfarecard_old/uploads/{pid}.jpg` | `gold_card_documents` (doc_type='photo') + file copy | ~2,693 ไฟล์ |
| `welfarelog` table | `gold_card_history` | ~9,604 rows |
| `welfareuser` ↔ `sys_users` | `gold_card_members.linked_user_id` | by citizen_id match |

---

## 📋 Pre-flight Checklist

ก่อนเริ่ม migration ให้ตรวจสอบ:

- [ ] **Backup database** ทั้งหมดก่อน (`mysqldump`)
- [ ] **Login เป็น superadmin** ที่ portal — migration scripts ตรวจสิทธิ์เข้ม
- [ ] **Import legacy SQL** เข้า database เดียวกันกับระบบใหม่:
  - `welfarecard.sql` → ตาราง `welfarecard`
  - `welfarelog.sql` → ตาราง `welfarelog`
  - `welfareuser.sql` → ตาราง `welfareuser`
- [ ] **Upload uploads folder** ไปที่: `/var/www/html/e-campaignv2/welfarecard_old/uploads/`
- [ ] ตรวจ permission: PHP เขียนได้ที่ `uploads/gold_card/legacy/`

---

## 🚀 Run Order (สำคัญมาก — ห้ามสลับ!)

### **Step 1: Schema Migration** — เพิ่ม legacy columns
```
URL: https://healthycampus.rsu.ac.th/e-campaignv2/database/migrations/migrate_gold_card_legacy_columns.php
```

เพิ่ม:
- `gold_card_members.legacy_id` (รองรับ resume)
- `gold_card_members.gender`, `date_of_birth`, `migrated_at`
- `gold_card_documents.doc_type` ENUM เพิ่ม `'signature'`

✅ **Expected output:** "✅ เพิ่ม ..." 4-5 บรรทัด · ✗ ไม่ควรมี error

---

### **Step 2: Data Migration (Dry-run)** — ทดสอบก่อน
```
URL: https://healthycampus.rsu.ac.th/e-campaignv2/database/migrations/migrate_welfarecard_legacy_data.php?dry=1
```

✅ **Expected output:**
- "✓ พบตาราง `welfarecard`" + "✓ พบตาราง `welfareuser`"
- Column mapping แสดงทุก field ที่ map ได้
- Progress log "[HH:MM:SS] batch offset=0 → processed=100..."
- สรุป: Inserted 5,628 / Errors 0 / Photos ~2,693 / Signatures ~5,000+

❌ **ถ้าเจอ error:**
- "ตาราง `welfarecard` ไม่พบ" → ยังไม่ได้ import legacy SQL
- "❌ ขาด required columns (pid + name)" → schema ของ welfarecard.sql ผิด → ดู `colMap` ใน script + ปรับ candidates
- "errors > 0" → ดู error message ใน log แล้วแก้ก่อนรันจริง

---

### **Step 3: Reset Bulk-Import เก่า** (ถ้ามี)

ถ้ามีข้อมูล bulk-import เก่า (105 rows ที่ legacy_id = NULL) ให้ลบก่อน:

```
URL: ...migrate_welfarecard_legacy_data.php?reset=1
```

⚠️ **คำเตือน:**
- ลบทุก row ที่ `legacy_id IS NULL` (รวม documents/history ที่ผูกกับมัน — CASCADE)
- ใช้แค่ครั้งเดียว
- ถ้าต้องการเก็บ bulk-import เดิมไว้ → ข้าม Step 3

---

### **Step 4: Data Migration (Live)** — รันจริง
```
URL: ...migrate_welfarecard_legacy_data.php
```

**Resumable:** ถ้า browser ขาด/error กลางทาง → รัน URL เดิมซ้ำ — script จะ skip rows ที่ migrate แล้ว (ดูจาก legacy_id)

⏱️ **Estimated time:** ~5-15 นาที (ขึ้นกับขนาดไฟล์รูป + speed disk)

---

### **Step 5: History Migration (Dry-run)**
```
URL: ...migrate_welfarelog_history.php?dry=1
```

ตรวจ:
- พบ `welfarelog` ✓
- Column mapping ✓
- "Inserted 9,604" + "no_member" ต่ำๆ (ส่วนใหญ่ควร match member ได้)

---

### **Step 6: History Migration (Live)**
```
URL: ...migrate_welfarelog_history.php
```

⏱️ **Estimated time:** ~2-5 นาที

---

## 🧪 Verification

หลัง migration เสร็จ — ตรวจ DB:

```sql
-- 1. Members count
SELECT
    COUNT(*) AS total,
    COUNT(legacy_id) AS from_legacy,
    COUNT(linked_user_id) AS matched_users
FROM gold_card_members;
-- Expected: total ≈ 5,628 + bulk-import เดิม (ถ้าไม่ reset)

-- 2. Documents count
SELECT doc_type, COUNT(*) FROM gold_card_documents GROUP BY doc_type;
-- Expected: signature ~5,000, photo ~2,693

-- 3. History count
SELECT COUNT(*) FROM gold_card_history WHERE action LIKE 'legacy:%';
-- Expected: ~9,604

-- 4. Status distribution
SELECT status, COUNT(*) FROM gold_card_members WHERE legacy_id IS NOT NULL GROUP BY status;

-- 5. Sample check
SELECT id, legacy_id, citizen_id, full_name, gender, date_of_birth, status, application_date
FROM gold_card_members WHERE legacy_id IS NOT NULL ORDER BY legacy_id LIMIT 10;
```

ทดสอบ UI:
- เปิด portal → Insurance Hub → Gold Card → ค้นหาด้วย citizen_id ของผู้ใช้เก่า
- คลิก member → ตรวจว่ามี signature + photo แสดง

---

## 🗑️ Cleanup (สำคัญ — ทำหลัง verify เรียบร้อย)

```bash
# 1. ลบ migration scripts (security — ป้องกันรันซ้ำโดยไม่ตั้งใจ)
cd /var/www/html/e-campaignv2/database/migrations
rm migrate_gold_card_legacy_columns.php
rm migrate_welfarecard_legacy_data.php
rm migrate_welfarelog_history.php
rm MIGRATION_WELFARECARD_LEGACY.md

# 2. ลบ legacy uploads folder (ใช้พื้นที่เปล่าๆ)
rm -rf /var/www/html/e-campaignv2/welfarecard_old/

# 3. ลบ legacy tables (optional — เก็บไว้สำรอง 1-3 เดือนแล้วค่อยลบ)
mysql> DROP TABLE welfarecard;
mysql> DROP TABLE welfarelog;
mysql> DROP TABLE welfareuser;
```

---

## 🔧 Troubleshooting

### ❌ "ขาด required columns (pid + name)"
- เปิด `migrate_welfarecard_legacy_data.php` แก้ `colMap['pid']` หรือ `colMap['name']`
- เพิ่มชื่อคอลัมน์จริงใน array `pick_col($wcCols, [...])`

### ❌ "Photo not found" จำนวนมาก
- ตรวจ path: `ls /var/www/html/e-campaignv2/welfarecard_old/uploads/ | head`
- ตรวจ permission: `ls -la /var/www/html/e-campaignv2/welfarecard_old/uploads/`
- ไฟล์ใน old system อาจมี extension อื่น (`.JPG`, `.png`) — script ลอง 4 รูปแบบแล้ว

### ❌ Browser timeout / 504 Gateway
- Migration ทำงานต่อ background ได้ (`ignore_user_abort(true)`)
- รอ 5-10 นาที แล้ว refresh — ดูสถานะใน DB:
  ```sql
  SELECT COUNT(*) FROM gold_card_members WHERE legacy_id IS NOT NULL;
  ```
- ถ้ายังไม่ครบ → รัน URL เดิมซ้ำ (resume จาก row ที่ค้าง)

### ❌ Memory exhausted (signature ใหญ่มาก)
- เพิ่ม `ini_set('memory_limit', '1G')` ที่ต้น script
- หรือ process ทีละน้อยลง: เปลี่ยน `$BATCH_SIZE = 100` → `50`

### ❌ Many "no_member" in welfarelog migration
- หมายถึง `welfarelog.pid` ไม่ตรงกับ member ใหม่
- เช็คว่า welfarecard rows ครบใน gold_card_members ก่อน
- หรือ pid ของบาง logs เป็น NULL/blank (ปกติได้)

---

## 📞 Rollback (ถ้าต้องยกเลิก)

```sql
-- ลบ migrated data ทั้งหมด (เก็บ bulk-import เดิมไว้)
DELETE FROM gold_card_members WHERE legacy_id IS NOT NULL;
-- documents + history จะ CASCADE DELETE อัตโนมัติ

-- หรือลบเฉพาะ history ที่มาจาก legacy
DELETE FROM gold_card_history WHERE action LIKE 'legacy:%';
```

ลบไฟล์ที่ copy ไปแล้ว:
```bash
rm -rf /var/www/html/e-campaignv2/uploads/gold_card/legacy/
```

---

## 📝 Field Mapping Reference

### `welfarecard` → `gold_card_members`

| welfarecard | gold_card_members | Note |
|---|---|---|
| `id` | `legacy_id` | resume support |
| `pid` | `citizen_id` | 13 digits |
| `name` | `full_name` |  |
| `gender` (ชาย/หญิง) | `gender` (male/female) | mapped |
| `dob` | `date_of_birth` |  |
| `phone` | `phone` |  |
| `hospital` | `hospital_main` |  |
| `sub_hospital` | `hospital_sub` |  |
| `signature` (base64) | → `gold_card_documents` doc_type=signature | extracted as PNG |
| `status` (Thai) | `status` (ENUM) | mapped |
| `submitdate` | `application_date` + `created_at` |  |
| `remarks` | `remarks` |  |
| (welfareuser join) | `linked_user_id` | by citizen_id match |

### Status mapping (Thai → ENUM)

| Old (Thai) | New (ENUM) |
|---|---|
| อนุมัติ / อนุมัติแล้ว / ใช้งาน | `active` |
| ไม่อนุมัติ / ปฏิเสธ | `rejected` |
| รอส่ง / รอตัดสินใจ | `submitted` |
| หมดอายุ | `expired` |
| รอดำเนินการ / (default) | `pending` |

### `welfarelog` → `gold_card_history`

| welfarelog | gold_card_history | Note |
|---|---|---|
| `id` | (in `old_value` as `log_id=N`) | for resume |
| `pid` | → resolve `member_id` | join via citizen_id |
| `action` | `action` | prefixed `legacy:` |
| `oldvalue` | `old_value` | with metadata header |
| `newvalue` | `new_value` |  |
| `uid` | `changed_by` | admin_id (or NULL) |
| `logdate` | `changed_at` |  |
| `ip` | `ip_address` |  |
