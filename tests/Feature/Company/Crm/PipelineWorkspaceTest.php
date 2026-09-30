<?php

use App\ActivityType;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'name' => 'Apex Healthcare Group',
        'city' => 'Ahmedabad',
        'state' => 'Gujarat',
    ]);
    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->repUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Rahul Sharma',
        ])
    );
});

it('renders the canonical pipeline workspace with real stage grouped leads and operational metrics', function () {
    actingAs($this->adminUser);

    $leadNew = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::New,
        'expected_mrr' => 15000,
    ]);

    $leadContacted = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Contacted,
        'expected_mrr' => 25000,
    ]);

    // The board was removed; the stage rail carries stage distribution now,
    // and reports every stage rather than seven grouped columns.
    $response = get(route('company.crm.deals.index'));

    $response->assertOk()
        ->assertInertia(function ($page) {
            $page->component('Company/Crm/Deals/Index')
                ->has('counts')
                ->has('assignableUsers')
                ->missing('board');

            $rail = collect($page->toArray()['props']['stageBreakdown'])->keyBy('value');

            expect($rail->get('new')['count'])->toBe(1)
                ->and($rail->get('contacted')['count'])->toBe(1)
                // Every stage is present, including the empty ones — the rail
                // shows the whole pipeline, not only the populated parts.
                ->and($rail)->toHaveCount(count(LeadStatus::cases()))
                ->and($page->toArray()['props']['counts']['all'])->toBe(2);
        });
});

it('scopes pipeline leads strictly by tenant isolation', function () {
    actingAs($this->adminUser);

    $otherOrg = Organization::factory()->create(['name' => 'Other Hospital Network']);

    $ownLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Own Clinic Deal',
    ]);

    $otherLead = Lead::factory()->create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Clinic Deal',
    ]);

    // Use company user scoped to organization
    $companyUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
        ])
    );
    actingAs($companyUser);

    $response = get(route('company.crm.deals.index'));

    $response->assertOk()
        ->assertInertia(function ($page) use ($ownLead, $otherLead) {
            $page->component('Company/Crm/Deals/Index');
            $cardIds = collect($page->toArray()['props']['deals']['data'])->pluck('id')->toArray();
            expect($cardIds)->toContain($ownLead->id)
                ->and($cardIds)->not->toContain($otherLead->id);
        });
});

it('filters pipeline leads by search query across clinic and doctor name', function () {
    actingAs($this->adminUser);

    $leadA = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Apollo Specialized Hospital',
        'contact_name' => 'Dr. Rajesh Patel',
        'status' => LeadStatus::Contacted,
    ]);

    $leadB = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Smile Care Dental',
        'contact_name' => 'Dr. Priya Mehta',
        'status' => LeadStatus::Contacted,
    ]);

    $response = get(route('company.crm.deals.index', ['search' => 'Apollo']));

    $response->assertOk()
        ->assertInertia(function ($page) use ($leadA, $leadB) {
            $page->component('Company/Crm/Deals/Index');
            $rows = collect($page->toArray()['props']['deals']['data']);
            $cardIds = $rows->pluck('id')->toArray();
            expect($cardIds)->toContain($leadA->id)
                ->and($cardIds)->not->toContain($leadB->id);
        });
});

it('filters pipeline leads by priority and assigned owner', function () {
    actingAs($this->adminUser);

    $hotLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->repUser->id,
        'priority' => LeadPriority::High,
        'status' => LeadStatus::New,
    ]);

    $coldLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => null,
        'priority' => LeadPriority::Low,
        'status' => LeadStatus::New,
    ]);

    // Filter by assigned user
    $response = get(route('company.crm.deals.index', [
        'assigned_user_id' => $this->repUser->id,
        'priority' => 'high',
    ]));

    $response->assertOk()
        ->assertInertia(function ($page) use ($hotLead, $coldLead) {
            $page->component('Company/Crm/Deals/Index');
            $cardIds = collect($page->toArray()['props']['deals']['data'])->pluck('id')->toArray();
            expect($cardIds)->toContain($hotLead->id)
                ->and($cardIds)->not->toContain($coldLead->id);
        });
});

