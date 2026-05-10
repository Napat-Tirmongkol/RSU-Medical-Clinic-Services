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

### `welfarecard` → `gold_card_members` (confirmed schema)

ระบบเก่ามี **8 columns**:
```sql
CREATE TABLE `welfarecard` (
  `id` int NOT NULL,                  -- → legacy_id
  `pid` varchar(13) NOT NULL,         -- → citizen_id
  `username` varchar(50) NOT NULL,    -- → full_name (⚠ ชื่อ field คือ username)
  `gender` varchar(10) NOT NULL,      -- → gender (ชาย/หญิง → male/female)
  `birth` date NOT NULL,              -- → date_of_birth (⚠ ชื่อ field คือ birth)
  `signature` longtext,               -- → gold_card_documents (extract as PNG)
  `registrar` varchar(50),            -- → remarks ("ผู้ลงทะเบียน (ระบบเก่า): ...")
  `status` varchar(50),               -- → status ENUM (mapped from Thai)
  `submitdate` timestamp,             -- → application_date + created_at
);
```

| welfarecard | gold_card_members | Note |
|---|---|---|
| `id` | `legacy_id` | resume support |
| `pid` | `citizen_id` | 13 digits |
| `username` | `full_name` | ⚠️ ใช้ชื่อ `username` ใน welfarecard |
| `gender` (ชาย/หญิง) | `gender` (male/female) | mapped via `map_gender()` |
| `birth` | `date_of_birth` | ⚠️ ใช้ชื่อ `birth` ใน welfarecard |
| `signature` (base64 longtext) | → `gold_card_documents` doc_type='signature' | extracted as PNG file |
| `registrar` | → `remarks` (concat) | "ผู้ลงทะเบียน (ระบบเก่า): {name}" |
| `status` (Thai) | `status` (ENUM) | mapped via `map_status()` |
| `submitdate` | `application_date` + `created_at` |  |
| (welfareuser join) | `linked_user_id` | by citizen_id ↔ sys_users match |
| **uploads/{pid}.jpg** | → `gold_card_documents` doc_type='photo' | copy to `uploads/gold_card/legacy/{y}/{m}/` |

### ⚠️ ฟิลด์ที่ระบบเก่า "ไม่มี" — จะเป็นค่าว่างใน gold_card_members

| Field ในระบบใหม่ | Default | ที่มา / ผู้ใช้ต้องกรอกตอน |
|---|---|---|
| `phone` | จาก `welfareuser.mobile` (auto-fill) | กรณีไม่มีใน welfareuser → renewal |
| `hospital_main` | `''` | renewal |
| `hospital_sub` | `''` | renewal |
| `member_type` | `'บุคคลทั่วไป'` | renewal |
| `position` | `''` | renewal |
| `address` | NULL | renewal |

**แนะนำ:** สร้าง dashboard widget แสดง "Member ที่ข้อมูลไม่ครบ" + ปุ่ม Bulk SMS ขอให้ผู้ใช้มาเติมข้อมูล

### Status mapping (Thai → ENUM)

| Old (Thai) | New (ENUM) |
|---|---|
| อนุมัติ / อนุมัติแล้ว / ใช้งาน | `active` |
| ไม่อนุมัติ / ปฏิเสธ | `rejected` |
| รอส่ง / รอตัดสินใจ | `submitted` |
| หมดอายุ | `expired` |
| รอดำเนินการ / (default) | `pending` |

### `welfarelog` → `gold_card_history` (confirmed schema)

ระบบเก่า welfarelog มี **5 columns**:
```sql
CREATE TABLE `welfarelog` (
  `id` int AUTO_INCREMENT,         -- → metadata (log_id=N)
  `uid` varchar(10) NOT NULL,      -- → metadata (legacy_uid=N) — varchar ไม่ใช่ int
  `activity` varchar(50) NOT NULL, -- → action (prefixed 'legacy:')
  `pid` varchar(50) NOT NULL,      -- → resolve member_id
  `dateTime` timestamp,            -- → changed_at (camelCase)
);
```

| welfarelog | gold_card_history | Note |
|---|---|---|
| `id` | → metadata in `old_value` (`log_id=N`) | for resume detection |
| `uid` (varchar) | → metadata in `old_value` (`legacy_uid=N`) | varchar — ไม่ map เข้า changed_by ตรงๆ |
| `activity` | `action` (prefix `legacy:`) | ⚠️ ใช้ชื่อ `activity` ไม่ใช่ `action` |
| `pid` | resolve → `member_id` | JOIN via gold_card_members.citizen_id |
| `dateTime` | `changed_at` | ⚠️ camelCase |
| (ไม่มี oldvalue/newvalue/ip) | `old_value`=NULL, `new_value`=NULL, `ip_address`=NULL |  |

### `welfareuser` → enhances `gold_card_members` (confirmed schema)

ระบบเก่า welfareuser มี **7 columns** (ใช้เพื่อ enrich + match user เท่านั้น):
```sql
CREATE TABLE `welfareuser` (
  `id` int AUTO_INCREMENT,
  `lineid` varchar(50) NOT NULL,    -- ⭐ ใช้ match กับ sys_users.line_user_id
  `pid` varchar(13) NOT NULL,       -- ⭐ JOIN ไป welfarecard.pid
  `uid` varchar(10) NOT NULL,       -- (ไม่ใช้)
  `username` varchar(50) NOT NULL,  -- (ไม่ใช้ — ดึง name จาก welfarecard แทน)
  `mobile` varchar(20),             -- ⭐ → gold_card_members.phone
  `role` varchar(20),               -- (ไม่ใช้)
);
```

**Logic ใน script:**
1. Pre-load welfareuser ทั้งหมดเข้า PHP array (indexed by pid, latest entry by id DESC)
2. ขณะ migrate welfarecard แต่ละ row:
   - Lookup welfareuser by pid
   - ถ้ามี `mobile` → set `gold_card_members.phone`
   - ถ้ามี `lineid` → match `sys_users.line_user_id` (priority over citizen_id match)
3. ถ้า lineid match ไม่ได้ → fallback citizen_id match
