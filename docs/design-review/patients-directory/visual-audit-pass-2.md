# Patients Directory mockup — visual audit (pass 2)

Date: 2026-10-24. Subject: `directory-mockup.html` as of pass 1 and its ten screenshots in `qa/`.
Scope: presentation only. The functional decisions in `docs/architecture/patients-directory-blueprint.md` stand.

## Why pass 1 feels unsatisfactory

**1. No type system.** Fourteen font sizes (10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 16, 18, 20, 22, 26px) and four weights, chosen element by element. Adjacent text differs by half a pixel, so nothing reads as deliberately different and nothing reads as the same. Line heights are left to the browser.

**2. Two fonts fighting in the row.** The patient ID is set in a system monospace inside a grey chip. It looks like code, sits on a different baseline from the Inter beside it, and is the loudest thing in the secondary line — louder than the age it sits next to.

**3. Too much uppercase.** Column labels and every mobile field label are uppercase with tracking. Seven small shouting labels per card on a phone.

**4. The header is under-built.** "Patients" at 26px with a 14px line under it reads as a section label, not a page title. The New Patient button floats on its own to the right with a heavy glow shadow and no alignment to anything.

**5. The search area is a gradient slab.** A teal→blue→violet tinted panel holds a white input, a grey helper line, a row of native selects and a separate row of applied chips: four visual languages in one box. The controls are four different heights (56 search, 44 segmented switch, 40 selects, 32 chips) with three different radii. On phones the dropdown arrow sits at the edge of a stretched wrapper, detached from the pill, and labels truncate ("Registered: A…").

**6. Filters say things twice.** An applied filter appears as the select's value *and* again as a chip in a second row — the same fact in two places, two lines tall.

**7. Avatar colours mean nothing.** Teal, blue and violet rotate by row index, so the list looks striped and colourful without telling the reader anything.

**8. Rows are pieces, not records.** A phone icon on every row, a monospace chip, dots with wide letter-spacing, a date column, a two-line activity column, a status chip in a far-right column that moves into the name area below 1280px, and a chevron in a 112px column that is mostly empty. Each column has its own vertical rhythm; the row has no single baseline.

**9. Stacked grey captions.** "Newest registrations first" + "Only patients seen at your branches" sit over an uppercase column header: two levels of small grey text before the first patient.

**10. Mobile is the desktop shrunk.** Each card has a hairline divider, three uppercase-labelled fields in a 2×2 grid with an empty quarter, and is ~200px tall — two patients per screen at 390px. The New Patient button becomes a full-width slab with a glow shadow. Status and ID collide onto a wrapped line.

**11. 768px uses the desktop table squeezed to 720px.** Names truncate ("Rohan Venkatesh D…"), the activity label wraps onto two lines, columns crowd.

**12. The review panel competes with the design.** A large dark block permanently covers the lower right of every screenshot.

## Direction for pass 2

- **One font, one scale.** Inter (the font the Clinic Portal already loads in `resources/views/app.blade.php`), six sizes, three weights, defined line heights, tabular figures for every number.
- **One control height per breakpoint** (40px desktop, 44px touch), one radius scale (6 / 10 / 12 / 16).
- **One white search-and-filter card**; filters are compact buttons with the label, value and arrow inside one shape; an active filter carries its own remove button. No second chip row.
- **Neutral identity**: one avatar treatment; colour is reserved for status and the primary action.
- **Rows with one rhythm**: two lines everywhere — primary line in ink, secondary line in a quieter grey — status beside the name, no per-row icons, no empty status column.
- **Cards from 1023px down**, two columns at tablet, one on phones, with sentence-case labels and the registration date kept.
- **Review panel collapsed to a small tab** by default; hidden entirely for screenshots.

## Correction received mid-pass: keep it colourful

The first pass-2 draft followed the "one neutral avatar, colour only for status" line above and came out white-and-grey. The direction was corrected: colourful, premium healthcare SaaS, with a coherent colour system. The typography system stayed; colour was added as a system, not decoration.

## What pass 2 delivers

**Type (Inter, already loaded by the Clinic Portal).** Six sizes — 12/16, 13/20, 14/20, 15/22, 20/28, 30/38 — and weights 400/500/600 (700 only for the page title). Tabular figures on every ID, date and number. Sentence case everywhere except the approved shell. Names 15/600 ink; IDs 13/500 ink-2; secondary text 13/400 at 6:1 contrast; nothing below 12px.

**Colour: one job per hue, everywhere on the page.**

| Hue | Job |
|---|---|
| Teal → blue gradient | Primary action (New Patient), chevron on hover, focus ring (cyan) |
| Teal | Status filter |
| Blue / sky | Mobile number, Registered filter |
| Mint | Registration date |
| Violet / lavender | Last activity and branch, Branch filter, branch-scope chip, Archived badge |
| Green-mint · amber · slate · lavender | Badges: Active · Inactive · Deceased · Archived |
| Rose | Errors only |

- **Hero:** a soft teal → sky → lavender surface holds the gradient page mark, the title, New Patient, search and filters as one unit.
- **Filters:** each keeps its own hue in its icon tile. When active it fills with that hue's tint and gains an attached ✕.
- **Avatars:** five coordinated pastel gradients, chosen from the patient's name, so the same patient always has the same colour.
- **Desktop rows:** each fact has a small hue-coded icon tile (blue mobile, mint calendar, violet pulse), and the column labels carry matching dots. Hover adds a soft teal → blue wash and lifts the row.
- **Tablet and phone cards:** a tinted fact band with dot-coded labels.
- **Empty, error and no-results states:** each has its own gradient icon tile and a soft tinted top wash.

