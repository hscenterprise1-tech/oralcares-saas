# OralCares — Patient Management Product Rebuild
## Phase 9: Role-Based Access Control, Governance & Security Matrix

**Document:** `docs/patient-management/09-role-permission-matrix.md`  
**Status:** Approved Security Specification (Revised & Fully Synchronized)  
**Governing Principles:**  
1. Owner/Admin is the **same combined role** (`owner`/`admin`).  
2. Owner/Admin controls clinic operations, staff, configuration, financial governance, and administrative approvals.  
3. The treating clinician is the **sole clinical author and signer** of their consultation. Owner/Admin cannot rewrite clinical notes, diagnoses, medical opinions, or signed records.  
4. Owner/Admin may approve a clinician-requested void, subject to forensic audit and soft-void rules. Clinical corrections must use timestamped addenda.  
5. Cross-branch access strictly follows explicit staff branch placement, distinguishing between global patient safety data and permission-gated clinical notes.

---

## 1. Clinic Roles in Patient Management

| Role Identifier | Role Title | Core Operational Domain & Boundaries |
| :--- | :--- | :--- |
| `owner` / `admin` | **Owner / Clinic Admin** | Full executive, operational, and financial governance. Manages staff, branch placements, fee structures, financial write-offs, and administrative approvals. **Zero clinical override.** |
| `doctor` / `practitioner` | **Doctor / Dentist / Physiotherapist** | Clinical care delivery. Sole author and signer of diagnoses, clinical examinations, SOAP notes, tooth charts, prescriptions, and treatment plans. |
| `receptionist` | **Reception / Front Desk** | Front office intake. Patient registration, quick check-in, calendar bookings, queue calling, intake triage vitals, and collection of desk fees. |
| `billing_staff` | **Billing / Cashier** | Financial desk. Invoice raising, package sales, payment collections, receipt reprinting, installment schedules, and refund request submissions. |
| `nurse` / `assistant` | **Clinical Assistant / Nurse** | Patient triage, vital signs measurement, assistant charting mode, clinical document/scan uploads, and safety alert verification. |

---

## 2. Granular Permission & Feature Access Matrix

| Feature / Action | Associated Permission Key | Owner / Admin | Doctor / Therapist | Receptionist | Billing Staff | Nurse / Assistant |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| **Directory: View Patient List** | `patient.view` | **Full** | **Assigned Only / All** | **Full** | **Full** | **Full** |
| **Directory: View Patient Phone** | `patient.contact.view` | **Unmasked** | **Masked (`•••• 1234`)** | **Unmasked** | **Unmasked** | **Masked** |
| **Register New Patient Profile** | `patient.create` | **Yes** | **Yes** | **Yes** | No | No |
| **Edit Permanent Demographics** | `patient.edit` | **Yes** | No | **Yes** | No | No |
| **Reassign Default Doctor** | Governed Action (`Owner Mode`) | **Yes (Direct)** | Request Only | Request Only | No | No |
| **Reception Intake: Check-in Today**| `queue.manage` | **Yes** | No | **Yes** | No | No |
| **Clinical Consultation: Start / Edit**| `clinical.edit` | No (Unless examining) | **Treating Doctor Only**| No | No | Read-only |
| **Clinical Consultation: Sign Record** | `clinical.sign` | No (Unless examining) | **Sole Medical Signer**| No | No | No |
| **Clinical Consultation: Add Addendum**| `clinical.edit` | No | **Authoring Doctor** | No | No | No |
| **Void Clinical Encounter (Error)** | Governed Action (`Owner Mode`) | **Yes (Approval)** | Request Only | No | No | No |
| **Dental Odontogram: Chart Teeth** | `dental.chart` | Read-only (Unless doc) | **Yes** | Read-only | No | Assist Mode |
| **Dental Lab: Order & Track** | `dental.lab` | **Yes** | **Yes** | Status Only | No | Status Only |
| **Physio: Assessment & Body Map** | `physio.assess` | Read-only (Unless doc) | **Yes** | No | No | Assist Mode |
| **Physio: View Package Balance** | `physio.operational` | **Yes** | **Yes** | **Yes (Counters Only)** | **Yes** | **Yes** |
| **Sell Physio Session Package** | `billing.create` | **Yes** | Can Recommend | **No by default** (Requires explicit `billing.create`) | **Full Authority** | No |
| **Issue Electronic Prescription** | `prescriptions.manage` | Read-only (Unless doc) | **Yes** | No | No | No |
| **Upload Administrative Docs (ID)** | `documents.manage` | **Yes** | **Yes** | **Yes** | **Yes** | **Yes** |
| **Upload Clinical Scans & X-Rays** | `documents.manage` | **Yes** | **Yes** | No | No | **Yes** |
| **Record Triage Vitals (BP, Temp)**| `clinical.triage` | **Yes** | **Yes** | **Yes** | No | **Yes** |
| **View Patient Financial Ledger** | `billing.view` | **Full Ledger** | **Own Fees Only** (`billing.view_own_fees`) | Bills & Receipts | **Full Ledger** | No |
| **View Cross-Branch Clinical Notes**| `clinical.notes.cross_branch` | **Full (Audited)** | **Active Attendance Only (Audited)** | No | No | No |
| **Apply Invoice Discount** | `billing.discount` | **Yes** | No | With Limit | **Yes** | No |
| **Collect In-Clinic Payment** | `payment.collect` | **Yes** | No | **Yes** | **Yes** | No |
| **Process Payment Refunds** | Governed Action (`Owner Mode`) | **Yes** | No | No | Request Only | No |
| **Merge Duplicate Patient Records** | Governed Action (`patient.merge`) | **Yes** | No | Request Only | No | No |
| **Record Patient Death** | Governed Action (`patient.death`) | **Yes** | Request Only | Request Only | No | No |

