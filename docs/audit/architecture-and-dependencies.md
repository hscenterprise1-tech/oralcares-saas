# Architecture and dependencies

Read-only audit, 2026-10-27. Versions are in repository-inventory.md §1.

## 1. Shape of the system (VERIFIED)

There is one Laravel 12 monolith and one database (MySQL in dev; in-memory SQLite for tests). It serves three portals from one `users` table and one `web` guard:

| Portal | Users (`UserType`) | Route prefix | Authorization | Frontend |
|---|---|---|---|---|
| Master Admin / Company HQ | MasterAdmin, CompanyUser | `/company/*` (318 routes) | Spatie permissions through `can:` plus controller Gates; `CrmPermissions::ALIASES` | `pages/Company/*` with the `Company/design` kit; legacy `ovs` behind `components/ui` shims |
| Clinic Portal | ClinicUser | `/clinic/*` (295 routes); path mode or domain mode (`config('app.routing_mode')`) | `clinic_permission` (`App\ClinicPermission`), workspaces, entitlements, Owner Mode, `BranchReach` | `pages/ClinicPortal/*` plus `resources/js/clinic/*` (the `cx` kit) |
| Public | guests | `/enquiry`, `/login`, `/clinic/login`, webhooks | throttles, signed URLs | `pages/Public/LeadIntake.tsx`, auth pages |

Tenancy is row-level on `clinic_id` (`ClinicScoped` → `TenantScope`), and branch is the Spatie team. `organizations` is still both the HQ tenant and the customer record (memory `crm_architecture_audit_2026_08_31` defect 3; NOT re-verified).

## 2. Layering (VERIFIED by tracing)

```
Route (middleware stack) → Controller (thin) → Domain service (writes, transactions, locks, audit)
                                             → Read model / Support class (ClinicAccess, BillingRegister, Patient360*, OwnerCommandCenter, ClinicReports)
                                             → Eloquent models (ClinicScoped) / query builder with explicit clinic_id
→ Inertia::render(component, props)  |  JSON for in-page actions  |  Blade for printables (clinic/crm *_printable)
```

Cross-cutting pieces:
- `ClinicContext` (request-scoped tenant/branch/employee);
- `ClinicAccess` (one request-memoised state: permissions, workspaces, capabilities, specialties, modules);
- `ClinicNavigation` and `ClinicFrontend::AVAILABLE` (nav from one registry, with a frontend-exists gate);
- `EntitlementResolver` (subscription window and limits);
- `IntakeSubmissions` (idempotency);
- `SecurityAudit` (hash-chained audit log);
- `ClinicClock` (clinic-local dates over UTC storage).

## 3. Commercial lifecycle (FACT from code and memory; flows PARTIALLY VERIFIED)

```
Lead (CRM, 12 stages) → Demo → Trial (TrialController, provisioning) → Quotation → Deal Won
 → ProvisionClinicFromDeal (queued, staged ClinicProvisioningRun) → owner invitation → Clinic onboarding wizard
 → CompanySubscription (+ add-ons) → EntitlementResolver → modules, specialties and seats in the Clinic Portal
```

- There is no payment gateway. `PaymentGatewayInterface` exists but no implementation was found wired (NOT VERIFIED in full).
- There is no renewal invoicing job. Subscription lapse is computed at read time by `EntitlementResolver`.

## 4. Dependency risks

| Item | Risk | Status |
|---|---|---|
| `tailwindcss` 3.4 **and** `@tailwindcss/vite` 4.3 installed | Version confusion; v4 plugin unused | VERIFIED |
| `typescript` 7.0.2 | Very new major; tsc runs (21 s) | VERIFIED |
| `alpinejs`, `axios`, `three`, `@types/three`, `playwright` | Unused or nearly unused (Alpine only through the non-built `app.js`) | PARTIALLY VERIFIED (grep) |
| `laravel/breeze` (dev) | Its Blade pages are still routed (B-11) | VERIFIED |
| `laravel/boost` MCP | Timed out in this session | BLOCKED |
| Spatie teams on `branch_id` | Company users use team 0; clinic roles are per branch. Two role systems share one guard (memory) | VERIFIED (Pest helper) |
| Database queue/cache/session defaults | Not production-grade at scale | VERIFIED (`.env.example`) |
| No CI | Nothing runs tests on push | VERIFIED |

## 5. Design systems in the tree

| Kit | Used by | Keep? |
|---|---|---|
| `resources/js/clinic/ui` + `clinic/shell` (cx tokens) | All new Clinic pages | **Yes**: the approved direction. Guard tests forbid legacy imports |
| `pages/Company/design/*` (Crm*/Company shell, two `crmTheme.ts`) | Company CRM, Marketing, People | Yes for Company. **Must not be imported by Clinic** (guarded) |
| `resources/js/ovs/*` (104 files) and `components/ui/*` shims, `components/enterprise*`, `executive-shell`, `foundations` | Older Company pages, auth pieces | Legacy; all 24 tsc errors live here |
| `components/base`, `charts`, `widgets`, `enterprise-system` | Nothing external | Dead (cleanup C-6) |
| Breeze Blade/Alpine (`resources/views/layouts`, `app.js`) | `/profile`, `/notifications` | Replace (B-11) |

## 6. Navigation direction conflict (REPORTED, NOT RESOLVED)

- The task brief mentions a "zero-sidebar, persistent top navigation" direction.
- The code and recorded decisions say otherwise:
  - `ClinicPortalShell.tsx:92-125` renders a 240px sidebar at ≥lg, a drawer below lg, and a top bar.
  - Memory `clinic_navigation_approved` (2026-10-12) and `clinic_portal_full_replacement_decision` (2026-10-22) say the sidebar and top bar are **approved and kept**.
  - Memory `clinic_design_readiness_audit_2026_10_22` already noted that a top-nav blueprint conflicts with the approved sidebar.
- **The user must decide.** No change was made.
