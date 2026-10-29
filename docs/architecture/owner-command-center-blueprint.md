<!--
  Owner Command Center: master blueprint (canonical repository copy)

  Provenance: everything between the "BEGIN VERBATIM" and "END VERBATIM" markers
  is the A–Z blueprint exactly as prepared on 2026-10-22, copied byte for byte:
    file:    OralCares_Owner_Command_Center_A-Z_Blueprint.md (Claude session scratchpad)
    lines:   746
    bytes:   56,090
    SHA-256: 6aefc5d400a72f50faf6fc89e2a615612fd7b0daf16ff218e1bd0187772e458b
  Addenda A and B follow it. Where an addendum supersedes a statement in the
  verbatim part, the addendum names the item it supersedes; the verbatim text
  is left unchanged as the historical record.

  Status: planning only. No part of this document authorizes implementation.
-->

# Owner Command Center: Master Blueprint

**Repository copy created:** 2026-10-22, at the planning checkpoint.
**Read in this order:**
1. The verbatim A–Z blueprint (Deliverables 1–10).
2. **Addendum A:** decisions confirmed at this checkpoint, which supersede the named items.
3. **Addendum B:** the consolidated decision register. Every entry is labelled CONFIRMED, PROPOSED — NOT APPROVED, UNDECIDED or DEFERRED.

<!-- BEGIN VERBATIM: A–Z blueprint, 2026-10-22 -->

# OralCares Owner Command Center: A–Z Master Blueprint and Coverage Audit

**Date:** 2026-10-22. **Type:** read-only planning and audit; nothing in the repository, database or runtime was changed.
**Repository:** branch `wip/m0-snapshot-2026-10-22`, HEAD `6bd98695719062a4910c1d00675194d3c3b33849`. The uncommitted working tree has 39 entries.
**Scope:** cloud multi-tenant SaaS only. Hybrid local/cloud and offline-first operation are **deferred and excluded**. `resources/js/lib/offline.ts` is left untouched.

### Status legend

| Code | Meaning |
|---|---|
| **V** | Implemented and verified: code plus passing tests, and a browser check where one is noted |
| **B** | The backend exists and is tested, but there is no owner-facing page (the React page is missing) |
| **P** | Partially implemented |
| **N** | Not implemented |
| **D** | Needs a decision before it can be built |
| **U** | Not verified: the code exists, but there is no evidence that it behaves as intended |

### Where the evidence comes from

- Routes: `routes/web.php`, which has 301 `clinic` routes.
- Controllers in `app/Http/Controllers/Clinic/**`, services in `app/Services/**`, and support classes in `app/Support/**`.
- The 340 migrations, and read-only `SELECT`s against the dev database.
- The tests in `tests/Feature/Clinic/**`. Per-area test counts:

| Area | Tests | Area | Tests | Area | Tests | Area | Tests |
|---|---|---|---|---|---|---|---|
| Admin | 55 | CaseNumbers | 34 | Onboarding | 42 | Security | 98 |
| Appointments | 51 | Communication | 10 | Patients | 186 | Shell | 3 |
| Auth | 37 | Consultations | 86 | Portal | 149 | Staff | 91 |
| Billing | 35 | FollowUps | 4 | Practitioner | 21 | Tenancy | 24 |
| Business | 24 | Foundation | 43 | Reception | 145 | Treatments | 70 |
| Governance | 20 | Reports | 6 | Visits | 2 | WhatsApp | 21 |

- The React pages in `resources/js/pages/**`. There are 41 distinct render targets; 15 have a page file and **26 do not**.

### Dev servers

The dev servers on ports 8000 and 5173 were **not running** when this audit started. Neither port answered, and no preview process was registered. They ended with the previous app session, not because of anything this audit did. Following the instruction not to restart them, I left them down.

---

## Deliverable 1: Master Coverage Checklist

Every item has a stable ID: `<MODULE>-<nn>`. The ID is never reused, even if an item is dropped.

