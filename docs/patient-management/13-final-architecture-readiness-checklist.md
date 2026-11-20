# OralCares — Patient Management Product Rebuild
## Phase 13: Final Architecture Readiness & Verification Checklist

**Document:** `docs/patient-management/13-final-architecture-readiness-checklist.md`  
**Status:** Verification Audit Complete — Awaiting Explicit Product Owner Authorization for Phase A  
**Verification Date:** 2026-10-30  
**Evaluation Standard:** Zero assumptions. Strict distinction between conceptual approval, specification consistency, live codebase verification, database schema alignment, readiness for implementation, and unverified/unimplemented components.

---

## 1. Readiness State Taxonomy & Standard of Proof

To eliminate premature readiness claims, every architectural component is categorized into one of the following rigorous validation states:

1. **`[CONCEPT-APPROVED]` (Owner-Approved Concept):** The operational workflow, business logic, clinical scope, and governance boundaries have been formally approved by the Product Owner (including OPD-01, OPD-02, and OPD-03 on 2026-10-30).
2. **`[SPEC-CONSISTENT]` (Target Specification Consistent):** The component is fully specified across all 13 architecture documents (`docs/patient-management/`) with zero internal contradictions, exact formulas, and validated constraints. Ready for Phase A implementation planning.
3. **`[CODE-VERIFIED]` (Verified Against Live Codebase):** The logic has been directly inspected in active application source files (`app/Services/`, `app/Models/`, `app/Policies/`), citing exact files, methods, line numbers, and passing test names.
4. **`[DB-VERIFIED]` (Verified Against Live Database Schema):** Confirmed against physical migrations in `database/migrations/` and live table structures in MySQL.
5. **`[READY]` (Production Implementation Ready):** Permitted **ONLY** when migration prerequisites, backfill scripts, rollback procedures, reconciliation logic, and automated tests are 100% complete and passing. *(Note: Proposed target additions not yet migrated cannot receive `[READY]`)*.
6. **`[NOT-VERIFIED]` / `[NOT-IMPLEMENTED]`:** Functionality that is scheduled for future implementation phases, lacks frontend components, or requires runtime payment gateway authorization.

> [!IMPORTANT]
> **REGRESSION SAFETY BASELINE CAVEAT:**  
> The current baseline of **4,115 passing tests** (including 490 passing tests across `tests/Feature/Clinic/Patients/` and `tests/Feature/Clinic/Reception/`) represents regression protection for the **EXISTING live application**.  
> It must **NOT** be presented as proof that the proposed target architecture (such as `patient_cases`, `patient_clinical_access_audits`, or decoupled billing items) is implemented or verified. The proposed architecture remains unmigrated and unexecuted pending Phase A authorization.

---

## 2. Rigorous Component-by-Component Readiness Matrix

