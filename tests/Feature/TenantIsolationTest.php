<?php

use App\Exceptions\TenantContextMissingException;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Role;
use App\Models\User;
use App\PermissionKey;
use App\Services\ClinicContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('global tenant scope automatically scopes branches and roles for clinic user', function () {
    $clinic1 = Clinic::factory()->create();
    $clinic2 = Clinic::factory()->create();

    $branch1 = Branch::factory()->create(['clinic_id' => $clinic1->id]);
    Branch::factory()->create(['clinic_id' => $clinic2->id]);

    $role1 = Role::factory()->create(['clinic_id' => $clinic1->id]);
    Role::factory()->create(['clinic_id' => $clinic2->id]);

    $user = User::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic1->id,
        'user_id' => $user->id,
    ]);

    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($user);
    $context = app(ClinicContext::class);
    $context->setUser($user);
    $context->setMembership($membership);

    expect(Branch::all())->toHaveCount(1)
        ->and(Branch::first()->id)->toBe($branch1->id)
        ->and(Role::all())->toHaveCount(1)
        ->and(Role::first()->id)->toBe($role1->id);
});

test('global tenant scope throws exception for clinic user with no active context', function () {
    $user = User::factory()->create();

    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($user);
    $context = app(ClinicContext::class);
    $context->clear();

    $this->expectException(TenantContextMissingException::class);
    Branch::all();
});

test('global tenant scope bypasses for master admin', function () {
    $clinic1 = Clinic::factory()->create();
    $clinic2 = Clinic::factory()->create();

    Branch::factory()->create(['clinic_id' => $clinic1->id]);
    Branch::factory()->create(['clinic_id' => $clinic2->id]);

    $admin = User::factory()->masterAdmin()->create();

    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($admin);

    expect(Branch::all())->toHaveCount(2);
});

test('global tenant scope bypasses in console or CLI', function () {
    $clinic1 = Clinic::factory()->create();
    $clinic2 = Clinic::factory()->create();

    Branch::factory()->create(['clinic_id' => $clinic1->id]);
    Branch::factory()->create(['clinic_id' => $clinic2->id]);

    expect(Branch::all())->toHaveCount(2);
});

test('RequireClinicContext middleware blocks clinic user without active context from dashboard', function () {
    $user = User::factory()->create();

    app()->instance('enforce_tenant_scope_in_tests', true);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(403);
});

test('RequireClinicContext middleware allows clinic user with active context to access dashboard', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    app()->instance('enforce_tenant_scope_in_tests', true);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
});

test('Laravel Gate helper delegates PermissionKey checks to ClinicAuthorization', function () {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => true,
    ]);

    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($user);
    $context = app(ClinicContext::class);
    $context->setUser($user);
    $context->setMembership($membership);

    expect(Gate::allows(PermissionKey::ClinicManage->value))->toBeTrue();
});

test('policies correctly restrict cross-tenant resources', function () {
    $clinic1 = Clinic::factory()->create();
    $clinic2 = Clinic::factory()->create();

    $branch1 = Branch::factory()->create(['clinic_id' => $clinic1->id]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic2->id]);

    $user = User::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic1->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($user);
    $context = app(ClinicContext::class);
    $context->setUser($user);
    $context->setMembership($membership);

    expect(Gate::allows('view', $branch1))->toBeTrue()
        ->and(Gate::allows('view', $branch2))->toBeFalse();
});
