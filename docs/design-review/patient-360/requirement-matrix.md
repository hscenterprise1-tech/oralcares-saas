# Patient 360 — requirement-to-implementation matrix

Status 2026-10-27, from the code and tests (not from earlier reports). Statuses: **1** implemented and verified · **2** implemented, not fully verified · **3** partial · **4** missing · **5** blocked (external).

## 1. Requirements

| # | Requirement | St. | UI | Route / service | Authorisation | Tenant / branch | Evidence |
|---|---|---|---|---|---|---|---|
| 1 | Header: identity, UHID, masked contact, doctor, registration, last visit; Start/Continue consultation | 1 | `PatientHeader.tsx` | `Patient360Controller@show` | `PatientPolicy::view`; contact rule; consultation actions via `ClinicalEncounterPolicy` | `assertInClinic`, `PatientBranchAccess` | `Patient360PageTest`, `Patient360HeaderOverviewTest` |
| 2 | Medical conditions (distinct), View all, Manage | 1 | `SafetyStrip.tsx` | `PUT patients/{p}/clinical-entries/condition` | `writeClinicalData` | policy | `Patient360SafetyStripTest` |
| 3 | Condition catalogue enforcement; custom only via Settings | 1 | Settings → Patient form switch; Lists → Medical history | `PatientClinicalEntries::prepare` | `settings.manage` (list), `clinical.edit` (patient) | per clinic | `ClinicalSafetyIntegrityTest` |
| 4 | Allergy review before signing, every path; allergies in the consultation | 1 | Consultation → Prescriptions (review step), "Before you prescribe" | `POST prescriptions/{rx}/allergy-review`, `ClinicalPrescriptionService::finalize` | `PrescriptionPolicy::update` | clinic + branch | `ClinicalSafetyIntegrityTest` (allergy tests), browser run (`qa/consultation/`) |
| 5 | Safety alerts: add, resolve (kept), attribution | 1 | SafetyStrip → Manage | `PatientSafetyController` alerts | `writeClinicalData` | 404 other clinic | `ClinicalSafetyIntegrityTest` |
| 6 | Medical notes: edit with author/time, previous text kept | 1 | SafetyStrip → Edit | `PUT patients/{p}/medical-notes` | `writeClinicalData` | 404 other clinic | `ClinicalSafetyIntegrityTest` |
| 7 | Overview (no Allergies card), View all links only to openable sections | 1 | `Overview.tsx` | `Patient360Summary::overview` | per field | `inBranches` | stress QA, `Patient360HeaderOverviewTest` |
| 8 | Consultation workspace: narrative autosave (409 on conflict), diagnoses, procedures, notes, orders, prescriptions, sign, addenda/amendments | 1 | `ClinicPortal/Consultations/Workspace.tsx` (new) | `ConsultationController`, `EncounterWorkspace` | `ClinicalEncounterPolicy`, order/prescription policies | branch | `tests/Feature/Clinic/Consultations` (86), browser run |
| 9 | Sections: Visits, Clinical, Treatment, Appointments, Prescriptions, Investigations, Billing (+bill), Documents, Consents, Messages, Follow-ups, Timeline, Requests, Audit, Dental, Physio | 1 | `Sections.tsx`, `RecordSections.tsx`, `DentalSection.tsx`, `PhysioSection.tsx` | `Patient360SectionController` → `Patient360Sections` | `Patient360SectionPolicy::allows` per section | branch scopes in each query | `Patient360PageTest` (per-role lists), `Patient360Test`, sections QA |
| 10 | Documents: upload (malware scan), types by role/module, view/download (audited), governed removal | 1 | Documents section | `patients.documents.store/download`, `document.remove` governance | `documents.manage`; clinical docs to clinical roles | clinic | `Patient360Test`, `Patient360SectionLinksTest` |
| 11 | Follow-ups: add, move, done, cancel; distinct from appointments | 1 | Follow-ups section; Overview "Follow-up task" | `PatientFollowUpController` | `canManageFollowUps` | clinic | `FollowUpBoardTest`, sections QA |
| 12 | Treatments with real progress only; procedures with billed flag | 1 | Treatment section | `/360/treatment` | section policy | branch | sections QA |
| 13 | Billing: totals = register source (`patient_billings`), bills, payments, refunds, print; collect/refund on Billing page | 1 | Billing section, "Open in Billing" (register filtered by UHID) | `/360/billing`, `/360/bills/{bill}`, `CheckoutController` | `billing.view` + workspace | branch | billing suite, bill-dialog QA |
| 14 | Consents: record per purpose, withdraw with reason, history | 1 | Consents section | `PatientConsentController` | `consent.manage` | clinic | `PatientConsentTest`, `ConsentTypesTest` |
| 15 | Manual WhatsApp share (Rx, bill, receipt, report, appointment, follow-up) with preview + confirm | 1 (local) / **5** (real delivery) | Messages → Send on WhatsApp | `POST patients/{p}/messages/share` → `ManualShare` → `PatientMessaging::sendManual` | per-kind permission | clinic; record must be this patient's | `ManualShareTest` (5) |
| 16 | WhatsApp sender isolation, status history, retries | 1 | Messages section | `ClinicWhatsApp::connectionFor`, `deliver`, `retry` | settings.manage for config | `clinic_id` on own connections; shared only `clinic_id IS NULL` | `ManualShareTest` (own mode → no fallback), `PatientMessageEligibilityTest` |
| 17 | Deceased safeguards incl. legacy services | 1 | banner, menu | `PatientCareGuard` in every writer (+ 4 legacy services; 2026-10-27: + `PhysioRecord::openSession` / `startPlan`, which an open consultation could still reach) | governed | clinic + branch | `DeceasedPatientTest` (19), `PatientMessageEligibilityTest` (scheduled sweep, queued, send job), `ConsultationCareTest` "once a death is recorded…" |
| 18 | Module separation (Dental vs Physio) on the server | 1 | sections and consultation panels appear per plan | `Patient360SectionPolicy` + `SpecialtyEntitlements`; consultation `dental`/`physio` props | entitlement | — | `Patient360ModuleSectionsTest` (4: dental-only, physio-only, both, neither), real 403 on dental-only QA clinic 62 |
| 19 | Free-text WhatsApp messages; sending files (PDF, X-ray) on WhatsApp | **5** | — | — | — | — | Meta: free text only inside a 24-hour customer window; documents need media-header templates approved per number. Not built to avoid unapproved sends. |
| 20 | Dental writing in the consultation: FDI finding + surfaces, plan + items, accept, item done with resulting condition, remove, gum charting, lab case | 1 | `Consultations/ClinicalPanels.tsx` `DentalPanel` | consultation `dental.links` → `DentalController`, `DentalSpecialtyController` | `writeExtension`; reception 403 | branch; other clinic 403 | `ConsultationClinicalPanelsTest` (2), browser E2E consultation 43 + reload + Patient 360 Dental (`qa/consultation/dental-e2e-*.png`) |
| 21 | Physio writing in the consultation: assessment + outcome measures, start treatment, plan of care + exercises, SOAP session with pain and exercises done, complete against a package, discharge | 1 | `PhysioAssessmentPanel`, `CarePanel` (`TreatmentCard`, `PlanOfCare`, `Session`, `Discharge`) | `physio.links.assessment`; `clinic.treatments.*`; discharge = `consultations.physio.plans.close` (completed, note) then `treatments.complete` | treatment.execute + clinical.edit + practised specialty | branch | `ConsultationCareTest` (8), browser E2E clinic 68: visit 1, sign, visit 2 on package (1 of 6 used), discharge, reload, Patient 360 Physio (`qa/consultation/physio-*.png`) |
| 22 | Investigations: order → collect → result (values, units, ranges, flags, report) → correct (previous kept in timeline) → review/finalise (who/when/note) → read-only; report attachment; worklist | 1 | `Orders/Show.tsx`, `Orders/Index.tsx`; consultation lines link to the order | `InvestigationController`, `ClinicalOrderService::recordResult` (correction keeps `previous`) | `clinical.view`/`clinical.edit` + order policy; reception/billing 403; result hidden from reception in Patient 360 | branch; other clinic 403 | `InvestigationResultsWorkflowTest` (3), browser E2E order 4 + reload + Patient 360 Investigations (`qa/consultation/orders-*.png`) |
| 23 | Session packages: sell (idempotent), use per session, balance | 1 | consultation `Packages` (practitioner with billing rights); Patient 360 Physio "Sell a package" (owner/admin with billing rights, active plan) | `treatments.packages.store`; `patients.session-packages.store` | `billing.create`; Doctor role refused (403) | clinic + branch | `ConsultationCareTest` (Discharge test: double submit sells once, doctor 403), browser: double-click sold one |
| 24 | Neither Dental nor Physio | 1 | no sections, no consultation panels | as row 18 | — | — | `Patient360ModuleSectionsTest` "neither" |

