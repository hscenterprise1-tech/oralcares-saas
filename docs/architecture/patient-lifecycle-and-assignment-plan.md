# Patient lifecycle, Active/Inactive cleanup and doctor assignment — proposed plan

Status: **proposal only. Not approved. Nothing implemented.**
Date: 2026-10-24. This builds on `patients-directory-status-doctor-audit.md`.
Product decision recorded by the owner: **Active and Inactive must not remain as patient lifecycle statuses anywhere in the application.**

No production code, schema, data or tests were changed. The database was read with aggregate `SELECT`s only.

---

## 1. Repository-wide inventory — Active / Inactive

Classes:
- **BL** = business logic
- **UI** = presentation
- **DATA** = storage, defaults or fixtures
- **TEST** = tests only
- **COMPAT** = must stay for compatibility

| # | Location | Reference | Class | What it does | Cleanup action |
|---|---|---|---|---|---|
| 1 | `app/PatientStatus.php` | `case Active = 'active'` | DATA/COMPAT | The stored value of every normal record (column default) | **Keep the value `active`** as the storage of "Current". Relabel to *Current*; never render it as a badge |
| 2 | `app/PatientStatus.php` | `case Inactive = 'inactive'` | DATA | Declared, never assigned by the app | Remove **after** the data migration (§6). Removing it first would crash every read of an `inactive` row (enum cast `ValueError`) |
| 3 | `app/PatientStatus.php` | `working()` = active, inactive, deceased | BL | The default directory and search scope ("not archived/merged") | Rename to `current()`, returning `active` (+ `deceased`, per §3 decision L1). Keep `working()` as a deprecated alias for one release |
| 4 | `database/migrations/2026_07_19_000034_…:24` | `status` `default('active')` | DATA/COMPAT | Every new patient starts `active` | **Keep.** Column and default unchanged |
| 5 | `database/factories/PatientFactory.php:40` | `'status' => PatientStatus::Active` | TEST/DATA | Default factory state | Keep |
| 6 | `database/factories/PatientFactory.php:59` | `inactive()` state | TEST | Unused by any test | Remove with #2 |
| 7 | `app/Services/ClinicalDomainValidationService.php:10-18` | active ⇄ inactive ⇄ archived transitions | BL (dead) | Referenced nowhere in `app/` | Delete (needs approval; see #8) |
| 8 | `tests/Feature/ClinicalDomainTest.php:137-138` | asserts `active→inactive` allowed | TEST | Tests only the dead service | Delete or replace with the new transition tests. **Deleting a test needs your approval** |
| 9 | `app/Services/Reports/ClinicReports.php:149` | `where('status','active')` in "Not seen for 6 months" | BL (report) | Silently excludes `inactive` *and* `deceased`. Also ignores the report's branch filter | Use `PatientStatus::current()` minus deceased. Apply the branch filter. Add a test |
| 10 | `app/Http/Controllers/Clinic/PatientController.php:92, 126` | status filter validation + `options.statuses` = **all** cases | BL+UI | The Directory dropdown offers Active, Inactive, Deceased, Archived, Merged | Accept only `archived` (+ `deceased`, per L1). Options = *Current patients* (default), *Archived* |
| 11 | `app/Http/Controllers/Clinic/PatientDirectoryController.php:30` | same validation (JSON) | BL | — | Same as #10 |
| 12 | `PatientController:221`, `Patient360Summary:63`, `PatientDirectory:74`, `PatientDuplicateDetectionService:139`, `ReceptionPatientLookup:147` | `status?->value ?? 'active'` | COMPAT | Fallback for null status | Keep (null → current). Covered by tests |
| 13 | `app/Shared/Search/SearchProviders/PatientSearchProvider.php:36` | `'status' => $model->status ?? 'active'` in the search index metadata | COMPAT/UI | Stored in the shared search index; **passes an enum object**, not `->value` | Fix to `?->value`. Reindex after the data migration |
| 14 | `PatientClinicalEntryController:39`, `ReceptionController:426`, `PatientPolicy:89`, `TreatmentAccess:75`, `MergePatient:67`, `PatientMergeService:84`, `PatientSearch:47`, `ReceptionPatientLookup:82`, `PatientDirectory:102` | `PatientStatus::Active` as fallback + `working()` | BL | The gate "record is usable" | Switch to `current()`. Behaviour identical once no `inactive` rows remain |
| 15 | `resources/js/pages/ClinicPortal/Patients/components/directory/PatientList.tsx:89` | badge when `status !== 'active'` | UI | Already hides an Active badge | Badge only for `deceased` / `archived` / `merged` |
| 16 | `resources/js/clinic/ui/visuals.ts:233` | `statusTone('active')` → green | UI (shared) | Shared by many non-patient statuses | Keep (generic). Patient code must stop passing patient `active` to it |
| 17 | `resources/js/pages/ClinicPortal/Patients/components/types.ts:10,22,40,52`; `registration/types.ts:67,138,175` | `status: string` | UI types | Untyped | Narrow to `'active' \| 'deceased' \| 'archived' \| 'merged'`. The UI never labels `active` |
| 18 | `docs/design-review/patients-directory/directory-mockup.html` (+ qa copies) | Active/Inactive badges, "Working" filter | Docs | Review artifact | Update the mockup when the design is next touched (not in this phase) |
| 19 | `docs/architecture/patients-*.md` | describe the old statuses | Docs | — | Update after approval |
| — | `PatientSpecialty::INACTIVE`, `ClinicSpecialty::INACTIVE`, employee, branch, membership, clinic `Inactive` enums | — | **Out of scope** | Different concepts (specialty enrolment, staff, tenancy) | **Do not touch** |

**Not found:** no API routes outside `web.php` and `ai.php` expose patient status; no integration writes it; no seeder sets `inactive` or `deceased`; no dashboard counts patients by status. Only the report in #9 does.

---

## 2. Deceased, Archived and Merged — dependencies to preserve or fix separately

| State | Current dependencies | Preserve | Gap to fix (separately) |
|---|---|---|---|
| **Archived** | `ArchivePatient` governed action (sets status, `archived_at/by/reason`). `PatientPolicy::update` refuses it. Edit redirects read-only. Check-in 422. Clinical entries 403. Treatment refused. Excluded from directory default, desk lookup and search | Everything listed | (A1) **Booking, consultation start and billing do not check status** (no guard in `AppointmentBookingService`, `ConsultationController`, `BillingService`), so an archived patient can be booked and billed. (A2) **No restore.** (A3) Messaging continues (§5) |
| **Merged** | `PatientMergeService` (status + survivor link). Patient 360 and Edit redirect to the survivor. Excluded everywhere. Duplicate detection skips it. Directory filter accepts `merged` (tested in `PatientDirectoryApiTest:83`) | Redirects, exclusion, merge integrity tests | Stop offering `merged` as a UI filter (the API may keep it for admin/tests). Messaging should stop (§5) |
| **Deceased** | Declared only. In `working()`, so treated as current everywhere. No date, reason or writer | — | (D1) No workflow. (D2) Not blocked anywhere. (D3) Messaged like anyone else. See §3 |

---

## 3. Proposed lifecycle

States the user sees:
- **Current** — stored as `active`; no badge.
- **Deceased** — badge.
- **Archived** — separate view, badge.
- **Merged** — never listed; redirects.

### Transition matrix (proposed)

| From → To | Current | Deceased | Archived | Merged |
|---|---|---|---|---|
| **Current** | — | Governed "Record death" (L2) | Governed `ArchivePatient` (exists) | Governed `MergePatient` (exists) |
| **Deceased** | Governed "Correct record" (owner only, reason required) (L3) | — | Governed `ArchivePatient` (allowed) | Allowed only into a survivor (L4) |
| **Archived** | **New** governed `RestorePatient` | — (restore first) | — | — (restore first) |
| **Merged** | Not supported (merge is one-way, as today) | — | — | — |

### What each state permits (proposed)

| Action | Current | Deceased | Archived | Merged |
|---|---|---|---|---|
| Appear in default Directory and desk search | ✓ | ✓ with badge (L1) | ✗ (Archived view) | ✗ |
| Register a duplicate warning | ✓ match | ✓ match, shown as deceased | ✓ match, archived | ✗ (as today) |
| Book appointment | ✓ | ✗ | ✗ **(new guard)** | ✗ **(new guard)** |
| Check in | ✓ | ✗ **(new)** | ✗ (exists) | ✗ (exists) |
| Start consultation / new clinical entry | ✓ | ✗ **(new)**, except documenting the death encounter (L5) | ✗ (exists for entries; **new** for consultation start) | ✗ |
| Create bill / collect payment | ✓ | Settle existing balances only (L6) | Settle existing only (L6) **(new guard)** | ✗ |
| Edit demographics | ✓ | ✓ (correcting identity) | ✗ (exists) | ✗ |
| Patient messaging | ✓ | ✗ **(new)** | ✗ **(new)** | ✗ **(new)** |
| Archive | ✓ governed | ✓ governed | — | — |
| Restore | — | — | ✓ governed **(new)** | — |
| View Patient 360 | ✓ | ✓ | ✓ read-only | redirect |

### Decisions the product owner must make (not invented here)

- **L1:** Do deceased patients stay in the default "Current patients" list with a badge, or move to their own view?
- **L2:** Who may record a death (owner only? governed request from clinical staff?), and which evidence fields are required? Is a date of death mandatory? Is there a legal retention or notification duty?
- **L3:** Can a death record be corrected, by whom, and with what audit?
- **L4:** May a deceased record be merged?
- **L5:** Must clinical documentation continue after death (death notes, final billing)?
- **L6:** Can money still be collected from an archived or deceased patient's account (outstanding balance)?
- **L7:** On archive or death, should future appointments be cancelled automatically, or flagged for the desk?

---

## 4. Doctor assignment

### Today (verified)
- **Storage:** `patients.current_practitioner_id` → `clinic_employees.id`. It is nullable, with a plain FK index, and is clinic-wide (not per branch).
- **History:** `patient_doctor_assignments` (from, to, assigned_by, reason) plus the timeline event `doctor_transferred`.
- **Only writer:** `PatientController::transfer`.
  - Authorized by `PatientPolicy::update`: `patient.edit` + branch reach + record usable. That means Owner, Admin and Reception, not Practitioner (tested in `PatientTransferTest`).
- **Gaps:**
  - (T1) The target is only checked to be *a clinic employee*. It isn't checked to be an **active practitioner**, so a receptionist or a resigned doctor can be assigned. `ClinicEmployee::activePractitioners()` exists but isn't used.
  - (T2) No rule ties the doctor to the patient's branches. A branch-limited doctor may be assigned a patient they cannot open, which makes "My patients" show fewer patients than are assigned.
  - (T3) There is no **unassign**.
  - (T4) There is no **initial assignment** at registration or first consultation. The registration doctor is the *first visit's* doctor only.
  - (T5) There is no UI, because Patient 360 is not built.

### Proposed workflow

| Step | Who (permission) | Rule |
|---|---|---|
| Initial assignment | Same as transfer (`patient.edit`) | **Optional choice (D15):** offer "Make this the patient's doctor" at registration when a doctor is picked, *or* auto-assign at the first finalized consultation if unassigned. Never silently. Always history plus timeline |
| Change / transfer | `patient.edit` (Owner, Admin, Reception) | Target must be in `activePractitioners()` and practise at ≥1 branch where the patient has activity (T1, T2). A reason is optional, or required if the owner decides so |
| Unassign | `patient.edit` | Writes a history row with `to = null` (needs `to_practitioner_id` nullable; see §6) |
| Doctor leaves (employment inactive/resigned) | System | Do **not** auto-reassign. The Directory shows "Doctor inactive". The desk transfers |
| Unassigned patients | — | Shown as "Not assigned"; any `clinical.edit` holder may write (unchanged, `PatientPolicy:135`). The Assigned-doctor filter gains an "Not assigned" option (`whereNull`) |

### Concepts kept distinct
- **Assigned doctor** = `current_practitioner_id`.
- **First-visit doctor** = `patient_visits.doctor_id` of the first visit.
- **Last treated by** = `clinical_encounters.doctor_id` of the latest non-voided encounter in the viewer's branches.

Only the first is ever labelled "Assigned doctor".

### Authorization matrix — doctor name and assignment

| Role (template) | See assigned doctor name | Filter by doctor | Transfer / assign / unassign |
|---|---|---|---|
| Owner | ✓ | ✓ | ✓ |
| Admin | ✓ | ✓ | ✓ |
| Reception | ✓ | ✓ | ✓ |
| Practitioner | ✓ | Scope switch (My / All Accessible) instead | ✗ (tested) |
| Billing | ✓ today (Patient 360 shows it to every viewer). **Decision D19:** hide in Directory? | ✗ (product choice) | ✗ |
| Staff | as Billing | ✗ | ✗ |

### Directory query (no N+1)
- `current_practitioner_id` is **already selected** in `PatientDirectory::page()`.
- Add one batched lookup per page: `ClinicEmployee::withoutGlobalScopes()->where('clinic_id', …)->whereIn('id', $ids)->pluck('name','id')`. That's 1 query regardless of page size; extend the existing "same queries for 25 as for 2" test.
- Filter: `where current_practitioner_id = ?` (exists) or `whereNull` for "Not assigned".
- At scale, add a composite index `(clinic_id, current_practitioner_id, created_at)` so the filtered cursor walk stays indexed. This is the only schema change for this part, and it is optional for v1.

---

## 5. Reminder-safety remediation

All patient messages go through **one** method: `App\Services\Communication\PatientMessaging::send()` (`:168`). They are dispatched later by `App\Jobs\DispatchDuePatientMessages`.

Sources:
- appointment booked, reminder, rescheduled, cancelled, missed;
- check-in and called-in;
- visit completed;
- prescription issued;
- report reviewed;
- treatment stage and physio session completed;
- package expiring;
- bill issued, payment recorded, balance open;
- follow-up due or today;
- the scheduled sweep `QueueScheduledPatientMessages` (dues, follow-ups, package-ending).

**None** checks patient status.

| Layer | Change | Why |
|---|---|---|
| R1 — queue time | In `send()`, skip unless the patient is messageable (status `active`; `deceased` / `archived` / `merged` → skip). Record a timeline note "message not sent: patient deceased/archived" | One choke point covers every source |
| R2 — dispatch time | In `DispatchDuePatientMessages` / `NotificationService`, re-check status before sending. If no longer messageable, mark the notification `skipped` (`NotificationService::SKIPPED` already exists) | Reminders queued *before* the death or archive must not fire |
| R3 — on transition | `ArchivePatient`, `MergePatient` and the new "Record death" withdraw pending notifications for the patient (the `withdraw()` helper exists, `PatientMessaging:392`) | Clean queue, honest timeline |
| R4 — sweep | `QueueScheduledPatientMessages` filters candidates with `whereHas('patient', status = active)` | Avoids queue-then-skip noise |
| Tests | One test per source family proving a deceased / archived / merged patient gets nothing, and a test that a reminder queued earlier is `skipped` at dispatch | — |

R1 + R2 need **no schema change and no product decision**, so they are the **safest first phase**.

---

## 6. Migrations and data cleanup

**Current data (dev):** active 38 · inactive 1 · deceased 1 · archived 0 · merged 0. The inactive and deceased rows were created in the same second as the first seeded patients (2026-09-25 05:04:35), so they look like fixtures. **Production counts are unknown. Run the count in each environment before migrating.**

| Migration | Purpose | Reversible? |
|---|---|---|
| M1 — data | `inactive` → `active`. Before converting, copy the affected ids into an audit record (a `security_audit` entry, or a small `patient_status_migrations` table) so they can be restored | Yes, via the recorded ids |
| M2 — code | Remove `PatientStatus::Inactive` **after** M1 has run everywhere. Make the enum cast tolerant during rollout (an unknown value is logged and treated as current) so a straggler row can't take a page down | Yes (code) |
| M3 — optional | Nullable `to_practitioner_id` in `patient_doctor_assignments` (for unassign). Add composite index `(clinic_id, current_practitioner_id, created_at)` | Yes |
| M4 — only if L2 approved | `deceased_at` (date), `deceased_recorded_by`, `deceased_reason` on `patients` | Yes |

- **Kept:** `patients.status` and its `active` default stay. Archived, merged and deceased depend on them, and dropping the column is not safe.
- **Deceased:** no data conversion for the existing deceased row; it is left as is.

---

## 7. Tests and compatibility risks

**New or updated tests** (feature tests, Pest; existing tests are not deleted without approval):

- **Registration:** a new patient is stored `active` and shown without a badge.
- **Visibility:**
  - The default list excludes archived and merged.
  - The status filter accepts only `archived` (+ `deceased` per L1).
  - `inactive` is refused (422) after M2.
- **Archive / restore:** archive → read-only; restore → current again with an audit trail; the restore fingerprint fails if the record changed.
- **Appointments:** booking refused for archived / merged (+ deceased).
- **Consultations:** start refused for archived / merged (+ deceased per L5).
- **Billing:** new charges refused for archived / merged; settlement per L6.
- **Reminders:** R1–R4, as in §5.
- **Doctor assignment:**
  - transfer to a non-practitioner or inactive employee → 422;
  - transfer to a doctor outside the patient's branches → 422;
  - unassign writes history;
  - directory returns `assigned_doctor` at constant query count.
- **Report:** "Not seen for 6 months" counts current, non-deceased patients and respects the branch filter.
- **Search index:** metadata status is a string.

**Compatibility risks:**
- An enum case removed before the data migration crashes reads.
- An old cached search index holds enum objects.
- Bookmarked `?status=inactive` URLs: refuse with a clear message, or ignore.
- `ClinicalDomainTest` depends on the dead service.
- New booking and billing guards change today's behaviour for archived patients (intended, but desk staff need to be told).

---

## 8. Patients Directory (v1, on the approved pass-5 design)

- **Rows:**
  - Patient (avatar, name, ID, age, sex, badge only for deceased/archived);
  - Mobile (masked);
  - Registered;
  - **Last activity** (label unchanged).
- **No Assigned-doctor column in v1.** Coverage is too thin until D15 lands. Revisit after initial assignment exists.
- **Filters:**
  - *Status*: **Current patients** (default) and **Archived**; plus *Deceased* only if L1 says it gets its own view.
  - *Registered*.
  - *Assigned doctor* with a "Not assigned" option (Owner, Admin, Reception).
  - *Branch* (more than one branch).
  - Doctor scope switch: My Patients / All Accessible.
- **Owner** (incl. a doctor-owner in Owner Mode): all authorized patients, no switch (D11).

---

## 9. Ordered phases (smallest safe first)

| Phase | Scope | Schema | Needs decision | Risk |
|---|---|---|---|---|
| **P1** | Reminder safety R1 + R2 (+ R4) for archived and merged; also deceased (blocking messages to a recorded deceased patient needs no policy) | None | — | Low |
| **P2** | Guards: booking, consultation start and new billing refused for archived / merged | None | L6 (settlement) | Low–medium (behaviour change) |
| **P3** | Active/Inactive cleanup: M1 data migration with recorded ids; `current()`; filter options; badges; report fix; search-index fix; tolerant cast; then remove `Inactive` | M1 (data) | Approve test deletion (#8) | Medium |
| **P4** | Transfer hardening T1–T3 + unassign + "Not assigned" filter + `assigned_doctor` in the directory payload | M3 (optional) | D15, D19 | Low |
| **P5** | `RestorePatient` governed action + R3 withdrawals on archive/merge | None | — | Medium |
| **P6** | Deceased workflow ("Record death", "Correct record") + guards + Directory handling | M4 | L1–L7 | Medium–high |
| **P7** | Directory production build on pass 5 (after D1–D3, D8, D11 etc.) | — | Design approval | — |

**Files inspected for this plan:**
- everything listed in `patients-directory-status-doctor-audit.md` §7;
- `app/Services/Communication/PatientMessaging.php`;
- `app/Observers/Messaging/PatientMessageObserver.php`;
- `app/Jobs/DispatchDuePatientMessages.php`;
- `app/Services/NotificationService.php`;
- `app/Services/Billing/PaymentMessages.php`;
- `app/Services/Reports/ClinicReports.php`;
- `app/Shared/Search/SearchProviders/PatientSearchProvider.php`;
- `app/Console/Commands/ReindexSearchCommand.php`;
- `app/Services/Governance/DeletionGovernance.php` and `Actions/*`;
- `app/Models/PatientDoctorAssignment.php`;
- `resources/js/clinic/ui/visuals.ts`;
- `resources/js/pages/ClinicPortal/Patients/**/types.ts`;
- `database/seeders/*`;
- `tests/Feature/ClinicalDomainTest.php`, `PatientDirectoryApiTest.php`, `PatientMergeTest.php`, `DeletionGovernanceTest.php`, `PatientTransferTest.php`.
