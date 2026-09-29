<?php

use App\CompensationCalculationType;
use App\CompensationComponentType;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyIncentive;
use App\Models\CompanyPayroll;
use App\Models\CompensationComponent;
use App\Models\EmployeeCompensation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\PayrollPeriodStatus;
use App\Services\People\PayrollService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Payroll as a lifecycle rather than a text box.
 *
 * What this replaces: one table where somebody typed a gross and a deductions
 * figure and the row was written `paid` in the same request, gated on a *read*
 * permission. There was no salary structure, no period, no calculation, no
 * approval, no lock, and one unexplained deductions scalar.
 */
function payrollPermissions(): void
{
    foreach (['view-payroll', 'manage-payroll', 'approve-payroll', 'view-incentives', 'manage-incentives'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
}

function employeeWithPay(float $base, string $from = '2026-01-01'): CompanyEmployee
{
    $department = CompanyDepartment::firstOrCreate(
        ['code' => 'ENG'],
        ['name' => 'Engineering', 'status' => 'active'],
    );

    $employee = CompanyEmployee::create([
        'user_id' => User::factory()->create()->id,
        'employee_code' => 'EMP-'.fake()->unique()->numerify('####'),
        'designation' => 'Engineer',
        'department_id' => $department->id,
        'employment_status' => 'active',
        'joining_date' => '2026-01-01',
    ]);

    app(PayrollService::class)->setCompensation($employee, [
        'base_salary' => $base,
        'effective_from' => $from,
    ]);

    return $employee;
}

function draftPeriod(string $start = '2026-09-01', string $end = '2026-09-30'): PayrollPeriod
{
    return PayrollPeriod::create([
        'code' => 'PR-'.str_replace('-', '', $start),
        'label' => 'September 2026',
        'period_start' => $start,
        'period_end' => $end,
        'status' => PayrollPeriodStatus::Draft,
    ]);
}

beforeEach(function () {
    setPermissionsTeamId(0);
    payrollPermissions();

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->reader = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $this->reader->givePermissionTo('view-payroll');

    $this->operator = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $this->operator->givePermissionTo(['view-payroll', 'manage-payroll']);
});

/* ─────────────────── read vs manage: the security defect ───────────────── */

it('lets a reader see payroll but not create a run', function () {
    /*
     * The whole reason this batch exists: `POST /company/finance/payroll` was
     * gated on `view-payroll`, so anybody who could look at the payroll could
     * write a record and mark it paid for any amount.
     */
    $this->actingAs($this->reader)->get(route('company.people.payroll.index'))->assertOk();

    $this->actingAs($this->reader)
        ->post(route('company.people.payroll.store'), [
            'label' => 'Sneaky', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ])
        ->assertForbidden();

    expect(PayrollPeriod::count())->toBe(0);
});

it('refuses every payroll mutation to a user with no payroll permission', function () {
    $nobody = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $period = draftPeriod();

    $this->actingAs($nobody)->post(route('company.people.payroll.store'), [
        'label' => 'x', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
    ])->assertForbidden();

    $this->actingAs($nobody)->post(route('company.people.payroll.calculate', $period))->assertForbidden();

    $this->actingAs($nobody)->post(route('company.people.payroll.transition', $period), [
        'status' => PayrollPeriodStatus::Calculated->value,
    ])->assertForbidden();
});

it('requires approve-payroll to approve, not merely manage-payroll', function () {
    /* The person who calculates a run does not automatically sign it off. */
    $period = draftPeriod();
    employeeWithPay(100000);

    $this->actingAs($this->operator)->post(route('company.people.payroll.calculate', $period));
    $this->actingAs($this->operator)->post(route('company.people.payroll.transition', $period), [
        'status' => PayrollPeriodStatus::PendingApproval->value,
    ]);

    $this->actingAs($this->operator)
        ->post(route('company.people.payroll.transition', $period), [
            'status' => PayrollPeriodStatus::Approved->value,
        ])
        ->assertForbidden();

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::PendingApproval);
});

/* ───────────────────────── compensation effective dating ───────────────── */

it('closes the previous arrangement instead of editing it', function () {
    $employee = employeeWithPay(100000, '2026-01-01');

    app(PayrollService::class)->setCompensation($employee, [
        'base_salary' => 120000,
        'effective_from' => '2026-07-01',
    ]);

    $history = $employee->fresh()->load('user');
    $rows = EmployeeCompensation::where('employee_id', $employee->id)
        ->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-06-30')
        ->and($rows[1]->effective_to)->toBeNull()
        ->and((float) $rows[1]->base_salary)->toBe(120000.0);
});

it('reads the arrangement in force on the period end date', function () {
    $employee = employeeWithPay(100000, '2026-01-01');
    app(PayrollService::class)->setCompensation($employee, ['base_salary' => 200000, 'effective_from' => '2026-10-01']);

    $service = app(PayrollService::class);

    expect((float) $service->compensationFor($employee, new DateTime('2026-09-30'))->base_salary)->toBe(100000.0)
        ->and((float) $service->compensationFor($employee, new DateTime('2026-10-31'))->base_salary)->toBe(200000.0);
});

/* ─────────────────────────── calculation and totals ────────────────────── */

it('computes gross, deductions and net from explicit components', function () {
    $hra = CompensationComponent::create([
        'code' => 'HRA', 'name' => 'House rent allowance',
        'type' => CompensationComponentType::Earning,
        'calculation_type' => CompensationCalculationType::PercentageOfBase,
    ]);

    $pf = CompensationComponent::create([
        'code' => 'PF', 'name' => 'Provident fund',
        'type' => CompensationComponentType::Deduction,
        'calculation_type' => CompensationCalculationType::Fixed,
    ]);

    $employee = employeeWithPay(100000);

    /* The rates are the business's, configured here — not encoded anywhere. */
    app(PayrollService::class)->setCompensation($employee, [
        'base_salary' => 100000, 'effective_from' => '2026-02-01',
    ], [
        ['component_id' => $hra->id, 'value' => 20],
        ['component_id' => $pf->id, 'value' => 5000],
    ]);

    $period = draftPeriod();
    app(PayrollService::class)->calculate($period, $this->admin);

    $entry = $period->fresh()->entries()->sole();

    expect((float) $entry->gross_amount)->toBe(120000.0)
        ->and((float) $entry->deduction_amount)->toBe(5000.0)
        ->and((float) $entry->net_amount)->toBe(115000.0)
        ->and((float) $period->fresh()->total_net)->toBe(115000.0);

    /* Every line is named, so nothing on a payslip is unexplained. */
    expect($entry->lines->pluck('component_code')->all())->toBe(['BASE', 'HRA', 'PF']);
});

it('reports employees with no compensation instead of inventing one', function () {
    $department = CompanyDepartment::firstOrCreate(['code' => 'ENG'], ['name' => 'Engineering', 'status' => 'active']);

    CompanyEmployee::create([
        'user_id' => User::factory()->create(['name' => 'Unpaid Person'])->id,
        'employee_code' => 'EMP-NOPAY',
        'designation' => 'Analyst',
        'department_id' => $department->id,
        'employment_status' => 'active',
        'joining_date' => '2026-01-01',
    ]);

    $result = app(PayrollService::class)->calculate(draftPeriod(), $this->admin);

    expect($result['entries'])->toBe(0)
        ->and($result['skipped'])->toContain('Unpaid Person');
});

it('never pays one employee twice in a run', function () {
    employeeWithPay(100000);
    $period = draftPeriod();
    $service = app(PayrollService::class);

    $service->calculate($period, $this->admin);
    $service->calculate($period, $this->admin);

    expect($period->fresh()->entries()->count())->toBe(1);
});

it('refuses a second run covering the same window', function () {
    draftPeriod('2026-09-01', '2026-09-30');

    $this->actingAs($this->operator)
        ->post(route('company.people.payroll.store'), [
            'label' => 'Duplicate', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ])
        ->assertSessionHasErrors('period_start');

    expect(PayrollPeriod::count())->toBe(1);
});

/* ─────────────────────────── snapshot immutability ─────────────────────── */

it('does not rewrite a calculated run when a salary later changes', function () {
    $employee = employeeWithPay(100000);
    $period = draftPeriod();
    app(PayrollService::class)->calculate($period, $this->admin);

    /* A raise afterwards must not touch what the run already reported. */
    app(PayrollService::class)->setCompensation($employee, [
        'base_salary' => 999999, 'effective_from' => '2026-09-15',
    ]);

    expect((float) $period->fresh()->entries()->sole()->base_salary)->toBe(100000.0);
});

it('keeps a payslip readable after its component is renamed', function () {
    $bonus = CompensationComponent::create([
        'code' => 'BONUS', 'name' => 'Festive bonus',
        'type' => CompensationComponentType::Earning,
        'calculation_type' => CompensationCalculationType::Fixed,
    ]);

    $employee = employeeWithPay(50000);
    app(PayrollService::class)->setCompensation($employee, [
        'base_salary' => 50000, 'effective_from' => '2026-02-01',
    ], [['component_id' => $bonus->id, 'value' => 10000]]);

    $period = draftPeriod();
    app(PayrollService::class)->calculate($period, $this->admin);

    $bonus->update(['name' => 'Renamed Entirely']);

    $line = $period->fresh()->entries()->sole()->lines()->where('component_code', 'BONUS')->sole();

    expect($line->component_name)->toBe('Festive bonus');
});

/* ───────────────────────────── transitions and lock ────────────────────── */

it('walks the full lifecycle and records who moved it', function () {
    employeeWithPay(100000);
    $period = draftPeriod();
    $service = app(PayrollService::class);

    $service->calculate($period, $this->admin);
    $service->transition($period->fresh(), PayrollPeriodStatus::PendingApproval, $this->admin);
    $service->transition($period->fresh(), PayrollPeriodStatus::Approved, $this->admin);
    $service->transition($period->fresh(), PayrollPeriodStatus::Locked, $this->admin);
    $service->transition($period->fresh(), PayrollPeriodStatus::Paid, $this->admin, [
        'payment_reference' => 'NEFT-2026-09',
    ]);

    $fresh = $period->fresh();

    expect($fresh->status)->toBe(PayrollPeriodStatus::Paid)
        ->and($fresh->approved_by)->toBe($this->admin->id)
        ->and($fresh->locked_at)->not->toBeNull()
        ->and($fresh->paid_at)->not->toBeNull()
        ->and($fresh->payment_reference)->toBe('NEFT-2026-09');
});

it('refuses a jump straight from draft to paid', function () {
    $period = draftPeriod();

    expect(fn () => app(PayrollService::class)->transition($period, PayrollPeriodStatus::Paid, $this->admin))
        ->toThrow(InvalidArgumentException::class);

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Draft);
});

