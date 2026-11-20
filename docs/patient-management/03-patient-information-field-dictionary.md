# OralCares — Patient Management Product Rebuild
## Phase 3: Patient Information Field Dictionary

**Document:** `docs/patient-management/03-patient-information-field-dictionary.md`  
**Status:** Approved Specification  
**Purpose:** Single canonical source of truth for all patient-related fields across the OralCares platform, classifying them into explicit architectural tiers to eliminate data duplication and improper field scoping.

---

## 1. Architectural Field Classification Tiers

```
┌────────────────────────────────────────────────────────────────────────┐
│ TIER 1: PERMANENT PATIENT PROFILE & DEMOGRAPHICS                       │
│ (Lifetime Patient Identity, Primary Contact, Address, Legal Guardian)  │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ TIER 2: PERMANENT CLINICAL SAFETY & BASELINE HEALTH                    │
│ (Allergies, Chronic Illnesses, Safety Alerts, Blood Group, Habits)     │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ TIER 3: CASE / EPISODE-OF-CARE LEVEL DATA                              │
│ (Clinical Episode, Primary Diagnosis, Specialty, Case Status, Plans)   │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ TIER 4: VISIT / ENCOUNTER LEVEL DATA                                   │
│ (Attendance, Queue Ticket, Treating Doctor, Vitals, SOAP, Procedures) │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│ TIER 5: FINANCIAL & BILLING LEDGER DATA                                │
│ (Bills, Payments, Receipts, Package Credits, Outstanding Balance)     │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Tier 1: Permanent Patient Profile & Demographics

These fields belong to the patient's lifetime identity inside the clinic. They are managed via **Patient Create** and **Patient Edit**, and never change on individual visits.

| Field Name | DB Table & Column | Data Type | Validation Rules | Description & Clinical Purpose | Visibility & Role Access |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `uhid` | `patients.uhid` | `VARCHAR(32)` | Unique per clinic, uppercase, alphanumeric (`OC-00000001`). Immutable once set. | Permanent Unique Healthcare Identifier. Lifetime record anchor. | Visible to all roles. |
| `first_name` | `patients.first_name` | `VARCHAR(100)` | Required, min 2 chars, max 100, regex alphabetic + spaces. | Patient's legal given name. | Visible to all roles. |
| `middle_name` | `patients.middle_name` | `VARCHAR(100)` | Optional, max 100. | Patient's middle / patronymic name. | Visible to all roles. |
| `last_name` | `patients.last_name` | `VARCHAR(100)` | Required, min 1 char, max 100. | Family / surname. Used for formal communication and directory indexing. | Visible to all roles. |
| `name` | `patients.name` | `VARCHAR(255)` | Maintained automatically by model boot listener. | Auto-concatenated full name for fast prefix and full-text searching. | Internal search & directory. |
| `date_of_birth` | `patients.date_of_birth` | `DATE` | Optional if age provided; `before_or_equal:today`. | Canonical source of age calculation. Essential for pediatric/geriatric dosing. | Visible to all roles. |
| `age` | `patients.age` | `INTEGER` | Required if DOB unknown; 0 to 125. | Maintained from DOB if present; stores recorded age if DOB is unknown. | Visible to all roles. |
| `gender` | `patients.gender` | `ENUM` | Required, `in:male,female,other`. | Biological/clinical gender for clinical risk stratification. | Visible to all roles. |
| `phone` | `patients.phone` | `VARCHAR(20)` | Required, 10 digits standard Indian mobile, indexed. | Primary contact number. Primary key for duplicate detection and SMS/WhatsApp. | Masked for doctors/staff without contact permission; full for Reception/Billing/Owner. |
| `email` | `patients.email` | `VARCHAR(150)` | Optional, valid email format, lowercased and trimmed. | Primary email for digital receipts, estimates, and lab reports. | Masked based on contact permission. |
| `blood_group` | `patients.blood_group` | `ENUM` | Optional, `in:A+,A-,B+,B-,AB+,AB-,O+,O-,unknown`. | Blood group for surgical, trauma, and emergency preparedness. | Visible to all roles. |
| `address` | `patient_profiles.address` | `TEXT` | Optional, max 500 chars. | Residential street address. | Reception, Billing, Owner. |
| `locality` | `patient_profiles.locality` | `VARCHAR(150)`| Optional, max 150 chars. | Sub-district / neighborhood / area. | Reception, Billing, Owner. |
| `city` | `patient_profiles.city` | `VARCHAR(100)` | Optional, max 100 chars. | Municipality / city of residence. | Reception, Billing, Owner. |
| `state` | `patient_profiles.state` | `VARCHAR(100)` | Optional, max 100 chars. | State / Province. | Reception, Billing, Owner. |
| `postal_code` | `patient_profiles.postal_code`| `VARCHAR(20)` | Optional, 6-digit numeric PIN for India. Canonical column (retires `pincode`). | Postal code for demographic catchment reporting. | Reception, Billing, Owner. |
| `country` | `patient_profiles.country` | `VARCHAR(100)` | Optional, default "India". | Country of residence. | Reception, Billing, Owner. |
| `occupation` | `patient_profiles.occupation` | `VARCHAR(100)` | Optional, max 100 chars. | Ergonomic/occupational risk indicator (e.g. IT desk job, manual labour). | Clinical, Reception, Owner. |
| `nationality` | `patient_profiles.nationality`| `VARCHAR(100)` | Optional, default "Indian". | Legal nationality for international patient reporting. | Reception, Owner. |
| `emergency_name` | `patient_profiles.emergency_contact_name` | `VARCHAR(100)` | Optional, max 100 chars. | Name of family member, kin, or legal guardian. | All roles. |
| `emergency_phone`| `patient_profiles.emergency_contact_phone`| `VARCHAR(20)` | Optional, 10-digit mobile format. | Phone number to contact in case of syncope, allergy shock, or crisis. | All roles. |
| `referral_source`| `patient_profiles.referral_source` | `VARCHAR(100)`| Optional, `in:walk_in,google,friend,doctor_referral,social_media`. | Patient acquisition source for clinic marketing analytics. | Reception, Owner. |
| `primary_branch_id`| `patients.primary_branch_id` | `BIGINT FK` | Optional, belongs to `branches.id`. | Default home branch where the patient was originally registered. | All roles. |
| `current_practitioner_id` | `patients.current_practitioner_id` | `BIGINT FK` | Belongs to `clinic_employees.id`. Locked by Owner Mode. | Permanently assigned primary doctor. Governed: change requires Owner approval. | All roles. |
| `status` | `patients.status` | `ENUM` | `in:active,inactive,deceased,merged`. | Operational patient status. Governed actions for deceased (`RecordDeath`) and merge. | All roles. |

---

## 3. Tier 2: Permanent Clinical Safety & Baseline Health

Safety data is maintained continuously across all patient interactions. Any update creates an immutable audit stamp.

| Field Name | DB Table & Column | Data Type | Validation Rules | Description & Clinical Purpose | Visibility & Role Access |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `allergies` | `patient_clinical_entries` (`kind=allergy`) | Normalized Rows | Required category, drug/substance name, severity (`mild,moderate,severe`). | Known drug allergies (e.g. Penicillin, Sulfa, NSAIDs) or materials (Latex). | Prominently highlighted to ALL roles. Safety alert trigger. |
| `chronic_conditions`| `patient_clinical_entries` (`kind=condition`) | Normalized Rows | Condition label, ICD/System key, severity, onset date. | Systemic illnesses: Diabetes Mellitus, Hypertension, Ischemic Heart Disease, Asthma, Epilepsy. | Clinical, Reception (high-level flag), Owner. |
| `current_medications`| `patient_clinical_entries` (`kind=medication`)| Normalized Rows | Drug name, dosage, frequency. | Drugs taken routinely (e.g. Blood thinners: Aspirin, Warfarin; Insulin, Steroids). | Clinical, Owner. |
| `blood_disorder` | `patient_medical_histories.blood_disorder` | `BOOLEAN` | Default false. | Bleeding diathesis, hemophilia, low platelet count. Critical for extractions/surgery. | Prominently highlighted (Red safety badge). |
| `pregnancy_status`| `patient_medical_histories.pregnancy_status` | `BOOLEAN` | Default false. Valid only for females. | Trimester indicator. Blocks elective dental X-rays and contra-indicated drugs. | Prominently highlighted. |
| `cardiac_condition`| `patient_medical_histories.cardiac_condition` | `BOOLEAN` | Default false. | History of valve replacement, stent, bypass, pacemaker. Pre-medication antibiotic protocol. | Prominently highlighted. |
| `habits` | `patient_medical_histories.tobacco_use`, `alcohol_use` | `BOOLEAN` | Default false. | Tobacco chewing/smoking (oral submucous fibrosis risk, implant failure) & alcohol. | Clinical, Owner. |
| `safety_alerts` | `patient_alerts` | Table Rows | Title, severity (`warning,danger`), trigger source. Resolved by clinician only. | Active sticky warning banner rendered atop Patient 360, Reception Intake, and Calendar. | Visible to all roles. |
| `safety_notes` | `patient_medical_histories.notes` | `TEXT` | Max 1000 chars. | Free-text clinical precautions written by treating doctor. | Clinical, Owner. |

---

## 4. Tier 3: Case / Episode-of-Care Level Data

A **Case** represents a distinct clinical condition, treatment goal, or rehabilitation episode. A patient may have multiple sequential or concurrent cases.

| Field Name | DB Table & Column | Data Type | Validation Rules | Description & Clinical Purpose | Visibility & Role Access |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `case_id` | `patient_cases.id` | `BIGINT PK` | Auto-increment primary key. | Unique internal identifier for the episode of care. | Internal. |
| `case_number` | `patient_cases.case_number`| `VARCHAR(32)` | Unique per clinic (`CASE-2026-000123`). | Human-readable case tracking code. | All roles. |
| `patient_id` | `patient_cases.patient_id` | `BIGINT FK` | Belongs to `patients.id`. Indexed. | The patient owning this clinical case. | All roles. |
| `branch_id` | `patient_cases.branch_id` | `BIGINT FK` | Belongs to `branches.id`. Indexed. | The clinic branch managing this case. | All roles. |
| `specialty` | `patient_cases.specialty` | `ENUM` | `in:dental,physiotherapy,maxillofacial,multispecialty`. | Specialty domain governing the clinical workspace layout. | All roles. |
| `title` | `patient_cases.title` | `VARCHAR(150)`| Required, max 150 chars. | High-level case title (e.g. "Upper Arch Orthodontic Realignment", "Right ACL Rehab"). | All roles. |
| `chief_complaint` | `patient_cases.chief_complaint` | `TEXT` | Required, initial presenting clinical complaint. | Patient's initial complaint that initiated this treatment episode. | Clinical, Reception, Owner. |
| `primary_diagnosis`| `patient_cases.primary_diagnosis`| `VARCHAR(255)`| Clinical diagnostic statement or ICD code. | Main diagnosis driving this episode of care. | Clinical, Owner. |
| `lead_practitioner_id` | `patient_cases.lead_practitioner_id` | `BIGINT FK` | Belongs to `clinic_employees.id`. | The doctor/therapist in charge of this overall case. | All roles. |
| `referring_doctor`| `patient_cases.referring_doctor`| `VARCHAR(150)`| Optional external or internal referring doctor. | Professional referral attribution. | Clinical, Reception, Owner. |
| `case_status` | `patient_cases.status` | `ENUM` | `in:active,on_hold,completed,abandoned,transferred`. | Current clinical status of this episode. | All roles. |
| `opened_at` | `patient_cases.opened_at` | `DATETIME` | Timestamp when the episode of care commenced. | Case commencement timestamp. | All roles. |
| `closed_at` | `patient_cases.closed_at` | `DATETIME` | Timestamp when treatment was completed or terminated. | Case completion timestamp. | All roles. |
| `discharge_summary`| `patient_cases.discharge_summary`| `TEXT` | Optional clinical closing summary. | Final evaluation, outcomes achieved, and discharge advice. | Clinical, Owner. |

---

## 5. Tier 4: Visit / Encounter Level Data

A **Visit** represents a single physical or virtual attendance event. It ties a patient and case to a specific appointment, queue ticket, and doctor consultation.

| Field Name | DB Table & Column | Data Type | Validation Rules | Description & Clinical Purpose | Visibility & Role Access |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `visit_id` | `patient_visits.id` | `BIGINT PK` | Primary key. | Unique visit record. | Internal. |
| `encounter_number`| `clinical_encounters.encounter_number`| `VARCHAR(32)`| Unique per clinic (`ENC-2026-004561`). | Clinical consultation document number. | All roles. |
| `case_id` | `patient_visits.case_id` | `BIGINT FK` | Nullable (links to `patient_cases.id`). | Links this attendance to its overarching treatment episode. | All roles. |
| `visit_date` | `patient_visits.visit_date` | `DATE` | Default today; indexed. | Calendar date of attendance. | All roles. |
| `doctor_id` | `patient_visits.doctor_id` | `BIGINT FK` | Belongs to `clinic_employees.id`. | Treating doctor for THIS specific visit (permanent historical attribution). | All roles. |
| `registration_type`| `patient_visits.registration_type`| `ENUM` | `in:new,repeat`. Computed by `classifyNext()`. | Attendance classification (first attended visit is New; return visits are Repeat). | All roles. |
| `visit_reason` | `patient_visits.visit_reason`| `VARCHAR(255)`| Required on intake. | Patient's stated reason for today's visit (e.g. "Severe toothache", "Knee exercise"). | All roles. |
| `queue_status` | `clinic_queue_items.status`| `ENUM` | `in:waiting,called,in_progress,completed,cancelled,no_show`. | Real-time queue state on the Front Desk board. | Front Desk, Doctor, Owner. |
| `intake_vitals` | `patient_visits.intake_vitals`| `JSON` | Blood Pressure (Systolic/Diastolic), Pulse, SpO2, Temperature, Weight. | Baseline physical measurements recorded at the front desk or nurse triage. | All roles. |
| `soap_subjective`| `clinical_encounters.chief_complaint` | `TEXT` | Doctor's consultation notes. | S in SOAP: Patient's subjective history, symptoms, pain severity today. | Clinical, Owner. |
| `soap_objective` | `clinical_encounters.examination` | `TEXT` | Clinical findings, palpation, tests. | O in SOAP: Intra-oral examination, goniometry, reflex tests. | Clinical, Owner. |
| `soap_assessment`| `clinical_encounters.history` | `TEXT` | Diagnostic synthesis. | A in SOAP: Impression, working diagnosis, tooth status. | Clinical, Owner. |
| `soap_plan` | `clinical_encounters.follow_up_note` | `TEXT` | Instructions and next steps. | P in SOAP: Treatment performed today, medication advice, next appointment. | Clinical, Owner. |
| `is_finalized` | `clinical_encounters.finalized_at`| `DATETIME` | Nullable timestamp. Set on digital signing. | Locks the consultation record against edits. Addenda required for corrections. | All roles. |
| `signature_hash` | `clinical_encounters.signature_hash` | `VARCHAR(64)` | SHA-256 hash of encounter contents + doctor key. | Legal non-repudiation cryptographic seal. | Audit, Owner. |

---

## 6. Tier 5: Financial & Billing Ledger Data

Financial data tracks check-in fees, procedure charges, packages, payments, and balances under strict transactional concurrency.

| Field Name | DB Table & Column | Data Type | Validation Rules | Description & Clinical Purpose | Visibility & Role Access |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `bill_number` | `patient_billings.bill_number`| `VARCHAR(32)` | Unique per branch (`INV-2026-000123`). | Canonical invoice number generated via `ClinicDocument::Invoice`. | Billing, Reception, Owner. |
| `total_amount` | `patient_billings.total_amount`| `DECIMAL(12,2)` | Non-negative currency figure. | Gross invoiced amount across procedures and fees. | Billing, Reception, Owner. |
| `discount_amount`| `patient_billings.discount_amount`| `DECIMAL(12,2)` | Requires `billing.discount` permission. | Approved promotional or professional fee discount. | Billing, Reception, Owner. |
| `paid_amount` | `patient_billings.paid_amount` | `DECIMAL(12,2)` | Maintained via payment transactions. | Total collected against this invoice. | Billing, Reception, Owner. |
| `balance_amount`| `patient_billings.balance_amount`| `DECIMAL(12,2)` | Virtual or stored: `total - discount - paid`. | Outstanding balance due from patient. | Billing, Reception, Owner. |
| `payment_method`| `patient_billing_payments.method` | `ENUM` | `in:cash,card,upi,bank_transfer,cheque`. (Gateway/Insurance disabled at desk). | Verified payment instrument used for collection. | Billing, Reception, Owner. |
| `receipt_number`| `patient_billing_payments.receipt_number`| `VARCHAR(32)`| Unique per clinic (`REC-2026-000890`). | Sequential thermal and A4 receipt number. | Billing, Reception, Owner. |
| `package_sessions_total` | `patient_session_packages.total_sessions` | `INTEGER` | $\ge 1$. | Total therapy sessions purchased in advance. | Billing, Reception, Clinical, Owner. |
| `package_sessions_used` | `patient_session_packages.used_sessions` | `INTEGER` | Incremented under row lock on consultation signing. | Sessions consumed to date. | Billing, Reception, Clinical, Owner. |

---

## 7. Explicit Exclusions & Governance Rules

The following fields and workflows are **EXPLICITLY EXCLUDED** from the Permanent Patient Profile by product mandate and must never be reintroduced:

1. **Patient Photo / Avatar Upload:** Not part of core clinical or reception requirements; avatar tiles use high-contrast initials gradient boxes.
2. **Alternate Mobile Number:** Eliminated to maintain phone uniqueness and prevent split communication histories.
3. **Separate WhatsApp Number:** The patient's primary `phone` is the single verified WhatsApp and SMS delivery target.
4. **Marital Status:** Non-clinical personal demographic; excluded.
5. **Preferred Language & Preferred Contact Method:** Removed to avoid complex multi-channel routing overhead; standard clinic notification policies apply.
6. **Consent Status / Source Section in Profile:** Consent records are legal transaction documents (`ConsentRecord`) signed for specific procedures or data processing, not a static checkbox on a profile.
7. **Doctor Assignment Governance Rule:**  
   - Changing a patient's permanent default doctor (`current_practitioner_id`) requires Owner/Admin approval.
   - Individual visit treating doctors (`patient_visits.doctor_id`) are assigned at reception check-in and never overwrite the patient's default doctor.
   - Historical visits permanently retain the doctor who attended the patient, guaranteeing legal and medico-legal auditability.
