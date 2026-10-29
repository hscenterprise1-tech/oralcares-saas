# Patient 360 — section plan and standing requirements

Updated 2026-10-26. Patient 360 is built one section at a time; each section ships with its tests and five-width browser QA, then stops for review.

**Permanent constraints:**
- No patient archive.
- No Active/Inactive patient UI or filters.
- Duplicate merging and clinical history are preserved.
- No section or action is linked before its screen exists (`ClinicFrontend::PATIENT_360_SECTIONS` and `AVAILABLE`).

## 1. Section order and status

| # | Phase | Status |
|---|---|---|
| 1 | Page shell + **Overview** (reference layout, Phase B data and actions: Edit, Print, Assign doctor) | **Built 2026-10-26** |
| 2 | **Visits & History** (visits from `patient_visits`, plus bookings never attended) | **Built 2026-10-26** |
| X | **Deceased**: a governed "record death" action and restrictions on booking, check-in, consultation and bills | Next (P0, small) |
| C1 | Clinical Records (consultations, diagnoses, notes) | — |
| C2 | **Consultation workspace** (the writing side; Dental charting and Physio sessions write here) | — (large; its own blueprint) |
| C3 | Prescriptions → Investigations | — |
| C4 | Treatments | — |
| C5 | Billing & Payments | — |
| C6 | Files & Reports → Consents → Follow-ups (and allergy/alert editing) | — |
| C7 | Dental (Dental clinics) → Physiotherapy (Physio clinics) | — |
| C8 | Messages (WhatsApp status) → manual WhatsApp and sharing (§2) → Requests → Audit | — |

The full requirement-by-requirement audit, role matrix and tests are in `docs/design-review/patient-360/functional-gap-audit.md` (2026-10-26). It supersedes the earlier order above (Timeline / Appointments first).

## 1a. Reference implementation (2026-10-26): Phase A gap audit

The reference design (Clinical Workspace) is the **target**: header, actions, safety, summary cards, patient information, treatment, appointment and financial summaries, and documents. Status: **E** = exists and is reused, **B** = built in Phase B, **C** = Phase C (its section), **G** = a gap that needs a decision or a new capability.

| Reference element | Data source / action | Status |
|---|---|---|
| Avatar / photograph | No patient photo column, upload or storage. Initials avatar | **G** (a patient photo needs storage, malware scan and consent; Phase C with Files) |
| Name, UHID, age, sex, blood group | `patients` | E |
| Mobile / email (masked) | `patients` plus the contact rule (`PatientDirectoryAudience::canSeeContact`) | E |
| Assigned doctor | `current_practitioner_id` | E |
| Registered on / at branch | `created_at`; `patient_branch_activity.first_source = registration` | **B** (the registration branch is new in the payload) |
| Last visit / branch | `patient_visits` | E |
| Allergies / alerts | clinical entries, `patient_alerts` | E |
| Medical notes | `patient_medical_histories.notes`, clinical roles only | **B** |
| Check In | Reception intake (Repeat Case) | E |
| Edit | `PatientController@edit/update` exist; the screen was missing | **B** (Edit page: demographics, contact, address, emergency contact. Allergy/alert editing stays with the Clinical Records section, C) |
| Print | No printable patient summary | **B** (audited print view; same role rules as the page) |
| More → Assign doctor | `PatientController@transfer` exists; there was no screen | **B** (dialog, practitioners only) |
| More → Upload document / Messages / Merge | Endpoints exist | C (with Files & Reports, Messages, governance) |
| Section navigation | `Patient360SectionPolicy` + `ClinicFrontend::PATIENT_360_SECTIONS` | E (Overview, Visits & History) |
| Care Under Way (progress ring) | `TreatmentReadModel::forPatient` | E |
| Coming Up | Next appointment, next follow-up | E (+ a "View appointments" link to the built Appointments page) |
| Recent Activity | Latest consultation, prescription, result and procedure | E |
| Billing card | `patient_billings.outstanding_balance` | E |
| Patient information & emergency contact | `patient_profiles` (address, city, state, pincode, preferred channel, emergency contact) | **B** (in the payload; the emergency number follows the contact rule) |
| Treatment summary | Active care with progress | E |
| Appointment summary | Counts: upcoming, attended visits, missed bookings | **B** (new aggregate) |
| Financial summary | Total billed / paid / outstanding from `patient_billings` (cancelled excluded) | **B** (new aggregate) |
| Documents & reports | `attached_documents` via `canOpenDocument`, downloaded through the authorised route | **B** (latest four on the Overview; the full section is C) |
| WhatsApp/share | See §2 (D-W1…D-W8) | G |

**Schema changes needed for Phase B: none.** Every Phase B item reads existing tables. A patient photo is the one schema-level gap, deferred with Files & Reports.

**Adding a section:**
1. Add its key to `ClinicFrontend::PATIENT_360_SECTIONS`.
2. Build its screen under `resources/js/pages/ClinicPortal/Patients/components/patient360/`.
3. Add the endpoint tests that are missing for it.
4. Do browser QA at 1440, 1024, 768, 390 and 360.

Actions become available when their page or section is built (`Patient360Controller::ACTION_PAGES`).

## 2. Standing requirement: WhatsApp and document sharing (every section)

Patient 360 offers one consistent **WhatsApp/share** action:
- in the header, for a custom message;
- in each section that holds something shareable.

The flow is always the same:
1. Pick the item.
2. Preview the message, recipient and attachment.
3. Confirm.
4. Track the status (queued → sent → delivered/read, or failed/skipped with the reason).
5. Every send stays in the patient's message history and the audit log.

