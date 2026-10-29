# Patient Management audit — 2026-10-28

Read-only audit. No code, data, routes or permissions were changed, and no patient or test record was created. Evidence comes from:

- **Code reading:** controllers, policies, requests, services and pages.
- **Test run:** 67 patient-related test files.
- **Browser:** the local dev server at 127.0.0.1:8000, signed in as the QA receptionist of the Combined QA clinic (id 69).

## Summary

| Priority | Count | Headline |
|---|---|---|
| P0 | 0 | No cross-clinic exposure and no unguarded write found. |
| P1 | 3 | Phone and email are masked only in the UI; booking accepts merged and other-branch patients; Physio has no front-desk view in Patient 360. |
| P2 | 6 | Search/view race, focus after validation, empty "seen lately" list, dead legacy code with failing tests, missing cross-clinic tests, unopenable section URLs sent. |

**Tests:** 531 passed, 3 failed, 1 skipped (67 files, 4,598 assertions).
- All 3 failures were already failing in the earlier full-suite baseline (`full-suite.txt`: 15 failed / 4,101 passed).
- They cover code the app no longer uses (P2-4).

## What was verified as working

| Area | Result | How |
|---|---|---|
| Directory, default view | Receptionist opens on "Today's patients" with the views Today / All. The empty state reads "Nobody checked in yet today" and offers "See all patients". | Browser |
| Directory search | Exact mobile `9000000602` → 1 row. Surname `Patient` → 3. Patient ID `OC-00000002` → 1. `zzqq` → "No patients match" with Clear filters. | Browser |
| Directory filters | Doctor and Specialty filters come from the server's `options` (2 doctors; Dental and Physio). The branch filter is hidden in a single-branch clinic. | Browser plus props |
| Duplicate check (Add patient) | Typing an existing mobile shows "Possible existing patient": the number masked as `••••0601`, plus "Check in instead" and "This is a different person". | Browser (POST `check-duplicate`, read-only) |
| Client validation | Submitting with gender, names and date of birth empty shows field errors and sends **no** request. | Browser (network checked) |
| Repeat case search | Mobile `9000000601` → 1 match showing specialty and last visit. Visit for: Dental / Physiotherapy; doctors filtered by module. | Browser (dialog closed without check-in) |
| Patient 360, receptionist | Sections: Overview, Visits, Dental, Appointments, Follow-ups, Billing, Investigations, Documents, Consents, Messages, Timeline, Requests. | Browser |
| Patient 360, server refusals | Clinical, prescriptions, audit, physio and treatment section endpoints return **403**. Visits, billing and dental return 200. | Browser `fetch` |
| Tenancy guards | Covered by test files (tests passing): `Patient` uses `ClinicScoped`; `PatientPolicy::view/update` compare the patient's clinic to the active clinic; `Patient360SectionController` checks the clinic, then the section policy; `BindsToActiveClinic` returns 404 on another clinic's consents; the registration request scopes `doctor_id`/`department_id` to the clinic; a prospect booking is resolved inside the clinic. | Code, plus `CrossTenantAccessTest`, `ClinicTenantIsolationTest`, `PatientRecordOwnershipTest` |
| Consents list | `ConsentService::for` runs its own `assertCanView`. | Code |
| Contracts | The Patient 360 `endpoints` keys map to real routes (`clinical`→`360/encounters`, `communication`→`360/communications`, `consents`→`consents.index`). The registration request fields match the form fields. | Code |
| Responsive | Directory and Patient 360 at 1440 / 1024 / 768 / 390 / 360: no horizontal page scroll, no element past the viewport. Add patient at 390: none either. | Browser measurement plus screenshots |

## P1 findings

### P1-1 Phone and email are masked in the UI only

> **Corrected 2026-10-28** (see `patient-contact-privacy-plan-2026-10-28.md`): this finding overstated the problem.
> - The Directory and Patient 360 already omit the full number and email on the server (`PatientDirectoryAudience::canSeeContact`). The receptionist observed below is allowed contact access.
> - The real gap is narrower: `clinic.patients.search` and global search (`clinic.search`) send the full mobile to Billing Staff.
>
> The original text is kept below for the record.

