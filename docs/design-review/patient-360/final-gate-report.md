# Patient 360 — final gate report

2026-10-27. Evidence comes from the code, the test runs and browser runs on the local QA fixtures listed below. All data is fictional. Nothing is committed.

## Verdict: CONDITIONAL PASS

Every mandatory workflow is implemented and was verified server-side and in a real browser, including a page reload. There are no regressions, the test suite matches its baseline, and no TypeScript errors come from Patient 360 code.

The single condition is something this environment cannot verify, not a known defect:

1. **Live WhatsApp delivery is unverified.** The dev database has no WhatsApp connection (`whatsapp_connections`: 0 rows), so no real message was sent. Sender isolation, consent and send-time eligibility are verified by tests.

## 1. Changes in this programme (uncommitted)

Earlier rounds of this session (safety strip, sections, allergy review, alerts and notes attribution, deceased guards in legacy services, migrations `2026_10_26_121004`, `121005`, `150959`) are recorded in `requirement-matrix.md` rows 1–19. This round added:

| File | Change |
|---|---|
| `resources/js/pages/ClinicPortal/Consultations/ClinicalPanels.tsx` (new) | `DentalPanel` (FDI finding and surfaces, plan items, accept, done-today with resulting condition, remove, gum charting, lab case), `PhysioAssessmentPanel`, `CarePanel` (start a treatment, plan of care with exercises, SOAP session with pain and exercises, complete against a package, package sale for practitioners with billing rights, **Discharge**). Actions are hidden on completed or cancelled treatments. |
| `resources/js/pages/ClinicPortal/Consultations/Workspace.tsx` | Renders the panels. Investigation lines now link to their order page. |
| `resources/js/pages/ClinicPortal/Orders/Show.tsx`, `Orders/Index.tsx` (new) | Order page (collect, enter/correct result, review and finalise, attach report, cancel with reason) and branch worklist. |
| `resources/js/pages/ClinicPortal/Patients/components/patient360/PhysioSection.tsx` | "Sell a package" dialog (billing rights, active plan, idempotency key, guarded against double submission). |
| `resources/js/pages/ClinicPortal/Patients/components/patient360/SafetyStrip.tsx` | `LinkButton` is at least 24 px wide (the "Edit" target measured 23 px). |
| `app/Http/Controllers/Clinic/ConsultationController.php` | Dental payload gains periodontal and lab links plus the periodontal vocabulary. |
| `app/Services/Treatment/ConsultationCare.php` | Card links `close_plan` and `complete`. `complete` is only offered on stage-less physio treatments. |
| `app/Services/ClinicalOrderService.php` | A re-entered result keeps the previous result (`corrected`, `previous`) in the timeline. |
| `app/Services/Physio/PhysioRecord.php` | Deceased guard on `openSession` and `startPlan`. This was a real gap: a consultation opened before a death was recorded could still start new physio care. |
| `app/Support/ClinicFrontend.php` | `clinic.orders.index` and `clinic.orders.show` are now available. |
| Tests | `InvestigationResultsWorkflowTest` (new, 4), `ConsultationClinicalPanelsTest` (new, 2), `ConsultationCareTest` (+2: discharge and package sale; deceased physio), `Patient360ModuleSectionsTest` (+1: neither module), `Patient360SectionLinksTest` (order url). |

## 2. Dental and Physio writing in the consultation (browser, then reload)

- **Dental**, as the dentist in clinic 62, consultation 43, patient 359:
  - Steps: caries finding on tooth 25 (O surface), plan "E2E plan 25" proposed and accepted, gum charting (stage II), lab case "E2E onlay".
  - After reload, tooth 25 shows caries and the plan persists. Patient 360 Dental shows the lab case and gum health.
  - No console errors.
  - Screenshots: `qa/consultation/dental-e2e-1440.png`, `dental-e2e-p360-1440.png`.
- **Physio**, as the physiotherapist in clinic 68 (physio-only), patient 346:
  - Visit 45: assessment (pain 3/7, outcome measure, diagnosis), ad-hoc treatment, plan of care with exercise, session 1 completed. The chief complaint autosaved and survived a reload. The visit was signed.
  - The owner sold a package from Patient 360. A double-click sold exactly one package.
  - Visit 46: session 2 completed on the package, which then read 1 of 6 used, 5 left. Discharge with a note followed.
  - After reload the treatment shows "Completed". Patient 360 Physio shows the discharge note, "No active plan of care" and the pain trend.
  - The doctor (no billing rights) sees no sell button, and the endpoint returns 403.
  - Screenshots: `qa/consultation/physio-*.png`.
- Server tests: `ConsultationClinicalPanelsTest`, `ConsultationCareTest` (8), `DentalSpecialtyTest`, `PhysiotherapyExtensionTest`.

## 3. Investigation order to final result

