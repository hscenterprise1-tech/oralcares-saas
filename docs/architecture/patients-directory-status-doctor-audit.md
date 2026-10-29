# Patients Directory — status, doctor and last-activity audit

Date: 2026-10-24. **Read-only investigation.** No production code, schema or data was changed. The dev database was queried read-only, as aggregate counts only.
Companion to `patients-directory-blueprint.md` (§7) and the design-review mockup (pass 5).

---

## 1. Patient status semantics

Storage: `patients.status` is a plain `string` column, `default('active')` (`database/migrations/2026_07_19_000034_create_clinical_domain_tables.php:24`). It is cast to `App\PatientStatus` (`app/Models/Patient.php:52`) and indexed as `(clinic_id, status, created_at)` (`2026_10_03_144939_…:30`).
Dev database today (counts only): active 38 · inactive 1 · deceased 1 · archived 0 · merged 0.

| Value | What the code establishes | Set by | Auto / manual | Effect on access, visibility and workflows | Genuine lifecycle state? | In the default Directory list? |
|---|---|---|---|---|---|---|
| `active` | The default for every new record | Column default; `PatientRegistrar` / `PatientRegistrationService` never set it explicitly | Automatic | Fully usable everywhere | Yes | Yes |
| `inactive` | **Unknown.** No code path assigns it. The only writer is a test factory state (`PatientFactory::inactive()`, line 59). No dormancy rule, no UI, no route | Nothing in `app/`. The one dev row has no traceable source | — | Treated exactly like `active`: it is in `PatientStatus::working()`, so check-in, booking, editing, treatment, merge and search all allow it | Declared, but **meaning undefined** | Yes (part of `working()`) |
| `deceased` | **Unknown beyond the label.** No code path assigns it. No `deceased_at`, date of death or reason field exists | Nothing in `app/` | — | **No special handling.** It is in `working()`, so a deceased patient can be checked in (`ReceptionController:426`), edited (`PatientPolicy:89`), treated (`TreatmentAccess:75`), merged, and is found by desk search (`ReceptionPatientLookup:82`). Scheduled messages do not check patient status (`QueueScheduledPatientMessages:43–68`) | Declared, but **not enforced** | Yes (part of `working()`) |
| `archived` | "Removed from the working directory by an owner-approved request; the record is kept" (`PatientStatus.php:11`) | `Governance\Actions\ArchivePatient::execute` (line 75) via `DeletionGovernance`: the owner acts directly, others send a request (`patient.delete`). Also sets `archived_at`, `archived_by`, `archive_reason` | Manual (governed) | Leaves the default list and desk search. Read-only: `PatientPolicy::update` refuses it, Edit redirects (`PatientController:206`), check-in refuses (422), treatment refuses. Still viewable in Patient 360. **No restore path** | Yes, a real lifecycle state | No (explicit "Archived" filter) |
| `merged` | "A duplicate merged into another patient; never shown as a working record" (`PatientStatus.php:14`) | `PatientMergeService` (line 120) via `MergePatient` governance (`patient.merge`) | Manual (governed) | Never listed; duplicate detection skips it; Patient 360 and Edit redirect to the surviving record | A **record-housekeeping** state, not a patient state | No, and never offered as a filter |
| **"Working"** | **Not a status.** `PatientStatus::working()` is a *group*: active + inactive + deceased, meaning "not archived or merged" | — | — | It is the server's default directory scope when no status filter is sent (`PatientDirectory::query`, line 102) | **No.** The mockup's "Working" label was my wording, not a backend value. Production labels this default **"Current patients"** (`DirectoryToolbar.tsx`, placeholder) | It *is* the default list |

**Dead code:** `App\Services\ClinicalDomainValidationService::validatePatientStatusTransition` (active ⇄ inactive, ↔ archived) is referenced nowhere. No status-transition rules are enforced.

**Answer on "Working":** it does not mean an active consultation, current treatment or an in-clinic patient. It means "not archived or merged". Showing it as a patient status would mislead staff.

---

## 2. Patient ⇄ doctor relationships

| Concept | Exists? | Storage | Set by | Multiplicity | Branch-specific? | Reliability today |
|---|---|---|---|---|---|---|
| **Assigned doctor** (responsible doctor) | **Yes** | `patients.current_practitioner_id` → `clinic_employees.id` (nullable, `2026_09_26_042211_…`); relation `Patient::currentPractitioner()` | **Only Transfer** (`PatientController::transfer`, line 679), which also writes `patient_doctor_assignments` history | One at a time (history kept) | **No, clinic-wide** | **Sparse.** Registration and consultation never set it. Dev DB: 7 of 40 patients have one |
| Primary or responsible doctor (separate concept) | **No.** "Assigned doctor" *is* the responsible doctor (`Patient.php:278` docblock; `PatientPolicy::writeClinicalData` gives that doctor exclusive clinical write) | — | — | — | — | — |
| Doctor who last treated the patient | **Derivable, not stored** | `clinical_encounters.doctor_id` (branch-scoped rows), `patient_visits.doctor_id`, `appointments.practitioner_id` | Consultation start, check-in, booking | Many over time; one per encounter | Yes, each encounter or visit carries `branch_id` | Accurate per encounter. Needs a per-page "latest encounter" query; voided encounters must be excluded |
| Doctor who registered the patient | **No.** There is no column. The doctor picked at registration is the *first visit's* doctor (`PatientController:411` → `PatientRegistrar` check-in), not saved as assigned | — | — | — | — | — |
| Doctor of the latest encounter or consultation | Same as "last treated by" | `clinical_encounters.doctor_id` | — | — | Yes | As above |

