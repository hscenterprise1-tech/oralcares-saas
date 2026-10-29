# OralCares Clinic Portal — UX Re-Architecture Blueprint

**Date:** 2026-10-23. **Type:** audit and design specification only. No production code, route, schema, record or permission was changed while writing it.
**Branch:** `wip/m0-snapshot-2026-10-22` (HEAD `6bd9869`, large uncommitted working tree).
**Companion documents (not replaced):**
- `owner-command-center-blueprint.md`: the A–Z capability checklist (IDs such as `DSH-01`, `BIL-07`) and the decision register `D1`–`D27`. This blueprint cites those IDs and never re-decides them.
- `clinic-portal-replacement-map.md`: the binding page-by-page replacement tracker and the retirement rule.

**How to read status:** every claim below comes from the repository: routes (`php artisan route:list --path=clinic`, 304 routes), the `Inertia::render` targets in `app/Http/Controllers/Clinic/**`, the page files in `resources/js/pages/**`, `ClinicFrontend::AVAILABLE`, `ClinicNavigation::DOMAINS`, services, and the test suites. "Works" means code plus passing feature tests. It does **not** mean a browser walkthrough unless one is stated.

---

## 0. Executive summary

1. **The patient journey breaks after check-in.**
   - New Case and Repeat Case register, check in and (optionally) charge the visit correctly: request-key idempotency, a registration lock with a duplicate gate, branch access checks and fee verification are all tested.
   - But the next screens in the journey have **no page file**, so their routes render the `Unavailable` placeholder:
     - the Queue (`ClinicPortal/FrontDesk/Index`);
     - the doctor's Today (`ClinicPortal/Today/Index`);
     - the consultation (`ClinicPortal/Consultations/Workspace`);
     - the patient record (`ClinicPortal/Patients/Show`);
     - after-visit billing (`ClinicPortal/Billing/Checkout`).
   - The backend for every one of these exists and is tested.
2. **The backend is far ahead of the UI.**
   - 25 of the 44 distinct `Inertia::render` targets in `app/Http/Controllers/Clinic` have no page file. 19 do.
   - Most missing pages are a UI problem, not a business-logic problem.
3. **Three Owner Command Center visual directions were rejected** (the original grid, Pulse, Lenses). A fourth, Concept C "The Owner's Sheet", exists only as a review artifact (`docs/design-review/clinic-portal-v2/owner-command-center-concept-c-fresh.html`).
   - The live Command Center *data* (`OwnerCommandCenter`, nine sections, 34 passing tests) is sound and is reused below.
4. **Dental and Physio are already separate in the backend**: separate writers, Patient 360 sections, timeline categories and check-in charging rules. The UI that would show the difference does not exist yet.
5. **Twelve product decisions block parts of the plan** (§15). The new ones found in this audit:
   - **D28:** whether a repeat visit is charged differently. Today every visit charges the practitioner's `consultation_fee`; no repeat-fee rule exists anywhere in code or config.
   - **D29:** where New Case and Repeat Case live, in a dialog or a focused page.

---

## 1. Current-state inventory

### 1.1 Classification key

| # | Class | Meaning |
|---|---|---|
| 1 | Working and reusable | Backend and page both work; keep the page or only restyle it |
| 2 | Working, needs a new UI | Backend works; the page is live but rejected under the replacement decision |
| 3 | Partially implemented | Some of the workflow works; named gaps remain |
| 4 | Broken | Code exists but fails (a test or a render) |
| 5 | Missing | 5a: the backend exists and the page does not. 5b: the backend is missing too |
| 6 | Blocked | Waits on an unresolved decision (§15) |

### 1.2 Shell, auth and global

| Item | Evidence | Class | Notes |
|---|---|---|---|
| Clinic login, forgot / setup password, accept invitation | `Clinic/Auth/*.tsx`; Auth tests 37 | 1 | Approved and frozen |
| Sidebar, top bar, account menu, branch switcher, global search, notifications menu, Owner Mode dialog | `clinic/shell/*` | 1 | Frozen: structure, width, labels, icons, order, behaviour |
| Navigation registry | `ClinicNavigation::DOMAINS` × `ClinicFrontend::AVAILABLE` | 1 | Unbuilt items show "Soon" |
| `Unavailable` placeholder | `ClinicPortal/Unavailable.tsx` via the `app.tsx` resolver | 1 (temporary) | Shown by every missing page in 1.3 |
| Owner Mode, T1/T2 step-up | `OwnerMode`, `OwnerModeRequired`, `useOwnerModeStepUp`; Portal tests | 1 | Binding semantics in memory `owner_doctor_mode_semantics` |
| Onboarding (wizard, account, done) | `ClinicPortal/Onboarding/*`; Onboarding tests 42 | 2 | Runs before the portal |

### 1.3 Pages: route → render target → state

