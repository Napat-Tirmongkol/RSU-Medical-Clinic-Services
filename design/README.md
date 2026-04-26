# Handoff: RSU Medical — User Hub Dashboard

## Overview

A **User Hub** dashboard (`hub.php`) for the RSU Medical Clinic Services university healthcare portal. This is the primary authenticated landing page for students and staff. It shows:
- Identity verification status
- Upcoming clinic / E-Vax appointments
- Accident insurance wallet
- Quick-action shortcuts
- Notifications feed
- Open vaccination campaigns
- At-a-glance health stats
- Wellness tip banner

---

## About the Design Files

The files in this bundle are **design references created as HTML prototypes**. They demonstrate intended look, layout, interactions and copy — they are **not production code to copy directly**.

Your task is to **recreate these designs inside the project's existing tech stack** (Vite + React 18 + Tailwind CSS v4 + shadcn/ui + Lucide React), matching the visual fidelity as closely as possible while following the codebase's established patterns.

Open `index.html` in any browser to view the interactive prototype. Use the **Tweaks** button (bottom-right) to toggle language, accent, density, and card states.

---

## Fidelity

**High-fidelity.** Colors, typography, spacing, card layouts, hover states, transition animations, and copy are all finalized. Recreate pixel-accurately using the project's existing Tailwind + shadcn setup.

---

## Tech Stack (target project)

From `package.json` already in the project:

| Concern | Library |
|---|---|
| Framework | React 18.3.1 + Vite 6 |
| Styling | Tailwind CSS v4 |
| Components | shadcn/ui (Radix UI primitives) |
| Icons | `lucide-react` 0.487.0 |
| Charts (if needed) | `recharts` |
| Motion | `motion` (Framer Motion v12) |
| Forms | `react-hook-form` |
| Routing | `react-router` v7 |

---

## Screens / Views

### 1. Desktop — Bento Grid (primary, 1400px wide)

**Layout:** 12-column CSS grid, gap 18px. Sections:

```
[ Profile card (col 1–8) ]  [ Insurance wallet (col 9–12) ]
[ Appointment (col 1–5) ]  [ Quick actions (col 6–9) ]  [ Notifs (col 10–12) ]
[ Stats strip (col 1–8) ]  [ Campaigns (col 9–12) ]
[ Wellness tip (col 1–12, full-width) ]
```

At ≤1100px: profile spans full 12 cols; wallet/appointment/qa/notifs go to 6-col pairs.

---

### 2. Mobile — Single Column Stack (390px)

Same cards stacked vertically with 14px gaps. Sticky top bar + fixed bottom nav (5 items, centre = FAB for booking, elevated 12px).

---

### 3. Dark Premium Variant

Same desktop bento layout but with dark tokens applied (see Design Tokens below).

---

## Components

### TopBar
- **Left:** Logo (SVG cross icon) + "RSU Medical / User Hub" label
- **Centre nav:** Home · Services · Appointments · Help (active = accent-50 bg + accent-600 text, border-radius 10px)
- **Search bar:** `⌘K` shortcut chip, 420px wide, 38px height, border radius 10px
- **Right:** Language toggle (EN/TH), Bell (with red ping dot), Avatar button (32×32, border-radius 10px)

### Profile Card
- Background: radial gradient blobs (accent at 28% and 18% opacity) + decorative concentric circles (top-right, accent 8% opacity)
- **Avatar:** 56×56, border-radius 16, accent-100 bg, "TP" monogram in accent-600. Green tick badge (20×20, border-radius 50%, ok colour, 2.5px white border)
- **Name:** 22px, weight 600, letter-spacing −0.01em
- **Sub-label:** ID + membership year, 12px, ink-3
- **QR button:** ghost icon button top-right
- **Verified strip:** accent-50 bg, accent-100 border, border-radius 12. Shield icon in accent circle (22×22, radius 6). "Identity Verified · Thai National ID · Cleared 22 Feb"

### Appointment Card — Booked state
- **Date block:** 72px wide, rounded 12, blue header strip (MON), large date (28px 700), day-of-week
- **Info:** Service name (15px 600), Doctor + location (13px ink-3)
- **Chips:** accent-50 pill with clock icon + time, grey pill with map pin + location, booking number
- **Actions (below divider):** "Cancel appointment" ghost button → red hover state (`border #fecaca, color #dc2626, bg #fee2e2`), "Add to calendar" ghost

