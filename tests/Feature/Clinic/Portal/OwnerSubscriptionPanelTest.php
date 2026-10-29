<?php

use App\ClinicRole;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The sidebar's clinic panel shows the plan to the owner with Owner Mode
 * verified on the server, and to nobody else: the figures are not in anyone
 * else's page at all. They are the entitlement resolver's, for this clinic.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('owner-plan-clinic');
    $this->f->owner->update(['password' => bcrypt('owner-pass')]);
    $this->plan = CompanyPlan::factory()->create(['name' => 'Growth', 'key' => 'growth-test']);
    // The dates given win; every other date is cleared, so only the state under test is in force.
    $this->subscribe = fn (array $dates) => CompanySubscription::factory()->create($dates + [
        'clinic_id' => $this->f->clinic->id, 'company_plan_id' => $this->plan->id,
        'trial_ends_at' => null, 'grace_ends_at' => null, 'ends_at' => null,
    ]);
    $this->props = function ($as): array {
        $props = [];
        $as->get('/clinic/support')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->inOwnerMode = function () {
        ($this->asOwner)()->post('/clinic/owner-mode', ['password' => 'owner-pass'])->assertRedirect();

        return $this;
    };
});

test('the owner in Owner Mode sees the plan, its state, the day it ends and the days left', function () {
    ($this->subscribe)(['ends_at' => CarbonImmutable::parse('2026-11-14 10:00', 'Asia/Kolkata')]);

    $props = ($this->props)(($this->inOwnerMode)());

    expect($props['clinic']['owner_subscription'])->toBe([
        'state' => 'active', 'plan' => 'Growth', 'ends_on' => '2026-11-14', 'days_remaining' => 32,
    ]);
});

test('an owner who does not treat patients is sent the plan from sign-in; a doctor-owner only in Owner Mode', function () {
    ($this->subscribe)(['ends_at' => now()->addMonth()]);

    expect(($this->props)(($this->asOwner)())['clinic']['owner_subscription'])->not->toBeNull();

    // In Doctor Mode the owner works as a doctor, and the plan is not sent.
    $this->f->doctor('Dr. Owner', $this->f->owner);
    expect(($this->props)(($this->asOwner)())['clinic']['owner_subscription'])->toBeNull()
        ->and(($this->props)(($this->inOwnerMode)())['clinic']['owner_subscription'])->not->toBeNull();
});

test('reception and doctors are never sent the plan, and the shared banner data no longer carries its name', function (ClinicRole $role) {
    ($this->subscribe)(['trial_ends_at' => now()->addDays(5)]);
    [$user, $session] = $this->f->memberWithRole($role);

    $props = ($this->props)($this->actingAs($user)->withSession($session));

    expect($props['clinic']['owner_subscription'])->toBeNull()
        ->and($props['subscriptionWindow'])->toBe(['state' => 'trial', 'days_remaining' => 5])
        ->and(json_encode($props))->not->toContain('Growth');
})->with([ClinicRole::Receptionist, ClinicRole::Doctor]);

test('no subscription on record, and a paid term with no end date, are stated as they are', function () {
    expect(($this->props)(($this->inOwnerMode)())['clinic']['owner_subscription'])
        ->toBe(['state' => 'none', 'plan' => null, 'ends_on' => null, 'days_remaining' => null]);

    ($this->subscribe)([]);

    expect(($this->props)(($this->inOwnerMode)())['clinic']['owner_subscription'])
        ->toBe(['state' => 'active', 'plan' => 'Growth', 'ends_on' => null, 'days_remaining' => null]);
});

test('trial and grace end on their own dates, as the resolver decides', function () {
    $subscription = ($this->subscribe)(['trial_ends_at' => CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Kolkata')]);
    expect(($this->props)(($this->inOwnerMode)())['clinic']['owner_subscription'])
        ->toMatchArray(['state' => 'trial', 'ends_on' => '2026-10-18', 'days_remaining' => 5]);

    $subscription->update(['trial_ends_at' => now()->subDay(), 'grace_ends_at' => CarbonImmutable::parse('2026-10-20 09:00', 'Asia/Kolkata')]);
    expect(($this->props)(($this->inOwnerMode)())['clinic']['owner_subscription'])
        ->toMatchArray(['state' => 'grace', 'ends_on' => '2026-10-20', 'days_remaining' => 7]);
});

test('the owner sees their own clinic\'s plan, never another clinic\'s', function () {
    ($this->subscribe)(['ends_at' => now()->addMonth()]);
    $other = ReceptionFixture::make('other-plan-clinic');
    CompanySubscription::factory()->create([
        'clinic_id' => $other->clinic->id,
        'company_plan_id' => CompanyPlan::factory()->create(['name' => 'Elsewhere', 'key' => 'elsewhere-test'])->id,
    ]);

    expect(($this->props)(($this->inOwnerMode)())['clinic']['owner_subscription']['plan'])->toBe('Growth');
});
