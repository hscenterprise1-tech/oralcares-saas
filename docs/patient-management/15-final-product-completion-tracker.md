# OralCares Patient Management — Final Product Completion Tracker

**Date:** 2026-11-02
**Branch:** `wip/m0-snapshot-2026-10-22` @ `7cd2e7a` plus the uncommitted work: Antigravity Phase A/B/C, payment-first check-in, and Phase 0 (case integrity and billing separation).
**Status of this document:** the **single master tracker**. It replaces the plan in `14-final-completion-audit.md`; that file stays as the evidence log of the first audit. No code, schema, permission or UI was changed for this tracker.
**Method:** I read the current working tree and traced each workflow through its controller → service → model → frontend caller. A feature counts as COMPLETE only when the whole path exists and is reachable from the UI. Tests passing on their own prove nothing beyond themselves.

**Status keys:**
- COMPLETE: the whole path is verified.
- PARTIAL: part of the workflow is missing.
- MISSING: not implemented.
- BROKEN: implemented, but does not work.
- BLOCKED: waits on the owner or on another piece of work.

**Verification baseline (2026-11-02, after Phase 0):**

| Check | Result |
|---|---|
| `tests/Feature/Clinic` + `PatientBillingTest` | 1676 passed, 0 failed, 2 skipped |
| `npx tsc --noEmit` | 24 errors, all legacy, none in Clinic code |
| `npm run build` | passes |
| Full repo suite | Not re-run. Known baseline: 15 rotted failures (`docs/audit/test-baseline.md`). |

---

## 1. Module completion status

| Module | Status | One-line reality |
|---|---|---|
| A. Patient Directory | **COMPLETE** (1 P1 fix) | Keyset search, views, masking and isolation work; the internal Case Number is shown to staff (TR-12). |
| B. Patient Create/Edit | **PARTIAL** | Core fields, duplicate check and PIN lookup work. **Documents chosen at registration are silently dropped** (TR-03). **Dental/Physio history on Edit is discarded** (TR-04). |
| C. New Case | **COMPLETE** at the desk | Payment-first, idempotent, eligible-lead case and queue all verified. The journey breaks after check-in (see workflow 1). |
| D. Repeat Case | **PARTIAL** | Dental and Physio check-in work and no package session is used at check-in. Only the first physio treatment is offered (TR-09). The desk "session done" confirmation has no UI (TR-08). |
| E. Patient 360 | **PARTIAL** | 17 working tabs, not the approved 7 zones. No Active Cases bar and no Cases zone (TR-05, TR-06). |
| F. Dental | **PARTIAL** | Charting, plans, completion, lab, periodontal and recall work in the consultation. **Done procedures cannot be billed from the UI** (TR-02). |
| G. Physio | **PARTIAL** | Assessment (free-text location), SOAP, plans, packages, reassessment and discharge work. **Body map, ROM and MMT have no UI**; no home-exercise print (TR-10). |
| H. Billing | **PARTIAL** | Check-in charge, Pay Later, the Billing page's pay/refund/adjust, receipts, own-fees and package permissions all work. **No after-consultation billing UI** (TR-02). |
| I. Documents & prescriptions | **PARTIAL** | Patient 360 upload/download/governed removal and Rx create/review/print work. Refill and drug–allergy matching are missing (TR-13). Registration upload is broken (TR-03). |
| J. Timeline & communications | **PARTIAL** | Timeline, audit, consent and the WhatsApp company/clinic split work. Retry exists only for payment messages; follow-ups are capped at 50 (TR-16). |

## 2. End-to-end workflow verification

