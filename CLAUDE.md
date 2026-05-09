# RSU Medical Clinic Services — Claude Guidelines

## Coding Conventions

### Tables / Data Grids
- **ทุกครั้งที่สร้างตารางแสดงข้อมูล ต้องทำเป็น Pagination เสมอ**
- ค่า default: **20 รายการ/หน้า**
- ต้องมีปุ่มนำทางครบ: หน้าแรก `«` / ก่อนหน้า `‹` / เลขหน้า (window ±2) / ถัดไป `›` / สุดท้าย `»`
- Pagination ต้องทำงานร่วมกับ search/filter ได้เสมอ
- ใช้ `LIMIT` + `OFFSET` ใน SQL query
- แสดง "หน้า X / Y · รวม N รายการ" เหนือ/ใต้ pagination controls

### Dialogs / Alerts
- **ห้ามใช้ `alert()`, `confirm()`, `prompt()` ของ browser** — ใช้ **SweetAlert2** (`Swal.fire()`) ทุกครั้ง
- SweetAlert2 โหลดอยู่แล้วที่ `portal/index.php` (CDN line ~540) — ใช้ได้ทันทีในทุก partial ของ portal
- ใช้ `await Swal.fire({ ... })` ใน async function และ destructure `{ isConfirmed }` สำหรับ confirm dialog

### Design System
- ใช้ tokens และ component classes จาก `assets/STYLE_GUIDE.md` เสมอ
- **ห้ามเขียน CSS ใหม่ใน user-facing modules** — ถ้า `<style>` ยาวเกิน ~10 บรรทัด ให้เพิ่ม component ใน `assets/css/tailwind.src.css` แทน
- **ห้ามใส่ค่าสี hex ดิบในมาร์กอัป** — ใช้ token (`bg-brand-500`, `text-rose-600`, ฯลฯ)
- หลังแก้ `tailwind.src.css` หรือ `tailwind.config.js` ต้องรัน `npm run build:css` แล้ว commit ไฟล์ output (`assets/css/tailwind.min.css`) ด้วย
- Bottom nav ของ user เป็น shared include — ใช้ `includes/user_bottom_nav.php` ทุกครั้ง อย่า copy markup
- หน้า reference: `user/profile.php`

### Tailwind CSS — Compiled Class Pitfalls
`assets/css/tailwind.min.css` เป็นไฟล์ที่ build ไว้ล่วงหน้า **ไม่รองรับ JIT** — class ที่ไม่ได้ scan จะไม่มีใน output

**Classes ที่ไม่ compile และต้องหลีกเลี่ยง:**
- Arbitrary values: `z-[200]`, `z-[300]`, `max-h-[90vh]`, `flex-[2]`, `text-[10px]`, `max-w-[1400px]` เป็นต้น
- Opacity modifier บน color: `bg-black/40` ❌ → **ข้อยกเว้น:** `bg-black/30`, `bg-black/40`, `bg-black/50`, `bg-black/60` compile แล้ว ✓
- `min-h-0`, `hover:bg-violet-700`, `bg-violet-600` ฯลฯ ที่ไม่ได้ถูก scan

**แนวทางแก้ไขเมื่อต้องใช้ค่าเหล่านี้:**
- เพิ่ม CSS rule ใน `<style>` block ที่มีอยู่แล้วในไฟล์ (portal partials) ผ่าน ID/class selector
- หรือเพิ่ม component ใน `assets/css/tailwind.src.css` แล้ว rebuild
- **ห้ามใช้ `style="..."` attribute สำหรับสีตรง** — ใส่ใน `<style>` block แทน

**Classes ที่ compile แล้วและใช้ได้:**
- Colors: `bg-purple-500/600/700`, `bg-cyan-600/700`, `bg-rose-*`, `bg-emerald-*`, `bg-amber-*`, `bg-slate-*`
- Z-index: `z-10`, `z-20`, `z-50`
- Flex: `flex-1`, `flex-col`, `flex-row`, `shrink-0`
- Overflow: `overflow-y-auto`, `overflow-x-auto`
- Max-width: `max-w-2xl`, `max-w-3xl`, `max-w-4xl`

---

## Architecture Notes

### Portal Admin Partials (`portal/_partials/`)
- โหลดผ่าน `portal/index.php` — มี `portal_CSRF` และ SweetAlert2 พร้อมใช้เสมอ
- AJAX endpoint ส่วนกลาง: `portal/ajax_clinic_master.php` (entity + action pattern)
- AJAX เฉพาะทาง: สร้างไฟล์แยก เช่น `portal/ajax_schedule_import.php`
- ทุก AJAX ต้อง `validate_csrf_or_die()` และรับเฉพาะ POST

### Modal Overlays ใน Portal Partials
- ใช้ `fixed inset-0 bg-black/40` สำหรับ backdrop (compile แล้ว)
- z-index ต้องใส่ใน `<style>` block: `#my-modal { z-index: 200; }`
- `max-height` ของ modal box ต้องใส่ใน `<style>` block: `#my-modal-box { max-height: 90vh; }`
- Scrollable step (flex layout ใน modal): ต้องใส่ `min-height: 0` ใน style block สำหรับ flex child ที่ต้อง overflow