| Area | Route (name) | Render target | Page file | In `AVAILABLE` | Class |
|---|---|---|---|---|---|
| Dashboard (owner/admin) | `clinic.dashboard` | `ClinicPortal/CommandCenter/Index` | yes | yes | 2: data sound, visual rejected |
| Dashboard (others) | `clinic.dashboard` | `ClinicPortal/Dashboard` | yes | yes | 2: old shared widget composition |
| Appointments | `clinic.appointments.index` | `ClinicPortal/Appointments/Index` (+14 components) | yes | yes | 2 |
| Patients list | `clinic.patients.index` | `ClinicPortal/Patients/Index` | yes | yes | 2 |
| Register patient | `clinic.patients.create` | `ClinicPortal/Patients/Create` | yes | yes | 2 |
| **Patient 360** | `clinic.patients.show` | `ClinicPortal/Patients/Show` | **no** | no | **5a** |
| **Patient edit** | `clinic.patients.edit` | `ClinicPortal/Patients/Edit` | **no** | no | **5a** |
| **Queue / Front Desk** | `clinic.reception`, `clinic.reception.list`, `clinic.queue` | `ClinicPortal/FrontDesk/Index`, `/List` | **no** | no | **5a** (sidebar "Queue · Soon") |
| New / Repeat Case | dialog opened from the top bar "+ New", dashboards, appointments, patients | `Clinic/Reception/intake/ReceptionIntakeDialog` | yes (legacy folder) | n/a (dialog) | 2 |
| **Doctor Today** | `clinic.consultations.index` | `ClinicPortal/Today/Index` | **no** | no | **5a** |
| **Consultation workspace** | `clinic.consultations.show` | `ClinicPortal/Consultations/Workspace` | **no** | no | **5a** |
| **Orders (lab / imaging)** | `clinic.orders.index`, `.show` | `ClinicPortal/Orders/Index`, `/Show` | **no** | no | **5a** |
| Bills & collections | `clinic.billing.index` | `ClinicPortal/Billing/Index` | yes | yes | 1 (built 2026-10-22) |
| Refunds & adjustments | `clinic.billing.reversals` | `ClinicPortal/Billing/Reversals` | yes | yes | 1 |
| Financial summary | `clinic.billing.summary` | `ClinicPortal/Billing/Summary` | yes | yes | 1 |
| **Checkout (after visit)** | `clinic.billing.checkout` | `ClinicPortal/Billing/Checkout` | **no** | no | **5a** |
| Expenses | `clinic.expenses.index` | `ClinicPortal/Expenses/Index` | yes | yes | 1 |
| **Follow-ups board** | `clinic.follow-ups.index` | `ClinicPortal/FollowUps/Index` | **no** | no | **5a** (sidebar "Soon") |
| **Reports** | `clinic.reports.index` | `ClinicPortal/Reports/Index` | **no** | no | **5a / 6 (D20)** (sidebar "Soon") |
| **Staff directory / profile / form** | `admin.employees.*` | `ClinicPortal/Staff/Index`, `/Show`, `/Form`, `/DocumentSettings` | **no** | no | **5a / 6 (D6)** |
| **Payroll** | `clinic.payroll.*` | `ClinicPortal/Payroll/Index` | **no** | no | **5a / 6 (D3, D26)** |
| **Leave** | `clinic.leave.*` | `ClinicPortal/Leave/Index` | **no** | no | **5a / 6 (D7)** |
| **Branches / Departments / Rooms** | `admin.branches.*` etc. | `Clinic/Admin/Infrastructure/*` | **no** | no | **5a / 6 (D8)** |
| **Settings index** | `admin.settings.index` | `Clinic/Admin/Settings/Index` | **no** | no | **5a** |
| Settings: patient form | `admin.settings.patient-form` | `ClinicPortal/Settings/PatientForm` | yes | yes | 2 |
| **Catalogue & pricing** | `admin.catalog.*` | `ClinicPortal/Admin/Catalog` | **no** | no | **5a** |
| **Consent types** | `admin.consents.*` | `ClinicPortal/Admin/Consents` | **no** | no | **5a** |
| **WhatsApp** | `clinic.whatsapp.*` | `ClinicPortal/WhatsApp/Index` | **no** | no | **5a** |
| Approvals inbox | `clinic.approvals.index` | `ClinicPortal/Approvals/Index` | yes | **held back** | **6 (D1)** |
| Notifications | `clinic.notifications.index` | `ClinicPortal/Notifications` | yes | yes | 2 |
| Support | `clinic.support.*` | `ClinicPortal/Support/Index`, `/Show` | yes | yes | 2 |
| Legacy visits list | `clinic.visits.index` | `Clinic/Visits/Index` | **no** | no | **4**: a legacy route whose page does not exist. Not linked from the navigation; retire it or point it at Patient 360 |

### 1.4 Workflows, end to end

| Workflow | Backend | UI | Class | Break point |
|---|---|---|---|---|
| New Case: register and check in | `PatientController@store` (`check_in=1`), the registrar lock and duplicate gate, `CheckInCharges`, `IntakeSubmissions::once` | the dialog | 2 | None in itself; it hands off to a missing Queue page |
| Repeat Case: find and check in | `ReceptionController@search` (≥3 characters, at most 8, clinic-wide identity, masked across branches), `@checkIn`, `expected_charge` verification | the dialog | 2 | Same |
| Booking → arrival | `bookingMatches`, `prospectBooking`, appointment status | appointments page, dialog | 2 | — |
| Queue: call, reassign, priority, correct visit | `ReceptionController` queue actions, `QueueCorrections`, `DoctorQueue` | **none** | 5a | **The queue cannot be seen or worked by the desk** |
| Doctor: start from queue, notes, diagnoses, procedures, Rx, orders, finalize, addenda | `ConsultationController`, `PrescriptionController`, `InvestigationController`; Consultations tests 86 | **none** | 5a | **A doctor cannot consult in the UI** |
| Dental charting, plans, perio, endo, implant, ortho, lab | `DentalController`, `DentalSpecialtyController` | **none** | 5a | — |
| Physio assessment, plan, exercises, sessions, packages | `PhysioController` (encounter-scoped) **and** `TreatmentCareController` (treatment-scoped) | **none** | 5a + **overlap** | Two entry points write the same physio domain. The UI must pick one per context (§6.4) |
| Long treatments (stages, pause, resume) | `PatientTreatmentController`, `TreatmentBoardController`; Treatments tests 70 | **none** | 5a | — |
| After-visit billing | `CheckoutController@show/store`, `ReceptionController@collect` (`EncounterCheckout`) | **none** | 5a | **A consultation's bill cannot be raised from the UI** |
| Payments, refunds, adjustments, print | `CheckoutController@pay/refund/adjust/printBill`; MySQL race proofs | Billing pages | 1 | Adjustment idempotency key is D27 |
| Patient record (360) and edit | `Patient360Controller`, `Patient360Sections` (role-shaped), `PatientController@update/transfer` | **none** | 5a | Every "open patient" link has nowhere to go |
| Follow-ups | `PatientFollowUpController`, `FollowUpBoardController` | **none** | 5a | — |
| Patient reminders (outbound) | — | — | **5b** | No patient notification or reminder sender (memory `staff_module_build_state`) |
| Consents | `PatientConsentController`, `ConsentTypeController` | **none** | 5a | No consent UI at all (audit 2026-10-18) |
| Expenses | `ExpenseController`, `ClinicExpenses` | Expenses page | 1 | — |
| Payroll | `PayrollController`, `ClinicPayroll` | none | 5a/6 | D3 maker-checker, D26 reopen |
| Staff, invitations, documents, branch access | `EmployeeController`, `EmployeeInvitationController`, `StaffProfileController`, `StaffDocumentController` | none | 5a/6 | D6 self-escalation |
| Seats vs plan | `ResourceLimitService` | Command Center "People" only | 3/6 | D10 pools, D18 plan source; dev clinics have no plan |
| WhatsApp | `WhatsAppController`, webhook | none | 5a | — |
| Reports | `ReportController` (3 routes) | none | 5a/6 | D20 |
| Approvals / deletion governance | `DeletionRequestController`, `DeletionGovernance`; Governance tests 21 | page hidden | 6 | D1, D9 |

