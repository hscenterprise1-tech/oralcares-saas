<?php

use App\BranchStatus;
use App\ClinicStatus;
use App\Jobs\TenantProvisioningJob;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicMembershipRole;
use App\Models\CompanyOpportunity;
use App\Models\CompanyProvisioningProcess;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\User;
use App\OpportunityStage;
use App\ProvisioningState;
use App\Services\TenantProvisioningService;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('startProvisioning initializes process and dispatches job', function () {
    Queue::fake();

    $opportunity = CompanyOpportunity::factory()->create();

    $service = app(TenantProvisioningService::class);
    $process = $service->startProvisioning($opportunity);

    expect($process)->not->toBeNull()
        ->and($process->status)->toBe(ProvisioningState::Pending)
        ->and($process->opportunity_id)->toBe($opportunity->id);

    Queue::assertPushed(TenantProvisioningJob::class, function ($job) use ($process) {
        return $job->process->id === $process->id;
    });

    expect($process->activities)->toHaveCount(1)
        ->and($process->activities->first()->event)->toBe('Provision Started');
});

test('successful provisioning runs all steps and updates status', function () {
    $opportunity = CompanyOpportunity::factory()->create();
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => null,
        'opportunity_id' => $opportunity->id,
        'status' => SubscriptionStatus::Pending,
        'max_users' => 10,
    ]);

    $service = app(TenantProvisioningService::class);
    $process = CompanyProvisioningProcess::create([
        'opportunity_id' => $opportunity->id,
        'status' => ProvisioningState::Pending,
        'completed_steps' => [],
    ]);

    $service->runProcess($process);

    $process->refresh();
    expect($process->status)->toBe(ProvisioningState::Completed)
        ->and($process->error_message)->toBeNull()
        ->and($process->completed_steps)->toHaveCount(8);

    // Verify clinic created
    $clinic = Clinic::where('id', $process->clinic_id)->first();
    expect($clinic)->not->toBeNull()
        ->and($clinic->status)->toBe(ClinicStatus::Active);

    // Verify branch created
    $branch = Branch::where('clinic_id', $clinic->id)->first();
    expect($branch)->not->toBeNull()
        ->and($branch->is_main)->toBeTrue()
        ->and($branch->status)->toBe(BranchStatus::Active);

    // Verify owner user created
    $lead = $opportunity->lead;
    $owner = User::where('email', $lead->email)->first();
    expect($owner)->not->toBeNull()
        ->and($owner->user_type)->toBe(UserType::ClinicUser);

    // Verify membership created
    $membership = ClinicMembership::where('clinic_id', $clinic->id)->where('user_id', $owner->id)->first();
    expect($membership)->not->toBeNull()
        ->and($membership->is_primary_owner)->toBeTrue();

    // Verify role cloned and assigned
    $role = Role::where('clinic_id', $clinic->id)->where('key', 'clinic_admin')->first();
    expect($role)->not->toBeNull();

    $assignment = ClinicMembershipRole::where('clinic_membership_id', $membership->id)->where('role_id', $role->id)->first();
    expect($assignment)->not->toBeNull();

    // Verify opportunity advanced to Won
    expect($opportunity->fresh()->stage)->toBe(OpportunityStage::Won);

    // Verify subscription activated
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('subscription validation fail halts process and records error', function () {
    $opportunity = CompanyOpportunity::factory()->create();

    // Do NOT create subscription for this opportunity

    $service = app(TenantProvisioningService::class);
    $process = CompanyProvisioningProcess::create([
        'opportunity_id' => $opportunity->id,
        'status' => ProvisioningState::Pending,
        'completed_steps' => [],
    ]);

    try {
        $service->runProcess($process);
    } catch (Throwable $e) {
        // expected
    }

    $process->refresh();
    expect($process->status)->toBe(ProvisioningState::Failed)
        ->and($process->error_message)->toContain('No subscription found linked')
        ->and($process->completed_steps)->toBeEmpty();
});

test('idempotency avoids duplicate creation on retry', function () {
    $opportunity = CompanyOpportunity::factory()->create();
    CompanySubscription::factory()->create([
        'clinic_id' => null,
        'opportunity_id' => $opportunity->id,
        'status' => SubscriptionStatus::Pending,
        'max_users' => 10,
    ]);

    $service = app(TenantProvisioningService::class);
    $process = CompanyProvisioningProcess::create([
        'opportunity_id' => $opportunity->id,
        'status' => ProvisioningState::Pending,
        'completed_steps' => [],
    ]);

    // First Run: Let's fail it at the membership step by passing invalid lead data, or throw manually
    // Actually, we can simulate an already completed set of steps:
    // e.g. create clinic and branch manually, and mark those steps as completed
    $lead = $opportunity->lead;
    $clinicName = $lead->company_name ?: ($lead->contact_name.' Clinic');
    $clinicSlug = Str::slug($clinicName);

    $clinic = Clinic::create(['name' => $clinicName, 'slug' => $clinicSlug, 'status' => ClinicStatus::Active]);
    $branch = $clinic->branches()->create(['name' => 'Main', 'slug' => 'main', 'is_main' => true, 'status' => BranchStatus::Active]);

    $process->clinic_id = $clinic->id;
    $process->completed_steps = ['validate_subscription', 'validate_entitlements', 'create_clinic', 'create_branch'];
    $process->save();

    // Run process now
    $service->runProcess($process);

    $process->refresh();
    expect($process->status)->toBe(ProvisioningState::Completed)
        ->and($process->completed_steps)->toHaveCount(8);

    // Verify only ONE clinic and branch exist
    expect(Clinic::count())->toBe(1)
        ->and(Branch::count())->toBe(1);
});