### Doctor Schedule (`sys_doctor_schedule`)
- `type ENUM('regular','override','off')` — regular = ทุกสัปดาห์ (recurring), override = เฉพาะวัน, off = ลา
- `weekday TINYINT NULL` — 0=อาทิตย์ … 6=เสาร์ (NULL สำหรับ override/off)
- `specific_date DATE NULL` — NULL สำหรับ regular
- `recur_end_date DATE NULL` — วันสิ้นสุดการเกิดซ้ำ (NULL = ไม่มีกำหนด)
- **Bug pattern:** `COALESCE($_POST['specific_date'], col)` ที่ `$_POST['specific_date'] = ''` จะ set เป็น `''` ไม่ใช่ NULL — ต้อง normalize ก่อน: `!empty($val) ? $val : null`
- ลบ recurring: 3 ตัวเลือก (กิจกรรมนี้ / นี้และที่ตามมา / ทั้งหมด) ใช้ `Swal.fire({ input: 'radio' })`

### Gemini AI Integration
- API Key: โหลดจาก `GEMINI_API_KEY` constant (ตั้งค่าใน site settings) — fallback ไปอ่าน `config/secrets.php` ตรง
- Model ปัจจุบันที่ใช้งานได้: **`gemini-2.5-flash`** (primary), `gemini-2.0-flash` (fallback)
- `gemini-1.5-flash` และ `gemini-2.0-flash` อาจถูก deprecate — ใช้ `gemini-2.5-flash` เสมอ
- Vision (multimodal image): ส่ง `inlineData` ใน parts พร้อม `mimeType` และ base64 `data`
- Endpoint: `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={apiKey}`
- Import schedule จากรูป: `portal/ajax_schedule_import.php`

### LINE Webhook FAQ (`sys_line_faq_settings`)
- `enabled` — เปิด/ปิด FAQ auto-reply
- `only_when_closed` — ตอบเฉพาะตอนคลินิกปิด (0 = ตอบตลอด)
- Logic อยู่ใน `api/line_webhook.php` — ตรวจ `get_clinic_current_status()` ก่อน reply

### Clinic Status Preview
- `build_clinic_simulated_status(state)` — hardcode ค่าทดสอบ (ห้ามเอาค่าจริงมาใช้ใน production)
- `build_clinic_test_flex(pdo, state)` — override ด้วยเวลาจริงจาก DB ก่อน render preview
- `get_clinic_hours_for_date(pdo, date)` — ดึงเวลาเปิด/ปิดจริงของวันนั้น

### Permissions / Access Flags
- **Roles**:
  - `sys_admins.role` ENUM: `admin` / `editor` / `superadmin` (whitelist บังคับใน `portal/actions/identity_actions.php`)
  - `sys_staff.role` (e-Borrow / Asset / Consumables): whitelist `admin` / `librarian` / `employee` (`editor` คงไว้ใน sub-module check_session เพื่อ legacy)
  - `sys_staff.ecampaign_role` ENUM: `editor` / `admin` / `superadmin`
- **Module access flags บน sys_staff** (TINYINT(1) DEFAULT 0):
  - `access_eborrow` · `access_ecampaign` · `access_insurance` · `access_registry` · `access_system_logs` · `access_site_settings` · `access_edms`
  - `access_ai` · `access_consumables` · `access_asset` (ใหม่)
- **UI จัดการสิทธิ์ที่เป็นทางการ**: `portal/index.php?section=identity` (Identity & Governance) — modal มี audit log + justification ตาม ISO 27001
  - `portal/manage_admins.php` ถูก deprecate แล้ว — redirect ไปหน้า identity
- **Section gate pattern** (portal partials): ตรวจ `$adminRole === 'superadmin' || !empty($_SESSION['access_xxx'])` ก่อน include partial; ถ้าไม่ผ่านให้ render `ACCESS DENIED` block
- **Sub-module gate** (consumables/asset): ตรวจใน `includes/check_session.php` หลัง SSO sync — `is_portal_admin` ผ่านเสมอ, `sys_staff` role admin/editor ผ่าน, role อื่นต้องมี flag = 1
- **เมื่อเพิ่ม access flag ใหม่** ต้องอัปเดต 7 จุด:
  1. สร้าง migration ใน `database/migrations/`
  2. `portal/queries/identity_queries.php` (auto-migrate column + SELECT)
  3. `admin/auth/staff_login.php` (SELECT + `$_SESSION` + check บัญชีไม่มีสิทธิ์ใดๆ)
  4. `portal/actions/identity_actions.php` (POST handler — INSERT/UPDATE)
  5. `portal/index.php` Identity Governance modal: checkbox + JS load/reset + table icon column
  6. `portal/_partials/profile.php` `$accessLabels` array (self-service display)
  7. `portal/index.php` section gate ของ partial นั้นๆ + sidebar nav visibility