it('rejects an invalid transition through the endpoint', function () {
    $period = draftPeriod();

    $this->actingAs($this->operator)
        ->post(route('company.people.payroll.transition', $period), [
            'status' => PayrollPeriodStatus::Locked->value,
        ])
        ->assertSessionHasErrors('status');

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Draft);
});

it('refuses to recalculate a locked run', function () {
    employeeWithPay(100000);
    $period = draftPeriod();
    $service = app(PayrollService::class);

    $service->calculate($period, $this->admin);
    $service->transition($period->fresh(), PayrollPeriodStatus::PendingApproval, $this->admin);
    $service->transition($period->fresh(), PayrollPeriodStatus::Approved, $this->admin);
    $locked = $service->transition($period->fresh(), PayrollPeriodStatus::Locked, $this->admin);

    expect(fn () => $service->calculate($locked, $this->admin))->toThrow(InvalidArgumentException::class);
    expect($locked->fresh()->entries()->count())->toBe(1);
});

/* ─────────────────────────── legacy data preserved ─────────────────────── */

it('leaves the legacy payroll table entirely alone', function () {
    /*
     * The eighteen historical rows carry no period, components or approval,
     * because none of that existed when they were written. They are read and
     * counted, never migrated.
     */
    CompanyPayroll::create([
        'payroll_code' => 'PAY-202606-1', 'employee_id' => employeeWithPay(50000)->id,
        'month' => '2026-06', 'gross_amount' => 50000, 'deductions' => 6000,
        'net_amount' => 44000, 'status' => 'paid', 'paid_date' => '2026-06-28',
    ]);

    employeeWithPay(100000);
    app(PayrollService::class)->calculate(draftPeriod(), $this->admin);

    $legacy = CompanyPayroll::sole();

    expect($legacy->status)->toBe('paid')
        ->and((float) $legacy->net_amount)->toBe(44000.0)
        ->and(CompanyPayroll::count())->toBe(1);
});