### Appointment Card — Empty state
- SVG calendar illustration (accent-50/100 colours)
- Title + hint copy
- Primary "Book now" button with loading/spinner state (1.8s, white spinning ring)

### Insurance Wallet Card
- Full-card gradient: `linear-gradient(135deg, --accent 0%, --accent-600 100%)`
- White text throughout
- Decorative SVG overlay (subtle radial + wave)
- Active/Expired pill: white bg at 18% opacity, green/red dot with glow
- Balance: currency symbol 18px + amount 34px 600 + "/ 100,000" label
- Progress bar: 5px height, white at 20% for track, white fill for progress
- Footer: two metadata columns (Policy / Coverage) + chevron right

### Quick Actions Grid
- 2×2 grid of action buttons, gap 8px
- Each: `flex row`, icon box (36×36 radius 10) + text column (title 13px 600 + subtitle 11.5px ink-3) + chevron right
- Hover: surface bg, accent-200 border, translateY(−1px)
- Icon tones:
  - Book campaign → accent-100 bg / accent-600 icon
  - Visit history → `#ede9fe` bg / `#7c3aed` icon
  - Medical records → `#ccfbf1` bg / `#0d9488` icon
  - Settings → `#e2e8f0` bg / `#475569` icon

### Notifications Card
- List of 3 items, gap 10px
- Each item: `#f5f7fb` bg, border-radius 10, left-edge coloured dot (6×6)
  - Info → accent dot; OK → green dot; Warn → amber dot
- Title (12.5px 600) + timestamp (11px ink-4, right-aligned) + body text (11.5px ink-3)

### Campaigns Card
- 3 rows, each: icon circle (30×30) + text (title 13px / date 11px ink-3) + chevron
- Same icon tone system as Quick Actions

### Stats Strip
- 4-column grid, dividers between columns
- Each stat: label (11px uppercase tracking) + value (22px 600) + delta (11.5px ink-4)

### Wellness Tip Banner
- Full-width, gradient from accent-50 → surface (left to right)
- accent border, left icon (36×36 accent circle with sparkles)
- "Wellness tip" eyebrow (11px uppercase accent-600) + body text (13.5px 500)

---

## Interactions & Behaviour

| Element | Behaviour |
|---|---|
| Any card hover | `border-color: --accent-200`, `box-shadow` lift, `translateY(-1px)`, 200ms ease |
| Quick action button hover | Background to surface, accent border, chevron slides right 2px |
| Cancel appointment hover | Red tint: border `#fecaca`, text `#dc2626`, bg `#fee2e2` |
| Primary button active | `translateY(1px)` |
| Book button (loading) | Replace content with white spinner ring + "Booking…", disable button, reset after 1800ms |
| Language toggle | Swap all copy EN ↔ TH, name changes to Thai variant |
| TopBar search | Keyboard shortcut ⌘K focuses input |
| Bell icon | Red 7px ping dot (absolute positioned) |
| Mobile FAB | `position: fixed`, `translateY(-12px)`, accent bg, drop shadow |

---

## State Management

```ts
// Minimum state per page
interface HubState {
  lang: 'en' | 'th'
  appointment: AppointmentData | null   // null = empty state
  insurance: { status: 'active' | 'expired', balance: number, cap: number }
  notifications: Notification[]
  campaigns: Campaign[]
  bookingLoading: boolean
}
```

Data should come from your existing API layer. The prototype uses hardcoded mock data — replace with real fetches.

---

## Design Tokens

### Light mode
```css
--accent:      #2563eb
--accent-600:  #1d4ed8
--accent-50:   #eff6ff
--accent-100:  #dbeafe
--accent-200:  #bfdbfe
--bg:          #f5f7fb
--surface:     #ffffff
--ink:         #0f172a
--ink-2:       #334155
--ink-3:       #64748b
--ink-4:       #94a3b8
--line:        #e5e8ef
--line-2:      #eef1f6
--ok:          #16a34a
--ok-bg:       #dcfce7
--warn:        #ca8a04
--warn-bg:     #fef9c3
--danger:      #dc2626
--danger-bg:   #fee2e2
--radius:      16px
--radius-sm:   10px
```