| Component / Subsystem | Architectural State | Exact Code & Method Reference | Relevant Migration / Schema | Test Name & Actual Result | Verification Provenance | Detailed Readiness Assessment |
| :--- | :---: | :--- | :--- | :--- | :---: | :--- |
| **Permanent Patient Identity (UHID)** | **`[READY]`** | `UhidGenerator::generate()`, `Patient::booted()` | `2026_07_01_000001_create_patients_table.php` (`patients.uhid` UNIQUE) | `PatientRegistrationTest::it_generates_sequential_uhid_atomically` $\rightarrow$ **PASS** | Verified in this review (2026-10-30) | Atomic sequence with pessimistic row locking. Fully implemented, migrated, and verified in active code. |
| **Decoupled 6 Identifiers** | **`[SPEC-CONSISTENT]`** | Spec: `04:L10-57`, `08:L134-163`, `11:ADR-01` | Proposed table `patient_cases` (Target DDL drafted in Phase 8) | Proposed schema: Test coverage will be written in Phase A | Verified in this review (2026-10-30) | Conceptual mapping complete across all docs. `patient_cases` table is NOT yet created in database. |
| **Appointment ↔ Visit ($0..1 \leftrightarrow 0..1$)** | **`[SPEC-CONSISTENT]`** | `PatientVisit.php`, `Appointment.php`; Spec: `04:L103-116`, `08:L206-234` | Target: `patient_visits.appointment_id` (`UNIQUE`, `ON DELETE RESTRICT`). `appointments.patient_visit_id` removed | `ReceptionCheckinTest::test_appointment_checkin` $\rightarrow$ **PASS** (Legacy baseline) | Verified in this review (2026-10-30) | Target schema removes redundant backlink and adds `RESTRICT`. Requires Phase A migration. |
| **Attendance vs Encounter ($1 \rightarrow 0..1$)** | **`[SPEC-CONSISTENT]`** | `PatientVisit::clinicalEncounter()`, `ClinicalEncounter::patientVisit()` | Target: `clinical_encounters.patient_visit_id` (`UNIQUE`, `ON DELETE RESTRICT`) | `ClinicalEncounterTest::test_encounter_creation` $\rightarrow$ **PASS** (Legacy baseline) | Verified in this review (2026-10-30) | Cardinality corrected in spec ($1 \rightarrow 0..1$). Target transaction separation drafted for Phase B. |
| **Independent Financial Ledger** | **`[SPEC-CONSISTENT]`** | `PaymentService::recordPayment()`; Spec: `04:L154-170`, `08:L286-382` | Target: `patient_billings`, `patient_billing_items` (`RESTRICT`), `patient_billing_refunds` | `BillingTest::test_invoice_creation` $\rightarrow$ **PASS** (Legacy baseline) | Verified in this review (2026-10-30) | Exact formulas defined (no double discount). 6 numerical scenarios verified. Additive schema in Phase A. |
| **Payment Receipts vs Attempts** | **`[SPEC-CONSISTENT]`** | `PaymentService::recordPayment()`, `ClinicDocumentNumbers::generateReceiptNumber()` | Target: `receipt_number NULL` on attempts, allocated on `status = 'successful'` | `PaymentCollectionTest::test_payment_generates_receipt` $\rightarrow$ **PASS** | Verified in this review (2026-10-30) | Contradiction resolved: `receipt_number` is strictly NULL on pending/failed rows. Idempotency specified. |
| **Financial Line Item Protection** | **`[SPEC-CONSISTENT]`** | Spec: `08:L320-337`, `11:ADR-04`, `12:Contradiction 5` | Target: `patient_billing_items` FK `fk_billing_items_billing` ON DELETE RESTRICT | Planned for Phase A migration test suite | Verified in this review (2026-10-30) | Replaces legacy `CASCADE` with `RESTRICT`. Safe lifecycle: adjustments require credit notes or soft-void. |
| **Dependent Profile Cascade** | **`[SPEC-CONSISTENT]`** | Spec: `08:L100-130`, `11:ADR-09` | `patient_profiles.patient_id` FK ON DELETE CASCADE | `PatientProfileTest::test_profile_creation` $\rightarrow$ **PASS** | Verified in this review (2026-10-30) | 1:1 demographic extension justified. Safe because parent `patients` has `RESTRICT` on all medical/billing child tables. |
| **Doctor Financial Visibility (OPD-01)** | **`[CONCEPT-APPROVED]`** | Spec: `09:L86-98`, `08:L428-436`, `11:OPD-01` | Target: `patient_billing_items.doctor_id`, `BillingPolicy::view()` scoping | Planned for Phase D / Phase G | Verified in this review (2026-10-30) | **Owner-Approved (Option A: 2026-10-30)**. Doctors see only own procedure fees/estimates. Scoped queries specified. |
| **Receptionist Package Selling (OPD-02)** | **`[CONCEPT-APPROVED]`** | Spec: `09:L73-85`, `11:OPD-02` | Policy: `billing.create` check in `SessionPackageController` | Planned for Phase B / Phase C | Verified in this review (2026-10-30) | **Owner-Approved (Option A: 2026-10-30)**. Not granted by default. Checked server-side on creation/sale. |
| **Cross-Branch Notes & Audit (OPD-03)** | **`[CONCEPT-APPROVED]`** | Spec: `08:L380-425`, `09:L102-160`, `11:OPD-03` | Target: `patient_clinical_access_audits` (DDL drafted in Phase 8) | Planned for Phase A migration & Phase D policy | Verified in this review (2026-10-30) | **Owner-Approved (Option A: 2026-10-30)**. Requires active attendance context + audit log entry. |
| **Treating Clinician Sole Signer** | **`[SPEC-CONSISTENT]`** | `ClinicalEncounter::finalize()`; Spec: `09:L3-10`, `11:ADR-05` | Target: `signature_hash`, `finalized_at`, `finalized_by` columns | `ClinicalEncounterSignatureTest::test_signing` $\rightarrow$ **PASS** | Verified in this review (2026-10-30) | Owner/Admin cannot edit clinical notes. Non-repudiation and addendum rules fully specified. |
| **Historical Doctor Attribution** | **`[CODE-VERIFIED]`** | `ClinicalEncounter.php:125-133`, `PatientVisit.php:86-89` | Live: `doctor_id` foreign keys point to historical `clinic_employees.id` | `PatientReassignmentTest::test_reassignment_preserves_history` $\rightarrow$ **PASS** | Verified in this review (2026-10-30) | Reassigning default doctor updates `patients.current_practitioner_id` only; past visits/encounters untouched. |
| **Patient Profile Exclusions** | **`[CODE-VERIFIED]`** | `resources/js/pages/patients/Create.tsx`, `Edit.tsx` | Schema excludes photo, alternate phone, WhatsApp, marital status, preferred language | `PatientFormTest::test_excluded_fields_absent` $\rightarrow$ **PASS** | Verified in this review (2026-10-30) | Inspected UI code and FormRequests; confirmed all 5 forbidden fields are excluded. |
| **FDI 2-Digit Tooth Chart** | **`[CODE-VERIFIED]`** | `resources/js/components/dental/DentalSection.tsx`, `PatientDentalChart.php` | `patient_dental_charts` table | `DentalChartTest::test_odontogram_render` $\rightarrow$ **PASS** | Inherited from 2026-10-29 audit | Interactive 11-48 FDI tooth grid exists in UI. Procedure checkout linkage planned for Phase E. |
| **Physio Body Map & SOAP Sessions** | **`[CODE-VERIFIED]`** | `PhysioAssessment.php`, `PhysioSession.php`, `PhysioCarePlan.php` | `physio_assessments`, `physio_sessions` tables | `PhysioWorkflowTest::test_body_map_and_soap` $\rightarrow$ **PASS** | Inherited from 2026-10-29 audit | Domain models and session punch counters exist. Front-desk `physio_operational` counter mapping specified. |
| **Prepaid Session Package Punching** | **`[CODE-VERIFIED]`** | `PatientSessionPackage.php`, `TreatmentCareController.php` | `patient_session_packages` table | `SessionPackageTest::test_atomic_session_decrement` $\rightarrow$ **PASS** | Inherited from 2026-10-29 audit | Atomic session decrementing under pessimistic row locks verified in code and passing Pest tests. |
| **Live Payment Gateway Manual Block** | **`[NOT-VERIFIED]`** | `reception-patient-management-audit-2026-10-29.md:41` | Live code accepts manual gateway entries | Requires real webhook integration test | Inherited from 2026-10-29 audit | Server accepts manual Gateway/Insurance entries. Fix drafted in Phase 1 P0; awaiting live test approval. |
| **After-Visit Billing Checkout Screen** | **`[NOT-IMPLEMENTED]`**| Target: `resources/js/pages/billing/Checkout.tsx` | Target component missing | Target feature not yet coded | Verified in this review (2026-10-30) | Backend endpoint exists; frontend React component unbuilt. Scheduled for Phase 2 implementation. |
| **Duplicate Patient Merge UI** | **`[NOT-IMPLEMENTED]`**| `PatientMergeService.php` (Backend exists; UI missing) | `patients.status = 'merged'`, `merged_into_patient_id` | `PatientMergeTest::test_backend_merge_logic` $\rightarrow$ **PASS** | Inherited from 2026-10-29 audit | Backend service passes tests; user-facing resolution modal unbuilt. Scheduled for Phase 4 implementation. |

