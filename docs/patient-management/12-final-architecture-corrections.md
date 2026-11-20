# OralCares — Patient Management Product Rebuild
## Final Architecture Corrections & Integrity Audit

**Document:** `docs/patient-management/12-final-architecture-corrections.md`  
**Status:** Audit & Corrections Complete — Awaiting Final Product Leadership Sign-off  
**Scope:** Formal record of all identified contradictions, technical corrections made, decisions pending product-owner approval, database constraints requiring application-level enforcement, and residual risk analysis.

---

## 1. Exact Contradictions Found in Previous Specifications

During the deep architecture consistency review, the following critical contradictions and integrity risks were identified and isolated:

### Contradiction 1: Owner/Admin Authority vs Clinical Authorship
- **Previous Contradiction:**  
  Phase 9 described the Owner/Admin role as having *"Full executive, clinical, and financial authority"*, while ADR-05 stated that the *"treating clinician is the sole clinical author and signer"*.
- **Risk:** Conflating administrative ownership with medical diagnosis could imply an owner can rewrite a doctor's consultation notes, violating medical council regulations (NMC/DCI) and legal non-repudiation.

### Contradiction 2: Mandatory 1:1 PatientVisit to ClinicalEncounter Cardinality
- **Previous Contradiction:**  
  Phase 8 ERD depicted `PATIENT_VISIT ||--|| CLINICAL_ENCOUNTER` as a mandatory 1:1 relationship, implying every checked-in visit immediately generates a clinical encounter.
- **Risk:** In real-world clinics, patients check in and may leave without being seen (due to long queues, triage redirection, or billing-only visits). Forcing a 1:1 relationship would either create blank dummy encounters or cause check-in failures.

### Contradiction 3: Bidirectional Appointment ↔ PatientVisit Keys Without Canonical Hierarchy
- **Previous Contradiction:**  
  Schema drafts defined both `appointments.patient_visit_id` and `patient_visits.appointment_id` without specifying which column was canonical, risking split-brain references. Furthermore, `patient_visits.appointment_id` previously used `ON DELETE SET NULL`, which could erase booking provenance.
- **Risk:** A single appointment could accidentally generate multiple visits, cancellation/reschedule states could corrupt attendance records, or deleting an appointment could erase the historical relationship with its attended visit.

### Contradiction 4: Double Subtraction of Discounts in Balance Calculations
- **Previous Contradiction:**  
  The generated column in Phase 8 was drafted as:
  `balance_amount = total_amount - discount_amount - paid_amount`.
  However, `total_amount` had already factored in `discount_amount` (`subtotal - discount_amount + tax_amount`).
- **Risk:** Discounts were subtracted twice, resulting in artificially lowered or negative balances on patient invoices.

### Contradiction 5: Unsafe `ON DELETE CASCADE` on Financial Line Items and Medical Records
- **Previous Contradiction:**  
  Phase 8 SQL drafts included `ON DELETE CASCADE` on `patient_billing_items` and `clinical_encounters`.
- **Risk:** If an invoice or patient record were altered, line items would silently disappear, violating accounting standards and medical audit regulations.

### Contradiction 6: Payment Attempts vs Successful Payment Receipts Contradiction
- **Previous Contradiction:**  
  `patient_billing_payments.receipt_number` was set to `NOT NULL` while the status column included `pending` and `failed`.
- **Risk:** Front-desk or gateway attempts that failed would either generate invalid legal receipts or throw database constraint violations, creating accounting chaos.

---

## 2. Technical Corrections Applied

