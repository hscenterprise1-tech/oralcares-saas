# OralCares — 100k-Clinic Architecture Audit (Phase 0 / Phase 1)

**Date:** 2026-10-02 · **Scope:** current working tree on `main` (HEAD `ceb03c1` plus the uncommitted onboarding/invitation system) · **Mode:** read-only audit; remediation is tracked separately in the final report.

Earlier audit reports (2026-08-31 CRM architecture audit, 2026-09-29 Phase 0.5 / Phase 2 / security readiness) were treated as historical evidence only. Every finding below was re-derived from the current code and the local database (MySQL 8.0.46, 247 tables, ~85k rows of development data).

**Baseline before any change:** `php artisan test` → **3119 passed, 18 failed, 8 skipped** (405 s). All 18 failures pre-date this audit (see §12).

Priority scale: **P0** security / data loss / tenant isolation / corruption / production blocker · **P1** core architecture blocker · **P2** required for the Dental + Physio launch · **P3** enterprise maturity · **P4** future enhancement.

---

## 1. What is already sound

These were verified and do not need rework:

| Area | Evidence |
|---|---|
| Clinic resolution | `ResolveClinicContext` re-resolves on every request, binds membership to the requested subdomain, refuses suspended clinics, inactive memberships and access-ended staff (`app/Http/Middleware/ResolveClinicContext.php`). |
| Tenant scope fails closed | `TenantScope` returns `1 = 0` for guests, throws for a clinic user with no context and throws inside queued jobs with no context (`app/Scopes/TenantScope.php`). |
| Job/command tenancy primitives | `ClinicContext::runForClinic()`, `withoutTenancy()`, `RunsForClinic` job concern, `RunsPerClinic` command concern. |
| Branch isolation | `ClinicContext::requireBranch()` / `branchScopeOrAbort()`; never falls back to the main branch for a member without access. |
| Clinic route authorization | Every `/clinic/*` route sits behind `clinic_portal → clinic_context → onboarding_completed → subscription_window`, plus `clinic_permission:*` or a policy (`PatientPolicy`). Route-bound unscoped models (`ClinicRoom`, `Branch`, `ClinicQueueItem`) are ownership-checked in the controller. |
| Same-clinic invariants | Composite `(id, clinic_id)` foreign keys on staff placement, schedules and membership-branch pivots. |
| Invitations / password links | Tokens are stored as SHA-256 hashes (`clinic_employee_invitations.token`, `clinic_owner_invitations.token_hash`, `clinic_password_tokens.token_hash`); accept links are signed and throttled. |
| Uploads | ClamAV scanning, quarantine disk, fail-closed `UnconfiguredScanner` in production, authorized download routes. |
| Audit trail | Append-only `security_audit_logs` with HMAC hash chain and `security:verify-audit-chain`. |
| Provisioning | Idempotent, checkpointed `ProvisionClinicFromDeal` (`ShouldBeUnique`, unique `idempotency_key`, `provisioning` queue). |
| Queue claim | `QueueStateMachine` row-locks items; unique `(clinic_queue_id, position)` and `(branch_id, queue_date, doctor_key)` backstops. |
| Case numbers | Per-clinic sequences with unique `(clinic_id, counter_key, sequence)`; `CaseNumberRaceCheck` command. |
| Production gate | `security:production-check` covers debug, https, cookies, sessions, mail, malware, object storage, backups, audit shipping. |

---

## 2. P0 findings

### P0-1 · Global search leaks patients across clinics and to HQ
- **Where:** `app/Shared/Search/Repositories/SearchRepository.php::search()`; route `GET /api/search` (`auth` only); index fed by `PatientSearchProvider` (name, phone, email).
- **Current behaviour:** a clinic user is scoped to *every clinic of `users.organization_id`*, not the active clinic, so a receptionist of clinic A1 can search patients of sister clinic A2 without a membership there. A user whose memberships span organisations is scoped to whichever organisation is stamped on the user row. Company users and master admins are not filtered at all and can search every patient of every tenant.
- **Risk:** cross-clinic PHI disclosure; violates the platform rule that HQ never sees clinical data.
- **At 100k tenants:** one leaked query exposes the whole patient base; the endpoint is reachable from the command palette.
- **Fix:** clinic users → active `ClinicContext` clinic only, clinic-owned document types only, `patient.search` permission required for patient documents; HQ users → clinic-owned (clinical) document types excluded.
- **Dependencies:** none. **Migration impact:** none. **Test:** cross-clinic same-org, cross-org, HQ exclusion, no-context, permission denial.

