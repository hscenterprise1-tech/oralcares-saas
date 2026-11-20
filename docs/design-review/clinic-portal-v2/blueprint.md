# OralCares Clinic Portal v2 — Design Blueprint (proposal)

> **Status: DESIGN REVIEW, NOT APPROVED. Implementation waits for explicit approval.**
>
> Visual language: [`design-system.md`](design-system.md). Reference mockup: [`doctor-dashboard.html`](doctor-dashboard.html).
>
> Starting inventory: `docs/architecture/clinic-portal-replacement-map.md` (2026-10-22/23). Product rules: `docs/architecture/owner-command-center-blueprint.md` and its decision register.
>
> Once approved, this file is the single source of truth for every implementation phase. A page is "done" only when it matches this blueprint, its acceptance criteria and its QA.

## 0. Ground rules carried into every page

- **Kept as they are:**
  - The login page and its recovery, setup and invitation screens.
  - The sidebar: same items, sections, order and visibility rules (`ClinicNavigation` and `ClinicFrontend::AVAILABLE`).
  - The persistent top bar: search, date and time, support, notifications and account menu.
- **Everything inside the workspace is new**, built in the v2 language. No old page is used as a template.
- **No imports from** `@/ovs/*`, OEDL or `crmTheme.ts`. The Company Portal's design tokens are not a dependency.
- **The server decides everything that involves access:**
  - permissions, at the record's own branch;
  - the plan's modules and specialties;
  - Owner and Doctor Mode.

  A page draws what arrives. Data the user may not see is never sent.
- **Owner semantics (binding, 2026-10-23):**
  - A non-doctor owner has the owner's workspace from sign-in.
  - A doctor-owner starts in Doctor Mode and enters Owner Mode explicitly.
  - A Doctor or Admin role never grants Owner authority.
  - T2 actions still need a recent password confirmation.
- **Specialty rule:** dental-only UI (tooth chart, FDI tooth numbers, dental plans, lab crowns) never renders in a Physio-only clinic. Physio UI (assessments, exercise plans, session packages) never renders in a Dental-only clinic. A multi-specialty clinic shows each one only in that specialty's context.
- **Real data only:** every figure has a server source. Illustrative values appear only in design-review files, and are labelled as such.
- **Interaction rules:**
  - No drawers or slide-overs: use a centred modal or a full page.
  - Every page has populated, empty, loading, error and permission states.
  - No horizontal scroll from 375px up, and touch targets of at least 44px.

## 1. Role dashboards (one route, `clinic.dashboard`, a different page per role)

Every dashboard shares the shell: canvas, workspace, top bar, greeting with a heading, and a KPI row. What differs is the layout below the KPIs and the order of information, because each role works differently.

