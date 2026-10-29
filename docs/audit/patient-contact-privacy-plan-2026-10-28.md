# Patient contact-data privacy: trace and plan — 2026-10-28

This is a read-only investigation for audit finding P1-1 (`patient-management-audit-2026-10-28.md`). No code, routes, permissions or data were changed.

## Correction to the audit

P1-1 said that every member who can view patients receives the full mobile number and email. **That is not accurate.**

A server-side rule already exists: `PatientDirectoryAudience::canSeeContact()` (`app/Services/Patient/PatientDirectoryAudience.php:46,114`).
- It returns **true** when the member holds `patient.edit`, `appointment.create` or `clinical.edit`.
- The Directory page, the Directory JSON and Patient 360 follow it. A member without contact access gets `phone: null`, `email: null` and `phone_last_digits` only.
- This is covered by `PatientDirectoryViewsTest.php:191`, which asserts `phone` and `email` are null and `phone_last_digits` is `2334`.
- The audit observed a **Receptionist**, who is allowed contact access, so the full number in the props was correct behaviour.

The real problem is narrower: **two search endpoints ignore the rule.** Details are below.

## Who may see contact details under the current rule

| Role (`ClinicRole`) | patient.view | patient.search | Contact access (`canSeeContact`) |
|---|---|---|---|
| Clinic Admin / owner | Y | Y | **Yes** (all three permissions) |
| Receptionist | Y | Y | **Yes** (patient.edit, appointment.create) |
| Doctor | Y | Y | **Yes** (clinical.edit) |
| Billing Staff | Y | Y | **No:** last 4 digits only |

There is no route for editing role permissions, so these four permission sets are fixed. **Billing Staff is the only role the rule restricts.**

## Every place patient contact data leaves the server

| # | Endpoint and code | Fields | Who can reach it | Follows `canSeeContact`? | Shown in the UI? |
|---|---|---|---|---|---|
| 1 | `clinic.patients.index` → `PatientDirectory::page` (`PatientDirectory.php:103–105`) | phone, email, phone_last_digits | patient.view | **Yes** | Masked, with an eye toggle |
| 2 | `clinic.patients.directory` (JSON) → same service (`PatientDirectoryController.php:57`) | same | patient.view | **Yes** | Same |
| 3 | `clinic.patients.show` → `Patient360Summary::identity` / `profile` (`:63–121`) | phone, email, alternate_phone, emergency contact phone, address, `contact_visible` | patient.view plus branch | **Yes** | Header and Overview |
| 4 | **`clinic.patients.search`** → `PatientController@search` (`PatientController.php:742`) | **phone, full** | patient.search (**Billing Staff included**) | **No** | `clinic/patients/PatientPicker.tsx:78,129` (Appointments booking and detail) |
| 5 | **`clinic.search`** (global search) → `ClinicSearch::patients` (`app/Support/ClinicSearch.php:137–141`) | **phone, full, inside `subtitle`** | patient.search (**Billing Staff included**) | **No** | `clinic/shell/GlobalSearch.tsx:244` displays the subtitle |
| 6 | `clinic.reception.check-in.search` → `ReceptionPatientLookup` (`:143`) | phone, full | queue.manage plus the front-desk workspace (admin and reception only) | No, but every caller has contact access today | **Not displayed.** The intake type declares it (`intake/types.ts:84`) and nothing reads it |
| 7 | `clinic.appointments.index` → `AppointmentPresenter` (`:51`); eager load at `AppointmentController.php:90` | patient phone, prospect phone | appointment.view (not Billing Staff) | No, but every caller has contact access today | Calendar `DetailDialog.tsx:77–88` |
| 8 | `clinic.follow-ups.index` → `FollowUpBoardController.php:82` | phone | `canManageFollowUps` (clinical.edit or appointment.create) | No, but this permission set is inside the contact set | Follow-up board |
| 9 | `clinic.leave.index` "affected" list → `LeaveController.php:204` | phone | Leave approvers (admin), or a member viewing their own leave | No, but every caller has contact access in practice | Leave page |
| 10 | `clinic.patients.edit` → `PatientController.php:240–242` | phone, email (form values) | `update` policy (patient.edit) | Implicitly, because patient.edit is a contact permission | Edit form; **required** |
| 11 | `clinic.patients.check-duplicate` and the registration gate → `PatientDuplicateDetectionService::present` | masked (`••••0601`); initials for other branches | patient.create | Masked by design | Duplicate card |
| 12 | Reception desk prospects → `FrontDesk.php:401` | **prospect** phone (not a registered patient) | Front desk | n/a | Booking match |