### P0-2 · Broadcast channels authorize everyone
- **Where:** `routes/channels.php` — `clinic.{clinicId}` and `clinic.{clinicId}.user.{userId}` return `true`.
- **Current behaviour:** any authenticated user (any clinic, any HQ user) may subscribe to any clinic's private channel.
- **Risk:** latent today (`BROADCAST_CONNECTION=log`, one stub event); becomes a live cross-tenant leak the day a real broadcaster is configured.
- **Fix:** require an active membership in that clinic (and `user` match for the per-user channel).
- **Migration impact:** none. **Test:** channel callbacks for member, non-member, other user.

### P0-3 · Clinic users can hard-delete their own account
- **Where:** `app/Http/Controllers/ProfileController.php::destroy()` (route `DELETE /profile`).
- **Current behaviour:** company users are refused, clinic users — including a clinic's **primary owner** and practitioners — can delete their own `users` row. FKs cascade: `clinic_memberships`, `login_histories` (security history) and `patient_doctor_assignments.assigned_by` (clinical assignment history) are deleted with it; other FKs either null out authorship or make the delete fail.
- **Risk:** loss of clinical/audit history; an owner can orphan a tenant; attribution of clinical acts is destroyed.
- **Fix:** refuse self-deletion for every account type; closure is an administrative deactivation (staff status / membership status). Change `patient_doctor_assignments.assigned_by` to `restrictOnDelete`.
- **Migration impact:** one FK change. **Test:** clinic user delete refused, data intact; FK refuses user deletion while assignments exist.

### P0-4 · Numbering sequences can fork, making UHIDs collide permanently
- **Where:** `app/Services/Core/NumberingEngine.php::generate()`; table `numbering_sequences` unique `(organization_id, branch_id, entity_type)`.
- **Current behaviour:** `firstOrCreate` then `lockForUpdate`. The unique index does not protect organisation-level sequences because `branch_id` is `NULL` and MySQL treats NULLs as distinct. Two concurrent first registrations in an organisation create two sequence rows; both issue `OC-00000001`; `patients_clinic_uhid_unique` rejects one, and afterwards the two counters drift and keep colliding.
- **Risk:** permanent patient-registration failures for that organisation; manual DB repair required.
- **At 100k tenants:** the first-registration race happens 100k times during onboarding waves.
- **Fix:** a non-null generated scope key in the unique index, insert-or-ignore creation, then a row lock; de-duplicate existing rows in the migration.
- **Migration impact:** add virtual column + replace unique index (data-preserving). **Test:** sequence is single per scope; repeated/racing creation reuses the row; numbers strictly increase.

### P0-5 · Deleting a branch or staff record erased clinical history (found during remediation)
- **Where:** `Clinic/Admin/BranchController::destroy()`, `Clinic/Admin/EmployeeController::destroy()` (hard deletes, routed); foreign keys from `appointments`, `appointment_series`, `clinical_encounters` (→ prescriptions, diagnoses, notes, orders, procedures), `patient_visits`, `patient_billings`, `clinic_queues`, `queue_histories`, `inventory_*`, `numbering_sequences` to `branches` were `CASCADE`; `clinical_consultations.doctor_id` and `patient_doctor_assignments.to_practitioner_id` cascaded from `clinic_employees`, and encounter/appointment/bill authorship was `SET NULL`.
- **Current behaviour:** a clinic admin deleting a non-main branch silently deleted every appointment, visit, encounter, queue and bill of that branch; deleting a staff record deleted their consultations and blanked the doctor on encounters and bills. The controller even carried the comment "Ideally check if employee has dependencies."
- **Risk:** irreversible clinical and financial data loss from one click.
- **Fix:** both actions refuse when history exists (deactivate instead); the database restricts the same deletes.
- **Migration impact:** FK rule changes only. **Test:** HTTP refusal with records intact; DB-level refusal for branch, staff and login deletes.

