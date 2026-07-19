<?php

use App\Events\DepartmentCreated;
use App\Events\RoomCreated;
use App\Events\SettingsInitialized;
use App\Events\WorkspaceInitialized;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicRoom;
use App\Models\ClinicSetting;
use App\Services\BranchSettingsService;
use App\Services\ClinicSettingsService;
use App\Services\ClinicWorkspaceInitializationService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('clinic workspace initialization seeds default configurations', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'is_main' => true]);

    $service = app(ClinicWorkspaceInitializationService::class);
    $service->initializeWorkspace($clinic);

    // Assert Events Fired
    Event::assertDispatched(SettingsInitialized::class);
    Event::assertDispatched(DepartmentCreated::class);
    Event::assertDispatched(RoomCreated::class);
    Event::assertDispatched(WorkspaceInitialized::class);

    // Verify Settings Created
    expect(ClinicSetting::where('clinic_id', $clinic->id)->count())->toBeGreaterThan(0);
    $lang = app(ClinicSettingsService::class)->getSetting($clinic, 'localization', 'language');
    expect($lang)->toBe('en');

    // Verify Departments Seeded
    expect(ClinicDepartment::where('clinic_id', $clinic->id)->count())->toBe(7);
    expect(ClinicDepartment::where('clinic_id', $clinic->id)->pluck('key'))->toContain('reception', 'doctors', 'billing');

    // Verify Rooms Seeded
    expect(ClinicRoom::where('branch_id', $branch->id)->count())->toBe(5);
    expect(ClinicRoom::where('branch_id', $branch->id)->pluck('key'))->toContain('operatory_1', 'waiting_area');
});

test('workspace services are fully idempotent', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'is_main' => true]);

    $service = app(ClinicWorkspaceInitializationService::class);

    // First Run
    $service->initializeWorkspace($clinic);
    $settingsCount = ClinicSetting::count();
    $deptsCount = ClinicDepartment::count();
    $roomsCount = ClinicRoom::count();

    // Second Run
    $service->initializeWorkspace($clinic);

    // Verify counts remain identical
    expect(ClinicSetting::count())->toBe($settingsCount)
        ->and(ClinicDepartment::count())->toBe($deptsCount)
        ->and(ClinicRoom::count())->toBe($roomsCount);
});

test('get and set setting functionality', function () {
    $clinic = Clinic::factory()->create();
    $settingsService = app(ClinicSettingsService::class);

    $settingsService->setSetting($clinic, 'localization', 'currency', 'EUR');
    expect($settingsService->getSetting($clinic, 'localization', 'currency'))->toBe('EUR');

    // Fallback to default
    expect($settingsService->getSetting($clinic, 'localization', 'nonexistent', 'fallback'))->toBe('fallback');
});

test('branch get and set setting functionality', function () {
    $branch = Branch::factory()->create();
    $branchSettingsService = app(BranchSettingsService::class);

    $branchSettingsService->setSetting($branch, 'queue', 'prefix', 'K');
    expect($branchSettingsService->getSetting($branch, 'queue', 'prefix'))->toBe('K');
});
