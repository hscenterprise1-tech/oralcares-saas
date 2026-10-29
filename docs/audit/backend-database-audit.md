# Backend and database integrity audit (Audit B)

Read-only. Row counts come from read-only `DB::table()->count()` against the dev DB `oralcares_saas`. No PHI was read. Findings are numbered B-n.

## 1. Canonical request trace (VERIFIED)

Example: Patient 360 opened by a receptionist.

```
UI  pages/ClinicPortal/Patients/Show.tsx
 → GET clinic/patients/{patient}                (clinic.patients.show)
 → web | clinic_portal | clinic_context | onboarding_completed | subscription_window | clinic_entitled:patients
 → ResolveClinicContext / SetCurrentBranch (before SubstituteBindings) → Patient bound through TenantScope
 → Patient360Controller@show → PatientPolicy / Patient360SectionPolicy (14 auth calls in the controller)
 → Patient360 read model (App\Support\Patient360*), deferred section props
 → Inertia::render('ClinicPortal/Patients/Show')
```

Example: money.

```
GET clinic/billing → clinic_permission:billing.view + clinic_workspace:… → CheckoutController@index
 → App\Services\Billing\BillingRegister (BranchReach-scoped, single query for list and totals)
 → patient_billings / payments / refunds / adjustments (canonical)
 writes → PaymentService / BillingService (transactions, idempotency through IntakeSubmissions)
```

The pattern holds across the clinic side:
- thin controllers;
- domain services that own writes inside `DB::transaction` with `lockForUpdate`;
- request-keyed idempotency (`IntakeSubmissions::once`);
- an audit record on every governed change (`SecurityAudit`).

**This is the strongest part of the codebase and should be preserved.**

## 2. Findings

### B-1 Billing has one live clinic model; the "pending decision" is stale. P2, VERIFIED

- **Fact**:
  - `BillingRegister` reads only `patient_billings` and says it never reads `invoices` (`app/Services/Billing/BillingRegister.php:15-31`).
  - `BillingService` numbers bills through `ClinicDocument::Invoice` (`app/Services/BillingService.php:87`).
  - DB: `patient_billings` = 23 rows, `invoices` = 0.
  - Yet `Clinic/Admin/SettingsController.php:53-55` still describes Invoice vs PatientBilling as "an open decision", and the memory `project_billing_model_decision_pending` says the same.
  - The `Invoice` model is still referenced by 15 app files and 11 test files (mostly the Company/CRM commercial side and `SeedPackageService`).
- **Recommendation**: record the decision (PatientBilling is canonical for clinics). Then deprecate the clinic use of `invoices`, and leave the Company commercial use alone until B-2 is settled.

### B-2 Company side runs three commercial document systems. P2, VERIFIED (counts) / PARTIALLY (flows)

- **Fact**:
  - `company_quotations` (14) plus `company_quotation_items` (7: still fewer items than quotations);
  - `commercial_documents` (1);
  - `commercial_invoices` (3);
  - `invoices` (0).
  - `CommercialDocument` has 14 app and 14 test references; `CommercialInvoice` backs `/company/finance/invoices` (`FinanceWorkspaceService.php:89,235`).
- **Recommendation**: consolidate behind one ledger. Delete nothing until the 14+ tests are migrated (memory `crm_architecture_audit_2026_08_31` §validation 1–2).

### B-3 Duplicate or legacy model families (VERIFIED counts)

| Family | Canonical | Legacy / duplicate | Rows (canonical / legacy) | Refs (app / tests) for the legacy side |
|---|---|---|---|---|
| Queue | `clinic_queues` / `clinic_queue_items` + `QueueStateMachine` | `queue_entries` (`QueueEntry`) | 23 / 0 | 2 / 0 |
| Procedure catalogue | `clinical_procedures` (`ClinicalProcedure`) | `treatment_catalogs` (`TreatmentCatalog`) | 46 / **48** | 5 / 5 |
| Teams | `company_teams` / `company_employees` | `teams` / `team_users` (`Team`) | 9 / 1 (+0 members) | 14 / 14 |
| Contacts | `marketing_contacts` | `contacts` (`Contact`) | 613 / 23 | 36 / 24 |
| Activity | `activities` (1,027) | `lead_activities`, `entity_activities`, `entity_tasks` | **dropped since Aug** | n/a |
| Inventory | `inventory_*` (0 rows, unrouted) | `stock_*` | 0 / dropped | `InventoryFoundationTest` fails |

