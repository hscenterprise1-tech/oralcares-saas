# OralCares Clinic Portal — Patients Module Blueprint (v1, for approval)

Status: **audit + information architecture + design blueprint only.** Nothing in production is changed by this document. The visual mockup is an isolated design-review artifact (§12). Production implementation waits for explicit approval.

Date: 2026-10-24 · Scope: Clinic Portal Patients module · Companion to `docs/architecture/clinic-portal-ux-blueprint.md` (whose §15 decisions are not re-decided here).

---

## 1. Executive summary

- **The backend is far ahead of the frontend.** The patient domain (registration with a race-safe duplicate gate, branch privacy, cursor directory, role-filtered Patient 360 with 15 lazy sections, dental and physio workspaces, consents, documents, follow-ups, governed archive/merge) is built and covered by 26 test files in `tests/Feature/Clinic/Patients` plus ~30 more across Reception, Consultations, Security and Treatments.
- **Only two patient pages exist in the new portal**: the Directory (`ClinicPortal/Patients/Index`) and Registration (`ClinicPortal/Patients/Create`). **Patient 360 (`ClinicPortal/Patients/Show`) and Edit (`ClinicPortal/Patients/Edit`) have no page**: their controllers render, the app falls back to the `Unavailable` placeholder, and `ClinicFrontend::AVAILABLE` correctly withholds links to them.
- **The new design is a new composition over the same contracts.** Every screen below maps to an existing route and payload. No new routes, migrations or schema are needed for the first release; the gaps that *would* need backend work are listed separately (§11) and are not assumed.

---

## 2. Inventory — routes, pages and code inspected

### 2.1 Patient routes (all `auth` + clinic context; 121 patient-related clinic routes in total)

| Route name | Method · URI | Controller@action | Page / response |
|---|---|---|---|
| `clinic.patients.index` | GET `/clinic/patients` | `PatientController@index` | **Page** `ClinicPortal/Patients/Index` (exists) |
| `clinic.patients.directory` | GET `/clinic/patients/directory` | `PatientDirectoryController@index` | JSON cursor page |
| `clinic.patients.search` | GET `/clinic/search/patients` | `PatientController@search` | JSON (autocomplete, any status) |
| `clinic.patients.check-duplicate` | POST `/clinic/patients/check-duplicate` | `PatientController@checkDuplicate` | JSON (`present()` shape, masked across branches) |
| `clinic.patients.create` | GET `/clinic/patients/create` | `PatientController@create` | **Page** `ClinicPortal/Patients/Create` (exists) |
| `clinic.patients.store` | POST `/clinic/patients` | `PatientController@store` | Redirect / JSON (duplicate gate in a clinic-row-locked transaction) |
| `clinic.patients.show` | GET `/clinic/patients/{patient}` | `Patient360Controller@show` | **Page** `ClinicPortal/Patients/Show` — **missing** |
| `clinic.patients.360.section` | GET `/clinic/patients/{patient}/360/{section}` | `Patient360SectionController@show` | JSON, 14 sections |
| `clinic.patients.360.bill` | GET `…/360/bills/{bill}` | `Patient360SectionController@bill` | JSON |
| `clinic.patients.360.dental.tooth` | GET `…/360/dental/teeth/{tooth}` | `Patient360SectionController@tooth` | JSON (clinical only) |
| `clinic.patients.edit` | GET `/clinic/patients/{patient}/edit` | `PatientController@edit` | **Page** `ClinicPortal/Patients/Edit` — **missing** |
| `clinic.patients.update` | PUT `/clinic/patients/{patient}` | `PatientController@update` | Redirect |
| `clinic.patients.transfer` | POST `…/transfer` | `PatientController@transfer` | Assign doctor (audited timeline event) |
| `clinic.patients.clinical-entries.update` | PUT `…/clinical-entries/{kind}` | `PatientClinicalEntryController@update` | Allergies/conditions/medications (clinical write gate) |
| `clinic.patients.consents.*` | GET/POST `…/consents`, POST `…/{consent}/revoke` | `PatientConsentController` | JSON / redirect |
| `clinic.patients.documents.store` / `.download` | POST/GET `…/documents` | `Patient360Controller` | Upload (malware-scanned) / download |
| `clinic.patients.follow-ups.*` | POST/PATCH `…/follow-ups…` | `PatientFollowUpController` | store, update, complete, cancel |
| `clinic.patients.treatments.*`, `.treatment-charges.*` | `…/treatments`, `…/treatment-charges` | `PatientTreatmentController`, `TreatmentCareController`, `TreatmentChargeController` | Treatment engine |
| `clinic.patients.session-packages.*` | POST `…/session-packages…` | `PhysioController` | Sell / cancel physio packages |
| `clinic.patients.dental.plans.transition`, `.dental.lab.step` | POST | `DentalController`, `DentalSpecialtyController` | Plan transitions, lab steps |
| `clinic.approvals.*` | `/clinic/approvals…` | `DeletionRequestController` | Governed archive / merge / voids (owner decides in Owner Mode) |
| Reception: `clinic.reception.check-in`, `.check-in.search`, `.check-in.recent`, `.intake`, `.walk-in`, `.bookings.match` | | `ReceptionController` | New/Repeat Case intake (dialog), check-in |
| Consultations: `clinic.consultations.*` (start-for-patient, show, dental/physio writers, prescriptions, orders) | | `ConsultationController`, `DentalController`, `PhysioController`, `PrescriptionController`, `InvestigationController` | Clinical writes happen in the consultation, not in Patient 360 |
| `clinic.visits.index` | GET `/clinic/visits` | `VisitController@index` | Legacy page `Clinic/Visits/Index` — **does not exist**; not linked (obsolete candidate) |

