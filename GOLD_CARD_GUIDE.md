# คู่มือการใช้งานระบบ "บัตรทอง" (Gold Card / UC Coverage)

ระบบจัดการสิทธิ์รักษาพยาบาลถ้วนหน้า (Universal Health Coverage) สำหรับบุคลากร นักศึกษา และบุคคลทั่วไป — รวมการสมัครผ่าน LIFF, รีวิวเอกสาร, อนุมัติสิทธิ์ 1 ปี, bulk import จาก PDF อนุมัติ, และส่งข้อความผ่าน LINE

> **Audience:** ผู้ใช้งาน 2 ฝั่ง — **Admin** (เจ้าหน้าที่คลินิก) และ **Applicant** (ผู้สมัครผ่าน LINE)

---

## 1. ภาพรวมการทำงาน

```
┌────────────────────────────┐         ┌──────────────────────────┐
│  Applicant (สมัครผ่าน LINE)│         │  Admin (เจ้าหน้าที่)      │
├────────────────────────────┤         ├──────────────────────────┤
│ ① กรอกข้อมูลใน LIFF        │ ──────► │ ② ตรวจรูป + ลายเซ็น        │
│   (เลข ปชช./ชื่อ/รูป/เซ็น) │         │   ในแท็บ "รอตรวจสอบ"      │
│                            │         │                          │
│                            │         │ ③ อัพโหลด PDF อนุมัติ      │
│                            │         │   (จากสปสช./รพ.)         │
│                            │         │                          │
│                            │ ◄────── │ ④ อนุมัติ → status active │
│                            │         │   (auto-set coverage 1ปี) │
│                            │         │                          │
│ ⑤ แจ้งผลผ่าน LINE          │ ◄────── │ ⑤ ส่งข้อความ LINE         │
└────────────────────────────┘         └──────────────────────────┘

                  ทางเลือก: Admin upload CSV/PDF แบบ bulk import
                  → OCR แยกชื่อ + เลข ปชช. → match กับ sys_users
```

**3 กลุ่มผู้ใช้สิทธิ์:**
- 👨‍⚕️ **บุคลากร** — เจ้าหน้าที่ในมหาวิทยาลัย
- 🎓 **นักศึกษา** — นักศึกษา RSU
- 🧑 **บุคคลทั่วไป** — คนนอก

---

## 2. คู่มือสำหรับ Admin

### 2.1 การเข้าใช้งาน
- Portal → sidebar **"ประกันสุขภาพ" → "Insurance Hub"** (หรือเข้าตรง `?section=gold_card`)
- ต้องมีสิทธิ์ `access_insurance` หรือ role `superadmin`

### 2.2 Dashboard KPIs (อยู่ที่หัวหน้า)

| KPI | ความหมาย |
|---|---|
| `gold_total` | สมาชิกทั้งหมด (ทุกสถานะ) |
| `gold_approved` | อนุมัติแล้ว (status=approved/active) |
| `gold_auto_matched` | จับคู่กับ `sys_users` สำเร็จ |
| `gold_pending_docs` | รอเอกสารอนุมัติ (status=submitted/processing) |
| `gold_rejected` | ถูกปฏิเสธ |
| `gold_expiring_30d` | สิทธิ์จะหมดอายุภายใน 30 วัน |

> ทุก KPI สามารถ override ค่าด้วยมือได้ (กดที่ตัวเลข) — ใช้ตอนต้องรายงานตามเอกสาร

### 2.3 แท็บหลัก — รายการสมาชิก

**Search/Filter:**
- ค้นหา: ชื่อ / เลข ปชช. (13 หลัก)
- กรอง: type (นักศึกษา/บุคลากร/ทั่วไป) · สถานะ · โรงพยาบาลหลัก · ปี/เดือนยื่นเอกสาร
- Pagination 20/หน้า

