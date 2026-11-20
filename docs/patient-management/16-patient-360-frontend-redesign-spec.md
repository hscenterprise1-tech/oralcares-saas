# Patient 360 — Frontend Redesign Specification (from zero)

**Date:** 2026-11-02 · **Status:** design for owner approval, nothing implemented.
**Scope:** frontend only. Backend, endpoints, policies, `PatientCaseLifecycle`, billing rules, permissions, schema: unchanged.
**Visual reference:** the Owner Dashboard (`ClinicPortal/CommandCenter`) — its `Panel`, `IconChip`, tones (teal / blue / violet + amber / rose / mint as meaning), wash gradients, `shadow-cx-card`, 24px radius scale. No `@/ovs/*`, no `crmTheme`, no OEDL tokens.

---

## 1. Audit of the current Patient 360 frontend

| File | Lines | What it is | Verdict |
|---|---|---|---|
| `Patients/Show.tsx` | ~230 | Zone switch (reuses `SectionNav` pills) + a second row of sub-pills; renders one section at a time | **Replace.** Zones read as small tabs; two pill rows; no layout per zone |
| `patient360/PatientHeader.tsx` | ~330 | Identity, actions, death dialog, transfer | **Rebuild** visually; keep its action wiring (check-in → Repeat popup, transfer dialog, death governance, print, edit) |
| `patient360/SafetyStrip.tsx` | ~630 | Conditions, allergies, alerts, notes editors | **Reuse only** the condition and notes saving calls; its allergy, alert and extra-history UI is **not** carried into the redesign (§3a) |
| `patient360/Overview.tsx` | ~700 | 7 cards from the deferred `overview` prop | **Rebuild** layout; reuse its data mapping |
| `patient360/Sections.tsx` | ~1500 | Prescriptions, Investigations, Billing (+ own-fees), Documents (+ upload, governed removal), Messages, Follow-ups, Treatment | **Split** per zone; keep fetch hooks, dialogs and payload types |
| `patient360/RecordSections.tsx` | ~560 | Timeline, Appointments, Consents, Audit, Clinical (encounters), Requests | **Split** per zone |
| `patient360/VisitsHistory.tsx` | ~290 | Visits list | **Rebuild** as Visits zone list |
| `patient360/DentalSection.tsx` | ~750 | FDI chart, plans, imaging, lab, perio, recalls | **Keep the FDI chart and actions**, re-house in Clinical › Dental |
| `patient360/PhysioSection.tsx` | ~455 | Plan, sessions, packages, trends, assessments, discharges | **Keep content**, re-house in Clinical › Physio |
| `patient360/CasesSection.tsx` | ~300 | Cases list + lifecycle dialog + Active Cases bar | **Rebuild** visuals; keep lifecycle dialog logic |
| `patient360/SectionNav.tsx` | ~45 | Pill track | **Retire** from Patient 360 (keep file; unused) |

Problems: everything is the same white-card list; zones look like filters; no per-zone layout (no main/side split); repeated identity across cards; mobile is a long pill scroll; raw Tailwind `slate-*` in places.

## 2. New information architecture

```
┌─────────────────────────────────────────────────────────────┐
│ A. PATIENT HEADER (persistent)                               │
│    identity · status · module chips · safety band · actions  │
├─────────────────────────────────────────────────────────────┤
│ B. ACTIVE CASES (persistent, horizontal cards, select one)   │
├─────────────────────────────────────────────────────────────┤
│ C. ZONE BAR: 7 icon tiles (desktop) / segmented scroller     │
├─────────────────────────────────────────────────────────────┤
│ D. ZONE CANVAS: zone-specific grid (main 8 / side 4 cols)    │
└─────────────────────────────────────────────────────────────┘
```

- Zone keys and their sections are the server's (`props.zones`, `Patient360SectionPolicy::ZONES`). A zone the member cannot open is not drawn.
- **Case context**: selecting an Active Case card sets `selectedCase` (client state, URL `?case=`). It drives the Clinical zone's module (Dental vs Physio) in combined clinics and highlights that case in Cases & Plans. It does **not** filter server data (endpoints take no case parameter — see §8 gaps).
- URL: `?zone=` (+ `?case=`); legacy `?section=` resolves to its zone.

## 3. Patient Header (A)

