<?php

use App\Http\Middleware\CheckLicenseLimit;
use App\Models\EnabledModule;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->license = License::create([
        'organization_id' => $this->organization->id,
        'plan_name' => 'starter',
        'max_branches' => 2,
        'max_users' => 3,
        'status' => 'active',
    ]);
});

it('allows request when module is enabled', function () {
    EnabledModule::create([
        'license_id' => $this->license->id,
        'module_name' => 'inventory',
    ]);

    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $middleware = new CheckLicenseLimit;
    $response = $middleware->handle($request, function () {
        return response('OK');
    }, 'module', 'inventory');

    expect($response->getContent())->toBe('OK');
});

it('aborts when module is not enabled', function () {
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $middleware = new CheckLicenseLimit;

    $middleware->handle($request, function () {
        return response('OK');
    }, 'module', 'inventory');
})->throws(HttpException::class, 'Module inventory is not enabled on your current plan.');

it('allows request when branch limit is not reached', function () {
    // Current branches count mocked or assumed 0 in DB
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $middleware = new CheckLicenseLimit;
    $response = $middleware->handle($request, function () {
        return response('OK');
    }, 'branches');

    expect($response->getContent())->toBe('OK');
});

it('aborts when branch limit is reached', function () {
    // Update max_branches to 0 to simulate limit reached
    $this->license->update(['max_branches' => 0]);

    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $middleware = new CheckLicenseLimit;

    $middleware->handle($request, function () {
        return response('OK');
    }, 'branches');
})->throws(HttpException::class, 'Branch limit reached on your current plan. Please upgrade.');

it('aborts when license is not active', function () {
    $this->license->update(['status' => 'suspended']);

    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $middleware = new CheckLicenseLimit;

    $middleware->handle($request, function () {
        return response('OK');
    }, 'branches');
})->throws(HttpException::class, 'Active license required.');
