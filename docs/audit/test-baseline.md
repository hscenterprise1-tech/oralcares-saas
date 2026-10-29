# Test and runtime baseline (Audit F)

Run on 2026-10-27 against the uncommitted working tree on `wip/m0-snapshot-2026-10-22`. Nothing was changed to make anything pass.

## 1. Full PHP suite: VERIFIED

Command: `php artisan test --compact`. It ran in the background against in-memory SQLite (`phpunit.xml:26-30`; queue `sync`, mail `array`).

| | 2026-10-27 (this run) | Patient 360 gate baseline (2026-10-27) | 2026-10-21 audit |
|---|---|---|---|
| Passed | **4,101** | 4,100 | 3,881 |
| Skipped | 9 | 9 | 9 |
| Failed | **15** | 15 | 15 |
| Assertions | 26,207 | | |
| Duration | 667 s (about 11 min) | | about 38 min |

**New failures: 0.** The 15 failures are the same tests as the baseline. Every one is **test rot**: the test points at code that was deliberately removed or renamed. None shows a production defect.

| # | Test | Cause (from the run output) | Classification |
|---|---|---|---|
| 1–3 | `ClinicWorkspaceTest` (3 tests) | `no such table: clinic_settings` | The table was retired; the test was not |
| 4 | `ClinicalFoundationTest › it can fetch clinical departments via API` | `Route [clinical.master.departments.index] not defined` | The route was removed |
| 5–7 | `Company\Crm\Phase0LifecycleTest` (3 tests) | `file_get_contents(resources/js/registry/ModuleRegistry.ts)` / `components/app-shell/BreadcrumbEngine.tsx`: no such file | Asserts on dead files that were deleted |
| 8 | `Company\CustomerSuccess\Phase4CustomerSuccessTest › links from CRM won deal…` | `array contains 'customer_account'` fails | Payload contract drift (NOT VERIFIED whether the UI depends on it) |
| 9 | `Company\SuperAdminBypassTest › navigation visibility…` | `components/app-shell/Sidebar.tsx` does not exist | Asserts on a deleted file |
| 10 | `InventoryFoundationTest` | `NOT NULL constraint failed: inventory_items.sku` | The inventory module is unrouted (see the matrix) |
| 11 | `PatientRegistrationTest` | `NOT NULL constraint failed: patients.clinic_id` | Old fixture predates `clinic_id NOT NULL` |
| 12–13 | `TaskControllerTest › update / destroy` | 403 where a redirect was expected | Authorization tightened after the test was written |
| 14–15 | `TenantProvisioningTest` (2 tests) | `Target class [App\Services\TenantProvisioningService] does not exist` | The service was replaced by staged provisioning |

Skipped (9):
- `MasterAdminRouteProtectionTest` (`markTestSkipped`, "routes moved to /company");
- `PatientWordPrefixSearchTest` (SQLite only);
- `MalwareScanningTest` (needs `CLAMAV_TEST_HOST`);
- the rest are datasets of the same.

Recommendation (not done): repair or delete the 15 rotted tests in one reviewed change so that "15 failed" stops hiding new failures. Deleting tests needs the user's approval (CLAUDE.md, Pest rules).

## 2. Important caveats on what the suite proves

1. **Tenant isolation is enforced in only 14 test files. FACT, VERIFIED.**
   - `TenantScope::apply()` returns early in console unless `enforce_tenant_scope_in_tests` is bound (`app/Scopes/TenantScope.php:25-55`), and the suite runs in console.
   - Only 14 test files bind that flag. They are under `Clinic/Tenancy`, `Security`, `Patients/Patient360Test`, `Consultations/*`, `Governance`, `Onboarding` and `Staff/MultiClinicInvitationTest` (grep `enforce_tenant_scope_in_tests`).
   - Every other test runs with the global scope **off**. A missing `where clinic_id` in a controller would pass those tests.
