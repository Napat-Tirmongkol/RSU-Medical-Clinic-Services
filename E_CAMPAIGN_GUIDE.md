# คู่มือการใช้งานระบบ "e-Campaign"

ระบบจองรอบบริการสุขภาพแบบ campaign — ฉีดวัคซีน, ตรวจสุขภาพ, อบรม ฯลฯ พร้อมเช็คอินด้วย QR + รายงานประจำวัน + สร้างประวัติวัคซีนอัตโนมัติ

> **Audience:** ผู้ใช้งาน 2 ฝั่ง — **Admin** (เจ้าหน้าที่คลินิก) และ **User** (นักศึกษา/บุคลากรที่จอง)

---

## 1. ภาพรวมการทำงาน

```
┌─────────────────────────┐         ┌──────────────────────────┐
│  Admin (เจ้าหน้าที่)    │         │  User (จองผ่าน LINE)     │
├─────────────────────────┤         ├──────────────────────────┤
│ ① สร้าง Campaign         │ ──────► │ ② เลือก campaign         │
│   (title, type, ความจุ) │         │   → เลือกวันที่ → เลือกเวลา│
│                         │         │   → submit booking        │
│ ③ เปิด slot รายวัน      │         │                          │
│   (date × time × cap)   │         │ ④ มาที่คลินิก:           │
│                         │         │   • Scan QR (self check) │
│ ⑤ admin เช็คอินเอง      │ ◄────── │   • หรือ admin scan      │
│   (กดปุ่มใน daily report)│         │                          │
│                         │         │                          │
│ ⑥ ดูรายงานวันนั้น        │ ◄────── │ ⑦ ระบบสร้าง vaccination  │
│   ใครมา/ไม่มา + CSV     │         │   record อัตโนมัติ (vaccine)│
│                         │         │                          │
│ ⑧ Cron auto-cancel       │         │                          │
│   no-show ทุกคืน         │         │                          │
└─────────────────────────┘         └──────────────────────────┘
```

**ประเภท campaign:** `vaccine` · `training` · `health_check` · `other`

---

## 2. คู่มือสำหรับ Admin

### 2.1 การเข้าใช้งาน
- เข้าผ่าน **`/admin/`** (admin/staff session)
- จัดการ campaign ที่ **`admin/campaigns.php`**

### 2.2 สร้าง / แก้ Campaign (`admin/campaigns.php`)

| ฟิลด์ | คำอธิบาย |
|---|---|
| **Title** | ชื่อ campaign เช่น "ฉีดวัคซีนไข้หวัดใหญ่ 2569" |
| **Type** | vaccine / training / health_check / other (เฉพาะ vaccine จะสร้าง vaccination record ให้อัตโนมัติ) |
| **Total capacity** | จำนวนคนรวมทุก slot |
| **Status** | draft / coming_soon / **active** / full / inactive / private / archived (user เห็นเฉพาะ `active`) |
| **Available until** | ปิดรับจองอัตโนมัติเมื่อเลย deadline |
| **Auto-approve** | 1 = จองแล้ว confirm ทันที, 0 = รอ admin approve |
| **Share token** | สร้างลิงก์สั้น `https://.../user/c.php?t={token}` สำหรับโปสเตอร์/social |
| **QR enabled** | เปิด QR self check-in (1) |

**Capacity bar:** สีตาม fill % — เขียว <60%, ส้ม 60-90%, แดง >90%

**ข้อจำกัด:**
- ห้ามลด capacity ต่ำกว่าจำนวนที่จองแล้ว
- ลบ campaign ได้ก็ต่อเมื่อไม่มี booking

### 2.3 จัดการ Time Slots (`admin/time_slots.php`)

**Bulk create:**
- เลือก campaign
- หลายวัน (multi-select) × หลายช่วงเวลา = สร้างกี่ slot ก็ได้ในชอตเดียว
- ความจุรวม distribute เท่าๆ กัน (เศษเติม slot แรกๆ)
- Auto-skip slot เวลาที่มีอยู่แล้ว