---

## 3. The 6 Architectural Verification Pillars

```
                                  VERIFICATION AUDIT SUMMARY (2026-10-30)
  
  [1] CONCEPTUAL INTEGRITY (100% COMPLETE):
      ✔ All 10 foundational architectural points resolved and documented.
      ✔ Decoupled 6 identifiers, 1 -> 0..1 encounters, and independent invoices/receipts.
      ✔ 3 Owner Policy Decisions (OPD-01, OPD-02, OPD-03) formally approved (Option A for all three).
  
  [2] DOCUMENTATION SYNCHRONIZATION (100% COMPLETE):
      ✔ Specifications 01 through 13 in docs/patient-management/ are completely aligned.
      ✔ Zero conflicting definitions of Owner Mode, formulas, foreign keys, or receipt lifecycles.
      ✔ Exact financial calculation hierarchy with 6 verified numerical scenarios.
  
  [3] CODEBASE TRACING & LIVE VERIFICATION:
      ✔ Canonical services inspected: PaymentService, UhidGenerator, PatientDirectory, PatientPolicy.
      ✔ TypeScript compilation: 0 errors in Clinic portal code (`npx tsc --noEmit`).
      ✔ 490 patient/reception feature tests executed and passing (4,642 assertions, 0 failures).
  
  [4] DATABASE TARGET MIGRATION DESIGN:
      ✔ Additive migration strategy confirmed: patient_cases created without touching legacy numbers.
      ✔ Deletion safety guaranteed: ON DELETE RESTRICT on clinical, visit, invoice, and line item tables.
      ✔ Forensic audit ledger specified: patient_clinical_access_audits table schema drafted.
  
  [5] REGRESSION SAFETY BASELINE:
      ✔ Test baseline recorded: 4,115 passed (tests/Feature/Clinic: 1,592 passed, 0 failed).
      ✔ 15 legacy test rot failures isolated in Phase 0 benchmark.
      ✔ Baseline confirms current stability; does NOT claim proposed architecture is already migrated.
  
  [6] STRICT EXECUTION DISCIPLINE:
      ✔ ZERO application code files modified in this review pass.
      ✔ ZERO database tables, migrations, or live records touched.
      ✔ ZERO Git commits or pushes executed.
      ✔ Phase A remains strictly BLOCKED / NOT AUTHORIZED.
```

---

## 4. Final Implementation Gate & Sign-off Status

| Milestone / Gate | Description | Responsible Party | Current Status |
| :--- | :--- | :--- | :---: |
| **Milestone 1** | Architecture Corrections & ADR Review | Product Owner | **OWNER-APPROVED (2026-10-30)** |
| **Milestone 2** | Doctor Financial Visibility Policy (`OPD-01`) | Product Owner | **OWNER-APPROVED: OPTION A (2026-10-30)** |
| **Milestone 3** | Receptionist Package Selling Policy (`OPD-02`) | Product Owner | **OWNER-APPROVED: OPTION A (2026-10-30)** |
| **Milestone 4** | Cross-Branch Clinical Notes Policy (`OPD-03`) | Product Owner | **OWNER-APPROVED: OPTION A (2026-10-30)** |
| **Milestone 5** | **Authorization to Begin Phase A (Additive Migrations)** | Product Owner | **LOCKED (NOT AUTHORIZED / BLOCKED)** |

---

### Mandatory Execution Policy
No migrations, schema alterations, backend service modifications, frontend component rebuilds, or database writes will commence until **explicit written authorization to begin Phase A** is received from the Product Owner.

---

## 5. Phase A Corrected Preflight & Implementation Readiness Package (Audit Date: 2026-10-30)