| # | Domain | Original Flawed State | Corrected Architectural State | Target Documentation Synchronized |
| :--- | :--- | :--- | :--- | :--- |
| **1** | **Owner/Admin Boundary** | Claimed full clinical authority over all modules. | Clarified: Owner/Admin governs operations, staffing, financials, and approvals. **Zero clinical override.** Treating clinician is sole medical author and signer. | `04`, `08`, `09`, `11` |
| **2** | **Visit to Encounter Cardinality** | Depicted as mandatory 1:1 (`||--||`). | Corrected to **`1 -> 0..1`** (`||--o|`). Visits represent attendance; encounters are instantiated only when doctor care commences. | `04`, `08`, `11` |
| **3** | **Appointment vs Visit Integrity** | Dual foreign keys; `ON DELETE SET NULL` on visit. | `patient_visits.appointment_id` is canonical (`UNIQUE`, `ON DELETE RESTRICT`). `appointments.patient_visit_id` is **REMOVED** from target schema. Visit derived via relationship. | `04`, `08`, `11` |
| **4** | **Financial Balance Formula** | Double discount subtraction bug. | Corrected: `total_amount = subtotal - discount_amount + tax_amount`; `balance_amount = total_amount - paid_amount`. Tax is configurable (no hardcoded GST). | `04`, `08`, `11` |
| **5** | **Deletion Protection & Financial Line Items** | `ON DELETE CASCADE` on `patient_billing_items`. | Replaced with **`ON DELETE RESTRICT`**. Line items cannot be deleted. Corrections require credit notes (`refunds`) or invoice soft-voiding (`status = 'voided'`). | `04`, `08`, `11` |
| **6** | **Payment Attempts vs Receipts** | `receipt_number NOT NULL` on pending/failed rows. | `receipt_number` is strictly `NULL` on attempts; allocated upon confirmed `successful` status inside atomic transaction. Idempotent gateway callbacks. | `04`, `08`, `11` |
| **7** | **Dependent Profile Cascade Justification** | Unexplained cascade on `patient_profiles`. | Documented: 1:1 demographic extension. Cascade is safe because parent `patients` has `ON DELETE RESTRICT` on all visits, encounters, cases, and invoices. | `08`, `11` |
| **8** | **Multi-Branch Safety vs Notes** | Safety profile was mixed with private notes. | Tiered: Master Safety Profile (allergies, systemic illnesses) is accessible cross-branch; detailed private doctor SOAP notes remain permission-gated and audited. | `04`, `08`, `09`, `11` |

---

## 3. Owner-Approved Policy Decisions (Approved: 2026-10-30)

The three operational and governance policies have received **formal Product Owner approval (Option A for all three)**. Zero decisions remain pending for Phase A:

### Decision 1: Doctor Financial Visibility — OPTION A (APPROVED: 2026-10-30)
- **Approved Mandate:**
  - Doctors may view **only their own procedure fees and agreed estimates** associated with their authored clinical consultations or assigned active cases (`billing.view_own_fees`).
  - Doctors must **NOT** receive unrestricted access to the clinic-wide financial ledger.
  - Clinic-wide collections, outstanding patient balances across other providers, practice revenue reports, payment method summaries, and cash drawer registers remain strictly restricted by explicit billing permissions (`billing.view_all`, `billing.manage`).
  - Clinical access (`clinical.edit`, `clinical.sign`) does **NOT** automatically grant financial ledger access.
  - Visibility boundaries must be enforced through **explicit server-side query scoping and authorization policies**, never merely by hidden UI tabs.

### Decision 2: Receptionist Package Selling — OPTION A (APPROVED: 2026-10-30)
- **Approved Mandate:**
  - Package selling is **NOT automatically available to every receptionist**.
  - A receptionist may sell/create a therapy session package or dental bundle **ONLY when explicitly granted `billing.create`**.
  - Package creation and commercial sale transactions must be **validated server-side** on submission.
  - The `billing.create` permission must **NOT** be assigned by default to the receptionist role.
  - The system strictly preserves the architectural distinction between:
    - *Patient Registration & Session Check-in:* Managed via `queue.manage` and `physio_operational` (viewing session punch balances like `"4/10 completed"`).
    - *Financial & Package Transactions:* Governed by `billing.create` and `payment.collect`.

### Decision 3: Cross-Branch Clinical Notes Access & Auditing — OPTION A (APPROVED: 2026-10-30)
- **Approved Mandate:**
  - A doctor receives clinical-note access **in the context of an active attendance** at the branch where the doctor is currently practicing.
  - Access to clinical notes originating from another branch must be **strictly permission-controlled** (`clinical.notes.cross_branch`) and **forensically audited**.
  - All doctors must **NOT** be granted unrestricted browsing access to every branch's complete historical clinical notes.
  - The platform strictly separates cross-branch data into two distinct tiers:
    - *Tier 1 (Master Safety Profile):* Verified drug allergies, chronic conditions, cardiac/bleeding alerts, and blood group are globally accessible across all branches for acute patient safety.
    - *Tier 3 (Detailed Clinical Notes):* Doctor SOAP narratives, progress notes, and diagnostic interpretations from other branches remain permission-gated and require active attendance.
  - **Mandatory Forensic Audit:** Every cross-branch access event must be recorded in `patient_clinical_access_audits` capturing `actor_user_id`, `patient_id`, `source_branch_id`, `accessing_branch_id`, `resource_type`, `resource_id`, `attendance_visit_id`, `access_reason`, `ip_address`, `user_agent`, and `accessed_at`.

