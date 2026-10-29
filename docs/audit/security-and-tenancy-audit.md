# Multi-tenant SaaS and security audit (Audit E)

Read-only. Findings are numbered S-n. **Fact**, **Assumption** and **Recommendation** are kept apart. Severity is P0 (fix before any production or PHI use), P1 (before scale or GA), P2 (hygiene).

## 1. Tenancy model: how it works (VERIFIED in code)

- **Tenant = clinic.**
  - `ResolveClinicContext` → `ClinicContext` resolves the clinic from the session membership. In domain mode it uses the subdomain slug (`ClinicPortalMiddleware.php:41-43`).
  - `SetCurrentBranch` sets the active branch and the Spatie team id.
  - Both run **before** `SubstituteBindings` so that bound models are tenant-filtered (`bootstrap/app.php:61-82`).
- **`ClinicScoped` trait → `App\Scopes\TenantScope`** (`app/Scopes/TenantScope.php`):
  - web request with a ClinicUser: `where clinic_id = context`;
  - ClinicUser without a context: throws `TenantContextMissingException`;
  - guest: `1 = 0`;
  - MasterAdmin/CompanyUser: **unscoped** (by design, for HQ);
  - console: unscoped, unless the work was started through `ClinicContext::runForClinic()`;
  - **queued job**: throws unless it runs for a clinic or declares `withoutTenancy()` (`TenantScope.php:36-45`, bound in `AppServiceProvider.php:302-304`).
- **Branch**: `BranchReach` (untracked file, `app/Support/ClinicAccess/BranchReach.php`) limits money reads to the branches a user may open. `ClinicAccess::state()['clinic_wide']` is owner-in-Owner-Mode or `has_all_branches_access`.
- **Owner Mode**: T1 elevates; T2 needs a fresh password (`EnsureOwnerMode`, `App\Services\OwnerMode`). See the memory decision "Owner/Doctor Mode semantics".
- **DB invariants**: composite `(id, clinic_id)` uniques and same-clinic composite FKs (for example `appointments_pt_same_clinic_fk`, `patient_billings_id_clinic_unique`) are VERIFIED via `Schema::getIndexes`.

## 2. Findings

### S-1 Most of the test suite cannot detect a tenancy leak. P1, VERIFIED

- **Fact**:
  - `TenantScope` is disabled in console unless `enforce_tenant_scope_in_tests` is bound (`TenantScope.php:25`).
  - Only 14 test files bind it (grep). The other ~435 test files run with tenancy off.
  - Isolation-specific tests do exist: `Clinic/Tenancy/*` (6 files, 25 tests), `Clinic/Security/CrossTenantAccessTest`.
- **Impact**: a new controller that forgets a `clinic_id` filter, or uses `withoutGlobalScopes()` carelessly, will pass CI-equivalent runs.
- **Recommendation**:
  - Bind the flag globally for `Feature/Clinic` in `tests/Pest.php` and fix the fixtures that break, or
  - add a route-sweep test that hits every `clinic_portal` GET route as a user of clinic B with clinic A's ids.

### S-2 Reports ignore branch reach: a branch-limited admin can read every branch, finance included. P1, PARTIALLY VERIFIED (code path read; not reproduced at HTTP level)

- **Fact**:
  - `clinic.reports.*` requires `reports.view` plus the `command_center` workspace (route list).
  - `command_center` is granted to anyone holding `staff.manage` (`ClinicAccess.php:337`), not only to clinic-wide users.
  - `ClinicReports::filters()` accepts **any** branch of the clinic, or none, which means all branches (`app/Services/Reports/ClinicReports.php:101`).
  - Queries filter only by `clinic_id` and the optional `branch_id` (`:125-164`).
  - `ReportController` lists every branch (`ReportController.php:49`) and never consults `BranchReach`. `BillingRegister` does consult it.
  - `finance_outstanding` (balances per patient) and `expenses` are among the reports (`ClinicReports.php:60-62`).
  - `ExportClinicReport` runs the same build in a job with the stored filters (`app/Jobs/ExportClinicReport.php:40-41`).
- **Assumption**: an administrator role with `staff.manage` scoped to one branch and `has_all_branches_access = false` exists in practice. The role templates were not checked against live data.
- **Reproduction (proposed, not run)**:
  1. Create a membership with branch-A-only access holding `staff.manage` and `reports.view`.
  2. GET `/clinic/reports?report=finance_outstanding&branch_id=<B>`.
  3. Expect 403 or empty; the code suggests branch B's rows come back.
- **Recommendation**: apply `BranchReach` in `ClinicReports::filters()`/`build()` the same way as in `BillingRegister`, and add the test. Persist the reach in `clinic_report_exports.filters` so the job inherits it.

