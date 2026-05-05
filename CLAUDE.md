# RSU Medical Clinic Services — Claude Guidelines

## Coding Conventions

### Tables / Data Grids
- **ทุกครั้งที่สร้างตารางแสดงข้อมูล ต้องทำเป็น Pagination เสมอ**
- ค่า default: **20 รายการ/หน้า**
- ต้องมีปุ่มนำทางครบ: หน้าแรก `«` / ก่อนหน้า `‹` / เลขหน้า (window ±2) / ถัดไป `›` / สุดท้าย `»`
- Pagination ต้องทำงานร่วมกับ search/filter ได้เสมอ
- ใช้ `LIMIT` + `OFFSET` ใน SQL query
- แสดง "หน้า X / Y · รวม N รายการ" เหนือ/ใต้ pagination controls

### Dialogs / Alerts
- **ห้ามใช้ `alert()`, `confirm()`, `prompt()` ของ browser** — ใช้ **SweetAlert2** (`Swal.fire()`) ทุกครั้ง
- SweetAlert2 โหลดอยู่แล้วที่ `portal/index.php` (CDN line ~540) — ใช้ได้ทันทีในทุก partial ของ portal
- ใช้ `await Swal.fire({ ... })` ใน async function และ destructure `{ isConfirmed }` สำหรับ confirm dialog

### Design System
- ใช้ tokens และ component classes จาก `assets/STYLE_GUIDE.md` เสมอ
- **ห้ามเขียน CSS ใหม่ใน user-facing modules** — ถ้า `<style>` ยาวเกิน ~10 บรรทัด ให้เพิ่ม component ใน `assets/css/tailwind.src.css` แทน
- **ห้ามใส่ค่าสี hex ดิบในมาร์กอัป** — ใช้ token (`bg-brand-500`, `text-rose-600`, ฯลฯ)
- หลังแก้ `tailwind.src.css` หรือ `tailwind.config.js` ต้องรัน `npm run build:css` แล้ว commit ไฟล์ output (`assets/css/tailwind.min.css`) ด้วย
- Bottom nav ของ user เป็น shared include — ใช้ `includes/user_bottom_nav.php` ทุกครั้ง อย่า copy markup
- หน้า reference: `user/profile.php`
