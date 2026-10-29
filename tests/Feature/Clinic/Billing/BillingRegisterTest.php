<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use App\Support\ClinicFrontend;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The Billing register: Bills & Collections, Refunds & Adjustments and
 * Financial Summaries read the canonical billing tables, filtered and paged
 * on the server, with totals summed from the same filtered rows. A member
 * sees only the branches where they hold the permission; another clinic's
 * bills are never found.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('billing-register');
    $this->east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->bill = fn (string $first, float $price, ?Branch $branch = null) => app(BillingService::class)
        ->createBill($this->f->clinic, $this->f->patient($first, 'Rao'), $branch ?? $this->f->branch, [['description' => 'Visit', 'quantity' => 1, 'unit_price' => $price]]);
    $this->props = function (callable $as, string $url): array {
        $props = [];
        $as()->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };

    $main = ($this->bill)('Anil', 2000);
    app(PaymentService::class)->recordPayment($main->fresh(), PaymentMethod::Cash, 1500);
    app(PaymentService::class)->recordRefund($main->fresh(), 200, 'Overcharged');
    app(PaymentService::class)->recordAdjustment($main->fresh(), 100, 'Senior concession');
    $east = ($this->bill)('Bina', 1000, $this->east);
    app(PaymentService::class)->recordPayment($east->fresh(), PaymentMethod::Upi, 1000);
    $this->main = $main;
    $this->eastBill = $east;
});

test('bill totals are the sums of the rows the filters select', function () {
    $all = ($this->props)($this->asOwner, '/clinic/billing');
    expect($all['bills']['total'])->toBe(2)
        ->and($all['totals'])->toMatchArray(['count' => 2, 'billed' => 3000.0, 'collected' => 2500.0, 'refunded' => 200.0, 'adjusted' => 100.0])
        ->and((float) $all['totals']['outstanding'])->toEqual(collect($all['bills']['data'])->sum('outstanding'));

    $searched = ($this->props)($this->asOwner, '/clinic/billing?q=Bina');
    expect($searched['bills']['total'])->toBe(1)->and($searched['totals']['billed'])->toEqual(1000);

    $byBranch = ($this->props)($this->asOwner, "/clinic/billing?branch={$this->east->id}");
    expect(collect($byBranch['bills']['data'])->pluck('branch')->all())->toBe(['East']);

    $collections = ($this->props)($this->asOwner, '/clinic/billing?view=collections&method=upi');
    expect($collections['collections']['total'])->toBe(1)->and($collections['totals']['collected'])->toEqual(1000);
});

test('a member sees only the branches where they hold billing, and another clinic sees nothing', function () {
    [$clerk, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $asClerk = fn () => $this->actingAs($clerk)->withSession($session);

    $props = ($this->props)($asClerk, '/clinic/billing');
    expect(collect($props['bills']['data'])->pluck('patient')->all())->toBe(['Anil Rao'])
        ->and($props['totals']['billed'])->toEqual(2000);
    // Asking for a branch outside reach does not widen it.
    expect(($this->props)($asClerk, "/clinic/billing?branch={$this->east->id}")['bills']['total'])->toBe(0);

    $other = ReceptionFixture::make('billing-register-other');
    $foreign = ($this->props)(fn () => $this->actingAs($other->owner)->withSession(withOwnerMode($other->session)), '/clinic/billing');
    expect($foreign['bills']['total'])->toBe(0);
    $this->actingAs($other->owner)->withSession($other->session)->getJson("/clinic/billing/bills/{$this->main->id}")->assertNotFound();
});

test('a bill opens in full only at a branch where the member holds billing', function () {
    ($this->asOwner)()->getJson("/clinic/billing/bills/{$this->main->id}")->assertOk()
        ->assertJsonPath('patient.name', 'Anil Rao');

    [$clerk, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->actingAs($clerk)->withSession($session)->getJson("/clinic/billing/bills/{$this->main->id}")->assertOk();
    $this->actingAs($clerk)->withSession($session)->getJson("/clinic/billing/bills/{$this->eastBill->id}")->assertForbidden();
});

test('refunds and adjustments are listed together as recorded, filterable by type', function () {
    $props = ($this->props)($this->asOwner, '/clinic/billing/reversals');
    expect($props['reversals']['total'])->toBe(2)
        ->and($props['totals'])->toMatchArray(['count' => 2, 'refunded' => 200.0, 'adjusted' => 100.0])
        ->and(collect($props['reversals']['data'])->pluck('type')->sort()->values()->all())->toBe(['adjustment', 'refund']);

    expect(($this->props)($this->asOwner, '/clinic/billing/reversals?type=refund')['reversals']['data'][0])
        ->toMatchArray(['type' => 'refund', 'amount' => 200.0, 'reason' => 'Overcharged']);
});

test('the summary uses one date basis per figure and is the owner\'s, not the billing desk\'s', function () {
    $summary = ($this->props)($this->asOwner, '/clinic/billing/summary')['summary'];
    expect($summary['figures'])->toMatchArray([
        'bills' => 2, 'billed' => 3000.0, 'collected' => 2500.0, 'refunded' => 200.0, 'net_collected' => 2300.0, 'adjusted' => 100.0,
    ])->and(collect($summary['by_method'])->sum('total'))->toEqual(2500)
        ->and(collect($summary['by_branch'])->sum('billed'))->toEqual(3000)
        ->and($summary['legacy_invoices'])->toBe(0);

    // Last month had none of it.
    expect(($this->props)($this->asOwner, '/clinic/billing/summary?from=2026-09-01&to=2026-09-30')['summary']['figures']['billed'])->toEqual(0);

    [$clerk, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->actingAs($clerk)->withSession($session)->get('/clinic/billing/summary')->assertForbidden();
    $this->actingAs($clerk)->withSession($session)->get('/clinic/billing/reversals')->assertOk();
});

test('the billing sections are live destinations and show only the tabs a member may open', function () {
    foreach (['clinic.billing.index', 'clinic.billing.reversals', 'clinic.billing.summary', 'clinic.expenses.index'] as $route) {
        expect(ClinicFrontend::available($route))->toBeTrue();
    }

    expect(collect(($this->props)($this->asOwner, '/clinic/billing')['tabs'])->pluck('key')->all())->toBe(['bills', 'reversals', 'expenses', 'summary']);

    [$clerk, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    expect(collect(($this->props)(fn () => $this->actingAs($clerk)->withSession($session), '/clinic/billing')['tabs'])->pluck('key')->all())->toBe(['bills', 'reversals']);
});