### 2.2 Frontend files (current)

| File | Lines | Role today |
|---|---:|---|
| `resources/js/pages/ClinicPortal/Patients/Index.tsx` | 181 | Directory page (live) |
| `…/Patients/components/directory/DirectoryToolbar.tsx`, `PatientList.tsx` | 188, 192 | Directory pieces |
| `…/Patients/components/{design,format,types,PatientIdentity}.ts(x)` | 51–63 | Shared directory helpers |
| `…/Patients/Create.tsx`, `registration/types.ts` | 331, 230 | Full-page registration (live) |
| `resources/js/pages/Clinic/Reception/intake/*` (10 files) | ~3,000 | New/Repeat Case dialog; `PatientFields.tsx` (733) is shared with `Create.tsx` |
| `ClinicPortal/Patients/Show.tsx`, `Edit.tsx` | — | **Do not exist** (removed in the 2026-10-11 frontend reset) |

No patient page imports `@/ovs/*`, OEDL or `crmTheme.ts` (verified by grep).

### 2.3 Backend services and models inspected

`PatientController`, `Patient360Controller`, `Patient360SectionController`, `PatientDirectoryController`, `PatientConsentController`, `PatientFollowUpController`, `PatientClinicalEntryController`; services `Patient\PatientDirectory`, `Patient\PatientSearch`, `Patient\PatientBranchAccess`, `Patient\PatientMergeService`, `Patient360\{Access, Summary, Sections, SectionPolicy, DentalWorkspace, PhysioWorkspace}`, `Governance\DeletionGovernance` (+ `Actions\ArchivePatient`, `MergePatient`); `Policies\PatientPolicy`; enums `ClinicPermission`, `ClinicRole`, `PatientStatus` (active, inactive, deceased, archived, merged); model scope `Patient::scopeMatchingSearch`.

---

## 3. Feature inventory and classification

Legend: **P** = existing functionality to preserve · **N** = needs a new UI · **M** = missing, needs a separate proposal · **O** = obsolete/duplicated (with evidence).