- Browser, as the dentist: ordered in consultation 43 → link to `/clinic/orders/4` → collected → result 9.1 % (high) → corrected to 8.4 % with a report → reviewed with a note.
- After reload the item shows final and "Correct result" is gone.
- Database: `diagnostic_results` holds 8.4, `verified_by` and `verified_at` set, and the review note.
- The worklist's done tab lists it. Patient 360 Investigations shows the name and 8.4.
- Reception gets 403 on the order page and sees the status but never the value.
- `InvestigationResultsWorkflowTest` (4) covers:
  - full lifecycle, with the correction keeping the previous values in the timeline;
  - 403 when changing a reviewed result;
  - validation (summary or values required; flag whitelist);
  - reception 403 on the order page and the worklist, and billing 403 on the worklist and on review;
  - another clinic 403, and a doctor limited to another branch 403 on both read and result entry.

## 4. The 17 sections

All 17 sections are built and gated per section by `Patient360SectionPolicy` with branch-scoped queries (matrix row 9): Overview, Visits, Clinical, Dental, Physio, Treatment, Appointments, Prescriptions, Investigations, Billing, Documents, Consents, Communication, Follow-ups, Timeline, Requests, Audit.

Evidence:
- `Patient360PageTest` (per-role lists), `Patient360Test`, `Patient360SectionLinksTest`;
- the earlier sections QA (`qa/sections/`) and this round's responsive pass.

## 5. Roles and entitlements

| Role | Verified in browser | Verified in tests |
|---|---|---|
| Owner | 360 billing, physio package sale | sections, governed actions, Owner Mode |
| Doctor | consultation (dental and physio), orders, 360 sections | section lists, writes, 403 on package sale |
| Reception | 360 overview; order page 403; result value hidden | 403 on order page, worklist and dental write |
| Billing | 360 billing | 403 on worklist and review; no clinical physio section |
| Clinic Admin | all 16 built sections on clinic 62, each through its server endpoint (200) and opened in the UI (renders, no error or permission state); 360 overview, Billing and Documents at all five widths with 0 overflow (`qa/responsive/p360-admin-*.png`); orders and consultation open (`clinical.view`) | per-role section list |

Entitlements (`Patient360ModuleSectionsTest`, 4 tests; also live: clinic 62 dental-only, 68 physio-only):
- dental-only: physio returns 403;
- physio-only: dental returns 403;
- both modules: both sections shown;
- neither module: no sections, both refused, no consultation panels, and a dental write returns 403.

## 6. Tenant, branch, patient and deceased safeguards

- **Clinic and branch isolation:** policies check `clinic_id` and branch access, with tests in 27 files across Patients, Consultations and Treatments. New this round: order page and result entry are refused for another branch and another clinic.
- **Patient binding:** dental, physio, order and treatment writers refuse a record belonging to another patient (`InvalidArgumentException` / 403). Browser-supplied ids are never trusted: links come from the server, and `encounter_id` and `package_id` are re-checked.
- **Deceased patients:**
  - `RecordDeath` cancels future bookings, closes open follow-ups and skips waiting messages.
  - `PatientCareGuard` covers every new-care writer, and the four legacy services, and (new) physio session and plan start.
  - Scheduled messages (`DispatchDuePatientMessages`, `clinic:queue-scheduled-messages`) re-check eligibility at queue, dispatch and send time.
  - Care given before the death can still be documented and billed.
  - Tests: `DeceasedPatientTest` (14 cases), `PatientMessageEligibilityTest` (9), `ConsultationCareTest` deceased case.
- **Allergies versus conditions:**
  - A prescription with documented allergies is signed only after a review against the current list (a fingerprint). An older review does not count.
  - Allergies are not shown as conditions.
  - `ClinicalSafetyIntegrityTest` covers 6 allergy cases.
- **WhatsApp:**
  - Own-number mode never falls back to the company number.
  - Consent is required, and eligibility is re-checked at send time.
  - Statuses are never shown as delivered.
  - Tests: `ManualShareTest` (5), `PatientMessageEligibilityTest`, `PatientMessageDeliveryStatusTest`.
  - Live delivery: **unverified** (no connection).

## 7. Test suite

Command: `php artisan test --compact` (SQLite in-memory, the same command and environment as the baseline). Exit code 2.

| | Passed | Skipped | Failed |
|---|---|---|---|
| Baseline | 4,092 | 9 | 15 |
| Now | **4,100** | 9 | **15** |

- **No new failures.** The 15 match the recorded baseline by name (`docs/architecture/clinic-portal-ux-blueprint.md` §1.5):
  - `ClinicWorkspaceTest` ×3 (QueryException);
  - `ClinicalFoundationTest` departments API (RouteNotFound);
  - `PatientRegistrationTest` legacy UHID (QueryException);
  - `Company\Crm\Phase0LifecycleTest` ×3;
  - `Phase4CustomerSuccessTest` ×1;
  - `SuperAdminBypassTest` ×1;
  - `InventoryFoundationTest` ×1;
  - `TaskControllerTest` ×2;
  - `TenantProvisioningTest` ×2.

  None of them touches code changed here.
