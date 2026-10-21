<?php

use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\Services\Provisioning\ClinicSubscriptionSelector;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\ResourceLimitService;
use App\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Which subscription a clinic's access and its seat limits are read from.
 * Access: the most recent subscription, whatever its status, with access
 * decided by its dates (unchanged). Seats: the most recent active one. When
 * they are not the same row, or more than one is active, it is logged.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Kolkata'));
    $this->clinic = Clinic::factory()->create();
    $this->selector = app(ClinicSubscriptionSelector::class);
    $this->subscription = fn (SubscriptionStatus $status, array $attributes = []) => CompanySubscription::create([
        'clinic_id' => $this->clinic->id, 'status' => $status, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear(), ...$attributes,
    ]);
});

test('with one active subscription, access and seats read the same row and nothing is logged', function () {
    Log::spy();
    $only = ($this->subscription)(SubscriptionStatus::Active);

    expect($this->selector->forAccess($this->clinic)->id)->toBe($only->id)
        ->and($this->selector->forSeats($this->clinic)->id)->toBe($only->id)
        ->and(app(ResourceLimitService::class)->getActiveSubscription($this->clinic)->id)->toBe($only->id);
    Log::shouldNotHaveReceived('warning');
});

test('when a newer draft follows the active one, access reads the draft and seats the active one, and the difference is logged', function () {
    Log::spy();
    $active = ($this->subscription)(SubscriptionStatus::Active);
    $draft = ($this->subscription)(SubscriptionStatus::Draft);

    expect($this->selector->forAccess($this->clinic)->id)->toBe($draft->id)
        ->and($this->selector->forSeats($this->clinic)->id)->toBe($active->id);
    Log::shouldHaveReceived('warning')->once()->with(
        'Clinic subscriptions disagree: seats and access are read from different rows, or more than one is active.',
        ['clinic_id' => $this->clinic->id, 'seat_subscription_id' => $active->id, 'access_subscription_id' => $draft->id, 'more_than_one_active' => false],
    );
});

test('with two active subscriptions, seats read the newest and the conflict is logged', function () {
    Log::spy();
    ($this->subscription)(SubscriptionStatus::Active);
    $newer = ($this->subscription)(SubscriptionStatus::Active);

    expect($this->selector->forSeats($this->clinic)->id)->toBe($newer->id)
        ->and($this->selector->forAccess($this->clinic)->id)->toBe($newer->id);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['more_than_one_active'] === true && $context['seat_subscription_id'] === $newer->id);
});

test('a clinic whose only subscription is cancelled has no seat subscription, while access still reads the cancelled row by its dates', function () {
    $cancelled = ($this->subscription)(SubscriptionStatus::Cancelled);

    expect($this->selector->forSeats($this->clinic))->toBeNull()
        ->and($this->selector->forAccess($this->clinic)->id)->toBe($cancelled->id)
        // Unchanged access rule: dates decide, status does not (an open policy decision).
        ->and(app(EntitlementResolver::class)->forClinic($this->clinic)['grants_access'])->toBeTrue();
});

test('a clinic with no subscription has neither, and is not locked out', function () {
    expect($this->selector->forSeats($this->clinic))->toBeNull()
        ->and($this->selector->forAccess($this->clinic))->toBeNull()
        ->and(app(EntitlementResolver::class)->forClinic($this->clinic))->toMatchArray(['has_subscription' => false]);
});

test('the trial, grace and lapsed windows are unchanged', function () {
    $resolver = app(EntitlementResolver::class);
    $sub = ($this->subscription)(SubscriptionStatus::Active, ['trial_ends_at' => now()->addDays(3), 'grace_ends_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)]);

    expect($resolver->forClinic($this->clinic)['window']['state'])->toBe('trial');

    $sub->update(['trial_ends_at' => now()->subDay()]);
    expect($resolver->forClinic($this->clinic))->window->state->toBe('grace')->grants_access->toBeTrue();

    $sub->update(['grace_ends_at' => now()->subDay(), 'ends_at' => now()->subDay()]);
    expect($resolver->forClinic($this->clinic))->window->state->toBe('lapsed')->grants_access->toBeFalse();
});
