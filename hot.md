# hot.md — Project Snapshot

> **Startup cache สำหรับ AI agent** — อ่านไฟล์นี้เป็นไฟล์แรกของทุก session ก่อนทำงานใหม่
> เป้าหมาย: ให้ agent เข้า working mode ได้ภายในไม่กี่วินาที โดยไม่ต้อง warm-up นาน
>
> **Update คู่กับ commit สำคัญทุกครั้ง** — ถ้าเปลี่ยน phase / ปิด task ใหญ่ / decision สำคัญ → แก้ที่นี่ทันที
> ใช้ภาษาธรรมชาติ ไม่ใช่ checklist — เขียนให้คนใหม่อ่านแล้วเข้าใจสถานะภายใน 60 วินาที

---

## Project ใจความ
**RSU Medical Clinic Services** — ระบบบริหารคลินิกแพทย์ของมหาวิทยาลัยรังสิต ครอบคลุม
ประกันสุขภาพ · นัดหมายแพทย์ · ครุภัณฑ์/วัสดุสิ้นเปลือง (e_Borrow + asset + consumables) · การเงิน (Cash Book) · LINE webhook · AI assistant (Gemini) · ISO 27001 audit · EDMS

Stack: PHP 8 + MySQL (PDO singleton) · Tailwind compiled CSS (no JIT) · vanilla JS · Chart.js · SweetAlert2 · Driver.js (tour) · Font Awesome

---

## สถานะปัจจุบัน (พฤษภาคม 2026)

### Phase ที่จบล่าสุด
- **Daily Summary (สรุปงานประจำวัน)** — single-page read-only dashboard รวมข้อมูลจาก 4 module ใน 1 หน้า (Productivity ต่อ dept · Cash Book income/expense + top categories · Stock movement + low-stock alerts · Other: gold card / insurance / asset / docs / schedule วันนี้) · date navigator (‹ › วันนี้) · period delta vs เมื่อวาน · A4 print · 7-spot flag `access_daily_summary` ครบ
- **Nurse Productivity (OPD)** — multi-tenant per `sys_departments` · 3-tab UI (Dashboard/Entry/Settings) · daily/monthly/yearly views · period delta · cross-dept comparison · Excel I/O (PhpSpreadsheet) · A4 print · auto-derive RN/หัวหน้า จาก `sys_nurse_schedule_monthly` เมื่อช่องว่าง · 7-spot access flag `access_nurse_productivity` ครบ
- **Context Persistence** — `hot.md` + `AI/` folder (logs/knowledge/scratch)
- **Finance / Cash Book** ครบ 3 phase (A: search/quick-dates/CSV/bulk · B: chart/donut/period delta · C: recurring/attachments/audit log) + dark mode pass สมบูรณ์
- **Bold & Colorful redesign** — sidebar section accents, rainbow header strip, hover micro-interactions (`--lift` var pattern), `RsuFx` library (counters/tilt/skeleton)
- **e_Borrow shell skin** — portal-matching glassmorphism + finance bridge สำหรับค่าปรับ → Cash Book
- **Inventory shared locations** — `asset/admin/manage_locations.php` ใช้ร่วมกันระหว่าง asset + consumables

### กำลังทำอยู่
- รอ migration `database/migrations/migrate_nurse_productivity.php` รันใน production · user test Nurse Productivity module · ผูก position พยาบาลใน sys_staff_positions ให้ติด flag `access_nurse_productivity` อัตโนมัติ

