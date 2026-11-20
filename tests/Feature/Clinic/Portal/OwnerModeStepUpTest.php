<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicExpense;
use App\Models\ClinicMembership;
use App\Models\ClinicPayrollPeriod;
use App\Models\PatientBillingRefund;
use App\Models\SecurityAuditLog;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Business\ClinicExpenses;
use App\Services\Business\ClinicPayroll;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\OwnerMode\OwnerMode;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;
use Tests\Support\ReceptionFixture;

/**
 * Sensitive actions taken by the clinic owner, checked where the action is
 * carried out. Here the owner also works as a doctor, so they act in Doctor
 * Mode until they enter Owner Mode: T1 actions need Owner Mode on, and T2
 * actions need the password confirmed in the last 5 minutes. Owner Mode ends
 * 15 minutes after its last use and 60 minutes after it began, however busy
 * the session. (An owner who does not treat patients holds the owner's
 * privileges from sign-in: see the tests at the end.)
 *
 * The sole owner may decide their own expense directly — explicitly, with a
 * fresh confirmation, only while nobody else could decide it — and that is
 * recorded as such. Nobody else may, and nobody pays an expense they recorded.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    config(['security.owner_mode' => ['idle_timeout_minutes' => 15, 'lifetime_minutes' => 60, 'fresh_verification_minutes' => 5]]);
    $this->f = ReceptionFixture::make('owner-step-up');
    // The owner treats patients here too: Doctor Mode by default.
    $this->f->doctor('Dr. Owner', $this->f->owner);

    $this->bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Step', 'Up'), $this->f->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 2000]]);
    app(PaymentService::class)->recordPayment($this->bill->fresh(), PaymentMethod::Cash, 2000);
    $this->category = app(ClinicExpenses::class)->categories($this->f->clinic)->firstWhere('name', 'Rent');

    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->enterOwnerMode = fn () => ($this->asOwner)()->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->refund = fn () => $this->post("/clinic/billing/bills/{$this->bill->id}/refunds", ['amount' => 10, 'reason' => 'Overcharged', 'method' => 'cash']);
    $this->refunds = fn () => PatientBillingRefund::query()->withoutGlobalScopes()->count();
    $this->refused = fn (string $reason) => SecurityAuditLog::query()->where('event', 'owner_mode.action_refused')->get()
        ->filter(fn (SecurityAuditLog $log) => ($log->context['reason'] ?? null) === $reason)->count();
    $this->ownExpense = fn () => ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'category_id' => $this->category->id,
        'title' => 'Owner bought chairs', 'amount' => 6000, 'spent_on' => '2026-10-21', 'status' => ClinicExpense::PENDING, 'created_by' => $this->f->owner->id,
    ]);
    $this->expenseEvents = fn (string $event, ClinicExpense $expense) => SecurityAuditLog::query()->where('event', $event)
        ->where('subject_type', 'ClinicExpense')->where('subject_id', $expense->id)->get();
});

// ---------------------------------------------------------------- Owner Mode on the server

test('the owner without Owner Mode is refused a refund, and the refusal is recorded', function () {
    ($this->asOwner)()->post("/clinic/billing/bills/{$this->bill->id}/refunds", ['amount' => 10, 'reason' => 'Overcharged'])
        ->assertForbidden()->assertSee(OwnerMode::ACTION_REFUSAL);

    // In Doctor Mode the owner holds a doctor's permissions, so the route itself offers Owner Mode.
    // A doctor holds only their own fees (`billing.view_own_fees`), so the billing area's own gate refuses first.
    expect(($this->refunds)())->toBe(0)
        ->and(($this->refused)('owner_mode_inactive'))->toBe(1)
        ->and(SecurityAuditLog::query()->where('event', 'owner_mode.action_refused')->sole()->context)
        ->toMatchArray(['action' => 'clinic.billing.refunds.store', 'permission' => 'billing.view', 'tier' => 'standard']);
});

test('a wrong password leaves Owner Mode off and the action refused', function () {
    ($this->asOwner)()->post('/clinic/owner-mode', ['password' => 'not-it'])->assertSessionHasErrors('password');

    ($this->refund)()->assertForbidden();
    expect(($this->refunds)())->toBe(0);
});

test('in Owner Mode the owner refunds, and the refund is audited', function () {
    ($this->enterOwnerMode)();

    ($this->refund)()->assertSessionHasNoErrors()->assertRedirect();

    expect(($this->refunds)())->toBe(1)
        ->and(SecurityAuditLog::query()->where('event', 'billing.refunded')->sole()->context['amount'])->toEqual(10);
});

test('Owner Mode ends 15 minutes after it was last used', function () {
    ($this->enterOwnerMode)();

    $this->travel(16)->minutes();
    ($this->refund)()->assertForbidden();

    expect(($this->refunds)())->toBe(0)
        ->and(SecurityAuditLog::query()->where('event', 'owner_mode.expired')->sole()->context['reason'])->toBe('idle_timeout');
});

test('Owner Mode ends 60 minutes after it began, however busy the session', function () {
    ($this->enterOwnerMode)();

    // Used every 10 minutes: never idle, still past its lifetime at 61 minutes.
    foreach (range(1, 5) as $step) {
        $this->travel(10)->minutes();
        $this->get('/clinic/dashboard')->assertOk();
    }
    $this->travel(9)->minutes();
    ($this->refund)()->assertSessionHasNoErrors();

    $this->travel(2)->minutes();
    ($this->refund)()->assertForbidden();

    expect(($this->refunds)())->toBe(1)
        ->and(SecurityAuditLog::query()->where('event', 'owner_mode.expired')->sole()->context['reason'])->toBe('lifetime');
});

test('confirming the password again refreshes the verification but not the lifetime', function () {
    ($this->enterOwnerMode)();

    $this->travel(14)->minutes();
    $this->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->travel(14)->minutes();
    $this->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->travel(14)->minutes();
    $this->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->travel(14)->minutes();
    ($this->refund)()->assertSessionHasNoErrors();

    $this->travel(5)->minutes(); // 61 minutes since it began
    ($this->refund)()->assertForbidden();

    $events = SecurityAuditLog::query()->where('event', 'like', 'owner_mode.%')->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['owner_mode.activated', 'owner_mode.reverified', 'owner_mode.reverified', 'owner_mode.reverified', 'owner_mode.expired', 'owner_mode.action_refused']);
});

test('a T2 action needs the password confirmed within the last 5 minutes', function () {
    $period = app(ClinicPayroll::class)->open($this->f->clinic, '2026-10', $this->f->owner);
    ($this->enterOwnerMode)();

    $this->travel(6)->minutes();
    $this->post("/clinic/payroll/periods/{$period->id}/finalise")->assertForbidden()->assertSee(OwnerMode::FRESH_REFUSAL);
    expect(ClinicPayrollPeriod::query()->withoutGlobalScopes()->find($period->id)->status)->not->toBe(ClinicPayrollPeriod::FINALISED)
        ->and(($this->refused)('verification_stale'))->toBe(1);

    // A T1 action still runs on the same, not-fresh Owner Mode.
    ($this->refund)()->assertSessionHasNoErrors();

    $this->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->post("/clinic/payroll/periods/{$period->id}/finalise")->assertSessionHasNoErrors();
    expect(ClinicPayrollPeriod::query()->withoutGlobalScopes()->find($period->id)->status)->toBe(ClinicPayrollPeriod::FINALISED);
});

test('Owner Mode entered in one clinic does not carry over to another clinic of the same owner', function () {
    $second = ReceptionFixture::make('owner-step-up-second');
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $second->clinic->id, 'user_id' => $this->f->owner->id, 'is_primary_owner' => true,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);
    // A doctor there too, so the owner's privileges there need Owner Mode entered there.
    $second->doctor('Dr. Owner Too', $this->f->owner);
    $theirBill = app(BillingService::class)->createBill($second->clinic, $second->patient('Other', 'Clinic'), $second->branch, [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 900]]);
    app(PaymentService::class)->recordPayment($theirBill->fresh(), PaymentMethod::Cash, 900);

    // Owner Mode state for the first clinic, carried in a session working in the second.
    $this->actingAs($this->f->owner)->withSession([
        ...withOwnerMode($this->f->session),
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $second->branch->id,
    ])->post("/clinic/billing/bills/{$theirBill->id}/refunds", ['amount' => 10, 'reason' => 'Overcharged'])->assertForbidden();

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(SecurityAuditLog::query()->where('event', 'owner_mode.exited')->sole()->context['reason'])->toBe('context_changed');
});

test('Owner Mode state does not make anyone else the owner', function () {
    [$admin, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $ownersState = withOwnerMode($this->f->session)[OwnerMode::SESSION_KEY];

    $this->actingAs($admin)->withSession([...$adminSession, OwnerMode::SESSION_KEY => $ownersState])
        ->post('/clinic/approvals/direct', [])->assertForbidden();
});

test('changing a staff member\'s roles needs Owner Mode from the owner', function () {
    setPermissionsTeamId(null);
    $role = Role::firstOrCreate(['name' => ClinicRole::Receptionist->value, 'guard_name' => 'web', 'branch_id' => null]);
    $employee = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Desk One', 'email' => 'desk.one@example.com', 'status' => 'active']);
    $payload = ['name' => 'Desk One', 'email' => 'desk.one@example.com', 'status' => 'active', 'branches' => [$this->f->branch->id], 'roles' => [$role->id]];

    ($this->asOwner)()->put(route('admin.employees.update', $employee->id), $payload)->assertForbidden();
    expect($employee->fresh()->roles)->toHaveCount(0)
        ->and(($this->refused)('owner_mode_inactive'))->toBe(1);

    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))
        ->put(route('admin.employees.update', $employee->id), $payload)->assertSessionHasNoErrors();
    expect($employee->fresh()->roles->pluck('id')->all())->toBe([$role->id]);

    // Saving the same roles again is not a role change and needs no Owner Mode.
    ($this->asOwner)()->withSession(['clinic_owner_mode' => null])->put(route('admin.employees.update', $employee->id), $payload)->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------- Sole-owner direct decision

test('the sole owner decides their own expense directly, with a fresh confirmation, and it is recorded as such', function () {
    $expense = ($this->ownExpense)();
    ($this->enterOwnerMode)();

    // Asked for explicitly: a plain approval of one's own expense stays refused.
    $this->post("/clinic/expenses/{$expense->id}/approve")->assertForbidden();
    $this->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertSessionHasNoErrors();

    $approved = ($this->expenseEvents)('expense.approved', $expense);
    expect($expense->fresh())->status->toBe(ClinicExpense::APPROVED)->decided_by->toBe($this->f->owner->id)
        ->and($approved)->toHaveCount(1)
        ->and($approved->sole()->context['path'])->toBe('sole_owner_direct')
        ->and($approved->sole()->actor_user_id)->toBe($this->f->owner->id);

    // Sent again: decided once.
    $this->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertSessionHasErrors(['expense' => 'This expense has already been decided.']);
    expect(($this->expenseEvents)('expense.approved', $expense))->toHaveCount(1);
});

test('the direct path needs a fresh confirmation', function () {
    $expense = ($this->ownExpense)();

    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session, verifiedMinutesAgo: 6))
        ->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertForbidden()->assertSee(OwnerMode::FRESH_REFUSAL);
    ($this->asOwner)()->withSession(['clinic_owner_mode' => null])
        ->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertForbidden();

    // Without Owner Mode the doctor-owner is in Doctor Mode: refused at the route, before the expense.
    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING)
        ->and(($this->expenseEvents)('expense.sole_owner_direct_refused', $expense)->pluck('context.reason')->all())->toBe(['verification_stale'])
        ->and(($this->refused)('owner_mode_inactive'))->toBe(1);
});

test('the direct path is closed while someone else could decide the expense', function () {
    $expense = ($this->ownExpense)();
    $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))
        ->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING)
        ->and(($this->expenseEvents)('expense.sole_owner_direct_refused', $expense)->sole()->context['reason'])->toBe('approver_available');
});

test('an approver working only at another branch does not close the direct path for this branch\'s expense', function () {
    $expense = ($this->ownExpense)();
    $other = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Other Branch', 'status' => BranchStatus::Active]);
    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $membership = ClinicMembership::query()->withoutGlobalScopes()->findOrFail($session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]);
    $membership->update(['has_all_branches_access' => false]);
    $membership->assignBranch($other);
    app(ClinicRoleAssignmentService::class)->syncRoles($admin, [], [$this->f->branch]);
    app(ClinicRoleAssignmentService::class)->syncRoles($admin, [ClinicRole::ClinicAdmin->value], [$other]);

    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))
        ->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertSessionHasNoErrors();

    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED);
});

test('a clinic administrator cannot use the sole-owner exception', function () {
    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->actingAs($admin)->withSession($session)->post('/clinic/expenses', ['title' => 'Toner', 'amount' => 900, 'spent_on' => '2026-10-21', 'category_id' => $this->category->id])->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();

    $this->actingAs($admin)->withSession([...$session, OwnerMode::SESSION_KEY => withOwnerMode($this->f->session)[OwnerMode::SESSION_KEY]])
        ->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING)
        ->and(($this->expenseEvents)('expense.sole_owner_direct_refused', $expense)->sole()->context['reason'])->toBe('not_primary_owner');
});

test('the owner never pays an expense they recorded, even after deciding it directly', function () {
    $expense = ($this->ownExpense)();
    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))
        ->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertSessionHasNoErrors();

    $this->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'bank'])->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->expenseEvents)('expense.paid', $expense))->toHaveCount(0)
        ->and(($this->expenseEvents)('expense.pay_refused', $expense)->sole()->context['reason'])->toBe('recorder');
});

test('a recurring expense raised for the owner waits for a decision like any other', function () {
    app(ClinicExpenses::class)->saveRecurring($this->f->clinic, ['title' => 'Rent', 'amount' => 40000, 'category_id' => $this->category->id, 'frequency' => 'monthly', 'next_due_on' => '2026-10-01'], $this->f->owner);
    app(ClinicExpenses::class)->raiseDue(CarbonImmutable::parse('2026-10-22'));
    $raised = ClinicExpense::query()->withoutGlobalScopes()->sole();

    expect($raised->status)->toBe(ClinicExpense::PENDING)->and($raised->created_by)->toBe($this->f->owner->id);

    ($this->asOwner)()->post("/clinic/expenses/{$raised->id}/approve", ['sole_owner_direct' => true])->assertForbidden();
    expect($raised->fresh()->status)->toBe(ClinicExpense::PENDING);
});

// ---------------------------------------------------------------- an owner who does not treat patients

test('an owner who does not treat patients refunds and changes staff roles with no Owner Mode and no password prompt', function () {
    $plain = ReceptionFixture::make('owner-no-practice');
    $bill = app(BillingService::class)->createBill($plain->clinic, $plain->patient('Plain', 'Owner'), $plain->branch, [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 1000);
    $asPlainOwner = fn () => $this->actingAs($plain->owner)->withSession($plain->session);

    // Several ordinary owner actions, spread over more than an hour: never refused, never prompted.
    foreach (range(1, 3) as $step) {
        $asPlainOwner()->post("/clinic/billing/bills/{$bill->id}/refunds", ['amount' => 10, 'reason' => 'Overcharged', 'method' => 'cash'])->assertSessionHasNoErrors()->assertRedirect();
        $this->travel(25)->minutes();
    }

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->where('patient_billing_id', $bill->id)->count())->toBe(3)
        ->and(SecurityAuditLog::query()->where('event', 'owner_mode.action_refused')->count())->toBe(0)
        ->and(SecurityAuditLog::query()->where('event', 'like', 'owner_mode.%')->count())->toBe(0);
});

test('an owner who does not treat patients still confirms their password for a T2 action, and only for it', function () {
    $plain = ReceptionFixture::make('owner-no-practice-t2');
    $period = app(ClinicPayroll::class)->open($plain->clinic, '2026-10', $plain->owner);
    $asPlainOwner = fn () => $this->actingAs($plain->owner)->withSession($plain->session);

    // Finalising payroll is T2: the owner is asked to confirm, and nothing runs until they do.
    $asPlainOwner()->withHeaders(['X-Inertia' => 'true'])->post("/clinic/payroll/periods/{$period->id}/finalise")->assertForbidden()
        ->assertHeader('X-Owner-Mode-Required', 'stale')->assertJsonPath('owner_mode.executed', false)->assertJsonPath('message', OwnerMode::FRESH_REFUSAL);
    expect(ClinicPayrollPeriod::query()->withoutGlobalScopes()->find($period->id)->status)->not->toBe(ClinicPayrollPeriod::FINALISED);

    $this->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->post("/clinic/payroll/periods/{$period->id}/finalise")->assertSessionHasNoErrors();
    expect(ClinicPayrollPeriod::query()->withoutGlobalScopes()->find($period->id)->status)->toBe(ClinicPayrollPeriod::FINALISED);
});