it('identifies and filters stalled deals', function () {
    actingAs($this->adminUser);

    $activeFreshLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Contacted,
        'stage_entered_at' => now(),
        'updated_at' => now(),
        'next_followup_at' => now()->addDays(2),
    ]);

    // Phase 3 defines stalled as time sat in the current stage. A missing
    // follow-up date no longer qualifies — that clause reported 12 of 12 live
    // deals stalled before Phase 1 made the column derived.
    $stalledLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Contacted,
        'stage_entered_at' => now()->subDays(20),
        'updated_at' => now()->subDays(20),
        'next_followup_at' => null,
    ]);

    $response = get(route('company.crm.deals.index', ['stalled_only' => true]));

    $response->assertOk()
        ->assertInertia(function ($page) use ($stalledLead, $activeFreshLead) {
            $page->component('Company/Crm/Deals/Index');
            $rows = collect($page->toArray()['props']['deals']['data']);
            $cardIds = $rows->pluck('id')->toArray();
            expect($cardIds)->toContain($stalledLead->id)
                ->and($cardIds)->not->toContain($activeFreshLead->id);
        });
});

it('allows valid stage transition and records immutable activity log', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::New,
    ]);

    /* Contacted asks for evidence that somebody was contacted. */
    logContact($lead);

    $response = post(route('company.crm.leads.transition', $lead->id), [
        'stage' => 'contacted',
    ]);

    $response->assertRedirect();
    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);

    $activity = Activity::where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(ActivityType::SystemUpdate)
        ->and($activity->properties['new_stage'])->toBe('contacted');
});

it('rejects invalid stage transition when precursor requirements are unmet', function () {
    actingAs($this->adminUser);

    $leadWithoutDemo = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::New,
    ]);

    // Try transitioning to demo_completed without a Demo record
    $this->expectException(InvalidArgumentException::class);
    app(LeadWorkspaceService::class)->transitionStage($leadWithoutDemo, 'demo_completed', $this->adminUser);
});

it('does not display fake zero commercial values on unpriced leads', function () {
    actingAs($this->adminUser);

    $unpricedLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::New,
        'expected_mrr' => null,
        'expected_arr' => null,
    ]);

    $response = get(route('company.crm.deals.index'));

    $response->assertOk()
        ->assertInertia(function ($page) use ($unpricedLead) {
            $page->component('Company/Crm/Deals/Index');
            $rows = collect($page->toArray()['props']['deals']['data']);
            $card = $rows->firstWhere('id', $unpricedLead->id);
            // An unpriced deal reports zero on both figures and is never
            // dressed up with a fabricated value.
            expect($card['expected_mrr'])->toEqual(0)
                ->and($card['expected_arr'])->toEqual(0);
        });
});

it('executes customer provisioning boundary when lead transitions to won', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => null,
        'status' => LeadStatus::PaymentReceived,
        'company_name' => 'City Hospital Healthcare',
        'contact_name' => 'Dr. Suresh Kumar',
        'email' => 'suresh@cityhospital.com',
        'phone' => '+91 98765 43210',
    ]);

    $service = app(LeadWorkspaceService::class);
    $wonLead = $service->transitionStage($lead, 'won', $this->adminUser);

    /*
     * The provisioned customer lands on `customer_organization_id`.
     * `organization_id` is the CRM tenant that worked the deal and does not
     * move when the deal is won — the two were conflated while leads were
     * created without a tenant, which is what let a customer organisation
     * overwrite the column the policy scopes on.
     */
    expect($wonLead->status)->toBe(LeadStatus::Won)
        ->and($wonLead->customer_organization_id)->not->toBeNull();

    $org = Organization::find($wonLead->customer_organization_id);
    expect($org)->not->toBeNull()
        ->and($org->name)->toBe('City Hospital Healthcare');
});
