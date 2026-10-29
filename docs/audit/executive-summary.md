# Executive summary: OralCares repository audit, 2026-10-27

This was a read-only audit of the working tree on `wip/m0-snapshot-2026-10-22`:
- no app code, dependencies, migrations, database rows or git state were changed;
- no dev servers were started or stopped;
- no messages were sent.

Deliverables are in this folder:
- `repository-inventory.md`
- `architecture-and-dependencies.md`
- `module-completion-matrix.md`
- `backend-database-audit.md`
- `clinic-portal-audit.md`
- `security-and-tenancy-audit.md`
- `test-baseline.md`
- `cleanup-candidates.md`
- `critical-risks.md`

## 1. What the project contains

A Laravel 12.69 / PHP 8.2 monolith with Inertia v3 + React 19 + TypeScript 7 + Vite 7 + Tailwind 3. It serves:
- a **Company/HQ portal**: CRM, Marketing, Customer Success, Lifecycle/Subscriptions, People, Finance, Support, Integrations;
- a **multi-tenant Clinic Portal**: Dental and Physio, row-level tenancy on `clinic_id`, branch as the Spatie team.

Scale of the codebase:
- 660 routes, 138 controllers, 373 service files, 269 models, 345 migrations (1 pending, deliberately not run);
- 450 test files: **4,101 passing, 15 failing (all old test rot), 9 skipped**.

## 2. What works and what does not

**Works (VERIFIED by tests and code):**
- clinic login, membership and branch context;
- server-side permissions, workspaces, Owner Mode and entitlements;
- patient registration and directory;
- Patient 360 (17 sections);
- consultation workspace (Dental and Physio extensions, prescriptions with allergy review, orders and results);
- appointments calendar;
- billing register (bills, collections, refunds, adjustments, summary) and expenses;
- deletion governance;
- deal-won provisioning;
- the whole Company CRM and Marketing suite.

The backend for almost every clinic domain exists and is tested, including queue, staff, payroll, leave, reports, WhatsApp, follow-ups and admin.

**Does not work for a real clinic day (VERIFIED):**
- 20 Clinic screens have no page and show "Unavailable".
- Critically, there is no Front Desk/Queue, no Checkout and no doctor Today list. A signed consultation **cannot be billed from the UI** (`CheckoutController.php:88,247` points at a missing page).
- Also missing: staff/branches/settings admin, reports, WhatsApp setup, payroll and leave screens.
- Live WhatsApp delivery is NOT VERIFIED (0 connections in dev).

## 3. Worst architectural and backend problems

1. **Uncommitted, unpushed work.** It includes untracked canonical security code (`BranchReach`, `BillingRegister`, `PatientCareGuard`) and 5 applied migrations, one of which drops patient archive columns (R-1).
2. **Test signal is weaker than it looks.** Tenant scope is off in ~435 of 450 test files, and Clinic page-existence checks are disabled (R-5).
3. **WhatsApp webhooks fail open** when the app secret is unset, and clinic-owned numbers cannot be signature-verified with one global secret (R-3).
4. **Reports bypass branch reach**: a branch-limited admin can read all branches' finance (R-4, partially verified).
5. **Duplicate domain models on the Company side**: three or four commercial document systems, two team systems, two contact systems, two procedure catalogues (R-9). Also `organizations` serves as both tenant and customer (from memory; not re-verified).
6. **Subscription-less clinics are unrestricted** (12/33 in dev). There is no payment gateway, MFA or CI (R-6, R-7).

## 4. What to preserve

- The clinic backend architecture:
  - `ClinicContext`/`TenantScope` with the queued-job guard;
  - `ClinicAccess` (permissions → workspaces);
  - `ClinicNavigation` + `ClinicFrontend::AVAILABLE`;
  - `BranchReach`, `BillingRegister`, `PaymentService`, `IntakeSubmissions` idempotency;
  - `DeletionGovernance`, `OwnerMode`, `SecurityAudit` hash chain, `ResourceLimitService` seat locks;
  - the specialty/extension gating.
