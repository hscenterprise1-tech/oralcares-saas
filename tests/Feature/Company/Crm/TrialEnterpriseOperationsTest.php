<?php

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Trial;
use App\Models\TrialExtensionRequest;
use App\Models\User;
use App\TrialExtensionRequestStatus;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Step 3 — operational cadence, real subdomain provisioning, and trial
 * extension approval governance.
 *
 * Three unrelated concerns share one file because they share one theme: a
 * promise the CRM makes (a callback is booked, a tenant exists, a trial's
 * extra time was actually authorised) now has to be true, not just claimed on
 * a form.
 */
beforeEach(function () {
    setPermissionsTeamId(0);

    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->rep->assignRole('Sales Representative');
});

// ─────────────────────────────────────────────────────────────────────────────
// Task 1 — mandatory interaction cadence on the call log
// ─────────────────────────────────────────────────────────────────────────────

it('refuses to log a call as "busy" or "follow-up requested" without a next_followup_at', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $this->rep->id]);

    foreach (['busy', 'follow_up_required'] as $disposition) {
        $this->actingAs($this->rep)
            ->post(route('company.crm.leads.call-disposition', $lead), [
                'disposition' => $disposition,
                'duration_seconds' => 30,
            ])
            ->assertSessionHasErrors('next_followup_at');
    }

    expect($lead->fresh()->next_followup_at)->toBeNull()
        ->and(Task::where('taskable_id', $lead->id)->count())->toBe(0);
});

it('logs the call and opens a pending Call task once a next_followup_at is given', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $this->rep->id]);
    $when = now()->addDays(3)->startOfMinute();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.call-disposition', $lead), [
            'disposition' => 'follow_up_required',
            'duration_seconds' => 120,
            'notes' => 'Wants pricing for the multi-branch plan.',
            'next_followup_at' => $when->toDateTimeString(),
        ])
        ->assertSessionHasNoErrors();

    $lead->refresh();

    expect($lead->next_followup_at->timestamp)->toBe($when->timestamp);

    $task = Task::where('taskable_id', $lead->id)->where('type', 'call')->first();

    expect($task)->not->toBeNull()
        ->and($task->status->value)->toBe('pending')
        ->and($task->due_at->timestamp)->toBe($when->timestamp)
        ->and($task->assigned_user_id)->toBe($this->rep->id);
});

it('does not require a next_followup_at for a disposition with nothing left to promise', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $this->rep->id]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.call-disposition', $lead), [
            'disposition' => 'not_interested',
            'duration_seconds' => 20,
        ])
        ->assertSessionHasNoErrors();

    expect($lead->fresh()->next_followup_at)->toBeNull();
});

it('recomputes next_followup_at from the earliest pending contact task through LeadFollowupSyncService', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $this->rep->id]);
    $sooner = now()->addDay()->startOfMinute();
    $later = now()->addDays(5)->startOfMinute();

    // Logged later, for the sooner date — the sync recomputes from the Task
    // table's earliest pending commitment, not from whichever call happened
    // most recently.
    $this->actingAs($this->rep)->post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'follow_up_required',
        'next_followup_at' => $later->toDateTimeString(),
    ]);

    $this->actingAs($this->rep)->post(route('company.crm.leads.call-disposition', $lead), [
        'disposition' => 'busy',
        'next_followup_at' => $sooner->toDateTimeString(),
    ]);

    expect($lead->fresh()->next_followup_at->timestamp)->toBe($sooner->timestamp);
});

// ─────────────────────────────────────────────────────────────────────────────
// Task 2 — real subdomain trial provisioning
// ─────────────────────────────────────────────────────────────────────────────

it('starting a trial invokes CustomerProvisioningEngine and links clinic, customer organisation and admin user', function () {
    $plan = CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ]);

    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'company_name' => 'Mehta Physio Rehab',
        'email' => 'mehta@physio.test',
        'assigned_user_id' => $this->rep->id,
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.create-trial', $lead), [
            'company_plan_id' => $plan->id,
            'duration_days' => 14,
        ])
        ->assertSessionHasNoErrors();

    $trial = Trial::where('lead_id', $lead->id)->firstOrFail();

    expect($trial->isProvisioned())->toBeTrue()
        ->and($trial->status)->toBe(TrialStatus::Active)
        ->and($trial->company_plan_id)->toBe($plan->id)
        ->and($trial->customer_organization_id)->not->toBeNull()
        ->and($trial->clinic_id)->not->toBeNull();

    $clinic = Clinic::find($trial->clinic_id);

    expect($clinic)->not->toBeNull()
        ->and(CompanySubscription::where('organization_id', $trial->customer_organization_id)->exists())->toBeTrue();

    /*
     * `CustomerProvisioningEngine` keys the clinic's primary admin user
     * against the lead's own email — that admin identity is what the Deal
     * 360 trial hero now surfaces (`DealPageService::evaluation()`), so it
     * has to actually exist, not just be inferred from the lead record.
     */
    $admin = User::where('email', 'mehta@physio.test')->where('user_type', UserType::ClinicUser)->first();

    expect($admin)->not->toBeNull();
});

