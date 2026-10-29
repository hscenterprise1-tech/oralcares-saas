# Module completion matrix (Audit C)

Classification values:
- **FULL**: frontend, backend and tests all exist;
- **PARTIAL**;
- **BACKEND-ONLY**: server and tests exist, but the page falls back to Unavailable;
- **FRONTEND-ONLY**;
- **PLACEHOLDER**;
- **BROKEN**;
- **DUPLICATED**;
- **OBSOLETE**;
- **NOT IMPLEMENTED**.

Verification values: **V** = VERIFIED, **PV** = PARTIALLY VERIFIED (code and tests read; UI not exercised in a browser in this audit), **NV** = NOT VERIFIED.

**No browser QA was run in this audit.** "Frontend exists" means the page file exists and is in `ClinicFrontend::AVAILABLE`. Test counts are `it(`/`test(` occurrences per folder (`tests/Feature/...`). The full suite passed except for the 15 rotted tests in test-baseline.md.

## A. Clinic Portal

| Feature | Expected behaviour | Frontend | Backend / API (routes) | DB | Authorization | Tests | Gaps | Class | Ver. |
|---|---|---|---|---|---|---|---|---|---|
| Login / auth / invitations | Clinic sign-in with membership; password setup/reset; staff and owner invitations | `pages/Clinic/Auth/*` (APPROVED, frozen) | `clinic.login`, `clinic.password.*`, `clinic.invitations.accept`, `clinic.owner-invitations.accept` | users, clinic_memberships, clinic_employee_invitations | throttle `clinic-login`, membership + staff-status check (`ClinicLoginController.php:170-190`) | Clinic/Auth 37 | No MFA (S-8); no seat check at login (S-6) | FULL | V (code), PV |
| Onboarding wizard | Owner completes setup after provisioning | `ClinicPortal/Onboarding/*`, `Clinic/Onboarding/Index` | `clinic.onboarding.*` (13) | clinic onboarding tables | `onboarding_completed` gate; owner | Clinic/Onboarding 46 | Marked REPLACE in the map | PARTIAL (old design) | PV |
| Shell, navigation, branch switch, search, notifications menu | Role-aware sidebar + top bar; "Soon" for unbuilt pages | `clinic/shell/*` (approved) | `ClinicNavigation`, `clinic.branches.*`, `clinic.search*` | – | per item `requires` | Clinic/Portal 173, Shell 3 | Zero-sidebar direction conflict (architecture §6) | FULL | V |
| Owner Command Center | Owner/admin home | `ClinicPortal/CommandCenter/*` | `ClinicDashboardController` → `OwnerCommandCenter` (untracked file) | reads many | `command_center` workspace; Owner Mode for money/people | Portal | Untracked source (S-5) | FULL | PV |
| Reception / Doctor / Billing dashboards | Role dashboards | old `ClinicPortal/Dashboard.tsx` (rejected design) | `ClinicDashboardController`, `DashboardWidgets` | – | widget `requires` | Portal | Design rejected; replacement pending | PARTIAL | PV |
| Owner settings | Clinic settings, case numbers, check-in, quick care, patient form | Only `Settings/PatientForm.tsx` | `admin.settings.*` (12 routes) | clinic settings JSON, case_number_* | `settings.manage` | Clinic/Admin 55, CaseNumbers 34 | Settings index, catalogue, consents pages missing | PARTIAL | V (missing pages) |
| Branches / departments / rooms | CRUD | **none** (`Clinic/Admin/Infrastructure/*` missing) | `admin.branches/departments/rooms.*` | branches, departments, clinic_rooms | `branch.manage` / settings; seat check on branch create | Clinic/Admin | No UI | BACKEND-ONLY | V |
| Staff / roles / login seats | Staff directory, profile, documents, invitations, seats | **none** (`ClinicPortal/Staff/*` missing) | `admin.employees.*` (20), `admin.invitations.*`, `admin.staff-documents.*` | clinic_employees, clinic_memberships, Spatie roles | `staff.manage`; `ResourceLimitService::assertSeatAvailable` | Clinic/Staff 91, Practitioner 21 | No UI; no concurrent-login limit | BACKEND-ONLY | V |
| Payroll / leave | Salary periods, entries, payslips; leave requests | **none** | `clinic.payroll.*` (11), `clinic.leave.*` (5) | clinic_payroll_*, leave | `payroll.*` / leave perms | Clinic/Business 24 | No UI | BACKEND-ONLY | V |
| Patient registration + directory | Fast registration, duplicate check, role views | `Patients/Index`, `Create`, `Edit` (live; map says REPLACE) | `clinic.patients.*`, `patients.directory`, `check-duplicate` | patients (+ indexes), patient_branch_activity | `clinic_entitled:patients` + `PatientPolicy` | Clinic/Patients 264 | Directory redesign approved per memory | FULL | PV |
| Repeat patients / New Case intake | Search → visit → charge → queue | `Clinic/Reception/intake/*` dialog (live, REPLACE) | `clinic.reception.intake`, `check-in`, `walk-in`, `bookings.match` | clinic_queue_items, patient_visits, patient_billings | `front_office.operate` | Clinic/Reception 152 | Opened only from the top bar "+ New" and the calendar | PARTIAL | PV |
| Queue / Front desk | Live queue, call next, priority, doctor | **none** (`FrontDesk/Index`, `List` missing) | `clinic.reception*`, `clinic.queue`, `queue.call-next` | clinic_queues, clinic_queue_items (+ unique position) | `queue.manage`, `front_desk` workspace, `clinic_module:queue` | Reception | No UI; nav "Queue" shows Soon | BACKEND-ONLY | V |
| Queue display (TV) | Public waiting-room display | none | **no route** | `QueueDisplay` model only (1 ref) | – | – | – | NOT IMPLEMENTED | V |
| Appointments | Date/time calendar, booking, reschedule, settings | `ClinicPortal/Appointments/*` (live, REPLACE) | `clinic.appointments.*` (8) | appointments (+ calendar indexes) | `appointment.*` | Clinic/Appointments 51 | No waitlist (by decision) | FULL | PV |
| Patient 360 | 17 sections, role-filtered | `Patients/Show` + `patient360/*` | `clinic.patients.show`, `360/{section}`, bills, tooth | many | `Patient360SectionPolicy`, `PatientCareGuard` | Patient360Test et al. | CONDITIONAL PASS gate (live WhatsApp unverified); no Allergies card (decision) | FULL | PV |
| Encounters / consultation workspace | Draft, notes, diagnoses, procedures, Rx, orders, finalize, addenda | `Consultations/Workspace.tsx`, `ClinicalPanels.tsx` | `clinic.consultations.*` (≈35) | clinical_encounters, consultations, diagnoses | `clinical.view/edit`, treating doctor | Clinic/Consultations 92 | Doctor "Today" list (`consultations.index` → `Today/Index`) missing, so the workspace is reached only from Patient 360 | PARTIAL | V |
| Dental | Odontogram, perio, endo, implants, ortho, plans, lab | `DentalSection.tsx` (P360) + consultation panels | `consultations/{e}/dental/*` (15), `patients/{p}/dental/*` | patient_tooth_*, dental plans, lab orders | `odontogram` extension per branch specialty | SpecialtyEntitlementTest, Consultations | — | FULL | PV |
| Physio: assessment, care plans, exercises, sessions, packages | Per-treatment physio domain | `PhysioSection.tsx` (P360), consultation panels | `consultations/{e}/physio/*`, `clinic.treatments.*` (18), `session-packages` | physio_care_plans, physio_sessions, patient_session_packages, usages | clinical + `BillingCreate` for packages | Clinic/Treatments 72 | Treatment board pages (`clinic.treatments.index`) NV | PARTIAL | PV |
| Prescriptions | Rx with allergy review, print | Consultation workspace | `clinic.prescriptions.*` (8) | prescriptions, items | clinical | Consultations | Allergy check exists on Rx (`PrescriptionAllergyCheck`, untracked) | FULL | PV |
| Investigations / orders / results | Order, collect, result, review | `Orders/Index`, `Orders/Show` | `clinic.orders.*`, `investigations.search` | clinical_orders, items | `orders.*` | InvestigationsTest | — | FULL | PV |
| Billing: bills, collections, refunds, adjustments, summary | Branch-scoped money | `Billing/Index`, `Reversals`, `Summary` | `clinic.billing.*` | patient_billings, payments, refunds, adjustments | `billing.view`, workspace, `BranchReach` | Clinic/Billing 45 | **Checkout page missing**: signed consultations cannot be billed from the UI (clinic-portal-audit D-1) | PARTIAL / BROKEN loop | V |
| Receipts / printables | Bill A4 and thermal, Rx, patient summary, payslip | Blade printables | `billing.bills.print`, `prescriptions.print`, `patients.print`, `payroll…payslip` | – | per route | Billing tests | — | FULL | PV |
| Expenses | Record, approve, pay, recurring | `Expenses/Index` | `clinic.expenses.*` (10) | clinic_expenses | `expense.manage/approve` | Business | — | FULL | PV |
| Reports / exports | Clinic reports, CSV export | **none** (`Reports/Index` missing) | `clinic.reports.*`, `ExportClinicReport` job | clinic_report_exports | `reports.view`, `command_center` | Clinic/Reports 6 | No UI; **branch reach not applied** (S-2) | BACKEND-ONLY | V |
| Follow-ups | Board + per-patient follow-ups | Board **missing**; per-patient in P360 | `clinic.follow-ups.*`, `patients/{p}/follow-ups/*` | patient_follow_ups | `clinic_entitled:patients` | Clinic/FollowUps 4 | Board UI missing; thin tests | PARTIAL | V |
| Visits | Visit list | **missing** | `clinic.visits.*` | patient_visits | `visit.view` | Clinic/Visits 2 | — | BACKEND-ONLY | V |
| WhatsApp (clinic): connection, consent, messaging, follow-up messages | Own or shared number, templates, consented sends | `WhatsApp/Index` **missing**; P360 Communication section and manual share exist | `clinic.whatsapp.*`, `patients/{p}/messages/share`, `billing.payments.messages*`, webhook | whatsapp_connections (0 rows), clinic_whatsapp_messages | messaging perms; consent re-checked in job | Clinic/WhatsApp 21, Communication 23 | Live delivery NOT VERIFIED; webhook fail-open (S-3); no per-connection app secret | PARTIAL | PV |
| Consents | Consent types and patient consents | P360 Consents section; admin page missing | `patients/{p}/consents*`, `admin.consents.*` | consent types and versions | settings / patients | Patients | Admin UI missing | PARTIAL | PV |
| Approvals / deletion governance | Owner approves risky actions | `Approvals/Index` (HIDDEN by decision) | `clinic.approvals.*` (8) | deletion_requests | `DeletionGovernance` owner checks | Clinic/Governance 22 | Inbox hidden until Phase 1.6 | PARTIAL (by decision) | V |
| Support (clinic → HQ) | Raise and follow tickets | `Support/Index`, `Show` | `clinic.support.*` | company_support_tickets | visibility service | Portal | — | FULL | PV |
| Notifications | In-app list | `ClinicPortal/Notifications.tsx` | `clinic.notifications.index` | notifications | member | Notification 7 | SMS/email providers absent, so SKIPPED, not delivered (by design, M0) | PARTIAL | PV |
| Audit | Hash-chained security audit | P360 "audit" section | `security_audit_logs`, `VerifyAuditChain` | security_audit_logs | owner | Security 98 | No clinic-wide audit viewer | PARTIAL | PV |
| Inventory | Stock, batches, POs | none | **no routes** | inventory_* (0 rows) | – | `InventoryFoundationTest` **fails** | Unrouted | NOT IMPLEMENTED / OBSOLETE draft | V |
| Hybrid / offline | — | `offline.ts` stub | – | – | – | – | Excluded by decision | NOT IMPLEMENTED (out of scope) | V |

