<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicExpense;
use App\Models\Lead;
use App\Models\PatientBillingAdjustment;
use App\Models\PatientBillingPayment;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Business\ClinicExpenses;
use App\Services\ClinicRoleAssignmentService;
use App\Services\PaymentService;
use Tests\Concurrency\Support\ConcurrencyDatabase;
use Tests\Concurrency\Support\Race;
use Tests\Support\ReceptionFixture;
use Tests\TestCase;

/**
 * Financial actions raced on MySQL: separate worker processes, separate
 * connections, real requests through the HTTP kernel. Where the actions meet
 * on a row lock, the parent holds that row until the server shows every
 * worker blocked on it inside its own open transaction, so the transactions
 * provably overlap. Authorization refusals happen before any lock, so those
 * races overlap at the request level only, and are labelled so.
 *
 * Data is committed (no wrapping transaction), behind the isolation guard.
 */
uses(TestCase::class)->group('mysql-concurrency');

beforeEach(function () {
    ConcurrencyDatabase::truncateAll();
    Lead::forgetOrganizationRoles();

    $this->f = ReceptionFixture::make('race-clinic');
    $this->category = app(ClinicExpenses::class)->categories($this->f->clinic)->firstWhere('name', 'Rent');
    $this->expense = fn (array $attributes = []) => ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'category_id' => $this->category->id,
        'title' => 'Compressor repair', 'amount' => 4200, 'spent_on' => now()->toDateString(), 'status' => ClinicExpense::PENDING,
        'created_by' => $this->f->owner->id, ...$attributes,
    ]);
    $this->events = fn (string $event, ClinicExpense $expense) => SecurityAuditLog::query()->where('event', $event)
        ->where('subject_type', 'ClinicExpense')->where('subject_id', $expense->id)->count();
});

/** One worker's request. */
function racer(User $user, array $session, string $uri, array $data = [], bool $json = false): array
{
    return ['user_id' => $user->id, 'session' => $session, 'method' => 'POST', 'uri' => $uri, 'data' => $data, 'json' => $json];
}

/** Every worker on the isolated database, each on its own connection; with a held lock, all of them were blocked on it at once. */
function expectGenuineRace(array $race, bool $lockHeld = true): void
{
    $results = collect($race['results']);
    $ids = $results->pluck('connection.id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($results->pluck('connection.db')->unique()->values()->all())->toBe([ConcurrencyDatabase::DATABASE])
        ->and($results->pluck('connection.account')->unique()->values()->all())->toBe([ConcurrencyDatabase::ACCOUNT])
        ->and(array_unique($ids))->toHaveCount(count($ids));

    if ($lockHeld) {
        expect(collect($race['blocked'])->sort()->values()->all())->toBe($ids);
    }
}

/** The workers that succeeded (a redirect with no errors) and the rest. */
function partition(array $race): array
{
    return collect($race['results'])->partition(fn (array $r) => $r['status'] === 302 && $r['errors'] === [])->map->values()->all();
}

test('the same expense paid twice at once is paid once', function () {
    $expense = ($this->expense)(['status' => ClinicExpense::APPROVED, 'decided_by' => $this->f->owner->id, 'decided_at' => now()]);
    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    $race = Race::run([
        racer($admin, $session, "/clinic/expenses/{$expense->id}/pay", ['method' => 'cash', 'reference' => 'R-0']),
        racer($admin, $session, "/clinic/expenses/{$expense->id}/pay", ['method' => 'cash', 'reference' => 'R-1']),
    ], holdingLock: ['clinic_expenses', $expense->id]);

    expectGenuineRace($race);
    [$won, $lost] = partition($race);
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1)
        ->and($lost[0]['errors']['expense'][0])->toBe('Only an approved expense can be marked paid.')
        ->and($expense->fresh())->status->toBe(ClinicExpense::PAID)->reference->toBe('R-'.$won[0]['worker'])
        ->and(($this->events)('expense.paid', $expense))->toBe(1);
});

