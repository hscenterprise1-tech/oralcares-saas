# Clinic Portal frontend replacement map

Started 2026-10-22, under the product decision of that date: the whole Clinic
Portal frontend is rebuilt. Requirements come from
`owner-command-center-blueprint.md` and its decision register.

Only three existing frontend elements are kept:

1. The login page (`pages/Clinic/Auth/ClinicLogin.tsx`). The other pre-auth
   screens are kept with it: Forgot Password, Setup Password, Accept
   Invitation, and `pages/Clinic/components/*`.
2. The sidebar (`clinic/shell/Sidebar.tsx`), with the navigation it draws from
   `ClinicNavigation`.
3. The persistent top bar (`clinic/shell/TopBar.tsx`) and its menus: the
   account menu, branch switcher, global search, notifications menu and the
   Owner Mode dialog.

`ClinicPortalShell` is the frame that holds the sidebar and top bar, so it is
kept as infrastructure. Its content area belongs to each page.

Everything else visible inside the shell is rejected and gets a new page.

## Infrastructure that is kept (not UI)

| Kept | Why |
|---|---|
| `clinic/http.ts`, `live.ts`, `context.ts`, `types.ts`, `ownerModeStepUp.tsx` | Request client, live props, the Owner Mode step-up contract |
| `clinic/ui/*` primitives (Button, Field/Input/Select, Modal, DatePicker, Notice, Spinner, focus) | Low-level controls with no page composition. A new page may restyle around them |
| `app.tsx` resolver | An unbuilt `ClinicPortal/*` page renders `Unavailable` instead of crashing |
| Backend: `ClinicAccess`, `ClinicNavigation`, `ClinicFrontend::AVAILABLE`, `TenantScope`, `ClinicContext` | Authorization and tenancy, enforced on the server |
| Backend: `PaymentService`, `BillingRegister`, `BranchReach`, `ClinicExpenses`, `ClinicPayroll`, `OwnerMode`, `IntakeSubmissions`, `SecurityAudit`, `DashboardWidgets`/`DashboardData` | Verified business logic with tests. The money paths have MySQL race proofs |

## Page-by-page map

Status key:

- **NEW**: a new page, built under this decision.
- **REPLACE**: old UI that is still live and must be rebuilt.
- **MISSING**: the controller exists, but the page was never built and renders
  `Unavailable`.
- **HIDDEN**: the page exists but is not in `AVAILABLE`.

