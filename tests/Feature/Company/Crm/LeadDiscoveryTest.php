<?php

use App\FacilityType;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Support\DealDomainPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fast intake, and the discovery gap it deliberately leaves open.
 *
 * The create form asks five things a rep can answer on a first call. Everything
 * else — what kind of place this is, how many doctors, beds, chairs, theatres —
 * has a column on `leads` and `lead_branches` and, until this pass, no request
 * in the application would accept it. A lead's footprint on the Deals list and
 * the Deal 360 masthead was always seed data, because nothing else could write
 * it.
 *
 * These tests hold two things: that intake bootstraps a real main branch rather
 * than leaving `lead_branches` empty under a `branch_count` of 1, and that the
 * update path — which is what the Clinical Discovery form posts to — now
 * accepts and persists the facility archetype and per-site capacity.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create(['organization_id' => $this->org->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fast intake
// ─────────────────────────────────────────────────────────────────────────────

it('creates a lead from the five core fields alone', function () {
    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.store'), [
            'contact_name' => 'Dr Harshil Mehta',
            'phone' => '9825012345',
            'company_name' => 'Mehta Spine & Physio Rehab',
            'city' => 'Vadodara',
            'healthcare_specialties' => ['physiotherapy'],
        ])
        ->assertRedirect(route('company.crm.deals.index'));

    $lead = Lead::where('company_name', 'Mehta Spine & Physio Rehab')->firstOrFail();

    expect($lead->contact_name)->toBe('Dr Harshil Mehta')
        ->and($lead->phone)->toBe('9825012345')
        ->and($lead->city)->toBe('Vadodara')
        ->and($lead->healthcare_specialties)->toContain('physiotherapy')
        ->and($lead->status->value)->toBe('new');
});

/*
 * The five fields the create form now requires. `email` is deliberately absent
 * from this list — a doctor on a cold call frequently will not give one, and
 * the form keeps it optional.
 */
it('refuses to create a lead missing any of the five required fields', function () {
    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.store'), [])
        ->assertSessionHasErrors(['contact_name', 'phone', 'company_name', 'city', 'healthcare_specialties']);

    expect(Lead::count())->toBe(0);
});

/*
 * A lead created with no `branches` payload — which is every lead the fast
 * intake form produces, since it does not ask about sites — gets a real main
 * branch row rather than an empty `lead_branches` table under a `branch_count`
 * that still defaults to 1.
 */
it('bootstraps a main branch for a lead created without one', function () {
    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.store'), [
            'contact_name' => 'Dr Kavita Joshi',
            'phone' => '9812345678',
            'company_name' => 'Joshi Dental Studio',
            'city' => 'Nashik',
            'healthcare_specialties' => ['dental'],
        ])
        ->assertRedirect();

    $lead = Lead::where('company_name', 'Joshi Dental Studio')->firstOrFail();

    expect($lead->branches)->toHaveCount(1);

    $branch = $lead->branches->first();

    expect($branch->is_main)->toBeTrue()
        ->and($branch->name)->toBe('Joshi Dental Studio')
        ->and($branch->city)->toBe('Nashik')
        /* Capacity stays unknown until Clinical Discovery asks. */
        ->and($branch->doctor_count)->toBeNull()
        ->and($branch->bed_count)->toBeNull();
});

/*
 * An explicit branch list is respected as given — the bootstrap only fires
 * when intake supplied nothing at all.
 */
it('does not bootstrap a branch when the request already named one', function () {
    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.store'), [
            'contact_name' => 'Dr Rohit Bansal',
            'phone' => '9800011122',
            'company_name' => 'Bansal Multi-Specialty',
            'city' => 'Jaipur',
            'healthcare_specialties' => ['general_hospital'],
            'branches' => [
                ['name' => 'Bansal MI Road', 'is_main' => true, 'city' => 'Jaipur'],
            ],
        ])
        ->assertRedirect();

    $lead = Lead::where('company_name', 'Bansal Multi-Specialty')->firstOrFail();

    expect($lead->branches)->toHaveCount(1)
        ->and($lead->branches->first()->name)->toBe('Bansal MI Road');
});

// ─────────────────────────────────────────────────────────────────────────────
// Clinical discovery — the update path
// ─────────────────────────────────────────────────────────────────────────────

