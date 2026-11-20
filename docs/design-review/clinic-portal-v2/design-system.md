# OralCares Clinic Portal v2 — Visual Design System (proposal)

> **Status: PROPOSAL FOR REVIEW. Nothing here is implemented.**
>
> The primary visual reference is the healthcare dashboard image supplied on 2026-10-23. Its brand, text and data are not reused.
>
> The reference mockup is [`doctor-dashboard.html`](doctor-dashboard.html). Every figure in it is illustrative.

The language, in one line: a soft aqua-to-lavender ambient canvas holding one large rounded light workspace. Inside it sit white rounded cards, pastel KPI accents, a single violet primary colour, and one teal-to-violet gradient panel per page for the time-based view.

---

## 1. Colour palette

### Canvas and surfaces

| Token | HEX | Use |
|---|---|---|
| `canvas-aqua` | `#D3F1EE` | Top-left radial glow of the page canvas |
| `canvas-lavender` | `#E5E1FA` | Bottom-right radial glow |
| `canvas-mist` | `#F1F4FB` | Canvas base |
| `workspace` | `#F6F7FB` (88% opacity, 6px backdrop blur) | The large rounded working area |
| `card` | `#FFFFFF` | Every card and table surface |
| `line` | `#E9EBF3` | Card borders and table rules |
| `line-strong` | `#DADDEA` | Secondary-button and input borders |
| `ambient-line` | `#FFFFFF` at 70% opacity, 1.2px | Decorative curves on the canvas only |

### Ink

All contrast ratios are measured on `#FFFFFF`.

| Token | HEX | Contrast | Use |
|---|---|---|---|
| `ink` | `#1B1D3A` | 16.0:1 | Headings, names, figures |
| `ink-2` | `#474C66` | 8.5:1 | Body text, reasons |
| `ink-3` | `#636984` | 5.4:1 | Captions and metadata. The lightest text allowed (AA) |

### Accents

Each accent has three forms:

- **base**: fills and icons;
- **soft**: tinted backgrounds;
- **ink**: text on the soft background, AA at 12px or larger.

| Family | Base | Soft | Ink | Meaning |
|---|---|---|---|---|
| Violet (primary) | `#7357E6` (hover `#5F43D6`) | `#EFEBFF` | `#5232C9` | Primary actions, selection, "with doctor", repeat case |
| Teal | `#13B3A3` | `#E0F6F3` | `#0B7A6F` | Done, healthy, new case, progress |
| Cyan | `#2BB8DE` | `#E0F4FB` | `#0A6F8C` | Called, information |
| Lavender | `#B7A6F4` | `#F3F0FE` | `#5232C9` | Secondary pastel, repeat-case tint |
| Amber | `#F59E0B` | `#FFF2D9` | `#8F5200` | Waiting, pending, due |
| Rose | `#EE5470` | `#FFE7EC` | `#AE2140` | Emergency, allergy and safety flags, overdue, drafts |
| Green | `#22C55E` | `#E3F7EA` | `#13703A` | Paid, collected (financial pages) |

Usage rules:

- One violet primary button per view.
- Rose is kept for safety and urgency. It is never used for decoration.
- No neon colours, and no rainbow sequences: a chart uses at most 3 accents.

### Approved navigation (unchanged)

The sidebar and top bar keep today's values: `nav-bg #EEF5FB`, active item `#DDF2F2`, teal marker `#00A8A8`, nav ink `#0B1F33`, muted text `#4A5D73` and line `#D6E3EF`.

Restyling them to match the reference is decision **V-01** and is not assumed.

---

## 2. Gradient recipes

| Name | Recipe | Where |
|---|---|---|
| Canvas | `radial-gradient(1100px 700px at 8% 0%, #D3F1EE, transparent 60%), radial-gradient(900px 700px at 100% 100%, #E5E1FA, transparent 62%), #F1F4FB` | Page background only |
| Schedule panel | `linear-gradient(165deg, #0EA394 0%, #2B86D6 52%, #6B48DE 100%)`, plus a 135° diagonal hatch: white at 6%, 2px in every 14px | The single "time" panel per page (appointments, queue timeline, day plan) |
| Primary button | `linear-gradient(135deg, #7F63EE 0%, #5F43D6 100%)` | Primary buttons and initials avatars |
| KPI meters | `#FFC56B → #F59E0B`, `#B7A6F4 → #7357E6`, `#6FE0D2 → #13B3A3`, `#F8A3B3 → #EE5470` | 6px progress bars in KPI cards |
| KPI corner glow | radial soft colour → transparent, 120px, 50% opacity, bottom-right | Pastel accent inside a white KPI card |