**Actions are only the server's** (`props.actions`, each already permission-checked; none invented):
- Check in → the existing Repeat Case popup (its new-episode choice is how an existing patient starts a new case).
- Start / Continue consultation → the existing consultation flow (queue-linked, Phase 1).
- Bill consultation → the existing Checkout.
- Edit profile, Print.
- Assign doctor and Record death → only through the existing transfer and governed death dialogs.

**Desktop (≥1280):** one white surface, 24px radius, a 4px gradient edge (teal→blue→violet) on the left like the Owner Welcome card.

| Left (identity) | Middle (facts) | Right (actions) |
|---|---|---|
| Initials tile (no photo), **Name** (22/600), UHID pill (mono), age · gender, status pill (Active / Deceased / Merged) | Mobile (full or ••1234 per `contact_visible`), Registered on (`registered_on`, `registered_branch`), Assigned doctor (`practitioner`), Last visit (`last_visit_on`), module chips from `context` (Dental / Physio) | Primary: **Check in** (opens Repeat Case popup — server `check_in` action) or **Start/Continue consultation** (clinical); secondary: **Bill consultation** (when offered), **Edit profile**, **Print**; More ▾: Assign doctor, Record death (governed) |

- **Medical history line** under the identity row (replaces the old safety strip; see §3a): compact chips of the recorded New Case conditions only, plus a "Notes" chip when notes exist. No allergies, no safety alerts, no other history fields.
- **New Case** for an existing patient = the Repeat popup's "new episode" choice (no separate server action exists; see gaps). Registering a *new* patient stays on the Front Desk / Patients page (not in P360).
- Excluded fields (photo, alternate mobile, marital status, WhatsApp number) are not shown even though `profile.alternate_phone` exists.
- **Tablet:** facts wrap under identity; actions collapse to Primary + More. **Mobile:** sticky compact bar (name, UHID, primary action icon); full header scrolls away; actions in a bottom sheet-style menu (centred modal, no drawer).

## 3a. Medical history — the approved New Case form is the single source of truth (owner correction, final)

Patient 360 shows exactly the New Case medical history (`intake/MedicalHistorySection.tsx`), nothing more:

| # | Field | Stored as (existing contract, unchanged) |
|---|---|---|
| 1 | Diabetes | condition entry, key `diabetes` |
| 2 | Blood Pressure | key `hypertension` |
| 3 | Heart Problem | key `heart_disease` |
| 4 | Asthma | key `asthma` |
| 5 | Thyroid | key `thyroid_disorder` |
| 6 | Kidney Disease | key `kidney_disease` |
| 7 | Epilepsy | key `epilepsy` |
| 8 | Other Condition | a condition entry without one of the keys above (its typed name); the input appears only when "Other Condition" is selected |
| 9 | Medical History Notes & Remarks | the medical-history notes |

- **Read:** `context.medical_conditions` (each with key/label as recorded) and `context.medical_notes` (+ updated on / by). Any recorded condition whose key is not one of the seven is shown under **Other Condition** with its own name — never as a new field.
- **Write:** the existing `condition_editor.url` (PUT `patients/{p}/clinical-entries/condition`) and `safety_editor.notes` (PUT `patients/{p}/medical-notes`), offered only when the server sends them. The editor is the same 8 toggle tiles + notes textarea as New Case (same order, labels, colours); "Other Condition" reveals its text input only when selected.
- **Header:** chips of the recorded conditions (max 4, "+n"), and a Notes chip; clicking opens Clinical → Medical History. Shown only when the server sends the data (clinical roles); otherwise the line is not drawn.
- **Removed from the redesign:** allergies and any allergy section, allergy badges, safety alerts, the drug-allergy matching UI, medications, surgical / family history, and any other old-P360 medical fields. The backend data and endpoints for them stay untouched; the redesign simply does not render them.

## 4. Active Cases (B)

- Horizontal scroller of case cards (min 260 / max 340px), max 4 visible on desktop, snap-scroll on mobile. Hidden when there are none (one quiet line "No open cases" for clinical roles).
- Card: module icon tile (Dental = blue `Smile`, Physio = violet `Activity`, General = slate), **title**, specialty · status pill (Active / Completion recommended), Lead: name, since date, progress line (`summary[]`: treatment "2 of 3 stages", "6 sessions left"), **Open case** button.
- Selected card: ring + tinted wash; selecting sets `selectedCase`.
- Never shows the internal case number (not in payload).
- **Reception (and any non-clinical member):** title, specialty, status, lead practitioner, opened date and branch — what the server sends. No treatment plans, clinical notes, package/session details or agreed estimates: the server sends `summary: []` for them, and the card draws no progress line rather than an empty one. No new access is added.