**Edit/Delete slot:**
- แก้ start/end/capacity — ห้ามลด cap ต่ำกว่าจำนวนจอง
- ลบ slot → cancel booking ที่ยังไม่มา + ส่งอีเมลแจ้ง user

### 2.4 รายงานประจำวัน (`admin/daily_report.php`)

**Controls:**
- Date picker (default วันนี้)
- กรองตาม campaign / type tab (all · มาตามนัด · มาก่อนวันนัด · ยังไม่มา · ยกเลิก)
- Auto-refresh 60 วินาที (toggle)
- Export CSV

**KPI cards (with delta vs เมื่อวาน):**
| KPI | สูตร |
|---|---|
| มาตามนัด | `attended_at NOT NULL` AND `DATE(attended_at) = slot_date` |
| มาก่อนวันนัด | `attended_at NOT NULL` AND `DATE(attended_at) < slot_date` |
| ยังไม่มา | `attended_at IS NULL` AND `slot_date = วันรายงาน` AND not cancelled |
| ยกเลิก | `status IN ('cancelled', 'cancelled_by_admin')` |
| No-show rate | no_show / active_bookings × 100% |

**Slot breakdown:** capacity, booked, attended, pending, cancelled, fill % ต่อ slot

**ตารางผู้รับบริการ:**
- ชื่อ-รหัส-เบอร์-campaign-วันนัด-รอบเวลา-**วันที่+เวลาเช็คอิน**-สถานะ
- ปุ่ม "ยกเลิกการเช็คอิน" สำหรับ revert (ใช้เมื่อ check-in ผิด)

### 2.5 Check-in workflow

**Option A — Admin manual** (`admin/ajax/ajax_checkin_booking.php`):
- กดปุ่ม "เช็คอิน" ใน UI ที่ booking
- เปลี่ยน `status = 'completed'` + `attended_at = NOW()`
- ถ้า campaign type = vaccine → สร้าง vaccination record อัตโนมัติ

**Option B — User QR self check-in** (`user/checkin_campaign.php`):
- ต้องเปิด `qr_enabled = 1` ที่ campaign
- Token: `HMAC-SHA256("qr:campaign:{id}", QR_SLOT_SECRET)`
- URL: `https://.../user/checkin_campaign.php?campaign={id}&token={hash}`
- User scan QR ที่จุดบริการ → ระบบหา booking ของ user คนนั้นในวันที่เหมาะสม
- พิมพ์ QR ติดป้าย/หน้าจอจาก campaign modal ได้

### 2.6 QR modal (ใน `admin/campaigns.php`)
- Toggle `qr_enabled` (AJAX)
- แสดง QR code (generated จาก `/user/api_campaign_qr.php?campaign={id}`)
- คัดลอกลิงก์ / พิมพ์โปสเตอร์

---

## 3. คู่มือสำหรับ User

### 3.1 จองรอบ (3 ขั้นตอน)

**Step 1 — เลือก campaign** (`user/booking_campaign.php`)
- เห็นเฉพาะ campaign `status = 'active'` + ยังไม่เกิน `available_until`
- ดูจำนวนที่ว่าง · ห้ามจองถ้า remaining ≤ 0
- ห้ามจองซ้ำ campaign ที่ booked / confirmed อยู่แล้ว

**Step 2 — เลือกวันที่** (`user/booking_date.php?campaign_id=X`)
- ปฏิทินแสดงเฉพาะวันที่มี slot ว่าง

**Step 3 — เลือกเวลา** (`user/booking_time.php?campaign_id=X&year=Y&month=M&day=D`)
- ทุกรอบของวัน + จำนวนว่าง/ใช้ + fill %

**Submit** (`user/submit_booking.php`)
- Validation:
  - User ยังไม่ได้จอง campaign นี้ (booked/confirmed)
  - capacity campaign + slot ยังไม่เต็ม
- Status: `confirmed` (ถ้า auto-approve) หรือ `booked` (รอ admin)
- ส่งอีเมลยืนยัน

