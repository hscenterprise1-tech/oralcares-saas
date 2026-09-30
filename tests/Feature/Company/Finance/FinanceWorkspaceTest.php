<?php

use App\ExpenseStatus;
use App\Models\Clinic;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyExpense;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\MrrSnapshot;
use App\Models\Organization;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\PayrollPeriodStatus;
use App\Services\Finance\MrrService;
use App\SubscriptionStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * The vendor's books.
 *
 * What this replaces, in order of how much it mattered:
 *
 *   - Revenue summed a `mrr` column that did not exist, so every figure on the
 *     page was silently zero while a hard-coded `growth_rate: 0.0` sat beside
 *     it and the chart repeated today's MRR across six months as a "trend".
 *   - `POST /company/finance/expenses` was gated on `view-expenses` and wrote
 *     the row `approved` in the same request.
 *   - All four lists built search as an ungrouped `orWhere`, so searching
 *     silently discarded the filter next to it.
 *   - "This month" was `whereMonth(...)` with no year.
 *
 * Finance is HQ-global by design. Every query is deliberately unscoped: this is
 * the company's own position across all tenants, not a per-customer view.
 */
function financeUser(array $permissions): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

function subscriptionWorth(?float $mrr, array $overrides = []): CompanySubscription
{
    $org = Organization::factory()->create();

    return CompanySubscription::create(array_merge([
        'organization_id' => $org->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addYear(),
        'max_users' => 5,
        'max_branches' => 1,
        'mrr' => $mrr,
    ], $overrides));
}

function expenseAt(string $status, float $amount, array $overrides = []): CompanyExpense
{
    return CompanyExpense::create(array_merge([
        'expense_number' => 'EXP-'.fake()->unique()->numerify('####'),
        'description' => 'Server hosting',
        'category' => 'infrastructure',
        'amount' => $amount,
        'expense_date' => now()->toDateString(),
        'status' => $status,
        /* `submitted_by` is NOT NULL — every expense was claimed by somebody. */
        'submitted_by' => User::factory()->create(['user_type' => UserType::CompanyUser])->id,
    ], $overrides));
}

function invoiceFor(int $clinicId, string $number, string $status, float $amount, ?string $dueDate = null): CommercialInvoice
{
    return CommercialInvoice::create([
        'clinic_id' => $clinicId,
        'invoice_number' => $number,
        'status' => $status,
        'amount' => $amount,
        'final_amount' => $amount,
        /* NOT NULL on the table. */
        'due_date' => $dueDate ?? now()->addDays(30)->toDateString(),
    ]);
}

beforeEach(function () {
    setPermissionsTeamId(0);

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $this->reader = financeUser(['view-finance', 'view-expenses', 'view-revenue', 'view-payments', 'view-invoices']);
    $this->claimant = financeUser(['view-expenses', 'manage-expenses']);
    $this->approver = financeUser(['view-expenses', 'manage-expenses', 'approve-expenses']);
});

/* ────────────────────────── super admin access ──────────────────────────── */

it('lets the super admin open every Finance surface holding no permissions', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    foreach ([
        'company.finance.overview',
        'company.finance.revenue.index',
        'company.finance.invoices.index',
        'company.finance.payments.index',
        'company.finance.expenses.index',
        /*
         * `company.finance.payroll.index` is gone. It was the last reader of
         * the legacy `company_payrolls` table and had already lost its menu
         * entry, which left a gated route nothing linked to. Payroll is
         * `company.people.payroll.*` and nowhere else.
         */
    ] as $route) {
        $this->actingAs($this->admin)->get(route($route))->assertSuccessful();
    }
});

/* ─────────────────── read / write / approve separation ──────────────────── */

it('refuses a reader the right to submit an expense', function () {
    /*
     * The defect this closes: the POST was gated on `view-expenses`, so anybody
     * who could read the ledger could enter and self-approve any amount.
     */
    $this->actingAs($this->reader)->get(route('company.finance.expenses.index'))->assertOk();

    $this->actingAs($this->reader)
        ->post(route('company.finance.expenses.store'), [
            'description' => 'Sneaky', 'category' => 'office', 'amount' => 5000, 'expense_date' => now()->toDateString(),
        ])
        ->assertForbidden();

    expect(CompanyExpense::count())->toBe(0);
});

