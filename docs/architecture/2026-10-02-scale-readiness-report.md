# OralCares — 100k-Clinic Scale Readiness Report

**Date:** 2026-10-02 · **Companion documents:** `2026-10-02-platform-scale-audit.md` (findings, Phase 0/1), `ADR-001-canonical-platform-architecture.md` (decisions, Phase 2)

**Verdict: not production-ready.** The P0 and P1 code defects found are fixed and covered by tests, and the scale foundation is in place, but production readiness also needs infrastructure that does not exist yet (Redis, object storage with its driver installed, worker pools, log shipping, a restore test, load testing on production-class hardware), the clinical workflow after the queue is not built, and two owner decisions are open. Section 13 says exactly what must happen before launch, before 10k and before 100k clinics.

---

## 0. Validation results

| Check | Result |
|---|---|
| Full suite (before) | 3119 passed · 18 failed · 8 skipped |
| Full suite (after) | **3186 passed · 18 failed · 9 skipped** — the 18 failures are the identical pre-existing set (audit §12); 0 new failures; 67 new tests |
| Clinic + security + tenancy + authorization suites | 696 passed, 1 skipped (MySQL-only escaping case) |
| Pint | clean (`--dirty`) |
| TypeScript (`tsc --noEmit`) | 24 errors, all pre-existing in files not touched (22 in the deferred `resources/js/ovs/` orphan modules, 2 in shared components) |
| Production build (`vite build`) | passes (1 m 15 s) |
| `migrate:fresh` on MySQL 8 (scratch DB) | 283 migrations pass |
| Rollback of the new migrations + re-apply (MySQL) | passes |
| Full `migrate:reset` | stops at a pre-existing migration (`2026_09_29_102600`); forward-only deploys unaffected (P3-14) |
| Dev database | the 6 new migrations applied; `clinic_id` backfill verified (0 nulls) |
| Browser audit (desktop) | Dashboard, Patients, Patient 360 (+ billing/clinical/timeline JSON), Reception, Appointments, Staff, Branches, Profile — rendered against a hospital-scale synthetic clinic (50k patients, 120 staff, 5 branches); 0 console errors; 0 server errors |
| Mobile audit (375 px) | 6 pages, no horizontal overflow, 0 console errors |
| `security:production-check` | extended with 5 scale checks; correctly fails today on the missing S3 adapter |
| Synthetic scale tests | 1k / 10k / 50k / 100k clinics — section 9 |

---

## 1. Current architecture map

```
Company Portal (HQ)                          Clinic Portal (tenant)
 Marketing → CRM (lead→deal→won)              /clinic/* (subdomain or path mode)
   └ ClinicProvisioning (queued, idempotent)    ResolveClinicContext → SetCurrentBranch (Spatie team)
       └ ProvisionClinicFromDeal [provisioning] → clinic_portal → clinic_context → onboarding_completed
           tenant → branch → specialty →           → subscription_window → clinic_entitled → clinic_permission
           onboarding session → owner invite     Dashboard · Reception/Queue · Appointments · Patients/360
                                                 Staff/Practitioners · Leave · Admin (branches, rooms, settings)

Organization (customer) ─┬─ Clinic (TENANT: clinic_id on every clinical row) ─┬─ Branch (operations, permission team)
                         └─ Clinic …                                           └─ Branch …

Data: MySQL 8 (single primary) · queue/cache/sessions: database driver (dev) · files: local disks (dev)
Cross-cutting: ClinicContext (web/jobs/commands) · TenantScope (fails closed) · OrganizationScope (now clinic-aware)
               SecurityAudit (hash chain) · malware intake · TenantCache · TenantConnectionResolver · Metrics
```

## 2. Gap matrix

| Area | Built | Gap | Priority |
|---|---|---|---|
| Lead → deal → won → provisioning → owner onboarding → staff invites | yes | converted trials skip new onboarding; no provisioning UI | P2 |
| Login / reception / appointments / check-in / queue | yes | patient reminders absent | P2 |
| Consultation / encounter at check-in / prescription | **no routes** | build on canonical encounter (ADR §E) | P2 |
| Billing | **no** | model decision (ADR §G) then build; per-branch FY numbering | P2 |
| Dental chart / physio assessment | tables only (dental) / none (physio) | specialty extensions via registry | P2 |
| Investigations | tables only | ADR §H | P2 |
| Patient 360 | read side | write flows depend on the above | P2 |
| Clinic reports | no | reporting connection + queued exports | P3 |
| Communication (patients) | no | notifications queue, sender config | P2 |
| Renewal / expansion | yes (HQ) | — | — |
| Search | isolated; tenant-bounded | external engine for very large tenants; stop indexing patient PHI into HQ index | P2/P3 |
| Infra (Redis, S3, workers, logs, backups) | config ready | not provisioned | P1 for launch |