| Area | Page | Old file (live?) | Backend source | Permission | Status |
|---|---|---|---|---|---|
| DSH | Owner Command Center (owner and administrator home) | none (new): `ClinicPortal/CommandCenter/*` | `ClinicDashboardController` → `OwnerCommandCenter` (reuses `DashboardData` sources, `BillingRegister`, `ResourceLimitService`, `BranchReach`) | `command_center` workspace; money, people, security and activity: owner in Owner Mode | NEW |
| DSH | Reception, Doctor and Billing dashboards | `ClinicPortal/Dashboard.tsx` and `Dashboard/*` (live for these roles only; the old shared composition) | `ClinicDashboardController`, `DashboardWidgets`, `DashboardData` | per widget `requires` | REPLACE: Reception next |
| BIL | Bills & Collections | none (new) | `CheckoutController@index`, `BillingRegister::bills/collections`, `@bill` JSON | billing.view, workspace | NEW |
| BIL | Refunds & Adjustments | none (new) | `BillingRegisterController@reversals` | billing.view, workspace | NEW |
| BIL | Expenses | none (new) | `ExpenseController`, `ClinicExpenses` | expense.manage / approve | NEW |
| BIL | Financial Summaries | none (new) | `BillingRegisterController@summary` | billing.view, reports.view, command_center | NEW |
| BIL | Consultation checkout | none | `CheckoutController@show` → `Billing/Checkout` | billing.view | MISSING |
| APT | Appointments calendar | `Appointments/Index.tsx` and `components/*` (live) | `AppointmentController`, scheduling services | appointments.* | REPLACE |
| APT | Queue / Front Desk | none | `ReceptionController` → `FrontDesk/Index`, `List` | front_office.operate | MISSING |
| APT | New Case / Repeat Case intake | `Clinic/Reception/intake/*` (live, opened from the top bar "+ New" and the calendar) | `ReceptionController@intake`, `IntakeSubmissions`, `CheckInCharges` | front_office.operate | REPLACE |
| APT | Follow-ups | none | `FollowUpBoardController` | follow_ups.view | MISSING |
| PAT | Patient directory | `Patients/Index.tsx` and `components/directory/*` (live) | `PatientController@index` | patients.view | REPLACE |
| PAT | Register patient | `Patients/Create.tsx` (live) | `PatientController@create/store` | patients.create | REPLACE |
| PAT | Patient 360 / Edit | none | `Patient360Controller`, `PatientController@edit`, `Patient360Sections` | patients.view / edit | MISSING |
| CLN | Doctor Today / Consultation workspace | none | `ConsultationController` (Today, Workspace) | clinical.* | MISSING (Dental and Physio must differ; no tooth chart for Physio) |
| CLN | Orders | none | `InvestigationController` | orders.* | MISSING |
| STF | Staff directory / profile / form / documents | none | `Admin\EmployeeController`, `StaffProfileController`, `StaffDocumentController` | staff.manage | MISSING |
| PAY | Payroll | none | `PayrollController`, `ClinicPayroll` | payroll.* | MISSING |
| LVA | Leave | none | `LeaveController` | leave.* | MISSING |
| ORG | Branches / Departments / Rooms | none (`Clinic/Admin/Infrastructure/*` never built) | `BranchController`, `DepartmentController`, `RoomController` | settings.manage | MISSING |
| SET | Settings: patient form | `Settings/PatientForm.tsx` and `patient-form/*` (live) | `SettingsController@patientForm` | settings.manage | REPLACE |
| SET | Settings: index, catalogue, consents | none | `SettingsController`, `CatalogPageController`, `ConsentTypeController` | settings.manage | MISSING |
| WA | WhatsApp | none | `WhatsAppController` | messaging.* | MISSING |
| RPT | Reports | none | `ReportController` | reports.view | MISSING |
| APR | Approvals inbox | `Approvals/Index.tsx` (HIDDEN, decision D1) | `DeletionRequestController` (approvals page) | per item | HIDDEN; rebuilt in Phase 1.6 |
| SUP | Notifications | `Notifications.tsx` (live) | `ClinicNotificationsController` | any member | REPLACE |
| SUP | Support | `Support/Index`, `Show`, `StatusChip` (live) | `ClinicSupportController` | any member | REPLACE |
| ONB | Onboarding wizard / account / done | `Onboarding/*` (live, before the portal) | `OnboardingController` | owner invite | REPLACE |
| — | Unavailable placeholder | `Unavailable.tsx` | resolver fallback | — | Kept until no route renders it |

## Retirement rule

An old page file is deleted only when all of these hold:

- its replacement is live;
- `grep` shows no imports and no `Inertia::render` name pointing at it;
- `tests/` has no assertion against its component name;
- the replacement has passed browser QA at desktop, tablet and mobile widths.

Until then the old file stays, because removing it would turn a live route
into `Unavailable`.

No migrations, tables, records or backend services are removed because their
old UI is replaced.

## Open decisions carried forward (not invented)

- D4: Billing-role access.
- D18: seat pools.
- The no-subscription policy.
- Payroll maker-checker and payroll reopen.
- Daybook & Cash Reconciliation (F).
- Accounting and GST (F).
- Billing model: the legacy `invoices`/`payments` tables vs `patient_billings`. Summaries read `patient_billings` only and count legacy payments separately, so nothing is double counted.