| Area | What the repository actually supports | Class |
|---|---|---|
| Directory | Cursor pages of 25 (`created_at, id`), no COUNT; prefix search (digits → phone/alt phone/UHID; words → first/last, then middle); filters status, specialty, branch, "in active care", My patients (practitioners); row data: name, UHID, phone, email, gender, age, status, specialties, active care, last activity + branch, critical alerts, registered on | **P** backend · **N** UI |
| Directory sorting | Only newest-registered first (cursor key). "Last visit" sort is not supported | **M** (needs an indexed cursor key) |
| Registration | One-screen form (`PatientFields`), duplicate check endpoint, duplicate gate with acknowledged override (audited `patient.duplicate_override`), idempotent submit, international phone rule, branch-masked matches | **P** · **N** UI |
| Registration → workflow | Desk path: New Case dialog registers + checks in (charges at check-in); page path registers only | **P** (D29 open) |
| Edit | `edit`/`update` with clinical write gate, merged → survivor redirect, archived → read-only redirect | **P** backend · **N** UI (page missing) |
| Assign doctor | `transfer` with audit trail event | **P** · **N** UI |
| Patient 360 | Identity, state (last visit, next appointment, outstanding, episode, alerts), context (conditions, medications, flags), deferred overview; 14 lazy sections; persona lens (owner/admin/dental/physio/clinical/reception/billing/staff); role-ordered sections and actions | **P** backend · **N** UI (page missing) |
| Encounters / clinical history | `encounters` section (paged); writes only in the Consultation workspace | **P** · **N** |
| Prescriptions, investigations | Sections + print route for prescriptions | **P** · **N** |
| Treatment engine | `treatment` section, treatment routes (plans, stages, sessions, packages) | **P** · **N** |
| Dental | `Patient360DentalWorkspace` (chart, overlays, plans, endo/implant/ortho/perio, lab, imaging) in clinical / operational / financial views; tooth detail; 3D chart via three.js (lazy) | **P** · **N** (dental-enabled only) |
| Physio | `Patient360PhysioWorkspace` (assessments, plan of care with goals/exercises/home programme, sessions with pain before/after, packages used/remaining, outcomes, discharges) | **P** · **N** (physio-enabled only) |
| Billing | `billing` section (bills, payments, outstanding), bill detail; refunds field-gated | **P** · **N** |
| Follow-ups | Section + create/update/complete/cancel | **P** · **N** |
| Documents | Upload (malware scan) / download; removal is governed | **P** · **N** |
| Consents | List / record / revoke (`consent.manage`) | **P** · **N** |
| Communication | `communications` section = patient-addressed `notifications` with status (queued, scheduled, sent, simulated, failed, withdrawn) | **P** · **N** |
| Timeline / audit | Narrative timeline (categorised); security audit section (owner/admin) | **P** · **N** |
| Archive | Governed `patient.archive`: owner direct in Owner Mode, others request (needs `patient.delete`) | **P** · **N** |
| Merge | Governed `patient.merge` (`PatientMergeService`, integrity tests); merged record redirects to survivor | **P** · **N** (no merge UI) |
| Restore (un-archive) | `PatientPolicy::restore` exists; **no route, no governed action** | **M** |
| Bulk actions, export | None | **M** (not proposed now) |
| Legacy visits list | `clinic.visits.index` renders `Clinic/Visits/Index`, which does not exist; not in navigation | **O** (retire or redirect to Patient 360 — decision) |
| Two check-in paths | Memory/audit evidence: a thinner P360 check-in beside Repeat Case existed in the removed UI | **O** for the new UI: Patient 360 opens the **existing Repeat Case dialog**, never a second check-in |
| `App\Services\PatientRegistrationService` | Documented as deprecated/dead (only a deliberately failing legacy test uses it) | **O** (backend clean-up, separate approval) |

---

## 4. Proposed information architecture

The sidebar keeps **one** destination: **Patients** (already in the approved navigation). Everything else lives under it; nothing new is added to the sidebar.

```
Patients (sidebar)
├─ Directory                     /clinic/patients                    [page]
│    └─ Register patient          /clinic/patients/create            [page]  (+ New Case dialog from the top bar, unchanged)
└─ Patient 360                   /clinic/patients/{id}               [page]
     ├─ Overview  (default; billing/dental/physio personas default to their section)
     ├─ Clinical  — Encounters · Prescriptions · Investigations · Treatment
     ├─ Specialty — Dental (dental-enabled) · Physiotherapy (physio-enabled)
     ├─ Care      — Appointments · Follow-ups
     ├─ Money     — Billing (bills, payments, bill detail)
     ├─ Records   — Documents · Consents · Communication · Timeline
     └─ Governance — Requests · Audit (owner/admin)
     Edit details                 /clinic/patients/{id}/edit          [page]
     Dialogs: Repeat Case (existing intake), Book appointment, Collect payment, Add follow-up,
              Upload document, Record consent, Assign doctor, Archive/Merge request, Step-up (Owner Mode)
```

Rules: sections are *within* Patient 360 (deep-linkable as `?section=billing`), never separate sidebar items. Dialogs for short, single-purpose tasks; full pages for Patient 360, Register, Edit (consistent with the portal blueprint: **no drawers**). The section list a user sees is exactly `lens.sections` from the server.

---

## 5. Core workflows