### S-3 WhatsApp webhooks fail open when the Meta app secret is unset. P1 (P0 if production ships without it), VERIFIED

- **Fact**:
  - `WebhookSignatureVerifier::rejects()` refuses only `INVALID` and `MISSING`. With no `META_WHATSAPP_APP_SECRET`, `check()` returns `UNCONFIGURED`, which is **accepted** (`app/Services/Marketing/WebhookSignatureVerifier.php:47-70`).
  - Both `webhooks.whatsapp.handle` and `webhooks.clinic-whatsapp.handle` then process the payload: status changes, inbound replies (`ClinicWhatsAppWebhooks.php:55-80`).
  - The `signature_valid=false` flag is stored, but processing still happens.
- **Impact**: anyone who knows a `phone_number_id` can forge delivery or read statuses, and inbound messages, into a clinic's patient message log.
- **Also**:
  - There is **one global app secret** (`config/services.php:54`) for all connections. A clinic-owned number registered under the clinic's own Meta app would sign with a different secret, so it is either always INVALID (refused) or never verifiable. Clinic-owned WhatsApp is therefore not production-ready as designed. NOT VERIFIED live: there are 0 connections in the dev DB.
  - `verify()` decrypts every connection's `webhook_verify_token` in a loop (`ClinicWhatsAppWebhooks.php:42-46`), which is O(n) at 100k clinics.
  - `webhooks/whatsapp` (marketing) has no throttle; the clinic webhook has `throttle:600,1` (`routes/web.php:157-161`).
- **Recommendation**:
  - Treat `UNCONFIGURED` as a refusal outside `local`/`testing`.
  - Add it to `security:production-check`.
  - Store a per-connection app secret for clinic-owned numbers.
  - Index the verify token by a hash.

### Company vs clinic WhatsApp isolation (VERIFIED, no defect found)

- `SendClinicWhatsAppMessage` refuses a connection unless it is shared (`clinic_id NULL`) or belongs to the message's clinic (`app/Jobs/SendClinicWhatsAppMessage.php:69-73`).
- It re-checks consent and eligibility at send time (`:62`).
- Company CRM WhatsApp uses `services.whatsapp.*` through `app/Shared/Notification/Channels/WhatsAppChannel.php`, a separate credential set.

### S-4 Clinics with no subscription are unrestricted. P1, VERIFIED

- **Fact**: `EnforceSubscriptionWindow` passes any clinic with `has_subscription=false` (`app/Http/Middleware/EnforceSubscriptionWindow.php:46-53`). In the dev DB, **12 of 33 clinics** have no `company_subscriptions` row (read-only count). "Legacy plans unrestricted" also applies to specialty entitlements (memory `specialty_entitlement_model`).
- **Impact**: a clinic provisioned outside the deal flow gets every module, unlimited seats (no subscription means no limits) and both specialties.
- **Recommendation**:
  - Backfill subscriptions with `app:backfill-legacy-subscriptions` (exists; not run).
  - Then flip the default to deny, behind a flag.

### S-5 Security-critical code exists only as untracked files. P0 (operational), VERIFIED

- **Fact**: `BranchReach`, `BillingRegister`, `PatientCareGuard`, `PatientDirectoryAudience`, `OwnerModeRequired`, and 5 migrations already applied to the dev DB are untracked (`??` in `git status`). `main` is 14 commits ahead of origin and nothing is pushed.
- **Impact**: a disk loss, or a `git clean`, deletes the branch-isolation and deceased-patient guards. The dev DB schema would then no longer match any commit.
- **Recommendation**: the user commits (and pushes to a private remote) in reviewed slices. This audit did not commit, by instruction.

### S-6 Seats and login limits. P2, VERIFIED (named seats) / NOT IMPLEMENTED (concurrency)

- **Fact**:
  - Named-user seats are enforced when a seat is granted (invite accept, reactivation, role change) under a shared lock (`app/Services/ResourceLimitService.php:80-110`; used by `EmployeeController`, `EmployeeInvitationController`, `InvitationAcceptance`). Branch limits are checked in `BranchController::store`.
  - **Not enforced at sign-in.** `ClinicLoginController::membershipToSignInto()` checks membership, clinic and staff status only (`:170-190`). A plan downgraded below its current seat count keeps every login working (NOT VERIFIED by test).
  - There is no concurrent-session or device limit anywhere (no code found).
- **Recommendation**: decide whether "login limits" means named seats (done) or concurrent sessions (not built). Add an over-limit state on downgrade.

### S-7 46 models carry `clinic_id` without `ClinicScoped`. P2, VERIFIED (classified)

