# database/migrations/

ไฟล์ migration ทั้งหมดของโปรเจกต์ รวมอยู่ที่นี่ที่เดียว

## วิธีรัน
รันผ่าน CLI เท่านั้น — ไม่ควร expose ผ่าน web browser

```bash
php database/migrations/migrate_status_column.php
```

## ไฟล์ที่มี

| ไฟล์ | คำอธิบาย |
|---|---|
| `migrate_status_column.php` | เพิ่มคอลัม status |
| `migrate_add_gender.php` | เพิ่มคอลัม gender ใน sys_users |
| `migrate_announcements.php` | สร้างตาราง sys_announcements |
| `migrate_reset_tokens.php` | สร้างตาราง password reset tokens |
| `migrate_privilege_table.php` | สร้างตาราง privilege inventory |
| `fix_staff_schema.php` | แก้ schema ตาราง staff |
| `setup_links.php` | ตั้งค่า symlinks |
| `migrate_line_user_id_new.php` | เพิ่มคอลัมน์ line_user_id_new สำหรับ migrate LINE Login provider |

## หมายเหตุ
- ไฟล์เหล่านี้รันครั้งเดียว (idempotent ด้วย `IF NOT EXISTS`)
- ไม่ต้องรันซ้ำถ้าตาราง/คอลัมน์มีอยู่แล้ว