it('refuses a claimant the right to approve', function () {
    $expense = expenseAt(ExpenseStatus::Submitted->value, 1000);

    $this->actingAs($this->claimant)
        ->post(route('company.finance.expenses.transition', $expense), ['status' => 'approved'])
        ->assertForbidden();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

/* ──────────────────────── the expense workflow ──────────────────────────── */

it('submits an expense without approving it', function () {
    $this->actingAs($this->claimant)
        ->post(route('company.finance.expenses.store'), [
            'description' => 'Laptop', 'category' => 'office', 'amount' => 82000, 'expense_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    $expense = CompanyExpense::sole();

    expect($expense->status)->toBe(ExpenseStatus::Submitted)
        ->and($expense->submitted_by)->toBe($this->claimant->id)
        ->and($expense->submitted_at)->not->toBeNull()
        ->and($expense->approved_at)->toBeNull()
        ->and($expense->paid_at)->toBeNull();
});

it('walks an expense from submitted to approved to paid, recording who did each', function () {
    $expense = expenseAt(ExpenseStatus::Submitted->value, 25000, ['submitted_by' => $this->claimant->id]);

    $this->actingAs($this->approver)
        ->post(route('company.finance.expenses.transition', $expense), ['status' => 'approved'])
        ->assertRedirect();

    $expense = $expense->fresh();

    expect($expense->status)->toBe(ExpenseStatus::Approved)
        ->and($expense->approved_by)->toBe($this->approver->id);

    $this->actingAs($this->claimant)
        ->post(route('company.finance.expenses.transition', $expense), [
            'status' => 'paid', 'payment_reference' => 'NEFT-99201',
        ])
        ->assertRedirect();

    $expense = $expense->fresh();

    expect($expense->status)->toBe(ExpenseStatus::Paid)
        ->and($expense->paid_by)->toBe($this->claimant->id)
        ->and($expense->payment_reference)->toBe('NEFT-99201')
        ->and($expense->paid_at)->not->toBeNull();
});

it('refuses to pay an expense nobody approved', function () {
    $expense = expenseAt(ExpenseStatus::Submitted->value, 4000);

    $this->actingAs($this->approver)
        ->post(route('company.finance.expenses.transition', $expense), ['status' => 'paid'])
        ->assertSessionHasErrors('status');

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('refuses to let the claimant approve their own expense', function () {
    $expense = expenseAt(ExpenseStatus::Submitted->value, 9000, ['submitted_by' => $this->approver->id]);

    $this->actingAs($this->approver)
        ->post(route('company.finance.expenses.transition', $expense), ['status' => 'approved'])
        ->assertSessionHasErrors('status');

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('treats the legacy pending status as submitted rather than rewriting it', function () {
    /*
     * One row in the real database carries `pending`, written before the
     * workflow existed. It stays exactly as it is and is still approvable.
     */
    $expense = expenseAt('pending', 28400);

    expect($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->status->allowedNext())->toBe([ExpenseStatus::Approved]);

    $this->actingAs($this->approver)
        ->post(route('company.finance.expenses.transition', $expense), ['status' => 'approved'])
        ->assertRedirect();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Approved);
});

it('counts paid expenses as spend rather than dropping them', function () {
    expenseAt(ExpenseStatus::Approved->value, 10000);
    expenseAt(ExpenseStatus::Paid->value, 15000);
    expenseAt(ExpenseStatus::Submitted->value, 3000);

    $this->actingAs($this->admin)
        ->get(route('company.finance.expenses.index'))
        ->assertInertia(fn ($page) => $page
            /* Was `where('status', 'approved')`, which lost every paid row. */
            ->where('kpi.total_expenses', 25000)
            ->where('kpi.pending_approval', 3000)
        );
});

/* ─────────────────────────── MRR snapshotting ───────────────────────────── */

it('records MRR on the subscription and does not move when the plan price changes', function () {
    $plan = CompanyPlan::factory()->create(['price' => 12999, 'billing_period' => 'monthly']);
    $subscription = subscriptionWorth(app(MrrService::class)->snapshotValueFor($plan), ['company_plan_id' => $plan->id]);

    expect((float) $subscription->mrr)->toBe(12999.0);

    /* The list price triples. What this customer contributes does not. */
    $plan->update(['price' => 38997]);

    expect((float) $subscription->fresh()->mrr)->toBe(12999.0)
        ->and(app(MrrService::class)->currentPosition()['mrr'])->toBe(12999.0);
});

it('normalises a yearly plan to a monthly figure', function () {
    $plan = CompanyPlan::factory()->create(['price' => 120000, 'billing_period' => 'yearly']);

    expect(app(MrrService::class)->snapshotValueFor($plan))->toBe(10000.0);
});

it('reports a subscription with no recorded MRR instead of counting it as zero', function () {
    subscriptionWorth(50000.0);
    subscriptionWorth(null);

    $position = app(MrrService::class)->currentPosition();

    expect($position['mrr'])->toBe(50000.0)
        ->and($position['active_subscriptions'])->toBe(2)
        ->and($position['unrecorded'])->toBe(1);
});

/* ───────────────────────── MRR monthly history ──────────────────────────── */

it('captures one snapshot per month and replaces it rather than accumulating', function () {
    subscriptionWorth(20000.0);

    $mrr = app(MrrService::class);
    $mrr->capture();
    $mrr->capture();

    expect(MrrSnapshot::count())->toBe(1)
        ->and((float) MrrSnapshot::sole()->mrr)->toBe(20000.0);
});

it('leaves an earlier month alone when a later one is captured', function () {
    $subscription = subscriptionWorth(10000.0);

    app(MrrService::class)->capture(now()->subMonth());

    $subscription->update(['mrr' => 30000]);
    app(MrrService::class)->capture();

    $history = app(MrrService::class)->history(6);

    expect($history)->toHaveCount(2)
        ->and((float) $history->first()->mrr)->toBe(10000.0)
        ->and((float) $history->last()->mrr)->toBe(30000.0);
});

it('reports growth as unavailable until two months exist', function () {
    subscriptionWorth(10000.0);

    expect(app(MrrService::class)->growthRate())->toBeNull();

    app(MrrService::class)->capture(now()->subMonth());
    expect(app(MrrService::class)->growthRate())->toBeNull();

    CompanySubscription::query()->update(['mrr' => 12000]);
    app(MrrService::class)->capture();

    expect(app(MrrService::class)->growthRate())->toBe(20.0);
});

it('sends Revenue a gap rather than a zero for a month it never recorded', function () {
    subscriptionWorth(15000.0);
    app(MrrService::class)->capture();

    $this->actingAs($this->admin)
        ->get(route('company.finance.revenue.index'))
        ->assertInertia(function ($page) {
            $trend = collect($page->toArray()['props']['monthly_trend']);

            /* Six months rendered, one recorded, five honest nulls. */
            expect($trend)->toHaveCount(6)
                ->and($trend->whereNotNull('mrr')->count())->toBe(1)
                ->and($trend->whereNull('mrr')->count())->toBe(5);
        });
});

it('never sends a hard-coded growth figure', function () {
    subscriptionWorth(15000.0);

    $this->actingAs($this->admin)
        ->get(route('company.finance.revenue.index'))
        ->assertInertia(fn ($page) => $page
            ->where('kpi.growth_rate', null)
            ->where('kpi.has_history', false)
        );
});

/* ──────────────────── search and filters, together ──────────────────────── */

it('applies the search and the status filter at the same time', function () {
    /*
     * The defect: `where(A)->orWhereHas(B)` then `->where('status', …)` reads
     * as `A OR (B AND status)` in SQL, so the search escaped the filter beside
     * it and the list stopped obeying it.
     */
    expenseAt(ExpenseStatus::Approved->value, 1000, ['description' => 'Datadog licence']);
    expenseAt(ExpenseStatus::Submitted->value, 2000, ['description' => 'Datadog licence']);
    expenseAt(ExpenseStatus::Approved->value, 3000, ['description' => 'Coffee machine']);

    $this->actingAs($this->admin)
        ->get(route('company.finance.expenses.index', ['search' => 'Datadog', 'status' => 'approved']))
        ->assertInertia(fn ($page) => $page->has('expenses.data', 1));
});

it('applies the search and the category filter at the same time', function () {
    expenseAt(ExpenseStatus::Approved->value, 1000, ['description' => 'Renewal', 'category' => 'software']);
    expenseAt(ExpenseStatus::Approved->value, 2000, ['description' => 'Renewal', 'category' => 'travel']);

    $this->actingAs($this->admin)
        ->get(route('company.finance.expenses.index', ['search' => 'Renewal', 'category' => 'travel']))
        ->assertInertia(fn ($page) => $page->has('expenses.data', 1));
});

it('applies the search and the status filter together on invoices', function () {
    $clinic = Clinic::factory()->create(['name' => 'Northgate Dental']);

    invoiceFor($clinic->id, 'INV-1', 'paid', 100);
    invoiceFor($clinic->id, 'INV-2', 'draft', 200);

    $this->actingAs($this->admin)
        ->get(route('company.finance.invoices.index', ['search' => 'Northgate', 'status' => 'draft']))
        ->assertInertia(fn ($page) => $page->has('invoices.data', 1));
});

it('applies the search and the payment method filter together', function () {
    $clinic = Clinic::factory()->create(['name' => 'Northgate Dental']);

    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 100, 'payment_method' => 'neft', 'paid_at' => now()]);
    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 200, 'payment_method' => 'upi', 'paid_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('company.finance.payments.index', ['search' => 'Northgate', 'payment_method' => 'upi']))
        ->assertInertia(fn ($page) => $page->has('payments.data', 1));
});

/* ─────────────────────── month filtering knows the year ─────────────────── */

it('does not count the same month of a previous year as this month', function () {
    $clinic = Clinic::factory()->create();

    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 5000, 'payment_method' => 'neft', 'paid_at' => now()]);
    /*
     * `whereMonth('paid_at', now()->month)` had no year predicate, so this row
     * — the same month, a year earlier — was counted as this month's takings.
     */
    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 90000, 'payment_method' => 'neft', 'paid_at' => now()->subYear()]);

    $this->actingAs($this->admin)
        ->get(route('company.finance.payments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('kpi.this_month', 5000)
            ->where('kpi.total_collected', 95000)
        );
});

it('filters payments to one month of one year', function () {
    $clinic = Clinic::factory()->create();

    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 100, 'payment_method' => 'neft', 'paid_at' => now()]);
    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 200, 'payment_method' => 'neft', 'paid_at' => now()->subYear()]);

    $this->actingAs($this->admin)
        ->get(route('company.finance.payments.index', ['month' => now()->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('payments.data', 1));
});

