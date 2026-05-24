# 2026-05-24 — e-Campaign Admin Overhaul (Phase 1)

> **Branch**: `claude/focused-knuth-Z9Hho`
> **Scope**: ยกเครื่อง `admin/includes/header.php` (shell ที่ใช้ร่วม 23 หน้า) + `admin/index.php` (Dashboard) + `admin/ajax/ajax_dashboard.php`. User trigger: "https://healthycampus.rsu.ac.th/e-campaignv2/admin/index.php อยากปรับปรุงยกเครื่อง"
> **User decisions** (via AskUserQuestion):
> - Scope หลัก: UI/UX redesign + Architecture refactor + Performance/DX
> - ขนาดงาน: เริ่มที่ 1-2 หน้าก่อน (Recommended)
> - Design source: sync กับ portal เต็มที่ (Recommended)

---

## ก่อนปรับ — Audit สภาพปัจจุบัน

`admin/includes/header.php` (605 LOC เก่า) + `admin/index.php` (432 LOC เก่า) มีปัญหา:

1. **Hex literals ในมาร์กอัป** (ขัดกฎ CLAUDE.md "ห้ามใส่ค่าสี hex ดิบในมาร์กอัป")
   - `bg-[#e8f8f0]`, `bg-[#2e9e63]`, `from-[#0B6623]` etc.
   - `style="background:#fff"` (fights dark mode — global override จับเฉพาะ `.bg-white`)
2. **Arbitrary Tailwind values** (ไม่ compile JIT — เป็น empty class)
   - `rounded-[18px]`, `rounded-[22px]`, `tracking-[0.25em]`, `font-[950]`, `font-[950]`
3. **Antipattern: `font-black uppercase tracking-widest`** (portal เลิกใช้แล้วในรอบ Quieter เพราะ Thai ไม่ทำงาน + ตึงเครียดเกินจำเป็น)
4. **Dark mode partial** — header.php มี dark toggle แต่ body styles/cards ไม่ override → ติดธีมขาวค้าง
5. **5s polling แบบไม่เช็ค visibility** — เปลือง bandwidth ตอน tab background
6. **ไม่มี period delta vs เมื่อวาน** — KPI โชว์ตัวเลขเฉยๆ ไม่บอก trend
7. **ไม่มี trend chart** — ต้องเข้าหน้า kpi.php / campaign_overview.php แยก
8. **Heatmap ใช้ raw hex** — สีฝัง markup → switch dark mode แล้วสะดุดตา
9. **Popular campaigns ไม่มี drill-down link**
10. **Notification dropdown z-index 200** — เสี่ยงโดน sibling stacking contexts บัง

---

## แผนงาน + การตัดสินใจ

### 1) Shell rebuild (`admin/includes/header.php`)

**Design tokens via CSS variables** (กัน Tailwind compile lag):
```css
:root {
    --ec-brand-50:  #ecfdf5;
    --ec-brand-200: #a7f3d0;
    --ec-brand-500: #2e9e63;  /* canonical */
    --ec-brand-700: #155e3d;
    --ec-bg:        #e8f4ec;
    --ec-surface:   #ffffff;
    --ec-ink-1:     #0f172a;
    ...
    /* per-section accent dots */
    --ec-acc-overview: #2e9e63;
    --ec-acc-campaign: #d946ef;
    --ec-acc-report:   #6366f1;
    --ec-acc-tools:    #64748b;
}
body[data-theme='dark'] {
    --ec-surface:   #111827;
    --ec-bg:        #0b1220;
    --ec-ink-1:     #f1f5f9;
    ...
}
```

ทุก custom class (`.ec-kpi`, `.nav-link`, `.notif-panel`) ใช้ var เหล่านี้ → dark mode toggle = swap CSS var values เท่านั้น (no per-element override hell).

