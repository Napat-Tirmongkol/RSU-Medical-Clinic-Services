# AI/scratch/ — Temporary Workspace

พื้นที่ทำงานชั่วคราว — draft, exploration, ของที่ยังไม่แน่ว่าจะเก็บ

## กติกา
- เขียนอะไรก็ได้ (markdown, txt, json) — ไม่มี format บังคับ
- **อาจถูกลบเมื่อไหร่ก็ได้** — อย่าเก็บของสำคัญที่นี่
- ถ้าใช้แล้วพบว่ามีค่า:
  - ความรู้ทั่วไป → ย้ายไป `AI/knowledge/`
  - decision record → ย้ายไป `AI/logs/`
  - กฎโปรเจกต์ → promote ขึ้น `CLAUDE.md`

## Clean policy
- Agent ที่เริ่ม session ใหม่ดูเห็นไฟล์ scratch เก่า → ถามผู้ใช้ก่อนลบ
- ถ้ามีไฟล์ ≥ 20 ไฟล์ → archive หรือลบ
- ไฟล์ที่ไม่ถูกแตะนาน > 30 วัน → candidate สำหรับลบ
