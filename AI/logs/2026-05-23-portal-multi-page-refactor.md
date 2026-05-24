# Portal Multi-Page Refactor — 2026-05-23

## Context

User report ก่อนหน้านี้: คลิก "งาน/Tasks" ใน sidebar แล้วหน้าไม่เปลี่ยน
ปัญหา root cause: `portal/index.php` เป็นไฟล์เดียวขนาด 6476 บรรทัด ที่ render
ทุก section (42 อัน) เป็น `<div>` ซ้อนกันแล้วซ่อนทุกอันยกเว้น active ผ่าน
`display:none` + JS `switchSection()` swap

ปัญหา:
1. ทุก request render 42 partials → DB queries เยอะเกินจำเป็น
2. Mix 2 pattern (JS swap vs full reload) → URL/DOM state ไม่ sync
3. ไฟล์เดียว 6476 บรรทัด → maintain ยาก, merge conflict บ่อย
4. Bookmarks / share URL ลำบาก

User ตอบคำถาม: ต้องการ **Big Bang** (migrate ทั้งหมด) + **keep backwards-compat**

## Decision

Migrate ทั้ง 42 sections เป็น standalone page file ภายใต้ `portal/<section>.php`
+ shared layout system + index.php เป็น redirect router

## Architecture หลังแก้

```
portal/
├── _init.php              ← auth + role flags + portal_handlers (every page requires this first)
├── _layout.php            ← layout_start() / layout_end() / layout_access_denied() functions
├── _layout_top.php        ← <!DOCTYPE> → opening <main> (head + sidebar) — included by layout_start()
├── _layout_bottom.php     ← </main> → </html> (closing scripts + modal markup) — included by layout_end()
├── _portal_data.php       ← KPI queries + activity log + announcement handler + maintenance data
│                            (required by fat sections: dashboard, announcements, identity, settings)
├── index.php              ← Backwards-compat redirect router: ?section=X → X.php
├── dashboard.php          ← Standalone page
├── edms.php               ← (with ?view= sub-routing)
├── finance.php            ← Standalone page
├── ... (39 more section files)
```

## Migration pattern

แต่ละ section file ใช้ pattern เดียวกัน:
```php
<?php
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'X', 'title' => 'Page Title']);
?>
<!-- section content (inline or includes _partials/X.php) -->
<?php layout_end(); ?>
```

Sections ที่มี gate:
```php
<?php
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'finance', 'title' => 'ระบบการเงิน']);
?>
<div class="portal-section" style="...">
    <?php
    if ($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance'])) {
        include __DIR__ . '/_partials/finance.php';
    } else {
        layout_access_denied('ต้องมีสิทธิ์ access_finance');
    }
    ?>
</div>
<?php layout_end(); ?>
```

## Sidebar — link pattern เปลี่ยน

**ก่อน**:
```html
<button class="psb-item" data-section="finance" onclick="switchSection('finance',this)">
    Finance
</button>
```

**หลัง**:
```html
<a class="psb-item" data-section="finance" href="finance.php">
    Finance
</a>
```

EDMS sub-menu:
- ก่อน: `<a href="index.php?section=edms&edms_view=list&type=task">`
- หลัง: `<a href="edms.php?view=list&type=task">`

## Backwards-compat strategy

`portal/index.php` หลังแก้ = redirect router:
- `?section=X` → 302 redirect ไป `X.php`
- `?section=edms&edms_view=Y` → `edms.php?view=Y` (preserve query)
- ไม่มี section → fallback dashboard.php

ผลลัพธ์:
- Bookmark เก่า `?section=edms` ยังใช้งานได้
- LINE webhook / email links ที่มี deep link ยังใช้งานได้
- ทุกอย่างเก่า + ใหม่ทำงานคู่ขนาน

## switchSection JS fallback

JS function `switchSection()` ยังเก็บไว้ใน `_layout_top.php` แต่เพิ่ม fallback:
- ถ้าหา `#section-X` ในหน้าไม่เจอ (เพราะ standalone page ไม่มี div ซ้อน) →
  navigate ไป `X.php` ตรงๆ
- ทำให้ link เก่า `<a href="javascript:switchSection('X')">` ยังทำงานได้

## Execution log