### Decision ล่าสุดที่ต้องจำ
- **Nurse Productivity → Schedule integration**: ตอน save daily ถ้าช่อง RN/หัวหน้าว่าง → derive จาก `sys_nurse_schedule_monthly` + `sys_nurse_schedule_global.nurses_json` แล้ว stamp `rn_source='schedule'`/`head_source='schedule'`. Position mapping: `พยาบาลวิชาชีพ` → RN, `หัวหน้าหอผู้ป่วย`/`รองหัวหน้าหอผู้ป่วย`/`พยาบาลหัวหน้าเวร` → head. Schedule เป็น singleton ใช้ร่วมทุก dept (ตามการตัดสินใจ)
- **Gemini model** ใช้ `gemini-2.5-flash` (primary) เท่านั้น — 1.5/2.0 ใกล้ deprecate
- **Modal pattern** ใช้ Portal-Escape (teleport ไป `<body>` + z-index ≥ 9000) ทุกครั้งที่ modal อาจติด ancestor stacking context — reference: `portal/_partials/gold_card.php`
- **Cross-module finance sync** ห้ามเรียก `portal/ajax_finance.php` ตรงจาก sub-module → ใช้ bridge `e_Borrow/admin/ajax_finance_sync.php` pattern เสมอ
- **Dark mode** — surface ที่ใช้ raw `background:#fff` ต้องมี override ที่ `body[data-theme='dark']` (portal) หรือ `body.dark-mode` (e_Borrow); utility class `.bg-white` มี global override แล้ว

### ข้อห้าม / ของอันตราย
- ห้ามใช้ arbitrary Tailwind values (`z-[200]`, `max-h-[90vh]`) — ไม่ compile · ใช้ `<style>` block แทน
- ห้ามใช้ `alert()/confirm()/prompt()` — ใช้ `Swal.fire()` (SweetAlert2 โหลดอยู่ที่ `portal/index.php` line ~540)
- ห้ามใส่เมนูลอยใน portal sidebar — ต้องอยู่ใน 12 section ที่กำหนด (ดู CLAUDE.md "Portal Sidebar")
- ห้าม commit secrets — `config/secrets.php` อยู่ใน `.gitignore`
- ตารางข้อมูลทุกตัวต้องมี pagination 20/หน้า + ปุ่ม `« ‹ … › »`

---

## Quick Reference (paths ที่เปิดบ่อย)

| ต้องการ | ดูที่ |
|---|---|
| Portal shell + section routing | `portal/index.php` |
| Portal partial ใหม่ | `portal/_partials/<name>.php` |
| Portal AJAX กลาง | `portal/ajax_clinic_master.php` (entity+action) |
| Finance AJAX | `portal/ajax_finance.php` |
| Cross-module finance sync | `includes/finance_sync_helper.php::finance_sync_upsert()` |
| Design tokens / components | `assets/STYLE_GUIDE.md` + `tailwind.config.js` |
| Tailwind source (rebuild!) | `assets/css/tailwind.src.css` → `npm run build:css` |
| FX library (counter/tilt/skeleton) | `assets/js/rsu-fx.js` (`window.RsuFx`) |
| Guided tour | `assets/js/rsu-tour.js` (`RsuTour.maybeAutoStart(key, steps)`) |
| Access flags / roles | CLAUDE.md → "Permissions / Access Flags" (7-spot checklist) |
| LINE webhook | `api/line_webhook.php` |
| Doctor schedule | `sys_doctor_schedule` (regular/override/off) |
| Asset locations (shared) | `asset/admin/manage_locations.php` |
| e_Borrow shell | `e_Borrow/includes/header.php` + `e_Borrow/assets/css/eb-skin.css` |

---

## Workflow recap
1. อ่าน `hot.md` (ไฟล์นี้) + `CLAUDE.md` ทุก session
2. ก่อนสร้างของใหม่ — เช็คว่ามี token / component / helper เดิมรองรับอยู่ไหม
3. หลัง commit ใหญ่ → อัพเดต `hot.md` (Phase / Decision / กำลังทำ) + drop note ใน `AI/logs/YYYY-MM-DD-<topic>.md` ถ้า decision สำคัญ
4. ของชั่วคราว / draft → `AI/scratch/` (จะถูก clean เป็นระยะ)
5. Distilled knowledge ถาวร → `AI/knowledge/` (เช่น schema notes, troubleshooting recipes)

ดู `AI/README.md` สำหรับ convention เต็ม