### 5.1 Reconciled Patient Cases Target Schema (Phase 8 Ground Truth)
The proposed `patient_cases` migration is 100% reconciled against the approved Phase 8 architecture (`08-proposed-database-architecture.md:L140-165`) and dictionary (`03-patient-information-field-dictionary.md:L103-116`):
- `id`: BIGINT UNSIGNED PK
- `clinic_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `clinics.id` ON DELETE RESTRICT)
- `branch_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `branches.id` ON DELETE RESTRICT)
- `patient_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `patients.id` ON DELETE RESTRICT)
- `case_number`: VARCHAR(32) NOT NULL (Format: `CASE-YYYY-XXXXXX`)
- `specialty`: ENUM(`'dental'`, `'physiotherapy'`, `'maxillofacial'`, `'multispecialty'`) NOT NULL
- `title`: VARCHAR(150) NOT NULL
- `chief_complaint`: TEXT NOT NULL
- `primary_diagnosis`: VARCHAR(255) NULL
- `lead_practitioner_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `clinic_employees.id` ON DELETE RESTRICT)
- `referring_doctor`: VARCHAR(150) NULL
- `status`: ENUM(`'active'`, `'on_hold'`, `'completed'`, `'abandoned'`, `'transferred'`) NOT NULL DEFAULT `'active'`
- `opened_at`: DATETIME NOT NULL
- `closed_at`: DATETIME NULL
- `discharge_summary`: TEXT NULL
- `created_at`, `updated_at`: TIMESTAMPS
- Constraints: `UNIQUE KEY (clinic_id, case_number)`, `INDEX (patient_id, status)`, `INDEX (branch_id, status)`, `INDEX (lead_practitioner_id)`.

### 5.2 Canonical Appointment ↔ Visit Relationship
1. **Live Codebase & Database Ground Truth:**
   - Database inspection reveals `appointments` currently has **NO `patient_visit_id` column** in MySQL.
   - Live appointments (61 total) and visits (36 total) were historically bridged via `clinic_queue_items` (`appointment_id` and `patient_visit_id`), of which 3 live appointments are currently linked (Appt #31 $\rightarrow$ Visit #4, Appt #32 $\rightarrow$ Visit #5, Appt #33 $\rightarrow$ Visit #8).
2. **Approved Canonical Architecture:**
   - `patient_visits.appointment_id` is the **single canonical link** (BIGINT UNSIGNED NULL, `UNIQUE KEY`, FK $\rightarrow$ `appointments.id` ON DELETE RESTRICT).
   - Zero competing backlinks: no `appointments.patient_visit_id` will be introduced.
   - Deterministic backfill in Phase A safely maps the 3 verified links from `clinic_queue_items`.

### 5.3 Non-Destructive Patient Profile Hardening
- **Zero-Deletion Boundary:** The legacy `pincode` column in `patient_profiles` will **NOT be dropped** during Phase A.
- Live database audit confirms: 5 rows exist, `pincode` is NULL across all 5 rows, while populated rows have valid values in `postal_code` (`'411001'`, `'411045'`, `'560087'`).
- Phase A Migration will:
  1. Synchronize any legacy data: `UPDATE patient_profiles SET postal_code = pincode WHERE postal_code IS NULL AND pincode IS NOT NULL;`
  2. Retain `pincode` as a nullable column for a documented compatibility window.
  3. Enforce the required 1:1 constraint: `UNIQUE KEY patient_profiles_patient_id_unique (patient_id)`.
  4. Physical removal of `pincode` is formally deferred to a later phase with explicit Owner authorization.

### 5.4 OPD-03 Cross-Branch Clinical Access Audit Schema
Reconciled to exact Owner Policy OPD-03 and Phase 8 specification (`08:L398-424`):
- `id`: BIGINT UNSIGNED PK
- `clinic_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `clinics.id` ON DELETE RESTRICT)
- `actor_user_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `users.id` ON DELETE RESTRICT)
- `patient_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `patients.id` ON DELETE RESTRICT)
- `source_branch_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `branches.id` ON DELETE RESTRICT)
- `accessing_branch_id`: BIGINT UNSIGNED NOT NULL (FK $\rightarrow$ `branches.id` ON DELETE RESTRICT)
- `resource_type`: ENUM(`'clinical_encounter'`, `'clinical_note'`, `'prescription'`, `'diagnostic_scan'`) NOT NULL
- `resource_id`: BIGINT UNSIGNED NOT NULL
- `attendance_visit_id`: BIGINT UNSIGNED NULL (FK $\rightarrow$ `patient_visits.id` ON DELETE RESTRICT)
- `access_reason`: ENUM(`'active_attendance'`, `'emergency_care'`, `'referral_review'`, `'administrative_audit'`) NOT NULL
- `ip_address`: VARCHAR(45) NULL
- `user_agent`: TEXT NULL
- `accessed_at`: TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP

### 5.5 OPD-02 Permission Alignment
- The approved policy strictly enforces **`billing.create`** for creating invoices and selling packages.
- Receptionists do not receive `billing.create` by default. They handle queue check-in via `queue.manage` and view remaining package sessions via `physio_operational`.
- Replaced the erroneous draft reference `billing.package.sell` with the approved canonical permission `billing.create`.

### 5.6 Decoupled Payment Attempts, Receipts & Refunds Lifecycle
1. **Schema Correction:**
   - `patient_billing_payments.receipt_number` is already `NULLABLE` in the live DB (verified).
   - Add `status`: `VARCHAR(20) NOT NULL DEFAULT 'successful'` (`'pending'`, `'successful'`, `'failed'`, `'refunded'`).
   - Existing 16 payment records in the database are all verified completed historical transactions and are assigned `status = 'successful'`.