1. **Find a patient → Patient 360.** Directory search field is focused on load; typing runs the server prefix search (debounced ~200 ms, request aborted on new keystroke); ↑/↓ moves through rows, Enter opens Patient 360. Global search (top bar, Ctrl K) is the second entry point. Opening records `patient.viewed` in the audit trail (already server-side).
2. **Register without a duplicate.** Register page (or New Case dialog at the desk): as soon as name + phone (or DOB) are present, the duplicate check runs and shows matches inline *above the Save button* — same-branch matches open their record; other-branch matches are masked (initials only, "registered at another branch"). Saving with a match requires an explicit "This is a different person" acknowledgement (server audits the override). Submit is idempotent.
3. **Register → continue the clinic's workflow.** After save: success panel with UHID and three next steps gated by permission: *Check in now* (opens Repeat Case pre-filled — reception), *Book appointment* (dialog), *Open Patient 360*. A receptionist is never sent through clinical or billing screens.
4. **Previous visits and clinical records.** Patient 360 → Clinical → Encounters list (newest first, paged); each encounter opens the consultation record; prescriptions print from the list.
5. **Move between identity, clinical history and billing.** The identity header stays pinned at the top of Patient 360 (compact on scroll); the section rail switches content without losing scroll in the rail; URLs carry `?section=` so Back works.
6. **Archived and restricted.** Archived patients open read-only with a banner (reason + date); Edit/Check-in/Book are hidden; Archive is replaced by "Archived"; restore is a product gap (§11). A record the member may not open is a 403 page with plain wording — never a partial record. Other-branch identities stay masked wherever the server masks them.

---

## 6. Page specifications

### 6.1 Patients Directory (`/clinic/patients`)
- **Header row:** page title "Patients", the sentence "Newest registrations first" (honest about the only supported order), primary **Register patient** (if `links.create`), secondary **New Case** (if intake available).
- **Search bar** (hero, full width): placeholder adapts to what the server matches ("Name, phone or patient ID"); clear button; keyboard hint.
- **Scope + filters:** segmented *My patients / All patients* for practitioners only; filter chips for Status (default "working": active + inactive), Specialty (only enrolled specialties), Branch (only when the member has >1 and the list is complete), In active care. Active filters show as removable chips; "Clear all".
- **Results:** list rows (desktop) / cards (phone). Each row: specialty-tinted avatar, name, UHID chip, age · gender, phone, specialty chips, active-care badge, last seen (date + branch), critical-alert marker; whole row opens Patient 360; hover/focus reveals role actions (Check in, Book) that open the existing dialogs.
- **Pagination:** "Show next 25" and "Back" from the cursor (no page numbers, no total — by design at 100k+ scale).
- **States:** first load skeleton rows; no patients yet (register CTA); no results (echo query, suggestions: try phone or ID, clear filters); error with retry; offline.

### 6.2 Register patient (`/clinic/patients/create`) and Edit (`/clinic/patients/{id}/edit`)
- Two-column on desktop: **left** form in three groups — *Identity* (first, middle, last, gender, DOB or age, blood group), *Contact* (phone with country, alternate phone, email, address), *Clinical flags* (allergies, alerts; shown only with clinical permission); **right** sticky panel: duplicate matches, required-fields checklist, save.
- Required fields marked by the server's configuration (`PatientFormConfigurationTest`), not hard-coded. Optional groups collapsed.
- Phone: single column on mobile, numeric keyboard, sticky Save bar at the bottom.
- Edit uses the same layout; clinical fields read-only without `clinical.edit`; archived/merged redirect as today.

### 6.3 Patient 360 (`/clinic/patients/{id}`)
- **Identity header (pinned):** avatar, name, UHID, age · gender · blood group, phone, status chip, assigned practitioner, registered branch; **safety strip** (allergies + high/critical alerts) in rose/amber — always visible to clinical roles, summarised for others per lens; **state strip** — Last visit, Next appointment, Outstanding (only with `fields.financial`).
- **Actions:** one primary button chosen by the server (`kind: primary`), up to three `desk` buttons, the rest in a "More" menu — exactly the server's `actions` list.
- **Section rail** (desktop left, tablet top, phone horizontal scroll): grouped as §4, counts only where the section already returns a total.
- **Overview:** Now (open consultation, active care streams), Next (appointment, follow-up — overdue flagged), Recent diagnoses, Investigations awaiting results, Latest payments (financial lens). Non-clinical roles never see "No consultations yet" style false negatives — a card the role may not see is simply absent.
- **Other sections:** paged lists with consistent row anatomy (icon by kind · title · meta line · status chip · action), empty state per section, error with retry.

