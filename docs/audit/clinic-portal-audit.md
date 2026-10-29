# Clinic Portal UI/UX audit (Audit D)

Read-only. **No browser session was run in this audit**, by instruction: no dev servers were started or stopped. Every visual or responsive claim below comes from source code (PARTIALLY VERIFIED). Where a claim relies on earlier browser QA, it cites the memory and design-review reports, which this audit did not re-run.

## 1. History: which implementation is current (VERIFIED from code, comments and memory)

| Generation | Status | Evidence |
|---|---|---|
| 1. OVS/OEDS-based Clinic pages (ClinicShell, `@/ovs`) | **Removed** | Memory `clinic_portal_foundation_state`; guard tests forbid `@/ovs` in clinic code |
| 2. CRM-language rebuild (Reception Control Center, etc., Oct 10–11) | **Removed** on 2026-10-11 | `app.tsx:14-19` comment; memory `clinic_frontend_reset_2026_10_11` |
| 3. Current rebuild (`resources/js/clinic/*` cx kit, approved sidebar + top bar) | **Live** | `clinic/shell/ClinicPortalShell.tsx`; `ClinicFrontend::AVAILABLE` |
| Binding decision 2026-10-22 | Every Clinic page is rebuilt; only login, sidebar and top bar are kept | Memory `clinic_portal_full_replacement_decision`; `docs/architecture/clinic-portal-replacement-map.md` |

Generation-3 pages that are still the **old, rejected composition** (map status REPLACE):
- `Dashboard.tsx` (used by every non-owner role);
- Appointments;
- Patients Index/Create;
- the intake dialog;
- Settings/PatientForm;
- Notifications;
- Support;
- Onboarding.

## 2. Findings

### D-1 The consultation → billing loop dead-ends. P0 for go-live, VERIFIED

- `CheckoutController@index` sends each signed consultation and each unpaid bill with `url = route('clinic.billing.checkout', …)` (`app/Http/Controllers/Clinic/CheckoutController.php:88,98`).
- That route renders `ClinicPortal/Billing/Checkout` (`:247`), which **has no page file**, so the app shows `Unavailable`.
- The Billing page tells staff that signed consultations "are billed from the consultation checkout" (`pages/ClinicPortal/Billing/Index.tsx:136-139`), but nothing in the UI reaches a working checkout.
- The desk path (`clinic.reception.billing.collect`) lives on the missing Front Desk page.
- **Effect**: a doctor can finalize a consultation (the workspace is live), but nobody can bill it from the UI. The backend works and is tested (`tests/Feature/Clinic/Billing/*`, 45 tests).

### D-2 Doctors have no work list. P1, VERIFIED

- `clinic.consultations.index` renders `ClinicPortal/Today/Index`, which is missing.
- The consultation workspace is therefore reachable only from Patient 360.
- The "call next" link in its payload (`ConsultationController.php:113`) points at a queue that has no UI.

### D-3 Front desk / queue has no UI. P0 for go-live, VERIFIED

- `FrontDesk/Index` and `FrontDesk/List` are missing. The nav shows "Queue" as Soon (`ClinicNavigation.php:69-70`; `Sidebar.tsx:101`).
- Check-in works only through the "+ New" intake dialog. There is no way to see, reorder or call the queue.

### D-4 The rest of the 20 missing pages. VERIFIED

See repository-inventory.md §3. Admin (staff, branches, departments, rooms, settings index, catalogue, consents), payroll, leave, reports, WhatsApp, follow-ups board and visits are all backend-only.

### D-5 Dead buttons and links. PARTIALLY VERIFIED

- **Nav**: unbuilt destinations render as non-links with `aria-disabled="true"` and the title "coming soon" (`clinic/shell/Sidebar.tsx:101`). That is correct, not dead.
- **Server-provided links to missing pages**: checkout (D-1); `follow_ups_url` (`ConsultationController.php:109`) → missing Follow-ups board.
- A full sweep of in-page links built on the server was NOT done. **Recommendation**: assert in a test that every `route()` emitted in a Clinic payload is in `ClinicFrontend::AVAILABLE` or is a JSON or print endpoint.

