<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->user);
});

it('displays the leads index', function () {
    Lead::factory()->count(3)->create([
        'organization_id' => $this->organization->id,
    ]);

    get(route('company.crm.deals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->has('deals.data', 3)
        );
});

it('redirects the legacy pipeline url to the deals board', function () {
    Lead::factory()->create(['status' => LeadStatus::New]);

    // Phase 3 collapsed Leads and Pipeline into Deals. The old URL is kept as
    // a guarded redirect rather than removed, so bookmarks still land.
    get(route('company.crm.leads.pipeline'))
        ->assertRedirect(route('company.crm.deals.index', absolute: false));
});

it('displays the lead create page', function () {
    get(route('company.crm.leads.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Create')
            ->has('leadSources')
            ->has('organizationTypes')
            ->has('assignableUsers')
        );
});

it('stores a new lead', function () {
    $data = [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'email' => 'john@test.com',
        'phone' => '1234567890',
        'city' => 'Mumbai',
        'healthcare_specialties' => ['dental'],
        'status' => 'new',
        'priority' => 'high',
        'source' => 'website',
    ];

    post(route('company.crm.leads.store'), $data)
        ->assertRedirect();

    $this->assertDatabaseHas('leads', [
        'company_name' => 'Test Company',
        'contact_name' => 'John Doe',
        'email' => 'john@test.com',
    ]);
});

it('stores a new lead with a partial address and flags it as incomplete', function () {
    $data = [
        'company_name' => 'Partial Address Co',
        'contact_name' => 'John Doe',
        'phone' => '1234567890',
        'source' => 'website',
        'city' => 'Mumbai',
        'healthcare_specialties' => ['dental'],
    ];

    post(route('company.crm.leads.store'), $data)->assertRedirect();

    $lead = Lead::where('company_name', 'Partial Address Co')->firstOrFail();

    expect($lead->city)->toBe('Mumbai');
    expect($lead->has_complete_address)->toBeFalse();
});

it('stores a new lead with a full address and flags it as complete', function () {
    $data = [
        'company_name' => 'Full Address Co',
        'contact_name' => 'Jane Doe',
        'phone' => '9998887777',
        'source' => 'website',
        'address' => '221B Baker Street',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'postal_code' => '400001',
        'healthcare_specialties' => ['dental'],
    ];

    post(route('company.crm.leads.store'), $data)->assertRedirect();

    $lead = Lead::where('company_name', 'Full Address Co')->firstOrFail();

    expect($lead->has_complete_address)->toBeTrue();
});

it('rejects a lead source outside the canonical list', function () {
    $data = [
        'company_name' => 'Bad Source Co',
        'contact_name' => 'John Doe',
        'source' => 'not_a_real_source',
    ];

    post(route('company.crm.leads.store'), $data)
        ->assertSessionHasErrors('source');
});

it('logs an audit trail entry with old and new values when a lead address is updated', function () {
    $lead = Lead::factory()->create([
        'company_name' => 'Audit Co',
        'city' => null,
        'state' => null,
    ]);

    put(route('company.crm.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'city' => 'Pune',
        'state' => 'Maharashtra',
    ])->assertRedirect();

    $lead->refresh();

    expect($lead->city)->toBe('Pune');
    expect($lead->has_complete_address)->toBeFalse(); // still missing street address

    $activity = $lead->activities()->latest('id')->first();
    expect($activity)->not->toBeNull();
    /*
     * The audit line is now written for a person rather than a log reader —
     * `City: — → Pune` instead of `City: "" → "Pune"` — and it covers every
     * editable field rather than a hand-maintained subset. What is asserted
     * here is the same thing it always was: the change is on the record.
     */
    expect($activity->description)->toContain('City')
        ->toContain('Pune')
        ->toContain('State')
        ->toContain('Maharashtra');

    expect($activity->properties['changes'])->toHaveCount(2)
        ->and(collect($activity->properties['changes'])->pluck('field')->all())
        ->toBe(['city', 'state']);
});

it('displays the deal page', function () {
    $lead = Lead::factory()->create();

    get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Show')
            ->has('deal')
            ->has('can')
        );
});

it('displays the organisation profile editor', function () {
    /*
     * `Leads/Edit` is gone. It was a third edit surface, 249 lines, that
     * nothing in the application linked to — reachable only by typing the URL,
     * alongside a modal and six inline editors that all wrote the same record.
     * Reference data is edited on one page now.
     */
    $lead = Lead::factory()->create();

    get(route('company.crm.leads.profile', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Profile')
            ->has('deal')
            ->has('profile.organisation')
            ->has('specialties')
        );
});

it('updates an existing lead', function () {
    $lead = Lead::factory()->create([
        'company_name' => 'Old Name',
    ]);

    $data = [
        'company_name' => 'New Name',
        'contact_name' => 'Jane Doe',
        'status' => 'contacted',
        'priority' => 'medium',
        'source' => 'referral',
    ];

    put(route('company.crm.leads.update', $lead), $data)
        ->assertRedirect();

    $this->assertDatabaseHas('leads', [
        'id' => $lead->id,
        'company_name' => 'New Name',
        'contact_name' => 'Jane Doe',
    ]);
});
