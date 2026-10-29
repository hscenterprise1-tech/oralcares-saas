# Repository inventory (Audit A)

Audit date: 2026-10-27. Branch `wip/m0-snapshot-2026-10-22` at `6bd9869`. This was a read-only audit: no app code, dependencies, database or git state were changed. The only writes were this `docs/audit/` folder and a Vite build sent to a scratch directory outside the repo.

Status key: **VERIFIED** (seen directly in code, command output or the DB), **PARTIALLY VERIFIED** (code read but not exercised end-to-end), **NOT VERIFIED** (inferred or not run), **BLOCKED** (could not be checked).

## 1. Versions (VERIFIED: `composer show --direct`, `node_modules/*/package.json`, `php -v`)

| Layer | Installed | Manifest constraint | Note |
|---|---|---|---|
| PHP | 8.2.12 (ZTS, Windows) | ^8.2 | |
| Laravel framework | 12.69.2 | ^12.0 | |
| inertiajs/inertia-laravel | 3.3.4 | ^3.0 | |
| @inertiajs/react | 3.6.1 | ^3.6.1 | |
| React / react-dom | 19.2.8 | ^19.2.8 | |
| TypeScript | 7.0.2 | ^7.0.2 | `tsc` reports 24 errors (see test-baseline.md) |
| Vite | 7.3.6 | ^7.0.7 | Builds cleanly in about 41s |
| tailwindcss | **3.4.19** | ^3.1.0 | **`@tailwindcss/vite` 4.3.2 is also installed but unused.** `vite.config.js` does not load it, and PostCSS drives Tailwind 3. See cleanup-candidates.md C-14. |
| Pest | 3.8.7 | ^3.8 | |
| spatie/laravel-permission | 6.25.0 | ^6.25 | Teams mode, `branch_id` is the team key (`tests/Pest.php:121`) |
| laravel/pennant | 1.26.0 | ^1.24 | |
| tightenco/ziggy / ziggy-js | 2.6.4 | | |
| barryvdh/laravel-dompdf | 3.1.2 | | |
| laravel/breeze | 2.4.2 (dev) | | Its Blade/Alpine scaffolding is still live: `/profile` and `/notifications` (see backend audit B-11) |
| laravel/boost | 2.9.0 (dev) | | The MCP server timed out in this session, so Boost tools were not used |

Notable JS dependencies:
- **Unused**, per the M0 notes; re-verify before removing: `three`, `@types/three`, `alpinejs` (only `resources/js/app.js`, which is not a Vite input), `axios` (Inertia v3 dropped it).
- **Imported**: `playwright`, but there are no browser tests in `tests/`.

## 2. Repository layout

| Area | Count | Evidence |
|---|---|---|
| Routes (non-vendor) | 660 (company 318, clinic 295, api 19, webhooks 4, auth/profile ~20) | `php artisan route:list --except-vendor --json` |
| Route files | `web.php` (1,841 lines), `auth.php`, `channels.php`, `console.php` | `routes/ai.php` is **not loaded** (it is not in `bootstrap/app.php`), and its only line is commented out |
| Controllers | 138 (Clinic 43 top level + `Clinic/Admin`; Company 13 areas) | `app/Http/Controllers` |
| Services | 373 PHP files | `app/Services` |
| Models | 269 | `app/Models` |
| Policies | 20 | `app/Policies`. Most authorization is permission middleware plus service-level checks, not policies |
| Middleware | 19 | `app/Http/Middleware`, registered in `bootstrap/app.php:45-108` |
| Jobs | 27 + `Concerns/RunsForClinic` + `Middleware/ScopeToClinic` | `app/Jobs` |
| Events / Listeners | 81 / 3 | 5 events are never dispatched (cleanup C-9) |
| Artisan commands | 28 | Backfills, backups, `security:production-check`, `perf:*`, queue close-day |
| Migrations | 345 files: 344 ran, **1 pending** (`2026_10_21_173134_add_subscription_index_…`, not run, as instructed) | `php artisan migrate:status` (read-only) |
| Seeders / factories | 22 / 41 | Includes browser/stress seeders: `Patient360StressTestSeeder`, `OwnerModesBrowserTestSeeder` |
| Tests | 450 PHP files in `tests/` (Feature, Unit (1), Concurrency (2, MySQL via `phpunit.mysql.xml`)) | See test-baseline.md |
| Frontend TS/TSX | 718 files, 262 page files | `resources/js` |
| Blade views | 137 (auth 6, clinic printables 5, crm printables 12, components 56, vendor 27, errors 11, …) | `resources/views` |

### Other top-level directories in `app/`
`Contracts`, `Data`, `Repositories`, `Rules`, `Scopes`, `Shared` (47 files, a parallel notification/search layer; 4 classes are unreferenced), `Support`, `Traits`, `View`.

## 3. Frontend entry points and layouts (VERIFIED)