### 3.2 ยกเลิกการจอง (`user/cancel_booking.php` POST)
- ยกเลิกได้เฉพาะ status `booked` / `confirmed`
- ส่งอีเมลแจ้ง · slot ว่างให้คนอื่นจอง

### 3.3 เช็คอิน

**(แนะนำ) Scan QR ที่จุดบริการ:**
1. เปิด LINE → เข้าระบบ
2. Scan QR ติดป้าย/หน้าจอ
3. ขึ้นหน้ายืนยัน → กด "เช็คอิน"
4. สำเร็จ → status = completed

**State ที่เป็นไปได้ตอน scan:**
- `confirm` → พร้อม check-in
- `success` → check-in สำเร็จ
- `already` → เช็คอินแล้ว
- `no_booking` → ไม่พบการจองของคุณในวันนี้
- `qr_disabled` → admin ปิด QR
- `invalid` → token ผิด/หมดอายุ

---

## 4. Status State Machine

```
              ┌──────────┐  is_auto_approve=1   ┌────────────┐
   submit ──► │ booked   │ ─────────────────►   │ confirmed  │
              │ (รอ admin)│                      │ (ยืนยันแล้ว) │
              └────┬─────┘                      └─────┬──────┘
   user/admin     │                                   │
   cancel        ▼                                   ▼ check-in
              ┌──────────┐                      ┌────────────┐
              │cancelled │                      │ completed  │
              │ + admin  │                      │ (มาแล้ว)    │
              └──────────┘                      └────────────┘
                                ▲                     │
                                │                     ▼ (vaccine campaign)
              ┌────────────┐    │           ┌──────────────────────┐
              │ expired    │    │           │ user_vaccination_     │
              │ (no-show)  │ ◄──┘           │ records auto-created │
              └────────────┘                └──────────────────────┘
              cron job
```

| Status | ความหมาย |
|---|---|
| `booked` | จองแล้ว รอ admin approve (ถ้า auto-approve=0) |
| `confirmed` | ยืนยันแล้ว — พร้อมเช็คอิน |
| `completed` | มาแล้ว (attended_at ≠ NULL) |
| `cancelled` | user ยกเลิกเอง |
| `cancelled_by_admin` | admin ยกเลิกให้ |
| `expired` | no-show — cron ยกเลิกอัตโนมัติ (จาก `cron/auto_cancel_expired_bookings.php`) |

---

## 5. Capacity Enforcement

**ระดับ campaign:**
- ตอน submit: `COUNT(status IN ('booked','confirmed')) < total_capacity`
- ถ้าเต็ม → user เห็น overlay "เต็มแล้ว"

**ระดับ slot:**
- ตอน submit: `COUNT slot bookings (active) < max_capacity`
- ถ้าเต็ม → ขึ้น error ระหว่าง booking

**ลบ slot:**
- Booking ที่ยังไม่มา (attended_at IS NULL) ถูก cancel ทั้งหมด + แจ้งอีเมล

---

## 6. QR Token Mechanism

**Campaign-level QR** (daily self check-in):
- Token: `hash_hmac('sha256', "qr:campaign:{campaign_id}", QR_SLOT_SECRET)`
- URL: `/user/checkin_campaign.php?campaign={id}&token={hash}`
- ตั้งค่า `QR_SLOT_SECRET` ใน `config.php` / secrets

**Slot-level QR** (legacy):
- Token: `hash_hmac('sha256', "qr:slot:{slot_id}", QR_SLOT_SECRET)`
- URL: `/user/checkin.php?slot={id}&token={hash}`

---

## 7. Vaccination Record Auto-creation

**Trigger:** booking → `completed`

**Logic** (`includes/vaccination_helper.php` → `record_vaccination_from_booking()`):
1. booking exists + status = `completed`
2. campaign.type = `vaccine`
3. ยังไม่มี vaccination record (idempotent ด้วย `campaign_booking_id`)
4. INSERT `user_vaccination_records`:
   - `user_id`, `campaign_booking_id`, `vaccine_name` = campaign.title
   - `vaccinated_at` = booking.attended_at
   - `status` = 'completed'

