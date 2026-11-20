# OralCares — Patient Management Product Rebuild
## Phase 5: Patient 360 & Patient Directory Information Architecture

**Document:** `docs/patient-management/05-patient-360-information-architecture.md`  
**Status:** Approved Information Architecture Specification  
**Objective:** Streamline the cluttered 17-tab Patient 360 into a purpose-driven, zero-redundancy 7-zone operational workspace, and optimize the Patient Directory for instant receptionist retrieval.

---

## 1. Patient Directory Rebuild Specification

The Patient Directory is the operational gateway for receptionists, doctors, and billing executives. It must deliver sub-second search and clear visual indicators without requiring staff to navigate away to find basic status.

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│ PATIENT DIRECTORY INTERFACE SPECIFICATION                                                        │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ [ Search: Name, UHID, Mobile, Case No... ]  [ All Patients | Today's Patients ]  [ Filters (3) ] │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ UHID      PATIENT NAME & GENDER   MOBILE      ACTIVE CASE / STATUS    DOCTOR      BALANCE ACTIONS│
│ OC-000042 Ananya Sharma (F, 28)   •••• 0601   [In Progress] Aligners  Dr. Sen     ₹0.00   [360]  │
│ OC-000043 Rahul Verma (M, 42)     •••• 4412   [New] General OPD       Dr. Patel   ₹450    [360]  │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### 1.1 Directory Column Hierarchy: Table vs Drawer vs Profile
To maintain optimal scannability across desktop (1440px), tablet (768px), and mobile (390px):

| Information Element | Placement Location | Technical Rationale & Role Access |
| :--- | :--- | :--- |
| **UHID** | **Always Visible** (Table Column 1) | Monospace badge (`font-mono text-xs font-semibold`). Core identifier for chart matching. |
| **Patient Name, Gender, Age** | **Always Visible** (Table Column 2) | Full name with avatar initials tile; gender and age in secondary muted text. |
| **Primary Contact (Mobile)** | **Always Visible** (Table Column 3) | Phone with masked digits for unauthorized roles; eye icon toggle for permitted roles (`reception`, `billing`, `owner`). |
| **Active Case & Treatment Status** | **Always Visible** (Table Column 4) | High-signal badge: `[Active] Upper Arch Aligners` or `[Completed] RCT #36` or `[New] No Active Case`. |
| **Assigned Doctor** | **Always Visible** (Table Column 5) | Attributed lead practitioner (`clinic_employees.name`). |
| **Outstanding Balance** | **Conditional Column** (Table Column 6) | Rendered **only** for roles with `billing.view` permission (`owner`, `billing`). Hidden from general staff. |
| **Quick Actions** | **Always Visible** (Table Column 7) | `[Open 360]`, `[Check In]`, `[New Case]`, `[More ⋯]`. |
| **Address, Emergency Contact, Alerts** | **Right Context Drawer / 360** | Available on row click via quick preview drawer without page reload. |

### 1.2 Multi-Field Instant Search Engine
Search must be bounded to the active tenant clinic (`where clinic_id = ?`) and use prefix matching on standard B-Tree indexes:
- **Phone Prefix Search:** Matches `9000...` on `patients.phone` index.
- **UHID Exact/Prefix Search:** Matches `OC-0000...` on `patients_clinic_uhid_unique`.
- **Name Word-Prefix Search:** Matches words in `patients.name` (`ash sha` finds "Asha Sharma").
- **Case Number Search:** Matches `CASE-2026-...` on `patient_cases.case_number`.

### 1.3 High-Utility Filter Set
Discard non-actionable filters. Retain only operationally impactful controls:
1. **Branch Filter:** (Auto-hidden in single-branch clinics; switches branch scope for enterprise networks).
2. **Attending Doctor / Specialty:** Filter patients by Doctor or Practice Domain (Dental vs Physio).
3. **Treatment Status:** `All`, `Under Active Treatment`, `Completed`, `Not Started`.
4. **Attendance Type:** `All`, `Today's Checked-in Patients`, `Upcoming Bookings`.
5. **Registration Date Range:** For cohort tracking.

---

## 2. Patient 360: Streamlined 7-Zone Information Architecture

### 2.1 The Problem with the Current 17-Tab Structure
The current Patient 360 exposes 17 micro-tabs:
$$\text{Overview, Visits, Encounters, Appointments, Treatment, Prescriptions, Investigations, Billing,}$$
$$\text{Documents, Follow-ups, Communications, Requests, Audit, Dental, Physio, Consents, Timeline}$$
**Failure Modes:**
1. **Encounter vs Visit Split:** Staff cannot tell where attendance ends and medical notes begin.
2. **Notes Duplicated in Timeline:** Narrative notes are repeated verbatim in the Timeline, Overview, and Encounters tab.
3. **Documents Scattered:** Prescriptions, X-rays, and consents live in 3 separate tabs.
4. **Cognitive Overload:** Clinicians spend more time navigating tabs than treating patients.

---

### 2.2 The Approved 7-Zone Workspace Structure

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│ ZONE 1: PATIENT HEADER (Persistent Identity Anchor)                                             │
│ Patient Name, UHID, Age/Gender, Mobile, Safety Alerts, Assigned Doctor, Quick Actions (Check-in) │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ ZONE 2: ACTIVE CASES BAR                                                                         │
│ [Case #26-0042: Aligners (Dental) • Active]  [Case #26-0089: Knee Rehab (Physio) • In Progress]   │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ WORKSPACE NAVIGATION (7 Streamlined Tabs Only):                                                  │
│ [ 1. Overview ] [ 2. Clinical ] [ 3. Cases & Plans ] [ 4. Visits ] [ 5. Docs ] [ 6. Billing ] [ 7. Timeline ]
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Detailed Zone Breakdown

#### Zone 1: Patient Header (Persistent Hero Anchor)
- **Identity:** Full Name, Avatar Initials Tile, Monospace UHID Pill (`OC-00000001`), Age & Date of Birth, Gender, Blood Group.
- **Safety Strip:** Sticky banner displaying active allergies, systemic illnesses (Diabetic, Hypertensive, Pregnant, Blood Disorder), and critical precautions in high-contrast pastel badges.
- **Assignment & Branch:** Primary branch, Default Assigned Doctor (with locked Owner-Mode transfer governance).
- **Quick Action Hub:**
  - `[ + Check In Today ]` (Triggers fast intake dialog).
  - `[ + New Clinical Case ]` (Starts a new episode of care).
  - `[ Book Appointment ]` (Opens calendar slot modal).
  - `[ Print Patient Summary ]` (Audited PDF export).

#### Zone 2: Overview (Executive & Clinical Snapshot)
*Strict Rule: Overview is a high-level summary, NOT a duplicate full log.*
- **Top Row KPI Tiles:**
  - Active Treatment Case & Current Stage Progress Bar (e.g. Stage 2 of 4 Completed).
  - Latest Visit summary (Date, Attending Doctor, Reason).
  - Next Scheduled Appointment (Date, Time, Doctor) or "No upcoming appointment [Schedule]".
  - Total Outstanding Balance (visible only to billing/owner roles).
- **Clinical Summary Grid (2 Columns):**
  - *Left Column (Medical Safety Profile):* Verified active allergies, chronic illnesses, active safety precautions, emergency contact.
  - *Right Column (Active Care Progress):* Current treatment plan highlights, pending lab items, remaining physio package sessions.

#### Zone 3: Clinical Workspace (Specialty-Driven)
*Automatically adapts based on clinic entitled modules and active case:*
- **Dental Workspace:**
  - Interactive FDI 2-digit Tooth Chart (Adult 11-48 / Deciduous 51-85).
  - Tooth surface-level condition inspection.
  - Perio probing pocket depths and endodontic canal notes.
  - Quick action: "Add Procedure to Active Case".
- **Physiotherapy Workspace:**
  - Visual Body Pain Map (Anterior/Posterior trigger point mapping).
  - Baseline vs Current Pain VAS Score (0-10) Trend Chart.
  - Range of Motion (ROM) goniometric comparison table.
  - Current Home Exercise Program (HEP) active set.

#### Zone 4: Cases & Treatment Plans
- **Case Explorer:** Lists all clinical cases (Active, On Hold, Completed, Closed).
- **Case Detail View:**
  - Case metadata: Title, Chief Complaint, Primary Diagnosis, Lead Doctor, Open Date.
  - Associated Treatment Plans: Itemized procedures, teeth/areas involved, estimated cost, acceptance status.
  - Stage Completion Stepper: Visual progression showing completed vs pending clinical milestones.

#### Zone 5: Encounters & Visits (Unified Attendance & Clinical Log)
*Consolidates `patient_visits` and `clinical_encounters` into a single seamless chronological view.*
- **Encounter Card Feed:**
  - Card Header: Visit Date, Encounter Number (`ENC-2026-004521`), Attending Doctor, Branch, Registration Type (`New` / `Repeat`).
  - Intake Vitals Strip: BP, Pulse, Temp, SpO2, Weight.
  - Structured SOAP Narrative:
    - **S:** Chief Complaint & History of Present Illness.
    - **O:** Clinical Examination & Diagnostic Findings.
    - **A:** Diagnosis & Tooth/Joint Assessment.
    - **P:** Procedures Performed Today, Prescription Issued, Post-Op Instructions.
  - Status Badge: `Finalized & Signed (Dr. Sharma)` with cryptographic hash, or `Open Draft`.
  - Addendum Viewer: Timestamped post-signing addenda (if any).

#### Zone 6: Documents & Scans (Consolidated Repository)
*Merges Attachments, Consents, Prescriptions, and Imaging into one organized repository.*
- **Category Filter Tabs:** `[All Documents]`, `[X-Rays & Imaging]`, `[Prescriptions]`, `[Lab Reports]`, `[Signed Consents]`.
- **Card-Based File Grid:**
  - File thumbnail preview (JPEG, PNG, DICOM render; PDF icon for reports).
  - Metadata: Document Title, Category, Associated Case/Visit, Uploaded By, Date, File Size.
  - Direct Actions: View Fullscreen Lightbox, Download, Print, Share via WhatsApp (audited).

#### Zone 7: Billing & Financial Ledger
*Direct, permitted view into patient accounting.*
- **Account Summary Card:** Total Invoiced, Total Collected, Total Adjustments/Discounts, Net Balance Due.
- **Invoices & Receipts Ledger:**
  - Invoice Number, Date, Branch, Services Rendered, Bill Status (`Paid`, `Partial`, `Unpaid`).
  - Direct Actions: `[Collect Payment]`, `[Print Thermal Receipt]`, `[Print A4 Tax Invoice]`.
- **Package Ledger (Physiotherapy):**
  - Active packages, sessions purchased, sessions consumed, expiry date, remaining value.

#### Zone 8: Timeline & Audit History
- **Concise Milestone Feed:** Records ONLY high-level enterprise lifecycle events:
  - *Patient Registered (UHID Issued)*
  - *Case #26-0042 Opened (Dr. Sen assigned)*
  - *Treatment Plan Accepted (Estimate ₹24,000)*
  - *Dental Lab Order #89 Dispatched*
  - *Payment Received (₹10,000 via UPI)*
  - *Case Completed & Discharged*
- *Eliminates narrative consultation text duplication to keep timeline scannable and lightweight.*

---

## 3. Redundant Sections Consolidation Register

| Old Standalone Tab | New Consolidated Home | Rationale |
| :--- | :--- | :--- |
| **Visits** (`patient_visits`) | **Merged into Zone 5 (Encounters & Visits)** | Visiting attendance and clinical consultation are two sides of the same encounter event. |
| **Encounters** (`clinical_encounters`) | **Merged into Zone 5 (Encounters & Visits)** | Eliminates confusion between reception attendance and doctor notes. |
| **Prescriptions** | **Integrated into Zone 5 (Encounter Cards) & Zone 6 (Documents)** | Prescriptions are issued during encounters and printed/downloaded as clinical documents. |
| **Consents** | **Integrated into Zone 6 (Documents - Signed Consents)** | Consents are legal document attachments linked to cases or procedures. |
| **Investigations** | **Integrated into Zone 6 (Documents - Lab Reports & Scans)** | Diagnostic reports and scans naturally live in the organized document repository. |
| **Follow-ups** | **Integrated into Zone 2 (Overview Next Appointment) & Zone 5 (Encounter Plan)** | Follow-up recalls belong to clinical planning and calendar scheduling. |
| **Communications** | **Integrated into Zone 8 (Timeline)** | WhatsApp messages and automated alerts are logged as timeline lifecycle events. |
| **Requests / Audit** | **Integrated into Zone 8 (Timeline & Audit History)** | Deletion, transfer, and death record requests belong to the governed audit log. |
