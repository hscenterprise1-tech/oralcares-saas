<?php

use App\Models\Clinic;
use App\Models\EnabledModule;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->license = License::create([
        'organization_id' => $this->org->id,
        'plan_name' => 'pro',
        'status' => 'active',
    ]);

    $this->clinic = Clinic::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    $this->user = User::factory()->create(['user_type' => UserType::ClinicUser]);

    // Create test routes wrapped in our middleware
    Route::middleware(['web', 'module:billing'])->get('/test/billing', function () {
        return 'Billing works';
    });

    Route::middleware(['web', 'module:patients'])->get('/test/patients', function () {
        return 'Patients works';
    });

    // Set up ClinicContext for the test requests
    $clinicContext = app(ClinicContext::class);
    $clinicContext->setUser($this->user);

    // We mock the ClinicContext to always return our clinic without needing a full ClinicMembership setup for this test
    $mockContext = Mockery::mock(ClinicContext::class)->makePartial();
    $mockContext->shouldReceive('clinic')->andReturn($this->clinic);
    app()->instance(ClinicContext::class, $mockContext);
});

it('aborts with 403 when module is completely unlicensed', function () {
    $this->actingAs($this->user)
        ->get('/test/billing')
        ->assertStatus(403);
});

it('aborts with 403 when module is licensed but disabled', function () {
    EnabledModule::create([
        'license_id' => $this->license->id,
        'module_name' => 'billing',
        'is_enabled' => false,
    ]);

    $this->actingAs($this->user)
        ->get('/test/billing')
        ->assertStatus(403);
});

it('allows access when module is licensed and enabled', function () {
    EnabledModule::create([
        'license_id' => $this->license->id,
        'module_name' => 'billing',
        'is_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->get('/test/billing')
        ->assertStatus(200)
        ->assertSee('Billing works');
});

it('allows independent access to different modules', function () {
    EnabledModule::create([
        'license_id' => $this->license->id,
        'module_name' => 'patients',
        'is_enabled' => true,
    ]);

    // Patients is enabled, should work
    $this->actingAs($this->user)
        ->get('/test/patients')
        ->assertStatus(200)
        ->assertSee('Patients works');

    // Billing is not enabled, should fail
    $this->actingAs($this->user)
        ->get('/test/billing')
        ->assertStatus(403);
});