- **Where:**
  - `app/Services/Patient/PatientDirectory.php` (row shape), `PatientController@search` (`clinic.patients.search`), and the Patient 360 header.
  - On screen: `pages/ClinicPortal/Patients/components/directory/DirectoryTable.tsx`.
- **Evidence:** the receptionist's directory props carry `"phone": "9000000602"` and `email` in full for every row, while the table shows `••• 0602` with an eye toggle. This is the open decision D1 from the 2026-10-24 directory review: there is no contact permission.
- **Impact:** anyone with `patient.view` can read every patient's full mobile and email from the page data or the JSON endpoints. The masking is cosmetic, not access control.
- **Repro:** sign in as any `patient.view` role, open `/clinic/patients?view=all`, then read `history.state.page.props.patients.data[0].phone`.
- **Recommended fix:** decide D1 first. If contact details must be protected, add a contact permission. The server should send `phone_last_digits` only, and reveal the full number through an audited request.

### P1-2 Booking accepts a merged record, or a patient from a branch the member cannot access
- **Where:**
  - `app/Http/Controllers/Clinic/AppointmentController.php:270` and `:382` resolve the patient only by `clinic_id`.
  - `AppointmentBookingService::book` (`:60`) guards only `PatientCareGuard`, which blocks Deceased only.
- **Evidence:** code reading. Check-in, by contrast, refuses merged records (`ReceptionController.php:426`). Search is branch-restricted (`PatientSearch`), but booking by id is not.
- **Impact:**
  - An appointment can be attached to a merged record. It then cannot be checked in, and it never moves to the survivor.
  - A branch-limited member can book any patient in the clinic by id, bypassing the branch-privacy rule.
- **Repro (not run, because it would create a record):** POST `clinic.appointments.store` with the `patient_id` of a `merged` patient, or of a patient seen only at another branch, as a branch-limited user.
- **Recommended fix:** resolve the patient through `PatientBranchAccess`, and refuse statuses outside `PatientStatus::working()`. Merged could also be added to `PatientCareGuard::assertCanReceiveCare`. Add a feature test for each case.
- **Status:** unverified at runtime; based on code reading.

### P1-3 Patient 360 specialty tabs follow the clinic, not the patient, and Physio has no front-desk view
- **Where:**
  - `app/Services/Patient/Patient360/Patient360Access.php:76` (`specialtyWorkspaces`): Dental and Physio come from the **branch extensions**.
  - `Patient360SectionPolicy.php:60` and `:270–272`: the front-desk role gets `dental_operational` / `dental_financial`, but no Physio counterpart.
- **Evidence:** in the Combined clinic the receptionist opened patient 344, a Physiotherapy-only patient.
  - Sections include **Dental**, and there is no Physio section.
  - `360/physio` returns 403, while `360/dental` returns 200.
- **Impact:**
  - The desk sees an irrelevant Dental tab on Physio patients.
  - It cannot see a Physio patient's plan, sessions or package balance from Patient 360, although it books and checks in those sessions.
- **Repro:** sign in as `intake-combined-reception@browser-test.test` and open `/clinic/patients/344`.
- **Recommended fix:**
  - Gate the Dental and Physio tabs on the patient's own specialties (`PatientSpecialties`).
  - Add a read-only `physio_operational` view (active plan, sessions used and left, package) for the front desk, mirroring `dental_operational`.
- **Needs a product decision:** what exactly the desk may see for Physio.

## P2 findings