### Selected case vs patient-wide data (honest behaviour)
- `selectedCase` is **client-side context only**. It: highlights the card; highlights the case in Cases & Plans; chooses Dental vs Physio workspace in combined clinics.
- It **never filters** Visits, Clinical, Docs, Billing or Timeline — those endpoints take no case. Those zones carry a fixed caption "All of this patient's records" (patient-wide), and show no "for this case" wording, badges or counts. Cases & Plans is the only zone that shows case-specific data (from `endpoints.cases`).
- When a case is selected, a quiet context chip on the zone bar reads "Case: {title}" with a clear (×); it changes no list contents.

## 5. Zone bar (C)

- **Desktop:** 7 equal tiles in one row inside a soft track; each tile = colour icon chip + label + a small count/badge where data is already loaded (e.g. Docs count, Billing "₹ due" only for `financial`). Selected tile raised white with its zone colour underline. Not plain text tabs.

| Zone | Icon | Tone |
|---|---|---|
| Overview | LayoutDashboard | teal |
| Clinical | Stethoscope | blue |
| Cases & Plans | FolderHeart | violet |
| Visits | CalendarCheck | teal |
| Docs | FileText | blue |
| Billing | Wallet | mint |
| Timeline | History | violet |

- **Tablet:** two rows (4+3). **Mobile:** horizontal segmented scroller of icon+label chips, sticky under the compact header.
- Inside a zone, sections become **in-zone panels** (side-by-side on desktop), not a second tab row. Only where two sections would be too large (Clinical: Dental/Physio workspace vs Consultations) is a segmented switch used, styled as a panel header control.

## 6. Zone layouts (D)

Grid: 12 columns desktop (main 8 / side 4), single column ≤1024.

### Overview
| Main | Side |
|---|---|
| **Care now** panel: active treatments (`overview.current.active_care`), open consultation CTA | **Next** panel: next appointment, follow-up due (`overview.next`) |
| **Latest visit** (from `overview.recent`) + diagnoses & medicines (clinical) | **Billing snapshot** (only `overview.summary.finance` non-null: billed / paid / outstanding of *this patient*) |
| **Recent activity**: first 5 timeline events (fetch `endpoints.timeline` page 1) | **Recent documents** (`overview.documents`, 4) |
| | **Physio at a glance** (`overview.physio_care`) in physio clinics |
Next actions are the header's; the Overview repeats none.

### Clinical
- Top: **Medical History** card — exactly the nine New Case fields of §3a (7 condition tiles + Other Condition + Notes & Remarks), editable only when the server sends the editors. No allergies, alerts, medications or other history.
- Segmented switch: **Dental workspace** | **Physio workspace** | **Consultations** — Dental/Physio shown only when that section is in the zone (server gate) and, in combined clinics, defaulting to the selected case's module.
- **Consultations** (`endpoints.clinical`): cards per encounter — date, practitioner, branch, status (draft/signed/voided), complaint, diagnoses, counts (procedures/Rx/orders), open link.
- Dental workspace: existing `DentalSection` content re-laid: FDI chart full-width hero (preserve component), right rail tooth detail; below, Plans · Procedures done · Imaging · Lab · Periodontal · Endodontic/Implant/Ortho (from `dental.*`).
- Physio workspace: Plan of care hero (goals, home exercises), Sessions timeline (SOAP excerpts, pain before/after), Packages (remaining meters), Outcomes & pain trend, Assessments/Reassessments, Discharges. **No body map / ROM / MMT editors** (read-only ROM rows exist in payload `rom`; shown as a table only if present).
- Prescriptions live in **Docs** (printable record) and are linked from each consultation card.

### Cases & Plans
- Left list (4 cols): cases, open first; each row title, specialty, status, lead, dates; selecting shows detail. Matches `selectedCase`.
- Right detail (8 cols): header (title, specialty, status, lifecycle buttons from `actions.*`), **Lead practitioner** and **Treated by** as two distinct labelled chips groups, visits count/last, **Plans** table (treatment, status, progress, practitioner, agreed estimate per billing rules — "Your agreed estimate" for own-fees), **Packages** (meter: remaining of total), discharge summary.
- Below: **Plans not tied to a case** (`other_treatments`), and the existing **Treatments** section (procedures done) as a secondary panel.
- Lifecycle dialogs (centred modal): Recommend (note optional), Close (summary optional), Reopen (reason required) — unchanged backend.

