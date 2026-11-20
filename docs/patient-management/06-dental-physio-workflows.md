# OralCares — Patient Management Product Rebuild
## Phase 6: Specialized Dental & Physiotherapy Workflows

**Document:** `docs/patient-management/06-dental-physio-workflows.md`  
**Status:** Approved Workflow Architecture  
**Core Mandate:** Complete operational specialization between Dental and Physiotherapy. Strict isolation ensures that Physio clinics never see dental odontograms or tooth charts, and Dental clinics never encounter physio body maps or range-of-motion tables.

---

## 1. Specialty Isolation & Adaptive Runtime Architecture

OralCares supports three operational clinic modes governed by clinic license entitlements and branch specialties (`ClinicSpecialty`):
1. **Dental-Only Practice:** Specialty is locked to `dental`. All physio navigation, tables, and endpoints are completely hidden and unrouted.
2. **Physiotherapy-Only Practice:** Specialty is locked to `physiotherapy`. All dental terminology, tooth numbering charts, surfaces, endo/perio exams, and dental lab orders are completely purged from the workspace.
3. **Combined / Multispecialty Practice:** Both modules exist in the platform, but **the patient's active case context dictates which clinical workspace renders**. A patient undergoing dental care sees the dental chart; switching to their physio rehabilitation case shifts the workspace cleanly to the physiotherapy body map and exercise tracker.

---

## 2. Dental Specialized Workflows & Clinical Components

```
┌────────────────────────────────────────────────────────────────────────┐
│ DENTAL CLINICAL ENGINE                                                 │
├────────────────────────────────────────────────────────────────────────┤
│ 1. Dental Medical History & Pre-Op Safety (Bleeding, SBE, Allergies)   │
│ 2. FDI Interactive Tooth Chart (Adult 11-48, Pedo 51-85, Surfaces)     │
│ 3. Periodontal Probing (BPE / 6-Point Charting: Pocket Depths, Bleed)  │
│ 4. Endodontic Diagnostic Log (Vitality, Working Length, Canals)        │
│ 5. Phased Treatment Plans & Estimates (Phase 1, 2, 3 + Acceptance)     │
│ 6. Multi-Visit Procedure Tracker (RCT Stage 1, 2, 3)                   │
│ 7. Dental Lab Order Lifecycle (Impression -> Fabrication -> Delivery)  │
│ 8. Dental Imaging (IOPA, OPG, CBCT X-Rays)                            │
│ 9. Preventive Recall & Periodontal Maintenance Scheduling             │
└────────────────────────────────────────────────────────────────────────┘
```

### 2.1 The FDI 2-Digit Interactive Tooth Chart
- **International Standard:** FDI Two-Digit Notation:
  - Upper Right (Quadrant 1: 18 to 11) | Upper Left (Quadrant 2: 21 to 28)
  - Lower Right (Quadrant 4: 48 to 41) | Lower Left (Quadrant 3: 31 to 38)
  - Primary / Deciduous: Quadrants 5, 6, 7, 8 (55-51, 61-65, 75-71, 81-85)
- **Surface-Level Precision (MODBL):**
  - Mesial (M), Occlusal / Incisal (O/I), Distal (D), Buccal / Facial (B/F), Lingual / Palatal (L/P).
- **Standardized Color & State Taxonomy:**
  - **Healthy / Intact:** Crisp slate outline, white fill.
  - **Pathology / Caries:** High-contrast Amber/Red fill on affected surfaces.
  - **Existing Restoration (Done Elsewhere):** Solid Slate/Gray fill.
  - **Planned Procedure (OralCares Plan):** Vibrant Blue hatched outline (`#2563EB`).
  - **Completed Procedure (Done in Clinic):** Solid Emerald Green fill (`#10B981`).
  - **Missing / Extracted:** Crossed out (`✕`).
  - **Crown / Bridge:** Gold / Teal outline enclosing tooth crown.
  - **Root Canal Treated:** Vertical endodontic canal fill in root apex.
  - **Implant:** Screw-post icon anchoring root position.