### 1.5 Tests and known failures

- **Clinic feature tests by folder:**
  - Patients 186, Portal 168, Reception 145, Security 98, Staff 91, Consultations 86;
  - Treatments 70, Admin 55, Appointments 51, Billing 45, Foundation 43, Onboarding 42;
  - Auth 37, CaseNumbers 34, Tenancy 24, Business 24, WhatsApp 21, Practitioner 21, Governance 21;
  - Communication 10, Reports 6, FollowUps 4, Shell 3, Visits 2.
- **Latest full suite (SQLite, 2026-10-23 01:44):** 3,986 passed, 9 skipped, **15 failed**. The failures equal the 2026-10-21 baseline by name. The clinic-related ones are legacy paths:
  - `ClinicWorkspaceTest` (3), a schema `QueryException`;
  - `ClinicalFoundationTest` "fetch clinical departments via API", a `RouteNotFoundException`;
  - `PatientRegistrationTest` "registers a patient with uhid", a `QueryException` on the old UHID path.

  The others are Company or CRM, inventory, tasks and tenant provisioning, all outside the Clinic Portal.
- TypeScript: 24-error baseline (no new errors were introduced by the Billing and Command Center work).
- Browser evidence exists only for the pages built since 2026-10-22: Billing, Expenses, the Command Center (live, QA clinic 62), and Owner/Doctor Mode. **No page in the 5a list has ever had browser QA.**

### 1.6 UI-only versus backend problems

| Problem | Kind |
|---|---|
| Queue, Today, Workspace, Patient 360, Checkout, Orders, Follow-ups, Staff, Payroll, Leave, Branches, Settings index, Catalogue, Consents, WhatsApp, Reports have no page | **UI only** (5a) |
| Clinic-wide completed consultations figure | Backend (`DSH-02`: only `my_seen` exists) |
| Expenses paid out this month on the dashboard | Backend read model (the Expenses page has it; the dashboard does not) |
| Patient reminders and notifications | Backend (5b) |
| Seat pools, plans as sold | Schema and decision (D10, D18) |
| Repeat-visit fee | Decision (D28) |
| Physio written through two controllers | Backend design overlap: the UI must not expose both for one context |
| Legacy UHID and workspace tests | Backend legacy, outside the new UI |

---

## 2. Target information architecture

The sidebar and top bar stay exactly as approved. **No new top-level item.** Every new page lives under an existing destination: an in-page tab, a focused full page with its own URL, or a centred modal (never a drawer or slide-over, per memory `feedback_no_drawers`).

```
Sidebar · Main (unchanged order)
├─ Dashboard ........ role home (§4.1–4.4): Owner Command Center · Front Desk home · Doctor Today home · Billing home
├─ Appointments ..... calendar (day / week / month) · booking dialog · find next slot
├─ Patients ......... directory · Register (full page) · Patient 360 /patients/{id} · Edit /patients/{id}/edit
├─ Queue ............ Front Desk board /reception · stage lists /reception/{list}       (front_office.operate)
├─ Billing .......... Bills & collections · Refunds & adjustments · Expenses · Financial summary · Checkout /billing/checkout/{encounter}
└─ Reports .......... report index (D20)

Sidebar · Additional (unchanged)
├─ Follow-ups ....... board (due · overdue · booked · done)
├─ Notifications
└─ Support

Account menu · Clinic administration (unchanged entries)
├─ Approvals ........ Open | Decided (D1)
├─ Staff ............ Directory · Profile · Invite · Documents · Roles & branch access · Seats · Leave · Payroll
├─ Branches ......... Branches · Departments · Rooms
└─ Settings ......... Profile & branding · Patient form · Check-in & queue · Catalogue & pricing · Consents · WhatsApp · Specialties · Audit history

Not in navigation (reached from context only)
├─ Consultation workspace /consultations/{encounter}   (from Today, the Queue and Patient 360)
├─ Orders /orders, /orders/{order}                    (from the workspace and Patient 360)
└─ Treatment /treatments/{treatment}                   (from Patient 360 and Today)
```

**Role homes, one route:** `clinic.dashboard` resolves the composition from `ClinicAccess::workspaces()`, as it does today.
- `command_center` → Owner Command Center.
- `front_desk` → Front Desk home.
- `today` → Doctor home.
- `billing` → Billing home.

A person with several workspaces gets one composed home, never a redirect.

---

## 3. Role × permission × page matrix

Source: `ClinicPermission::defaultRolePermissions()` and `ClinicAccess::resolveWorkspaces()`. Every cell is enforced on the server; the UI only hides what the server would refuse.

**Columns:**
- **Owner**: the primary owner with owner privileges. That is a non-doctor owner from sign-in, or a doctor-owner in Owner Mode.
- **Dr-Owner (DM)**: a doctor-owner in Doctor Mode, holding the Practitioner template.
- **Admin**: Clinic Admin, which is every permission except `specialty.configure`.

**Legend:** ● full, ◐ partial or own scope, ○ read-only, — none.

| Page / action | Owner | Dr-Owner (DM) | Admin | Doctor | Reception | Billing |
|---|---|---|---|---|---|---|
| Home | Command Center | Doctor home | Command Center (no owner-only sections) | Doctor home | Front Desk home | Billing home |
| New Case (register + check in) | ● | — | ● | — | ● | — |
| Repeat Case (check in) | ● | — | ● | — | ● | — |
| Queue board and actions | ● | ◐ own queue, call next | ● | ◐ own queue | ● | — |
| Appointments | ● | ○ | ● | ○ | ● | — |
| Patients list / 360 | ● | ● clinical view | ● | ● clinical view | ◐ no clinical fields | ◐ identity + money |
| Patient edit / transfer / merge | ● | — | ● | — | ● edit, merge | — |
| Consultation workspace | — (Owner Mode is not clinical) | ● | — | ● | — | — |
| Dental / Physio clinical actions | — | ● if the specialty is active | — | ● if active | — | — |
| Checkout (raise bill) | ● | — | ● | — | ● `billing.create` | ● |
| Take payment | ● | — | ● | — | ● | ● |
| Discount | ● | — | ● | — | — | ● (D4 questions this) |
| Refund / adjustment | ● (Owner Mode gate, STANDARD tier) | — | ● | — | — | — (`refund.process` not granted) |
| Expenses: record / approve / pay | ● | — | ● | — | — | — |
| Financial summary | ● | — | ● | — | — | ◐ D20 |
| Reports | ● | — | ● | — | — | ◐ D20 |
| Staff, roles, branch access | ● T1/T2 | — | ● (D6 self-escalation) | — | — | — |
| Payroll | ● (D3) | — | ● | — | — | — |
| Leave: request / approve | ● / ● | ● / — | ● / ● (D7) | ● / — | ● / — | ● / — |
| Branches / rooms | ● T1 (D8) | — | ● | — | — | — |
| Settings, catalogue, consents | ● | — | ● (not specialties) | — | — | — |
| Specialties configure | ● only | — | — | — | — | — |
| Approvals inbox | ● | — | ◐ answers requests | — | — | — |
| Owner-only dashboard sections (money, people, security, activity) | ● | — | — | — | — | — |