### A. Executive Dashboard (DSH)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| DSH-01 | KPIs: patients today, waiting, with doctor, collections, appointments | V | `DashboardWidgets` (`patients_today`, `waiting_now`, `with_doctor_now`, `collections_today`, `appointments_today`); `DashboardFiguresTest` |
| DSH-02 | Consultations completed today (clinic-wide) | P | `my_seen` covers only the practitioner's own; there is no clinic-wide completed count |
| DSH-03 | Appointment and queue lists (today's patients, upcoming, my queue) | V | Widgets `todays_patients`, `upcoming_appointments`, `my_queue` |
| DSH-04 | Money: billed today and this month, collected, outstanding, overdue, refunds, discounts | V | `DashboardData::ownerFinance` (Owner Mode, `billing.view`, billing module, clinic-wide) |
| DSH-05 | Adjustments and write-offs figure | N | Not part of `ownerFinance` |
| DSH-06 | Expenses, payables (approved but unpaid), pending expense payments | N | Exists only in the expenses page summary, and that page has no React file |
| DSH-07 | Branch activity and comparison (appointments, visits, collections) | V | `branch_activity` (`branch.manage`, clinic-wide, multi-branch) |
| DSH-08 | Staff availability today (on leave, scheduled) | N | — |
| DSH-09 | Login seats and plan capacity | N | `ResourceLimitService` has counts, but there is no widget |
| DSH-10 | "Needs your decision" (governed actions, expenses, payroll) | P | `needs_attention` counts governed requests only; expenses and payroll are not aggregated |
| DSH-11 | Payroll readiness (open period, entries to finalise, unpaid payslips) | N | — |
| DSH-12 | Follow-ups due and overdue | V | `follow_ups_due` |
| DSH-13 | Communication failures (WhatsApp failed or skipped) | N | The data exists (`clinic_whatsapp_messages.status`); there is no widget |
| DSH-14 | Security alerts (sign-in failures, Owner Mode checks, 7 days) | V | `owner_security` (Owner Mode) |
| DSH-15 | Recent sensitive actions (refunds, adjustments, role changes) | N | Audit events exist; there is no widget |
| DSH-16 | Plan, renewal, grace and lapsed state | P | `ClinicOwnerSubscription` (Owner Mode, sidebar card) and `EnforceSubscriptionWindow` banners; usage against limits is missing |
| DSH-17 | Per-role widget visibility set by the owner | V | `DashboardVisibility`, `PUT dashboard/visibility` (Owner Mode) |
| DSH-18 | Every figure drills down to the identical filtered list | N | No drill-down contract exists. The known divergence risk is recorded in memory `crm_metric_list_divergence` |
| DSH-19 | Date boundaries in clinic time zone (`ClinicClock`), branch scope, loading, empty and error states | P | `ClinicClock` is used throughout; the deferred widget data has loading and empty states; the drill-down pages don't exist |
| DSH-20 | Salary and financial data never sent to an unauthorized role | V | `requires` in `DashboardWidgets::compose`; the money widget is server-gated |

### B. Organization and Branches (ORG)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| ORG-01 | Clinic identity: name, display name, legal name, logo, invoice logo | B | `SettingsController::index` and `update`; the page `Clinic/Admin/Settings/Index` is missing |
| ORG-02 | Clinic switcher across several clinics | N | A session binds one membership. The only way to change clinic is to sign in again (`ClinicLoginController::membershipToSignInto`) |
| ORG-03 | Branch directory, create, update, delete | B | `Clinic\Admin\BranchController` (resource routes limited to index, store, update, destroy); the page `Clinic/Admin/Infrastructure/Branches` is missing; `BranchTest` |
| ORG-04 | Branch limit enforced when a branch is created | V | `ResourceLimitService::enforceBranchLimit` in `BranchController::store` |
| ORG-05 | Branch delete refused while history exists | V | `RetainedClinicalHistory`; FKs set to RESTRICT (scale audit) |
| ORG-06 | Branch status (active or inactive) and archival | P | `BranchStatus` enum; there is no archive UI and no archive policy |
| ORG-07 | Active branch context and switcher | V | `ResolveClinicContext`, `BranchSwitchController`, `BranchSwitcher.tsx` |
| ORG-08 | Departments and rooms CRUD | B | `DepartmentController` and `RoomController`; their pages are missing |
| ORG-09 | Room capacity | P | `branches.resource_capacity` column exists; no UI |
| ORG-10 | Opening hours | P | `ClinicOperatingHour` model and `operatingHours()` relation; UI coverage unverified (U) |
| ORG-11 | Clinic and branch holidays | N | Only `CompanyHoliday` exists, on the Company side |
| ORG-12 | Branch-level price overrides | P | `ClinicAdminPricingController` (`catalog/prices`); `CatalogPriceVersion` per branch per migration `2026_09_29_102600`; no page |
| ORG-13 | Staff placements and branch access | B | `StaffProfileController::branches`, `EmployeeController::update`; Owner Mode gate (1A.3, uncommitted) |
| ORG-14 | Branch financial and operational summary | P | `branch_activity` (today only); no per-branch period summary page |
| ORG-15 | Cross-branch comparison report | P | `patients_mix` report (by branch); no finance comparison |
| ORG-16 | Organization layer above the clinic (customer account) | P | `Organization` is a CRM customer owned by the Company; the clinic owner has no UI for it |
| ORG-17 | Legal Entity layer (Organization, then Legal Entity, then Clinic, then Branch) | N / D | Decided in principle; no schema (D11) |
| ORG-18 | Multi-clinic ownership and consolidated views | N / D | D13 |
| ORG-19 | GSTIN per branch | P | `branches.gstin` column (migration `2026_09_23_000001`); not used in billing |

### C. Patients (PAT)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| PAT-01 | Directory, search, filters, pagination | V | `ClinicPortal/Patients/Index`; Patients tests (186) |
| PAT-02 | New patient (full-page Create) | V | `ClinicPortal/Patients/Create` |
| PAT-03 | New Case and Repeat Case at the desk (intake) | B / P | `ReceptionController::intake` and `IntakeSubmissions`; the intake dialog components exist, but the FrontDesk page is missing |
| PAT-04 | Duplicate detection and override | V | `PatientDuplicateDetectionService`, `DuplicateOverride.tsx`; tests |
| PAT-05 | Patient 360 | B | `ClinicPortal/Patients/Show` is missing (removed in the 2026-10-11 reset); the `Patient360Sections` backend is tested |
| PAT-06 | Edit demographics and contact details | B | `ClinicPortal/Patients/Edit` is missing |
| PAT-07 | Documents (upload, scan, download) | B | Routes `documents.manage`; malware intake |
| PAT-08 | Communication consent captured and withdrawn | B | `consent.manage` routes; `ClinicConsentType`; master plan says New Case and Edit |
| PAT-09 | Encounters, diagnoses, notes, prescriptions, orders | B | Consultations routes (86 tests); `ClinicPortal/Consultations/Workspace` is missing |
| PAT-10 | Visit history and financial history | B | `Patient360Sections::bill` etc.; there is no page |
| PAT-11 | Follow-ups per patient | B | `PatientFollowUpController`; the `FollowUps/Index` board is missing |
| PAT-12 | Archive and merge, governed | V (backend) | `ArchivePatient` and `MergePatient` governed actions; `DeletionGovernanceTest`, `PatientMergeTest` |
| PAT-13 | Restore after archive | U | No restore route found |
| PAT-14 | Cross-branch visibility rules (branch-private search) | V | Patient Management P1.2 (memory); `GlobalSearchIsolationTest` |
| PAT-15 | Doctor assignment, My Patients, Transfer | B | `ClinicPatientController::transfer` |

### D. Appointments, Reception and Queue (APT)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| APT-01 | Calendar, booking, reschedule, cancel, status changes | V | `ClinicPortal/Appointments/Index`; 51 tests |
| APT-02 | Walk-in, New Case, Repeat Case check-in | B | Reception intake routes; the FrontDesk page is missing |
| APT-03 | Queue: case number plus position, doctor allocation, transitions, wait times | B | `QueueStateMachine`, `ReceptionController::updateQueueStatus`, `callNext` (queue module); the queue page is missing |
| APT-04 | Queue TV display | B / U | `QueueDisplayService`; there is no current page |
| APT-05 | Reception dashboard | P | Dashboard role composition (Front Desk widgets); the FrontDesk page is missing |
| APT-06 | Follow-up board | B | `FollowUpBoardController`; the `FollowUps/Index` page is missing ("Soon") |
| APT-07 | Fast search (top bar) | V | `ClinicSearchController` |
| APT-08 | Token system | — | Excluded by decision: the queue uses case number plus position |

### E. Clinical and Specialty (CLN): interfaces only, no workspace redesign

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| CLN-01 | Specialty activation per clinic, filtered by plan entitlement | V | `ClinicSpecialties`, `SpecialtyEntitlements`; `SpecialtyEntitlementTest` |
| CLN-02 | Specialty configuration, owner-only in Owner Mode | V (backend) | `PUT admin/specialties` (`specialty.configure` plus `clinic_owner_mode`) |
| CLN-03 | Dental odontogram, tooth charting, treatment plans, endo, implant, ortho records | B | `DentalController` and the `Dental*` services; the workspace page is missing |
| CLN-04 | Physio assessment, care plans, sessions, exercises, packages and allowances | B | `PhysioController`, `TreatmentCareController`, `PatientSessionPackage`; the page is missing |
| CLN-05 | Treatment catalogue (versions, stages, procedures, prices) | B | `ClinicAdminTreatmentCatalogController`; the `ClinicPortal/Admin/Catalog` page is missing |
| CLN-06 | Practitioner profiles, qualifications, services, schedule | B | `StaffProfileController` (`practitioner.manage`) |
| CLN-07 | Clinical write gate, signed records, addenda, void governance | V (backend) | `EncounterOwnershipTest`, governed `VoidEncounter` |
| CLN-08 | Physio sees no tooth chart; Dental sees no physio modules | V (backend) | Specialty flags and extensions (`odontogram`, `session_packages`) |

### F. Billing and Financial Operations (BIL)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| BIL-01 | Bill creation, items, tax, outstanding | V (backend) | `BillingService::createBill`; `PatientBillingTest` |
| BIL-02 | Bills list and checkout pages | B | `CheckoutController::index` and `show`; `ClinicPortal/Billing/Index` and `Checkout` are missing |
| BIL-03 | Collect payment, idempotent, branch-checked | V | `CheckoutController::pay`, `IntakeSubmissions`; `MoneyRequestIdempotencyTest`; target-branch check (stabilization, uncommitted); MySQL race test |
| BIL-04 | Receipts and printing | V (backend) | `bills.print`; `ReceiptAndPaymentMessagesTest` |
| BIL-05 | Payment message retry, branch-checked | V | `retryPaymentMessages` with `payment.collect` at the payment's branch |
| BIL-06 | Refunds: idempotent, capped, branch-checked, owner T1, audited | V | `PaymentService::recordRefund`; tests; `billing.refunded` audit (uncommitted) |
| BIL-07 | Adjustments and write-offs: audited, branch-checked, owner T1 | P | No approval or threshold, and the **Billing role holds `discount.apply`** (D4) |
| BIL-08 | Discounts at bill creation | V (backend) | `BillingService` (`billDiscount`) |
| BIL-09 | Bill void, governed | V | `VoidBill` |
| BIL-10 | Expenses: record, decide, pay, maker-checker, branch rules, sole-owner paths | V (backend) | `ClinicExpenses`; 4 test files; MySQL races; the `ClinicPortal/Expenses/Index` page is missing |
| BIL-11 | Expense categories, vendors, recurring | V (backend) | `ClinicExpenses`; `RaiseRecurringExpenses` |
| BIL-12 | Receivables and ageing | P | `finance_outstanding` report; dashboard outstanding and overdue; no ageing buckets page |
| BIL-13 | Payables (approved, unpaid) | P | Expenses summary `unpaid`; no vendor ledger |
| BIL-14 | Financial Summaries tab | N | Would be composed from existing queries |
| BIL-15 | Daybook and cash reconciliation | N / D | Needs schema (D2) |
| BIL-16 | Exports | P | Reports CSV (`ClinicReportExport`); there is no billing-list export |
| BIL-17 | Legacy `invoices` and `payments` against canonical `patient_billings` | P / D | `PatientBilling` is canonical. Dev data counts: legacy invoices 0, payments 6. Reconciliation is not done (D24) |
| BIL-18 | Concurrency: payments, refunds, expense decide and pay | V (local MySQL, 6 scenarios) | `tests/Concurrency/FinancialRaceTest.php` |
| BIL-19 | Tax: one rate per line, inclusive or exclusive | P | `BillingService::applyTax`; no GST split (see ACC) |

### G. Accounting and Tax (ACC): future programme

| ID | Capability | Status | Needs |
|---|---|---|---|
| ACC-01 | Chart of accounts | N | Schema; accountant review |
| ACC-02 | Double-entry journals | N | Schema; D12 |
| ACC-03 | General ledger and subledgers (AR, AP) | N | Schema |
| ACC-04 | Trial balance | N | ACC-02 |
| ACC-05 | Profit and loss | N | ACC-02 |
| ACC-06 | Balance sheet | N | ACC-02 |
| ACC-07 | Cash-flow statement | N | ACC-02 |
| ACC-08 | Bank accounts and reconciliation | N | Schema; bank feeds decision |
| ACC-09 | Vendor and payable accounting | N | ACC-03 |
| ACC-10 | GSTIN ownership (branch or entity) | D | D11; a `branches.gstin` column exists |
| ACC-11 | HSN/SAC, CGST/SGST/IGST, place of supply, tax invoice | N | GST and tax expert review; healthcare exemptions |
| ACC-12 | Credit notes | N | — |
| ACC-13 | GST returns (GSTR-1, GSTR-3B) | N / D | Legal and tax review |
| ACC-14 | Period close and lock | N | — |
| ACC-15 | Tally or Zoho integration against native books | D | D12 |

### H. Staff and People (STF)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| STF-01 | Directory and profiles | B | `EmployeeController`, `StaffProfileView`; the `ClinicPortal/Staff/Index`, `Show` and `Form` pages are missing (91 Staff tests) |
| STF-02 | Supported roles: Clinic Admin, Doctor, Receptionist, Billing Staff, plus the primary-owner flag | V | `ClinicRole` has 4 cases. The "Staff" permission template has **no** `ClinicRole` case, so it can't be assigned |
| STF-03 | Invitations, acceptance, revoke | V (backend) | `StaffInvitationService`, `InvitationAcceptance`; `AcceptInvitation` page exists |
| STF-04 | Suspension and offboarding remove access | V | `ResolveClinicContext` excludes access-ending employees; `ClinicLoginController` |
| STF-05 | Staff documents and verification | B | `StaffDocumentController`; the `Staff/DocumentSettings` page is missing |
| STF-06 | Role assignment per branch | V (backend) | `ClinicRoleAssignmentService`; owner T1 (1A.2) |
| STF-07 | Block changing your own roles or branch access | N | Only self-deactivation is blocked (`EmployeeController::updateStatus`) (D6) |
| STF-08 | Branch placements and access | V (backend) | owner T1 (1A.3) |
| STF-09 | Payroll-only profiles (no login, no seat) | V | A seat is held only by an active membership (`ResourceLimitService::seatHolders`) |
| STF-10 | Staff history and audit | P | `access.roles_changed`, `staff.updated`, `access.branches_changed` audit events; no history page |
| STF-11 | Account recovery and reassignment | P / U | Password reset and setup links (`ClinicPasswordToken`); owner handover is undefined (D) |
| STF-12 | Authorized additional owner | N | Only one `is_primary_owner` (D21) |
| STF-13 | Shifts and schedules | P | `PractitionerSchedule` for practitioners; no staff shifts |

### I. Seats and Plan Entitlements (SEAT)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| SEAT-01 | Total login cap (`max_users` plus `additional_users` add-ons) | V | `ResourceLimitService::assertSeatAvailable` (locked); `SeatEntitlementTest` |
| SEAT-02 | Doctor seat cap (`max_doctors` plus `additional_doctors`) | V | Same |
| SEAT-03 | Staff cap (`max_staff`) | P | Supported, but **null in every dev-DB plan** |
| SEAT-04 | Reception pool, owner pool, separate clinical pool | N / D | No columns; Physio Standard needs them (D10) |
| SEAT-05 | Owner takes a staff seat | V | The owner membership is a seat holder with no doctor role |
| SEAT-06 | Activating someone when the plan is full is refused | V | `EmployeeController::holdSeatFor` |
| SEAT-07 | Branch cap | V | `max_branches`, `enforceBranchLimit` |
| SEAT-08 | Commercial plans (Dental Standard ₹2,500, Physio Standard ₹1,500, the other two Dental plans) | **D** | **No source evidence.** The dev DB has Starter ₹4,999, Growth ₹12,999, Enterprise ₹34,999, Professional ₹190.86 and two Audit test plans. The add-on catalogue (`company_package_addons`) is empty (D18) |
| SEAT-09 | Feature modules per plan | V | `company_plan_features`: patients, appointments, billing, emr, inventory, whatsapp, analytics, lab, xray, dental, physiotherapy |
| SEAT-10 | Behaviour on upgrade, downgrade or exceeding a limit | P / D | Change-plan keeps the existing limits unless `adopt_plan_limits` is set (M0 note); over-limit after a downgrade is undefined |
| SEAT-11 | Expiry: trial, grace, lapsed | V | `EnforceSubscriptionWindow` (402 when lapsed; banners) |
| SEAT-12 | Clinic with no subscription is unrestricted | D | 11 of 32 dev clinics (master audit); policy needed (D18) |
| SEAT-13 | Owner view of seats and branches used against included | N | Phase 1 read model |

### J. Payroll (PAY)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| PAY-01 | Salary structures (fixed, daily, hourly, per-session, commission) | V (backend) | `ClinicPayroll::setSalary`; owner T1 |
| PAY-02 | Monthly periods, entries, lines (incentive, allowance, deduction, advance recovery) | V (backend) | `ClinicPayrollTest` |
| PAY-03 | Unpaid-leave deduction | V (backend) | `unpaidLeaveDays` |
| PAY-04 | Finalise (locked, numbered payslips) | P | Owner T2. **No maker-checker** (D3) |
| PAY-05 | Pay (and recover advances) | P | Owner T2; no approval |
| PAY-06 | Reopen a finalised period | N | Not supported; decide whether it is ever allowed |
| PAY-07 | Payslip view and print | V (backend) | `payroll.payslip` |
| PAY-08 | Salary privacy | V | `payroll.view`; salaries kept out of the audit trail (test) |
| PAY-09 | Statutory PF, ESI, TDS, PT | N | Expert review |
| PAY-10 | Allocation by branch or entity | N / D | Clinic-wide today |
| PAY-11 | Accounting postings | N | ACC |
| PAY-12 | Payroll UI | B | `ClinicPortal/Payroll/Index` is missing |

### K. Leave and Attendance (LVA)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| LVA-01 | Request, approve, reject, cancel | V (backend) | `LeaveWorkflow`, `LeaveController`; the page is missing |
| LVA-02 | Balances | P | `LeaveService` (clinic) and `CompanyLeaveBalance` (Company only); clinic balances unverified (U) |
| LVA-03 | Approver is not the requester | **N** | `LeaveWorkflow::approve` has no check (D7) |
| LVA-04 | Effect on the calendar and on appointments | V (backend) | `LeaveWorkflow::affected`; Appointments `LeaveReviewDialog` |
| LVA-05 | Clinic attendance (clock in and out, corrections, shifts) | **N** | Only `company_attendance_*` tables exist |

### L. Settings (SET)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| SET-01 | Profile and branding | B | `SettingsController` |
| SET-02 | Intake: check-in, quick care, visit types | B | `settings.check-in`, `settings.quick-care`, `settings.catalogue.*` |
| SET-03 | Patient form | V | `ClinicPortal/Settings/PatientForm` |
| SET-04 | Catalogue and pricing | B | Catalog routes |
| SET-05 | Consents | B | `ConsentTypeController`; the `ClinicPortal/Admin/Consents` page is missing |
| SET-06 | Case number and receipt numbering | B | `settings.case-number`; `NumberingEngine` |
| SET-07 | Bill settings (tax mode, rate, payment methods) | P | `clinics.billing_config` written at onboarding; edit UI unverified (U) |
| SET-08 | Notification preferences | P | `CommunicationPreference` model; owner UI unverified |
| SET-09 | WhatsApp preferences | B | `WhatsAppController` (see WA) |
| SET-10 | Specialties | V (backend) | CLN-02 |
| SET-11 | Settings audit history | P | `ClinicSettingAudit` (recent changes on the settings index); patient-form and catalogue changes go to `SecurityAudit` |
| SET-12 | Inheritance: organization, then branch, then role or user, then platform | **N** | `BranchSettingsService::getSetting` never falls back to the clinic setting; `OrganizationSetting` is Company-side; there are no role or user preferences |
| SET-13 | Dashboard visibility per role | V | DSH-17 |

### M. WhatsApp and Communications (WA)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| WA-01 | Clinic preferences: enabled, mode, automations, opt-in, reminder hours | B | `ClinicWhatsAppSettings` (no credentials stored clinic-side); `WhatsApp/Index` page is missing; 21 tests |
| WA-02 | Company number by default; clinic asks for its own number | V (backend) | `WhatsAppController::requestOwn`; activation on the Company side (`company.clinic-whatsapp.*`) |
| WA-03 | Credentials isolated per tenant | V (by design) | Credentials live on the Company side only; the clinic UI never receives them |
| WA-04 | Templates | P | `WhatsAppTemplate` (Company); clinic choices unverified |
| WA-05 | Delivery status, failures, retries, duplicate prevention | V (backend) | `clinic_whatsapp_messages.idempotency_key`, retry route |
| WA-06 | Consent and opt-out enforced | V | `ClinicWhatsApp::agreed` (M0) |
| WA-07 | Message history | B | `WhatsAppController::index` (status filter) |
| WA-08 | Usage and limits, Company visibility | P / D | Company-side views exist; clinic usage limits undefined |
| WA-09 | SMS, email, in-app delivery | P | `NotificationService` marks these **skipped** when there is no provider (M0) |

### N. Reports (RPT)

All reports run through `ClinicReports`. The page needs `reports.view` **plus the `command_center` workspace** (owner or `staff.manage`), and each report then has its own gate. Report data is not reconciled against the lists it summarizes (U).

| ID | Report key | Group | Extra gate | Filters |
|---|---|---|---|---|
| RPT-01 | patients_new | Patients | — | period, branch, specialty |
| RPT-02 | patients_activity | Patients | — | period, branch |
| RPT-03 | patients_mix | Patients | — | period |
| RPT-04 | operations_visits | Operations | — | period, branch, specialty |
| RPT-05 | operations_appointments | Operations | — | period, branch, practitioner |
| RPT-06 | staff_workload | Staff | — | period, branch, specialty |
| RPT-07 | dental_procedures | Dental | `odontogram` extension | period, branch, practitioner |
| RPT-08 | dental_treatments | Dental | `odontogram` plus `billing.view` | period, branch, practitioner |
| RPT-09 | dental_teeth | Dental | `odontogram` | period, branch |
| RPT-10 | physio_packages | Physio | `session_packages` | period, branch |
| RPT-11 | physio_expiring | Physio | `session_packages` | branch |
| RPT-12 | physio_therapists | Physio | `session_packages` | period, branch, practitioner |
| RPT-13 | finance_revenue | Finance | `billing.view` | period, branch |
| RPT-14 | finance_outstanding | Finance | `billing.view` | branch |
| RPT-15 | staff_payroll | Staff | `payroll.view` | period |
| RPT-16 | expenses | Expenses | `expense.manage` | period, branch |
| RPT-17 | follow_ups | Follow-ups | — | period, branch, practitioner |
| RPT-18 | whatsapp | WhatsApp | — | period |
| RPT-19 | Export (CSV, throttled 10 per minute, stored, downloaded per user) | — | — | V (backend); `ClinicReportExport` |
| RPT-20 | Branch finance comparison | — | — | N |
| RPT-21 | Background generation for large exports | — | — | U |
| RPT-22 | Reports page | — | — | B: the `ClinicPortal/Reports/Index` page is missing (6 tests) |

### O. Approvals and Governance (APR)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| APR-01 | Governed void and remove (9 actions) | V | `DeletionGovernance`; 11 tests plus 9 page tests |
| APR-02 | Approvals page: open and decided, requester view, decide, ask, answer, withdraw | P | Built in P1 (uncommitted); browser-checked on QA clinic 62; still to aggregate expenses and payroll |
| APR-03 | Step-up contract (`OwnerModeRequired`, `X-Owner-Mode-Required`, `executed:false`, retry once) | V (uncommitted) | `ApprovalsPageTest`; browser check |
| APR-04 | Expense decisions in the inbox | N | Phase 1.6 read model |
| APR-05 | Payroll decisions in the inbox | N | D3 |
| APR-06 | Same service whether deciding from the inbox or in place | V for governance and expenses; design rule for the rest | — |
| APR-07 | Configurable thresholds, delegation, escalation | N / D | Future programme P2 |
| APR-08 | Expiry (30 days) and a clean failure path (success never flashed on failure) | V | `expireStale`; P1 controller fix |

### P. Owner Mode and Security (SEC)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| SEC-01 | Owner Mode T1: 15 minutes idle, 60 minutes absolute | V (uncommitted) | `OwnerMode`; `OwnerModeStepUpTest` |
| SEC-02 | T2: password confirmed within 5 minutes | V (uncommitted) | `freshlyVerified()` |
| SEC-03 | Owner Mode ends on expiry, sign-out or clinic change | V | `matchesContext`; tests |
| SEC-04 | Refusals audited and not lost to a rollback | V | Gates run before transactions (1A.2 stabilization) |
| SEC-05 | Tenant isolation | V | `TenantScope`; Tenancy and Security suites |
| SEC-06 | Branch isolation for money actions | V | `hasClinicPermissionAt` |
| SEC-07 | IDOR on financial records | V | Clinic-scoped `find` returns 404 |
| SEC-08 | Mass assignment | U | `$fillable` throughout; not audited per model |
| SEC-09 | Role escalation by oneself | N | STF-07 |
| SEC-10 | Rate limits | P | Named limiters: clinic-login, clinic-password, clinic-owner-mode, clinic-patient-lookup, api-global-search, api-notifications, api-geo; plus `throttle:` on money routes |
| SEC-11 | MFA | N | Future work |
| SEC-12 | Hash-chained audit trail | V | `SecurityAudit`, `security:verify-audit-chain` |
| SEC-13 | Periodic access review | N | — |
| SEC-14 | Clinic Admin step-up | N (by decision) | D16 |

### Q. Support, Notifications, System Health (SUP)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| SUP-01 | Support tickets: raise, view, status | V | `ClinicPortal/Support/*`; no clinic reply, which protects the SLA clock |
| SUP-02 | Notifications and unread count | V | `ClinicPortal/Notifications` |
| SUP-03 | Operational warnings for the owner (failed jobs, failed messages) | N | — |
| SUP-04 | Backup status visible to the owner | N | Backups run platform-wide |
| SUP-05 | Company support access to clinic data, audited | U | No break-glass flow found |

### R. Inventory, Equipment, Vendors (INV)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| INV-01 | Inventory items, batches, transactions, transfers, purchase orders, vendors | **P (models only)** | `Inventory*` and `Stock*` models; **no clinic routes**; M0 says scope them by tenant before routing |
| INV-02 | Expense vendors | V (backend) | `ClinicVendor` |
| INV-03 | Equipment and maintenance | N | — |
| INV-04 | Link to expenses and payables | N | — |

### S. Subscription and Company Controls (SUB)

| ID | Capability | Status | Evidence / gap |
|---|---|---|---|
| SUB-01 | Plan name, state and end date, owner-only in Owner Mode | V | `ClinicOwnerSubscription` |
| SUB-02 | Usage against limits (seats, branches, modules) | N | SEAT-13 |
| SUB-03 | Add-ons listed | N | The catalogue is empty |
| SUB-04 | Upgrade or add-on request | N | Possible route through Support |
| SUB-05 | Payment status and invoices (Company `CommercialInvoice`) | N | No clinic-side view |
| SUB-06 | Clinic cannot change pricing or entitlements | V | No clinic routes for these (Phase 0 grep) |

### OPS. Scale and Reliability (cloud)

| ID | Capability | Status |
|---|---|---|
| OPS-01 | Cache keys isolated by tenant (`TenantCache`) | P / U |
| OPS-02 | Encrypted backups, scheduled run and verify | P |
| OPS-03 | Measured restore drill (recovery time and data loss) | N |
| OPS-04 | Request metrics and readiness probe | P |
| OPS-05 | Error tracking and alerting | N |
| OPS-06 | CI pipeline | N |
| OPS-07 | Load test at 100,000+ clinics | N (harness exists, no database, no runs) |
| OPS-08 | Queue workers: tenant context in jobs (`RunsForClinic`) | V |
| OPS-09 | Incident response runbook | N |
| OPS-10 | Hybrid and offline | **Deferred, excluded** |

---

## Deliverable 2: Navigation and Page Map

The sidebar and top bar are **frozen**. Everything below is an in-page tab or a section inside an existing destination. **(F)** marks future work, which is not built in Phase 1 and gets no navigation entry until it exists.

```
Sidebar · Main
├─ Dashboard ........................ Owner Command Center home (DSH)
├─ Appointments ..................... calendar (APT-01)
├─ Patients ......................... list · Create · Patient 360 · Edit (PAT)
├─ Queue (existing approved item) ... Front Desk (APT-02/03) — not Owner-specific
├─ Billing
│   ├─ Bills & Collections (BIL-01..05)
│   ├─ Refunds & Adjustments (BIL-06..09)
│   ├─ Expenses (BIL-10..11; in-place approvals APR-04)
│   ├─ Financial Summaries (BIL-12..14)
│   └─ Daybook & Cash Reconciliation (F, BIL-15)
└─ Reports .......................... RPT-01..22

Sidebar · Additional (existing, unchanged)
├─ Follow-ups (APT-06) · Notifications (SUP-02) · Support (SUP-01)

Account menu · Clinic administration
├─ Approvals ........ Inbox: Open | Decided  (APR)
├─ Staff / People
│   ├─ Directory · Profile · Invite · Documents (STF)
│   ├─ Roles, Branch Access & Seat Usage (STF-06..08, SEAT-13)
│   ├─ Payroll (PAY)        ├─ Leave (LVA)        └─ Attendance (F, LVA-05)
├─ Branches ......... Branches · Departments · Rooms (ORG)
└─ Settings ......... Profile & Branding · Intake & Patient Forms · Catalogue & Pricing ·
                      Consents · WhatsApp · Specialties · Settings Audit History (SET, WA)
Account menu · Owner Mode on/off (existing) · Plan card (sidebar footer, existing)
```

**Navigation rules:**
- A menu item appears only when `ClinicNavigation` allows it **and** its route is listed in `ClinicFrontend::AVAILABLE`.
- A tab appears only when the server's `can` flags allow it.
- A tab locked by the plan shows an upgrade state. A tab locked by Owner Mode shows a locked state with "Enter Owner Mode".

---

## Deliverable 3: Role × Plan × Specialty × Branch Matrix

### Source rules
- Role permissions come from `ClinicPermission::defaultRolePermissions`.
- The owner holds every permission implicitly (`User::hasClinicPermission`), and Owner Mode is applied on top of that.
- **Branch scope:**
  - **S:** a single active branch (the role held at that branch).
  - **A:** every branch the user can access, with the permission checked at the record's branch.
  - **C:** clinic-wide (owner, or all-branch access).
- **OM** is the Owner Mode tier, which applies to the owner only.

### Matrix

| Module · action | Owner | Clinic Admin | Reception | Billing | Doctor | Branch scope | Plan module | Specialty | OM | Add-on |
|---|---|---|---|---|---|---|---|---|---|---|
| Dashboard: ops KPIs | ✓ | ✓ | ✓ (front desk) | ✓ (billing) | ✓ (own) | S / C (owner) | — | — | — | — |
| Dashboard: money (DSH-04) | ✓ | ✗ (no command center for money) | ✗ | ✗ | ✗ | C | billing | — | T1 | — |
| Dashboard: security (DSH-14) | ✓ | ✗ | ✗ | ✗ | ✗ | C | — | — | T1 | — |
| Patients: view, search | ✓ | ✓ | ✓ | ✓ | ✓ | A (branch-private search) | patients | — | — | — |
| Patients: create, edit | ✓ | ✓ | ✓ | ✗ | ✗ | A | patients | — | — | — |
| Patients: merge or archive (governed request) | decides (T1) | asks | asks | ✗ | ✗ | A | patients | — | T1 | — |
| Appointments: view, create, manage | ✓ | ✓ | ✓ | ✗ | view | A | appointments | — | — | — |
| Clinical: view, edit, prescribe | ✓ | ✓ | ✗ | ✗ | ✓ | A | emr | dental and/or physio | — | — |
| Billing: view | ✓ | ✓ | ✓ | ✓ | ✓ (view) | A | billing | — | — | — |
| Billing: create bill, collect | ✓ | ✓ | ✓ | ✓ | ✗ | the bill's branch | billing | — | — | — |
| Refund | ✓ | ✓ | ✗ | ✗ | ✗ | the bill's branch | billing | — | T1 | — |
| Adjustment or write-off | ✓ | ✓ | ✗ | **✓ (risk, D4)** | ✗ | the bill's branch | billing | — | T1 | — |
| Void a bill (governed) | decides | asks | asks* | asks* | ✗ | the bill's branch | billing | — | T1 (D9: T2) | — |
| Expenses: record, pay | ✓ | ✓ | ✗ | ✗ | ✗ | the expense's branch; branchless needs C | billing | — | T1 | — |
| Expenses: approve or reject | ✓ (not your own) | ✓ (not your own) | ✗ | ✗ | ✗ | the expense's branch | billing | — | T1; T2 sole-owner | — |
| Reports: open | ✓ | ✓ | ✗ | **✗ (has `reports.view` but no command center, D20)** | ✗ | per filter | analytics? (U) | per report | — | — |
| Reports: finance, payroll, expenses | ✓ | ✓ | ✗ | ✗ | ✗ | C | billing | — | — | — |
| Export | ✓ | ✓ | ✗ | ✗ | ✗ | per filter | — | — | — | — |
| Staff: view, manage | ✓ | ✓ | ✗ | ✗ | ✗ | reachable branches | — | — | — | — |
| Roles and branch access | ✓ | ✓ | ✗ | ✗ | ✗ | reachable branches | — | — | T1 | seats |
| Payroll: view (salaries) | ✓ | ✓ | ✗ | ✗ | ✗ | C | — | — | — | — |
| Payroll: manage | ✓ | ✓ | ✗ | ✗ | ✗ | C | — | — | T1 | — |
| Payroll: finalise, pay | ✓ | ✓ | ✗ | ✗ | ✗ | C | — | — | **T2** | — |
| Leave: request | ✓ | ✓ | ✓ | ✓ | ✓ | own | — | — | — | — |
| Leave: approve | ✓ | ✓ | ✗ | ✗ | ✗ | C | — | — | — | — |
| Branches: CRUD | ✓ | ✓ | ✗ | ✗ | ✗ | C | — | — | D8: T1 | branches |
| Settings | ✓ | ✓ | ✗ | ✗ | ✗ | C | — | — | — | — |
| Specialties | ✓ | ✗ | ✗ | ✗ | ✗ | C | specialty product | — | T1 | — |
| WhatsApp preferences | ✓ | ✓ | ✗ | ✗ | ✗ | C | whatsapp | — | — | own number (Company) |
| Approvals inbox | ✓ (decides) | ✓ (own requests; menu shown) | URL only, own (D22) | URL only, own | URL only, own | per request | — | — | T1 | — |
| Plan card | ✓ | ✗ | ✗ | ✗ | ✗ | — | — | — | T1 | — |

\* Reception and Billing can ask for a void only if they hold the action's request permission. This is unverified per action (U).

### Examples

1. **Physio Standard, Doctor at branch A only, no login:** a payroll-only profile. Takes no seat and sees nothing.
2. **Dental clinic, Billing Staff at branch A, with access to branches A and B:**
   - Collecting payment on a bill at branch B is refused with 403. This is verified, including under real concurrency on MySQL.
   - Can still write balances off at branch A without approval (risk R3).
3. **Owner, not in Owner Mode, opens Billing:**
   - Sees bills and can collect payments.
   - A refund opens the password step-up and then continues.
   - The money card shows a locked state.
4. **Physio clinic owner opens Reports:** the dental reports (RPT-07 to RPT-09) are not listed and not reachable, because the extension gate applies on the server.
5. **Lapsed subscription:** every clinic route answers with the 402 lapsed page (SEAT-11). Expected behaviour on the owner's plan page is decided under D18.

---

## Deliverable 4: Existing vs. Missing (summary)

- **V (complete and verified):**
  - Patients list and create; Appointments; Support; Notifications; the Patient Form setting.
  - Dashboard core.
  - Owner Mode and step-up.
  - Target-branch money checks.
  - Expense maker-checker and the sole-owner paths.
  - Governance.
  - Tenant isolation.
  - Seat caps (total and doctors) and the branch cap.
  - Leave's effect on appointments.
  - WhatsApp consent.
  - MySQL race safety (6 scenarios, local).
- **B (backend done, no page):** 26 render targets, listed below.
- **P (partial):**
  - Dashboard: decisions, seats and drill-downs.
  - Adjustment policy; receivables and payables.
  - Payroll: no maker-checker, no reopen.
  - Settings inheritance; settings audit coverage.
  - Plan usage view; branch summaries.
  - Inventory (models only); opening hours; WhatsApp usage.
- **N (not implemented):**
  - Daybook; accounting and GST (ACC-01 to ACC-15).
  - Attendance; statutory payroll.
  - Multi-clinic; Legal Entity.
  - Self-escalation block; leave separation; additional owners.
  - Clinic holidays; equipment.
  - Owner operational warnings; MFA; CI; error tracking; load tests; measured restore drill.
- **D (decision needed):** see Deliverable 6.

The 26 render targets with no React page:
- Branches, Departments, Rooms (Infrastructure)
- Settings/Index
- Visits/Index
- Admin/Catalog, Admin/Consents
- Billing/Checkout, Billing/Index
- Consultations/Workspace
- Expenses/Index
- FollowUps/Index
- FrontDesk/Index, FrontDesk/List
- Leave/Index
- Orders/Index, Orders/Show
- Patients/Edit, Patients/Show
- Payroll/Index
- Reports/Index
- Staff/DocumentSettings, Staff/Form, Staff/Index, Staff/Show
- Today/Index
- WhatsApp/Index

---

## Deliverable 5: End-to-End Workflows

Each workflow lists its steps, then its checks: **Auth** (authorization), **Idem** (idempotency or replay), **Audit**, **Fail** (failure modes) and **Recover** (how it recovers).

**WF-01 · New or repeat patient.** Search the mobile number, run the duplicate gate, then create (or take the Repeat Case path) and check in with any charge.
- **Auth:** `patient.create`, front office.
- **Idem:** intake idempotency key (`IntakeSubmissions`).
- **Audit:** patient and visit events.
- **Fail:** duplicate needs an override reason; already checked in returns a conflict.
- **Recover:** retrying with the same key returns the same result.

**WF-02 · Appointment through to consultation.** Book, check in, give a case number and queue position, call next, consult, sign, then check out.
- **Auth:** `appointment.*`, `queue.manage`, `clinical.edit` plus the write gate.
- **Fail:** an illegal state transition is refused (`QueueStateMachine`).
- **Audit:** queue history.

**WF-03 · Dental treatment.** Chart on the odontogram, plan, accept, carry out stage by stage, charge per stage.
**WF-04 · Physio package.** Assess, write a care plan, sell a package with a session allowance, complete sessions (each decrements), reverse a session if needed.
- **Auth (both):** `treatment.*`, `billing.create`.
- **Idem (both):** package sale key.
- **Fail (both):** allowance exhausted or package expired.

**WF-05 · Bill and money.** Create the bill, then any of: collect, receipt, refund, adjust.
- **Auth:** permission at the bill's branch; owner T1 for a refund or adjustment.
- **Idem:** keys on pay and refund. **Adjustment has no key (gap).**
- **Concurrency:** row lock; MySQL-verified.
- **Audit:** `billing.refunded`, `billing.adjusted`.
- **Fail:** overpayment and over-refund are refused.
- **Recover:** the step-up re-sends once only when the server says nothing was executed.

**WF-06 · Expense.** Record (always pending), approve or reject (by someone else), then pay (by someone else). Sole owner: direct decision (T2), then direct payment (T2).
- **Auth:** permission at the expense's branch.
- **Idem:** status check under a lock; MySQL-verified.
- **Audit:** `expense.*`, including the refusals.

**WF-07 · Staff.** Invite, accept (the seat check runs under a lock), then change roles or branches (owner T1), suspend (access ends), offboard.
- **Gap:** STF-07, a user editing their own roles or branches.

**WF-08 · Leave.** Request, then approve or reject. Calendar conflicts are reviewed. Payroll deducts unpaid leave.
- **Gap:** the approver may be the requester (LVA-03).

**WF-09 · Payroll.** Set salaries (T1), open the period, recalculate, add lines, finalise (T2), pay (T2).
- **Gap:** no maker-checker; no reopen policy.

**WF-10 · Settings and pricing.** Edit, save through the service, audit (`ClinicSettingAudit` or `SecurityAudit`).
- **Gap:** audit coverage across all settings is inconsistent.

**WF-11 · Follow-up and WhatsApp.** Create the follow-up, check consent, queue the message (idempotency key), deliver, retry on failure.
- **Fail:** no consent means skipped, never a fallback to another channel.

**WF-12 · Approval.** Request (one open per record, record fingerprinted), decide (owner T1, under a lock), executed. Alternatively ask a question, answer, decide; or withdraw; or expire after 30 days.
- **Idem:** a second approval is a no-op.

**WF-13 · Subscription.** Trial or grace banner, lapsed (402), renew on the Company side.
- **Gap:** upgrade request and usage view (SUB-02, SUB-04).

**WF-14 · Step-up.** A sensitive action is refused with `X-Owner-Mode-Required` and `executed:false`, the dialog asks for the password, and the action is re-sent once. Any other 403, 404, 422 or 401 is never retried.

---

## Deliverable 6: Decision Register

Columns: why it matters, recommended default **(R)**, cost of delay, whether it blocks Phase 1, and the review needed.

| ID | Decision | Why | (R) | If delayed | Blocks P1 | Review |
|---|---|---|---|---|---|---|
| D1 | Approvals exposure before the aggregated inbox | P1 page is linked now; `CommandCenterTest` fails | Hide until 1.6 (provisional) | Partial page stays in production | **Yes (1.0)** | Product |
| D2 | Daybook in Phase 1 or P2 | Needs schema | P2 | No cash control | No | Schema |
| D3 | Payroll maker-checker before the Payroll UI | Self-finalising payroll | Required (provisional) | Payroll UI blocked | **Yes (1.3)** | Possibly schema |
| D4 | Write-off threshold or approver; Billing role holds `discount.apply` | Unchecked write-offs | Owner approval above a threshold (P2); remove `discount.apply` from Billing? (decide) | Revenue leakage | Yes (1.2) | Business |
| D5 | Refund threshold | Same | Same as D4 | Same | No | Business |
| D6 | Block changing your own roles or branch access | Escalation | Block (provisional) | Security gap | **Yes (1.3)** | — |
| D7 | Leave approver is not the requester | Self-approval (verified gap) | Separate (provisional) | Abuse | Yes (1.3) | — |
| D8 | Branch create and delete need T1 | Seats and billing impact | T1 (provisional) | — | Yes (1.4) | — |
| D9 | Direct governed actions T1 or T2 | Bill void is financial | T2 for bill void (provisional) | — | Yes (1.6) | — |
| D10 | Seat-pool model (reception, owner, clinical pools) | Physio Standard can't be expressed | Pool schema in P2; P1 shows total and doctor caps only | Plans can't be enforced as sold | No (P1 displays the current caps) | **Schema** |
| D11 | What a Legal Entity owns; GSTIN per branch or per entity | Tax and books | Defer to P5 | Accounting blocked | No | Legal, tax |
| D12 | Native accounting or Tally/Zoho | Huge scope | Decide before P3 | P3 blocked | No | Accountant |
| D13 | Multi-clinic views: read-only or actionable | Owner experience | Read-only first (P5) | — | No | Product |
| D15 | Load-test targets and environment | 100,000+ claim | Define in P7 | Claims unverifiable | No | Ops |
| D16 | Clinic Admin step-up | Admin moves money without step-up | Keep "none" (confirm) | Risk | No | Security |
| D17 | Fate of the QA rows in clinic 62 and the `launch.json` entries | Hygiene | Keep for now; remove before commit | Clutter | No | — |
| **D18** | **Source of truth for commercial plans** (3 Dental plans, Physio Standard), add-on catalogue, and the no-subscription policy | Entitlement matrix can't be finalised | Company seeds the real plans on the Company side; clinics with no subscription get read-only or are blocked | Wrong limits enforced | **Yes (1.0 plan card and seats)** | Business |
| **D19** | Behaviour on downgrade, over-limit and expiry | Seats over the cap | Block new activations, keep existing users, warn | Ambiguity | No | Business |
| **D20** | Billing role has `reports.view` but can't open Reports (command-center gate) | Inconsistent | Allow Billing the finance reports only, or drop `reports.view` from Billing | Confusion | Yes (1.7) | Product |
| **D21** | Additional owners (co-owner) | Single `is_primary_owner` | Not in V1 | — | No | Product |
| **D22** | Staff reach the Approvals page by URL only (no menu entry) | Discoverability | Link from the request toast and Patient 360; no nav change | — | No | Product |
| **D23** | Restarting the dev servers (they are down) | Browser checks need them | Allow restart at Phase 1.0 start | No browser evidence | Yes (any browser check) | — |
| **D24** | Legacy `payments` (6 dev rows) reconciliation | Canonical billing | Read-only reconcile report, then decide | Reports may diverge | No | Finance |
| **D25** | Settings inheritance (branch falls back to clinic) | SET-12 | Clinic default plus branch override for prices and hours only, in V1 | Inconsistent config | No | — |
| **D26** | Payroll period reopen policy | Corrections | Not allowed; adjustments go into the next period | — | Yes (1.3) | Accountant |
| **D27** | Adjustment idempotency key | Double-submit | Add a key (same pattern as refunds) | Duplicate write-offs | Yes (1.2) | — |

D14 (hybrid) is withdrawn and does not return.

---

## Deliverable 7: Dependency-Aware Roadmap

Phase 1 keeps the structure from the previous blueprint.

**These gates apply to every phase, and none is skippable:**
- the SQLite full suite compared **by test name** against the 2026-10-21 baseline (15 failures);
- the MySQL concurrency suite (`phpunit.mysql.xml`);
- tsc no worse than 24 errors;
- `git diff --check` clean;
- no change to the sidebar or top bar;
- browser checks with a role set of owner, Clinic Admin, Reception, Billing and Doctor, at 1366px and 375px widths;
- every new page is linked in `AVAILABLE` only once it is complete.

| Phase | Scope (IDs) | Depends on | Backend / UI / schema | Migration risk | Security gates | Acceptance |
|---|---|---|---|---|---|---|
| **1.0 Foundations** | APR-03 (finalise), DSH-18 contract, SEAT-13 read model, DSH-10 read model, owner hub tab kit, Owner Mode notice and locked state; resolve the `CommandCenterTest` outcome | D1, D18, D23 | Backend read models; UI kit; **no schema** | None | Step-up only on the documented set | Each read-model count equals its list (tests); step-up works on one T1 and one T2 action in the browser |
| **1.1 Dashboard** | DSH-02, 05, 06, 08, 09, 10, 11, 13, 15, 16, 18 | 1.0 | Widgets plus drill-down routes to existing lists | None | Each widget's `requires` matches its target page | Each figure opens a list with the same count (tested per widget); staff dashboards unchanged |
| **1.2 Billing** | BIL-02, 07 (policy), 12, 13, 14, 16, APR-04 in place; D27 key | 1.0, D4, D27 | Pages; adjustment idempotency; no schema | Low | Target-branch checks; T1; maker-checker | MySQL races extended to adjustment; every Billing tab passes the role matrix |
| **1.3 Staff** | STF-01, 05, 06, 07, 10; SEAT-13 UI; LVA-01, 03; PAY-04, 12 | 1.0, D3, D6, D7, D26 | Pages; self-escalation block; leave separation; payroll maker-checker (**schema may be needed: approval**) | Medium if schema | T1 and T2; seats under a lock | Seats shown equal seats enforced; self-escalation, self-leave and self-payroll are refused (tests) |
| **1.4 Branches** | ORG-03, 06, 08, 09, 10, 12, 14 | 1.0, D8 | Pages; no schema | None | `branch.manage`; T1 (D8) | Matrix totals equal the dashboard's; delete refused while history exists |
| **1.5 Settings** | SET-01, 02, 04, 05, 06, 07, 09, 11 (all settings audited), WA-01, 07 | 1.0, D25 | Pages; audit coverage | None | `settings.manage`; specialties T1 | Each save audited; WhatsApp shows no credentials |
| **1.6 Approvals** | APR-02, 04, 05, 06 aggregated; D9 | 1.2, 1.3 | Aggregation read model | None | Same services as in place | Every pending item appears once; deciding from the inbox or in place gives identical results (tests) |
| **1.7 Reports** | RPT-01 to 22 page, RPT-20; D20 | 1.0 | Page; branch finance comparison | None | Per-report gates | Each report's totals reconcile with its source list (tests); exports throttled |

**Future programmes:** each has its own decisions and approvals, and none is counted complete because Phase 1 is planned.

| Programme | Scope | Depends on |
|---|---|---|
| P2 | Daybook (BIL-15); configurable approval policies (APR-07); seat pools (SEAT-04, D10); downgrade rules (D19) | Schema approval |
| P3 | Accounting and GST (ACC-01 to ACC-15) | D11, D12; accountant and tax review |
| P4 | Attendance (LVA-05); statutory payroll (PAY-09); payroll by branch or entity (PAY-10) | Expert review |
| P5 | Multi-clinic (ORG-02, ORG-18); Legal Entity (ORG-17) | D11, D13 |
| P7 | Cloud scale, security and recovery evidence (OPS-03, 05, 06, 07, 09; SEC-11, SEC-13) | D15 |
| — | Inventory and equipment (INV-01, INV-03, INV-04) | Product decision; tenant scoping first |
| **Deferred, excluded** | Hybrid local/cloud and offline sync (OPS-10) | — |

**Out of scope for Phase 1 overall:** everything in P2–P7, the inventory and equipment programme, and the clinical workspace pages (PAT-05, PAT-06, PAT-09; CLN-03, CLN-04), which belong to the clinical programme.

---

## Deliverable 8: Definition of Done (Owner Command Center V1)

V1 is complete when **every** one of the following holds:

1. Every Phase 1 page in Deliverable 2 exists. No owner-facing in-scope menu item or tab shows "Soon", is a dead link, or is a placeholder.
2. Every action in the role matrix (Deliverable 3) has a server test for **allowed** and for **denied**, for each role.
3. Every money-moving or money-reversing action has:
   - an idempotency key or a guard on its state;
   - a row lock;
   - a passing MySQL race test.
4. Every dashboard figure has a test showing it equals the count or total of the list it opens, in clinic time zone, with the same branch scope.
5. Every T1 and T2 action steps up in place and re-sends once only when the server says `executed:false`. Non-owners never see a step-up prompt.
6. Locked states are explicit and distinct: plan lock, specialty lock, Owner Mode lock and permission denial each look different. No locked data is sent to the browser.
7. Every sensitive action writes an audit event. Refusals are audited and survive rollback.
8. Dental and Physio clinics each pass a browser walkthrough that shows no features from the other specialty.
9. Every page works at 375px and 1366px wide, with no horizontal scroll and touch targets of at least 44px.
10. Every regression gate in Deliverable 7 passes, and the full-suite failures equal the baseline **by name**.
11. The decisions marked "Blocks P1" in Deliverable 6 are resolved and recorded.

---

## Deliverable 9: Risk and Gap Register

Ranked by severity.

| # | Risk | Severity | Impact | Phase |
|---|---|---|---|---|
| R1 | 39-entry uncommitted working tree on a WIP branch, never pushed | High | Work could be lost | Before 1.0 (commit decision) |
| R2 | Payroll can be finalised and paid by its preparer | High | Financial | 1.3 (D3) |
| R3 | Billing role can write balances off without approval or a limit | High | Revenue leakage | 1.2 (D4) |
| R4 | A user can change their own roles or branch access | High | Escalation | 1.3 (D6) |
| R5 | A leave approver can approve their own leave (verified) | Medium | Abuse | 1.3 (D7) |
| R6 | Commercial plans in the dev database don't match the plans as sold; no add-on catalogue; clinics with no subscription are unrestricted | High | Wrong entitlements | 1.0 (D18) |
| R7 | Seat pools can't express Physio Standard | Medium | Plan can't be enforced as sold | P2 (D10) |
| R8 | P1 Approvals exposed while incomplete; `CommandCenterTest` failing | Medium | Partial UI | 1.0 (D1) |
| R9 | Adjustments have no idempotency key | Medium | Duplicate write-off | 1.2 (D27) |
| R10 | Legacy payments not reconciled | Medium | Report divergence | P2 (D24) |
| R11 | No drill-down equality tests (dashboard vs. lists) | Medium | Wrong decisions | 1.1 |
| R12 | No CI, error tracking, MFA or measured restore | High (production) | Operations | P7 |
| R13 | No load evidence for 100,000+ clinics | High (claim) | Scale | P7 |
| R14 | Inventory models routable later without tenant scoping | Medium | Isolation | When routed |
| R15 | Dev servers are down, so no browser verification is possible | Low | Evidence | D23 |
| R16 | Settings inheritance absent (branch never falls back to clinic) | Low | Configuration drift | 1.4 / 1.5 |

---

## Deliverable 10: Implementation Readiness

**Not ready to start Phase 1.0.** These must be resolved first:

1. **D1:** how the P1 Approvals exposure is handled, and therefore the intended `CommandCenterTest` outcome.
2. **D18:** the source of truth for the commercial plans (the three Dental plans and Physio Standard), the add-on catalogue, and the no-subscription policy.
3. **D23:** permission to restart the local dev servers for browser verification.
4. **R1:** whether to commit the existing uncommitted work, and how, before new phases build on it. This blueprint doesn't assume a commit.

**Must be resolved before the later steps** (they don't block 1.0):

| Step | Decisions |
|---|---|
| 1.2 | D4, D27 |
| 1.3 | D3, D6, D7, D26 |
| 1.4 | D8 |
| 1.6 | D9 |
| 1.7 | D20 |

**No canonical blueprint document exists in the repository.** `docs/` has no Owner Command Center file. This blueprint therefore lives outside the working tree. Proposed destination, pending your approval: `docs/architecture/owner-command-center-blueprint.md`.

<!-- END VERBATIM: A–Z blueprint, 2026-10-22 -->

---

## Addendum A: Decisions confirmed at the 2026-10-22 planning checkpoint

The product owner confirmed these decisions at the planning checkpoint. They are **requirements, not implementation claims**. Where one supersedes an item in the verbatim blueprint, the item is named.

### A1. Dental Standard (CONFIRMED)

**Supersedes:** SEAT-08 and D18 (in part) for this plan.

| Entitlement | Confirmed value |
|---|---|
| Price | ₹2,500 per month |
| Features | Full Dental feature set (the module list behind "full" is still UNDECIDED, see B1-14) |
| Branches | 1 included; extra branches are paid add-ons |
| Logins | 2 active doctor logins and 3 active staff logins |
| Owner login | Uses one of the 3 staff logins |
| Payroll-only profiles | Staff profiles kept only for payroll don't count against the active staff-login limit |
| Add-on prices | Extra doctor logins, extra staff logins, and every other add-on price: **UNDECIDED** |
| The other two Dental plans | **UNDECIDED** |

### A2. Physio Standard (CONFIRMED)

| Entitlement | Confirmed value |
|---|---|
| Price | ₹1,500 per month |
| Logins | 5 doctor logins, 1 reception login, and 1 Owner login |
| Branches | 1 included |
| Treating-doctor profiles | May exist without login access |
| Add-on prices | Extra branches and extra seats: **UNDECIDED** |

**How this compares with the code:** the seat model today has only a total-login cap and a doctor cap (SEAT-01, SEAT-02). It can't express Physio Standard's separate reception and Owner seats (SEAT-04, D10). It also can't express Dental's "3 staff logins including the Owner" separately from the total cap, because `max_staff` is supported but unset in every dev plan. Implementing these entitlements exactly is open: see B1-18.

The plan rows in the dev and test database (Starter, Growth, Enterprise, Professional, Audit plans) are **not** commercial truth.

### A3. Subscription expiry (CONFIRMED)

**Supersedes:** Deliverable 3 Example 5, WF-13 and SEAT-11, as the *target* behaviour.

1. A **7-day grace period** follows expiry.
2. **After the grace period, the clinic is read-only.**
3. The policy is **enforced on the server**, not by hiding things in the UI.
4. **Existing data is preserved.**
5. In read-only mode, **new clinical and financial writes are blocked**.
6. **Exporting existing data** while expired or read-only is allowed **only for an authorized Owner**, after Owner Mode and password verification, with server-side authorization and an audit log entry.
7. **Renewal restores access only after the subscription or payment status is verified.**
8. **No exceptions** beyond these are defined.

**Current implementation compared with this policy (evidence: `app/Http/Middleware/EnforceSubscriptionWindow.php`, `app/Services/Provisioning/EntitlementResolver.php`):**

| Policy point | Today | Status |
|---|---|---|
| 7-day grace | `EntitlementResolver::GRACE_DAYS = 7` exists and is used by trial provisioning. Paid-subscription grace comes from the stored `grace_ends_at`; that it is always set to 7 days is unverified | **Not verified** |
| Read-only after grace | After grace, `grants_access` is false and **every** clinic request gets a 402 lock-out page. That is full lock-out, not read-only | **Not implemented as specified** |
| Owner export while read-only | Impossible today: the lock-out blocks it | **Not implemented** |
| Server-side enforcement | Middleware on the clinic route group | Implemented (for lock-out) |
| Data preserved | Nothing is deleted on expiry; the lock-out message says data is retained | Implemented |
| Renewal restores access | Access follows the entitlement window computed from the subscription | Implemented for the window; payment verification is unverified |
| No subscription at all | `has_subscription` false → **unrestricted** (11 of 32 dev clinics) | Policy **UNDECIDED** (B2-10) |

---

## Addendum B: Consolidated Decision Register (v2)

**Labels:**
- **CONFIRMED:** stated or approved by the product owner.
- **PROPOSED — NOT APPROVED:** a recommendation that hasn't been approved.
- **UNDECIDED:** no decision yet.
- **DEFERRED:** explicitly moved to a later programme or out of scope.

Recommendations from earlier discussions are **not** treated as approved. The earlier IDs (D*, R1) are kept in brackets.

### B1. Commercial plans and entitlements

| ID | Decision | Label |
|---|---|---|
| B1-01 | Dental Standard costs ₹2,500 per month | CONFIRMED |
| B1-02 | Dental Standard includes 1 branch; extra branches are paid add-ons | CONFIRMED |
| B1-03 | Dental Standard has 2 active doctor logins and 3 active staff logins | CONFIRMED |
| B1-04 | Dental Standard's Owner login uses a staff login | CONFIRMED |
| B1-05 | Payroll-only staff profiles don't count toward active staff logins | CONFIRMED |
| B1-06 | Dental Standard includes the full Dental feature set | CONFIRMED |
| B1-07 | Physio Standard costs ₹1,500 per month | CONFIRMED |
| B1-08 | Physio Standard has 5 doctor logins, 1 reception login and 1 Owner login | CONFIRMED |
| B1-09 | Physio Standard includes 1 branch | CONFIRMED |
| B1-10 | Treating-doctor profiles may exist without a login | CONFIRMED |
| B1-11 | Extra seats and branches are paid add-ons, on both plans | CONFIRMED |
| B1-12 | Add-on prices and units (doctor seat, staff seat, reception seat, branch; monthly or prorated) | UNDECIDED |
| B1-13 | Names, prices and entitlements of the other two Dental plans (D18) | UNDECIDED |
| B1-14 | Which modules make up "full Dental" and the Physio feature set (lab, X-ray, WhatsApp, analytics) | UNDECIDED |
| B1-15 | Dental Standard's 3 staff logins are one shared pool covering reception, billing and admin | PROPOSED — NOT APPROVED |
| B1-16 | Whether Physio's reception seat can go to another non-doctor role, and whether the Owner seat can be swapped | UNDECIDED |
| B1-17 | Canonical plan source: plans configured in the Company portal, with dev and test rows never used (D18) | PROPOSED — NOT APPROVED |
| B1-18 | Seat-pool model (reception, Owner and staff pools); needs schema; programme P2 (D10) | PROPOSED — NOT APPROVED |
| B1-19 | Clinics running both specialties (plan combination) | UNDECIDED |
| B1-20 | Trial length for each plan, cancellation policy, billing cycles and annual plans | UNDECIDED |
| B1-21 | Downgrade or over-limit: block new activations, keep existing users, warn (D19) | PROPOSED — NOT APPROVED |

### B2. Subscription, grace, read-only and export

| ID | Decision | Label |
|---|---|---|
| B2-01 | 7-day grace period after expiry | CONFIRMED |
| B2-02 | Read-only mode after the grace period | CONFIRMED |
| B2-03 | Enforced on the server, not by hiding UI | CONFIRMED |
| B2-04 | Existing data preserved | CONFIRMED |
| B2-05 | New clinical and financial writes blocked in read-only mode | CONFIRMED |
| B2-06 | Export allowed only for an authorized Owner after Owner Mode and password verification, authorized on the server and audited | CONFIRMED |
| B2-07 | Renewal restores access only after the subscription or payment status is verified | CONFIRMED |
| B2-08 | Read-only export requires the T2 tier (password confirmed within 5 minutes) | PROPOSED — NOT APPROVED |
| B2-09 | Which other writes remain allowed in read-only mode (sign-in, password reset, support tickets, staff deactivation) | UNDECIDED |
| B2-10 | Clinic with no subscription at all is treated like an expired one: read-only, with Owner export (D18) | PROPOSED — NOT APPROVED |
| B2-11 | Whether trial expiry follows the same grace and read-only rules as paid expiry | UNDECIDED |
| B2-12 | Who or what verifies renewal (Company payment status, manual confirmation) | UNDECIDED |

### B3. Owner Mode, approvals and sensitive financial actions

| ID | Decision | Label |
|---|---|---|
| B3-01 | T1: 15 minutes idle, 60 minutes absolute | CONFIRMED |
| B3-02 | T2: password confirmed within the last 5 minutes | CONFIRMED |
| B3-03 | Re-verification extends only the T2 window, never the 60-minute lifetime | CONFIRMED |
| B3-04 | Owner Mode ends on expiry, sign-out or clinic change | CONFIRMED |
| B3-05 | Step-up re-sends the action once, only when the server guarantees nothing was executed | CONFIRMED |
| B3-06 | Non-owners are never offered the step-up | CONFIRMED |
| B3-07 | Clinic Admin has no step-up; templates unchanged (D16) | CONFIRMED |
| B3-08 | Hide the Approvals menu entry until Phase 1.6, keeping the route and code (D1) | PROPOSED — NOT APPROVED |
| B3-09 | Voiding a bill directly requires T2 (D9) | PROPOSED — NOT APPROVED |
| B3-10 | Creating or deleting a branch requires T1 (D8) | PROPOSED — NOT APPROVED |
| B3-11 | Approvals are a central inbox *and* available in context, using the same backend service | CONFIRMED |
| B3-12 | Expense maker-checker, including the sole-owner direct decide and pay paths (Option B) | CONFIRMED |
| B3-13 | Staff reach their own approval requests through in-context links, not a nav change (D22) | PROPOSED — NOT APPROVED |
| B3-14 | Configurable thresholds, delegation and escalation | DEFERRED (P2) |
| B3-15 | Sole-owner exceptions for actions other than expenses (for example payroll) | UNDECIDED |

### B4. Billing, refunds, adjustments, write-offs and idempotency

| ID | Decision | Label |
|---|---|---|
| B4-01 | `PatientBilling` is the canonical patient billing model | CONFIRMED |
| B4-02 | Expenses live inside Billing, with no separate nav item | CONFIRMED |
| B4-03 | Financial permissions are checked at the record's own branch | CONFIRMED |
| B4-04 | Write-off approval or threshold, and whether the Billing role keeps `discount.apply` (D4) | UNDECIDED |
| B4-05 | Refund threshold or second approver (D5) | UNDECIDED |
| B4-06 | Idempotency key on adjustments, same pattern as refunds (D27) | PROPOSED — NOT APPROVED |
| B4-07 | Legacy `invoices` and `payments` reconciled through a read-only report first, nothing deleted (D24) | PROPOSED — NOT APPROVED |
| B4-08 | Daybook and cash reconciliation in programme P2 (D2) | PROPOSED — NOT APPROVED |
| B4-09 | Configurable financial approval thresholds | DEFERRED (later task, as stated in 1A.1) |

### B5. Staff, login seats, leave and payroll

| ID | Decision | Label |
|---|---|---|
| B5-01 | Payroll and Leave live inside Staff / People | CONFIRMED |
| B5-02 | Payroll finalisation must not ship without maker-checker (D3) | CONFIRMED |
| B5-03 | The exact payroll maker-checker policy (who prepares, who checks; the owner's role) | UNDECIDED |
| B5-04 | No reopening a finalised period; corrections go into the next period (D26) | PROPOSED — NOT APPROVED |
| B5-05 | Block changing your own roles or branch access (D6) | PROPOSED — NOT APPROVED |
| B5-06 | The leave approver must not be the requester (D7; verified gap) | PROPOSED — NOT APPROVED |
| B5-07 | Clinic attendance | DEFERRED (P4) |
| B5-08 | Statutory payroll (PF, ESI, TDS, PT) | DEFERRED (P4) |
| B5-09 | No additional owners in V1 (D21) | PROPOSED — NOT APPROVED |
| B5-10 | Owner handover and owner account recovery | UNDECIDED |

### B6. Branches, organization ownership and multi-clinic access

| ID | Decision | Label |
|---|---|---|
| B6-01 | Hierarchy in principle: Organization → Legal Entity → Clinic → Branch | CONFIRMED |
| B6-02 | What a Legal Entity owns (books, bank, payroll); whether GSTIN is per branch or per entity (D11) | UNDECIDED |
| B6-03 | One Owner login with an authorized multi-clinic switcher and consolidated views | CONFIRMED (as a requirement) |
| B6-04 | Consolidated views: read-only or actionable (D13) | UNDECIDED |
| B6-05 | Branch archive policy | UNDECIDED |
| B6-06 | Clinic and branch holidays in scope | UNDECIDED |
| B6-07 | Implementing the Legal Entity layer and multi-clinic switching | DEFERRED (P5) |

### B7. Reports and role permissions

| ID | Decision | Label |
|---|---|---|
| B7-01 | The Billing role may open the finance reports only (D20) | PROPOSED — NOT APPROVED |
| B7-02 | Every report must reconcile with its source list (tested) | PROPOSED — NOT APPROVED |
| B7-03 | Background generation for large reports | UNDECIDED |

### B8. Settings inheritance and configuration

| ID | Decision | Label |
|---|---|---|
| B8-01 | V1 inheritance: clinic default plus branch override, for prices and opening hours only (D25) | PROPOSED — NOT APPROVED |
| B8-02 | Every settings change is audited | PROPOSED — NOT APPROVED |
| B8-03 | Full inheritance chain: organization → branch → role or user → platform | UNDECIDED |

### B9. WhatsApp separation

| ID | Decision | Label |
|---|---|---|
| B9-01 | Company-managed WhatsApp is kept separate from a clinic-owned account; the Company authorizes and activates | CONFIRMED |
| B9-02 | A clinic's credentials, messages and usage are never exposed to another clinic or to the clinic UI | CONFIRMED |
| B9-03 | Clinic WhatsApp usage limits and billing | UNDECIDED |

### B10. Accounting, GST, inventory and deferred scope

| ID | Decision | Label |
|---|---|---|
| B10-01 | Hybrid local/cloud and offline sync | DEFERRED (excluded) |
| B10-02 | Double-entry accounting, GST and bank reconciliation | DEFERRED (P3) |
| B10-03 | Native accounting or Tally/Zoho integration (D12) | UNDECIDED |
| B10-04 | Inventory and equipment workflows | DEFERRED (not in V1) |
| B10-05 | Inventory models scoped by tenant before any route is added | PROPOSED — NOT APPROVED |

### B11. Backup, security, performance and 100,000+ readiness

| ID | Decision | Label |
|---|---|---|
| B11-01 | Cloud backups and restore procedures stay in scope | CONFIRMED |
| B11-02 | A measured restore drill (recovery time and data-loss targets) | PROPOSED — NOT APPROVED |
| B11-03 | MFA | DEFERRED (P7) |
| B11-04 | When CI is introduced | UNDECIDED |
| B11-05 | Load-test targets and environment (D15) | UNDECIDED |
| B11-06 | Error tracking and alerting tool | UNDECIDED |
| B11-07 | 100,000+ clinic capacity is never claimed without measured evidence | CONFIRMED |

### B12. QA, baseline failures, browser testing and release gates

| ID | Decision | Label |
|---|---|---|
| B12-01 | Permission to restart the dev servers for browser QA (D23) | UNDECIDED |
| B12-02 | Commit policy and checkpoint for the uncommitted work (R1); an off-repo backup exists, see below | UNDECIDED |
| B12-03 | What `CommandCenterTest > Needs attention…` should expect (tied to B3-08 / D1) | UNDECIDED |
| B12-04 | Regression gates: SQLite failures equal the 2026-10-21 baseline by name; MySQL suite passes; tsc ≤ 24 errors; `git diff --check` clean | PROPOSED — NOT APPROVED |
| B12-05 | What happens to the QA rows in clinic 62 and the `.claude/launch.json` entries (D17) | UNDECIDED |
| B12-06 | The sidebar and persistent top navigation are frozen | CONFIRMED |
| B12-07 | A backup on a separate physical disk (only one disk exists today) | UNDECIDED |

### Counts

| Label | Count |
|---|---|
| CONFIRMED | 39 |
| PROPOSED — NOT APPROVED | 24 |
| UNDECIDED | 30 |
| DEFERRED | 9 |
| **Total** | **102** |

### Exact blockers before Phase 1.0

| Blocker | What's needed |
|---|---|
| B3-08 / B12-03 (D1) | Decide whether the Approvals menu entry is hidden, and what `CommandCenterTest` should expect |
| B1-13, B1-14, B1-17, B2-10 (D18) | A canonical plan source, the module lists, and the no-subscription policy. The confirmed Dental and Physio Standard values are recorded here, but they can't be enforced until B1-18 (seat pools) is decided |
| B12-01 (D23) | Permission to restart the dev servers so pages can be checked in a browser |
| B12-02 (R1) | Commit policy for the uncommitted work |

### Working-tree backup at this checkpoint

- **Location:** `C:\Projects\oralcares-source-backups\2026-10-22-occ-checkpoint\`
- **Contents:**
  - `tracked-changes.patch`: `git diff --binary HEAD`
  - `untracked-files.tar`: the files listed in `untracked-files.txt`
  - `manifest.sha256`: 47 files
  - `status.txt` and `HEAD.txt`
- **Excluded:** the git-ignored `.env.concurrency` secrets file.
- **Verified:** a clean clone at `6bd98695…` with the patch applied and the archive extracted matched all 47 manifest hashes.
- **Limitation:** the backup is on the **same physical disk** (Disk 0, the only disk), so it is **not** an independent backup against disk failure.