**Sidebar accordion** — section toggle (`.nav-section-toggle`) มี per-section accent dot + chevron + collapse state ใน localStorage:
```js
function toggleNavSection(btn) {
    var sec = btn.closest('.nav-section');
    sec.classList.toggle('collapsed');
    // persist via localStorage.ec_nav_collapsed (JSON array of section keys)
}
```
Auto-expand section ที่มี `.nav-link.active` ตอนโหลด (ไม่ collapse แม้ user เคย collapse) — `data-section="overview|campaign|report|tools"` key.

**`--lift` CSS variable pattern** (yet portal-compatible):
```css
.nav-link { transform: translateY(var(--lift, 0)); }
.nav-link:hover { --lift: -1px; }
```

**SweetAlert2 preload** ใน head — ให้ทุก admin page (23 ไฟล์) ใช้ `Swal.fire()` ได้ทันทีโดยไม่ต้องเพิ่ม script tag ตัวเอง.

**FOUC fix**:
```html
<head><script>(function(){if(localStorage.getItem('ecampaign_theme')==='dark')document.documentElement.setAttribute('data-theme-preload','dark');})();</script></head>
<body><script>(function(){if(localStorage.getItem('ecampaign_theme')==='dark')document.body.setAttribute('data-theme','dark');})();</script>
```
2 scripts: head one is no-op (reserved สำหรับอนาคต ถ้าอยากใช้ html[data-theme-preload] CSS); body one ที่ทำงานจริง — execute ก่อน sidebar/topbar render → ไม่กระพริบ.

**Notification panel** — extract เป็น `.notif-panel` class แทน inline Tailwind utilities → cleaner CSS + ดูแลง่าย.

**Visibility-aware polling**:
```js
function fetchNotifications() {
    if (document.hidden) return; // skip when tab not visible
    fetch(ajaxUrl)...
}
setInterval(fetchNotifications, 30000);
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) fetchNotifications();
});
```

**Helper guards** — `function_exists('navLink')` + `function_exists('navSection')` ป้องกัน redeclare ถ้า header.php โดน include ซ้ำ (เกิดยาก แต่กันไว้ก่อน).

### 2) Dashboard rebuild (`admin/index.php`)

**KPI tile pattern** (`.ec-kpi`) — แต่ละ tile มี:
- `border-left: 4px solid var(--kpi-accent)` (calm accent stripe)
- label สี `var(--ec-ink-3)` 11px font-weight 700
- value 30px font-weight 800 สี `var(--ec-ink-1)` (slate-900) — ไม่ใช้สี emerald เด่นเกินแข่ง CTA
- delta chip `.ec-kpi-delta[data-dir="up|down|flat"]` — สี ecfdf5/047857 (up) / fef2f2/b91c1c (down) / surface-2/ink-3 (flat) + dark mode override
- hover lift -3px + colored shadow

**Period delta helper** (PHP):
```php
function periodDelta(int $current, int $prior): array {
    if ($prior === 0) return ['pct'=>$current>0?100:null, 'dir'=>$current>0?'up':'flat', 'label'=>$current>0?'ใหม่':'—'];
    $pct = (int) round((($current - $prior) / $prior) * 100);
    return ['pct'=>$pct, 'dir'=>$pct>0?'up':($pct<0?'down':'flat'), 'label'=>($pct>0?'+':'').$pct.'%'];
}
```
- `bookings_today` delta vs `bookings_yesterday`
- `new_users_7d` delta vs `new_users_prev_7d` (8-14 วันก่อน)

**Booking funnel** — 1 SQL query นับ status all-time → segment bar proportional + 4-column legend ใน mobile/desktop. Edge case: ถ้า `total === 0` → empty state.

**Chart.js 12-week trend bar** (theme-aware):
```js
function chartTheme() {
    var dark = document.body.getAttribute('data-theme') === 'dark';
    return {
        tick: dark ? '#cbd5e1' : '#64748b',
        grid: dark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)',
        ...
    };
}
function buildTrend() {
    if (trendChart) trendChart.destroy();
    var th = chartTheme();
    trendChart = new Chart(ctx, {...with th values...});
}
buildTrend();
window.addEventListener('ec-theme-change', buildTrend);
new MutationObserver(function(muts) {
    muts.forEach(function(m) { if (m.attributeName === 'data-theme') buildTrend(); });
}).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });
```
Both `ec-theme-change` custom event (dispatched by `adminToggleDark()`) AND `MutationObserver` (catches programmatic body attr changes from cross-tab `message` event) → robust dual trigger.