- The +8 passing tests are the new tests.
- Focused suites: `php artisan test --compact tests/Feature/Clinic/Patients tests/Feature/Clinic/Consultations tests/Feature/Clinic/Treatments` gave 448 passed, 1 skipped, 0 failed.
- `InvestigationResultsWorkflowTest` (4) was rerun after the suite started, because its branch test was added later.

## 8. TypeScript

Command: `npx tsc --noEmit -p tsconfig.json`. Exit code 1, **24 errors, the same as the baseline**. The output was identical before and after this round's last change.

The errors are all in legacy or unrelated files:

| File | Errors |
|---|---|
| `ovs/calendar/scheduler.tsx` | 12 |
| `ovs/patterns/prescription-builder.tsx` | 4 |
| `ovs/patterns/odontogram.tsx` | 2 |
| `ovs/calendar/mini-calendar.tsx` | 1 |
| `ovs/location/OVSLocationSelect.tsx` | 1 |
| `ovs/primitives/index.ts` | 1 |
| `ovs/tables/data-table.tsx` | 1 |
| `components/auth/OtpDigitInput.tsx` | 1 |
| `components/enterprise-system/widgets/RevenueIntelligenceWidget.tsx` | 1 |

There are **0 errors** in Patient 360, consultation, Dental, Physio or order files.

## 9. Responsive browser QA (1440, 1024, 768, 390, 360 — each width actually run)

**Full-page sweep:** 16 page states × 5 widths = 80 captures, in `qa/responsive/` with the raw audit in `results.json`. Each capture was checked for page overflow, elements past the edge, overlapping controls, tap targets under 32 px on phones, clipped text and console errors.

| Page state | Role |
|---|---|
| consultation 43 (dental, plan tab, gums tab) | dentist |
| 360 overview, Dental, Investigations, Documents | dentist |
| order page and result dialog; worklist | dentist |
| 360 Billing | owner, billing |
| 360 overview | reception |
| physio consultation 46 and 360 Physio | physiotherapist |

**Close-ups:** 9 states × 5 widths, in `qa/responsive/panels/`.
- dental gums tab, plan tab, plans list;
- physio treatment card and assessment;
- discharge, start-treatment, package-sale and review dialogs.

**Results:**
- Page overflow is 0 on all 125 captures. Nothing is past the edge, and there are no console errors.
- The flags were inspected against the screenshots:
  - The "clipped" items are `sr-only` text.
  - The 20 px items are labelled checkboxes, and the 1 px items are hidden file inputs.
  - The "overlaps" are page controls under the modal backdrop, or dialog content scrolling under its fixed footer, which is intended.
  - 28 px-high text buttons meet the WCAG 2.2 AA 24 px minimum.
- One real defect: the "Edit" target was 23 px wide. It is fixed and rechecked at 390 and 360 (now 24 × 28).
- The first sweep's package-sale state failed by design, because the patient had been discharged so no sale was offered. It was retested on a patient with an active plan (`panels/sell-package-dialog-*.png`).

## 10. Evidence locations

- `docs/design-review/patient-360/qa/consultation/`: dental, physio and order E2E screenshots.
- `docs/design-review/patient-360/qa/responsive/`: 80 captures plus `results.json`. `panels/` holds 45 close-ups.
- Earlier sets: `qa/sections/`, `qa/stress/`.
- `docs/design-review/patient-360/requirement-matrix.md`.

## 11. External dependencies and genuine limitations

- **WhatsApp:**
  - Real sends need the clinic's (or the company's) Meta number connected and each template approved.
  - Free text works only inside Meta's 24-hour window, and files need media templates. Neither is built, to avoid unapproved sends (matrix row 19).
  - Statuses stop at "sent (accepted by provider)", "failed" or "skipped with reason". They are never shown as delivered.
- **Package sales:** done by staff with billing rights inside clinical context: the owner, an admin or a practitioner who holds billing rights. The Billing Staff role does not see the clinical Physio section, which is a deliberate role view because it contains SOAP notes and assessments. So Billing Staff cannot sell from there; they bill sold packages through Billing. If the business wants Billing Staff to start sales, that is a product decision: a sale action on the Billing page. It is not a defect against the agreed requirements.
- **One session per treatment per visit:** by design, `PhysioRecord::openSession` returns the visit's existing session. Session 2 needs a new visit (verified with visits 45 and 46).
- **Investigation results:** a result can be corrected until review, and every correction keeps the previous values and reporter in the timeline. After review (final) it cannot be changed (403). An amendment workflow for final results does not exist; clinically that means a new order.
- **Orders worklist:** the "Done" tab covers the last 14 days.
- **Discharge:** offered on stage-less physio treatments. Staged (dental) treatments complete through their stages.

## 12. Unverified or incomplete acceptance criteria

1. Live WhatsApp delivery: external, no connection in this environment.

Everything else in the master prompt's applicable criteria is verified as recorded above.

## Continuation checkpoint

- Nothing is pending for this gate.
- The next action belongs to the user:
  - review;
  - connect a Meta number on staging to verify live delivery;
  - decide whether Billing Staff should start package sales from the Billing page.
