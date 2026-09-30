<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommercialAccountHealthService;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\Services\CustomerSuccess\ChurnRiskService;
use App\SubscriptionStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Churn risk, derived and overridable.
 *
 * The column has always existed and nothing ever wrote it but a person typing
 * into a form, so at any real number of accounts every customer kept the "Low"
 * it was created with. These pin both halves of the replacement: the signals
 * that raise a risk, and the rule that a human's judgement outranks them.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->service = app(AccountSnapshotService::class);
    $this->plan = CompanyPlan::factory()->create(['name' => 'Derivation Plan']);
});

/**
 * A customer with a live contract, full adoption, completed onboarding and
 * recent contact — the shape nothing should flag.
 */
function healthyAccount(CompanyPlan $plan, array $overrides = []): Organization
{
    $org = Organization::factory()->customer()->create($overrides);

    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    Branch::factory()->create(['organization_id' => $org->id, 'clinic_id' => $clinic->id]);

    User::factory()->count(9)->create([
        'organization_id' => $org->id,
        'user_type' => UserType::ClinicUser,
    ]);

    CompanySubscription::create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(6),
        'ends_at' => now()->addMonths(6),
        'mrr' => 20000,
        'max_users' => 10,
        'max_branches' => 3,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'go_live',
        'status' => 'completed',
        'completed_steps' => ['go_live'],
    ]);

    $org->activities()->create([
        'type' => 'note',
        'description' => 'Quarterly review held.',
        'created_at' => now()->subDays(3),
    ]);

    return $org->fresh();
}

/* ─────────────────────────────── the signals ──────────────────────────────── */

it('leaves a healthy account alone', function () {
    $org = healthyAccount($this->plan);

    $snapshot = $this->service->refresh($org);

    expect($snapshot->churn_risk)->toBe(CustomerChurnRisk::Low)
        ->and($snapshot->churn_reasons)->toBe([]);
});

it('raises the risk when a contract has lapsed', function () {
    $org = healthyAccount($this->plan);
    $org->subscriptions()->update([
        'ends_at' => now()->subDay(),
        'trial_ends_at' => now()->subMonths(2),
    ]);

    $snapshot = $this->service->refresh($org->fresh());

    expect($snapshot->churn_risk)->toBe(CustomerChurnRisk::High)
        ->and($snapshot->churn_reasons)->toContain('Subscription has lapsed');
});

it('notices an account that bought seats and never used them', function () {
    $org = healthyAccount($this->plan);
    /* Nine of ten seats used becomes one of ten. */
    User::where('organization_id', $org->id)
        ->where('user_type', UserType::ClinicUser)
        ->limit(8)
        ->delete();

    $snapshot = $this->service->refresh($org->fresh());

    expect($snapshot->churn_risk)->toBe(CustomerChurnRisk::Medium)
        ->and($snapshot->churn_reasons)->toContain('Only 10% of purchased seats are in use');
});

it('notices an account nobody has spoken to', function () {
    $org = healthyAccount($this->plan);
    $org->activities()->update(['created_at' => now()->subDays(ChurnRiskService::SILENCE_DAYS + 5)]);

    $snapshot = $this->service->refresh($org->fresh());

    expect($snapshot->churn_risk)->toBe(CustomerChurnRisk::Medium)
        ->and(implode(' ', $snapshot->churn_reasons))->toContain('No recorded contact');
});

it('notices an account that never finished onboarding', function () {
    $org = healthyAccount($this->plan);
    OnboardingProgress::where('organization_id', $org->id)
        ->update(['status' => 'in_progress', 'current_step' => 'branch_setup']);

    $snapshot = $this->service->refresh($org->fresh());

    expect($snapshot->churn_reasons)->toContain('Onboarding never completed');
});

it('adds signals up rather than letting one decide', function () {
    /*
     * One bad reading is a question; three are an answer. Silence plus low
     * adoption plus unfinished onboarding reaches High without any of them
     * being High on its own.
     */
    $org = healthyAccount($this->plan);
    $org->activities()->update(['created_at' => now()->subDays(90)]);
    User::where('organization_id', $org->id)->where('user_type', UserType::ClinicUser)->limit(8)->delete();
    OnboardingProgress::where('organization_id', $org->id)->update(['status' => 'in_progress']);

    $snapshot = $this->service->refresh($org->fresh());

    expect($snapshot->churn_risk)->toBe(CustomerChurnRisk::High)
        ->and($snapshot->churn_reasons)->toHaveCount(3);
});

