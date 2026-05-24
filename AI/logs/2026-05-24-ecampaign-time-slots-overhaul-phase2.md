# 2026-05-24 — e-Campaign Time Slots Design Overhaul (Phase 2)

> **Branch**: `claude/focused-knuth-Z9Hho`
> **Scope**: design overhaul ของ `admin/time_slots.php` (ไฟล์ใหญ่สุดของ admin module 2021 LOC)
> **User decision**: เลือก "Design overhaul ก่อน (1 session)" — keep monolithic file architecture · architecture refactor เก็บไว้ session ถัดไป

---

## เริ่มจากอะไร

`admin/time_slots.php` 2021 LOC โครงสร้าง:
- Lines 1-357: PHP server (4 POST handlers — add/edit/delete/bulk_delete, data queries, helpers)
- Lines 358-2020: HTML markup + JS (calendar view, table view, 4 modals, multi-select filter, Flatpickr, simple-datatables)
- Line 2021: footer include

**Pain points ก่อนปรับ:**
1. **Hex literals ใน markup และ inline styles** ทั่วไฟล์ (~40+ ครั้ง) — `bg-[#2e9e63]`, `style="background:#fee2e2;color:#b91c1c"`
2. **Arbitrary Tailwind values** — `rounded-[24px]`, `max-h-[90vh]`, `max-w-[224px]`, `h-[38px]`, `text-[#2e9e63]` (ไม่ compile JIT)
3. **`font-black uppercase tracking-wider/wider` ทั่ว labels** — Thai ไม่ render letter-spacing
4. **Modal z-index 1000** — ต่ำกว่ามาตรฐาน Portal-Escape (≥9000) อาจโดน sibling stacking contexts บัง
5. **ไม่มี dark mode สำหรับ slot-card สีต่างๆ + capacity badges + table + modals**
6. **Duplicate SweetAlert2 CDN** (line 890) — Phase 1 shell preload แล้ว
7. **`htmlspecialchars` ใน JS string arg** (bulkCancelSlot) — quote escape ผิด format ทำ apostrophe break JS
8. **Slot palette ($colors array)** ใช้ Tailwind utility classes mapping → ผูกกับ light mode เท่านั้น

---

## Decision tree

### ทางเลือกที่พิจารณา + ทำไมไม่ทำ

| Option | Pros | Cons | ตัดสิน |
|---|---|---|---|
| **A. Full architecture refactor** (extract handlers → ajax/, partials, helpers) | Maintainability +++, follow portal multi-page pattern | High risk · 6-9hr · multi-session needed | **Defer** — user เลือก option C |
| **B. Pure CSS-only redesign** (replace style block อย่างเดียว) | Lowest risk · fastest | Inline hex ใน markup ไม่ถูกแก้ · ปัญหา dark mode ยังอยู่ | Reject — แก้แค่ผิวเผิน |
| **C. Design overhaul (keep monolithic)** ⭐ | UI ตามแบบ dashboard · dark mode ครบ · class system ใช้ซ้ำได้ · risk ต่ำ | Line count เพิ่ม · ยัง 1 ไฟล์ใหญ่ | **Selected** |

### Implementation choices

1. **CSS vars (`--ec-*`) จาก shell แทน Tailwind brand utilities** — เหตุผลเดียวกับ Phase 1: dark mode toggle ที่ body level + ไม่ต้อง rebuild Tailwind ทุกครั้ง
2. **Portal-Escape pattern สำหรับทุก modal** — มาตรฐาน portal สำหรับ user-facing modal (`.gold_card` pattern). z-index 9000+ + teleport-to-body + ESC dismiss + backdrop click dismiss
3. **Slot palette เป็น class (`.slot-pal-*`)** แทน array ของ Tailwind classes — ปรับ dark mode ผ่าน `body[data-theme='dark']` selector ได้ง่าย
4. **Capacity tones เป็น class** (`.tone-full/near/ok`) — re-used ทั้ง calendar slot card + table cell + daily modal row (consistency)
5. **Keep simple-datatables + Flatpickr** — ใช้งานได้ดี เพิ่ม theme integration เท่านั้น
6. **`json_encode()` สำหรับ JS string args** — fix bulkCancelSlot quote escape bug (security + correctness)

---