Owner Mode rules are unchanged:
- A doctor-owner enters Owner Mode explicitly; leaving it or letting it expire restores Doctor Mode.
- A Doctor or Admin role never grants Owner authority.
- T2 (fresh password within 5 minutes) applies to the documented sensitive set.
- Non-owners never see a step-up prompt.

---

## 4. Page-by-page requirements

Each page lists:
- purpose and roles;
- the primary task and secondary actions;
- the key information, filters and data sources;
- where it leads;
- its states (§11 defines the shared state patterns).

### 4.1 Owner Command Center (`clinic.dashboard`, `command_center`)

- **Purpose:** the owner's decisions for today, not a report.
- **Roles:**
  - Owner (all sections).
  - Admin (no money, people, security or activity sections; `OwnerCommandCenter::OWNER_SECTIONS`).
  - A doctor-owner only in Owner Mode.
- **Primary task:** clear what is waiting on the owner.
- **Secondary actions** (all exist):
  - New Case, Repeat Case, Book appointment, Billing;
  - Staff dashboards (`DashboardVisibility`), Refresh (a partial reload of `sections`).
- **Composition** (this replaces the grid, Pulse and Lenses; Concept C is the reference direction, pending approval):
  1. A masthead: place, date, greeting, and one sentence built by fixed rules from the outstanding figure, the decision count and the floor state.
  2. A three-part spread:
     - **Position**: outstanding as the hero figure; this month's collected-versus-billed bar; a Today / This month ledger.
     - **On your desk**: ranked decision items as a stack you leaf through.
     - **The floor**: booked → checked in → waiting → with a doctor; completed shows "not sent yet".
  3. An instrument strip: plan state, seats against limits, failed security checks.
  4. Branches: only when the clinic has two or more.
  5. The audit trail, plotted at real times over seven days.
  6. A text index of destinations.
- **Data, all existing:**
  - `today`: `patients_today`, `appointments_today`, `waiting_now`, `with_doctor_now`.
  - `money`: `BillingRegister::summary` for today, the month, and outstanding at all dates.
  - `spending`: pending and approved expenses within reach.
  - `decisions`: `needsAttention` plus follow-ups due.
  - `branches`: `branchActivity`.
  - `people`: `ResourceLimitService` counts, profiles without a login, approved leave today.
  - `security`: `ownerSecurity`.
  - `activity`: `ownerActivity`.
  - `mine`: for a doctor-owner.
- **Calculated values:** collection rate = collected ÷ billed (this month); net = collected − refunded.
- **Unavailable, never shown as zero:**
  - completed consultations clinic-wide (`DSH-02`);
  - expenses paid out this month;
  - a seat limit when no plan is on record.
- **Drill-downs:** every money figure opens the Billing list it totals (tested: "every money figure is the total of the Billing list it opens"). Today's counts open nothing until the Queue page exists.
- **States:**
  - each section fails independently (`state: error`, with Retry);
  - no plan → an amber notice plus "no limit on record";
  - nothing on the desk → a "Desk clear" note.

### 4.2 Front Desk home and Queue board (`clinic.reception`, `clinic.reception.list`, `clinic.queue`)