**Campaign type donut** — 5 type colors (vaccine green, training indigo, health_check/health sky, other slate). Center label show total count. Same theme-aware re-render pattern.

**Heatmap dual-palette** — แต่ละ cell มี `data-light="#XXX"` และ `data-dark="#XXX"` attributes:
```js
function applyHeatmapTheme() {
    var dark = document.body.getAttribute('data-theme') === 'dark';
    document.querySelectorAll('.ec-heatmap-cell, .ec-heatmap-legend-cell').forEach(function(el) {
        var c = dark ? el.dataset.dark : el.dataset.light;
        if (c) el.style.background = c;
    });
}
```
Light palette: `#f1f5f9` → `#16a34a` (slate-100 → green-600)
Dark palette: `#1e293b` → `#6ee7b7` (slate-800 → green-300) — ปรับ contrast ให้อ่านบนพื้นมืด

**Visibility-aware live updater**:
```js
function refreshDashboard() {
    if (document.hidden) return;
    if (isFetching && abortController) abortController.abort();
    ...
}
setInterval(refreshDashboard, 15000); // 5s → 15s (less aggressive)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) refreshDashboard(); // resume immediately on focus
});
```

**Flash animation** บน value update:
```js
function updateWithFlash(id, newVal) {
    var el = document.getElementById(id);
    var currentVal = parseInt(el.textContent.replace(/[^0-9-]/g, ''), 10) || 0;
    if (currentVal !== parseInt(newVal, 10)) {
        el.classList.add('flash'); // scale(1.06) + color brand-500
        setTimeout(function() {
            el.textContent = parseInt(newVal, 10).toLocaleString();
            el.classList.remove('flash');
        }, 200);
    }
}
```

### 3) AJAX (`admin/ajax/ajax_dashboard.php`)

- Keep backward-compat field names: `stats.total`, `stats.pending`, `stats.confirmed`, `stats.bookings_today`, `stats.new_users_7d`
- Refactor `popular_html` generator ใช้ `.ec-popular-row` class ใหม่ (เปลี่ยน rank classes, ลบ `bg-[#e8f8f0]` literal)
- Popular row คลิกได้ — link ไป `campaign_overview.php?id=N`

---

## Antipatterns ที่ลบ (audit checklist)

| ก่อน | หลัง | จำนวน |
|------|------|---|
| `bg-[#XXX]` ใน markup | `style="background:var(--ec-brand-XX)"` หรือ class ที่ใช้ var | ~24 spots |
| `font-[950]` | `font-weight: 800` ใน CSS | 2 spots |
| `font-black uppercase tracking-[0.25em]` | `font-weight: 700; letter-spacing: .04em` | 4 spots |
| `rounded-[18px]/[22px]/[24px]` | `border-radius: 18-22px` ใน custom class | 8 spots |
| `style="background:#fff"` | `background: var(--ec-surface)` ใน class | 12 spots |
| 5s polling no visibility check | 15s + Page Visibility API | 1 spot (dashboard) |
| Heatmap hex hardcoded inline | data-light/data-dark dual-palette + JS theme switch | 24+24=48 cells |

---

## Decisions ที่อาจกลับมาสำคัญในอนาคต

1. **CSS variables vs Tailwind utility classes** — เลือกใช้ CSS var (`--ec-brand-500`) เพราะ:
   - Dark mode toggle = swap var values ที่ body — ง่ายกว่าเขียน `body[data-theme='dark'] .my-class { ... }` ต่อ class
   - ไม่ต้องรอ Tailwind rebuild ทุกครั้งที่เพิ่ม shade ใหม่ (`bg-brand-50` ฯลฯ ยังไม่ compile)
   - **Trade-off**: ใช้ utility-first ไม่ได้ → ต้องเขียน custom class (`.ec-kpi`, `.ec-card`) แต่ admin มี vocabulary จำกัด → manageable