**Geometry.** Radius 8 (badges) / 12 (controls) / 14 (rows) / 20 (hero, cards). Controls are 40px on desktop and 44–56px on touch. Spacing is on a 4px scale.

**Responsive.**
- **1440 and 1024:** hybrid rows. At 1024 the icon tiles drop and the Patient column keeps ≥296px so the ID line never wraps.
- **768:** two-column cards.
- **390 and 360:** one-column cards.
- **Phone filters:** an even two-column grid with the label above the value. Active filters move last and take a full row.
- **Phone header:** "New Patient" shortens to "New" below 380px.

**Known limitation.** At 1024 the longest fictional name ("Rohan Venkatesh Desai") pushes its status badge onto a second line, making that one row taller. Nothing is clipped.

## Pass 4 — Compact Premium (2026-10-24)

Measured in the browser at each viewport, Owner · all branches, filters closed. Pass 3 is preserved at `qa/pass-3/directory-mockup-pass-3.html`.

| Viewport | First patient row (pass 3 → 4) | Rows fully visible (3 → 4) | Row / card height (3 → 4) |
|---|---|---|---|
| 1440 × 900 | 508px → **267px** | 4 → **9** | 76 → **64px** |
| 1024 × 768 | 564px → **267px** | 2 → **6** | 76 → **64px** |
| 768 × 1024 | 528px → **255px** | 4 (2-col cards) → **10** (rows) | 207 → **64px** |
| 390 × 844 | 434px → **231px** | 1 → **4** | 199 → **133px** |
| 360 × 780 | 434px → **231px** | 1 → **3** | 199 → **133px** |

With three filters applied, the first row sits at 307px on desktop (pass 3: 508px) and 361px on phones (pass 3: 562px).

**What changed**
- The hero container is gone. The header is one line: title with a gradient accent bar, subtitle, and New Patient.
- One 44px toolbar holds search, the doctor's My/All switch and a Filters button with an applied count. On phones the button shows only its icon and count, with an accessible label.
- Filters open in a 560px popover on desktop and tablet, and inline on phones. Status · Registered · Assigned doctor · Branch appear per role.
  - Esc and click-outside close the popover; focus returns to the Filters button.
- Applied filters show as compact coloured chips with ✕ and Clear all. They are hidden while the popover is open, so values never show twice.
- Desktop and tablet rows are 64px:
  - a 36px avatar;
  - name and status badge on line 1; ID, age and sex on line 2;
  - small hue-coded icons instead of 30px tiles.
- Phone cards drop the tinted band. They show three aligned label/value lines: Mobile, Registered, Last activity.
- At 768 the compact row replaces two-column cards. All four columns fit in 720px, which shows 10 patients instead of 4.

**Known limitation:** below 1280px the longest fictional name ("Rohan Venkatesh Desai") pushes its status badge onto a second line, so that one row is 85px.

## Pass 5 — original patient visuals, compact top only (2026-10-24)

Pass 4 shrank the patient list too far. Pass 5 restores the pass 2/3 patient presentation exactly and keeps only pass 4's compact header and toolbar.

**Restored from pass 3**
- Rows: 44px avatars with gradient and inset highlight, 14px padding / 20px sides, 14px radius, 10px gaps, soft shadow and hover lift.
- Icon tiles: 30px hue tiles for mobile, registered and last activity.
- Status badges: 24px.
- Tablet: two-column cards. Phones: one-column cards, each with the tinted fact band.

**Kept from pass 4**
- No hero container. A compact header holds the original gradient mark (44px desktop, 36px phone), title, subtitle and the teal→blue New Patient button.
- A 44px toolbar: search, the doctor's scope switch and Filters with a count.
- The filter popover (inline on phones) and the applied chips.

**Role titles**
- Owner (Owner Mode, including a doctor-owner): "Patients" · "Everyone registered at this clinic." · no switch.
- Doctor: "My Patients", with the subtitle following scope ("Patients assigned to you as their doctor." or "Everyone seen at your branches."), and the My Patients / All Accessible switch.

| Viewport | First row: pass 3 → 4 → **5** | Rows visible: 3 → 4 → **5** | Row / card: 3 → 4 → **5** | Avatar: 3 → 4 → **5** |
|---|---|---|---|---|
| 1440 × 900 | 508 → 267 → **279px** | 4 → 9 → **7** | 76 → 64 → **76px** | 44 → 36 → **44px** |
| 1024 × 768 | 564 → 267 → **279px** | 2 → 6 → **5** | 76 → 64 → **76px** | 40 → 36 → **40px** |
| 768 × 1024 | 528 → 255 → **243px** | 4 cards → 10 rows → **6 cards** (3 pairs) | 207 → 64 → **207px** | 44 → 36 → **44px** |
| 390 × 844 | 434 → 231 → **247px** | 1 → 4 → **2** | 199 → 133 → **199px** | 44 → 36 → **44px** |
| 360 × 780 | 434 → 231 → **247px** | 1 → 3 → **2** | 199 → 133 → **199px** | 44 → 36 → **44px** |

Header block: 50px desktop, 46px phone. Toolbar: 44px (96px on phones for doctors, whose scope switch sits on its own row).