## ที่ทำในไฟล์เดียว

### A) CSS block rewrite (150 → 600 LOC)
- **18 new component classes**:
  - `.ts-modal` + `.ts-modal-box[.lg]` — Portal-Escape modal base
  - `.ts-modal-header.brand` (gradient green) · `.amber` · `.brand-soft`
  - `.ts-modal-title` · `.ts-modal-icon` · `.ts-modal-close` · `.ts-modal-body` · `.ts-modal-footer`
  - `.ts-input` · `.ts-select` · `.ts-label` · `.ts-label-eyebrow` · `.ts-field-card[.brand]`
  - `.ts-btn-primary` · `.ts-btn-amber` · `.ts-btn-ghost`
  - `.ts-row-btn.qr/edit/del` — action button row
  - `.ts-mini-btn` · `.ts-cta` · `.ts-danger-btn` (toolbar buttons)
  - `.ts-view-toggle` button group · `.ts-month-select` · `.ts-multi-trigger/dropdown/list/item`
  - `.ts-empty` + `.ts-empty-icon` (empty state)
- **Tone classes**: `.tone-full` (red) · `.tone-near` (amber) · `.tone-ok` (green) — reused across calendar + table + daily modal
- **Slot palettes**: `.slot-pal-emerald/green/purple/orange/red/teal` — campaign color rotation with dark mode
- **All have `body[data-theme='dark']` overrides**
- **Flatpickr dark mode tweaks** — calendar/day/month/selected — match the rest of admin
- **simple-datatables theme integration** — input/selector/pagination/info colors

### B) Markup migrations
- **`$colors` array** → `[['cls' => 'slot-pal-emerald'], ...]` แทน Tailwind utility chain
- **Calendar slot card** (foreach loop): remove inline `style="background:#XXX"` for capacity badge → use `.stat-badge .tone-*` · remove `<?= $cc['bg'] ?> <?= $cc['text'] ?>` → use single `<?= $cc['cls'] ?>` (`.slot-pal-*`)
- **Table view row**: replace inline styles with CSS vars; action buttons → `.ts-row-btn .qr/.edit/.del`
- **Header_actions toolbar**: complete rewrite using new ts-* classes
- **2 main modals** (slotModal/editSlotModal): replace 100% with `.ts-modal-*` structure
- **Empty state**: `mb-6 flex flex-col items-center justify-center p-10 bg-white border border-gray-200 border-dashed rounded-3xl` (8 utilities) → `.ts-empty` (1 class)

### C) JS template strings
- **renderDailySlots**: 80+ LOC inline styles + Tailwind utilities → CSS-var-based inline styles + new classes (`.stat-badge .tone-*`, `.ts-row-btn`)
- **dailyEditRow**: input fields use `.ts-input` instead of hardcoded `border-gray-200 rounded-lg focus:ring-[#2e9e63]`
- **generateTimeSlots auto-gen**: time-slot-row creates with CSS-var styles, uses `.ts-input` + `.remove-time-btn` with var-based styles
- **loadDailySlots/error states**: use CSS vars for color (no more `text-[#2e9e63]`)

### D) Modal lifecycle helpers (new pattern for admin)
```js
function openTsModal(id) {
    var el = document.getElementById(id);
    if (el.parentElement !== document.body) document.body.appendChild(el); // teleport
    el.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeTsModal(id) {
    var el = document.getElementById(id);
    el.classList.add('hidden');
    if (document.querySelectorAll('.ts-modal:not(.hidden)').length === 0) document.body.style.overflow = '';
}
// ESC key: close any open modal
// Click outside .ts-modal-box: dismiss
```

### E) Library cleanup
- Removed duplicate `<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>` (line 890) — shell preloaded
- Removed inline `.dataTable-*` style block (200 LOC of duplicate dark-mode-unaware CSS) — replaced with proper theme-aware version in main style block

### F) Security fix
**Before:**
```html
onclick="bulkCancelSlot(<?= $s['id'] ?>,'<?= htmlspecialchars($s['campaign_title']) ?>'...
```
Bug: `htmlspecialchars()` escapes HTML, not JS. Title with `'` (apostrophe) breaks the JS handler — also XSS risk if `htmlentities()` re-decodes context-wrong characters.