| 25 | Responsive layout at 1440 / 1024 / 768 / 390 / 360 | 1 | all pages above | — | — | — | 125 captures with 0 page overflow and no console errors (`qa/responsive/`, `results.json`, `panels/`). One defect fixed: `SafetyStrip` `LinkButton` minimum width 24 px. |
| 26 | Full suite / TypeScript against baseline | 1 | — | `php artisan test --compact`; `npx tsc --noEmit -p tsconfig.json` | — | — | 4,100 passed, 9 skipped, 15 failed (baseline 4,092 / 9 / 15; the same 15 by name); TypeScript 24, the same as baseline, 0 in Patient 360 files. See `final-gate-report.md` §7–8. |
| 27 | Live WhatsApp delivery | **5** | — | — | — | — | No connection in the dev DB; not verifiable here. |

Evidence for rows 1–16 was re-confirmed this round: the focused suites (`tests/Feature/Clinic/Patients`, `Consultations`, `Treatments`) gave 448 passed, 1 skipped, 0 failed, and the full suite showed no new failures. The browser runs covered Owner, Doctor (dentist and physiotherapist), Reception, Billing and Clinic Admin (all 16 built sections render). Full report: `final-gate-report.md`.

## 2. Dental vs Physio

| Capability | Dental | Physio |
|---|---|---|
| Section | FDI chart (states, legend, per-tooth detail), plans (accept/pause/resume/decline/cancel, mark item done with resulting condition), procedures (billed), imaging, lab, gum health, recalls | plan of care + exercises, sessions (SOAP, pain before/after, exercises), packages (used / left / expired), pain and outcome trends, assessments, discharges |
| Gate | `dental` product → 403 otherwise | `physiotherapy` product → 403 otherwise |
| Shared | Treatments, Prescriptions, Investigations, Billing, Documents (intraoral photo type only with Dental), Follow-ups, Timeline | same |
| Writing | consultation `DentalPanel` (row 20) | consultation `PhysioAssessmentPanel` + `CarePanel` (row 21); one session per treatment per visit (`PhysioRecord::openSession` returns the visit's existing session) |

## 3. Roles (built sections, server-checked)

| Role | Sections | Writes |
|---|---|---|
| Owner | all (Audit incl.) | everything; governed actions directly (Owner Mode for doctor-owners) |
| Clinic Admin | Overview, Visits, Clinical, Appointments, Treatment, Prescriptions, Investigations, Billing, Documents, Consents, Follow-ups, Timeline, Messages, Requests, Audit | patient edit, requests for governed actions, documents, consents |
| Doctor | Overview, Visits, Clinical, Prescriptions, Investigations, Treatment, Follow-ups, Appointments, Documents, Consents, Billing, Timeline, Messages, Requests (+ Dental/Physio by plan) | consultation, conditions, alerts, notes, prescriptions (allergy review), orders, follow-ups |
| Reception | Overview, Visits, Appointments, Follow-ups, Billing, Investigations (status only), Documents (non-clinical), Consents, Messages, Timeline, Requests | check-in, edit, assign doctor, requests, non-clinical uploads, follow-ups, messages |
| Billing Staff | Billing, Overview, Messages, Timeline, Requests | bill/receipt messages; money on the Billing page. No package sale from the clinical Physio section, which they cannot see by design. |

## 4. WhatsApp isolation

- `connectionFor`: OWN mode → only `whatsapp_connections` with `mode=owned` **and this `clinic_id`**; COMMON → only `mode=shared` with `clinic_id IS NULL`. No fallback between them (tested: own mode without its number → refused, nothing queued).
- Templates per connection (`templateFor`); credentials never sent to the browser; previews show the template text only.
- Every message: eligibility (deceased/merged) at queue, dispatch, send job and retry; consent (`agreed`); idempotency (`event_key`, `idempotency_key`); manual shares once per record per hour; audited (`patient.message_shared`, identifiers only).
- Status: queued → sent (provider accepted) / failed / skipped with reason; "sent" is never shown as delivered or read.
- External blocker: real sends need the clinic's or company's Meta number connected and each template approved.
