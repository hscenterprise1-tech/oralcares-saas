# OralCares — Patient Management Product Rebuild
## Phase 7: Backend Cleanup & Architecture Register

**Document:** `docs/patient-management/07-backend-cleanup-register.md`  
**Status:** Audit & Proposal Complete — Awaiting Approval  
**Strict Policy:** **NO DESTRUCTIVE CHANGES IN THIS PHASE.** Every cleanup candidate is thoroughly classified with dependency tracking, data migration impact, tenant isolation verification, and rollback protocols.

---

## 1. Classification Categories & Audit Criteria

Every backend asset in the Patient Management module has been audited against active production routes, test files, and database references, and categorized as:
- **A. KEEP:** Core, robust, actively used components that form the architectural foundation.
- **B. IMPROVE:** Valuable components that suffer from performance issues, payload bloating, or incomplete implementation.
- **C. CONSOLIDATE:** Redundant models, parallel tables, or duplicate columns that split data integrity.
- **D. DEPRECATE:** Stale code, obsolete endpoints, or legacy fallback accessors maintained for backward compatibility.
- **E. REMOVE (SAFE):** Confirmed dead code with zero runtime references and zero test dependencies.

---

## 2. Comprehensive Backend Component Register

| # | Component / Asset | Type | Current Status & Evidence | Classification | Recommended Action | Risk |
| :--- | :--- | :--- | :--- | :---: | :--- | :---: |
| **1** | `Patient` model & table | Model | Primary entity. Manages lifetime UHID, core demographics. | **A. KEEP** | Retain as primary patient identity entity. | None |
| **2** | `UhidGenerator` service | Service | Atomic pessimistic lock generation of sequential UHID per clinic. | **A. KEEP** | Retain. Enterprise standard. | None |
| **3** | `ClinicalEncounter` model | Model | Stores doctor consultation, narrative, SOAP notes, signature hash. | **A. KEEP** | Retain as clinical consultation record. | None |
| **4** | `PaymentService` | Service | Row-locked payment recording, receipt numbering, transaction idempotency. | **A. KEEP** | Retain. Strongest financial logic. | None |
| **5** | `PatientDirectory` service | Service | Keyset paginated query engine for patient search and filtering. | **B. IMPROVE** | Optimize eager loading to avoid N+1 on `case_numbers` and `currentPractitioner`. Add active case subquery. | Low |
| **6** | `Patient360SectionController` | Controller | Routes 17 micro-tabs. Sends unopenable endpoint URLs to restricted roles. | **B. IMPROVE** | Consolidate from 17 down to 7 core zones. Only send endpoints the user's role can open. | Low |
| **7** | `PatientClinicalEntry` vs `PatientMedicalHistory` | Models | Dual storage: entries live as normalized rows AND as JSON arrays (`allergies`, `medical_conditions`). | **C. CONSOLIDATE** | Make `patient_clinical_entries` the single source of truth. Keep `PatientMedicalHistory` as read-only legacy mirror until migration. | Medium |
| **8** | `pincode` vs `postal_code` | Columns | Duplicate columns in `patient_profiles`. Model uses accessor/mutator hack. | **C. CONSOLIDATE** | Standardize on `postal_code`. Deprecate `pincode` column via safe migration. | Low |
| **9** | `case_numbers` table | Table | Treats Case Number as an annual visit counter (`YYYY-000001`) instead of a clinical episode. | **C. CONSOLIDATE** | Transition to `patient_cases` (Episode of Care) and leave daily queue tokens to `clinic_queue_items`. | High |
| **10** | `TreatmentCatalog` model vs `ClinicalProcedure` | Models | `treatment_catalogs` (48 legacy rows) duplicates `clinical_procedures` (46 rows). | **C. CONSOLIDATE** | Unify under `clinical_procedures`. Backfill legacy references, then deprecate `treatment_catalogs`. | Medium |
| **11** | `queue_entries` (`QueueEntry`) | Model | Legacy queue table with 0 rows; superseded by `clinic_queue_items`. | **E. REMOVE** | Drop model and table via reviewed migration (already 0 rows). | Low |
| **12** | `PatientRegistrationService` | Service | Legacy org-level service unreferenced by any clinic portal route. Causes test failure in `PatientRegistrationTest`. | **E. REMOVE** | Remove dead class and repair test against canonical `PatientRegistrar`. | Low |
| **13** | Standalone `BillDialog` in Patient 360 | UI / Component | Duplicate billing modal using older dark styling; lacks thermal reprint links. | **C. CONSOLIDATE** | Replace with canonical `BillDetailDialog` in read-only mode. | Low |
| **14** | `patients/{p}/treatment-charges` endpoints | Routes / Controller | Backend routes exist with no frontend caller. | **B. IMPROVE** | Connect to clinical consultation checkout or deprecate if covered by `CheckoutController`. | Low |
| **15** | `app/Shared` unreferenced classes | Shared Classes | `BatchNotificationJob`, `CentralNotificationListener`, `SavedSearch` have 0 callers. | **D. DEPRECATE** | Deprecate in codebase; remove after checking service providers. | Low |