it('persists the facility archetype and branch capacity through leads.update', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Apex Metro Hospital',
        'contact_name' => 'Dr Nilesh Trivedi',
        'city' => 'Surat',
    ]);

    $branch = $lead->branches()->create([
        'name' => 'Apex Metro Hospital',
        'is_main' => true,
        'city' => 'Surat',
        'position' => 0,
    ]);

    $this->actingAs($this->admin)
        ->put(route('company.crm.leads.update', $lead), [
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'facility_type' => FacilityType::MultiSpecialtyHospital->value,
            'branches' => [[
                'name' => $branch->name,
                'is_main' => true,
                'city' => 'Surat',
                'doctor_count' => 32,
                'bed_count' => 85,
                'operation_theatre_count' => 4,
                'treatment_station_count' => 10,
            ]],
        ])
        ->assertRedirect();

    $lead->refresh();

    expect($lead->facility_type)->toBe(FacilityType::MultiSpecialtyHospital);

    $branch = $lead->branches()->first();

    expect($branch->doctor_count)->toBe(32)
        ->and($branch->bed_count)->toBe(85)
        ->and($branch->operation_theatre_count)->toBe(4)
        ->and($branch->treatment_station_count)->toBe(10);
});

/*
 * The footprint the Deal 360 masthead reads is a sum over every branch's
 * capacity columns — `DealDomainPresenter::facility()`. Persisting through
 * discovery must move that number, not just the row underneath it.
 */
/*
 * `DealDomainPresenter::facility()` deliberately returns null for a single
 * site with no recorded archetype — a bare "one site, nothing else known" is
 * not a footprint worth badging. It starts returning a shaped facility once
 * either fact is on record, which is exactly what Clinical Discovery supplies
 * in one submission.
 */
it('recalculates the facility footprint once discovery names the archetype and capacity', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'city' => 'Ahmedabad',
    ]);

    $lead->branches()->create(['name' => 'Main', 'is_main' => true, 'position' => 0]);

    expect(DealDomainPresenter::facility($lead->fresh(['branches'])))->toBeNull();

    $this->actingAs($this->admin)
        ->put(route('company.crm.leads.update', $lead), [
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'facility_type' => FacilityType::SoloClinic->value,
            'branches' => [[
                'name' => 'Main',
                'is_main' => true,
                'doctor_count' => 6,
            ]],
        ])
        ->assertRedirect();

    $after = DealDomainPresenter::facility($lead->fresh(['branches']));

    expect($after)->not->toBeNull()
        ->and($after['doctors'])->toBe(6)
        ->and($after['type'])->toBe(FacilityType::SoloClinic->value);
});

/*
 * A group with several sites: only the branch discovery is describing gains
 * new figures. The others keep exactly what they held before.
 */
it('updates one branch without disturbing the capacity already recorded on the others', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $lead->branches()->create([
        'name' => 'HQ', 'is_main' => true, 'position' => 0,
    ]);
    $lead->branches()->create([
        'name' => 'Satellite', 'is_main' => false, 'position' => 1,
        'doctor_count' => 3, 'bed_count' => 12,
    ]);

    $this->actingAs($this->admin)
        ->put(route('company.crm.leads.update', $lead), [
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'branches' => [
                ['name' => 'HQ', 'is_main' => true, 'doctor_count' => 15, 'bed_count' => 40],
                ['name' => 'Satellite', 'is_main' => false, 'doctor_count' => 3, 'bed_count' => 12],
            ],
        ])
        ->assertRedirect();

    $branches = $lead->branches()->orderBy('position')->get();

    expect($branches[0]->doctor_count)->toBe(15)
        ->and($branches[0]->bed_count)->toBe(40)
        ->and($branches[1]->doctor_count)->toBe(3)
        ->and($branches[1]->bed_count)->toBe(12);
});

it('rejects a facility type outside the enum', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->admin)
        ->put(route('company.crm.leads.update', $lead), [
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'facility_type' => 'mega_hospital_chain',
        ])
        ->assertSessionHasErrors('facility_type');
});

it('rejects negative capacity figures', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->admin)
        ->put(route('company.crm.leads.update', $lead), [
            'company_name' => $lead->company_name,
            'contact_name' => $lead->contact_name,
            'branches' => [[
                'name' => 'Main',
                'doctor_count' => -1,
            ]],
        ])
        ->assertSessionHasErrors('branches.0.doctor_count');
});
