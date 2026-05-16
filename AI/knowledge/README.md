# AI/knowledge/ — Semantic Memory

Distilled, reusable knowledge — pattern, recipe, schema reference

## Format
ตั้งชื่อตาม topic (kebab-case): `<topic>.md` เช่น
- `gemini-vision-quirks.md`
- `tailwind-jit-pitfalls.md`
- `modal-portal-escape.md`
- `dark-mode-checklist.md`

### Template
```markdown
# <Topic>

## ปัญหา / Pattern ที่เจอ
อะไรคือ situation ที่ pattern นี้แก้

## วิธี
ขั้นตอน + code example

## Gotchas
ของที่ทำพังบ่อย / ต้องระวัง

## Reference
- file:line ในโค้ดจริง
- AI/logs/ entry ที่ origin
- external link (ถ้ามี)
```

## เกณฑ์การเขียน
- เจอ pattern ≥ 2 ครั้ง — ค่อยเขียน (ไม่ใช่ทุก one-off)
- เนื้อหาต้อง **actionable** — บอกว่าต้องทำอย่างไร ไม่ใช่ว่ามันคืออะไร
- ลิงก์ไปโค้ดจริง (`file:line`) เสมอเพื่อ verify
- ถ้า outdated → **อัพเดตได้** (ต่างจาก logs/) แต่ต้อง verify ก่อน

## ความสัมพันธ์กับ CLAUDE.md
- CLAUDE.md = กฎ + convention ของโปรเจกต์ (ผู้บังคับบัญชา)
- knowledge/ = recipe + troubleshooting (ลูกมือช่าง)

ถ้า knowledge entry ไหนกลายเป็น "ห้ามลืม" สำหรับทุก agent → promote ขึ้น CLAUDE.md