- **Vite inputs**: `resources/css/app.css` and `resources/js/app.tsx` only (`vite.config.js:7-10`).
- **`app.tsx`**: resolves `./pages/**/*.tsx`. Any `Clinic/` or `ClinicPortal/` component with no file falls back to `ClinicPortal/Unavailable.tsx` (`app.tsx:20-27`). A missing Clinic page therefore never errors; it shows a placeholder.
- **`resources/js/app.js` + `bootstrap.js`** (Breeze/Alpine) are **not** Vite inputs, but three Blade layouts still `@vite` them: `layouts/app.blade.php:16`, `auth/login.blade.php:13`, `components/enterprise-auth.blade.php:16`.

Portals and shells:

| Portal | Shell | Design kit |
|---|---|---|
| Clinic Portal | `resources/js/clinic/shell/ClinicPortalShell.tsx`: **240px sidebar at ≥lg + top bar**, with a Radix drawer below lg | `resources/js/clinic/ui/*` (the "cx" tokens). No `@/ovs`, `@/components/ui` or `crmTheme` imports (VERIFIED by grep; guarded by `tests/Feature/Clinic/Foundation/FrontendFoundationTest.php` and `Portal/ClinicUiFoundationTest.php`) |
| Company (HQ) | `pages/Company/design/CrmShell.tsx`, `CompanyShell` | `pages/Company/design/*` (26 files, including two `crmTheme.ts` copies) |
| Legacy / enterprise | `layouts/EnterpriseLayout.tsx`, `components/enterprise*`, `components/executive-shell` | `resources/js/ovs/*` (104 files), reached through the `components/ui/*` shims |
| Dev | `pages/Dev/DesignLab.tsx`, `simulator/`, `contexts/SimulatorContext.tsx` | Route registered only when `app()->environment('local')` (`routes/web.php:164`) |

Clinic page files that exist (VERIFIED, `find resources/js/pages/ClinicPortal`):
- Appointments (calendar);
- Approvals (hidden);
- Billing: Index, Reversals, Summary;
- CommandCenter;
- Consultations Workspace;
- Dashboard (the old shared one);
- Expenses;
- Notifications;
- Onboarding (Account, Done, Wizard);
- Orders: Index, Show;
- Patients: Index, Create, Edit, Show (Patient 360 with 17 sections);
- Settings/PatientForm;
- Support: Index, Show;
- Unavailable.

Under `pages/Clinic`:
- Auth: Login, ForgotPassword, SetupPassword, AcceptInvitation;
- Onboarding/Index;
- the Reception intake dialog components.

**Clinic components rendered by the server with no page file (20)**, all of which fall back to Unavailable (VERIFIED by diffing `Inertia::render` names against `resources/js/pages`):

| Component | Route(s) |
|---|---|
| `ClinicPortal/Billing/Checkout` | `clinic.billing.checkout` |
| `ClinicPortal/FrontDesk/Index`, `FrontDesk/List` | `clinic.reception`, `clinic.reception.list` |
| `ClinicPortal/Today/Index` | `clinic.consultations.index` |
| `ClinicPortal/FollowUps/Index` | `clinic.follow-ups.index` |
| `ClinicPortal/Reports/Index` | `clinic.reports.index` |
| `ClinicPortal/WhatsApp/Index` | `clinic.whatsapp.index` |
| `ClinicPortal/Staff/Index`, `Show`, `Form`, `DocumentSettings` | `admin.employees.*`, `admin.staff-documents.settings` |
| `ClinicPortal/Payroll/Index` | `clinic.payroll.index` |
| `ClinicPortal/Leave/Index` | `clinic.leave.index` |
| `ClinicPortal/Admin/Catalog`, `Admin/Consents` | `admin.catalog.*`, `admin.consents.*` |
| `Clinic/Admin/Settings/Index` | `admin.settings.index` |
| `Clinic/Admin/Infrastructure/Branches`, `Departments`, `Rooms` | `admin.branches/departments/rooms.index` |
| `Clinic/Visits/Index` | `clinic.visits.index` |

No non-Clinic component is missing.

## 4. Backend request pipeline (VERIFIED: `bootstrap/app.php`)

Global and web middleware run in this order:
1. `AssignRequestId` (prepended);
2. web defaults;
3. `ResolveClinicContext` → `SetCurrentBranch` → `HandleInertiaRequests`. The first two are moved ahead of `SubstituteBindings` so that route-model binding runs with tenant context;
4. `SecurityHeaders` and `RecordRequestMetrics` (appended).

Clinic route stack (typical):

```
web | clinic_portal | clinic_context | onboarding_completed | subscription_window | clinic_entitled:<module>? | clinic_permission:<perm> | clinic_workspace:<ws>? | clinic_module:<m>? | throttle?
```

Middleware coverage across the 660 routes:
- `clinic_permission`: 294
- `can:` (Company): 183
- `clinic_entitled`: 68
- `clinic_workspace`: 20
- `clinic_owner_mode`: 6

