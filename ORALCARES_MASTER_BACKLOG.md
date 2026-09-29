# OralCares — Master Cleanup & Stabilization Backlog

> Persistent tracking document for the module-by-module audit, prune and hardening
> pass. Feature development is paused while this runs. Every entry is either
> **VERIFIED** (proven by reading code, querying the live schema, or measuring) or
> **CLAIM** (asserted, not yet proven — must be verified before it is actioned).
>
> Last updated: 2026-09-14 · Audit engine: code-level read of `app/`, `routes/`,
> `resources/js/`, plus live schema queries against `oralcares_saas`.

---

## 1. Platform Architectural Principles

These are the invariants the cleanup enforces. A module is not "done" until every
one of them holds for its surface, and a grep-able check exists for each.

### P1 — Fail-closed scoping

A read that cannot establish the caller's tenant returns **nothing**, never
everything. The current codebase repeatedly does the opposite:
`->when($orgId, fn ($q) => $q->where('organization_id', $orgId))` silently drops
the tenant predicate when `$orgId` is null. Fail-closed means the null case is
`whereRaw('1 = 0')`, and the surface says "we could not establish your
organisation" rather than rendering another tenant's book.

Authorization answers *may this user reach this page*; scoping answers *which
rows*. Both are server-side. Filtering in React is presentation, never a
boundary.

### P2 — DB-driven gating

What a tenant may use is a row, not a constant. Entitlements, plan features,
module enablement and trial state are read from `company_plans` /
`company_plan_features` / `enabled_modules` / `company_subscription_entitlements`
— never from a hard-coded array, an `env()` value, or a frontend flag. A gate
that cannot find its row denies (see P1).

### P3 — Zero orphaned tables

Every table is reachable from exactly one owning model, and every model is
reachable from a controller, service, job or command that a user or scheduler can
actually invoke. Three failure shapes are all in scope:

- **Orphan table** — exists in the database, no model, no query. Drop it.
- **Orphan model** — model file exists, nothing imports it. Delete it.
- **Test-only surface** — model/service exists and is *only* reachable from
  `tests/`. This reads as "covered" on a coverage report while no user can get to
  it. Re-point the test at the live path, then delete the dead one. Never delete
  a class without grepping `tests/` first.

### P4 — One fact, one source

A figure and the list beneath it come from the same query. Two tables must not
hold the same event (see the `activities` / `lead_call_logs` double-write, and
the `payments` / `commercial_payments` chain). Where a projection is deliberate,
it carries a foreign key back to its source and is written exactly once.

### P5 — Bounded queries

No per-row query inside a list mapper. No `pluck()` of an unbounded id set fed
back into `whereIn()`. No `LOWER(col) = ?` on a searched column without a
matching functional or generated-column index. Every list endpoint has a query
count that is flat in the number of rows returned, asserted by a test.

---

## 2. Module Execution Progress Tracker

| # | Module | Surface | Audit | Prune | Harden | Status |
|---|--------|---------|-------|-------|--------|--------|
| 1 | **CRM & Acquisition** | 14 controllers · 17 services · 13 pages · 958 tests | ✅ Done | ✅ Done | ✅ Done | **Rebuilt 2026-09-14 — Tier 1 & 2 complete; see §4.6** |
| 2 | **Marketing** | 16 controllers · WhatsApp / Instagram / automations | ⬜ | ⬜ | ⬜ | Not started |
| 3 | **Customer Success** | 5 controllers · accounts, renewals, onboarding | ⬜ | ⬜ | ⬜ | Not started |
| 4 | **People & HR** | 10 controllers · payroll, attendance, leave | ⬜ | ⬜ | ⬜ | Not started |
| 5 | **Revenue & Finance** | Finance controller · MRR, expenses, ledger | ⬜ | ⬜ | ⬜ | Not started |
| 6 | **Platform Governance** | Security, audit, workflow, integrations, notifications | ⬜ | ⬜ | ⬜ | Not started |

**Baseline at audit time:** `php artisan test tests/Feature/Company/Crm --compact`
→ **923 passed, 3803 assertions, 72.02s.** Every CRM change below must keep that
green. Where a fix requires deleting a class a test pins, the test moves first.

---

## 3. Pending Global Technical Debt

Cross-module items that do not belong to any single module's deep dive.

### G1 — "16 un-scoped marketing tables" — **partially disproven, needs restating**

Measured against the live schema: only **4** `marketing_*` tables lack an
`organization_id` column — `marketing_automation_execution_steps`,
`marketing_campaign_recipients`, `marketing_contact_import_rows`,
`marketing_contact_tag`. All four are legitimately child rows scoped through
their parent, which is the same pattern CRM uses for `demos`, `trials` and
`contacts`.

The real question is not *does the column exist* but *does every read path
constrain on it*. That is a query-layer audit, not a schema one, and it is
Module 2's job. Carry this item forward, restated: **"audit every Marketing read
path for P1 compliance"**, and record the actual count of fail-open branches
found. **VERIFIED (schema) / CLAIM (the number 16)**

### G2 — ABDM / DPDP certification readiness