---

## 3. Treating Clinician Signing Authority vs Owner/Admin Governance

### 3.1 Medical Authorship & Digital Sealing
- **Attending Clinician:** The doctor who examined the patient is the **exclusive author and signer** of the clinical encounter (`clinical_encounters`).
- When the doctor clicks "Finalize & Sign", a digital signature payload is computed:
  $$\text{SHA-256}(\text{Encounter ID} + \text{Doctor ID} + \text{Timestamp} + \text{SOAP Narrative} + \text{Diagnoses})$$
- The encounter status becomes `completed`, `finalized_at` is set, and the record is permanently write-protected.
- **Owner/Admin Boundary:** The Clinic Owner/Admin CANNOT alter clinical notes, medical opinions, or diagnoses. The doctor's professional medical record is legally sealed.

### 3.2 Owner/Admin Administrative Governance
- **Voiding a Record Entered in Error:** If a consultation was mistakenly created under the wrong patient's chart, the attending clinician submits a `VoidEncounterRequest`. Only Owner Mode can approve this. The record is flagged (`voided_at`, `voided_by`, `void_reason`), removed from clinical summaries, but **preserved in the forensic audit ledger**.
- **Post-Finalization Amendments:** Clinicians can only amend signed notes via timestamped `ClinicalEncounterAddenda`.
- **Financial Write-Offs & Refunds:** While clinicians can recommend fee adjustments, only Owner/Admin or designated managers with `Owner Mode` can issue money refunds or write off outstanding balances.

---

## 4. Doctor Reassignment vs Historical Clinical Authorship

A critical medico-legal safeguard in OralCares:
1. **Permanent Default Doctor:** Stored in `patients.current_practitioner_id`. Indicates the patient's primary care practitioner.
2. **Reassignment Governance:** Reassigning a patient's default doctor is an administrative event:
   - Initiated in Patient Edit or Patient 360 Header.
   - Enforces `Owner Mode` authorization.
   - Updates `patients.current_practitioner_id` and writes an audit event to `security_audit_logs`.
3. **Historical Authorship Immutability:**
   - Previous encounters (`clinical_encounters.doctor_id`), past visits (`patient_visits.doctor_id`), and historical prescriptions **permanently retain the doctor who authored them**.
   - If Dr. A performed a surgery in 2024, and Dr. B becomes the default doctor in 2026, the 2024 surgery record permanently attributes Dr. A. Retrospective doctor overwriting is impossible.