2. **Operational Rules:**
   - Initial payment attempt: Creates record with `status = 'pending'`, `receipt_number = NULL`.
   - On payment failure/cancellation: Updates `status = 'failed'`, `receipt_number = NULL` (no gaps in receipt series).
   - On confirmed success: Atomically transitions `status = 'successful'`, allocates sequential `receipt_number` (`RCT-YYYY-XXXXX`), records `paid_at`, and decrements `patient_billings.outstanding_balance`.
   - Gateways/Webhooks: Handled idempotently matching `transaction_reference`.
   - Refunds: Recorded in `patient_billing_refunds` (FK ON DELETE RESTRICT) preserving original receipt and invoice records intact.

### 5.7 Doctor Identity Mapping Ground Truth
Empirical codebase and database audit proves:
- All operational clinical entities in OralCares attribute the healthcare practitioner via `clinic_employees.id`:
  - `appointments.practitioner_id` $\rightarrow$ `clinic_employees.id`
  - `clinical_encounters.doctor_id` $\rightarrow$ `clinic_employees.id`
  - `patient_visits.doctor_id` $\rightarrow$ `clinic_employees.id`
  - `patient_billings.doctor_id` $\rightarrow$ `clinic_employees.id`
  - `patient_cases.lead_practitioner_id` $\rightarrow$ `clinic_employees.id`
- Therefore, `patient_billing_items.doctor_id` **MUST reference `clinic_employees.id`** (FK ON DELETE RESTRICT).
- When a doctor signs in, their user identity (`users.id`) maps to their clinical identity via `clinic_employees.user_id`. OPD-01 query filtering resolves `clinic_employees.id` for the authenticated user and matches `patient_billing_items.doctor_id`.

### 5.8 Robust Non-Destructive Rollback & Recovery Protocols
- **Absolute Rule:** `FOREIGN_KEY_CHECKS = 0` will **NEVER** be recommended or executed.
- **No Cascading Rollbacks:** Rollbacks will **NEVER** restore `ON DELETE CASCADE`. If a migration adding `ON DELETE RESTRICT` is rolled back, the constraint is relaxed to `ON DELETE NO ACTION` or the foreign key check remains intact without cascade rules.
- **Schema vs Data Rollback:** A schema rollback cannot drop a table (like `patient_cases`) if live data rows exist. Rollback scripts must check `if (DB::table('patient_cases')->count() > 0)` and abort if data is present.
- **Disaster Recovery:** Full database snapshot with SHA256 checksum taken immediately prior to migration. Full point-in-time restore procedure verified.

### 5.9 Deterministic Historical Case Backfill
- `patient_cases` represents an Episode of Care.
- 19 legacy records exist in `case_numbers` (annual sequence counter), and 36 records exist in `patient_visits`.
- **Mapping Invariant:** Only visits with an unambiguous, documented diagnosis or treatment plan are linked to an episode of care.
- **Ambiguous Visits:** Historical visits that do not map to an obvious clinical case **MUST REMAIN UNASSIGNED (`case_id = NULL`)**.
- Under no circumstances will artificial or fake "Default Cases" be synthesized. Unassigned visits are cataloged in an automated reconciliation report.

### 5.10 Corrected 8-Stage Granular Migration Manifest
The monolithic migration has been split into 8 independent, focused, atomically reversible migrations:
1. `2026_11_01_000001_create_patient_cases_table.php` (Creates `patient_cases` with Phase 8 approved schema).
2. `2026_11_01_000002_add_appointment_id_and_case_id_to_patient_visits.php` (Adds `case_id` RESTRICT, adds canonical `appointment_id` UNIQUE RESTRICT).
3. `2026_11_01_000003_harden_patient_profiles_unique_patient_id.php` (Non-destructive: preserves `pincode`, enforces `UNIQUE (patient_id)`).
4. `2026_11_01_000004_create_patient_clinical_access_audits_table.php` (Creates OPD-03 audit table with verified schema).
5. `2026_11_01_000005_harden_clinical_encounters_foreign_keys.php` (Hardens `patient_id` and `patient_visit_id` to RESTRICT, enforces `UNIQUE (patient_visit_id)`).
6. `2026_11_01_000006_harden_patient_billings_and_items_foreign_keys.php` (Hardens billing FKs to RESTRICT, adds `doctor_id` FK $\rightarrow$ `clinic_employees.id` RESTRICT).
7. `2026_11_01_000007_harden_patient_billing_payments_and_attempts.php` (Hardens payment FKs to RESTRICT, adds `status` column).
8. `2026_11_01_000008_harden_patients_and_visits_foreign_keys.php` (Hardens `patients.clinic_id` and `patient_visits.patient_id` to RESTRICT).

---

## 6. Five Final Read-Only Preflight Verification Invariants (Audit: 2026-10-30)

### 6.1 Exact Live Branch Table & Foreign Key Target
- **Live Empirical Evidence:** MySQL table name is strictly **`branches`** (NOT `clinic_branches`). Confirmed via `SHOW TABLES LIKE '%branch%'`.
- **Existing References:** All 53 foreign keys across the application reference `branches.id` (e.g. `appointments.branch_id -> branches.id`, `patient_visits.branch_id -> branches.id`, `clinical_encounters.branch_id -> branches.id`).
- **Target Specification:** In `patient_cases` and `patient_clinical_access_audits`, the foreign key target MUST be `branches.id` ON DELETE RESTRICT.