---

## 3. P1 findings

### P1-1 · Organisation scope reads the wrong tenant for multi-clinic users
- **Where:** `app/Scopes/OrganizationScope.php` (uses `users.organization_id`); affects `Branch`, `Invoice`, `PatientMedicalHistory`, `PatientProfile`, `PatientProcedure`, `ClinicalOrder`, `ClinicalAttachment`, `QueueEntry`, `StockLocation`, `TreatmentCatalog`, masters.
- **Current behaviour:** multi-clinic invitations (Phase 2C) let one login join clinics of different organisations, but `users.organization_id` is a single value. In the second clinic, branch lists, route binding of `{branch}` and invoice reads filter by the first organisation → empty pages / 404; org-only clinical rows of the first organisation are visible while working in the second clinic.
- **Fix:** for clinic users the organisation comes from the active `ClinicContext` clinic; `users.organization_id` remains only for HQ users.
- **Test:** cross-org member sees the second clinic's branches and nothing of the first while in the second.

### P1-2 · Patient-owned clinical tables have no `clinic_id`
- **Where:** `patient_medical_histories`, `patient_profiles`, `patient_procedures`, `clinical_orders`, `invoices`, `consent_records`, `patient_alerts` (all carry `patient_id NOT NULL`; most carry only `organization_id`, `consent_records`/`patient_alerts` carry no tenant column at all).
- **Current behaviour:** clinic isolation inside an organisation is not enforceable by scope; consent and alerts cannot be queried per tenant without a join.
- **At 100k tenants:** every per-clinic report/export on these tables must join `patients`; retention/deletion per tenant is impossible to express directly.
- **Fix:** add `clinic_id` (FK, indexed with `patient_id`), backfill from `patients`, enforce NOT NULL, models gain `ClinicScoped` + `InheritsClinicOwnership` (derive from patient/encounter, refuse cross-clinic links).
- **Migration impact:** additive; backfill is a single join update (≤1 row today).

### P1-3 · `queue:close-day` scans every tenant every 15 minutes
- **Where:** `app/Console/Commands/CloseQueueDay.php::candidateDays()`.
- **Current behaviour:** loads *every* awaiting appointment across *all* clinics for the last 7 days **and today** into PHP (`->get()`), with `whereDate()` predicates that cannot use the `scheduled_at` indexes.
- **At 100k tenants:** ~100k clinics × ~25 bookings/day × 8 days ≈ 20M rows hydrated every 15 minutes — the scheduler would never finish.
- **Fix:** sargable range predicates, `DISTINCT (branch_id, day)` aggregation in SQL, cursor iteration; closing-time check stays per branch.

### P1-4 · Per-clinic command fan-out loads every clinic
- **Where:** `app/Console/Concerns/RunsPerClinic.php` (`->get()` of all active clinics, sequential).
- **Fix:** `lazyById()` iteration (constant memory). Fan-out to jobs is a P2 follow-up once a per-clinic scheduled workload exists.

### P1-5 · Scheduler is not multi-server safe
- **Where:** `routes/console.php` — 19 scheduled entries, none `onOneServer()`.
- **Risk:** with two app servers every job (reminders, expiries, backups, MRR capture) runs twice.
- **Fix:** `onOneServer()` on every entry (cache store already supports atomic locks).

### P1-6 · Queue timing makes Redis unsafe
- **Where:** `config/queue.php` — `redis.retry_after = 90` while `ProcessLeadImportJob`/`ProcessMarketingContactImportJob` declare `$timeout = 600` and provisioning can run minutes.
- **Risk:** under Redis a long job is re-delivered while still running → duplicate imports/provisioning stages.
- **Fix:** `retry_after` above the longest job timeout on every connection, `after_commit` true so jobs never run before the dispatching transaction commits; named queues (see ADR §J).