---

## 5. Specific Permissions: Reception, Billing, and Nursing

### 5.1 Package Sales & Payments (Governed by OPD-02)
- **Receptionist:** Can collect standard intake consultation fees.
  - **OPD-02 (Owner-Approved):** Package selling is **NOT automatically available to every receptionist**.
  - A receptionist may sell/create a therapy session package or dental bundle **ONLY when explicitly granted `billing.create`**.
  - Package creation/sale must be validated server-side on submission.
  - The `billing.create` permission is **NOT** assigned by default to receptionists.
  - This preserves the strict distinction between:
    - Patient registration and session punch verification (`physio_operational` — e.g. viewing `"4/10 completed"`).
    - Commercial package selling and financial transactions (`billing.create`).
- **Billing Staff:** Full authority over package sales, installment schedules, tax invoices, and payment collections. Can process refunds **only with Owner Mode authorization**.
- **Nurse / Assistant:** Zero financial permissions. Cannot collect cash, create bills, or sell packages.

### 5.2 Doctor Financial Visibility (Governed by OPD-01)
- **Treating Doctors / Practitioners:**
  - **OPD-01 (Owner-Approved):** Doctors may view **only their own procedure fees and agreed estimates** associated with their clinical encounters or assigned active cases (`billing.view_own_fees`).
  - Doctors must **NOT** receive unrestricted access to the clinic-wide financial ledger.
  - Clinic-wide collections, outstanding balances across other providers, turnover reports, payment records, and financial summaries remain restricted by explicit billing permissions (`billing.view_all`).
  - Clinical access (`clinical.edit`, `clinical.sign`) does **NOT** automatically grant financial ledger access.
  - Visibility boundaries are enforced through **explicit server-side query scoping and authorization policies**, never merely by hidden UI tabs.

### 5.3 Document Uploads & Imaging
- **Receptionist:** Permitted to upload administrative attachments (Govt Photo ID, Insurance Card, Employer Referral Letter).
- **Nurse / Clinical Assistant:** Permitted to upload clinical attachments (Pre-op Photos, Lab Test PDF Reports, Intra-oral Camera Scans, Digital Periapical X-Rays).
- **Billing Staff:** Permitted to upload payment bank slips, claim approval letters, and financial undertakings.

### 5.4 Clinical Records & Vitals
- **Receptionist & Nurse:** Can record intake triage vitals (`intake_vitals` on `patient_visits`: BP, Pulse, SpO2, Temperature, Weight).
- **Receptionist:** Has **`physio_operational`** read permission to see therapy package balances (`"4/10 sessions completed"`) and dental lab order status (`"Crown received"`), but zero access to doctor's clinical SOAP narrative.
- **Nurse / Assistant:** Has **Assistant Mode** on the dental chart to record teeth called out by the dentist, but CANNOT finalize or digitally sign the encounter.

---

## 6. Multi-Branch Security: Safety Profile vs Detailed Clinical Notes (Governed by OPD-03)

To balance acute clinical safety with patient confidentiality and branch operational integrity:

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│ MULTI-BRANCH DATA ACCESSIBILITY TIERS                                                            │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ TIER 1: GLOBAL PATIENT SAFETY PROFILE (Accessible across all clinic branches)                    │
│ • Verified Drug Allergies (Penicillin, NSAIDs, Latex)                                            │
│ • Systemic Medical Conditions (Diabetic, Hypertensive, Cardiac Stent, Bleeding Disorder)         │
│ • Active High-Priority Safety Alerts                                                             │
│ • Blood Group & Emergency Contact                                                                │
│ -> Rationale: Acute clinical emergency protection whenever patient visits a different branch.   │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ TIER 2: BRANCH-SPECIFIC OPERATIONAL DATA (Strictly isolated by branch placement)                 │
│ • Daily Waiting Room Queue & Calling Board                                                       │
│ • Daily Branch Calendar Appointments                                                             │
│ • Branch Cash Drawers, Daily Closing & Cash Registers                                           │
│ -> Rationale: Zero queue clutter; strict branch-level financial accountability.                  │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ TIER 3: DETAILED CLINICAL NOTES & SIGNED ENCOUNTER NARRATIVE (OPD-03 Permission-Gated & Audited) │
│ • Private Doctor SOAP Consultation Notes & Examination Findings from Other Branches              │
│ • Detailed Diagnostic Case Histories from Other Branches                                         │
│ -> Rationale: Governed by OPD-03. Access is granted ONLY in the context of an active patient     │
│    attendance at the accessing branch, requires 'clinical.notes.cross_branch' permission,        │
│    and MUST write a forensic audit record to 'patient_clinical_access_audits'.                   │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Server-Side Policy Enforcement Patterns

