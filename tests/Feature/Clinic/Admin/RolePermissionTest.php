<?php

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Clinic;
use App\Models\ClinicRolePermissionOverride;
use App\Services\Staff\ClinicRolePermissions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The owner decides what each standard role may do at their own clinic. The
 * standard roles are shared and never edited; a change is this clinic's only.
 */
beforeEach(function () {
    ClinicRolePermissions::flush();
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return $this->actingAs($user)->withSession($session);
    };
});

test('the owner sees every permission by area and what each role has', function () {
    ($this->asOwner)()->get('/clinic/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/Admin/Roles', false)
        ->has('roles', 4)
        ->where('roles.0.name', 'Clinic Admin')
        ->where('areas.0.key', 'patients')
        ->where('areas.0.items.0.permission', 'patient.view')
        ->where('areas.0.items.0.roles.Receptionist.granted', true)
        ->where('areas.0.items.0.roles.Billing Staff.granted', true));
});

test('every permission the product knows is on the screen', function () {
    $listed = collect(ClinicRolePermissions::AREAS)->flatMap(fn (array $area) => array_keys($area['items']))->all();

    expect(array_values(array_diff(ClinicPermission::values(), $listed)))->toBe([])
        ->and(array_values(array_diff($listed, ClinicPermission::values())))->toBe([]);
});

test('nobody but the owner may open or change it, not even a clinic admin', function () {
    ($this->asRole)(ClinicRole::ClinicAdmin)->get('/clinic/admin/roles')->assertForbidden();
    ($this->asRole)(ClinicRole::ClinicAdmin)->put('/clinic/admin/roles/Doctor/permissions', ['permission' => 'refund.process', 'granted' => true])->assertForbidden();
    ($this->asRole)(ClinicRole::Doctor)->post('/clinic/admin/roles/Doctor/reset')->assertForbidden();

    expect(ClinicRolePermissionOverride::count())->toBe(0);
});

test('giving a role a permission lets that role do it at this clinic', function () {
    ($this->asRole)(ClinicRole::Receptionist)->get('/clinic/admin/catalog')->assertForbidden();

    ($this->asOwner)()->put('/clinic/admin/roles/Receptionist/permissions', ['permission' => 'catalog.manage', 'granted' => true])->assertRedirect();

    ($this->asRole)(ClinicRole::Receptionist)->get('/clinic/admin/catalog')->assertOk();
    expect(ClinicRolePermissionOverride::where('role_name', 'Receptionist')->where('permission', 'catalog.manage')->value('granted'))->toBeTrue();
});

test('taking a permission away stops that role doing it, and other roles are unaffected', function () {
    ($this->asRole)(ClinicRole::ClinicAdmin)->get('/clinic/admin/catalog')->assertOk();

    ($this->asOwner)()->put('/clinic/admin/roles/Clinic Admin/permissions', ['permission' => 'catalog.manage', 'granted' => false])->assertRedirect();

    ($this->asRole)(ClinicRole::ClinicAdmin)->get('/clinic/admin/catalog')->assertForbidden();
    ($this->asRole)(ClinicRole::Doctor)->get('/clinic/admin/catalog')->assertForbidden();
});

test('a change equal to the standard is not stored, and a reset undoes every change of a role', function () {
    ($this->asOwner)()->put('/clinic/admin/roles/Receptionist/permissions', ['permission' => 'catalog.manage', 'granted' => true]);
    ($this->asOwner)()->put('/clinic/admin/roles/Receptionist/permissions', ['permission' => 'refund.process', 'granted' => true]);
    ($this->asOwner)()->put('/clinic/admin/roles/Receptionist/permissions', ['permission' => 'catalog.manage', 'granted' => false]);

    expect(ClinicRolePermissionOverride::where('role_name', 'Receptionist')->pluck('permission')->all())->toBe(['refund.process']);

    ($this->asOwner)()->post('/clinic/admin/roles/Receptionist/reset')->assertRedirect();
    expect(ClinicRolePermissionOverride::count())->toBe(0);
});

test('managing roles and choosing specialties can never be given to a role', function () {
    ($this->asOwner)()->put('/clinic/admin/roles/Doctor/permissions', ['permission' => 'roles.manage', 'granted' => true])->assertSessionHasErrors('permission');
    ($this->asOwner)()->put('/clinic/admin/roles/Doctor/permissions', ['permission' => 'specialty.configure', 'granted' => true])->assertSessionHasErrors('permission');
    ($this->asOwner)()->put('/clinic/admin/roles/Nobody/permissions', ['permission' => 'patient.view', 'granted' => true])->assertNotFound();

    expect(ClinicRolePermissionOverride::count())->toBe(0);
});

test('every change is written to the audit trail', function () {
    ($this->asOwner)()->put('/clinic/admin/roles/Billing Staff/permissions', ['permission' => 'refund.process', 'granted' => true]);

    expect(DB::table('security_audit_logs')->where('event', 'access.role_permission_changed')->count())->toBe(1);
});

test('one clinic\'s choices never reach another clinic', function () {
    ($this->asOwner)()->put('/clinic/admin/roles/Receptionist/permissions', ['permission' => 'refund.process', 'granted' => true]);
    $other = Clinic::factory()->create();

    $permissions = app(ClinicRolePermissions::class);
    expect($permissions->decide($this->f->clinic, ['Receptionist'], 'refund.process'))->toBeTrue()
        ->and($permissions->decide($other, ['Receptionist'], 'refund.process'))->toBeNull();
});