### P1-7 · Search indexing jobs fail and carry no tenant contract
- **Where:** `app/Shared/Search/Jobs/IndexDocumentJob.php`; `failed_jobs` holds 25 failures (`ModelNotFoundException` for deleted Patient/Invoice).
- **Fix:** `deleteWhenMissingModels`, bounded tries/backoff, explicit cross-tenant intent (`withoutTenancy`) since the index row stores its own `clinic_id`, dedicated `search` queue.

### P1-8 · Invitation/reset links sit in plaintext in the queue
- **Where:** `OwnerInvitationMail`, `StaffInvitationMail`, `ClinicPasswordResetMailable`, `PasswordSetupMailable` (all `ShouldQueue`).
- **Risk:** the signed URL (a bearer capability) is stored in `jobs` and, on failure, permanently in `failed_jobs` and DB backups.
- **Fix:** `ShouldBeEncrypted`.

### P1-9 · S3 driver is configured but not installed
- **Where:** `config/filesystems.php` (`private`, `backups` disks) and `security:production-check` require S3; `vendor/league` has no `flysystem-aws-s3-v3`.
- **Risk:** production cannot store patient documents or backups off-server as the gate requires.
- **Fix:** `composer require league/flysystem-aws-s3-v3` — **dependency change, needs owner approval** (not applied).

### P1-10 · Dead parallel stacks still in the tree
- 24 controllers with no route (`Clinical/*` ×8, `Reception/*` ×3, `Operational/FrontDesk`, master-data controllers, Breeze registration, `Api/WidgetController`; the first scan also listed the two invokable email-verification controllers, which are routed and were kept), the legacy `App\Services\PatientRegistrationService` (random `UHID-YYYYMMDD-####`), the unused `ClinicInvitationService` (plaintext tokens) and three never-rendered pages (`Clinic/Admin/Employees/*`).
- **Risk:** they render 22 page components that do not exist and encode superseded rules (random numbers, org-only scoping); the next feature built on them inherits those defects.
- **Fix:** remove what has no test dependency; list the rest for approval (tests must not be deleted without approval).

---

## 4. P2 findings (required before the Dental + Physio launch)

| # | Finding | Where | Fix |
|---|---|---|---|
| P2-1 | Clinical workflow after the queue is unbuilt: no encounter at check-in, no consultation, prescription, investigation, dental chart or physio routes/screens | `routes/web.php`; tables exist (`clinical_encounters`, `prescriptions`, `lab_orders`, `xray_records`, `patient_dental_charts`) | Build on the canonical models in the ADR, not on the unrouted `Clinical/*` controllers |
| P2-2 | Clinic billing model undecided (`Invoice` org-only, wired to Patient 360; `PatientBilling` branch-aware, read only by the dashboard) | `app/Models/Invoice.php`, `PatientBilling.php` | Decision recorded in ADR §G (PatientBilling family canonical); build Billing on it |
| P2-3 | Document numbers are random strings on **global** unique indexes (`INV-YYYY-XXXXXX`, `RX-XXXXXXXX`, `ENC-YYYYMMDD-XXXX`, `XR-uniqid`) | `BillingService`, `ClinicalPrescriptionService`, `FrontDeskService`, `XrayRecord::boot` | Per-clinic/branch sequences via `NumberingEngine` (GST needs sequential per FY); unique indexes become `(clinic_id, number)`. Not reachable from any route today |
| P2-4 → **P1 (measured)** | Patient FULLTEXT index is global — InnoDB resolves `MATCH` across all tenants before `clinic_id` applies. The scale run measured a 15-patient clinic's name search at 12 ms (1k clinics) → 68 ms (10k); the pure FULLTEXT form took 1.1 s at 10k | `patients_name_fulltext`, `Patient::scopeMatchingSearch` | Fixed: tenant-bounded word-prefix match (1.6 ms at 10k) |
| P2-11 | Clinic-wide "today" counts used `whereDate()` and had no `(clinic_id, scheduled_at)` index — 352 ms on a 120k-booking clinic, measured | `DashboardData`, `QueueBoard`, `ReceptionCheckIn`, `PatientController`, `VisitController` | Fixed: `whereOnDate()` range macro + index (9.7 ms) |
| P2-5 | No patient-facing communication (reminders, confirmations) on the clinic side | — | Notification pipeline on the `notifications` queue, per-clinic sender config |
| P2-6 | Specialty registry hard-coded in PHP constants | `ClinicCapabilities::SPECIALTY_MODULES`, `ClinicActions::CASE_SPECIALTIES` | Move to a config-driven specialty registry |
| P2-7 | Legacy `Clinic/components/ClinicShell` still used by 10 admin/patient pages beside the new `resources/js/clinic` shell | `resources/js/pages/Clinic/**` | Migrate pages to the new shell (approved CRM language) |
| P2-8 | Composite indexes missing on hot clinic paths (`patients(clinic_id, phone)`, `appointments(branch_id, status, scheduled_at)`, `clinic_queue_items(clinic_queue_id, status)`, `patient_timeline_events(patient_id, …)`, `patient_billings(clinic_id, branch_id, status)`) | schema | Added in Phase 4 |
| P2-9 | `ResourceLimitService` reads storage usage from a cache key nothing writes | `ResourceLimitService::storageUsage()` | Meter uploads into `usage_metrics` |
| P2-10 | Clinic dashboard computes live COUNT/SUM aggregates per load | `app/Support/Dashboard/DashboardData.php` | Daily pre-aggregation into `analytics_snapshots` once billing volume exists |