- Same-clinic composite FKs and uniques in the schema.
- The `resources/js/clinic` cx kit and its guard tests. The approved login, sidebar and top bar.
- Patient 360, the consultation workspace, Billing/Expenses and Orders, all NEW and approved per the trackers.
- The Company CRM/Marketing suite: consolidate, do not rewrite.

## 5. What to repair or replace

- **Replace** (binding decision): the old shared `Dashboard.tsx`, Appointments, Patients Index/Create, the intake dialog, Settings/PatientForm, Notifications, Support and Onboarding (REPLACE rows).
- **Build**: the 20 missing Clinic pages, Checkout / Front Desk / Today first.
- **Repair**:
  - webhook fail-open;
  - report branch reach;
  - test-suite tenancy enforcement;
  - the 15 rotted tests;
  - the Breeze `/profile` and `/notifications` pages;
  - the stale replacement map and billing-decision comment.

## 6. Cleanup candidates (detail in cleanup-candidates.md)

- **Low risk**:
  - root scratch scripts (C-1);
  - `routes/ai.php`;
  - 4 unreferenced `app/Shared` classes;
  - 4 dead `components/*` folders (72 files);
  - 5 never-dispatched events;
  - `queue_entries`;
  - unused npm packages (needs approval).
- **High risk, consolidate only**: `ovs/` (load-bearing for Company), `teams`, `contacts`, commercial documents.
- Nothing was deleted.

## 7. Missing requirements

Not implemented:
- queue display (TV);
- payment gateway and renewal invoicing;
- MFA;
- concurrent-session/login limits (named seats exist);
- seat enforcement on downgrade;
- per-connection Meta app secret;
- inventory (unrouted);
- CI;
- E2E/browser tests;
- an audit viewer beyond Patient 360;
- SMS/email providers (notifications are SKIPPED, not delivered);
- CRM reporting (per memory).

Out of scope by decision: hybrid/offline and the Allergies card on Patient 360.

## 8. Safest implementation order

| Step | Work | Depends on |
|---|---|---|
| 0 | **Safety** (no feature work): user-reviewed commits and push to a private remote; refresh backup; minimal CI (suite + tsc + build) | – |
| 1 | **Test signal**: enforce tenant scope in `Feature/Clinic`; route-sweep isolation test; link-availability test; repair or remove the 15 rotted tests | 0 |
| 2 | **Security fixes**: webhook fail-closed + production check; report `BranchReach`; subscription backfill, then deny-by-default flag | 1 |
| 3 | **Decide** the navigation direction (sidebar vs top-nav) and record the billing decision | Before any new shell or page work |
| 4 | **Close the clinic day loop**: Checkout → Front Desk/Queue → Doctor Today, reusing existing services | 2, 3 |
| 5 | **Owner admin**: Staff, Branches/Departments/Rooms, Settings index/catalogue/consents, Reports (with the fix from step 2), WhatsApp setup | 4 |
| 6 | **Replace** the remaining REPLACE pages (role dashboards, Appointments, Patients list/create, intake, Notifications, Support, Onboarding); then delete old files under the deletion rules | 4 |
| 7 | **Company consolidation**: commercial ledger, teams, contacts, catalogue; retire `ovs/`; replace the Breeze pages | 1 |
| 8 | **Production readiness**: MFA, payment gateway, Redis queue/cache/session, live WhatsApp verification, MySQL concurrency run, E2E smoke tests, scale benchmark | 5–7 |

## 9. Extra access or evidence needed

- **Production/staging hosting details**: env (secrets presence only), queue/cache/session drivers, backups, TLS, DB encryption. Needed to verify R-3, R-11 and S-8.
- **A real (test) Meta WhatsApp Business connection and app secret**, to verify delivery, webhooks and clinic-owned numbers.
- **A MySQL test database**, to run `tests/Concurrency` (`phpunit.mysql.xml`).
- **Permission to run a dev server and browser QA** at 375, 768, 1280 and 1440 for the 16 live Clinic pages, plus an axe pass.
- **Laravel Boost MCP** (timed out this session) for schema and docs tools.
- **User decisions**: navigation direction; which commits to make; whether to delete the rotted tests; the Company duplicate-model canonicals; and whether "login limits" means named seats or concurrent sessions.
