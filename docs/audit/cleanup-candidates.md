# Cleanup candidates (Audit G)

**Nothing was deleted.** Each candidate gives:
- the file(s);
- the evidence;
- the references checked (`app/`, `routes/`, `resources/js/`, `tests/`);
- the impact;
- the risk;
- the recommended action;
- the safeguards to use before acting.

Standing safeguards for every item:
1. Commit or back up the current working tree first: 72 untracked files today (security S-5).
2. Grep `tests/` as well as `app/` (memory `feedback_verify_deletions_against_tests`).
3. Run the narrow affected tests, then the full suite, and compare against the 15-failure baseline.
4. Do it as a separate reviewed change.
5. Never write files from regex (memory `feedback_no_regex_file_writes`).

| ID | Candidate | Evidence | Refs checked | Impact (routes / migrations / data / tests) | Risk | Action |
|---|---|---|---|---|---|---|
| C-1 | Root scratch files: `all())`, `oralcares_saas` (2.4 MB SQLite), `reset.php`, `update_password.php`, `test-widget.php`, `refactor.ps1`, `reconstruct.cjs`, `generate_manifests.cjs`, `generate_metrics.cjs` | `all())` is a captured tinker error. `reset.php` and `update_password.php` set an admin password to a weak literal. `reconstruct.cjs` reads another IDE's private folder. All are gitignored | No references in app/routes/tests/package.json | None | Low | **Remove** from the working copy (they are not in git). Copy them to the backup folder first if the user wants them |
| C-2 | 15 rotted tests (test-baseline.md §1) | Each fails on removed code: `ModuleRegistry.ts`, `app-shell/*`, `TenantProvisioningService`, `clinic_settings`, `clinical.master.departments.index` | Run output | Tests only | Low | **Repair** (`PatientRegistrationTest`, `TaskControllerTest`, `Phase4CustomerSuccessTest`) **or remove**. Removal needs user approval |
| C-3 | `resources/js/app.js`, `resources/js/bootstrap.js`, Breeze layouts (`resources/views/layouts/app.blade.php`, `guest`, `auth/login.blade.php`, `components/enterprise-auth.blade.php`), `alpinejs` | `app.js` is not a Vite input; three Blade files `@vite` it (B-11) | `ProfileController`, `NotificationViewController`, `AppLayout`/`GuestLayout` View components | `/profile`, `/notifications*` routes; Breeze auth tests | Medium | **Replace** `/profile` and `/notifications` with Inertia pages, then deprecate and remove |
| C-4 | `routes/ai.php` | Not loaded by `bootstrap/app.php`; only line is commented out | None | None | Low | Remove, or keep as a stub with a README note |
| C-5 | `app/Shared/{Notification/Jobs/BatchNotificationJob, Notification/Listeners/CentralNotificationListener, Search/Models/SavedSearch, Search/Providers/SearchServiceProvider}` | 0 references outside their own files (grep app/routes/tests) | Also check `bootstrap/providers.php` and `config/app.php` for provider registration | None found | Low–Med | **Deprecate**, verify provider registration, then remove |
| C-6 | `resources/js/components/{base (18), charts (2), widgets (21), enterprise-system (31)}` | 0 importers outside each folder. `RevenueIntelligenceWidget` adds 1 tsc error | grep `components/<dir>` across `resources/js` | Fewer tsc errors | Low | **Remove** after checking `import.meta.glob` users and string registries |
| C-7 | `resources/js/ovs/*` (104 files) | All 22 of the other tsc errors are here. Still imported by `components/ui/*` shims, `enterprise*`, `executive-shell` | 30+ importers | Company pages | **High** | **Keep** for now. **Consolidate** Company onto `Company/design`, then retire. Memory `deferred_ovs_orphan_modules`: the user deferred this |
| C-8 | Duplicate `crmTheme.ts` (`pages/Company/design/crmTheme.ts`, `pages/Company/Crm/components/crmTheme.ts`) | Two copies | Importers in `Company/design/*`, `Lifecycle/ProvisioningWorkspace.tsx` | Company only | Low | **Consolidate** to the design copy |
| C-9 | Never-dispatched events: `NotificationSent`, `PatientAddedToQueue`, `TenantProvisioningStarted/Completed/Failed` | grep for `new X(` and `X::dispatch` finds nothing | Check `tests/` (`Event::fake` assertions) and `channels.php` | Possibly tests | Low | Re-grep tests, then **remove** or wire them up |
| C-10 | Legacy queue `queue_entries` / `QueueEntry` | 0 rows; 2 app refs, 0 tests | app | Table drop needs a migration | Low | **Deprecate**: remove the model and refs, then a drop migration (needs approval) |
| C-11 | `teams` / `team_users` / `Team` vs `company_teams` | 1 / 0 rows vs 9; `Team` has 14 app and 14 test refs | Memory lists 8 test files | Lead routing engine | **High** | **Consolidate** after the People Teams UI exists (memory step order) |
| C-12 | `contacts` / `Contact` vs `marketing_contacts` | 23 vs 613 rows; 36 app, 24 test refs | | CRM contacts pages | High | **Consolidate**; the business decision is pending (frozen per memory) |
| C-13 | Commercial docs: `commercial_documents` (1), `company_quotations` (14), `commercial_invoices` (3), `invoices` (0) | B-2 | 14+ tests each | Finance pages, PDFs | High | **Consolidate**, one ledger. Do not delete |
| C-14 | `@tailwindcss/vite` 4.3.2 (unused), `three`, `@types/three`, `axios`, `playwright` | Not in `vite.config.js`; `three` imported nowhere (M0 note); Inertia v3 has no axios | grep `resources/js` | `package.json` only | Low | **Remove** after approval (dependency change needs approval per CLAUDE.md) |
| C-15 | `inventory_*` models and `InventoryFoundationTest` | Unrouted, 0 rows, test failing | 4–5 refs per model | Migrations exist | Medium | **Decide**: build or retire. Until then, mark the test skipped with a reason |
| C-16 | `treatment_catalogs` (48 rows) vs `clinical_procedures` (46) | Two catalogues (B-3); the class name collides with the `TreatmentCatalog` service | Needs a precise grep of the model's writers | Provisioner, catalogue | Medium | **Repair**: make `clinical_procedures` the single source; backfill; then deprecate |
| C-17 | `docs/architecture/clinic-portal-replacement-map.md` | Stale rows (D-13) | – | Planning only | Low | **Repair** (update) |
| C-18 | Old `ClinicPortal/Dashboard.tsx` and the REPLACE pages | Rejected design; still live | `ClinicDashboardController` | Non-owner home | Medium | **Replace** per the binding decision. Delete only after the replacement is live, there are no imports, no test asserts against it, and browser QA has passed at three widths |
| C-19 | `pages/Dev/*`, `simulator/`, `contexts/SimulatorContext.tsx` | Route is local-only, but the pages are bundled (78 kB chunk) | `NotificationContext.tsx` imports the simulator | Bundle size | Low | **Keep** the dev tool. Exclude `pages/Dev` from the production glob |
