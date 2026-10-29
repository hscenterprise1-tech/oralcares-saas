<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicExpense;
use App\Models\ClinicMembership;
use App\Models\SecurityAuditLog;
use App\Services\Business\ClinicExpenses;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Deciding an expense is a second person's decision: whoever records an
 * expense never approves or rejects it, even when they may approve others'.
 * Deciding and paying need the permission at the expense's own branch; a
 * clinic-wide expense needs someone who works across the whole clinic.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('expense-authority');
    $this->branchB = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second Branch', 'status' => BranchStatus::Active]);
    $this->category = app(ClinicExpenses::class)->categories($this->f->clinic)->firstWhere('name', 'Rent');

    // A clinic administrator (records and approves) who may open every branch, with the role only at the main branch.
    [$this->admin, $this->adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->asAdmin = fn () => $this->actingAs($this->admin)->withSession($this->adminSession);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->record = fn ($as, array $extra = []) => $as->post('/clinic/expenses', ['title' => 'Gloves', 'amount' => 1200, 'spent_on' => '2026-10-20', 'category_id' => $this->category->id, ...$extra]);
    $this->expense = fn (array $attributes = []) => ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'category_id' => $this->category->id, 'title' => 'Plumbing', 'amount' => 800,
        'spent_on' => '2026-10-20', 'status' => ClinicExpense::PENDING, 'created_by' => $this->f->owner->id, ...$attributes,
    ]);
    $this->events = fn (string $event, ClinicExpense $expense) => SecurityAuditLog::query()->where('event', $event)
        ->where('subject_type', 'ClinicExpense')->where('subject_id', $expense->id)->count();
});

test('an expense recorded by someone who may approve stays pending, and its recorder cannot decide it', function () {
    ($this->record)(($this->asAdmin)())->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();

    expect($expense->status)->toBe(ClinicExpense::PENDING)
        ->and($expense->decided_by)->toBeNull()->and($expense->decided_at)->toBeNull()
        ->and(SecurityAuditLog::query()->where('event', 'expense.recorded')->sole()->context['status'])->toBe(ClinicExpense::PENDING);

    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/approve")->assertForbidden();
    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/reject", ['reason' => 'Changed my mind'])->assertForbidden();

    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING)
        ->and($expense->fresh()->decided_by)->toBeNull()
        ->and(($this->events)('expense.approved', $expense) + ($this->events)('expense.rejected', $expense))->toBe(0)
        // Each refusal is recorded, and survives the refused decision.
        ->and(($this->events)('expense.self_decision_refused', $expense))->toBe(2);

    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasNoErrors();

    expect($expense->fresh())->status->toBe(ClinicExpense::APPROVED)->decided_by->toBe($this->f->owner->id)
        ->and(($this->events)('expense.approved', $expense))->toBe(1);
});

test('the owner\'s own expense also waits for a different approver', function () {
    ($this->record)(($this->asOwner)())->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();
    expect($expense->status)->toBe(ClinicExpense::PENDING);

    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/approve")->assertForbidden();
    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING);

    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasNoErrors();
    expect($expense->fresh())->status->toBe(ClinicExpense::APPROVED)->decided_by->toBe($this->admin->id);
});

test('a recorder who may not approve stays refused', function () {
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $expense = ($this->expense)();

    $this->actingAs($receptionist)->withSession($session)->post("/clinic/expenses/{$expense->id}/approve")->assertForbidden();
    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING);
});

test('deciding or paying twice changes nothing the second time', function () {
    $expense = ($this->expense)();

    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasNoErrors();
    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasErrors(['expense' => 'This expense has already been decided.']);
    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/reject", ['reason' => 'Too late'])->assertSessionHasErrors('expense');

    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'bank', 'reference' => 'NEFT-1'])->assertSessionHasNoErrors();
    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'cash'])->assertSessionHasErrors(['expense' => 'Only an approved expense can be marked paid.']);

    expect($expense->fresh())->status->toBe(ClinicExpense::PAID)->payment_method->toBe('bank')->reference->toBe('NEFT-1')
        ->and(($this->events)('expense.approved', $expense))->toBe(1)
        ->and(($this->events)('expense.rejected', $expense))->toBe(0)
        ->and(($this->events)('expense.paid', $expense))->toBe(1);
});

test('the permission at branch A does not decide or pay an expense at branch B', function () {
    $pending = ($this->expense)(['branch_id' => $this->branchB->id]);
    $approved = ($this->expense)(['branch_id' => $this->branchB->id, 'status' => ClinicExpense::APPROVED, 'title' => 'Paint']);

    ($this->asAdmin)()->post("/clinic/expenses/{$pending->id}/approve")->assertForbidden();
    ($this->asAdmin)()->post("/clinic/expenses/{$pending->id}/reject", ['reason' => 'Not ours'])->assertForbidden();
    ($this->asAdmin)()->post("/clinic/expenses/{$approved->id}/pay", ['method' => 'cash'])->assertForbidden();

    expect($pending->fresh()->status)->toBe(ClinicExpense::PENDING)
        ->and($approved->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and(($this->events)('expense.approved', $pending) + ($this->events)('expense.paid', $approved))->toBe(0);

    // With the role held at branch B as well, the same administrator decides and pays there.
    app(ClinicRoleAssignmentService::class)->syncRoles($this->admin, [ClinicRole::ClinicAdmin->value], [$this->f->branch, $this->branchB]);

    ($this->asAdmin)()->post("/clinic/expenses/{$pending->id}/approve")->assertSessionHasNoErrors();
    ($this->asAdmin)()->post("/clinic/expenses/{$approved->id}/pay", ['method' => 'cash'])->assertSessionHasNoErrors();

    expect($pending->fresh()->status)->toBe(ClinicExpense::APPROVED)
        ->and($approved->fresh()->status)->toBe(ClinicExpense::PAID);
});

test('a clinic-wide expense is decided only by someone who works across the whole clinic', function () {
    $expense = ($this->expense)();

    // A branch administrator: the role and access at the main branch only.
    [$branchAdmin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $membership = ClinicMembership::query()->withoutGlobalScopes()->findOrFail($session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]);
    $membership->update(['has_all_branches_access' => false]);
    $membership->assignBranch($this->f->branch);

    $this->actingAs($branchAdmin)->withSession($session)->post("/clinic/expenses/{$expense->id}/approve")->assertForbidden();
    expect($expense->fresh()->status)->toBe(ClinicExpense::PENDING);

    ($this->asAdmin)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasNoErrors();
    expect($expense->fresh()->status)->toBe(ClinicExpense::APPROVED);
});

test('another clinic\'s expense is not found to decide or pay', function () {
    $other = ReceptionFixture::make('expense-authority-other');
    $theirs = ClinicExpense::query()->withoutGlobalScopes()->create([
        'clinic_id' => $other->clinic->id, 'category_id' => app(ClinicExpenses::class)->categories($other->clinic)->first()->id,
        'title' => 'Theirs', 'amount' => 5, 'spent_on' => '2026-10-20', 'status' => ClinicExpense::PENDING,
    ]);

    ($this->asOwner)()->post("/clinic/expenses/{$theirs->id}/approve")->assertNotFound();
    ($this->asAdmin)()->post("/clinic/expenses/{$theirs->id}/reject", ['reason' => 'Not ours'])->assertNotFound();
    expect($theirs->fresh()->status)->toBe(ClinicExpense::PENDING);
});
