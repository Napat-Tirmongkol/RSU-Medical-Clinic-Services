# RSU Medical Clinic Services — Claude Guidelines

## Context Persistence (AI Memory) — อ่านก่อนเสมอ

ทุก session ของ AI agent (Claude Code / Codex / Cursor) **ต้องอ่าน 2 ไฟล์เป็นอันดับแรก**:

1. **`hot.md`** (root) — project snapshot ปัจจุบัน · phase ที่กำลังทำ · decision ล่าสุด · ของกำลังค้าง
   อัพเดตคู่กับ commit สำคัญทุกครั้ง (เปลี่ยน phase / ปิด task ใหญ่ / decision ใหม่)
2. **`CLAUDE.md`** (ไฟล์นี้) — กฎและ convention ถาวรของโปรเจกต์

### โครงสร้าง `AI/` folder (long-term memory)
```
AI/
├── README.md      ← convention เต็ม
├── logs/          ← episodic memory — append-only decision records (YYYY-MM-DD-<topic>.md)
├── knowledge/     ← semantic memory — distilled pattern/recipe/schema (curated, แก้ได้)
└── scratch/       ← ephemeral workspace — clean ได้เป็นระยะ
```

### Workflow
1. **เริ่ม session** → อ่าน `hot.md` + `CLAUDE.md` → search `AI/knowledge/` ถ้าทำ topic เฉพาะ
2. **ระหว่างทำงาน** → reference `AI/logs/` ถ้าต้องเข้าใจ decision เก่า
3. **จบ task ใหญ่** → อัพเดต `hot.md` (Phase / Decision) + drop `AI/logs/YYYY-MM-DD-<topic>.md` ถ้ามี decision สำคัญ
4. **เจอ pattern ที่ใช้ซ้ำ ≥ 2 ครั้ง** → เขียน `AI/knowledge/<topic>.md` (ถ้ากลายเป็น "ห้ามลืม" สำหรับทุก agent → promote ขึ้น CLAUDE.md)
5. **Draft / exploration ชั่วคราว** → `AI/scratch/` (อย่าเก็บของสำคัญที่นี่)

### กติกาสำคัญ
- **`AI/logs/` append-only** — ห้ามแก้ไฟล์เก่า เขียนไฟล์ใหม่ reference ของเก่าแทน (audit trail)
- **CLAUDE.md = กฎถาวร** (โปรเจกต์ทั้งโปรเจกต์); **hot.md = สถานะปัจจุบัน** (เปลี่ยนบ่อย)
- ห้าม commit secrets / personal data ใน `AI/`
- ดูรายละเอียดเต็มที่ `AI/README.md`

---

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

