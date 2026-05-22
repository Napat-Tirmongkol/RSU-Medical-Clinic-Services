# แผนการรวมระบบ UX Staging เข้าสู่ Production (Merge Plan)

แผนนี้มีวัตถุประสงค์เพื่อย้ายดีไซน์ "Command Center" จากโฟลเดอร์ `ux_staging/` ไปเป็นหน้าหลักของระบบจริง โดยแบ่งเป็น 4 ระยะหลักครับ

## ระยะที่ 1: เตรียมโครงสร้างฐานข้อมูล (Database Preparation)
เพื่อให้ฟีเจอร์ใหม่ (เช่น ระบบช่วยเหลือ) ใช้งานได้จริง คุณต้องรันคำสั่ง SQL ต่อไปนี้ในฐานข้อมูล `e-campaignv2_db`:

```sql
-- สร้างตารางเก็บข้อความช่วยเหลือ (Support Chat)
CREATE TABLE IF NOT EXISTS sys_support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    sender_type ENUM('user', 'admin') NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## ระยะที่ 2: การย้ายไฟล์ทรัพยากร (Assets Migration)
ย้ายไฟล์ภาพและสื่อจากโฟลเดอร์ Staging ไปยังตำแหน่งส่วนกลางของระบบ:

- [ ] สร้างโฟลเดอร์ `assets/images/` (ถ้ายังไม่มี)
- [ ] ย้ายไฟล์ `ux_staging/assets/images/clinic_map.jpg` ไปที่ `assets/images/clinic_map.jpg`
- [ ] ตรวจสอบสิทธิ์การเข้าถึงไฟล์ (Permissions) ให้ระบบสามารถอ่านรูปภาพได้

## ระยะที่ 3: การเปลี่ยนไฟล์หน้าหลัก (Core Files Replacement)
ขั้นตอนนี้จะเป็นการเปลี่ยนหน้าตาหน้าหลักของฝั่ง User:

- [ ] **Backup**: สำรองไฟล์ `user/hub.php` (หรือหน้าหลักเดิม) เก็บไว้ในชื่อ `user/hub_backup.php`
- [ ] **Deploy**: คัดลอกเนื้อหาจาก `ux_staging/index.php` ไปแทนที่ใน `user/hub.php`
- [ ] **Path Update**: ปรับเปลี่ยนพาธการอ้างอิงไฟล์ (Relative Paths) เช่น:
    - จาก `../user/booking_date.php` เป็น `booking_date.php`
    - จาก `../logout.php` เป็น `../logout.php` (ตรวจสอบให้ถูกต้องตามระดับโฟลเดอร์)

## ระยะที่ 4: การตรวจสอบความสมบูรณ์ (Post-Merge Validation)
- [ ] ทดสอบการ Login และการดึงข้อมูล Profile (ต้องแสดงชื่อและรหัสถูกต้อง)
- [ ] ทดสอบการกด "จองคิว / แคมเปญ" (Modal ต้องเด้งและแสดงรายการจริง)
- [ ] ทดสอบการส่งข้อความในระบบ "ช่วยเหลือ" (ข้อมูลต้องลงตาราง `sys_support_messages`)
- [ ] ทดสอบการแสดงผลบนมือถือ (Responsive Check)

---

### ⚠️ ข้อควรระวัง (Critical Notes)
1. **Session Control**: ตรวจสอบว่าในไฟล์ใหม่มีการเช็ค `$_SESSION['user_id']` ให้สอดคล้องกับระบบเดิมเพื่อป้องกันการเข้าถึงโดยไม่ได้รับอนุญาต
2. **Database Connection**: เปลี่ยนการเรียกใช้ `$pdo` ในไฟล์ใหม่ให้ใช้ไฟล์ `config/db_connect.php` มาตรฐานของโปรเจกต์แทนการเชื่อมต่อแบบชั่วคราว

**คุณพร้อมจะเริ่มดำเนินการในระยะที่ 1 (SQL) และ 2 (Assets) เลยไหมครับ? ผมสามารถช่วยปรับเปลี่ยนพาธในโค้ดให้ถูกต้องตามตำแหน่งจริงได้ทันทีครับ**