| # | Workflow | Status | Where it breaks (evidence) |
|---|---|---|---|
| 1 | New patient → New Case → payment → check-in → queue → consultation → treatment → billing → Patient 360 | **BROKEN** | (a) The doctor has **no queue screen**: `ConsultationController@index` renders `ClinicPortal/Today/Index`, which **does not exist**. No UI calls `consultations.start-from-queue` or `queue.call-next`. (b) The only UI entry, Patient 360 "Start consultation" (`start-for-patient` → `EncounterWorkspace::startForPatient(…, visit: null)`), opens an encounter **not linked to today's visit or queue entry**. The queue entry stays "Waiting" and the visit never completes. `EncounterCheckout::consultationBilled` cannot see a check-in charge without `patient_visit_id`, so it could charge the consultation twice once checkout exists. (c) There is no billing UI for anything charged after the consultation (TR-02). |
| 2 | Dental Repeat → follow-up → consultation → continue treatment → billing | **BROKEN** | Check-in ✓. Consultation entry has the same break as 1(a–b). Plan item completion in `ClinicalPanels::PlansList` → `DentalRecord::completeItem` ✓. Billing ✗ (TR-02). |
| 3 | Physio Repeat → package/session → today's therapist → check-in → session completion → remaining updated | **PARTIAL** | Check-in with therapist ✓; `RepeatPhysioStep` shows the remaining sessions; check-in consumes nothing ✓. Completion through the therapist's consultation (`completeLocked` → `SessionPackages::consume`) ✓, but the consultation starts unlinked (1a–b). The desk path `PhysioRecord::confirmAtDesk` (links the queue correctly) has **no UI caller**: `PhysioCareSummary` builds the URL; its consumer `PhysioCareAtCheckIn.tsx` is mounted only by the orphan `RepeatCaseForm.tsx`. Only `care.treatments[0]` is shown. |
| 4 | Profile edit → Directory → Patient 360 | **PARTIAL** | Core fields ✓ (`PatientEditTest`, `PatientUpdateDataSafetyTest`). Dental/Physio history fields on Edit are never sent (TR-04). |
| 5 | Clinical notes → doctor view → Patient 360 Clinical & Timeline | **COMPLETE** | `EncounterWorkspace` → `Patient360Sections::encounters/timeline`; role gating in `Patient360RoleVisibilityTest`. |
| 6 | Payment → receipt → billing history → Patient 360 | **COMPLETE** (existing bills) | Check-in receipt; `clinic/billing/BillDetailDialog` pay/refund/adjust; Patient 360 Billing read. Per-payment reprint is linked only right after check-in (P2, TR-17). |
| 7 | Document upload → secure access → Patient 360 Docs | **COMPLETE** in Patient 360 / **BROKEN** at registration | Patient 360: `uploadDocument` → `Security\Malware\ScannedUploadIntake`, patient-scoped download with audit ✓. Registration: TR-03. |
| 8 | Prescription create → review → print → history | **PARTIAL** | Create, allergy-list review before signing, print/PDF and history ✓. Refill ✗; drug–allergy matching ✗ (D6 approved, not built). |
| 9 | Cross-branch access | **PARTIAL** (safe) | `ClinicalEncounterPolicy::view → canOpenBranch` **blocks** other branches' notes. The approved D5 path (permission + active attendance + `patient_clinical_access_audits`) is not built; the audit table has no writer. Documents and follow-ups are not branch-filtered. |
| 10 | Cross-clinic isolation | **COMPLETE** | Every Patient 360 endpoint checks `clinic_id` (404); directory and reads lead with `clinic_id`; `Tenancy/*`, `Security/*` and Phase 0 tests. Caveat from the baseline: most tests run with the tenant scope off, so isolation relies on explicit `where clinic_id`, which every audited query has. |

## 3. Master tracker (grouped by the files each change touches)

Each item: priority · module · affected code · what to preserve · what is missing · final behaviour · dependencies · tests · effort · phase.

### Group G1: Doctor's day and the consultation entry (one change set)

