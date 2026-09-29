<?php

use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can create a demo for a lead', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->masterAdmin()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $presenter = User::factory()->masterAdmin()->create(['organization_id' => $org->id]);

    $response = $this->actingAs($user)->post(route('company.crm.demos.store'), [
        'lead_id' => $lead->id,
        'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        'presenter_id' => $presenter->id,
        'meeting_link' => 'https://zoom.us/j/123456789',
        'status' => 'scheduled',
        'notes' => 'Product demo for XYZ',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('demos', [
        'lead_id' => $lead->id,
        'presenter_id' => $presenter->id,
        'status' => 'scheduled',
    ]);
});

test('completing a demo automatically creates a follow up task', function () {
    $user = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create([
        'assigned_user_id' => $user->id,
    ]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $user->id,
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($user)->put(route('company.crm.demos.update', $demo), [
        'status' => 'completed',
        'notes' => 'Demo went well',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('demos', [
        'id' => $demo->id,
        'status' => 'completed',
        'notes' => 'Demo went well',
    ]);

    // Check if task was created
    $this->assertDatabaseHas('tasks', [
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
        'title' => "Post-Demo Follow-up: {$lead->company_name}",
    ]);
});

/*
 * The demos surface, wired to the endpoints behind it.
 *
 * Every one of these pinned a button that could not work. The board and the
 * demo page were written against a vocabulary the controller does not accept
 * and field names it does not read, so the outcome saved and everything the rep
 * typed about it was dropped on arrival.
 */
it('confirms a demo from a button that sends no body', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    /*
     * The board, the demo page and the deal's evaluation zone all post an empty
     * body here. Requiring `confirmation_status` made all three fail.
     */
    $this->actingAs($user)
        ->post(route('company.crm.demos.confirm', $demo))
        ->assertSessionHasNoErrors();

    expect($demo->fresh()->confirmation_status->value ?? $demo->fresh()->confirmation_status)
        ->toBe('confirmed');
});

it('still records an explicit confirmation status when one is sent', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('company.crm.demos.confirm', $demo), ['confirmation_status' => 'declined'])
        ->assertSessionHasNoErrors();

    expect($demo->fresh()->confirmation_status->value ?? $demo->fresh()->confirmation_status)
        ->toBe('declined');
});

it('accepts every outcome the demos board offers', function () {
    /*
     * The board offered `successful`, `follow_up_needed` and `unqualified`,
     * none of which are DemoOutcome values — and `successful` was the default,
     * so the commonest path through the form failed validation.
     */
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);

    $offered = ['highly_interested', 'interested', 'trial_requested', 'quotation_requested',
        'needs_followup', 'no_decision', 'not_interested', 'lost'];

    $accepted = [];

    foreach ($offered as $outcome) {
        $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
        $demo = Demo::factory()->create([
            'lead_id' => $lead->id,
            'status' => 'scheduled',
            'presenter_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('company.crm.demos.complete', $demo), ['outcome' => $outcome])
            ->assertSessionHasNoErrors();

        $accepted[] = $demo->fresh()->outcome->value ?? $demo->fresh()->outcome;
    }

    expect($accepted)->toBe($offered);
});

it('keeps the feedback the rep wrote, so a completed demo leaves the attention list', function () {
    $user = crmUser([], ['view-crm', 'view-leads', 'view-demos']);
    $lead = Lead::factory()->create(['organization_id' => $user->organization_id]);
    $demo = Demo::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'scheduled',
        'presenter_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('company.crm.demos.complete', $demo), [
            'outcome' => 'interested',
            'feedback' => 'Liked the chairside EMR.',
            'next_action' => 'Send pricing on Monday.',
        ])
        ->assertSessionHasNoErrors();

    /*
     * "Needs attention" is defined as completed-with-no-feedback. The board
     * used to send this text as `notes`, which the endpoint does not read, so
     * every completed demo stayed on that list forever.
     */
    expect($demo->fresh()->feedback)->toBe('Liked the chairside EMR.');
});
