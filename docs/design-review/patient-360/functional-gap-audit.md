# Patient 360 — information and functional gap audit

2026-10-26. Audit only; no implementation. Sources: code (routes, controllers, services, policies, React), schema, and tests. "No data in the test clinic" is kept separate from "not implemented": the clinic-62 seed data has no consultations, diagnoses, prescriptions, treatments or documents, so several **working** features show empty states in the browser.

**Status key:**
- **Working** — persisted, retrieved, has a screen, and its actions work.
- **Backend only** — API, service and tests exist, but there is no screen in the rebuilt portal.
- **Partial** — some of it works.
- **Missing** — not implemented.
- **N/A**.

**Built screens today:**
- the Patient 360 header and Overview;
- Visits & History;
- the Edit page;
- Print summary;
- Directory, Appointments, Billing.

The consultation workspace, the Follow-ups board, Approvals and the remaining Patient 360 sections have **no screen** in the rebuilt portal (`ClinicFrontend::AVAILABLE`).

## 1. Requirement checklist

### 1.1 Identity and safety (Header, Edit)

| Requirement | Status | Screen / code | Tables | Actions | Roles | Missing |
|---|---|---|---|---|---|---|
| Name, UHID, age/DOB, sex, blood group | **Working** | Header; `Patient360Summary::identity`; Edit page | `patients` | Edit saves (`PatientController@update`, audited timeline) | View: everyone with `patient.view`. Edit: `patient.edit` | — |
| Photo | **Missing** | Initials avatar | — (no column, no storage) | — | — | Column + private storage + malware scan + consent + upload UI; tests |
| Mobile, alternate mobile, email | **Working** | Header (masked + reveal), Overview "Patient information" | `patients` | Edit | Full values only with `patient.edit` / `appointment.create` / `clinical.edit` (`PatientDirectoryAudience::canSeeContact`); otherwise last 4 digits | — |
| Address, preferred channel | **Working** | Overview "Patient information"; Edit | `patient_profiles` | Edit | Contact rule | — |
| Emergency contact | **Working** (one contact) | Same | `patient_profiles.emergency_contact_*` | Edit | Name: all; phone: contact rule | More than one contact would need a table (only if wanted) |
| Registration date and branch | **Working** | Header, Overview, Print | `patients.created_at`, `patient_branch_activity.first_source` | — | All (branch only if it is one of the member's branches) | — |
| Assigned doctor | **Working** | Header; More → Assign doctor | `patients.current_practitioner_id`, `patient_doctor_assignments`, timeline | Transfer (practitioners only, validated) | Assign: `patient.edit` | — |
| Allergies | **Partial** | Header (all roles), Print | Clinical entries (`patient_clinical_entries`), `patient_alerts` type allergy | Recorded at registration/New Case and by `PatientClinicalEntryController` (`clinical.edit`) — **no Patient 360 screen to edit** | View: all | Edit UI (Clinical Records phase) |
| Alerts | **Partial** | Header | `patient_alerts` | `PatientController@update` (`medical_alerts`, clinical edit) — not exposed on the new Edit page | View: all | Edit UI |
| Medical history / notes | **Partial** | Header "Notes" (clinical roles), Print | `patient_medical_histories` (notes, legacy flags, JSON lists), clinical entries for conditions/medications | No edit screen for notes | Clinical roles | Medical-history edit UI |
| Consents (incl. WhatsApp opt-in) | **Backend only** | `PatientConsentController` index/store/revoke; tests `PatientConsentTest`, `ConsentTypesTest` | `consent_records`, `clinic_consent_types(+versions)` | Work via API | `consent.manage` | Consents screen |

### 1.2 Visits and clinical history

| Requirement | Status | Screen / code | Tables | Missing |
|---|---|---|---|---|
| Visit history (attended vs not attended) | **Working** | Visits & History (`Patient360Sections::visits`), 6 tests | `patient_visits`, `clinic_queue_items`, `appointments` | Link to a visit/consultation page (none built) |
| Consultations (complaint, history, examination, diagnoses, signed/voided) | **Backend only** | `Patient360Sections::encounters`; Overview shows the latest one; writes in `ConsultationController` / `EncounterWorkspace` | `clinical_encounters`, `clinical_diagnoses`, `clinical_encounter_addenda` | Clinical Records screen; the **consultation workspace screen** (the writing side) is not built either |
| Diagnoses / conditions | **Partial** | Overview "Conditions and medicines" (last 12 months, clinical only) | `clinical_diagnoses`, clinical entries | Full history in Clinical Records |
| Follow-ups | **Backend only** (+ Overview read) | `PatientFollowUpController` (add / reschedule / complete / cancel, tested); Overview "Coming up"; `FollowUpBoardTest` | `patient_follow_ups` | Follow-ups screen in Patient 360; the board page is not built |
| Timeline | **Backend only** | `Patient360Sections::timeline` (per-role event filter, tested) | `patient_timeline_events` | Timeline screen |

### 1.3 Dental (only when a branch has the `odontogram` extension)

| Requirement | Status | Code | Tables | Missing |
|---|---|---|---|---|
| Tooth chart (FDI), tooth history | **Backend only** | `Patient360DentalWorkspace` (three payloads: clinical / front desk / billing), `/360/dental/teeth/{tooth}`; write `consultations/{encounter}/dental/chart` | `patient_dental_charts`, `patient_tooth_status_histories` | Dental screen (chart UI); writing needs the consultation workspace |
| Tooth-wise diagnoses and procedures | **Backend only** | Same + `patient_procedures`, `patient_treatment_teeth` | Same | Same |
| Treatment plans (accept / pause / decline, items complete / cancel) | **Backend only** | `DentalController@transitionPlan`, consultation plan endpoints; tests `DentalSpecialtyTest` | `treatment_plans`, `patient_treatments`, stages | Screen |
| Endodontic / implant / orthodontic / periodontal / lab | **Backend only** | Consultation endpoints; lab step `patients/{p}/dental/lab/{order}/{step}` | `endodontic_records`, `implant_records`, `orthodontic_*`, `periodontal_*`, `lab_orders` | Screens |
| Treatment progress | **Working** (Overview) | Overview rings/bars from `TreatmentReadModel` | `patient_treatments`, `patient_treatment_stages` | Full Treatments screen |
| Dental X-rays / photos by tooth | **Backend only** | Upload with `tooth_numbers`; listed in the dental payload | `attached_documents` | Screen |

### 1.4 Physiotherapy (only when a branch has a physio extension)

| Requirement | Status | Code | Tables | Missing |
|---|---|---|---|---|
| Assessments (initial / re-assessment, pain, goals) | **Backend only** | `Patient360PhysioWorkspace`; write `consultations/{e}/physio/assessment` | `physio_assessments` | Physio screen; writing needs the consultation workspace |
| Plan of care and exercise programme | **Backend only** | Plans and exercises endpoints | `physio_care_plans`, `physio_exercises` | Same |
| Sessions (SOAP, pain before/after) | **Backend only** | `consultations/{e}/physio/session`, `treatments/{t}/sessions*` | `physio_sessions`, `physio_session_exercises` | Same |
| Packages: completed / remaining | **Backend only** | Sell / cancel package endpoints; payload `remaining()` | `patient_session_packages`, `session_package_usages` | Same |
| Progress (pain trend, outcomes) | **Backend only** | Workspace progress series | Sessions, assessments | Same |

**Module separation:** `Patient360Access::specialtyWorkspaces` decides from the branches' extensions (`odontogram` for Dental; `physio_assessment` / `soap_notes` / `exercise_plan` / `session_packages` for Physio). A Physio-only clinic gets no Dental section and the reverse; tested in `Patient360Test` ("the dental workspace exists only where the clinic practises dentistry"). **Gap:** no matching test that a Dental-only clinic gets no Physio section. The Overview shows "Last procedure · tooth N" only when a procedure has a tooth (dental data), so it does not leak across modules.

### 1.5 Appointments and reminders

| Requirement | Status | Notes |
|---|---|---|
| Next appointment, counts | **Working** | Overview |
| Appointment history | **Working** in Visits & History (bookings not attended) and the Appointments page; **Backend only** as a Patient 360 section (`/360/appointments`) | — |
| Book / reschedule / cancel from Patient 360 | **Missing** in Patient 360 (works on the Appointments page) | A booking dialog in Patient 360, reusing the Appointments page's flow |
| Reminders | **Working**, automatic (`appointment_reminder` template via `PatientMessaging`, lifecycle-guarded) | No manual resend (D-W1) |

### 1.6 Prescriptions, reports and files

| Requirement | Status | Notes |
|---|---|---|
| Prescriptions list | **Backend only** (`/360/prescriptions`); Overview shows the latest | Screen |
| Print a prescription | **Working** (`clinic.prescriptions.print`, HTML/PDF, audited) | — |
| Write a prescription | **Backend only** (consultation endpoints) | Consultation workspace screen |
| Investigations and results | **Backend only** (`/360/investigations`, results only to clinical roles, tested) | Screen; the orders page is not built |
| Files and reports | **Partial**: Overview lists the latest 4 the member may open, with authorised preview/download (audited); upload API with malware scan exists | Files screen with upload; full list; removal through governance |

### 1.7 Billing

| Requirement | Status | Notes |
|---|---|---|
| Outstanding, totals, unpaid bills | **Working** (Overview, Print) | `patient_billings`; cancelled excluded |
| Bills, payment history, refunds | **Working** on the **Billing page**; **Backend only** in Patient 360 (`/360/billing`, `/360/bills/{bill}`, refunds only with `refund.process`, tested) | Billing & Payments section |
| Discounts | **Working** at bill creation and as adjustments (`BillingService` discount allocation, `billing/bills/{bill}/adjustments`, `discount.apply`) | Shown in the section once built |
| Receipts | **Partial**: every payment has a `receipt_number` and an automatic receipt **message** (`PatientMessaging::paymentRecorded`, `payment_receipt`) with status and retry (`PaymentMessages`, `billing/payments/{p}/messages`) | No receipt document (PDF/print) |
| Bill print | **Working** (`clinic.billing.bills.print`) | — |

### 1.8 WhatsApp

| Requirement | Status | Notes |
|---|---|---|
| Automatic messages (appointments, check-in, queue, dental/physio follow-ups, payment, receipt) | **Working** | `PatientMessaging` → `ClinicWhatsApp::deliver` → `SendClinicWhatsAppMessage`; lifecycle guard (merged / deceased); consent `agreed()`; idempotent |
| Account selection (company-managed vs clinic-owned) | **Working** | `ClinicWhatsApp::connectionFor`: SHARED common number (`clinic_id` null) or the clinic's OWNED number; the HQ Marketing number is a separate model set; never another clinic's |
| Delivery status / retry | **Working** for payment messages (Billing page); **Backend only** elsewhere | Webhook statuses (`ClinicWhatsAppWebhooks`) |
| Message history in Patient 360 | **Backend only** (`/360/communications`; it does not join WhatsApp status) | D-W7 |
| Manual messages, documents through WhatsApp | **Missing** | D-W1 to D-W4, D-W6, D-W8 (see the section plan §2) |

### 1.9 Identity safeguards

| Requirement | Status | Notes |
|---|---|---|
| Merged patients | **Working** | Page, Print and Edit redirect to the survivor; merged records read-only (policy); excluded from lists; merge via governance (tested) |
| Deceased: marking | **Missing** | No writer anywhere; the status exists but cannot be set from the app |
| Deceased: restrictions | **Partial** | Messaging blocked (P1); booking, check-in, consultation and billing **not** restricted; a badge is shown |
| Duplicate detection at registration | **Working** | `PatientDuplicateDetectionService` |

### 1.10 Isolation, audit and sensitive data

| Requirement | Status | Notes |
|---|---|---|
| Clinic isolation | **Working** | Policy plus `assertInClinic` on every endpoint; cross-clinic tests (`CrossTenantAccessTest`, page and print tests) |
| Branch isolation | **Working** | `PatientBranchAccess` (patient visibility); branch-held records filtered (`inBranches`), bill detail by branch (tested) |
| Audit | **Partial** | `patient.viewed`, `patient.summary_printed`, document upload/preview/download, governance actions, transfer timeline. **Not** logged: opening individual section endpoints (clinical data reads) |
| Clinical vs financial fields | **Working** | `Patient360SectionPolicy::fields` (results, medical history, clinical documents only to clinical roles; refunds only with `refund.process`) |

## 2. Screen → data → action mapping (built screens)

| Screen element | Endpoint / prop | Data | Action |
|---|---|---|---|
| Header facts | `patient` (`identity`) | `patients`, `patient_visits`, `patient_branch_activity` | Edit → `PUT clinic.patients.update`; Print → `GET clinic.patients.print`; Assign → `POST clinic.patients.transfer`; Check in → intake (`POST clinic.reception.check-in`) |
| Safety | `context` | Clinical entries, `patient_alerts`, `patient_medical_histories` | — |
| Summary cards | `state`, deferred `overview` | `patient_treatments`, `appointments`, `patient_follow_ups`, `clinical_encounters`, `patient_billings` | View appointments → `clinic.appointments.index` |
| Clinical activity, conditions | `overview.recent`, `context` | Encounters, prescriptions, order items, procedures, diagnoses | Print prescription |
| Treatment / appointment / financial summaries | `overview.summary`, `current` | As above | View visits & history (in-page) |
| Patient information | `profile` | `patient_profiles`, `patients` | — |
| Documents | `overview.documents` (from `Patient360Sections::documents`) | `attached_documents` | Preview / download (authorised route, audited) |
| Visits & History | `GET /360/visits` | `patient_visits`, `appointments`, `clinic_queue_items`, encounters | Open the day in Appointments |

## 3. Role and permission matrix (default role templates)

| Capability | Owner / Admin | Doctor | Reception | Billing |
|---|---|---|---|---|
| Open patient, header, allergies, alerts | ✓ | ✓ (branch reach) | ✓ (branch reach) | ✓ (branch reach) |
| Full mobile, email, address, emergency number | ✓ | ✓ (`clinical.edit`) | ✓ | ✗ (last 4 digits) |
| Medical notes, conditions, medicines, clinical activity | ✓ | ✓ | ✗ | ✗ |
| Visits & History | ✓ | ✓ (consultation named) | ✓ (consultation not named) | ✗ |
| Appointments (summary, next) | ✓ | ✓ | ✓ | ✗ |
| Billing figures | ✓ | ✓ (`billing.view`) | ✓ | ✓ |
| Refunds | ✓ | ✗ | ✗ | only with `refund.process` |
| Documents: administrative / clinical | ✓ / ✓ | ✓ / ✓ | ✓ / ✗ | ✗ / ✗ |
| Edit details, Assign doctor | ✓ | ✗ (no `patient.edit`) | ✓ | ✗ |
| Check in | ✓ (desk) | ✗ (no intake) | ✓ | ✗ |
| Print summary | ✓ (full) | ✓ (clinical) | ✓ (no clinical) | ✓ (masked, no clinical) |
| Audit section | ✓ | ✗ | ✗ | ✗ |
| Dental section | clinical payload | clinical payload | front-desk payload | billing payload |
| Physio section | ✓ | ✓ (physio clinic) | ✗ | ✗ |

The doctor-owner works in Doctor Mode unless in Owner Mode (`OwnerMode`).

## 4. Missing work (prioritised)

| P | Item | Kind |
|---|---|---|
| P0 | Clinical Records screen (encounters, diagnoses, notes, addenda) | UI on existing backend |
| P0 | **Consultation workspace screen** (start / continue consultation, write notes, diagnoses, prescriptions, orders; Dental charting and Physio sessions write here) | UI on existing backend (large) — the source of most clinical data |
| P0 | Deceased: a governed "record death" action (date, reason, audit) and restrictions on booking, check-in, consultation start and new bills | Backend + UI + tests |
| P1 | Treatments screen (plans, stages, progress, sessions) | UI |
| P1 | Prescriptions and Investigations screens | UI |
| P1 | Billing & Payments section (bills, payments, refunds, adjustments, payment messages) | UI on existing backend |
| P1 | Files & Reports screen with upload (malware-scanned) and governed removal | UI |
| P1 | Follow-ups section (add / reschedule / complete / cancel) | UI |
| P1 | Allergy / alert / medical-history editing (clinical) | UI on existing backend (`PatientClinicalEntryController`, update) |
| P1 | Consents screen (incl. WhatsApp opt-in) | UI |
| P2 | Dental screen (chart, tooth detail, plans, lab) — Dental clinics only | UI (large) |
| P2 | Physio screen (assessments, plan, exercises, sessions, packages, progress) — Physio clinics only | UI |
| P2 | Messages section with WhatsApp status and retry (D-W7) | Backend join + UI |
| P2 | Manual WhatsApp send and document sharing (D-W1 to D-W4, D-W6, D-W8) | Backend + templates + provider media upload |
| P2 | Receipt document (PDF) and visit summary document (D-W5, D-W8) | Backend |
| P3 | Patient photo | Schema + storage + UI |
| P3 | Audit of section reads (clinical data access) | Backend |
| P3 | Booking from Patient 360 | UI reuse |

## 5. Recommended phases and dependencies

1. **C1 — Clinical Records** (read): encounters list and detail. Depends on nothing new.
2. **C2 — Consultation workspace** (write): unlocks real data for C1, Prescriptions, Investigations, Dental charting and Physio sessions. Largest item; it may need its own blueprint.
3. **C3 — Prescriptions and Investigations** (read and print), after C2.
4. **C4 — Treatments** (plans, stages, sessions).
5. **C5 — Billing & Payments** (reuses the Billing page's dialogs and `PaymentMessages`).
6. **C6 — Files & Reports** (upload, list, governed removal), **Consents**, **Follow-ups**.
7. **C7 — Dental** (Dental clinics) and **Physio** (Physio clinics), each behind its extension.
8. **C8 — Messages** (history and status) then **manual WhatsApp and sharing** (D-W1…D-W8, in the order of the section plan §2).
9. **Cross-cutting, early:** the Deceased action and restrictions (P0, small); a Dental-only/Physio-only separation test; section-read audit.

## 6. Tests required to close the gaps

- **Per section:** an endpoint test for every role (allowed and refused), branch limit, another clinic refused, empty state, pagination where paged; a page test that the section is `built` only after it is added.
- **Separation:** a Physio-only clinic gets no Dental section, no tooth data and no dental Overview items; a Dental-only clinic gets no Physio section.
- **Deceased:** recording is governed and audited; booking, check-in, consultation start and new bills are refused; messages are blocked (existing); the record stays readable.
- **Consultation workspace:** write → visible in Clinical Records, Overview, Prescriptions; signing locks; voiding governed.
- **Billing section:** payments, refunds (permission), adjustments (`discount.apply`), receipts with message status; totals match the Overview.
- **Files:** upload scanned (quarantine, then cleared), clinical vs administrative visibility, download authorised and audited, removal governed.
- **WhatsApp manual send:** consent required, the clinic's own connection only, no public URL, the status reflects provider callbacks, audit entry, refused for merged / deceased.
- **Browser QA:** each new section at five widths and four roles, with **seeded clinical records** (the current test clinic has none) so filled states are checked.