Rules:

- At most one strong gradient panel per page.
- White text on the schedule gradient must be 18px semibold or larger (contrast is at least 3:1 everywhere on the gradient).
- Smaller content on the gradient sits on white cards at 94% opacity, using ink colours.

---

## 3. Typography

The family is **Plus Jakarta Sans** (400, 500, 600, 700), falling back to the system UI font. Figures use `font-variant-numeric: tabular-nums`.

| Role | Size / line height | Weight | Letter spacing |
|---|---|---|---|
| Page heading (H1) | 28 / 32 (24 on phones) | 600 | -0.02em |
| Greeting line | 14 / 20 | 500, `ink-2` | 0 |
| Panel heading on gradient | 18 / 24 | 600 | 0 |
| Card title | 15.5 / 22 | 600 | 0 |
| KPI figure | 30 / 34 (26 on phones) | 600 | -0.02em |
| Body and table cell | 14 / 20 | 500 or 600 for names | 0 |
| Secondary line | 13 / 18 | 400, `ink-2` | 0 |
| Caption, metadata, badge | 12–12.5 / 16 | 600 for badges | 0 |
| Section label (nav) | 11 / 14 | 700, uppercase | 0.08em |

The minimum text size is 12px; nothing smaller. The required density (roughly the feel of 80–85% zoom) comes from layout: 14px gaps, 16–18px card padding and 44px controls. Type is never scaled down to get there.

---

## 4. Shape, spacing and depth

| Token | Value |
|---|---|
| Workspace radius | 32px (24px on phones) |
| Card radius | 20px |
| Gradient panel radius | 24px |
| Inner tile and list-icon radius | 11–14px |
| Control radius | 12px for inputs; pill (999px) for buttons, chips, badges and search |
| Spacing scale | 4 · 6 · 8 · 10 · 12 · 14 · 16 · 18 · 22 · 24 |
| Grid gaps | 18px between major blocks, 14px between KPI and lower cards |
| Card padding | 16–18px; table cells 11px × 18px |
| Desktop columns | main `minmax(0, 1fr)` + time panel `372px` |

Shadows:

- Card: `0 1px 2px rgba(27,29,58,.04), 0 10px 28px -18px rgba(27,29,58,.22)`
- Raised (hover or dialog): `0 2px 6px rgba(27,29,58,.06), 0 22px 48px -24px rgba(56,40,140,.35)`
- Workspace: `0 40px 90px -50px rgba(60,48,140,.45)`
- Gradient panel: `0 24px 50px -28px rgba(70,50,190,.7)`

Glass effects are limited to the workspace's 6px blur. There are no frosted cards.

---

## 5. Icons and charts

- **Icons:** Lucide line icons (already a dependency), 1.8px stroke, 16–19px. KPI icons sit in a 36px tile with a 12px radius on the soft accent, drawn in the accent's ink colour.
- **Donut charts:** 6-unit ring, round caps, the total in the centre (700 weight), and a legend with values. At most 3 segments.
- **Bar and column charts:** rounded tops (6px), the violet family, and hatched pale columns for "planned" against solid for "done", as in the reference's Workload card.
- **Gauge (reserved):** a semicircle in pastel stops, used only where a real score exists. No patient-satisfaction score exists today, so it isn't used (see decision **V-04**).
- **When charts are allowed:** only when the figure has a real source and an action follows from it. No decorative sparklines, and no trend deltas (+23%) without a stored history to compare against.

---

## 6. Components