- **Purpose:** run arrivals and the queue without leaving one screen.
- **Roles:** Reception, Owner, Admin (`front_office.operate`, `queue.manage`).
- **Primary task:** check people in and keep the queue moving.
- **Layout:**
  - **Desktop:** a three-column board. Left: arrivals (today's bookings with Arrived / Check in). Centre: waiting by doctor, with position, wait time, priority and safety flags. Right: with a doctor and done.
  - **Tablet:** two columns, with the Done column as a tab.
  - **Phone:** a single stage list with stage tabs.
- **Actions:**
  - New Case and Repeat Case;
  - call or call again, not present, back to queue, left (`updateQueueStatus`);
  - reassign doctor (`reassign`), priority (`updatePriority`), correct visit (`correctVisit`), call next (`callNext`);
  - collect for a signed consultation (`collect`).
- **Data:** `FrontDesk`, `QueueBoard`, `DoctorRoster`, `VisitLabels`, `PatientFlowConfigService::queueShown`.
- **Refresh:** live props (`useLiveProps`), with optimistic stage moves that roll back on refusal. A refusal is shown inline, for example "the doctor took them first", from `QueueCorrections`.
- **Rules:**
  - Positions follow the case number and queue position rule (master plan v1).
  - When a clinic switches the Queue module off, the board shows arrivals and doctors only, with no ordering.

### 4.3 Doctor home and Today (`clinic.consultations.index`)

- **Purpose:** see my next patient and finish my notes.
- **Roles:** practitioners (`today` workspace) and doctor-owners in Doctor Mode.
- **Primary task:** start the next consultation (`start-from-queue`).
- **Secondary actions:** resume a draft, finish unsigned notes, open a patient, open my follow-ups.
- **Composition:** the approved Doctor Dashboard design. The appointments panel and the refined Option B layout are frozen references (`doctor-dashboard-option-b-refined.html`).
- **Data:** `DoctorToday`; `mySeen`, `myNotes`, `myQueue`; the doctor's appointments.
- **Gaps:**
  - The week arrows and day pills need a dashboard date parameter (a small backend addition).
  - Every "Soon" in the approved mockup turns into a link only when its page ships.

### 4.4 Billing home (`billing` workspace)

- **Purpose:** money in and out today.
- **Roles:** Billing staff; also composed into Reception's home when that member holds `payment.collect`.
- **Primary task:** settle open bills (Checkout) and record payments.
- **Data:**
  - `BillingRegister`: bills and collections lists, today's figures;
  - consultations waiting for checkout (signed encounters without a bill);
  - payment-message failures (`paymentMessages`, retry).
- **Never shown to Billing:** clinical detail. Refunds also stay out (Billing staff lack `refund.process`).

### 4.5 Patients directory (`clinic.patients.index`)

- **Purpose:** find anyone fast.
- **Search:** name, phone or patient number, server-side. Filters: branch (within reach), status, last visit.
- **Rows:** identity (name, age and sex, case number), last visit, branch, safety flags the viewer may see.
- **Actions:** New Case, Register, open Patient 360.
- **Masking:** a patient of a branch the member cannot open is masked and has no link (`PatientBranchAccess`).

### 4.6 Patient 360 (`clinic.patients.show`), Edit (`clinic.patients.edit`)

- **Purpose:** one record, shaped by role (`Patient360Access`).
- **Layout:**
  - an identity header: name, number, age and sex, phone, branch, safety flags, active care;
  - section tabs from `Patient360Sections`: Overview, Timeline, Encounters, Prescriptions, Investigations, Treatments, **Dental** (Dental clinics only), **Physio** (Physio clinics only), Lab, Documents, Consents, Billing, Follow-ups, Communication;
  - each section lazy-loads (`clinic.patients.360.section`).
- **Actions:**
  - Check in (Repeat Case, preselected); Book; Start consultation (practitioners);
  - Edit; Transfer branch; Upload document; Record or revoke consent;
  - Add follow-up; Sell a session package (Physio);
  - Request deletion or void (governance).
- **Rules:**
  - a section the role may not see is not sent;
  - "No … yet" appears only when the source truly has no rows (defect found 2026-10-11).

### 4.7 Consultation workspace (`clinic.consultations.show`)

- **Purpose:** record the visit and finish it.
- **Layout:**
  - **Desktop:** the left rail holds the patient summary and safety. The centre holds the note: complaint, history, examination, diagnosis, plan. The right rail is the specialty panel: the Dental chart or Physio assessment, orders, Rx.
  - **Tablet:** the specialty panel becomes a full-width tab.
- **Actions:**
  - autosave (`autosave`); diagnoses, procedures and treatments;
  - prescription (draft → finalize → print); orders;
  - finalize with a signature; addendum after finalizing.
- **Rules:**
  - Finalize is refused while required parts are empty; the server decides.
  - After finalizing, a payment-at-checkout clinic routes to Checkout.

### 4.8 Checkout (`clinic.billing.checkout`)

- **Purpose:** turn a signed visit into a bill and take payment.
- **Layout:** the encounter's lines at catalogue price (procedures, treatments), a discount (permission plus the D4 threshold), the payment method, amount and reference, then the receipt.
- **Idempotency:** a request key (same pattern as the refunds key).
- **Handling a refusal:** the bill is unchanged and the server's message is shown.
- **After success:** print, WhatsApp the receipt (when configured), next patient.

### 4.9 Bills & collections, Refunds & adjustments, Financial summary, Expenses

These are built (class 1) and only need restyling into the new language.
- Keep their filters: period, branch, method, status.
- Keep the figure ⇄ list equality guaranteed by `BillingRegister`.
- Refund and adjustment keep their Owner Mode gate (`billing.refund`, `billing.adjust`, STANDARD tier).
- The adjustment idempotency key waits on D27.

### 4.10 Appointments (`clinic.appointments.index`)

- **Purpose:** the calendar from date to time, with no provider columns (memory `appointments_module_build_state`).
- **Keep:**
  - booking, reschedule, cancel, status changes;
  - linking a booking to a patient; find next slot;
  - booking for someone not yet registered, picked up at check-in by mobile number.
- **Rebuild:** the visual language, with arrival actions joined to the Queue.

### 4.11 Follow-ups board (`clinic.follow-ups.index`)

- **Tabs:** Due today, Overdue, Booked, Done. Each row offers Book (opens the booking dialog), Annotate, Complete, Cancel.
- **Mine / All toggle:** for practitioners.

### 4.12 Orders (`clinic.orders.*`)

- **Flow:** collect → enter result → review per item, with cancel available.
- **Grouping:** by status; a "reports due" figure feeds the decisions list.
- **Visibility:** shown only when the `lab` or `xray` capability is on.

### 4.13 Staff, seats, leave, payroll (account menu → Staff)

- **Staff:**
  - directory; profile (identity, practitioner profile, fee, branches, roles, documents);
  - invite (an invitation, never a password); seat usage against the plan.
- **Leave:** a request and approval queue. The approver can never be the requester (D7).
- **Payroll:** a period list → entries → finalise → pay. Finalise and pay already require a fresh password (`ClinicPayroll`, FRESH tier); salary and advance changes use the STANDARD tier. Blocked by D3 and D26.
- **Server refusals:** "you cannot change your own access" (D6); "no seats left" (`ResourceLimitService`, under a lock).

### 4.14 Branches, departments, rooms

- A directory plus create and edit dialogs. Delete is refused while history exists (`RetainedClinicalHistory`).
- Branch create and delete need T1 (D8).
- Branch limit: `enforceBranchLimit`.

### 4.15 Settings

- **Settings index:** cards replaced by a two-pane settings page with sections:
  - Profile & branding;
  - Patient form (built);
  - Check-in & queue (`PatientFlowConfigService`: visit reason, priority, queue, show fee, payment timing);
  - Catalogue & pricing (`admin.catalog.*`, branch overrides, versions and publish);
  - Consents; WhatsApp; Specialties (owner only, T1); Settings audit history.
- **Audit:** every save is audited.

### 4.16 Approvals, notifications, audit

- **Approvals** (after D1): Open | Decided. Each item can be decided in place or from its source page with identical results (`DeletionGovernance`).
- **Notifications:** the member's own feed, and mark as read.
- **Audit history:** owner only, filterable; read from the security audit chain.

### 4.17 WhatsApp, reminders

- **WhatsApp page:** a delivery log with a failed filter (feeds the "messages not delivered" decision), templates and connection status. Credentials are never shown.
- **Patient reminders:** **5b**, no sender exists. Shown as "Soon"; not designed as working.

### 4.18 Support

- The clinic's tickets: list, thread, and new request. Restyle only.

---

## 5. New Case and Repeat Case: flow specifications

### 5.1 Recommendation: keep one dialog, made larger and faster

**Recommendation:** one large centred dialog with two modes, as today: New / Repeat as a switch. It sits over whatever page the desk is on, as a full-height sheet on phones.

**Why:**
- the desk never loses the queue;
- switching modes keeps everything typed (already implemented);
- duplicate detection can turn New into Repeat for the found patient without re-typing;
- the request key is created once when the dialog opens.

A focused page (`/reception/new`) is offered as the alternative under **D29**. A page is better only if clinics demand full registration forms on every visit.

### 5.2 New Case

| Step | Behaviour (as implemented unless marked new) |
|---|---|
| Entry | Top bar "+ New" (`IntakeContext`), the dashboards' New Case action, Appointments (`CaseButtons`, prefilled from a booking), the Patients list, a doctor's card (prefilled doctor). Keyboard: ⌘/Ctrl+K search offers "New Case" (new: a shortcut hint) |
| Patient fields | Mobile first, then name, sex and age (or date of birth). The clinic's optional fields are folded unless required (`PatientFieldConfigService::SURFACE_NEW_CASE`, per specialty). Medical safety (allergies, conditions, alerts) is shown only when the member `canRecordAtRegistration` and the role is in `alert_roles` |
| Duplicate detection | Live matches under the fields as they're typed (`check-duplicate`: phone, email, name + date of birth). "Use this patient" switches to Repeat Case with no registration sent. "This is a different person" needs a reason, is audited, and is re-checked under the clinic's registration lock |
| Booking match | Typing a mobile number that has a booking today for an unregistered person offers "This is the 10:30 booking with Dr X" (`bookingMatches`, exact match only) |
| Visit | Doctor (today's roster for this branch; the doctor decides the specialty), or a specialty and area when no doctor is chosen. Visit type and reason (catalogue filtered by specialty and area; validated again by `VisitContext`). Priority when enabled |
| Fee shown | The doctor's consultation fee, when `show_fee` is set, or always when the clinic charges at check-in and the member may bill. **No repeat-fee rule exists (D28)** |
| Submit | **Register & check in** (primary) or **Register only**. One request with `idempotency_key`; `IntakeSubmissions::once` returns the first result on a retry |
| Server | Registration under the lock → duplicate gate → patient plus encounter and visit → queue item → `CheckInCharges::chargeVisit` (only when the clinic charges at check-in, the doctor has a fee, the module charges at check-in (Physio does not), and no session package covers the visit), all in one transaction |
| Charge verification | The desk sends `expected_charge`. If the server's amount differs, the check-in is refused with the real amount. No one is charged a sum they were not shown |
| Payment | Never inside check-in. After success, when something is owed and the member has `payment.collect`, a payment panel (`CheckInPayment`) posts to the billing payment endpoint, then shows the receipt |
| Success | Who, the case number, for which doctor, the position in the queue, the amount owed or paid. Next: New Case again, Open patient (once Patient 360 exists), Print receipt, Close |
| Failure | Field errors inline (422). A duplicate → the match list with its reason. A changed fee → the refusal shows the new amount and asks again. A network failure → retry with the same key (safe). A lost session → sign in, then the dialog restores the draft (new: draft kept in `sessionStorage`) |

### 5.3 Repeat Case

| Step | Behaviour |
|---|---|
| Search | Name, phone or patient number, after 3 characters, at most 8 results (`clinic.reception.check-in.search`). A recent list when the box is empty (`recent`) |
| Results | Name, age and sex, number, phone (masked to the last 4 digits for a cross-branch match), last visit, today's booking, "already in the queue" (disabled, with a link to their place) |
| Ambiguity | Two identical names → both shown with distinguishing number and age, never preselected |
| No result | "No patient found" → "Register as New Case" carrying over the typed name or phone |
| Confirm | An identity card with safety flags the member may see and active care (package sessions left, an open treatment) |
| Visit | As in New Case. A doctor preselected from today's booking |
| Fee | As in New Case, including the package cover rule (`packageCovers`) |
| Submit | **Check in** with `idempotency_key` and `expected_charge` → `clinic.reception.check-in` |
| Success and payment | As in New Case |

### 5.4 Keyboard, tablet and phone

- **Keyboard:**
  - the dialog traps focus; Escape asks before discarding;
  - Tab order follows the reading order: mode → patient → visit → submit; Enter in search selects the highlighted result; the arrow keys move through results;
  - Ctrl+Enter submits;
  - every control is reachable and labelled.
- **Tablet (768–1279):** the patient and visit halves stack. The submit bar is sticky at the bottom.
- **Phone (<768):** a full-height sheet. The mobile field uses `inputmode="tel"`. The submit bar stays above the keyboard. All targets are at least 44px.

---

## 6. Dental versus Physio

The active modules (`ClinicSpecialties::activeKeys`, `specialty_entitlement_model`) and the doctor's practice area decide what appears. **Inactive-module UI is never rendered or sent.**

| Area | Dental | Physio |
|---|---|---|
| Check-in charge | The consultation fee at check-in when configured | **None at check-in** (`charges_at_check_in=false`); billed per session or package |
| Workspace panel | Tooth chart (FDI numbering, per-tooth history `dental.tooth`), periodontal chart, findings | Assessment: body region, pain scale, range of motion, special tests, impression |
| Planning | Treatment plan: propose → accept → complete or cancel items; plan transitions on Patient 360 | Plan of care: goals, frequency, exercises (`plans.exercises`), close or discharge |
| Long care | Endodontic, implant and orthodontic cases with visits; lab orders with steps | Sessions (open → save → complete → reverse); progress across sessions |
| Packages | — | Session packages: sell, cancel, adjust, eligibility; a package covers visits of that specialty |
| Patient 360 | Dental section with a 3D or 2D chart; a Lab section | Physio section: plans, sessions, packages, progress |
| Timeline | `dental`, `lab` categories | `physio` category |
| Never show | — | No tooth chart, perio, endo, implant, ortho or dental lab |

**§6.4 Physio entry-point rule:**
- Inside a consultation, use the encounter-scoped `PhysioController` (assessment, start plan).
- For a running course of care opened from Patient 360 or Today, use the treatment-scoped `TreatmentCareController` (sessions, packages, revise plan).
- The UI must never show both for one visit.
- A backend owner should confirm that the two paths stay consistent. This is listed as a dependency, not a UI decision.

---

## 7. Cross-page interaction and navigation rules

1. A destination appears only when `ClinicNavigation` allows it **and** its route is in `ClinicFrontend::AVAILABLE`. Unbuilt destinations show "Soon", aren't focusable, and are never styled as working.
2. Every figure links to the list it counts, with the same filters, branch scope and clinic-time day. A figure with no list is not a link.
3. Patient names link to Patient 360 everywhere (once built).
4. Dialogs: New and Repeat Case, booking, payment, refund, step-up, confirmations. Full pages: Patient 360, the consultation, Checkout, Settings, Staff profile. No drawers.
5. A server refusal is shown where the action was taken, with the server's own words. Owner Mode refusals open the step-up dialog and re-send once only when the server says `executed:false`.
6. The branch context comes from the top-bar switcher. Pages never offer a branch the member cannot open.
7. Deep links work: every tab, filter and dialog-worthy state is in the URL query.
8. Live data refreshes in place (`useLiveProps`) and never resets focus or a half-typed form.

---

## 8. UI component and design-system principles

- **One language, four layouts.** Shared tokens and components; different compositions per workspace (Owner: a sheet; Reception: a board; Doctor: a focus workspace; Billing: ledger lists).
- **Tokens:**
  - **Surfaces:** canvas `#F5F8FC` (shell), ivory content surfaces for owner and reporting pages, white for working surfaces.
  - **Ink:** navy `#0B1F33`, with secondary text that meets WCAG AA (memory `feedback_text_contrast_tokens`).
  - **Accents carry meaning:** teal / cyan = money in and primary actions; amber = owed or waiting; violet = decisions and clinical; rose = alerts and safety only; blue = scheduling; mint = success.
- **Type:** Inter. Scale: 12 / 13.5 / 15 / 18 / 22 / 30 / 40 / 64 (hero figures only). Tabular numerals for money and counts. No text under 12px.
- **Structure over boxes:** use hairline rules and typographic hierarchy before cards. Never a uniform grid of equal KPI tiles.
- **Components:**
  - **Reuse:** `@/clinic/ui` primitives (Button, Field, Input, Select, Modal, DatePicker, Notice, Spinner, focus).
  - **Add:** IdentityHeader, SafetyFlags, QueueCard, StageColumn, LedgerLine, FigureLink, TickMeter, DecisionStack, Timeline7d, SectionTabs, StatusChip, EmptyState, ErrorState, SoonTag.
- **Motion:** 150–350ms; it confirms a change (a stage move, a saved state) or reveals an order. It is never decorative, and `prefers-reduced-motion` is respected.
- **Forbidden:** `@/ovs/*`, OEDL, `crmTheme.ts`; drawers; neon or dark themes; heavy glass; tiny text.

## 9. Responsive behaviour

| Width | Rules |
|---|---|
| ≥1280 | Sidebar 240px; multi-column compositions (board three columns, workspace three panes, Command Center spread) |
| 1024–1279 | Sidebar visible; second-level content wraps (board two columns plus a tab; workspace two panes) |
| 768–1023 | Drawer navigation (shell); one main column plus inline tabs; dialogs centred at 640–720px |
| <768 | Single column; dialogs become full-height sheets; tables become stacked rows; sticky primary actions |

Everywhere: no horizontal page scroll, targets at least 44px, gutters 16 / 24 / 32px. Verified at 1440, 1024, 768, 390 and 360.

## 10. Accessibility and keyboard

- WCAG 2.2 AA contrast, with focus rings visible on every control.
- Landmarks: one `h1` per page; tablists use `role=tab` and arrow keys; dialogs trap focus and return it to the trigger.
- Live regions announce queue changes, saves and refusals.
- Safety flags carry text, not colour alone.
- Charts and figures have text equivalents (`aria-label` or a visible list).
- Shortcuts: Ctrl/⌘+K search; in New and Repeat Case, Ctrl+Enter submits. On the Queue board: `N` New Case, `R` Repeat Case, `/` focuses search. Every shortcut is listed in a help dialog.

## 11. States

| State | Pattern |
|---|---|
| Loading | A skeleton in the final layout (no spinners for page loads); deferred sections pulse |
| Empty | Says what will appear here and the one action that creates it; never "No data" |
| Unavailable data | "Not sent yet" in muted italics; **never zero** |
| Error (section) | That section only: what failed, plus Retry; the rest of the page works |
| Error (action) | An inline message with the server's words; the form keeps its input |
| Validation | Per field, after blur or submit; the summary at the top links to the fields |
| Refusal (permission) | "You can't do this here", plus who can; distinct from plan, specialty and Owner Mode locks (Blueprint DoD 6) |
| Success | A confirmation where the action happened; transient toast only for background saves |
| Offline / network | Retry with the same request key; never double-submits |

## 12. Existing versus missing backend capabilities

**Existing and reused:**
- `ClinicAccess`, `ClinicNavigation`, `ClinicFrontend`, `OwnerMode`, `BranchReach`;
- `PaymentService`, `BillingRegister`, `CheckInCharges`, `EncounterCheckout`, `ClinicExpenses`, `ClinicPayroll`;
- `IntakeSubmissions`, `ReceptionCheckIn`, `ReceptionPatientLookup`, `FrontDesk`, `QueueBoard`, `QueueCorrections`, `DoctorQueue`, `DoctorRoster`;
- `DoctorToday`, `ConsultationCare`, the Dental and Physio writers, `Patient360Access` and `Patient360Sections`;
- `DeletionGovernance`, `DashboardWidgets` / `DashboardData` / `OwnerCommandCenter`, `ResourceLimitService`, `SecurityAudit`.

**Missing or partial backend (5b):**
- clinic-wide completed consultations (`DSH-02`); expenses paid out this month on the dashboard;
- a dashboard date parameter (Doctor panel week navigation);
- patient reminders and outbound notifications;
- staff availability beyond leave (`DSH-08`); clinic holidays (`ORG-11`);
- the drill-down contract for every figure (`DSH-18`); seat pools (D10);
- Daybook (D2); attendance (P4); the multi-clinic switcher (`ORG-02`);
- a repeat-visit fee rule (D28, only if the product decides one).

## 13. Testing and browser QA strategy

For every page:
1. **Server feature tests:**
   - allowed and denied, per role in §3;
   - figure = list total, in clinic time and branch scope;
   - idempotent writes; refusals audited.
2. **Pest browser or manual browser QA** with the role set (owner, doctor-owner in each mode, Admin, Reception, Billing, Doctor) on QA clinic 62 and the Owner/Doctor Mode seed clinic 71. Widths 1440, 1024, 768, 390, 360.
3. **Automated layout checks:** no horizontal overflow, no clipping or overlap, all targets at least 44px, "Soon" items not focusable. Plus a **human screenshot review** at 1440 and 390 (passing checks is not approval).
4. **Gates:**
   - the SQLite full suite matches the 15-failure baseline by name;
   - the MySQL concurrency suite passes for money paths;
   - TypeScript stays at or below 24 errors;
   - `git diff --check` is clean;
   - sidebar and top bar diffs are empty.
5. **Retiring an old page** follows the replacement map's rule:
   - its replacement is live;
   - no imports and no render names remain;
   - no test references it;
   - browser QA has passed.

## 14. Phased implementation roadmap

| Phase | Scope | Depends on | Exit |
|---|---|---|---|
| **0. Decide and approve** | Approve this blueprint, the visual language (pick or revise Concept C), and the representative screens (§16); resolve D28 and D29; confirm D1, D18, D20 | — | Written decisions in the register |
| **1. Patient journey spine** | Queue / Front Desk board, Reception home, New and Repeat Case rebuild (same backend), Patient 360 plus Edit | 0 | A patient goes from New Case to the queue to an open record in the browser for each role |
| **2. Doctor** | Doctor home and Today, the consultation workspace, the Dental panel, the Physio panel (§6.4 rule), Orders | 1 | A doctor finalizes a Dental and a Physio visit end to end |
| **3. Money** | Checkout, Billing home, receipts and WhatsApp receipt, the Owner Command Center rebuild; adjustment key (D27) | 2, D4, D27 | Signed visit → bill → payment → receipt; owner figures drill to lists |
| **4. Care continuity** | Follow-ups board, Treatments board, WhatsApp page; reminders only if the 5b backend is approved | 2 | Follow-up created in a visit appears due, books, completes |
| **5. Administration** | Staff, seats, invitations, documents; Leave (D7); Payroll (D3, D26); Branches, departments, rooms (D8); Settings index, catalogue, consents, specialties; Approvals inbox (D1); Reports (D20) | 0 decisions | Each admin task passes the role matrix with T1/T2 where specified |
| **6. Retire and harden** | Remove replaced pages per the retirement rule; full regression; accessibility audit | 1–5 | The replacement map is complete; no `Unavailable` route is reachable |

## 15. Unresolved decisions and dependencies

| ID | Decision | Blocks |
|---|---|---|
| D1 | Approvals exposure before the aggregated inbox | Approvals page (Phase 5) |
| D3 / D26 | Payroll maker-checker; payroll reopen | Payroll UI |
| D4 / D5 | Discount, write-off and refund thresholds; does Billing keep `discount.apply` | Checkout discount control |
| D6 / D7 | Block changing your own access; the leave approver is not the requester | Staff, Leave |
| D8 / D9 | T1 for branch create and delete; T1 or T2 for direct governed actions | Branches, Approvals |
| D10 / D18 / D19 | Seat pools; source of commercial plans; downgrade and over-limit behaviour | Seats UI, plan notices |
| D13 / D21 | Multi-clinic views; co-owners | Not in V1 |
| D20 | Billing role access to Reports | Reports |
| D24 / D25 | Legacy `payments` reconciliation; settings inheritance | Reports, Settings |
| D27 | Adjustment idempotency key | Checkout adjustments |
| **D28 (new)** | Is a repeat visit charged differently from a first visit (for example a free review within N days)? No rule exists today: every check-in charges the practitioner's `consultation_fee` | New and Repeat Case fee display |
| **D29 (new)** | New and Repeat Case as one dialog (recommended) or a focused page | Phase 1 intake rebuild |
| Dependency | Confirm consistency between the two physio write paths (`PhysioController` and `TreatmentCareController`) | Phase 2 Physio panel |
| Dependency | Patient reminders backend (5b) | Reminders UI |
| Dependency | Dashboard date parameter | Doctor home week navigation |

## 16. Traceability

| Requirement | Existing implementation | Planned work |
|---|---|---|
| Owner sees money that matches the lists | `OwnerCommandCenter::money`, `BillingRegister`; test "every money figure is the total of the Billing list it opens" | Phase 3 visual rebuild (§4.1) |
| Non-doctor owner has no Owner Mode prompt; doctor-owner defaults to Doctor Mode | `ClinicAccess::resolveWorkspaces`, `OwnerMode`; `OwnerDoctorModeTest` | Keep; test every new page under both modes |
| New Case never registers twice | `IntakeSubmissions::once`, the registrar lock, the duplicate gate; Reception and Patients tests | Phase 1 dialog rebuild, same endpoints |
| No one is charged an unseen amount | `CheckInCharges::chargeVisit` `verify/expected`; Reception tests | Phase 1: show the fee and handle a fee-change refusal |
| Physio never charged at check-in | `charges_at_check_in=false`, `moduleChargesAtCheckIn` | Phase 1: hide the fee for Physio visits |
| Package-covered visit not charged | `CheckInCharges::packageCovers` | Phase 1: show "covered by package" |
| Queue corrections refused when stale | `QueueCorrections` | Phase 1: inline refusal |
| Doctor consults and finalizes | `ConsultationController` (12 routes); Consultations tests 86 | Phase 2 workspace |
| Dental chart and plans | `DentalController`, `DentalSpecialtyController` | Phase 2 Dental panel |
| Physio plan, sessions, packages | `PhysioController`, `TreatmentCareController`; Treatments tests 70 | Phase 2 Physio panel (§6.4) |
| Signed visit becomes a bill | `CheckoutController@show/store`, `EncounterCheckout` | Phase 3 Checkout |
| Sensitive money actions step up | `OwnerMode::requireForSensitiveAction`: refund and adjust STANDARD; payroll finalise and pay FRESH; expense decide and pay STANDARD with a freshness check; Billing and Business tests | Keep; each page's step-up comes from the server's refusal, never from client logic |
| Role-shaped patient record | `Patient360Access`, `Patient360Sections` | Phase 1 Patient 360 |
| Branch isolation | `BranchReach`, `PatientBranchAccess`, `TenantScope`; Tenancy tests 24 | Every list and search |

## 17. Representative design-review package (identified, not built)

Minimum screens to validate the whole experience, in build order, with what each depends on:

| # | Screen | Depends on |
|---|---|---|
| 1 | Reception home and Queue board (desktop, tablet, phone) | §4.2, D29 |
| 2 | New Case dialog: default, duplicate found, fee changed, success with payment | §5.2, D28 |
| 3 | Repeat Case dialog: search, ambiguous match, already queued, success | §5.3 |
| 4 | Patient search and Patient 360 (Reception view and Doctor view) | §4.5–4.6 |
| 5 | Doctor home and consultation workspace (general) | §4.3, §4.7 (Doctor Dashboard mockup frozen as reference) |
| 6 | Dental: tooth chart and treatment plan in the workspace | §6 |
| 7 | Physio: assessment, plan of care, session with a package | §6, §6.4 |
| 8 | Checkout and Billing home | §4.4, §4.8, D4 |
| 9 | Owner Command Center (final direction) | §4.1; Concept C is a candidate |

These will live in `docs/design-review/clinic-portal-v3/` when approved to start. Production pages stay untouched until each design is approved.
