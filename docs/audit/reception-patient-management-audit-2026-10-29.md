# Reception & Patient Management — A-to-Z Audit (2026-10-29)

Audit only. No code, schema, route, permission or data was changed. Nothing was committed. No registration, check-in, payment, refund or message was submitted.

**Evidence used**
- `php artisan route:list` (312 clinic routes).
- Every Inertia `render()` target checked against `resources/js/pages`.
- The handlers, services and middleware read for each money path.
- The full `tests/Feature/Clinic` suite (in-memory SQLite, so it touched no real data).
- `tsc`.
- Read-only browser checks as the Physio and Dental QA receptionists (`intake-*-reception@browser-test.test`).

Anything marked **Unverified** was not exercised end to end.

---

## 1. Headline findings

| # | Finding | Priority |
|---|---|---|
| 1 | **After-visit billing has no UI.** `GET /clinic/billing/checkout/{encounter}` renders `ClinicPortal/Billing/Checkout`, which does not exist, so the resolver shows the "not available yet" placeholder. The desk `POST reception/billing/{encounter}/collect` has no frontend caller. Billing says signed consultations "are billed from the consultation checkout", but there is no such screen. A clinic that bills after the visit cannot bill or collect in the UI. | **P1** |
| 2 | **The server accepts "Payment Gateway" and "Insurance" typed in by hand.** `PaymentService::recordPayment` checks only the clinic's accepted list (`acceptedMethods($clinic)`), not the desk rule (`atDesk`). The desk UI hides these methods, but `POST billing/bills/{bill}/payments` and `reception/billing/{encounter}/collect` accept `method=gateway` from any member with `payment.collect`. That records a gateway payment that no gateway confirmed. The Billing page and the Patient 360 bill dialog also offer Gateway and Insurance as manual options (`methodOptions($clinic)` without `atDesk`). | **P0** |
| 3 | **Raising a bill skips the target-branch permission check.** `CheckoutController::store` calls `assertBranch($encounter)` with no permission. `billing.create` is checked only by route middleware, at the session's branch. Pay, refund and adjust do check the bill's branch (`TargetBranchFinancePermissionTest`); store has no such test. It can be reached only by a direct POST today, because the page is missing. | **P0** (low exposure) |
| 4 | **No receipt reprint after the moment of payment.** The thermal receipt (`print?layout=receipt&payment=`) is linked only from the check-in success panel. The Billing bill dialog and Patient 360 offer only the A4 bill. | **P1** |
| 5 | **No Daily Closing or Cash Handover.** There is no model, migration, route, service or page. Only `CloseQueueDay` (queue housekeeping) exists. | **P2** |
| 6 | **No UI for duplicate merge or bill voiding.** The governed actions `patient.merge` and `bill.void` exist on the server (`Governance/Actions`), with nothing in the frontend that raises them. | **P2** |
| 7 | **Pages that render the placeholder:** Follow-ups board, Reports, Visits list, Today (doctor), Payroll, Leave, Staff, WhatsApp, Admin catalog and consents, and branch infrastructure. Of these, only Follow-ups is in the reception scope (Reports is owner-only, and Reception gets 403, correctly). | **P2** |
| 8 | **Treatment charges have no UI.** `clinic.patients.treatment-charges.*` exists, and nothing links to it. | **P2** |
| 9 | **Three dialog systems** (`clinic/ui/Modal`, `clinic/ds/Dialog`, `clinic/next/frame`) and **two bill dialogs** (`BillDetailDialog` and Patient 360's own `BillDialog`). The bill dialog uses the older black-button kit, not the approved intake look. | **P3** |
| 9a | **The Front Desk page is unreachable from the sidebar.** "Queue" shows as "Soon" because `clinic.reception` is missing from `ClinicFrontend::AVAILABLE` (section 10, N1). Dashboard and Front Desk are two overlapping reception home screens (N2). | **P1** / Decision |
| 10 | **Role-design question: practitioners hold `billing.view` by default.** So doctors see money on Patient 360, the desk billing panel and bill JSON. That may be intended, but it contradicts the "money only to billing roles" comment in `Sections.tsx`. | Decision |

---

## 2. Inventory (module / page / workflow)

| Module / workflow | Route · main files | Popups / forms | Backend & persistence | UI/UX | Tests & evidence | Status | Gaps / reproducible problems | Pri | Phase |
|---|---|---|---|---|---|---|---|---|---|
| **New Case** (register + check in) | `clinic.reception.intake` (config JSON), `POST clinic/patients`, `check-in` · `Reception/intake/NewCaseForm.tsx`, `ReceptionIntakeDialog.tsx` | Intake dialog, duplicate panel, DatePicker, CollectPaymentDialog, DonePanel / BillingResult | Registration + check-in + check-in charge in one transaction; idempotency key (`IntakeSubmissions::once`); duplicate check `patients/check-duplicate`; server refuses a mismatched `expected_charge` | Approved; no scroll at 1440/1024 (723 px); phones scroll inside | NewCaseIntakeTest, NewCaseFinalizationTest, NewCaseQuickHealthTest, MultiModuleIntakeTest; browser-measured 2026-10-29 | **Complete** | Note field left out (no save path; your decision) | — | Done |
| **Repeat Case** (returning check-in) | `check-in/search`, `check-in/recent`, `bookings/match`, `physio-summary`, `check-in` · `RepeatCaseForm.tsx`, `PatientSearchStep.tsx` | Same dialog; CollectPaymentDialog | Branch-private lookup; deceased and merged refused; already-queued refused (`QueueUniquenessTest`); booking linked | Search-first, no auto-select, fee once | RepeatCaseTest, ReceptionCheckInFlowTest, QueueUniquenessTest; browser at five widths, Dental + Physio | **Complete** | — | — | Done |
| **Check-in charge → payment → receipt** | `POST billing/bills/{bill}/payments` (JSON) · `CheckInPayment.tsx` | CollectPaymentDialog, BillingResult (thermal + A4 print, message status / retry) | Bill row lock; amount ≤ outstanding; receipt number from `ClinicDocumentNumbers`; idempotency key; events `PaymentRecorded` / `BillCompleted` | Older kit look (green/black) | MoneyRequestIdempotencyTest, ReceiptAndPaymentMessagesTest | **Complete** (server) / **Unverified** (live payment not submitted) | Gateway/Insurance accepted by the server (finding 2) | P0 | 1 |
| **After-visit billing** (signed consultation → bill → pay) | `billing/checkout/{encounter}` GET/POST, `reception/billing/{encounter}/collect` · *no page* | *None exist* | `EncounterCheckout::lines/bill` under row lock; `collect` = bill + payment in one transaction with a key | Placeholder page; the desk shows a read-only "Ready to bill" count | Desk collect covered in FrontDeskTest / VisitLifecycleTest (server) | **Missing UI** | Findings 1 and 3 | P1 / P0 | 1–2 |
| **Billing — Bills & Collections** | `clinic.billing.index` · `Billing/Index.tsx`, `clinic/billing/*` | BillDetailDialog (Collect payment / Refund / Adjust / Print), DatePicker filters | `BillingRegister` reach by `billing.view` per branch; pay, refund and adjust check permission at the bill's branch and are idempotent; refund and adjust need Owner Mode | No overflow at 390; detail loads in ~1–2 s behind a skeleton | BillingRegisterTest, BillAdjustmentTest, AdjustmentIdempotencyTest, TargetBranchFinancePermissionTest; browser (physio QA bill opened, actions read, nothing submitted) | **Partial** | No raise-bill entry; A4 only (no receipt reprint); Gateway/Insurance offered as manual methods; status filter offers "Draft" | P1 | 2 |
| **Billing — Refunds & Adjustments**, **Summary** | `billing.reversals`, `billing.summary` (owner) · `Billing/Reversals.tsx`, `Summary.tsx` | — | Read-only registers | — | BillingRegisterTest | **Complete** (read-only) | — | — | — |
| **Receipts / printing** | `billing/bills/{bill}/print` (`?layout=receipt&width=58\|80&payment=`) · `bill_printable`, `bill_receipt_thermal` Blade | — | Read-only; writes a `billing.printed` audit entry | — | ReceiptAndPaymentMessagesTest | **Partial** | Per-payment reprint reachable only right after check-in (finding 4) | P1 | 2 |
| **Refund / void / cancel** | `refunds.store` (Owner Mode); `bill.void` governed action | Refund form in BillDetailDialog | Refund ≤ collected, locked, keyed | — | TargetBranchFinancePermissionTest | **Partial** | Void bill has no UI (finding 6) | P2 | 4 |
| **Payment messages (WhatsApp)** | `payments/{payment}/messages[/retry]` | Status panel in BillingResult | Retry throttled 10/min; shown as skipped when not delivered | — | ReceiptAndPaymentMessagesTest | **Complete** (server) / **Unverified** live | Live WhatsApp never sent (by rule) | — | — |
| **Physio packages** | `session-packages.store`, `treatments/{t}/packages` · PhysioSection, ClinicalPanels | SellPackage form | Bill raised for the package; payment through Billing | — | ReceptionPhysioSessionTest and others | **Complete** | Package bill then paid in Billing (2 steps) | — | — |
| **Treatment charges** | `patients/{p}/treatment-charges` | — | Controller exists | No UI | — | **Missing UI** | Finding 8 | P2 | 4 |
| **Front Desk / Queue** | `clinic.reception` (also nav "Queue"), `reception/list/{list}`, `clinic.queue` · `FrontDesk/Index.tsx`, `List.tsx`, `QueueBoard.tsx` | ActionMenu, PriorityDialog, DoctorDialog, Cancel ConfirmDialog, intake dialog | `QueueStateMachine` (call / recall / skip / requeue / cancel), reassignment and correction via `QueueCorrections`; live props polled (`useLiveProps`) | No overflow at 390 | FrontDeskTest, QueueStateMachineTest, DoctorQueueTest, ReceptionControlCenterTest | **Complete** (desk ops) | `links.visit` (correct reason) sent but unused; billing panel read-only (see after-visit billing) | P2 | 2 |
| **Queue TV display** | — | — | — | — | — | **Missing** | No route or page | P3 | 5 |
| **Appointments / Calendar** | `clinic.appointments.*` · `Appointments/Index.tsx` + 11 components | BookingDialog, DetailDialog (Check in / Register & check in → intake), Reschedule / Cancel / MoveScope, FindNext, Filters, Settings, LeaveReview | `AppointmentBookingService`; status, reschedule, link-patient | No overflow at 390 | tests/Feature/Clinic/Appointments | **Complete** | Booked → check-in uses the same intake (verified in code) | — | — |
| **Patients directory** | `clinic.patients.index` · `Patients/Index.tsx`, `directory/*` | Filters, toolbar menus | Role views, branch-private, server-side phone rule | No overflow at 390 | tests/Feature/Clinic/Patients | **Complete** | — | — | — |
| **Patient create / edit / transfer** | `patients.create/edit/update/transfer` · `Create.tsx`, `Edit.tsx` | AssignDoctorDialog | Registration lock; clinical write-gate | — | Patients tests | **Complete** | — | — | — |
| **Patient 360** (17 sections) | `patients.show`, `360/{section}`, `360/bills/{bill}` · `Show.tsx`, `patient360/*` | DeathRecordDialog, BillDialog (read-only), document upload, consents, safety editors, follow-up forms, SellPackage | `Patient360SectionPolicy` per section; deletion governance | Earlier QA: conditional pass | Patient360Test and others | **Complete** (records) | Billing section read-only (collect is in Billing); duplicate bill dialog; merge not exposed | P2 / P3 | 4–5 |
| **Deceased handling** | governed `patient.record_death` / `correct_death` | DeathRecordDialog | `PatientCareGuard` in every new-care writer | — | Deceased tests | **Complete** | Owner decides in Approvals, which is reachable only from Patient 360 links (not in nav) | P2 | 3 |
| **Duplicate resolution / merge** | governed `patient.merge` | *none* | `PatientMergeService` | No UI | Governance tests | **Missing UI** | Finding 6 | P2 | 4 |
| **Approvals** | `clinic.approvals.*` · `Approvals/Index.tsx` | Decide / clarify dialogs | Governance actions | Page exists; held out of nav (`ClinicFrontend::AVAILABLE`) | Governance tests | **Partial** | Owner has no nav entry; pending requests can wait unseen | P2 | 3 |
| **Follow-ups board** | `clinic.follow-ups.index` → `FollowUps/Index` **missing** | — | Board controller + annotate | Placeholder | FollowUps tests | **Missing UI** | Follow-ups exist per patient in Patient 360 and on the desk panel only | P2 | 3 |
| **Reports** | `clinic.reports.index` → `Reports/Index` **missing** | — | Exports exist | Placeholder (owner) | Reports tests | **Missing UI** | Reception 403 is correct | P2 | 4 |
| **Visits list** | `clinic.visits.index` → `Clinic/Visits/Index` **missing** | — | Legacy | Placeholder | — | **Missing / legacy** | Not linked in nav | P3 | 5 |
| **Daily Closing / Cash Handover** | — | — | — | — | — | **Missing** | Finding 5 | P2 | 3 |
| **Notifications** | `clinic.notifications.index` · `Notifications.tsx` | Bell menu | — | — | — | **Complete** | — | — | — |
| **Shell / nav / global search** | `ClinicPortalShell`, `ClinicNavigation`, `clinic.search` | Account menu, Owner Mode dialog | Role-aware nav from one registry; `ClinicFrontend::AVAILABLE` gates "Soon" | No overflow at 390 | Shell / Portal tests | **Complete** | Approvals hidden (see above) | — | — |

---

## 3. Master list of pages (reception and patient scope)

**Built**
- Front Desk (`FrontDesk/Index`).
- Queue lists (`FrontDesk/List`).
- Dashboard / Command Center.
- Appointments.
- Patients directory, Create, Edit, Patient 360.
- Billing (Bills & Collections), Refunds & Adjustments, Summary.
- Approvals (page exists, not in the navigation).
- Notifications.
- Support.
- Consultation workspace.
- Investigations.

**Placeholder (render target missing)**
- `Billing/Checkout`.
- `FollowUps/Index`.
- `Reports/Index`.
- `Clinic/Visits/Index`.
- `Today/Index`.
- Payroll, Leave, Staff, WhatsApp, Admin catalog and consents, and the infrastructure pages (outside this scope).

**Absent entirely**
- Daily Closing / Cash Handover.
- Queue TV display.
- Duplicate-merge screen.

## 4. Master list of popups / dialogs

| Area | Popups and dialogs |
|---|---|
| Intake | ReceptionIntakeDialog (New / Repeat), DatePicker, ClinicalPicker, CollectPaymentDialog, BookingFound, DonePanel / BillingResult |
| Front Desk | ActionMenu (row), PriorityDialog, DoctorDialog, Cancel-visit ConfirmDialog |
| Appointments | BookingDialog, DetailDialog, RescheduleDialog, CancelDialog, MoveScopeDialog, FindNextDialog, FiltersDialog, SettingsDialog, LeaveReviewDialog, SlotPicker |
| Billing | BillDetailDialog, with its in-dialog Collect / Refund / Adjust forms |
| Patient 360 | DeathRecordDialog, AssignDoctorDialog, BillDialog (read-only duplicate), document upload, consent forms, SafetyStrip editors (AlertPicker), SellPackage, follow-up forms |
| Shell | AccountMenu, NotificationsMenu, OwnerModeDialog, Dashboard VisibilityDialog, StaffDashboardsDialog |

**Dialog systems in use:** `clinic/ui/Modal` (intake, Patient 360), `clinic/ds/Dialog` (Front Desk), and `clinic/next/frame`.

## 5. User journeys (as built today)

1. **New patient, clinic charges at check-in**
   - Steps: New Case → Register & check in → fee charged on a bill → Collect payment popup → receipt (print thermal or A4; WhatsApp status) → patient waits in the queue → doctor calls and signs → done.
   - **Connected end to end.** No live payment was submitted.
2. **New patient, clinic bills after the visit**
   - Steps: New Case → check in → doctor signs → the consultation appears as "Ready to bill" on the Front Desk and Billing → **dead end**.
   - Billing points to a checkout page that renders the placeholder, and no desk button calls `collect`. **Broken (P1).**
3. **Returning patient:** Repeat Case → search → select → check in → as journey 1 or 2.
4. **Booked patient:** Calendar → appointment → Check in → the same intake with the booking linked → as journey 1 or 2.
5. **Paying an existing balance:** Billing → open the bill → Collect payment → A4 print. **Works**, but there is no per-payment receipt reprint.
6. **Later visit:** Patient 360 → Check in (intake) or Repeat Case → history in Visits / Timeline / Billing (read-only).
7. **End of day:** no closing, cash count or handover. "Collected today" is shown on the Front Desk panel only.

## 6. Tests and build

- `tsc`: 24 errors, the existing baseline, all in legacy `ovs/`, enterprise and auth widgets. None are in Clinic code.
- Clinic suite: see section 9.
- Never run, by rule: migrations, seeders, `npm run build` alongside tests, live payments or messages.

## 7. Already complete — do not rebuild

- New Case and Repeat Case (approved design).
- Check-in charging and the Collect Payment popup.
- `PaymentService` (locking, ceilings, receipt numbers).
- Idempotency (`IntakeSubmissions`).
- Front Desk queue board and its dialogs.
- Appointments calendar and all its dialogs.
- Patients directory, Create, Edit, Transfer.
- Patient 360 sections.
- Deceased safeguards.
- Billing register, Refunds & Adjustments, Summary.
- Payment message status and retry.
- Physio packages.
- Navigation registry and Owner Mode.

## 8. Implementation order (awaiting approval)

**Phase 1 — Payment correctness (P0)**
1. Enforce the desk method rule on the server: refuse Gateway and Insurance in manual payments (`recordPayment` callers, or an explicit `$manual` flag), and stop offering them on Billing and Patient 360. Add tests.
2. Add the target-branch `billing.create` check to `CheckoutController::store` (match `assertBranchId(..., BillingCreate)`). Add a test beside `TargetBranchFinancePermissionTest`.

**Phase 2 — Finish the money journey (P1)**

2a. Link Queue to the Front Desk (add `clinic.reception` to `ClinicFrontend::AVAILABLE`, N1), after you decide which page is the reception home (N2).

3. Build the **Checkout** page (`ClinicPortal/Billing/Checkout`) on the existing `show` / `store` props: lines, discount (with permission), raise bill, then collect through the shared `PaymentForm`, then the receipt. Add `clinic.billing.checkout` to `ClinicFrontend::AVAILABLE`.
4. **Desk collect** on the Front Desk "Ready to bill" rows: open a Collect popup that posts to the existing `collect` endpoint with a key, and show a receipt result (reuse `BillingResult`).
5. **Receipt reprint:** per-payment thermal / A4 links in BillDetailDialog and the Patient 360 bill dialog (the server already supports `?layout=receipt&payment=`).
6. Billing status filter: drop "Draft". `BillingStatus::Draft` exists, but no writer ever sets it (verified by grep), so the option never matches.

**Phase 3 — Daily operations (P2)**

7. **Daily Closing / Cash Handover:** needs a design decision and new tables. Scope it first; do not build it unapproved.
8. **Follow-ups board** page on the existing controller.
9. Approvals in the owner navigation (`ClinicFrontend::AVAILABLE`), or in the Command Center decision list.

**Phase 4 — Governance and records (P2)**

10. Duplicate merge UI on the `patient.merge` governed action.
11. Void bill UI on `bill.void`.
12. Treatment-charges UI, or retire the endpoint.
13. Reports page (owner).

**Phase 5 — Consistency (P3)**

14. One bill dialog (Patient 360 reuses BillDetailDialog, read-only mode).
15. Bring the billing dialogs and the check-in success panel to the approved intake look.
16. Queue visit-reason correction UI (`links.visit`).
17. Queue TV display, if wanted.
18. Converge on one dialog system.

**Decision needed before Phase 2:** should practitioners keep `billing.view` (money visible to doctors)?

## 9. Test results (2026-10-29)

| Check | Result |
|---|---|
| `php artisan test --compact tests/Feature/Clinic` (177 files, in-memory SQLite) | **1612 passed, 2 skipped, 0 failed** (14,252 assertions, 458 s) |
| Reception + Patients subset, after the Repeat Case work | 469 passed, 1 skipped |
| `npx tsc --noEmit` | 24 errors, the baseline: `ovs/` (22), `components/enterprise-system` (1), `components/auth` (1). None in Clinic code. |
| `npm run build` | Passed earlier today (after the intake work); not re-run for this audit |
| Frontend component tests | None exist for the Clinic portal (no Vitest/Jest suite). Covered by server tests and browser checks only. |

No test covers:
- a manual Gateway or Insurance payment being refused (finding 2);
- `checkout.store` at another branch (finding 3);
- after-visit billing through any UI (there is none).

---

## 10. Navigation, sidebar, top bar and portal-wide visual consistency

Audit only. No shell file was changed.

### 10.1 Navigation defects (functional)

| # | Problem | Evidence | Pri |
|---|---|---|---|
| N1 | **The Front Desk is unreachable from the sidebar.** The "Queue" entry (`ClinicNavigation` key `front_desk` → `clinic.reception`) renders as a disabled "Soon" item, because `clinic.reception` is not in `ClinicFrontend::AVAILABLE`, although `FrontDesk/Index` is built and working. On `/clinic/reception` no sidebar item is highlighted. | Browser, Physio reception: sidebar shows `Queue · Soon`; `aside [aria-current=page]` is null on `/clinic/reception` | **P1** |
| N2 | **Two reception home screens.** Dashboard (`/clinic/dashboard`, `ClinicPortal/Dashboard`: quick actions, figures, today's patients) and Front Desk (`/clinic/reception`, `FrontDesk/Index`: queue board, row actions, side panels) overlap. Their greetings differ ("Good Morning, QA! 👋" against "Good morning, QA"). Which one is "the Reception Dashboard" you do not want redesigned needs confirming before the shell phase. | Browser, both pages | Decision |
| N3 | Follow-ups appears as "Soon" (its page is missing; see section 2). Correct as a placeholder, but reception sees a dead entry. | Browser drawer | P2 |
| N4 | Approvals is in the account menu's admin section, gated by `command_center`, yet hidden by `ClinicFrontend::AVAILABLE`. Owners can reach it only from Patient 360 links. | Code | P2 |

### 10.2 Role-aware navigation (from `ClinicNavigation::DOMAINS` and the default role permissions; covered by `DynamicNavigationDashboardTest`, `RoleAwareDashboardTest` and `ClinicAppShellTest`, all passing)

| Entry | Rule | Owner / Admin | Reception | Billing | Practitioner | Staff |
|---|---|---|---|---|---|---|
| Dashboard | everyone | ✓ | ✓ | ✓ | ✓ | ✓ |
| Appointments | `appointment.view` + appointments module | ✓ | ✓ | — | ✓ | ✓ |
| Patients | `patient.view` + patients module | ✓ | ✓ | ✓ | ✓ | ✓ |
| Queue (Front Desk) | workspace `front_desk` | ✓ | ✓ (shown as **Soon**, N1) | — | — | — |
| Billing | `billing.view` + workspace command_center / front_desk / billing | ✓ | ✓ | ✓ | — (no workspace, but holds `billing.view`: decision 10 in section 1) | — |
| Reports | `reports.view` + `command_center` | ✓ | — (403 verified) | — | — | — |
| Follow-ups | `clinical.edit` or `appointment.create` | ✓ | ✓ (Soon) | — | ✓ (Soon) | — |
| Notifications, Support | everyone | ✓ | ✓ | ✓ | ✓ | ✓ |
| Approvals, Staff, Branches, Settings (account menu) | per entry | ✓ | — | — | — | — |

The browser check covered the Reception role only. The other columns are read from code and covered by the passing tests listed above. Every route re-checks on the server, so a hidden entry is never the only guard.

### 10.3 Visual consistency gaps (measured in the browser at 1440, Physio reception)

**Reference (the approved intake):**
- white surface on a lavender wash (`#F2F6FF` → `#F6F3FF`);
- teal `#14917F` / `#0F6B5F`;
- blue `#1769AA`;
- ink `#0F2A44`;
- muted `#475569` / `#64748B`;
- section titles 13 px bold uppercase teal;
- 40–44 px controls with 12 px radius, and soft rings in place of borders.

| Surface | Measured | Gap against the intake |
|---|---|---|
| **Shell tokens** (`tailwind.config` `cx.nav-*`) | teal `#00A8A8` / `#007A7B`, ink `#0B1F33`, canvas `#F5F8FC`, line `#D6E3EF` | A second teal and a second ink. Side by side with the intake (teal `#0F6B5F`, ink `#0F2A44`) the active nav state reads as a different brand colour. |
| **Sidebar** (`Sidebar.tsx`) | 240 px, cyan wash `#F1FAFA` → white, active = teal→blue gradient pill (`#DDF4F2` → `#E6F0FD`) with `#007A7B` text, 18 px icons, 11 px uppercase group labels, 40 px rows, `rounded-md` | Closest to the intake already. The canvas is grey-blue `#F5F8FC`, not the intake's lavender, and group labels are grey, not teal. "Soon" items look nearly like live ones. |
| **Top bar** (`TopBar.tsx`) | 72 px white/95 with a hairline, 44 px icon buttons, search 200–560 px, live clock ≥1024, wordmark only below `lg` | Fine structurally. Search and focus colours use `cx-nav-teal` (`#00A8A8`), not the intake teal. |
| **Page titles** | Front Desk 28 px / 700 / `#173B60`; Patients 24 / 700 / `#173B60`; Appointments 26 / 700 / `#0B0B0F`; Billing and Notifications 26 / 600 / `#0B1F33` | **Four title styles and three inks.** Only Billing, Notifications and Approvals use the shared `PageHeader`; the others hand-roll it. |
| **Primary buttons** | Front Desk and Patients: blue `#1769AA`, 44 px, `rounded-xl` (24 px); Appointments: near-black `#0B0B0F` pill; Billing and Notifications toggles: navy `#0B1F33` pill; Bill dialog: black `Collect payment` | **Three primary colours.** The intake's primary is the blue (Check in, Register & check in). |
| **Cards** | 24 px radius on Front Desk and Patients; 16 px on Billing; gradient hero on Front Desk and Dashboard | Radius mix (16 / 24) and decorative heroes; the intake uses no nested cards. |
| **Dialogs** | Intake: `clinic/ui/Modal` `case` + white body; Front Desk: `clinic/ds/Dialog`; Bill: `Modal size=lg` with black buttons; Patient 360: its own `BillDialog` | Three dialog skins. |
| **Responsive** | No horizontal overflow at 390 on Front Desk, Dashboard, Patients, Appointments, Billing. The drawer below 1024 works: teal active pill, close button, clinic card at the foot. | No defect found. 360 and 768 checked for the intake only. |

### 10.4 Smallest safe set of shared-layout changes (for the later, separate phase)

These change styling only; navigation structure, routes, permissions and menu items stay as they are.

1. **One palette.** Point the `cx.nav-*` tokens at the intake's values (teal `#0F6B5F` / `#14917F`, ink `#0F2A44`, lavender canvas `#F4F6FD`). One file, `tailwind.config.js`, and every shell surface follows. Check text contrast afterwards (WCAG AA).
2. **Sidebar:** teal group labels; the active pill keeps its shape, recoloured by (1); make "Soon" rows clearly inert (lower opacity, no hover).
3. **Shared page header:** make `clinic/ui/PageHeader` the one title style (24 px / 700 / ink, 14 px muted subtitle, actions right) and adopt it on Front Desk, Patients and Appointments. Markup swap only.
4. **One primary button:** the intake blue `#1769AA` (`DsButton primary`). Replace the black and navy primaries on Appointments, Billing and the bill dialog.
5. **Radius:** 16 px for cards and 12 px for controls, everywhere.
6. Two items that are not styling and need your approval:
   - **N1:** add `clinic.reception` to `ClinicFrontend::AVAILABLE` so Queue links to the Front Desk.
   - **N2:** decide whether Dashboard or Front Desk is the reception home.

Verify the result for each role at 1440, 1024, 768, 390 and 360 when implemented.
