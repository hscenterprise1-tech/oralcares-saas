# OralCares — Handoff for the next coding agent (2026-10-30)

Read this file, then `CLAUDE.md` (the Laravel Boost guidelines: Laravel 12, Inertia v3 + React, Tailwind 3, Pest), before changing anything. The product owner writes in Hindi/English (Hinglish); answer the same way, briefly.

## 1. Where things stand

- Branch: `wip/m0-snapshot-2026-10-22`. The last checkpoint commit is "chore: checkpoint Reception UI, Front Desk home and audit" (see `git log`).
- Not in git on purpose: `docs/design-review/` (about 196 MB of QA screenshots, left on disk only).
- Clinic Portal = `resources/js/pages/ClinicPortal/*` + `resources/js/clinic/*`. The old Clinic UI was removed in October 2026; a route whose page file is missing renders the "not available yet" placeholder (`resources/js/app.tsx`). `app/Support/ClinicFrontend.php::AVAILABLE` lists the routes whose new page exists; only those are linked.

## 2. Decisions the owner has made (do not undo)

1. **Reception's ONE dashboard is the Front Desk** (`/clinic/reception`, `ClinicPortal/FrontDesk/Index`). The old staff dashboard (`ClinicPortal/Dashboard`) was **deleted**. Never recreate a staff dashboard.
2. **Homes** (`app/Support/ClinicWorkspaces.php::home`): owner/admin → Command Center (`/clinic/dashboard`, the only page that route still renders); reception → Front Desk; billing staff → Billing; doctor → Appointments calendar; no role → Notifications. `/clinic/dashboard` redirects everyone except owners/admins to their home.
3. **Sidebar** (`app/Support/ClinicNavigation.php`): for reception, the "Dashboard" entry opens the Front Desk and there is no separate "Queue" entry; doctors and billing staff have no Dashboard entry. Staff-dashboard settings (the visibility route, controller and dialog) were removed.
4. **Approved and frozen designs:** New Case and Repeat Case popups (`resources/js/pages/Clinic/Reception/intake/*`), the Front Desk, and the Clinic Login / pre-auth pages. Polish only when asked; never redesign them.
5. **Visual language for everything else** = the Front Desk look:
   - **Shell tokens** (`tailwind.config.js` → `cx.nav-*`): ink `#173B60`, muted `#64748B`, line `#E6EBF1`, tint `#EAF2FA`, blue `nav-blue #1769AA`, teal `#14917F` / `#0F6B5F`.
   - **Sidebar and top bar** (`resources/js/clinic/shell/*`): the greeting wash gradient (`#EAF2FF` → `#F3F1FE` → `#EAF6F2`); the current page is a white card with blue text; pill search; white round icon buttons.
   - **Buttons:** primary is always blue `#1769AA` (`clinic/ui/Button` primary, `clinic/ds` DsButton primary). Never black or navy buttons.
   - **Selected chips and toggles:** pastel teal (`bg-cx-nav-active text-cx-nav-teal-strong`).
   - **Section titles:** small, bold, uppercase teal (`FormGroup` in `NewCaseForm.tsx`).
6. **Sidebar clinic card:** logo, name, branch, specialty pills (text only, never a tooth or other specialty icon in the sidebar); for multi-branch users it opens the branch list; the owner sees a quiet plan line.
7. No links to unbuilt pages. No drawers or slide-overs: use a centred modal or a full page.
8. The New Case note field stays out (there is no save path).

## 3. Hard rules

- **Never run `vendor/bin/pint --dirty`.** Format only the files you changed: `vendor/bin/pint path/to/File.php --format agent`.
- **Never write files with regex or `preg_replace`.** It has emptied files three times. Use exact edits and check file sizes afterwards.
- **Do not change** the database schema, billing rules, permissions, queue logic or tenant/branch rules unless the owner asks for exactly that.
- **No real side effects in QA:** never send WhatsApp messages, never take real payments, never create persistent QA patients or check-ins without permission. Seed or fake data never lives in the real database.
- **QA logins:** `intake-{dental|physio|combined}-{reception|owner|dentist|physio}@browser-test.test`. The password is the seeder default (see `database/seeders/IntakeModulesBrowserTestSeeder.php`); do not paste it in chat. The app runs at `http://127.0.0.1:8000`.
- **Never run `npm run build` while tests run:** the Inertia version flips and FrontDeskTest fails. Run them one after the other.
- **Do not delete tests** without the owner's approval. Do not commit or push unless asked.

## 4. Baseline (before handing off)

- `php artisan test --compact` (SQLite in memory): see `docs/audit/test-baseline.md` plus the numbers in section 7 below.
- `npx tsc --noEmit`: 24 errors, all old (in `resources/js/ovs/*`, `components/enterprise-system`, `components/auth/OtpDigitInput.tsx`). **None in Clinic code — keep it that way.**
- `npm run build`: passes.

## 5. Open work, in recommended order

The full audit is in `docs/audit/reception-patient-management-audit-2026-10-29.md` (inventory, user journeys, phased plan).

1. **P0 payment correctness (backend; needs the owner's go-ahead):**
   - The server accepts hand-typed "Payment Gateway" and "Insurance" payments. `PaymentService::recordPayment` checks only the clinic's accepted methods, not the desk rule (`acceptedMethods($clinic, atDesk: true)`).
   - `CheckoutController::store` lacks the target-branch `billing.create` check (pay, refund and adjust have one; see `TargetBranchFinancePermissionTest`).
2. **P1:**
   - After-visit billing has no UI. The page `ClinicPortal/Billing/Checkout` is missing, and the desk `POST reception/billing/{encounter}/collect` has no caller.
   - Per-payment receipt reprint (`bills/{bill}/print?layout=receipt&payment=`) is only linked right after a check-in payment.
3. **P2:**
   - The Follow-ups board page is missing.
   - There is no Daily Closing / Cash Handover; it needs a design and approval first.
   - No UI for `patient.merge` or `bill.void`.
   - No UI for treatment charges.
   - The Reports page is missing.
   - The `clinics.dashboard_config` column and the read-only `DashboardVisibility` remain; removing them needs a migration.
4. **Unverified in a browser:**
   - The multi-branch clinic card (no QA login has several branches).
   - The owner's plan line in the sidebar.
5. **Decision pending:** practitioners hold `billing.view` by default (doctors see money). Ask the owner.

## 6. How to verify a UI change

- Check widths 1440×900, 1024×768, 768, 390 and 360: no horizontal overflow, nothing clipped; desktop dialogs should not scroll internally.
- Run the narrowest Pest tests (`php artisan test --compact tests/Feature/Clinic/<Area>`), then `npx tsc --noEmit` (still 24), then `npm run build`.

## 7. Test results at handoff (2026-10-30)

- Full suite, `php artisan test --compact`: **4115 passed, 15 failed, 9 skipped** (700 s).
  - The 15 failures are the known "test rot" listed in `docs/audit/test-baseline.md` (`ClinicWorkspaceTest` ×3, `ClinicalFoundationTest`, `Phase0LifecycleTest` ×3, `Phase4CustomerSuccessTest`, `SuperAdminBypassTest`, `InventoryFoundationTest`, `PatientRegistrationTest`, `TaskControllerTest` ×2, `TenantProvisioningTest` ×2).
  - They point at code that was deliberately removed; none is a new failure. Any count above 15 failures is a regression.
- Clinic suite only (`tests/Feature/Clinic`): 1592 passed, 2 skipped, 0 failed.
- `npx tsc --noEmit`: 24 errors (all old, none in Clinic code).
- `npm run build`: passes.
