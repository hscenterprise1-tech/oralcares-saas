<?php

use App\Exceptions\ModuleDependencyException;
use App\Exceptions\ModuleNotLicensedException;
use App\Models\EnabledModule;
use App\Models\License;
use App\Models\Organization;
use App\Services\Core\ModuleEngine;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->license = License::create([
        'organization_id' => $this->org->id,
        'plan_name' => 'pro',
        'status' => 'active',
    ]);
    $this->engine = app(ModuleEngine::class);

    // Mock config for testing
    Config::set('modules.modules', [
        'patients' => ['dependencies' => []],
        'treatments' => ['dependencies' => ['patients']],
        'billing' => ['dependencies' => ['patients', 'treatments']],
    ]);
});

it('can check if a module is licensed', function () {
    expect($this->engine->isLicensed($this->org, 'patients'))->toBeFalse();

    EnabledModule::create([
        'license_id' => $this->license->id,
        'module_name' => 'patients',
        'is_enabled' => false,
    ]);

    expect($this->engine->isLicensed($this->org, 'patients'))->toBeTrue();
    expect($this->engine->isEnabled($this->org, 'patients'))->toBeFalse();
});

it('can check if a module is enabled', function () {
    EnabledModule::create([
        'license_id' => $this->license->id,
        'module_name' => 'patients',
        'is_enabled' => true,
    ]);

    expect($this->engine->isEnabled($this->org, 'patients'))->toBeTrue();
});

it('throws ModuleNotLicensedException when trying to enable an unlicensed module', function () {
    $this->engine->enable($this->org, 'patients');
})->throws(ModuleNotLicensedException::class);

it('enables a module successfully when dependencies are met', function () {
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'patients', 'is_enabled' => false]);

    $this->engine->enable($this->org, 'patients');

    expect($this->engine->isEnabled($this->org, 'patients'))->toBeTrue();
});

it('throws ModuleDependencyException when trying to enable without dependencies', function () {
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'treatments', 'is_enabled' => false]);

    // treatments depends on patients, which is not enabled
    $this->engine->enable($this->org, 'treatments');
})->throws(ModuleDependencyException::class);

it('enables a module when all dependencies are satisfied', function () {
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'patients', 'is_enabled' => true]);
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'treatments', 'is_enabled' => false]);

    $this->engine->enable($this->org, 'treatments');

    expect($this->engine->isEnabled($this->org, 'treatments'))->toBeTrue();
});

it('throws ModuleDependencyException when trying to disable a module that others depend on', function () {
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'patients', 'is_enabled' => true]);
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'treatments', 'is_enabled' => true]);

    // treatments is enabled and depends on patients
    $this->engine->disable($this->org, 'patients');
})->throws(ModuleDependencyException::class);

it('disables a module successfully when no other enabled modules depend on it', function () {
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'patients', 'is_enabled' => true]);
    EnabledModule::create(['license_id' => $this->license->id, 'module_name' => 'treatments', 'is_enabled' => true]);

    // Disable treatments first
    $this->engine->disable($this->org, 'treatments');
    expect($this->engine->isEnabled($this->org, 'treatments'))->toBeFalse();

    // Now patients can be disabled
    $this->engine->disable($this->org, 'patients');
    expect($this->engine->isEnabled($this->org, 'patients'))->toBeFalse();
});