it('exposes the provisioned tenant, admin identity, and telemetry meters on the deal page', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->org->id, 'email' => 'reader@clinic.test']);
    $clinic = Clinic::factory()->create(['slug' => 'reader-clinic']);

    Trial::factory()->create([
        'lead_id' => $lead->id,
        'clinic_id' => $clinic->id,
        'provisioned_at' => now(),
        'usage_telemetry' => ['patients' => 12, 'prescriptions' => 4, 'invoices' => 2],
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.crm.leads.show', $lead))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('evaluation.trials.0.provisioning.tenant_url', 'reader-clinic.'.config('crm.tenant_base_domain'))
            ->where('evaluation.trials.0.provisioning.admin_email', 'reader@clinic.test')
            ->where('evaluation.trials.0.provisioning.credentials_sent', true)
            ->where('evaluation.trials.0.telemetry.patients', 12)
            ->where('evaluation.trials.0.telemetry.prescriptions', 4)
            ->where('evaluation.trials.0.telemetry.invoices', 2)
        );
});

// ─────────────────────────────────────────────────────────────────────────────
// Task 3 — trial extension approval governance
// ─────────────────────────────────────────────────────────────────────────────

function extensionTrial(): Trial
{
    $lead = Lead::factory()->create([
        'organization_id' => test()->org->id,
        'assigned_user_id' => test()->rep->id,
    ]);

    return Trial::factory()->create([
        'lead_id' => $lead->id,
        'end_date' => now()->addDays(5),
    ]);
}

it('lets a sales rep request an extension, creating a pending request without moving end_date', function () {
    $trial = extensionTrial();
    $originalEnd = $trial->end_date->copy();

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.extension-requests.store', $trial), [
            'additional_days' => 7,
            'reason' => 'Clinic asked for two more weeks to onboard staff.',
        ])
        ->assertSessionHasNoErrors();

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    expect($request->status)->toBe(TrialExtensionRequestStatus::Pending)
        ->and($request->requested_by)->toBe($this->rep->id)
        ->and($request->approved_by)->toBeNull()
        ->and($request->additional_days)->toBe(7)
        ->and($trial->fresh()->end_date->isSameDay($originalEnd))->toBeTrue()
        ->and($trial->fresh()->extension_count)->toBe(0);
});

it('lets a manager approve a pending request, applying additional_days to end_date', function () {
    $trial = extensionTrial();
    $originalEnd = $trial->end_date->copy();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 7,
        'reason' => 'Clinic asked for two more weeks to onboard staff.',
    ]);

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.approve', [$trial, $request]))
        ->assertSessionHasNoErrors();

    $trial->refresh();
    $request->refresh();

    expect($request->status)->toBe(TrialExtensionRequestStatus::Approved)
        ->and($request->approved_by)->toBe($this->manager->id)
        ->and($trial->end_date->isSameDay($originalEnd->copy()->addDays(7)))->toBeTrue()
        ->and($trial->extension_count)->toBe(1);
});

it('lets a manager reject a pending request, leaving end_date untouched', function () {
    $trial = extensionTrial();
    $originalEnd = $trial->end_date->copy();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 10,
        'reason' => 'Wants a month to decide.',
    ]);

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.reject', [$trial, $request]), [
            'rejection_reason' => 'A month is longer than policy allows without a signed quotation.',
        ])
        ->assertSessionHasNoErrors();

    $trial->refresh();
    $request->refresh();

    expect($request->status)->toBe(TrialExtensionRequestStatus::Rejected)
        ->and($request->rejection_reason)->not->toBeNull()
        ->and($trial->end_date->isSameDay($originalEnd))->toBeTrue();
});

it('applies a manager\'s own extension request immediately, with a self-approved audit row', function () {
    $trial = extensionTrial();
    $originalEnd = $trial->end_date->copy();

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.store', $trial), [
            'additional_days' => 14,
            'reason' => 'Strategic account — extending myself.',
        ])
        ->assertSessionHasNoErrors();

    $trial->refresh();
    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    expect($request->status)->toBe(TrialExtensionRequestStatus::Approved)
        ->and($request->requested_by)->toBe($this->manager->id)
        ->and($request->approved_by)->toBe($this->manager->id)
        ->and($trial->end_date->isSameDay($originalEnd->copy()->addDays(14)))->toBeTrue();
});

it('refuses a sales rep permission to approve or reject an extension request', function () {
    $trial = extensionTrial();

    $this->actingAs($this->manager)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 5,
        'reason' => 'Testing rep denial.',
    ]);

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    // The manager's own request auto-approved above, so start a second, genuinely pending one from the rep.
    $trial->refresh();
    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 3,
        'reason' => 'Rep asking again.',
    ]);
    $pending = TrialExtensionRequest::where('trial_id', $trial->id)->where('status', 'pending')->firstOrFail();

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.extension-requests.approve', [$trial, $pending]))
        ->assertForbidden();

    expect($pending->fresh()->status)->toBe(TrialExtensionRequestStatus::Pending);
});

it('exposes the pending extension banner and approval ability on the deal page', function () {
    $trial = extensionTrial();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 7,
        'reason' => 'Needs more time to convert their team.',
    ]);

    $lead = $trial->lead;

    $this->actingAs($this->rep)
        ->get(route('company.crm.leads.show', $lead))
        ->assertInertia(fn ($page) => $page
            ->where('evaluation.trials.0.pending_extension_request.additional_days', 7)
            ->where('can.approve_trial_extension', false)
        );

    $this->actingAs($this->manager)
        ->get(route('company.crm.leads.show', $lead))
        ->assertInertia(fn ($page) => $page
            ->where('evaluation.trials.0.pending_extension_request.additional_days', 7)
            ->where('can.approve_trial_extension', true)
        );
});
