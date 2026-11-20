# OralCares — Patient Management Product Rebuild
## Phase 2: Industry Benchmark & Competitive Analysis

**Document:** `docs/patient-management/02-industry-benchmark.md`  
**Status:** Benchmark Complete  
**Analyzed Dental Platforms:** Dentrix (Henry Schein), CareStack, Curve Dental, denSmart, MocDoc Dental, Practo Ray.  
**Analyzed Physio Platforms:** Cliniko, Jane App, PhysioApp, Apollo/Indian Physiotherapy practice EHRs.

---

## 1. Executive Benchmarking Overview

Enterprise healthcare practices distinguish clearly between:
1. **The Patient (Permanent Identity & Demographics)**
2. **The Case / Episode of Care (Clinical Condition / Course of Treatment)**
3. **The Visit / Encounter (Individual Attendance & Clinical Touchpoint)**
4. **The Financial Ledger (Invoices, Receipts, Insurance, Balances)**

In leading systems like **CareStack** and **Jane App**, a patient can have multiple concurrent or sequential cases (e.g. "Case #1: Orthodontic Alignment" and "Case #2: Periodontal Maintenance", or "Episode #1: Lumbar Disc Rehabilitation" and "Episode #2: Rotator Cuff Recovery"). Visits and billing lines are grouped under their respective cases while remaining rolled up to the patient's master ledger.

In contrast, earlier dental tools (e.g. legacy Dentrix on-premise) and generic Indian clinic software frequently merge the concept of "Case" with an annual serial queue number, causing severe record fragmentation across years and branches.

---

## 2. In-Depth Comparative Workflow Analysis

### 2.1 Dental Industry Workflows (Dentrix, CareStack, Curve Dental, denSmart)
- **Patient Identification:** All cloud systems issue a persistent Clinic-wide Medical Record Number (MRN / UHID). Multi-location groups (DSOs) support Global Patient Indexing, allowing a patient visiting Branch B to have their complete historical odontogram and billing loaded without duplicate registration.
- **Odontogram & Charting:** 
  - Standardized FDI 2-digit (International) or Universal 1-32 (US) charting.
  - Distinct graphic rendering for: Existing conditions (blue/gray), Pathology/Decay (red), Proposed treatment (green/yellow), Completed work (dark blue/purple).
  - Tooth surface-level granularity (Mesial, Occlusal, Distal, Buccal/Facial, Lingual/Palatal).
- **Treatment Planning & Case Acceptance:**
  - Multi-option treatment planning (Option A: Root Canal + PFM Crown vs Option B: Extraction + Implant).
  - Phased treatment planning (Phase 1: Emergency & Stabilization $\rightarrow$ Phase 2: Restorative $\rightarrow$ Phase 3: Prosthetic maintenance).
  - Explicit patient acceptance step (signature/PIN) with pre-printed financial estimates and payment schedules before treatment execution begins.
- **Dental Lab Tracking:**
  - Integrated tracking of Prosthetics / Crown / Bridge / Aligners / Removable dentures.
  - Status pipeline: Impression Taken $\rightarrow$ Sent to Lab $\rightarrow$ In Fabrication $\rightarrow$ Received at Clinic $\rightarrow$ Try-in Scheduled $\rightarrow$ Final Fit & Completed $\rightarrow$ Remake (if rejected).

### 2.2 Physiotherapy Industry Workflows (Cliniko, Jane App, PhysioApp)
- **Clinical Intake & Assessment:**
  - Visual Body Chart (Pain Mapping): Front, back, and bilateral diagrams where patients or therapists mark pain spots, radiation lines, numbness, or trigger points.
  - Standardized Scoring: Visual Analogue Scale (VAS 0-10) pain at rest vs on activity.
  - Objective Range of Motion (ROM): Goniometric measurements (Degrees) for Flexion, Extension, Abduction, Rotation, compared against normal reference baselines.
  - Muscle Power: Oxford Scale / Manual Muscle Testing (MMT Grade 0 to 5).