/* ───────────────────────── incentives stay separate ────────────────────── */

it('records an incentive without touching payroll', function () {
    $employee = employeeWithPay(100000);
    $period = draftPeriod();
    app(PayrollService::class)->calculate($period, $this->admin);

    $this->actingAs($this->admin)
        ->post(route('company.people.incentives.store'), [
            'employee_id' => $employee->id,
            'description' => 'Q3 commission',
            'amount' => 25000,
            'awarded_date' => '2026-09-15',
        ])
        ->assertSessionHasNoErrors();

    /* The award exists, and the run's money is untouched by it. */
    expect(CompanyIncentive::count())->toBe(1)
        ->and((float) $period->fresh()->total_net)->toBe(100000.0)
        ->and($period->fresh()->entries()->sole()->lines()->where('component_code', 'BONUS')->count())->toBe(0);
});

it('refuses an incentive against a deal the awarder cannot see', function () {
    $employee = employeeWithPay(100000);
    $otherOrg = Organization::factory()->create();
    $foreignLead = Lead::factory()->create(['organization_id' => $otherOrg->id]);

    $awarder = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => Organization::factory()->create()->id,
    ]);
    $awarder->givePermissionTo(['view-incentives', 'manage-incentives']);

    $this->actingAs($awarder)
        ->post(route('company.people.incentives.store'), [
            'employee_id' => $employee->id,
            'description' => 'Cross-tenant',
            'amount' => 1000,
            'awarded_date' => '2026-09-15',
            'related_lead_id' => $foreignLead->id,
        ])
        ->assertNotFound();

    expect(CompanyIncentive::count())->toBe(0);
});