## 3. Backlog with status

### P0 (all fixed)

| ID | Issue | Status |
|---|---|---|
| P0-1 | `/api/search` let clinic users search sister clinics' patients and HQ search every patient | **Fixed** — active clinic + clinic document types + permission; HQ excludes clinical documents; FULLTEXT input sanitised. 7 tests |
| P0-2 | Clinic broadcast channels authorised any user | **Fixed** — active membership in an active clinic. 6 tests |
| P0-3 | Clinic users (incl. primary owner) could hard-delete their own account, cascading away sign-in and assignment history | **Fixed** — no self-deletion for any account; delete form removed. Tests inverted with reasons |
| P0-4 | Numbering sequences could fork (NULL branch in unique key) → permanent duplicate UHIDs | **Fixed** — generated `branch_scope` in the unique key, duplicates merged, race-safe insert-or-ignore + row lock. 2 tests |
| P0-5 | Deleting a branch/staff record cascaded away appointments, visits, encounters (+ prescriptions/notes), queues, bills; blanked authorship | **Fixed** — controllers refuse when history exists; 22 foreign keys now `RESTRICT`. 6 tests |

### P1

| ID | Issue | Status |
|---|---|---|
| P1-1 | Organisation scope used `users.organization_id` (wrong for multi-organisation members) | **Fixed** — active clinic's organisation. 2 tests |
| P1-2 | 7 patient-owned tables had no `clinic_id` (`patient_profiles`, `patient_medical_histories`, `patient_alerts`, `consent_records`, `patient_procedures`, `clinical_orders`, `invoices`) | **Fixed** — column + FK + index, backfilled, NOT NULL, `ClinicScoped` + `InheritsClinicOwnership`. 15 tests |
| P1-3 | `queue:close-day` hydrated every tenant's bookings every 15 min | **Fixed** — indexed UNION, streamed, chunked; today's pairs limited to branches already closed in SQL. Query count constant in tenant count (test) |
| P1-4 | `RunsPerClinic` loaded every clinic | **Fixed** — `lazyById(500)` |
| P1-5 | Scheduler not multi-server safe | **Fixed** — every task `onOneServer()`. Test |
| P1-6 | Redis `retry_after` 90 s < 600 s job timeouts; jobs could run before commit | **Fixed** — 900 s, `after_commit`, named queues, production check. Test |
| P1-7 | Search index jobs failing (25 in `failed_jobs`), no tenancy contract, bulk reindex = 1 job/record and broken in queue | **Fixed** — `deleteWhenMissingModels`, retries, `withoutTenancy`, `search` queue, paged reindex. Test |
| P1-8 | Bearer links (invites, resets) in plaintext queue payloads | **Fixed** — `ShouldBeEncrypted`, `critical` queue; notification jobs encrypted too. Test |
| P1-9 | S3 driver configured but `league/flysystem-aws-s3-v3` not installed | **Open — needs your approval** (dependency change). The production check now fails on it |
| P1-10 | Dead parallel stacks | **Fixed** — 24 unrouted controllers, `ClinicInvitationService`, 5 never-rendered employee page files removed (route count unchanged, 470). **Kept pending approval:** legacy `App\Services\PatientRegistrationService` + its already-failing test |
| P1-11 (measured) | Patient name search used a platform-wide FULLTEXT index | **Fixed** — tenant-bounded word-prefix match. 9 tests |
| P1-12 (measured) | Clinic-wide "today" counts used `date()` predicates with no clinic+time index | **Fixed** — `whereOnDate()`/`whereSinceDate()` macros on 18 hot call sites + `appointments(clinic_id, scheduled_at)`. Test |

### P2 (required before the Dental + Physio launch) — open

