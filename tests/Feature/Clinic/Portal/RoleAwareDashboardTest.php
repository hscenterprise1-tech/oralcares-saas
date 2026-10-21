<?php

use App\BranchStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\OnboardingProgress;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * The new Clinic Portal foundation (Phase 1): one Dashboard entry point for
 * every user, composed on the server from what each may actually do; one
 * sidebar built from the same rules, with one destination per area; and a
 * branch context the server validates. Combined capabilities combine, never
 * duplicate, never redirect; what a user may not see is never sent.
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
    /** @return array{component: string, nav: list<string>, widgets: list<string>, props: array<string, mixed>} */
    $this->dashboard = function ($as): array {
        $result = [];
        $as->get('/clinic/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$result) {
            $props = $page->toArray()['props'];
            $result = [
                'component' => $page->toArray()['component'],
                'nav' => array_column($props['clinic']['navigation'], 'key'),
                'widgets' => array_column($props['widgets'], 'key'),
                'props' => $props,
            ];
        });

        return $result;
    };
    $this->widgetData = function ($as): array {
        $data = [];
        $as->get('/clinic/dashboard')->assertInertia(function (Assert $page) use (&$data) {
            $data = $page->toArray()['props']['widgetData'];
        });

        return $data;
    };
    $this->revokeBilling = function (ClinicRole $role) {
        setPermissionsTeamId(null);
        Role::findByName($role->value, 'web')->revokePermissionTo([ClinicPermission::BillingView->value, ClinicPermission::PaymentCollect->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

test('everyone signs in to the one Dashboard: no role is redirected to a separate home', function (?ClinicRole $role) {
    $member = $role ? ($this->member)($role) : [$this->f->owner, $this->f->session];

    ($this->as)($member)->get(route('dashboard'))->assertRedirect(route('clinic.dashboard'));
    $dashboard = ($this->dashboard)(($this->as)($member));
    expect($dashboard['component'])->toBe('ClinicPortal/Dashboard')
        ->and($dashboard['props']['portalLinks']['dashboard'])->toBe('/clinic/dashboard-redirect');
})->with([null, ClinicRole::ClinicAdmin, ClinicRole::Receptionist, ClinicRole::Doctor, ClinicRole::BillingStaff]);

test('reception without billing rights: front office only — no Billing in the sidebar, no money widgets, none sent', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    ($this->revokeBilling)(ClinicRole::Receptionist);
    $reception[0]->unsetRelation('roles')->unsetRelation('permissions');
    $dashboard = ($this->dashboard)(($this->as)($reception));

    expect($dashboard['nav'])->toBe(['dashboard', 'appointments', 'patients', 'front_desk', 'follow_ups', 'notifications', 'support'])
        ->and($dashboard['widgets'])->toContain('waiting_now', 'with_doctor_now', 'appointments_today', 'patients_today', 'todays_patients', 'upcoming_appointments');
    foreach (['collections_today', 'pending_payments', 'owner_finance', 'my_waiting', 'my_queue'] as $hidden) {
        expect($dashboard['widgets'])->not->toContain($hidden);
    }
    expect(array_keys(($this->widgetData)(($this->as)($reception))))->not->toContain('collections_today')
        ->and(collect(($this->widgetData)(($this->as)($reception))['needs_attention']['items'])->pluck('id'))->not->toContain('pending_payments')
        ->and(array_column($dashboard['props']['actions'], 'key'))->toBe(['new_case', 'repeat_case', 'new_appointment']);

    ($this->as)($reception)->get('/clinic/billing')->assertForbidden();
});

test('reception granted billing keeps its own dashboard, with Billing in the sidebar and the billing widgets added', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    expect(ClinicRole::Receptionist->permissions())->toContain(ClinicPermission::BillingView->value);

    $dashboard = ($this->dashboard)(($this->as)($reception));
    expect($dashboard['component'])->toBe('ClinicPortal/Dashboard')
        ->and($dashboard['nav'])->toBe(['dashboard', 'appointments', 'patients', 'front_desk', 'billing', 'follow_ups', 'notifications', 'support'])
        ->and($dashboard['widgets'])->toContain('todays_patients', 'collections_today', 'pending_payments');
    ($this->as)($reception)->get('/clinic/billing')->assertOk();
});

test('a user holding several roles gets their union: every destination and widget once', function () {
    ($this->doctorMember)(); // the Doctor role exists in this clinic
    $member = $this->f->memberWithRole(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($member[0], [ClinicRole::Receptionist->value, ClinicRole::Doctor->value], [$this->f->branch]);
    $this->f->doctor('Dr. Desk', $member[0]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $member[0]->unsetRelation('roles')->unsetRelation('permissions');

    $dashboard = ($this->dashboard)(($this->as)($member));
    expect($dashboard['nav'])->toContain('front_desk', 'billing', 'follow_ups')
        ->and($dashboard['widgets'])->toContain('todays_patients', 'my_queue')
        ->and($dashboard['nav'])->toBe(array_values(array_unique($dashboard['nav'])))
        ->and($dashboard['widgets'])->toBe(array_values(array_unique($dashboard['widgets'])));
});

test('a doctor gets their own day: who is waiting for them, who they have seen, notes to finish, and their queue', function () {
    $doctor = ($this->doctorMember)();
    $dashboard = ($this->dashboard)(($this->as)($doctor));

    expect($dashboard['nav'])->toBe(['dashboard', 'appointments', 'patients', 'follow_ups', 'notifications', 'support'])
        ->and(array_slice($dashboard['widgets'], 0, 3))->toBe(['my_waiting', 'my_seen', 'my_notes'])
        ->and($dashboard['widgets'])->toContain('my_queue')
        // No clinical action is offered while its screen is not built, and no front-office one at all.
        ->and($dashboard['props']['actions'])->toBe([]);
    foreach (['todays_patients', 'waiting_now', 'collections_today', 'owner_finance', 'my_care', 'my_dental', 'my_physio'] as $hidden) {
        expect($dashboard['widgets'])->not->toContain($hidden);
    }
    ($this->as)($doctor)->get('/clinic/billing')->assertForbidden();
    ($this->as)($doctor)->getJson('/clinic/reception/intake')->assertForbidden();
});

test('dashboard figures are the real records: the desk\'s waiting patient, and the same patient waiting for their doctor', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    $doctor = ($this->doctorMember)();
    $patient = $this->f->patient('Asha', 'Real');
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $patient, doctor: $this->doctor,
        context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => 'dental', 'visit_reason' => 'Tooth pain']));
    DB::table('patient_treatments')->insert([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'practitioner_id' => $this->doctor->id,
        'specialty_key' => 'dental', 'source' => 'catalog', 'title' => 'Root canal', 'status' => 'in_progress',
        'stages_total' => 4, 'stages_completed' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $desk = ($this->widgetData)(($this->as)($reception));
    expect($desk['todays_patients']['counts']['waiting'])->toBe(1)
        ->and($desk['todays_patients']['rows'][0]['patient']['name'])->toContain('Asha')
        ->and($desk['todays_patients']['rows'][0]['kind'])->toBe('new')
        ->and($desk['waiting_now']['value'])->toBe(1)
        ->and($desk['patients_today']['value'])->toBe(1)
        // Four figures at most: with billing rights, Collections takes the fourth place from Appointments.
        ->and($desk)->toHaveKey('collections_today')->not->toHaveKey('appointments_today');

    $mine = ($this->widgetData)(($this->as)($doctor));
    expect($mine['my_waiting']['value'])->toBe(1)
        ->and($mine['my_queue']['total'])->toBe(1)
        ->and($mine['my_queue']['items'][0]['primary'])->toContain('Asha');
});

test('the owner looks across all branches or drills into one; the server alone decides who may see all', function () {
    $second = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second Branch', 'status' => BranchStatus::Active]);

    ($this->asOwner)()->post('/clinic/branches/switch', ['branch_id' => null])->assertRedirect();
    expect(session(ClinicContext::SESSION_BRANCH_ID))->toBeNull();
    $all = ($this->dashboard)($this->actingAs($this->f->owner));
    expect($all['props']['scope'])->toMatchArray(['branch' => null, 'all_branches' => true, 'needs_branch' => false])
        // Check-in happens at a branch: across all branches the intake is not offered.
        ->and(array_column($all['props']['actions'], 'key'))->not->toContain('new_case', 'repeat_case');

    $this->post('/clinic/branches/switch', ['branch_id' => $second->id])->assertRedirect();
    $one = ($this->dashboard)($this->actingAs($this->f->owner));
    expect($one['props']['scope']['branch'])->toBe(['id' => $second->id, 'name' => 'Second Branch'])
        ->and($one['props']['scope']['all_branches'])->toBeFalse();

    // Staff hold their roles per branch: no one but the owner may choose "all".
    $admin = ($this->member)(ClinicRole::ClinicAdmin);
    ($this->as)($admin)->post('/clinic/branches/switch', ['branch_id' => null])->assertForbidden();
    // And never a branch of another clinic.
    $foreign = Branch::factory()->create(['status' => BranchStatus::Active]);
    ($this->as)($admin)->post('/clinic/branches/switch', ['branch_id' => $foreign->id])->assertForbidden();
});

test('a staff member at several branches is asked to choose one; a single-branch member never sees a selector', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);
    $single = ($this->dashboard)(($this->as)($reception));
    expect($single['props']['clinic']['accessible_branch_count'])->toBe(1)
        ->and($single['props']['scope'])->toMatchArray(['all_branches' => false, 'needs_branch' => false]);

    Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'status' => BranchStatus::Active]);
    // A fresh session: signed in, no branch chosen yet.
    $this->flushSession();
    $unchosen = [$reception[0], [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $reception[1][ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]]];
    expect(($this->dashboard)(($this->as)($unchosen))['props']['scope']['needs_branch'])->toBeTrue();
});