### 2.2 Treatment Planning, Estimates & Patient Acceptance
1. **Diagnosis to Plan:** Selecting tooth #36 with "Deep Dentinal Caries" auto-suggests treatment options (Option 1: RCT + Post & Core + Zirconia Crown; Option 2: Extraction + Bone Graft + Implant).
2. **Itemized Financial Estimate:** Generates a formal estimate detailing Clinic Fees, Lab Charges, Material Costs, and Patient Out-of-Pocket Liability.
3. **Formal Patient Acceptance:** Patient or guardian signs digitally (or verifies via OTP) prior to high-value restorative/orthodontic procedures.
4. **Multi-Visit Clinical Stepper:**
   - E.g., Root Canal Therapy:
     - *Visit 1:* Access opening, pulp extirpation, temporary dressing.
     - *Visit 2:* Biomechanical preparation (BMP), apex locator working length check, master cone fit.
     - *Visit 3:* Obturation with Gutta-Percha, post-endo seal.
     - *Visit 4:* Core build-up, crown tooth preparation, digital/alginate impression.
     - *Visit 5:* Final crown cementation and occlusion adjustment.

### 2.3 Dental Lab Order Tracking
- **Order Initiation:** Linked directly to the planned crown, bridge, denture, or aligner.
- **Specification:** Lab Vendor selection, Tooth Number, Shade matching (VITA Classical A1-D4, 3D Master), Margin design, Material (Zirconia, PFM, E-max, Acrylic).
- **Operational Status Pipeline:**
  $$\text{Impression Sent} \longrightarrow \text{In Fabrication} \longrightarrow \text{Received at Clinic} \longrightarrow \text{Try-In Done} \longrightarrow \text{Final Fit \& Cemented}$$
  *(With exception handling for Remake / Lab Return).*

---

## 3. Physiotherapy Specialized Workflows & Clinical Components

```
┌────────────────────────────────────────────────────────────────────────┐
│ PHYSIOTHERAPY CLINICAL ENGINE                                          │
├────────────────────────────────────────────────────────────────────────┤
│ 1. Initial Assessment & Presenting Mechanism of Injury (Onset/Trauma)  │
│ 2. Visual Body Pain Map (Anterior/Posterior Trigger Points, Radiation) │
│ 3. Pain Quantification (VAS Score 0-10 at Rest vs on Activity)         │
│ 4. Goniometric Range of Motion (ROM Degrees: Active vs Passive vs Norm)│
│ 5. Manual Muscle Testing (MMT Grade 0 to 5) & Postural Analysis        │
│ 6. SMART Treatment Goals (Functional Recovery Milestones)              │
│ 7. Daily SOAP Treatment Session Log (Modalities + Exercises Performed) │
│ 8. Home Exercise Program (HEP) Prescription (Sets, Reps, Frequency)    │
│ 9. Prepaid Session Package Punch-Card Management                       │
│ 10. Periodic Reassessment (Outcome Comparison) & Discharge Summary     │
└────────────────────────────────────────────────────────────────────────┘
```

### 3.1 Visual Body Pain Map & Initial Assessment
- **Interactive Anatomical Model:** Front and Back full-body human figures divided into clinical regions (Cervical Spine, Lumbar Spine, Shoulder, Elbow, Wrist, Hip, Knee, Ankle).
- **Pain Sensation Pinning:** Clinician or patient flags specific coordinates with sensation tags (Dull ache, Sharp pain, Burning, Numbness/Paresthesia, Radiating pain).
- **Numeric Rating:** Visual Analogue Scale (VAS) from `0` (No Pain) to `10` (Unbearable Pain), evaluated independently for **Pain at Rest** and **Pain on Activity**.

### 3.2 Objective Measurements: ROM & Muscle Strength (MMT)
- **Range of Motion (Goniometry):**
  - Captures Active ROM and Passive ROM in degrees against normal anatomical references.
  - *Example (Knee Flexion):* Normal: $135^\circ$ | Initial Baseline: $65^\circ$ | Goal: $120^\circ$.