- **Fact**: script output (`grep clinic_id` without a scope trait) includes `Branch`, `User`, `Notification`, `CaseNumber*`, `QueueHistory`, `Inventory*`, `Lab*`, `PatientDoctorAssignment`, `ClinicPayrollEntryLine`, `WhatsAppConnection`, `WhatsAppTemplate` and others.
- `docs/security/child-model-ownership.md` classifies them. The M0 checkpoint confirmed no reachable leak. `Inventory*` and `Shift` are unrouted.
- **Recommendation**: add `ClinicScoped` before any of them is routed by id. Keep the child-model doc as a gate in review.

### S-8 PHI at rest and in transit. P1, PARTIALLY VERIFIED

- **Fact**:
  - Patient identity fields (name, phone, DOB, email) are plaintext columns with plain indexes (`Schema::getIndexes('patients')`).
  - No `encrypted` cast on `Patient`.
  - `SESSION_ENCRYPT` defaults to false; sessions are DB-backed.
  - Attachments are scanned (`MalwareScanningTest`).
  - An audit hash chain exists (`VerifyAuditChain` command, `security_audit_logs`).
  - PHI-free logs since M0 (`NotificationService`).
- **No MFA exists**: `pages/Auth/TwoFactorChallenge.tsx` is frontend-only, and grep finds no 2FA/TOTP backend.
- **Recommendation**: MFA for owners and admins first; decide on column encryption versus disk/DB encryption at the infrastructure level (needs hosting info).

### S-9 Route-level authorization gaps are covered in controllers. VERIFIED by sampling, no defect

- 61 clinic routes and about 120 company routes have no permission middleware. A sample of 24 controllers was checked. All authorize inside the controller (`authorize`, `abort_unless`, `Gate`) or in the service:
  - `DeletionGovernance` throws `AuthorizationException` for non-owners (`app/Services/Governance/DeletionGovernance.php:375-405`);
  - `SessionPackages::adjust` needs `BillingCreate` (`app/Services/Physio/SessionPackages.php:261-265`);
  - `ClinicSupportController` reads through `ClinicSupportTickets::visible()`.
- **Recommendation**: prefer route middleware for coarse gates so that `route:list` shows the policy.

### S-10 Dental vs Physio gating. VERIFIED (code and tests)

- Dental consultation routes need `clinical.view`/`clinical.edit`.
- `DentalController` additionally requires the branch's specialty to carry the `odontogram` extension (`app/Http/Controllers/Clinic/DentalController.php:28-35`).
- Covered by `tests/Feature/Clinic/Foundation/SpecialtyEntitlementTest.php` and `SpecialtyRegistryTest.php`.

### S-11 Jobs, exports and notifications

- **Jobs**: 27 jobs. The queued-job guard (S-1 context) makes an unscoped clinic-model read throw. `ExportClinicReport` uses `withoutGlobalScopes()` with an explicit `clinic_id` and writes to the `private` disk as `clinic_{id}/reports/…` (VERIFIED). CSV formula injection is neutralised (`ExportClinicReport.php:59-64`).
- **Exports**: downloads are filtered by `clinic_id` **and** `requested_by` (`ReportController.php:76`). Branch reach: see S-2.
- **Notifications**: since M0, no false "delivered" (memory `m0_safety_checkpoint`; `PatientMessageDeliveryStatusTest`).

### S-12 Root maintenance scripts. P2, VERIFIED

- `reset.php` and `update_password.php` reset a named admin's password to a weak literal. They are gitignored and outside `public/`, so they are not web-reachable. `test-widget.php` boots the kernel against an API route.
- **Recommendation**: delete them from the working copy (cleanup C-1).

## 3. Scalability risks toward 100k+ clinics (NOT VERIFIED at scale; from code)

| Risk | Evidence | Note |
|---|---|---|
| DB queue, DB cache, DB sessions by default | `.env.example:37-47` | Use Redis in production (the docs say so; the config is not proven) |
| Single shared database, row-level tenancy | Design | Fine to roughly 10k with good indexes; 100k needs partitioning or sharding planning |
| Global `patients.patient_number` unique, `patients_phone_index` (not clinic-prefixed) | `Schema::getIndexes('patients')` | Cross-tenant hot index; harmless functionally |
| WhatsApp verify loops all connections | S-3 | O(n) decrypt per verification |
| `Patient360` counts and `limit(1000)->count()` patterns | `ConsultationController.php:107-108` | Bounded, OK |
| Scheduler | Every task `onOneServer()` (`routes/console.php:167-169`) | OK |
| Perf harness exists | `perf:seed-tenants`, `perf:benchmark` commands; `docs/architecture/2026-10-02-scale-readiness-report.md` | Perf DBs were dropped per memory; not re-run |
