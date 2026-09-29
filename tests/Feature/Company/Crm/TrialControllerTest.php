<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can create a trial for a lead', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->masterAdmin()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $response = $this->actingAs($user)->post(route('company.crm.trials.store'), [
        'lead_id' => $lead->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'expected_users' => 10,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('trials', [
        'lead_id' => $lead->id,
        'expected_users' => 10,
    ]);

    // Check if task was created by TrialObserver
    $this->assertDatabaseHas('tasks', [
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
        'title' => 'Trial Ending Soon Follow-up',
    ]);
});

test('a master admin without an organization_id can create a trial for any organization lead', function () {
    // Master admins have organization_id === null. The cross-tenant guard in
    // TrialController::store() must explicitly bypass for master admins
    // (via isMasterAdmin()) rather than comparing against a resolved org id,
    // otherwise a master admin could be blocked from acting on leads that
    // belong to a different organization than the one they'd resolve to.
    $org = Organization::factory()->create();
    $masterAdmin = User::factory()->create([
        'organization_id' => null,
        'user_type' => 'master_admin',
    ]);
    $lead = Lead::factory()->create([
        'assigned_user_id' => $masterAdmin->id,
        'organization_id' => $org->id,
    ]);

    $response = $this->actingAs($masterAdmin)->post(route('company.crm.trials.store'), [
        'lead_id' => $lead->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'expected_users' => 5,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('trials', [
        'lead_id' => $lead->id,
        'expected_users' => 5,
    ]);
});

test('a trial refuses usage metrics and a status posted by hand', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->masterAdmin()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $user->id,
    ]);

    $statusBefore = $trial->status;

    $response = $this->actingAs($user)->put(route('company.crm.trials.update', $trial), [
        'notes' => 'Spoke to the practice manager.',
        'status' => 'completed',
        'usage_metrics' => [
            'reports_generated' => 5,
            'logins_count' => 12,
        ],
    ]);

    $response->assertRedirect();

    $updatedTrial = Trial::find($trial->id);

    /*
     * The note is authored by a human and is kept. The status is derived from
     * the trial's dates and the usage figures are measured nightly into
     * `trial_usage_snapshots`, so neither may be asserted through a form — a
     * number nobody measured is worse than none, because it gets believed.
     */
    expect($updatedTrial->notes)->toBe('Spoke to the practice manager.')
        ->and($updatedTrial->status)->toBe($statusBefore)
        ->and($updatedTrial->usage_metrics)->not->toMatchArray(['reports_generated' => 5]);
});