#### Tokens ที่ extend แล้วใน `tailwind.config.js` (ใช้ได้ทันทีหลัง build)
- **Colors**:
  - `brand-{50..900}` — primary clinic green (`brand-500 = #2e9e63` คือ canonical)
  - `info-{50,100,500,600,700}` — admin blue
  - `accent-{50,100,400,500,600,700}` — fuchsia (สำหรับ "bold & colorful" accents)
  - `canvas` (#F8FAFF) — page bg
- **Shadows**:
  - `shadow-glow-brand` / `glow-info` / `glow-accent` / `glow-amber` / `glow-rose` — colored glows สำหรับ primary CTA
  - `shadow-lift` — generic deep hover shadow
  - `shadow-card` / `card-lg` — card surface shadows
- **Background gradients**:
  - `bg-brand-gradient` (เขียว) / `bg-sunset-gradient` (ส้ม-ชมพู) / `bg-royal-gradient` (ม่วง-ชมพู) / `bg-ocean-gradient` (ฟ้า-เทอร์คอยซ์)
- **Components** (component layer ใน `tailwind.src.css`):
  - `.ds-btn` + `.ds-btn-primary` / `-ghost` / `-danger` / `-info` / `-accent` / `-sunset` — buttons พร้อม hover lift
  - `.ds-card` / `.ds-card-lg` / `.ds-card-soft` / `.ds-card-accent` (มี top accent stripe)
  - `.ds-input` — form input
  - `.ds-pill-brand/amber/rose/slate/info/accent` — badges
  - `.ds-section-title` — heading พร้อม gradient bar ด้านหน้า
  - `.ds-kpi-tile` (มี `data-tone="info|amber|rose|accent|ocean"` ปรับ accent stripe)
  - `.btn-solid` — generic toolbar button (combine กับ utility color เช่น `bg-amber-500 text-white`)

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

## Visual System — "Bold & Colorful" (current direction)

หน้าใหม่/redesign ทุกครั้งให้ตามแนวทางนี้ — เก็บ brand-green เป็นแกนหลัก ปล่อยให้ section / KPI / ปุ่มหลัก มีสีสันเด่นกว่าเดิม

### Portal Sidebar — section accent colors
แต่ละกลุ่มใน sidebar มีสีประจำตัว (กำหนดที่ `.psb-group[data-group="X"]` ใน `portal.css`):
- `overview` เขียว · `ai` ม่วง · `line` LINE-green (#06c755) · `security` ชมพู-แดง · `insurance` ฟ้า · `comm` ส้ม
- `inventory` เทอร์คอยซ์ · `finance` ฟูเชีย · `monitor` cyan · `reports` indigo · `docs` slate
- `masterdata` cyan-dark · `settings` slate-dark
- เมนูใหม่ใน group → ใช้สีของ group นั้นเป็น icon color (ไอคอน FA inline `style="color:#XXX"` ให้ตรง accent)

### Portal Header — rainbow accent
`.portal-header::before` มีแถบ gradient 6 สีเลื่อนช้าๆ ตลอด (animation `hdrShimmer 14s`). ถ้าทำ shell ใหม่ของ sub-module ให้ตามแนวนี้ (ตัวอย่าง: `e_Borrow/assets/css/eb-skin.css` → `.header::before`)

### Hover micro-interactions (มาตรฐาน)
- การ์ดที่ interactive: ยกลอย `-2/-3px` + colored glow shadow + border-color เปลี่ยนเป็น accent
- **ใช้ `--lift` CSS variable** บน `:hover` แทนการเขียน `transform: translateY` ตรงๆ — เพื่อให้ compose กับ `.fx-tilt` ได้:
  ```css
  .my-card:hover:not(.fx-tilt) { transform: translateY(-3px); }
  .my-card.fx-tilt:hover { --lift: -3px; }
  ```
- ปุ่มหลัก (CTA): `.ds-btn-primary` มี gradient + glow-brand + hover lift 0.5
- Icon ใน card ทำ `transform: scale(1.08) rotate(-4deg)` ตอน parent hover

### Animation timing
- **Easing curves**:
  - `cubic-bezier(.16,1,.3,1)` — entrance / card lift (snappy + overshoot-feel)
  - `cubic-bezier(.4,0,.2,1)` — sidebar collapse / material-style
- **Durations**: hover 0.15-0.25s · entrance 0.35-0.55s · stagger delay 0.08-0.16s ต่อ item
- **เคารพ `prefers-reduced-motion: reduce`** ทุกครั้ง — disable transform/animation/glow follow

---

## FX Library — `assets/js/rsu-fx.js`

โหลดที่ portal/e_Borrow shell (เป็น `<script defer>` ใน header). `window.RsuFx` มี API พร้อมใช้:

### 1. Number CountUp (IntersectionObserver-driven)
```html
<span data-counter="12345">0</span>
<!-- options ผ่าน data-attrs: -->
<span data-counter="3.14" data-counter-decimals="2" data-counter-duration="800">0</span>
```
- เริ่มนับเมื่อ scroll เข้า viewport เท่านั้น (threshold 35%)
- ใส่ใน wrap span ของตัวเลขถ้ามี suffix เช่น `<span data-counter="..."></span><span>/200</span>` — counter จะไม่ทำลาย sub element
- Auto-init บน DOMContentLoaded + รันซ้ำด้วย `RsuFx.refresh(rootEl)` หลัง insert markup ใหม่

### 2. Tilt + Cursor-Following Glow
```html
<div class="fx-tilt fx-tilt-light" data-tilt="5"> ... </div>
<!-- variants: fx-tilt-dark | fx-tilt-light | fx-tilt-accent | fx-tilt-info -->
```
- `data-tilt="N"` คือ max rotation degree (default 6)
- ใช้กับ `.dash-kpi`, `.proj-card`, `.priority-item`, `.pinned-row` แล้ว
- **CSS ภายในใช้ `isolation: isolate` กัน ::after glow ไปทับ position absolute ของลูก** (เช่น `.pin-btn`)
- Auto-disabled บน touch (`@media (hover: none)`) และ reduced-motion

### 3. Skeleton Loading
```js
RsuFx.skeleton('#my-table', { variant: 'table', rows: 5 });
// fetch...
RsuFx.unskeleton('#my-table', newHtml);   // หรือ unskeleton(el) เพื่อ restore เดิม
```
- 3 variants: `rows` (เส้นยาวๆ) · `cards` (กริด) · `table` (แถวพร้อม avatar+col)
- หรือใช้ class ตรงๆ: `<div class="skel skel-line w-60"></div>` (width helpers: `w-20/30/40/60/80`)
- Shimmer keyframe หยุดเองตอน `prefers-reduced-motion`

---

## Dark Mode Patterns

ระบบใช้ 2 selector แยกตามฝั่ง:
- **Portal**: `body[data-theme='dark']` (เปลี่ยนผ่าน data-attribute)
- **e_Borrow**: `body.dark-mode` (legacy class — ยังเก็บไว้)

### กฎสำคัญที่ทำพังบ่อย
1. **ห้ามใช้ inline `style="background:#fff"` หรือ raw `background:#fff` ใน CSS** — global dark override จับเฉพาะ `.bg-white` (Tailwind utility class) เท่านั้น พอเขียน raw bg surface จะติดขาวค้างในธีมมืด
2. **ถ้าจำเป็นต้องใช้ raw bg** ให้ใช้ `data-tone` attribute แทน inline style:
   ```html
   <div class="my-tile" data-tone="income"> ... </div>
   ```
   ```css
   .my-tile[data-tone="income"] { background:#f0fdf4; }
   body[data-theme='dark'] .my-tile[data-tone="income"] { background:rgba(46,158,99,.15); }
   ```
3. **เมื่อสร้าง class ใหม่ที่มี surface ขาว** (`.fin-card`, `.dash-panel` ฯลฯ) ต้องเพิ่ม `body[data-theme='dark'] .my-class` override ที่ portal.css หรือใน `<style>` ของ partial เอง
4. **Text colors ที่ใช้ `text-slate-*` utility** มี global dark override แล้ว — แต่ถ้าเขียน raw `color: #0f172a` ต้อง override เอง
5. **Reference implementation** — ดูที่ `portal/_partials/finance.php` (section "DARK MODE" ใน `<style>`) ครอบคลุม card / kpi tones / filter inputs / table / pagination

### Charts (Chart.js) — theme-aware pattern
```js
function chartTheme() {
    const dark = document.body.getAttribute('data-theme') === 'dark';
    return {
        tick:   dark ? '#cbd5e1' : '#64748b',
        grid:   dark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)',
        legend: dark ? '#e2e8f0' : '#334155',
        border: dark ? '#1e293b' : '#fff',   // donut slice borders
    };
}
// ใน options pass: scales.x.ticks.color, scales.y.ticks.color/grid.color, plugins.legend.labels.color

// ฟัง theme toggle แล้ว re-render ทั้ง chart ทันที (ไม่ต้องโหลด data ใหม่):
new MutationObserver(muts => {
    for (const m of muts) {
        if (m.attributeName === 'data-theme') { renderMonthlyChart(cached); renderCategoryChart(cached); break; }
    }
}).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });
```

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

#### ⚠️ Bug pattern — modal "ติด" อยู่ใน content area (sidebar/topbar ไม่โดน backdrop)
**อาการ:** เปิด modal แล้ว
- ไม่อยู่กลางจอ (centered ที่ content area แทน viewport)
- Sidebar / topbar ไม่ถูก darken (backdrop ครอบเฉพาะพื้นที่ทางขวา)
- Stat badge / element ของ section ลอดออกมาเหนือ modal box

**สาเหตุ:** `position:fixed` ถูก trap โดย ancestor ที่สร้าง **containing block ใหม่** — เกิดจาก property ใดก็ได้ในห่วงโซ่ ancestor:
- `transform` (รวม `translateY(0)` ที่หลงเหลือจาก `animation-fill-mode: both`)
- `filter`, `backdrop-filter` (เช่น `.portal-header` มี `backdrop-filter: blur(12px)`)
- `perspective`
- `contain: layout/paint/strict/content`
- `will-change: transform/filter/...`

นอกจากนั้น z-index ที่ต่ำเกิน (200) ก็อาจโดน sibling stacking contexts บัง (เช่น `ann-form-modal` z-index 999)

**วิธีแก้ — Portal-Escape Pattern (มาตรฐานของโปรเจกต์):**
1. **Teleport modal ไป `<body>`** ตอนเปิดครั้งแรก → anchor `position:fixed` กับ viewport แน่ๆ:
   ```js
   function myTeleport(id) {
       const el = document.getElementById(id);
       if (el && el.parentElement !== document.body) document.body.appendChild(el);
       return el;
   }
   function openMyModal() {
       const m = myTeleport('myModal');
       m.classList.remove('hidden'); m.classList.add('flex');
   }
   ```
2. **CSS selector ห้าม scope ใต้ `#section-xxx`** — หลัง teleport จะหายหมด:
   ```css
   /* ❌ ห้าม */
   #section-foo .my-modal { z-index: 200; }
   /* ✓ ใช้ */
   .my-modal { z-index: 9000 !important; }
   ```
3. **z-index ขั้นต่ำ 9000** สำหรับ user-facing modal (สูงกว่า ann-form-modal 999, priv modals 500, header 40, sidebar 30)
4. **Backdrop fallback ใน CSS ตรงๆ** กันเคส Tailwind compile หาย:
   ```css
   .my-modal {
       background: rgba(15, 23, 42, 0.55) !important;
       backdrop-filter: blur(6px);
       -webkit-backdrop-filter: blur(6px);
   }
   ```

**Reference implementation:** `portal/_partials/gold_card.php` (gcBulkModal + gcMemberModal) — ใช้ `gcTeleport()` helper

### Doctor Schedule (`sys_doctor_schedule`)
- `type ENUM('regular','override','off')` — regular = ทุกสัปดาห์ (recurring), override = เฉพาะวัน, off = ลา
- `weekday TINYINT NULL` — 0=อาทิตย์ … 6=เสาร์ (NULL สำหรับ override/off)
- `specific_date DATE NULL` — NULL สำหรับ regular
- `recur_end_date DATE NULL` — วันสิ้นสุดการเกิดซ้ำ (NULL = ไม่มีกำหนด)
- **Bug pattern:** `COALESCE($_POST['specific_date'], col)` ที่ `$_POST['specific_date'] = ''` จะ set เป็น `''` ไม่ใช่ NULL — ต้อง normalize ก่อน: `!empty($val) ? $val : null`
- ลบ recurring: 3 ตัวเลือก (กิจกรรมนี้ / นี้และที่ตามมา / ทั้งหมด) ใช้ `Swal.fire({ input: 'radio' })`

### Document Library (`portal/_partials/documents.php` + `docs/`)
- **คลังเอกสาร** สำหรับ proposal, ISO docs, รายงานเชิงนโยบาย, คู่มือ — แสดงผ่าน sidebar กลุ่ม "เอกสาร"
- รายการเอกสารกำหนดในตัวแปร `$documents = [...]` ใน `documents.php` — เพิ่มเอกสารใหม่โดย append array entry
- แต่ละ entry มี `access` array (เช่น `['superadmin','admin']`) — partial จะ filter อัตโนมัติตาม `$adminRole`
- เอกสารจริง (HTML print-ready) อยู่ใน `docs/*.php` — **ต้อง guard ด้วย session check** เพราะอาจมีข้อมูลงบประมาณ:
  ```php
  $adminRole = $_SESSION['admin_role'] ?? '';
  if ($adminRole !== 'superadmin' && $adminRole !== 'admin') {
      http_response_code(403); exit('Access Denied');
  }
  ```
- เอกสาร HTML print-ready ควรมีปุ่ม Print + ดาวน์โหลด PDF (html2pdf.js) + ดาวน์โหลด .doc (Blob+msword MIME)
- ตัวอย่าง: `docs/user_hub_proposal.php` — เอกสารเสนอโครงการ user-side

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

### Finance Module (Cash Book) — `portal/_partials/finance.php`
- **Schema** (auto-migrated ใน `ensure_finance_schema()`):
  - `sys_finance_categories` (id, name, kind ENUM income/expense, icon, color, sort_order, is_active)
  - `sys_finance_transactions` (id, txn_date, kind, category_id FK, amount DECIMAL(12,2), description, reference, payment_method, note, **receipt_no UNIQUE**, **source_module**, **source_id**, created_by, updated_by, timestamps)
  - `sys_finance_recurring` — template ค่าใช้จ่ายประจำเดือน (name, kind, category_id, amount, day_of_month 1-28, active, **last_generated_ym**)
  - `sys_finance_attachments` — ไฟล์แนบต่อ txn (stored_name, original_name, mime_type, size_bytes)
  - `sys_finance_audit` — append-only history (action, changes_json, performed_by_name, ip_addr)
- **AJAX endpoint**: `portal/ajax_finance.php`
  - List/CRUD: `list` (GET, รวม summary + attachment_count) · `analytics` (monthly trend + category breakdown + period delta) · `txn:create/update/delete/bulk_delete` · `txn:assign_receipt` · `txn:upsert_from_source` · `txn:check_duplicate` · `category:create/update/toggle/delete`
  - Phase C actions: `recurring:list/create/update/delete/toggle/run` · `audit:list?txn_id=NN` · `attachment:list/upload/delete`
- **Helper endpoints**:
  - `portal/finance_receipt.php?id=NN` — A4 print-friendly + bahtText() helper. Format: `RCP-{พ.ศ.}-{6 หลัก}` รายได้, `PV-{พ.ศ.}-{6 หลัก}` รายจ่าย — atomic running number
  - `portal/finance_export.php?from=...&to=...&kind=...&category_id=...&q=...` — CSV download (UTF-8 BOM สำหรับ Excel) + summary footer
  - `portal/finance_attachment.php?id=NN[&download=1]` — auth-gated proxy stream ไฟล์แนบ (validation: realpath ต้องอยู่ใต้ `uploads/finance/`)
- **Cross-module integration** (`txn:upsert_from_source`): โมดูลอื่นๆ ส่งยอดเข้า Cash Book → idempotent ด้วยคีย์ `(source_module, source_id)`
  - `nurse_schedule` → OT รายเดือน (source_id = yearBE×100+month)
  - `scholarship` → ค่าตอบแทนนักศึกษาทุนรายเดือน
  - `asset` → จัดซื้อครุภัณฑ์ต่อชิ้น (source_id = asset.id)
  - `consumables_txn` → รับเข้าวัสดุต่อ transaction
  - `eborrow_payment` → ค่าปรับยืมเกินกำหนด (income) — มี e_Borrow-side bridge `e_Borrow/admin/ajax_finance_sync.php` สำหรับ user ที่ไม่มี portal admin session
  - `finance_recurring` → auto-generate รายเดือนตอน `list` (idempotent ด้วย last_generated_ym)
- **Recurring auto-generation**: `fin_run_recurring()` รันทุกครั้งที่กด list — สร้าง txn สำหรับ rule ที่ active + ถึงวัน + ยังไม่ generate เดือนนี้ → INSERT แล้ว stamp `last_generated_ym='YYYY-MM'` (day_of_month cap 1-28 กัน Feb)
- **Attachment storage**:
  - ไฟล์เก็บที่ `uploads/finance/{YYYY}/{MM}/{hashedname}.{ext}` — stored_name เป็น random hex 12 ไบต์
  - อัปโหลดครั้งแรกจะ drop `.htaccess` deny-all ที่ `uploads/finance/` root กัน direct URL access
  - รองรับ JPG/PNG/WEBP/GIF/PDF ≤ 8MB · mime check ด้วย `mime_content_type()` ไม่ใช่ trust `$_FILES['type']`
- **Audit log**: `fin_audit_log()` ถูก hook ลง `txn:create/update/delete/bulk_delete` + `attach_add/remove` + `recurring_generate` — capture before-state ก่อน destroy / field-diff สำหรับ update
- **UI patterns** ที่ใช้บ่อย:
  - Quick-date chips: `วันนี้ · เดือนนี้ · เดือนก่อน · 3 เดือนล่าสุด · ปีนี้ · ปีที่แล้ว · ทั้งหมด` (chip class `.fin-chip`, `.is-active` เป็น gradient)
  - Search box — debounce 350ms ใน description / reference / receipt_no
  - Bulk select + sticky bottom action bar (`.fin-bulk-bar` slide-up เมื่อ selectedIds.size > 0)
  - Duplicate detection — debounce probe ใน "อ้างอิง" field ของ create/edit modal
  - Period delta บน KPI tiles — เทียบ same-length prior period (`▲/▼ NN%`)
  - Charts (Chart.js): bar 12 เดือน + donut top-10 categories (theme-aware ผ่าน `chartTheme()`)
  - Per-row details modal: tabs ไฟล์แนบ (drag-drop upload) + ประวัติ audit timeline
- **Default categories** seeded 11 ตัว — รายได้ 5 + รายจ่าย 6 ลบไม่ได้ถ้ามีรายการอ้างอิง

### e_Borrow Module — portal-matching skin + cross-module bridges
- **Shell skin**: `e_Borrow/assets/css/eb-skin.css` โหลดหลัง `style.css` ใน `e_Borrow/includes/header.php` — re-paint `.header` เป็น brand-green glassmorphism + rainbow strip ด้านบน, ปรับ `.btn-logout` / `.theme-toggle-btn` / `.user-info` ให้แมตช์ portal (icon + user pill + danger pill style)
- **Dark mode**: ใช้ `body.dark-mode` (legacy class) — เขียน override ใน `eb-skin.css` ด้วย selector นี้ ไม่ใช่ `body[data-theme]`
- **FX library**: `rsu-fx.js` โหลดผ่าน `<script defer>` ใน `header.php` ของ e_Borrow → ทุกหน้าใช้ `data-counter` / `.fx-tilt` / `RsuFx.skeleton` ได้ทันที
- **Cross-module bridge**: `e_Borrow/admin/ajax_finance_sync.php` — endpoint ฝั่ง e_Borrow สำหรับส่งค่าปรับเข้า Cash Book โดยใช้ e_Borrow session check (ไม่ต้อง portal admin login) แล้ว delegate ไป `includes/finance_sync_helper.php::finance_sync_upsert()`. **ใช้ pattern นี้ทุกครั้งเมื่อ sub-module ต้องเขียนข้อมูลข้ามไป portal** — อย่าเรียก `portal/ajax_finance.php` ตรง (จะโดน `check_admin_session` redirect เป็น HTML)
- **Path gotcha**: `<base href="/.../e_Borrow/">` ใน header.php → relative path ต้องนับจาก `e_Borrow/` ไม่ใช่จากไฟล์ปัจจุบัน. เช่นจาก `e_Borrow/admin/manage_fines.php` → `../portal/index.php` (ไม่ใช่ `../../portal/index.php` ที่ resolve นอก deploy root)

### Inventory Modules (Asset + Consumables) — ใช้ตาราง `asset_locations` ร่วมกัน
- **Sidebar group**: Portal sidebar กลุ่ม "คลังพัสดุ" (icon `fa-warehouse`) — ลิงก์ออกไป `/asset/` และ `/consumables/`
- **Module switcher tabs**: `<header>` ของทั้ง 2 โมดูล มี tab strip "ครุภัณฑ์ ↔ วัสดุสิ้นเปลือง" (sticky บนสุด) — สลับได้ใน 1 คลิก
- **Shared location management**: `asset/admin/manage_locations.php` ใช้ร่วมกัน → consumables sidebar มีเมนู "จุดจัดเก็บ" ลิงก์ตรงไปหน้านี้ผ่าน `<base href>` resolution

### Guided Tour (Driver.js) — `assets/js/rsu-tour.js`
- CDN: `driver.js@1.3.1` (`dist/driver.css` + `dist/driver.js.iife.js`) ผ่าน jsdelivr
- API: `RsuTour.maybeAutoStart(areaKey, steps)` — auto-start ครั้งแรกที่เปิด · `RsuTour.start(steps, areaKey)` — บังคับเริ่ม · `RsuTour.reset(areaKey)` — ลบ flag
- localStorage key: `tour_done_<area>` (area: `portal` / `user_hub` / `asset` / `consumables`)
- กรอง steps ที่ selector ไม่พบอัตโนมัติ — กัน null reference
- ใช้ใน 4 entry pages: `portal/index.php`, `user/hub.php`, `asset/index.php`, `consumables/index.php` — มีปุ่ม `?` มุมขวาล่าง (bottom:20px, right:20px; z-index:90) สำหรับเรียก tour ซ้ำ
- user/hub.php FAB อยู่ที่ `bottom:84px` เพื่อกัน bottom nav

### Permissions / Access Flags
- **Roles**:
  - `sys_admins.role` ENUM: `admin` / `editor` / `superadmin` (whitelist บังคับใน `portal/actions/identity_actions.php`)
  - `sys_staff.role` (e-Borrow / Asset / Consumables): whitelist `admin` / `librarian` / `employee` (`editor` คงไว้ใน sub-module check_session เพื่อ legacy)
  - `sys_staff.ecampaign_role` ENUM: `editor` / `admin` / `superadmin`
- **Module access flags บน sys_staff** (TINYINT(1) DEFAULT 0):
  - `access_eborrow` · `access_ecampaign` · `access_insurance` · `access_registry` · `access_system_logs` · `access_site_settings` · `access_edms`
  - `access_ai` · `access_consumables` · `access_asset` · `access_finance` (ใหม่)
- **UI จัดการสิทธิ์ที่เป็นทางการ**: `portal/index.php?section=identity` (Identity & Governance) — modal มี audit log + justification ตาม ISO 27001
  - `portal/manage_admins.php` ถูก deprecate แล้ว — redirect ไปหน้า identity
- **Section gate pattern** (portal partials): ตรวจ `$adminRole === 'superadmin' || !empty($_SESSION['access_xxx'])` ก่อน include partial; ถ้าไม่ผ่านให้ render `ACCESS DENIED` block
  - **หมายเหตุ:** `$adminRole` มีเฉพาะใน scope ของ `portal/index.php` — สำหรับ partial ที่อาจถูกเข้าตรงๆ (เช่น `portal/nurse_schedule.php`) ให้คำนวณ local: `$_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? ''` แล้วใช้ `$_role` แทน
- **Sub-module gate** (consumables/asset): ตรวจใน `includes/check_session.php` หลัง SSO sync — `is_portal_admin` ผ่านเสมอ, `sys_staff` role admin/editor ผ่าน, role อื่นต้องมี flag = 1
- **เมื่อเพิ่ม access flag ใหม่** ต้องอัปเดต 7 จุด:
  1. สร้าง migration ใน `database/migrations/`
  2. `portal/queries/identity_queries.php` (auto-migrate column + SELECT)
  3. `admin/auth/staff_login.php` (SELECT + `$_SESSION` + check บัญชีไม่มีสิทธิ์ใดๆ)
  4. `portal/actions/identity_actions.php` (POST handler — INSERT/UPDATE)
  5. `portal/index.php` Identity Governance modal: checkbox + JS load/reset + table icon column
  6. `portal/_partials/profile.php` `$accessLabels` array (self-service display)
  7. `portal/index.php` section gate ของ partial นั้นๆ + sidebar nav visibility

---

## Portal Sidebar — กฎการจัดกลุ่มเมนู (`portal/index.php`)

Sidebar ใช้ `psb-section-label` (กลุ่ม) + `psb-item` (เมนู) — โครงสร้างแบ่งเป็น 11 sections **ห้ามเพิ่มเมนูลอยๆ** ทุกเมนูใหม่ต้องเข้ากลุ่มที่มีอยู่ หรือเพิ่ม section ใหม่ที่ตำแหน่งเหมาะสม

### ลำดับ section (จากบนลงล่าง)
1. **OVERVIEW** (icon: `fa-chart-line`) — Dashboard, โปรไฟล์ของฉัน
2. **AI Suite** (icon: `fa-wand-magic-sparkles`) — AI Assistant, ผู้ช่วยข้อมูล, AI QA Lab, AI Prompts, AI Knowledge — ต้อง gate ด้วย `access_ai`
3. **LINE Official** (icon: `fa-brands fa-line`, data-group: `line`) — LINE Chat (กล่องแชต LINE OA) — gate `access_ai` เช่นกัน (LINE Chat ใช้ AI ช่วย reply)
4. **สิทธิ์ & ความปลอดภัย** (icon: `fa-shield-halved`) — Identity & Governance, ISO Governance
5. **ประกันสุขภาพ** (icon: `fa-hospital-user`) — Insurance Hub, อัพโหลดรายชื่อ, สถานะเอกสาร, Insurance Partners
6. **สื่อสาร** (icon: `fa-bullhorn`) — ประกาศ, สารบรรณอิเล็กทรอนิกส์ (EDMS)
7. **คลังพัสดุ** (icon: `fa-warehouse`) — ครุภัณฑ์สำนักงาน (link ออกไป `/asset/`), วัสดุสิ้นเปลือง (link ออกไป `/consumables/`)
8. **การเงิน** (icon: `fa-money-bill-trend-up`) — Cash Book (รายรับ-รายจ่าย, หมวด, สรุป) — gate `superadmin || role=admin || access_finance`
9. **ติดตามระบบ** (icon: `fa-binoculars`) — Activity Dashboard (superadmin), Activity Logs, Error Logs
10. **รายงาน** (icon: `fa-clipboard-list`) — รายงานประจำเดือน — gate `superadmin || access_monthly_report || access_director_view`
11. **เอกสาร** (icon: `fa-folder-tree`, data-group: `docs`) — คลังเอกสาร (proposal, ISO, รายงาน, คู่มือ) — gate `superadmin || admin`
12. *(spacer `<div style="flex:1"></div>`)*
13. **ข้อมูลหลัก** (icon: `fa-database`) — ข้อมูลคลินิก, นักศึกษาทุน, Master Data อื่นๆ
14. **ตั้งค่า** (icon: `fa-gear`) — Settings (อยู่ล่างสุดเสมอ)

### กติกาเมื่อเพิ่มเมนูใหม่
1. **เลือก section ที่ตรงหน้าที่** — ห้ามใส่ใน OVERVIEW เป็น default
   - งานเกี่ยวกับ AI/LLM (chatbot, prompt, knowledge base) → **AI Suite**
   - งานเกี่ยวกับ LINE OA (chat, rich menu, broadcast) → **LINE Official**
   - งานเกี่ยวกับสิทธิ์/audit/ISO → **สิทธิ์ & ความปลอดภัย**
   - ประกัน/ทะเบียนผู้ป่วย → **ประกันสุขภาพ**
   - แจ้ง/ส่งสาร → **สื่อสาร**
   - ครุภัณฑ์/วัสดุสิ้นเปลือง/สต็อก → **คลังพัสดุ**
   - รายรับ-รายจ่าย/Cash Book/บัญชี → **การเงิน**
   - log/monitor → **ติดตามระบบ**
   - master data ของคลินิก/บุคลากร → **ข้อมูลหลัก**
   - การตั้งค่าระบบทั้งคลินิก → **ตั้งค่า**
2. **ถ้าไม่เข้ากลุ่มไหน** → สร้าง section ใหม่พร้อม `psb-section-label` ที่มี FontAwesome icon prefix และคำอธิบายภาษาไทย
3. **Section label format** เป็น collapsible toggle (accordion) — ต้องมี `data-group` key (`overview` / `ai` / `line` / `security` / `insurance` / `comm` / `inventory` / `finance` / `monitor` / `reports` / `docs` / `masterdata` / `settings`) จับคู่ระหว่างปุ่มและ wrapper:
   ```html
   <button type="button" class="psb-section-toggle" data-group="GROUP_KEY" onclick="togglePsbGroup('GROUP_KEY',this)">
       <i class="fa-solid fa-XXX" style="color:#XXX"></i>
       <span>ชื่อกลุ่ม</span>
       <i class="fa-solid fa-chevron-down psb-chevron"></i>
   </button>
   <div class="psb-group" data-group="GROUP_KEY">
       <!-- psb-item buttons here -->
   </div>
   ```
   - เมื่อ section ที่ active อยู่ใน group นี้ JS จะ auto-expand ตอนโหลด
   - สถานะ collapse/expand ต่อ group ถูกบันทึกใน `localStorage.psb_groups_collapsed` (array ของ keys ที่ collapse)
   - ตอน sidebar ย่อ icon-only — toggle จะถูกซ่อน, items ใน group แสดงเสมอ (ไม่ขึ้นกับ collapse state)
4. **Gate ทุก section** ด้วย `if (!$registryOnly && ...)` — `registryOnly` ใช้กับ partner ภายนอกที่อัพโหลดรายชื่ออย่างเดียว
5. **เมนูที่ต้อง access flag** ใส่เงื่อนไข `$isSuper || !empty($_SESSION['access_xxx'])` เสมอ
6. **Settings ต้องอยู่ล่างสุด** — ไม่ย้ายไปกลาง sidebar ไม่ว่ากรณีใด

หลังเพิ่มเมนูใหม่ — รีวิวว่า sidebar ยังอ่านง่าย ไม่ยาวจนต้อง scroll เกินจอ desktop (>10 เมนูต่อ section ถือว่ามากเกิน → split section)