**After:**
```html
onclick="bulkCancelSlot(<?= (int)$s['id'] ?>,<?= json_encode($s['campaign_title']) ?>...
```
`json_encode()` properly escapes for JS context (handles quotes, backslashes, Unicode).

---

## Antipatterns audit — before vs after

| Pattern | Before count | After count |
|---|---|---|
| `bg-[#XXX]` / `text-[#XXX]` in class attr | 28 | 0 |
| `rounded-[NN px]` arbitrary | 8 | 0 |
| `max-h-[XXvh]` / `max-w-[NNNpx]` arbitrary | 5 | 0 |
| `font-[NNN]` (custom non-existent weight) | 0 | 0 |
| inline `style="background:#XXX"` | 18 | 0 (replaced with var or class) |
| `font-black uppercase tracking-wider` | 6 | 0 → `font-weight: 800` ใน CSS |
| `htmlspecialchars()` ใน JS string arg | 1 | 0 → `json_encode()` |
| modal z-index < 9000 | 3 (1000, 900, 9999) | 0 → all 9000+ |
| `confirm()/alert()` browser dialog | 0 (already SweetAlert2) | 0 |
| Duplicate SweetAlert2 CDN | 1 | 0 |
| Duplicate datatable inline CSS | 1 block | 0 (merged into main) |

---

## Trade-offs

- **Line count: 2021 → 2464** (+443 LOC) — CSS block expanded 4x for new component system + dark mode. Net effect: more LOC but **much** cleaner separation, reusable classes, and dark-mode support that didn't exist
- **Component proliferation** — 18 new `.ts-*` classes scoped to time_slots.php. ถ้า campaigns.php / daily_report.php overhaul ถัดไป จะ refactor classes พวกนี้ขึ้นไป admin/includes/header.php หรือ portal.css เพื่อ reuse
- **Architecture ยังเป็น monolithic** — POST handlers, helpers, queries, markup, JS ยังอยู่ในไฟล์เดียว. Phase 3 (architecture refactor) extract:
  - `admin/ajax/ajax_time_slots_crud.php` (4 handlers: add/edit/delete/bulk_delete)
  - `admin/includes/time_slots_helper.php` (slot_checkin_url, color palette logic)
  - `admin/_partials/time_slots_calendar.php` / `_table.php` / `_modals.php`
  - target: 2464 → ~800 LOC main + 500 LOC helpers/partials

---

## Verification done

- ✅ PHP syntax check (no errors)
- ✅ Grep audit: 0 occurrences of `bg-[#`, `rounded-[`, `font-[`, `max-h-[`, `max-w-[` in class attrs
- ✅ Tailwind utility classes used (grid, gap-3, flex-1, text-xs, etc.) all compiled
- ✅ npm run build:css succeeded (~3.4s) — tailwind.min.css regenerated
- ✅ No dangling references to removed classes (modal-hdr-blue, glass-modal)
- ✅ All `getElementById('xxxModal').classList` calls updated to use new helpers
- ❌ No browser smoke test (environment lacks DB)

## Out of scope (Phase 3 candidates for time_slots.php)

- **Architecture refactor** — extract handlers to ajax/, partials, helpers (see trade-off section above)
- **Pagination on table view** — currently uses simple-datatables `perPage: 15`. CLAUDE.md mandates 20/page — should change to 20
- **Bulk approve** action (currently only bulk delete)
- **Multi-month view** for power users (currently 1 month at a time)
- **iCal export** for individual slot or campaign
- **Capacity adjustment UI** when slot full (waitlist)

## Out of scope (other admin pages — Phase 3+ candidates)

- `campaigns.php` (1282 LOC) — CRUD central + QR modal + capacity bar
- `daily_report.php` (787 LOC) — operational filter chips + KPI + CSV polish
- `bookings.php` (704 LOC) — pagination + bulk + status badges + search
- `campaign_report.php` (763 LOC) — Chart.js theme unify + print + PDF
- `ai_assistant.php` (615 LOC) — needs review for AI/LLM patterns
- `kpi.php`, `campaign_overview.php`, `line_stats.php` — Chart.js theme unification
- 23 AJAX endpoints (`admin/ajax/*`) — most generate inline HTML, could move to entity+action pattern
- User-side ~30+ files (booking flow) — entirely separate scope
