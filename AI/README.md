# AI/ — Agent Memory Architecture

โฟลเดอร์นี้คือ **long-term memory** ของ AI agent (Claude Code / Codex / Cursor) ที่ทำงานกับ repo นี้
แยกออกจากโค้ดและ docs ของมนุษย์อย่างชัดเจน เพื่อกัน vault รก + แยก hallucination ออกจากความรู้จริง

## ปรัชญา (PARA-style mapping)

| Layer | ที่อยู่ | เปรียบเหมือน | Agent ทำอะไรได้ |
|---|---|---|---|
| **Working memory** | session ปัจจุบัน + `hot.md` | RAM | อ่าน/เขียน เต็มที่ |
| **Project rules** | `CLAUDE.md`, `AGENTS.md`, `AI_GUIDE.md` | กติกาบ้าน | อ่านอย่างเดียว (แก้เมื่อ user ขอ) |
| **Episodic memory** | `AI/logs/` | สมุดบันทึก | append-only — log ที่เกิดขึ้นจริง |
| **Semantic memory** | `AI/knowledge/` | คลังความรู้ | distilled, curated — แก้แบบ careful |
| **Scratch** | `AI/scratch/` | กระดาษทด | ใช้แล้วทิ้ง — clean ได้ |

## โครงสร้าง

```
AI/
├── README.md           ← ไฟล์นี้ (convention)
├── logs/               ← session summaries, decision records
│   └── YYYY-MM-DD-<topic>.md
├── knowledge/          ← distilled facts, schema notes, recipes
│   └── <topic>.md
└── scratch/            ← draft, exploration, ของชั่วคราว
    └── (อะไรก็ได้ — clean เป็นระยะ)
```

## กติกาการเขียน (สำคัญ)

### `AI/logs/` — Episodic
- ทุกไฟล์ตั้งชื่อ `YYYY-MM-DD-<short-topic>.md` (เช่น `2026-05-16-finance-phase-c.md`)
- **Append-only** — ไม่แก้ไฟล์เก่า เขียนไฟล์ใหม่แทนถ้ามีอัพเดต
- เนื้อหา: สิ่งที่ทำ · decision ที่ตัดสินใจ · ปัญหาที่เจอ · ทางแก้ที่เลือก
- เขียนเมื่อ: ปิด task ใหญ่ · ตัดสินใจ architectural · debug ปัญหายาก
- **ไม่ต้อง log ทุกเรื่อง** — ของเล็กๆ ไม่ต้อง

### `AI/knowledge/` — Semantic
- ตั้งชื่อตาม topic (เช่น `gemini-vision-quirks.md`, `tailwind-jit-pitfalls.md`)
- **Curated** — แก้ไขได้ แต่ต้องผ่านการกลั่นกรอง ไม่ใช่ dump ดิบๆ
- เนื้อหา: pattern ที่ใช้ซ้ำ · troubleshooting recipe · schema reference · constraint แปลกๆ
- เขียนเมื่อ: เจอ pattern เดียวกัน ≥ 2 ครั้ง · มีความรู้ที่ session อื่นต้องใช้แน่ๆ

### `AI/scratch/` — ชั่วคราว
- ของอะไรก็ได้ที่ยังไม่แน่ว่าจะเก็บ — draft, test data, exploration notes
- **อาจถูกลบเมื่อไหร่ก็ได้** — อย่าเก็บของสำคัญที่นี่
- ถ้าใช้แล้วพบว่ามีค่า → ย้ายไป `knowledge/` หรือ promote เป็น CLAUDE.md section

## Workflow

```
session เริ่ม
  ↓
อ่าน hot.md (snapshot) + CLAUDE.md (rules)
  ↓
ถ้าทำงาน topic เฉพาะ → search AI/knowledge/ + AI/logs/ ด้วยคีย์เวิร์ด
  ↓
ทำงาน
  ↓
ก่อนจบ session:
  - อัพเดต hot.md ถ้า phase/decision เปลี่ยน
  - drop AI/logs/YYYY-MM-DD-<topic>.md ถ้ามีของควรจำ
  - clean AI/scratch/ ถ้ารก
```

## ห้าม

- ❌ ห้ามเขียน secrets / credentials / personal data ใน AI/ (commit ลง git)
- ❌ ห้ามใช้ AI/ เก็บโค้ดจริงของ feature — โค้ดอยู่ในไฟล์โปรเจกต์
- ❌ ห้ามให้ scratch โตเกิน 20 ไฟล์ — clean ทุกครั้งที่ทำงาน topic เดิมเสร็จ
- ❌ ห้ามแก้ไฟล์ `logs/` เก่า — append ไฟล์ใหม่แทน (audit trail ของ decision)

## Reference
- Inspiration: บทความ "Obsidian as OS for Claude Code" (PARA + hot.md pattern)
- Project rules: `CLAUDE.md` (root)
- This folder is tracked in git so memory persists across machines + agents
