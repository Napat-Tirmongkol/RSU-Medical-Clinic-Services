# AI/logs/ — Episodic Memory

Session summaries และ decision records — append-only

## Format
ตั้งชื่อไฟล์ `YYYY-MM-DD-<short-topic>.md`

### Template
```markdown
# <topic> — YYYY-MM-DD

## Context
อะไรเกิดขึ้น ทำไมต้องตัดสินใจเรื่องนี้

## Decision
ตัดสินใจอะไร / เลือกทางไหน

## Alternatives considered
ทางเลือกอื่นที่พิจารณา + ทำไมไม่เลือก

## Consequences
ผลที่ตามมา · สิ่งที่ต้อง update / migrate
```

## เมื่อไหร่ควรเขียน
- ปิด task ใหญ่ (phase, feature)
- ตัดสินใจ architectural ที่ session อื่นต้องเคารพ
- debug ปัญหายากที่ใช้เวลานานหา root cause
- เปลี่ยน decision เก่า (เขียนไฟล์ใหม่ reference ของเก่า — **ห้ามแก้ไฟล์เก่า**)

## เมื่อไหร่ไม่ต้องเขียน
- งานเล็กๆ (typo fix, color tweak)
- ของที่ commit message ครอบคลุมหมดแล้ว
- งานที่ context ทั้งหมดอยู่ใน CLAUDE.md อยู่แล้ว