**Rules:**
- Reuse the existing pipeline; there is never a second messaging system.
- Respect role permission, patient consent and clinic/branch isolation.
- Only this patient's items are ever offered.
- No public document URLs.
- "Delivered" is shown only on provider confirmation.
- A share is offered only when its document type and WhatsApp capability exist. Otherwise it is listed below as a dependency of its phase.

### What exists today (reuse)

| Piece | Where | What it already does |
|---|---|---|
| Message entry point | `App\Services\Communication\PatientMessaging::send()` | One `notifications` row per event key (idempotent). The lifecycle guard (`PatientMessageEligibility`: merged and deceased are never messaged). Timeline `message_queued` |
| Dispatch | `DispatchDuePatientMessages` → `NotificationService::sendNotification` | Re-checks eligibility when due; `skipped` with reason, never a false "sent" |
| WhatsApp channel | `App\Services\WhatsApp\ClinicWhatsApp::deliver()` / `carries()` | Number selection by `ClinicWhatsAppSettings::mode`: **SHARED** OralCares common number (`clinic_id` null) or the clinic's **OWNED** number. Never another clinic's number, never the HQ Marketing number (separate `MarketingWhatsApp*` models). Requires an approved template per automation, the patient's mobile and `agreed()` consent |
| Consent | `ClinicWhatsApp::agreed()`, `ConsentRecord` types `whatsapp` / `communication`, `require_opt_in` setting | — |
| Send job and retry | `SendClinicWhatsAppMessage` (claims, re-checks eligibility before the provider) and `ClinicWhatsApp::retry()` (failed only, re-checks, audited) | — |
| Delivery status | `ClinicWhatsAppMessage` statuses (`queued`, `sent`, `delivered`, `read`, `failed`, `skipped`), updated by `ClinicWhatsAppWebhooks` | — |
| History | Patient 360 `communications` section endpoint (`Patient360Sections::communications`) | Reads `notifications`. It does not yet show the per-message WhatsApp status or failure reason |
| Printable documents | Prescription: `clinic.prescriptions.print` (HTML and PDF download, audited). Bill: `clinic.billing.bills.print` | — |
| Provider | `App\Contracts\WhatsAppProviderInterface` | `sendMessage` (text) and `sendTemplateMessage` (components). **No media upload** |

### Missing (dependencies, by phase)

| # | Dependency | Needed by |
|---|---|---|
| D-W1 | **User-initiated send.** Today every patient message is an automation fired by an event. A "share this" request needs: a validated, permission-checked endpoint that calls `PatientMessaging::send()` with a share event key; a preview endpoint (recipient, rendered body, consent and eligibility verdict); and an audit entry. No new pipeline | Every share action |
| D-W2 | **Who may share.** There is no communication permission. Decide whether to reuse an existing permission (e.g. the contact rule `PatientDirectoryAudience::CONTACT_PERMISSIONS`) or add one | Every share action |
| D-W3 | **Share templates.** The automations catalogue (`config/clinic_whatsapp.php`) holds only event messages (appointments, check-in, queue, dental/physio follow-ups). Share types need approved templates per connection (`prescription_shared`, `bill_shared`, `receipt_shared`, `document_shared`, `follow_up_reminder`, `clinic_message`) | Prescriptions, Billing, Documents, Follow-ups, header |
| D-W4 | **Documents on WhatsApp.** No media upload in the provider interface and no template document headers. Files are on the private disk. Needed: render or locate the file, upload it to the provider's media API (a media id, no public URL), and send it with a DOCUMENT-header template | Prescriptions, Billing, Documents (reports, X-rays) |
| D-W5 | **Receipts and statements as documents.** Corrected 2026-10-26: payments already have a `receipt_number` and an automatic receipt **message** (`payment_receipt`, with status and retry via `PaymentMessages`). What is missing is a receipt or statement **document** (print/PDF) to attach | Billing |
| D-W6 | **Custom messages.** Free text is allowed only inside the 24-hour customer-service window. Outside it, an approved generic template is required. Needed: a window check from inbound webhooks, or a `clinic_message` template | Header action |
| D-W8 | **Visit/consultation summary document.** No printable visit or encounter summary exists; one is needed before a visit can be shared | Clinical |
| D-W7 | **Status in history.** The `communications` section should join `ClinicWhatsAppMessage` (status, failure reason, delivered/read times) and offer `retry()` to authorised users | Messages section |

### Per phase
- **Overview (built):** no share action. The header WhatsApp action waits on D-W1, D-W2 and D-W6, so it is not shown yet.
- **Visits & History (built, no share action).** What could be shared:
  - **Appointment details:** templates exist (`appointment_confirmed`, `appointment_reminder`, `appointment_rescheduled`, `appointment_cancelled`, `appointment_missed`), but they only go out when the event fires. Resending one on request needs D-W1 and D-W2.
  - **Check-in confirmation with the case number:** the `checkin_confirmation` template exists, event-only; same dependencies.
  - **A visit summary document:** none exists (no visit or encounter summary PDF). A new dependency, **D-W8**, belongs with the Clinical section.
  - Nothing is shareable from this section today without D-W1 and D-W2, so no button is shown.
- **Appointments:** resend appointment details through the existing `appointment_confirmed` / `appointment_reminder` templates (D-W1, D-W2).
- **Prescriptions:** share the finalised prescription PDF (D-W1 to D-W4).
- **Billing:** bills, invoices, receipts and statements (D-W1 to D-W5).
- **Documents:** authorised reports and X-rays, following the clinical/administrative document rule in `Patient360SectionPolicy::canOpenDocument` (D-W1 to D-W4).
- **Follow-ups:** a reminder (D-W1 to D-W3).
- **Messages:** history with WhatsApp status and retry (D-W7). The custom message lives here and in the header (D-W6).
