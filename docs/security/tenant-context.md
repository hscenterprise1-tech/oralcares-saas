# Tenant context

Hierarchy: **Organization → Clinic → Branch**. Clinic data is owned by a
clinic (`clinic_id`), and by a branch where the work is branch-local.

## Where the tenant comes from

| Process | Source of the tenant | Enforced by |
| --- | --- | --- |
| Web request | Session membership, bound to the clinic named by subdomain or `?clinic=` (`ResolveClinicContext`) | `TenantScope` on every `ClinicScoped` model, route binding, policies |
| Queued job | Ids carried by the job (`RunsForClinic`) | `ScopeToClinic` job middleware → `ClinicContext::runForClinic()` |
| Artisan command | `--clinic=ID` or an explicit `--all-clinics` (`RunsPerClinic`) | `ClinicContext::runForClinic()` per clinic |
| Scheduled task | Same as the job or command it runs | as above |

The tenant is **never** taken from the logged-in user, the session, or
static state in a job or command. `runForClinic()` clears the user and
membership, and restores the previous context afterwards.

## Rules

1. **A job that touches one clinic's data uses `RunsForClinic`** and calls
   `$this->forClinic($clinic, $branch)` when it is constructed. The job
   carries `tenantOrganizationId`, `tenantClinicId` and `tenantBranchId` as
   plain ints, so they survive serialization unchanged. When the job runs:
   - the clinic must exist and belong to the carried organization,
     otherwise it throws;
   - the branch must belong to the clinic, otherwise it throws;
   - a clinic that is no longer active: the job is dropped and logged.
2. **Clinic data read in a queued job with no tenant throws**
   (`TenantContextMissingException`). `TenantScope` knows it is inside a job
   through `Queue::before`/`after` (AppServiceProvider).
3. **Deliberately cross-clinic work** (platform telemetry, maintenance)
   wraps the read in `ClinicContext::withoutTenancy(fn () => …)`, so every
   such place is visible in review.
4. **Commands** that process clinic data use `RunsPerClinic` and refuse to run
   without `--clinic` or `--all-clinics`. Example: `documents:rescan`.
5. **Query builder bypasses scopes.** `DB::table(...)` is never tenant
   filtered. Any raw query over clinic data must add `where('clinic_id', …)`
   itself. `TenantTelemetryService` is the existing example: it filters by
   the clinic it is given.
6. Migrations, seeders and test fixtures run in console with no job flag and
   stay unscoped. Isolation tests bind `enforce_tenant_scope_in_tests` after
   building cross-clinic fixtures.

Tests: `JobTenancyTest`, `CrossTenantAccessTest`.