- **Manual Muscle Testing (Oxford Scale):**
  - Grade 0: No contraction
  - Grade 1: Flicker of movement
  - Grade 2: Movement with gravity eliminated
  - Grade 3: Movement against gravity
  - Grade 4: Movement against gravity with moderate resistance
  - Grade 5: Normal power against full resistance

### 3.3 Daily SOAP Session Logging & Modalities
Each session records a standardized SOAP entry:
- **S (Subjective):** Patient's reported pain rating today, morning stiffness duration, medication taken.
- **O (Objective):** 
  - *Modalities Applied:* TENS (frequency/duration), Ultrasound (W/cm²), Cryotherapy/Hot pack, Traction, Manual joint mobilization (Maitland Grade I-IV).
  - *Exercises Performed:* Specific exercise, resistance band color/weight, repetitions, sets, rest intervals.
- **A (Assessment):** Tolerance to load, biomechanical corrections, pain response post-exercise.
- **P (Plan):** Progression criteria, exercises added to home regimen, next session date.

### 3.4 Prepaid Session Package Management (Punch-Card Telemetry)
1. **Package Purchase:** Patient buys a therapy bundle at registration or checkout (e.g., "12-Session Post-Surgical Knee Rehab").
2. **Billing Ledger Lock:** Creates a `patient_session_packages` record with financial transaction link.
3. **Session Punching:** Upon the therapist signing a completed `PhysioSession`, the system automatically executes an atomic transaction decrementing the package:
   $$\text{Used Sessions} \mathrel{+}= 1, \quad \text{Remaining Sessions} = \text{Total} - \text{Used}$$
4. **Front-Desk Visibility:** Front-desk receptionists have full operational access to package counters (`physio_operational` permission) to inform patients upon check-in: *"You have completed 4 of 12 sessions; 8 sessions remain valid until 20-Nov-2026."*

### 3.5 Reassessment & Formal Discharge Summary
- **Automatic Milestone Trigger:** At session 6 and session 12, the system prompts for a **Progress Reassessment**.
- **Comparative Metrics Table:** Compares Day 1 baseline against Current Day:
  - VAS Pain: $8/10 \longrightarrow 2/10$ ($75\%$ reduction).
  - Knee Flexion: $65^\circ \longrightarrow 120^\circ$ ($+55^\circ$ gain).
  - Functional Score: Independent stair climbing restored.
- **Discharge Summary:** Document signed upon treatment conclusion outlining long-term maintenance exercises, ergonomic guidelines, and return-to-sport clearance.

---

## 4. Module Isolation Matrix

| UI Component / Feature | Dental-Only Clinic | Physio-Only Clinic | Combined / Multispecialty Clinic |
| :--- | :---: | :---: | :---: |
| **FDI 2-Digit Tooth Chart** | **Active** | **Completely Hidden (Purged)** | Active under Dental Cases |
| **Periodontal / Endo Exams** | **Active** | **Completely Hidden (Purged)** | Active under Dental Cases |
| **Dental Lab Order Pipeline** | **Active** | **Completely Hidden (Purged)** | Active under Dental Cases |
| **Visual Body Pain Map** | **Completely Hidden (Purged)** | **Active** | Active under Physio Cases |
| **Goniometric ROM & MMT Tables** | **Completely Hidden (Purged)** | **Active** | Active under Physio Cases |
| **Therapy Session Packages** | **Completely Hidden (Purged)** | **Active** | Active under Physio Cases |
| **Home Exercise Program (HEP)** | **Completely Hidden (Purged)** | **Active** | Active under Physio Cases |
| **Master Patient Header & UHID** | Shared Core Standard | Shared Core Standard | Shared Core Standard |
| **Medical Safety & Allergy Alerts**| Shared Core Standard | Shared Core Standard | Shared Core Standard |
| **Document & Scan Repository** | Shared Core Standard | Shared Core Standard | Shared Core Standard |
| **Invoicing & Billing Engine** | Shared Core Standard | Shared Core Standard | Shared Core Standard |