### D-6 Tests cannot detect missing pages. P1, VERIFIED

- `inertia.testing.ensure_pages_exist=false` for `Feature/Clinic` (`tests/Pest.php:56-57`).
- Combined with the `Unavailable` fallback (`app.tsx:25`), a removed or misspelled page never fails a test or a render.

### D-7 Legacy design-system dependencies. VERIFIED clean

- There are 0 imports of `@/ovs`, `@/components/ui`, `@/layouts` or `pages/Company` from `resources/js/clinic`, `pages/ClinicPortal` or `pages/Clinic`.
- `crmTheme` appears in clinic code only as a comment forbidding it (`clinic/index.ts:5`).
- Guarded by `tests/Feature/Clinic/Foundation/FrontendFoundationTest.php` and `Portal/ClinicUiFoundationTest.php`.

### D-8 Layout and navigation (PARTIALLY VERIFIED)

- Sidebar at ≥lg (240px), Radix drawer below lg (`min(280px,86vw)`), sticky top bar, `<main id="main">` capped at 1600px (`ClinicPortalShell.tsx:92-126`).
- Role-aware navigation comes from one server registry (`App\Support\ClinicNavigation`). Each item carries `requires` (permissions, workspace, module) and `available`.
- **Conflict to resolve**: the brief's "zero-sidebar, persistent top navigation" versus the approved sidebar (architecture-and-dependencies.md §6).

### D-9 Responsiveness (NOT VERIFIED in this audit)

- Earlier evidence: Patient 360 stress QA at several widths (`docs/design-review/patient-360/*`; memory `patient_360_stress_qa`), with a CONDITIONAL PASS gate.
- In code, breakpoints are sidebar-aware (`xl` thresholds) and wide tables scroll (memory `feedback_responsive_overview_fixes`).
- To re-verify: run browser QA at 375, 768, 1280 and 1440 on the 16 live pages.

### D-10 Form states (PARTIALLY VERIFIED)

- Shared primitives cover loading, empty, error and disabled states: `clinic/ui/StatePanel.tsx`, `states.tsx`, `Notice.tsx`, `Spinner.tsx`, `Toast.tsx`.
- `PortalErrorBoundary` wraps pages.
- Money and intake forms send an idempotency key (`BillingFrame.tsx:152`, `PatientFields.tsx:107`).

### D-11 Accessibility (PARTIALLY VERIFIED)

- Present:
  - skip link "Skip to content" to `#main` (`ClinicPortalShell.tsx:49-52,84`);
  - 559 `aria-` attributes across clinic code;
  - Radix dialogs (focus trap);
  - `focus.ts` helper.
- Risks:
  - 40 occurrences of 9–11px text in clinic code (`text-[9px]`/`[10px]`/`[11px]`);
  - an earlier contrast failure on muted grey (memory `feedback_text_contrast_tokens`).
- An automated audit (axe or Lighthouse) was NOT run.

### D-12 Size and complexity hot spots (VERIFIED line counts)

- `patient360/Sections.tsx`: 1,428 lines.
- `Consultations/Workspace.tsx`: 997 lines.
- These are candidates to split when they are next touched, not a defect.

### D-13 Stale tracker. VERIFIED

`clinic-portal-replacement-map.md` lists Patient 360, Consultation and Orders as MISSING, but they are live. Update it before planning from it.

## 3. Live Clinic pages (16 destinations in `ClinicFrontend::AVAILABLE`)

| Page | Status per tracker |
|---|---|
| Dashboard | Old shared design for non-owners; new Command Center for owner/admin |
| Appointments | REPLACE |
| Patients index | REPLACE |
| Patients create | REPLACE |
| Patient 360 / Edit | NEW |
| Consultation workspace | NEW |
| Orders index | NEW |
| Order show | NEW |
| Settings → patient form | REPLACE |
| Notifications | REPLACE |
| Support | REPLACE |
| Billing | NEW |
| Reversals | NEW |
| Summary | NEW |
| Expenses | NEW |

Approvals is built but hidden.