### Dark mode (overlay on top of light tokens)
```css
--bg:          #0b0f17
--surface:     #121826
--ink:         #f1f5f9
--ink-2:       #cbd5e1
--ink-3:       #94a3b8
--ink-4:       #64748b
--line:        #1e2636
--line-2:      #161c29
--accent-50:   #0f1b3a
--accent-100:  #17244d
--accent-200:  #1f3270
/* bg also gets a radial blue + violet glow */
background: radial-gradient(1200px 600px at 10% -10%, rgba(37,99,235,.25), transparent 60%),
            radial-gradient(800px 500px at 100% 0%, rgba(139,92,246,.14), transparent 60%),
            #0b0f17;
```

### Typography
- **Font:** `'Prompt'` (Google Fonts) as primary; fallback `'Inter'`, `ui-sans-serif`, `system-ui`
- Heading (greeting): 28px / 600 / letter-spacing −0.015em
- Card section label: 13px / 600 / uppercase / letter-spacing 0.06em / ink-3
- Body: 13–14px / 400–500
- Micro/label: 11–12px

### Spacing scale
- Card padding: 20px (compact 14px, comfy 24px)
- Bento gap: 18px (compact 12px, comfy 22px)
- Border radius: card 16px, buttons 10px, chips 999px

### Shadows
```css
--shadow-card:  0 1px 2px rgba(15,23,42,.04), 0 1px 0 rgba(15,23,42,.02)
--shadow-hover: 0 10px 30px -12px rgba(37,99,235,.25), 0 2px 6px rgba(15,23,42,.06)
button primary: 0 1px 2px rgba(37,99,235,.25), 0 4px 14px -4px rgba(37,99,235,.45)
```

---

## Copy (English)

All strings are in `hub.jsx` under `HUB_COPY.en` and `HUB_COPY.th`. Use these exact strings for both languages.

**User shown:** Thanakorn P. (Thai: ธนากร พ.), Student ID 6504123

---

## Icons

The prototype uses hand-drawn inline SVG stroke icons. In the target project, use **`lucide-react`** equivalents:

| Prototype name | Lucide component |
|---|---|
| IconShield | `ShieldCheck` |
| IconCalendarPlus | `CalendarPlus` |
| IconClock | `Clock` |
| IconBell | `Bell` |
| IconStethoscope | `Stethoscope` |
| IconSyringe | `Syringe` |
| IconClipboard | `ClipboardList` |
| IconHistory | `History` |
| IconSettings | `Settings` |
| IconSparkles | `Sparkles` |
| IconWallet | `Wallet` |
| IconHeart | `Heart` |
| IconSearch | `Search` |
| IconQr | `QrCode` |

---

## Files in this Bundle

| File | Purpose |
|---|---|
| `index.html` | Entry point — open in browser to view all 3 variants |
| `hub.jsx` | All card components + copy strings (design reference) |
| `variants.jsx` | Mobile + dark variants |
| `styles.css` | All CSS tokens + layout (read for measurements) |
| `app.jsx` | Design canvas + Tweaks wiring (ignore for implementation) |
| `icons.jsx` | Prototype icon set (replace with lucide-react) |
| `design-canvas.jsx` | Canvas shell (prototype utility, not needed in prod) |
| `tweaks-panel.jsx` | Tweaks panel (prototype utility, not needed in prod) |

---

## Implementation Notes for Claude Code

1. **Start with Tailwind tokens** — add the CSS custom properties above to your `globals.css` or Tailwind config
2. **Use shadcn/ui Card** as the base for each Bento box; extend with custom class variants
3. **Replace the bento grid** with CSS Grid (`grid-cols-12`) using Tailwind responsive prefixes
4. **Insurance card** — use a `div` with `bg-gradient-to-br` from accent to accent-600; overlay a semi-transparent SVG for the decorative blobs
5. **Appointment booking button** — use `react-hook-form` + a `useState` loading flag, not a bare `setTimeout`
6. **Mobile bottom nav** — use `fixed bottom-0` with `safe-area-inset-bottom` padding
7. **i18n** — wire `HUB_COPY` to your i18n system (or `next-intl` / `react-i18next`) using the provided EN/TH strings as the translation source

---

*Generated by Claude · RSU Medical User Hub design handoff · April 2026*