`app/Traits/Auditable.php:30` writes `'properties' => $model->getAttributes()` on
create and delete, and `getDirty()` on update, into the shared `activities`
table as unencrypted JSON. The trait is applied to `Appointment`,
`ClinicalEncounter`, `ClinicalNote`, `ClinicalDiagnosis`, `ClinicalOrder`,
`DiagnosticResult`, `PatientProfile` and others — so **full patient attribute
dumps land in a general-purpose activity log**. This is a blocker for ABDM/DPDP
certification independently of any CRM work.

The same method is wrapped in `if (auth()->check())`, so **queue and CLI writes
are never audited at all** — an audit trail with silent holes. **VERIFIED**

### G3 — Polymorphic `subject_type` is not normalised

`Relation::morphMap` in `AppServiceProvider.php:80` registers
`lead`, `clinic`, `organization`, `contact`. Most writers use
`$model->getMorphClass()` (correct). Two do not:

- `app/Traits/Auditable.php:30` — `get_class($this)` (FQCN, bypasses the map)
- `database/seeders/ExecutiveDashboardSeeder.php` — writes `App\Models\Lead`

The live table proves the split: `activities` currently holds 187 rows with
`subject_type = 'lead'` **and** 2 rows with `subject_type = 'App\Models\Lead'`.
Readers disagree about which to accept — `CrmMetricsService:725` and `:789` and
`MyWorkspaceService:343` accept both; `CrmMetricsService:1127`
(`pipelineMovement`) and `LeadWorkspaceService:1461` accept only the alias, and
silently drop the FQCN rows. `AuditController:122` builds its filter dropdown
from `DISTINCT subject_type`, so the operator sees `lead` and `App\Models\Lead`
as two unrelated subject types.

Fix is platform-wide, not CRM-local: normalise the column, fix `Auditable`,
fix the seeder, and add an arch test asserting no writer uses `get_class()` for a
morph column. **VERIFIED**

### G4 — Third parallel activity/task system is entirely dead

`app/Platform/Entities/` — 3 contracts (`HasAICopilot`, `HasActivityTimeline`,
`HasNotesAndTasks`), 3 models (`EntityActivity`, `EntityNote`, `EntityTask`), 2
traits — has **zero references anywhere outside its own directory**. Its three
tables (`entity_activities`, `entity_notes`, `entity_tasks`) are empty. It sits
alongside `activities`/`tasks` (CRM) and `company_employee_activities` (People)
as a third, unused model of the same idea. Delete the directory and drop the
three tables. **VERIFIED**

### G5 — Retired `ovs/` frontend modules

`resources/js/ovs/` contains unimported modules left over from the Visual Studio
retirement. `ovs/layouts/sidebar.tsx:288` still references `/company/crm/dashboard`
— a URL that **does not exist** (the route is `company/crm`, named
`company.crm.dashboard`). Harmless only because nothing imports the file. The
keep/delete decision was previously deferred; it should be settled during Module
6. **VERIFIED**

### G6 — Platform-wide orphan-table census

A crude sweep (table name → StudlyCase model file + grep) flagged ~40 candidates,
but singular/plural conversion makes it unreliable on its own (`lead_branches` →
`LeadBranches` misses the real `LeadBranch` model). Build a proper census as a
one-off command that resolves each table through `Model::getTable()` rather than
by name convention, then run it per module. Do **not** drop anything from the
crude list without per-table verification. **CLAIM — method needs rebuilding**

### G7 — `clinic` vs `tenant` vs `customer organization`

`trials`, `commercial_invoices`, `commercial_payments` and `company_quotations`
all carry `clinic_id`, while `leads` and `trials` carry
`customer_organization_id` and `leads` also carries `organization_id` (the
*vendor* tenant). Three names for two different ideas, which is why
`CrmScope::trials()` has to reach through the lead. Needs one naming decision
applied across modules 1, 3 and 5 before any of them can be called stable.
**VERIFIED (schema) — decision pending**

---

## 4. Module Deep Dives

---

## MODULE 1 — CRM & Acquisition

**Audited:** 2026-09-14 · **Verdict:** structurally sound, visually finished, and
carrying a large layer of superseded code that the test suite is holding alive.

The CRM has clearly been through a deliberate rebuild: the routes file documents
every removal, the frontend has no dead buttons, no `TODO`s and no `console.log`,
every `Inertia::render` target resolves to a real file, and 923 tests pass. The
problems are not sloppiness. They are three specific things:

1. **Fail-open tenant scoping** in the one class that exists to prevent it.
2. **A superseded god-service** (`LeadWorkspaceService`, 4,618 lines) whose
   payload builders are now reachable only from tests.
3. **Schema drift** left by the rebuild — orphan tables from a deleted model
   generation, a globally-unique key on a multi-tenant table, and missing
   composite indexes on the columns every CRM query filters by.

---

### 1.1 Database & Models

#### 🔴 CRITICAL — `teams.key` is globally unique on a multi-tenant table

```
teams_key_unique  →  UNIQUE (key)      -- not (organization_id, key)
```