2. **Sidebar collapsible vs static** — เลือก collapsible (per portal pattern) เพราะ admin sidebar ยาว ~10 items ทุก section expand จะเปลือง real estate. Default ขยายหมด แต่ remember collapse state ผ่าน localStorage
3. **SweetAlert2 preload in shell** — ทุกหน้า admin ใช้ได้ทันที (เหมือน portal). Cost: +~20KB CDN ทุกหน้า — รับได้
4. **15s polling vs WebSocket** — 15s + visibility check เพียงพอสำหรับ dashboard ที่ไม่ใช่ real-time-critical. ถ้าต้องการ true real-time ในอนาคต → SSE หรือ WebSocket (พิจารณาตอนนั้น)
5. **Heatmap dual-palette via dataset** vs CSS var — เลือก data attributes เพราะแต่ละ cell ต้องการสีต่างกัน 6 ระดับ (legendStops 0-5). ถ้าใช้ CSS var ต้องเขียน 12 vars (6×2 theme) — verbose มาก. data-light/data-dark + JS เป็นวิธีที่ DOM-flexible สุด

---

## Out of scope (Phase 2+ candidates)

- **`admin/time_slots.php` (2021 LOC)** — ไฟล์ใหญ่สุด ควรแตกเหมือน portal multi-page refactor (`_init.php` + `_layout.php`) — แต่ scope ใหญ่ ต้อง 1 session แยก
- **`admin/campaigns.php` (1282 LOC)** — CRUD central, ต้อง redesign QR modal + bulk operations + status badges + capacity bar
- **`admin/daily_report.php` (787 LOC)** — operational hub ที่ใช้ทุกวัน, ต้อง improve filter chips + auto-refresh UX + CSV export polish
- **`admin/bookings.php` (704 LOC)** — pagination 20/page ตามกฎ CLAUDE.md, bulk approve/cancel UX, search debounce
- **`admin/campaign_report.php` (763 LOC)** — สรุปรายแคมเปญ, ต้อง chart unify + print-friendly + PDF export
- **`admin/kpi.php`, `admin/campaign_overview.php`, `admin/line_stats.php`** — รายงาน 3 ตัว, theme-aware Chart.js patterns สามารถ propagate
- **Sub-modules**: `admin/ajax/*` 23 endpoints ส่วนใหญ่ยังใช้ inline HTML generation — ควรย้ายไป entity+action pattern เหมือน `portal/ajax_clinic_master.php`
- **`user/` side** (~30+ files) — booking flow ยังไม่ overhaul (booking_campaign.php, booking_date.php, booking_time.php, submit_booking.php, checkin_campaign.php)
- **Mobile UX deep dive** — booking flow บนมือถือต้อง critique/clarify pass

---

## Verification

- PHP syntax check ผ่าน 3 ไฟล์ (header.php, index.php, ajax_dashboard.php)
- npm run build:css ผ่าน 3.2s — output 2 brand classes + ds-* components compile แล้ว
- All Tailwind utility classes ที่ใช้ใน 2 ไฟล์ verify ว่ามีใน tailwind.min.css
- ไม่ได้ smoke test ใน browser เพราะ environment ไม่มี DB
- Pages อื่น 21 ไฟล์ที่ใช้ `renderPageHeader()` ยังเรียกได้ปกติ (signature 2-3 args เหมือนเดิม)

---

## Follow-ups ก่อน user accept

- [ ] User test ใน browser — ตรวจ dark mode toggle, sidebar collapse, KPI delta accuracy, charts render
- [ ] ทดสอบ dashboard บน Safari/Firefox (View Transitions API support varies)
- [ ] Lighthouse audit หลัง deploy — ดู Performance/Accessibility score
- [ ] Verify polling load บน server (15s × N concurrent admin) — ถ้า scale ปัญหา ขยายเป็น 30s + ETag
