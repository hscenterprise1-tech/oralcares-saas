<?php

use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\TrialUsageSnapshot;
use App\Models\User;
use App\Services\CRM\TrialPageService;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * Phase 4 — who may do what, and what the page is told.
 *
 * The policy previously accepted `view-trials` or `view-crm` for both `view` and
 * `update`, so anybody who could open the trials board could also extend a
 * trial, cancel a customer's access, or re-provision their tenant. Reading and
 * writing are not the same authority, and reaching into somebody's live
 * environment is a third thing again.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->plan = CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Kamath Polyclinic',
        'contact_name' => 'Sudha Kamath',
        'email' => 'sudha@kamath.test',
        'phone' => '919300000000',
    ]);

    $this->trial = Trial::factory()->create([
        'lead_id' => $this->lead->id,
        'company_plan_id' => $this->plan->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'duration_days' => 14,
    ]);

    /** Somebody who can only look. */
    $this->reader = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-trials'],
    );

    /** A sales rep: the standard set, which includes provisioning. */
    $this->rep = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
    );
});

/* ------------------------------------------------ view / update separation */

it('lets a read-only user see a trial', function () {
    expect(Gate::forUser($this->reader)->allows('view', $this->trial))->toBeTrue()
        ->and(Gate::forUser($this->reader)->allows('viewAny', Trial::class))->toBeTrue();
});

it('does not let reading a trial grant changing one', function () {
    /*
     * The actual defect: `view-crm` satisfied `update`, so finance and support
     * users could edit trials.
     */
    expect(Gate::forUser($this->reader)->allows('update', $this->trial))->toBeFalse();
});

it('does not let reading a trial grant touching the customer environment', function () {
    /*
     * Provisioning, extending and cancelling reach into a live clinic or move
     * the date somebody's access ends. Highest consequence in the module, and
     * previously behind the same grant as reading a list.
     */
    expect(Gate::forUser($this->reader)->allows('provision', $this->trial))->toBeFalse()
        ->and(Gate::forUser($this->reader)->allows('create', Trial::class))->toBeFalse();
});

it('lets a sales rep provision, extend and cancel their own trials', function () {
    /* Starting a prospect's trial is the rep's own job. */
    expect(Gate::forUser($this->rep)->allows('provision', $this->trial))->toBeTrue()
        ->and(Gate::forUser($this->rep)->allows('update', $this->trial))->toBeTrue()
        ->and(Gate::forUser($this->rep)->allows('create', Trial::class))->toBeTrue();
});

it('separates recording an outcome from revoking a tenant', function () {
    /*
     * A rep who ran the trial should be able to write down what happened
     * without that also being the authority to revoke access.
     */
    $recorder = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-trials', 'manage-trials'],
    );

    expect(Gate::forUser($recorder)->allows('complete', $this->trial))->toBeTrue()
        ->and(Gate::forUser($recorder)->allows('update', $this->trial))->toBeTrue()
        ->and(Gate::forUser($recorder)->allows('provision', $this->trial))->toBeFalse();
});

/* ----------------------------------------------------- tenant isolation */

it('hides a trial belonging to another tenant', function () {
    $otherOrg = Organization::factory()->create();
    $outsider = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $otherOrg->id]),
    );

    expect(Gate::forUser($outsider)->allows('view', $this->trial))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('update', $this->trial))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('provision', $this->trial))->toBeFalse();
});

it('refuses a clinic user outright', function () {
    /* CRM is the vendor's tool. A customer's own staff have no standing in it. */
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    expect(Gate::forUser($clinicUser)->allows('view', $this->trial))->toBeFalse()
        ->and(Gate::forUser($clinicUser)->allows('provision', $this->trial))->toBeFalse();
});

it('enforces tenant isolation over the http actions too', function () {
    $otherOrg = Organization::factory()->create();
    $outsider = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $otherOrg->id]),
    );

    $this->actingAs($outsider)->post(route('company.crm.trials.retry', $this->trial))->assertForbidden();
    $this->actingAs($outsider)->post(route('company.crm.trials.cancel', $this->trial), ['reason' => 'nope'])->assertForbidden();
    $this->actingAs($outsider)->get(route('company.crm.trials.show', $this->trial))->assertForbidden();
});

it('refuses a read-only user the actions that touch a tenant', function () {
    $this->actingAs($this->reader)->post(route('company.crm.trials.retry', $this->trial))->assertForbidden();
    $this->actingAs($this->reader)->post(route('company.crm.trials.cancel', $this->trial), ['reason' => 'nope'])->assertForbidden();
    $this->actingAs($this->reader)->post(route('company.crm.trials.extend', $this->trial), [
        'additional_days' => 7,
        'extension_reason' => 'Because I can.',
    ])->assertForbidden();
});

/* ------------------------------------------------------- the payload */