Tenant B cannot create a sales team whose key collides with tenant A's. At 10,000
clinics this is a guaranteed production failure with a confusing error. Same
shape, lower severity, on `commercial_invoices.invoice_number` (globally unique)
and `company_quotations (quotation_number, version)`. **VERIFIED**

#### 🔴 CRITICAL — `commercial_payments.commercial_invoice_id` has no FK and no index

`commercial_payments` has a FK + unique on `payment_id` and a FK on `clinic_id`,
but the column that actually links a payment to its invoice carries neither. A
payment can reference an invoice id that does not exist, and every
"payments for this invoice" read is a scan. **VERIFIED**

#### 🟠 Orphan tables from the deleted model generation

The following tables exist in `oralcares_saas`, hold **0 rows**, have **no model
file** (all were deleted in the pending working tree) and **no code reference
anywhere**:

| Table | Model status | Rows |
|---|---|---|
| `company_demos` | deleted | 0 |
| `company_opportunities` | deleted | 0 |
| `company_trials` | deleted | 0 |
| `company_trial_extensions` | deleted | 0 |
| `company_sales_activities` | deleted | 0 |
| `company_reminders` | deleted | 0 |
| `company_provisioning_processes` | deleted | 0 |
| `company_provisioning_activities` | deleted | 0 |

Their live replacements are `demos`, `leads`, `trials`, `activities` and
`provisioning_audits`. **VERIFIED**

⚠️ **`company_plan_features` is NOT an orphan** — it is the pivot behind
`CompanyFeature::plans()` and `CompanyPlan::features()`, used by
`EntitlementService`, `FeatureCatalogService`, `PlanService` and
`SubscriptionPlanService`. It is empty because entitlements are unseeded, which
is a P2 (DB-driven gating) gap, not a table to drop. **VERIFIED**

#### 🟠 `lead_whatsapp_messages` — superseded, still present

Migration `2026_09_03_001531_preserve_lead_whatsapp_history_as_activities`
already copied its history into `activities`. The table still holds 25 rows.
`Lead::whatsAppMessages()` (`app/Models/Lead.php:399`) and the
`LeadWhatsAppMessage` model have **zero callers**. **VERIFIED**

#### 🟠 `lead_automation_rules` + `WorkflowAutomationEngine` — fully dead

`app/Services/WorkflowAutomationEngine.php` is the only file that imports
`LeadAutomationRule`, and the engine itself has **no references anywhere** — not
in a controller, service, job, command or test. Table is empty. **VERIFIED**

#### 🟡 `commercial_documents` — read-only legacy layer

No code creates a `CommercialDocument` any more (no `::create`, no
`commercialDocuments()->create`, no `new CommercialDocument`). One legacy row
survives. The chain it belonged to —
`Lead → CommercialDocument(agreement) → Payment` — was replaced by
`Quotation → Approval → Payment → Invoice`, and `Lead::hasAcceptedAgreement()` is
already marked `@deprecated`. Still wired into `CrmScope::commercialDocuments()`,
`payments.commercial_document_id` and `commercial_invoices.commercial_document_id`.
Keep the table (it is settled financial history) but retire the write path and the
scope helper. **VERIFIED**

#### 🟡 One-time backfill logs still in the schema

`lead_priority_merge_log` (4 rows) and `lead_tenant_backfill_log` (10 rows) are
written by migrations and read by nothing. Either promote them to a documented
`migration_audits` convention or drop them once the backfills are confirmed
settled in production. **VERIFIED**

#### 🟢 Two "duplicates" that are NOT duplicates — do not merge

| Pair | Verdict |
|---|---|
| `teams` vs `company_teams` | **Both live, both correct.** `teams` = CRM sales teams with `assignment_strategy`, `territory_map`, `round_robin_cursor`; `leads.assigned_team_id` and `tasks.assigned_team_id` point here. `company_teams` = HR org structure, used by `StructureController`. Different domains, deliberately separate. **Keep both.** |
| `payments` vs `commercial_payments` | **Deliberate projection.** Migration `2026_08_27_054555` documents it: CRM writes `payments`, Finance's ledger is `commercial_payments`, joined by `commercial_payments.payment_id` (unique). **Keep both**, but fix the missing invoice FK above. |
| `invoices` / `invoice_items` | Clinic-side **patient** billing (`BillingController`, `FrontDeskController`, `PatientManagementController`). Not CRM. Defer to the Clinic module. |
| `lead_branches` | **Live end-to-end** — written by `syncLeadBranches()`, edited on `Leads/Create.tsx` and `Leads/Profile.tsx`. Empty only because no multi-branch prospect exists in dev. **Do not drop.** |

**VERIFIED**

#### Missing constraints and indexes

