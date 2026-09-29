<?php

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\DealsQueryService;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The saved views and the filters the Deals workspace gained.
 *
 * Two rules under test throughout: a view means exactly one thing, and its
 * count comes from the same query that renders it — a chip must never be able
 * to promise rows the list does not then show.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->manager = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->manager->assignRole('CRM Manager');

    $this->deals = app(DealsQueryService::class);
});

function dealFor(Organization $org, array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge(['organization_id' => $org->id], $attributes));
}

it('gives every saved view a count taken from the query that renders it', function () {
    dealFor($this->org, ['status' => LeadStatus::Contacted->value, 'assigned_user_id' => null]);
    dealFor($this->org, ['status' => LeadStatus::Lost->value]);

    $counts = $this->deals->counts($this->manager);

    foreach (DealsQueryService::SAVED_VIEWS as $view) {
        $rendered = $this->deals->query($this->manager, ['view' => $view['key']])->count();

        expect($counts[$view['key']])->toBe($rendered, "count and query disagree for the {$view['key']} view");
    }
});

it('shows a rep only their own deals in the mine view', function () {
    dealFor($this->org, ['assigned_user_id' => $this->manager->id, 'status' => LeadStatus::Contacted->value]);

    /*
     * Explicitly somebody else's. A null owner would be auto-assigned on
     * creation, and half the time that is the manager — which would make this
     * test pass or fail depending on who the rota reached.
     */
    $other = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);

    dealFor($this->org, ['assigned_user_id' => $other->id, 'status' => LeadStatus::Contacted->value]);

    expect($this->deals->query($this->manager, ['view' => 'mine'])->count())->toBe(1);
});

it('treats payment pending as approved-without-a-settled-payment, not a stage name', function () {
    dealFor($this->org, ['status' => LeadStatus::QuotationApproved->value]);
    // Already won, so no longer waiting on anything.
    dealFor($this->org, ['status' => LeadStatus::Won->value]);

    $pending = $this->deals->query($this->manager, ['view' => 'payment_pending'])->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->status->value)->toBe(LeadStatus::QuotationApproved->value);
});

it('finds deals by whether a trial exists rather than by their stage', function () {
    $withTrial = dealFor($this->org, ['status' => LeadStatus::Negotiation->value]);
    Trial::factory()->create([
        'lead_id' => $withTrial->id,
        'status' => TrialStatus::Active,
        'end_date' => now()->addWeek(),
    ]);

    dealFor($this->org, ['status' => LeadStatus::Negotiation->value]);

    expect($this->deals->query($this->manager, ['trial' => 'active'])->count())->toBe(1)
        ->and($this->deals->query($this->manager, ['trial' => 'none'])->count())->toBe(1);
});

it('finds deals by whether a quotation exists rather than by their stage', function () {
    $quoted = dealFor($this->org, ['status' => LeadStatus::Contacted->value]);
    CompanyQuotation::factory()->create([
        'lead_id' => $quoted->id,
        'organization_id' => $this->org->id,
    ]);

    dealFor($this->org, ['status' => LeadStatus::Contacted->value]);

    expect($this->deals->query($this->manager, ['quotation' => 'any'])->count())->toBe(1)
        ->and($this->deals->query($this->manager, ['quotation' => 'none'])->count())->toBe(1);
});

it('bounds deal value from both ends', function () {
    dealFor($this->org, ['status' => LeadStatus::Contacted->value, 'expected_arr' => 50_000]);
    dealFor($this->org, ['status' => LeadStatus::Contacted->value, 'expected_arr' => 5_000_000]);

    expect($this->deals->query($this->manager, ['value_max' => 100_000])->count())->toBe(1)
        ->and($this->deals->query($this->manager, ['value_min' => 100_000])->count())->toBe(1);
});
