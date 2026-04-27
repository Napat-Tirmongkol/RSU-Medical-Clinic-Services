# RSU Medical Clinic Services — Codex Guidelines

## Coding Conventions

### Tables / Data Grids
- **ทุกครั้งที่สร้างตารางแสดงข้อมูล ต้องทำเป็น Pagination เสมอ**
- ค่า default: **20 รายการ/หน้า**
- ต้องมีปุ่มนำทางครบ: หน้าแรก `«` / ก่อนหน้า `‹` / เลขหน้า (window ±2) / ถัดไป `›` / สุดท้าย `»`
- Pagination ต้องทำงานร่วมกับ search/filter ได้เสมอ
- ใช้ `LIMIT` + `OFFSET` ใน SQL query
- แสดง "หน้า X / Y · รวม N รายการ" เหนือ/ใต้ pagination controls