| Table | Missing | Why it matters |
|---|---|---|
| `teams` | `UNIQUE (organization_id, key)` replacing `UNIQUE (key)` | cross-tenant collision |
| `commercial_payments` | FK + index on `commercial_invoice_id` | integrity + scan |
| `leads` | composite `(organization_id, status, updated_at)` | every scoped list filters org+status and sorts `updated_at`; today only single-column `organization_id` exists |
| `leads` | index on `stage_entered_at` | `scopeStaleFor()` filters it and it is a whitelisted sort column |
| `demos` | index on `(status, scheduled_at)` | "demos today" and the My Work demos tab both scan |
| `payments` | index on `status` | every settlement sum filters `status = 'successful'` |
| `contacts` | partial/unique guaranteeing one `is_primary` per `lead_id` | "one primary per deal" is enforced in PHP only |
| `lead_branches` | unique on `(lead_id)` where `is_main = 1` | same — one main site is app-enforced only |
| `tasks` | no `organization_id` at all | tenancy is inferred from `assigned_user_id`; a task with a null assignee is invisible to every non-admin scope |
| `activities` | no `organization_id` at all | there is no `CrmScope::activities()`; timeline tenancy rides entirely on the polymorphic subject |

**VERIFIED**

#### 🟡 `lead_branches.deleted_at` is dead weight

`syncLeadBranches()` calls `$lead->branches()->forceDelete()` then recreates, so
the soft-delete column is never populated and branch ids churn on every profile
save. Either soft-delete properly or drop the column. **VERIFIED**

---

### 1.2 Backend — Services, Scoping & Routes

#### 🔴 CRITICAL — `CrmScope` fails **open**, not closed (violates P1)

The class whose entire purpose is tenant isolation drops its tenant predicate
whenever the organisation cannot be resolved. Four separate branches:

| Location | Code | Effect when `$orgId` is null |
|---|---|---|
| `CrmScope::apply()` `app/Support/CrmScope.php:318` | `->when($orgId, fn ($q) => $q->where($tenantColumn, $orgId))` | manager sees **every tenant's leads** |
| `CrmScope::throughLead()` `:352` | `if (! $orgId) { return $query; }` | manager sees every tenant's quotations, demos, commercial documents |
| `CrmScope::contacts()` `:221` | `->when($orgId, ...)` | manager sees every tenant's contacts |
| `CrmScope::organizationUserIds()` `:288` | `if ($orgId) { $query->where(...) }` | returns **every company-portal user on the platform**, which then feeds `tasks()` and `customerAccounts()` |

Each of these is one null `resolveOrganizationId()` away from a cross-tenant
read. The docblocks explicitly state that the previous `orWhereNull` branches
were removed *for exactly this reason* — the null-`$orgId` case is the same bug
in a different shape and was missed. **VERIFIED**

**Fix:** a single `scopeToTenant(Builder, ?int $orgId, string $column)` helper
that emits `whereRaw('1 = 0')` on null, used by all four; plus a Pest test per
entry point asserting a manager with an unresolvable organisation reads zero
rows.

#### 🔴 CRITICAL — `findDuplicateLead()` leaks across tenants and cannot use an index

`app/Services/LeadWorkspaceService.php:2192`

```php
if ($organizationId) {
    $query->where('organization_id', $organizationId);
}
```

Same fail-open shape. The endpoint that reaches it —
`GET company/crm/deals/check-duplicate` — returns the matched lead's
**company name, contact name, stage, owner name and URL**. With a null org that
is another tenant's pipeline handed over an unauthenticated-to-tenant read.

Compounding it, the matching predicates are:

```php
->orWhereRaw('LOWER(email) = ?', [$email])
->orWhereRaw('LOWER(company_name) = ?', [strtolower($companyName)])
```

`LOWER(col)` defeats `leads_email_index` entirely, and `company_name` has no
index at all — so every keystroke of the duplicate check is a full scan of
`leads`. Also `->orWhere('phone', $normalized)` compares the **raw** `phone`
column against a normalised value, which is almost never equal and does nothing.
**VERIFIED**

#### 🔴 CRITICAL — N+1 on the Deals index: **25 of 44 queries**, measured

`DealController::row()` (`app/Http/Controllers/Company/Crm/DealController.php:203`)
calls `$this->deals->isStalled($lead)` once per row, and `isStalled()`
(`app/Services/CRM/DealsQueryService.php:373`) issues its own
`SELECT EXISTS(...)` per call.

Measured on the live database with 26 leads:

```
total queries: 44
isStalled-shaped exists() queries: 25
25 x select exists(select * from `leads` where `leads`.`id` = ? and `status` not in (?, ?) and (`stage_entered_at` …
```

`PER_PAGE = 25`, so this is **always** exactly 25 extra queries regardless of data
volume. The predicate is fully computable in PHP from attributes already loaded
(`status`, `stage_entered_at`, `updated_at`) — `scopeStaleFor` is
`status NOT IN (won, lost) AND (stage_entered_at <= cutoff OR (stage_entered_at IS NULL AND updated_at <= cutoff))`.

`CrmPerformanceTest` asserts flat query counts for the **Overview** and the
**Sales Teams index** but not for the Deals index, which is why this survived.
**VERIFIED — measured**

#### 🟠 Second N+1 — `Lead::hasApprovedPaidQuotation()`