```php
// app/Policies/ClinicalEncounterPolicy.php
namespace App\Policies;

use App\Models\User;
use App\Models\ClinicalEncounter;
use App\Models\PatientClinicalAccessAudit;
use App\Models\PatientVisit;

class ClinicalEncounterPolicy
{
    /**
     * Determine whether the user can view detailed consultation notes.
     * Enforces OPD-03: Active attendance context + explicit permission + forensic audit log.
     */
    public function viewNotes(User $user, ClinicalEncounter $encounter): bool
    {
        // 1. Strict Tenant Boundary
        if ($encounter->clinic_id !== $user->active_clinic_id) {
            return false;
        }

        // 2. Owner/Admin has global tenant review rights
        if ($user->hasRole('owner') || $user->hasRole('admin')) {
            return true;
        }

        // 3. Same-branch access: Allowed if doctor has active shift placement at encounter branch
        if ($encounter->branch_id === $user->active_branch_id) {
            return $user->hasPermissionTo('clinical.view');
        }

        // 4. Cross-Branch Access (OPD-03 Mandate):
        // Doctor must possess explicit cross-branch permission
        if (! $user->hasPermissionTo('clinical.notes.cross_branch')) {
            return false;
        }

        // Must have an active attendance visit for this patient at the doctor's CURRENT branch today
        $hasActiveAttendance = PatientVisit::where('patient_id', $encounter->patient_id)
            ->where('branch_id', $user->active_branch_id)
            ->whereDate('visit_date', today())
            ->whereIn('visit_status', ['registered', 'waiting', 'in_progress'])
            ->exists();

        if (! $hasActiveAttendance) {
            return false; // Prohibits remote browsing without active physical attendance
        }

        // 5. Mandatory Forensic Audit Log (OPD-03)
        PatientClinicalAccessAudit::create([
            'clinic_id'           => $encounter->clinic_id,
            'actor_user_id'       => $user->id,
            'patient_id'          => $encounter->patient_id,
            'source_branch_id'    => $encounter->branch_id,
            'accessing_branch_id' => $user->active_branch_id,
            'resource_type'       => 'clinical_encounter',
            'resource_id'         => $encounter->id,
            'access_reason'       => 'active_attendance',
            'ip_address'          => request()->ip(),
            'user_agent'          => request()->userAgent(),
            'accessed_at'         => now(),
        ]);

        return true;
    }
}
```

```php
// app/Policies/BillingPolicy.php
namespace App\Policies;

use App\Models\User;
use App\Models\PatientBilling;
use Illuminate\Database\Eloquent\Builder;

class BillingPolicy
{
    /**
     * Enforces OPD-01: Doctors can only view their own procedure fees unless granted billing.view_all.
     */
    public function view(User $user, PatientBilling $billing): bool
    {
        if ($billing->clinic_id !== $user->active_clinic_id) {
            return false;
        }

        if ($user->hasPermissionTo('billing.view_all') || $user->hasRole('owner') || $user->hasRole('admin')) {
            return true;
        }

        if ($user->hasPermissionTo('billing.view_own_fees')) {
            // Can only view invoice if it contains line items authored by this practitioner
            return $billing->items()
                ->where('item_reference_id', $user->employee_id)
                ->exists();
        }

        return false;
    }

    /**
     * Enforces OPD-02: Receptionist cannot create packages unless explicitly granted billing.create.
     */
    public function createPackage(User $user): bool
    {
        return $user->hasPermissionTo('billing.create');
    }
}
```
