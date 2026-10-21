<?php

use App\ClinicRole;
use App\Models\ClinicExpense;
use App\Models\ClinicExpenseCategory;
use App\Services\Business\ClinicExpenses;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\ReceptionFixture;

/**
 * Clinic expenses: recorded, approved by someone allowed to, paid; totals by
 * status and category for a period; recurring costs raised once a period;
 * receipts in private storage; never another clinic's.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    Storage::fake('private');
    Storage::fake('quarantine');
    $this->f = ReceptionFixture::make('expenses');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->category = fn ($fixture = null) => app(ClinicExpenses::class)->categories(($fixture ?? $this->f)->clinic)->firstWhere('name', 'Rent');
    $this->page = function (callable $as, array $query = []): array {
        $props = [];
        $as()->get('/clinic/expenses?'.http_build_query($query))->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    // An accounts clerk: billing staff also allowed to record expenses (not approve them) at this branch.
    $this->clerk = function (): array {
        [$user, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
        setPermissionsTeamId($this->f->branch->id);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'expense.manage', 'guard_name' => 'web']));

        return [$user->fresh(), $session];
    };
});

test('the owner records an expense, it counts at once, and is marked paid', function () {
    ($this->asOwner)()->post('/clinic/expenses', ['title' => 'October rent', 'amount' => 40000, 'spent_on' => '2026-10-01', 'category_id' => ($this->category)()->id])->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();
    expect($expense->status)->toBe('approved');

    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/pay", ['method' => 'bank', 'reference' => 'NEFT-1'])->assertSessionHasNoErrors();

    $summary = ($this->page)($this->asOwner)['summary'];
    expect($expense->fresh()->status)->toBe('paid')
        ->and($summary['spent'])->toEqual(40000)->and($summary['paid'])->toEqual(40000)->and($summary['unpaid'])->toEqual(0)
        ->and($summary['by_category'][0])->toEqual(['name' => 'Rent', 'total' => 40000]);
});

test('an accounts clerk records; only an approver decides; nothing counts until approved', function () {
    [$clerk, $session] = ($this->clerk)();
    $asClerk = fn () => $this->actingAs($clerk)->withSession($session);

    $asClerk()->post('/clinic/expenses', ['title' => 'Gloves', 'amount' => 1200, 'spent_on' => '2026-10-05', 'category_id' => ($this->category)()->id])->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();
    expect($expense->status)->toBe('pending')->and(($this->page)($asClerk)['summary']['spent'])->toEqual(0);

    $asClerk()->post("/clinic/expenses/{$expense->id}/approve")->assertForbidden();
    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/reject", ['reason' => 'Duplicate'])->assertSessionHasNoErrors();
    expect($expense->fresh())->status->toBe('rejected')->rejection_reason->toBe('Duplicate');
    ($this->asOwner)()->post("/clinic/expenses/{$expense->id}/approve")->assertSessionHasErrors('expense');
});

test('front-desk and clinical staff have no expenses', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);

    $this->actingAs($user)->withSession($session)->get('/clinic/expenses')->assertForbidden();
})->with([ClinicRole::Receptionist, ClinicRole::Doctor, ClinicRole::BillingStaff]);

test('another clinic\'s category or expense cannot be used', function () {
    $other = ReceptionFixture::make('expenses-other');
    $theirCategory = ($this->category)($other);
    $theirs = ClinicExpense::query()->withoutGlobalScopes()->create(['clinic_id' => $other->clinic->id, 'category_id' => $theirCategory->id, 'title' => 'Theirs', 'amount' => 5, 'spent_on' => '2026-10-01', 'status' => 'approved']);

    ($this->asOwner)()->post('/clinic/expenses', ['title' => 'X', 'amount' => 10, 'spent_on' => '2026-10-01', 'category_id' => $theirCategory->id])->assertSessionHasErrors('expense');
    ($this->asOwner)()->post("/clinic/expenses/{$theirs->id}/pay", ['method' => 'cash'])->assertNotFound();
    expect(($this->page)($this->asOwner)['expenses']['data'])->toBe([]);
});

test('a recurring expense is raised once per period, for approval, however often the job runs', function () {
    $recurring = app(ClinicExpenses::class)->saveRecurring($this->f->clinic, ['title' => 'Rent', 'amount' => 40000, 'category_id' => ($this->category)()->id, 'frequency' => 'monthly', 'next_due_on' => '2026-09-01'], $this->f->owner);

    app(ClinicExpenses::class)->raiseDue(CarbonImmutable::parse('2026-10-07'));
    app(ClinicExpenses::class)->raiseDue(CarbonImmutable::parse('2026-10-07'));

    $raised = ClinicExpense::query()->withoutGlobalScopes()->where('recurring_expense_id', $recurring->id)->orderBy('spent_on')->get();
    expect($raised->map(fn ($e) => $e->spent_on->toDateString())->all())->toBe(['2026-09-01', '2026-10-01'])
        ->and($raised->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($recurring->fresh()->next_due_on->toDateString())->toBe('2026-11-01');
});

test('a receipt is kept privately and downloads only for this clinic', function () {
    ($this->asOwner)()->post('/clinic/expenses', ['title' => 'Lab bill', 'amount' => 900, 'spent_on' => '2026-10-04', 'category_id' => ($this->category)()->id,
        'receipt' => UploadedFile::fake()->create('lab.pdf', 40, 'application/pdf')])->assertSessionHasNoErrors();
    $expense = ClinicExpense::query()->withoutGlobalScopes()->sole();

    expect($expense->attachment_path)->toStartWith("clinic_{$this->f->clinic->id}/expenses/");
    Storage::disk('private')->assertExists($expense->attachment_path);
    ($this->asOwner)()->get("/clinic/expenses/{$expense->id}/receipt")->assertOk();
    expect(ClinicExpenseCategory::query()->withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe(count(ClinicExpenses::DEFAULT_CATEGORIES));
});