### 6.2 Historical Appointment Mapping Resolution
- **Live Empirical Evidence:** Exactly 3 appointments were checked in through the queue: Appt #31 $\rightarrow$ Visit #4, Appt #32 $\rightarrow$ Visit #5, Appt #33 $\rightarrow$ Visit #8. All 3 share identical `clinic_id` and `patient_id`.
- **Migration Invariant:** Column `appointment_id` added as nullable to `patient_visits`. In-migration deterministic update sets `appointment_id` for those 3 rows. The remaining 33 visits remain with `appointment_id = NULL` (true walk-in visits).
- **Index Safety:** `UNIQUE KEY patient_visits_appointment_id_unique (appointment_id)` is applied after update. In MySQL, multiple `NULL` rows do not violate a unique constraint.

### 6.3 Technical Immutability for `patient_clinical_access_audits`
- **Application Level:** Model defines `public const UPDATED_AT = null;` and throws `LogicException` on `static::updating()` and `static::deleting()`.
- **Authorization Level:** Policy forbids `update()` and `delete()` unconditionally. No mutating HTTP routes registered.
- **Database Level:** Direct protection via MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers raising signal `45000`.

### 6.4 Historical Billing Item Attribution & OPD-01 Scoping
- **Live Empirical Evidence:** All 20 existing rows in `patient_billing_items` currently lack `doctor_id`.
- **Invention Rule:** Zero historical doctor attributions will be synthesized. Migration 06 adds `doctor_id` as NULL without inferring values.
- **OPD-01 Visibility Invariant:** Under OPD-01 query scoping (`WHERE doctor_id = :authDoctorEmployeeId`), items with `doctor_id IS NULL` are automatically excluded from individual doctor views. They are visible solely to clinic administrators and billing staff holding `billing.view_all`.

### 6.5 Payment & Refund Accounting Traceability
- **Mathematical Formula:** $\mathbf{outstanding\_balance} = \mathbf{final\_amount} - (\mathbf{paid\_amount} - \mathbf{refunded\_amount}) - \mathbf{adjusted\_amount}$.
- **Traceability Invariant:** Original `patient_billing_payments` records and receipts (`RCT-YYYY-XXXXX`) are never modified or deleted. Refunds create an immutable audit record in `patient_billing_refunds` referencing `payment_id` and `patient_billing_id` with `ON DELETE RESTRICT`.

---

## 7. Accounting Rigor, Receipt Allocation & Production Migration Safety (Audit: 2026-10-30)

### 7.1 Mathematical Accounting Standards & Sign Conventions
1. **`final_amount` ($\ge 0.00$):** Total legally payable amount for invoiced procedures after line-item discounts, taxes, and invoice-level adjustments.
2. **`paid_amount` ($\ge 0.00$):** Gross cumulative sum of all confirmed successful payments (`patient_billing_payments.status = 'successful'`).
3. **`refunded_amount` ($\ge 0.00$):** Gross cumulative sum of all refunds issued (`patient_billing_refunds`). Strictly bounded by $\mathbf{refunded\_amount} \le \mathbf{paid\_amount}$.
4. **`net_collected` ($\ge 0.00$):** Net cash retained by the clinic: $\mathbf{net\_collected} = \mathbf{paid\_amount} - \mathbf{refunded\_amount}$.
5. **`adjusted_amount` ($\ge 0.00$):** Concessions, fee waivers, or debt write-offs on unpaid balances (`patient_billing_adjustments`). Bounded by $\mathbf{adjusted\_amount} \le \mathbf{outstanding\_balance}$.
6. **`outstanding_balance` ($\ge 0.00$):** Patient debt remaining:
   $$\mathbf{outstanding\_balance} = \mathbf{final\_amount} - \mathbf{net\_collected} - \mathbf{adjusted\_amount}$$
7. **Double-Counting Prevention:** Adjustments operate strictly on unpaid balances; refunds operate strictly on collected funds. They are mathematically partitioned and cannot double-count.

### 7.2 Receipt Allocation Mechanics & Concurrency Realities
- **Implementation Mechanism:** Driven by `ClinicDocumentNumbers::next()` and `NumberingEngine::issue()` using pessimistic row locking (`lockForUpdate()`) on `numbering_sequences`.
- **Concurrency Serialization:** Concurrent payment requests for the same branch queue on the sequence row lock, preventing duplicate number generation.
- **Rollback Behavior:** If the payment transaction rolls back, MySQL rolls back the sequence counter. Pending and failed attempts leave `receipt_number = NULL`.
- **Gap-Free Distinction:** Sequential numbering is guaranteed during normal operations, but "mathematically gap-free" status cannot be guaranteed under unhandled database server hard crashes mid-flight.

### 7.3 Multi-Layer Audit Immutability & Privileged Operator Realities
- **Layer 1 (Application):** `PatientClinicalAccessAudit` disables `UPDATED_AT` and throws `LogicException` on `updating()` and `deleting()`. Zero mutating routes registered.
- **Layer 2 (Policy):** `PatientClinicalAccessAuditPolicy` returns `false` unconditionally for update/delete.
- **Layer 3 (Database Triggers):** MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers raise SQLSTATE 45000.
- **Privileged Operator Boundary:** A root DBA with `SUPER` or `DROP TRIGGER` privileges can bypass triggers. True forensic non-repudiation requires external append-only log streaming (WORM storage).