test('the sidebar lists only what the user may open, and says which destinations are not built yet', function () {
    $owner = ($this->dashboard)(($this->asOwner)())['props']['clinic']['navigation'];
    $byKey = collect($owner)->keyBy('key');

    expect($byKey->keys()->all())->toBe(['dashboard', 'appointments', 'patients', 'front_desk', 'billing', 'reports', 'follow_ups', 'notifications', 'support', 'approvals', 'staff', 'branches', 'settings'])
        ->and($byKey['support']['available'])->toBeTrue()
        ->and($byKey['notifications']['available'])->toBeTrue()
        ->and($byKey)->not->toHaveKey('daily_closing')
        ->and($byKey['staff']['section'])->toBe('admin')
        ->and($byKey['front_desk']['label'])->toBe('Queue')
        ->and($byKey['dashboard']['available'])->toBeTrue()
        ->and($byKey['appointments']['available'])->toBeTrue()
        ->and($byKey['billing']['available'])->toBeFalse()
        ->and($byKey['billing']['section'])->toBe('main');
});

test('New Case and Repeat Case are not sidebar items, and the intake opens only for the front office', function () {
    $owner = ($this->dashboard)(($this->asOwner)())['props']['clinic'];
    $doctor = ($this->dashboard)(($this->as)(($this->doctorMember)()))['props']['clinic'];

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

test('the figures come with the first response, and the live refresh can still ask for just them', function () {
    $reception = ($this->member)(ClinicRole::Receptionist);

    ($this->as)($reception)->get('/clinic/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('widgetData.waiting_now')
        ->reloadOnly('widgetData', fn (Assert $reload) => $reload->has('widgetData.waiting_now')->missing('widgets')->missing('actions')));
});