---

## 4. Database Constraints That CANNOT Be Enforced by Schema Alone

Relational foreign keys and unique constraints in MySQL/MariaDB have technical limitations in high-concurrency multi-tenant environments. The following invariants **cannot be enforced by DDL constraints alone** and MUST be enforced through application-level domain service transactions:

| Invariant | Why Schema Alone Cannot Enforce It | Application-Level Service Enforcement Strategy |
| :--- | :--- | :--- |
| **Cross-Tenant Isolation on Child Entities** | MySQL foreign keys validate row existence, but cannot enforce that `patient_cases.clinic_id == patients.clinic_id == appointments.clinic_id` without 4-column composite foreign keys on every table (which causes severe index bloat). | Enforced by Laravel `ClinicScoped` global scope + explicit `assertClinicContext($clinic)` in domain service constructors. |
| **Branch Placement Context Validation** | A composite foreign key cannot verify whether the signed-in user currently holds an active shift placement at the specific branch where a visit is created. | Enforced by `BranchReach` policy middleware + `assertBranchAccess($user, $branch)` before executing any intake or consultation write. |
| **Payment Status vs Balance Decrement** | Generated SQL columns (`balance_amount = total_amount - paid_amount`) calculate arithmetic, but cannot distinguish between a successful payment and a failed/pending UPI attempt. | Enforced by `PaymentService::recordPayment()` inside a `DB::transaction`: only transactions transitioning to `status = 'successful'` increment `patient_billings.paid_amount` and allocate `receipt_number`. |
| **Idempotent Queue Position Sequence** | Daily queue tokens (`#01`, `#02`, `#03`) reset at midnight per branch per doctor. MySQL auto-increment cannot partition by `(branch_id, doctor_id, DATE(created_at))`. | Enforced by `QueueStateMachine` and `AtomicDailySequenceService` using pessimistic row locking on daily token counters. |
| **Encrypted Digital Signature Non-Repudiation** | MySQL cannot compute or verify SHA-256 cryptographic hashes of complex JSON clinical narrative payloads upon insertion. | Enforced by `ClinicalEncounterService::finalizeAndSign()` computing `hash_hmac('sha256', $payload, $doctorKey)` prior to setting `finalized_at`. |
| **Cross-Branch Audit Log Generation** | MySQL foreign keys cannot trigger application audit writes across HTTP requests without complex database triggers. | Enforced by `ClinicalEncounterPolicy::viewNotes()` creating `patient_clinical_access_audits` records upon successful authorization. |

---

## 5. Remaining Risks & Mitigation Protocols

1. **Legacy Test Failures ("Test Rot"):**  
   15 legacy test failures exist in deleted Phase 0 modules (`docs/audit/test-baseline.md`).  
   *Mitigation:* These 15 tests are isolated and documented. Any failure count above 15 during Patient Management rebuild represents an immediate regression and blocks deployment.
2. **Frontend Inertia Page Manifest Desynchronization:**  
   Running `npm run build` while test suites are actively executing flips the Inertia asset manifest hash, causing intermittent test 409 errors.  
   *Mitigation:* Build and test steps must run sequentially, never concurrently.
3. **Data Migration Volume for Existing Clinics:**  
   Adding `patient_cases` requires grouping historical visits into baseline cases without locking large tables.  
   *Mitigation:* Additive migrations only. A chunked background queue job (`chunkById(500)`) will populate baseline cases without downtime.

---

## 6. Strict Non-Modification Confirmation & Phase A Implementation Gate

It is hereby confirmed that during this review pass:
- **Zero application code files were modified.**
- **Zero database migrations were created or executed.**
- **Zero database records were altered, added, or deleted.**
- **Zero routes, permissions, or billing configurations were changed.**
- **Zero Git commits or pushes were executed.**
- **Phase A remains strictly BLOCKED / NOT AUTHORIZED.**
- Execution will NOT commence until explicit Product Owner written authorization is received.