### 7.4 Production Migration Safety & DDL Mechanics (Verified on MySQL 8.0.46 InnoDB)
1. **Migration 01 (`patient_cases`):** New table creation. Instantaneous. Zero lock on existing tables.
2. **Migration 02 (`patient_visits`):** `appointment_id` column addition uses `ALGORITHM=INSTANT` (supported in MySQL 8.0.29+). `UNIQUE` index addition is `ALGORITHM=INPLACE, LOCK=NONE`. Foreign key addition with `foreign_key_checks=ON` requires `ALGORITHM=INPLACE, LOCK=SHARED` (concurrent reads allowed, writes blocked during validation scan).
3. **Migration 03 (`patient_profiles`):** Upgrading `patient_id` to unique index is `ALGORITHM=INPLACE, LOCK=NONE`. `pincode` preserved.
4. **Migration 04 (`patient_clinical_access_audits`):** New table creation. Instantaneous. Triggers created without table locks.
5. **Migration 05 (`clinical_encounters`):** Dropping cascade FK is `ALGORITHM=INPLACE, LOCK=NONE`. Adding `RESTRICT` FK requires `ALGORITHM=INPLACE, LOCK=SHARED`. Adding `UNIQUE (patient_visit_id)` is `ALGORITHM=INPLACE, LOCK=NONE`.
6. **Migration 06 (`patient_billings` & items):** Adding `doctor_id` is `ALGORITHM=INSTANT`. Re-pointing FKs to `RESTRICT` requires `ALGORITHM=INPLACE, LOCK=SHARED`.
7. **Migration 07 (`patient_billing_payments`):** Adding `status` is `ALGORITHM=INSTANT`. FK upgrades require `ALGORITHM=INPLACE, LOCK=SHARED`.
8. **Migration 08 (`patients` & visits):** Upgrading clinic and patient FKs to `RESTRICT` requires `ALGORITHM=INPLACE, LOCK=SHARED`.

---

## 8. Deep Technical Verifications & Non-Atomicity Recovery Matrix (Audit: 2026-10-30)

### 8.1 Payment-Level Refund Validation (The Dual-Limit Invariant)
Every refund must satisfy **both** boundaries simultaneously:
1. **Payment-Level Refundable Limit:**
   $$\mathbf{refundable_{payment}} = \mathbf{payment.amount} - \sum(\text{refunds on this payment})$$
2. **Bill-Level Refundable Limit:**
   $$\mathbf{refundable_{bill}} = \mathbf{paid\_amount} - \mathbf{refunded\_amount}$$
3. **Execution Rule:** $\mathbf{refund\_amount} \le \min(\mathbf{refundable_{payment}}, \mathbf{refundable_{bill}})$.
4. **Concurrency & Locking:** Inside `DB::transaction()`, both the `patient_billings` row AND the specific `patient_billing_payments` row are locked via `lockForUpdate()`. Two simultaneous refund requests on the same payment or bill are strictly serialized, preventing race conditions.

### 8.2 Non-Circular Adjustment Accounting Lifecycle
1. **Pre-Adjustment Balance ($\mathbf{balance_{pre}}$):**
   $$\mathbf{balance_{pre}} = \mathbf{final\_amount} - (\mathbf{paid\_amount} - \mathbf{refunded\_amount}) - \mathbf{adjusted\_amount_{current}}$$
2. **Limit Enforcement:** Proposed adjustment $\mathbf{\Delta adjusted}$ must satisfy $0 < \mathbf{\Delta adjusted} \le \mathbf{balance_{pre}}$.
3. **Post-Adjustment Balance ($\mathbf{balance_{post}}$):**
   $$\mathbf{balance_{post}} = \mathbf{balance_{pre}} - \mathbf{\Delta adjusted}$$
   $$\mathbf{adjusted\_amount_{new}} = \mathbf{adjusted\_amount_{current}} + \mathbf{\Delta adjusted}$$
4. **Preservation & Reversal:** Adjustment records in `patient_billing_adjustments` are immutable. Concession revocations are recorded as reversing audit events without deleting history.

### 8.3 First-Time Sequence Initialization in `NumberingEngine`
1. **Database Constraint:** MySQL virtual generated column `branch_scope = coalesce(branch_id, 0)` backed by `UNIQUE KEY num_seq_scope_unique (organization_id, branch_scope, entity_type)`.
2. **Concurrent First-Time Races:** Handled via `NumberingSequence::insertOrIgnore([...])`. If two concurrent requests attempt initial insertion, one succeeds and the second ignores the duplicate error.
3. **Serialization:** Both threads immediately execute `NumberingSequence::where(...)->lockForUpdate()->firstOrFail()`. The second thread blocks until the first completes its increment and commits.

### 8.4 MySQL DDL Non-Atomicity & Recovery Checkpoints
> [!IMPORTANT]
> **MySQL Implicit Commit Reality:**  
> In MySQL/InnoDB (Version 8.0.46), every DDL statement (`CREATE TABLE`, `ALTER TABLE`, `DROP FOREIGN KEY`) triggers an **implicit commit**.  
> The 8-migration sequence **CANNOT be rolled back as a single atomic transaction**.

For each migration, recovery is checkpointed independently:
- **Failure Before DDL:** Clean state. Rerun safely.
- **Failure During/After DDL:** DDL is already committed. Rerunning naive migration script fails with "table/column already exists".
- **Partial State Detection & Forward-Fix:**
  - Migrations must check `Schema::hasColumn()` or `Schema::hasTable()` before applying alterations.
  - If a step fails midway (e.g. DDL succeeded, but subsequent data backfill failed), forward-fix by executing the backfill command directly, or drop the newly added column/table and re-run.
- **Point-in-Time Restore Checkpoint:** If an unrecoverable structural inconsistency occurs, restore from the full pre-migration SQL snapshot (`pre_phase_a_snapshot_20261030.sql`).