**Answers to the eight questions**

1. **What exists:** assigned doctor (stored, with assignment history), and per-encounter / per-visit / per-appointment doctors (derivable). There is no "registered by" and no separate "primary doctor".
2. **Reliable enough to show:** *Assigned doctor* is correct where set, but is set for very few patients. *Last treated by* is accurate but needs a new query.
3. **Multiple doctors:** one assigned doctor at a time. Many treating doctors over time.
4. **No assigned doctor:** `null`. Desk check-in then lets the user choose; any `clinical.edit` holder may write clinical data (`PatientPolicy:135`).
5. **Branch-specific:** assignment is **clinic-wide**. Encounters and visits are per branch.
6. **Who may see the name:** the Directory has no per-field gating, so anyone with `patient.view` would see it. Patient 360 already shows `practitioner` to every viewer (`Patient360Summary:66`). Staff names are not PHI, but *who treats whom* can be sensitive in some specialties, which is worth a product decision.
7. **Query cost:**
   - *Assigned doctor* is cheap. `current_practitioner_id` is **already selected** by the directory query (`PatientDirectory:47`), so it needs one batched `clinic_employees whereIn(ids)` per page.
   - *Last treated by* needs one batched latest-encounter-per-patient query per page: a window function or correlated max over `clinical_encounters` (index `clinic_id, branch_id, patient_id` exists). It must be limited to the member's branches and exclude voided encounters.
8. **Backend change needed:**
   - Assigned doctor: add `assigned_doctor` (id, name) to `PatientDirectory::page()` with the batched lookup above, plus a test that it costs constant queries.
   - Last treated by: a new batched query, governance filtering and a test.
   - Neither is in the response today.

---

## 3. What "Last activity" really is

`last_activity_at` / `last_branch` = the most recent `patient_branch_activity.last_seen_at` **among the member's own branches** (`PatientDirectory:54–60`). The table is written only by `PatientBranchActivityRecorder`. Dates only ever move forward.

| Event | Records activity? | Source |
|---|---|---|
| Registration | Yes ("registration") | `PatientRegistrationService:53`, used by `PatientRegistrar` |
| Check-in (a `PatientVisit` is created) | Yes ("visit") | `PatientActivityObserver::created` |
| **Booking an appointment** | Yes ("appointment"), **stamped with the booking time, not the appointment date**. A future booking or a later-cancelled booking still counts | Observer, `$at = now()` |
| Consultation started (`ClinicalEncounter` created) | Yes ("encounter") | Observer |
| **A bill created** (`PatientBilling`) | Yes ("billing") | Observer |
| Payment collected | **No** (payments are not observed) | — |
| Rescheduling, completing, updating any of the above | **No** (`created` only) | — |
| Writes that skip model events | Caught up later by `patients:backfill-foundation` | — |

**Could it mislead?** Yes, if read as "last visit":

- A patient booked today for next month shows today.
- A cancelled booking still moves the date.
- A bill raised without a visit moves it.
- The branch is "latest among *your* branches", so two staff members can see different branches for the same patient.

It is honest only when labelled **"Last activity"**.

---

## 4. Recommendation: the patient-list column (first version)

**Keep `Last activity`, with exactly that label. Do not add a doctor column in v1.**

- An *Assigned doctor* column would read "Not assigned" for most patients today, because only Transfer sets it. That is noise, and it would wrongly suggest most patients have no doctor.
- *Last treated by* would be the most useful clinical column. It needs a new batched query and a decision on voided encounters, so it is a backend change.
- The Assigned-doctor **filter** stays (Owner, Admin, Reception). It is supported by the service, and a filter does not imply every row has a value.

**Later (decision D15):** pick one.

