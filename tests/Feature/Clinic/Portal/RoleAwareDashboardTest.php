<?php

use App\BranchStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\OnboardingProgress;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * The Clinic Portal foundation: one home per job, no separate staff
 * dashboard. Whoever runs the clinic lands on the Command Center, reception on
 * the Front Desk (its one dashboard), billing staff on Billing and
 * practitioners on the Calendar. One sidebar is built from the same rules,
 * with one destination per area; what a user may not see is never sent.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->as = fn (array $member) => $this->actingAs($member[0])->withSession($member[1]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->member = function (ClinicRole $role, ?string $name = null): array {
        $member = $this->f->memberWithRole($role);
        ClinicEmployee::query()->withoutGlobalScopes()->firstOrCreate(
            ['clinic_id' => $this->f->clinic->id, 'user_id' => $member[0]->id],
            ['name' => $name ?? $role->value, 'email' => $member[0]->email, 'status' => 'active'],
        );

        return $member;
    };
    $this->doctorMember = function (): array {
        $member = $this->f->memberWithRole(ClinicRole::Doctor);
        $this->doctor = $this->f->doctor('Dr. Dashboard', $member[0]);

        return $member;
    };
    /**
     * A page the member opens (by default the one every member may open), as its component, navigation keys and props.
     *
     * @return array{component: string, nav: list<string>, props: array<string, mixed>}
     */
    $this->page = function ($as, string $url = '/clinic/notifications'): array {
        $result = [];
        $as->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$result) {
            $props = $page->toArray()['props'];
            $result = [
                'component' => $page->toArray()['component'],
                'nav' => array_column($props['clinic']['navigation'], 'key'),
                'props' => $props,
            ];
        });

        return $result;
    };
    $this->revokeBilling = function (ClinicRole $role) {
        setPermissionsTeamId(null);
        Role::findByName($role->value, 'web')->revokePermissionTo([ClinicPermission::BillingView->value, ClinicPermission::PaymentCollect->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

test('everyone signs in at their one home: the Command Center, the Front Desk, Billing or the Calendar', function (?ClinicRole $role, string $route, string $component) {
    $member = match ($role) {
        null => [$this->f->owner, $this->f->session],
        ClinicRole::Doctor => ($this->doctorMember)(),
        default => ($this->member)($role),
    };

    ($this->as)($member)->get(route('dashboard'))->assertRedirect(route($route));
    $home = ($this->page)(($this->as)($member), route($route, absolute: false));
    expect($home['component'])->toBe($component)
        ->and($home['props']['portalLinks']['dashboard'])->toBe('/clinic/dashboard-redirect');
})->with([
    'owner' => [null, 'clinic.dashboard', 'ClinicPortal/CommandCenter/Index'],
    'administrator' => [ClinicRole::ClinicAdmin, 'clinic.dashboard', 'ClinicPortal/CommandCenter/Index'],
    'reception' => [ClinicRole::Receptionist, 'clinic.reception', 'ClinicPortal/FrontDesk/Index'],
    'doctor' => [ClinicRole::Doctor, 'clinic.consultations.index', 'ClinicPortal/Today/Index'],
    'billing' => [ClinicRole::BillingStaff, 'clinic.billing.index', 'ClinicPortal/Billing/Index'],
]);

test('reception without billing rights: front office only — no Billing in the sidebar, and Billing closed', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    ($this->revokeBilling)(ClinicRole::Receptionist);
    $reception[0]->unsetRelation('roles')->unsetRelation('permissions');
    $page = ($this->page)(($this->as)($reception));

    // Reception's Dashboard entry is the Front Desk; no second queue destination.
    expect($page['nav'])->toBe(['dashboard', 'appointments', 'patients', 'follow_ups', 'notifications', 'support'])
        ->and(collect($page['props']['clinic']['navigation'])->firstWhere('key', 'dashboard')['href'])->toBe('/clinic/reception');

    ($this->as)($reception)->get('/clinic/billing')->assertForbidden();
});

test('reception granted billing keeps the Front Desk as its dashboard, with Billing in the sidebar', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    // The ledger is a default Reception right since 2026-11-05 (full billing at the desk).
    expect(ClinicRole::Receptionist->permissions())->toContain(ClinicPermission::BillingView->value);
    setPermissionsTeamId(null);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->givePermissionTo(Permission::firstOrCreate(['name' => ClinicPermission::BillingView->value, 'guard_name' => 'web']));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $reception[0]->unsetRelation('roles')->unsetRelation('permissions');

    $page = ($this->page)(($this->as)($reception));
    expect($page['nav'])->toBe(['dashboard', 'appointments', 'patients', 'billing', 'follow_ups', 'notifications', 'support']);
    ($this->as)($reception)->get('/clinic/billing')->assertOk();
    ($this->as)($reception)->get('/clinic/dashboard')->assertRedirect(route('clinic.reception'));
});

test('a user holding several roles gets their union: every destination once', function () {
    ($this->doctorMember)(); // the Doctor role exists in this clinic
    $member = $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($member[0], [ClinicRole::Receptionist->value, ClinicRole::Doctor->value], [$this->f->branch]);
    $this->f->doctor('Dr. Desk', $member[0]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $member[0]->unsetRelation('roles')->unsetRelation('permissions');

    $page = ($this->page)(($this->as)($member));
    // Neither role reads the ledger by default (OD-1 / OD-9): no Billing entry.
    expect($page['nav'])->toContain('dashboard', 'follow_ups')->not->toContain('front_desk', 'billing')
        ->and($page['nav'])->toBe(array_values(array_unique($page['nav'])));
});

test('a doctor works from the Doctor Dashboard, with Appointments beside it: no billing, no intake', function () {
    $doctor = ($this->doctorMember)();
    $page = ($this->page)(($this->as)($doctor));

    // The Dashboard entry is the doctor's day (Today); Appointments stays where it was; Reports is their own work.
    expect($page['nav'])->toBe(['dashboard', 'appointments', 'patients', 'reports', 'follow_ups', 'notifications', 'support'])
        ->and(collect($page['props']['clinic']['navigation'])->firstWhere('key', 'dashboard')['href'])->toBe('/clinic/consultations')
        ->and(collect($page['props']['clinic']['navigation'])->firstWhere('key', 'reports')['label'])->toBe('My reports');
    ($this->as)($doctor)->get('/clinic/dashboard')->assertRedirect(route('clinic.consultations.index'));
    ($this->as)($doctor)->get('/clinic/billing')->assertForbidden();
    ($this->as)($doctor)->getJson('/clinic/reception/intake')->assertForbidden();
});

test('the owner looks across all branches or drills into one; the server alone decides who may see all', function () {
    $second = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second Branch', 'status' => BranchStatus::Active]);

    ($this->asOwner)()->post('/clinic/branches/switch', ['branch_id' => null])->assertRedirect();
    expect(session(ClinicContext::SESSION_BRANCH_ID))->toBeNull();
    $all = ($this->page)($this->actingAs($this->f->owner), '/clinic/dashboard');
    expect($all['props']['scope'])->toMatchArray(['branch' => null, 'all_branches' => true, 'needs_branch' => false])
        // The Command Center offers no intake of its own, across all branches or at one.
        ->and($all['props'])->not->toHaveKey('actions');

    $this->post('/clinic/branches/switch', ['branch_id' => $second->id])->assertRedirect();
    $one = ($this->page)($this->actingAs($this->f->owner), '/clinic/dashboard');
    expect($one['props']['scope']['branch'])->toBe(['id' => $second->id, 'name' => 'Second Branch'])
        ->and($one['props']['scope']['all_branches'])->toBeFalse();

    // Staff hold their roles per branch: no one but the owner may choose "all".
    $admin = ($this->member)(ClinicRole::ClinicAdmin);
    ($this->as)($admin)->post('/clinic/branches/switch', ['branch_id' => null])->assertForbidden();
    // And never a branch of another clinic.
    $foreign = Branch::factory()->create(['status' => BranchStatus::Active]);
    ($this->as)($admin)->post('/clinic/branches/switch', ['branch_id' => $foreign->id])->assertForbidden();
});

test('the sidebar lists only what the user may open, and says which destinations are not built yet', function () {
    $owner = ($this->page)(($this->asOwner)(), '/clinic/dashboard')['props']['clinic']['navigation'];
    $byKey = collect($owner)->keyBy('key');

    expect($byKey->keys()->all())->toBe(['dashboard', 'appointments', 'patients', 'front_desk', 'billing', 'reports', 'follow_ups', 'notifications', 'support', 'approvals', 'staff', 'branches', 'settings'])
        ->and($byKey['support']['available'])->toBeTrue()
        ->and($byKey['notifications']['available'])->toBeTrue()
        ->and($byKey)->not->toHaveKey('daily_closing')
        ->and($byKey['staff']['section'])->toBe('admin')
        ->and($byKey['front_desk']['label'])->toBe('Queue')
        ->and($byKey['dashboard']['available'])->toBeTrue()
        ->and($byKey['appointments']['available'])->toBeTrue()
        ->and($byKey['billing']['available'])->toBeTrue()
        ->and($byKey['billing']['section'])->toBe('main');
});

test('New Case and Repeat Case are not sidebar items, and the intake opens only for the front office', function () {
    $owner = ($this->page)(($this->asOwner)())['props']['clinic'];
    $doctor = ($this->page)(($this->as)(($this->doctorMember)()))['props']['clinic'];

    expect(collect($owner['navigation'])->pluck('key')->all())->not->toContain('new_case', 'repeat_case')
        ->and($owner['intake'])->toBe(['available' => true, 'can_register' => true])
        ->and($doctor['intake'])->toBe(['available' => false, 'can_register' => false]);
});

test('keeps the onboarding gate: an unfinished clinic is held on onboarding, not shown the dashboard', function () {
    OnboardingProgress::create([
        'organization_id' => $this->f->clinic->organization_id, 'current_step' => 'clinic_profile', 'status' => 'pending', 'completed_steps' => [],
    ]);

    ($this->asOwner)()->get(route('dashboard'))->assertRedirect(route('clinic.onboarding.index'));
    ($this->asOwner)()->get('/clinic/dashboard')->assertRedirect(route('clinic.onboarding.index'));
});

test('the Calendar, booking and check-in stay where they were', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    ($this->as)($reception)->get('/clinic/appointments')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Appointments/Index', true)->where('can.check_in', true));
    ($this->as)($reception)->getJson('/clinic/reception/intake')->assertOk();

    $billing = ($this->member)(ClinicRole::BillingStaff);
    ($this->as)($billing)->get('/clinic/appointments')->assertForbidden();
});