## 5. P3 findings (enterprise maturity)

| # | Finding |
|---|---|
| P3-1 | No Horizon / queue dashboard (database queue in dev); needs a dependency decision |
| P3-2 | No structured JSON application log channel; only the security channel is shippable |
| P3-3 | No slow-query or per-request query-time monitoring |
| P3-4 | Health check is `/up` only (no DB/cache/queue/storage readiness) |
| P3-5 | No metrics hooks (queue depth, job latency, request timing) |
| P3-6 | Sessions, cache and Spatie permission cache use the database store; Redis is required at scale |
| P3-7 | No read/write split configuration for reporting |
| P3-8 | Two parallel search-indexing pipelines (`SearchIndexObserver` sync, `SearchModelObserver` queued) |
| P3-9 | Patient 360 timeline merges unbounded per-patient collections in PHP |
| P3-10 | `leaves` list is unpaginated |
| P3-11 | Duplicate index `clinic_employees(clinic_id, status)` ×2 |
| P3-12 | Local, git-ignored root scripts (`reset.php`, `update_password.php`, `test-widget.php`) reset/print credentials; must never be shipped in a deploy artifact |
| P3-13 | No data-export or retention/deletion tooling per tenant |
| P3-14 | Full `migrate:reset` stops at `2026_09_29_102600_scope_procedure_catalogue_to_clinic_and_branch_prices` (MySQL had let a composite index absorb a foreign key's index); forward-only deploys are unaffected |
| P3-15 | Local dev DB has an index (`clinic_employees_clinic_id_status_index`) no migration creates — schema drift |
| P3-16 | Staff list is unpaginated (120 staff rendered at once on the hospital-scale clinic) |

## 6. P4 (future)

Physical sharding / per-tenant databases; external search engine; WhatsApp patient channel; ABDM (ABHA / HPR / HFR) integration; read replicas per region; tenant-level encryption keys.

---

## 7. Canonical domain map (as found)

| Concept | Canonical today | Duplicates / legacy | Status |
|---|---|---|---|
| Login | `users` | — | ok |
| Clinic access | `clinic_memberships` + `branch_clinic_membership` + Spatie branch-team roles | `clinic_employee_roles` (invite-time only) | ok |
| Staff identity | `clinic_employees` | `provider_id` columns (always null) | ok |
| Practitioner profile | `doctor_profiles` via `Practitioner` | `DoctorProfile` (alias), `doctor_availabilities` (retired), `DoctorProfileController` (unrouted) | isolate |
| Patient | `patients` (clinic-scoped) | `patient_profiles`/`patient_medical_histories` org-only (P1-2) | fix |
| Visit | `patient_visits` | — | ok |
| Encounter | `clinical_encounters` (clinic NOT NULL) | `clinical_consultations` (child, no tenant column) | ok |
| Appointment | `appointments` (+ `appointment_series`) | `availabilities` (unused) | ok |
| Queue | `clinic_queues` / `clinic_queue_items` / `queue_histories` | `queue_entries` + FrontDesk (legacy) | isolate |
| Billing | **undecided** | `invoices` (org) vs `patient_billings` (clinic+branch) | P2-2 |
| Investigation | `lab_orders`, `xray_records`, `clinical_orders` | — | unrouted |
| Inventory | `inventory_*` | `stock_*` (legacy) | unrouted |
| Catalogue | `clinical_procedures` (+ prices) | `treatment_catalogs`, `procedure_masters` | isolate |

## 8. Tenancy safety summary

- 217 models; 36 use `ClinicScoped`; 4 use `InheritsClinicOwnership`; 19 are `OrganizationScoped` only.
- 216 `withoutGlobalScope(s)` call sites; 47 in clinic controllers. Spot-checked: every clinic-controller bypass re-applies a `clinic_id`/branch filter or constrains a relation of an already-scoped parent. No IDOR found in routed clinic code.
- Unscoped clinic tables reached only through ownership-checked parents: `clinic_rooms`, `clinic_queue_items`, `clinic_operating_hours`, `case_numbers`, `queue_histories`.
- Cache keys: only geo (global data), marketing (org-keyed) and storage usage (clinic-keyed). Lock keys: campaign jobs (campaign-keyed). No tenant-unsafe key found.
- Jobs: only provisioning and onboarding jobs touch clinic data; both run without `TenantScope` reads or through `runForClinic`.

## 9. Security summary

Fixed earlier and verified: hashed tokens, signed links, throttled auth, malware scanning, authorized downloads, security headers, audit chain, production gate. New issues: P0-1, P0-2, P0-3, P1-8, P1-9. No secrets in tracked files (`.gitignore` covers local scripts and `.env`).

## 10. Data-volume risk register

| Path | Assumption | Status |
|---|---|---|
| `queue:close-day` candidate discovery | small global appointment volume | P1-3 |
| `RunsPerClinic` | few clinics | P1-4 |
| Global search FULLTEXT | small index | P0-1 / P2-4 |
| Patient FULLTEXT | small global patient table | P2-4 |
| Dashboard aggregates | small clinic | P2-10 |
| Patient 360 timeline | short patient history | P3-9 |
| Leave list | few leave rows | P3-10 |
| Appointment calendar | bounded (`MAX_APPOINTMENTS + 1`) | ok |
| Patient directory / search | paginated / `limit(20)` | ok |
| Reception queue board | one branch-day | ok |

## 11. Product workflow trace

| Step | State |
|---|---|
| CRM lead → deal → won | built (Company side) |
| Provisioning | built (queued, idempotent) |
| Owner invitation → onboarding | built (uncommitted) |
| Staff invitation | built |
| Clinic login / reception / appointment / check-in / queue | built |
| Consultation / prescription / investigation | **not routed** |
| Billing | **not built** (model decision) |
| Dental chart / physio | **not built** |
| Patient 360 | built (read side) |
| Clinic reports | **not built** |
| Patient communication | **not built** |
| Renewal / expansion | built (Company side) |

## 12. Baseline test failures (pre-existing, not caused by this work)

`ClinicWorkspaceTest` ×3 (retired `clinic_settings` table) · `ClinicalFoundationTest` (route removed) · `Phase0LifecycleTest` ×3 (`ModuleRegistry.ts` moved) · `Phase4CustomerSuccessTest` · `SuperAdminBypassTest` (file moved) · `InventoryFoundationTest` (`sku` NOT NULL) · `PatientBillingTest` ×3 (`createBill()` removed) · `PatientRegistrationTest` (legacy registration service) · `TaskControllerTest` ×2 (403) · `TenantProvisioningTest` ×2 (deleted service).
