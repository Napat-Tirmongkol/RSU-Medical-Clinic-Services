# Context Persistence System Setup — 2026-05-16

## Context
CLAUDE.md โตขึ้นถึง ~36KB ครอบคลุมกฎและสถานะของโปรเจกต์ทั้งหมด — แต่ทุก session AI ยังต้อง
ปู context ใหม่ทุกครั้ง (project phase ปัจจุบัน, decision ล่าสุด, สิ่งที่ค้างอยู่)
เสียเวลา warm-up นานก่อนเริ่มงานจริง

Inspiration: บทความ "Obsidian as OS for Claude Code" (PARA + hot.md + AI/ folder pattern)

## Decision
สร้าง 3 layer ของ agent memory:
1. **`hot.md`** (root) — project snapshot สั้น (~500 คำ) อ่านเป็นไฟล์แรกของทุก session
2. **`AI/logs/`** — episodic memory (append-only decision records ตามวันที่)
3. **`AI/knowledge/`** — semantic memory (distilled patterns, recipes, schema notes)
4. **`AI/scratch/`** — ephemeral workspace

CLAUDE.md ยังเป็น "constitution" — กฎเข้มของโปรเจกต์ที่ทุก agent ต้องอ่าน
แต่ของที่เปลี่ยนบ่อย (phase, current task) ย้ายไป `hot.md` แทน

## Alternatives considered
- **เก็บทุกอย่างใน CLAUDE.md อย่างเดียว** — ปฏิเสธเพราะไฟล์โตเร็ว + ปนกฎถาวรกับสถานะชั่วคราว
- **ใช้ git tag / release notes** — ปฏิเสธเพราะ agent อ่านยาก ไม่ optimize สำหรับ AI consumption
- **เก็บใน DB** — ปฏิเสธเพราะต้อง infra เพิ่ม + ไม่ portable ข้าม machine

## Consequences
- ทุก agent ที่ทำงานกับ repo นี้ต้องอ่าน `hot.md` + `CLAUDE.md` เป็นไฟล์แรก
- เพิ่ม "Context Persistence" section ใน CLAUDE.md อธิบาย workflow
- หลัง commit ใหญ่ — ต้องอัพเดต `hot.md` (Phase / Decision / กำลังทำ)
- AI/scratch/ จะถูก clean เป็นระยะ — อย่าเก็บของสำคัญที่นี่

## Files created
- `hot.md`
- `AI/README.md`
- `AI/logs/README.md`
- `AI/knowledge/README.md`
- `AI/scratch/README.md`
- `AI/logs/2026-05-16-context-persistence-setup.md` (ไฟล์นี้)

## Files modified
- `CLAUDE.md` — เพิ่ม section "Context Persistence (AI Memory)"
