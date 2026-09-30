<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);
});

/*
 * The interaction cadence tightened this from "auto-schedule two days out if
 * the rep doesn't say" to "the rep says, or the call isn't logged" — a silent
 * default was how a promised callback quietly slipped for two days before
 * anyone noticed. `busy` and `follow_up_required` now require an explicit
 * `next_followup_at` at the validation layer, same as the form does.
 */
it('refuses to log "busy" without a next_followup_at', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'busy',
        'duration_seconds' => 15,
        'notes' => 'No answer, will call back.',
    ])->assertSessionHasErrors('next_followup_at');

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('logs "busy" once a next_followup_at is given, and creates the follow-up task', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
    $chosenDate = now()->addDays(2)->startOfMinute();

    post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'busy',
        'duration_seconds' => 15,
        'notes' => 'No answer, will call back.',
        'next_followup_at' => $chosenDate->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $lead->refresh();

    expect($lead->next_followup_at->timestamp)->toBe($chosenDate->timestamp);
    expect(Task::where('taskable_id', $lead->id)->where('type', 'call')->exists())->toBeTrue();
});

it('refuses to log "follow_up_required" without a next_followup_at', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'follow_up_required',
        'duration_seconds' => 90,
    ])->assertSessionHasErrors('next_followup_at');

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('does not override an explicitly chosen follow-up date', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
    $chosenDate = now()->addDays(5)->startOfMinute();

    post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'busy',
        'duration_seconds' => 30,
        'next_followup_at' => $chosenDate->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    expect($lead->fresh()->next_followup_at->timestamp)->toBe($chosenDate->timestamp);
});

it('does not auto-schedule a follow-up for a disposition with no callback intent', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'not_interested',
        'duration_seconds' => 45,
    ])->assertSessionHasNoErrors();

    expect($lead->fresh()->next_followup_at)->toBeNull();
});