- **Episode of Care & Goals:**
  - Functional SMART goals (e.g. "Achieve 90° knee flexion within 3 weeks; Return to unassisted walking within 6 weeks").
  - Treatment plan with defined frequency and session duration (e.g. "3 sessions/week for 4 weeks").
- **SOAP Notes & Session Execution:**
  - **S (Subjective):** Patient's reported progress, current pain rating today.
  - **O (Objective):** Exercises performed today (reps, sets, resistance/weight), modalities applied (Ultrasound 1.5W/cm², TENS 80Hz, Ice pack).
  - **A (Assessment):** Response to treatment, therapist's clinical observation.
  - **P (Plan):** Next session adjustments, Home Exercise Program (HEP) updates.
- **Session Package Management:**
  - Selling prepaid session packages (e.g. "10-Session Post-Op Rehab Package").
  - Real-time punch-card deduction on encounter completion: "Session 4 of 10 Completed. 6 Sessions Remaining. Valid until 15-Dec-2026".
- **Outcome Reassessment & Discharge:**
  - Mandatory reassessment every 6-10 sessions comparing initial ROM/VAS with current scores.
  - Formal Discharge Summary documenting functional improvement and post-discharge home maintenance.

---

## 3. Comprehensive Industry Comparison Matrix

| Clinical & Product Domain | Industry Standard Practice (CareStack, Jane, Cliniko) | Existing OralCares Implementation | Architectural & UX Gap in OralCares | Strategic Recommendation for OralCares Rebuild | Priority |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Patient Identification** | Persistent Clinic-wide UHID; multi-branch indexing; photo-optional; phone & National ID indexing. | Clinic-wide sequential UHID (`OC-00000001`); phone search. | `case_numbers` table creates a new annual number per visit (`YYYY-000001`), confusing queue ticket with patient identity. | Keep UHID as lifetime patient identity. Disconnect annual queue number from Case/Patient entity. | **P0** |
| **Case / Episode Management** | Multi-case support. An episode groups visits, diagnoses, treatment plans, and bills for a specific clinical condition. | No case entity. Visits are flatly tied directly to `patient_id`. | Cannot track whether a visit is for a tooth extraction, a long-term orthodontic case, or knee rehab. | Introduce canonical `PatientCase` (Episode of Care) entity linking visits, treatment plans, and progress. | **P0** |
| **Patient Directory** | High-density fast list; quick search across name, UHID, mobile; multi-branch toggle; active case indicator. | Keyset paginated table; today vs all views; standard demographic columns. | No active case column; financial balance hidden from directory table; filter drawer requires multiple clicks. | Fast-action directory: include Active Case badge, Latest Visit, quick-action drawer, and balance for authorized roles. | **P1** |
| **Clinical Encounter Notes** | Standardized SOAP structure; auto-save; digital signing; addendum capability; immutable audit trail. | `ClinicalEncounter` with narrative fields (`chief_complaint`, `history`, `examination`, `follow_up_note`), signature hash. | Narrative fields are broad strings; no structured SOAP tab division; addenda exist in schema but under-utilized in UI. | Adopt structured SOAP presentation with auto-save indicators and clear finalized/signed badges. | **P1** |
| **Dental Odontogram** | FDI 2-digit / Universal chart; colour-coded states (existing, disease, planned, completed); surface selection (MODBL). | `PatientDentalChart` with FDI grid; surface-level array; conditions list. | Charting UI is isolated in one tab; not linked to direct invoice checkout or procedure generation on the fly. | Connect dental chart interactive actions directly to Treatment Plan item creation and billing checkout. | **P1** |
| **Dental Lab Tracking** | Full lab order lifecycle: prescription, shade, prep, try-in, remake, finish, lab vendor cost tracking. | `LabOrder`, `LabCaseTrial`, `LabProsthetic` models; basic status transitions. | Lab orders are hidden inside sub-routes with no global lab queue or technician communication portal. | Provide a dedicated Lab Management card within the Dental Case workspace and links to Central Lab Board. | **P2** |
| **Physiotherapy Assessment** | Visual front/back body pain map; VAS score 0-10; goniometric ROM degrees; MMT muscle grades; functional tests. | `PhysioAssessment` model has arrays for `range_of_motion`, `strength`, `body_chart`, and integers for pain. | UI renders text inputs and numeric sliders; lacks graphical body pain map and comparative ROM tables. | Implement interactive Body Map selector and structured baseline vs reassessment comparison tables. | **P1** |
| **Physio Package Tracking** | Prepaid packages; automatic session decrement upon consultation signing; refund & transfer handling. | `PatientSessionPackage`, `PhysioSession`, `SessionPackageUsage` with row locks and balance guards. | Backend logic is solid, but front-desk receptionists get 403 access issues in Combined clinic setups. | Resolve front-desk permission mapping (`physio_operational` view) so desk staff can see package balances and book sessions. | **P0** |
| **Home Exercise Program (HEP)** | Visual exercise library with diagrams, sets, reps, frequency, and WhatsApp/PDF patient share. | `PhysioExercise` model with sort order; `PatientMessageShareController`. | Exercises are currently manual text entries; lacks standard built-in exercise template catalog. | Create structured exercise prescription cards (Exercise, Sets, Reps, Hold time, Frequency) with WhatsApp/Print sharing. | **P2** |
| **Treatment Planning** | Staged estimates; acceptance signature/PIN; insurance pre-authorization; cost breakdown per visit. | `TreatmentPlan` + `TreatmentRecord` models; proposed, accepted, active, completed states. | Acceptance is a simple state toggle without formal patient signature/acknowledgement audit trail. | Introduce structured Treatment Plan Acceptance with digital consent, estimated out-of-pocket costs, and milestone timeline. | **P1** |
| **Prescriptions & Pharmacy** | Electronic prescription with drug dosage database, frequency, food relation, duration, refill rules, safety alerts. | `Prescription` and `PrescriptionItem` models; allergies safety checks; PDF print. | Drug catalogue relies on manual typing; no medication interaction warnings; refills not tracked. | Standardize dosage picker (Morning-Afternoon-Night, Before/After Food, Days) with allergy conflict warnings. | **P1** |
| **Document & Scan Storage** | Categorized folders (Radiographs, Reports, Lab, Consents); DICOM/JPEG viewer; measurement tools; zoom/pan. | `AttachedDocument` model; file download; size/type validation. | Simple flat list; no image thumbnail preview; no zoom/pan viewer for periapical/panoramic X-rays. | Introduce visual document cards with category filters, image lightboxes, and direct zoom/pan for X-ray records. | **P2** |
| **Patient 360 Workspace** | Unified 4-6 workspace modules; clear separation of Overview Snapshot vs Encounter Log; progressive disclosure. | 17 separate tab buttons in top bar; duplicate data across Overview, Visits, Encounters, Timeline. | Extreme cognitive fatigue; staff get lost clicking between Visits, Encounters, Timeline, and Clinical notes. | Streamline into 7 clear, purposeful operational zones (Header, Overview, Clinical Workspace, Cases, Visits, Docs, Billing). | **P0** |

---

## 4. Key Takeaways for OralCares Architecture

1. **Adopt the Episode-of-Care (Case) Model:**  
   OralCares must stop treating "Case" as an annual visit counter. Case is a clinical container that holds a diagnosis, a treatment plan, and multiple sequential visits.
2. **Strict Specialty Isolation:**  
   CareStack and Jane App succeed because they never confuse the practitioner. Dental clinics should never see physio terminology (body maps, ROM), and Physio clinics should never see teeth or dental charts. Combined clinics must cleanly separate specialties per Case.
3. **Connect Charting to Billing:**  
   In top dental software, charting a cavity and planning a composite restoration automatically generates the line item in the treatment plan and queues it for checkout upon procedure completion.
4. **Front Desk Empowerment Without Clinical Breach:**  
   Front desk receptionists need operational visibility (e.g. "Does this patient have remaining physio package sessions?", "Has the dental lab crown arrived?") without exposing detailed private doctor narrative notes.