it('keeps the incentive list filtering correctly while searching', function () {
    /*
     * The payroll list has an ungrouped `orWhereHas` that lets a search escape
     * the filters beside it. This list groups it, and this pins that.
     */
    $a = employeeWithPay(100000);
    $b = employeeWithPay(90000);

    CompanyIncentive::create(['employee_id' => $a->id, 'description' => 'Alpha award', 'amount' => 100, 'awarded_date' => '2026-09-01']);
    CompanyIncentive::create(['employee_id' => $b->id, 'description' => 'Alpha award', 'amount' => 200, 'awarded_date' => '2026-09-02']);

    $this->actingAs($this->admin)
        ->get(route('company.people.incentives.index', ['search' => 'Alpha', 'employee_id' => $a->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('incentives.data', 1));
});

/* ──────────────────────────── page contracts ───────────────────────────── */

it('sends the payroll workspace its period list and legacy count', function () {
    draftPeriod();

    $this->actingAs($this->admin)
        ->get(route('company.people.payroll.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/PayrollWorkspace')
            ->has('periods.data', 1)
            ->has('legacy.count')
            ->where('can.manage', true)
        );
});

it('renders a payslip from the snapshot', function () {
    employeeWithPay(100000);
    $period = draftPeriod();
    app(PayrollService::class)->calculate($period, $this->admin);
    $entry = $period->fresh()->entries()->sole();

    $this->actingAs($this->admin)
        ->get(route('company.people.payroll.payslip', [$period, $entry]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/People/Payslip')
            ->where('entry.net_amount', 100000)
            ->has('earnings', 1)
        );
});

it('refuses a payslip reached through the wrong run', function () {
    employeeWithPay(100000);
    $period = draftPeriod();
    app(PayrollService::class)->calculate($period, $this->admin);
    $entry = $period->fresh()->entries()->sole();

    $other = draftPeriod('2026-10-01', '2026-10-31');

    $this->actingAs($this->admin)
        ->get(route('company.people.payroll.payslip', [$other, $entry]))
        ->assertNotFound();
});