1. ✅ สร้าง `_init.php` — auth + role flags (35 บรรทัด)
2. ✅ Extract `_layout_top.php` (978 บรรทัด — head + sidebar + open `<main>`)
3. ✅ Extract `_layout_bottom.php` (2275 บรรทัด — closing scripts + html close)
4. ✅ Create `_layout.php` (orchestrator with start/end functions)
5. ✅ Extract `_portal_data.php` (621 บรรทัด — KPIs + activity + handlers)
6. ✅ Convert sidebar links via Python — 38 buttons → `<a>` anchors
7. ✅ Update EDMS sub-menu links — `?section=edms&edms_view=X` → `edms.php?view=X`
8. ✅ Generate 42 section page files via Python (auto-extracted from index.php blocks)
9. ✅ Strip `display:none` toggle from all generated files (41 stripped, dashboard already correct)
10. ✅ Replace `index.php` with backwards-compat redirect (60 lines, was 6476)
11. ✅ Add EDMS `?view=` → `$_GET['edms_view']` compat shim in edms.php
12. ✅ switchSection JS — fallback to URL navigation when no in-page target
13. ✅ Add `text-decoration: none` to `.psb-item` CSS for `<a>` items
14. ✅ Wire `_portal_data.php` into 4 fat sections (dashboard, announcements, identity, settings)

## Files clobbered (overwrites)

Pre-refactor มี 9 standalone "legacy" page files ที่เป็นของเก่าก่อน modular era:
- `activity_logs.php`, `apps.php`, `error_logs.php`, `insurance_sync.php`,
  `line_settings.php`, `nurse_schedule.php`, `profile.php`, `smtp_settings.php`

ทั้งหมดถูก overwrite ด้วย thin wrappers ที่ include `_partials/<name>.php`
(ซึ่งคือ content ที่ใช้จริงผ่าน `?section=X` mechanism). ของเก่าเป็น orphan
ไม่ถูก link จากที่ไหน — ตรวจแล้ว grep ทั้ง codebase. ไม่มี regression risk.

Restore จาก commit `5f665bf` หรือก่อนหน้าได้ถ้าต้องการ.

## Risks / Things to test

- [ ] เปิด `/portal/dashboard.php` ตรงๆ — render dashboard ปกติ
- [ ] เปิด `/portal/edms.php?view=list&type=task` — task list render
- [ ] เปิด `/portal/?section=edms&edms_view=sla_dashboard` — redirect ไป edms.php?view=sla_dashboard
- [ ] กด sidebar links ทุกตัว — navigate ปกติ (full reload)
- [ ] ทดสอบ POST submissions (ประกาศ create/edit/delete) — portal_handlers ใน _init ยังทำงาน
- [ ] ทดสอบ Identity Governance modal — identity_actions ใน _portal_data ยังทำงาน
- [ ] Dark mode toggle — ยังทำงาน (อยู่ใน _layout_bottom)
- [ ] Sidebar group collapse + search — ยังทำงาน (อยู่ใน _layout_top)
- [ ] EDMS modal teleport — ยังทำงาน
- [ ] AI Assistant chat — ยังทำงาน

## Possible follow-ups

1. **Update internal `<a href="?section=...">` links** — มี 78 จุดในไฟล์ partials
   ที่ใช้ pattern เก่า ตอนนี้ผ่าน redirect ได้แต่ extra 302 round-trip
2. **Lazy-load sidebar badge queries** — ทุกหน้า render sidebar เลย DB queries
   เกิดขึ้นทุก request — อาจ cache สั้นๆ (60s)
3. **Identity inline content → _partials/identity.php** — ตอนนี้ identity.php
   มี 665 บรรทัด inline (HTML+JS modal). ย้ายเข้า partial เพื่อ maintainability
4. **Announcement handler → actions/announcement_handlers.php** — แยกจาก
   `_portal_data.php` (ตอนนี้รวมอยู่ด้วยกัน) — ทำให้ section อื่นไม่ต้องโหลด
5. **Remove `_portal_data.php` from non-dashboard pages** — ตอนนี้ announcements/
   identity/settings โหลด KPI queries เกินจำเป็น — split data prep ต่อ section
6. **Convert `data-section="X"` to ARIA labels** — บางที JS ที่อ้างอิง attribute
   นี้ยังต้องใช้ แต่ก็เก็บไว้ได้ ไม่กระทบอะไร

## Pattern reference

Migration template สำหรับ section ใหม่:
```php
<?php
/**
 * portal/<name>.php — <Page title>
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';
// require_once __DIR__ . '/_portal_data.php';  // เฉพาะถ้าต้องใช้ KPI/activity

layout_start(['section' => '<name>', 'title' => '<Thai title>']);
?>
<div class="portal-section" style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
    <?php
    if (/* role check */) {
        include __DIR__ . '/_partials/<name>.php';
    } else {
        layout_access_denied('ต้องมีสิทธิ์ access_X');
    }
    ?>
</div>
<?php layout_end(); ?>
```

## Stats

- Files created: 47 (42 section + 5 foundation)
- Files modified: 2 (`index.php`, `assets/css/portal.css`)
- Files overwritten (legacy): 9 (verified orphan — see above)
- Lines added: ~5500 (new files)
- Lines deleted: ~6400 (index.php diff)
- Net code change: -900 LOC (smaller surface area + better organization)
