# Patient 360 Header and Overview — visual audit against the reference

Reference: the Clinical Workspace image (desktop and mobile). Before: `qa/phase-b/` (captured 2026-10-26, after Phase B). After: `qa/visual-fix/`.

The reference has a top-bar-only shell. Ours keeps the approved sidebar and top bar, which are unchanged by decision, so proportions are compared on the content area, not the whole viewport.

## Mismatches found (before)

| # | Area | Reference | Before | Fix |
|---|---|---|---|---|
| H1 | Header surface | One light card holding identity, facts and safety, with an even soft gradient | A gradient wash that stops after about 144px, leaving a visible seam and a white band under it | Even wash across the whole card; no seam |
| H2 | Avatar | Large round portrait (~96px) beside the name block, vertically centred | A rounded-square 84px tile in a white frame, top-aligned | Larger round initials avatar (photo is a data gap), vertically centred with the name block |
| H3 | Name block | Name; UHID on its own line; "28 years · Female · B+" | Same order, but the UHID label is weak and the spacing loose | UHID as a labelled line; tighter rhythm |
| H4 | Facts row | Five facts in one row with plain blue outline icons, small grey label above a bold value; the branch sub-line under Registered | Facts in heavy filled tiles; on phones a single column (one fact per line) | Lighter icon chips; 5 columns on desktop, 3 on tablet, **2×2 on phones** |
| H5 | Safety row | Three equal panels in one row (Allergies, Alerts, Notes), label and chips inline | Allergies alone at half width, leaving the other half empty when there are no alerts | Allergies and Alerts always shown (Alerts says "None recorded"), Notes for clinical roles; equal columns, label and chips inline |
| H6 | Actions | Check In, Edit, Print, More on one line, top right; on phones one row of four | Correct on desktop; 2×2 on phones | One row on phones (equal widths) |
| N1 | Tabs | Large active tab with a thick underline, sitting directly under the card | Close; spacing above slightly large | Kept; spacing tightened |
| S1 | Summary cards | Four equal cards: icon tile left, content in a column beside it (title, lines), ring on the right, a link pinned to the bottom | Icon and title on one row and content below starting at the card edge; links not bottom-aligned; small icon on a white tile | Two-column card (tile ∣ content), title 16px, content 14px, links pinned to the bottom, tinted icon tile |
| S2 | Summary cards (phones) | Compact horizontal rows: tile, title and two lines, ring or chevron at the right | Tall stacked cards | Compact rows below `sm` |
| S3 | Care card | "1 Active Treatment · Root Canal · Started 12 Oct" plus a ring | No start date (not in the payload) | Start date added from the treatment record (real data) |
| R1 | Row 2 | Clinical activity (~58%) ∣ Conditions & medicines (~42%), equal heights | Unequal heights (`items-start`) | Rows stretch to equal height |
| R2 | Activity timeline | Date chip ∣ dot on a vertical line ∣ icon tile ∣ title and description ∣ doctor pill on the right | Each item in its own bordered box, doctor pill under the text, no connector line | Timeline with a connector line and dots, no inner boxes, doctor pill right-aligned (below on phones) |
| R3 | Conditions | Icon tile, name and "Noted on date", status pill on the right | No icon, no date | Icon tile; "Noted on" date added from the diagnosis record (real data) |
| R4 | Row 3 | Three equal summary cards (Treatment, Appointment, Financial) with tile ∣ content and a ring | Treatment card dropped when there is no treatment, leaving 2 wide cards; large stat tiles in the appointment card; `items-start` gives unequal heights | Always three (Treatment with an empty state); compact tile ∣ content layout; counts as a small line, not tiles; equal heights |
| R5 | Row 4 (not in the reference) | — | Patient information ∣ Documents, unequal | Equal heights, same card language |
| T1 | Type | Card titles ~16px semibold; body 13–14px; labels 12px grey | Mixed 15–16px, labels 12.5px | Unified scale |
| C1 | "View …" links | On every card (Treatment, Appointments, Activity, Bills) | Only where a built page exists | **Unchanged on purpose:** Treatment, Clinical Records, Billing and the timeline are Phase C. A link to a missing page would be a fake action |
| P1 | Photo, "Primary Branch" with city | Portrait; "Karelibagh · Vadodara" | No photo storage; "Registered at" branch (no primary-branch concept, no branch city in the payload) | Gap kept (see the plan, 1a) |
| P2 | "Active Patient" badge | Shown | Not shown | **Never added:** Active/Inactive patient UI is excluded by decision |

## After (`qa/visual-fix/`, 2026-10-26)

Fixed: H1–H6, N1, S1–S3, R1–R5 and T1, plus three issues the re-captures exposed:

- the facts row wrapped "Last visit" alone at 1024; it is now one row of four;
- an odd last card left half a row empty at 768–1279; it now spans the row;
- the masked mobile number was cut off at 1024 and 360.

All 40 screenshots (patients 349 and 350 × owner, dentist, reception and billing × 1440/1024/768/390/360) show no horizontal overflow and no console errors.

**Still unmatched, on purpose:**
- C1: links to sections not yet built.
- P1: photo and city.
- P2: the "Active Patient" badge.
- The sidebar shell, which differs from the reference's top-bar-only shell.

**Not verified in the browser:** the seeded clinic has no consultations, diagnoses, prescriptions, treatments or documents, so the timeline, conditions, rings and documents rows render their empty states in the browser. Their filled layouts are covered by tests and component code only.
