<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\CompanyPortalRolesSeeder;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Changing a customer's contract, from the module that owns the customer.
 *
 * Customer Success could read a subscription and could not touch one: every
 * upgrade, seat change, renewal and cancellation was routed into the CRM as a
 * *deal* — the pipeline built for winning strangers — or done by hand in the
 * database. These pin the four verbs, the audit trail behind them, and the two
 * rules that make them safe: money is stated rather than inferred, and a
 * contract is reachable only through a customer this reader may change.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);
    $this->seed(CompanyPortalRolesSeeder::class);

    $this->vendorOrg = Organization::factory()->provider()->create(['name' => 'OralCares HQ']);

    $this->manager = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->manager->assignRole(Role::findByName('Customer Success Manager', 'web'));

    $this->executive = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->executive->assignRole(Role::findByName('Customer Success Executive', 'web'));

    $this->starter = CompanyPlan::factory()->create(['name' => 'Starter']);
    $this->growth = CompanyPlan::factory()->create(['name' => 'Growth']);

    $this->account = Organization::factory()->customer()->create([
        'name' => 'Northgate Dental',
        'assigned_user_id' => $this->manager->id,
    ]);

    $clinic = Clinic::factory()->create(['organization_id' => $this->account->id]);

    $this->subscription = CompanySubscription::create([
        'organization_id' => $this->account->id,
        'clinic_id' => $clinic->id,
        'company_plan_id' => $this->starter->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(11),
        'ends_at' => now()->addMonth(),
        'mrr' => 10000,
        'max_users' => 10,
        'max_branches' => 2,
    ]);
});

/* ─────────────────────────────── change plan ──────────────────────────────── */

it('moves a customer onto a different plan and records who did it', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.change-plan', $this->subscription), [
            'company_plan_id' => $this->growth->id,
            'mrr' => 25000,
            'max_users' => 30,
            'reason' => 'Agreed at the quarterly review.',
        ])
        ->assertSessionHasNoErrors();

    $this->subscription->refresh();

    expect($this->subscription->company_plan_id)->toBe($this->growth->id)
        ->and((float) $this->subscription->mrr)->toBe(25000.0)
        ->and($this->subscription->max_users)->toBe(30);

    $event = SubscriptionEvent::where('company_subscription_id', $this->subscription->id)->latest('id')->first();

    expect($event->event)->toBe('plan_changed')
        ->and($event->from_plan_id)->toBe($this->starter->id)
        ->and($event->to_plan_id)->toBe($this->growth->id)
        ->and($event->actor_id)->toBe($this->manager->id)
        ->and($event->reason)->toBe('Agreed at the quarterly review.')
        ->and($event->changes)->toHaveKey('mrr');
});

it('never adopts the new plan price on its own', function () {
    /*
     * A plan change that silently took the new plan's list price would rewrite
     * what this customer agreed to pay. Money is stated or it does not move.
     */
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.change-plan', $this->subscription), [
            'company_plan_id' => $this->growth->id,
        ])
        ->assertSessionHasNoErrors();

    expect((float) $this->subscription->fresh()->mrr)->toBe(10000.0);
});

it('refuses a plan change that changes nothing', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.change-plan', $this->subscription), [
            'company_plan_id' => $this->starter->id,
        ])
        ->assertSessionHasErrors('subscription');
});

/* ───────────────────────────── adjust entitlements ────────────────────────── */

it('adjusts seats without moving the customer off their plan', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.entitlements', $this->subscription), [
            'max_users' => 25,
            'reason' => 'Two new associates.',
        ])
        ->assertSessionHasNoErrors();

    $this->subscription->refresh();

    expect($this->subscription->max_users)->toBe(25)
        ->and($this->subscription->company_plan_id)->toBe($this->starter->id);

    $event = SubscriptionEvent::latest('id')->first();

    expect($event->event)->toBe('entitlements_changed')
        ->and($event->changes['max_users'])->toBe(['from' => 10, 'to' => 25]);
});

it('refuses an adjustment that names no limit', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.entitlements', $this->subscription), [
            'reason' => 'Just checking.',
        ])
        ->assertSessionHasErrors('subscription');
});

/* ──────────────────────────────── renewal ─────────────────────────────────── */