### Visits
- Main: **Visit history** table/cards (`endpoints.visits`): date & time, type (New/Repeat), reason, treating practitioner, branch, status, consultation link (when present); not-attended bookings marked. Legacy `case_number` in this payload is **not shown**.
- Side: **Upcoming appointments** (`endpoints.appointments`, scope upcoming / history toggle) and **Follow-ups** (`endpoints['follow-ups']` with add/move/complete/cancel as today).

### Docs
- Toolbar: category filter chips (from `documents.types`), **Upload** button (only when `upload` present; malware-scanned path unchanged).
- Grid of document tiles (file-type icon, name, category, date, uploaded by, source consultation/order when `source` present) with Preview / Download; governed Remove.
- Panels: **Prescriptions** (print/PDF), **Reports** (investigations with status; results only for `investigation_results`), **Consents** (record/revoke when allowed).

### Billing
- Ledger readers (`financial`): summary strip (billed / paid / refunded / outstanding — patient only), **Bills** list (status, total, paid, due, View → existing bill dialog, Print), **Payments & receipts** list, **Refunds** list, link "Open in Billing" (`links.bills`).
- Own-fees practitioners (`scope: own_fees`): only "Your fees" list (lines per bill), no totals/payments/refunds/detail.
- Reception (no `billing.view`): zone not drawn (server omits section); the header's **Bill consultation** remains.
- Package transactions: package **sales** appear as bills; package **usage** is shown in Cases & Plans / Physio (not a billing payload — see gaps).

### Timeline
- Filter chips by category (server `categories`), vertical timeline grouped by day, event icon by category, actor, description, open link. Sub-panels on the side: **Messages** (communication, with share), **Requests** (governance), **Audit** (owner/admin only).

## 7. Dental vs Physio separation

| Clinic | Header chips | Active Cases | Clinical zone |
|---|---|---|---|
| Dental-only | Dental | dental cases only (server `module_offered`) | Dental workspace + Consultations; no physio component mounted |
| Physio-only | Physio | physio cases only | Physio workspace + Consultations; no tooth chart mounted |
| Combined | both | both | switch shows both; defaults to selected case's module, else the member's practice (`lens.persona` dental/physio) |

Rule: a workspace component renders only when its section key is in the Clinical zone's `sections` (server-gated by `specialtyWorkspaces`).

## 8. Role-wise view (from existing permissions)

| Area | Owner/Admin | Doctor | Reception | Billing staff |
|---|---|---|---|---|
| Header actions | all + governed menu | Start/Continue consultation, Edit (if `patient.edit`) | Check in, Bill consultation, Edit, Assign doctor | Bill consultation, Print |
| Medical history (header line + Clinical card) | when sent (clinical data) | yes, editable | not sent → not drawn | not sent → not drawn |
| Active Cases | yes, with summaries | yes, with summaries | title, specialty, status, lead, dates only | not drawn (no `cases`) |
| Overview | full | clinical cards, no finance | desk cards, no finance (OD-9) | billing card + basics |
| Clinical | full | full | Dental operational view only (dental clinics) | Dental financial view only |
| Cases & Plans | lifecycle per rules | recommend/close per rules, plans | cases without plans, no actions | — |
| Visits | yes | yes | yes | — |
| Docs | all | clinical + admin docs | admin docs, consents | — |
| Billing | full | **own fees only** | not drawn | full (no refunds unless `refund.process`) |
| Timeline | all + audit | clinical categories | desk categories | billing categories |

## 9. Component / file architecture (proposed)