test('two sole-owner direct payments at once pay once', function () {
    $expense = ($this->expense)(['status' => ClinicExpense::APPROVED, 'decided_by' => $this->f->owner->id, 'decided_at' => now()]);
    $session = withOwnerMode($this->f->session);

    $race = Race::run([
        racer($this->f->owner, $session, "/clinic/expenses/{$expense->id}/pay", ['method' => 'bank', 'reference' => 'D-0', 'sole_owner_direct' => 1]),
        racer($this->f->owner, $session, "/clinic/expenses/{$expense->id}/pay", ['method' => 'bank', 'reference' => 'D-1', 'sole_owner_direct' => 1]),
    ], holdingLock: ['clinic_expenses', $expense->id]);

    expectGenuineRace($race);
    [$won, $lost] = partition($race);
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1)
        ->and($lost[0]['errors']['expense'][0])->toBe('Only an approved expense can be marked paid.')
        ->and($expense->fresh())->status->toBe(ClinicExpense::PAID)->reference->toBe('D-'.$won[0]['worker'])
        ->and(($this->events)('expense.paid_sole_owner_direct', $expense))->toBe(1)
        ->and(($this->events)('expense.paid', $expense))->toBe(0);
});

test('an approval and a rejection at once leave one decision', function () {
    $expense = ($this->expense)();
    [$approver, $approverSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    [$rejecter, $rejecterSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    $race = Race::run([
        racer($approver, $approverSession, "/clinic/expenses/{$expense->id}/approve"),
        racer($rejecter, $rejecterSession, "/clinic/expenses/{$expense->id}/reject", ['reason' => 'Duplicate bill']),
    ], holdingLock: ['clinic_expenses', $expense->id]);

    expectGenuineRace($race);
    [$won, $lost] = partition($race);
    $winner = $won[0]['worker'] === 0 ? [$approver, ClinicExpense::APPROVED] : [$rejecter, ClinicExpense::REJECTED];
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1)
        ->and($lost[0]['errors']['expense'][0])->toBe('This expense has already been decided.')
        ->and($expense->fresh())->status->toBe($winner[1])->decided_by->toBe($winner[0]->id)
        ->and(($this->events)('expense.approved', $expense) + ($this->events)('expense.rejected', $expense))->toBe(1);
});

test('a rejection and a payment at once never pay a rejected expense', function () {
    $expense = ($this->expense)();
    [$rejecter, $rejecterSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    [$payer, $payerSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    $race = Race::run([
        racer($rejecter, $rejecterSession, "/clinic/expenses/{$expense->id}/reject", ['reason' => 'Not ours']),
        racer($payer, $payerSession, "/clinic/expenses/{$expense->id}/pay", ['method' => 'cash']),
    ], holdingLock: ['clinic_expenses', $expense->id]);

    expectGenuineRace($race);
    $results = collect($race['results'])->keyBy('worker');
    expect($results[0]['errors'])->toBe([])
        ->and($results[1]['errors']['expense'][0])->toBe('Only an approved expense can be marked paid.')
        ->and($expense->fresh()->status)->toBe(ClinicExpense::REJECTED)
        ->and(($this->events)('expense.rejected', $expense))->toBe(1)
        ->and(($this->events)('expense.paid', $expense))->toBe(0);
});

test('two collections at once never take more than is due', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Race', 'Payer'), $this->f->branch, [['description' => 'Root canal', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 500);
    [$first, $firstSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    [$second, $secondSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    $race = Race::run([
        racer($first, $firstSession, "/clinic/billing/bills/{$bill->id}/payments", ['method' => 'cash', 'amount' => 400]),
        racer($second, $secondSession, "/clinic/billing/bills/{$bill->id}/payments", ['method' => 'cash', 'amount' => 400]),
    ], holdingLock: ['patient_billings', $bill->id]);

    expectGenuineRace($race);
    [$won, $lost] = partition($race);
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1)
        ->and($lost[0]['errors']['checkout'][0])->toBe('The payment is more than the amount due.')
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->where('patient_billing_id', $bill->id)->count())->toBe(2)
        ->and((float) $bill->fresh()->outstanding_balance)->toEqual(100)
        ->and((float) $bill->fresh()->paid_amount)->toEqual(900);
});

test('two adjustments at once never write off more than is due', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Race', 'Adjust'), $this->f->branch, [['description' => 'Bridge', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 500);
    $session = withOwnerMode($this->f->session);

    $race = Race::run([
        racer($this->f->owner, $session, "/clinic/billing/bills/{$bill->id}/adjustments", ['amount' => 300, 'reason' => 'Concession A', 'idempotency_key' => 'race-adjust-0001']),
        racer($this->f->owner, $session, "/clinic/billing/bills/{$bill->id}/adjustments", ['amount' => 300, 'reason' => 'Concession B', 'idempotency_key' => 'race-adjust-0002']),
    ], holdingLock: ['patient_billings', $bill->id]);

    expectGenuineRace($race);
    [$won, $lost] = partition($race);
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1)
        ->and($lost[0]['errors']['billing'][0])->toBe('The adjustment is more than the amount due.')
        ->and(PatientBillingAdjustment::query()->withoutGlobalScopes()->where('patient_billing_id', $bill->id)->count())->toBe(1)
        ->and((float) $bill->fresh()->outstanding_balance)->toEqual(200);
});

