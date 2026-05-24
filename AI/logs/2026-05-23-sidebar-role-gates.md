# Sidebar Group Toggle — Role Gate Audit & Fix — 2026-05-23

## Context

User report (ส่ง screenshot): หลังจาก /distill pass (`a540953`) ที่เพิ่ม default-collapse + search ใน sidebar
user role `admin` (ไม่มี `access_identity` flag) เห็น header "สิทธิ์ & ความปลอดภัย" ใน sidebar
แต่ items ทั้ง 3 ตัวข้างใน (Identity & Governance / ISO Governance / PDPA Audit) hidden หมด
→ คลิก expand แล้วเจอ group ว่างเปล่า → confusing

User feedback: "สิทธิ์ & ความปลอดภัย ไม่ได้เพิ่ม role ทำไมถึงมี"
ตามมาด้วย "แก้ด้วย และเช็คให้ครบ" — ขอ audit ทุก group toggle

## Decision

**Pattern**: Group toggle ต้องมี role gate ที่เป็น OR ของทุก inner-item gate
(ถ้า item ไหนเห็น → toggle เห็น · ถ้าไม่มี item เห็นเลย → toggle ไม่เห็น)

**Implementation**: คำนวณ `$has{Group}Group` flag ในชุด role flags
(หลัง `$hasAsset/Consumables/Inventory` ที่ line ~1175)
แล้วใช้ flag นั้น gate toggle

## Audit results (เช็คครบทุก 13 groups)

| # | Group | Toggle condition (เดิม) | Inner items + gates | สถานะ |
|---|-------|------------------------|---------------------|-------|
| 1 | overview | `!$registryOnly` | Dashboard (ไม่ gate · ทุกคน), App Launcher (ไม่ gate), โปรไฟล์ (`$isStaff`) | ✅ OK — Dashboard always shows |
| 2 | ai | `!$registryOnly && ($isSuper \|\| access_ai)` | 6 items ไม่มี inner gate · gate ที่ parent | ✅ OK |
| 3 | security | `!$registryOnly` | Identity (`$isSuper \|\| access_identity`), ISO (`$isSuper`), PDPA (`$isSuper \|\| access_identity`) | ❌ **Fixed** commit `0fecb3c` — เพิ่ม `$hasSecurityGroup` |
| 4 | insurance | `!$registryOnly \|\| $hasRegistry \|\| $hasInsurance` | 4 items แรกใช้ `!$registryOnly` (ทุก admin เห็น) · registry_upload (`$hasRegistry`) · batch_status (`$hasInsurance`) · partners (`$isSuper`) | ❌ **Fixed** ใน commit นี้ — เพิ่ม `$hasInsuranceGroup` + เปลี่ยน 4 items แรก ใช้ `!$registryOnly && $hasInsurance` |
| 5 | comm | `!$registryOnly` | ประกาศ (ไม่ gate · ทุก admin) · EDMS (`$hasEdms`) | ✅ OK — ประกาศ visible to all admins by design |
| 6 | inventory | `!$registryOnly && $hasInventory` | items มี gate ของตัวเอง | ✅ OK |
| 7 | finance | `!$registryOnly && $hasFinance` | single item | ✅ OK |
| 8 | pharmacy | `!$registryOnly && ($isSuper \|\| admin \|\| access_identity)` | 2 items ไม่ gate inner · gate ที่ parent | ✅ OK |
| 9 | monitor | `!$registryOnly && $hasSysLogs` | items มี gate | ✅ OK |
| 10 | reports | `!$registryOnly && (monthly \|\| nurse \|\| daily)` | items มี gate ต่อ | ✅ OK |
| 11 | docs | `!$registryOnly && (admin \|\| superadmin)` | single item | ✅ OK |
| 12 | masterdata | `!$registryOnly && $hasSiteSet` | clinic_data (ไม่ inner gate), scholarship (`$hasScholarship`), nurse_schedule (ไม่ inner gate) | ✅ OK — gated by $hasSiteSet at parent |
| 13 | settings | `!$registryOnly && $hasSiteSet` | single item | ✅ OK |

**สรุป**: เจอ bug 2 จุด (security + insurance) จาก 13 groups · แก้ทั้ง 2 แล้ว

## Alternatives considered

1. **Hide individual items only (ไม่ gate toggle)** — เลือกไม่ทำเพราะ user ยังเห็น header ว่างๆ confusing
2. **Compute `$has{Group}Group` ใน include separate file** — overkill สำหรับ project size · เก็บ inline ใกล้กับ flags เดิม
3. **ใช้ JavaScript hide empty groups** — เลือกไม่ทำ เพราะ render เหลือ ระยะแรกแล้ว flash · server-side gate ดีกว่า

## Consequences

- ผู้ใช้ role `admin`/`editor` ที่ไม่มี `access_identity` จะไม่เห็น "สิทธิ์ & ความปลอดภัย" อีกแล้ว
- ผู้ใช้ที่ไม่มี `access_insurance` และ `access_registry` จะไม่เห็น "ประกันสุขภาพ"
- 4 items ในประกันสุขภาพ (Dashboard Workbook, Insurance Hub, gold_card_*) ที่เคย visible ให้ทุก admin
  ตอนนี้ต้องมี `$hasInsurance` ถึงจะเห็น — **อาจกระทบ admin ที่เคยใช้ฟีเจอร์ gold card โดยไม่มี access_insurance**
  → ถ้าจะให้กลับมา ต้องเพิ่ม `access_insurance` ให้ที่ Identity & Governance modal
- Pattern กลายเป็น standard สำหรับเมนูใหม่ — ดู `AI/knowledge/sidebar-role-gating.md`

## Related commits

- `0fecb3c` — security group gate fix
- (current) — insurance group gate fix + full audit log

## Files touched

- `portal/index.php` (line ~1175 add flag, ~1278 security gate, ~1313 insurance gate + items)
