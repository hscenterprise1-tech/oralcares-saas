# OralCares — Patient Management Final Completion Audit

**Date:** 2026-11-02
**Branch / HEAD:** `wip/m0-snapshot-2026-10-22` @ `7cd2e7a` + about 90 uncommitted or untracked paths (Antigravity Phase A/B/C and Claude's payment-first check-in work). Nothing was committed and no code was changed for this audit.
**Method:** I read the code, routes, migrations and tests in the current working tree. A screen counts as complete only when its JSX, its endpoint, a writer service and a test all exist together. I did not run a browser (the owner verifies the UI).

**Status keys**

| Key | Meaning |
|---|---|
| **VC** | VERIFIED COMPLETE: frontend, backend and tests exist and agree. |
| **FIX** | IMPLEMENTED BUT NEEDS FIX: it works, but has a concrete defect. |
| **PART** | PARTIALLY IMPLEMENTED: the backend is ready and the UI is missing, or the reverse, or part of the scope is missing. |
| **NI** | NOT IMPLEMENTED. |
| **OWNER** | BLOCKED / OWNER DECISION REQUIRED. |

---

## 0. Project state

| Item | Finding |
|---|---|
| Migrations | All migrations ran, none pending. Eight new `2026_11_01_*` migrations are untracked but applied: `patient_cases`, `patient_visits.case_id` / `appointment_id`, `patient_clinical_access_audits`, and RESTRICT foreign-key hardening. |
| Dev data | 2 `patient_cases`. 36 of the 38 visits have no `case_id` (no backfill has run). 21 legacy `case_numbers` rows. 0 rows in `patient_clinical_access_audits`. |
| Linked sessions | `finish-patient-360-visit` and `finish-patient-archive-r` are idle. The notes folder is empty. |
| Test baseline | See section 13. |
| TypeScript / build | 24 old `tsc` errors, none in Clinic code. `npm run build` passes (checked 2026-11-02 after payment-first check-in). |

### Previously reported vs verified now

| Area previously reported "done" | Verified in the working tree? |
|---|---|
| Phase A schema (cases, audits, RESTRICT foreign keys) | **Yes**, the schema exists. **But** the audit table has no writer, and the case service has integrity defects (see F-6 and S-4). |
| Phase B directory and identity | **Yes**, apart from one defect: the Case Number is shown to users (D-6). |
| Phase C case workflow at intake | **Partly.** Cases are linked at check-in, but they never close, are not backfilled and are not shown in Patient 360. |
| "Patient 360 production programme, CONDITIONAL PASS" (2026-10-27) | The **17 sections** are built and tested. The **7-zone IA and the Active Cases bar do not exist.** |
| Phase 4E physio domain | The backend is ready. **The body map, ROM and MMT have no UI** (the request validates them, but no form sends them). |
| Payment-first check-in (2026-11-02) | **Yes**, tested. This audit changes nothing there. |

---

## 1. Patient Foundation

| # | Requirement | Status | Frontend | Backend | Tables | Tests | Missing work / risk |
|---|---|---|---|---|---|---|---|
| F-1 | Permanent UHID, never reset yearly | **VC** | `Patients/components/PatientIdentity.tsx` | `Services/Patient/UhidGenerator.php` → `Core\NumberingEngine` (`patient` series per organization, no year) | `patients.uhid` | `Patients/PatientUhidTest.php`, `PhaseBPatientIdentityAndDirectoryTest.php` | None. |
| F-2 | Profiles and demographics | **VC** | `Patients/Create.tsx`, `Edit.tsx`, `components/form/*` | `PatientController@store/update`, `PatientRegistrar`, `PatientRegistrationService`, `Register/UpdatePatientRequest` | `patients`, `patient_profiles` | `PatientCreateEditTest`, `PatientEditTest`, `PatientUpdateDataSafetyTest`, `RegistrationHardeningTest` | Raw Tailwind palette in `components/form/*` (UI-3). |
| F-3 | Medical safety information | **VC** | `patient360/SafetyStrip.tsx`, `PatientHeader.tsx` | `PatientSafetyController`, `PatientClinicalEntryController` | `patient_alerts`, condition, allergy and medication entries | `Patient360SafetyStripTest`, `PatientClinicalWriteGateTest`, `ClinicalSafetyIntegrityTest` | Allergies stay off Patient 360 by the owner's earlier decision; the safety strip carries the alerts. |
| F-4 | Duplicate detection | **VC** | Intake `Step1IdentifyPatient.tsx`, `Patients/Create.tsx` | `POST patients/check-duplicate` (needs `patient.create`, throttled), `PatientSearch` | `patients` | `PatientDirectoryAndRegistrationTest`, `IntakeHardeningTest` | — |
| F-5 | Case vs Visit separation | **PART** | Repeat Case case picker (`RepeatCaseForm.tsx`) | `PatientCaseService::resolveCaseForVisit`, called from `ReceptionCheckIn` and `PatientRegistrationService` | `patient_cases`, `patient_visits.case_id` | `PhaseCPatientCaseWorkflowTest` | **There is no case lifecycle:** no close, pause or reopen; a plan completed or a physio discharge never closes the case. No backfill (36 of 38 visits unlinked). Patient 360 shows no cases. Two case-number systems coexist (legacy `case_numbers` `YYYY-000001` and `patient_cases` `CASE-YYYY-000001`). |
| F-6 | Case lead-practitioner integrity | **FIX (P0)** | — | `PatientCaseService::resolveFallbackDoctor` | `patient_cases.lead_practitioner_id`, `clinic_employees` | none | With no doctor chosen ("Next available"), the lead becomes the **first practitioner in the clinic, from any branch**. If none exists, it becomes **any employee (e.g. the receptionist)**. Failing that, it **silently creates a `clinic_employees` row with `staff_type=doctor`** for the current user inside the check-in transaction. The lead is never corrected when a doctor actually sees the patient. This is wrong medico-legal attribution plus a hidden staff write. |
| F-7 | Case-number concurrency | **FIX (P2)** | — | `PatientCaseService::generateCaseNumber` (max+1 with `lockForUpdate`) | `patient_cases` (unique `clinic_id, case_number`) | none | The first case of a year, or a gap lock miss, can collide; the unique index then turns it into a 500 and a rolled-back check-in instead of a duplicate. Move to `NumberingEngine` like the UHID. |
| F-8 | Branch-level patient relationships | **VC** | Directory "last seen at" | `PatientBranchAccess`, `PatientBranchActivity` | `patient_branch_activities` | `PatientDirectoryViewsTest`, Tenancy tests | — |
| F-9 | History preservation (merge, death, archive) | **VC** | `DeathRecordDialog.tsx`, governance requests | `PatientMergeService`, `RecordDeath`, `DeletionGovernance`, RESTRICT foreign keys | `patients`, `deletion_requests` | `PatientMergeIntegrityTest`, `DeceasedPatientTest`, `PatientArchiveRemovalMigrationTest` | Merge has no UI (`patient.merge`; listed in the handoff as P2). |
| F-10 | Registration and edit | **VC** | `Create.tsx`, `Edit.tsx`, intake popups | as F-2 | as F-2 | as F-2, `FastRegistrationTest`, `NewCaseFinalizationTest` | — |
| F-11 | Doctor assignment governance | **VC** | `PatientHeader.tsx` (transfer) | `PatientController@transfer` | `patients.current_practitioner_id` | `PatientTransferTest`, `PatientAssignmentDashboardTest` | — |

## 2. Patient Directory

| # | Requirement | Status | Evidence | Missing / risk |
|---|---|---|---|---|
| D-1 | Search by name, mobile and UHID | **VC** | `PatientDirectory::query → matchingSearch` (prefix, indexed); `PatientWordPrefixSearchTest`, `PatientDirectoryApiTest` | — |
| D-2 | All and Today views | **VC** | `PatientDirectoryAudience::views` (All first; a doctor opens on Mine); `PatientDirectoryViewsTest` | — |
| D-3 | Role-aware filtering | **VC** | Audience, policy and branch access; `PatientDirectoryFiltersTest` | — |
| D-4 | Pagination and query performance | **VC** | Keyset `cursorPaginate`, every page fact batched (one query each); `throttle:120,1` | The balance query still runs for billing users although **nothing renders it** (wasted query; drop it or keep it for the API, P2). |
| D-5 | Active case information | **PART** | `PatientTable.tsx::CaseType` | Depends on F-5: cases never close, so "active" never clears. |
| D-6 | Case Number not patient-facing | **FIX (P1)** | `PatientTable.tsx:302-309` | The table shows `active_case.case_number` as a mono badge, against the rule that the Case Number is internal. Show the case title and specialty instead. |
| D-7 | Contact masking | **VC** | `PatientDirectoryAudience::canSeeContact`, server-side; tests in the Directory suite | — |
| D-8 | Billing-balance permission / no balance column | **VC** | No balance column; the payload holds a balance only with `billing.view` | See D-4. |
| D-9 | Selecting a row opens Patient 360; Edit lives inside Patient 360 | **VC** | `PatientTable` row → `profileHref`; `PatientHeader` `edit.url` | — |
| D-10 | No unnecessary actions | **VC** | Only an "Open" column | — |

## 3. Patient 360: the seven zones

**Main finding:** the page today is a header plus **17 section tabs** (`Patient360SectionPolicy::SECTIONS`, `ClinicFrontend::PATIENT_360_SECTIONS`). The approved 7-zone IA (doc 05 §2.2 and §3) and the **Active Cases bar do not exist**. Every section has a real endpoint (`GET patients/{patient}/360/{section}`), server-side authorization (`Patient360SectionPolicy::allows`, re-checked per endpoint), loading, error and permission states (`Frame` / `SectionFrame`, `ErrorState`, `PermissionState`) and pagination. The work left is consolidation, not rebuilding.

| Zone | Status | Current sections that feed it | Frontend | Backend | Missing work |
|---|---|---|---|---|---|
| Header (persistent) | **VC** | — | `PatientHeader.tsx`, `SafetyStrip.tsx` | `Patient360Controller@show`, `Patient360Summary` | — |
| Active Cases bar | **NI** | — | — | `PatientCaseService::getActiveCases` exists but is **unused** | Build the bar; depends on F-5 (lifecycle) and F-6. |
| 1. Overview | **VC** | overview | `Overview.tsx` (deferred prop and skeleton) | `Patient360Summary` | Re-point its links once the sections merge. |
| 2. Clinical | **PART** | clinical (encounters), dental, physio, prescriptions, investigations | `RecordSections::ClinicalSection`, `DentalSection`, `PhysioSection`, `Sections::Prescriptions/Investigations` | `Patient360Sections::encounters/dental/physio/prescriptions/investigations` | Merge into one zone with specialty sub-views; the module gate already exists (`Patient360Access::specialtyWorkspaces`). |
| 3. Cases & Plans | **PART** | treatment | `Sections::TreatmentSection` | `Patient360Sections::treatment` (`patient_treatments`) | No `patient_cases` view at all. Cases ↔ treatments can be derived through `encounters.patient_visit_id → visits.case_id` (no migration). |
| 4. Visits | **VC** (merge pending) | visits, appointments, follow-ups | `VisitsHistory.tsx`, `AppointmentsSection`, `FollowUpsSection` | `visits/appointments/followUps` | Merge into one zone. Follow-ups are capped at 50 with no pager and no branch filter (P2). |
| 5. Docs | **VC** (merge pending) | documents, consents | `Sections::DocumentsSection`, `RecordSections::ConsentsSection` | `documents`, `PatientConsentController` | Merge into one zone. |
| 6. Billing | **FIX** | billing | `Sections::BillingSection`, `BillDialog` | `Patient360Sections::billing/bill` (branch-scoped) | Read-only by design. **OPD-01 is not enforced** (S-2): every practitioner holds `billing.view` and sees the whole ledger. |
| 7. Timeline | **VC** (merge pending) | timeline, communication, requests, audit | `RecordSections::Timeline/Requests/Audit`, `Sections::MessagesSection` | `timeline/communications/requests/audit` | Merge into one zone. The audit view stays owner/admin only. |

Tests: `Patient360Test`, `Patient360PageTest`, `Patient360HeaderOverviewTest`, `Patient360ModuleSectionsTest`, `Patient360RoleVisibilityTest`, `Patient360SectionLinksTest`, `Patient360VisitsTest`, `Patient360SafetyStripTest`.

## 4. Dental workspace

Charting happens in the consultation (`Consultations/Workspace.tsx` → `ClinicalPanels::DentalPanel`). Patient 360 → Dental reads it and runs plan transitions and lab steps.

| # | Requirement | Status | Frontend | Backend / tables | Missing / risk |
|---|---|---|---|---|---|
| DN-1 | FDI two-digit chart | **VC** | `ClinicalPanels::DentalPanel`, `DentalSection.tsx` | `DentalController@chart`, `DentalChartService::isValidFdiToothNumber`; `patient_dental_charts`, `patient_tooth_status_histories` | — |
| DN-2 | Tooth conditions and surfaces | **VC** | `FindingForm` | `ChartToothRequest`, `DentalRecord` | — |
| DN-3 | Diagnosis and clinical notes | **VC** | Workspace `Diagnoses`, `Notes`, `Addenda` | `EncounterWorkspace`; encounter diagnoses and notes | — |
| DN-4 | Treatment planning and transitions | **VC** | `PlanForm`, `PlansList`; 360 plan actions | `DentalController@proposePlan/acceptPlan/transitionPlan`; `treatment_plans`, `treatment_records` | — |
| DN-5 | Procedure completion | **VC** | `PlansList` complete | `DentalRecord::completeItem` (locks the plan and encounter, writes `patient_procedures`, tooth history) | — |
| DN-6 | Multi-stage treatment | **VC** | `CarePanel` stages | `patient_treatment_stages`, `treatments/{t}/stages/*` | — |
| DN-7 | Imaging | **PART** | `DentalSection` image list and preview; upload through Docs | `uploadDocument` (type `xray` / `intraoral_photo`, `tooth_numbers`) | No DICOM; JPG, PNG and PDF up to 10 MB only. No in-app viewer beyond the browser preview. |
| DN-8 | Lab orders and lifecycle | **VC** | `LabForm`; 360 lab steps | `DentalSpecialtyController@orderLab/labStep`, `LaboratoryWorkflowService`; `lab_orders` | — |
| DN-9 | Gum / periodontal | **VC** | `GumForm` | `DentalController@periodontal`; `periodontal_exams/teeth` | — |
| DN-10 | Recalls and follow-ups | **VC** | 360 Dental → recall; Follow-ups | `PatientFollowUpController`; `patient_follow_ups` | Recall = follow-up (no separate recall type). |
| DN-11 | Procedure-level doctor attribution | **VC** | — | `completeItem($doctor)`; encounter doctor; history row | — |
| DN-12 | Treatment → billing linkage | **PART (P1)** | **No UI.** `ClinicPortal/Billing/Checkout` page **missing**; `CheckoutController@show` renders it. No treatment-charges UI. | `EncounterCheckout`, `TreatmentCheckout`, `TreatmentChargeController` exist | Completed procedures **cannot be billed from the UI.** This is the largest dental product gap. Needs the Checkout page (routes and service exist). |

Tests: `Consultations/*`, `Treatments/*`, `Patient360ModuleSectionsTest`.

## 5. Physio workspace

| # | Requirement | Status | Evidence | Missing / risk |
|---|---|---|---|---|
| PH-1 | Anterior/posterior body pain map | **PART (P1)** | Backend: `physio_assessments.body_chart`, `PhysioAssessmentRequest` (region, side, pain type), `config/clinic_physio.body_regions` | **No UI.** `PhysioAssessmentPanel` sends only free-text "Where". |
| PH-2 | Pain score and assessment | **VC** | `PhysioAssessmentPanel` (rest and activity 0–10, impression, goals, diagnosis, outcome measures) | — |
| PH-3 | ROM and MMT | **PART (P1)** | Backend: `range_of_motion`, `strength` JSON validated | **No UI** for either. |
| PH-4 | SOAP | **VC** | `ClinicalPanels::Session`, `PhysioSessionRequest` (S/O/A/P) | — |
| PH-5 | Goals | **VC** | Assessment goals, `PlanOfCare` | — |
| PH-6 | Exercise prescription and home plan | **PART** | `physio_exercises` (`is_home`), `PlanOfCare` | There is no printable or shareable home-exercise sheet. |
| PH-7 | Packages and entitlements | **VC** | `SessionPackages`, `patient_session_packages`, `session_package_usages`; 360 package sale | Default reception rights conflict with OPD-02 (S-3). |
| PH-8 | Session registration (desk) | **VC** | Reception check-in: physio charges nothing and **consumes no package** (verified 2026-11-02, `NewCaseFinalizationTest`) | — |
| PH-9 | Completing treatment consumes the session | **VC** | `PhysioRecord::completeLocked → SessionPackages::consume` (idempotent, locked) | — |
| PH-10 | Lead vs treating practitioner | **VC** | `patient_treatments.practitioner_id` (lead) vs `physio_sessions.therapist_id` and `completed_by` | A third "lead" now exists in `patient_cases.lead_practitioner_id` (F-6). Keep the two-level model the source of truth. |
| PH-11 | Historical assignment immutability | **VC** (by convention) | Sessions written once, then completed or reversed | No database trigger (doc 12 §4 accepted application-level enforcement). |
| PH-12 | Reassessment and discharge | **VC** | `PhysioAssessment::REASSESSMENT` auto-kind; `ClinicalPanels::Discharge` | — |
| PH-13 | Progress history | **VC** | `PhysioSection` trends and discharges | — |
| PH-14 | Physio-only clinics show no Dental | **VC** | `Patient360Access::specialtyWorkspaces`; `MultiModuleIntakeTest`, `Patient360ModuleSectionsTest` | — |

## 6. Documents and prescriptions

| # | Requirement | Status | Evidence | Missing / risk |
|---|---|---|---|---|
| DR-1 | Secure upload with malware scan | **VC** | `Patient360Controller@uploadDocument` → `ScannedUploadIntake` (quarantine, scan, private disk); `documents.manage` | — |
| DR-2 | Categories | **VC** | `Patient360Sections::DOCUMENT_TYPES`; clinical vs administrative split | — |
| DR-3 | Image and X-ray preview | **PART** | `preview_url` (inline) | No lightbox; DICOM unsupported. |
| DR-4 | Secure download with audit | **VC** | Patient-scoped `findOrFail`, `isCleared`, `canOpenDocument`; `SecurityAudit document.downloaded/previewed` | Documents are not branch-filtered (OPD-03 tier; S-4). |
| DR-5 | Prescription create | **VC** | Workspace `Prescriptions`, `LineForm`; `PrescriptionLineRequest` | — |
| DR-6 | Dosage, duration, food relation | **VC** | `dosage`, `frequency`, `duration`, `timing` (`config/clinic_prescriptions.timings`) | — |
| DR-7 | Allergy warnings | **PART** | `PrescriptionAllergyCheck`: the documented allergies must be **reviewed** before signing | **No drug–allergy matching** (the code says the data model cannot do it). Needs an owner decision on a coded allergen/drug-class list. |
| DR-8 | Print and history | **VC** | `print_url`, `pdf_url`; 360 Prescriptions | — |
| DR-9 | Refills | **NI** | No code anywhere | Needs a scope decision (repeat-Rx copy vs refill count). |
| DR-10 | Document access permissions | **VC** | `canOpenDocument` (clinical vs admin field) | — |
| DR-11 | Owner approval for restricted removal | **VC** | `RemoveDocumentDialog` direct or request → `DeletionGovernance` | — |

## 7. Follow-ups, Timeline and communication

| # | Requirement | Status | Evidence | Missing / risk |
|---|---|---|---|---|
| C-1 | Follow-up create, reschedule, complete, cancel | **VC** | `PatientFollowUpController` (4 actions); `FollowUpsSection`, `AddFollowUp`, `MoveFollowUp`; `tests/Feature/Clinic/FollowUps` | Capped at 50, no pager, no branch scope (P2). The clinic-wide Follow-ups board is missing (handoff P2). |
| C-2 | Timeline events (clinical, billing, document, Rx) | **VC** | `Patient360Sections::timeline` + `TIMELINE` map; categories gated per field | — |
| C-3 | Clinical audit history | **VC** | `audit()` from `SecurityAuditLog`, owner/admin only | — |
| C-4 | WhatsApp permissions and consent | **VC** | `ManualShare` → `blockedReason` (consent, clinic sender, no fallback); per-kind permission | — |
| C-5 | Company vs clinic WhatsApp separation | **VC** | `ClinicWhatsAppSettings` / `ClinicWhatsAppMessage` vs `Company/Marketing/MarketingWhatsAppAccountController`; `tests/Feature/Clinic/WhatsApp` | — |
| C-6 | Retry and delivery history | **PART** | Delivery status in `communications()`; retry exists only for **payment** messages (`payments/{payment}/messages/retry`) | No retry for other patient messages (P2). |

## 8. Billing integration

| # | Requirement | Status | Evidence | Missing / risk |
|---|---|---|---|---|
| B-1 | Invoices and receipts | **VC** | `BillingSection`, `BillDialog`, print layouts | — |
| B-2 | Outstanding balances | **VC** | Bill balances; directory balance fixed to `pending` + `partially_paid` (2026-11-02) | — |
| B-3 | Package ledger | **VC** | `SessionPackages`, usages, 360 Physio | — |
| B-4 | Dental procedure billing | **PART (P1)** | See DN-12 (no Checkout UI) | — |
| B-5 | Physio package consumption | **VC** | PH-9 | — |
| B-6 | Payment history and refunds | **VC** (read) | `billing()` payments and refunds | Writes live in the Billing module. |
| B-7 | Doctor-specific financial visibility (OPD-01) | **NI / OWNER** | `ClinicPermission` template: `Practitioner` holds `billing.view`; no `billing.view_own_fees` | See S-2. |
| B-8 | Reception package-sale permission (OPD-02) | **OWNER** | `Reception` template holds `billing.create`; package sale checks `billing.create` | See S-3. |
| B-9 | Branch financial separation | **VC** | `Patient360Summary::bills → inBranches`; `TargetBranchFinancePermissionTest` | The handoff's P0: `CheckoutController::store` lacks the target-branch `billing.create` check. Re-verify it before the Checkout UI is built. |
| B-10 | Payment-first check-in and explicit Pay Later | **VC** | `CheckInCharges::chargeVisit`; `NewCaseFinalizationTest` | Preserved. |

## 9. Roles and multi-tenant security

| # | Requirement | Status | Evidence | Missing / risk |
|---|---|---|---|---|
| S-1 | Cross-clinic isolation | **VC** | Every 360 endpoint: `authorize('view')` and `clinic_id === context` (404); queries lead with `clinic_id`; `tests/Feature/Clinic/Tenancy`, `Security` | No leak found in this pass. |
| S-2 | OPD-01 doctor financial visibility | **OWNER (P0 policy)** | Not implemented | Needs a decision on how to change the **default templates for existing clinics** and what "own fees" covers (procedure lines where `doctor_id` = me? estimates on my cases?). Server-side scoping of `billing()` / `bill()` follows. |
| S-3 | OPD-02 receptionist package selling | **OWNER (P0 policy)** | `Reception` default holds `billing.create`; **payment-first check-in needs `billing.create` to raise the check-in fee** (ADR-07 says the desk *does* collect intake fees). | Removing `billing.create` from Reception would **stop the desk charging consultation fees.** Recommendation: a separate `package.sell` permission (not granted to Reception by default), keeping `billing.create` for the desk fee. This is a permission-policy change and needs approval. |
| S-4 | OPD-03 cross-branch notes and audit | **PART** | `ClinicalEncounterPolicy::view → canOpenBranch` **blocks** other-branch notes outright (safe). `patient_clinical_access_audits` has **no writer**; no `clinical.notes.cross_branch` permission. | Implement permission + attendance gate + audit write, or keep the hard block (owner decides). Documents are not branch-filtered. |
| S-5 | Contact masking | **VC** | D-7 | — |
| S-6 | Clinical-note access by role | **VC** | `Patient360SectionPolicy` fields (reception sees investigation status, never results); `Patient360RoleVisibilityTest` | — |
| S-7 | Owner/Admin combined, Owner Mode | **VC** | `OwnerMode`, governance direct vs request | — |
| S-8 | Audit logging | **VC** | `SecurityAudit` on print, download and governance | — |

## 10. Backend and data architecture

| # | Finding | Priority | Evidence | Action (no deletion without approval) |
|---|---|---|---|---|
| A-1 | Case fallback doctor / hidden staff write | **P0** | F-6 | Fix in service; add tests. |
| A-2 | No case lifecycle or backfill | **P1** | F-5 | Close or reopen rules need an owner decision. Backfill writes data and needs approval. |
| A-3 | Two case-number systems | **P2** | `CaseNumberGenerator`/`case_numbers` vs `patient_cases` | Register only; keep both until the owner decides. |
| A-4 | `PatientSearchService` unused | **P2** | Referenced nowhere but itself | Candidate for removal after grepping tests (memory rule); approval needed. |
| A-5 | Directory balance query unused by the UI | **P2** | D-4 | Skip it when not rendered. |
| A-6 | Follow-ups unbounded by page or branch | **P2** | C-1 | Add pagination and a branch filter. |
| A-7 | `generateCaseNumber` race | **P2** | F-7 | Use `NumberingEngine`. |
| A-8 | `patient_treatments.clinic_id` cascades on delete | Info | Migration `2026_10_03_154807` | Against ADR-09, but clinics are never hard-deleted. Register only. |
| A-9 | N+1 | OK | Directory batched; 360 sections eager-load; 500-row service list capped | — |
| A-10 | Indexes | OK | Cases, audits and visits indexes present | — |
| A-11 | Large files | OK | 10 MB limit, scanned | — |
| A-12 | Transactions and idempotency | OK | Check-in and registration atomic idempotency; plan and session locks | — |

## 11. UI and UX language

| # | Finding | Status |
|---|---|---|
| UI-1 | No `@/ovs/*`, `crmTheme` or `@/components/crm` imports in the Clinic Portal (only a comment in `clinic/index.ts`) | **VC** |
| UI-2 | Module-aware Dental and Physio | **VC** |
| UI-3 | Raw Tailwind palette (`slate-*`, `blue-*`, `purple-*`) instead of `cx` tokens in `directory/PatientTable.tsx`, `form/*` (7 files), `patient360/DentalSection.tsx`, `Overview.tsx` | **FIX (P2)** — token pass while Phase 1 touches these files |
| UI-4 | Patient 360 tab strip with 17 entries | **FIX** — solved by the 7-zone consolidation |

## 12. Remaining work in priority order

1. **P0** A-1 / F-6: case lead-practitioner fallback (silent `clinic_employees` creation, wrong attribution).
2. **P0 (owner)** S-2 / S-3: OPD-01 and OPD-02 defaults conflict with the shipped role templates; S-3 interacts with payment-first check-in.
3. **P1** DN-12 / B-4: Billing Checkout page (dental procedures and treatment charges have no UI path to a bill). Re-verify the handoff's `CheckoutController::store` branch check first.
4. **P1** Patient 360 7-zone consolidation and the Active Cases bar; Cases & Plans zone (needs A-2 rules).
5. **P1** D-6: hide the Case Number in the Directory.
6. **P1** PH-1 / PH-3: body map, ROM and MMT UI (backend ready).
7. **P1 (owner)** S-4: OPD-03 implementation vs keeping the hard block.
8. **P2** DR-9 refills, DR-7 drug–allergy matching (owner scope), PH-6 home-exercise print, C-6 message retry, DR-3 lightbox.
9. **P2** A-3 … A-7 clean-up and performance, UI-3 tokens.

## 13. Test baseline (2026-11-02)

`php artisan test --compact tests/Feature/Clinic`: **1642 passed, 2 skipped, 0 failed** (14,241 assertions, 370 s). The earlier appointment clock-boundary flake passed this time. The full suite was not re-run; its known baseline is 15 old failures.

## 14. Proposed phase plan (adjusted to dependencies)

The requested order is reshuffled in three places:

- **Integrity and decisions come first.** The case defects and the OPD defaults block Patient 360's Cases zone and its Billing zone.
- **Billing Checkout moves ahead of Dental.** Without it, dental completion has nowhere to go.
- **Security moves earlier.** OPD-01 changes what the Billing zone may show.

| Phase | Scope | Depends on | Exit gate |
|---|---|---|---|
| **0. Integrity and owner decisions** | Fix A-1 / F-6 (no silent employee writes; lead = the visit's doctor or none, corrected when a doctor starts the encounter; needs nullable-lead handling or an owner rule). Collect decisions D1–D6 below. | — | `PhaseCPatientCaseWorkflowTest` plus new fallback tests green |
| **1. Case lifecycle and Patient 360 zones** | Case close and reopen rules, approved backfill, D-6, then 17 sections → 7 zones + Active Cases bar (same endpoints, regrouped; the old `?section=` keys redirect to their zone). UI-3 tokens on the files touched. | 0, D3, D4 | Patient 360 tests updated; `tsc` 24; build |
| **2. Security (OPD-01/02/03)** | Implement the approved options server-side (billing scoping for doctors, package-sale permission, cross-branch audit or the confirmed hard block). | 0, D1, D2, D5 | New role tests; `TargetBranchFinancePermissionTest` |
| **3. Billing Checkout and Dental gaps** | `ClinicPortal/Billing/Checkout` (+ treatment charges); DN-7 / DR-3 preview. | 2 | Checkout feature tests; dental bill end to end |
| **4. Physio gaps** | Body map (anterior/posterior), ROM and MMT forms on the existing validation; home-exercise print. | 1 | Physio consultation tests |
| **5. Documents, prescriptions and follow-ups** | Refills (D6), allergy matching (if approved), follow-up pagination and branch scope, message retry. | 1 | Section tests |
| **6. Backend clean-up** | A-3 … A-7 (removals only with approval). | all | Full suite = baseline |
| **7. Regression and sign-off** | Full suite (≤ 15 known failures), `tsc`, build, the owner's manual UI pass. | all | Owner sign-off |

### Decisions needed from the owner

- **D1 (OPD-01):** what a doctor's "own fees" covers, and whether existing clinics' Practitioner roles lose `billing.view` automatically.
- **D2 (OPD-02):** a new `package.sell` permission (recommended), or removing `billing.create` from Reception (this breaks the desk check-in fee).
- **D3 (cases):** when a case closes (plan completed, physio discharge, manual) and whether to backfill historical visits. The backfill writes data.
- **D4 (Patient 360):** confirm the 17 → 7 mapping in section 3.
- **D5 (OPD-03):** build the permission + attendance + audit path now, or keep the current hard block.
- **D6 (Rx):** the meaning of a refill, and whether coded drug–allergy matching is in scope.