/* ────────────────────────────── the override ──────────────────────────────── */

it('never overwrites a risk a person set by hand', function () {
    $org = healthyAccount($this->plan, [
        'churn_risk' => CustomerChurnRisk::High,
        'churn_risk_overridden_at' => now(),
    ]);

    $snapshot = $this->service->refresh($org);

    expect($snapshot->churn_risk)->toBe(CustomerChurnRisk::High)
        ->and($snapshot->churn_reasons)->toBe(['Set by hand'])
        ->and($org->fresh()->churn_risk)->toBe(CustomerChurnRisk::High);
});

it('writes the derived risk back to the account, not only the projection', function () {
    /*
     * Everything outside this module — the CRM deal payload, the audit trail,
     * the renewals queue's "at risk" count — reads `organizations.churn_risk`.
     * A projection the rest of the product cannot see is not an answer.
     */
    $org = healthyAccount($this->plan, ['churn_risk' => CustomerChurnRisk::High]);
    expect($org->churn_risk)->toBe(CustomerChurnRisk::High);

    $this->service->refresh($org);

    expect($org->fresh()->churn_risk)->toBe(CustomerChurnRisk::Low);
});

it('measures health against the risk it just settled', function () {
    /*
     * The fifth health dimension is a churn-risk inversion, so health is a
     * function of churn. Deriving churn afterwards left the stored score
     * describing the previous risk — the snapshot and a recomputation of the
     * same account disagreed by five points, and neither was wrong alone.
     */
    $org = healthyAccount($this->plan, ['churn_risk' => CustomerChurnRisk::High]);

    $snapshot = $this->service->refresh($org);

    $recomputed = app(CommercialAccountHealthService::class)
        ->calculateHealthScore($org->fresh());

    expect($snapshot->health_score)->toBe($recomputed['total_score']);
});

/* ──────────────────────────── the human's decision ────────────────────────── */

it('pins health and churn when a rep sets them, and says so on the timeline', function () {
    $org = healthyAccount($this->plan);
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->put(route('company.customer-success.accounts.update', $org), [
            'health_score' => 41,
            'churn_risk' => 'high',
        ])
        ->assertSessionHasNoErrors();

    $org->refresh();

    expect($org->health_score)->toBe(41)
        ->and($org->churn_risk)->toBe(CustomerChurnRisk::High)
        ->and($org->health_score_overridden_at)->not->toBeNull()
        ->and($org->churn_risk_overridden_at)->not->toBeNull()
        ->and($org->metrics_overridden_by)->toBe($admin->id);

    /* And the projection honours it rather than deriving over the top. */
    expect($org->snapshot->health_score)->toBe(41)
        ->and($org->snapshot->churn_risk)->toBe(CustomerChurnRisk::High);

    expect($org->activities()->latest('id')->first()->description)
        ->toContain('health score pinned to 41')
        ->toContain('churn risk pinned to High');
});

it('hands an account back to the derivation when the override is cleared', function () {
    $org = healthyAccount($this->plan, [
        'health_score' => 41,
        'health_score_overridden_at' => now(),
        'churn_risk' => CustomerChurnRisk::High,
        'churn_risk_overridden_at' => now(),
    ]);
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->put(route('company.customer-success.accounts.update', $org), [
            'clear_health_override' => true,
            'clear_churn_override' => true,
        ])
        ->assertSessionHasNoErrors();

    $org->refresh();

    expect($org->health_score_overridden_at)->toBeNull()
        ->and($org->churn_risk_overridden_at)->toBeNull()
        ->and($org->churn_risk)->toBe(CustomerChurnRisk::Low)
        ->and($org->snapshot->health_score)->toBeGreaterThan(41);
});

it('accepts the lowercase churn vocabulary every caller actually sends', function () {
    /*
     * The enum is capitalised and every caller — this module's form, the
     * seeders, the CRM payload — sends lowercase. An enum rule alone rejected
     * the whole request and redirected back, which reads as a save that did
     * nothing.
     */
    $org = healthyAccount($this->plan);
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->put(route('company.customer-success.accounts.update', $org), ['churn_risk' => 'medium'])
        ->assertSessionHasNoErrors();

    expect($org->fresh()->churn_risk)->toBe(CustomerChurnRisk::Medium);
});