/* ───────────────────────── HQ-global by design ──────────────────────────── */

it('reports every tenant, because Finance is the company book and not a customer view', function () {
    $a = Clinic::factory()->create();
    $b = Clinic::factory()->create();

    CommercialPayment::create(['clinic_id' => $a->id, 'amount' => 1000, 'payment_method' => 'neft', 'paid_at' => now()]);
    CommercialPayment::create(['clinic_id' => $b->id, 'amount' => 2000, 'payment_method' => 'neft', 'paid_at' => now()]);

    /*
     * A finance reader belonging to one organisation still sees both. That is
     * the design, and it is stated here so nobody "fixes" it into a scoped
     * query later.
     */
    $this->actingAs($this->reader)
        ->get(route('company.finance.payments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('payments.data', 2)
            ->where('kpi.total_collected', 3000)
        );
});

it('holds no tenant scoping in the Finance service', function () {
    $source = File::get(app_path('Services/FinanceWorkspaceService.php'));

    /* Named in the docblock explaining why it is absent; never called. */
    expect($source)->not->toContain('CrmScope::')
        ->and($source)->not->toContain('use App\Support\CrmScope');
});

/* ────────────────── money in, money out, and what is owed ───────────────── */

it('reports money in, money out and the net movement for the month', function () {
    $clinic = Clinic::factory()->create();

    CommercialPayment::create(['clinic_id' => $clinic->id, 'amount' => 100000, 'payment_method' => 'neft', 'paid_at' => now()]);
    expenseAt(ExpenseStatus::Paid->value, 30000, ['paid_at' => now()]);
    /* Approved but not paid is a commitment, not a movement. */
    expenseAt(ExpenseStatus::Approved->value, 50000);

    $this->actingAs($this->admin)
        ->get(route('company.finance.overview'))
        ->assertInertia(fn ($page) => $page
            ->where('movement.money_in', 100000)
            ->where('movement.money_out', 30000)
            ->where('movement.net_movement', 70000)
            ->where('commitments.expenses_approved_unpaid', 50000)
        );
});

it('ages what is owed by how late it is', function () {
    $clinic = Clinic::factory()->create();

    invoiceFor($clinic->id, 'INV-A', 'sent', 1000, now()->addDays(10)->toDateString());
    invoiceFor($clinic->id, 'INV-B', 'overdue', 2000, now()->subDays(15)->toDateString());
    invoiceFor($clinic->id, 'INV-C', 'overdue', 4000, now()->subDays(120)->toDateString());
    /* Paid invoices are not owed. */
    invoiceFor($clinic->id, 'INV-D', 'paid', 9000, now()->subDays(5)->toDateString());

    $this->actingAs($this->admin)
        ->get(route('company.finance.overview'))
        ->assertInertia(fn ($page) => $page
            ->where('receivables.total_outstanding', 7000)
            ->where('receivables.invoice_count', 3)
            ->where('receivables.buckets.current', 1000)
            ->where('receivables.buckets.1_30', 2000)
            ->where('receivables.buckets.over_90', 4000)
        );
});

/* ──────────────────────── the payroll read seam ─────────────────────────── */

it('reads paid payroll runs into Finance without writing an expense', function () {
    $period = PayrollPeriod::create([
        'code' => 'PR-TEST', 'label' => 'September 2026',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => PayrollPeriodStatus::Paid,
        'total_gross' => 500000, 'total_deductions' => 50000, 'total_net' => 450000,
        'entry_count' => 4, 'paid_at' => now(),
    ]);

    /*
     * The legacy payroll register used to be read here too. Its route is gone —
     * it was the last door onto `company_payrolls` and nothing linked to it —
     * so the assertion that matters is the one below: Finance still reports
     * what payroll paid out, from the live periods table.
     */
    $this->actingAs($this->admin)
        ->get(route('company.finance.overview'))
        ->assertInertia(fn ($page) => $page->where('movement.money_out_payroll', 450000));

    /*
     * Finance reads payroll; payroll does not write to Finance. Creating an
     * expense row from a run would double-count against the very figure above.
     */
    expect(CompanyExpense::count())->toBe(0)
        ->and($period->fresh()->status)->toBe(PayrollPeriodStatus::Paid);
});

/* ─────────────── navigation agrees with the routes it points at ─────────── */

it('names the permission each Finance route actually requires', function () {
    /*
     * Every entry said `view-finance` while the routes asked `view-revenue`,
     * `view-invoices`, `view-payments`, `view-expenses` and `view-payroll` — so
     * a user holding only `view-finance` was shown all five and got a 403 on
     * every one.
     */
    $config = File::get(resource_path('js/ovs/layouts/sidebar-configs.ts'));

    /*
     * Every Finance entry names the permission its own route enforces. They all
     * used to say `view-finance` while the routes asked for these, so a reader
     * holding only `view-finance` was shown five entries and refused on four.
     *
     * Payroll is not in this list any more: it read the legacy `company_payroll`
     * table while the live payroll is People's. The route is untouched; only the
     * menu entry is gone.
     */
    foreach ([
        '/company/finance/revenue' => 'view-revenue',
        '/company/finance/invoices' => 'view-invoices',
        '/company/finance/payments' => 'view-payments',
        '/company/finance/expenses' => 'view-expenses',
    ] as $href => $permission) {
        expect($config)->toMatch("#href: '{$href}',\s+icon: \w+,\s+permission: '{$permission}'#");
    }

    expect($config)->not->toContain("href: '/company/finance/payroll'");
});

it('refuses a reader a Finance surface they hold no permission for', function () {
    $narrow = financeUser(['view-expenses']);

    $this->actingAs($narrow)->get(route('company.finance.expenses.index'))->assertOk();
    $this->actingAs($narrow)->get(route('company.finance.revenue.index'))->assertForbidden();
    $this->actingAs($narrow)->get(route('company.finance.payments.index'))->assertForbidden();
});
