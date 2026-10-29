# Patients Directory — final blueprint (Directory only)

Status: **design review — not approved for implementation.**
Date: 2026-10-24. Scope: the Patients Directory page only. Patient 360, Registration, Edit, Dental and Physio are out of scope.
Supersedes the Directory parts of `patients-module-blueprint.md` §4A/§6A where they differ.

Approved by the user before this blueprint:
hybrid compact card-style rows · mobile number masked by default, permission-controlled · balanced density with last visit and applicable branch · branch filter only for multi-branch clinics · New Patient opens the existing registration flow.

---

## 1. Files and routes inspected

| Area | File / route |
|---|---|
| Page route | `GET /clinic/patients` → `Clinic\PatientController@index` (`clinic.patients.index`), `routes/web.php:1451` group, middleware `clinic_entitled:patients` |
| JSON pages | `GET /clinic/patients/directory` → `Clinic\PatientDirectoryController@index` (`clinic.patients.directory`), `throttle:120,1` |
| Query service | `app/Services/Patient/PatientDirectory.php` (`page()`, `query()`, `PER_PAGE = 25`) |
| Search | `App\Models\Patient::scopeMatchingSearch` (`app/Models/Patient.php:351`) |
| Branch scope | `app/Services/Patient/PatientBranchAccess.php`; `patient_branch_activity` written only by `PatientBranchActivityRecorder` via `App\Observers\PatientActivityObserver` |
| Status | `app/PatientStatus.php` (`working()` = active, inactive, deceased) |
| Policy | `app/Policies/PatientPolicy.php` (`viewAny` → `patient.view`; `create`) |
| Permissions | `app/ClinicPermission.php` (cases + `defaultRolePermissions`) |
| Page availability | `app/Support/ClinicFrontend.php` `AVAILABLE` — has `clinic.patients.index`, `clinic.patients.create`; **not** `clinic.patients.show` / `edit` |
| Shared branch context | `app/Http/Middleware/HandleInertiaRequests.php` (`accessible_branches`, `accessible_branch_count`, `has_all_branches_access`, `active_branch`), `resources/js/clinic/types.ts` `ClinicContextProps` |
| Current page | `resources/js/pages/ClinicPortal/Patients/Index.tsx`, `components/directory/{DirectoryToolbar,PatientList}.tsx`, `components/{types,format,design,PatientIdentity}` |
| New Patient | `Index.tsx:104–120` + `resources/js/clinic/shell/IntakeContext.tsx` (desk popup) / `clinic.patients.create` (full page) |
| Tests | `tests/Feature/Clinic/Patients/PatientDirectoryApiTest.php`, `PatientDirectoryPageTest.php`, `PatientDirectoryTabsTest.php`, `PatientDirectoryAndRegistrationTest.php`, `PatientWordPrefixSearchTest.php` |

## 2. Verified data contract

`PatientDirectory::page()` returns `{ data[], next_cursor, prev_cursor, per_page }`. No totals, by design (no `COUNT(*)`). Order: `created_at desc, id desc` — the only order supported.

Page filters accepted by `PatientController@index`: `q`, `status`, `specialty`, `branch_id`, `active_care`, `tab` (mine|all), `cursor`.
The JSON endpoint additionally accepts `practitioner_id`, `registered_from`, `registered_to`, `per_page` (≤100).

### Field inventory

Every row field comes from `PatientDirectory::page()`. "Who sees" = anyone allowed `viewAny` (`patient.view`), filtered to their branches; there is **no per-field permission** in the directory today.