## B. Company / Master Admin

| Feature | Frontend | Backend (routes) | Tests | Gaps | Class | Ver. |
|---|---|---|---|---|---|---|
| CRM: leads/deals (12-stage), demos, trials, quotations, tasks, teams, calendar | `pages/Company/Crm/*` (38) | `company/crm/*` (115) | Company/Crm 1,209 + CRM 27 | Duplicate `teams` vs `company_teams` (B-3); 3 commercial doc systems (B-2); `Phase0LifecycleTest` rot | FULL with duplication | PV |
| Marketing: contacts import/dedup, campaigns, automations, WhatsApp, Instagram | `pages/Company/Marketing/*` (36) | `company/marketing/*` (85), `webhooks/whatsapp` | Marketing 183 | Webhook fail-open (S-3); `contacts` vs `marketing_contacts` | FULL with duplication | PV |
| Customer success, renewals, accounts | `CustomerSuccess/*` | `company/customer-success/*` (23) | 191 | `Phase4CustomerSuccessTest` failing assertion | PARTIAL | PV |
| Subscriptions / plans / add-ons / entitlements / usage | `Lifecycle/*`, `Admin/Packages` | `company/subscriptions`, `usage`, `lifecycle`, `EntitlementResolver`, `ClinicSubscriptionSelector` | Lifecycle 26 + CRM | No payment gateway; 12/33 dev clinics have no subscription, so they are unrestricted (S-4); pending index migration | PARTIAL | V |
| Provisioning (deal won → clinic) | `Lifecycle/ProvisioningWorkspace` | `ProvisionClinicFromDeal` job, `ClinicProvisioningRun` | Onboarding tests; `TenantProvisioningTest` rot | — | FULL | PV |
| Organizations / clinics directory | Customer-success Accounts | — | — | `organizations` is both tenant and customer (memory) | PARTIAL | NV |
| People: employees, structure, attendance, leave, payroll, incentives, roles | `Company/People/*` (17) | `company/people/*` (42) | People 73 | — | FULL | PV |
| Company admin: roles/permissions UI | `People` roles pages | `company/people/roles*` (4) | People | Two role systems on one guard (memory) | PARTIAL | PV |
| Clinic WhatsApp connections (HQ-managed) | `Integrations/*` | `company/clinic-whatsapp/*` (9) | Integrations 18 | 0 connections in dev | PARTIAL | PV |
| Finance (HQ) | `Finance/*` | `company/finance/*` (7) | Finance 30 | `CommercialInvoice` vs others | PARTIAL | PV |
| Support desk | Company support pages | `company/support/*` (6) | – | — | PARTIAL | NV |
| Reports / executive dashboard | Dashboard | `company/dashboard`, `company/reports` (2) | – | No CRM reporting (memory) | PARTIAL | NV |
| Workflows / audit / security / ops | `Workflow`, `Security`, `Operations` | 1 route each | Workflow 27, Security 23, Operations 19 | — | PARTIAL | NV |
| Profile / notification preferences | Breeze Blade | `/profile`, `/notifications*` | Auth | Fails under a production build (B-11) | BROKEN (prod) | PV |
| AI (MCP) | – | `routes/ai.php` not loaded | – | — | NOT IMPLEMENTED | V |
| Design lab / simulator | `pages/Dev/*` | `design-lab` (local only) | – | Bundled into the production build | Dev tool | V |