**กดที่ row → เปิด Member Detail Modal:**
- ข้อมูลพื้นฐาน (citizen_id, ชื่อ, เพศ, วันเกิด, เบอร์, ประเภท, ตำแหน่ง)
- ระยะเวลาสิทธิ์ (coverage_start ↔ coverage_end)
- โรงพยาบาลหลัก/รอง · หมายเหตุ
- เปลี่ยน status ได้
- ✅ **กด "อนุมัติ" → coverage_start = วันนี้, coverage_end = +1 ปี อัตโนมัติ**
- 🔗 **Link/Unlink user** — จับคู่กับบัญชี LINE ใน `sys_users.line_user_id`
- 📎 **เอกสารแนบ** — รายการ + กดดู/ดาวน์โหลด/ลบ
- 📋 **ประวัติแก้ไข** — log ทุก action

### 2.4 แท็บ "รอตรวจสอบ" (Pending)

แสดงคำขอที่ user เพิ่งส่งมาจาก LINE (status = `submitted`):
- เห็นรูปถ่าย + ลายเซ็นทันที
- กด "อนุมัติ" / "ปฏิเสธ" พร้อมเหตุผล
- กรองตามวันที่ + ค้นหา

> ⚠️ **กฎ:** จะอนุมัติ (set status → `approved` / `active`) ไม่ได้ ถ้ายังไม่มีเอกสารชนิด **`approval`** (PDF อนุมัติจาก สปสช./รพ.)

### 2.5 แท็บ Bulk Import — สร้างหลายคนทีเดียวจาก CSV/PDF

**สำหรับกรณี:** สปสช./รพ. ส่งรายการอนุมัติมาเป็น batch (PDF/CSV)

**Step 1 — Upload + Scan:**
- อัพโหลด PDF อนุมัติ (หลายไฟล์)
- กดปุ่ม "Scan OCR" → ระบบใช้ **Tesseract** (on-premise) แยก:
  - เลข ปชช. 13 หลัก
  - ชื่อ-นามสกุล
  - วันที่ในเอกสาร (จาก PDF metadata)
- ทำงานในเครื่องเอง — **ไม่ส่งข้อมูลออกข้างนอก (PDPA-safe)**

**Step 2 — Link User:**
- ระบบ fuzzy-match ชื่อกับ `sys_users` ที่มีอยู่
- จัดกลุ่ม: exact match · substring · ambiguous · no-match
- Admin ปรับ mapping ได้

**Step 3 — Commit:**
- กดยืนยัน → INSERT ทุก row ลง `gold_card_members` พร้อม `source_filename` และ `linked_user_id`
- Default status = `pending` (admin ตามรีวิวแต่ละราย/อัพโหลด approval PDF ทีหลังได้)

**Requirements (server):** `tesseract` + `tesseract-ocr-tha` + `pdftoppm`

### 2.6 การส่งข้อความผ่าน LINE

ในหน้า Member Detail Modal:
- ปุ่ม **"📩 ส่ง LINE"** (ต้องมี `linked_user_id` ก่อน)
- มี **5 template สำเร็จรูป** ให้เลือก:
  - ขอรูปถ่ายเพิ่ม
  - ขอลายเซ็นเพิ่ม
  - เอกสารไม่ครบ
  - โทรกลับ
  - ทั่วไป (เขียนเอง)
- ขีดจำกัด 4,000 ตัวอักษร
- ใช้ LINE Messaging API ผ่าน `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` (ตั้งใน `config/secrets.php`)
- ข้อความ prepend: "📋 แจ้งจากระบบบัตรทอง" + ลงท้ายด้วย footer คลินิก
- รองรับ `line_user_id` ทั้ง 2 channel (เก่า/ใหม่)

### 2.7 ดาวน์โหลดเป็น ZIP รายเดือน

ปุ่ม **"📦 Download ZIP"** ในหน้าหลัก:
- เลือก ปี + เดือน
- ระบบ pack เอกสารทั้งหมดของสมาชิกที่มี `application_date` ในเดือนนั้น
- ใช้สำหรับรายงาน/backup

---

## 3. คู่มือสำหรับ Applicant (สมัครผ่าน LINE)

### 3.1 เข้าฟอร์มสมัคร
- เปิดผ่าน **LINE → เมนู "สมัครบัตรทอง"** หรือ scan QR
- URL: `user/gold_card_apply.php`
- ระบบเช็คเฉพาะ:
  - ต้อง login ผ่าน LINE
  - ห้ามมีคำขอ `pending` / `submitted` / `approved` / `active` ค้างอยู่