it('serves a payload whose can flags are real gate checks', function () {
    $payload = app(TrialPageService::class)->payload($this->trial, $this->reader);

    expect($payload['can']['update'])->toBeFalse()
        ->and($payload['can']['provision'])->toBeFalse()
        ->and($payload['can']['extend'])->toBeFalse()
        ->and($payload['can']['cancel'])->toBeFalse();

    $repPayload = app(TrialPageService::class)->payload($this->trial, $this->rep);

    expect($repPayload['can']['provision'])->toBeTrue()
        ->and($repPayload['can']['extend'])->toBeTrue();
});

it('reports the derived status, not whatever is stored on the row', function () {
    /*
     * The stored value is stale between scheduler passes, and a page showing
     * "active" for a trial that ended on Sunday is exactly the drift this
     * replaces.
     */
    app(TrialProvisioningService::class)->provision($this->trial);
    $trial = $this->trial->fresh();

    /*
     * Both move together, because in production they were set from the same
     * dates and are carried by the same actions. Moving only the trial row
     * would be testing a state the system cannot reach.
     */
    $trial->forceFill(['end_date' => now()->subDays(2), 'grace_ends_at' => now()->addDays(5)])->save();

    CompanySubscription::where('organization_id', $trial->customer_organization_id)->update([
        'trial_ends_at' => now()->subDays(2),
        'grace_ends_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5),
    ]);

    $payload = app(TrialPageService::class)->payload($trial->fresh(), $this->rep);

    expect($payload['trial']['status'])->toBe('in_grace')
        ->and($payload['window']['state'])->toBe('grace')
        ->and($payload['window']['grantsAccess'])->toBeTrue();
});

it('reports usage as absent rather than as zero before the first snapshot', function () {
    /*
     * Zeros claim the customer did nothing. "Not measured yet" is a different
     * and far less damaging thing to tell a salesperson.
     */
    app(TrialProvisioningService::class)->provision($this->trial);

    $payload = app(TrialPageService::class)->payload($this->trial->fresh(), $this->rep);

    expect($payload['usage'])->toBeNull()
        ->and($payload['usageTrend'])->toBe([]);
});

it('surfaces the modules a customer is given and has not touched', function () {
    app(TrialProvisioningService::class)->provision($this->trial);
    $trial = $this->trial->fresh();

    TrialUsageSnapshot::create([
        'trial_id' => $trial->id,
        'clinic_id' => $trial->clinic_id,
        'organization_id' => $trial->customer_organization_id,
        'captured_at' => now(),
        'modules_enabled' => ['appointments', 'patients', 'billing', 'emr'],
        'modules_used' => ['patients'],
        'storage_bytes' => 0,
        'storage_limit_mb' => 51200,
    ]);

    $payload = app(TrialPageService::class)->payload($trial, $this->rep);

    expect($payload['usage']['modules']['unused'])
        ->toContain('emr')->toContain('billing')
        ->and($payload['usage']['modules']['used'])->toBe(['patients']);
});

it('carries no patient, clinical or message content in the page payload', function () {
    app(TrialProvisioningService::class)->provision($this->trial);
    $trial = $this->trial->fresh();

    TrialUsageSnapshot::create([
        'trial_id' => $trial->id,
        'clinic_id' => $trial->clinic_id,
        'organization_id' => $trial->customer_organization_id,
        'captured_at' => now(),
        'patients_count' => 12,
        'modules_enabled' => ['patients'],
        'modules_used' => ['patients'],
    ]);

    $payload = app(TrialPageService::class)->payload($trial, $this->rep);
    $usage = json_encode($payload['usage']);

    /* Counts, dates, module keys — and nothing that came out of a clinical table. */
    expect($payload['usage']['records']['patients'])->toBe(12)
        ->and($usage)->not->toContain('name')
        ->and($usage)->not->toContain('body')
        ->and($usage)->not->toContain('diagnosis');
});

it('reports the whatsapp quota as unavailable in the payload', function () {
    app(TrialProvisioningService::class)->provision($this->trial);

    $payload = app(TrialPageService::class)->payload($this->trial->fresh(), $this->rep);

    /* Never a zero, never a denominator. */
    expect($payload['window']['whatsappQuota'])->toBeNull();
});

/* --------------------------------------------- usage is not user-editable */

it('refuses to accept a usage figure through the update endpoint', function () {
    /*
     * `usage_metrics`, `usage_percent`, `users_created` and `inactive_days` were
     * all writable here and written by nothing else, so a usage number was
     * whatever the last person typed.
     */
    app(TrialProvisioningService::class)->provision($this->trial);
    $trial = $this->trial->fresh();

    $this->actingAs($this->rep)->patch(route('company.crm.trials.update', $trial), [
        'notes' => 'Legitimate note.',
        'usage_percent' => 99,
        'users_created' => 42,
        'inactive_days' => 0,
        'usage_metrics' => ['patients' => 500],
        'status' => 'completed',
    ])->assertSessionHasNoErrors();

    $trial->refresh();

    expect($trial->notes)->toBe('Legitimate note.')
        ->and((float) $trial->usage_percent)->toBe(0.0)
        ->and($trial->users_created)->not->toBe(42)
        /* And the derived status is untouched by the attempt. */
        ->and($trial->status)->toBe(TrialStatus::Active);
});
