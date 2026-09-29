<?php

use App\ClinicStatus;
use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommercialRenewalService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 8 — retention is measured or it is unavailable.
 *
 * `CommercialRenewalService` returned a hard-coded 96.5, rendered to users as
 * "97% retained". No invented business metric may remain: the rate is now
 * computed from contracts that actually reached their renewal date, and is
 * null with a stated reason whenever the data cannot answer.
 */
function customerOrg(string $name): Organization
{
    $org = Organization::factory()->create(['name' => $name]);

    Clinic::create([
        'organization_id' => $org->id,
        'name' => $name.' Clinic',
        'slug' => Str::slug($name).'-clinic',
        'status' => ClinicStatus::Active,
    ]);

    return $org;
}

function subscription(Organization $org, array $attributes = []): CompanySubscription
{
    return CompanySubscription::create(array_merge([
        'organization_id' => $org->id,
        'status' => 'active',
        'starts_at' => now()->subYear(),
        'ends_at' => now()->addYear(),
        'max_users' => 5,
    ], $attributes));
}

function retentionMetrics(User $admin): array
{
    return app(CommercialRenewalService::class)->getRenewalsPayload($admin)['metrics'];
}

beforeEach(function () {
    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
});

it('never returns a hard-coded rate', function () {
    $source = file_get_contents(app_path('Services/CommercialRenewalService.php'));

    expect($source)->not->toContain('96.5');
});

it('reports no rate when there is no subscription history', function () {
    $metrics = retentionMetrics($this->admin);

    expect($metrics['retention_rate'])->toBeNull()
        ->and($metrics['retention_available'])->toBeFalse()
        ->and($metrics['retention_sample_size'])->toBe(0)
        ->and($metrics['retention_unavailable_reason'])->toContain('No subscription history');
});

it('reports no rate when no contract has reached its renewal date', function () {
    // Insufficient history: subscriptions exist, but all are still running.
    subscription(customerOrg('Still Running'), ['ends_at' => now()->addMonths(6)]);

    $metrics = retentionMetrics($this->admin);

    expect($metrics['retention_rate'])->toBeNull()
        ->and($metrics['retention_available'])->toBeFalse()
        ->and($metrics['retention_sample_size'])->toBe(0)
        ->and($metrics['retention_unavailable_reason'])->toContain('reached their renewal date');
});

it('computes retention from contracts that came up for renewal', function () {
    // Renewed: term ended, and the account holds a live contract after it.
    $renewed = customerOrg('Renewed Dental');
    subscription($renewed, ['starts_at' => now()->subYears(2), 'ends_at' => now()->subMonths(3), 'status' => 'expired']);
    subscription($renewed, ['starts_at' => now()->subMonths(3), 'ends_at' => now()->addMonths(9)]);

    // Churned: term ended and was cancelled, nothing after it.
    $churned = customerOrg('Churned Dental');
    subscription($churned, [
        'starts_at' => now()->subYears(2),
        'ends_at' => now()->subMonths(2),
        'status' => 'cancelled',
        'canceled_at' => now()->subMonths(2),
    ]);

    $metrics = retentionMetrics($this->admin);

    expect($metrics['retention_sample_size'])->toBe(2)
        ->and($metrics['retention_available'])->toBeTrue()
        ->and($metrics['retention_rate'])->toBe(50.0)
        ->and($metrics['retention_unavailable_reason'])->toBeNull();
});

it('reports full retention when every completed contract renewed', function () {
    $org = customerOrg('Loyal Dental');
    subscription($org, ['starts_at' => now()->subYears(2), 'ends_at' => now()->subMonths(4), 'status' => 'expired']);
    subscription($org, ['starts_at' => now()->subMonths(4), 'ends_at' => now()->addMonths(8)]);

    $metrics = retentionMetrics($this->admin);

    expect($metrics['retention_rate'])->toBe(100.0)
        ->and($metrics['retention_sample_size'])->toBe(1);
});

it('reports zero retention when every completed contract churned', function () {
    $org = customerOrg('Lost Dental');
    subscription($org, [
        'starts_at' => now()->subYears(2), 'ends_at' => now()->subMonth(),
        'status' => 'cancelled', 'canceled_at' => now()->subMonth(),
    ]);

    $metrics = retentionMetrics($this->admin);

    expect($metrics['retention_rate'])->toBe(0.0)
        ->and($metrics['retention_sample_size'])->toBe(1);
});

it('ignores contracts that ended outside the lookback window', function () {
    $org = customerOrg('Ancient Dental');
    subscription($org, [
        'starts_at' => now()->subYears(4), 'ends_at' => now()->subYears(3),
        'status' => 'cancelled', 'canceled_at' => now()->subYears(3),
    ]);

    expect(retentionMetrics($this->admin)['retention_sample_size'])->toBe(0);
});
