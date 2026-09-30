<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.2. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

<RULE[AGENTS.md]>
<commercial-crm-ui-ux-refinement-standards>
# Commercial CRM UI/UX Refinement & Operational Identity Standards

The Commercial CRM v1 backend architecture is approved, BUT the UI/UX is explicitly NOT frozen.
Do not freeze the Commercial workspaces after initial implementation. Perform a dedicated UX refinement pass enforcing the following 8 Universal Standards:

1. **Universal AI Context Rail (Intelligent Briefing)**
   Every Commercial workspace top section must present an intelligent operational briefing tailored specifically to that workspace's domain (e.g. Leads: 12 new leads today, 4 overdue follow-ups, Best lead to call next, 2 competitors detected; Organizations: 3 customers at risk, 1 expansion opportunity, 2 inactive branches; Trials: 5 trials ending this week, 2 trials with high conversion probability).

2. **Right Context Drawer (Fast Inspection)**
   Clicking any table/list row must open a Right Context Drawer (Timeline, Contacts, Notes, WhatsApp, Email, Tasks, Quotes, Documents) for quick review without navigating away. Full page routes (`/leads/{id}`) are reserved for deep focus work only.

3. **Command Bar / Quick Action Hub**
   Every workspace top bar must feature a unified command interface combining Search with immediate Quick Action shortcuts (Create Lead, Create Trial, Create Quote, Call Client, Send WhatsApp, Assign Lead, Export).

4. **Workspace Personalization Hooks**
   Role-based workspace rendering (Sales Executive vs Manager vs Support vs CEO). Reserve architectural hooks for: Hide widgets, Reorder widgets, Save filters, Default view.

5. **Global Timeline (Unified Activity Stream)**
   Maintain a cross-module unified activity stream capturing all enterprise events (Lead created, Demo done, Trial started, Quote sent, Payment received, Renewal completed) so executives have a single chronological activity view.

6. **Smart Empty States**
   Empty states must never display generic "No Data". Every empty state must provide contextual guidance and direct primary action triggers (e.g., "No leads yet. Create your first lead or import existing prospects. [Create Lead] [Import Excel]").

7. **Progressive Disclosure**
   Follow multi-tiered disclosure: Row → Expand → Quick Details → Right Context Drawer → Full Workspace.

8. **Real Business Automation Hooks**
   Reserve event hooks for future automations (Trial expires → Reminder, Quote approved → WhatsApp, Payment received → Activate subscription, Renewal due → Notify Account Manager).

## Distinct Operational Identity Mandate
DO NOT repeat the exact same layout pattern across every page. Each workspace must possess its own unique operational identity and composition:
- **Leads Workspace:** Fast Action Workspace
- **Pipeline Workspace:** Visual Kanban Board Workspace
- **Organizations Workspace:** Relationship & Topology Workspace
- **Trials Workspace:** Progress Tracking & Duration Telemetry Workspace
- **Subscriptions Workspace:** Revenue Engine Workspace
- **Renewals Workspace:** Retention & Extension Engine Workspace
</commercial-crm-ui-ux-refinement-standards>
</RULE[AGENTS.md]>

<RULE[AGENTS.md]>
<task-driven-operating-system-standard>
# Task-Driven Commercial Executive Operating System Standard

The Commercial CRM must function as a **Task-Driven Operating System**, NOT a collection of static data tables or admin pages.

## Core Operational Directives

1. **Morning Operational Briefing ("Today's Focus"):**
   Before rendering data tables, every workspace must present the user with their immediate daily work dispatch (e.g., "Good Morning Rahul. You have: 12 follow-ups today, 2 quotations waiting, 1 demo at 3 PM, 1 renewal overdue. [Continue Working]").

2. **Module-Specific Workspace Composition (Task Before Data):**
   - **Leads Workspace (Fast Action Hub):**
     Today's Focus → My Tasks → AI Suggestions (Next Best Action) → Lead Queue → Lead Health Timeline → Data Table.
     *Lead Enhancements:* Lead Health Stepper, Next Best Action (Call today / Send Quote / Schedule Demo / Wait), Priority Badges (HOT, WARM, COLD, BLOCKED), Time Since Last Contact.
   - **Organizations Workspace (Network Explorer):**
     Relationship Overview → 5-Dimensional Health Summary (Relationship, Financial, Usage, Support, Renewal) → Organization Network Cards → Topology Table.
   - **Trials Workspace (Progress Center & Usage Telemetry):**
     Ending Soon → High Conversion Probabilities → Module Feature Usage Heatmap → Trial Timeline → Table.
   - **Subscriptions Workspace (Revenue Operating System):**
     Revenue Today → MRR/ARR Run-rate → Doctor Seat Allocations → Billing Frequency Schedule → Contracts.
   - **Renewals Workspace (Retention Center & Upsell Engine):**
     Expiring Today → Expansion Opportunities (+₹2.4L ARR Upsells) → Risk Mitigation Triage → Actions → Table.

3. **Global Cross-Module Enterprise Search:**
   A single omni-search interface returning results across Leads, Organizations, Trials, Subscriptions, Invoices, Payments, Employees, and Support Tickets.

4. **The 5-Second Unique Workspace Identity Test:**
   If a user cannot identify within 5 seconds whether they are looking at "Leads", "Pipeline", "Trials", "Organizations", "Subscriptions", or "Renewals", the workspace layout must be redesigned. Data tables must never be the primary view outside of Leads; primary experiences must be purpose-built for that domain's operational workflow (Kanban for Pipeline, Progress Heatmap for Trials, Network Topology for Organizations, Revenue Telemetry for Subscriptions, Retention & Expansion Engine for Renewals).
</task-driven-operating-system-standard>
</RULE[AGENTS.md]>