`app/Models/Lead.php:472` does `->get()->contains(fn ($q) => app(CommercialLedgerService::class)->reconcile($q)['state'] === PAID)`,
and `reconcile()` runs its own `Payment` query per quotation. Runs on every stage
transition and every Won gate check. Lower blast radius than the Deals N+1, same
shape. **VERIFIED**

#### 🟠 Unbounded `pluck → whereIn` in the metrics service (violates P5)

`CrmMetricsService::pipelineMovement()` (`:1117`) does
`(clone $this->leads($user))->pluck('company_name', 'id')` — every scoped lead
into PHP memory — then `whereIn('subject_id', $leads->keys())`. Same pattern at
`:724` and `:787`. For a master admin at platform scale this materialises the
entire `leads` table and builds an IN list of the same size. Must be a subquery.
**VERIFIED**

#### 🟠 `pipelineMovement` silently drops rows (instance of G3)

`:1127` filters `where('subject_type', 'lead')` while sibling methods at `:725`
and `:789` use `whereIn('subject_type', ['lead', Lead::class])`. The live table
contains both spellings, so the Overview's pipeline-movement panel is missing
stage changes that the stale-lead panel counts. **VERIFIED**

#### 🟠 `LeadWorkspaceService` — 4,618 lines, 13 payload builders reachable only from tests

The page-serving work moved to focused services (`DealPageService`,
`DealDetailService`, `TrialPageService`, `CommercialPageService`,
`MyWorkspaceService`). The old builders were never removed, and the old tests
still call them directly — so they pass CI while **no route, controller or
command can reach them**. This is P3's "test-only surface" in its purest form.

Reachable only from `tests/`:

`getWorkspacePayload` · `getLeadsDirectoryPayload` · `getPipelinePayload` ·
`getSalesOsPayload` · `getEmployeeBriefingPayload` · `getQuotationWorkspacePayload` ·
`getDemoWorkspacePayload` · `getSalesOperationsPayload` · `getOrganizationsPayload` ·
`getSubscriptionsPayload` · `checkAndGenerateReminders`

Reachable from **nothing at all**, including tests:

`getTrialWorkspacePayload` · `checkAndGenerateDemoReminders`

One genuine cross-module caller to preserve: `getOverviewPayload()` is used by
`app/Http/Controllers/Company/Finance/FinanceController.php:41`. Finance reads
the CRM overview payload — that coupling needs a decision in Module 5, not a
silent deletion here. **VERIFIED**

#### 🟡 `lead_call_logs` is a double-write (violates P4)

`logCall()` writes the *same* event twice: once to `lead_call_logs` (disposition,
duration, notes, next follow-up) and once to `activities` with the identical
payload in `properties`. The deal timeline reads `activities`; the "calls today"
counters read `lead_call_logs`. Two sources for one fact is exactly how the
headline-vs-list divergences arise. **VERIFIED**

#### 🟡 `resolveFallbackOwner()` can assign across tenants

`app/Services/LeadWorkspaceService.php:1845` falls back to
`User::where('user_type', MasterAdmin)->orderBy('id')->first()` — it *prefers* a
master admin in the lead's organisation via `orderByRaw`, but if none exists it
assigns the lead to a master admin belonging to **another organisation**. Better
than dropping the lead, but it should record the cross-tenant assignment
explicitly rather than silently. **VERIFIED**

#### 🟡 Missing background jobs

Scheduled today (`routes/console.php`): unassigned-lead SLA, overdue-followup
escalation, demo-day reminders, trial lifecycle + usage snapshot, win-backs,
quotation expiry, CS snapshots, MRR capture.

**Absent, and needed for CRM to be production-complete:**

- **Lead import** — Marketing has `ProcessMarketingContactImportJob` +
  `marketing_contact_imports` + `marketing_contact_import_rows`; CRM has no
  equivalent. Leads can only be created one at a time through a form.
- **Lead dedupe / merge** — `checkDuplicate` is a *read-only warning at create
  time*. There is no merge endpoint, no bulk dedupe pass, and no job to find
  duplicates created before the check existed.
- **Stalled-deal escalation** — staleness is computed and displayed but nothing
  acts on it.
- **Lead score recompute** — `LeadScoringService` runs from `LeadObserver` on
  write only, so a score never decays with time even though staleness does.

**VERIFIED**

#### Endpoints that exist but no UI reaches

Diffed all 98 `company.crm.*` route names against every `route(...)` call and
every literal `/company/crm/...` string in `resources/js`:

| Route | Assessment |
|---|---|
| `leads.documents.download` · `leads.documents.destroy` | **Real gap.** `documents.store` has UI — upload works, but an uploaded document can never be downloaded or removed. **BUILD the UI.** |
| `trials.checklist` · `trials.cancel` | Backend is implemented and tested (`TrialWiringTest` covers cancel); no button exists. **BUILD the UI.** |
| `leads.email.retry` | `lead_email_threads` carries `status`/`attempts`/`failure_reason`; a failed send is a dead end for the user. **BUILD the UI.** |
| `leads.followup.complete` | Superseded by task completion. **REMOVE.** |
| `leads.communications` | Superseded by `whatsapp` / `email.store` / `call-disposition`. **REMOVE.** |
| `demos.store` · `demos.update` · `demos.destroy` | Creation happens via `leads.schedule-demo`; these are an unused second door. **REMOVE.** |
| `quotations.printable` | `quotations.pdf` is the one in use. **REMOVE.** |
| `quotations.update` · `quotations.destroy` | A sent/approved quotation should be revised, not edited or deleted. **REMOVE** (confirm no admin need first). |
| `leads.assign` · `leads.triage` · `leads.winback` | Reached by literal URL strings, not `route()`. **Live — keep.** |
| `leads.index` · `pipeline` · `operations` · `onboarding.*` · `organizations.*` · `subscriptions` · `renewals` · `tasks.index` · `demos.index` | Deliberate authorized redirects, documented in `routes/web.php`. **Keep.** |

**VERIFIED**

---

### 1.3 Frontend — Inertia / React

**This is the healthiest layer in the module.** The audit found:

- ✅ All 13 `Inertia::render()` targets resolve to real `.tsx` files.
- ✅ **Zero** `TODO`, `FIXME`, "coming soon", `console.log`, `alert()`,
  `href="#"` or `onClick={() => {}}` anywhere under
  `resources/js/Pages/Company/Crm/`.
- ✅ Empty states are handled everywhere — `CrmDataState` on Dashboard (11),
  TrialsWorkspace (2) and Commercial (2); explicit inline `length === 0`
  branches with written copy on MyWorkspace, Deals and Teams.
- ✅ Deferred props on the Dashboard all declare loading states.
- ✅ The `components/Crm*.ts` files are intentional 8-line shims re-exporting
  `Company/design` — the shared kit, not duplicates.

Remaining frontend items are all *missing surface*, not broken surface:

| # | Item | Severity |
|---|---|---|
| F1 | No download/delete control for documents uploaded to a deal | 🟠 |
| F2 | No trial checklist UI and no cancel button, despite both endpoints existing and being tested | 🟠 |
| F3 | No retry control for a failed lead email | 🟡 |
| F4 | `Teams/Index.tsx` hides itself when `teams.length === 0` (`:305`) — and `teams` has **0 rows** in the live database with no seeder writing it, so Sales Teams, territory routing, round-robin and the Deals team filter are all permanently invisible. The feature is fully built and completely unreachable. | 🟠 |
| F5 | `Demos/` has only `Show.tsx`; demo work is done from the deal and My Work, which is correct — but confirm no nav entry still points at a list | 🟢 |

**VERIFIED**

---

### 1.4 Actionable Recommendations

#### ✅ KEEP — do not touch these

- `App\Services\CRM\*` — the focused page services (`DealsQueryService`,
  `DealPageService`, `DealDetailService`, `DealStageService`, `TrialPageService`,
  `CommercialPageService`, `DealActivityRecorder`). This is the good
  architecture; everything else should move toward it.
- `CrmScope` as a *concept* and single entry point — fix its four null branches,
  do not replace it.
- The whole `resources/js/Pages/Company/Crm/` design layer.
- `teams` **and** `company_teams`; `payments` **and** `commercial_payments`;
  `lead_branches`; `company_plan_features` — verified non-duplicates.
- The 923-test CRM suite. Move tests before deleting what they pin.
- The documented redirect routes in `routes/web.php`.

#### 🗑️ REMOVE / PRUNE

**Migration `drop_orphan_crm_tables`:**

```
company_demos · company_opportunities · company_trials · company_trial_extensions
company_sales_activities · company_reminders
company_provisioning_processes · company_provisioning_activities
lead_automation_rules · entity_activities · entity_notes · entity_tasks
lead_whatsapp_messages   (25 rows — already preserved into `activities`)
```

`down()` cannot restore data; document that, as
`2026_09_10_051913_drop_legacy_crm_tables` already does.

**Code:**

- `app/Services/WorkflowAutomationEngine.php` + `app/Models/LeadAutomationRule.php`
- `app/Models/LeadWhatsAppMessage.php` + `Lead::whatsAppMessages()` (`Lead.php:399`)
- `app/Platform/Entities/` — the entire directory (G4)
- `LeadWorkspaceService::getTrialWorkspacePayload()` and
  `::checkAndGenerateDemoReminders()` — zero references including tests
- Routes: `leads.followup.complete`, `leads.communications`, `demos.store`,
  `demos.update`, `demos.destroy`, `quotations.printable`
- `lead_branches.deleted_at` (never written)

**Deferred pending a decision:** `lead_priority_merge_log`,
`lead_tenant_backfill_log` — drop once the backfills are confirmed settled in
production.

#### 🔀 MERGE / SHIFT

1. **Retire `LeadWorkspaceService`'s payload builders.** For each of the 11
   test-only builders: re-point the test at the live service that replaced it,
   then delete the builder. Resolve `getOverviewPayload()`'s Finance caller
   first (Module 5 coupling). Target: `LeadWorkspaceService` drops from 4,618
   lines to the write-path methods that are genuinely still used
   (`createLead`, `updateLead`, `transitionStage`, `assignLead`,
   `autoAssignLead`, `logCall`, demo/trial/quotation lifecycle).