### 6.4 Dental section (dental-enabled clinics; `lens.fields.dental*`)
- Clinical view: FDI tooth chart (2D by default, 3D toggle lazily loaded), condition/treatment overlays, tooth detail panel, plans with items to do/done, endo/implant/ortho/perio summaries, lab orders with steps.
- Operational view (front desk): teeth under treatment, next steps, lab status — no clinical notes. Financial view (billing): plan estimates, billed vs to-do.

### 6.5 Physiotherapy section (physio-enabled clinics; `lens.fields.physio`)
- Plan of care (goals, frequency, progress), sessions table with pain before/after and exercises performed, home programme, functional measures/ROM trends, packages (used / remaining / valid until), discharges. **No dental controls ever appear in a physio-only clinic.**

---

## 7. Layout plan

| Width | Directory | Patient 360 |
|---|---|---|
| ≥1280 | Search hero; filters inline; list rows with all columns; optional right "Recently opened" (local, per device) | Pinned header; 240px section rail + content; Overview two-column cards |
| 1024 | Same, the last-seen column merges into the meta line | Rail 208px; Overview one column of cards in two rows |
| 768 | Filters collapse into a "Filters" button opening a dialog; rows become two-line | Rail becomes a horizontal scroll strip under the header; header compacts |
| ≤390 | Cards; sticky search; actions in each card's overflow | Header stacks; safety strip first; primary action as a full-width button; sections as a scroll strip |

All within the approved shell (sidebar ≥1024, drawer below); content follows the 1600px measure and 16/24/32px gutters already used by the Command Center.

---

## 8. Component and interaction inventory (new, Patients-scoped)

`PatientSearchField` (debounced, abortable, keyboard list) · `FilterChips` + `FiltersDialog` (≤768) · `PatientRow` / `PatientCard` · `CursorPager` · `IdentityHeader` (pinned, compact-on-scroll) · `SafetyStrip` · `StateStrip` · `ActionBar` (primary / desk / more from `actions`) · `SectionRail` · `SectionList` (paged list anatomy) · `EmptyState`, `SectionError`, `Skeleton` · `ToothChart2D` (+ existing lazy 3D) · `PhysioPlanCard`, `SessionTable`, `PackageMeter` · `DuplicateMatches` · `RegistrationForm` groups · `ArchivedBanner` · `RestrictedNotice`. Reuse `@/clinic/ui` primitives (Button, controls, Spinner, modal kit) and the shell; reuse the existing intake dialog for New/Repeat Case and check-in.

Interactions: hover lift on rows (no motion under `prefers-reduced-motion`), visible teal focus rings, 44px touch targets, Enter/Space on rows, Esc closes dialogs, `aria-current` on the active section, live region for search result counts on the page ("25 shown").

---

## 9. Role and permission matrix (from the code, not invented)

Default role templates (`ClinicPermission::defaultRolePermissions`) and the Patient 360 lens (`Patient360SectionPolicy`):

| Capability | Owner | Admin | Doctor (Practitioner) | Reception | Billing | Staff |
|---|---|---|---|---|---|---|
| Open directory / search (`patient.view`, `.search`) | ✓ | ✓ | ✓ | ✓ | ✓ | view only |
| Register (`patient.create`) | ✓ | ✓ | — | ✓ | — | — |
| Edit details / assign doctor (`patient.edit`) | ✓ | ✓ | — | ✓ | — | — |
| Clinical sections (`clinical.view`) | ✓ | ✓ | ✓ | — | — | — |
| Clinical write (allergies, flags) (`clinical.edit`) | ✓ | ✓ | ✓ | — | — | — |
| Dental clinical / operational / financial view | clinical | clinical | clinical (if dental area) | operational | financial | — |
| Physio section | ✓ | ✓ | ✓ (if physio area) | — | — | — |
| Appointments section, Book | ✓ | ✓ | view | ✓ | — | view |
| Check in (`queue.manage`) | ✓ | ✓ | ✓ | ✓ | — | — |
| Billing section (`billing.view`) / Collect (`payment.collect`) | ✓ / ✓ | ✓ / ✓ | view / — | ✓ / ✓ | ✓ / ✓ | — |
| Refunds field (`refund.process`) | ✓ | ✓ | — | — | — | — |
| Documents upload (`documents.manage`) | ✓ | ✓ | ✓ | ✓ | — | — |
| Consents record (`consent.manage`) | ✓ | ✓ | ✓ | ✓ | — | — |
| Archive (`patient.delete` → request; owner direct) | direct | request | — | — | — | — |
| Merge (`patient.merge` → request; owner direct) | direct | request | — | request | — | — |
| Audit section | ✓ | ✓ | — | — | — | — |