### P2-1 A search that is still pending overwrites a view switch
- **Where:** `pages/ClinicPortal/Patients/Index.tsx:125–132`. The debounced `search` captures `filters` at keystroke time, then calls `change({ q })`.
- **Evidence (browser):** I typed `Pat`, then clicked **Today** within 350 ms. The final URL was `?q=Pat&view=all`, with "All patients" still selected. The Today visit also carried the previous query (`q=zzqq`).
- **Impact:** the view the user chose is silently undone.
- **Fix:** read the latest filters from a ref when the timer fires. Also cancel the pending timer in `change`.

### P2-2 Focus stays put after a failed submit
- **Where:** `pages/ClinicPortal/Patients/Create.tsx` (submit handler).
- **Evidence (browser):** after "Register patient" with errors, focus stayed on `pr-phone`, which was valid. The first error was Gender.
- **Impact:** keyboard and screen-reader users aren't taken to the problem.
- **Fix:** focus the first field with an error, or a summary with `role="alert"`.

### P2-3 Repeat case shows an empty "Seen at Main Branch lately" list
- **Where:** `pages/Clinic/Reception/intake/PatientSearchStep.tsx:146`.
- **Evidence (browser):** with no recent patients, the heading shows above an empty list with no message.
- **Fix:** hide the heading when the list is empty, or show "Nobody seen here recently — search above."

### P2-4 Dead legacy code kept alive by failing tests
- **Where:**
  - `app/Services/PatientRegistrationService.php`: organisation-level and referenced by no app code.
  - `tests/Feature/PatientRegistrationTest.php` ("registers a patient with uhid and timeline event"): fails with `NOT NULL constraint failed: patients.clinic_id`.
  - `tests/Feature/TenantProvisioningTest.php` (2 tests): `App\Services\TenantProvisioningService` does not exist.
- **Impact:** the suite never runs green, which hides new failures.
- **Fix (needs approval, because deleting tests requires it):** remove the dead service and its tests, or rewrite the tests against `Patient\PatientRegistrar`.

### P2-5 Missing cross-clinic tests on several patient endpoints
- **Evidence:** the guards exist in code, but no test sends another clinic's patient to:
  - `clinic.patients.360.section`
  - `clinic.patients.directory` (JSON)
  - `clinic.patients.edit` / `update`
  - `clinic.patients.print`
  - `clinic.patients.consents.index`
  - `clinic.patients.360.dental.tooth`, which has **no tests at all**.
- **Fix:** add one dataset-driven test that asserts 404 or 403 for each route with another clinic's patient. Add a test for the tooth endpoint covering clinical vs front-desk access.

### P2-6 Patient 360 sends URLs for sections the member cannot open
- **Where:** `Patient360Controller.php:113–134`. The `endpoints` map includes `clinical`, `prescriptions`, `audit`, `physio` and `treatment` for the receptionist.
- **Evidence:** the server refuses all five with 403, so nothing leaks. The payload is simply larger than needed and reveals which routes exist.
- **Fix:** send an endpoint only when its section is in `sections`.

## Not verified

- **Doctor, owner and billing roles:** not opened in the browser. They are covered by passing tests only (`Patient360RoleVisibilityTest`, `PatientDirectoryViewsTest`, `PatientAccessPermissionTest`).
- **Dental-only (70) and Physio-only (68) clinics:** not opened in the browser in this audit. Only the Combined clinic (69) was checked.
- **A branch-limited member:** no multi-branch QA clinic exists, so branch privacy was checked in code only.
- **A real registration, check-in, edit save or server-side 422:** none was submitted (no records may be created).
- **Pagination beyond one page:** the QA clinic has 3 patients.
- **Loading skeletons and a real server or network failure** on the Directory and Patient 360.
- **The Edit page**, and Add patient at widths other than 390 px.
- **P1-2** was found by code reading and not reproduced.

## Recommended order once approved

1. P1-2: a server guard and tests. It is small and safety-relevant.
2. P1-1: decision D1 first, then the server-side change.
3. P1-3: product decision on the Physio desk view, then the fix.
4. P2-1, P2-2, P2-3: frontend only.
5. P2-5: tests.
6. P2-4: needs approval to delete tests.
7. P2-6: payload cleanup.