**ผล:** ประวัติวัคซีน user อัปเดตอัตโนมัติ ไม่ต้องบันทึก manual

---

## 8. Cron: Auto-Cancel No-Show

**Endpoint:** `cron/auto_cancel_expired_bookings.php?token={secret}`
- รัน daily 02:00 (Asia/Bangkok) เช่นผ่าน cron-job.org
- Secret: `CRON_SECRET_TOKEN`

**เงื่อนไข cancel:**
- `slot_date < CURDATE()`
- `attended_at IS NULL`
- `status IN ('booked', 'confirmed')`

**Actions:**
- Set `status = 'expired'`
- Email + LINE notification ถึง user
- Log activity

---

## 9. FAQ / Troubleshooting

| ปัญหา | สาเหตุ / วิธีแก้ |
|---|---|
| User ไม่เห็น campaign | ตรวจ status = `active`, available_until ไม่หมดอายุ, ยังไม่ได้จอง campaign นี้อยู่ |
| User กดจอง ขึ้น "เต็มแล้ว" | campaign หรือ slot capacity ครบ — เปิด slot เพิ่มหรือเลือกวันอื่น |
| Scan QR ขึ้น "no_booking" | user ยังไม่ได้จอง campaign นี้ในวันนี้ |
| Scan QR ขึ้น "invalid" | token ผิด/หมดอายุ — generate ใหม่จาก campaign modal |
| daily report KPI ไม่ตรง | กดปุ่ม "รีเฟรช" หรือเช็คฟิลเตอร์ที่เลือก (campaign / type) |
| Vaccination record ไม่ขึ้น | ตรวจ campaign.type = `vaccine` + booking.status = `completed` |
| User บอกถูกยกเลิกไม่ทันรู้ตัว | cron auto-cancel เช็คทุก 02:00 — ดู log + email ที่ส่งหา user |

---

## 10. ไฟล์โค้ดที่เกี่ยวข้อง

| ไฟล์ | บทบาท |
|---|---|
| `admin/campaigns.php` | Admin: CRUD campaign + QR modal |
| `admin/time_slots.php` | Admin: bulk create/edit/delete slots |
| `admin/daily_report.php` | Admin: รายงานวันนั้น + Export CSV |
| `admin/ajax/ajax_checkin_booking.php` | Admin: manual check-in |
| `admin/ajax/ajax_daily_report.php` | Admin: daily report data |
| `user/booking_campaign.php` | User: เลือก campaign |
| `user/booking_date.php` | User: เลือกวันที่ |
| `user/booking_time.php` | User: เลือกเวลา |
| `user/submit_booking.php` | User: submit + validate |
| `user/cancel_booking.php` | User: ยกเลิกการจอง |
| `user/checkin_campaign.php` | User: QR self check-in |
| `user/c.php` | Short link `?t={share_token}` |
| `user/api_campaign_qr.php` | Generate QR image |
| `includes/vaccination_helper.php` | Auto-create vaccination record |
| `cron/auto_cancel_expired_bookings.php` | Cron: cancel no-show |

---

## 11. Database Schema (ย่อ)

| ตาราง | บทบาท |
|---|---|
| `camp_list` | Campaign (title, type, capacity, status, share_token, qr_enabled, is_auto_approve) |
| `camp_slots` | Slot รายวัน (campaign_id, slot_date, start_time, end_time, max_capacity) |
| `camp_bookings` | การจอง (student_id, campaign_id, slot_id, status, attended_at) |
| `user_vaccination_records` | ประวัติวัคซีน auto-created (campaign_booking_id UNIQUE) |

---

_เอกสารนี้สร้างเมื่อ 11 พ.ค. 2569 · ดูประวัติผ่าน `git log E_CAMPAIGN_GUIDE.md` หากระบบทำงานไม่ตรงตามนี้ แจ้งทีมพัฒนา_