**TR-01 · P0 · Consultation / Queue: the doctor's queue and visit-linked consultations**
- **Affected:**
  - `ConsultationController@index` (renders the missing `ClinicPortal/Today/Index`), `@startFromQueue`, `@startForPatient`;
  - `EncounterWorkspace::startForPatient/open`;
  - `ReceptionController@callNext`;
  - `Patient360SectionPolicy::actions` (`start_consultation`);
  - `ClinicFrontend::AVAILABLE`;
  - `ClinicWorkspaces::home` (doctor → Appointments).
- **Preserve:**
  - `EncounterWorkspace::startFromQueue` (correct linkage), `DoctorToday` service and `QueueStateMachine`;
  - the approved Calendar home and navigation (no new nav item without approval).
- **Missing:**
  - a doctor-facing list of today's queue;
  - Patient 360 "Start consultation" ignoring the patient's open queue entry today.
- **Final behaviour:**
  - A doctor sees their waiting patients and starts from the queue entry.
  - Starting from Patient 360 when the patient has an open queue entry today at this branch goes through `startFromQueue`, so visit → encounter → queue stay linked and the check-in charge is recognised.
- **Dependencies:** owner decision **OD-1** (where the doctor's queue lives).
- **Tests:**
  - start from queue links visit, encounter and queue status;
  - Patient 360 start with a queued visit links it;
  - Patient 360 start with no visit stays an ad hoc encounter;
  - consultation not charged twice after a check-in charge.
- **Effort:** medium. **Phase:** 1.

### Group G2: Billing after the consultation (one change set: Billing module + desk)

**TR-02 · P0 · Billing / Dental / Treatments: checkout page and desk collection**
- **Affected:**
  - `CheckoutController@show` → missing `ClinicPortal/Billing/Checkout`;
  - `EncounterCheckout`, `TreatmentCheckout`, `TreatmentChargeController` (`patients/{p}/treatment-charges`, no UI);
  - `reception/billing/{encounter}/collect` (no caller);
  - `ClinicPortal/Billing/Index.tsx` ("ready" count only);
  - `FrontDesk/components/SidePanels.tsx` (counts only);
  - `Patient360Sections::bill` (`checkout_url`, not rendered).
- **Preserve:**
  - payment-first check-in (`CheckInCharges`), `PaymentService` rules, `BillDetailDialog` pay/refund/adjust;
  - `consultationBilled` de-duplication;
  - target-branch finance checks;
  - own-fees scope and `package.sell`.
- **Missing:** the screen that turns a signed consultation's lines (consultation fee when charged after the visit, done procedures, treatment stage charges) into a bill and takes payment.
- **Final behaviour:**
  - From Billing "ready", the Front Desk panel or the bill dialog, staff open the checkout, see the lines, bill once, take full/part/later payment and get a receipt.
  - Dental "done" procedures and treatment charges become bill lines.
  - Agreed estimates stay separate from actual charges.
- **Dependencies:**
  - TR-01 (visit linkage), for correct de-duplication;
  - the handoff P0 to re-verify: `CheckoutController::store` target-branch `billing.create` check;
  - **OD-2** (checkout design approval).
- **Tests:**
  - signed dental consultation → bill lines → payment → receipt;
  - no double consultation charge;
  - treatment-stage charge billed once;
  - branch and permission refusals;
  - own-fees practitioner cannot open checkout.
- **Effort:** large. **Phase:** 1.

### Group G3: Patient Create/Edit data integrity (`PatientController`, form components)

**TR-03 · P0 · Create: registration documents silently dropped**
- **Affected:** `app/Http/Controllers/Clinic/PatientController.php`.
  - It imports `App\Services\ScannedUploadIntake`, a class that does not exist (the real one is `App\Services\Security\Malware\ScannedUploadIntake`).
  - `app(...)` throws, and `catch (\Throwable)` (around line 576) only logs.
  - The upload also runs after the registration transaction, with no audit.
- **Preserve:** the Patient 360 upload path (`Patient360Controller::uploadDocument`, the reference implementation); registration atomicity and idempotency.
- **Final behaviour:** a chosen document is scanned and stored, or the user is told it failed. Nothing is silently lost.
- **Tests:** registration with a document stores it (fake scanner); a rejected file is reported.
- **Effort:** small. **Phase:** 1.

**TR-04 · P1 · Create/Edit: Dental/Physio history fields**
- **Affected:**
  - `Patients/Create.tsx` (about lines 316–324): concatenates the module fields into `medical_notes` as tagged text;
  - `Patients/Edit.tsx`: renders `ModuleSpecificSection`, but its state is never sent, so the input is discarded;
  - `components/form/ModuleSpecificSection.tsx`.
- **Missing:** structured storage, or at least round-tripping on Edit.
- **Final behaviour:** what is entered is saved and shown again on Edit. No duplicate tagged text.
- **Dependencies:** **OD-3** (structured columns need a migration; the alternative is to keep notes text and parse it back).
- **Tests:** create → edit shows the values → save keeps them once.
- **Effort:** small (text) or medium (structured). **Phase:** 1.

### Group G4: Case lifecycle and Patient 360 zones (Patient360* services, `Show.tsx`, `patient360/*`)

**TR-05 · P1 · Cases: lifecycle and historical linking (D3 approved)**
- **Affected:** `PatientCaseService` (no close, pause or reopen), `patient_cases`, `patient_visits.case_id`, `DentalRecord::closeIfDone`, physio `Discharge`.
- **Preserve:**
  - `eligibleLead` (Phase 0);
  - a lead is never overwritten;
  - lead stays NOT NULL (D2 = not approved): with no eligible lead, no case is opened.
- **Missing:**
  - treatment-completion recommendation by the treating doctor, then authorised closure (dental);
  - physio closure only through reassessment/discharge, never through package completion;
  - verified-only backfill (dev DB: 36 of 38 visits unlinked).
- **Final behaviour:** cases close only through the approved steps and are audited; ambiguous history stays unlinked.
- **Dependencies:** none beyond D3. A backfill is a data write and needs explicit run approval (**OD-4**).
- **Tests:**
  - recommendation → closure;
  - package exhaustion does not close;
  - discharge proposes closure;
  - backfill links only unambiguous visits and is idempotent.
- **Effort:** medium. **Phase:** 2.

**TR-06 · P1 · Patient 360: seven zones and the Active Cases bar (D4 approved)**
- **Affected:**
  - `Patient360SectionPolicy::SECTIONS/ORDER`, `ClinicFrontend::PATIENT_360_SECTIONS`;
  - `Show.tsx`, `SectionNav.tsx`, `Sections.tsx`, `RecordSections.tsx`, `VisitsHistory.tsx`, `DentalSection.tsx`, `PhysioSection.tsx`, `Overview.tsx`;
  - `Patient360SectionController` (endpoints stay);
  - `PatientCaseService::getActiveCases` (unused today).
- **Preserve:**
  - every section endpoint and its server-side authorization and field gating;
  - own-fees Billing;
  - the module gate (`specialtyWorkspaces`);
  - header, `SafetyStrip` and governance;
  - deferred Overview.
- **Mapping:**

| Zone | Absorbs |
|---|---|
| Overview | overview |
| Clinical | clinical, dental/physio (module-aware), prescriptions, investigations |
| Cases & Plans | `patient_cases` + treatment |
| Visits | visits, appointments, follow-ups |
| Docs | documents, consents |
| Billing | billing |
| Timeline | timeline, communication, requests, audit (audit owner/admin only) |

  - The old `?section=` keys resolve to their zone.
- **Final behaviour:** the header and Active Cases bar stay fixed above exactly 7 zone tabs. Selecting a case filters Cases & Plans and Visits.
- **Dependencies:** TR-05 (case states).
- **Tests:**
  - per-role zone lists;
  - old keys redirect;
  - Active Cases bar = active cases in the member's branches;
  - Physio-only clinic shows no dental anywhere.
- **Effort:** large. **Phase:** 2.

**TR-12 · P1 · Directory: Case Number shown to staff**
- **Affected:** `components/directory/PatientTable.tsx` (`CaseType`, about lines 302–309).
- **Final behaviour:** show the case title and specialty; the Case Number stays internal.
- **Tests:** directory payload or render assertion.
- **Effort:** small. **Phase:** 2, with TR-06 (same identity language).

### Group G5: Physio completion (`ClinicalPanels.tsx`, Repeat steps, `PhysioCareSummary`)

**TR-08 · P1 · Physio: desk "session done" confirmation has no UI**
- **Affected:**
  - `ReceptionController@confirmPhysioSession` / `PhysioRecord::confirmAtDesk` (correct, idempotent, links the queue);
  - `PhysioCareSummary::confirmation` (builds the URL);
  - the only consumer is the orphan `intake/PhysioCareAtCheckIn.tsx`.
- **Final behaviour:** on the Front Desk queue row of a physio patient "with therapist", the desk confirms the session; the remaining sessions update.
- **Preserve:** no consumption at check-in; the treating therapist = the queue doctor; lead unchanged.
- **Dependencies:** **OD-5** (where the button lives: the Front Desk is approved and frozen).
- **Tests:** existing `confirmAtDesk` tests, plus a feature test for the desk payload.
- **Effort:** small. **Phase:** 3.

**TR-09 · P1 · Repeat Physio: only the first treatment is offered**
- **Affected:** `intake/RepeatCaseSteps.tsx::RepeatPhysioStep` (`care?.treatments[0]`).
- **Final behaviour:** a patient with concurrent physio treatments picks which one today's session is for, with each one's remaining sessions.
- **Tests:** a two-treatment patient shows both.
- **Effort:** small. **Phase:** 3.
- Note: the New/Repeat popups are approved and frozen, so this needs owner sign-off on the change.

**TR-10 · P1 · Physio: body map (anterior/posterior), ROM, MMT UI; home-exercise sheet**
- **Affected:** `Consultations/ClinicalPanels.tsx::PhysioAssessmentPanel` (sends a free-text "Where" only). The backend is ready: `PhysioAssessmentRequest` validates `body_chart`, `range_of_motion` and `strength`; `config/clinic_physio.body_regions`; `PhysioSection` shows trends.
- **Final behaviour:** a structured body map with side and pain type; ROM and MMT tables; reassessment comparison; a printable home-exercise sheet from `physio_exercises.is_home`.
- **Tests:** existing assessment tests already cover the payloads; add a print route test.
- **Effort:** medium. **Phase:** 3.

### Group G6: Dental presentation (`DentalSection.tsx`, consultation panels)

**TR-11 · P2 · Dental imaging viewer and estimate vs charge display**
- **Affected:** `DentalSection.tsx` (preview links only), `Patient360DentalWorkspace`.
- **Final behaviour:**
  - an in-page image viewer for X-ray and intraoral photos (no DICOM);
  - plan estimates labelled "Estimate", billed lines labelled "Charged" (after TR-02).
- **Effort:** small–medium. **Phase:** 4.

### Group G7: Prescriptions, documents, cross-branch (D5, D6 approved)

**TR-13 · P1 · Prescriptions: refill workflow and drug–allergy warning**
- **Affected:** `ClinicalPrescriptionService`, `PrescriptionAllergyCheck` (review only, no matching), `Consultations/Workspace.tsx` (`Prescriptions`, `AllergyCheck`).
- **Final behaviour:**
  - Refill: request → clinician review/approval → new version linked to the original, with history; no dispensing or payment.
  - Allergy: coded allergy entries matched against drug/class; the clinician must acknowledge; the system never auto-prescribes.
- **Dependencies:** a refill version link and allergen/drug-class data probably need a migration (**OD-6**).
- **Tests:** the refill lifecycle; matched warning requires acknowledgement before signing.
- **Effort:** large. **Phase:** 5.

**TR-14 · P1 · Cross-branch clinical notes (D5 approved)**
- **Affected:** `ClinicalEncounterPolicy::view`, `Patient360Summary::inBranches`, `Patient360Sections::encounters/documents/followUps`, `PatientClinicalAccessAudit` (model and policy exist, no writer).
- **Final behaviour:**
  - The master safety profile is visible everywhere.
  - Other-branch notes are visible only with the new cross-branch permission and an active attendance here, and every access writes an audit row.
  - Without attendance the notes stay blocked (as today).
- **Dependencies:** a new permission name and its role defaults (a permission change, which needs approval at implementation).
- **Tests:** blocked without attendance; allowed with attendance plus permission; audit row written; other-branch documents follow the same rule.
- **Effort:** medium. **Phase:** 5.

### Group G8: Communications and follow-ups

**TR-16 · P2 · Messages retry; follow-up pagination, branch scope and board**
- **Affected:**
  - `Patient360Sections::followUps` (`limit(50)`, no branch filter), `communications` (status only);
  - payment-message retry exists (`payments/{p}/messages/retry`), the general patient-message retry does not;
  - the Follow-ups board page is missing (handoff P2).
- **Effort:** medium. **Phase:** 6.

**TR-17 · P2 · Per-payment receipt reprint from history**
- **Affected:** `bills/{bill}/print?layout=receipt&payment=`, linked only right after check-in.
- **Effort:** small. **Phase:** 6.

### Group G9: Clean-up register (removals need explicit approval: **OD-7**)

| Item | Evidence | Action |
|---|---|---|
| Orphan intake components | `RepeatCaseForm.tsx`, `PhysioCareAtCheckIn.tsx` (after TR-08 reuses its logic), `CheckInPayment.tsx`, `IntakeStepper.tsx`, `PatientIdentityBanner.tsx`, `Step1IdentifyPatient.tsx`: none is imported by live code | Delete after approval |
| `PatientSearchService` | Referenced only by itself | Delete after a tests grep and approval |
| Two case-number systems | Legacy `CaseNumberGenerator` / `case_numbers` (`YYYY-000001`, still written at every check-in) vs `patient_cases` | Decide retirement (OD-8) |
| `generateCaseNumber` race | Unique-index collision → 500 | Move to `NumberingEngine` (P2) |
| Directory balance query computed but never rendered | `PatientDirectory::page` | Skip when unused (P2) |
| Raw Tailwind palette | `PatientTable.tsx`, `components/form/*`, `DentalSection.tsx`, `Overview.tsx` | Token pass in the phase that touches each file |

## 4. P0 blockers (production cannot be signed off without these)

1. **TR-01:** the doctor has no queue screen, and consultations started from Patient 360 are not linked to the visit or queue.
2. **TR-02:** no after-consultation billing UI. Clinics in the default "pay after consultation" mode cannot bill the consultation, and no clinic can bill done procedures or treatment charges from the UI.
3. **TR-03:** registration documents silently lost.

## 5. Files and architecture that must be preserved

| What | Why |
|---|---|
| `CheckInCharges::chargeVisit` + `IntakeSubmissions::once(..., atomic: true)` | Payment-first check-in, rollback and idempotency |
| `PatientCaseService::eligibleLead` / no-fallback rule | Phase 0 attribution |
| `ClinicPermission` own-fees / `package.sell`, `SessionPackages::maySell`, the role sync migration `2026_11_02_054332_*` | Phase 0 billing separation |
| `Patient360Sections::ownFees`, `TreatmentReadModel` `$ownEstimatesFor` | OPD-01 server enforcement |
| `Patient360SectionPolicy`, `Patient360Access`, every `/360/{section}` endpoint | Server-side field gating |
| `PatientDirectory` keyset + batched page facts, `PatientDirectoryAudience` | Scale and role views |
| `UhidGenerator` → `NumberingEngine` | Permanent UHID |
| `EncounterWorkspace::startFromQueue`, `DentalRecord`, `PhysioRecord::completeLocked/confirmAtDesk`, `SessionPackages::consume` | Correct clinical/financial writers; extend them, never duplicate |
| `Security\Malware\ScannedUploadIntake` | The only safe upload path |
| `ClinicFrontend::AVAILABLE` / `PATIENT_360_SECTIONS` | Link gating to built screens |
| Approved, frozen UI: New Case / Repeat Case popups, Front Desk, Clinic Login, sidebar/top bar | Owner decision; change only with sign-off |

## 6. Recommended order and phase plan

The order follows dependencies, and each phase touches one group of files.

| Phase | Items | Why here | Exit gate |
|---|---|---|---|
| **1. Close the core journey** | TR-01, TR-02, TR-03, TR-04 | Workflows 1 and 2 are broken without them; everything downstream (cases, zones, dental billing) relies on visit ↔ encounter ↔ bill linkage | Workflows 1, 2 and 7 pass as feature tests; no double consultation charge |
| **2. Cases and Patient 360 zones** | TR-05, TR-06, TR-12 | Needs linked visits (Phase 1); D3/D4 approved | 7 zones + Active Cases bar; role zone tests; backfill run only on approval |
| **3. Physio completion** | TR-08, TR-09, TR-10 | Touches the consultation panels and the Repeat step once | Workflow 3 passes end to end |
| **4. Dental presentation** | TR-11 | After checkout exists (estimate vs charged) | Dental Patient 360 render tests |
| **5. Rx, cross-branch** | TR-13, TR-14 | D5/D6 approved; needs a schema decision | Refill/allergy/cross-branch audit tests |
| **6. Communications and clean-up** | TR-16, TR-17, G9 | Low risk, last | Removals approved; suite at baseline |
| **7. Regression and handover** | — | — | Full suite = baseline (15 known), `tsc` 24 with 0 in Clinic, build, the owner's manual UI pass |

## 7. Owner decisions still required

- **OD-1:** where the doctor's queue lives: a new "Today" screen, or a queue panel on the approved Appointments home.
- **OD-2:** approval of the Checkout page design (Billing module visual language).
- **OD-3:** Dental/Physio registration history: structured fields (needs a migration) or notes text that round-trips.
- **OD-4:** permission to run the verified-only historical visit → case backfill on real data.
- **OD-5:** placement of the desk "Session done" button (the Front Desk is frozen).
- **OD-6:** schema for refill versions and coded allergens/drug classes.
- **OD-7:** approval to delete the orphan files in G9.
- **OD-8:** retire or keep the legacy `case_numbers` series (still written at every check-in).
- **OD-9 (carried over):** Reception still holds clinic-wide `billing.view` (not covered by D1/D2).

## 8. Final handover checklist

- [ ] Phases 1–6 complete with their exit gates.
- [ ] Workflows 1–10 each covered by a feature test that follows the whole path, not just one screen.
- [ ] Full suite: failures only the 15 known rotted tests, or those repaired with approval.
- [ ] `tsc`: no errors in Clinic code; `npm run build` passes.
- [ ] Concurrency tests (`phpunit.mysql.xml`) run against MySQL for check-in, payment and case numbering.
- [ ] Role templates in each environment match `ClinicRole::permissions()` (run migration `2026_11_02_054332_*`).
- [ ] No orphan components in `resources/js/pages/Clinic/Reception/intake/` (after OD-7).
- [ ] `docs/HANDOFF.md` updated with the final state, the decisions log (D1–D6, OD-1–OD-9) and the QA logins.
- [ ] The owner's manual UI pass at 1440 / 1024 / 768 / 390 for: Directory, Create/Edit, New/Repeat Case, Patient 360 zones, Consultation (Dental, Physio), Checkout, Billing.
- [ ] Work committed in reviewed checkpoints (only on the owner's instruction).
