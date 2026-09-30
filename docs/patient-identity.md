# Patient Identity Architecture (Phase 7)

This document describes the foundational patient identity systems in OralCares, implemented during Phase 7. The system is designed to provide a permanent, immutable record for a patient (UHID) alongside reset-capable yearly queue numbers (Case Numbers).

## Core Concepts

### 1. Permanent Patient Identity (UHID)

Every patient in OralCares receives a permanent **Unique Healthcare Identifier (UHID)**.

- **Format**: `OC-00000001` (Configurable prefix + 8 digits)
- **Scope**: Unique per Clinic.
- **Immutability**: Once assigned, the UHID never changes. It is the core identifier for all billing, clinical records, laboratory records, imaging, and reports.
- **Generation Logic**: Uses an atomic transaction with a pessimistic lock (`lockForUpdate()`) on a tracking table or the latest patient record to guarantee sequence integrity under high concurrency.
- **Database Column**: `patients.uhid`

### 2. Year-wise Case Number

Case numbers are used to identify a patient within a specific calendar year. They decouple the patient's identity from the queue and reception workflow, adding a layer of privacy and operational convenience.

- **Format**: `YYYY-000001` (e.g., `2026-000001`)
- **Scope**: Unique per Clinic per Year.
- **Reset**: The sequence automatically resets to `000001` on January 1st of every year.
- **Usage**: Used in Reception, Doctor Queue, TV Displays, and Calling Systems instead of the patient's name or UHID.
- **Generation Logic**: Like UHID, uses pessimistic locking to guarantee no duplicates or sequence gaps during concurrent registrations.
- **Database Table**: `case_numbers`

### 3. Patient Visits

The core transactional entity in the system is the `PatientVisit`. 
A visit represents a physical or teleconsultation event.

- **Relationship**: `Patient` -> Has Many -> `PatientVisit`
- **Case Numbers**: A Case Number is assigned to a `PatientVisit`.

## Workflows

### New Patient Registration
1. Receptionist captures demographics (mobile, name, gender, age).
2. The `PatientRegistrationService` assigns a new **UHID** and creates the `Patient` record.
3. A `PatientVisit` is created for the current date.
4. A new **Case Number** for the current year is generated and linked to the visit.

### Repeat Patient Registration
1. Receptionist searches for the patient by Mobile, Name, UHID, or previous Case Number using `PatientSearchService`.
2. The existing `Patient` is selected (retaining their original **UHID**).
3. A new `PatientVisit` is created.
4. A new **Case Number** for the current year is generated (if the patient visits across multiple years, they will have multiple case numbers).

## Configuration

The format of the UHID and Case Number can be configured via `config/app.php` or `.env`:

```env
PATIENT_UHID_PREFIX=OC-
PATIENT_UHID_PAD=8
CASE_NUMBER_PAD=6
```

## Legacy Support

The previous `patient_number` column has been retained but made nullable to support backward compatibility for existing records before the transition to the enterprise UHID standard.