| Dashboard | For | Layout below the KPIs | Gradient "time" panel | Key actions |
|---|---|---|---|---|
| **Doctor** (mockup) | Practitioners, and a doctor-owner in Doctor Mode | Main: **Your queue** table (calling order, safety flags, wait time, Start or Resume). Lower: Notes to finish · Treatment in progress · Today's visits (new or repeat) | **Your appointments**: week strip plus today's timeline with a "now" line | Call next patient · My appointments |
| **Reception** | `front_office.operate` | Two **hero action tiles** across the top, larger than the KPIs: **NEW CASE** (violet gradient) and **REPEAT CASE** (teal gradient), with a fast patient search inside the Repeat tile. Main: the **live queue board**, grouped Waiting / Called / With doctor / Done, with check-in, call, reassign and priority. Side cards: dues to collect today, unassigned walk-ins | **Arrivals**: today's bookings still to arrive, the next 2 hours expanded, a late badge | New Case · Repeat Case · Book appointment · Check in |
| **Owner Command Center** (built in v1 layout, to be re-skinned) | Owner (a doctor-owner only in Owner Mode) and administrators (`staff.manage`, no owner blocks) | KPI row of money (billed, collected, net, outstanding). Main: a **ledger card** for today and this month, with every amount a drill-down. A **decision inbox** (approvals, expenses, payroll, queue exceptions). Lower: branches table · people and plan seat meters · security · sensitive actions | **Clinic day**: appointments and visits per hour across branches (the owner's only time view) | Financial summary · Approvals · Staff dashboards |
| **Billing** | Billing workspace | KPI row: collected today, pending dues, refunds today, open bills. Main: the **bills to collect** table (today's visits first) with a collect action in a modal. Lower: collections by method · refunds and adjustments (recorded) · recently paid | **Payments timeline**: receipts through the day | Collect payment · Open bill · Refund (by permission) |
| **Dental Doctor** | Doctor workspace in a dental-entitled clinic | The Doctor layout plus: the queue rows show tooth or treatment context (for example "RCT #36 stage 2"); **Treatment in progress** shows the dental plan stage; a **mini odontogram** preview of the selected patient (findings by FDI tooth) opens the consultation | As Doctor | Start consultation · Open chart |
| **Physio Doctor** | Doctor workspace in a physio-entitled clinic | The Doctor layout, but the lower cards are **Session plan today** (exercises due), **Rehab progress** (package sessions used / total, goal), and **Assessments due** (re-assessment dates). No tooth chart, no dental vocabulary | **Sessions**: today's therapy slots with session number (for example "Session 4 of 10") | Start session · Record assessment |

A user who holds several workspaces gets the page for their primary workspace, plus a header switch for the others. Whether to keep a union page instead is decision **V-03**.

## 2. Page-by-page plan

Status values below come from the replacement map: **NEW** (built since the reset) or **REPLACE** (old page still live), or **MISSING** (no page yet).

Every page also gets the standard QA:

- the states (populated, empty, loading, error, permission);
- widths 1440, 1024, 768 and 375 with no horizontal scroll;
- keyboard and focus, and reduced motion;
- server tests for allowed and denied access, and tenant and branch isolation;
- a dental and a physio clinic walkthrough where specialty applies;
- figures that reconcile with the lists they summarise.

### 2.1 Dashboards
See §1. For every dashboard:

- **Acceptance:** each KPI equals the count of the list it opens, in clinic time and the same branch scope.
- **Live:** updates every 45–60s with no entrance replay.
- **Empty day:** says so plainly, for example "No one is waiting". Zeros are never presented as data problems.

### 2.2 Patients
| Page | Users / permission | Purpose and layout | Notes |
|---|---|---|---|
| **Directory** (REPLACE) | `patient.view` (branch-limited) | Tabs My patients / All patients (practitioners); search by name, mobile or UHID; filter chips; a table on desktop that becomes row-cards on phones; each row shows identity, last visit, active care and safety flags | "My patients" stays the default for practitioners. Empty state: "No patients yet" with Register (if `patient.create`) |
| **Register** (REPLACE) | `patient.create` | Single-screen form in 3 cards: identity · contact · clinical safety (allergies, conditions); duplicate check before save (existing `DuplicateOverride`); consent where configured | Uses the owner's patient-form configuration. Phone layout is one column |
| **Patient 360** (MISSING page; backend built) | `patient.view` and per-section policy | Header card: identity, safety, active care, assigned doctor. Then section tabs: Overview · Visits · Treatment · Clinical (dental chart **or** physio record, by specialty) · Billing · Documents · Follow-ups · Communications · Audit (owner or settings) | Each section loads when opened. Writes follow `writeClinicalData`. A doctor-owner in Doctor Mode writes only for their own assigned patients |
| **Edit / transfer** (MISSING) | `patient.edit` / transfer rule | Modal or full page per field group; transfer is a modal with a reason | Audited |

### 2.3 Appointments, queue and check-in
| Page | Users | Layout | Notes |
|---|---|---|---|
| **Calendar** (REPLACE) | `appointment.view` | Day / week / month; date→time grid (no provider columns, as approved); the gradient panel holds the selected day's timeline; booking in a centred modal | Late, arrived and cancelled states carry labels. Find-next-slot stays |
| **Queue / Front Desk** (MISSING page; backend built) | `front_office.operate` | Board columns Waiting / Called / With doctor / Done (stacked on phones); a card per visit with case number, priority, doctor and wait; actions to call, reassign, set priority, check out | Position and case number per the master plan. Reassigning needs a reason |
| **New Case / Repeat Case intake** (REPLACE) | `front_office.operate` | Centred modal, step strip Search → Patient → Visit → Charges; quick care fields by specialty | Physio intake shows no dental fields and the reverse. Idempotent submit |
| **Follow-ups** (MISSING) | `clinical.edit` or `appointment.create` | Tabs Due today / Overdue / Upcoming; row actions book, call, dismiss with a reason | Linked from both dashboards once built |

### 2.4 Clinical encounters
| Page | Users | Layout | Notes |
|---|---|---|---|
| **Doctor Today** (MISSING) | practitioners | Full-page version of the dashboard queue, plus filters and history | It may merge with the Doctor Dashboard (decision **V-05**) |
| **Consultation workspace, Dental** (MISSING) | practitioners with dental entitlement | Left: patient summary and safety. Centre: chief complaint → findings on the **odontogram** (FDI, surface picker) → diagnosis → plan (procedures, teeth, stages) → prescription. Right: treatment stages and lab orders. Sign at the bottom | The tooth chart is dental-only. Draft autosave; signing locks |
| **Consultation workspace, Physio** (MISSING) | practitioners with physio entitlement | Assessment (pain scale, ROM, functional tests) → goals → exercise plan builder → session record (exercises done, response) → package progress | No dental controls at all |
| **Orders / investigations** (MISSING) | `clinical.view` and capabilities lab / x-ray | Table of orders by status with due dates; detail modal with results | Only with the capability |

### 2.5 Billing and money (built 2026-10-22, re-skin to v2)
| Page | Users | Notes |
|---|---|---|
| **Bills & Collections** (NEW) | billing workspace | Re-skin: KPI row; bills table; bill detail as a centred modal; collect, refund and adjust by permission at the bill's branch |
| **Refunds & Adjustments** (NEW) | billing workspace | Shown as recorded, with no request or approval stage. No claim that money moved |
| **Expenses** (NEW) | `expense.manage` / `expense.approve` | Pending and approved-unpaid kept apart; "all dates" from dashboard counts; sole-owner direct path labelled |
| **Financial Summaries** (NEW) | owner or administrator with `reports.view` | Date-basis notes; no profit, ledger or GST |
| **Consultation checkout** (MISSING) | `billing.create` | Lines from the encounter, then charges, then payment in one modal flow |

### 2.6 Reports (MISSING)
- **Users:** `reports.view`.
- **Layout:** a report catalogue in cards, each opening a full page with filters, a chart (only where it informs a decision) and an exportable table.
- **Scope:** the reports RPT-01..22 in the Owner Command Center blueprint.
- **Exports:** owner-only exports follow the expiry and export policy.

### 2.7 Staff and access (MISSING pages; backend built)
| Page | Users | Layout |
|---|---|---|
| **Staff directory** | `staff.manage` | Table plus row-cards: role chips, branches, login status, seat type |
| **Staff profile** | `staff.manage` | Header, then tabs: Roles & branches · Practitioner profile · Documents · Leave · Payroll (only with `payroll.view`) |
| **Invite / edit** | `staff.manage` | Full page, form in cards. Roles and branch changes are sensitive actions (doctor-owner: Owner Mode) |
| **Login seats** | owner | Seat meters as on the Command Center, plus who holds each seat |
| **Leave** | `leave.request` / `leave.approve` | My requests and To approve; calendar strip |
| **Payroll** | `payroll.view` / `payroll.manage` | Period list → period page; finalise and pay are T2 (password within 5 minutes) |

### 2.8 Branches, departments and rooms (MISSING)
- **Users:** `branch.manage` and `settings.manage`.
- **Layout:** branch cards with status and the plan's branch meter; departments and rooms as nested tables per branch.
- **Rules:** create and delete follow **D8**. Inactive items are kept read-only.

### 2.9 Settings, catalogues, forms and WhatsApp
| Page | Status | Notes |
|---|---|---|
| Settings index | MISSING | Card grid of setting areas, each showing only what the role may change |
| Patient form | REPLACE | Field editor and live preview side by side; preview stacks on tablet |
| Catalogue & pricing | MISSING | Treatments, stages and procedures with versioned prices; specialty filter |
| Consents | MISSING | Consent types; used in New Case and Edit |
| Specialties | MISSING | Owner only; offers only what the plan sells |
| WhatsApp | MISSING | Templates, delivery log, failures (matches the Command Center "messages failed" item) |

### 2.10 Approvals, notifications, support and audit
| Page | Status | Notes |
|---|---|---|
| Approvals inbox | HIDDEN (D1) | Open / Decided tabs; detail modal; owner decides. Menu entry waits for Phase 1.6 |
| Notifications | REPLACE | List grouped by day; read / unread; deep links |
| Support | REPLACE | Tickets list plus a conversation page |
| Audit (owner) | MISSING | Filterable trail of security and sensitive actions; hash-chain status shown read-only |

## 3. Decision register (open — needs your call)

| ID | Question | Why it matters |
|---|---|---|
| **V-01** | May the sidebar and top bar take the v2 *styling* (colours, radius, spacing) while keeping their items and behaviour? The mockup keeps today's styling exactly | The reference uses a top pill navigation; the approved shell is a sidebar. Structure stays either way |
| **V-02** | Font: adopt Plus Jakarta Sans (closest to the reference) instead of the current Inter? | Changes the whole portal's typography. Loaded from Google Fonts or self-hosted |
| **V-03** | A user with several workspaces (for example Doctor plus Reception): one page per primary workspace with a switch, or one combined page? | Today's engine unions them; v2 role pages are distinct by design |
| **V-04** | No patient-satisfaction or review data exists. Leave the reference's "reviews" gauge out (proposed), or plan a feedback feature? | No fabricated metrics |
| **V-05** | Should the Doctor Dashboard and Doctor Today become one page? | Avoids two near-identical screens |
| **V-06** | Trend deltas (±% vs last period) need stored history; show none until a comparison source is built (proposed)? | The reference shows deltas |
| **V-07** | In Doctor Mode, may a doctor-owner *view* (not edit) other doctors' patients, as any doctor can today? | Raised in the 2026-10-23 report; still open |
| Carried | D4 (Billing role and `discount.apply`), D5 (refund threshold), D8 (branch create/delete step-up), D9 (void bill T2), D18 (seat pools), no-subscription policy, payroll maker-checker and reopen, D22 (staff approval links) | From the owner blueprint register; not decided here |

## 4. Implementation order (after approval)

1. v2 foundation in `@/clinic`: tokens, canvas, workspace, card, KPI, badge, table, modal, the gradient time panel, motion and reduced-motion. Guard test: no `@/ovs` or `crmTheme` imports.
2. Doctor Dashboard (then Dental and Physio variants).
3. Reception Dashboard, Queue / Front Desk, and New / Repeat Case intake.
4. Patients: directory, register, Patient 360, edit.
5. Calendar.
6. Re-skin the Owner Command Center and Billing.
7. Consultation workspaces, Dental and Physio.
8. Staff, Leave, Payroll; Branches; Settings, Catalogue, Consents, WhatsApp.
9. Reports; Approvals (D1); Notifications, Support, Audit.
10. Retire each old page only after its replacement passes QA and grep shows no consumers (replacement-map rule).