| Proposed field | Returned? | Source → key | Nullable / unavailable | Who sees today | Transformation / masking |
|---|---|---|---|---|---|
| Name | Yes | `Patient::full_name` → `name` | Never empty in practice | `patient.view` | None. Initials for the avatar derived client-side |
| Patient ID | Yes | `patients.uhid` → `uhid` | Nullable (legacy rows may lack one) | `patient.view` | Monospace chip; show "No ID" when null |
| Mobile | Yes, **in full** | `patients.phone` → `phone` | Nullable | **Everyone with `patient.view`** (all six role templates) | Mask in UI: country code + last 4. **Not security** — the full number is in the Inertia props/JSON (gap D1) |
| Age · gender | Yes | `age`, `gender` | Both nullable | `patient.view` | "34 · M"; omit the missing part |
| Registration date | Yes | `created_at` → `registered_on` (Y-m-d) | Nullable type, always set in practice | `patient.view` | Format "3 Mar 2025" |
| Last visit | **No** | — | — | — | Not available. See next row and gap D2 |
| Last activity | Yes | `patient_branch_activity.last_seen_at` (latest among the member's branches) → `last_activity_at` | Null when no activity was recorded | `patient.view` | Label **"Last activity"**, not "Last visit": it moves on registration, booking, check-in, consultation and billing (`PatientActivityObserver`, `$at = now()` at creation) |
| Branch | Yes | branch of that latest activity → `last_branch` | Null with no activity | `patient.view` | Show only in multi-branch context, under Last activity. It is *where last active*, not the registration branch (no such field exists) |
| Status | Yes | `patients.status` → `status` | Defaults to `active` | `patient.view` | Chip only when not Active (Inactive, Deceased, Archived, Merged) |
| Email | Yes | `email` | Nullable | `patient.view` | **Not shown** (density) |
| Specialties | Yes | `patient_specialties` → `specialties[]` | Empty list | `patient.view` | **Not shown** — no Dental/Physio UI in this task |
| Active care | Yes | `TreatmentReadModel::activeCareFor` → `active_care[]` | Empty list | `patient.view` | **Not shown** (out of scope; see D5) |
| Critical alerts | Yes | `patient_alerts` (critical/high count) → `critical_alerts` | 0 | `patient.view` | **Not shown** (see D6) |

### Branch context (verified)

- The patient record is clinic-wide. A **branch-limited** member sees only patients with activity at one of their branches (`PatientBranchAccess::restrict`). A `branch_id` filter can only narrow it, never widen it (tested: `PatientDirectoryApiTest` "cannot widen it with a filter").
- Owner, primary owner and `has_all_branches_access` members are unlimited.
- The frontend knows the member's branches from shared props: `accessible_branch_count` and the first `SHARED_BRANCH_LIMIT` `accessible_branches`.
- **Rule used:** show the branch filter and the branch line only when `accessible_branch_count > 1`. The current page also hides the filter when the shared list is truncated (`accessible_branches.length < accessible_branch_count`); the redesign keeps that guard until a searchable branch picker exists (D4).
- A multi-branch clinic member limited to one branch therefore sees **no** branch filter — correct, they have nothing to choose.

### Permissions (verified role templates)

| | Owner | Admin | Reception | Practitioner | Billing | Staff |
|---|---|---|---|---|---|---|
| Open the Directory (`patient.view`) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Sees full mobile in data | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| "My / All patients" switch | if practitioner | if practitioner | — | ✓ (defaults to My) | — | — |
| + New Patient (`PatientPolicy::create`) | ✓ | ✓ | ✓ | — | — | — |
| Open a patient | only once `clinic.patients.show` is in `ClinicFrontend::AVAILABLE` (today: **no** — `links.profile` is null) |
| Edit / archive from the Directory | not supported (Edit page not available; archive goes through `DeletionGovernance` from the record) |

## 3. Information architecture (Directory only)

One page, `/clinic/patients`, inside the unchanged Clinic shell.

**A. Header** — title **Patients**; description "Find anyone registered at this clinic by name, patient ID or mobile number." (Practitioner on *My*: "Patients assigned to you."). One primary action **+ New Patient**. No KPI tiles, no money.

**B. Search** — one large field: "Name, patient ID or mobile number". Server-backed through the existing `q` (debounced Inertia visit, as today). Behaviour stated honestly under the field:
- digits → start of mobile, alternate mobile, or patient ID;
- words → start of first/last name (later words also middle name), or start of patient ID.
States: spinner in the field while loading; results replace the list; no-results and error states (§F).

**C. Filters** — one row, never more than four controls:
1. *My / All patients* segmented switch — practitioners only (verified, `tab`).
2. *Status* — Working (default: active, inactive, deceased) · Active · Inactive · Deceased · Archived. (Merged is accepted by the server but is a duplicate record — omitted from the menu; D7.)
3. *Registered* — date range. **Supported by the service and JSON endpoint, not by the page controller** (D3). Shown in the mockup as proposed.
4. *Branch* — only when `accessible_branch_count > 1`; "All my branches" default.
Active filters appear as removable chips with "Clear all". Specialty and Active-care filters exist in the backend but are **not shown** (out of scope).

**D. Hybrid rows** — a single white list surface; each row is a compact, softly rounded band (12px radius, hairline border on hover, no heavy shadow). Desktop grid:
`[avatar + name / ID chip · age·gender] [mobile, masked] [registered] [last activity + branch] [status · open ›]`.
Identity is the only 15px/600 text; everything else is 13px secondary. Row height ≈ 64px. Whole row is the link when the record page exists; otherwise the row is static (no hover lift, no chevron).
Mobile (<768): stacked card-row — name + status on line 1, ID chip + age·gender on line 2, a two-column meta line (mobile · last activity/branch) on line 3. No horizontal scroll.

**E. Actions** — New Patient: front desk with the intake available and `can_register` → opens the existing New Case popup (as today); otherwise → `clinic.patients.create`; neither → no button. Open patient → `clinic.patients.show` only when available. No edit, archive, restore, merge or delete.

**F. States** — initial loading (skeleton rows), populated, first-time empty (with New Patient when allowed), search no-results (clear search / clear filters / register), error with Retry, Archived filter (rows muted, "Archived" chip, still openable when the record page exists), single- vs multi-branch, narrow layout.

**Pagination** — cursor: "← Newer" (prev_cursor) / "Older →" (next_cursor), "Showing 25 at a time, newest registrations first". No totals, no page numbers.

## 4. Gaps and unresolved decisions

| # | Gap / decision | Why it matters | Options |
|---|---|---|---|
| D1 | **No permission controls mobile numbers.** The full number goes to every `patient.view` member in props and JSON. | Masking only in the UI is not protection. | (a) new `patient.contact.view` permission + server-side masking in `PatientDirectory` (backend + migration-free seed of role templates); (b) accept UI masking with a "Show" reveal for desk roles and audit the reveal; (c) leave as is. **Needs your decision.** |
| D2 | **No true "last visit".** Only `last_activity_at` exists, and booking or billing moves it. | "Last visit" would be wrong for a patient who only booked. | (a) label "Last activity" (mockup default); (b) add one batched `max(visit_date)` from `patient_visits` per page (a new query — needs approval). |
| D3 | Registration-date filter not accepted by `PatientController@index`. | Filter would be ignored on the page. | Pass `registered_from/to` through the page controller (small change, tested), or drop the filter. |
| D4 | Branch filter hidden when the member has more than `SHARED_BRANCH_LIMIT` branches. | Large chains lose the filter. | Searchable picker on `clinic.branches.index` later. |
| D5 | Active care, specialties and critical alerts are returned but not shown. | Wasted payload; or lost safety signal. | Keep backend as is for now; revisit with Patient 360. |
| D6 | Critical-alert marker omitted. | A clinician may want it in the list. | Add a small marker for clinical roles later — your call. |
| D7 | `Merged` in the status filter. | Leads to duplicate shells. | Omit from the menu (proposed). |
| D8 | Rows cannot open a patient today (`clinic.patients.show` not available). | The Directory is a dead end until Patient 360 ships. | Ship Directory with static rows, or build alongside Patient 360. |
| D9 | No registration branch on `patients`. | The branch shown is "last active at". | Accept; label accordingly. |
| D10 | Digits-only search matches the **start** of the patient ID; typing just the serial ("00142") will not match "LSS-2025-00142". | Desk staff may expect suffix search. | Hint copy in the field; no backend change. |

## 5. Mockup

Role-aware visibility and filters are in §7 below.

- Standalone file: `docs/design-review/patients-directory/directory-mockup.html` (fictional data, no network calls, not imported or routed by the app).
- Review controls: role, branch context (single / multi / multi but limited to one), record page built or not, state.
- Screenshots: `docs/design-review/patients-directory/qa/`.

## 6. Implementation outline (after approval only)

1. Decide D1–D3, D8.
2. Rebuild `Index.tsx`, `DirectoryToolbar.tsx`, `PatientList.tsx` on the Clinic kit only (no `@/ovs`, OEDL or `crmTheme`).
3. Keep `PatientDirectory`, routes and policies unchanged unless D1/D2/D3 are approved.
4. Extend `PatientDirectoryPageTest` for any approved contract change; run the five directory test files.

---

## 7. Role-aware visibility and filters (added 2026-10-24, pass 3)

Verified against `app/ClinicPermission.php` (`defaultRolePermissions`), `app/Policies/PatientPolicy.php`, `app/Services/Patient/PatientBranchAccess.php`, `app/Services/Patient/PatientDirectory.php`, `Clinic\PatientController@index`, `ClinicEmployee::activePractitioners`, `App\Models\PatientDoctorAssignment` and the directory tests (`PatientDirectoryApiTest`, `PatientDirectoryPageTest`, `PatientDirectoryTabsTest`).

### 7.1 How patient visibility actually works

Three layers. Each one can only narrow the one before it:

1. **Tenant.** Every directory query leads with `patients.clinic_id` = the active clinic. Another clinic's patients never appear (tested).
2. **Branch reach.** `PatientBranchAccess::branchIds()` returns `null` (unlimited) for the primary owner, any member with `has_all_branches_access`, or no membership context. Otherwise it returns the member's *accessible active branches*. A limited member sees a patient only if `patient_branch_activity` has a row for that patient at one of those branches.
   - **It is not the active branch.** The branch picked in the top bar does not narrow the Directory. An owner is never limited to "the current branch".
3. **Permission.** `PatientPolicy::viewAny` and `view` need `patient.view`, and `view` also checks branch reach. **No role gets a narrower patient set than its branch reach.** A doctor can open any patient seen at their branches, not only their assigned patients.

**Role templates are defaults.** Clinics can edit roles (`roles.manage`), so the Directory must read permissions, never role names.

### 7.2 Role-wise patient visibility matrix

| Role (template) | Directory (`patient.view`) | Default scope | Scope switch | Branch reach | New Patient (`patient.create`) |
|---|---|---|---|---|---|
| **Owner** (primary) | ✓ | All patients of the clinic, all branches | — (see the Owner who is also a doctor note below) | Unlimited | ✓ |
| **Admin** | ✓ | All patients in their branch reach | — | Unlimited only with `has_all_branches_access`; otherwise their assigned branches | ✓ |
| **Reception** | ✓ | All patients in their branch reach | — | As assigned | ✓ (desk popup when New Case is available, otherwise the registration page) |
| **Practitioner (doctor / physio)** | ✓ | **My patients** = `patients.current_practitioner_id` = the doctor's own `clinic_employees.id` | **My patients / All accessible.** "All accessible" = every patient in the doctor's branch reach, which `PatientPolicy::view` already lets them open | As assigned | ✗ |
| **Billing** | ✓ | All patients in their branch reach | — | As assigned | ✗ |
| **Staff** | ✓ (`patient.view` only) | All patients in their branch reach | — | As assigned | ✗ |
| Company users | ✗ (`isClinicUser` fails) | — | — | — | — |

**Owner who is also a doctor.** `PatientController@index` treats *anyone* with a practitioner profile as a practitioner, so a doctor-owner defaults to **My patients** whatever their Owner Mode. Under the binding Owner/Doctor Mode semantics:

- Doctor Mode → My patients (as today).
- Owner Mode → All patients by default.

This needs a small controller change. **Decision D11.**

**What Billing and Staff receive.** The Directory shows no clinical information to any role. But the payload sent to every role, Billing and Staff included, carries `critical_alerts` (a count of critical or high alerts) and `active_care` (open treatment names), even though no row displays them. **Gap D12:** strip these two keys for roles without `clinical.view` (or drop them, since the redesign does not use them).

### 7.3 Filter availability matrix

Answers to the five questions, per candidate:

- **Supported today:** is it in the page controller (*page*) and/or the JSON endpoint and service (*service*)?
- **Backed by:** the field, relationship or query.
- **Roles:** who gets the filter in the redesign.
- **Backend change:** what would be needed.
- **Initial:** does it ship in the first build?

| Filter | Supported today | Backed by | Roles | Backend change | Initial |
|---|---|---|---|---|---|
| **Search** (name, patient ID, mobile) | page ✓ service ✓ | `Patient::scopeMatchingSearch`: digits → start of `phone`, `alternate_phone`, `uhid`; words → start of first/last name (later words also middle name) or start of `uhid` | All | None | **Yes** |
| **Status** | page ✓ service ✓ | `patients.status`. Default "Working" = active, inactive, deceased (`PatientStatus::working()`). Also active, inactive, deceased, archived. Merged is accepted but hidden (D7) | All | None | **Yes** |
| **Registered** (date range) | page ✗ service ✓ | `patients.created_at` via `registered_from` / `registered_to` | All | Pass both keys through `PatientController@index` (D3) | **Yes** (marked *proposed* in the mockup) |
| **Doctor — assigned** | page ✗ (only implicitly via `tab=mine`) service ✓ | `patients.current_practitioner_id` via `practitioner_id`. History in `patient_doctor_assignments` | Owner, Admin, Reception. Doctors use the scope switch instead | Accept `practitioner_id` on the page and pass the options from `ClinicEmployee::activePractitioners()` (clinic-wide; filtering to the member's branches is optional polish) | **Yes** (marked *proposed*) |
| Doctor — treating (last encounter) | ✗ | `clinical_encounters.doctor_id` / `patient_visits.doctor_id` would need an EXISTS subquery and an index review | — | New query | No |
| Doctor — who registered | ✗ | No such column on `patients` | — | Schema change | No |
| **Branch** | page ✓ service ✓ | `patient_branch_activity.branch_id` EXISTS. **Narrows only**: an unreachable branch id becomes 0 (tested) | Anyone with `accessible_branch_count > 1` and the full branch list shared (D4) | None | **Yes** |
| **My patients / All accessible** | page ✓ (`tab`) | `current_practitioner_id` = the current employee | Practitioners only (plus doctor-owners in Doctor Mode, D11) | None | **Yes** |
| In active care | page ✓ service ✓ | Open `patient_treatments` | — | None | No (treatment-specific; out of scope by prior decision) |
| Specialty | page ✓ service ✓ | `patient_specialties` | — | None | No (excluded by prior decision) |
| Last-activity range | ✗ | `patient_branch_activity.last_seen_at` | — | New filter | No (activity includes bookings and billing; low value) |
| **Clear filters** | page ✓ | Resets status, registered, doctor and branch. **Keeps** `tab` and branch reach | All | None | **Yes** |

**The doctor filter in practice:**

- **Owner and Admin** use it for clinic-wide lookups ("Dr. Rao's patients").
- **Reception** uses it at the desk ("whose patient is this?", rebooking with the same doctor).
- **Billing** does not get it. No billing workflow needs it, and it would add noise. The permission would allow it, so this is a product choice.
- **Doctors** get the scope switch instead. Filtering by a colleague inside "All accessible" is possible later, but not initially.
- **The filter means the assigned doctor only.** It is never "registered by" or "last treated by", and the label says "Assigned doctor".

### 7.4 Clearing and pagination rules

- Clearing filters never changes the scope switch or branch reach. An owner returns to *all branches*; a doctor stays inside their own reach.
- Removing one filter leaves the others untouched.
- Any filter or search change starts again from the first cursor page (cursors are only valid for one query). The order is always newest registration first. There are no totals; Older is offered only when `next_cursor` is present.
- **D13 (new).** The page controller currently drops `registered_*` and `practitioner_id` from its links. When D3 and the doctor filter are approved, its `filters` echo must include them so pagination keeps them.

### 7.5 Search and filter design (pass 3)

- **Search:** first and largest. A 56px field with a blue search icon and a scope line under it naming exactly what is searched ("Name · Patient ID · Mobile — from the start"). A clear button appears once something is typed.
- **Filters, desktop:** one aligned row beneath search. Doctors see the scope switch first, then Status · Registered · Doctor (where allowed) · Branch (where allowed). Every control is 44px, with an icon tile, the label, the value and the arrow inside one shape.
  - An applied filter fills with its own tint and carries its own ✕. That control *is* the chip, so nothing is duplicated.
  - "Clear filters" appears only when something is applied.
- **Result line:** under the panel, one honest sentence about scope ("All 3 branches", "Bandra only", "Assigned to you"). There are no counts, because the server never totals.
- **Tablet (768):** the same controls in an even grid inside the hero.
- **Phone (≤767):** search stays visible, and the doctor's scope switch stays visible because it is scope, not a filter.
  - The other filters sit behind a **Filters** button carrying an applied-count badge. It opens inline (no drawer) into a two-column grid with **Clear** and **Done**. Filters apply as they change, and Done only closes.
  - While closed, applied filters show as removable chips. They are the only place those values are shown, so there is no duplication.
- **Colour:**
  - Teal: Status and primary actions.
  - Blue: search and Registered.
  - Mint: Doctor.
  - Violet: Branch and scope.
  - Applied filters take their hue's soft tint with a darker label. Inactive ones stay white.

### 7.6 Decisions added in pass 3

| # | Decision |
|---|---|
| D11 | Doctor-owner default scope follows Owner/Doctor Mode (needs a controller change) |
| D12 | Stop sending `critical_alerts` / `active_care` to roles without clinical access |
| D13 | Echo the new filters in the page's `filters` so pagination keeps them |
| D14 | Doctor filter options: clinic-wide active practitioners (today) or only those at the member's branches |