test('concurrent requests do not get past branch or clinic authorization (request-level overlap)', function () {
    $branchB = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second Branch', 'status' => BranchStatus::Active]);
    $billB = app(BillingService::class)->createBill($this->f->clinic, $this->f->patient('Bee', 'Branch'), $branchB, [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 2000]]);
    $paymentB = app(PaymentService::class)->recordPayment($billB->fresh(), PaymentMethod::Cash, 1500);
    $expense = ($this->expense)(['status' => ClinicExpense::APPROVED, 'decided_by' => $this->f->owner->id, 'decided_at' => now()]);

    // The role at the main branch only (yet able to open branch B); the role at branch B too; another clinic's owner, in Owner Mode there.
    [$branchA, $branchASession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    [$bothBranches, $bothSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    app(ClinicRoleAssignmentService::class)->syncRoles($bothBranches, [ClinicRole::ClinicAdmin->value], [$this->f->branch, $branchB]);
    $other = ReceptionFixture::make('race-other-clinic');

    $race = Race::run([
        racer($branchA, $branchASession, "/clinic/billing/bills/{$billB->id}/payments", ['method' => 'cash', 'amount' => 100]),
        racer($branchA, $branchASession, "/clinic/billing/bills/{$billB->id}/payments", ['method' => 'cash', 'amount' => 100]),
        racer($branchA, $branchASession, "/clinic/billing/payments/{$paymentB->id}/messages/retry", json: true),
        racer($branchA, $branchASession, "/clinic/billing/payments/{$paymentB->id}/messages/retry", json: true),
        racer($other->owner, withOwnerMode($other->session), "/clinic/expenses/{$expense->id}/pay", ['method' => 'cash']),
        racer($bothBranches, $bothSession, "/clinic/billing/bills/{$billB->id}/payments", ['method' => 'cash', 'amount' => 100]),
    ]);

    expectGenuineRace($race, lockHeld: false);
    $status = collect($race['results'])->keyBy('worker')->map->status->all();
    expect($status)->toBe([0 => 403, 1 => 403, 2 => 403, 3 => 403, 4 => 404, 5 => 302])
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->where('patient_billing_id', $billB->id)->count())->toBe(2)
        ->and((float) $billB->fresh()->outstanding_balance)->toEqual(400)
        ->and($expense->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.paid', $expense))->toBe(0);
});
