# ระบบ SOS แจ้งเหตุผู้ป่วย — Specification Draft

> สถานะ: **ไอเดีย / ยังไม่ได้ implement**  
> บันทึกวันที่: 2026-05-12

---

## Use Case

ผู้ป่วยหรือผู้มาใช้บริการที่อยู่ในบริเวณมหาวิทยาลัย RSU สามารถกดแจ้ง SOS ขอความช่วยเหลือจากคลินิก พร้อมระบุจุดที่ตั้งของตนบนแผนที่วิทยาเขต — เจ้าหน้าที่คลินิกรับแจ้งผ่าน LINE Group และ dashboard portal

---

## User Journey (MVP)

```
ผู้ป่วย (LINE)
  └─ เปิด LIFF / short link
  └─ เลือกจุดที่ตั้ง (predefined list หรือ interactive map)
  └─ เพิ่ม note สั้น (optional)
  └─ กดยืนยัน → POST /api/sos_create.php

Server
  └─ บันทึก sys_sos_requests
  └─ Push Flex Message → LINE Group คลินิก

เจ้าหน้าที่คลินิก
  └─ รับ LINE push → กด "รับทราบ" (ผ่าน postback หรือ portal)
  └─ เดินทางช่วยเหลือ
  └─ กด "ปิด/แก้ไขแล้ว" → สถานะ resolved

Portal Dashboard (admin)
  └─ รายการ SOS pending / ack / resolved
  └─ กดรับ/ปิดได้จาก portal โดยตรง
```

---

## Database Schema

```sql
CREATE TABLE sys_sos_requests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_line_uid VARCHAR(50) NULL,          -- LINE User ID (ถ้า auth ผ่าน LINE)
    patient_name  VARCHAR(100) NULL,
    location_key  VARCHAR(80)  NOT NULL,        -- predefined key หรือ "custom"
    location_label VARCHAR(200) NOT NULL,       -- ชื่อจุดแสดงผล
    location_lat  DECIMAL(10,7) NULL,           -- ถ้าเลือกจาก map
    location_lng  DECIMAL(10,7) NULL,
    notes         TEXT NULL,
    status        ENUM('open','ack','resolved') NOT NULL DEFAULT 'open',
    ack_by        INT UNSIGNED NULL,            -- FK sys_admins.id
    ack_at        TIMESTAMP NULL,
    resolved_at   TIMESTAMP NULL,
    line_msg_id   VARCHAR(100) NULL,            -- LINE message ID ที่ push ออกไป (for dedup)
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Predefined Locations (Phase 1)

| key | label |
|-----|-------|
| `clinic_main` | คลินิกนักศึกษา (อาคารหลัก) |
| `gym` | ศูนย์กีฬา |
| `library` | หอสมุด |
| `canteen` | โรงอาหาร |
| `parking_a` | ลานจอดรถ A |
| `dorm_a` | หอพัก A |
| `dorm_b` | หอพัก B |
| `auditorium` | หอประชุมใหญ่ |
| `gate_main` | ประตูหลัก |
| `other` | อื่นๆ (ระบุใน note) |

---

## LINE Flex Message (Push to Group)

```json
{
  "type": "bubble",
  "header": { "type": "box", "layout": "vertical", "backgroundColor": "#D32F2F",
    "contents": [{ "type": "text", "text": "🚨 SOS แจ้งเหตุ", "color": "#FFFFFF", "weight": "bold", "size": "xl" }]
  },
  "body": { "type": "box", "layout": "vertical", "contents": [
    { "type": "text", "text": "จุดที่ตั้ง: {location_label}", "wrap": true },
    { "type": "text", "text": "เวลา: {HH:mm น.}", "color": "#888888", "size": "sm" },
    { "type": "text", "text": "หมายเหตุ: {notes}", "wrap": true }
  ]},
  "footer": { "type": "box", "layout": "vertical", "contents": [
    { "type": "button", "action": { "type": "uri", "label": "เปิด Dashboard", "uri": "https://rsu.clinic/portal/?section=sos" } }
  ]}
}
```

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/sos_create.php` | รับแจ้งจาก patient — validate, insert DB, push LINE |
| POST | `/portal/ajax_sos.php` | actions: `list` · `ack` · `resolve` (portal staff) |
| POST | `/api/line_webhook.php` | รับ postback "รับทราบ" จาก LINE (อนาคต) |

---

## Security / Anti-abuse

- **Rate limit**: 1 request ต่อ LINE UID ทุก 5 นาที (ป้องกัน spam) — ตรวจจาก `created_at` ล่าสุดใน DB
- **Auth**: ต้องผ่าน LINE LIFF login (มี `line_uid`) — anonymous ไม่รับ
- **CSRF**: LIFF token ใช้แทน csrf_token สำหรับ mobile path
- **No escalation loop**: ถ้า LINE push ล้มเหลว → log error แต่ยังบันทึก DB ไว้ (staff เห็นจาก portal poll)

---

## Portal Dashboard (Section: `sos`)

- ตารางรายการ SOS แบ่งแท็บ: **Pending** / **รับแล้ว** / **ปิดแล้ว**
- Pagination 20 รายการ/หน้า (ตาม CLAUDE.md convention)
- กด "รับทราบ" / "ปิดเคส" ได้ inline
- Auto-refresh ทุก 30 วินาที (polling) หรือใช้ SSE อนาคต
- แสดง badge จำนวน pending ที่ sidebar icon

---

## Implementation Phases

### Phase 1 — MVP
- [ ] `sys_sos_requests` table migration
- [ ] LIFF page (predefined location list + note form)
- [ ] `api/sos_create.php` — insert + LINE push
- [ ] Portal dashboard section `sos` — list + ack + resolve
- [ ] Sidebar menu ใน portal (section ข้อมูลหลัก หรือ section ใหม่ "การแพทย์ฉุกเฉิน")

### Phase 2 — Interactive Map
- [ ] แผนที่วิทยาเขต RSU (Leaflet.js + custom tile หรือ static image overlay)
- [ ] Tap-to-pin location แทน predefined list
- [ ] ส่ง lat/lng + reverse geocode label

### Phase 3 — Auto-escalate
- [ ] ถ้า ack ไม่เกิดขึ้นใน 3 นาที → re-push LINE + notify หัวหน้าเวร
- [ ] LINE postback "รับทราบ" จากเจ้าหน้าที่ใน LINE Group โดยตรง (ไม่ต้องเปิด portal)
- [ ] SMS fallback ถ้า LINE ล่ม (ผ่าน third-party SMS API)

---

## Open Questions

1. LIFF App ID ต้องสร้างใหม่ใน LINE Developer Console — ตั้งชื่อ `RSU-SOS`?
2. LINE Group ที่จะ push ไปคือ group เดิมที่ใช้ broadcast อยู่แล้ว หรือ group คลินิกเฉพาะ?
3. ต้องการ audit trail ว่าใครรับแต่ละเคสใช่ไหม (ปัจจุบัน schema มี `ack_by` ไว้แล้ว)?
4. รองรับผู้ป่วยที่ไม่มี LINE ไหม (walk-in กด kiosk)?
