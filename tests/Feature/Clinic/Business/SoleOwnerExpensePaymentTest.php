<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicExpense;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\Business\ClinicExpenses;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Tests\Support\ReceptionFixture;

/**
 * The sole owner pays an expense they recorded only through the direct path:
 * asked for explicitly, after deciding it directly themselves, with the
 * password confirmed in the last 5 minutes, and while no one else could pay
 * it. Anyone else's own expense is still paid by someone else. Marking paid
 * records what the payer says happened; it moves no money.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('sole-owner-pay');
    $this->category = app(ClinicExpenses::class)->categories($this->f->clinic)->firstWhere('name', 'Rent');
    $this->ownExpense = fn (array $attributes = []) => ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'category_id' => $this->category->id,
        'title' => 'Dental chair service', 'amount' => 7500, 'spent_on' => '2026-10-21', 'status' => ClinicExpense::PENDING, 'created_by' => $this->f->owner->id, ...$attributes,
    ]);
    $this->asOwner = fn (int $verifiedMinutesAgo = 0) => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session, $verifiedMinutesAgo));
    $this->decideDirectly = fn (ClinicExpense $expense) => ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/approve", ['sole_owner_direct' => true])->assertSessionHasNoErrors();
    $this->payDirectly = fn (ClinicExpense $expense, $as = null) => ($as ?? ($this->asOwner)())->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'bank', 'reference' => 'NEFT-77', 'sole_owner_direct' => true]);
    $this->events = fn (string $event, ClinicExpense $expense) => SecurityAuditLog::query()->where('event', $event)
        ->where('subject_type', 'ClinicExpense')->where('subject_id', $expense->id)->get();
    // A colleague who may record and pay expenses (not approve them) at the main branch.
    $this->payer = function (): array {
        [$user, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
        setPermissionsTeamId($this->f->branch->id);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'expense.manage', 'guard_name' => 'web']));

        return [$user->fresh(), $session];
    };
});

test('the sole owner pays their own directly decided expense, once, and it is audited as the direct path', function () {
    $expense = ($this->ownExpense)();
    ($this->decideDirectly)($expense);

    ($this->payDirectly)($expense)->assertSessionHasNoErrors();

    $paid = ($this->events)('expense.paid_sole_owner_direct', $expense);
    expect($expense->fresh())->status->toBe(ClinicExpense::PAID)->payment_method->toBe('bank')->reference->toBe('NEFT-77')
        ->and((float) $expense->fresh()->amount)->toEqual(7500)
        ->and($paid)->toHaveCount(1)
        ->and($paid->sole()->context)->toMatchArray(['path' => 'sole_owner_direct', 'method' => 'bank', 'amount' => 7500])
        ->and($paid->sole()->actor_user_id)->toBe($this->f->owner->id)
        ->and(($this->events)('expense.paid', $expense))->toHaveCount(0);

    // Sent again: paid once.
    ($this->payDirectly)($expense)->assertSessionHasErrors(['expense' => 'Only an approved expense can be marked paid.']);
    expect(($this->events)('expense.paid_sole_owner_direct', $expense))->toHaveCount(1)
        ->and($expense->fresh()->reference)->toBe('NEFT-77');
});

test('without the explicit flag the owner still cannot pay their own expense', function () {
    $expense = ($this->ownExpense)();
    ($this->decideDirectly)($expense);

    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'bank'])->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.pay_refused', $expense)->sole()->context['reason'])->toBe('recorder');
});

test('direct payment needs Owner Mode with a fresh confirmation', function () {
    $expense = ($this->ownExpense)();
    ($this->decideDirectly)($expense);

    ($this->payDirectly)($expense, ($this->asOwner)(6))->assertForbidden();
    ($this->payDirectly)($expense, $this->actingAs($this->f->owner)->withSession([...$this->f->session, 'clinic_owner_mode' => null]))->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.sole_owner_direct_pay_refused', $expense)->pluck('context.reason')->all())->toBe(['verification_stale', 'verification_stale']);
});

test('direct payment is closed while someone else could pay the expense', function () {
    $expense = ($this->ownExpense)();
    ($this->decideDirectly)($expense);
    [$payer, $payerSession] = ($this->payer)();

    ($this->payDirectly)($expense)->assertForbidden();
    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.sole_owner_direct_pay_refused', $expense)->sole()->context['reason'])->toBe('payer_available');

    // That colleague pays it through the ordinary path, recorded as such.
    $this->actingAs($payer)->withSession($payerSession)->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'cash'])->assertSessionHasNoErrors();

    expect($expense->fresh()->status)->toBe(ClinicExpense::PAID)
        ->and(($this->events)('expense.paid', $expense)->sole()->context['path'])->toBe('payer')
        ->and(($this->events)('expense.paid_sole_owner_direct', $expense))->toHaveCount(0);
});

test('direct payment never approves: a pending expense, or one approved by someone else, is refused', function () {
    $pending = ($this->ownExpense)();
    $someoneElse = User::factory()->create();
    $approvedByOther = ($this->ownExpense)(['status' => ClinicExpense::APPROVED, 'decided_by' => $someoneElse->id, 'decided_at' => now(), 'title' => 'Approved elsewhere']);

    ($this->payDirectly)($pending)->assertForbidden();
    ($this->payDirectly)($approvedByOther)->assertForbidden();

    expect($pending->fresh()->status)->toBe(ClinicExpense::PENDING)
        ->and($pending->fresh()->decided_by)->toBeNull()
        ->and($approvedByOther->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.sole_owner_direct_pay_refused', $pending)->sole()->context['reason'])->toBe('not_directly_approved')
        ->and(($this->events)('expense.sole_owner_direct_pay_refused', $approvedByOther)->sole()->context['reason'])->toBe('not_directly_approved');
});

test('an expense at a branch outside the clinic, or in another clinic, is not paid', function () {
    $other = ReceptionFixture::make('sole-owner-pay-other');
    $foreignBranch = Branch::factory()->create(['clinic_id' => $other->clinic->id, 'status' => BranchStatus::Active]);
    $misplaced = ($this->ownExpense)(['branch_id' => $foreignBranch->id, 'status' => ClinicExpense::APPROVED, 'decided_by' => $this->f->owner->id, 'decided_at' => now()]);
    $theirs = ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $other->clinic->id, 'category_id' => app(ClinicExpenses::class)->categories($other->clinic)->first()->id,
        'title' => 'Theirs', 'amount' => 50, 'spent_on' => '2026-10-21', 'status' => ClinicExpense::APPROVED, 'created_by' => $this->f->owner->id, 'decided_by' => $this->f->owner->id,
    ]);

    ($this->payDirectly)($misplaced)->assertForbidden();
    ($this->payDirectly)($theirs)->assertNotFound();

    expect($misplaced->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and($theirs->fresh()->status)->toBe(ClinicExpense::APPROVED);
});

test('anyone else\'s own expense is still paid by someone else, flag or not', function () {
    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->actingAs($admin)->withSession($session)->post('/clinic/expenses', ['title' => 'Toner', 'amount' => 900, 'spent_on' => '2026-10-21', 'category_id' => $this->category->id])->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();
    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasNoErrors();

    $this->actingAs($admin)->withSession([...$session, 'clinic_owner_mode' => withOwnerMode($this->f->session)['clinic_owner_mode']])
        ->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'cash', 'sole_owner_direct' => true])->assertForbidden();
    $this->actingAs($admin)->withSession($session)->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'cash'])->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.sole_owner_direct_pay_refused', $expense)->sole()->context['reason'])->toBe('not_primary_owner')
        ->and(($this->events)('expense.pay_refused', $expense)->sole()->context['reason'])->toBe('recorder');

    // The owner, who did not record it, pays it the ordinary way.
    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'cash'])->assertSessionHasNoErrors();
    expect($expense->fresh()->status)->toBe(ClinicExpense::PAID)
        ->and(($this->events)('expense.paid', $expense)->sole()->context['path'])->toBe('payer');
});