### 3.2 ฟิลด์ในฟอร์ม

| ฟิลด์ | บังคับ | ตรวจสอบ |
|---|---|---|
| **เลขประจำตัวประชาชน** | ✓ | 13 หลัก + Mod-11 checksum |
| **ชื่อ-นามสกุล** | ✓ | (auto-fill จาก sys_users) |
| **เพศ** | ✓ | male / female / other |
| **วันเกิด** | ✓ | YYYY-MM-DD |
| **เบอร์โทรศัพท์** | ✗ | 10 หลัก |
| **รูปถ่ายบัตรประชาชน** | ✓ | JPG/PNG/WEBP · max 10 MB · auto-compress 1200px |
| **ลายเซ็นดิจิทัล** | ✓ | วาดบน canvas → save เป็น PNG |
| **ยินยอมให้ใช้ข้อมูล** | ✓ | checkbox PDPA consent |

### 3.3 การอัพโหลดรูป
- กดที่กล่อง drag-and-drop หรือลากไฟล์มาวาง
- ระบบบีบขนาดอัตโนมัติ (max 1200px wide) เพื่อประหยัด bandwidth
- เก็บไว้ที่: `uploads/gold_card/{ปี}/{เดือน}/photo_mem{ID}_{hash}.{ext}`

### 3.4 การเซ็นชื่อ
- ใช้ปลายนิ้ว/stylus วาดบน canvas
- กด "ล้าง" เพื่อเริ่มใหม่
- เก็บเป็น base64 PNG → save ไฟล์ที่ `sig_mem{ID}_{hash}.png`

### 3.5 หลัง Submit
- ขึ้น confirmation: "ส่งคำขอเรียบร้อย — รอเจ้าหน้าที่ตรวจสอบ"
- Status เปลี่ยนเป็น **`submitted`**
- ระบบบันทึก history: action = `'user_apply'`
- จะเช็คสถานะได้ที่ไหนยังไง: admin จะส่ง LINE แจ้งเมื่ออนุมัติ (หรือขอเอกสารเพิ่ม)

---

## 4. Status State Machine

```
   ┌─────────┐    user submit    ┌───────────┐
   │ pending ├──────────────────►│ submitted │
   └─────────┘                   └─────┬─────┘
        ▲                              │ admin เริ่มดำเนินการ
        │ admin สร้างเอง                ▼
        │                       ┌────────────┐
        │                       │ processing │
        │                       └─────┬──────┘
        │                             │ admin upload "approval" PDF + กดอนุมัติ
        │                             ▼
        │                       ┌──────────┐ auto-set
        │                       │ approved ├─────────► coverage_end = +1ปี
        │                       └─────┬────┘
        │                             │
        │                             ▼
        │  reject any time      ┌────────┐    coverage_end < today    ┌─────────┐
        └──────────────────────►│ active │ ──────────────────────────►│ expired │
                                └────────┘                            └─────────┘
                       reject │
                              ▼
                        ┌───────────┐
                        │ rejected  │ (มี remarks)
                        └───────────┘
```

**Guard rule:** จะ transition ไปที่ `approved` / `active` ได้ก็ต่อเมื่อมี document ชนิด `doc_type='approval'` อย่างน้อย 1 ไฟล์

---

## 5. ประเภทเอกสาร (`gold_card_documents.doc_type`)

| Type | ใช้ตอนไหน |
|---|---|
| `photo` | รูปถ่ายตอนสมัคร (จาก LIFF) |
| `signature` | ลายเซ็นดิจิทัล (จาก LIFF) |
| **`approval`** | **PDF อนุมัติจาก สปสช./รพ. — ขาดไม่ได้ก่อนเปลี่ยนเป็น approved** |
| `id_copy` | สำเนาบัตรประชาชน |
| `house_reg` | สำเนาทะเบียนบ้าน |
| `application` | ใบสมัคร |
| `medical` | เวชระเบียน |
| `other` | อื่นๆ |

ที่เก็บไฟล์: `uploads/gold_card/{ปี}/{เดือน}/{type}_mem{ID}_{sha1[0..16]}.{ext}`

---

## 6. FAQ / Troubleshooting