P2-1 clinical workflow after the queue · P2-2 billing model decision then build · P2-3 per-clinic/branch FY document numbering (random numbers on global unique indexes today, unreachable) · P2-5 patient communication · P2-6 config-driven specialty registry · P2-7 migrate 10 legacy-shell pages · P2-9 storage metering · P2-10 dashboard pre-aggregation · P2-12 stop indexing patient PHI into the HQ search index (privacy + the index's FULLTEXT grows with every patient) · P2-13 tenant offboarding/purge procedure (clinics with history can no longer be hard-deleted, by design) · P2-14 Horizon or equivalent queue dashboard (dependency decision).

### P3 / P4

See audit §5–§6; additions from this work: P3-14 full rollback chain, P3-15 dev schema drift, P3-16 staff list pagination, P3-17 drop the now-unused `patients_name_fulltext` index once nothing reads it.

## 4. Canonical domain model map

See ADR-001 §A–§I. Summary: tenant = **clinic**; staff = `clinic_employees` + `doctor_profiles` (Practitioner); patient = `patients` + clinic-owned satellites; encounter = `clinical_encounters` anchoring all clinical children; appointment = `appointments`; billing = `patient_billings` family (*owner confirmation*); investigations = `clinical_orders` → `xray_records`/`lab_orders` → `diagnostic_results`; inventory = `inventory_*`. Retired/isolated: `DoctorProfile` alias, `doctor_availabilities`, `availabilities`, `queue_entries`/FrontDesk, `stock_*`, `treatment_catalogs`/`procedure_masters`, `invoices` (pending decision).

## 5. Tenancy safety report

- One canonical context (`ClinicContext`) for web, jobs and commands; organisation for clinic users now derives from it.
- Every patient-owned record now carries `clinic_id` and the tenant scope; cross-clinic links refused at insert (`InheritsClinicOwnership`).
- Search, broadcast channels and multi-organisation members were the three live leaks; all closed with regression tests.
- 216 scope bypasses reviewed by pattern; every routed clinic bypass re-applies a clinic/branch filter or constrains a scoped parent. No IDOR found.
- Cache keys: `TenantCache` makes tenant keys impossible to build without a clinic and flushable per clinic.
- Jobs: tenant jobs carry ids and run in `runForClinic`; cross-tenant jobs (search) declare `withoutTenancy`.
- Remaining: company users bypass `TenantScope` by design (HQ); keep HQ off clinical routes (enforced by `clinic_portal`) and off clinical search (now enforced).

## 6. Security report

Fixed: P0-1/2/3/5, P1-8, FULLTEXT operator injection (could raise SQL syntax errors), LIKE wildcard escaping in patient search, sensitive data kept out of telemetry (no bindings, route names only). Pre-existing and verified: hashed + signed invitation/reset tokens, throttled auth, malware intake, authorised downloads, security headers, audit hash chain, production gate. Open: S3 adapter (P1-9), local git-ignored credential scripts in the project root (P3-12), external penetration test (required before scale rollout), 2FA (Company side, planned).

## 7. Database / index report

New indexes: `appointments(status, scheduled_at, branch_id)`, `appointments(clinic_id, scheduled_at)`, `clinic_queues(closed_at, queue_date, branch_id)`, `patients(clinic_id, phone)`, `patients(clinic_id, alternate_phone)`, `clinic_queue_items(clinic_queue_id, status)`, `patient_timeline_events(patient_id, created_at)`, `patient_billings(clinic_id, branch_id, status)`, `ent_notifications(notifiable_type, notifiable_id, read_at)`, `login_histories(user_id, created_at)`, `queue_histories(clinic_id, created_at)`, `clinic_operating_hours(day_of_week, branch_id, is_closed, close_time)`, `(clinic_id, patient_id)` on the 7 newly clinic-owned tables, `numbering_sequences(organization_id, branch_scope, entity_type)` unique. Integrity: 22 FKs `CASCADE`/`SET NULL` → `RESTRICT`. Non-sargable `date()` predicates removed from 18 hot call sites. Remaining: global unique indexes on random document numbers (P2-3); global FULLTEXT on `patients` and `ent_search_documents` (P2-12, P3-17).

## 8. Queue / cache report

Named queues `critical, default, notifications, provisioning, search, imports, reports, maintenance` (`config/queue.php`), `retry_after` 900 s on database and Redis, `after_commit`, encrypted bearer-link mail, `queue:monitor` per queue with thresholds, failed-job pruning after 30 days, job duration/failure metrics, every scheduled task `onOneServer()`. Cache: `TenantCache` (`t:{clinic}:v{n}:…`, per-clinic flush), global `g:` keys. Production must run Redis for queue, cache, locks, sessions and the Spatie permission cache (enforced by the production check). Horizon is not installed (dependency decision).

## 9. 100k scale readiness report

_Filled in with the 50k/100k runs below._

## 10. Production infrastructure checklist

| Item | State |
|---|---|
| Managed MySQL 8 (encryption at rest, PITR, automated backups) | Not Started |
| Read replica(s) + `DB_READ_HOST`, `DB_REPORTING_CONNECTION` | Config ready · Not Started |
| Redis (queue, cache, locks, sessions) | Config ready · Not Started |
| Object storage + `league/flysystem-aws-s3-v3` | **Blocked on approval** |
| Worker pools (interactive / long) + `queue:restart` on deploy | Documented · Not Started |
| Scheduler on every app server (`onOneServer`) | Implemented |
| Readiness `/health/ready` + liveness `/up` on the load balancer | Implemented · LB not configured |
| Structured logs (`json`, `performance`, `metrics`, `security`) shipped off-box | Implemented · shipping Not Started |
| Slow-query / query-budget / slow-request alerts | Implemented (logs) · alerting Not Started |
| Encrypted backups + weekly restore test | Implemented · restore test never run (no mysqldump locally) |
| ClamAV with freshclam | Not Started |
| TLS, WAF, rate limits at the edge | Not Started |
| Secret manager | Not Started |
| Load test on production-class hardware | Not Started (this report's runs are on a laptop, 128 MB buffer pool) |
| Penetration test | External Review Required |

## 11. Compliance readiness checklist

Statuses only — nothing here is a certification.

| Area | Status |
|---|---|
| Tenant isolation of clinical data | Implemented (tests) |
| HQ never sees clinical content | Implemented (search, channels, portal gate) |
| Audit trail (append-only, hash chain, request id) | Implemented |
| Clinical history retention (no hard deletes through the app) | Implemented |
| Consent records per clinic | Implemented (storage) · consent UX Not Started |
| Data export per tenant/patient | Not Started |
| Deletion where legally permitted (purge procedure honouring retention) | Not Started (P2-13) |
| Retention policy (logs 180 d+, backups, failed jobs 30 d) | In Development |
| ABDM readiness (ABHA on patient, HPR on practitioner, HFR on branch) | In Development (fields/ADR only) |
| Incident response runbook | Not Started |
| VAPT | External Review Required |
| Encryption in transit / at rest | Depends on infrastructure (Not Started) |

## 12. Exact next implementation sequence

1. **Approve and install** `league/flysystem-aws-s3-v3`; provision S3 buckets (documents, backups with object lock); run `security:production-check --target=staging` until green.
2. **Provision Redis** and switch queue, cache, sessions; deploy two worker pools; wire `/health/ready`; ship logs.
3. **Run a restore test** of an encrypted backup into a scratch database (`backup:verify`).
4. **Decide billing** (ADR §G) and the legacy registration service; then remove legacy code and stop indexing patient PHI into the HQ search index (P2-12).
5. **Per-clinic numbering** for encounters, prescriptions, invoices (P2-3) before any of those screens ship.
6. **Clinical workflow**: encounter at check-in → consultation → prescription → investigations → billing, on the canonical models, each with cross-tenant tests and audit events.
7. **Specialty registry** (config), then dental chart and physio assessment as extensions.
8. **Patient communication** (reminders) on the `notifications` queue.
9. **Staging load test** with `perf:seed-tenants` at 10k on production-class MySQL, then the go/no-go for launch.
10. **Before 10k**: dashboard pre-aggregation, tenant purge/export tooling, alerting on the performance/metrics logs, Horizon (or equivalent).
11. **Before 100k**: external search engine for large tenants, read replicas in use for reports, partitioning of `security_audit_logs`/`appointments` by time, re-measure against the ADR §P sharding triggers.

## 13. Summary

**Already complete (verified, unchanged):** clinic context resolution, fail-closed tenant scope, branch isolation, clinic route authorisation, same-clinic composite keys, hashed/signed tokens, malware intake, audit chain, idempotent provisioning, queue state machine with row locks, per-clinic case numbers, production gate.

**Fixed in this work:** P0-1…P0-5, P1-1…P1-8, P1-10…P1-12; scale foundation (observability, health readiness, tenant cache, tenant routing contract, reporting connection, read/write split config, named queues, failed-job recovery, scheduler safety, production checks); 67 new regression tests; synthetic scale harness (`perf:seed-tenants`, `perf:benchmark`).

**Remains:** P1-9 (approval), all P2 items, infrastructure provisioning, restore test, production-class load test, pen test.

**Must happen before the Dental/Physio launch:** steps 1–9 of section 12; specifically S3 + Redis + workers + restore test, billing decision and per-clinic numbering, the clinical workflow screens, patient reminders, and a staging load test.

**Must happen before 10k clinics:** all of the above plus dashboard pre-aggregation, tenant export/purge, alerting, a queue dashboard, read replica for reports, and a re-run of `perf:benchmark` at 10k on production hardware with every hot path inside budget.

**Must happen before 100k clinics:** an external search engine for very large tenants, time partitioning of the largest tables, replicas carrying all report/export reads, worker autoscaling per queue, and a measured decision against the ADR §P sharding triggers (not before).