it('renews from where the term ended, not from today', function () {
    /*
     * A renewal processed three days late must not quietly give away three
     * days, and one processed early must not overlap.
     */
    $originalEnd = $this->subscription->ends_at->copy();

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), ['months' => 12])
        ->assertSessionHasNoErrors();

    expect($this->subscription->fresh()->ends_at->toDateString())
        ->toBe($originalEnd->addMonths(12)->toDateString());
});

it('renews a lapsed contract from today, because there is no term to extend', function () {
    $this->subscription->update(['ends_at' => now()->subMonths(2)]);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), ['months' => 12])
        ->assertSessionHasNoErrors();

    expect($this->subscription->fresh()->ends_at->toDateString())
        ->toBe(now()->addMonths(12)->toDateString());
});

it('brings a cancelled contract back when it is renewed', function () {
    $this->subscription->update([
        'canceled_at' => now()->subDay(),
        'status' => SubscriptionStatus::Cancelled,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), ['months' => 12])
        ->assertSessionHasNoErrors();

    $this->subscription->refresh();

    expect($this->subscription->canceled_at)->toBeNull()
        ->and($this->subscription->status)->toBe(SubscriptionStatus::Active);
});

it('records the renewal on the contract history', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), [
            'months' => 24,
            'reason' => 'Two-year deal agreed.',
        ])
        ->assertSessionHasNoErrors();

    $event = SubscriptionEvent::latest('id')->first();

    expect($event->event)->toBe('renewed')
        ->and($event->changes['months_added'])->toBe(24)
        ->and($event->reason)->toBe('Two-year deal agreed.');
});

it('can renew onto a different plan in one step', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), [
            'months' => 12,
            'company_plan_id' => $this->growth->id,
            'mrr' => 22000,
        ])
        ->assertSessionHasNoErrors();

    $this->subscription->refresh();

    expect($this->subscription->company_plan_id)->toBe($this->growth->id)
        ->and((float) $this->subscription->mrr)->toBe(22000.0);
});

/* ─────────────────────────────── cancellation ─────────────────────────────── */

it('cancels with a reason and leaves the paid term alone', function () {
    $paidThrough = $this->subscription->ends_at->copy();

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.cancel', $this->subscription), [
            'reason' => 'Practice sold and merging into another group.',
        ])
        ->assertSessionHasNoErrors();

    $this->subscription->refresh();

    expect($this->subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->subscription->canceled_at)->not->toBeNull()
        /* They paid through this date and keep their access until it. */
        ->and($this->subscription->ends_at->toDateString())->toBe($paidThrough->toDateString());

    expect(SubscriptionEvent::latest('id')->first()->reason)
        ->toBe('Practice sold and merging into another group.');
});

it('refuses a cancellation that says nothing', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.cancel', $this->subscription), ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

/* ───────────────────────────── who may do this ────────────────────────────── */

it('refuses every action to a reader who may only read', function () {
    foreach (['change-plan', 'entitlements', 'renew', 'cancel'] as $action) {
        $this->actingAs($this->executive)
            ->post(route("company.customer-success.subscriptions.{$action}", $this->subscription), [
                'company_plan_id' => $this->growth->id,
                'max_users' => 99,
                'months' => 12,
                'reason' => 'Nope.',
            ])
            ->assertForbidden();
    }

    expect($this->subscription->fresh()->company_plan_id)->toBe($this->starter->id);
});

it('hides a contract belonging to an account outside the reader scope', function () {
    /*
     * Route-model binding hands over any subscription whose id is in the URL.
     * Authorising against the owning organisation is what keeps one tenant's
     * book out of another's reach.
     */
    $foreignVendor = Organization::factory()->provider()->create(['name' => 'Other HQ']);
    $foreignManager = User::factory()->create([
        'organization_id' => $foreignVendor->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $foreignManager->assignRole(Role::findByName('Customer Success Manager', 'web'));

    $this->actingAs($foreignManager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), ['months' => 12])
        ->assertNotFound();
});

/* ─────────────────────────── the projection follows ───────────────────────── */

it('refreshes the account projection so the lists agree immediately', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.entitlements', $this->subscription), ['max_users' => 40])
        ->assertSessionHasNoErrors();

    expect($this->account->fresh()->snapshot->purchased_seats)->toBe(40);
});

it('puts every contract change on the account timeline', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $this->subscription), ['months' => 12])
        ->assertSessionHasNoErrors();

    expect($this->account->activities()->latest('id')->first()->description)
        ->toContain('Renewed for 12 months');
});