- (a) Populate the assigned doctor automatically (for example, at a patient's first consultation, or from the registration doctor) and then show an **"Assigned doctor"** column; or
- (b) build **"Last treated by"** from the latest non-voided encounter in the member's branches and use it *instead of* Last activity.

Do not show both doctor concepts. Do not use "Primary doctor" or "Registered by", which have no data behind them.

---

## 5. Recommendation: status filter and badges

**Filter (first version)**

| Option | Sends | Why |
|---|---|---|
| **Current patients** (default) | nothing (server default = `working()`) | Real, indexed and already the production label. **Replace the mockup's "Working".** |
| **Archived** | `status=archived` | A genuine lifecycle state with its own rules (read-only, governed). Show it with an "Archived records are read-only" note |

Do **not** offer **Active / Inactive / Deceased** as filter options yet:

- **Active vs Inactive:** nothing sets Inactive and nothing defines it, so the options are not meaningfully distinct.
- **Deceased:** nothing sets it, and the backend does not treat it differently. A filter would suggest a process that does not exist.

Never offer **Merged**.

**Row badges**

- **Inactive** and **Deceased** keep a badge **if present** (the dev DB has one of each; imported data may have more). Deceased in slate. Inactive in neutral grey rather than amber, since it is not known to need action.
- **Archived** keeps the lavender badge.
- **Active:** the pass-5 mockup shows a green badge on every row, as you asked. Since about 95% of rows are Active, it carries little information. Consider showing it only when another status is possible, or keep it for colour (your call; no backend impact).

Re-add Active, Inactive and Deceased as filter options once decision D16 defines how they are set.

---

## 6. Backend gaps and risks found

| # | Finding | Risk | Suggested direction (not done) |
|---|---|---|---|
| D16 | `inactive` and `deceased` have **no writer, no definition and no enforcement** | Staff can't mark either; existing rows behave as active | Define both: who sets them, from where, and what they block. Deceased should at least block check-in, booking and messaging and record a date |
| D17 | **Scheduled messages ignore patient status** (`QueueScheduledPatientMessages:43–68`: dues, follow-ups, package-ending) | Reminders could reach families of deceased patients, or archived patients | Exclude non-`active`/`inactive` statuses at the message queue |
| D18 | `ClinicalDomainValidationService::validatePatientStatusTransition` is dead | Implies rules that don't exist | Delete or wire it into a real status service |
| D2 (existing) | No true "last visit" | "Last activity" can mislead | `max(patient_visits.visit_date)` batched per page |
| D15 (new) | The assigned doctor is set only by Transfer | Doctor column or filter coverage is thin | See §4 |
| G2 (existing) | Archive has no restore | One-way governed action | Governed restore |
| — | Doctor name visibility | Anyone with `patient.view` (Billing, Staff) would see who treats whom | Decide whether Billing/Staff see the doctor column |
| — | Mockup label "Working" | Implied a status that doesn't exist | Rename to "Current patients" when the design is next touched |

**Query performance:** the current directory stays at constant queries per page (tested). An Assigned doctor column adds **one** cheap query. "Last treated by" adds one heavier groupwise query that needs an `EXPLAIN` check at scale before shipping.

---

## 7. Files inspected

- `app/PatientStatus.php`
- `app/Models/Patient.php` (fillable, casts, `currentPractitioner`, `doctorAssignments`, `scopeAssignedTo`)
- `app/Models/PatientDoctorAssignment.php`
- `app/Policies/PatientPolicy.php`
- `app/Http/Controllers/Clinic/PatientController.php` (index, edit, store, update, transfer)
- `app/Http/Controllers/Clinic/Patient360Controller.php`
- `app/Http/Controllers/Clinic/ReceptionController.php`
- `app/Http/Controllers/Clinic/PatientClinicalEntryController.php`
- `app/Http/Controllers/Clinic/ConsultationController.php`
- `app/Http/Requests/Patient/UpdatePatientRequest.php`
- `app/Services/Patient/PatientDirectory.php`
- `app/Services/Patient/PatientRegistrar.php`
- `app/Services/Patient/PatientRegistrationService.php`
- `app/Services/Patient/PatientSearch.php`
- `app/Services/Patient/PatientMergeService.php`
- `app/Services/Patient/PatientBranchActivityRecorder.php`
- `app/Services/Reception/ReceptionCheckIn.php`
- `app/Services/Reception/ReceptionPatientLookup.php`
- `app/Services/Treatment/TreatmentAccess.php`
- `app/Services/PatientDuplicateDetectionService.php`
- `app/Services/ClinicalDomainValidationService.php`
- `app/Services/Clinical/ClinicalContextResolver.php`
- `app/Services/Governance/Actions/ArchivePatient.php`
- `app/Services/Governance/Actions/MergePatient.php`
- `app/Services/BillingService.php`
- `app/Services/Patient/Patient360/Patient360Summary.php`
- `app/Observers/PatientActivityObserver.php`
- `app/Providers/AppServiceProvider.php` (observer registration)
- `app/Console/Commands/QueueScheduledPatientMessages.php`
- `database/migrations/2026_07_19_000034_create_clinical_domain_tables.php`
- `database/migrations/2026_09_26_042211_add_current_practitioner_id_to_patients_table.php`
- `database/migrations/2026_10_03_144939_add_middle_name_and_search_indexes_to_patients.php`
- `database/factories/PatientFactory.php`
- `resources/js/pages/ClinicPortal/Patients/components/directory/DirectoryToolbar.tsx`
- `resources/js/pages/ClinicPortal/Patients/components/directory/PatientList.tsx`
- `tests/` (searched for inactive/deceased patient coverage; none exists)
- Dev database: one read-only aggregate `SELECT status, COUNT(*) … GROUP BY status`