| Component | Specification |
|---|---|
| Primary button | 44px tall, pill, primary gradient, white 14/600 text, shadow `0 10px 22px -12px rgba(95,67,214,.9)`. On hover it lifts 1px and the shadow grows |
| Secondary button | 44px, pill, white, `line-strong` border, `ink` text. On hover the border turns violet and the text `violet-ink` |
| Row action | 34px, pill, 12.5/600 text. The next-in-line row uses solid violet; the others are outlined |
| Icon button | 44 × 44px circle, white with a `line` border. A 17px rose count badge sits top-right with a 2px white ring |
| Search | 44px pill, white, search icon, ellipsis placeholder, `Ctrl K` hint (hidden on phones) |
| Input or select | 44px, 12px radius, `line-strong` border. Focus adds a 3px `rgba(115,87,230,.45)` ring |
| Chip (context) | 28px pill, 12.5/600, soft background with ink text (branch, specialty) |
| Status badge | 26px pill, 12/600, a 6px dot in `currentColor`, soft background with ink text. Never colour alone: always a label |
| Tabs (segmented) | Container `#F1F2F8` with 4px padding; the selected tab is white with a small shadow and `violet-ink` text, and shows a count |
| KPI card | White card, 14px title, 36px icon tile, 30px figure, 6px meter and a 12px note. The pastel corner glow matches the icon |
| Table | Header 12/600 `ink-3`, rows 11px vertical padding with an `#F0F1F6` rule, hover `#FAFAFE`. Below 1024px it becomes stacked row-cards |
| Patient identity | A 38px initials avatar on a pastel gradient, name 14/600, `case · age sex · UHID` at 12px |
| Safety flag | Rose ink, alert icon and a short label (for example "Penicillin allergy"). Always visible beside the reason |
| Dialog | Centred modal, 24px radius, raised shadow, `rgba(27,29,58,.35)` overlay with 2px blur. **No drawers or slide-overs** (standing rule) |
| Empty, loading and error states | Inside the card's own frame: a soft icon tile, a one-line title and one action. Skeleton bars use `#EEF0F6` with a slow shimmer (disabled under reduced motion) |

---

## 7. Motion

| Interaction | Rule |
|---|---|
| Entrance | Each block rises 10px and fades in over 520ms, `cubic-bezier(.2,.7,.2,1)`, staggered 40–60ms, once per page load. Never on live refresh |
| Meters and progress | Grow from the left over 900ms after a 250ms delay, once |
| Hover | Cards lift 2px and gain the raised shadow; buttons lift 1px; rows tint; all in 180ms |
| Focus | A 3px violet ring at 45% opacity with a 2px offset, on every control. Never removed |
| Dialog | Overlay fades in over 160ms; the panel scales 0.98 → 1 and fades over 200ms; closing takes 140ms |
| Live data | Values swap without animation. A changed count may flash its soft background once (600ms). Nothing pulses on a loop |
| Reduced motion | Under `prefers-reduced-motion: reduce`, every animation and transition is disabled. State changes are instant |

---

## 8. Responsive behaviour

| Width | Behaviour |
|---|---|
| ≥ 1280px | Sidebar, then the workspace: a main column plus the 372px gradient time panel (sticky). 4 KPIs in a row, 3 lower cards |
| 1024–1279px | Sidebar stays. The time panel drops below the main column at full width. KPIs 2 × 2, lower cards 2 + 1 (the last spans the row) |
| 768–1023px | The sidebar moves behind the menu button (same items). The clock and account name are hidden. The queue table becomes stacked row-cards |
| < 768px | Workspace radius 24px and 12px padding. Search drops to its own row. Each queue row shows identity and action, then the reason, then visit, status and wait on one line. Lower cards in one column. The secondary heading action is hidden; the primary stays |

At every width there's no horizontal page scroll, and every touch target is at least 44px (Definition of Done item 9). Verified in the mockup at 774px and 375px; at 1440px by measurement.

---

## 9. Accessibility

- **Contrast:** all body text is AA (minimum `ink-3`, 5.4:1). Badge ink on its soft background is at least 4.6:1. White on the violet button is 4.9:1 or better.
- **Colour:** never the only signal. Every status has a text label, and safety flags have an icon and words.
- **Landmarks and semantics:** sidebar `nav`; `main`; each card a `section` or `article` with a heading; tabs use `role="tab"` with `aria-selected`; today's date carries `aria-current="date"`.
- **Charts:** each has `role="img"` and an `aria-label` that states the numbers.
- **Keyboard:** everything is reachable, focus is visible, and dialogs trap focus and return it on close.
- **Text scaling:** layouts reflow at 200% zoom. Text is never fixed below 12px.
