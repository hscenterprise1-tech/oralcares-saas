<?php

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadBranch;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CRM final gap closure — "one primary contact per lead" and "one main
 * branch per lead" were previously enforced only in PHP (a demote-then-set
 * in the happy path, a `saved`/`saving` model hook, and request-layer
 * validation), never at the database. These tests bypass that application
 * layer on purpose — raw inserts and mass updates that skip Eloquent events
 * entirely — to prove the database itself now refuses a second primary/main
 * row, not just the code paths that remember to check.
 */
it('refuses a second primary contact for the same lead at the database level', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    Contact::create([
        'organization_id' => $org->id,
        'lead_id' => $lead->id,
        'first_name' => 'First Primary',
        'is_primary' => true,
    ]);

    expect(fn () => DB::table('contacts')->insert([
        'organization_id' => $org->id,
        'lead_id' => $lead->id,
        'first_name' => 'Second Primary',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(Contact::where('lead_id', $lead->id)->where('is_primary', true)->count())->toBe(1);
});

it('still allows any number of non-primary contacts on the same lead', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    Contact::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'first_name' => 'A', 'is_primary' => false]);
    Contact::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'first_name' => 'B', 'is_primary' => false]);
    Contact::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'first_name' => 'C', 'is_primary' => false]);

    expect(Contact::where('lead_id', $lead->id)->count())->toBe(3);
});

it('allows two different leads to each have their own primary contact', function () {
    $org = Organization::factory()->create();
    $leadA = Lead::factory()->create(['organization_id' => $org->id]);
    $leadB = Lead::factory()->create(['organization_id' => $org->id]);

    Contact::create(['organization_id' => $org->id, 'lead_id' => $leadA->id, 'first_name' => 'A', 'is_primary' => true]);
    Contact::create(['organization_id' => $org->id, 'lead_id' => $leadB->id, 'first_name' => 'B', 'is_primary' => true]);

    expect(Contact::where('is_primary', true)->count())->toBe(2);
});

it('refuses a second main branch for the same lead at the database level, even bypassing the model hook', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    LeadBranch::create(['lead_id' => $lead->id, 'name' => 'HQ', 'is_main' => true]);

    // A mass insert through the query builder skips Eloquent's `saving`
    // hook entirely — exactly the gap the database constraint exists to
    // close for whatever code path forgets to demote first.
    expect(fn () => DB::table('lead_branches')->insert([
        'lead_id' => $lead->id,
        'name' => 'Second Site',
        'is_main' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(LeadBranch::where('lead_id', $lead->id)->where('is_main', true)->count())->toBe(1);
});

it('still allows any number of non-main branches on the same lead', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    LeadBranch::create(['lead_id' => $lead->id, 'name' => 'HQ', 'is_main' => true]);
    LeadBranch::create(['lead_id' => $lead->id, 'name' => 'Satellite 1', 'is_main' => false]);
    LeadBranch::create(['lead_id' => $lead->id, 'name' => 'Satellite 2', 'is_main' => false]);

    expect(LeadBranch::where('lead_id', $lead->id)->count())->toBe(3);
});

it('lets the model hook promote a new main branch in place without racing the constraint', function () {
    $org = Organization::factory()->create();
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    $original = LeadBranch::create(['lead_id' => $lead->id, 'name' => 'HQ', 'is_main' => true]);
    $second = LeadBranch::create(['lead_id' => $lead->id, 'name' => 'Satellite', 'is_main' => false]);

    // Promoting the second branch demotes the first inside the same
    // `saving` hook before this row's own write reaches the database — the
    // scenario the hook moved from `saved` to `saving` to make safe.
    $second->update(['is_main' => true]);

    expect($original->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and(LeadBranch::where('lead_id', $lead->id)->where('is_main', true)->count())->toBe(1);
});