Routes without route-level permission middleware are listed in security-and-tenancy-audit.md §3. Every one sampled authorizes in its controller or service.

## 5. Auth, roles and permissions (VERIFIED in code)

- **One `web` guard and one `users` table.** `user_type` separates MasterAdmin, CompanyUser and ClinicUser (`App\UserType`).
- **Clinic access**:
  - `clinic_memberships` plus Spatie roles in teams mode keyed by `branch_id`;
  - `App\ClinicPermission` enum;
  - `App\Support\ClinicAccess\ClinicAccess` composes permissions, workspaces (`command_center`, `front_desk`, `today`, `billing`), capabilities and specialties (`ClinicAccess.php:331-341`);
  - `BranchReach` scopes money reads.
- **Owner Mode**: `EnsureOwnerMode` (alias `clinic_owner_mode`) and `App\Services\OwnerMode\OwnerMode`. T2 actions need a fresh password.
- **Company access**:
  - Spatie permissions through `can:` middleware and Gates;
  - `CrmPermissions::ALIASES` reconciles four naming conventions;
  - 22 seeders, 6 of which write roles.
- **Clinic login**: `App\Http\Controllers\Auth\ClinicLoginController`. It is rate-limited, regenerates the session, and requires an active membership in an active clinic plus an active staff record (`:170-190`).

## 6. CI / deploy / ops (VERIFIED absence)

- **No CI**: there is no `.github/`, and no other CI config was found.
- `docker/` holds Sail runtimes (PHP 8.0–8.5, MySQL, MariaDB, pgsql); `sail` is at the root.
- `docs/security/production-deployment.md`, `backups.md` and others are runbooks. `backup:run` and `backup:verify` are scheduled only in production/staging (`routes/console.php:70-71`).
- Every scheduled event is forced `onOneServer()` (`routes/console.php:167-169`).
- `.env.example` defaults `SESSION_DRIVER`, `QUEUE_CONNECTION` and `CACHE_STORE` to `database`.
- **`public/build/manifest.json` is dated 2026-10-17.** Anyone running without `npm run dev` gets a 10-day-old frontend (PARTIALLY VERIFIED: file date only).

## 7. Git state (VERIFIED: `git status`, `git branch -vv`)

- 148 modified, 72 untracked and 1 deleted paths on top of `6bd9869`. None of them are committed.
- The untracked paths include **canonical runtime code and 5 migrations that have already run on the dev DB**:
  - `app/Support/ClinicAccess/BranchReach.php`
  - `app/Services/Billing/BillingRegister.php`
  - `app/Support/Dashboard/OwnerCommandCenter.php`
  - `app/Services/Patient/PatientCareGuard.php`
  - `database/migrations/2026_10_24_191407_remove_patient_archive.php` (drops `patients.archived_at/archived_by/archive_reason`)
  - `…_add_death_record_to_patients_table.php`
  - and others
- `main` is `ceb03c1`, **14 commits ahead of `origin/main`** (unpushed). The WIP branch is 1 commit ahead of main and also unpushed.
- Off-machine backup: `C:\Projects\oralcares-source-backups\2026-10-22\` per the M0 notes. It predates the Patient 360 and Billing work (NOT VERIFIED for current content).

## 8. Docs and abandoned attempts

| Item | Status | Evidence |
|---|---|---|
| `docs/architecture/clinic-portal-replacement-map.md` | **Stale.** It still lists Patient 360, Consultation and Orders as MISSING; those pages now exist and are in `ClinicFrontend::AVAILABLE` | `app/Support/ClinicFrontend.php:17-40` |
| `docs/architecture/owner-command-center-blueprint.md`, `clinic-portal-ux-blueprint.md`, `patient-360-section-plan.md`, … | Untracked design docs | `git status` |
| `docs/design-review/*` (10 folders) | QA and review reports, including the Patient 360 final gate | |
| `docs/OEDS-v1.0.md`, `Components.md`, `Animation.md`, … | The legacy OVS/OEDS design system docs | Superseded by the clinic `cx` kit |
| `ORALCARES_MASTER_BACKLOG.md` (40 KB), `AGENTS.md`, `walkthrough.md`, `ROUTING_SETUP.md`, `tokens.json` | Tracked root docs | |
| Root scratch files: `all())`, `oralcares_saas` (2.4 MB SQLite DB), `reset.php`, `update_password.php`, `test-widget.php`, `refactor.ps1`, `reconstruct.cjs`, `generate_manifests.cjs`, `generate_metrics.cjs` | Gitignored (per M0 notes `.gitignore:32-41`); none are referenced | cleanup C-1 |
| `resources/js/ovs/` (104 files) | Legacy design system. Still load-bearing for Company through the `components/ui/*` shims | tsc errors live here |
| `components/base`, `charts`, `widgets`, `enterprise-system` | 0 external importers | cleanup C-6 |
| Removed Clinic frontend (2026-10-11) | Deliberately deleted. Server routes remain and render Unavailable | `app.tsx:14-27` |