2. **Collapse the call double-write.** Make `activities` the single source for
   the call event and derive "calls today" from it, or keep `lead_call_logs` as
   the record and have the timeline read it — one of the two, not both.
3. **Normalise `subject_type` platform-wide** (G3) and add
   `CrmScope::activities()` so timeline reads are tenant-scoped like every other
   CRM read.
4. **Retire the `commercial_documents` write path** and `CrmScope::commercialDocuments()`;
   keep the table read-only for settled history.

#### 🔨 BUILD / FIX — ordered by risk

**Tier 1 — security & correctness (blocking)**

| # | Change | File |
|---|---|---|
| B1 | Fail-closed `scopeToTenant()` helper; apply to all four null branches | `app/Support/CrmScope.php:221,288,318,352` |
| B2 | Tenant-scope `findDuplicateLead()` fail-closed | `app/Services/LeadWorkspaceService.php:2192` |
| B3 | Migration: `teams` → `UNIQUE (organization_id, key)`, drop `UNIQUE (key)` | new migration |
| B4 | Migration: FK + index on `commercial_payments.commercial_invoice_id` | new migration |
| B5 | Normalise `activities.subject_type`; fix `Auditable` to use `getMorphClass()`; fix `ExecutiveDashboardSeeder` | `app/Traits/Auditable.php:30` + migration |
| B6 | Stop dumping `getAttributes()` into `activities.properties` for clinical models (G2) | `app/Traits/Auditable.php` |

**Tier 2 — performance (blocking at 10k clinics)**

| # | Change | File |
|---|---|---|
| B7 | Compute `is_stalled` in PHP from loaded attributes; delete the per-row `exists()` | `DealController.php:203`, `DealsQueryService.php:373` |
| B8 | Add a Deals-index query-count assertion to `CrmPerformanceTest` | `tests/Feature/Company/Crm/CrmPerformanceTest.php` |
| B9 | Replace `pluck → whereIn` with subqueries in the metrics service | `CrmMetricsService.php:724,787,1117` |
| B10 | Batch `hasApprovedPaidQuotation()` into one reconciliation query | `app/Models/Lead.php:472` |
| B11 | Migration: `leads (organization_id, status, updated_at)`, `leads.stage_entered_at`, `demos (status, scheduled_at)`, `payments.status` | new migration |
| B12 | Index the duplicate-check predicates (generated lowercase columns or a normalised `company_name`) so the check stops scanning | migration + `findDuplicateLead()` |

**Tier 3 — completeness**

| # | Change |
|---|---|
| B13 | Document download + delete UI on the deal (`leads.documents.download` / `.destroy`) |
| B14 | Trial checklist UI + cancel button (`trials.checklist` / `trials.cancel`) |
| B15 | Failed-email retry control (`leads.email.retry`) |
| B16 | Seed / onboard `teams` so Sales Teams, territory routing and round-robin become reachable (F4) |
| B17 | Lead import job + `lead_imports` / `lead_import_rows`, mirroring the Marketing import |
| B18 | Lead merge endpoint + a dedupe pass over rows created before `checkDuplicate` existed |
| B19 | `tasks.organization_id` (+ backfill) so task tenancy stops riding on the assignee, and null-assignee tasks stop being invisible |
| B20 | DB-level uniqueness for one primary `contact` per lead and one `is_main` branch per lead |
| B21 | Stalled-deal escalation job; lead-score decay job |

---

### 1.6 Execution log — 2026-09-14

Tiers 1 and 2 shipped, plus the demo/trial engine and the three new test files.
**958 CRM tests pass** (was 923; +35 new, 0 regressions). The whole
`tests/Feature/Company` tree — CRM, Marketing, Customer Success, People,
Finance — is **1,445 passing, 0 failing**.

**Migrations (5, all applied):**

| Migration | What it did |
|---|---|
| `drop_orphan_crm_tables_and_dead_columns` | Dropped 13 orphan tables; dropped `lead_branches.deleted_at` and `company_subscriptions.opportunity_id` (+ its FK, the last thing holding `company_opportunities` up). 234 → 221 tables. |
| `add_meeting_engine_to_demos_table` | `meeting_platform`, `meeting_id`, `passcode`, `rep_notified_at`, `customer_reminder_24h_at`, `customer_reminder_2h_at`; index `(status, scheduled_at)`. |
| `add_telemetry_and_churn_to_trials_table` | `lost_at`, `lost_reason`, `lost_competitor`, `usage_telemetry`; index on `lost_at`. |
| `add_multi_tenant_keys_and_crm_indexes` | `teams` UNIQUE(key) → UNIQUE(organization_id, key); FK+index on `commercial_payments.commercial_invoice_id`; `leads(organization_id, status, updated_at)`; `leads(stage_entered_at)`; functional indexes on `LOWER(email)` and `LOWER(company_name)`. |
| `index_lead_phone_for_duplicate_checks` | Index on `leads.phone`. |

