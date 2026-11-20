# OralCares — Patient Management Product Rebuild
## Phase 10: Phased Implementation Roadmap & Verification Blueprint

**Document:** `docs/patient-management/10-implementation-roadmap.md`  
**Status:** Implementation Blueprint — Awaiting Approval Before Code Changes  
**Scale Target:** 100,000+ Multi-Tenant Healthcare Clinics  
**Zero-Downtime Guarantee:** Fully additive migrations, zero destructive schema changes, and non-breaking backward compatibility.

---

## 1. Phased Implementation Overview

> [!CAUTION]
> **CRITICAL EXECUTION GATE: PHASE A IS STRICTLY BLOCKED / NOT AUTHORIZED**  
> All phases described in this roadmap are implementation blueprints. **No application code changes, migrations, database alterations, or Git commits are authorized.** Implementation will commence ONLY after explicit written authorization is granted by the Product Owner.

The complete rebuild of the Patient Management module is structured into **7 controlled, sequential execution phases**. No phase begins until the preceding phase passes all Pest feature tests and multi-breakpoint browser QA.

```
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE A: ARCHITECTURE CONSENSUS & ADDITIVE SCHEMA MIGRATIONS           │
│ (patient_cases, patient_clinical_access_audits, ON DELETE RESTRICT)   │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE B: CORE BACKEND DOMAIN SERVICES & CASE DECOUPLING                │
│ (PatientCaseService, CaseNumber decoupling, OPD-02 package sell check) │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE C: PATIENT DIRECTORY FAST-ACTION REBUILD                         │
│ (Active Case badge, balance shielding, sub-second search, fast drawer) │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE D: PATIENT 360 WORKSPACE STREAMLINING (7 ZONES)                  │
│ (OPD-01 Doctor Fee Scoping, OPD-03 Cross-Branch Audit, 7 zones)        │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE E: SPECIALTY WORKSPACES (DENTAL CHART & PHYSIO BODY MAP)         │
│ (Strict specialty isolation, FDI tooth chart link to billing, SOAP)    │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE F: DOCUMENTS, PRESCRIPTIONS & TREATMENT INTEGRATION              │
│ (Categorized document grid, prescription refills, acceptance flow)     │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ PHASE G: COMPREHENSIVE MULTI-TENANT QA & BENCHMARK VALIDATION          │
│ (4,115+ regression baseline, 0 TS errors, 1440/768/390px sign-off)     │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Detailed Execution Plan by Phase

### Phase A: Architecture Consensus & Additive Schema Migrations
- **Objective:** Establish the database foundation for episode-of-care cases, financial line item protection, and cross-branch auditing without breaking existing records.
- **Tasks:**
  1. Create additive migration for `patient_cases` table (`id`, `clinic_id`, `branch_id`, `patient_id`, `case_number`, `specialty`, `title`, `chief_complaint`, `lead_practitioner_id`, `status`, `opened_at`, `closed_at`).
  2. Add `case_id` foreign key column to `patient_visits` with `ON DELETE RESTRICT`.
  3. Create additive migration for `patient_clinical_access_audits` table (enforces **OPD-03 Cross-Branch Auditing**).
  4. Ensure `patient_billing_items` and `clinical_encounters` enforce `ON DELETE RESTRICT`.
  5. Remove redundant `appointments.patient_visit_id` from target schema; enforce canonical `patient_visits.appointment_id` (`UNIQUE`, `ON DELETE RESTRICT`).
  6. Ensure all compound indexes (`clinic_id, status`, `clinic_id, uhid`, `clinic_id, phone`) are present.
- **Rollback Strategy:** Migration `down()` cleanly drops foreign key and `patient_cases` / `patient_clinical_access_audits` tables. Existing `case_numbers` remains fully intact.
- **Exit Gate:** `php artisan migrate` completes cleanly; SQLite in-memory tests pass 100%.

---

### Phase B: Backend Domain Services & Case Decoupling
- **Objective:** Implement domain logic for case lifecycles, decouple visit numbers from annual counters, and enforce receptionist package sale gating.
- **Tasks:**
  1. Build `PatientCaseService` (Create Case, Transition Status, Close Case).
  2. Backfill 1 default case per active treatment plan for existing patients.
  3. Update `classifyNext()` to support case linking on repeat intake.
  4. Optimize `PatientDirectory::paginate()` with eager loading (`currentPractitioner:id,name`, `activeCase`).
  5. Enforce **OPD-02 (Receptionist Package Selling)**: In `SessionPackageController` and `PaymentService`, enforce server-side that receptionist role cannot sell or create packages unless explicitly granted `billing.create`.
- **Exit Gate:** Unit and feature tests written for `PatientCaseService` and package sell permission checks passing with 0 failures.

---

### Phase C: Patient Directory Fast-Action Rebuild
- **Objective:** Deliver a high-density, sub-second patient directory for front-desk receptionists.
- **Tasks:**
  1. Update `PatientTable.tsx` to render: UHID, Name + Initials Avatar, Phone (masked/unmasked based on permission), Active Case Badge, Doctor, Balance (for billing roles), and Action Hub.
  2. Implement Slide-over Quick Drawer for instant preview of address, emergency contacts, and active safety alerts without full-page navigation.
  3. Refine instant prefix search on Name, UHID, Mobile, and Case Number.
  4. Action Hub: Gated package selling button shown only when `user.can('billing.create')`.
- **Exit Gate:** Multi-breakpoint browser check (1440px desktop, 768px tablet, 390px mobile); 0 horizontal overflow.

---

### Phase D: Patient 360 Workspace Streamlining (7 Zones)
- **Objective:** Eradicate the 17-tab cognitive overload, deliver the approved 7-zone workspace, and enforce OPD-01 & OPD-03 governance.
- **Tasks:**
  1. **Zone 1 (Header):** Compact hero card with UHID badge, safety strip, and locked doctor assignment governance.
  2. **Zone 2 (Active Cases Bar):** Horizontal pill selector showing active clinical cases.
  3. **Zone 3 (Overview Snapshot):** High-level KPI tiles (Active Case Progress, Latest Visit, Next Appointment, Balance). Zero narrative note duplication.
  4. **Zone 4 (Encounters & Visits):** Unified feed combining `patient_visits` and `clinical_encounters` with structured SOAP cards and signed status indicators. Enforce **OPD-03 Cross-Branch Clinical Notes Policy**: verify active attendance visit today before exposing notes from another branch; record audit row to `patient_clinical_access_audits`.
  5. **Zone 5 (Cases & Plans):** Itemized treatment plans with visual stage progression.
  6. **Zone 6 (Documents & Scans):** Unified file repository with category tabs (Prescriptions, X-Rays, Lab, Consents).
  7. **Zone 7 (Billing Ledger):** Bills, thermal receipts, and package punch-card telemetry. Enforce **OPD-01 Doctor Financial Visibility**: Doctors view only their own procedure fees and agreed estimates (`billing.view_own_fees`). Total clinic ledger rejected with 403 unless user holds `billing.view_all`.
  8. **Zone 8 (Timeline):** Milestone-only enterprise activity log.
- **Exit Gate:** All 17 old routes gracefully redirect to the 7 unified zones; `Patient360RoleVisibilityTest` and cross-branch audit logging tests pass.

---

### Phase E: Specialty Clinical Workspaces (Dental Chart & Physio Body Map)
- **Objective:** Enforce complete operational isolation between dental and physiotherapy workflows.
- **Tasks:**
  1. Dental: Connect interactive FDI 2-digit tooth chart directly to treatment plan generation and procedure completion checkout.
  2. Physio: Implement visual Body Pain Map (Anterior/Posterior), Goniometric ROM comparison tables, and automated package session decrementing.
  3. Enforce runtime gating: Dental-only clinics render zero physio elements; Physio-only clinics render zero tooth charts.
- **Exit Gate:** `MultiModuleIntakeTest` and specialty isolation tests pass across Dental (clinic 70), Physio (clinic 68), and Combined (clinic 69).

---

### Phase F: Documents, Prescriptions & Treatment Integration
- **Objective:** Finalize document previews, electronic prescriptions, and treatment acceptance.
- **Tasks:**
  1. Add image lightbox preview for radiographs (IOPA, OPG).
  2. Standardize electronic prescription dosages (M-A-N, Food relation, Days) with allergy warning triggers.
  3. Implement digital patient estimate acceptance record.
- **Exit Gate:** Document upload, prescription print, and payment message retry tests pass.

---

### Phase G: Comprehensive Multi-Tenant QA, Security Audit & Sign-off
- **Objective:** End-to-end regression validation and security verification.
- **Tasks:**
  1. Run full test suite: Verify that 0 regressions occur against baseline (4,115+ passed).
  2. Run TypeScript compilation: Verify 0 errors in Clinic portal code.
  3. Security Verification:
     - Verify OPD-01: Doctors cannot query or view clinic-wide invoices.
     - Verify OPD-02: Receptionists cannot sell packages without `billing.create`.
     - Verify OPD-03: Cross-branch note inspection writes to `patient_clinical_access_audits` and blocks remote browsing without active attendance.
  4. Browser QA: Side-by-side screenshots across Desktop (1440px), Tablet (768px), and Mobile (390px).
- **Exit Gate:** Final report presented to product owner for sign-off.

---

## 3. Policy Traceability Matrix to Implementation Roadmap

| Policy ID | Policy Title | Approved Mandate | Primary Implementation Phase | Technical Verification Hook |
| :--- | :--- | :---: | :---: | :--- |
| **OPD-01** | **Doctor Financial Visibility** | Option A (Owner-Approved) | **Phase D & Phase G** | `BillingPolicy::view()`, `Patient360BillingLedger` scoped query |
| **OPD-02** | **Receptionist Package Selling** | Option A (Owner-Approved) | **Phase B & Phase C** | `SessionPackageController::store()`, `billing.create` check |
| **OPD-03** | **Cross-Branch Clinical Notes** | Option A (Owner-Approved) | **Phase A & Phase D** | `patient_clinical_access_audits` migration, `ClinicalEncounterPolicy` |

---

## 4. Risk Mitigation & Rollback Matrix

| Potential Risk Scenario | Likelihood | Impact | Preventive Architectural Safeguard | Emergency Rollback Action |
| :--- | :---: | :---: | :--- | :--- |
| **Data Loss during Case Migration** | Low | High | All migrations are strictly additive. No columns or tables dropped. | `patient_cases` is supplementary; fallback reads directly from `patient_visits` and `treatment_plans`. |
| **Cross-Tenant Data Leak in Directory Search** | Low | Critical | `TenantScope` and mandatory `where clinic_id = ?` enforced in `PatientDirectory`. | Automated Pest feature test `CrossTenantAccessTest` runs on every pull. |
| **Slow Queries on 1,000,000+ Records** | Medium | High | B-Tree prefix indexes on `(clinic_id, uhid)` and keyset cursor pagination (`LIMIT 25`). | Query optimizer EXPLAIN plan verified; index hints applied if planner chooses full table scan. |
| **Doctor Overwrites Past Attributions** | Low | High | `doctor_id` is locked on historical `patient_visits` and `clinical_encounters`. Model throws `LogicException`. | Database triggers prevent updates to `doctor_id` where `finalized_at IS NOT NULL`. |
| **Front Desk Intake Flow Slowdown** | Medium | High | Reception intake popups (`NewCaseForm`, `RepeatCaseForm`) remain fast, single-transaction popups. | Intake forms bypass full Patient 360 loading; only fetch lightweight branch configuration JSON. |