2. **The Clinic page-existence check is off. FACT, VERIFIED.**
   - `tests/Pest.php:56-57` sets `inertia.testing.ensure_pages_exist=false` for `Feature/Clinic`.
   - A Clinic test that asserts `->component('ClinicPortal/Today/Index')` passes even though the page does not exist. **The suite cannot tell you that a screen exists.**
3. **SQLite, not MySQL.**
   - Row locks (`lockForUpdate`), `sharedLock` seat checks and fulltext search behave differently.
   - `tests/Concurrency/*` (2 files, 13 tests) need `phpunit.mysql.xml`. They were **NOT VERIFIED** in this run.
4. **No browser or E2E tests.** `playwright` is installed but unused in `tests/`. UI behaviour is NOT VERIFIED by automation.
5. **Live WhatsApp is NOT VERIFIED.** The dev DB has 0 `whatsapp_connections` rows (read-only count). Tests use fakes.

## 3. TypeScript: VERIFIED

`npx tsc --noEmit -p tsconfig.json` (21 s) gives **24 errors**, the same as the baseline. All are in legacy code:

| File | Errors |
|---|---|
| `resources/js/ovs/calendar/scheduler.tsx` | 12 |
| `resources/js/ovs/patterns/prescription-builder.tsx` | 4 |
| `resources/js/ovs/patterns/odontogram.tsx` | 2 |
| `resources/js/ovs/calendar/mini-calendar.tsx`, `ovs/location/OVSLocationSelect.tsx`, `ovs/primitives/index.ts`, `ovs/tables/data-table.tsx` | 1 each |
| `resources/js/components/auth/OtpDigitInput.tsx` | 1 |
| `resources/js/components/enterprise-system/widgets/RevenueIntelligenceWidget.tsx` | 1 |

There are **0 errors in `resources/js/clinic/**` or `pages/ClinicPortal/**`.**

## 4. Lint: NOT VERIFIED (nothing to run)

- There is no ESLint config, no Prettier config and no `lint` script in `package.json`.
- PHP style uses Pint. Per the project rule, `pint --dirty` was **not** run (it would reformat 230+ uncommitted files), and Pint `--test` was not run either.

## 5. Frontend build: VERIFIED

- `npx vite build --outDir <scratchpad>` exits 0 and transforms 4,341 modules in 41 s. The output went to a scratch directory, so `public/build` was not touched.
- Largest assets:
  - CSS: 375 kB;
  - `CartesianChart` (recharts): 331 kB;
  - `vendor`: 323 kB;
  - `app`: 143 kB;
  - Dev-only `DataTableSimulationSection`: 78 kB. It is bundled because `import.meta.glob('./pages/**/*.tsx')` includes `pages/Dev/*`.
- The built manifest contains **no `resources/js/app.js` entry**, yet three Blade layouts `@vite` it. See backend-database-audit.md B-11.

## 6. Critical workflows lacking tests (FACT from test inventory, PARTIALLY VERIFIED)

| Workflow | Evidence of the gap |
|---|---|
| Any page actually rendering in a browser | No E2E tests; page-existence check disabled (§2.2) |
| Reports: branch-limited user | `tests/Feature/Clinic/Reports/ClinicReportsTest.php` (6 tests) has no case for a branch-restricted admin (see security S-2) |
| Consultation → checkout → payment, through the UI | The checkout page does not exist; backend tests exist under `Clinic/Billing` (45 tests) |
| Follow-ups board | 1 file, 4 tests |
| Visits | 1 file, 2 tests |
| WhatsApp webhook with no app secret configured | No test for the `UNCONFIGURED` fail-open path (security S-3) |
| Concurrency on MySQL | Exists but not run here |
| Tenant scope for the ~430 test files that do not enforce it | §2.1 |
| Subscription lapse for clinics with no subscription | Intentionally unrestricted (`EnforceSubscriptionWindow.php:46-53`) |

## 7. Reproduction

```bash
php artisan test --compact
```
```bash
npx tsc --noEmit -p tsconfig.json
```
```bash
php artisan test --compact tests/Feature/Clinic/Reports/ClinicReportsTest.php
```