`treatment_catalogs` still holds 48 rows, and the provisioner still writes it (memory `phase2_architecture_audit`, NOT re-verified). That is a second price/procedure source next to `clinical_procedures`.

**Correction (VERIFIED):** most of the "5 app refs" counted for `TreatmentCatalog` point at the **service** `App\Services\Treatment\TreatmentCatalog` (used by `ProcedureCatalogController` and `TreatmentCatalogController`), not at the `treatment_catalogs` model. That model's live writers need a precise grep before any cleanup.

### B-4 Migrations and schema drift. VERIFIED

- 345 migration files: 344 ran, 1 pending (`2026_10_21_173134_add_subscription_index_…`). The pending one is idempotent (`Schema::hasIndex` guard) and was left unrun by instruction.
- **5 applied migrations are untracked files** (security S-5). One of them is destructive: `2026_10_24_191407_remove_patient_archive.php` drops `patients.archived_at/archived_by/archive_reason` (lines 40-42). Its `down()` recreates empty columns, so the data is not recoverable. If that file is lost, a fresh environment rebuilt from git will differ from dev.
- All migration filenames are dated `2026_*` (plus 3 `0001_*`). History was squashed at some point, so per-feature provenance is not available from migrations.

### B-5 Tenant scoping of reads in services. VERIFIED (pattern), see security S-1/S-2/S-7

- Services frequently use `withoutGlobalScopes()` together with an explicit `where('clinic_id', …)`. Examples:
  - `ConsultationController.php:101-108`;
  - `ReportController.php:49`;
  - `DeletionRequestController.php:47`;
  - `ExportClinicReport.php:34`.
- That is correct, but it puts the whole burden on each call site, and most tests run with scoping off (S-1).

### B-6 Validation, transactions and races. VERIFIED (sample)

- Writes use FormRequests or inline `validate()` with bounded sizes, for example `TreatmentCareController::sellPackage` (`:193-201`).
- Money and seat paths lock rows: `SessionPackages::adjust` (`lockForUpdate`, balance ≥ 0); `ResourceLimitService::assertSeatAvailable` (`sharedLock`); `DeletionGovernance::approve` (`lockTarget`, stale-target detection).
- Queue position, encounter and billing numbers are protected by DB uniques: `clinic_queue_items_queue_position_unique`, `clinical_encounters_clinic_number_unique`, `patient_billings_branch_number_unique`.
- **Race proofs were run only on SQLite in this audit.** The MySQL concurrency tests exist but were NOT VERIFIED.

### B-7 Contract mismatches: tests do not prove that pages exist. P1, VERIFIED

- 20 server-rendered Clinic components have no page file (repository-inventory §3). Tests still assert on their names, because `ensure_pages_exist=false` (`tests/Pest.php:56-57`).
- Backend payloads for Today, FrontDesk, Checkout, Staff, Payroll, Leave, Reports, WhatsApp, Follow-ups and Admin are built and tested, but no UI consumes them. That is backend-only work.

### B-8 Fake or hardcoded data. VERIFIED, none material found

- Grep for `rand(`, `fake(`, `mock`, `dummy`, `lorem` and `TODO` across clinic controllers, services and pages found only:
  - UUID fallbacks (`Math.random` when `crypto.randomUUID` is missing);
  - `OpenAiContentService::suggestTopic` choosing from a fixed topic list when AI is not configured (`app/Services/Marketing/OpenAiContentService.php:63-69`). That is labelled fallback behaviour.
- Previous hardcoded executive tiles were replaced (comments at `ExecutiveDashboardService.php:251-256,345`).