/* ──────────────────────────────── bulk renew ──────────────────────────────── */

/**
 * A helper for the bulk tests: another customer on its own contract.
 */
function anotherContract(User $owner, CompanyPlan $plan, array $overrides = []): CompanySubscription
{
    $account = Organization::factory()->customer()->create(array_merge([
        'assigned_user_id' => $owner->id,
    ], $overrides['organization'] ?? []));

    return CompanySubscription::create(array_merge([
        'organization_id' => $account->id,
        'clinic_id' => Clinic::factory()->create(['organization_id' => $account->id])->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(11),
        'ends_at' => now()->addMonth(),
        'mrr' => 8000,
        'max_users' => 5,
    ], $overrides['subscription'] ?? []));
}

it('renews a selection of contracts in one action', function () {
    $second = anotherContract($this->manager, $this->starter);
    $third = anotherContract($this->manager, $this->starter);

    $endings = [
        $this->subscription->id => $this->subscription->ends_at->copy(),
        $second->id => $second->ends_at->copy(),
        $third->id => $third->ends_at->copy(),
    ];

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => [$this->subscription->id, $second->id, $third->id],
            'months' => 12,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '3 contracts renewed'));

    foreach ([$this->subscription, $second, $third] as $subscription) {
        expect($subscription->fresh()->ends_at->toDateString())
            ->toBe($endings[$subscription->id]->addMonths(12)->toDateString());
    }
});

it('extends the term and touches nothing else', function () {
    /*
     * The whole point of keeping this verb narrow. Plan and price differ per
     * customer, so a bulk form that can move them is a bulk form that
     * eventually reprices a hundred contracts by accident.
     */
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => [$this->subscription->id],
            'months' => 6,
        ])
        ->assertRedirect();

    $fresh = $this->subscription->fresh();

    expect((float) $fresh->mrr)->toBe(10000.0)
        ->and($fresh->company_plan_id)->toBe($this->starter->id)
        ->and($fresh->max_users)->toBe(10);
});

it('writes one contract event per renewal', function () {
    $second = anotherContract($this->manager, $this->starter);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => [$this->subscription->id, $second->id],
            'months' => 12,
            'reason' => 'Annual batch, agreed by the group.',
        ])
        ->assertRedirect();

    foreach ([$this->subscription, $second] as $subscription) {
        $event = $subscription->events()->latest('id')->first();

        expect($event)->not->toBeNull()
            ->and($event->event)->toBe('renewed')
            ->and($event->reason)->toBe('Annual batch, agreed by the group.')
            ->and($event->actor_id)->toBe($this->manager->id);
    }
});

it('silently ignores a posted contract outside the reader book', function () {
    /*
     * The ids arrive as numbers from a browser. The only thing that makes them
     * safe is asking the database which of them this reader may reach — the
     * same rule the single-contract verbs apply through `assertReachable`.
     */
    $stranger = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $outside = anotherContract($stranger, $this->starter);
    $outsideEndsAt = $outside->ends_at->copy();

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => [$this->subscription->id, $outside->id],
            'months' => 12,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 contract renewed'));

    expect($outside->fresh()->ends_at->toDateString())->toBe($outsideEndsAt->toDateString());
});

it('refuses a bulk renewal from a reader who may only read', function () {
    $this->actingAs($this->executive)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => [$this->subscription->id],
            'months' => 12,
        ])
        ->assertForbidden();

    expect($this->subscription->fresh()->ends_at->toDateString())
        ->toBe(now()->addMonth()->toDateString());
});

it('bounds how many contracts one call may renew', function () {
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => range(1, 201),
            'months' => 12,
        ])
        ->assertSessionHasErrors('ids');
});

it('says so rather than reporting success when nothing was renewable', function () {
    $stranger = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.bulk-renew'), [
            'ids' => [anotherContract($stranger, $this->starter)->id],
            'months' => 12,
        ])
        ->assertSessionHasErrors('ids');
});