**Deviations from the brief, and why.** Four columns in the spec already existed
under other names and were **reused rather than duplicated** — a second column
holding the same fact is precisely the P4 violation this pass exists to remove:
`extended_count`→`extension_count`, `last_extended_at`→`extended_at`,
`extension_reason` and `company_plan_id` (already present and constrained).
`demos.meeting_link` likewise already existed. `usage_telemetry` *is* new: the
existing `usage_metrics` has never been written by anything.

`TrialService::extend()` delegates to `TrialLifecycleService::extend()` rather
than reimplementing it — that method also moves the subscription window, without
which an extended trial locks the customer out on the original date.

`markLost()` takes a `LostReason`, not a trial-specific vocabulary:
`leads.lost_reason` is cast to that enum, so a separate list would have had to be
mapped onto it anyway and would drift on the first addition.

**Measured result — Deals index: 44 queries → 11.**

| | Before | After |
|---|---|---|
| Total queries | 44 | **11** |
| Per-row `isStalled()` `SELECT EXISTS` | 25 | **0** |
| Saved-view counts | 9 round trips | **1** (UNION ALL over the same 9 builders) |

The counts collapse keeps the "a chip and the list it opens cannot disagree"
invariant because it unions the *actual view builders* — re-expressing each view
as a `SUM(CASE WHEN …)` would have been fewer queries too, and would have created
a second definition of every view to drift from. `DealsQueryPerformanceTest`
asserts both the ceiling (≤18) and flatness, and separately asserts each union
count equals the count from its own builder.

**Fail-closed scoping (P1) — five branches closed.** All four `CrmScope` null
branches now route through one `constrainToTenant()` helper that emits
`whereRaw('1 = 0')`, plus `findDuplicateLead()`, which was returning another
tenant's company name, contact, stage and owner through the create form.

**Bugs found and fixed while in there, not in the brief:**

1. `DealPageService` read `$d->demo_method` — **no such column** — so every demo
   on a deal page reported as online, including in-person ones. It is `demo_mode`.
2. My Work's demo tab passed the *demo* to `openLead()`, which reads `.id`, so
   clicking a demo's company name opened whichever deal shared that number.
3. `TrialService::markLost()` read `$trial->lead` off a possibly-stale cached
   relation; the won-deal guard turns on that value, so a deal won moments
   earlier could be dragged back to Lost. Now loaded fresh inside the transaction.
4. `CrmMetricsService::recentActivity()` capped its lead set at an arbitrary 500
   via `->limit(500)->pluck('id')` — a correctness bug, not a safety rail: a
   tenant with more leads drew "recent activity" from an arbitrary subset.
5. `pipelineMovement()` matched `subject_type = 'lead'` only while its siblings
   matched both spellings, silently dropping rows (instance of G3). All three
   now go through one `leadSubjectTypes()` helper.
6. **The raw-`phone` fallback in `findDuplicateLead()` is load-bearing** — I
   removed it as dead and a test caught it. `leads.normalized_phone` is null on
   most rows and inconsistently formatted where present (`919811100003` where the
   normaliser produces `9811100003`). Restored, and `phone` is now indexed.
   **Follow-up debt: backfill `normalized_phone` and retire the fallback.**

**New test-only surface, 2026-09-14 (Overview rebuild).**
`CrmMetricsService::pipelineMovement()` is now reachable **only from
`tests/Feature/CrmOverviewCommandCentreTest.php`**. It fed the Overview's
"recent movement" feed, which was removed as an audit trail that belongs on the
deal's own timeline (`HistoryZone` already carries it in full). This is P3's
test-only shape and needs the usual decision: re-point those three assertions at
the deal timeline and delete the method, or give it a surface. Deliberately not
deleted here — that is a scope call, not a side effect of a UI change.

**Still outstanding from §1.4** (not in this brief's scope): B5/B6 (the
`Auditable` morph + PHI dump, G2/G3 — platform-wide), B13/B15 (document
download/delete and email retry UI), B16 (seed `teams`), B17/B18 (lead import and
merge), B19 (`tasks.organization_id`), B20 (DB-level primary-contact uniqueness),
B21 (stalled escalation, score decay). Plus: drop the never-written
`trials.usage_metrics`, and collapse `demo_mode` into `meeting_platform` now that
both exist.

---

### 1.5 CRM Exit Criteria

Module 1 is "done" when all of the following are true:

- [ ] `CrmScope` has no branch that returns an unscoped builder; a test per entry
      point proves a manager with an unresolvable organisation reads **zero** rows.
- [ ] `php artisan test tests/Feature/Company/Crm --compact` is ≥ 923 passing,
      with no test reaching a class no route can reach.
- [ ] Deals index query count is flat in page size and asserted by a test
      (target ≤ 20, from the measured 44).
- [ ] No CRM table lacks a model, and no CRM model lacks a non-test caller.
- [ ] `activities.subject_type` holds exactly one spelling per subject.
- [ ] Every `can:`-guarded route is reachable from the UI, or deleted.

---

*Modules 2–6 deep dives to follow. Each will use the same section structure:
Database & Models → Backend → Frontend → Keep / Remove / Merge / Build → Exit
Criteria.*
