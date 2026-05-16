# LINE FAQ guard — switch to regular office hours — 2026-05-16

## Symptom
User report ภาพ: เวลา 16:51 น. (ก่อน close 17:00) บอท FAQ ตอบ
"คลินิกจะปิดทำการในวันจันทร์ที่ 18 พ.ค. 2569 เนื่องจากเป็นช่วงเทอมเบรค"
ทั้งที่ admin ตั้ง `only_when_closed=1`

## Root cause
`get_clinic_current_status()` ดู `sys_clinic_hours` แล้วเจอ special-date entry
ของช่วงเทอมเบรค (is_closed=1) → คืน `state='closed_today', is_open_now=false`
→ guard `is_open_now=true` ไม่ match → บอทตอบ FAQ

ระหว่างเทอมเบรค สถานะ service = ปิด ตลอด 24 ชม. — แต่ staff ยังนั่งทำงาน
ในเวลาราชการ (จ-ศ 08:00-17:00) ของ regular schedule → บอทตอบทับขณะคนกำลังรอตอบ

## Decision
แยก 2 concept:
- **Service hours** (เปิดให้บริการตรวจรักษา) = `get_clinic_current_status()` — ใช้ตอบคำถาม "วันนี้เปิดไหม"
- **Office hours** (staff อยู่ในเวลาราชการปกติ) = `clinic_is_within_regular_office_hours()` — ใช้ใน guard ของ FAQ auto-reply

Guard ใหม่: บอทตอบเมื่ออยู่ "นอก regular office hours ของ weekday นั้น"
— ไม่สนใจ holiday/special override → ในเทอมเบรค จ-ศ 08:00-17:00 บอทเงียบ, นอกนั้นตอบ

## Files
- `includes/clinic_status_helper.php` — เพิ่ม `clinic_is_within_regular_office_hours(PDO $pdo, ?DateTimeImmutable $now)`
  - Query: `SELECT open_time, close_time, is_closed FROM sys_clinic_hours WHERE type='regular' AND weekday=:wd LIMIT 1`
  - Return: `open <= now < close` (false ถ้าไม่มี regular row, is_closed=1, หรือไม่มีเวลา)
- `api/line_webhook.php:531` — เปลี่ยน guard จาก `get_clinic_current_status($pdo)['is_open_now']`
  → `clinic_is_within_regular_office_hours($pdo)` + อัพเดตข้อความ log
- `portal/_partials/ai_qa_lab.php:863` — เปลี่ยน label "ตอบเฉพาะตอนปิด" → "ตอบเฉพาะนอกเวลาทำการ"
  + อัพเดต hint อธิบายว่ายึด regular hours ไม่ใช่ special override

## Side effects
- ไม่กระทบ `get_clinic_current_status()` (ยังใช้ตอบคำถาม "เปิดไหม", flex preview)
- ไม่กระทบ schema — ไม่มี migration
- DB column `only_when_closed` ความหมายเปลี่ยนเล็กน้อย: "เปิดอยู่" = "อยู่ใน regular office hours ของวันนั้น"

## Edge cases
- weekday นั้นไม่มี regular row (เช่น อาทิตย์) → return false → บอทตอบได้ตลอดวัน ✓
- regular row มี is_closed=1 (admin ตั้งวันหยุดประจำ เช่น อาทิตย์ปิด) → return false → บอทตอบได้ตลอดวัน ✓
- เวลาตรงกับ close_time pop (เช่น 17:00:00) → `nowHm < closeHm` คือ false → บอทเริ่มตอบ ✓
