<?php

use App\ContactRole;
use App\HealthcareSpecialty;
use App\Models\CompanyPlan;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadBranch;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * What the Create Deal form promises to store.
 *
 * The columns behind most of this have existed for a long time with no form to
 * fill them, so these tests are the contract that the form actually lands the
 * data rather than only displaying inputs.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->user->assignRole('CRM Manager');
});

function creationPayload(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Sunrise Multi-Specialty',
        'contact_name' => 'Dr Anita Rao',
        'email' => 'anita@sunrise.test',
        'phone' => '+91 98765 43210',
        'city' => 'Mumbai',
        'healthcare_specialties' => [HealthcareSpecialty::Dental->value],
        'source' => 'referral',
    ], $overrides);
}

it('stores several specialties and keeps the legacy single column in step', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'healthcare_specialties' => [
            HealthcareSpecialty::MultiSpecialty->value,
            HealthcareSpecialty::Dental->value,
            HealthcareSpecialty::ENT->value,
        ],
    ]))->assertRedirect();

    $lead = Lead::sole();

    expect($lead->healthcare_specialties)->toBe(['multi_specialty', 'dental', 'ent'])
        ->and($lead->healthcare_specialty)->toBe('multi_specialty');
});

it('refuses multi-specialty on its own, because it names nothing', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'healthcare_specialties' => [HealthcareSpecialty::MultiSpecialty->value],
    ]))->assertSessionHasErrors('healthcare_specialties');

    expect(Lead::count())->toBe(0);
});

it('writes the person spoken to as a real contact, not three loose columns', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'contact' => [
            'job_title' => 'Managing Director',
            'whatsapp' => '+91 90000 11111',
            'decision_role' => ContactRole::Owner->value,
        ],
    ]))->assertRedirect();

    $contact = Contact::sole();

    expect($contact->first_name)->toBe('Dr')
        ->and($contact->last_name)->toBe('Anita Rao')
        ->and($contact->job_title)->toBe('Managing Director')
        ->and($contact->whatsapp)->toBe('+91 90000 11111')
        ->and($contact->decision_role)->toBe(ContactRole::Owner)
        ->and($contact->is_primary)->toBeTrue()
        ->and($contact->lead->email)->toBe('anita@sunrise.test');
});

it('records the branches a prospect already runs', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'branches' => [
            ['name' => 'Andheri', 'city' => 'Mumbai', 'postal_code' => '400053'],
            ['name' => 'Bandra', 'city' => 'Mumbai', 'is_main' => true],
        ],
    ]))->assertRedirect();

    $lead = Lead::sole();

    expect($lead->branches)->toHaveCount(2)
        ->and($lead->mainBranch->name)->toBe('Bandra')
        ->and($lead->branch_count)->toBe(2);
});

it('treats the first branch as the main site when nobody said which', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'branches' => [['name' => 'Head office'], ['name' => 'Satellite']],
    ]));

    expect(Lead::sole()->mainBranch->name)->toBe('Head office');
});

it('refuses two main branches', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'branches' => [
            ['name' => 'One', 'is_main' => true],
            ['name' => 'Two', 'is_main' => true],
        ],
    ]))->assertSessionHasErrors('branches');

    expect(LeadBranch::count())->toBe(0);
});

it('stores the qualification fields that had no form behind them', function () {
    $plan = CompanyPlan::factory()->create();

    $this->actingAs($this->user)->post(route('company.crm.leads.store'), creationPayload([
        'current_software' => 'Dentrix',
        'deployment_preference' => 'cloud',
        'expected_go_live_date' => '2026-11-01',
        'required_modules' => ['Appointments', 'Billing'],
        'required_integrations' => ['Tally', 'WhatsApp'],
        'competitors' => 'Practo, Dentrix',
        'selected_plan_id' => $plan->id,
        'probability_to_close' => 45,
        'expected_users' => 22,
    ]))->assertRedirect();

    $lead = Lead::sole();

    expect($lead->current_software)->toBe('Dentrix')
        ->and($lead->deployment_preference)->toBe('cloud')
        ->and($lead->required_modules)->toBe(['Appointments', 'Billing'])
        ->and($lead->required_integrations)->toBe(['Tally', 'WhatsApp'])
        ->and($lead->selected_plan_id)->toBe($plan->id)
        ->and($lead->probability_to_close)->toBe(45)
        ->and($lead->expected_users)->toBe(22)
        ->and($lead->selectedPlan->id)->toBe($plan->id);
});

/*
 * The detection itself has been tested since the lead work. What is new is
 * being able to ask before saving — the only path used to run through
 * creation, so the product could tell you a deal was a duplicate exactly once
 * it had made one.
 */
it('warns about a possible duplicate before anything is created', function () {
    $existing = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Sunrise Multi-Specialty',
        'phone' => '+91 98765 43210',
        'normalized_phone' => '9876543210',
    ]);

    $this->actingAs($this->user)
        ->getJson(route('company.crm.deals.check-duplicate', ['phone' => '9876543210']))
        ->assertSuccessful()
        ->assertJsonPath('duplicate.id', $existing->id)
        ->assertJsonPath('duplicate.company_name', 'Sunrise Multi-Specialty');

    expect(Lead::count())->toBe(1);
});

it('says so plainly when nothing matches', function () {
    $this->actingAs($this->user)
        ->getJson(route('company.crm.deals.check-duplicate', ['phone' => '9999900000']))
        ->assertSuccessful()
        ->assertJsonPath('duplicate', null);
});