| ปัญหา | สาเหตุ / วิธีแก้ |
|---|---|
| User: กดสมัครแล้วขึ้น "คุณมีคำขอค้างอยู่" | มีคำขอสถานะ pending/submitted/approved/active อยู่ — รอเจ้าหน้าที่หรือติดต่อขอ reject ก่อน |
| Admin: กดอนุมัติแล้วขึ้น "ต้องมี PDF อนุมัติก่อน" | ต้องอัพโหลดเอกสาร doc_type=`approval` ก่อน → ค่อยกดเปลี่ยนสถานะ |
| Admin: ส่ง LINE ขึ้น "ไม่ได้ link user" | กด "🔗 Link user" ในหน้า detail เพื่อจับคู่กับ sys_users.id ก่อน |
| Bulk OCR ขึ้น error "tesseract not found" | server ขาด tesseract — รัน `apt install tesseract-ocr tesseract-ocr-tha poppler-utils` |
| รูปอัพโหลดไม่ขึ้น | เกิน 10MB หรือไม่ใช่ JPG/PNG/WEBP — ลด resolution ก่อน |
| Citizen ID ผิด แต่กรอกถูกแล้ว | ตรวจ Mod-11 checksum (ตัวสุดท้าย) — บางครั้ง 13 หลักผิดจริง |
| Coverage end date เพี้ยน | กดอนุมัติใหม่ → auto-set coverage_end = today + 1 year (override ค่าที่ผิดได้) |
| ปิดระบบสมัครชั่วคราว | แก้ `config/maintenance.json` → `gold_card_apply = false` (โดย Settings → Maintenance) |

---

## 7. โครงสร้างฐานข้อมูล (อ้างอิงเร็ว)

| ตาราง | บทบาท |
|---|---|
| `gold_card_members` | ข้อมูลสมาชิกหลัก (citizen_id unique, linked_user_id FK) |
| `gold_card_documents` | ไฟล์แนบ (ENUM doc_type) — ต้องมี `approval` ก่อนเปลี่ยน approved |
| `gold_card_history` | audit log ของทุก action (status_changed, message_sent, ฯลฯ) |

**Status enum:** `pending`, `submitted`, `processing`, `approved`, `active`, `rejected`, `expired`

---

## 8. ไฟล์โค้ดที่เกี่ยวข้อง

| ไฟล์ | บทบาท |
|---|---|
| `portal/_partials/gold_card.php` | UI admin หลัก — Dashboard + รายการสมาชิก + Bulk Import |
| `portal/_partials/gold_card_pending.php` | คิวรอตรวจสอบ (status=submitted) |
| `portal/ajax_gold_card.php` | AJAX dispatcher (entity:action — member/document/bulk/folder) |
| `user/gold_card_apply.php` | ฟอร์มสมัครฝั่ง user (LIFF) — รูป + เซ็น |
| `user/ajax_gold_card_apply.php` | รับ submit + validate Mod-11 + save ไฟล์ |
| `database/migrations/migrate_gold_card_module.php` | สร้างตารางหลัก |
| `database/migrations/migrate_gold_card_bulk_import.php` | เพิ่ม linked_user_id + source_filename |
| `database/migrations/migrate_gold_card_processing_status.php` | เพิ่ม `processing` status + `approval` doc_type |
| `uploads/gold_card/{YYYY}/{MM}/` | folder เก็บไฟล์ (gitignored) |

---

## 9. Integration Points

- **LINE Messaging API** — ส่งข้อความถึงผู้สมัครหลังอนุมัติ/ขอเอกสารเพิ่ม
- **Tesseract OCR (on-premise)** — bulk import อ่าน PDF อนุมัติ (PDPA-safe ไม่ส่งข้อมูลออกข้างนอก)
- **PDPA consent** — บังคับ checkbox ตอนสมัคร + log ใน history
- **Maintenance flag** — `config/maintenance.json.gold_card_apply` ปิดระบบสมัครชั่วคราว

---

_เอกสารนี้สร้างเมื่อ 11 พ.ค. 2569 · ดูประวัติการเปลี่ยนแปลงผ่าน `git log GOLD_CARD_GUIDE.md` หากพบจุดที่ระบบทำงานไม่ตรงตามนี้ แจ้งทีมพัฒนา_
