# Nurse Position Names — Canonical List

## Source of truth
`includes/nurse_positions.php` — PHP constants + `is_nurse_position()` helper

```php
NURSE_RN_POSITION       = 'พยาบาลวิชาชีพ'           // counted as RN
NURSE_HEAD_POSITIONS    = [3 ชื่อ]                   // counted as head
  - 'หัวหน้าหอผู้ป่วย'
  - 'รองหัวหน้าหอผู้ป่วย'
  - 'พยาบาลหัวหน้าเวร'
NURSE_POSITION_NAMES    = [4 ชื่อรวม]                 // all nurse roles
is_nurse_position($s)   = bool                       // strict in_array
```

## Where it's used (PHP)
| ไฟล์ | ทำอะไร |
|---|---|
| `portal/actions/identity_actions.php` | auto-flag `access_nurse_productivity` บน `sys_staff_positions` |
| `portal/nurse_productivity_import.php` | Excel import — นับ RN/head จาก schedule |
| `portal/ajax_nurse_productivity.php` | Daily derive — RN/head count จาก schedule_json |

## JS mirror (manual sync)
- `portal/nurse_schedule.php` — `const POSITIONS = { ... }` (object มี metadata icon/color)
- ถ้าเพิ่มชื่อใหม่ → ต้อง sync ทั้ง PHP constants และ JS POSITIONS

## Auto-flag behavior (identity_actions.php)
- **set**: เมื่อตำแหน่งชื่อตรงกับ 4 ชื่อนี้ → `flags.access_nurse_productivity = 1` (force) ทันที
- **unset (symmetric)**: เมื่อ rename ตำแหน่งจากชื่อพยาบาล → ชื่ออื่น → flag กลับเป็น 0
- ตำแหน่งชื่ออื่นที่ admin manually ติ๊ก flag เอง → ระบบเคารพการเลือก ไม่บังคับ off

### ผลกระทบที่ต้องจำ
- การ rename ตำแหน่งพยาบาล (เช่น typo fix) **จะตัด flag ของ staff ทุกคนที่ link กับตำแหน่งนั้น** ตอน login ถัดไป (เพราะ position.flags คุม flag staff)
- การ delete ตำแหน่งพยาบาล → staff ทั้งหมด `position_id = NULL` (custom mode) → flag จะกลับไปอ่านจาก `sys_staff.access_*` คอลัมน์เดิม
- Fail-closed: ถ้า DB lookup เก่าพังตอน rename → block save + set `$idError`

## เพิ่มชื่อใหม่ — Checklist
1. แก้ `includes/nurse_positions.php` (constants)
2. ถ้าชื่อใหม่เป็น head → เพิ่มใน `NURSE_HEAD_POSITIONS`
3. ถ้าชื่อใหม่ไม่ใช่ RN/head แต่ยังถือว่าเป็นพยาบาล → คิดให้ดีว่าจะใส่ใน group ไหน
4. Sync `portal/nurse_schedule.php` `POSITIONS` object (JS)
5. Backfill existing positions ที่ใช้ชื่อนั้นแล้ว — รัน UPDATE flag ด้วยมือ หรือเปิด edit แล้ว save ใหม่