```
pages/ClinicPortal/Patients/Show.tsx                → page shell: header, cases, zone bar, zone router
components/patient360/v2/
  PatientHeaderCard.tsx      (identity, facts, actions; wraps existing dialogs)
  MedicalHistory.tsx         (header line + Clinical card + editor: the 9 New Case fields only, §3a)
  ActiveCases.tsx            (cards + selection)
  ZoneBar.tsx                (tiles / mobile scroller)
  zones/OverviewZone.tsx
  zones/ClinicalZone.tsx     (MedicalHistoryCard, ConsultationList, DentalWorkspace=DentalSection, PhysioWorkspace=PhysioSection)
  zones/CasesZone.tsx        (CaseList, CaseDetail, LifecycleDialog)
  zones/VisitsZone.tsx
  zones/DocsZone.tsx
  zones/BillingZone.tsx
  zones/TimelineZone.tsx
  ui/ZonePanel.tsx           (= CommandCenter Panel look, local)
  hooks/useSection.ts        (one fetch/paging hook replacing usePaged/usePage copies)
```
Existing files stay; v2 components import their data hooks/types/dialogs. Old components left unused until approval to delete.

## 10. Endpoint mapping (no new endpoints)

| Zone | Data |
|---|---|
| Header | props `patient`, `state`, `context`, `profile`, `actions`, `condition_editor`, `safety_editor`, `lens` |
| Active Cases | props `active_cases` |
| Overview | deferred `overview` (+ `documents`, `physio_care`); `endpoints.timeline` page 1 |
| Clinical | `endpoints.clinical` (encounters), `endpoints.dental`, `endpoints.physio`, `360/dental/teeth/{n}` |
| Cases & Plans | `endpoints.cases`, `endpoints.treatment`, `patients.cases.transition` |
| Visits | `endpoints.visits`, `endpoints.appointments`, `endpoints['follow-ups']` |
| Docs | `endpoints.documents`, `endpoints.prescriptions`, `endpoints.investigations`, `endpoints.consents`, `documents.store`/download |
| Billing | `endpoints.billing`, `360/bills/{bill}`, `links.bills` |
| Timeline | `endpoints.timeline`, `endpoints.communication`, `endpoints.requests`, `endpoints.audit` |

## 11. Requested items the backend does not support (not invented)

1. **Filtering Clinical/Visits/Docs by the selected case** — no endpoint takes a case; visits payload has no case id. UI uses case context only for module choice and highlighting.
2. **Document ↔ case link** — documents carry consultation/order source only; "Linked case" cannot be shown.
3. **Procedures per visit inside the Visits list** — visits payload has a consultation link and counts, not procedure rows; shown via the consultation link / Treatments panel.
4. **Package transactions in Billing** — usage ledger is not in the billing payload; sales show as bills; usage shown in Cases & Plans / Physio.
5. **Plan/package progress on Active Cases for non-clinical roles** — `summary` comes from treatments, which are clinical-only; reception sees title/status/lead only.
6. **A "New Case" action for an existing patient** — no separate server action; the Repeat popup's new-episode option is used.
7. **Physio body map / ROM / MMT editors** — out of scope (read-only rows only if present).

Each needs a backend change and owner approval before it can be added.

## 11a. Contracts unchanged (confirmation)

This redesign changes no backend: no endpoint, payload, route, policy, permission, `PatientCaseLifecycle` rule, billing rule, schema or migration. Allergy, alert and other history data stay stored and served as today; the new frontend only stops rendering them. The prescription workflow's own allergy review inside the consultation screen is outside Patient 360 and is not touched by this redesign.

## 12. Responsive rules

- ≥1280: 12-col zone grid, header 3-column, zone tiles one row.
- 1024–1279: header 2 rows; zone tiles 4+3; zone grid stacks side panels under main.
- 768–1023: single column; Active Cases scroller; zone bar scroller.
- <768: compact sticky header + sticky zone scroller; panels full-width; tables become cards; dialogs centred full-height modals. No horizontal page overflow at 390/360.

## 13. Implementation sequence (after approval)

1. `ZonePanel`, `ZoneBar`, `useSection` (shared hook) — no data change.
2. `PatientHeaderCard` + `MedicalHistory` (reuse dialogs/actions and the existing condition/notes saving calls).
3. `ActiveCases` with selection + URL state.
4. `Show.tsx` shell with zone router; old sections still rendered inside zones as a fallback.
5. Zones one at a time: Overview → Cases & Plans → Visits → Docs → Billing → Timeline → Clinical (Dental/Physio last, as the largest).
6. Tests: zone/role rendering props already covered server-side; add frontend-agnostic feature tests only where payload use changes (none planned); `tsc`, build after each zone.
7. Owner UI review at 1440 / 1024 / 768 / 390; then approval to retire unused old components.
