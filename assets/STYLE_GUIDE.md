# RSU Medical — Design System (Phase 1-2)

A single source of truth for colors, typography, components, and the
build pipeline. Read this before adding new UI in any module.

---

## 1 — Tokens

All tokens live in `tailwind.config.js` (source) and become utility
classes you can reference anywhere.

### Brand colors

| Role               | Token        | Hex       | When to use                         |
|--------------------|--------------|-----------|-------------------------------------|
| Primary brand      | `brand-500`  | `#2e9e63` | User-facing CTAs, active nav, hero  |
| Brand dark         | `brand-600`  | `#1f7a4d` | Hover state of primary CTAs         |
| Brand soft surface | `brand-50`   | `#ecfdf5` | Subtle backgrounds, ghost icons     |
| Info / admin       | `info-500`   | `#0052CC` | Admin module accents only           |
| Canvas / page bg   | `canvas`     | `#F8FAFF` | `<body>` background                 |

> Decision: brand green wins everywhere user-facing. Blue stays for
> admin-only modules so we can visually distinguish "patient mode" from
> "operator mode".

### Typography

- `font-sans` → RSU → Sarabun → system fallback.
- Use `font-black` for emphasis, `font-bold` for body strong text.
- Avoid raw `font-family` declarations in PHP/HTML.

### Geometry

| Token            | Value      | When to use                           |
|------------------|------------|---------------------------------------|
| `rounded-card`   | `1.5rem`   | Standard inline cards                 |
| `rounded-card-lg`| `2.5rem`   | Hub-style "premium" cards (hero, modal panes) |
| `rounded-pill`   | full       | Status pills, language toggle         |
| `shadow-card`    | soft drop  | Standard surface elevation            |
| `shadow-card-lg` | larger     | Floating / hero cards                 |
| `shadow-glow-brand` | green   | Primary CTA buttons                   |

---

## 2 — Component classes

Defined in `assets/css/tailwind.src.css` under `@layer components`. Use
these instead of repeating long utility chains.

```html
<!-- Cards -->
<div class="ds-card">         …default card</div>
<div class="ds-card-lg">      …hub-style premium card</div>
<div class="ds-card-soft">    …light card</div>

<!-- Buttons -->
<button class="ds-btn-primary">บันทึก</button>
<button class="ds-btn-ghost">ยกเลิก</button>
<button class="ds-btn-danger">ลบ</button>
<a class="ds-btn-info">Admin action</a>

<!-- Inputs -->
<input class="ds-input" placeholder="…">

<!-- Status pills -->
<span class="ds-pill-brand">อนุมัติแล้ว</span>
<span class="ds-pill-amber">รออนุมัติ</span>
<span class="ds-pill-rose">เกินกำหนด</span>

<!-- Glass header (sticky page header) -->
<header class="ds-glass-header px-6 py-5 flex items-center justify-between">
    …
</header>

<!-- Section eyebrow label -->
<p class="ds-eyebrow">Health Overview</p>
```

If you need a one-off variation, prefer composing utilities on top of
the `ds-*` class rather than writing new CSS.

---

## 3 — Build pipeline

```bash
npm install              # one-time
npm run build:css        # produce assets/css/tailwind.min.css (~115 KB)
npm run watch:css        # rebuild on change while developing
```

The `content` glob in `tailwind.config.js` already covers `**/*.php`
and `**/*.html`, so any class you write in markup is included on the
next rebuild. Build output is committed (so production hosts that
don't run npm still serve the latest CSS).

### How to wire up a page

Replace the Tailwind CDN tag:

```diff
- <script src="https://cdn.tailwindcss.com/3.4.1"></script>
+ <link rel="stylesheet" href="/assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
```

The `<link>` is faster (no JIT in the browser), production-grade, and
respects the `content` purge so the file stays small.

---

## 4 — Module status (Phase 4 migration tracking)

| Module                  | CDN → compiled | Tokens used | Bottom nav |
|-------------------------|----------------|-------------|------------|
| `user/hub.php`          | ⏳ pending     | ⏳ pending  | inline     |
| `user/profile.php`      | ⏳ pending     | ⏳ pending  | shared ✅  |
| `user/my_bookings.php`  | ⏳             | ⏳          | inline     |
| `e_Borrow/` (user)      | ⏳             | ⏳          | shared ✅  |
| `e_Borrow/admin`        | uses style.css | n/a         | n/a        |
| `consumables/`          | TBD            | TBD         | TBD        |
| `asset/`                | TBD            | TBD         | TBD        |
| `insurance_partner/`    | TBD            | TBD         | TBD        |
| `admin/`, `staff/`      | own theme      | n/a         | n/a        |

Tick a box when a module is migrated. Phases 1-2 only deliver the
tokens + build; Phase 4 is the actual page-by-page migration.

---

## 5 — Rules of engagement

1. **No new CSS files for user-facing modules.** If you reach for a
   `<style>` block longer than ~10 lines, add a component to
   `tailwind.src.css` instead.
2. **No raw hex values in markup.** Use tokens (`bg-brand-500`,
   `text-rose-600`, etc.). The only allowed inline color is in dynamic
   PHP-generated styles where a token doesn't fit.
3. **No new font-family declarations.** Use `font-sans`.
4. **Bottom nav is shared.** Always include
   `includes/user_bottom_nav.php` rather than copying the markup.
5. **Admin pages may keep their own theme** but should still use
   `font-sans` and brand tokens for cross-module elements (badges that
   appear in both admin and user contexts, etc.).

When in doubt, mirror the patterns in `user/profile.php` — it's the
reference implementation for the new system.