---

## 3. High-Risk Consolidations: Detailed Safeguard & Impact Protocols

### Protocol 1: Consolidating Medical Safety (JSON Arrays $\rightarrow$ Normalized Clinical Entries)
- **Current State:**  
  `patient_medical_histories` stores `allergies` (JSON), `medical_conditions` (JSON), and `current_medications` (JSON). Simultaneously, `patient_clinical_entries` stores the same data as normalized rows with timestamps, recorder IDs, and resolution status.
- **Dependency Audit:**  
  - Controllers reading JSON: `PatientSafetyController`, `Patient360Summary`.
  - Services reading discrete rows: `PatientClinicalEntries`, `PatientCareGuard`.
- **Migration Impact:**  
  Write a safe idempotent backfill script migrating any JSON entries lacking a matching `patient_clinical_entries` row.
- **Tenant Isolation Impact:**  
  Both tables are `ClinicScoped`; no cross-tenant exposure risk.
- **Historical Record Impact:**  
  All historical notes and timestamps are preserved in `patient_clinical_entries.recorded_at`.
- **Rollback Plan:**  
  Keep `patient_medical_histories` JSON columns as read-only mirrors during a 60-day deprecation window. If any edge-case consumer fails, read fallback directly from the JSON mirror.

---

### Protocol 2: Decoupling Case Number from Visit Queue (`case_numbers` $\rightarrow$ `patient_cases`)
- **Current State:**  
  Every visit creates a `case_numbers` row (`YYYY-000001`), confusing an episode of care with an annual queue ticket.
- **Dependency Audit:**  
  - Read by: `NewCaseForm`, `RepeatCaseForm`, `PatientVisit.caseNumber()`, `PatientHeader`.
- **Migration Impact:**  
  1. Introduce new canonical table `patient_cases` (`id`, `clinic_id`, `branch_id`, `patient_id`, `case_number`, `title`, `specialty`, `status`, `opened_at`, `closed_at`).
  2. For existing records, create 1 default case per active treatment plan or group existing visits into an "Historical Care Episode".
  3. Leave `case_numbers` intact for backward query compatibility.
- **Historical Record Impact:**  
  Existing visit relationships are untouched; `patient_visits.case_number_id` remains readable.
- **Rollback Plan:**  
  Additive schema changes only. The existing `case_numbers` table is never altered or dropped until all dependencies are verified.

---

### Protocol 3: Retiring Procedure Catalog Redundancy (`treatment_catalogs` $\rightarrow$ `clinical_procedures`)
- **Current State:**  
  `clinical_procedures` has 46 rows and is the active master procedure list. `treatment_catalogs` has 48 rows from an earlier migration.
- **Dependency Audit:**  
  - 5 references in `app/` point mostly to the service class `App\Services\Treatment\TreatmentCatalog`, NOT the model.
- **Migration Impact:**  
  Compare the 48 rows of `treatment_catalogs` against `clinical_procedures`. Insert any missing procedure codes into `clinical_procedures`.
- **Rollback Plan:**  
  Do not drop `treatment_catalogs` table immediately. Mark the Eloquent model `@deprecated` and log an alert if any write hits the legacy table.

---

## 4. Query Performance & Anti-Pattern Rectification

### 4.1 N+1 Query Elimination in Patient Directory
- **Current Defect:** `PatientDirectory::paginate()` loads patient rows, but lazily evaluates doctor names and active case numbers when formatting the Inertia JSON payload.
- **Fix:** Eager-load relations natively:
  ```php
  $query->with([
      'currentPractitioner:id,name',
      'alerts:id,patient_id,title,severity',
  ])->withExists('visits as has_attended_visits');
  ```

### 4.2 Unbounded History Queries on Patient 360
- **Current Defect:** Opening Patient 360 previously triggered full relation loading for all historical timeline events and visits for patients with years of history.
- **Fix:** Strictly paginate Zone 5 (Visits) and Zone 8 (Timeline) with cursor/keyset pagination (`limit 15`), loading older records on demand.

### 4.3 Redundant Scope Bypasses
- **Current Defect:** Services frequently call `withoutGlobalScopes()->where('clinic_id', $clinic->id)` to prevent Spatie team context mismatch during route-model binding.
- **Fix:** Preserve `TenantScope` globally and ensure `ResolveClinicContext` and `SetCurrentBranch` are permanently prioritized before `SubstituteBindings` in `bootstrap/app.php` (already verified in baseline).