Not exposed:
- Printed bills and receipts (`bill_printable`, `bill_receipt_thermal`) print only the clinic's or branch's phone.
- `BillingRegister` uses the patient's phone only as a search filter (`:320`) and never returns it.

### What is actually wrong
- **#4 and #5:** a **Billing Staff** member gets every matching patient's full mobile from search, including the command palette, which the Directory and Patient 360 deliberately withhold from them.
- **#6:** sends a full number that no screen uses, which breaks the minimum-necessary principle.
- **#7–#9:** correct today only because of how the four roles happen to be composed, not because they apply the rule.

## Dependencies that must keep working

- **Searching by mobile:** `PatientSearch::find` matches the phone in SQL. Masking the *output* doesn't affect finding a patient by number, including for Billing Staff.
- **Duplicate detection:** uses the phone from the *request* and returns the already-masked `present()` shape. Unaffected.
- **Repeat case / check-in:** the lookup matches on the server. The intake never reads `patient.phone` from the lookup, and the duplicate path re-queries by UHID or name (`ReceptionIntakeDialog.tsx:312`).
- **Appointments booking and detail (`PatientPicker`, `DetailDialog`):** shows the phone. Every role that can open the calendar has contact access, so nothing changes for them.
- **Edit patient:** needs the real values and is behind `patient.edit`. Unchanged.
- **WhatsApp and SMS sending:** reads the number on the server (`PatientMessaging`). Never from the browser.
- **Existing tests:** `PatientDirectoryViewsTest` (Billing Staff masked), `ClinicGlobalSearchTest`, `RepeatCaseTest`, `PatientAccessPermissionTest`. None asserts a full phone in the search responses, so the change is additive.

## Proposed implementation (awaiting approval)

### Step 1 — required: close the two bypasses
1. **`PatientController@search`:**
   - Inject `PatientDirectoryAudience` and compute `canSeeContact` once.
   - Return `'phone' => $contact ? $p->phone : null` and add `'phone_last_digits'`.
2. **`ClinicSearch::patients`:** build the subtitle from `phone` when the member has contact access, otherwise from `•••• 1234`.
3. **Frontend, `clinic/patients/types.ts` and `PatientPicker.tsx`:**
   - Add `phone_last_digits` and type `phone` as `string | null`.
   - Show the last digits when `phone` is null.
   - Today's callers always have contact access, so they see no change.

### Step 2 — recommended: minimum necessary at the desk
4. **`ReceptionPatientLookup`:**
   - Return `phone` only when `canSeeContact` is true, which is every current front-desk caller, and add `phone_last_digits`.
   - Alternatively, drop `phone` from this response, since no screen reads it. The safer option keeps it gated rather than removed.

### Step 3 — optional hardening: no behaviour change for the current roles
5. Route #7–#9 through `canSeeContact`:
   - `AppointmentPresenter` (pass the flag in), `FollowUpBoardController` and `LeaveController`'s affected list.
   - Show `phone_last_digits` when contact access is denied.
   - This only changes the outcome if a future role combination appears.

### Tests to add
These would go in `ClinicGlobalSearchTest` and the patient search tests:
- **Billing Staff:**
  - `clinic.patients.search` returns `phone: null` with `phone_last_digits`;
  - the `clinic.search` subtitle has no full number;
  - searching *by* that full mobile still finds the patient.
- **Receptionist, Doctor and Clinic Admin:** the same endpoints still return the full number (regression).
- **If Step 2 is approved:** `check-in.search` shows the full number for reception, and last digits for a member without contact access.

### Not in scope
These would need separate decisions:
- **An audited "reveal" for the eye toggle.** Today it only reveals a number already in the page, for members who have contact access.
- **Changing `CONTACT_PERMISSIONS`.** For example, whether Doctors should keep full numbers through `clinical.edit`. Your proposed policy says to follow the existing authorization, so the plan keeps it as it is.

**Files touched if Steps 1 and 2 are approved:**
- `app/Http/Controllers/Clinic/PatientController.php` (search only)
- `app/Support/ClinicSearch.php`
- `app/Services/Reception/ReceptionPatientLookup.php`
- `resources/js/clinic/patients/types.ts`
- `resources/js/clinic/patients/PatientPicker.tsx`
- `resources/js/pages/Clinic/Reception/intake/types.ts`
- the two test files above

No routes, permissions, database or Reception Dashboard files are touched.
