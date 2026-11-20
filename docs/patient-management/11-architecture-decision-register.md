# OralCares — Patient Management Product Rebuild
## Architecture Decision Register (ADR) & Resolution Document

**Document:** `docs/patient-management/11-architecture-decision-register.md`  
**Status:** Approved Architectural Decisions (Revised & Fully Synchronized)  
**Author:** Antigravity AI & Healthcare Product Architecture Team  
**Review Target:** OralCares Product Leadership  

---

## 1. Executive Summary

This Architecture Decision Register (ADR) formally documents the resolution of the **10 critical architectural and operational questions** governing the complete rebuild of the OralCares Patient Management module.

All resolutions adhere to:
- Documentation-only phase (Zero code or schema modifications).
- Additive, zero-downtime database design.
- Separation of operational, clinical, and financial concerns.
- Strict multi-tenant isolation (100,000+ clinics).
- Medico-legal immutability of signed patient health records.
- Deletion protection (`ON DELETE RESTRICT`) for medical and accounting records.

---

## 2. The 10 Architectural Resolutions

### ADR-01: Decoupling the 6 Core Identifiers
- **Problem:** Historical conflation where "Case Number" was used as an annual visit queue counter (`YYYY-000001`), leading to broken episode tracking and inability to handle multiple concurrent treatments.
- **Decision:** Establish 6 independent identifiers with distinct scopes, formats, and lifetimes:
  1. **Patient UHID (`OC-00000001`):** Permanent lifetime identity, unique per clinic across all branches. Never resets.
  2. **Case Number (`CASE-YYYY-000001`):** Clinical episode of care (e.g. Root Canal #36, ACL Rehabilitation). Unique per clinic per year.
  3. **Appointment ID (`APT-YYYY-000001`):** Calendar time reservation. Does not imply physical attendance. Unique per clinic per year.
  4. **Queue Token (`#01`, `#02`, `#03`):** Daily operational calling token for reception and TV displays. Unique per branch per doctor per day. Ephemeral (resets daily at midnight).
  5. **Clinical Encounter Number (`ENC-YYYY-000001`):** Legal medical consultation document containing signed SOAP narrative. Unique per clinic per year.
  6. **Financial Numbers:**
     - **Invoice Number (`INV-YYYY-000001`):** Commercial legal bill for services rendered. Unique per branch per financial year.
     - **Receipt Number (`REC-YYYY-000001`):** Legal proof of payment collection issued on **every payment event**. Unique per branch per financial year.

---

### ADR-02: Correct Appointment-to-Visit Relationship & Integrity
- **Problem:** Assuming every visit has an appointment or every appointment becomes a visit breaks when patients cancel, reschedule, miss appointments, or walk in. Redundant bidirectional foreign keys risked inconsistent split-brain states.
- **Decision:**
  - **Canonical Foreign Key:** `patient_visits.appointment_id` is the single canonical database foreign key with a `UNIQUE KEY (appointment_id)` constraint and `ON DELETE RESTRICT`.
  - **Removal of Redundant Backlink:** `appointments.patient_visit_id` is **REMOVED** from the target schema. An appointment's attended visit is derived via the canonical relationship (`hasOne(PatientVisit::class, 'appointment_id')`).
  - **Cardinality:** Strictly **`Appointment (0..1) <---> (0..1) PatientVisit`**.
  - **Walk-in:** Creates `patient_visits` with `appointment_id = NULL`. Zero dummy appointments are generated.
  - **Cancellation & No-Show:** `appointments.status` updates to `cancelled` or `no_show`. **No `patient_visits` row is ever created.** Booking provenance is permanently preserved.
  - **Reschedule:** Updates `appointments.scheduled_at`. Visits remain untouched.
  - **Check-in Atomicity:** Checked in via atomic transaction: `appointments.status = 'checked_in'`, `patient_visits.appointment_id = appointment.id`.
  - **Tenant Integrity:** Service asserts that both records belong to the same `clinic_id` and `branch_id`.

---

### ADR-03: Attendance Records vs Signed Clinical Encounters ($1 \rightarrow 0..1$)
- **Problem:** Merging reception attendance with clinical doctor consultation notes confuses front-desk queueing with legal medical records. The earlier ERD mistakenly depicted a mandatory 1:1 relationship.
- **Decision:**
  - **Relationship:** Strictly **`patient_visits 1 → 0..1 clinical_encounters`**.
  - **Attendance Record (`patient_visits`):** Created at reception desk upon check-in. Holds queue token, check-in reason, triage vitals (BP, pulse, temp, weight), and intake fee status. Owned by reception/triage.
  - **Clinical Encounter (`clinical_encounters`):** Created in the consultation room ONLY when the doctor starts care. A patient may check in and leave without being seen (`left_without_being_seen`) or have a billing-only visit—in which case **zero clinical encounter is created**.
  - **Legal Sealing:** Once signed by the doctor (`finalized_at`), the encounter is cryptographically sealed (SHA-256 hash). It is permanently read-only. Any subsequent amendments must be authored as timestamped `ClinicalEncounterAddenda`.

---

### ADR-04: Independent Financial Ledger: Invoices, Items, Payments & Receipts
- **Problem:** Double subtraction of discounts in generated column formulas, coupled with conflating invoices with receipts, broke multi-installment payments, item audit trails, and package accounting.
- **Decision:**
  - **Exact Formulas:**
    $$\text{item\_gross} = \text{quantity} \times \text{unit\_price}$$
    $$\text{item\_net} = \text{item\_gross} - \text{item\_discount}$$
    $$\text{subtotal} = \sum \text{item\_net}$$
    $$\text{taxable\_amount} = \text{subtotal} - \text{invoice\_discount}$$
    $$\text{tax\_amount} = \text{taxable\_amount} \times (\text{tax\_rate} / 100)$$
    $$\text{total\_amount} = \text{taxable\_amount} + \text{tax\_amount}$$
    $$\text{balance\_amount} = \text{total\_amount} - \text{successful\_paid\_amount}$$
  - `discount_amount` is subtracted exactly once; zero double-discount defect.
  - `tax_amount` is configurable per clinic (NOT hardcoded to GST).
  - **Line Item Protection (`ON DELETE RESTRICT`):** `patient_billing_items` enforces `ON DELETE RESTRICT`. Invoiced line items cannot be deleted. Adjustments require credit notes (`patient_billing_refunds`) or whole-invoice soft-voiding (`status = 'voided'`).
  - **Receipts vs Payment Attempts:** `receipt_number` is strictly **NULL** for pending/failed payment attempts. Receipts (`REC-YYYY-000001`) are allocated uniquely inside the atomic transaction that confirms payment as `successful`. Idempotent payment gateway callbacks prevent duplicate receipts.
  - **Prepaid Packages:** Invoiced upfront; session credits are activated only upon confirmed payment.
  - **Invoice Voiding:** Invoices are soft-voided (`status = 'voided'`, `voided_at`, `voided_by`, `void_reason`); never hard-deleted.

---

### ADR-05: Treating Clinician Signing Authority vs Owner/Admin Governance
- **Problem:** Ambiguity between Owner/Admin authority and the treating doctor's medical independence.
- **Decision:**
  - **Treating Clinician Authority:** The examining doctor is the **sole clinical author and signer** of their consultation. The Clinic Owner/Admin CANNOT alter clinical notes, medical opinions, or diagnoses.
  - **Owner/Admin Governance Role:** Administrative and financial only:
    - *Voiding a Record Entered in Error:* Only Owner Mode can approve a clinician's request to void an erroneous encounter. The record is soft-voided, never erased.
    - *Financial Discounts & Refunds:* Clinicians have zero refund authority. Only Owner Mode can approve invoice fee waivers or issue financial refunds.

---

### ADR-06: Doctor Reassignment vs Historical Clinical Authorship
- **Problem:** Changing a patient's default doctor risks rewriting historical records and violating medico-legal compliance.
- **Decision:**
  - **Default Doctor (`patients.current_practitioner_id`):** Represents ongoing primary care responsibility. Reassignment is an administrative event governed by Owner Mode.
  - **Historical Authorship Immutability:** Historical visits (`patient_visits.doctor_id`), completed consultations (`clinical_encounters.doctor_id`), and past prescriptions **permanently retain the doctor who authored them**. Changing a default doctor has zero retrospective impact on past clinical records.

---

### ADR-07: Specific Permissions: Reception, Billing & Nursing
- **Problem:** Overlapping roles and ambiguous access to package sales, payments, document uploads, and clinical records.
- **Decision:**
  - **Receptionist:** Can collect intake consultation fees and record triage vitals. Package selling is NOT available by default; requires explicit `billing.create`. Has `physio_operational` read access to see package session counters (`4/10 completed`) and dental lab status, but zero access to doctor's clinical SOAP narrative. Can upload administrative ID/insurance documents.
  - **Billing Staff:** Full authority over package sales, invoices, payment collections, and receipt reprinting. Zero access to doctor clinical notes. Can upload financial undertakings and payment proofs.
  - **Nurse / Assistant:** Can record triage vitals and operate in "Assistant Mode" to chart teeth during dental exams. Permitted to upload clinical scans, X-rays, and lab reports. Zero financial collection authority. Cannot sign or finalize consultations.

---

### ADR-08: Multi-Branch Security: Safety Profile vs Detailed Clinical Notes
- **Problem:** Preventing cross-branch queue clutter while ensuring patient safety during cross-branch clinic visits.
- **Decision:**
  - **Single Master Safety Profile:** Allergies, chronic illnesses, and historical dental charts are visible across all clinic branches to ensure patient safety when attending a different location.
  - **Branch Queue & Ledger Isolation:** Staff only see the daily queue and cash register of the branch where they have active placement (`membership_branch_access`). Branch A staff cannot view Branch B's waiting room or cash drawer.
  - **Detailed Clinical Notes:** Access to detailed private SOAP notes from another branch is permission-gated, clinical-role restricted, and requires active patient attendance at that branch.
  - **Audit Logging:** Every cross-branch chart inspection is automatically logged in `patient_clinical_access_audits`.

---

### ADR-09: Patient and Financial Record Deletion Protection (`ON DELETE RESTRICT`)
- **Problem:** Proposed SQL drafts previously used `ON DELETE CASCADE`, which poses catastrophic risk of silent cascade deletion of medical and financial records.
- **Decision:**
  - All foreign keys on clinical encounters, patient visits, invoices, billing items, and payments enforce **`ON DELETE RESTRICT`**.
  - A patient with existing visits, encounters, or invoices **CANNOT be hard-deleted** at the database engine level.
  - **Dependent Profile Cascade Justification:** `patient_profiles.patient_id` uses `ON DELETE CASCADE` because it is an exact 1:1 dependent demographic extension. This cascade is safe because parent `patients` is protected by `ON DELETE RESTRICT` on all visits, encounters, cases, and invoices. An unreferenced draft patient with zero clinical/financial data cleans up cleanly without orphan rows.
  - Patient departure, demise, or duplicates are managed strictly via **governed soft-states**:
    - Demise: Handled via `RecordDeath` action (`status = 'deceased'`, `date_of_death`).
    - Duplicate: Handled via `patient.merge` (`status = 'merged'`, `merged_into_patient_id`).
    - Archival: Handled via `DeletionGovernance` approval (`status = 'inactive'`).

---

### ADR-10: Complete Relationship Diagrams & Operational Lifecycle Walkthroughs
- **Problem:** Abstract text specifications can be misinterpreted during frontend and backend implementation.
- **Decision:** Detailed Mermaid sequence diagrams, statecharts, and concrete step-by-step walkthroughs have been embedded into Phase 4:
  1. *Dental Multi-Stage Treatment Lifecycle:* Walk-in $\rightarrow$ Triage $\rightarrow$ Case Opened $\rightarrow$ Plan Accepted $\rightarrow$ Multi-visit RCT $\rightarrow$ Lab Order $\rightarrow$ Cementation $\rightarrow$ Recall.
  2. *Physiotherapy Rehabilitation Lifecycle:* Appointment Booking $\rightarrow$ Assessment & Body Map $\rightarrow$ Package Purchase $\rightarrow$ SOAP Session Punching $\rightarrow$ Reassessment $\rightarrow$ Discharge Summary.
  3. *Walk-in vs Booked Appointment Flow:* Side-by-side comparison of arrival without booking vs scheduled calendar check-in.
  4. *Repeat Visit Flow:* Fast phone lookup, retaining permanent UHID, linking to existing Active Case.
  5. *Multiple Concurrent Active Cases:* Same patient running simultaneous Dental Orthodontics and Physio Cervical Rehab under separate clinicians with zero data cross-contamination.

---

## 3. Owner Policy Decision Register (Approved: 2026-10-30)

The following three core operational and governance policies have received **formal Product Owner approval**. They represent locked architectural mandates and supersede all previous speculative options.

### Summary & Traceability Matrix

| Decision ID | Policy Title | Approved Option | Approval Date | Core Mandate Statement | Impacted Documentation | Implementation Dependency |
| :--- | :--- | :---: | :---: | :--- | :--- | :--- |
| **OPD-01** | **Doctor Financial Visibility** | **OPTION A (OWNER-APPROVED)** | 2026-10-30 | Doctors may view only their own procedure fees and agreed estimates. Doctors must not receive unrestricted access to the clinic-wide financial ledger. Clinic collections, balances, reports, and payments require explicit billing permissions. Enforce via server-side authorization. | `04`, `08`, `09`, `10`, `11`, `12`, `13` | Phase D / Phase G (`BillingPolicy::viewEncounterFees`, Patient 360 Ledger scoping) |
| **OPD-02** | **Receptionist Package Selling** | **OPTION A (OWNER-APPROVED)** | 2026-10-30 | Package selling is NOT automatically available to every receptionist. A receptionist may sell/create a package only when explicitly granted `billing.create`. Package creation/sale must be checked server-side. Do not grant by default. Preserve distinction between registration and financial operations. | `04`, `09`, `10`, `11`, `12`, `13` | Phase B / Phase C (`SessionPackageController`, `PaymentService`) |
| **OPD-03** | **Cross-Branch Clinical Notes** | **OPTION A (OWNER-APPROVED)** | 2026-10-30 | A doctor receives clinical-note access in the context of an active attendance. Access to notes from another branch must be permission-controlled and audited. Do not grant unrestricted access. Master safety profile is visible cross-branch, but private notes require attendance context and write to `patient_clinical_access_audits`. | `04`, `08`, `09`, `10`, `11`, `12`, `13` | Phase A (`patient_clinical_access_audits` schema), Phase B/D (`PatientPolicy::viewEncounter`) |

---

### Detailed Policy Specifications

#### OPD-01: Doctor Financial Visibility — OPTION A (APPROVED)
- **Problem Statement:** Historical systems frequently give all clinical practitioners default access to the entire clinic billing module, exposing total practice turnover, cash collection summaries, peer doctor revenues, and third-party accounting data.
- **Approved Policy Mandate:**
  1. Doctors may view **only their own procedure fees and agreed estimates** associated with their authored clinical consultations or assigned active cases.
  2. Doctors must **NOT** receive unrestricted access to the clinic-wide financial ledger.
  3. Clinic-wide collections, outstanding patient balances across other providers, practice revenue reports, payment method summaries, and cash drawer registers remain strictly restricted by explicit billing permissions (`billing.view_all`, `billing.manage`).
  4. Clinical access (`clinical.edit`, `clinical.sign`) does **NOT** automatically grant financial access.
  5. Visibility boundaries must be enforced through **explicit server-side authorization** (Eloquent query scoping and policy gates), never merely by hiding UI tabs or components.

#### OPD-02: Receptionist Package Selling — OPTION A (APPROVED)
- **Problem Statement:** Conflating front-desk check-in duties with commercial package sales risks unapproved discounting, cash handling discrepancies, and unauthorized credit allocation.
- **Approved Policy Mandate:**
  1. Package selling is **NOT automatically available to every receptionist**.
  2. A receptionist may sell/create a therapy session package or dental bundle **ONLY when explicitly granted `billing.create`**.
  3. Package creation and commercial sale transactions must be **validated server-side** on submission.
  4. The `billing.create` permission must **NOT** be assigned by default to the receptionist role.
  5. The system strictly preserves the architectural distinction between:
     - *Patient Registration & Session Check-in:* Managed via `queue.manage` and `physio_operational` (viewing session punch balances like `"4/10 completed"`).
     - *Financial & Package Transactions:* Governed by `billing.create` and `payment.collect`.

#### OPD-03: Cross-Branch Clinical Notes Access & Auditing — OPTION A (APPROVED)
- **Problem Statement:** Multi-branch healthcare organizations require immediate access to critical patient allergies and systemic conditions at any branch, but unrestricted clinician browsing of private psychiatric, dental, or physiotherapy consultation notes across all clinic branches violates patient confidentiality and data minimization principles.
- **Approved Policy Mandate:**
  1. A doctor receives clinical-note access **in the context of an active attendance** at the branch where the doctor is currently practicing.
  2. Access to clinical notes originating from another branch must be **strictly permission-controlled** (`clinical.notes.cross_branch`) and **forensically audited**.
  3. All doctors must **NOT** be granted unrestricted browsing access to every branch's complete historical clinical notes.
  4. The platform strictly separates cross-branch data into two distinct tiers:
     - *Tier 1 (Master Safety Profile):* Verified drug allergies, chronic conditions, cardiac/bleeding alerts, and blood group are globally accessible across all branches for acute patient safety.
     - *Tier 3 (Detailed Clinical Notes):* Doctor SOAP narratives, progress notes, and diagnostic interpretations from other branches remain permission-gated and require active attendance.
  5. **Mandatory Forensic Audit:** Every cross-branch access event must be recorded in `patient_clinical_access_audits` capturing:
     - Actor User ID (`actor_user_id`)
     - Patient ID (`patient_id`)
     - Source Branch ID (`source_branch_id`)
     - Accessing Branch ID (`accessing_branch_id`)
     - Resource Type and ID (`resource_type`, `resource_id`)
     - Active Attendance Visit ID (`attendance_visit_id`)
     - Access Reason / Context (`access_reason`: `active_attendance`, `emergency_care`, `referral_review`)
     - Timestamp (`accessed_at`), IP Address, and User Agent.