### B-9 Indexes. VERIFIED (hot tables)

- `patients`: `(clinic_id, phone)`, `(clinic_id, uhid)` unique, `(clinic_id, status, created_at)`, fulltext on name.
- `appointments`: `(clinic_id, branch_id, scheduled_at)`, `(practitioner_id, scheduled_at)`, `(room_id, scheduled_at)`.
- `clinical_encounters`: `(clinic_id, doctor_id, status)`, `(clinic_id, patient_id, started_at)`.
- `patient_billings`: `(clinic_id, branch_id, status)`.
- `security_audit_logs`: `(clinic_id, occurred_at)`, `(event, occurred_at)`, `(subject_type, subject_id)`.
- `sessions`: `user_id`, `last_activity`.
- No obvious missing index on the paths traced. Global `patients_phone_index` and `patients_alternate_phone_index` duplicate the clinic-prefixed ones (P2).

### B-10 Events with no producer. P2, VERIFIED

- 81 event classes and 3 listener classes.
- Never dispatched (grep for `new X(`, `X::dispatch`): `NotificationSent`, `PatientAddedToQueue`, `TenantProvisioningStarted`, `TenantProvisioningCompleted`, `TenantProvisioningFailed`.
- Most other events exist for broadcasting (`routes/channels.php`) or `Event::listen` closures in providers.

### B-11 Breeze Blade pages will fail under a production build. P1, PARTIALLY VERIFIED

- **Fact**:
  - `/profile` (`ProfileController@edit`) and `/notifications`, `/notifications/preferences` (`NotificationViewController`) render `<x-app-layout>`. That component `@vite(['resources/css/app.css','resources/js/app.js'])` (`resources/views/layouts/app.blade.php:16`).
  - `resources/js/app.js` is **not** a Vite input (`vite.config.js:7-10`), and it is absent from both the fresh build manifest and `public/build/manifest.json`.
  - Laravel throws `ViteException: Unable to locate file in Vite manifest` when no dev server is running.
  - The Company header links to these pages (`components/executive-shell/ExecutiveHeader.tsx`, `Company/design/CrmShell.tsx`).
- **Reproduction (not run, because it needs the dev server stopped)**:
  1. Remove `public/hot`.
  2. Sign in as a company user.
  3. GET `/profile`.
- **Recommendation**: replace these with Inertia pages or remove them. Retire `app.js`, Alpine and the Breeze layouts.

### B-12 Unloaded or unreferenced backend code. P2, VERIFIED

- `routes/ai.php`: not loaded, and its content is commented out.
- `app/Shared` (47 files), a parallel notification/search layer. Unreferenced outside itself:
  - `Notification/Jobs/BatchNotificationJob`;
  - `Notification/Listeners/CentralNotificationListener`;
  - `Search/Models/SavedSearch`;
  - `Search/Providers/SearchServiceProvider`.
- `app/Repositories`: no unreferenced class found.
- 23 controllers had no route at the 2026-09-29 audit (memory). NOT re-counted here.

## 3. CRUD gaps by clinic domain (FACT from the route list; UI status in module-completion-matrix.md)

| Domain | C | R | U | D / Archive | Note |
|---|---|---|---|---|---|
| Patients | yes | yes | yes | governed death record; archive **removed** (B-4) | Deletion goes through `DeletionGovernance` approvals |
| Appointments | yes | yes | reschedule/status/patient | cancel | |
| Visits | yes | yes (no page) | – | – | |
| Bills | from checkout / reception collect | yes | adjustments | refunds | No void-bill route (by design through adjustments; NOT VERIFIED) |
| Expenses | yes | yes | approve/reject/pay | – | Recurring exists |
| Staff | yes | yes | yes | status | No UI |
| Branches/Departments/Rooms | yes | yes | yes | yes | No UI |
| Catalogue/Consents | yes | yes | yes | active toggle | No UI |
| Prescriptions | through consultation | print | items | cancel | |
| Orders/Investigations | through consultation | yes | collect/result/review | cancel | |
