<?php

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Bulk stage moves and bulk tasks from the Deals list.
 *
 * The governing rule: bulk is a convenience, not an escape hatch. Every
 * requirement the detail page enforces on one deal is enforced here on each
 * deal, and a batch that reaches beyond the caller's tenant is refused whole
 * rather than half-applied.
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
});

/*
 * Renamed, because Contacted now asks for something.
 *
 * It used to be the example of a stage with no preconditions, which is exactly
 * why the pipeline's second column filled with deals nobody had spoken to. The
 * bulk mover honours the same guard as the stepper, so the evidence is logged
 * first and all three move.
 */
it('moves several deals in bulk once each one meets the stage requirement', function () {
    $leads = Lead::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::New->value,
    ]);

    $leads->each(fn ($lead) => logContact($lead));

    $ids = $leads->pluck('id')->all();

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-stage'), [
            'lead_ids' => $ids,
            'status' => LeadStatus::Contacted->value,
        ])
        ->assertRedirect();

    expect(Lead::whereIn('id', $ids)->where('status', LeadStatus::Contacted->value)->count())->toBe(3);
});

it('skips the deals that cannot meet the stage requirement instead of forcing them', function () {
    $quoted = Lead::factory()->create(['organization_id' => $this->org->id, 'status' => LeadStatus::Contacted->value]);
    CompanyQuotation::factory()->create(['lead_id' => $quoted->id, 'organization_id' => $this->org->id]);

    $unquoted = Lead::factory()->create(['organization_id' => $this->org->id, 'status' => LeadStatus::Contacted->value]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-stage'), [
            'lead_ids' => [$quoted->id, $unquoted->id],
            'status' => LeadStatus::QuotationSent->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect($quoted->fresh()->status->value)->toBe(LeadStatus::QuotationSent->value)
        ->and($unquoted->fresh()->status->value)->toBe(LeadStatus::Contacted->value);
});

it('refuses to mark a deal lost in bulk without a reason', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'status' => LeadStatus::Negotiation->value]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-stage'), [
            'lead_ids' => [$lead->id],
            'status' => LeadStatus::Lost->value,
        ])
        ->assertSessionHas('warning');

    expect($lead->fresh()->status->value)->toBe(LeadStatus::Negotiation->value);
});

it('refuses the whole batch when one deal belongs to another tenant', function () {
    $mine = Lead::factory()->create(['organization_id' => $this->org->id, 'status' => LeadStatus::New->value]);
    $theirs = Lead::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-stage'), [
            'lead_ids' => [$mine->id, $theirs->id],
            'status' => LeadStatus::Contacted->value,
        ])
        ->assertSessionHasErrors('lead_ids');

    expect($mine->fresh()->status->value)->toBe(LeadStatus::New->value);
});

it('puts one task on every selected deal', function () {
    $ids = Lead::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->manager->id,
    ])->pluck('id')->all();

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-task'), [
            'lead_ids' => $ids,
            'title' => 'Confirm budget before quarter end',
            'due_at' => now()->addWeek()->toDateString(),
        ])
        ->assertRedirect();

    expect(Task::where('title', 'Confirm budget before quarter end')->count())->toBe(3);
});

/*
 * "Assigned" is a stage named after a thing, so it must have that thing.
 *
 * assignLead() has always set the stage when an owner is given; nothing
 * stopped the reverse until now, and the list showed a deal reading "Assigned"
 * in the stage column and "Unassigned" in the next one along.
 */
/*
 * `assigned` was a stage meaning "somebody owns this". It is retired — every
 * lead has an owner from the moment it is created — and the bulk mover skips a
 * retired destination the same way it skips one whose evidence is missing:
 * quietly, counted, without abandoning the rest of the batch.
 */
it('skips a retired stage instead of forcing the batch through it', function () {
    $leads = Lead::factory()->count(2)->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::New->value,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-stage'), [
            'lead_ids' => $leads->pluck('id')->all(),
            'status' => LeadStatus::Assigned->value,
        ])
        ->assertSessionHas('warning');

    $leads->each(fn ($lead) => expect($lead->fresh()->status->value)->toBe(LeadStatus::New->value));
});