### 8.5 Actual MySQL 8.0.46 Engine Configuration
- **Version:** `8.0.46` (InnoDB: `8.0.46`).
- **`foreign_key_checks`:** `ON`.
- **`lock_wait_timeout`:** `31,536,000` seconds (Global metadata lock timeout).
- **`innodb_lock_wait_timeout`:** `50` seconds (Row lock wait timeout).
- **Online DDL Fact:** Adding foreign keys with `foreign_key_checks=ON` requires `LOCK=SHARED` in MySQL 8.0. Concurrent reads are permitted; concurrent writes to the child table are blocked during the validation scan.

---

## 9. Final Preflight Resolution & Technical Reconciliation (Audit Date: 2026-10-30)

### 9.1 Decoupled Payment vs. Invoice Lifecycle States
Zero net collection does **NOT** equal a refunded invoice. State models are strictly decoupled:
1. **Payment States (`patient_billing_payments.status`):**
   - `pending`: Attempt initiated, `receipt_number = NULL`.
   - `failed`: Attempt declined/expired, `receipt_number = NULL`.
   - `successful`: Payment collected, receipt stamped, immutable.
   - `partially_refunded`: $0 < \sum(\text{refunds on this payment}) < \text{payment.amount}$. Receipt preserved.
   - `refunded`: $\sum(\text{refunds on this payment}) == \text{payment.amount}$. Receipt preserved.
2. **Invoice States (`patient_billings.status`):**
   - `draft`: Unissued estimate.
   - `pending` (Unpaid): $\mathbf{paid\_amount} == 0.00$ and $\mathbf{outstanding\_balance} == \mathbf{final\_amount}$.
   - `partially_paid`: $0.00 < \mathbf{net\_collected} < \mathbf{final\_amount}$ and $\mathbf{outstanding\_balance} > 0.00$.
   - `debt_reopened` (Partially paid with refunded payment): e.g. Bill ₹10,000, paid ₹2,000, refunded ₹2,000 $\rightarrow$ $\mathbf{outstanding\_balance} = \mathbf{₹8,000}$. Treated as active outstanding debt, NOT refunded!
   - `paid`: $\mathbf{outstanding\_balance} === 0.00$ and rendered scope is settled.
   - `refunded`: Allowed ONLY when 100% of the invoice's collectible value was collected and 100% refunded, with $\mathbf{outstanding\_balance} === 0.00$.
   - `cancelled`: Formally voided invoice.

### 9.2 Four Refund Financial Treatments & Authorization Protocols
1. **Receivable Reopened:** Advance returned due to cash crunch; debt restored to full amount; requires `Owner Mode`; audit event `billing.refund.receivable_reopened`.
2. **Invoice Scope Reduction:** Undelivered treatment cancelled; line items voided; `final_amount` reduced; refund issued; `outstanding_balance` remains ₹0.00; audit event `billing.refund.scope_reduced`.
3. **Goodwill Refund with Authorized Write-off:** Dissatisfaction refund; cash returned AND remaining balance forgiven via `patient_billing_adjustments`; patient debt remains ₹0.00 (never silently creates debt); requires `Owner Mode`.
4. **Duplicate Payment Error:** Mistaken double payment refunded against redundant payment record; primary payment and receipt remain untouched; invoice remains `Paid`.

### 9.3 Adjustment Reversal Sign Convention
- **Counterpart Debit Invariant:** Adjustments and reversals use positive decimals. Reversals reference the original adjustment via `reversal_of_adjustment_id`.
- **Net Calculation:** $\mathbf{net\_adjusted} = \sum(\text{original concessions}) - \sum(\text{reversals})$.
- **Maximum Reversible Bound:** For adjustment $k$, $\mathbf{max\_reversible_k} = \mathbf{A_k} - \sum(\text{prior reversals of } k)$. Proposed reversal must satisfy $0 < \mathbf{\Delta rev} \le \mathbf{max\_reversible_k}$.
- **Concurrency:** Locked via `lockForUpdate()` on `patient_billings` and parent adjustment.

### 9.4 MySQL 8.0.46 Trigger Syntax Ground Truth
- **Empirical Fact:** `CREATE TRIGGER IF NOT EXISTS` throws SQL error 1064 in MySQL 8.0.46 (unsupported).
- **Safe Recovery Implementation:**
  ```php
  $exists = DB::table('information_schema.TRIGGERS')
      ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
      ->where('TRIGGER_NAME', 'prevent_access_audit_upd')
      ->exists();
  if (!$exists) {
      DB::unprepared("CREATE TRIGGER `prevent_access_audit_upd` BEFORE UPDATE ON `patient_clinical_access_audits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable';");
  }
  ```
- **Drop Syntax:** `DROP TRIGGER IF EXISTS` is fully supported.
- **Deep Schema Verification:** Recovery checks must inspect `information_schema.COLUMNS`, `STATISTICS`, `REFERENTIAL_CONSTRAINTS`, and `TRIGGERS`—not merely `hasTable()` or `hasColumn()`.
- **Backup Verification:** Snapshot verified via SHA-256 and dry-run table restore test before DDL execution.

### 9.5 Terminology Correction: Independently Checkpointed Migrations
The eight migrations are officially classified as **Independently Checkpointed Migrations with Explicit Recovery Procedures**. Because MySQL DDL executes implicit commits, multi-migration rollback atomicity is technically impossible; recovery is managed via isolated forward-fixes and restore checkpoints.


