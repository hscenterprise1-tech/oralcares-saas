<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->repUser = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
    ]);
});

it('renders the canonical leads directory workspace with real paginated leads and metrics', function () {
    actingAs($this->adminUser);

    Lead::factory()->count(5)->create([
        'organization_id' => $this->organization->id,
    ]);

    $response = get(route('company.crm.deals.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->has('deals.data', 5)
            ->has('counts')
            ->where('counts.all', 5)
            ->has('assignableUsers')
        );
});

it('filters leads by search query across company name, contact, and email', function () {
    actingAs($this->adminUser);

    $leadA = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Apollo Apex Hospital',
        'contact_name' => 'Dr. Apollo Specialist',
        'email' => 'apollo@test.com',
    ]);

    $leadB = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Care Dental Center',
        'contact_name' => 'Dr. Care Dentist',
        'email' => 'care@test.com',
    ]);

    $response = get(route('company.crm.deals.index', ['search' => 'Apollo']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $leadA->id)
            ->where('deals.data.0.company_name', 'Apollo Apex Hospital')
        );
});

it('filters leads by lifecycle stage', function () {
    actingAs($this->adminUser);

    $newLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::New,
    ]);

    $wonLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Won,
    ]);

    $response = get(route('company.crm.deals.index', ['stage' => 'won']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $wonLead->id)
        );
});

it('filters leads by assigned user and unassigned state', function () {
    actingAs($this->adminUser);

    $assignedLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->repUser->id,
    ]);

    /* Nothing creates an unowned lead any more — this is a legacy row. */
    $unassignedLead = unownedLead([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => null,
    ]);

    $response = get(route('company.crm.deals.index', ['assigned_user_id' => 'unassigned']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->has('deals.data', 1)
            ->where('deals.data.0.id', $unassignedLead->id)
        );
});

it('sorts leads by expected_mrr descending', function () {
    actingAs($this->adminUser);

    $lowMrr = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'expected_mrr' => 5000,
    ]);

    $highMrr = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'expected_mrr' => 35000,
    ]);

    $response = get(route('company.crm.deals.index', [
        'sort' => 'expected_mrr',
        'direction' => 'desc',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->where('deals.data.0.id', $highMrr->id)
            ->where('deals.data.0.expected_mrr', 35000)
        );
});

it('supports batch reassignment of leads', function () {
    actingAs($this->adminUser);

    $leads = Lead::factory()->count(3)->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => null,
    ]);

    $response = post(route('company.crm.leads.batch-reassign'), [
        'lead_ids' => $leads->pluck('id')->toArray(),
        'assigned_user_id' => $this->repUser->id,
    ]);

    $response->assertSessionHasNoErrors();

    foreach ($leads as $lead) {
        expect($lead->fresh()->assigned_user_id)->toBe($this->repUser->id);
    }
});

it('exports filtered leads to CSV stream', function () {
    actingAs($this->adminUser);

    Lead::factory()->count(3)->create([
        'organization_id' => $this->organization->id,
    ]);

    $response = get(route('company.crm.leads.export'));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});
