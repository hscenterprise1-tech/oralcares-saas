# Critical risks (ranked)

Severity:
- **P0**: blocks go-live, or risks data or PHI loss;
- **P1**: must be fixed before paying clinics or scale;
- **P2**: hygiene.

The detail for each risk is in the linked document.

| # | Risk | Severity | Status | Evidence | Recommended first step |
|---|---|---|---|---|---|
| R-1 | Canonical code and 5 applied migrations (one destructive) exist only as untracked files. 148 modified files are uncommitted. Nothing is pushed (`main` is +14 on origin) | **P0** | VERIFIED | `git status`, `git branch -vv`; S-5, B-4 | User reviews the work and commits it in slices, then pushes to a private remote. Refresh the off-machine backup |
| R-2 | The clinic front-office loop is not usable end to end: no Front Desk/Queue, no Checkout, no doctor Today list | **P0** for go-live | VERIFIED | D-1, D-2, D-3; `CheckoutController.php:88,98,247` | Build Checkout → Front Desk → Today. The backends exist and are tested |
| R-3 | WhatsApp webhooks accept unsigned payloads when `META_WHATSAPP_APP_SECRET` is unset. There is one global secret, so clinic-owned numbers cannot be verified | P1 (P0 if production lacks the secret) | VERIFIED | S-3; `WebhookSignatureVerifier.php:47-70` | Fail closed outside local/testing; add to `security:production-check` |
| R-4 | Reports and exports ignore branch reach. A branch-limited admin can read other branches' finance data | P1 | PARTIALLY VERIFIED | S-2; `ClinicReports.php:101`, `ClinicAccess.php:337` | Apply `BranchReach`; add the test |
| R-5 | The test suite mostly runs with tenant scope off and with page-existence checks off, so green does not mean isolated or rendered | P1 | VERIFIED | S-1, D-6; `TenantScope.php:25`, `tests/Pest.php:56` | Enforce the scope for `Feature/Clinic`; add a route sweep; add a link-availability test |
| R-6 | Clinics without a subscription are unrestricted (12 of 33 in dev) | P1 | VERIFIED | S-4; `EnforceSubscriptionWindow.php:46-53` | Backfill subscriptions, then deny by default behind a flag |
| R-7 | No CI, no MFA, no E2E tests, no payment gateway; MySQL concurrency tests not run | P1 | VERIFIED (absence) | Inventory §6; S-8 | Minimal CI (suite + tsc + build) first |
| R-8 | `/profile` and `/notifications` (Breeze Blade) will throw `ViteException` under a production build | P1 | PARTIALLY VERIFIED | B-11 | Replace with Inertia pages |
| R-9 | Duplicate domain models: commercial docs ×3–4, teams ×2, contacts ×2, catalogues ×2 | P2 (it becomes P1 when reports or finance are built on them) | VERIFIED (counts) | B-2, B-3 | Decide the canonical model per family; consolidate with test migration |
| R-10 | The billing decision (PatientBilling vs Invoice) is recorded as "pending" but is de facto made | P2 | VERIFIED | B-1 | Record the decision; fix the stale comment at `SettingsController.php:53-55` |
| R-11 | Infrastructure defaults: DB queue, cache and sessions; plaintext PHI columns; single shared DB | P1 toward 100k | NOT VERIFIED in production | S-8, security §3 | Needs hosting and deploy evidence (see the executive summary's "access needed") |
| R-12 | Navigation direction conflict: zero-sidebar top nav versus the approved sidebar + top bar | Decision | VERIFIED (conflict exists) | architecture §6 | User decides before any shell work |
| R-13 | `public/build` is from 2026-10-17. Anyone not running Vite dev sees a stale UI | P2 | PARTIALLY VERIFIED (file date) | Inventory §6 | Rebuild as part of the release process (not done here) |
