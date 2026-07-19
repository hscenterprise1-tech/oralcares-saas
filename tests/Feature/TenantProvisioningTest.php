<?php

use App\Events\TenantProvisioningCompleted;
use App\Events\TenantProvisioningFailed;
use App\Events\TenantProvisioningStarted;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicQueue;
use App\Models\CommunicationPreference;
use App\Models\NotificationTemplate;
use App\Models\ProvisioningAudit;
use App\Models\Resource;
use App\Models\WorkflowDefinition;
use App\Services\TenantProvisioningService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('successful new tenant provisioning with solo dentist profile and packages', function () {
    Event::fake([
        TenantProvisioningStarted::class,
        TenantProvisioningCompleted::class,
        TenantProvisioningFailed::class,
    ]);

    $service = app(TenantProvisioningService::class);

    $clinic = $service->provision(
        'Apex Dental',
        'Dr. Gregory House',
        'gregory@apex.com',
        'solo_dentist'
    );

    expect($clinic)->not->toBeNull()
        ->and($clinic->name)->toBe('Apex Dental');

    // 1. Verify Seeded Procedures
    $procedures = ClinicalProcedure::where('clinic_id', $clinic->id)->get();
    expect($procedures)->toHaveCount(2)
        ->and($procedures->first()->code)->toBe('D1110');

    // 2. Verify Seeded Notification Templates
    $templates = NotificationTemplate::where('clinic_id', $clinic->id)->get();
    expect($templates)->toHaveCount(2)
        ->and($templates->pluck('code')->toArray())->toContain('welcome');

    // 3. Verify Seeded Communication Preferences
    $prefs = CommunicationPreference::where('clinic_id', $clinic->id)->get();
    expect($prefs)->toHaveCount(4)
        ->and($prefs->first()->quiet_hours_start)->toBe('22:00:00');

    // 4. Verify Seeded Workflow
    $workflows = WorkflowDefinition::where('clinic_id', $clinic->id)->get();
    expect($workflows)->toHaveCount(1)
        ->and($workflows->first()->stages)->toHaveCount(3);

    // 5. Verify Branch, Hours, Queue and Resources (Chairs & Rooms)
    $branch = $clinic->branches()->first();
    expect($branch)->not->toBeNull();

    $hours = ClinicOperatingHour::where('branch_id', $branch->id)->get();
    expect($hours)->toHaveCount(7)
        ->and($hours->first()->open_time)->toBe('09:00:00');

    $resources = Resource::where('branch_id', $branch->id)->get();
    // Solo dentist configures 1 chair and 1 room
    expect($resources)->toHaveCount(2)
        ->and($resources->where('resource_type', 'chair')->count())->toBe(1);

    $queues = ClinicQueue::where('branch_id', $branch->id)->get();
    expect($queues)->toHaveCount(1)
        ->and($queues->first()->name)->toBe('General Reception Queue');

    // 6. Verify Audit Logs
    $audit = ProvisioningAudit::where('clinic_id', $clinic->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe('completed')
        ->and($audit->profile)->toBe('solo_dentist')
        ->and($audit->created_entities)->toHaveKey('clinics');

    // 7. Verify Events
    Event::assertDispatched(TenantProvisioningStarted::class);
    Event::assertDispatched(TenantProvisioningCompleted::class);
    Event::assertNotDispatched(TenantProvisioningFailed::class);
});

test('onboarding transaction rolls back completely on duplicate or error fails', function () {
    Event::fake([
        TenantProvisioningStarted::class,
        TenantProvisioningCompleted::class,
        TenantProvisioningFailed::class,
    ]);

    $service = app(TenantProvisioningService::class);

    // Create a conflict by creating a clinic with the same name beforehand
    Clinic::create(['name' => 'Duplicate Dental', 'slug' => 'duplicate-dental', 'is_active' => true]);

    $thrown = false;
    try {
        $service->provision(
            'Duplicate Dental',
            'Dr. Gregory House',
            'gregory@duplicate.com',
            'solo_dentist'
        );
    } catch (InvalidArgumentException $e) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();

    // Verify no orphan records (like notification preferences or workflows) are saved
    expect(WorkflowDefinition::count())->toBe(0)
        ->and(CommunicationPreference::count())->toBe(0)
        ->and(ClinicalProcedure::count())->toBe(0);

    // Verify failed audit log is recorded
    $audit = ProvisioningAudit::where('profile', 'solo_dentist')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe('failed')
        ->and($audit->error_message)->toContain('Duplicate Dental');

    Event::assertDispatched(TenantProvisioningStarted::class);
    Event::assertDispatched(TenantProvisioningFailed::class);
    Event::assertNotDispatched(TenantProvisioningCompleted::class);
});