Persona → default section: billing → Billing, dental → Dental, physio → Physiotherapy, others → Overview. Branch privacy applies to all rows (`PatientBranchAccess`).

**Permission gaps to decide (not invented):** (a) Reception may *request a merge* but not *request an archive* — intended? (b) Doctors cannot register a patient (no `patient.create`) — fine for most clinics, a problem for solo practitioners without a receptionist. (c) No permission or route for restore.

---

## 10. States, validation and confirmations

- **Loading:** skeleton rows/cards that match final layout; section content skeleton per section; deferred overview shows a pulsing skeleton (Inertia `defer`).
- **Empty:** per surface, with the next useful action only if permitted.
- **Errors:** inline "This part did not load — Retry" per section (never blank the page); 403 → restricted notice; 404 → "Patient not found in this clinic".
- **Validation:** server messages shown at the field; first invalid field focused; phone format rule mirrors `App\Rules\PhoneNumber`.
- **Confirmations:** archive/merge open the governed request dialog (reason required; Owner Mode step-up for direct); destructive buttons rose; duplicate override needs an explicit acknowledgement.
- **Archived:** read-only banner; actions removed; data intact.

---

## 11. Risks, backend gaps and decisions

| # | Item | Type |
|---|---|---|
| G1 | Directory can only sort by newest registered; "last visit" sort needs a cursor-safe index | Backend gap (proposal needed) |
| G2 | No restore/un-archive route or governed action (policy method exists) | Backend gap |
| G3 | No merge UI; merge exists only as a governed action | UI scope decision |
| G4 | `clinic.visits.index` renders a page that does not exist | Obsolete — retire or redirect |
| G5 | `PatientRegistrationService` deprecated; legacy test fails on purpose | Backend clean-up |
| G6 | Physio workspace returns `body_chart` / `body_regions`, while an earlier product decision said "no body chart, ever" | **Product decision** |
| G7 | Register page vs New Case dialog roles (D29) and repeat-visit fee (D28) still open | Product decisions |
| G8 | Patient photo/avatar: no photo field is used by the directory payload → initials avatars only | Design constraint |
| G9 | Communication = notifications only (no inbound messages) | Constraint |
| R1 | Patient 360 must not fetch all sections up front — keep lazy endpoints (scale) | Risk |
| R2 | Any new directory column must be batched per page like the existing extras (no N+1) | Risk |

---

## 12. Visual mockup (design review only)

- File: `docs/design-review/patients-v2/patients-mockup.html` — self-contained, fictional data, no network calls, not routed, not imported by the app.
- Screens: Directory (default, filters, no results, loading), Patient 360 (Overview, Dental, Physiotherapy, Billing, Timeline), Register with a duplicate match, archived state; a review panel switches **role** (Owner, Doctor, Reception, Billing) and **clinic type** (Dental, Physio, Both) to show visibility rules.
- Screenshots: `docs/design-review/patients-v2/qa/`.

## 13. Test and browser QA plan (for the implementation phase)

- Keep every existing test green (26 patient test files + Reception/Consultation/Security suites); add page tests asserting component names and props for Show/Edit and section visibility per persona (extend `Patient360RoleVisibilityTest`).
- Browser QA per page at 1440 / 1024 / 768 / 390 / 360 for Owner, Doctor (dental and physio), Reception, Billing; keyboard-only pass; 44px hit tests; no console errors; dental controls absent in a physio-only clinic; masked other-branch duplicates; archived read-only.
- Retirement gate for any old UI: replacement live, no imports/render names, no tests asserting it, browser QA passed (per `clinic-portal-full-replacement-decision`).

## 14. Build order (after approval)

1. Patient 360 shell + Overview + Clinical/Care/Money/Records sections (largest missing piece).
2. Dental and Physiotherapy sections.
3. Directory rebuild in the new language.
4. Register + Edit pages (shared form groups), duplicate panel.
5. Lifecycle dialogs (assign doctor, archive/merge requests), archived/restricted states.
6. QA, then retire the old Directory/Create components once nothing imports them.
